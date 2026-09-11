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

const riley = { userId: 2, name: 'Riley', avatarUrl: 'https://a/riley' };
const sam = { userId: 3, name: 'Sam' };
const kim = { userId: 4, name: 'Kim' };

describe( 'awareness store', () => {
	let registry: ReturnType< typeof createRegistry >;

	beforeEach( () => {
		registry = createRegistry();
		registry.register( store );
	} );

	it( 'finds a block’s peer by syncId or by clientId', () => {
		const { setPeer } = registry.dispatch( store );
		const { getPeersForBlock, getPeers } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '11', sam, '#D94145', 'c-plain' );

		expect( getPeersForBlock( 's1', 'c1' )[ 0 ]?.identity.name ).toBe(
			'Riley'
		);
		expect(
			getPeersForBlock( undefined, 'c-plain' )[ 0 ]?.identity.name
		).toBe( 'Sam' );
		expect( getPeersForBlock( 's9', 'c9' ) ).toEqual( [] );
		expect( getPeers().map( ( peer ) => peer.key ) ).toEqual( [
			'10',
			'11',
		] );
	} );

	it( 'moves a peer between blocks on one dispatch', () => {
		const { setPeer } = registry.dispatch( store );
		const { getPeersForBlock } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '10', riley, '#6F42C1', 's2' );
		expect( getPeersForBlock( 's1', 'c1' ) ).toEqual( [] );
		expect( getPeersForBlock( 's2', 'c2' )[ 0 ]?.key ).toBe( '10' );

		// In no block at all: present, but drawn nowhere.
		setPeer( '10', riley, '#6F42C1', null );
		expect( getPeersForBlock( 's2', 'c2' ) ).toEqual( [] );
		expect( registry.select( store ).getPeers() ).toHaveLength( 1 );
	} );

	it( 'keeps object identity when nothing changed', () => {
		const { setPeer } = registry.dispatch( store );
		const { getPeersForBlock, getPeers } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		const before = getPeersForBlock( 's1', 'c1' );
		const peersBefore = getPeers();
		setPeer( '10', { ...riley }, '#6F42C1', 's1' );
		expect( getPeersForBlock( 's1', 'c1' ) ).toBe( before );
		expect( getPeers() ).toBe( peersBefore );

		setPeer( '10', { ...riley, name: 'Riley R.' }, '#6F42C1', 's1' );
		expect( getPeersForBlock( 's1', 'c1' ) ).not.toBe( before );
		// An empty block always answers with the same array.
		expect( getPeersForBlock( 's9', 'c9' ) ).toBe(
			getPeersForBlock( 's8', 'c8' )
		);
	} );

	it( 'orders the peers in one block by who entered it first', () => {
		const { setPeer, removePeer, reset } = registry.dispatch( store );
		const { getPeersForBlock, getPeers } = registry.select( store );

		// Sam is known first, but Riley enters s1 first.
		setPeer( '11', sam, '#D94145', 's2' );
		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '11', sam, '#D94145', 's1' );
		setPeer( '12', kim, '#0E8A5D', 's1' );
		const keys = () =>
			getPeersForBlock( 's1', 'c1' ).map( ( peer ) => peer.key );
		expect( keys() ).toEqual( [ '10', '11', '12' ] );

		// A repeated report, or a renamed peer, keeps their place.
		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '10', { ...riley, name: 'Riley R.' }, '#6F42C1', 's1' );
		expect( keys() ).toEqual( [ '10', '11', '12' ] );

		// Leaving and coming back puts the peer at the end.
		setPeer( '10', riley, '#6F42C1', 's2' );
		expect( keys() ).toEqual( [ '11', '12' ] );
		setPeer( '10', riley, '#6F42C1', 's1' );
		expect( keys() ).toEqual( [ '11', '12', '10' ] );

		// A peer who drops off the channel entirely leaves the stack.
		removePeer( '11' );
		expect( keys() ).toEqual( [ '12', '10' ] );

		reset();
		expect( getPeers() ).toEqual( [] );
	} );
} );
