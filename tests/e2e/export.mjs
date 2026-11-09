import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
const col = process.argv[ 2 ];
const b = await chromium.launch();
const ctx = await b.newContext( { acceptDownloads: true } );
const p = await ctx.newPage();
await p.goto( 'http://localhost:8930/wp-login.php' );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };
const parse = ( csv ) => csv.replace( /^﻿/, '' ).trim().split( '\n' );
async function exportFrom( url ) {
	await p.goto( url );
	const [ dl ] = await Promise.all( [ p.waitForEvent( 'download' ), p.click( 'a.corinthian-export' ) ] );
	return parse( readFileSync( await dl.path(), 'utf8' ) );
}
await p.goto( 'http://localhost:8930/wp-admin/edit.php' );
const liveTotal = Number( ( ( await p.$eval( '.tablenav.top .displaying-num', ( el ) => el.textContent ) ).match( /\d+/ ) || [ 0 ] )[ 0 ] );
const all = await exportFrom( 'http://localhost:8930/wp-admin/edit.php' );
await p.evaluate( ( [ set, col ] ) => { window.corinthianExportCtx = { screen: 'post:post', set, column: col }; }, [ Number( process.argv[ 3 ] ), col ] );
check( liveTotal > 0 && all.length === liveTotal + 1, `control: unfiltered export has header + ${ liveTotal } rows, matching the list count (${ all.length } lines)` );
check( all[ 0 ].startsWith( 'ID,' ) && all[ 0 ].includes( 'Status' ) && all[ 0 ].includes( 'Notes' ), `header: ${ all[ 0 ].slice( 0, 90 ) }` );
const evil = all.find( ( l ) => l.includes( 'HYPERLINK' ) );
check( !! evil && evil.includes( `"'=HYPERLINK` ), `formula neutralised: ${ evil?.slice( evil.indexOf( 'HYPER' ) - 3, evil.indexOf( 'HYPER' ) + 12 ) }` );
// Seed: exactly two posts with Status "Exported" (other agents' tests reuse this site's data).
const seeded = await p.evaluate( async () => {
	const ids = [ ...document.querySelectorAll( 'table.wp-list-table tbody tr' ) ].slice( 0, 2 ).map( ( tr ) => Number( tr.id.replace( 'post-', '' ) ) );
	for ( const id of ids ) {
		await wp.apiFetch( { path: '/corinthian/v1/edit', method: 'POST', data: { ...window.corinthianExportCtx, id, value: 'Exported' } } );
	}
	return ids.length;
} ).catch( ( e ) => -1 );
const filter = encodeURIComponent( JSON.stringify( { rules: [ { column: col, op: 'eq', value: 'Exported' } ] } ) );
const done = await exportFrom( `http://localhost:8930/wp-admin/edit.php?corinthian_filter=${ filter }` );
check( seeded === 2 && done.length === 3 && done.slice( 1 ).every( ( l ) => l.includes( 'Exported' ) ), `filtered export: ${ done.length - 1 } rows, all Exported (seeded ${ seeded })` );
const href = await p.$eval( 'a.corinthian-export', ( a ) => a.href );
const resp = await p.request.get( href.replace( /_wpnonce=[^&]+/, '_wpnonce=bad' ) );
check( resp.status() === 403, `bad nonce refused (${ resp.status() })` );
await b.close();
process.exit( fail ? 1 : 0 );
