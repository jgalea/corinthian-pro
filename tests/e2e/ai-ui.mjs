// Local test site: set WP_CLI_CONTAINER to your wp-env cli container and SHOTS_DIR for screenshots.
const CLI = process.env.WP_CLI_CONTAINER || 'wp-env-cli';
const SHOTS = process.env.SHOTS_DIR || 'test-results';
// AI controls on the Posts list table: filter description and suggested bulk values.
// A fake mu-plugin answers the AI calls; no provider is ever contacted.
// SHOTS=<dir> also writes screenshots at 390 and 1440.
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const base = 'http://localhost:8930';
const shots = process.env.SHOTS || '';
const SET = 18;
const COL = '64882268049ba4';
const CONTAINER = CLI;
// Ask WordPress where must-use plugins live instead of hard-coding the container path.
const MU_DIR = execFileSync( 'docker', [ 'exec', CLI, 'wp', 'eval', 'echo WPMU_PLUGIN_DIR;' ], { encoding: 'utf8' } ).trim();
const MU = `${ MU_DIR }/corinthian-fake-ai.php`;
const here = dirname( fileURLToPath( import.meta.url ) );
const docker = ( args, input ) => execFileSync( 'docker', [ 'exec', ...( input ? [ '-i' ] : [] ), CONTAINER, ...args ], { encoding: 'utf8', input, stdio: [ input ? 'pipe' : 'ignore', 'pipe', 'pipe' ] } ).trim();
const wp = ( ...args ) => docker( [ 'wp', ...args ] );
const php = ( code ) => wp( 'eval', code );
const meta = ( id ) => { try { return wp( 'post', 'meta', 'get', String( id ), 'status' ).replace( /\s+$/, '' ); } catch { return ''; } };

let fail = 0;
const check = ( ok, msg ) => { console.log( ok ? 'PASS' : 'FAIL', msg ); if ( ! ok ) fail++; };

const stamp = `ai${ Date.now() }`;
const posts = [];
const users = [];
const mkPost = ( title, status, author = 1 ) => {
	const id = Number( wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', `--post_author=${ author }`, '--porcelain' ) );
	posts.push( id );
	wp( 'post', 'meta', 'update', String( id ), 'status', status );
	return id;
};
const installFake = () => {
	docker( [ 'mkdir', '-p', MU_DIR ] );
	docker( [ 'sh', '-c', `cat > ${ MU }` ], readFileSync( join( here, 'helpers/fake-ai.php' ), 'utf8' ) );
};
const removeFake = () => { try { docker( [ 'rm', '-f', MU ] ); } catch {} };

const activeKey = php( 'echo \\Corinthian\\Sets\\SetRepository::active_meta_key( "post:post" );' );
const originalActive = php( `echo get_user_meta( 1, "${ activeKey }", true );` );
const cleanup = () => {
	removeFake();
	try {
		posts.forEach( ( id ) => wp( 'post', 'delete', String( id ), '--force' ) );
		users.forEach( ( id ) => wp( 'user', 'delete', String( id ), '--yes' ) );
		php( `update_user_meta( 1, "${ activeKey }", "${ originalActive }" );` );
	} catch ( e ) { console.log( 'WARN cleanup failed', e.message ); }
};
process.on( 'exit', cleanup );
process.on( 'SIGINT', () => process.exit( 1 ) );
removeFake();
php( `update_user_meta( 1, "${ activeKey }", ${ SET } );` );

const filterVal = `${ stamp }-hit`;
const F = [ mkPost( `AI filter A ${ stamp }`, filterVal ), mkPost( `AI filter B ${ stamp }`, filterVal ), mkPost( `AI filter C ${ stamp }`, `${ stamp }-miss` ) ];
const S = [ mkPost( `AI suggest X ${ stamp }`, `orig-x-${ stamp }` ), mkPost( `AI suggest Y ${ stamp }`, `orig-y-${ stamp }` ) ];

mkdirSync( shots || '.', { recursive: true } );
const b = await chromium.launch();
const login = async ( page, user, pass ) => {
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
};
const overflows = ( page ) => page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth );
const shoot = async ( page, name ) => {
	if ( ! shots ) return;
	for ( const w of [ 390, 1440 ] ) {
		await page.setViewportSize( { width: w, height: w === 390 ? 844 : 900 } );
		await page.waitForTimeout( 200 );
		await page.screenshot( { path: join( shots, `ai-${ name }-${ w }.png` ) } );
	}
	await page.setViewportSize( { width: 1440, height: 900 } );
};

