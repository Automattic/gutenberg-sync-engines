/**
 * External dependencies
 */
import { beforeEach, describe, expect, it } from '@jest/globals';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { store } from '../../../src/awareness/store';
import type {
	ActivityBeacon,
	BlockRef,
	TrailEntry,
} from '../../../src/awareness/types';

const ref = ( syncId: string, clientId = `c-${ syncId }` ): BlockRef => ( {
	syncId,
	clientId,
	name: 'core/paragraph',
	path: [ 0 ],
} );

const beacon = (
	seq: number,
	focus: BlockRef | null,
	recent: TrailEntry[] = focus ? [ { ref: focus, ageMs: 0 } ] : [],
	edits: ActivityBeacon[ 'edits' ] = []
): ActivityBeacon => ( {
	v: 2,
	seq,
	at: 0,
	intervalMs: 5000,
	focus,
	recent,
	edits,
} );

const riley = { userId: 2, name: 'Riley' };

describe( 'awareness store', () => {
	let registry: ReturnType< typeof createRegistry >;
	beforeEach( () => {
		registry = createRegistry();
		registry.register( store );
	} );

	it( 'derives per-block presence from a beacon, by syncId or clientId', () => {
		registry
			.dispatch( store )
			.receiveBeacon(
				'7',
				riley,
				'#fbbf24',
				beacon( 1, ref( 's2' ), undefined, [
					{ ref: ref( 's2' ), kind: 'edit', count: 3 },
				] ),
				10_000
			);
		const bySyncId = registry
			.select( store )
			.getBlockPresence( 's2', 'local-id' );
		expect( bySyncId ).toEqual( [
			expect.objectContaining( {
				peerKey: '7',
				name: 'Riley',
				role: 'focus',
				typing: true,
				opacity: 1,
				ageMs: 0,
			} ),
		] );
		// The same entry answers to the sender's clientId (yjs-server rooms).
		expect(
			registry.select( store ).getBlockPresence( undefined, 'c-s2' )
		).toEqual( bySyncId );
		expect(
			registry.select( store ).getBlockPresence( 'other', 'x' )
		).toEqual( [] );
	} );

	it( 'sets stripe strength from the trail ages the sender reported', () => {
		registry.dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon( 1, ref( 's2' ), [
				{ ref: ref( 's2' ), ageMs: 0 },
				{ ref: ref( 's1' ), ageMs: 16_000 },
				{ ref: ref( 's0' ), ageMs: 31_000 },
			] ),
			10_000
		);
		const { getBlockPresence } = registry.select( store );
		expect( getBlockPresence( 's2', 'x' )[ 0 ] ).toMatchObject( {
			role: 'focus',
			opacity: 1,
		} );
		expect( getBlockPresence( 's1', 'x' )[ 0 ] ).toMatchObject( {
			role: 'recent',
			opacity: 0.5,
			ageMs: 16_000,
		} );
		expect( getBlockPresence( 's0', 'x' ) ).toEqual( [] );
	} );

	it( 'does not change presence on the clock tick', () => {
		registry
			.dispatch( store )
			.receiveBeacon(
				'7',
				riley,
				'#fbbf24',
				beacon( 1, ref( 's1' ), [
					{ ref: ref( 's1' ), ageMs: 14_000 },
				] ),
				10_000
			);
		const before = registry.select( store ).getBlockPresence( 's1', 'x' );
		expect( before[ 0 ].opacity ).toBe( 1 );
		registry.dispatch( store ).tick( 10_000 + 40_000 );
		expect( registry.select( store ).getBlockPresence( 's1', 'x' ) ).toBe(
			before
		);
	} );

	it( 'drops a peer entirely once it has been silent for long enough', () => {
		registry
			.dispatch( store )
			.receiveBeacon(
				'7',
				riley,
				'#fbbf24',
				beacon( 1, ref( 's1' ) ),
				10_000
			);
		registry.dispatch( store ).tick( 10_000 + 61_000 );
		expect( registry.select( store ).getPeers() ).toEqual( [] );
		// Its stripe lingers at zero strength for the exit animation...
		expect(
			registry.select( store ).getBlockPresence( 's1', 'x' )
		).toEqual( [
			expect.objectContaining( { opacity: 0, leaving: true } ),
		] );
		// ...until pruned.
		registry.dispatch( store ).pruneLeaving();
		expect(
			registry.select( store ).getBlockPresence( 's1', 'x' )
		).toEqual( [] );
	} );

	it( 'animates out blocks a new beacon no longer lists, and updates the rest', () => {
		const { dispatch, select } = registry;
		dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon( 1, ref( 's2' ), [
				{ ref: ref( 's2' ), ageMs: 0 },
				{ ref: ref( 's1' ), ageMs: 20_000 },
			] ),
			10_000
		);
		dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon( 2, ref( 's3' ), [
				{ ref: ref( 's3' ), ageMs: 0 },
				{ ref: ref( 's2' ), ageMs: 5000 },
			] ),
			15_000
		);
		expect(
			select( store ).getBlockPresence( 's3', 'x' )[ 0 ]
		).toMatchObject( { role: 'focus', opacity: 1 } );
		expect(
			select( store ).getBlockPresence( 's2', 'x' )[ 0 ]
		).toMatchObject( { role: 'recent', opacity: 1, ageMs: 5000 } );
		expect(
			select( store ).getBlockPresence( 's1', 'x' )[ 0 ]
		).toMatchObject( { opacity: 0, leaving: true } );
		dispatch( store ).pruneLeaving();
		expect( select( store ).getBlockPresence( 's1', 'x' ) ).toEqual( [] );
		expect( select( store ).getBlockPresence( 's2', 'x' ) ).toHaveLength(
			1
		);
	} );

	it( 'keeps peers in arrival order and lets a leaving one hold its slot', () => {
		const { dispatch, select } = registry;
		const sam = { userId: 3, name: 'Sam' };
		dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon( 1, ref( 's1' ) ),
			10_000
		);
		dispatch( store ).receiveBeacon(
			'9',
			sam,
			'#0f766e',
			beacon( 1, ref( 's1' ) ),
			11_000
		);
		const keys = () =>
			select( store )
				.getBlockPresence( 's1', 'x' )
				.map( ( e ) => [ e.peerKey, e.leaving ?? false ] );
		expect( keys() ).toEqual( [
			[ '7', false ],
			[ '9', false ],
		] );

		// Riley moves on: the entry stays first, leaving, until pruned.
		dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon( 2, ref( 's2' ) ),
			15_000
		);
		expect( keys() ).toEqual( [
			[ '7', true ],
			[ '9', false ],
		] );
		expect(
			select( store ).getBlockPresence( 's1', 'x' )[ 0 ]
		).toMatchObject( {
			opacity: 0,
			lastOpacity: 1,
		} );
		dispatch( store ).pruneLeaving();
		expect( keys() ).toEqual( [ [ '9', false ] ] );

		// Coming back puts Riley below Sam.
		dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon( 3, ref( 's1' ) ),
			20_000
		);
		expect( keys() ).toEqual( [
			[ '9', false ],
			[ '7', false ],
		] );
	} );

	it( 'tracks the hovered block', () => {
		expect( registry.select( store ).getHoveredBlock() ).toBeNull();
		registry
			.dispatch( store )
			.setHoveredBlock( { clientId: 'p1', syncId: 's1' } );
		expect( registry.select( store ).getHoveredBlock() ).toEqual( {
			clientId: 'p1',
			syncId: 's1',
		} );
	} );

	it( 'separates the focused block from other blocks touched in the window', () => {
		registry.dispatch( store ).receiveBeacon(
			'7',
			riley,
			'#fbbf24',
			beacon(
				1,
				ref( 's1' ),
				[
					{ ref: ref( 's1' ), ageMs: 0 },
					{ ref: ref( 's3' ), ageMs: 2000 },
				],
				[
					{ ref: ref( 's3' ), kind: 'insert', count: 1 },
					{ ref: ref( 's4' ), kind: 'remove', count: 1 },
				]
			),
			10_000
		);
		const { getBlockPresence } = registry.select( store );
		expect( getBlockPresence( 's1', 'x' )[ 0 ] ).toMatchObject( {
			role: 'focus',
			typing: false,
		} );
		expect( getBlockPresence( 's3', 'x' )[ 0 ].role ).toBe( 'insert' );
		// A removal is shown on the block the receiver still holds.
		expect( getBlockPresence( 's4', 'x' )[ 0 ].role ).toBe( 'remove' );
	} );

	it( 'attaches peer identity to phantoms and drops them with the peer', () => {
		registry
			.dispatch( store )
			.receiveBeacon(
				'7',
				riley,
				'#fbbf24',
				beacon( 1, ref( 'new' ) ),
				10_000
			);
		registry.dispatch( store ).setPhantoms( [
			{
				peerKey: '7',
				role: 'focus',
				ref: ref( 'new' ),
				opacity: 1,
				ageMs: 0,
				anchorClientId: 'p1',
				placement: 'after',
			},
		] );
		expect( registry.select( store ).getPhantoms() ).toEqual( [
			expect.objectContaining( {
				name: 'Riley',
				color: '#fbbf24',
				opacity: 1,
				anchorClientId: 'p1',
			} ),
		] );
		registry.dispatch( store ).removePeer( '7' );
		expect( registry.select( store ).getPhantoms() ).toEqual( [] );
	} );
} );
