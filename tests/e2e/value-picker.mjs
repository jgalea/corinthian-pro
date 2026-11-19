import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container, WP_BASE_URL to the site and
// SHOTS_DIR for screenshots. Seeds 620 users and 1,050 tags on the first run, which takes a minute.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
const base = process.env.WP_BASE_URL || 'http://localhost:8930';
const here = dirname( fileURLToPath( import.meta.url ) );
mkdirSync( SHOTS, { recursive: true } );

const docker = ( args, input ) => execFileSync( 'docker', [ 'exec', '-i', CLI, ...args ], { encoding: 'utf8', input, stdio: [ input ? 'pipe' : 'ignore', 'pipe', 'ignore' ] } ).trim();
const wp = ( ...args ) => docker( [ 'wp', ...args ] );
const seedFile = '/tmp/corinthian-picker-seed.php';
docker( [ 'sh', '-c', `cat > ${ seedFile }` ], readFileSync( join( here, 'helpers/picker-seed.php' ), 'utf8' ) );
const seed = JSON.parse( wp( 'eval-file', seedFile ) );
process.on( 'exit', () => {
	const key = wp( 'eval', "echo \\Corinthian\\Sets\\SetRepository::active_meta_key( 'post:post' );" );
	if ( seed.previous ) {
		wp( 'user', 'meta', 'update', '1', key, String( seed.previous ) );
	} else {
		wp( 'user', 'meta', 'delete', '1', key );
	}
} );

let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };
check( seed.users > 600 && seed.tags > 1000, `seeded ${ seed.users } users and ${ seed.tags } tags` );

