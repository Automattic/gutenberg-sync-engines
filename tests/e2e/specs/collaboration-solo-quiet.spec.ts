/**
 * Internal dependencies
 */
import { test, expect } from '../config/collaboration-fixtures';
import { SECOND_USER } from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures/collaboration-utils';

/**
 * Quiet while editing alone (issue #72): a solo editor tab stops sending
 * sync requests once its pipeline settles, and notices a second person
 * through the heartbeat WordPress already sends. Runs on the default
 * engine (intent-log) over the default transport (http-polling).
 */

function trackSyncRequests( page: import('@playwright/test').Page ): {
	count: () => number;
	reset: () => void;
} {
	let seen = 0;
	page.on( 'request', ( request ) => {
		if ( request.url().includes( 'wp-sync' ) ) {
			seen++;
		}
	} );
	return {
		count: () => seen,
		reset: () => {
			seen = 0;
		},
	};
}

/**
 * Waits until the page has gone a full observation window without a single
 * sync request (the transport settled into quiet mode).
 *
 * @param page    The page whose timers drive the wait.
 * @param tracker The request tracker for that page.
 */
async function waitForQuiet(
	page: import('@playwright/test').Page,
	tracker: ReturnType< typeof trackSyncRequests >
): Promise< void > {
	for ( let attempt = 0; attempt < 8; attempt++ ) {
		tracker.reset();
		await page.waitForTimeout( 6000 );
		if ( 0 === tracker.count() ) {
			return;
		}
	}
	throw new Error( 'Sync requests never went quiet' );
}

test.describe( 'quiet while editing alone', () => {
	test( 'an idle solo editor stops sending sync requests', async ( {
		collaborationUtils,
		requestUtils,
		page,
	} ) => {
		test.setTimeout( 120_000 );

		const post = await requestUtils.createPost( {
			title: 'Solo quiet idle',
			status: 'draft',
			content:
				'<!-- wp:paragraph --><p>Sitting still</p><!-- /wp:paragraph -->',
		} );

		const tracker = trackSyncRequests( page );
		await collaborationUtils.openPost( post.id );

		// The session bootstraps with a few polls, then settles.
		await waitForQuiet( page, tracker );

		// A focused, idle tab stays silent: not one sync request across a
		// window that would have held several polls at the old cadence.
		tracker.reset();
		await page.waitForTimeout( 25_000 );
		expect( tracker.count() ).toBe( 0 );
	} );

	test( 'text typed while alone reaches a late joiner, and both windows go live', async ( {
		collaborationUtils,
		requestUtils,
		editor,
		page,
	} ) => {
		test.setTimeout( 180_000 );

		const post = await requestUtils.createPost( {
			title: 'Solo quiet late join',
			status: 'draft',
			content: '',
		} );

		const tracker = trackSyncRequests( page );
		await collaborationUtils.openPost( post.id );

		// Type while alone. The transport wakes to drain the edits into the
		// room, then settles back into quiet.
		await editor.canvas
			.getByRole( 'document', { name: 'Add default block' } )
			.click();
		await page.keyboard.type( 'Typed while alone' );
		await waitForQuiet( page, tracker );

		// A second person joins. Their editor starts syncing immediately
		// (the first tab's presence is stamped server-side), so the text
		// typed during the quiet period is already in the room.
		const { page: page2, editor: editor2 } =
			await collaborationUtils.joinUser( post.id, SECOND_USER );
		await expect(
			editor2.canvas.getByText( 'Typed while alone' )
		).toBeVisible( { timeout: 30_000 } );

		// The first tab notices the company through the heartbeat and
		// resumes syncing within a heartbeat cycle.
		tracker.reset();
		await expect
			.poll( () => tracker.count(), { timeout: 30_000 } )
			.toBeGreaterThan( 0 );

		// From here on it is ordinary live collaboration, both directions.
		await editor2.canvas.getByText( 'Typed while alone' ).click();
		await page2.keyboard.press( 'End' );
		await page2.keyboard.press( 'Enter' );
		await page2.keyboard.type( 'Joined later' );
		await expect( editor.canvas.getByText( 'Joined later' ) ).toBeVisible( {
			timeout: 30_000,
		} );

		await page.keyboard.press( 'Escape' );
	} );
} );
