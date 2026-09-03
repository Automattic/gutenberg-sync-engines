/**
 * External dependencies
 */
import { describe, expect, it } from '@jest/globals';

/**
 * Internal dependencies
 */
import {
	buildIdentityIndex,
	excerptOf,
	makeBlockRef,
	pathOf,
	resolveBlockRef,
} from '../../../src/awareness/block-refs';
import { block, createFakeTree } from './fake-tree';

const withSyncId = ( syncId: string, content = '' ) => ( {
	content,
	metadata: { syncId },
} );

describe( 'block refs', () => {
	const tree = createFakeTree( [
		block(
			'p1',
			'core/paragraph',
			withSyncId( 's1', 'Hello <b>world</b>' )
		),
		block( 'g1', 'core/group', withSyncId( 'sg' ), [
			block( 'p2', 'core/paragraph', withSyncId( 's2', 'Inside' ) ),
			block( 'p3', 'core/paragraph', { content: 'No sync id' } ),
		] ),
	] );

	it( 'computes index paths from the root', () => {
		expect( pathOf( tree, 'p1' ) ).toEqual( [ 0 ] );
		expect( pathOf( tree, 'p3' ) ).toEqual( [ 1, 1 ] );
	} );

	it( 'builds a reference with identity, neighbors, and an excerpt', () => {
		expect( makeBlockRef( tree, 'p2' ) ).toEqual( {
			clientId: 'p2',
			syncId: 's2',
			name: 'core/paragraph',
			path: [ 1, 0 ],
			after: null,
			parent: 'sg',
			excerpt: 'Inside',
		} );
		expect( makeBlockRef( tree, 'p3' ) ).toEqual( {
			clientId: 'p3',
			name: 'core/paragraph',
			path: [ 1, 1 ],
			after: 's2',
			parent: 'sg',
			excerpt: 'No sync id',
		} );
		expect( makeBlockRef( tree, 'missing' ) ).toBeNull();
	} );

	it( 'strips markup and truncates excerpts', () => {
		expect( excerptOf( { content: 'Hello <b>world</b>&nbsp;!' } ) ).toBe(
			'Hello world !'
		);
		expect( excerptOf( { content: 'x'.repeat( 100 ) } ) ).toHaveLength(
			60
		);
		expect(
			excerptOf( { content: { toHTMLString: () => '<em>rich</em>' } } )
		).toBe( 'rich' );
		expect( excerptOf( { url: 'https://example.com/a.png' } ) ).toBe(
			'https://example.com/a.png'
		);
	} );

	it( 'resolves by syncId first, then clientId', () => {
		const index = buildIdentityIndex( tree );
		expect(
			resolveBlockRef(
				{
					syncId: 's2',
					clientId: 'other',
					name: 'core/paragraph',
					path: [],
				},
				index
			)
		).toEqual( { kind: 'local', clientId: 'p2' } );
		expect(
			resolveBlockRef(
				{ clientId: 'p3', name: 'core/paragraph', path: [] },
				index
			)
		).toEqual( { kind: 'local', clientId: 'p3' } );
	} );

	it( 'turns unknown blocks into phantoms anchored to what exists', () => {
		const index = buildIdentityIndex( tree );
		expect(
			resolveBlockRef(
				{
					syncId: 'new',
					clientId: 'n1',
					name: 'core/paragraph',
					path: [ 1 ],
					after: 's1',
					parent: null,
				},
				index
			)
		).toEqual( {
			kind: 'phantom',
			anchorClientId: 'p1',
			placement: 'after',
		} );
		expect(
			resolveBlockRef(
				{
					syncId: 'new',
					clientId: 'n1',
					name: 'core/paragraph',
					path: [ 1, 0 ],
					after: null,
					parent: 'sg',
				},
				index
			)
		).toEqual( {
			kind: 'phantom',
			anchorClientId: 'g1',
			placement: 'inside',
		} );
		expect(
			resolveBlockRef(
				{
					syncId: 'new',
					clientId: 'n1',
					name: 'core/paragraph',
					path: [ 0 ],
					after: null,
					parent: null,
				},
				index
			)
		).toEqual( {
			kind: 'phantom',
			anchorClientId: null,
			placement: 'start',
		} );
	} );
} );
