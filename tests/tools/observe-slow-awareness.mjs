/**
 * Two-user observer for the high-latency awareness prototype.
 *
 *   node tests/tools/observe-slow-awareness.mjs [outDir]
 *
 * Against a LIVE environment with "Awareness interval" set: logs in two
 * users in separate browser contexts on one post, has the second user
 * type, and screenshots the first user's canvas once the stripe and its
 * hover label appear, then the sidebar panel, then the 30-second trail
 * (one block at full strength, an older one at half). It then switches
 * the site to the Heartbeat channel, stalls the first user's document requests, has
 * the second user insert a block, and screenshots the phantom marker.
 * The channel setting is restored at the end.
 *
 * Requires: WP_BASE_URL (default: the wp-env dev site on :8888),
 * WP_USERNAME/WP_PASSWORD (default admin/password), and a second editor
 * account in WP_SECOND_USER/WP_SECOND_PASSWORD (default riley/password).
 * Run from the repo root so `@playwright/test` and `npm run env` resolve.
 */
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.WP_BASE_URL ?? 'http://localhost:8888';
const USER = process.env.WP_USERNAME ?? 'admin';
const PASS = process.env.WP_PASSWORD ?? 'password';
const USER2 = process.env.WP_SECOND_USER ?? 'riley';
const PASS2 = process.env.WP_SECOND_PASSWORD ?? 'password';
const OUT = process.argv[ 2 ] ?? 'slow-awareness-shots';
const CHANNEL_OPTION = 'gutenberg_sync_engines_awareness_channel';

fs.mkdirSync( OUT, { recursive: true } );

function setChannel( channel ) {
	execSync(
		`npm run env --silent -- run cli -- wp option update ${ CHANNEL_OPTION } ${ channel }`,
		{ stdio: 'ignore' }
	);
}

