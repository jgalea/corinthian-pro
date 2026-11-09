import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import { Button, Modal, ProgressBar, SelectControl, Spinner } from '@wordpress/components';
import { FieldInput, emptyValue, columnLabel, rowId } from './field-input';
import { aiEnabled } from './ai';
import { SuggestSection, SuggestReview, canSuggest } from './suggest';

const config = window.corinthianTable;

const MODE_LABELS = () => ( {
	set: __( 'Set to', 'corinthian-pro' ),
	clear: __( 'Clear', 'corinthian-pro' ),
	append: __( 'Add', 'corinthian-pro' ),
	remove: __( 'Remove', 'corinthian-pro' ),
} );

const bulkColumns = () => ( config?.editable || [] ).filter( ( c ) => Array.isArray( c.field?.bulk ) && c.field.bulk.length );

const filterActive = () => ( config?.filter?.current?.rules || [] ).length > 0;

function checkedRowIds() {
	return [ ...document.querySelectorAll( 'table.wp-list-table tbody .check-column input[type="checkbox"]:checked' ) ]
		.map( ( input ) => rowId( input.closest( 'tr' ) ) )
		.filter( Boolean );
}

function rowTitle( id ) {
	const tr = document.querySelector( `table.wp-list-table tbody tr[id$="-${ id }"]` );
	const title = tr?.querySelector( '.row-title, .column-title strong, .column-name strong' )?.textContent.trim();
	return title ? `${ title } (#${ id })` : `#${ id }`;
}

