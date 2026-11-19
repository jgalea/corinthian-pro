import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';
// Segments end to end: save, apply, edited state, update, rename, save choice, default on load, delete with undo.
// Set WP_CLI_CONTAINER to your wp-env cli container, WP_BASE to the site URL and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const base = process.env.WP_BASE || 'http://localhost:8930';
const shots = process.env.SHOTS_DIR || '';
const wp = ( cmd ) => execSync( `docker exec ${ CLI } wp ${ cmd }`, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const php = ( code ) => wp( `eval '${ code.replace( /'/g, `'"'"'` ) }'` );

// Own column set and posts, so the counts don't depend on what else is on the site.
const stamp = `Segrun${ Date.now() }`;
const set = Number( php( `
	$set = Corinthian\\Plugin::instance()->sets->save( array( "screen" => "post:post", "title" => "E2E segments", "columns" => array( array( "id" => "status", "type" => "post.status", "label" => "Status" ) ) ) );
	update_user_meta( 1, Corinthian\\Sets\\SetRepository::active_meta_key( "post:post" ), $set->id );
	echo $set->id;
` ) );
const posts = [ 'draft', 'draft', 'publish' ].map( ( status, i ) => Number( wp( `post create --post_title="${ stamp } ${ String.fromCharCode( 67 - i ) }" --post_status=${ status } --post_author=1 --porcelain` ) ) );
process.on( 'exit', () => {
	posts.forEach( ( id ) => wp( `post delete ${ id } --force` ) );
	php( `Corinthian\\Plugin::instance()->sets->delete( ${ set } ); delete_metadata( "user", 0, "corinthian_segments_${ set }", "", true ); delete_metadata( "user", 0, "corinthian_segments_default_${ set }", "", true );` );
} );

const b = await chromium.launch();
const p = await b.newPage( { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' } );
let fail = 0;
const check = ( ok, msg ) => {
	console.log( ok ? 'PASS' : 'FAIL', msg );
	if ( ! ok ) {
		fail++;
	}
};
const rows = async () => {
	const text = await p.$eval( '.tablenav.top .displaying-num', ( el ) => el.textContent ).catch( () => '0' );
	return Number( ( text.match( /\d[\d,]*/ ) || [ '0' ] )[ 0 ].replace( /,/g, '' ) );
};
const visit = async ( query = '' ) => {
	await p.goto( `${ base }/wp-admin/edit.php${ query }` );
	await p.waitForSelector( '.corinthian-filter__segments-toggle' );
};
const toggle = () => p.locator( '.corinthian-filter__segments-toggle' );
const label = async () => ( await toggle().innerText() ).replace( /\s+/g, ' ' ).trim();
const menu = () => p.locator( '.corinthian-segments__menu' );
const openMenu = async () => {
	await toggle().click();
	await menu().waitFor();
};
const rowAction = async ( name, action ) => {
	await p.getByRole( 'button', { name: `Actions for ${ name }` } ).click();
	await p.getByRole( 'menuitem', { name: action } ).click();
};
// apiFetch sends PUT, PATCH and DELETE as POST with an override header.
const sent = ( r, part, method ) => {
	const real = r.request().headers()[ 'x-http-method-override' ] || r.request().method();
	return r.url().includes( part ) && real === method;
};
const api = ( path, method = 'GET', data ) => p.evaluate( ( [ path, method, data ] ) => wp.apiFetch( { path, method, data } ), [ path, method, data ] );
const shoot = async ( name, width = 1440 ) => {
	if ( ! shots ) {
		return;
	}
	await p.setViewportSize( { width, height: width < 800 ? 844 : 900 } );
	await p.screenshot( { path: `${ shots }/${ name }-${ width }.png` } );
	check( ! ( await p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth ) ), `${ name }: no horizontal scroll at ${ width }px` );
	await p.setViewportSize( { width: 1440, height: 900 } );
};
const shootBoth = async ( name, reopen ) => {
	await shoot( name, 1440 );
	if ( shots ) {
		await p.setViewportSize( { width: 390, height: 844 } );
		if ( reopen ) {
			await reopen();
		}
		await p.screenshot( { path: `${ shots }/${ name }-390.png` } );
		check( ! ( await p.evaluate( () => document.documentElement.scrollWidth > window.innerWidth ) ), `${ name }: no horizontal scroll at 390px` );
		await p.setViewportSize( { width: 1440, height: 900 } );
	}
};

await p.goto( `${ base }/wp-login.php` );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

const drafts = { match: 'all', rules: [ { column: 'status', op: 'in', value: [ 'draft' ] } ] };
const filtered = `?s=${ stamp }&orderby=title&order=asc&corinthian_filter=${ encodeURIComponent( JSON.stringify( drafts ) ) }`;

// Control: the search alone finds all three posts, the rule cuts it to the two drafts.
await visit( `?s=${ stamp }` );
check( ( await rows() ) === 3, 'control: the search finds the 3 fixture posts' );
await visit( filtered );
check( ( await rows() ) === 2, 'control: search plus the draft rule gives 2' );
check( ( await label() ) === 'Segments', 'no segment label before one is applied' );

// Save the applied view.
const name = `E2E seg ${ stamp }`;
await p.click( '.corinthian-filter__toggle' );
await p.getByLabel( 'Segment name' ).fill( name );
const created = p.waitForResponse( ( r ) => sent( r, '/segments', 'POST' ) );
await p.getByRole( 'button', { name: 'Save as segment' } ).click();
const saved = await ( await created ).json();
check( saved.name === name && saved.search === stamp && saved.orderby === 'title' && saved.order === 'asc' && saved.filter.rules.length === 1, 'segment saved with rules, sort and search' );
check( new URL( p.url() ).searchParams.get( 'corinthian_segment' ) === saved.id, 'saving marks the segment active in the URL' );
check( ( await label() ).includes( name ) && ! ( await label() ).includes( 'edited' ), 'label shows the new segment, not edited' );

// Apply it from a bare list with the keyboard.
await visit();
const total = await rows();
check( total >= 3, `bare list shows everything (${ total })` );
await toggle().focus();
await p.keyboard.press( 'Enter' );
await menu().waitFor();
await p.keyboard.press( 'End' );
const focused = await p.evaluate( () => document.activeElement?.textContent || '' );
check( focused.includes( name ), 'End moves focus to the last segment' );
await shootBoth( 'segments-menu', async () => {
	await p.keyboard.press( 'Escape' );
	await openMenu();
} );
await p.keyboard.press( 'Escape' );
await openMenu();
await p.keyboard.press( 'End' );
await Promise.all( [ p.waitForNavigation(), p.keyboard.press( 'Enter' ) ] );
const applied = new URL( p.url() ).searchParams;
check( applied.get( 's' ) === stamp && applied.get( 'orderby' ) === 'title' && applied.get( 'order' ) === 'asc', 'Enter applies the segment with its sort and search' );
check( ( await rows() ) === 2, 'applied segment: 2 rows' );
const titles = await p.$$eval( 'table.wp-list-table tbody .row-title', ( a ) => a.map( ( el ) => el.textContent.trim() ) );
check( titles.join( '|' ) === `${ stamp } B|${ stamp } C`, `sorted by title ascending (${ titles.join( ', ' ) })` );
check( ( await label() ) === `Segment: ${ name }`, `active label reads "Segment: name" (${ await label() })` );
await shootBoth( 'active-label' );

// Change the sort: the label marks the segment as edited.
await Promise.all( [ p.waitForNavigation(), p.click( 'th#title a' ) ] );
check( new URL( p.url() ).searchParams.get( 'order' ) === 'desc', 'header click sorts descending' );
check( ( await label() ).endsWith( '· edited' ), `edited after a sort change (${ await label() })` );
await openMenu();
await shootBoth( 'edited', async () => {
	await p.keyboard.press( 'Escape' );
	await openMenu();
} );
const updateBtn = p.getByRole( 'button', { name: `Update “${ name }” with current filters` } );
check( await updateBtn.isVisible(), 'menu offers Update with current filters' );
const patched = p.waitForResponse( ( r ) => sent( r, `/segments/${ saved.id }`, 'PATCH' ) );
await updateBtn.click();
check( ( await patched ).status() === 200, 'update saved (200)' );
await p.waitForFunction( () => ! document.querySelector( '.corinthian-filter__segments-toggle' ).textContent.includes( 'edited' ) );
check( ! ( await label() ).includes( 'edited' ), 'no longer edited after the update' );
check( ( await api( `/corinthian/v1/sets/${ set }/segments` ) ).find( ( s ) => s.id === saved.id ).order === 'desc', 'stored order is now desc' );

// Search from WordPress's own box keeps the segment and its rules.
await visit( `?corinthian_segment=${ saved.id }&s=${ stamp }&orderby=title&order=desc&corinthian_filter=${ encodeURIComponent( JSON.stringify( drafts ) ) }` );
// Quoted, since WordPress drops one-letter search terms.
await p.fill( '#post-search-input', `"${ stamp } B"` );
await Promise.all( [ p.waitForNavigation(), p.click( '#search-submit' ) ] );
const searched = new URL( p.url() ).searchParams;
check( searched.get( 'corinthian_segment' ) === saved.id && searched.has( 'corinthian_filter' ), 'searching keeps the segment and filter' );
await p.waitForSelector( '.corinthian-filter__segments-toggle' );
const narrowed = await rows();
check( narrowed === 1 && ( await label() ).endsWith( '· edited' ), `a different search shows as edited (${ narrowed } rows, "${ await label() }")` );

// Rename inline.
await visit( `?corinthian_segment=${ saved.id }` );
await openMenu();
await rowAction( name, 'Rename' );
const renameInput = p.getByLabel( `New name for ${ name }` );
check( await renameInput.evaluate( ( el ) => el === document.activeElement ), 'rename field takes focus' );
const renamed = `${ name } (renamed)`;
await renameInput.fill( renamed );
await shootBoth( 'rename', async () => {
	await p.reload();
	await openMenu();
	await rowAction( name, 'Rename' );
	await p.getByLabel( `New name for ${ name }` ).fill( renamed );
} );
const renameSaved = p.waitForResponse( ( r ) => sent( r, `/segments/${ saved.id }`, 'PATCH' ) );
await p.keyboard.press( 'Enter' );
check( ( await renameSaved ).status() === 200, 'rename saved (200)' );
check( await p.getByRole( 'button', { name: renamed } ).first().isVisible(), 'renamed segment listed' );
check( ( await label() ).includes( renamed ), 'label follows the rename' );

// A failed save rolls back, says why and offers Retry.
const segmentRoute = '**/corinthian/v1/sets/*/segments/*';
await p.route( segmentRoute, ( route ) =>
	route.request().method() === 'GET'
		? route.continue()
		: route.fulfill( { status: 500, contentType: 'application/json', body: JSON.stringify( { code: 'boom', message: 'Server unavailable.' } ) } )
);
await visit( `?corinthian_segment=${ saved.id }` );
await openMenu();
await rowAction( renamed, 'Rename' );
await p.getByLabel( `New name for ${ renamed }` ).fill( 'Never saved' );
await p.keyboard.press( 'Enter' );
const errorToast = p.locator( '.corinthian-segments__toast.is-error' );
await errorToast.waitFor();
check( ( await errorToast.innerText() ).includes( 'Server unavailable.' ) && ( await errorToast.getByRole( 'button', { name: 'Retry' } ).isVisible() ), 'a failed rename says why and offers Retry' );
check( ( await label() ).includes( renamed ) && ! ( await label() ).includes( 'Never saved' ), 'the name rolls back' );
await p.unroute( segmentRoute );

// Save with an unapplied draft: the user chooses, and "applied only" saves what the list shows.
await visit( filtered );
await p.click( '.corinthian-filter__toggle' );
const draftPicker = p.getByRole( 'combobox', { name: 'Value, rule 1' } );
await draftPicker.click();
await p.locator( '#corinthian-filter-panel' ).getByRole( 'listbox' ).getByRole( 'option', { name: 'Published' } ).click();
await draftPicker.press( 'Escape' );
await p.getByLabel( 'Segment name' ).fill( `${ name } applied only` );
await p.getByRole( 'button', { name: 'Save as segment' } ).click();
const choice = p.locator( '.corinthian-filter__save-choice' );
check( await choice.isVisible(), 'a draft that differs from the list asks what to save' );
const appliedOnly = p.waitForResponse( ( r ) => sent( r, '/segments', 'POST' ) );
await choice.getByRole( 'button', { name: 'Save the applied filters only' } ).click();
const appliedSeg = await ( await appliedOnly ).json();
check( JSON.stringify( appliedSeg.filter.rules[ 0 ].value ) === '["draft"]', 'saved the applied rule, not the draft' );
await api( `/corinthian/v1/sets/${ set }/segments/${ appliedSeg.id }`, 'DELETE' );

// Default on load.
await visit( `?corinthian_segment=${ saved.id }` );
await openMenu();
const defaulted = p.waitForResponse( ( r ) => sent( r, '/default-segment', 'PUT' ) );
await rowAction( renamed, 'Open on this by default' );
check( ( await defaulted ).status() === 200, 'personal default saved (200)' );
await visit();
const landed = new URL( p.url() ).searchParams;
check( landed.get( 'corinthian_segment' ) === saved.id && landed.get( 's' ) === stamp, 'a bare visit opens on the default segment' );
check( ( await rows() ) === 2 && ( await label() ).includes( renamed ), 'default applied: 2 rows and its label' );
await shootBoth( 'default-on-load', async () => {
	await visit();
} );
await p.click( '.corinthian-filter__toggle' );
await Promise.all( [ p.waitForNavigation(), p.locator( '#corinthian-filter-panel' ).getByRole( 'button', { name: 'Clear', exact: true } ).click() ] );
check( new URL( p.url() ).searchParams.get( 'corinthian_segment' ) === 'none' && ! new URL( p.url() ).searchParams.has( 'corinthian_filter' ), 'Clear marks the list as cleared' );
await p.reload();
check( new URL( p.url() ).searchParams.get( 'corinthian_segment' ) === 'none', 'reloading a cleared list does not re-apply the default' );
await visit( `?orderby=date&order=desc` );
check( ! new URL( p.url() ).searchParams.has( 'corinthian_segment' ), 'a link with its own sort is left alone' );

// Delete without a confirm, then undo.
await visit( `?corinthian_segment=${ saved.id }` );
await openMenu();
const gone = p.waitForResponse( ( r ) => sent( r, `/segments/${ saved.id }`, 'DELETE' ) );
await rowAction( renamed, 'Delete' );
check( ( await gone ).status() === 200, 'deleted at once (200)' );
check( ( await p.getByRole( 'dialog' ).count() ) === 0, 'no confirm dialog' );
const toast = p.locator( '.corinthian-segments__toast' );
check( ( await toast.innerText() ).includes( `Deleted “${ renamed }”` ), 'toast names the deleted segment' );
check( await p.evaluate( () => document.activeElement?.classList.contains( 'corinthian-filter__segments-toggle' ) ), 'focus returns to the Segments button' );
await p.keyboard.press( 'Tab' );
check( await p.evaluate( () => document.activeElement?.textContent === 'Undo' ), 'Undo is the next tab stop' );
await shootBoth( 'undo-snackbar', async () => {
	await toast.hover();
} );
const restored = p.waitForResponse( ( r ) => sent( r, '/segments', 'POST' ) );
await toast.getByRole( 'button', { name: 'Undo' } ).click();
check( ( await restored ).status() === 201, 'undo restores (201)' );
const after = await api( `/corinthian/v1/sets/${ set }/segments` );
check( after.some( ( s ) => s.id === saved.id && s.name === renamed && s.order === 'desc' ), 'restored with the same id, name and view' );
check( ( await api( `/corinthian/v1/sets/${ set }/default-segment` ) ).personal === saved.id, 'undo brings the default back' );
check( ( await label() ).includes( renamed ), 'label comes back after undo' );

// Delete again and let the toast time out.
await openMenu();
await rowAction( renamed, 'Delete' );
await p.mouse.move( 5, 5 );
await p.waitForTimeout( 9000 );
check( ( await toast.count() ) === 0, 'the toast closes by itself after about 8 seconds' );
check( ! ( await api( `/corinthian/v1/sets/${ set }/segments` ) ).some( ( s ) => s.id === saved.id ), 'the segment stays deleted' );
await visit();
check( ! new URL( p.url() ).searchParams.has( 'corinthian_segment' ), 'a deleted default no longer redirects' );

await b.close();
process.exit( fail ? 1 : 0 );
