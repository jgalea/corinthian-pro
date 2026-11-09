import { chromium } from '@playwright/test';

// Smart filters through the URL. Other tests share this site's posts, so the
// checks rest on a value unique to this run plus invariants that hold whatever
// else is on the site, never on absolute site-wide counts.
const col = process.argv[ 2 ];
const set = Number( process.argv[ 3 ] || 18 );
const stamp = `Filter run ${ Date.now() }`;

const b = await chromium.launch();
const p = await b.newPage();
await p.goto( 'http://localhost:8930/wp-login.php' );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', 'password' );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

// WordPress's own "N items" total, so paging never hides rows.
const total = async ( filter ) => {
	const q = filter ? '?corinthian_filter=' + encodeURIComponent( JSON.stringify( filter ) ) : '';
	await p.goto( 'http://localhost:8930/wp-admin/edit.php' + q );
	const text = await p.$eval( '.tablenav.top .displaying-num', ( el ) => el.textContent ).catch( () => '0' );
	return Number( ( text.match( /\d[\d,]*/ ) || [ '0' ] )[ 0 ].replace( /,/g, '' ) );
};

// Seed: give the first three posts on the list this run's value.
await p.goto( 'http://localhost:8930/wp-admin/edit.php' );
const seeded = await p.evaluate( async ( [ set, column, stamp ] ) => {
	const ids = [ ...document.querySelectorAll( 'table.wp-list-table tbody tr' ) ].slice( 0, 3 ).map( ( tr ) => Number( tr.id.replace( 'post-', '' ) ) );
	let saved = 0;
	for ( const id of ids ) {
		// Count only edits that really saved, so a bad column shows up as failed checks, not a crash.
		saved += await wp.apiFetch( { path: '/corinthian/v1/edit', method: 'POST', data: { screen: 'post:post', set, column, id, value: stamp } } ).then( () => 1, () => 0 );
	}
	return saved;
}, [ set, col, stamp ] );

const eq = { column: col, op: 'eq', value: stamp };
const empty = { column: col, op: 'empty' };

let fail = 0;
const check = ( ok, msg ) => {
	console.log( ok ? 'PASS' : 'FAIL', msg );
	if ( ! ok ) fail++;
};

const all = await total( null );
const nEmpty = await total( { rules: [ empty ] } );
const nNotEmpty = await total( { rules: [ { column: col, op: 'not_empty' } ] } );

check( seeded === 3, `seeded 3 posts (${ seeded } saved)` );
check( all > 3, `control: ${ all } items without a filter` );
check( ( await total( { rules: [ eq ] } ) ) === 3, 'eq this run\'s value: exactly the 3 seeded posts' );
check( ( await total( { rules: [ { column: col, op: 'contains', value: stamp.slice( -8 ) } ] } ) ) === 3, 'contains a fragment unique to this run: 3' );
check( nEmpty + nNotEmpty === all, `empty (${ nEmpty }) + not empty (${ nNotEmpty }) = all (${ all })` );
check( ( await total( { match: 'any', rules: [ eq, empty ] } ) ) === 3 + nEmpty, 'any: eq OR empty = 3 + empty' );
check( ( await total( { rules: [ eq, empty ] } ) ) === 0, 'all: eq AND empty = 0' );
check( ( await total( { rules: [ { column: col, op: 'drop_table', value: 'x' } ] } ) ) === all, 'unknown op is ignored' );
check( ( await total( { rules: [ { column: col, op: 'eq', value: "x' OR '1'='1" } ] } ) ) === 0, 'injection value is treated as a literal' );

await b.close();
process.exit( fail ? 1 : 0 );
