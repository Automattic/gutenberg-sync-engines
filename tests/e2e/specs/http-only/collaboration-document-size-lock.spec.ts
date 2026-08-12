/**
 * Internal dependencies
 */
import {
	test,
	expect,
} from '../../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures';

/*
 * NOTE — engine-dependent scope: this spec covers the CLIENT-side outgoing
 * size guard (the polling manager fences an oversized local update and drops
 * the session out of collaboration). Under the retired yjs-relay engine the
 * guard ALSO fired for every later visitor, because each client re-authored
 * the whole document at init (client-side genesis) — so a second user fell
 * back to the classic post-locked modal, which this spec used to assert.
 * Under yjs-server the server owns genesis and clients only send small
 * diffs, so later joiners are never size-fenced; there is no server-side
 * genesis size gate yet (a known yjs-server gap — see
 * docs/engine-comparison.md).
 */
test.describe( 'Collaboration with large documents', () => {
	test( 'disables collaboration when an oversized local update exceeds the document size limit', async ( {
		collaborationUtils,
		requestUtils,
		admin,
		page,
	} ) => {
		// Rendering a >1 MB paragraph keeps the editor's main thread busy
		// for a long stretch (~45 s even on fast hardware), and CI runners
		// have needed well over 100 s in total. Declare the proven budget
		// explicitly rather than via test.slow(), which would silently
		// shrink whenever the config default changes.
		test.setTimeout( 300_000 );

		// Create a small draft post — the large content is inserted via
		// the block editor API after the editor has loaded, so User 1's
		// page renders quickly.
		const post = await requestUtils.createPost( {
			title: 'Document Size Lock Test',
			status: 'draft',
			date_gmt: new Date().toISOString(),
		} );

		const postRoom = `postType/post:${ post.id }`;

		// User 1 (admin) opens the post.
		await admin.editPost( post.id );

		// Wait for collaboration runtime and entity record to be ready.
		await collaborationUtils.waitForEntityReady( page );

		// Insert a paragraph block with content exceeding 1 MB
		// (MAX_UPDATE_SIZE_IN_BYTES). This triggers the polling
		// manager's onDocUpdate size check, which emits
		// 'document-size-limit-exceeded' and unregisters the room.
		await page.evaluate( () => {
			const largeContent = 'x'.repeat( 1.01 * 1024 * 1024 );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlock(
				window.wp.blocks.createBlock( 'core/paragraph', {
					content: largeContent,
				} )
			);
		} );

		// Wait for collaboration to be disabled. This confirms the full
		// code path ran: onDocUpdate detected the oversized update,
		// emitted the status, and unregistered the room.
		await page.waitForFunction(
			( consent ) => {
				const privateApis = ( window as any ).wp.privateApis;
				const { unlock } =
					privateApis.__dangerousOptInToUnstableAPIsOnlyForCoreModules(
						consent,
						'@wordpress/core-data'
					);
				return (
					unlock(
						window.wp.data.select( 'core/editor' )
					).isCollaborationEnabledForCurrentPost() === false
				);
			},
			'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.',
			{ timeout: 30000 }
		);

		// Verify the sync connection status is 'disconnected' with
		// a 'document-size-limit-exceeded' error code.
		const syncStatus = await page.evaluate( ( consent ) => {
			const privateApis = ( window as any ).wp.privateApis;
			const { unlock } =
				privateApis.__dangerousOptInToUnstableAPIsOnlyForCoreModules(
					consent,
					'@wordpress/core-data'
				);
			const status = unlock(
				window.wp.data.select( 'core' )
			).getSyncConnectionStatus();
			return {
				status: status?.status,
				errorCode: status?.error?.code,
			};
		}, 'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.' );
		expect( syncStatus ).toEqual( {
			status: 'disconnected',
			errorCode: 'document-size-limit-exceeded',
		} );

		// Verify that the post's entity room is no longer included in
		// sync polling requests. Race between the next sync response and
		// a timeout — if a response arrives, assert it doesn't contain
		// the post's room; if the timeout wins, polling has stopped
		// entirely. Either outcome confirms the room was unregistered.
		const POLL_TIMEOUT = 3000;
		const nextSyncResponse = page.waitForResponse(
			( res ) => res.url().includes( 'wp-sync' ) && res.status() === 200
		);
		const timeout = new Promise< 'timeout' >( ( resolve ) =>
			setTimeout( () => resolve( 'timeout' ), POLL_TIMEOUT )
		);
		const result = await Promise.race( [ nextSyncResponse, timeout ] );

		if ( result !== 'timeout' ) {
			const body = await result.text();
			expect( body ).not.toContain( postRoom );
		}
	} );
} );
