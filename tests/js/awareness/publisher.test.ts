/**
 * External dependencies
 */
import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

/**
 * Internal dependencies
 */
import type { BlockTreeReader } from '../../../src/awareness/block-id';
import { createPresencePublisher } from '../../../src/awareness/publisher';

/**
 * A tiny block tree: three paragraphs, the first two with a syncId.
 */
function fakeReader() {
	const attributes: Record< string, Record< string, unknown > > = {
		p1: { metadata: { syncId: 's1' } },
		p2: { metadata: { syncId: 's2' } },
		p3: {},
	};
	let selected: string | null = null;
	let selectionStart: { clientId?: string } = {};
	const reader: BlockTreeReader = {
		getSelectedBlockClientId: () => selected,
		getSelectionStart: () => selectionStart,
		getBlockAttributes: ( clientId ) => attributes[ clientId ] ?? null,
	};
	return {
		reader,
		select( clientId: string | null ) {
			selected = clientId;
			selectionStart = clientId ? { clientId } : {};
		},
		multiSelect( first: string ) {
			selected = null;
			selectionStart = { clientId: first };
		},
	};
}

describe( 'presence publisher', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );
	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'publishes on start and then only when the block changes', () => {
		const tree = fakeReader();
		const published: Array< string | null > = [];
		const publisher = createPresencePublisher( {
			reader: tree.reader,
			intervalMs: 5000,
			schedule: 'timer',
			onPublish: ( block ) => published.push( block ),
		} );

		tree.select( 'p1' );
		publisher.start();
		expect( published ).toEqual( [ 's1' ] );

		// Same block: the interval passes with nothing sent.
		jest.advanceTimersByTime( 5000 );
		expect( published ).toEqual( [ 's1' ] );

		// A move is sent at the next interval, not immediately.
		tree.select( 'p2' );
		expect( published ).toEqual( [ 's1' ] );
		jest.advanceTimersByTime( 5000 );
		expect( published ).toEqual( [ 's1', 's2' ] );

		// Leaving every block publishes null once.
		tree.select( null );
		jest.advanceTimersByTime( 10000 );
		expect( published ).toEqual( [ 's1', 's2', null ] );

		publisher.stop();
		tree.select( 'p1' );
		jest.advanceTimersByTime( 10000 );
		expect( published ).toEqual( [ 's1', 's2', null ] );
	} );

	it( 'names a block by syncId when stamped, else by clientId', () => {
		const tree = fakeReader();
		const published: Array< string | null > = [];
		const publisher = createPresencePublisher( {
			reader: tree.reader,
			intervalMs: 1000,
			schedule: 'timer',
			onPublish: ( block ) => published.push( block ),
		} );
		tree.select( 'p3' );
		publisher.start();
		tree.multiSelect( 'p2' );
		jest.advanceTimersByTime( 1000 );
		expect( published ).toEqual( [ 'p3', 's2' ] );

		// A block that no longer exists reads as no block.
		tree.select( 'gone' );
		jest.advanceTimersByTime( 1000 );
		expect( published ).toEqual( [ 'p3', 's2', null ] );
		publisher.stop();
	} );

	it( 'in manual mode publishes on every flush, unchanged or not', () => {
		const tree = fakeReader();
		const published: Array< string | null > = [];
		const publisher = createPresencePublisher( {
			reader: tree.reader,
			intervalMs: 5000,
			schedule: 'manual',
			onPublish: ( block ) => published.push( block ),
		} );
		publisher.start();
		jest.advanceTimersByTime( 20000 );
		expect( published ).toEqual( [] );

		tree.select( 'p1' );
		expect( publisher.flush() ).toBe( 's1' );
		expect( publisher.flush() ).toBe( 's1' );
		expect( published ).toEqual( [ 's1', 's1' ] );
		publisher.stop();
	} );
} );
