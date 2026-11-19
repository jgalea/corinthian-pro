import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Filter bar states: native filter row hidden, stale-link notice, empty state, inline validation
// and keyboard behaviour. Set WP_CLI_CONTAINER to the wp-env cli container, WP_BASE_URL to the
// site and SHOTS_DIR to keep screenshots. Seeds posts, an Events type and a column set per screen.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
const base = process.env.WP_BASE_URL || 'http://localhost:8930';
const here = dirname( fileURLToPath( import.meta.url ) );
mkdirSync( SHOTS, { recursive: true } );

const docker = ( args, input ) => execFileSync( 'docker', [ 'exec', '-i', CLI, ...args ], { encoding: 'utf8', input, stdio: [ input ? 'pipe' : 'ignore', 'pipe', 'ignore' ] } ).trim();
docker( [ 'sh', '-c', 'cat > /tmp/corinthian-states-seed.php' ], readFileSync( join( here, 'helpers/states-seed.php' ), 'utf8' ) );
const sets = JSON.parse( docker( [ 'wp', 'eval-file', '/tmp/corinthian-states-seed.php' ] ) );

let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };

const b = await chromium.launch();
const p = await b.newPage( { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' } );
p.setDefaultTimeout( 30000 );
const shot = ( name ) => p.screenshot( { path: `${ SHOTS }/${ name }.png` } );
const panel = () => p.locator( '#corinthian-filter-panel' );
const toggle = () => p.locator( '.corinthian-filter__toggle' );
const filterUrl = ( filter ) => `${ base }/wp-admin/edit.php?corinthian_filter=${ encodeURIComponent( typeof filter === 'string' ? filter : JSON.stringify( filter ) ) }`;
const inPanel = () => p.evaluate( () => !! document.activeElement?.closest( '#corinthian-filter-panel' ) );
const onToggle = () => p.evaluate( () => document.activeElement?.classList.contains( 'corinthian-filter__toggle' ) );
const overflows = () => p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );

await p.goto( `${ base }/wp-login.php` );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

// 2. WordPress's own filter row is gone wherever the Corinthian bar renders.
const NATIVE = '#post-query-submit, #filter-by-date, #cat, #filter-by-comment-type';
const nativeVisible = () => p.evaluate( ( sel ) => [ ...document.querySelectorAll( sel ) ].filter( ( el ) => el.getClientRects().length && getComputedStyle( el ).visibility !== 'hidden' ).map( ( el ) => el.id ), NATIVE );
const screens = {
	Posts: 'edit.php',
	Pages: 'edit.php?post_type=page',
	Events: 'edit.php?post_type=event',
	Users: 'users.php',
	Comments: 'edit-comments.php',
};
for ( const [ name, url ] of Object.entries( screens ) ) {
	await p.goto( `${ base }/wp-admin/${ url }` );
	await toggle().waitFor();
	const left = await nativeVisible();
	check( left.length === 0, `${ name }: no native filter controls (${ left.join( ', ' ) || 'none' })` );
}
await p.goto( `${ base }/wp-admin/users.php` );
await toggle().waitFor();
check( await p.locator( '#changeit' ).isVisible(), 'Users: the bulk role change still shows' );
await p.goto( `${ base }/wp-admin/edit.php?post_type=note` );
const kept = await nativeVisible();
check( ( await toggle().count() ) === 0 && kept.includes( 'filter-by-date' ) && kept.includes( 'post-query-submit' ), `a screen without the bar keeps core's filter row (${ kept.join( ', ' ) })` );

await p.goto( `${ base }/wp-admin/edit.php` );
await toggle().waitFor();
await shot( '1440-posts-no-native-filter' );

// 3a. A stale link says which rules were left out.
const notice = () => p.locator( '.corinthian-filter__notice' );
await p.goto( filterUrl( { match: 'all', rules: [ { column: 'nope', op: 'eq', value: 'x' }, { column: 'slug', op: 'contains', value: 'states-post-0' } ] } ) );
await toggle().waitFor();
check( await notice().isVisible(), 'stale link: a notice shows' );
const text = ( await notice().textContent().catch( () => '' ) ) || '';
check( text.includes( 'nope' ) && text.includes( "can't be filtered" ) && ! text.includes( 'nope eq' ), `notice names the dropped rule and why (${ text.trim().slice( 0, 90 ) })` );
check( ( await p.locator( '.corinthian-filter__chip' ).count() ) === 1, 'the valid rule still applies' );
await shot( '1440-dropped-notice' );
await notice().getByRole( 'button', { name: /close|dismiss/i } ).click();
check( ! ( await notice().isVisible().catch( () => false ) ), 'the notice can be dismissed' );

await p.goto( filterUrl( '{not json' ) );
await toggle().waitFor();
check( ( await notice().textContent().catch( () => '' ) ).includes( "couldn't be read" ), 'unreadable link: notice says so' );

await p.goto( `${ base }/wp-admin/edit.php` );
await toggle().waitFor();
check( ! ( await notice().isVisible().catch( () => false ) ), 'no notice without a filter' );

// 3b. Apply checks incomplete rules instead of dropping them.
await toggle().click();
await panel().waitFor();
const here0 = p.url();
await panel().getByRole( 'button', { name: 'Apply', exact: true } ).click();
await p.waitForTimeout( 600 );
const ruleError = p.locator( '.corinthian-filter__rule-error' );
check( p.url() === here0, 'Apply with an empty rule stays on the page' );
check( await ruleError.first().isVisible(), `the empty rule says what is missing (${ ( await ruleError.first().textContent().catch( () => '' ) ).trim() })` );
check( await p.evaluate( () => !! document.activeElement?.closest( '.corinthian-filter__rule' ) ), 'focus moves into the rule that needs fixing' );
await shot( '1440-apply-validation' );

await p.getByLabel( 'Column, rule 1' ).selectOption( { label: 'Slug' } );
await p.getByLabel( 'Operator, rule 1' ).selectOption( { label: 'contains' } );
check( ( await ruleError.count() ) === 0, 'the error clears when the rule changes' );
await p.getByLabel( 'Value, rule 1' ).fill( 'states-post-0' );
await Promise.all( [ p.waitForNavigation(), panel().getByRole( 'button', { name: 'Apply', exact: true } ).click() ] );
check( p.url().includes( 'corinthian_filter' ), 'a complete rule applies' );

// 9a. The count is announced after a filter applies.
const spoken = await p.evaluate( () => document.getElementById( 'a11y-speak-polite' )?.textContent || '' );
check( /\d+ items? match/i.test( spoken ), `result count announced (${ spoken.trim() })` );

// 3c. Zero matches shows a filter-aware empty state.
await p.goto( filterUrl( { rules: [ { column: 'slug', op: 'eq', value: 'no-such-slug-anywhere' } ] } ) );
await toggle().waitFor();
const empty = p.locator( 'tr.no-items .corinthian-filter-empty' );
check( await empty.isVisible(), 'zero matches: filter-aware empty state in the table' );
check( ( await empty.textContent() ).toLowerCase().includes( 'filter' ), `message mentions the filters (${ ( await empty.textContent() ).trim().slice( 0, 80 ) })` );
const spokenEmpty = await p.evaluate( () => document.getElementById( 'a11y-speak-polite' )?.textContent || '' );
check( true, `(announcement on a direct visit: "${ spokenEmpty.trim() }")` );
await shot( '1440-empty-state' );
await Promise.all( [ p.waitForNavigation(), empty.getByRole( 'button', { name: 'Clear filters' } ).click() ] );
check( ! p.url().includes( 'corinthian_filter' ), 'Clear filters removes the filter from the URL' );
check( ( await p.locator( 'tr.no-items' ).count() ) === 0, 'rows are back' );
const cleared = await p.evaluate( () => document.getElementById( 'a11y-speak-polite' )?.textContent || '' );
check( /cleared/i.test( cleared ), `clearing is announced (${ cleared.trim() })` );

// 9b. Keyboard.
await p.goto( `${ base }/wp-admin/edit.php` );
await toggle().focus();
await p.keyboard.press( 'Enter' );
await panel().waitFor();
check( await inPanel(), 'opening the panel moves focus into it' );
await shot( '1440-panel-focus' );
await p.keyboard.press( 'Escape' );
check( ! ( await panel().isVisible().catch( () => false ) ) && await onToggle(), 'Escape inside the panel closes it and returns focus to the toggle' );
await p.keyboard.press( 'Enter' );
await panel().waitFor();
await toggle().focus();
await p.keyboard.press( 'Tab' );
check( await inPanel(), 'Tab from the toggle lands in the open panel' );
await p.getByRole( 'button', { name: 'Add rule' } ).focus();
await p.keyboard.press( 'Escape' );
check( ! ( await panel().isVisible().catch( () => false ) ) && await onToggle(), 'Escape from the action row closes it' );
await toggle().click();
await panel().waitFor();
await p.locator( '.corinthian-segments__toggle, .corinthian-filter__segments-toggle' ).first().focus();
await p.keyboard.press( 'Escape' );
check( ! ( await panel().isVisible().catch( () => false ) ) && await onToggle(), 'Escape from the segments button (in the bar) closes the panel and focuses the toggle' );

// Layout: no horizontal scroll and the toolbar stays put at every width.
for ( const width of [ 1440, 768, 390, 320 ] ) {
	await p.setViewportSize( { width, height: 900 } );
	await p.goto( `${ base }/wp-admin/edit.php` );
	await toggle().waitFor();
	const rest = await toggle().boundingBox();
	await toggle().click();
	await panel().waitFor();
	const open = await toggle().boundingBox();
	check( rest.y === open.y && rest.x === open.x, `${ width }: toggle doesn't move when the panel opens` );
	await panel().getByRole( 'button', { name: 'Apply', exact: true } ).click();
	await p.waitForTimeout( 300 );
	check( ! ( await overflows() ), `${ width }: no horizontal scroll with the panel and a rule error` );
	if ( [ 1440, 390 ].includes( width ) ) {
		await shot( `${ width }-panel-error` );
	}
	const contains = { column: 'slug', op: 'contains', value: 'states' };
	await p.goto( filterUrl( { rules: [ contains ] } ) );
	await toggle().waitFor();
	const plain = await toggle().boundingBox();
	await p.goto( filterUrl( { rules: [ { column: 'nope', op: 'eq', value: 'x' }, contains ] } ) );
	await toggle().waitFor();
	const withNotice = await toggle().boundingBox();
	check( withNotice.y === plain.y && withNotice.x === plain.x, `${ width }: the notice doesn't move the toggle` );
	check( ! ( await overflows() ), `${ width }: no horizontal scroll with the notice` );
	await p.goto( filterUrl( { rules: [ { column: 'nope', op: 'eq', value: 'x' }, { column: 'slug', op: 'eq', value: 'no-such-slug-anywhere' } ] } ) );
	await toggle().waitFor();
	check( ! ( await overflows() ), `${ width }: no horizontal scroll with notice and empty state` );
	if ( [ 1440, 390 ].includes( width ) ) {
		await shot( `${ width }-notice-and-empty` );
	}
}
await p.setViewportSize( { width: 390, height: 900 } );
await p.goto( `${ base }/wp-admin/edit.php` );
await toggle().waitFor();
await toggle().click();
await panel().waitFor();
await shot( '390-panel-focus' );
await p.goto( `${ base }/wp-admin/edit.php` );
await shot( '390-posts-no-native-filter' );

await b.close();
console.log( fail ? `${ fail } FAILED` : 'ALL PASSED' );
process.exit( fail ? 1 : 0 );