function BulkModal( { onClose } ) {
	const columns = bulkColumns();
	const [ columnId, setColumnId ] = useState( columns[ 0 ].id );
	const column = columns.find( ( c ) => c.id === columnId );
	const modes = column.field.bulk;
	const [ mode, setMode ] = useState( modes[ 0 ] );
	const [ value, setValue ] = useState( emptyValue( column.field ) );
	const [ target, setTarget ] = useState( () => {
		const ids = checkedRowIds();
		return ids.length ? { kind: 'rows', ids, loading: false } : { kind: filterActive() ? 'filter' : 'none', ids: [], loading: filterActive() };
	} );
	const [ phase, setPhase ] = useState( 'form' );
	const [ progress, setProgress ] = useState( 0 );
	const [ failures, setFailures ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ review, setReview ] = useState( null );
	const [ reviewState, setReviewState ] = useState( 'idle' );
	const finished = useRef( false );

	useEffect( () => {
		if ( target.kind !== 'filter' ) {
			return;
		}
		apiFetch( {
			path: '/corinthian/v1/bulk-edit',
			method: 'POST',
			data: { screen: config.screen, set: config.set, column: columns[ 0 ].id, mode: columns[ 0 ].field.bulk[ 0 ], filter: config.filter.current },
		} )
			.then( ( r ) => setTarget( { kind: 'filter', ids: r.ids || [], loading: false } ) )
			.catch( ( e ) => {
				setError( e?.message || __( 'The matching rows could not be counted.', 'corinthian-pro' ) );
				setTarget( { kind: 'filter', ids: [], loading: false } );
			} );
	}, [] );

	const pickColumn = ( id ) => {
		const next = columns.find( ( c ) => c.id === id );
		setColumnId( id );
		setMode( next.field.bulk[ 0 ] );
		setValue( emptyValue( next.field ) );
	};

	const total = target.ids.length;
	const canRun = phase === 'form' && ! target.loading && total > 0;

	const finish = () => {
		if ( ! finished.current ) {
			finished.current = true;
			window.location.reload();
		}
	};

	const run = async () => {
		setPhase( 'running' );
		setError( '' );
		const found = [];
		let offset = 0;
		try {
			while ( offset !== null ) {
				const r = await apiFetch( {
					path: '/corinthian/v1/bulk-edit',
					method: 'POST',
					data: { screen: config.screen, set: config.set, column: column.id, ids: target.ids, mode, value: mode === 'clear' ? null : value, offset },
				} );
				found.push( ...( r.failed || [] ) );
				offset = r.next_offset ?? null;
				setProgress( offset === null ? total : offset );
			}
		} catch ( e ) {
			setError( e?.message || __( 'The bulk edit stopped before it finished.', 'corinthian-pro' ) );
			setFailures( found );
			setPhase( 'done' );
			return;
		}
		setFailures( found );
		setPhase( 'done' );
		if ( ! found.length ) {
			setTimeout( finish, 700 );
		}
	};

	const requestClose = () => {
		if ( phase === 'running' || reviewState === 'busy' ) {
			return;
		}
		if ( phase === 'done' || reviewState === 'done' ) {
			finish();
		} else {
			onClose();
		}
	};

	const modeOptions = modes.map( ( m ) => ( { value: m, label: MODE_LABELS()[ m ] || m } ) );

	let targetText;
	if ( target.loading ) {
		targetText = <Spinner />;
	} else if ( target.kind === 'rows' ) {
		targetText = sprintf( /* translators: %d: number of rows */ _n( '%d selected row', '%d selected rows', total, 'corinthian-pro' ), total );
	} else if ( target.kind === 'filter' ) {
		targetText = sprintf( /* translators: %d: number of rows */ _n( 'All %d row matching the filter', 'All %d rows matching the filter', total, 'corinthian-pro' ), total );
	} else {
		targetText = __( 'Check some rows or apply a filter to choose what to edit.', 'corinthian-pro' );
	}

	return (
		<Modal title={ __( 'Bulk edit', 'corinthian-pro' ) } size={ review ? 'large' : 'medium' } onRequestClose={ requestClose } shouldCloseOnClickOutside={ false }>
			{ review && (
				<SuggestReview
					column={ column }
					rows={ review }
					titleOf={ rowTitle }
					onBack={ () => {
						setReview( null );
						setReviewState( 'idle' );
					} }
					onFinish={ finish }
					onStateChange={ setReviewState }
				/>
			) }
			{ ! review && (
			<form
				className="corinthian-bulk"
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

				{ phase === 'form' && (
					<>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Column', 'corinthian-pro' ) }
							value={ columnId }
							options={ columns.map( ( c ) => ( { value: c.id, label: columnLabel( c.tableKey, c.id ) } ) ) }
							onChange={ pickColumn }
						/>
						{ modes.length > 1 && (
							<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Action', 'corinthian-pro' ) } value={ mode } options={ modeOptions } onChange={ setMode } />
						) }
						{ mode !== 'clear' && (
							<FieldInput field={ column.field } value={ value } onChange={ setValue } label={ __( 'Value', 'corinthian-pro' ) } showLabel />
						) }
						{ aiEnabled() && canSuggest( column ) && <SuggestSection key={ column.id } column={ column } target={ target } onResult={ setReview } /> }
						{ error && (
							<p className="corinthian-edit__error" role="alert">
								{ error }
							</p>
						) }
					</>
				) }

				{ phase !== 'form' && (
					<div className="corinthian-bulk__progress">
						<ProgressBar value={ phase === 'done' && ! error ? 100 : Math.round( ( progress / Math.max( total, 1 ) ) * 100 ) } aria-label={ __( 'Bulk edit progress', 'corinthian-pro' ) } />
						<p aria-live="polite">
							{ sprintf( /* translators: 1: rows processed, 2: total rows */ __( '%1$d of %2$d rows', 'corinthian-pro' ), phase === 'done' && ! error ? total : progress, total ) }
						</p>
					</div>
				) }

				{ phase === 'done' && error && (
					<p className="corinthian-edit__error" role="alert">
						{ error }
					</p>
				) }

				{ phase === 'done' && failures.length > 0 && (
					<div className="corinthian-bulk__failures" role="alert">
						<p className="corinthian-edit__error">
							{ sprintf( /* translators: %d: number of rows that failed */ _n( '%d row could not be changed', '%d rows could not be changed', failures.length, 'corinthian-pro' ), failures.length ) }
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

				{ phase === 'done' && ! failures.length && ! error && <p className="corinthian-bulk__done">{ __( 'Done. Reloading the list.', 'corinthian-pro' ) }</p> }

				<div className="corinthian-bulk__actions">
					{ phase === 'form' && (
						<>
							<Button variant="tertiary" onClick={ onClose }>
								{ __( 'Cancel', 'corinthian-pro' ) }
							</Button>
							<Button variant="primary" type="submit" accessibleWhenDisabled disabled={ ! canRun }>
								{ total > 0 ? sprintf( /* translators: %d: number of rows */ _n( 'Edit %d row', 'Edit %d rows', total, 'corinthian-pro' ), total ) : __( 'Edit rows', 'corinthian-pro' ) }
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
			) }
		</Modal>
	);
}

function App() {
	const [ open, setOpen ] = useState( false );
	const toggle = useRef();
	return (
		<>
			<Button ref={ toggle } className="corinthian-bulk-toggle" variant="secondary" icon="edit" onClick={ () => setOpen( true ) }>
				{ __( 'Bulk edit', 'corinthian-pro' ) }
			</Button>
			{ open && (
				<BulkModal
					onClose={ () => {
						setOpen( false );
						setTimeout( () => toggle.current?.focus(), 0 );
					} }
				/>
			) }
		</>
	);
}

export function mountBulkEdit( parent ) {
	if ( ! bulkColumns().length || ! parent || parent.querySelector( '#corinthian-bulk-root' ) ) {
		return;
	}
	const container = document.createElement( 'div' );
	container.id = 'corinthian-bulk-root';
	parent.appendChild( container );
	createRoot( container ).render( <App /> );
}
