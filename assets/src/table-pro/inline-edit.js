import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { createRoot, useState, useRef, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { FieldInput, emptyValue, columnLabel, rowId } from './field-input';

const config = window.corinthianTable;

function Editor( { column, id, label, initial, onDone, onCancel } ) {
	const [ value, setValue ] = useState( initial );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const root = useRef();

	useEffect( () => {
		root.current?.querySelector( 'input:not([type=checkbox]), textarea, select, button' )?.focus();
	}, [] );

	const submit = ( event ) => {
		event?.preventDefault();
		if ( busy ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: '/corinthian/v1/edit',
			method: 'POST',
			data: { screen: config.screen, set: config.set, column: column.id, id, value },
		} )
			.then( ( response ) => onDone( response ) )
			.catch( ( e ) => {
				setError( e?.message || __( 'The change could not be saved.', 'corinthian-pro' ) );
				setBusy( false );
			} );
	};

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			event.stopPropagation();
			onCancel();
		} else if ( event.key === 'Enter' && event.target.tagName === 'TEXTAREA' && ! event.shiftKey ) {
			submit( event );
		}
	};

	return (
		<form ref={ root } className="corinthian-edit" onSubmit={ submit } onKeyDown={ onKeyDown } noValidate>
			<FieldInput field={ column.field } value={ value } onChange={ setValue } label={ label } autoFocus />
			<div className="corinthian-edit__actions">
				<Button variant="primary" size="compact" type="submit" isBusy={ busy } accessibleWhenDisabled disabled={ busy }>
					{ __( 'Save', 'corinthian-pro' ) }
				</Button>
				<Button variant="tertiary" size="compact" onClick={ onCancel } disabled={ busy } accessibleWhenDisabled>
					{ __( 'Cancel', 'corinthian-pro' ) }
				</Button>
			</div>
			{ error && (
				<p className="corinthian-edit__error" role="alert">
					{ error }
				</p>
			) }
		</form>
	);
}

function open( td, column, id, pencil ) {
	if ( td.classList.contains( 'corinthian-editing' ) ) {
		return;
	}
	const label = columnLabel( column.tableKey, __( 'Value', 'corinthian-pro' ) );
	const original = [ ...td.childNodes ].filter( ( n ) => n !== pencil );
	original.forEach( ( n ) => n.remove() );
	pencil.hidden = true;
	td.classList.add( 'corinthian-editing' );
	const host = document.createElement( 'div' );
	host.className = 'corinthian-edit-host';
	td.insertBefore( host, pencil );
	const root = createRoot( host );

	const close = ( html ) => {
		// Unmounting inside the root's own event handler is unsafe, so defer.
		setTimeout( () => {
			root.unmount();
			host.remove();
			if ( typeof html === 'string' ) {
				pencil.insertAdjacentHTML( 'beforebegin', html );
				document.dispatchEvent( new CustomEvent( 'corinthian:cell-updated' ) );
			} else {
				original.forEach( ( n ) => td.insertBefore( n, pencil ) );
			}
			td.classList.remove( 'corinthian-editing' );
			pencil.hidden = false;
			pencil.focus();
		}, 0 );
	};

	const stored = td.dataset.corinthianValue;
	root.render(
		<Editor
			column={ column }
			id={ id }
			label={ label }
			initial={ stored !== undefined && column.field.input !== 'multiselect' ? stored : emptyValue( column.field ) }
			onCancel={ () => close() }
			onDone={ ( response ) => {
				if ( response.value !== null && response.value !== undefined && typeof response.value !== 'object' ) {
					td.dataset.corinthianValue = String( response.value );
				}
				close( response.html ?? '' );
			} }
		/>
	);
}

function addPencil( td, column, id ) {
	if ( td.querySelector( ':scope > .corinthian-edit-pencil' ) ) {
		return;
	}
	const label = columnLabel( column.tableKey, __( 'value', 'corinthian-pro' ) );
	const pencil = document.createElement( 'button' );
	pencil.type = 'button';
	pencil.className = 'corinthian-edit-pencil';
	pencil.setAttribute( 'aria-label', sprintf( /* translators: %s: column name */ __( 'Edit %s', 'corinthian-pro' ), label ) );
	pencil.innerHTML = '<span class="dashicons dashicons-edit" aria-hidden="true"></span>';
	pencil.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		open( td, column, id, pencil );
	} );
	td.classList.add( 'corinthian-editable' );
	td.appendChild( pencil );
}

export function mountInlineEdit() {
	const columns = config?.editable || [];
	if ( ! columns.length ) {
		return;
	}
	document.querySelectorAll( 'table.wp-list-table tbody tr' ).forEach( ( tr ) => {
		const id = rowId( tr );
		if ( ! id ) {
			return;
		}
		columns.forEach( ( column ) => {
			const td = tr.querySelector( `td.column-${ CSS.escape( column.tableKey ) }` );
			if ( td ) {
				addPencil( td, column, id );
			}
		} );
	} );
}
