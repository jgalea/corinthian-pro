import apiFetch from '@wordpress/api-fetch';
import { createRoot, useState, useRef, useEffect } from '@wordpress/element';
import { Button, TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

function QuickAdd( { config } ) {
	const [ open, setOpen ] = useState( false );
	const [ title, setTitle ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const input = useRef();

	useEffect( () => {
		if ( open ) {
			input.current?.focus();
		}
	}, [ open ] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! title.trim() || busy ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			await apiFetch( {
				path: `/${ config.namespace }/${ config.restBase }`,
				method: 'POST',
				data: { title: title.trim(), status: 'draft' },
			} );
			window.location.reload();
		} catch ( e ) {
			setError(
				e.message ||
					__( 'Could not create the draft.', 'corinthian-pro' )
			);
			setBusy( false );
		}
	};

	if ( ! open ) {
		return (
			<Button
				variant="secondary"
				icon="plus-alt2"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Quick add', 'corinthian-pro' ) }
			</Button>
		);
	}

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- form-level Escape handler
		<form
			className="corinthian-quick-add"
			onSubmit={ submit }
			onKeyDown={ ( e ) => e.key === 'Escape' && setOpen( false ) }
		>
			<TextControl
				ref={ input }
				label={ config.label }
				hideLabelFromVision
				placeholder={ __( 'Title', 'corinthian-pro' ) }
				value={ title }
				onChange={ setTitle }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || ! title.trim() }
				__next40pxDefaultSize
			>
				{ __( 'Add draft', 'corinthian-pro' ) }
			</Button>
			<Button
				variant="tertiary"
				onClick={ () => setOpen( false ) }
				__next40pxDefaultSize
			>
				{ __( 'Cancel', 'corinthian-pro' ) }
			</Button>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</form>
	);
}

export function mountQuickAdd( parent ) {
	const config = window.corinthianTable?.quickAdd;
	if (
		! config ||
		! parent ||
		parent.querySelector( '#corinthian-quick-add' )
	) {
		return;
	}
	const container = document.createElement( 'div' );
	container.id = 'corinthian-quick-add';
	parent.appendChild( container );
	createRoot( container ).render( <QuickAdd config={ config } /> );
}