const b = await chromium.launch();
const p = await b.newPage( { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' } );
p.setDefaultTimeout( 60000 );
const rows = async () => {
	const text = await p.$eval( '.tablenav.top .displaying-num', ( el ) => el.textContent ).catch( () => '0' );
	return Number( ( text.match( /\d[\d,]*/ ) || [ '0' ] )[ 0 ].replace( /,/g, '' ) );
};
const overflows = () => p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
const panel = () => p.locator( '#corinthian-filter-panel' );
const combo = ( n = 1 ) => p.getByRole( 'combobox', { name: `Value, rule ${ n }` } );
const listbox = () => panel().getByRole( 'listbox' );
const shot = ( name ) => p.screenshot( { path: `${ SHOTS }/${ name }.png` } );
const openPanel = async ( query = '' ) => {
	await p.goto( `${ base }/wp-admin/edit.php${ query }` );
	await p.click( '.corinthian-filter__toggle' );
	await panel().waitFor();
};
const pickColumn = async ( column, op, n = 1 ) => {
	await p.getByLabel( `Column, rule ${ n }` ).selectOption( { label: column } );
	await p.getByLabel( `Operator, rule ${ n }` ).selectOption( { label: op } );
};
const apply = () => Promise.all( [ p.waitForNavigation(), panel().getByRole( 'button', { name: 'Apply', exact: true } ).click() ] );

await p.goto( `${ base }/wp-login.php` );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

// The endpoint refuses a cookie request that carries no REST nonce.
const bare = await p.evaluate( async ( set ) => ( await fetch( `/?rest_route=/corinthian/v1/sets/${ set }/filter-values&column=tags`, { credentials: 'same-origin' } ) ).status, seed.set );
check( bare === 401, `no nonce: ${ bare }` );

// Tags: the old picker stopped at 500 checkboxes.
await openPanel();
await pickColumn( 'Tags', 'is any of' );
check( ( await p.locator( '.corinthian-filter__rule input[type=checkbox]' ).count() ) === 0, 'no checkbox list' );
const panelHeight = async () => ( await panel().boundingBox() ).height;
check( await panelHeight() < 400, `panel stays compact (${ Math.round( await panelHeight() ) }px)` );
await shot( '1440-closed' );

const searches = [];
p.on( 'request', ( r ) => r.url().includes( 'filter-values' ) && searches.push( new URL( r.url() ).searchParams.get( 'search' ) ) );
await combo().click();
await listbox().getByRole( 'option' ).first().waitFor();
check( ( await combo().getAttribute( 'aria-expanded' ) ) === 'true', 'aria-expanded is true when open' );
const firstPage = await listbox().getByRole( 'option' ).count();
check( firstPage === 20, `first page lists 20 (${ firstPage })` );
await shot( '1440-open' );

await listbox().evaluate( ( el ) => el.scrollTo( 0, el.scrollHeight ) );
await p.waitForFunction( ( n ) => document.querySelectorAll( '[role=listbox] [role=option]' ).length > n, firstPage );
check( ( await listbox().getByRole( 'option' ).count() ) > firstPage, 'scrolling to the end loads the next page' );

searches.length = 0;
await combo().pressSequentially( 'Zebra', { delay: 40 } );
await listbox().getByRole( 'option', { name: 'Zebra crossings' } ).waitFor();
check( searches.length <= 2, `typing is debounced (${ searches.length } requests for 5 keys)` );
await combo().press( 'ArrowDown' );
check( ( await combo().getAttribute( 'aria-activedescendant' ) ) !== null, 'ArrowDown sets aria-activedescendant' );
await combo().press( 'Enter' );
const token = panel().locator( '.corinthian-picker__token', { hasText: 'Zebra crossings' } );
check( await token.isVisible(), 'Enter adds a token' );
check( ( await listbox().getByRole( 'option', { name: 'Zebra crossings' } ).getAttribute( 'aria-selected' ) ) === 'true', 'picked option is aria-selected' );
await combo().fill( '' );
await combo().press( 'Backspace' );
check( ( await token.count() ) === 0, 'Backspace on an empty input removes the last token' );
await combo().fill( 'Zebra' );
await listbox().getByRole( 'option', { name: 'Zebra crossings' } ).click();
await combo().fill( 'Ancient b' );
await listbox().getByRole( 'option', { name: 'Ancient bakeries 421' } ).click();
await combo().fill( '' );
check( ( await panel().locator( '.corinthian-picker__token' ).count() ) === 2, 'two tokens' );
await shot( '1440-tokens' );

await combo().fill( 'qqqzzz' );
await panel().getByText( 'No values match “qqqzzz”.' ).waitFor();
check( true, 'no-match message' );
await shot( '1440-no-match' );
await combo().press( 'Escape' );
check( ( await combo().getAttribute( 'aria-expanded' ) ) === 'false' && await panel().isVisible(), 'Escape closes the list, not the panel' );
await combo().fill( '' );

// Remove the second token with its button, then apply.
await panel().getByRole( 'button', { name: /^Remove Ancient/ } ).click();
await apply();
check( ( await rows() ) === 1, `Tags is any of Zebra crossings: 1 row (${ await rows() })` );
const chip = await p.textContent( '.corinthian-filter__chips' );
check( chip.includes( 'Zebra crossings' ), `chip shows the name, not the id (${ chip.trim() })` );
await p.click( '.corinthian-filter__toggle' );
check( await panel().locator( '.corinthian-picker__token', { hasText: 'Zebra crossings' } ).isVisible(), 'token label survives reload' );

// Author: the old picker stopped at 200 users.
await openPanel();
await pickColumn( 'Author', 'is any of' );
await combo().fill( 'Zoe' );
await listbox().getByRole( 'option', { name: 'Zoe Zimmerman' } ).click();
await apply();
check( ( await rows() ) === 1, `Author is any of Zoe Zimmerman: 1 row (${ await rows() })` );

// Labels for values outside page 1 that arrive without a payload label (e.g. from the AI builder).
const looked = await p.evaluate( ( [ set, zebra ] ) => wp.apiFetch( { path: `/corinthian/v1/sets/${ set }/filter-values?column=tags&include[]=${ zebra }` } ), [ seed.set, seed.zebra ] );
check( looked.items?.[ 0 ]?.label === 'Zebra crossings', 'include returns the label' );

// A failed request says so and offers Retry.
await openPanel();
await pickColumn( 'Tags', 'is any of' );
const toValues = ( url ) => url.href.includes( 'filter-values' );
const blocked = ( route ) => route.abort();
await p.route( toValues, blocked );
await combo().click();
await panel().getByText( 'The values could not be loaded.' ).waitFor();
check( true, 'a failed request shows an error' );
await p.unroute( toValues, blocked );
await panel().getByRole( 'button', { name: 'Retry' } ).click();
await listbox().getByRole( 'option' ).first().waitFor();
check( ( await listbox().getByRole( 'option' ).count() ) === 20, 'Retry loads the values' );

// Priority: four values become a pick list, filtered locally.
await openPanel();
await p.getByLabel( 'Column, rule 1' ).selectOption( { label: 'Priority' } );
check( ( await p.getByLabel( 'Operator, rule 1' ).inputValue() ) === 'in', 'Priority defaults to "is any of"' );
searches.length = 0;
await combo().click();
const values = await listbox().getByRole( 'option' ).allTextContents();
check( [ 'high', 'low', 'medium', 'urgent' ].every( ( v ) => values.includes( v ) ), `Priority offers its values (${ values.join( ', ' ) })` );
check( searches.length === 0, 'a short pick list needs no request' );
await listbox().getByRole( 'option', { name: 'high' } ).click();
await listbox().getByRole( 'option', { name: 'urgent' } ).click();
await combo().press( 'Escape' );
await apply();
const expected = Number( wp( 'eval', "global $wpdb; echo (int) $wpdb->get_var( \"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key = 'priority' AND m.meta_value IN ('high','urgent')\" );" ) );
check( expected > 0 && ( await rows() ) === expected, `Priority is any of high, urgent: ${ await rows() } rows (want ${ expected })` );

// Free text keeps suggestions.
await openPanel();
await pickColumn( 'Priority', 'starts with' );
await combo().fill( 'ur' );
await listbox().getByRole( 'option', { name: 'urgent' } ).waitFor();
await combo().press( 'ArrowDown' );
await combo().press( 'Enter' );
check( ( await combo().inputValue() ) === 'urgent', 'a suggestion fills the text' );

// Phone width.
await p.setViewportSize( { width: 390, height: 844 } );
await openPanel();
await pickColumn( 'Tags', 'is any of' );
await combo().scrollIntoViewIfNeeded();
await shot( '390-closed' );
check( ! ( await overflows() ), 'no horizontal scroll at 390 (closed)' );
await combo().click();
await listbox().getByRole( 'option' ).first().waitFor();
await shot( '390-open' );
check( ! ( await overflows() ), 'no horizontal scroll at 390 (open)' );
const box = await p.locator( '.corinthian-picker__popup' ).boundingBox();
check( box.x >= 0 && box.x + box.width <= 390, `list fits the screen (${ Math.round( box.x ) }–${ Math.round( box.x + box.width ) })` );
for ( const name of [ 'Zebra crossings', 'Coastal walks 33', 'Golden markets 67' ] ) {
	await combo().fill( name );
	await listbox().getByRole( 'option', { name, exact: true } ).click();
}
await combo().fill( '' );
await combo().press( 'Escape' );
await shot( '390-tokens' );
check( await panelHeight() < 600, `panel with three tokens at 390 (${ Math.round( await panelHeight() ) }px)` );
await combo().fill( 'qqqzzz' );
await panel().getByText( 'No values match “qqqzzz”.' ).waitFor();
await shot( '390-no-match' );
await p.setViewportSize( { width: 320, height: 800 } );
check( ! ( await overflows() ), 'no horizontal scroll at 320' );

await b.close();
process.exit( fail ? 1 : 0 );
