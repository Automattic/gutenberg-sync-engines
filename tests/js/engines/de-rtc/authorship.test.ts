/**
 * Per-edit authorship (TODO-18): block-grain "who last touched this",
 * derived from the canonical row feed at zero extra wire cost.
 */
import { describe, expect, it, jest } from '@jest/globals';

import { createDeRtcAuthorship } from '../../../../src/engines/de-rtc/authorship';
import { createDeRtcUndoFeed } from '../../../../src/engines/de-rtc/revert-undo';

jest.mock( '@wordpress/blocks', () => ( {
	parse: ( content: string ) => ( content ? JSON.parse( content ) : [] ),
	__unstableSerializeAndClean: ( blocks: unknown[] ) =>
		JSON.stringify( blocks ),
} ) );

const A = { name: 'core/paragraph', attributes: { content: 'Alpha' } };
const A2 = { name: 'core/paragraph', attributes: { content: 'Alpha edited' } };
const B = { name: 'core/paragraph', attributes: { content: 'Beta' } };
const B2 = { name: 'core/paragraph', attributes: { content: 'Beta edited' } };

const contentOf = ( ...blocks: unknown[] ) => JSON.stringify( blocks );

describe( 'de-rtc block authorship', () => {
	it( 'attributes changed blocks to each row author and survives layering', () => {
		const feed = createDeRtcUndoFeed();
		const tracker = createDeRtcAuthorship( feed );

		feed.noteRow( {
			version: 'v1',
			baseVersion: null,
			content: contentOf( A, B ),
			own: false,
		} );
		expect( tracker.getBlockAuthorship() ).toEqual( [ null, null ] );

		feed.noteRow( {
			version: 'v2',
			baseVersion: 'v1',
			content: contentOf( A2, B ),
			own: false,
			author: 7,
			authorClientId: 701,
		} );
		feed.noteRow( {
			version: 'v3',
			baseVersion: 'v2',
			content: contentOf( A2, B2 ),
			own: false,
			author: 8,
			authorClientId: 801,
		} );

		expect( tracker.getBlockAuthorship() ).toEqual( [
			{ author: 7, authorClientId: 701, version: 'v2' },
			{ author: 8, authorClientId: 801, version: 'v3' },
		] );
	} );

	it( 'a structural change resets attribution instead of lying', () => {
		const feed = createDeRtcUndoFeed();
		const tracker = createDeRtcAuthorship( feed );

		feed.noteRow( {
			version: 'v1',
			baseVersion: null,
			content: contentOf( A, B ),
			own: false,
		} );
		feed.noteRow( {
			version: 'v2',
			baseVersion: 'v1',
			content: contentOf( A2, B ),
			own: false,
			author: 7,
			authorClientId: 701,
		} );
		// A block was removed: positions shifted.
		feed.noteRow( {
			version: 'v3',
			baseVersion: 'v2',
			content: contentOf( B ),
			own: false,
			author: 8,
			authorClientId: 801,
		} );

		expect( tracker.getBlockAuthorship() ).toEqual( [ null ] );
	} );
} );
