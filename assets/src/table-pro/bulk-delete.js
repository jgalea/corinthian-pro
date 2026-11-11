import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Modal,
	ProgressBar,
	Spinner,
} from '@wordpress/components';
import { checkedRowIds, filterActive, rowTitle } from './bulk-edit';

const config = window.corinthianTable;
const PREVIEW = 8;

function DeleteModal( { onClose } ) {
	const permanentOnly = !! config.bulkDelete.permanentOnly;
	const [ target, setTarget ] = useState( () => {
		const ids = checkedRowIds();
		return ids.length
			? { kind: 'rows', ids, loading: false }
			: {
					kind: filterActive() ? 'filter' : 'none',
					ids: [],
					loading: filterActive(),
			  };
	} );
	const [ permanent, setPermanent ] = useState( permanentOnly );
	const [ sure, setSure ] = useState( false );
	const [ phase, setPhase ] = useState( 'confirm' );
	const [ progress, setProgress ] = useState( 0 );
	const [ failures, setFailures ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const finished = useRef( false );
	const mode = permanent ? 'delete' : 'trash';
	const total = target.ids.length;

	useEffect( () => {
		if ( target.kind !== 'filter' ) {
			return;
		}
		apiFetch( {
			path: '/corinthian/v1/bulk-delete',
			method: 'POST',
			data: {
				screen: config.screen,
				set: config.set,
				mode,
				filter: config.filter.current,
			},
		} )
			.then( ( r ) =>
				setTarget( {
					kind: 'filter',
					ids: r.ids || [],
					loading: false,
				} )
			)
			.catch( ( e ) => {
				setError(
					e?.message ||
						__(
							'The matching rows could not be counted.',
							'corinthian-pro'
						)
				);
				setTarget( { kind: 'filter', ids: [], loading: false } );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional
	}, [] );

	const finish = () => {
		if ( ! finished.current ) {
			finished.current = true;
			window.location.reload();
		}
	};

	const canRun =
		phase === 'confirm' &&
		! target.loading &&
		total > 0 &&
		( ! permanent || sure );

	const run = async () => {
		setPhase( 'running' );
		setError( '' );
		const found = [];
		let offset = 0;
		try {
			while ( offset !== null ) {
				const r = await apiFetch( {
					path: '/corinthian/v1/bulk-delete',
					method: 'POST',
					data: {
						screen: config.screen,
						set: config.set,
						mode,
						ids: target.ids,
						confirm: total,
						offset,
					},
				} );
				found.push( ...( r.failed || [] ) );
				offset = r.next_offset ?? null;
				setProgress( offset === null ? total : offset );
			}
		} catch ( e ) {
			setError(
				e?.message ||
					__(
						'The delete stopped before it finished.',
						'corinthian-pro'
					)
			);
		}
		setFailures( found );
		setPhase( 'done' );
		if ( ! found.length && ! finished.current ) {
			setTimeout( finish, 700 );
		}
	};

	const requestClose = () => {
		if ( phase === 'running' ) {
			return;
		}
		if ( phase === 'done' ) {
			finish();
		} else {
			onClose();
		}
	};

	let targetText;
	if ( target.loading ) {
		targetText = <Spinner />;
	} else if ( target.kind === 'rows' ) {
		targetText = sprintf(
			/* translators: %d: number of rows */ _n(
				'%d selected row',
				'%d selected rows',
				total,
				'corinthian-pro'
			),
			total
		);
	} else if ( target.kind === 'filter' ) {
		targetText = sprintf(
			/* translators: %d: number of rows */ _n(
				'All %d row matching the filter',
				'All %d rows matching the filter',
				total,
				'corinthian-pro'
			),
			total
		);
	} else {
		targetText = __(
			'Check some rows or apply a filter to choose what to delete.',
			'corinthian-pro'
		);
	}

	const verb = permanent
		? __( 'Delete permanently', 'corinthian-pro' )
		: __( 'Move to trash', 'corinthian-pro' );

	return (
		<Modal
			title={ __( 'Delete rows', 'corinthian-pro' ) }
			size="medium"
			onRequestClose={ requestClose }
			shouldCloseOnClickOutside={ false }
		>
			<form
				className="corinthian-bulk corinthian-bulk--delete"
				onSubmit={ ( e ) => {
					e.preventDefault();
					if ( canRun ) {
						run();
					}
				} }
			>
				<p className="corinthian-bulk__target" aria-live="polite">
					{ targetText }
				</p>

				{ phase === 'confirm' && total > 0 && (
					<>
						<ul className="corinthian-bulk__preview">
							{ target.ids.slice( 0, PREVIEW ).map( ( id ) => (
								<li key={ id }>{ rowTitle( id ) }</li>
							) ) }
							{ total > PREVIEW && (
								<li>
									{ sprintf(
										/* translators: %d: number of further rows */ __(
											'and %d more',
											'corinthian-pro'
										),
										total - PREVIEW
									) }
								</li>
							) }
						</ul>
						{ ! permanentOnly && (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __(
									'Delete permanently instead of moving to the trash',
									'corinthian-pro'
								) }
								checked={ permanent }
								onChange={ ( v ) => {
									setPermanent( v );
									setSure( false );
								} }
							/>
						) }
						{ permanent && (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ sprintf(
									/* translators: %d: number of rows */ _n(
										'I understand %d row will be deleted for good',
										'I understand %d rows will be deleted for good',
										total,
										'corinthian-pro'
									),
									total
								) }
								checked={ sure }
								onChange={ setSure }
							/>
						) }
					</>
				) }

				{ error && (
					<p className="corinthian-edit__error" role="alert">
						{ error }
					</p>
				) }

				{ phase !== 'confirm' && (
					<div className="corinthian-bulk__progress">
						<ProgressBar
							value={
								phase === 'done' && ! error
									? 100
									: Math.round(
											( progress /
												Math.max( total, 1 ) ) *
												100
									  )
							}
							aria-label={ __(
								'Delete progress',
								'corinthian-pro'
							) }
						/>
						<p aria-live="polite">
							{ sprintf(
								/* translators: 1: rows processed, 2: total rows */ __(
									'%1$d of %2$d rows',
									'corinthian-pro'
								),
								phase === 'done' && ! error ? total : progress,
								total
							) }
						</p>
					</div>
				) }

				{ phase === 'done' && failures.length > 0 && (
					<div className="corinthian-bulk__failures" role="alert">
						<p className="corinthian-edit__error">
							{ sprintf(
								/* translators: %d: number of rows that failed */ _n(
									'%d row could not be removed',
									'%d rows could not be removed',
									failures.length,
									'corinthian-pro'
								),
								failures.length
							) }
						</p>
						<ul>
							{ failures.map( ( f ) => (
								<li key={ f.id }>
									<strong>{ rowTitle( f.id ) }</strong>
									{ ': ' }
									{ f.error }
								</li>
							) ) }
						</ul>
					</div>
				) }

				<div className="corinthian-bulk__actions">
					{ phase === 'confirm' && (
						<>
							<Button variant="tertiary" onClick={ onClose }>
								{ __( 'Cancel', 'corinthian-pro' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								type="submit"
								accessibleWhenDisabled
								disabled={ ! canRun }
							>
								{ total > 0
									? sprintf(
											/* translators: 1: action, 2: number of rows */ __(
												'%1$s (%2$d)',
												'corinthian-pro'
											),
											verb,
											total
									  )
									: verb }
							</Button>
						</>
					) }
					{ phase === 'done' && (
						<Button variant="primary" onClick={ finish }>
							{ __( 'Close', 'corinthian-pro' ) }
						</Button>
					) }
				</div>
			</form>
		</Modal>
	);
}

function App() {
	const [ open, setOpen ] = useState( false );
	const toggle = useRef();
	return (
		<>
			<Button
				ref={ toggle }
				className="corinthian-bulk-toggle corinthian-delete-toggle"
				variant="secondary"
				isDestructive
				icon="trash"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Delete', 'corinthian-pro' ) }
			</Button>
			{ open && (
				<DeleteModal
					onClose={ () => {
						setOpen( false );
						setTimeout( () => toggle.current?.focus(), 0 );
					} }
				/>
			) }
		</>
	);
}

export function mountBulkDelete( parent ) {
	if (
		! config?.bulkDelete ||
		! parent ||
		parent.querySelector( '#corinthian-bulk-delete-root' )
	) {
		return;
	}
	const container = document.createElement( 'div' );
	container.id = 'corinthian-bulk-delete-root';
	parent.appendChild( container );
	createRoot( container ).render( <App /> );
}
