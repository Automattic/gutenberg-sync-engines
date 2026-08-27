/**
 * Per-block base honesty: the doc bridge records the TRUE
 * base of blocks kept through colliding incorporations and clears the
 * record once the collision resolves — retiring the silent block-level
 * last-writer-wins.
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';
import * as Y from 'yjs';

import { createDeRtcDocBridge } from '../../../../src/engines/de-rtc/doc-bridge';
import { CRDT_RECORD_MAP_KEY } from '../../../../src/engines/yjs/constants';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

// Same stand-in as engine.test.ts: content is opaque JSON.
jest.mock( '@wordpress/blocks', () => ( {
	parse: ( content: string ) => ( content ? JSON.parse( content ) : [] ),
	__unstableSerializeAndClean: ( blocks: unknown[] ) =>
		JSON.stringify( blocks ),
} ) );

function makeSyncConfig(): jest.MockedObject< SyncConfig > {
	return {
		applyChangesToCRDTDoc: jest.fn( ( doc: Y.Doc, changes: any ) => {
			const map = doc.getMap( CRDT_RECORD_MAP_KEY );
			Object.entries( changes ).forEach( ( [ key, value ] ) => {
				map.set( key, value );
			} );
		} ),
		getChangesFromCRDTDoc: jest.fn( ( doc: Y.Doc ) =>
			doc.getMap( CRDT_RECORD_MAP_KEY ).toJSON()
		),
	} as unknown as jest.MockedObject< SyncConfig >;
}

const A = { name: 'core/paragraph', attributes: { content: 'Alpha' } };
const A_LOCAL = {
	name: 'core/paragraph',
	attributes: { content: 'Alpha local' },
};
const A_LOCAL_NEWER = {
	name: 'core/paragraph',
	attributes: { content: 'Alpha local newer' },
};
const A_PEER = {
	name: 'core/paragraph',
	attributes: { content: 'Alpha peer' },
};
const B = { name: 'core/paragraph', attributes: { content: 'Beta' } };
const B_PEER = { name: 'core/paragraph', attributes: { content: 'Beta peer' } };

const contentOf = ( ...blocks: unknown[] ) => JSON.stringify( blocks );

describe( 'de-rtc doc bridge per-block base honesty', () => {
	let doc: Y.Doc;
	let bridge: ReturnType< typeof createDeRtcDocBridge >;

	beforeEach( () => {
		doc = new Y.Doc();
		bridge = createDeRtcDocBridge( doc, makeSyncConfig() );
	} );

	function setLocalBlocks( ...blocks: unknown[] ) {
		doc.getMap( CRDT_RECORD_MAP_KEY ).set( 'blocks', blocks );
	}

	it( 'records the prior version as the base of a kept, collided block', () => {
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		// The editor typed into Alpha after proposing [A_LOCAL, B].
		setLocalBlocks( A_LOCAL_NEWER, B );

		// A peer's canonical (v2) also changed Alpha: true collision.
		const incorporated = bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);

		expect( incorporated ).toBe( true );
		expect( bridge.lastVersion() ).toBe( 'v2' );
		expect( bridge.blockBaseVersions() ).toEqual( { 0: 'v1' } );
	} );

	it( 'does not record a base for kept blocks the canonical left alone', () => {
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );

		// Canonical v2 changed only Beta; our kept Alpha is a clean
		// sole-writer change — no collision, no record.
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_LOCAL, B_PEER ),
			contentOf( A_LOCAL, B )
		);

		expect( bridge.blockBaseVersions() ).toEqual( {} );
	} );

	it( 'keeps the OLDEST base across repeated collisions', () => {
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);
		// A second colliding canonical arrives while the block stays hot.
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v3',
			contentOf( A_PEER, B_PEER ),
			contentOf( A_LOCAL, B )
		);

		expect( bridge.blockBaseVersions() ).toEqual( { 0: 'v1' } );
	} );

	it( 'clears the record when the block adopts canonical', () => {
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);
		expect( bridge.blockBaseVersions() ).toEqual( { 0: 'v1' } );

		// The conflicted block settles (our salvaged/parked proposal came
		// back; the doc holds exactly what we last proposed), so the next
		// incorporation ADOPTS canonical for it.
		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v3',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL_NEWER, B )
		);

		expect( bridge.blockBaseVersions() ).toEqual( {} );
	} );

	it( 'clears every record on wholesale adoption and on version-only advance', () => {
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);
		expect( bridge.blockBaseVersions() ).toEqual( { 0: 'v1' } );

		bridge.applyCanonical( 'v3', contentOf( A_PEER, B ) );
		expect( bridge.blockBaseVersions() ).toEqual( {} );

		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v4',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);
		expect( bridge.blockBaseVersions() ).toEqual( { 0: 'v3' } );

		bridge.advanceVersion( 'v5' );
		expect( bridge.blockBaseVersions() ).toEqual( {} );
	} );
} );

describe( 'de-rtc doc bridge contest base recording (merge view)', () => {
	let doc: Y.Doc;
	let bridge: ReturnType< typeof createDeRtcDocBridge >;

	beforeEach( () => {
		doc = new Y.Doc();
		bridge = createDeRtcDocBridge( doc, makeSyncConfig() );
	} );

	function setLocalBlocks( ...blocks: unknown[] ) {
		doc.getMap( CRDT_RECORD_MAP_KEY ).set( 'blocks', blocks );
	}

	it( 'a contest event carries the base block from the prior canonical', () => {
		const events: Array< Record< string, unknown > > = [];
		bridge.onContested( ( event ) => events.push( event ) );
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );

		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);

		expect( events ).toHaveLength( 1 );
		expect( events[ 0 ] ).toMatchObject( {
			index: 0,
			version: 'v2',
			baseHtml: JSON.stringify( [ A ] ),
		} );
	} );

	it( 'refreshes keep the FIRST recorded base (oldest base wins, like the version label)', () => {
		const events: Array< Record< string, unknown > > = [];
		bridge.onContested( ( event ) => events.push( event ) );
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v3',
			contentOf( B_PEER, B ),
			contentOf( A_LOCAL, B )
		);

		expect( events ).toHaveLength( 2 );
		// The refreshed event still carries v1's block as the base.
		expect( events[ 1 ] ).toMatchObject( {
			index: 0,
			version: 'v3',
			baseHtml: JSON.stringify( [ A ] ),
		} );
	} );

	it( 'a version-only advance without content leaves later contests base-less', () => {
		const events: Array< Record< string, unknown > > = [];
		bridge.onContested( ( event ) => events.push( event ) );
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		// The own round-trip advance, without its content string.
		bridge.advanceVersion( 'v2' );
		setLocalBlocks( A_LOCAL_NEWER, B );

		bridge.incorporateCanonicalPreservingLocalEdits(
			'v3',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);

		expect( events ).toHaveLength( 1 );
		expect( events[ 0 ].baseHtml ).toBeUndefined();
	} );

	it( 'an advance WITH content records the base for later contests', () => {
		const events: Array< Record< string, unknown > > = [];
		bridge.onContested( ( event ) => events.push( event ) );
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		bridge.advanceVersion( 'v2', contentOf( A_LOCAL, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );

		bridge.incorporateCanonicalPreservingLocalEdits(
			'v3',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);

		expect( events[ 0 ] ).toMatchObject( {
			baseHtml: JSON.stringify( [ A_LOCAL ] ),
		} );
	} );

	it( 'resolveContestedBlockWithMerged writes the merged block, clears the base, and resolves', () => {
		const resolved: number[] = [];
		bridge.onContestResolved( ( index ) => resolved.push( index ) );
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		setLocalBlocks( A_LOCAL_NEWER, B );
		bridge.incorporateCanonicalPreservingLocalEdits(
			'v2',
			contentOf( A_PEER, B ),
			contentOf( A_LOCAL, B )
		);
		expect( bridge.blockBaseVersions() ).toEqual( { 0: 'v1' } );

		const A_MERGED = {
			name: 'core/paragraph',
			attributes: { content: 'Alpha merged by hand' },
		};
		const applied = bridge.resolveContestedBlockWithMerged(
			0,
			contentOf( A_MERGED )
		);

		expect( applied ).toBe( true );
		expect( resolved ).toEqual( [ 0 ] );
		// The merged text was written against the contest's canonical, so
		// the recorded true base clears.
		expect( bridge.blockBaseVersions() ).toEqual( {} );
		expect(
			(
				doc.getMap( CRDT_RECORD_MAP_KEY ).get( 'blocks' ) as unknown[]
			 )[ 0 ]
		).toEqual( A_MERGED );
	} );

	it( 'resolveContestedBlockWithMerged is a no-op without a contest', () => {
		bridge.applyCanonical( 'v1', contentOf( A, B ) );
		expect(
			bridge.resolveContestedBlockWithMerged( 0, contentOf( A_PEER ) )
		).toBe( false );
	} );
} );
