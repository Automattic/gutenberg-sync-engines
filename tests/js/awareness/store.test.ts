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

describe( 'awareness store', () => {
	let registry: ReturnType< typeof createRegistry >;

	beforeEach( () => {
		registry = createRegistry();
		registry.register( store );
	} );

	it( 'finds a block’s peer by syncId or by clientId', () => {
		const { setPeer } = registry.dispatch( store );
		const { getPeerForBlock, getPeers } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '11', sam, '#D94145', 'c-plain' );

		expect( getPeerForBlock( 's1', 'c1' )?.identity.name ).toBe( 'Riley' );
		expect( getPeerForBlock( undefined, 'c-plain' )?.identity.name ).toBe(
			'Sam'
		);
		expect( getPeerForBlock( 's9', 'c9' ) ).toBeNull();
		expect( getPeers().map( ( peer ) => peer.key ) ).toEqual( [
			'10',
			'11',
		] );
	} );

	it( 'moves a peer between blocks on one dispatch', () => {
		const { setPeer } = registry.dispatch( store );
		const { getPeerForBlock } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '10', riley, '#6F42C1', 's2' );
		expect( getPeerForBlock( 's1', 'c1' ) ).toBeNull();
		expect( getPeerForBlock( 's2', 'c2' )?.key ).toBe( '10' );

		// In no block at all: present, but drawn nowhere.
		setPeer( '10', riley, '#6F42C1', null );
		expect( getPeerForBlock( 's2', 'c2' ) ).toBeNull();
		expect( registry.select( store ).getPeers() ).toHaveLength( 1 );
	} );

	it( 'keeps object identity when nothing changed', () => {
		const { setPeer } = registry.dispatch( store );
		const { getPeerForBlock, getPeers } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		const before = getPeerForBlock( 's1', 'c1' );
		const peersBefore = getPeers();
		setPeer( '10', { ...riley }, '#6F42C1', 's1' );
		expect( getPeerForBlock( 's1', 'c1' ) ).toBe( before );
		expect( getPeers() ).toBe( peersBefore );

		setPeer( '10', { ...riley, name: 'Riley R.' }, '#6F42C1', 's1' );
		expect( getPeerForBlock( 's1', 'c1' ) ).not.toBe( before );
	} );

	it( 'shows the first peer when two are in one block', () => {
		const { setPeer, removePeer, reset } = registry.dispatch( store );
		const { getPeerForBlock, getPeers } = registry.select( store );

		setPeer( '10', riley, '#6F42C1', 's1' );
		setPeer( '11', sam, '#D94145', 's1' );
		expect( getPeerForBlock( 's1', 'c1' )?.key ).toBe( '10' );

		removePeer( '10' );
		expect( getPeerForBlock( 's1', 'c1' )?.key ).toBe( '11' );

		reset();
		expect( getPeers() ).toEqual( [] );
	} );
} );
