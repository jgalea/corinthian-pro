import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container, WP_BASE_URL to the site and
// SHOTS_DIR to keep screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const base = process.env.WP_BASE_URL || 'http://localhost:8930';
const SHOTS = process.env.SHOTS_DIR || '';
const here = dirname( fileURLToPath( import.meta.url ) );
if ( SHOTS ) {
	mkdirSync( SHOTS, { recursive: true } );
}

const docker = ( args, input ) => execFileSync( 'docker', [ 'exec', '-i', CLI, ...args ], { encoding: 'utf8', input, stdio: [ input ? 'pipe' : 'ignore', 'pipe', 'ignore' ] } ).trim();
docker( [ 'sh', '-c', 'cat > /tmp/corinthian-chips-seed.php' ], readFileSync( join( here, 'helpers/chips-seed.php' ), 'utf8' ) );
const seed = JSON.parse( docker( [ 'wp', 'eval-file', '/tmp/corinthian-chips-seed.php' ] ) );
process.on( 'exit', () => {
	const key = docker( [ 'wp', 'eval', "echo \\Corinthian\\Sets\\SetRepository::active_meta_key( 'post:post' );" ] );
	if ( seed.previous ) {
		docker( [ 'wp', 'user', 'meta', 'update', '1', key, String( seed.previous ) ] );
	} else {
		docker( [ 'wp', 'user', 'meta', 'delete', '1', key ] );
	}
} );

let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };

const rules = [
	{ column: 'title', op: 'contains', value: 'task' },
	{ column: 'priority', op: 'eq', value: 'high' },
	{ column: 'tags', op: 'empty' },
];
const url = ( match, list = rules ) => `${ base }/wp-admin/edit.php?corinthian_filter=${ encodeURIComponent( JSON.stringify( { match, rules: list } ) ) }`;

