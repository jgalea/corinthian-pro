import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
import os from 'node:os';
import path from 'node:path';
// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';

const base = process.env.WP_BASE_URL || 'http://localhost:8930';
const shots = SHOTS;
const editorUrl = `${ base }/wp-admin/options-general.php?page=corinthian`;

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );

await page.goto( `${ base }/wp-login.php` );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'password' );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
const nonce = await ( await page.request.get( `${ base }/wp-admin/admin-ajax.php?action=rest-nonce` ) ).text();
const rest = async ( route, init = {} ) => {
	const res = await page.request.fetch( `${ base }/?rest_route=/corinthian/v1${ route }`, { ...init, headers: { 'X-WP-Nonce': nonce } } );
	return res.json();
};

await page.goto( editorUrl );
await page.waitForSelector( '.corinthian-app' );
await page.getByLabel( 'List screen' ).selectOption( { label: 'Pages' } );
await page.waitForFunction( () => ! document.querySelector( '.corinthian-app .components-spinner' ) );

const title = 'Corinthian e2e formatting';
for ( const s of await rest( '/sets' ) ) {
	if ( s.title === title ) await rest( `/sets/${ s.id }`, { method: 'DELETE' } );
}
let setId = 0;
try {
	await page.getByRole( 'button', { name: 'Add set', exact: true } ).first().click();
	await page.getByLabel( 'Set name' ).fill( 'Corinthian e2e formatting' );

	// Current (native) columns, then a Custom field column.
	const natives = await page.locator( '.corinthian-select optgroup[label="Existing columns"] option' ).evaluateAll( ( o ) => o.map( ( x ) => x.value ) );
	assert.ok( natives.length >= 3, `expected native columns, got ${ natives.length }` );
	for ( const value of natives ) {
		await page.getByLabel( 'Column to add' ).selectOption( value );
		await page.getByRole( 'button', { name: 'Add column', exact: true } ).click();
	}
	await page.getByLabel( 'Column to add' ).selectOption( { label: 'Custom field' } );
	await page.getByRole( 'button', { name: 'Add column', exact: true } ).click();
	const row = page.locator( '.corinthian-row' ).last();
	await row.getByLabel( 'Field', { exact: true } ).fill( 'priority' );
	await row.getByLabel( 'Show as' ).selectOption( 'number' );
	// Column toggles appear for a typed column.
	for ( const name of [ 'Sortable', 'Filterable' ] ) {
		assert.ok( await row.getByLabel( name, { exact: true } ).isVisible(), `${ name } toggle visible` );
	}

	// Rule: priority > 5, red, row.
	const formatting = page.locator( '.corinthian-formatting' );
	await formatting.getByRole( 'button', { name: 'Add rule' } ).click();
	const rule = formatting.locator( '.corinthian-rule' ).first();
	const customLabel = await row.locator( '.corinthian-row__label' ).textContent();
	await rule.getByLabel( 'Column', { exact: true } ).selectOption( { label: customLabel } );
	await rule.getByLabel( 'Condition' ).selectOption( 'gt' );
	await rule.getByLabel( 'Value', { exact: true } ).fill( '5' );
	await rule.getByRole( 'radio', { name: 'Red' } ).check( { force: true } );
	await rule.getByRole( 'radio', { name: 'Row' } ).check();

	await page.getByLabel( 'Allow export' ).uncheck();

	await page.screenshot( { path: path.join( shots, 'editor-formatting-1440.png' ), fullPage: true } );
	await formatting.screenshot( { path: path.join( shots, 'editor-formatting-section-1440.png' ) } );

	await page.getByRole( 'button', { name: 'Save set' } ).click();
	await page.locator( '.components-notice__content', { hasText: 'Set saved.' } ).waitFor();

	const all = await rest( '/sets&screen=' + ( await page.getByLabel( 'List screen' ).inputValue() ) );
	const mine = all.find( ( s ) => s.title === 'Corinthian e2e formatting' );
	assert.ok( mine, 'test set exists' );
	setId = mine.id;

	// Reload and check the UI shows the persisted state.
	await page.goto( editorUrl );
	await page.waitForSelector( '.corinthian-app' );
	await page.getByLabel( 'List screen' ).selectOption( { label: 'Pages' } );
	await page.getByRole( 'tab', { name: 'Corinthian e2e formatting' } ).click();
	assert.equal( await page.getByLabel( 'Allow export' ).isChecked(), false, 'export toggle persisted off in UI' );
	assert.ok( await page.getByRole( 'radio', { name: 'Red' } ).isChecked(), 'tone persisted in UI' );

	const stored = await rest( `/sets/${ setId }` );
	assert.equal( stored.settings.export, false, 'export persisted' );
	assert.equal( stored.settings.formatting.length, 1 );
	const r = stored.settings.formatting[ 0 ];
	assert.match( r.id, /^[a-z0-9]+$/ );
	const priorityCol = stored.columns.find( ( c ) => c.settings?.meta_key === 'priority' );
	assert.equal( r.column, priorityCol.id );
	assert.equal( r.op, 'gt' );
	assert.equal( String( r.value ), '5' );
	assert.equal( r.tone, 'red' );
	assert.equal( r.scope, 'row' );
	console.log( 'persisted rule', JSON.stringify( r ), 'export', stored.settings.export );

	// Mobile.
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.locator( '.corinthian-formatting' ).scrollIntoViewIfNeeded();
	await page.locator( '.corinthian-formatting' ).screenshot( { path: path.join( shots, 'editor-formatting-390.png' ) } );
	for ( const w of [ 390, 320 ] ) {
		await page.setViewportSize( { width: w, height: 800 } );
		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		assert.ok( overflow <= 0, `horizontal overflow at ${ w }: ${ overflow }` );
	}
} finally {
	if ( setId ) {
		await rest( `/sets/${ setId }`, { method: 'DELETE' } );
		const left = ( await rest( '/sets' ) ).some( ( s ) => s.id === setId );
		assert.ok( ! left, 'test set deleted' );
	}
	await browser.close();
}
assert.deepEqual( errors, [], `page errors: ${ errors }` );
console.log( 'editor-formatting OK' );
