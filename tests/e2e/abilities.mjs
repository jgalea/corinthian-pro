import { chromium } from '@playwright/test';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
const set = Number( process.argv[ 2 ] );
const col = process.argv[ 3 ];
async function login( user, pass ) {
	const b = await chromium.launch();
	const p = await b.newPage();
	await p.goto( 'http://localhost:8930/wp-login.php' );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', pass );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	await p.goto( 'http://localhost:8930/wp-admin/edit.php' );
	return { b, p };
}
const run = ( p, name, input, method ) => p.evaluate( async ( [ name, input, method ] ) => {
	const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
	const base = `/wp-json/wp-abilities/v1/abilities/${ name }/run`;
	let res;
	if ( method === 'GET' ) {
		const qs = new URLSearchParams();
		const add = ( prefix, v ) => {
			if ( v && typeof v === 'object' ) Object.entries( v ).forEach( ( [ k, x ] ) => add( `${ prefix }[${ k }]`, x ) );
			else qs.append( prefix, v );
		};
		add( 'input', input );
		res = await fetch( `${ base }?${ qs }`, { headers: { 'X-WP-Nonce': nonce } } );
	} else {
		res = await fetch( base, { method: 'POST', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { input } ) } );
	}
	return { status: res.status, body: await res.json() };
}, [ name, input, method ] );
let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };

const admin = await login( 'admin', 'password' );
let r = await admin.p.evaluate( async () => {
	const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
	return ( await fetch( '/wp-json/wp-abilities/v1/abilities?category=corinthian', { headers: { 'X-WP-Nonce': nonce } } ) ).json();
} );
const names = Array.isArray( r ) ? r.map( ( a ) => a.name ).sort() : r;
check( JSON.stringify( names ) === JSON.stringify( [ 'corinthian/bulk-edit', 'corinthian/find', 'corinthian/list-segments', 'corinthian/list-sets' ] ), `abilities listed: ${ JSON.stringify( names ) }` );

r = await run( admin.p, 'corinthian/list-sets', { screen: 'post:post' }, 'GET' );
check( r.status === 200 && r.body.some( ( s ) => s.id === set && s.columns.some( ( c ) => c.id === col && c.editable && c.operators.includes( 'eq' ) ) ), `list-sets includes set ${ set } with editable Status (${ r.status })` );

// Seed two posts with a known value via the edit API.
// Two fresh admin-authored posts, so the author's refusal check below means something.
const { execSync } = await import( 'node:child_process' );
const mk = () => Number( execSync( 'docker exec ' + CLI + ' wp post create --post_title="Ability fixture" --post_status=publish --post_author=1 --porcelain', { encoding: 'utf8' } ).trim() );
const ids = [ mk(), mk() ];
// Remove the fixture posts on exit so they don't pile up and push other tests' rows off the first page.
process.on( 'exit', () => { try { execSync( `docker exec ${ CLI } wp post delete ${ ids.join( ' ' ) } --force`, { stdio: 'ignore' } ); } catch ( e ) {} } );
const stamp = `Via ability ${ Date.now() }`;
r = await run( admin.p, 'corinthian/bulk-edit', { screen: 'post:post', set, column: col, mode: 'set', value: stamp, ids }, 'POST' );
check( r.status === 200 && r.body.done === 2, `bulk-edit via ability: ${ JSON.stringify( r.body ) }` );

r = await run( admin.p, 'corinthian/find', { screen: 'post:post', set, filter: { rules: [ { column: col, op: 'eq', value: stamp } ] } }, 'GET' );
check( r.status === 200 && JSON.stringify( [ ...r.body.ids ].sort() ) === JSON.stringify( [ ...ids ].sort() ), `find returns exactly the edited ids (${ JSON.stringify( r.body ) })` );
r = await run( admin.p, 'corinthian/find', { screen: 'post:post', set, filter: { rules: [ { column: col, op: 'eq', value: 'nothing-has-this' } ] } }, 'GET' );
check( r.status === 200 && r.body.ids.length === 0, `control: find with no match returns none` );

const author = await login( 'author2', 'author2pass' );
r = await run( author.p, 'corinthian/bulk-edit', { screen: 'post:post', set, column: col, mode: 'set', value: 'Hacked', ids }, 'POST' );
check( r.status === 200 && r.body.done === 0 && r.body.failed.length === 2, `author bulk-edit on admin posts refused per item (${ JSON.stringify( r.body ) })` );
r = await run( author.p, 'corinthian/find', { screen: 'post:post', set, filter: { rules: [ { column: col, op: 'eq', value: stamp } ] } }, 'GET' );
const visible = r.body.ids || [];
check( r.status === 200, `author find allowed (${ r.status }), sees ${ visible.length } published posts only` );

await admin.b.close();
await author.b.close();
process.exit( fail ? 1 : 0 );
