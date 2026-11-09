// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
// Inline edit, bulk edit and conditional formatting on the Posts list table.
// SHOTS=<dir> also writes screenshots at 390 and 1440.
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

const base = 'http://localhost:8930';
const shots = process.env.SHOTS || '';
const SET = 18;
const COL = '64882268049ba4';
const wp = ( ...args ) => execFileSync( 'docker', [ 'exec', CLI, 'wp', ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
const php = ( code ) => wp( 'eval', code );
const status = ( id ) => wp( 'post', 'meta', 'get', String( id ), 'status' ).replace( /\s+$/, '' );
const setStatus = ( id, v ) => ( v === null ? ( () => { try { return wp( 'post', 'meta', 'delete', String( id ), 'status' ); } catch { return ''; } } )() : wp( 'post', 'meta', 'update', String( id ), 'status', v ) );

let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };

// Fixture: remember everything we touch and put it back at the end.
const ids = JSON.parse( wp( 'post', 'list', '--post_type=post', '--post_status=publish', '--field=ID', '--format=json' ) );
check( ids.length >= 7, `fixture: at least 7 published posts (${ ids.length })` );
const original = {};
for ( const id of ids ) {
	let v = null;
	try { v = wp( 'post', 'meta', 'get', String( id ), 'status' ); } catch { v = null; }
	original[ id ] = v;
}
const activeKey = php( 'echo \\Corinthian\\Sets\\SetRepository::active_meta_key( "post:post" );' );
const originalActive = php( `echo get_user_meta( 1, "${ activeKey }", true );` );
const originalSettings = php( `echo wp_json_encode( \\Corinthian\\Plugin::instance()->sets->find( ${ SET } )->settings );` );
let copyId = 0;
const restore = () => {
	try {
		for ( const id of ids ) {
			if ( original[ id ] === null ) { try { setStatus( id, null ); } catch {} } else { setStatus( id, original[ id ] ); }
		}
		php( `$r = \\Corinthian\\Plugin::instance()->sets; $d = $r->find( ${ SET } )->to_array(); $d["settings"] = json_decode( '${ originalSettings.replace( /'/g, "\\'" ) }', true ); $r->save( $d, ${ SET } ); update_user_meta( 1, "${ activeKey }", "${ originalActive }" );` );
		if ( copyId ) { php( `\\Corinthian\\Plugin::instance()->sets->delete( ${ copyId } );` ); }
	} catch ( e ) { console.log( 'WARN restore failed', e.message ); }
};
process.on( 'exit', restore );

const seed = ( map ) => ids.forEach( ( id ) => setStatus( id, map[ id ] ?? null ) );
php( `update_user_meta( 1, "${ activeKey }", ${ SET } );` );

const b = await chromium.launch();
const p = await b.newPage( { viewport: { width: 1440, height: 900 } } );
await p.goto( `${ base }/wp-login.php` );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

const open = async ( query = '' ) => {
	await p.goto( `${ base }/wp-admin/edit.php${ query }` );
	await p.waitForSelector( '.corinthian-edit-pencil', { state: 'attached' } );
};
const cell = ( id ) => p.locator( `#post-${ id } td.column-corinthian-${ COL }` );
const pencil = ( id ) => cell( id ).locator( '.corinthian-edit-pencil' );
const overflows = () => p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
const snap = async ( name ) => {
	if ( ! shots ) return;
	mkdirSync( shots, { recursive: true } );
	await p.screenshot( { path: `${ shots }/edit-${ name }-1440.png` } );
};
const snapMobile = async ( name ) => {
	if ( ! shots ) return;
	await p.setViewportSize( { width: 390, height: 844 } );
	await p.waitForTimeout( 300 );
	await p.screenshot( { path: `${ shots }/edit-${ name }-390.png` } );
	await p.setViewportSize( { width: 1440, height: 900 } );
};

const [ a, bb, c, d, e, f, g ] = ids; // a..g in id order; any 7 distinct posts will do
const A = ids.slice().sort( ( x, y ) => x - y );

// ---- Inline edit ----------------------------------------------------------
seed( { [ A[ 0 ] ]: 'E2E start' } );
await open();
const target = A[ 0 ];
check( ( await pencil( target ).count() ) === 1, 'pencil present on an editable cell' );
check( ( await p.locator( '#post-' + target + ' td.column-title .corinthian-edit-pencil' ).count() ) === 0, 'no pencil on a non-editable column' );
check( ( await pencil( target ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ) === '0', 'pencil hidden until hover or focus' );
await cell( target ).hover();
check( ( await pencil( target ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ) === '1', 'pencil shows on hover' );
await p.mouse.move( 0, 0 );
await pencil( target ).focus();
check( ( await pencil( target ).evaluate( ( el ) => getComputedStyle( el ).opacity ) ) === '1', 'pencil shows on keyboard focus' );
check( /^Edit /.test( ( await pencil( target ).getAttribute( 'aria-label' ) ) || '' ), 'pencil has an accessible name' );

// Keyboard: tabbing from the row's title link reaches this row's pencil.
await p.locator( `#post-${ target } .row-title` ).focus();
let reached = false;
for ( let i = 0; i < 12 && ! reached; i++ ) {
	await p.keyboard.press( 'Tab' );
	reached = await p.evaluate( ( id ) => document.activeElement.classList.contains( 'corinthian-edit-pencil' ) && document.activeElement.closest( 'tr' ).id === `post-${ id }`, target );
}
check( reached, 'Tab reaches the pencil of the row' );

// Escape cancels.
await pencil( target ).press( 'Enter' );
let input = cell( target ).locator( 'input[type="text"]' );
await input.waitFor();
check( ( await input.inputValue() ) === '', 'editor starts empty' );
check( !! ( await input.getAttribute( 'placeholder' ) ), 'editor has a placeholder' );
check( await input.evaluate( ( el ) => document.activeElement === el ), 'editor input takes focus' );
await input.fill( 'E2E discarded' );
await p.keyboard.press( 'Escape' );
await p.waitForFunction( ( s ) => ! document.querySelector( s ), `#post-${ target } .corinthian-edit` );
check( ( await cell( target ).innerText() ).includes( 'E2E start' ), 'Escape restores the cell' );
check( await pencil( target ).evaluate( ( el ) => document.activeElement === el ), 'focus returns to the pencil' );
check( status( target ) === 'E2E start', 'Escape saves nothing' );

// Save with Enter; persists across reload.
await pencil( target ).click();
input = cell( target ).locator( 'input[type="text"]' );
await input.fill( 'E2E edited inline' );
await input.scrollIntoViewIfNeeded();
if ( shots ) { await snap( 'inline' ); }
await input.press( 'Enter' );
await p.waitForFunction( ( [ id, col ] ) => document.querySelector( `#post-${ id } td.column-corinthian-${ col }` )?.textContent.includes( 'E2E edited inline' ) && ! document.querySelector( `#post-${ id } .corinthian-edit` ), [ target, COL ] );
check( true, 'cell shows the saved value without reload' );
check( ( await pencil( target ).count() ) === 1, 'pencil is back after save' );
await p.reload();
await p.waitForSelector( '.corinthian-edit-pencil', { state: 'attached' } );
check( ( await cell( target ).innerText() ).includes( 'E2E edited inline' ), 'inline edit persists after reload' );
check( status( target ) === 'E2E edited inline', 'meta stored server-side' );

// Second edit starts from the saved value.
await pencil( target ).click();
input = cell( target ).locator( 'input[type="text"]' );
check( ( await input.inputValue() ) === '', 'after reload the editor starts empty again' );
await p.keyboard.press( 'Escape' );

// ---- Inline error ---------------------------------------------------------
copyId = Number( php( `$r = \\Corinthian\\Plugin::instance()->sets; $d = $r->find( ${ SET } )->to_array(); $d["title"] = "E2E protected copy"; $d["position"] = 99; $d["settings"] = (array) $d["settings"]; $d["settings"]["users"] = array(); $d["settings"]["roles"] = array(); foreach ( $d["columns"] as &$col ) { if ( $col["id"] === "${ COL }" ) { $col["settings"]["meta_key"] = "_corinthian_e2e_protected"; } } echo $r->save( $d )->id;` ) );
check( copyId > 0, `test copy set created (#${ copyId })` );
php( `update_user_meta( 1, "${ activeKey }", ${ copyId } );` );
await open();
const copyCfg = await p.evaluate( () => window.corinthianTable.set );
check( copyCfg === copyId, 'copy set is the active set' );
await pencil( target ).click();
input = cell( target ).locator( 'input[type="text"]' );
await input.fill( 'will be refused' );
await input.press( 'Enter' );
const err = cell( target ).locator( '.corinthian-edit__error' );
await err.waitFor();
const errText = ( await err.innerText() ).trim();
check( errText.length > 3, `server error shown inline: "${ errText }"` );
check( ( await err.getAttribute( 'role' ) ) === 'alert', 'error is announced (role=alert)' );
check( ( await err.evaluate( ( el ) => getComputedStyle( el, '::before' ).content ) ).length > 2, 'error carries an icon, not only colour' );
check( ! ( await pencil( target ).isVisible() ), 'pencil is hidden while the editor is open' );
check( ( await cell( target ).locator( 'input[type="text"]' ).inputValue() ) === 'will be refused', 'editor stays open with the typed value' );
if ( shots ) { await snap( 'inline-error' ); }
await p.keyboard.press( 'Escape' );
php( `update_user_meta( 1, "${ activeKey }", ${ SET } );` );

// ---- Bulk edit: 2 checked rows ---------------------------------------------
seed( { [ A[ 0 ] ]: 'B0', [ A[ 1 ] ]: 'B1', [ A[ 2 ] ]: 'B2', [ A[ 3 ] ]: 'B3' } );
await open();
await p.check( `#cb-select-${ A[ 1 ] }` );
await p.check( `#cb-select-${ A[ 2 ] }` );
await p.getByRole( 'button', { name: 'Bulk edit' } ).click();
const modal = p.getByRole( 'dialog' );
await modal.waitFor();
check( ( await modal.getByText( '2 selected rows' ).count() ) === 1, 'modal states 2 selected rows' );
await modal.getByLabel( 'Column' ).selectOption( { label: 'Status' } );
await modal.getByLabel( 'Value' ).fill( 'E2E bulk' );
check( ( await modal.getByRole( 'button', { name: 'Edit 2 rows' } ).count() ) === 1, 'confirm button names the count' );
await p.waitForTimeout( 500 );
if ( shots ) { await snap( 'bulk' ); await snapMobile( 'bulk' ); }
await p.setViewportSize( { width: 320, height: 640 } );
check( ! ( await overflows() ), 'no page-level horizontal scroll at 320 with the modal open' );
check( await modal.evaluate( ( el ) => el.getBoundingClientRect().right <= window.innerWidth + 1 ), 'modal fits within 320' );
await p.setViewportSize( { width: 1440, height: 900 } );
await Promise.all( [ p.waitForNavigation( { waitUntil: 'load' } ), modal.getByRole( 'button', { name: 'Edit 2 rows' } ).click() ] );
check( status( A[ 1 ] ) === 'E2E bulk' && status( A[ 2 ] ) === 'E2E bulk', 'both checked rows changed' );
check( status( A[ 0 ] ) === 'B0' && status( A[ 3 ] ) === 'B3', 'unchecked rows untouched' );

// ---- Bulk edit: all matching a filter -------------------------------------
seed( { [ A[ 0 ] ]: 'Other', [ A[ 1 ] ]: 'E2E match', [ A[ 2 ] ]: 'E2E match', [ A[ 4 ] ]: 'E2E match', [ A[ 5 ] ]: 'E2E match' } );
await open();
const param = await p.evaluate( () => window.corinthianTable.filter.param );
const filter = JSON.stringify( { match: 'all', rules: [ { column: COL, op: 'eq', value: 'E2E match' } ] } );
await p.goto( `${ base }/wp-admin/edit.php?${ param }=${ encodeURIComponent( filter ) }` );
await p.waitForSelector( '.corinthian-edit-pencil', { state: 'attached' } );
const onScreen = await p.$$eval( 'table.wp-list-table tbody tr:not(.no-items)', ( r ) => r.length );
check( onScreen === 4, `filter shows 4 rows (${ onScreen })` );
await p.getByRole( 'button', { name: 'Bulk edit' } ).click();
const modal2 = p.getByRole( 'dialog' );
await modal2.getByText( /^All \d+ rows matching the filter$/ ).waitFor();
const shown = Number( ( await modal2.getByText( /^All \d+ rows matching the filter$/ ).innerText() ).match( /\d+/ )[ 0 ] );
check( shown === onScreen, `count before confirming (${ shown }) matches rows on screen (${ onScreen })` );
await modal2.getByLabel( 'Column' ).selectOption( { label: 'Status' } );
await modal2.getByLabel( 'Value' ).fill( 'E2E all done' );
await Promise.all( [ p.waitForNavigation( { waitUntil: 'load' } ), modal2.getByRole( 'button', { name: `Edit ${ shown } rows` } ).click() ] );
check( [ 1, 2, 4, 5 ].every( ( i ) => status( A[ i ] ) === 'E2E all done' ), 'all four matching rows changed' );
check( status( A[ 0 ] ) === 'Other', 'non-matching row untouched' );

// Bulk with nothing chosen is blocked.
await p.goto( `${ base }/wp-admin/edit.php` );
await p.waitForSelector( '.corinthian-edit-pencil', { state: 'attached' } );
await p.getByRole( 'button', { name: 'Bulk edit' } ).click();
const modal3 = p.getByRole( 'dialog' );
check( await modal3.getByRole( 'button', { name: 'Edit rows' } ).isDisabled().catch( () => false ) || ( await modal3.getByRole( 'button', { name: 'Edit rows' } ).getAttribute( 'aria-disabled' ) ) === 'true', 'no target: confirm is disabled' );
await p.keyboard.press( 'Escape' );

// ---- Formatting ----------------------------------------------------------
seed( { [ A[ 0 ] ]: 'F-red', [ A[ 1 ] ]: 'F-red', [ A[ 2 ] ]: 'F-green', [ A[ 3 ] ]: 'plain', [ A[ 4 ] ]: 'F-blue' } );
const rules = [
	{ id: 'e2e1', column: COL, op: 'eq', value: 'F-red', tone: 'red', bold: true, scope: 'cell' },
	{ id: 'e2e2', column: COL, op: 'eq', value: 'F-green', tone: 'green', bold: false, scope: 'row' },
	{ id: 'e2e3', column: COL, op: 'eq', value: 'F-blue', tone: 'blue', bold: false, scope: 'cell' },
];
php( `$r = \\Corinthian\\Plugin::instance()->sets; $d = $r->find( ${ SET } )->to_array(); $d["settings"] = (array) $d["settings"]; $d["settings"]["formatting"] = json_decode( '${ JSON.stringify( rules ) }', true ); $r->save( $d, ${ SET } );` );
check( php( `echo count( \\CorinthianPro\\Rest\\FormattingController::rules( \\Corinthian\\Plugin::instance()->sets->find( ${ SET } ) ) );` ) === '3', 'three formatting rules stored' );
await open();
await p.waitForSelector( '.corinthian-formatted' );
const marked = await p.$$eval( '.corinthian-formatted', ( els ) => els.map( ( el ) => ( { tag: el.tagName, id: ( el.closest( 'tr' ) || {} ).id, cls: [ ...el.classList ].filter( ( x ) => x.startsWith( 'corinthian-' ) ).sort().join( ' ' ), col: el.className.match( /column-(\S+)/ )?.[ 1 ] || '' } ) ) );
const byRow = ( id ) => marked.filter( ( m ) => m.id === `post-${ id }` );
check( byRow( A[ 0 ] ).length === 1 && byRow( A[ 0 ] )[ 0 ].tag === 'TD' && byRow( A[ 0 ] )[ 0 ].col.includes( COL ) && byRow( A[ 0 ] )[ 0 ].cls.includes( 'corinthian-tone-red' ) && byRow( A[ 0 ] )[ 0 ].cls.includes( 'corinthian-bold' ), 'red bold rule marks only the Status cell of post 1' );
check( byRow( A[ 1 ] ).length === 1 && byRow( A[ 1 ] )[ 0 ].cls.includes( 'corinthian-tone-red' ), 'red rule marks the second match' );
check( byRow( A[ 2 ] ).length === 1 && byRow( A[ 2 ] )[ 0 ].tag === 'TR' && byRow( A[ 2 ] )[ 0 ].cls.includes( 'corinthian-tone-green' ) && ! byRow( A[ 2 ] )[ 0 ].cls.includes( 'corinthian-bold' ), 'green row rule marks the whole row, not bold' );
check( byRow( A[ 4 ] ).length === 1 && byRow( A[ 4 ] )[ 0 ].tag === 'TD' && byRow( A[ 4 ] )[ 0 ].cls.includes( 'corinthian-tone-blue' ), 'blue cell rule marks its cell' );
check( marked.length === 4, `exactly four marked elements (${ marked.length })` );
check( byRow( A[ 3 ] ).length === 0 && byRow( A[ 5 ] ).length === 0 && byRow( A[ 6 ] ).length === 0, 'non-matching rows carry no formatting' );
const labels = await p.$$eval( '.corinthian-tone-label', ( els ) => els.map( ( el ) => el.textContent ) );
check( labels.length === 4 && labels.every( ( t ) => /^Highlighted \w+:$/.test( t ) ), `each marked element has a hidden tone label (${ labels.join( ' | ' ) })` );
check( await p.$eval( '.corinthian-tone-label', ( el ) => { const r = el.getBoundingClientRect(); return r.width <= 1 && r.height <= 1; } ), 'tone label is visually hidden' );
// Marker + contrast, per tone.
const contrast = await p.evaluate( () => {
	const lum = ( rgb ) => { const [ r, g, bl ] = rgb.match( /\d+(\.\d+)?/g ).slice( 0, 3 ).map( Number ).map( ( v ) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ( ( v + 0.055 ) / 1.055 ) ** 2.4; } ); return 0.2126 * r + 0.7152 * g + 0.0722 * bl; };
	const ratio = ( x, y ) => { const [ hi, lo ] = [ lum( x ), lum( y ) ].sort( ( m, n ) => n - m ); return ( hi + 0.05 ) / ( lo + 0.05 ); };
	const rows = [ ...document.querySelectorAll( `table.wp-list-table tbody tr td.column-corinthian-${ '64882268049ba4' }` ) ];
	const real = rows.find( ( td ) => ! td.classList.contains( 'corinthian-formatted' ) && ! td.closest( 'tr' ).classList.contains( 'corinthian-formatted' ) );
	return [ 'red', 'amber', 'green', 'blue', 'purple', 'grey' ].map( ( tone ) => {
		const saved = real.className;
		const text = real.innerHTML;
		real.className = `${ saved } corinthian-formatted corinthian-tone-${ tone } corinthian-bold`;
		real.innerHTML = 'Text <a href="#">link</a>';
		const td = real;
		const cs = getComputedStyle( td );
		const link = getComputedStyle( td.querySelector( 'a' ) ).color;
		const mark = cs.getPropertyValue( '--corinthian-tone-mark' ).trim();
		const out = { tone, text: ratio( cs.color, cs.backgroundColor ), link: ratio( link, cs.backgroundColor ), marker: ( cs.boxShadow || '' ).includes( 'inset' ), mark: mark ? ratio( `rgb(${ [ 1, 3, 5 ].map( ( i ) => parseInt( mark.slice( i, i + 2 ), 16 ) ).join( ',' ) })`, cs.backgroundColor ) : 0 };
		real.className = saved;
		real.innerHTML = text;
		return out;
	} );
} );
contrast.forEach( ( t ) => check( t.text >= 4.5 && t.link >= 4.5 && t.mark >= 3 && t.marker, `tone ${ t.tone }: text ${ t.text.toFixed( 1 ) }, link ${ t.link.toFixed( 1 ) }, marker ${ t.mark.toFixed( 1 ) }, left marker ${ t.marker }` ) );
if ( shots ) { await snap( 'formatting' ); await snapMobile( 'formatting' ); }

// Edit a formatted cell: formatting follows the new value.
await pencil( A[ 3 ] ).click();
await cell( A[ 3 ] ).locator( 'input[type="text"]' ).fill( 'F-red' );
await cell( A[ 3 ] ).locator( 'input[type="text"]' ).press( 'Enter' );
await p.waitForFunction( ( id ) => document.querySelector( `#post-${ id } td.corinthian-tone-red` ), A[ 3 ] );
check( true, 'editing a cell re-applies formatting to it' );
check( ( await p.$$( '.corinthian-formatted' ) ).length === 5, 'formatting set stays exact after the edit' );

// Layout at 320 / 390 with formatting and a pencil per cell.
for ( const w of [ 320, 390 ] ) {
	await p.setViewportSize( { width: w, height: 800 } );
	await p.goto( `${ base }/wp-admin/edit.php` );
	await p.waitForSelector( '.corinthian-formatted', { state: 'attached' } );
	check( ! ( await overflows() ), `no page-level horizontal scroll at ${ w }` );
}
// Phone (touch): expand a row, open the editor on its Status.
const ctx = await b.newContext( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await p.context().storageState() } );
const m = await ctx.newPage();
const mCell = m.locator( `#post-${ A[ 0 ] } td.column-corinthian-${ COL }` );
const mPencil = mCell.locator( '.corinthian-edit-pencil' );
const mOverflows = () => m.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
await m.goto( `${ base }/wp-admin/edit.php` );
await m.waitForSelector( '.corinthian-formatted', { state: 'attached' } );
await m.locator( `#post-${ A[ 0 ] } .toggle-row` ).tap();
check( await mPencil.isVisible(), 'phone: pencil visible once the row is expanded' );
check( ( await mPencil.evaluate( ( el ) => el.getBoundingClientRect().width ) ) >= 44, 'phone: pencil is a 44px target' );
check( ( await mPencil.evaluate( ( el ) => getComputedStyle( el ).opacity ) ) === '1', 'phone: pencil needs no hover' );
await mPencil.tap();
await mCell.locator( 'input[type="text"]' ).waitFor();
check( ! ( await mOverflows() ), 'no page-level horizontal scroll at 390 with the editor open' );
await mCell.scrollIntoViewIfNeeded();
if ( shots ) { await m.waitForTimeout( 300 ); await m.screenshot( { path: `${ shots }/edit-inline-390.png` } ); }
await m.setViewportSize( { width: 320, height: 640 } );
check( ! ( await mOverflows() ), 'no page-level horizontal scroll at 320 with the editor open' );
await ctx.close();

await b.close();
console.log( fail ? `${ fail } FAILED` : 'ALL PASSED' );
process.exit( fail ? 1 : 0 );
