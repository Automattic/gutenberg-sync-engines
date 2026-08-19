/**
 * TODO-20 (Tier 3, stage 1): the announce model. Accepted proposals
 * arrive as content-less ANNOUNCE rows; the session advances by hash for
 * its own unchanged round-trips, fetches canonical content only when it
 * will actually use it, and incorporates fetched snapshots for merged
 * own proposals (preserving locally-edited blocks).
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';
import * as Y from 'yjs';

import { hashDeRtcContent } from '../../../../src/engines/de-rtc/descriptor';
import { createDeRtcEngine } from '../../../../src/engines/de-rtc/engine';
import {
	DE_RTC_ANNOUNCE_TYPE,
	DE_RTC_FETCH_TYPE,
	DE_RTC_PROPOSAL_TYPE,
	DE_RTC_SNAPSHOT_TYPE,
} from '../../../../src/engines/de-rtc/session';
import { CRDT_RECORD_MAP_KEY } from '../../../../src/engines/yjs/constants';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

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

const BLOCK_A = { name: 'core/paragraph', attributes: { content: 'Alpha' } };
const BLOCK_B = { name: 'core/paragraph', attributes: { content: 'Beta' } };
const BLOCK_C = { name: 'core/paragraph', attributes: { content: 'Gamma' } };

const contentOf = ( ...blocks: unknown[] ) => JSON.stringify( blocks );

const snapshotRow = ( version: string, content: string ) => ( {
	type: DE_RTC_SNAPSHOT_TYPE,
	data: JSON.stringify( { version, content } ),
} );

const announceRow = (
	version: string,
	contentHash: string,
	authorClientId: number,
	proposalId: string
) => ( {
	type: DE_RTC_ANNOUNCE_TYPE,
	data: JSON.stringify( {
		version,
		baseVersion: 'v1',
		contentHash,
		authorClientId,
		proposalId,
	} ),
} );

describe( 'de-rtc announce model', () => {
	let syncConfig: jest.MockedObject< SyncConfig >;

	beforeEach( () => {
		syncConfig = makeSyncConfig();
	} );

	function makeSession() {
		const entity = createDeRtcEngine().createEntity( {
			syncConfig,
			objectType: 'postType/post',
			objectId: '1',
		} as any );
		const session = entity.createSession();
		const sent: Array< { type: string; data: string } > = [];
		session.onLocalUpdate( ( update: any ) => sent.push( update ) );
		return { entity, session, sent };
	}

	it( 'advances by hash on its own unchanged round-trip — no fetch, no content', () => {
		const { entity, session, sent } = makeSession();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		expect( sent ).toHaveLength( 1 );
		const proposal = JSON.parse( sent[ 0 ].data );
		const clientId = proposal.proposalId.split( '-' )[ 1 ];

		session.receiveUpdate(
			announceRow(
				'v2',
				hashDeRtcContent( proposal.proposedContent ),
				Number( clientId ),
				proposal.proposalId
			)
		);

		// No fetch went out; the next proposal bases on the announced v2.
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 0 );
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B, BLOCK_C ] } as any,
			'editor',
			{}
		);
		const next = JSON.parse( sent[ sent.length - 1 ].data );
		expect( next.baseVersion ).toBe( 'v2' );
	} );

	it( 'fetches once for a foreign announcement and applies the snapshot', () => {
		const { entity, session, sent } = makeSession();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		session.receiveUpdate(
			announceRow( 'v2', 'peer-hash', 999, 'p-peer' )
		);
		const fetches = sent.filter(
			( update ) => DE_RTC_FETCH_TYPE === update.type
		);
		expect( fetches ).toHaveLength( 1 );
		expect( JSON.parse( fetches[ 0 ].data ).haveVersion ).toBe( 'v1' );

		// A repeated announcement of the same version does not re-fetch.
		session.receiveUpdate(
			announceRow( 'v2', 'peer-hash', 999, 'p-peer' )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 1 );

		// The synthesized snapshot catches the session up.
		session.receiveUpdate(
			snapshotRow( 'v2', contentOf( BLOCK_A, BLOCK_C ) )
		);
		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_C ] );
	} );

	it( 'fetches eagerly (even mid-flight); repeats only on announced progress', () => {
		const { session, sent, entity } = makeSession();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);

		// A peer's announcement lands while ours is in flight: the fetch
		// goes out IMMEDIATELY — the snapshot defers if we're still busy,
		// so the content is ready at settle instead of a round trip away.
		session.receiveUpdate(
			announceRow( 'v2', 'peer-hash', 999, 'p-peer' )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 1 );

		// A REPEATED announcement of the same version does not re-fetch,
		// but a NEWER one does (the liveness backstop for a lost fetch —
		// the wire-inspected soak's starvation lesson).
		session.receiveUpdate(
			announceRow( 'v2', 'peer-hash', 999, 'p-peer' )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 1 );
		session.receiveUpdate(
			announceRow( 'v3', 'peer-hash-2', 999, 'p-peer-2' )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 2 );

		// The snapshot answers everything announced so far: quiet.
		session.receiveUpdate(
			snapshotRow( 'v3', contentOf( BLOCK_A, BLOCK_C ) )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 2 );

		// A newer announcement after fulfillment fetches again.
		session.receiveUpdate(
			announceRow( 'v4', 'peer-hash-3', 999, 'p-peer-3' )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 3 );
	} );

	it( 'a voided disposition forces a catch-up fetch (stale-base recovery)', () => {
		const { entity, session, sent } = makeSession();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		const proposal = JSON.parse( sent[ 0 ].data );

		// The server voids the proposal (base aged out of the snapshot
		// window): the session must fetch canonical content immediately,
		// not wait for an announcement it may never correlate.
		session.receiveDispositions?.( [
			{
				intentId: proposal.proposalId,
				status: 'voided',
				reason: 'unknown-base-version',
			} as any,
		] );
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 1 );
	} );

	it( 'incorporates the fetched snapshot for a merged own proposal, keeping newer local edits', () => {
		const { entity, session, sent } = makeSession();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		const proposal = JSON.parse( sent[ 0 ].data );
		const clientId = Number( proposal.proposalId.split( '-' )[ 1 ] );

		// The server merged a peer's appended block into our proposal:
		// announce hash ≠ ours.
		session.receiveUpdate(
			announceRow( 'v2', 'merged-differs', clientId, proposal.proposalId )
		);
		expect(
			sent.filter( ( update ) => DE_RTC_FETCH_TYPE === update.type )
		).toHaveLength( 1 );

		// The fetched snapshot: our BLOCK_B plus the peer's BLOCK_C.
		session.receiveUpdate(
			snapshotRow( 'v2', contentOf( BLOCK_B, BLOCK_C ) )
		);
		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_B, BLOCK_C ] );

		// And the next proposal bases on v2.
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B, BLOCK_C, BLOCK_A ] } as any,
			'editor',
			{}
		);
		const next = JSON.parse( sent[ sent.length - 1 ].data );
		expect( next.type ?? DE_RTC_PROPOSAL_TYPE ).toBe(
			DE_RTC_PROPOSAL_TYPE
		);
		expect( next.baseVersion ).toBe( 'v2' );
	} );
} );
