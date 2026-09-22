/**
 * The host store: connection status, review items, and the "supported"
 * flag the collaboration UI reads.
 */

/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';

/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';

// The editor package drags most of the block editor in; the host modules
// only need its store handle.
jest.mock( '@wordpress/editor', () => ( {
	store: { name: 'core/editor' },
} ) );

/**
 * Internal dependencies
 */
import { store } from '../../../src/host/store';

describe( 'the host store', () => {
	beforeEach( () => {
		dispatch( store ).setCollaborationSupported( true );
		dispatch( store ).setSyncConnectionStatus(
			'postType',
			'post',
			1,
			null
		);
		dispatch( store ).setSyncConnectionStatus(
			'root',
			'comment',
			null,
			null
		);
		dispatch( store ).setSyncReviewItems( 'postType', 'post', 1, [] );
	} );

	it( 'coalesces connection status with disconnected first', () => {
		dispatch( store ).setSyncConnectionStatus( 'postType', 'post', 1, {
			status: 'connected',
		} );
		dispatch( store ).setSyncConnectionStatus( 'root', 'comment', null, {
			status: 'connecting',
		} );

		expect( select( store ).getSyncConnectionStatus() ).toEqual( {
			status: 'connecting',
		} );
		expect(
			select( store ).getEntitySyncConnectionStatus(
				'postType',
				'post',
				1
			)
		).toEqual( { status: 'connected' } );

		dispatch( store ).setSyncConnectionStatus(
			'root',
			'comment',
			null,
			null
		);
		expect( select( store ).getSyncConnectionStatus() ).toEqual( {
			status: 'connected',
		} );
	} );

	it( 'stores and clears review items per entity', () => {
		const items = [
			{
				id: 'p1',
				unitId: 'p1',
				isLocal: true,
				actorId: 'a',
				reason: 'r',
				intentType: 't',
			},
		];
		dispatch( store ).setSyncReviewItems( 'postType', 'post', 1, items );
		expect(
			select( store ).getSyncReviewItems( 'postType', 'post', 1 )
		).toEqual( items );

		dispatch( store ).setSyncReviewItems( 'postType', 'post', 1, [] );
		expect(
			select( store ).getSyncReviewItems( 'postType', 'post', 1 )
		).toEqual( [] );
	} );

	it( 'defaults to supported and records the flip', () => {
		expect( select( store ).isCollaborationSupported() ).toBe( true );
		dispatch( store ).setCollaborationSupported( false );
		expect( select( store ).isCollaborationSupported() ).toBe( false );
	} );
} );
