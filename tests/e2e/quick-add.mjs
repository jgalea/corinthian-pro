import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
const wp = ( cmd ) => execSync( `docker exec ${ CLI } wp ${ cmd }`, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const title = `Quick add ${ Date.now() }`;
let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };
const login = async ( user, pass ) => {
	const b = await chromium.launch();
	const p = await b.newPage( { viewport: { width: 1440, height: 900 } } );
	await p.goto( 'http://localhost:8930/wp-login.php' );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', pass );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	return { b, p };
};
const { b, p } = await login( 'admin', 'password' );
await p.goto( 'http://localhost:8930/wp-admin/edit.php' );
await p.locator( '#corinthian-quick-add > button' ).click();
await p.getByPlaceholder( 'Title' ).fill( title );
await Promise.all( [ p.waitForNavigation(), p.getByRole( 'button', { name: 'Add draft' } ).click() ] );
const found = wp( `post list --post_type=post --post_status=draft --title="${ title }" --field=ID` );
check( /^\d+$/.test( found ), `draft created (${ found })` );
check( ( await p.textContent( 'table.wp-list-table' ) ).includes( title ), 'new draft appears in the list' );
for ( const w of [ 390, 320 ] ) {
	await p.setViewportSize( { width: w, height: 800 } );
	await p.locator( '#corinthian-quick-add > button' ).click();
	check( ! ( await p.evaluate( () => document.documentElement.scrollWidth > innerWidth ) ), `no horizontal scroll with form open at ${ w }px` );
	await p.screenshot( { path: `${ SHOTS }/quick-add-${ w }.png` } );
	await p.getByRole( 'button', { name: 'Cancel' } ).click();
}
if ( found ) wp( `post delete ${ found } --force` );
await b.close();
const sub = await login( 'sub2', 'sub2pass' );
await sub.p.goto( 'http://localhost:8930/wp-admin/edit.php' );
check( ( await sub.p.locator( '#corinthian-quick-add > button' ).count() ) === 0, 'subscriber gets no Quick add (control: admin had it)' );
await sub.b.close();
process.exit( fail ? 1 : 0 );