try {
	const ctx = await b.newContext( { viewport: { width: 1440, height: 900 } } );
	const p = await ctx.newPage();
	await login( p, 'admin', 'password' );

	// 1. No fake AI: nothing AI-related renders.
	await p.goto( `${ base }/wp-admin/edit.php` );
	await p.waitForSelector( '.corinthian-filter__toggle' );
	check( await p.evaluate( () => window.corinthianTable.ai.canUse === true && window.corinthianTable.ai.available === false ), 'payload: admin canUse, provider not available' );
	await p.click( '.corinthian-filter__toggle' );
	check( ( await p.getByText( 'Describe a filter' ).count() ) === 0, 'no provider: no "Describe a filter"' );
	await p.keyboard.press( 'Escape' );
	await p.locator( `#cb-select-${ S[ 0 ] }` ).check();
	await p.getByRole( 'button', { name: 'Bulk edit' } ).click();
	await p.getByRole( 'dialog' ).getByLabel( 'Column' ).selectOption( { label: 'Status' } );
	check( ( await p.getByText( 'Suggest values' ).count() ) === 0, 'no provider: no "Suggest values"' );
	await p.keyboard.press( 'Escape' );

	// 2. Fake AI installed: describe a filter.
	installFake();
	await p.goto( `${ base }/wp-admin/edit.php` );
	await p.waitForSelector( '.corinthian-filter__toggle' );
	check( await p.evaluate( () => window.corinthianTable.ai.available === true && window.corinthianTable.ai.canUse === true ), 'payload: fake AI available' );
	await p.click( '.corinthian-filter__toggle' );
	const box = p.getByLabel( 'Describe a filter' );
	check( await box.isVisible(), 'describe box is visible' );
	check( ( await p.evaluate( () => document.body.innerText ) ).toLowerCase().includes( 'ai-powered' ) === false, 'UI never says "AI-powered"' );

	await box.fill( 'FAIL please' );
	await p.getByRole( 'button', { name: 'Build filter' } ).click();
	await p.waitForSelector( '.corinthian-filter__error' );
	check( ( await p.textContent( '.corinthian-filter__error' ) ).includes( 'unavailable' ), '503 shows inline' );

	await box.fill( `Status is exactly ${ filterVal }` );
	await p.getByRole( 'button', { name: 'Build filter' } ).click();
	await p.waitForSelector( '.corinthian-filter__dropped' );
	const notice = await p.textContent( '.corinthian-filter__describe .components-notice' );
	check( notice.includes( 'Left out' ) && notice.includes( 'no-such-column' ), 'dropped rule listed with reason' );
	const url0 = p.url();
	check( ! url0.includes( 'corinthian_filter' ) && ! url0.includes( 'filter=' ), 'not applied automatically' );
	const loaded = await p.getByLabel( 'Value, rule 1' ).inputValue().catch( async () => p.getByLabel( 'Value, rule 1' ).evaluate( ( el ) => el.value ) );
	check( loaded === filterVal || ( await p.getByLabel( 'Column, rule 1' ).inputValue() ) === COL, `rule 1 loaded (${ loaded })` );
	check( ( await p.getByLabel( 'Column, rule 1' ).inputValue() ) === COL, 'rule 1 column is Status' );
	check( ( await p.locator( '.corinthian-filter__rule' ).count() ) === 1, 'only the valid rule is loaded' );
	await shoot( p, 'filter' );
	for ( const w of [ 320, 390 ] ) {
		await p.setViewportSize( { width: w, height: 800 } );
		check( ! ( await overflows( p ) ), `no horizontal page scroll at ${ w }px (describe box)` );
	}
	await p.setViewportSize( { width: 1440, height: 900 } );
	await Promise.all( [ p.waitForNavigation(), p.getByRole( 'group', { name: 'Filter rules' } ).getByRole( 'button', { name: 'Apply', exact: true } ).click() ] );
	const ids = await p.$$eval( 'table.wp-list-table tbody tr[id^="post-"]', ( trs ) => trs.map( ( t ) => Number( t.id.replace( 'post-', '' ) ) ) );
	check( ids.length === 2 && ids.includes( F[ 0 ] ) && ids.includes( F[ 1 ] ) && ! ids.includes( F[ 2 ] ), `Apply shows exactly the 2 matching rows (${ ids.join( ',' ) })` );

	// 3. Suggest values on 2 checked rows.
	await p.goto( `${ base }/wp-admin/edit.php` );
	await p.waitForSelector( '.corinthian-filter__toggle' );
	await p.locator( `#cb-select-${ S[ 0 ] }` ).check();
	await p.locator( `#cb-select-${ S[ 1 ] }` ).check();
	await p.getByRole( 'button', { name: 'Bulk edit' } ).click();
	const dlg = p.getByRole( 'dialog' );
	await dlg.getByLabel( 'Column' ).selectOption( { label: 'Status' } );
	const suggestBtn = dlg.getByRole( 'button', { name: 'Suggest values' } );
	check( await suggestBtn.isVisible(), 'Suggest values visible in bulk modal' );
	check( await suggestBtn.isDisabled() || await suggestBtn.getAttribute( 'aria-disabled' ) === 'true', 'Suggest disabled until an instruction is typed' );
	await dlg.getByLabel( 'Instruction' ).fill( `AI ${ stamp }` );
	await dlg.getByLabel( 'Instruction' ).press( 'Enter' );
	await dlg.locator( '.corinthian-suggest__table' ).waitFor();
	check( ( await p.$$( '.components-modal__content form.corinthian-bulk' ) ).length === 0, 'Enter in instruction did not submit the bulk edit' );
	check( [ S[ 0 ], S[ 1 ] ].every( ( id ) => meta( id ) === `orig-${ id === S[ 0 ] ? 'x' : 'y' }-${ stamp }` ), 'nothing written before Apply' );
	const rowsShown = await dlg.locator( '.corinthian-suggest__table tbody tr' ).count();
	check( rowsShown === 2, `review table has 2 rows (${ rowsShown })` );
	const cur = await dlg.locator( '.corinthian-suggest__current' ).allTextContents();
	check( cur.some( ( t ) => t.includes( `orig-x-${ stamp }` ) ), 'current value shown' );
	await shoot( p, 'suggest' );
	for ( const w of [ 320, 390 ] ) {
		await p.setViewportSize( { width: w, height: 800 } );
		check( ! ( await overflows( p ) ), `no horizontal page scroll at ${ w }px (review)` );
	}
	await p.setViewportSize( { width: 1440, height: 900 } );

	const edited = `edited ${ stamp }`;
	await dlg.getByLabel( new RegExp( `Suggested value for .*#${ S[ 1 ] }` ) ).fill( edited );
	await dlg.getByRole( 'checkbox', { name: new RegExp( `Accept suggestion for .*#${ S[ 0 ] }` ) } ).uncheck();
	await Promise.all( [ p.waitForNavigation( { timeout: 20000 } ), dlg.getByRole( 'button', { name: 'Apply accepted' } ).click() ] );
	check( meta( S[ 0 ] ) === `orig-x-${ stamp }`, 'unchecked row unchanged' );
	check( meta( S[ 1 ] ) === edited, `accepted row saved with the edited value (${ meta( S[ 1 ] ) })` );

	// 4. Users without edit_others_posts see nothing (control: admin did above).
	const pass = 'Pw-e2e-ai-9x!';
	for ( const role of [ 'author', 'subscriber' ] ) {
		const login_name = `e2e${ role }${ Date.now() % 100000 }`;
		const uid = Number( wp( 'user', 'create', login_name, `${ login_name }@example.com`, `--role=${ role }`, `--user_pass=${ pass }`, '--porcelain' ) );
		users.push( uid );
		if ( role === 'author' ) {
			php( `update_user_meta( ${ uid }, "${ activeKey }", ${ SET } );` );
			mkPost( `AI author own ${ stamp }`, `author-${ stamp }`, uid );
		}
		const c = await b.newContext( { viewport: { width: 1440, height: 900 } } );
		const q = await c.newPage();
		await login( q, login_name, pass );
		await q.goto( `${ base }/wp-admin/edit.php` );
		await q.waitForLoadState( 'load' );
		const state = await q.evaluate( () => ( { ai: window.corinthianTable?.ai ?? null, toggle: !! document.querySelector( '.corinthian-filter__toggle' ), text: document.body.innerText } ) );
		check( state.ai === null || ( state.ai.canUse === false ), `${ role }: canUse is false or table script absent (${ JSON.stringify( state.ai ) })` );
		if ( role === 'author' ) {
			check( state.toggle, 'author: table UI renders (control)' );
			await q.click( '.corinthian-filter__toggle' );
			check( ( await q.getByLabel( 'Describe a filter' ).count() ) === 0, 'author: no "Describe a filter"' );
			await q.keyboard.press( 'Escape' );
			const row = q.locator( 'table.wp-list-table tbody tr[id^="post-"] .check-column input' ).first();
			await row.check();
			await q.getByRole( 'button', { name: 'Bulk edit' } ).click();
			await q.getByRole( 'dialog' ).getByLabel( 'Column' ).selectOption( { label: 'Status' } );
			check( ( await q.getByText( 'Suggest values' ).count() ) === 0, 'author: no "Suggest values"' );
		} else {
			check( ! state.text.includes( 'Describe a filter' ) && ! state.text.includes( 'Suggest values' ), 'subscriber: no AI text' );
		}
		await c.close();
	}
} finally {
	await b.close();
	cleanup();
	posts.length = 0;
	users.length = 0;
}
console.log( fail ? `${ fail } FAILED` : 'ALL PASSED' );
process.exit( fail ? 1 : 0 );