const b = await chromium.launch();
const p = await b.newPage( { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' } );
p.setDefaultTimeout( 30000 );
await p.goto( `${ base }/wp-login.php` );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

const load = async ( href ) => {
	await p.goto( href );
	await p.waitForSelector( '.corinthian-filter__toggle' );
};
const shot = async ( name ) => SHOTS && p.screenshot( { path: join( SHOTS, `${ name }.png` ) } );
const overflows = () => p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
// Every control in the toolbar's first row: the buttons that must not move when filters change.
const toolbarBoxes = () => p.evaluate( () => {
	const bar = document.querySelector( '.corinthian-toolbar' );
	const boxes = {};
	bar.querySelectorAll( 'button, a' ).forEach( ( el ) => {
		if ( el.closest( '.corinthian-filter__chiprow, .corinthian-filter__panel, .corinthian-segments__toast-region' ) ) {
			return;
		}
		const r = el.getBoundingClientRect();
		if ( r.width && r.height ) {
			boxes[ ( el.textContent || el.getAttribute( 'aria-label' ) || el.className ).trim().replace( /\d+$/, '' ) ] = [ Math.round( r.x ), Math.round( r.y ), Math.round( r.width ) ];
		}
	} );
	return boxes;
} );

for ( const width of [ 1440, 768, 390, 320 ] ) {
	await p.setViewportSize( { width, height: 900 } );
	await load( `${ base }/wp-admin/edit.php` );
	const before = await toolbarBoxes();
	check( Object.keys( before ).length >= 3, `${ width }px: ${ Object.keys( before ).length } toolbar controls measured (${ Object.keys( before ).join( ', ' ) })` );
	await shot( `toolbar-0-chips-${ width }` );
	await load( url( 'any' ) );
	const after = await toolbarBoxes();
	check( JSON.stringify( before ) === JSON.stringify( after ), `${ width }px: toolbar controls stay put with three filters` );
	if ( JSON.stringify( before ) !== JSON.stringify( after ) ) {
		console.log( '  before', JSON.stringify( before ), '\n  after ', JSON.stringify( after ) );
	}
	check( ! ( await overflows() ), `${ width }px: no horizontal scroll with three chips` );
	const layout = await p.evaluate( () => {
		const row = document.querySelector( '.corinthian-filter__chiprow' ).getBoundingClientRect();
		const toggle = document.querySelector( '.corinthian-filter__toggle' ).getBoundingClientRect();
		const buttons = [ ...document.querySelectorAll( '.corinthian-toolbar button' ) ].filter( ( el ) => ! el.closest( '.corinthian-filter__chiprow, .corinthian-filter__panel' ) );
		return { rowTop: row.top, lowest: Math.max( ...buttons.map( ( el ) => el.getBoundingClientRect().bottom ) ), clipped: row.right > window.innerWidth + 1 || row.left < 0, toggleBottom: toggle.bottom };
	} );
	check( layout.rowTop >= layout.lowest - 1 && ! layout.clipped, `${ width }px: chips sit on a row below every toolbar button` );
	await shot( `toolbar-3-chips-${ width }` );
}

await p.setViewportSize( { width: 1440, height: 900 } );
await load( url( 'any' ) );
check( ( await p.locator( '.corinthian-filter__chip' ).count() ) === 3, 'three chips' );
check( await p.getByText( 'Any of:' ).isVisible(), 'chip row says "Any of:" for an any match' );
check( await p.getByRole( 'button', { name: 'Clear all' } ).isVisible(), 'Clear all link on the chip row' );
check( ( await p.getByRole( 'button', { name: /^Remove filter/ } ).count() ) === 3, 'each chip keeps a remove button' );
await shot( 'chips-any-1440' );

await load( url( 'all' ) );
check( ! ( await p.getByText( 'Any of:' ).count() ), 'no "Any of:" for an all match' );

await load( url( 'any' ) );
await p.locator( '.corinthian-filter__chip-edit' ).nth( 1 ).click();
await p.waitForSelector( '#corinthian-filter-panel' );
const focused = await p.evaluate( () => {
	const li = document.activeElement?.closest( '.corinthian-filter__rule' );
	return li ? [ ...li.parentNode.children ].indexOf( li ) : -1;
} );
check( focused === 1, `clicking the second chip focuses rule 2 (rule ${ focused + 1 })` );
check( ( await p.getByLabel( 'Column, rule 2' ).inputValue() ) === 'priority', 'the focused rule is the chip\'s rule' );
await shot( 'chip-open-1440' );
await p.keyboard.press( 'Escape' );
await p.locator( '.corinthian-filter__chip-edit' ).nth( 2 ).click();
const ruleIndex = async () => {
	await p.waitForTimeout( 300 );
	return p.evaluate( () => [ ...document.querySelectorAll( '.corinthian-filter__rule' ) ].indexOf( document.activeElement?.closest( '.corinthian-filter__rule' ) ) );
};
const idx3 = await ruleIndex();
check( idx3 === 2, `reopening the panel from the third chip focuses rule 3 (got index ${ idx3 }, panel ${ await p.locator( '#corinthian-filter-panel' ).count() })` );
await p.locator( '.corinthian-filter__chip-edit' ).nth( 0 ).click();
check( ( await ruleIndex() ) === 0, 'with the panel already open, another chip moves focus to its rule' );

await p.setViewportSize( { width: 1440, height: 420 } );
await load( url( 'any' ) );
await p.evaluate( () => window.scrollTo( 0, 120 ) );
const y = await p.evaluate( () => window.scrollY );
check( y > 80, `page scrolled to ${ y } before removing a chip` );
await Promise.all( [ p.waitForNavigation(), p.getByRole( 'button', { name: /^Remove filter Tags/ } ).click() ] );
await p.waitForSelector( '.corinthian-filter__toggle' );
await p.waitForTimeout( 400 );
check( ( await p.locator( '.corinthian-filter__chip' ).count() ) === 2, 'chip removed' );
const y2 = await p.evaluate( () => window.scrollY );
check( Math.abs( y2 - y ) < 40, `scroll kept after removing a chip (${ y } to ${ y2 })` );

await p.evaluate( () => window.scrollTo( 0, 120 ) );
await Promise.all( [ p.waitForNavigation(), p.getByRole( 'button', { name: 'Clear all' } ).click() ] );
await p.waitForSelector( '.corinthian-filter__toggle' );
await p.waitForTimeout( 400 );
check( ! new URL( p.url() ).searchParams.has( 'corinthian_filter' ), 'Clear all drops the filter from the URL' );
check( ( await p.locator( '.corinthian-filter__chiprow' ).count() ) === 0, 'no chip row once cleared' );
const y3 = await p.evaluate( () => window.scrollY );
check( Math.abs( y3 - y ) < 40, `scroll kept after Clear all (${ y3 })` );

// The bar mounts after the page loads: with the script held back, the table's top edge must not move when it arrives.
await p.route( '**/corinthian-pro/assets/build/table-pro.js*', async ( route ) => {
	await new Promise( ( resolve ) => setTimeout( resolve, 1200 ) );
	route.continue();
} );
for ( const [ width, href, exact ] of [ [ 1440, `${ base }/wp-admin/edit.php`, true ], [ 768, `${ base }/wp-admin/edit.php`, true ], [ 390, `${ base }/wp-admin/edit.php`, true ], [ 320, `${ base }/wp-admin/edit.php`, true ], [ 1440, url( 'any' ), true ], [ 768, url( 'any' ), true ], [ 390, url( 'any' ), false ] ] ) {
	await p.setViewportSize( { width, height: 900 } );
	await p.goto( href, { waitUntil: 'commit' } );
	await p.waitForSelector( 'table.wp-list-table', { state: 'attached' } );
	const tableTop = () => p.evaluate( () => document.querySelector( 'table.wp-list-table' ).getBoundingClientRect().top );
	const early = await tableTop();
	await p.waitForSelector( '.corinthian-filter__toggle' );
	await p.waitForTimeout( 300 );
	const late = await tableTop();
	const note = `${ width }px${ href.includes( 'corinthian_filter' ) ? ' with filters' : '' }: table top ${ Math.round( early ) } before the bar mounts, ${ Math.round( late ) } after`;
	if ( exact ) {
		check( Math.abs( early - late ) <= 1, `${ note }, no layout shift` );
	} else {
		console.log( 'INFO', note, '(chips wrap, so the reserve is an estimate)' );
	}
}
await p.unroute( '**/corinthian-pro/assets/build/table-pro.js*' );

await p.setViewportSize( { width: 390, height: 844 } );
await load( url( 'any' ) );
await shot( 'chips-any-390' );
await p.locator( '.corinthian-filter__chip-edit' ).nth( 1 ).click();
await p.waitForSelector( '#corinthian-filter-panel' );
await p.waitForTimeout( 300 );
check( ! ( await overflows() ), '390px: no horizontal scroll with a chip open in the panel' );
await shot( 'chip-open-390' );

await b.close();
console.log( fail ? `${ fail } FAILED` : 'ALL PASSED' );
process.exit( fail ? 1 : 0 );
