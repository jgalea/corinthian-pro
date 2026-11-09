import { chromium } from '@playwright/test';
const fx = JSON.parse( process.argv[ 2 ] );
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
const ask = ( p ) => p.evaluate( ( fx ) => wp.apiFetch( { path: '/corinthian/v1/format', method: 'POST', data: { screen: 'post:post', set: fx.set, ids: [ fx.private, fx.own ] } } ).then( ( r ) => r.matches[ 0 ].ids.sort() ), fx );
let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };
const admin = await login( 'admin', 'password' );
const a = await ask( admin.p );
check( a.includes( fx.private ) && a.includes( fx.own ), `control: admin matches both (${ a })` );
const author = await login( 'author2', 'author2pass' );
const r = await ask( author.p );
check( ! r.includes( fx.private ), `author cannot probe admin's private post (${ r })` );
check( r.includes( fx.own ), `author still matches own draft (${ r })` );
await admin.b.close();
await author.b.close();
process.exit( fail ? 1 : 0 );
