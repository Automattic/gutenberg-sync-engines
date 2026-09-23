/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { test, expect } from '../../config/collaboration-fixtures';
import { SECOND_USER } from '../../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures/collaboration-utils';

/**
 * SSE receives over a stream while one can be opened, and short polling,
 * the base transport, is the fallback whenever it cannot (a host without
 * Redis answers the stream request with 503; a proxy may refuse it).
 * Here the stream route is blocked for both tabs, so both must receive
 * over short polling and still converge.
 */

interface SseDebugState {
	open: boolean;
	events: number;
}

async function sseState( page: Page ): Promise< SseDebugState > {
	return await page.evaluate( () => {
		const state = (
			window as Window & { __wpSyncSseState?: SseDebugState }
		 ).__wpSyncSseState;
		return state ?? { open: false, events: 0 };
	} );
}

// Matched on the decoded URL: without pretty permalinks the route is
// URL-encoded inside `?rest_route=`.
const isStreamRequest = ( url: URL ) =>
	decodeURIComponent( url.href ).includes( '/wp-sync/v1/sse' );

async function countUpdatesRequests( page: Page, windowMs: number ) {
	let count = 0;
	const onRequest = ( request: { url: () => string } ) => {
		if (
			decodeURIComponent( request.url() ).includes(
				'/wp-sync/v1/updates'
			)
		) {
			count++;
		}
	};
	page.on( 'request', onRequest );
	await page.waitForTimeout( windowMs );
	page.off( 'request', onRequest );
	return count;
}

test.describe( 'Collaboration - server-sent events fallback to short polling', () => {
	test.afterEach( async ( { collaborationUtils } ) => {
		await collaborationUtils.teardown();
	} );

	test( 'with the stream unreachable, both tabs receive over short polling and converge', async ( {
		collaborationUtils,
		requestUtils,
		page,
		editor,
		context,
	} ) => {
		// Block the stream route in this context AND in every context the
		// fixture opens for the joiner (it creates a fresh one from the
		// same browser).
		await context.route( isStreamRequest, ( route ) => route.abort() );
		const browser = context.browser()!;
		const newContext = browser.newContext.bind( browser );
		browser.newContext = ( async ( ...args: unknown[] ) => {
			const created = await (
				newContext as (
					...a: unknown[]
				) => ReturnType< typeof newContext >
			 )( ...args );
			await created.route( isStreamRequest, ( route ) => route.abort() );
			return created;
		} ) as typeof browser.newContext;

		const post = await requestUtils.createPost( {
			title: 'SSE fallback',
			content:
				'<!-- wp:paragraph --><p>Fallback</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );

		await collaborationUtils.openPost( post.id );
		const joined = await collaborationUtils.joinUser(
			post.id,
			SECOND_USER
		);
		browser.newContext = newContext;

		// Neither tab ever had a stream deliver anything.
		for ( const target of [ page, joined.page ] ) {
			expect( await sseState( target ) ).toMatchObject( {
				open: false,
				events: 0,
			} );
		}

		// Short polling is live: updates requests flow on the timer cadence
		// (the tests site pins the interval to one second).
		expect( await countUpdatesRequests( page, 4000 ) ).toBeGreaterThan( 0 );

		// And edits still converge through it.
		await editor.canvas
			.getByRole( 'document', { name: /Block: Paragraph/ } )
			.first()
			.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' over polling' );
		await expect(
			joined.editor.canvas
				.getByRole( 'document', { name: /Block: Paragraph/ } )
				.first()
		).toContainText( 'Fallback over polling', { timeout: 20000 } );
		for ( const target of [ page, joined.page ] ) {
			expect( ( await sseState( target ) ).open ).toBe( false );
		}
	} );
} );
