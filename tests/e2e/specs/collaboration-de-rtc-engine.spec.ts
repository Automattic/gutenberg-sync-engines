/**
 * WordPress dependencies
 */
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	test,
	expect,
} from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures';
import {
	SECOND_USER,
	type CollaborationUtils,
} from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures/collaboration-utils';

/**
 * Two-client collaboration through the de-rtc sync engine: Distributed
 * Editing's save-centric model on the room protocol. Clients propose
 * whole content against the version they last incorporated; the SERVER
 * three-way-merges every proposal with the ported DE-RTC merge core and
 * broadcasts canonical content rows.
 *
 * These specs flip the site's `wp_sync_engine` option to `de-rtc` and
 * exercise the full stack: editor changes → de-rtc session codec →
 * polling transport → WP_De_RTC_Engine (merge core) → back. The suite
 * restores the default engine when done.
 *
 * Deliberately absent (v1 engine gaps, see docs/engine-comparison.md):
 * title sync (proposals carry content only) and the empty-post
 * concurrent-first-paragraph scenario (concurrent differing appends at
 * the same edge are a BY-DESIGN escalation under DE-RTC policy, not a
 * merge — and now a REVIEWABLE one: escalations park for the conflict
 * review panel, exercised by the review-lane spec below).
 */

async function setSyncEngine(
	requestUtils: RequestUtils,
	engine: string | null
) {
	if ( null === engine ) {
		// Nulling an already-absent option 500s (see the intent-log spec's
		// identical helper): restore only while our flip is in effect.
		const settings = await requestUtils.rest( {
			path: '/wp/v2/settings',
		} );
		if ( 'de-rtc' !== settings.wp_sync_engine ) {
			return;
		}
	}
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: { wp_sync_engine: engine },
	} );
}

/**
 * openCollaborativeSession with a welcome-guide belt: the fixture
 * dismisses the second user's welcome guide with a preferences dispatch
 * that late preference hydration can clobber under full-suite load; an
 * open guide modal aria-hides the whole page, so mutual discovery times
 * out even though sync is healthy. Close the modal directly when it
 * (re)appears.
 *
 * @param collaborationUtils The collaboration fixture.
 * @param postId             The post to open.
 */
async function openSession(
	collaborationUtils: CollaborationUtils,
	postId: number
) {
	await collaborationUtils.openPost( postId );
	await collaborationUtils.joinUser( postId, SECOND_USER );
	const page2 = collaborationUtils.page2;
	await page2.evaluate( () => {
		( window as any ).wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false );
	} );
	await page2
		.getByRole( 'dialog' )
		.getByRole( 'button', { name: 'Close' } )
		.click( { timeout: 3000 } )
		.catch( () => {} );
	await collaborationUtils.waitForMutualDiscovery();
}