async function login( page, user, pass ) {
	await page.goto( `${ BASE }/wp-login.php` );
	// wp-login clears the password field shortly after load.
	await page.waitForTimeout( 1200 );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

async function dismissWelcome( page ) {
	await page
		.getByRole( 'button', { name: /Close|Get started/i } )
		.first()
		.click( { timeout: 4000 } )
		.catch( () => {} );
}

async function openEditor( page, postId ) {
	await page.goto(
		`${ BASE }/wp-admin/post.php?post=${ postId }&action=edit`
	);
	await dismissWelcome( page );
	await page.waitForFunction(
		() =>
			window.wp?.data?.select( 'core/block-editor' )?.getBlockCount() > 0
	);
}

function canvas( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

async function main() {
	const browser = await chromium.launch();
	const contextA = await browser.newContext( {
		viewport: { width: 1280, height: 800 },
	} );
	const contextB = await browser.newContext( {
		viewport: { width: 1280, height: 800 },
	} );
	const pageA = await contextA.newPage();
	const pageB = await contextB.newPage();
	for ( const [ label, page ] of [
		[ 'A', pageA ],
		[ 'B', pageB ],
	] ) {
		page.on( 'console', ( message ) => {
			if ( 'error' === message.type() ) {
				console.log(
					`${ label } console:`,
					message.text().slice( 0, 200 )
				);
			}
		} );
	}

	await login( pageA, USER, PASS );
	await login( pageB, USER2, PASS2 );

	// A creates a post with three paragraphs.
	await pageA.goto( `${ BASE }/wp-admin/post-new.php` );
	await dismissWelcome( pageA );
	await pageA.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	const postId = await pageA.evaluate( () => {
		const { createBlock } = window.wp.blocks;
		const blocks = [
			'The quick brown fox jumps over the lazy dog.',
			'Second paragraph, where the second user will type.',
			'Third paragraph stays untouched.',
		].map( ( content ) => createBlock( 'core/paragraph', { content } ) );
		window.wp.data.dispatch( 'core/block-editor' ).resetBlocks( blocks );
		window.wp.data
			.dispatch( 'core/editor' )
			.editPost( { title: 'Slow awareness demo' } );
		return window.wp.data.select( 'core/editor' ).getCurrentPostId();
	} );
	await pageA.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).savePost()
	);
	await pageA.waitForTimeout( 2500 );
	console.log( 'post:', postId );

	await openEditor( pageA, postId );
	await openEditor( pageB, postId );
	await pageA.waitForTimeout( 3000 );

	// B types into the second paragraph.
	const secondParagraph = canvas( pageB )
		.locator( '[data-type="core/paragraph"]' )
		.nth( 1 );
	await secondParagraph.click();
	await pageB.keyboard.press( 'End' );
	await pageB.keyboard.type( ' Words from the second user.', {
		delay: 60,
	} );

	// Wait for the beacon to land in A, then hover the stripe.
	await pageA.waitForFunction(
		( selector ) =>
			document
				.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument?.querySelector( selector ),
		'.gse-peer-presence',
		{ timeout: 30000 }
	);
	const stripe = canvas( pageA ).locator( '.gse-peer-presence' ).first();
	const box = await stripe.boundingBox();
	await pageA.mouse.move( box.x - 8, box.y + box.height / 2 );
	await pageA.waitForTimeout( 300 );
	await pageA.screenshot( { path: path.join( OUT, '1-stripe-hover.png' ) } );
	console.log(
		'label:',
		await pageA.evaluate( () =>
			document
				.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument?.querySelector( '.gse-peer-presence' )
				?.style.getPropertyValue( '--gse-peer-label' )
		)
	);

	// The sidebar panel.
	await pageA.mouse.move( 640, 600 );
	await pageA.evaluate( () =>
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea(
				'core',
				'gutenberg-sync-engines-awareness/gutenberg-sync-engines-awareness'
			)
	);
	await pageA.waitForTimeout( 800 );
	await pageA.screenshot( { path: path.join( OUT, '2-panel.png' ) } );

	// Trail: B sits in the first paragraph, leaves it, and types in the
	// third 16 s later. A should show the third at full strength and the
	// first at half, unchanged until the next beacon.
	await pageA.evaluate( () =>
		window.wp.data
			.dispatch( 'core/interface' )
			.disableComplementaryArea( 'core' )
	);
	const first = canvas( pageB )
		.locator( '[data-type="core/paragraph"]' )
		.nth( 0 );
	await first.click();
	await pageB.waitForTimeout( 1500 );
	const third = canvas( pageB )
		.locator( '[data-type="core/paragraph"]' )
		.nth( 2 );
	await third.click();
	await pageB.waitForTimeout( 16_000 );
	await pageB.keyboard.press( 'End' );
	await pageB.keyboard.type( ' Later words.', { delay: 60 } );
	await pageA.waitForTimeout( 6000 );
	const strengths = await pageA.evaluate( () =>
		Array.from(
			document
				.querySelector( 'iframe[name="editor-canvas"]' )
				.contentDocument.querySelectorAll( '.gse-peer-presence' )
		).map( ( element ) => [
			element.innerText.slice( 0, 24 ),
			element.style.getPropertyValue( '--gse-peer-opacity' ),
			element.style.getPropertyValue( '--gse-peer-label' ),
		] )
	);
	console.log( 'trail strengths:', strengths );
	await pageA.screenshot( { path: path.join( OUT, '3-trail.png' ) } );

	// Phantom: Heartbeat channel, A's document requests stalled, B inserts.
	setChannel( 'heartbeat' );
	try {
		await openEditor( pageA, postId );
		await openEditor( pageB, postId );
		await pageA.waitForTimeout( 4000 );
		await contextA.route( '**/wp-sync/v1/**', async ( route ) => {
			await new Promise( ( resolve ) => setTimeout( resolve, 25000 ) );
			await route.continue().catch( () => {} );
		} );
		const paragraph = canvas( pageB )
			.locator( '[data-type="core/paragraph"]' )
			.nth( 1 );
		await paragraph.click();
		await pageB.keyboard.press( 'End' );
		await pageB.keyboard.press( 'Enter' );
		await pageB.keyboard.type( 'A brand new paragraph.', { delay: 40 } );
		await pageA
			.waitForFunction(
				( selector ) =>
					document
						.querySelector( 'iframe[name="editor-canvas"]' )
						?.contentDocument?.querySelector( selector ),
				'.gse-phantom',
				{ timeout: 30000 }
			)
			.catch( () => console.log( 'no phantom appeared' ) );
		await pageA.waitForTimeout( 500 );
		await pageA.screenshot( { path: path.join( OUT, '4-phantom.png' ) } );
		console.log(
			'phantoms:',
			await pageA.evaluate( () =>
				Array.from(
					document
						.querySelector( 'iframe[name="editor-canvas"]' )
						.contentDocument.querySelectorAll( '.gse-phantom' )
				).map( ( element ) => element.textContent )
			),
			'| A block count:',
			await pageA.evaluate( () =>
				window.wp.data.select( 'core/block-editor' ).getBlockCount()
			)
		);
	} finally {
		setChannel( 'sync' );
	}
	await browser.close();
	console.log( 'screenshots in', OUT );
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
