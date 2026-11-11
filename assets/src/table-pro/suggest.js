import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	ProgressBar,
	TextControl,
} from '@wordpress/components';
import { FieldInput, optionEntries } from './field-input';

const config = window.corinthianTable;

export const MAX_SUGGEST_ROWS = 20;
const SUGGEST_INPUTS = [ 'text', 'textarea', 'select' ];

export const canSuggest = ( column ) =>
	SUGGEST_INPUTS.includes( column?.field?.input );

export function SuggestSection( { column, target, onResult } ) {
	const [ instruction, setInstruction ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const total = target.ids.length;

	let reason = '';
	if ( target.loading || target.kind !== 'rows' || ! total ) {
		reason = sprintf(
			/* translators: %d: maximum number of rows */ __(
				'Check up to %d rows in the list to get suggestions.',
				'corinthian-pro'
			),
			MAX_SUGGEST_ROWS
		);
	} else if ( total > MAX_SUGGEST_ROWS ) {
		reason = sprintf(
			/* translators: 1: maximum number of rows, 2: number of rows checked */
			__(
				'Suggestions work on up to %1$d rows at a time, and %2$d are checked. Uncheck some rows to continue.',
				'corinthian-pro'
			),
			MAX_SUGGEST_ROWS,
			total
		);
	}
	const disabled = busy || !! reason || ! instruction.trim();

	const run = () => {
		if ( disabled ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: '/corinthian/v1/ai/suggest',
			method: 'POST',
			data: {
				screen: config.screen,
				set: config.set,
				column: column.id,
				ids: target.ids,
				instruction: instruction.trim(),
			},
		} )
			.then( ( rows ) => {
				if ( Array.isArray( rows ) && rows.length ) {
					onResult( rows );
				} else {
					setError(
						__(
							'No suggestions came back for these rows. Try rewording the instruction.',
							'corinthian-pro'
						)
					);
				}
			} )
			.catch( ( e ) =>
				setError(
					e?.message ||
						__(
							'The suggestions could not be loaded.',
							'corinthian-pro'
						)
				)
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<fieldset className="corinthian-suggest">
			<legend>{ __( 'Suggest values', 'corinthian-pro' ) }</legend>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Instruction', 'corinthian-pro' ) }
				placeholder={ __(
					'Write a 150-character meta description',
					'corinthian-pro'
				) }
				value={ instruction }
				onChange={ setInstruction }
				// A bare Enter must not submit the surrounding bulk edit.
				onKeyDown={ ( e ) => {
					if ( e.key === 'Enter' ) {
						e.preventDefault();
						run();
					}
				} }
			/>
			<div className="corinthian-suggest__run">
				<Button
					variant="secondary"
					isBusy={ busy }
					accessibleWhenDisabled
					disabled={ disabled }
					aria-describedby={
						reason ? 'corinthian-suggest-reason' : undefined
					}
					onClick={ run }
				>
					{ __( 'Suggest values', 'corinthian-pro' ) }
				</Button>
				{ busy && (
					<span aria-live="polite">
						{ __( 'Working…', 'corinthian-pro' ) }
					</span>
				) }
			</div>
			{ reason && (
				<p
					id="corinthian-suggest-reason"
					className="corinthian-suggest__hint"
				>
					{ reason }
				</p>
			) }
			{ error && (
				<p className="corinthian-edit__error" role="alert">
					{ error }
				</p>
			) }
		</fieldset>
	);
}

export function SuggestReview( {
	column,
	rows,
	titleOf,
	onBack,
	onFinish,
	onStateChange,
} ) {
	const [ items, setItems ] = useState( () =>
		rows.map( ( r ) => ( {
			id: r.id,
			current: r.current,
			value: r.suggested,
			accept: true,
		} ) )
	);
	const [ phase, setPhase ] = useState( 'review' );
	const [ progress, setProgress ] = useState( 0 );
	const [ failures, setFailures ] = useState( [] );
	const options = optionEntries( column.field );
	const showCurrent = ( v ) => {
		const text =
			column.field.input === 'select'
				? options.find( ( o ) => o.value === String( v ) )?.label ??
				  String( v ?? '' )
				: String( v ?? '' );
		return text === '' ? (
			<span className="corinthian-suggest__empty">
				{ __( 'Empty', 'corinthian-pro' ) }
			</span>
		) : (
			text
		);
	};
	const patch = ( id, change ) =>
		setItems( ( list ) =>
			list.map( ( i ) => ( i.id === id ? { ...i, ...change } : i ) )
		);
	const accepted = items.filter( ( i ) => i.accept );

	const apply = async () => {
		setPhase( 'applying' );
		onStateChange( 'busy' );
		const failed = [];
		let done = 0;
		for ( const item of accepted ) {
			try {
				await apiFetch( {
					path: '/corinthian/v1/edit',
					method: 'POST',
					data: {
						screen: config.screen,
						set: config.set,
						column: column.id,
						id: item.id,
						value: item.value,
					},
				} );
			} catch ( e ) {
				failed.push( {
					id: item.id,
					error:
						e?.message ||
						__(
							'The change could not be saved.',
							'corinthian-pro'
						),
				} );
			}
			done++;
			setProgress( done );
		}
		setFailures( failed );
		setPhase( 'done' );
		onStateChange( 'done' );
		if ( ! failed.length ) {
			setTimeout( onFinish, 700 );
		}
	};

	if ( phase !== 'review' ) {
		const total = accepted.length;
		return (
			<div className="corinthian-bulk">
				<div className="corinthian-bulk__progress">
					<ProgressBar
						value={ Math.round(
							( progress / Math.max( total, 1 ) ) * 100
						) }
						aria-label={ __(
							'Applying suggestions',
							'corinthian-pro'
						) }
					/>
					<p aria-live="polite">
						{ sprintf(
							/* translators: 1: rows saved, 2: total rows */ __(
								'%1$d of %2$d rows',
								'corinthian-pro'
							),
							progress,
							total
						) }
					</p>
				</div>
				{ phase === 'done' && failures.length > 0 && (
					<div className="corinthian-bulk__failures" role="alert">
						<p className="corinthian-edit__error">
							{ sprintf(
								/* translators: %d: number of rows that failed */ _n(
									'%d row could not be changed',
									'%d rows could not be changed',
									failures.length,
									'corinthian-pro'
								),
								failures.length
							) }
						</p>
						<ul>
							{ failures.map( ( f ) => (
								<li key={ f.id }>
									<strong>{ titleOf( f.id ) }</strong>
									{ ': ' }
									{ f.error }
								</li>
							) ) }
						</ul>
					</div>
				) }
				{ phase === 'done' && ! failures.length && (
					<p className="corinthian-bulk__done">
						{ __( 'Done. Reloading the list.', 'corinthian-pro' ) }
					</p>
				) }
				{ phase === 'done' && (
					<div className="corinthian-bulk__actions">
						<Button variant="primary" onClick={ onFinish }>
							{ __( 'Close', 'corinthian-pro' ) }
						</Button>
					</div>
				) }
			</div>
		);
	}

	return (
		<div className="corinthian-bulk">
			<p className="corinthian-bulk__target">
				{ __(
					'Review the suggestions. Nothing is saved until you apply.',
					'corinthian-pro'
				) }
			</p>
			<table className="corinthian-suggest__table">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Accept', 'corinthian-pro' ) }
						</th>
						<th scope="col">{ __( 'Row', 'corinthian-pro' ) }</th>
						<th scope="col">
							{ __( 'Current value', 'corinthian-pro' ) }
						</th>
						<th scope="col">
							{ __( 'Suggested value', 'corinthian-pro' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => {
						const title = titleOf( item.id );
						return (
							<tr key={ item.id }>
								<td
									data-label={ __(
										'Accept',
										'corinthian-pro'
									) }
								>
									<CheckboxControl
										__nextHasNoMarginBottom
										aria-label={ sprintf(
											/* translators: %s: row title */ __(
												'Accept suggestion for %s',
												'corinthian-pro'
											),
											title
										) }
										checked={ item.accept }
										onChange={ ( on ) =>
											patch( item.id, { accept: on } )
										}
									/>
								</td>
								<td
									data-label={ __( 'Row', 'corinthian-pro' ) }
									className="corinthian-suggest__title"
								>
									{ title }
								</td>
								<td
									data-label={ __(
										'Current value',
										'corinthian-pro'
									) }
									className="corinthian-suggest__current"
								>
									{ showCurrent( item.current ) }
								</td>
								<td
									data-label={ __(
										'Suggested value',
										'corinthian-pro'
									) }
								>
									<FieldInput
										field={ column.field }
										value={ item.value }
										onChange={ ( value ) =>
											patch( item.id, { value } )
										}
										label={ sprintf(
											/* translators: %s: row title */ __(
												'Suggested value for %s',
												'corinthian-pro'
											),
											title
										) }
									/>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			<div className="corinthian-bulk__actions">
				<Button variant="tertiary" onClick={ onBack }>
					{ __( 'Back', 'corinthian-pro' ) }
				</Button>
				<Button
					variant="primary"
					accessibleWhenDisabled
					disabled={ ! accepted.length }
					onClick={ apply }
				>
					{ __( 'Apply accepted', 'corinthian-pro' ) }
				</Button>
			</div>
		</div>
	);
}
