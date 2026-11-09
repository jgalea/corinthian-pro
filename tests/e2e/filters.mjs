import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';

const base = 'http://localhost:8930';
const shots = process.env.SHOTS || '';
const wp = ( cmd ) => execSync( `docker exec ${ CLI } wp ${ cmd }`, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
// Own fixture: three fresh posts carrying a status unique to this run, trashed on exit.
// Counts compare against the live total, so other tests' posts don't matter.
const stamp = `Draft review ${ Date.now() }`;
const fixtures = [ 1, 2, 3 ].map( () => Number( wp( 'post create --post_title="Filter fixture" --post_status=publish --post_author=1 --porcelain' ) ) );
fixtures.forEach( ( id ) => wp( `post meta update ${ id } status "${ stamp }"` ) );
process.on( 'exit', () => fixtures.forEach( ( id ) => wp( `post delete ${ id } --force` ) ) );
const b = await chromium.launch();
const p = await b.newPage( { viewport: { width: 1440, height: 900 } } );
let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };
// Total matching items from WordPress's own "N items" count, not just the rows on this page.
const rows = async () => {
	const text = await p.$eval( '.tablenav.top .displaying-num', ( el ) => el.textContent ).catch( () => '0' );
	return Number( ( text.match( /\d[\d,]*/ ) || [ '0' ] )[ 0 ].replace( /,/g, '' ) );
};
const open = async ( query = '' ) => {
	await p.goto( `${ base }/wp-admin/edit.php${ query }` );
	await p.waitForSelector( '.corinthian-filter__toggle' );
};
const overflows = () => p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );

await p.goto( `${ base }/wp-login.php` );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

const segmentName = `E2E segment ${ Date.now() }`;
// Remove leftovers from an earlier failed run.
const cleanup = async () => {
	await p.goto( `${ base }/wp-admin/edit.php` );
	await p.evaluate( async () => {
		const c = window.corinthianTable;
		for ( const s of c.filter.segments.filter( ( x ) => x.name.startsWith( 'E2E segment' ) ) ) {
			await wp.apiFetch( { path: `/corinthian/v1/sets/${ c.set }/segments/${ s.id }`, method: 'DELETE' } );
		}
	} );
};
await cleanup();

await open();
const total = await rows();
check( total >= 3, `control: ${ total } items without a filter` );
check( ( await p.$$( '.corinthian-filter__badge' ) ).length === 0, 'no badge when nothing is filtered' );

await p.click( '.corinthian-filter__toggle' );
await p.getByLabel( 'Column, rule 1' ).selectOption( { label: 'Status' } );
await p.getByLabel( 'Operator, rule 1' ).selectOption( { label: 'is' } );
const value = p.getByLabel( 'Value, rule 1' );
if ( ( await value.evaluate( ( el ) => el.tagName ) ) === 'SELECT' ) {
	await value.selectOption( { label: stamp } );
} else {
	await value.fill( stamp );
}
if ( shots ) {
	await p.screenshot( { path: `${ shots }/filters-1440.png` } );
	await p.setViewportSize( { width: 390, height: 844 } );
	await p.screenshot( { path: `${ shots }/filters-390.png` } );
	check( ! ( await overflows() ), 'no horizontal page scroll at 390px' );
	await p.setViewportSize( { width: 320, height: 800 } );
	check( ! ( await overflows() ), 'no horizontal page scroll at 320px' );
	await p.setViewportSize( { width: 1440, height: 900 } );
}
await Promise.all( [ p.waitForNavigation(), p.locator( '#corinthian-filter-panel' ).getByRole( 'button', { name: 'Apply', exact: true } ).click() ] );
const url = new URL( p.url() );
check( url.searchParams.has( 'corinthian_filter' ) && ! url.searchParams.has( 'paged' ), 'Apply sets the param and drops paged' );
check( ( await rows() ) === 3, 'Status filter: exactly the 3 fixture posts' );
check( ( await p.textContent( '.corinthian-filter__badge' ) ).trim() === '1', 'badge shows 1' );
check( ( await p.textContent( '.corinthian-filter__chips' ) ).includes( `Status is ${ stamp }` ), 'chip reads "Status is <value>"' );

// Save as a personal segment.
await p.click( '.corinthian-filter__toggle' );
await p.getByLabel( 'Segment name' ).fill( segmentName );
const created = p.waitForResponse( ( r ) => r.url().includes( '/segments' ) && r.request().method() === 'POST' );
await p.getByRole( 'button', { name: 'Save as segment' } ).click();
check( ( await created ).status() === 201, 'segment saved (201)' );

// Clear, then pick the segment.
await Promise.all( [ p.waitForNavigation(), p.locator( '#corinthian-filter-panel' ).getByRole( 'button', { name: 'Clear', exact: true } ).click() ] );
check( ! new URL( p.url() ).searchParams.has( 'corinthian_filter' ), 'Clear removes the param' );
check( ( await rows() ) === total, 'cleared: back to the full total' );
await p.getByRole( 'button', { name: 'Segments' } ).click();
check( await p.getByRole( 'heading', { name: 'My segments' } ).isVisible(), 'segment listed under My segments' );
await Promise.all( [ p.waitForNavigation(), p.getByRole( 'button', { name: segmentName, exact: true } ).click() ] );
check( ( await rows() ) === 3, 'picked segment: 3 rows' );

// Remove through the chip.
await Promise.all( [ p.waitForNavigation(), p.getByRole( 'button', { name: /^Remove filter Status/ } ).click() ] );
check( ( await rows() ) === total, 'removing the chip clears the filter' );

// Delete with the confirm modal.
await p.getByRole( 'button', { name: 'Segments' } ).click();
await p.getByRole( 'button', { name: `Delete segment ${ segmentName }` } ).click();
const modal = p.getByRole( 'dialog' );
check( await modal.isVisible(), 'delete asks in a Modal' );
const deleted = p.waitForResponse( ( r ) => r.url().includes( '/segments/' ) && r.request().method() !== 'GET' );
await modal.getByRole( 'button', { name: 'Delete', exact: true } ).click();
check( ( await deleted ).status() === 200, 'segment deleted (200)' );
await p.getByRole( 'button', { name: 'Segments' } ).click();
check( ( await p.getByRole( 'button', { name: segmentName, exact: true } ).count() ) === 0, 'segment gone from the list' );

// Resizing still saves.
await open();
await p.$eval( 'th#title .corinthian-resize', ( h ) => h.scrollIntoView( { block: 'nearest', inline: 'nearest' } ) );
const box = await p.$eval( 'th#title .corinthian-resize', ( h ) => { const r = h.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; } );
const saved = p.waitForResponse( ( r ) => r.url().includes( 'corinthian/v1/widths' ) && r.request().method() === 'POST' );
await p.mouse.move( box.x, box.y );
await p.mouse.down();
await p.mouse.move( box.x - 60, box.y, { steps: 6 } );
await p.mouse.up();
check( ( await saved ).status() === 200, 'drag-resize POSTs to /widths' );
// Put the widths back.
await p.evaluate( ( c ) => wp.apiFetch( { path: `${ c.path }?screen=${ encodeURIComponent( c.screen ) }&set=${ c.set }`, method: 'DELETE' } ), await p.evaluate( () => window.corinthianTable ) );

await cleanup();
await b.close();
process.exit( fail ? 1 : 0 );