test.describe( 'Collaboration - de-rtc engine', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await setSyncEngine( requestUtils, 'de-rtc' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSyncEngine( requestUtils, null );
	} );

	test( 'syncs text edits between two users from a server-side genesis', async ( {
		collaborationUtils,
		requestUtils,
		editor,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'De-RTC Sync Test',
			status: 'draft',
			content:
				'<!-- wp:paragraph -->\n<p>Existing content</p>\n<!-- /wp:paragraph -->',
			date_gmt: new Date().toISOString(),
		} );

		await openSession( collaborationUtils, post.id );
		const { editor2 } = collaborationUtils;

		// Both clients bootstrapped from the SERVER's genesis snapshot (the
		// document was never seeded client-side).
		for ( const currentEditor of [ editor, editor2 ] ) {
			await expect( async () => {
				const blocks = await currentEditor.getBlocks();
				expect( blocks ).toMatchObject( [
					{
						name: 'core/paragraph',
						attributes: { content: 'Existing content' },
					},
				] );
			} ).toPass( { timeout: 10000 } );
		}

		// User 1 appends a paragraph.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'Written by user one' },
		} );

		// User 2 sees both paragraphs.
		await expect( async () => {
			const blocks = await editor2.getBlocks();
			expect( blocks ).toMatchObject( [
				{
					name: 'core/paragraph',
					attributes: { content: 'Existing content' },
				},
				{
					name: 'core/paragraph',
					attributes: { content: 'Written by user one' },
				},
			] );
		} ).toPass( { timeout: 10000 } );

		// User 2 edits the first paragraph; user 1 sees the edit.
		await editor2.canvas
			.locator( '[data-type="core/paragraph"]' )
			.first()
			.click();
		await collaborationUtils.page2.keyboard.press( 'End' );
		await collaborationUtils.page2.keyboard.type( ' plus user two' );

		await expect( async () => {
			const blocks = await editor.getBlocks();
			expect( blocks[ 0 ].attributes.content ).toBe(
				'Existing content plus user two'
			);
		} ).toPass( { timeout: 10000 } );
	} );

	test( 'concurrent edits to different blocks both survive the three-way merge', async ( {
		collaborationUtils,
		requestUtils,
		editor,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'De-RTC Concurrency Test',
			status: 'draft',
			content:
				'<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->',
			date_gmt: new Date().toISOString(),
		} );

		await openSession( collaborationUtils, post.id );
		const { editor2, page2 } = collaborationUtils;
		const page1 = editor.page;

		await editor.canvas
			.locator( '[data-type="core/paragraph"]' )
			.first()
			.click();
		await page1.keyboard.press( 'End' );
		await editor2.canvas
			.locator( '[data-type="core/paragraph"]' )
			.nth( 1 )
			.click();
		await page2.keyboard.press( 'End' );

		// One proposal lands first; the other arrives with a stale base and
		// the SERVER rebases it over the accepted edit.
		await page1.keyboard.type( ' from one' );
		await page2.keyboard.type( ' from two' );

		for ( const currentEditor of [ editor, editor2 ] ) {
			await expect( async () => {
				const blocks = await currentEditor.getBlocks();
				expect( blocks[ 0 ].attributes.content ).toBe(
					'First from one'
				);
				expect( blocks[ 1 ].attributes.content ).toBe(
					'Second from two'
				);
			} ).toPass( { timeout: 15000 } );
		}
	} );

	test( 'a save captures both users’ settled edits and persists clean content', async ( {
		collaborationUtils,
		requestUtils,
		editor,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'De-RTC Save Flow Test',
			status: 'draft',
			content:
				'<!-- wp:paragraph -->\n<p>Shared start</p>\n<!-- /wp:paragraph -->',
			date_gmt: new Date().toISOString(),
		} );

		await openSession( collaborationUtils, post.id );
		const { editor2, page2 } = collaborationUtils;

		await editor2.canvas
			.locator( '[data-type="core/paragraph"]' )
			.first()
			.click();
		await page2.keyboard.press( 'End' );
		await page2.keyboard.type( ' plus user two' );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'Added by admin' },
		} );

		for ( const currentEditor of [ editor, editor2 ] ) {
			await expect( async () => {
				const blocks = await currentEditor.getBlocks();
				expect( blocks ).toMatchObject( [
					{
						attributes: {
							content: 'Shared start plus user two',
						},
					},
					{ attributes: { content: 'Added by admin' } },
				] );
			} ).toPass( { timeout: 15000 } );
		}

		await editor.saveDraft();

		const saved = await requestUtils.rest< { content: { raw: string } } >( {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );
		expect( saved.content.raw ).toContain( 'Shared start plus user two' );
		expect( saved.content.raw ).toContain( 'Added by admin' );

		// The non-saving peer's editor is unaffected by the save.
		const peerBlocks = await editor2.getBlocks();
		expect( peerBlocks ).toMatchObject( [
			{ attributes: { content: 'Shared start plus user two' } },
			{ attributes: { content: 'Added by admin' } },
		] );
	} );

	test( 'a genuine conflict parks for review, the panel presents it, and discard closes it for both users', async ( {
		collaborationUtils,
		requestUtils,
		editor,
	} ) => {
		// The escalation needs a sustained same-region typing race plus
		// settle waits; comfortably past the 60 s default cap on CI.
		test.setTimeout( 120_000 );

		const post = await requestUtils.createPost( {
			title: 'DE-RTC Review Lane Test',
			status: 'draft',
			content:
				'<!-- wp:paragraph -->\n<p>Contested paragraph words</p>\n<!-- /wp:paragraph -->',
		} );

		await openSession( collaborationUtils, post.id );
		const { editor2, page2 } = collaborationUtils;
		const page1 = editor.page;

		/*
		 * Both users REPLACE the same words from the same base version
		 * (edge inserts at Home/End would merge cleanly — de-rtc's
		 * three-way merge conflicts only on overlapping rewrites). The
		 * server accepts whichever proposal lands first; the other is a
		 * genuine conflict (`manual-conflict-required`) that now PARKS as
		 * a durable review row instead of being silently abandoned.
		 */
		const paragraph1 = editor.canvas
			.locator( '[data-type="core/paragraph"]' )
			.first();
		const paragraph2 = editor2.canvas
			.locator( '[data-type="core/paragraph"]' )
			.first();
		await paragraph1.click( { clickCount: 3 } );
		await paragraph2.click( { clickCount: 3 } );

		await Promise.all( [
			page1.keyboard.type( 'Rewrite by user one', { delay: 50 } ),
			page2.keyboard.type( 'Rewrite by user two', { delay: 50 } ),
		] );

		// At least one side surfaces the escalation notice (the parked row
		// reaches BOTH replicas; the notice names the loser's own edit on
		// its page and a collaborator's edit on the other).
		let noticePage = page1;
		let noticeEditor = editor;
		await expect( async () => {
			const counts = await Promise.all( [
				page1.getByText( /set aside/ ).count(),
				page2.getByText( /set aside/ ).count(),
			] );
			expect( counts[ 0 ] + counts[ 1 ] ).toBeGreaterThan( 0 );
			noticePage = counts[ 0 ] > 0 ? page1 : page2;
			noticeEditor = counts[ 0 ] > 0 ? editor : editor2;
		} ).toPass( { timeout: 20000 } );

		// The review panel in the document sidebar lists the parked
		// conflict with the shared frame-conflict vocabulary.
		await noticeEditor.openDocumentSettingsSidebar();
		await noticePage
			.getByRole( 'tab', { name: 'Post', exact: true } )
			.click();
		const panel = noticePage.locator(
			'.editor-collaboration-review-panel'
		);
		await expect( panel ).toBeVisible( { timeout: 15000 } );

		// Discard everything parked, until settled-and-still-empty (the
		// typing race can escalate additional proposals in flight).
		await expect( async () => {
			for ( let i = 0; i < 40; i++ ) {
				const discardAll = panel.getByRole( 'button', {
					name: 'Discard all',
				} );
				if ( ( await discardAll.count() ) > 0 ) {
					await discardAll.click();
					continue;
				}
				const discard = panel
					.getByRole( 'button', { name: 'Discard', exact: true } )
					.first();
				if ( ( await discard.count() ) > 0 ) {
					await discard.click();
					continue;
				}
				break;
			}
			await noticePage.waitForTimeout( 3000 );
			expect( await panel.count() ).toBe( 0 );
		} ).toPass( { timeout: 60000 } );

		// The resolution row travels to the OTHER collaborator too: their
		// notices clear and their panel (were it open) would be empty.
		await expect( async () => {
			const otherPage = noticePage === page1 ? page2 : page1;
			expect(
				await otherPage
					.locator( '.components-notice' )
					.filter( { hasText: 'set aside' } )
					.count()
			).toBe( 0 );
			expect(
				await otherPage
					.locator( '.editor-collaboration-review-panel' )
					.count()
			).toBe( 0 );
		} ).toPass( { timeout: 20000 } );

		// Both canvases hold the same settled content (canonical won; the
		// parked words were discarded).
		await expect( async () => {
			const [ blocks1, blocks2 ] = await Promise.all( [
				editor.getBlocks(),
				editor2.getBlocks(),
			] );
			expect( blocks1 ).toEqual( blocks2 );
		} ).toPass( { timeout: 20000 } );
	} );
} );
