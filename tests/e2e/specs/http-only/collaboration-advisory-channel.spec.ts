/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { test, expect } from '../../config/collaboration-fixtures';

/**
 * The advisory channel (docs/plan/advisory-channel.md): two tabs editing
 * one post find each other through the heartbeat, connect browser to
 * browser, and then poll only on demand plus a slow safety poll; a tab
 * that is alone schedules no polls at all. Edits still travel over the
 * REST sync endpoint and still converge.
 */

interface AdvisoryDebugState {
	active: boolean;
	peers: Array< { token: string; clientId: number | null; open: boolean } >;
}

async function advisoryState( page: Page ): Promise< AdvisoryDebugState > {
	return await page.evaluate( () => {
		const api = (
			window as Window & {
				wpSync?: { advisory: () => AdvisoryDebugState };
			}
		 ).wpSync;
		return api ? api.advisory() : { active: false, peers: [] };
	} );
}

async function waitForOpenPeer( page: Page, timeout: number ): Promise< void > {
	await page.waitForFunction(
		() => {
			const api = (
				window as Window & {
					wpSync?: { advisory: () => AdvisoryDebugState };
				}
			 ).wpSync;
			const state = api?.advisory();
			return (
				!! state &&
				state.peers.some(
					( peer ) => peer.open && null !== peer.clientId
				)
			);
		},
		undefined,
		{ timeout }
	);
}

/**
 * Counts the sync-endpoint requests a page makes over a window. Matched
 * without slashes: without pretty permalinks the route is URL-encoded
 * inside `?rest_route=`.
 *
 * @param page     The page.
 * @param windowMs How long to watch.
 */
async function countSyncRequests( page: Page, windowMs: number ) {
	let count = 0;
	const onRequest = ( request: { url: () => string } ) => {
		if ( request.url().includes( 'wp-sync' ) ) {
			count++;
		}
	};
	page.on( 'request', onRequest );
	await page.waitForTimeout( windowMs );
	page.off( 'request', onRequest );
	return count;
}

test.describe( 'Collaboration - advisory channel', () => {
	test.afterEach( async ( { collaborationUtils } ) => {
		await collaborationUtils.teardown();
	} );

	test( 'a tab editing alone schedules no polls after its first handshake', async ( {
		collaborationUtils,
		requestUtils,
		page,
		editor,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Advisory channel: alone',
			content: '<!-- wp:paragraph --><p>Alone</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );
		await collaborationUtils.openPost( post.id );

		// Let the first poll (the genesis handshake) settle, then watch:
		// nothing should leave.
		await page.waitForTimeout( 4000 );
		expect( await countSyncRequests( page, 6000 ) ).toBe( 0 );

		// Typing is held in the browser: still nothing leaves (the 25 s
		// safety poll is the only timer, and it does not fire in this
		// window).
		await editor.canvas
			.getByRole( 'document', { name: /Block: Paragraph/ } )
			.first()
			.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' still typing' );
		expect( await countSyncRequests( page, 5000 ) ).toBe( 0 );

		// A save flushes the held work through the room first, so a
		// reload bootstraps from a room that saw it.
		const held = countSyncRequests( page, 8000 );
		await editor.saveDraft();
		expect( await held ).toBeGreaterThanOrEqual( 1 );
		await page.reload();
		await collaborationUtils.waitForCollaborationReady( page );
		await expect(
			editor.canvas
				.getByRole( 'document', { name: /Block: Paragraph/ } )
				.first()
		).toContainText( 'Alone still typing' );
	} );

	test( 'two tabs connect over the channel, keep converging, and poll on demand', async ( {
		collaborationUtils,
		requestUtils,
		page,
		editor,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Advisory channel: two tabs',
			content: '<!-- wp:paragraph --><p>Shared</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );
		await collaborationUtils.openCollaborativeSession( post.id );
		const page2 = collaborationUtils.getPage( 0 );

		// Discovery and the handshake ride the heartbeat (10 s ticks), so
		// the channel takes a few beats to come up.
		await waitForOpenPeer( page, 60000 );
		await waitForOpenPeer( page2, 60000 );
		const state = await advisoryState( page );
		expect( state.active ).toBe( true );
		expect( state.peers.filter( ( peer ) => peer.open ) ).toHaveLength( 1 );

		// Give the tabs one more poll to notice full coverage, then watch
		// an idle tab: well under the 1 s timer cadence.
		await page.waitForTimeout( 2500 );
		expect( await countSyncRequests( page2, 8000 ) ).toBeLessThanOrEqual(
			2
		);

		// An edit on one side still reaches the other through the REST
		// endpoint (announced over the channel, delivered by the poll).
		await editor.canvas
			.getByRole( 'document', { name: /Block: Paragraph/ } )
			.first()
			.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' from one' );
		await expect(
			collaborationUtils
				.getEditor( 0 )
				.canvas.getByRole( 'document', { name: /Block: Paragraph/ } )
				.first()
		).toContainText( 'Shared from one', { timeout: 20000 } );
	} );
} );
