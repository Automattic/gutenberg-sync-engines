/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { test, expect } from '../../config/collaboration-fixtures';

/**
 * Runs with the SSE transport selected on the tests site (see
 * playwright.rtc-sse.config.ts): each tab holds one long-lived stream
 * response that the server writes to when Redis announces a change, and
 * sends its own edits through the ordinary updates request.
 */

interface SseDebugState {
	open: boolean;
	events: number;
	rooms: Record< string, number >;
}

async function sseState( page: Page ): Promise< SseDebugState > {
	return await page.evaluate( () => {
		const state = (
			window as Window & { __wpSyncSseState?: SseDebugState }
		 ).__wpSyncSseState;
		return state ?? { open: false, events: 0, rooms: {} };
	} );
}

function countRequests( page: Page, needle: string ) {
	const counter = { count: 0 };
	page.on( 'request', ( request ) => {
		// Matched on the decoded URL: without pretty permalinks the route
		// is URL-encoded inside `?rest_route=`.
		if ( decodeURIComponent( request.url() ).includes( needle ) ) {
			counter.count++;
		}
	} );
	return counter;
}

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

test.describe( 'Collaboration - server-sent events transport', () => {
	test( "delivers a peer's edit over the open stream, with the receiver sending nothing", async ( {
		collaborationUtils,
		requestUtils,
		editor,
		page,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'SSE delivery',
			content: paragraph( 'Streamed' ),
			status: 'draft',
		} );

		await collaborationUtils.openCollaborativeSession( post.id );
		const { editor2, page2 } = collaborationUtils;

		// Both tabs hold an open stream that has delivered at least the
		// initial catch-up for the post room.
		for ( const target of [ page, page2 ] ) {
			await expect
				.poll( () => sseState( target ), { timeout: 20000 } )
				.toMatchObject( { open: true } );
			expect(
				Object.keys( ( await sseState( target ) ).rooms )
			).toContain( `postType/post:${ post.id }` );
		}

		const receiverUpdates = countRequests( page2, '/wp-sync/v1/updates' );
		const eventsBefore = ( await sseState( page2 ) ).events;

		await editor.canvas
			.getByRole( 'document', { name: /Block: Paragraph/ } )
			.first()
			.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' over sse' );

		await expect(
			editor2.canvas
				.getByRole( 'document', { name: /Block: Paragraph/ } )
				.first()
		).toContainText( 'Streamed over sse', { timeout: 20000 } );

		// The edit arrived as stream events; the receiver had nothing to
		// send, so it never used the updates request.
		expect( ( await sseState( page2 ) ).events ).toBeGreaterThan(
			eventsBefore
		);
		expect( receiverUpdates.count ).toBe( 0 );
	} );

	test( 'does not lose characters when two users rapidly type in different paragraphs', async ( {
		collaborationUtils,
		requestUtils,
		editor,
		page,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'SSE Concurrent Typing',
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

		// Human-plausible typing speed; see the websocket twin of this
		// spec for why 1 ms bursts are not representative.
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
					return { userAParagraphs, userBParagraphs };
				},
				{ timeout: 15000 }
			)
			.toEqual( {
				userAParagraphs: expectedParagraphs,
				userBParagraphs: expectedParagraphs,
			} );
	} );
} );
