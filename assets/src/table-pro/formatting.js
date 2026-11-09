import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { rowId } from './field-input';

const config = window.corinthianTable;

function clear() {
	document.querySelectorAll( '.corinthian-formatted' ).forEach( ( el ) => {
		[ ...el.classList ].filter( ( c ) => c.startsWith( 'corinthian-tone-' ) || c === 'corinthian-bold' || c === 'corinthian-row' || c === 'corinthian-formatted' ).forEach( ( c ) => el.classList.remove( c ) );
	} );
	document.querySelectorAll( '.corinthian-tone-label' ).forEach( ( n ) => n.remove() );
}

function mark( el, rule, label, labelHost = el ) {
	el.classList.add( 'corinthian-formatted', `corinthian-tone-${ rule.tone }` );
	if ( rule.bold ) {
		el.classList.add( 'corinthian-bold' );
	}
	// The border and tint are not the only signal: a hidden label names the tone.
	if ( ! labelHost.querySelector( ':scope > .corinthian-tone-label' ) ) {
		const span = document.createElement( 'span' );
		span.className = 'corinthian-tone-label screen-reader-text';
		span.textContent = label;
		labelHost.prepend( span );
	}
}

export function toneLabel( tone ) {
	const labels = {
		red: __( 'Highlighted red:', 'corinthian-pro' ),
		amber: __( 'Highlighted amber:', 'corinthian-pro' ),
		green: __( 'Highlighted green:', 'corinthian-pro' ),
		blue: __( 'Highlighted blue:', 'corinthian-pro' ),
		purple: __( 'Highlighted purple:', 'corinthian-pro' ),
		grey: __( 'Highlighted grey:', 'corinthian-pro' ),
	};
	return labels[ tone ] || __( 'Highlighted:', 'corinthian-pro' );
}

let seq = 0;

function apply() {
	const rows = new Map();
	document.querySelectorAll( 'table.wp-list-table tbody tr' ).forEach( ( tr ) => {
		const id = rowId( tr );
		if ( id ) {
			rows.set( id, tr );
		}
	} );
	if ( ! rows.size ) {
		return;
	}
	const mine = ++seq;
	apiFetch( { path: '/corinthian/v1/format', method: 'POST', data: { screen: config.screen, set: config.set, ids: [ ...rows.keys() ] } } )
		.then( ( { matches } ) => {
			if ( mine !== seq ) {
				return;
			}
			clear();
			( matches || [] ).forEach( ( { rule, ids } ) => {
				const label = toneLabel( rule.tone );
				( ids || [] ).forEach( ( id ) => {
					const tr = rows.get( Number( id ) );
					if ( ! tr ) {
						return;
					}
					if ( rule.scope === 'row' ) {
						tr.classList.add( 'corinthian-row' );
						mark( tr, rule, label, tr.querySelector( 'td.column-primary' ) || tr.querySelector( 'td' ) || tr );
						return;
					}
					const key = `corinthian-${ rule.column }`;
					const td = tr.querySelector( `td.column-${ CSS.escape( key ) }` ) || tr.querySelector( `td.column-${ CSS.escape( rule.column ) }` );
					if ( td ) {
						mark( td, rule, label );
					}
				} );
			} );
		} )
		.catch( () => {} );
}

export function mountFormatting() {
	if ( ! config?.hasFormatting ) {
		return;
	}
	apply();
	document.addEventListener( 'corinthian:cell-updated', apply );
}
