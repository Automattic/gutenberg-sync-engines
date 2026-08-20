/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { test, expect } from '../../config/collaboration-fixtures';

const USER_A_TEXT =
	'123456789012345678901234567890123456789012345678901234567890';
const USER_B_TEXT =
	'987654321098765432109876543210987654321098765432109876543210';

function paragraph( content: string ): string {
	return `<!-- wp:paragraph -->\n<p>${ content }</p>\n<!-- /wp:paragraph -->`;
}

async function focusParagraphEnd( page: Page, paragraphLocator: Locator ) {
	await paragraphLocator.click();
	await page.keyboard.press( 'End' );
}

async function getParagraphContents( page: Page ): Promise< string[] > {
	return page.evaluate( () =>
		( window as any ).wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.map( ( block: { attributes: { content?: unknown } } ) =>
				String( block.attributes.content ?? '' )
			)
	);
}

test.describe( 'Collaboration - WebSocket Concurrent Typing', () => {
	// Runs against the plugin's REAL websocket transport: the suite's
	// config launches the `wp collaboration sync-server` PHP daemon (the
	// engine seam and all) via tests/e2e/bin/rtc-real-ws-daemon.mjs and
	// selects the websocket transport on the tests site for the run. The
	// old PEER-relay fixture lane (the test WS provider plugin) only
	// demonstrated client-merging engines and none remains.

	test( 'does not lose characters when two users rapidly type in different paragraphs', async ( {
		collaborationUtils,
		requestUtils,
		editor,
		page,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'WebSocket Concurrent Typing Repro',
			content: [ 'p1', 'p2', 'p3', 'p4' ].map( paragraph ).join( '\n\n' ),
			status: 'draft',
			date_gmt: new Date().toISOString(),
		} );

		await collaborationUtils.openCollaborativeSession( post.id );

		const { editor2, page2 } = collaborationUtils;

		await expect
			.poll( () => editor2.getBlocks(), { timeout: 5000 } )
			.toMatchObject( [
				{ attributes: { content: 'p1' } },
				{ attributes: { content: 'p2' } },
				{ attributes: { content: 'p3' } },
				{ attributes: { content: 'p4' } },
			] );

		await Promise.all( [
			focusParagraphEnd(
				page,
				editor.canvas.getByText( 'p3', { exact: true } )
			),
			focusParagraphEnd(
				page2,
				editor2.canvas.getByText( 'p1', { exact: true } )
			),
		] );

		/*
		 * Rapid but human-plausible typing. The relay-era 1 ms delay
		 * assumed local-first CRDT typing with no server round trip; the
		 * server-authoritative engines push peers' rows back into the
		 * editor, and at 1000 chars/sec those mid-burst pushes remount
		 * the block under the caret faster than the editor can settle,
		 * eating keystrokes AT THE CANVAS on both windows (filed as a
		 * push-scheduling observation in the loop ledger — the sync lane
		 * itself loses nothing).
		 */
		await Promise.all( [
			page.keyboard.type( USER_A_TEXT, { delay: 15 } ),
			page2.keyboard.type( USER_B_TEXT, { delay: 15 } ),
		] );

		const expectedParagraphs = [
			`p1${ USER_B_TEXT }`,
			'p2',
			`p3${ USER_A_TEXT }`,
			'p4',
		];

		await expect
			.poll(
				async () => {
					const [ userAParagraphs, userBParagraphs ] =
						await Promise.all( [
							getParagraphContents( page ),
							getParagraphContents( page2 ),
						] );

					return {
						userAParagraphs,
						userBParagraphs,
					};
				},
				{ timeout: 15000 }
			)
			.toEqual( {
				userAParagraphs: expectedParagraphs,
				userBParagraphs: expectedParagraphs,
			} );
	} );
} );
