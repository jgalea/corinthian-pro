/* global CSS */
import { __ } from '@wordpress/i18n';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

export function optionEntries( field ) {
	const options = field?.options;
	if ( ! options || typeof options !== 'object' ) {
		return [];
	}
	return Object.entries( options ).map( ( [ value, label ] ) => ( {
		value: String( value ),
		label: String( label ),
	} ) );
}

export function emptyValue( field ) {
	return field?.input === 'multiselect' ? [] : '';
}

function MediaPicker( { value, onChange, label } ) {
	const id = String( value || '' );
	if ( ! window.wp?.media ) {
		return (
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min="0"
				label={ label }
				hideLabelFromVision
				placeholder={ __( 'Media ID', 'corinthian-pro' ) }
				value={ id }
				onChange={ onChange }
			/>
		);
	}
	const pick = () => {
		const frame = window.wp.media( {
			title: __( 'Choose an image', 'corinthian-pro' ),
			multiple: false,
			library: { type: 'image' },
		} );
		frame.on( 'select', () => {
			const picked = frame.state().get( 'selection' ).first();
			if ( picked ) {
				onChange( String( picked.get( 'id' ) ) );
			}
		} );
		frame.open();
	};
	return (
		<div className="corinthian-field__media">
			<Button variant="secondary" onClick={ pick }>
				{ id
					? __( 'Change image', 'corinthian-pro' )
					: __( 'Choose image', 'corinthian-pro' ) }
			</Button>
			{ id && (
				<>
					<span className="corinthian-field__media-id">{ `#${ id }` }</span>
					<Button variant="tertiary" onClick={ () => onChange( '' ) }>
						{ __( 'Remove', 'corinthian-pro' ) }
					</Button>
				</>
			) }
		</div>
	);
}

/**
 * One input for any Editable field. Enter handling lives with the caller,
 * which wraps this in a form.
 * @param {*} root0
 * @param {*} root0.field
 * @param {*} root0.value
 * @param {*} root0.onChange
 * @param {*} root0.label
 * @param {*} root0.autoFocus
 * @param {*} root0.showLabel
 */
export function FieldInput( {
	field,
	value,
	onChange,
	label,
	autoFocus = false,
	showLabel = false,
} ) {
	const common = {
		__nextHasNoMarginBottom: true,
		label,
		hideLabelFromVision: ! showLabel,
	};
	const size = { __next40pxDefaultSize: true };
	const options = optionEntries( field );

	switch ( field.input ) {
		case 'textarea':
			return (
				<TextareaControl
					{ ...common }
					rows={ 3 }
					placeholder={ __( 'Enter a new value', 'corinthian-pro' ) }
					value={ value ?? '' }
					onChange={ onChange }
					// eslint-disable-next-line jsx-a11y/no-autofocus
					autoFocus={ autoFocus }
				/>
			);
		case 'number':
		case 'date':
			return (
				<TextControl
					{ ...common }
					{ ...size }
					type={ field.input }
					placeholder={
						field.input === 'number'
							? __( 'Enter a number', 'corinthian-pro' )
							: undefined
					}
					value={ value ?? '' }
					onChange={ onChange }
					// eslint-disable-next-line jsx-a11y/no-autofocus
					autoFocus={ autoFocus }
				/>
			);
		case 'datetime':
			return (
				<TextControl
					{ ...common }
					{ ...size }
					type="datetime-local"
					value={ value ?? '' }
					onChange={ onChange }
					// eslint-disable-next-line jsx-a11y/no-autofocus -- field opens on demand, focus is intended
					autoFocus={ autoFocus }
				/>
			);
		case 'toggle':
			return (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ label }
					checked={ value === '1' || value === 1 || value === true }
					onChange={ ( on ) => onChange( on ? '1' : '0' ) }
				/>
			);
		case 'select':
		case 'user':
			if ( field.input === 'user' && ! options.length ) {
				return (
					<TextControl
						{ ...common }
						{ ...size }
						type="number"
						min="0"
						placeholder={ __( 'User ID', 'corinthian-pro' ) }
						value={ value ?? '' }
						onChange={ onChange }
					/>
				);
			}
			return (
				<SelectControl
					{ ...common }
					{ ...size }
					value={ value ?? '' }
					options={ [
						{ value: '', label: __( 'None', 'corinthian-pro' ) },
						...options,
					] }
					onChange={ onChange }
				/>
			);
		case 'multiselect':
			return (
				<SelectControl
					{ ...common }
					multiple
					value={ Array.isArray( value ) ? value : [] }
					options={ options }
					onChange={ onChange }
				/>
			);
		case 'media':
			return (
				<MediaPicker
					value={ value }
					onChange={ onChange }
					label={ label }
				/>
			);
		default:
			return (
				<TextControl
					{ ...common }
					{ ...size }
					type="text"
					placeholder={ __( 'Enter a new value', 'corinthian-pro' ) }
					value={ value ?? '' }
					onChange={ onChange }
					// eslint-disable-next-line jsx-a11y/no-autofocus
					autoFocus={ autoFocus }
				/>
			);
	}
}

/**
 * Read the label a column shows in the table header.
 * @param {*} tableKey
 * @param {*} fallback
 */
export function columnLabel( tableKey, fallback = '' ) {
	const th = document.querySelector( `thead th#${ CSS.escape( tableKey ) }` );
	if ( ! th ) {
		return fallback;
	}
	const copy = th.cloneNode( true );
	copy.querySelectorAll(
		'.corinthian-resize, .sorting-indicators, .screen-reader-text'
	).forEach( ( n ) => n.remove() );
	return copy.textContent.trim() || fallback;
}

export function rowId( tr ) {
	const match = /^(?:post|user|comment|tag)-(\d+)$/.exec( tr.id || '' );
	return match ? Number( match[ 1 ] ) : 0;
}
