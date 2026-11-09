import { chromium } from '@playwright/test';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
const set = Number( process.argv[ 2 ] );
const col = process.argv[ 3 ];
const base = 'http://localhost:8930';
async function login( user, pass ) {
	const b = await chromium.launch();
	const p = await b.newPage();
	await p.goto( `${ base }/wp-login.php` );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', pass );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	await p.goto( `${ base }/wp-admin/edit.php` );
	return { b, p };
}
const call = ( page, path, data ) => page.evaluate( ( [ path, data ] ) => wp.apiFetch( { path, method: 'POST', data } ).then( ( r ) => ( { ok: true, r } ), ( e ) => ( { ok: false, status: e.data?.status, code: e.code } ) ), [ path, data ] );
let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };
const admin = await login( 'admin', 'password' );
const post = await admin.p.$eval( 'table.wp-list-table tbody tr', ( tr ) => Number( tr.id.replace( 'post-', '' ) ) );
const ctx = { screen: 'post:post', set, column: col };

let r = await call( admin.p, '/corinthian/v1/edit', { ...ctx, id: post, value: 'In progress' } );
check( r.ok && r.r.value === 'In progress' && r.r.html.includes( 'In progress' ), `admin inline edit -> ${ JSON.stringify( r.r?.value ?? r ) }` );

const author = await login( 'author2', 'author2pass' );
r = await call( author.p, '/corinthian/v1/edit', { ...ctx, id: post, value: 'Hacked' } );
check( ! r.ok && r.status === 403, `author editing admin's post rejected (${ r.status } ${ r.code })` );
r = await call( author.p, '/corinthian/v1/bulk-edit', { ...ctx, ids: [ post ], value: 'Hacked', mode: 'set' } );
check( r.ok && r.r.done === 0 && r.r.failed.length === 1, `author bulk on admin's post: done=${ r.r?.done } failed=${ r.r?.failed?.length }` );
r = await call( admin.p, '/corinthian/v1/edit', { ...ctx, column: 'nope', id: post, value: 'x' } );
check( ! r.ok && r.status === 400, `unknown column rejected (${ r.status })` );
r = await call( admin.p, '/corinthian/v1/bulk-edit', { ...ctx, ids: [ post ], value: 'x', mode: 'append' } );
check( ! r.ok && r.status === 400, `unsupported bulk mode rejected (${ r.status })` );

// Regression: the resolve phase must not leak ids (security review finding).
const leakFilter = { rules: [ { column: col, op: 'not_empty' } ] };
r = await call( author.p, '/corinthian/v1/bulk-edit', { ...ctx, filter: leakFilter } );
const { execSync } = await import( 'node:child_process' );
const authorOwn = execSync( 'docker exec ' + CLI + ' wp post list --post_type=post --post_status=any --author=$(docker exec ' + CLI + ' wp user get author2 --field=ID) --field=ID', { encoding: 'utf8', shell: '/bin/zsh' } ).trim().split( /\s+/ ).filter( Boolean ).map( Number );
check( r.ok && r.r.ids.every( ( id ) => authorOwn.includes( id ) ), `author resolving a filter sees only their own posts (${ JSON.stringify( r.r?.ids ) } within own ${ JSON.stringify( authorOwn ) })` );
r = await call( admin.p, '/corinthian/v1/bulk-edit', { ...ctx, filter: leakFilter } );
check( r.ok && r.r.ids.length > 0, `control: admin resolving the same filter sees ${ r.r?.ids?.length }` );
const sub = await chromium.launch();
const sp = await sub.newPage();
await sp.goto( `${ base }/wp-login.php` );
await sp.fill( '#user_login', 'sub2' );
await sp.fill( '#user_pass', 'sub2pass' );
await Promise.all( [ sp.waitForNavigation(), sp.click( '#wp-submit' ) ] );
const subStatus = await sp.evaluate( async ( data ) => {
	const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
	const res = await fetch( '/wp-json/corinthian/v1/bulk-edit', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify( data ) } );
	return res.status + ':' + ( await res.json() ).code;
}, { ...ctx, filter: leakFilter } );
check( subStatus === '403:corinthian_forbidden', `subscriber resolve blocked by our check (${ subStatus })` );
await sub.close();

// Two-phase bulk: resolve the filter, then edit in batches.
const runId = Date.now();
const filter = { rules: [ { column: col, op: 'eq', value: `Bulk seed ${ runId }` } ] };
const allIds = await admin.p.$$eval( 'table.wp-list-table tbody tr', ( rows ) => rows.map( ( tr ) => Number( tr.id.replace( 'post-', '' ) ) ) );
for ( const id of allIds.slice( 0, 2 ) ) await call( admin.p, '/corinthian/v1/edit', { ...ctx, id, value: `Bulk seed ${ runId }` } );
r = await call( admin.p, '/corinthian/v1/bulk-edit', { ...ctx, filter } );
const ids = r.r.ids;
check( r.ok && ids.length === 2, `resolve phase found ${ ids.length } (want the 2 seeded)` );
let offset = 0, done = 0, guard = 0;
while ( offset !== null && guard++ < 10 ) {
	r = await call( admin.p, '/corinthian/v1/bulk-edit', { ...ctx, ids, value: `Done ${ runId }`, mode: 'set', offset } );
	done += r.r.done;
	offset = r.r.next_offset;
}
check( done === ids.length, `bulk edited ${ done }/${ ids.length }` );
r = await call( admin.p, '/corinthian/v1/bulk-edit', { ...ctx, filter } );
check( r.r.ids.length === 0, `no Bulk seed left (${ r.r.ids.length })` );
r = await call( admin.p, '/corinthian/v1/bulk-edit', { ...ctx, filter: { rules: [ { column: col, op: 'eq', value: `Done ${ runId }` } ] } } );
check( r.r.ids.length === 2, `Done now matches ${ r.r.ids.length }` );
await admin.b.close();
await author.b.close();
process.exit( fail ? 1 : 0 );
