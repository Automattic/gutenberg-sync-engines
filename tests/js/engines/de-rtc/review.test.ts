/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';
import * as Y from 'yjs';

/**
 * Internal dependencies
 */
import { createDeRtcEngine } from '../../../../src/engines/de-rtc/engine';
import {
	DE_RTC_PROPOSAL_PARKED_TYPE,
	DE_RTC_PROPOSAL_TYPE,
	DE_RTC_RESOLVED_TYPE,
	DE_RTC_SNAPSHOT_TYPE,
} from '../../../../src/engines/de-rtc/session';
import { CRDT_RECORD_MAP_KEY } from '../../../../src/engines/yjs/constants';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

// Same opaque-JSON block stand-in as the engine tests.
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

const parkedRow = (
	proposalId: string,
	reason: string,
	authorClientId: number,
	changedBlocks: Array< { index: number; html: string } >,
	excerpt = 'lost words'
) => ( {
	type: DE_RTC_PROPOSAL_PARKED_TYPE,
	data: JSON.stringify( {
		proposalId,
		reason,
		authorClientId,
		author: 7,
		at: 1000,
		baseVersion: 'v1',
		changedBlocks,
		excerpt,
	} ),
} );

describe( 'de-rtc review lane (client)', () => {
	let syncConfig: jest.MockedObject< SyncConfig >;
	let engine: ReturnType< typeof createDeRtcEngine >;

	beforeEach( () => {
		syncConfig = makeSyncConfig();
		engine = createDeRtcEngine();
	} );

	function makeEntity() {
		const entity = engine.createEntity( {
			syncConfig,
			objectType: 'postType/post',
			objectId: '1',
		} as any );
		const session = entity.createSession();
		const sent: any[] = [];
		session.onLocalUpdate( ( update: any ) => sent.push( update ) );
		return { entity, session, sent };
	}

	it( 'presents a parked row as a review item with normalized reasons', () => {
		const { session } = makeEntity();
		const changed = jest.fn();
		engine.review.subscribe( 'postType/post', '1', changed );

		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [
				{ index: 0, html: contentOf( BLOCK_C ) },
			] )
		);

		expect( changed ).toHaveBeenCalled();
		const items = engine.review.getOpenItems( 'postType/post', '1' );
		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ] ).toMatchObject( {
			id: 'p-9-1',
			unitId: 'p-9-1',
			isLocal: false,
			actorId: 'u7c9',
			reason: 'frame-conflict',
			summary: 'lost words',
		} );

		// The kses reason maps to the panel's capability-gated vocabulary.
		session.receiveUpdate(
			parkedRow( 'p-9-2', 'requires-unfiltered-html', 9, [] )
		);
		const updated = engine.review.getOpenItems( 'postType/post', '1' );
		expect( updated.find( ( item ) => 'p-9-2' === item.id )?.reason ).toBe(
			'requires-approval'
		);
	} );

	it( "marks the escalating client's own parked proposal as local", () => {
		const { session } = makeEntity();
		session.receiveUpdate(
			parkedRow(
				'p-own-1',
				'manual-conflict-required',
				session.clientId,
				[]
			)
		);
		expect(
			engine.review.getOpenItems( 'postType/post', '1' )[ 0 ].isLocal
		).toBe( true );
	} );

	it( 'a redelivered parked row never duplicates, and a resolved row closes it', () => {
		const { session } = makeEntity();
		const row = parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] );
		session.receiveUpdate( row );
		session.receiveUpdate( row );
		expect(
			engine.review.getOpenItems( 'postType/post', '1' )
		).toHaveLength( 1 );

		session.receiveUpdate( {
			type: DE_RTC_RESOLVED_TYPE,
			data: JSON.stringify( {
				proposalId: 'p-9-1',
				resolution: 'dismissed',
			} ),
		} );
		expect(
			engine.review.getOpenItems( 'postType/post', '1' )
		).toHaveLength( 0 );

		// A parked row replayed AFTER its resolution stays closed (bootstrap
		// replays deliver both rows).
		session.receiveUpdate( row );
		expect(
			engine.review.getOpenItems( 'postType/post', '1' )
		).toHaveLength( 0 );
	} );

	it( 'dismiss emits a resolved row through the session wire', () => {
		const { session, sent } = makeEntity();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] )
		);

		engine.review.resolveProposal(
			'postType/post',
			'1',
			'p-9-1',
			'dismissed'
		);

		expect(
			engine.review.getOpenItems( 'postType/post', '1' )
		).toHaveLength( 0 );
		const resolvedRows = sent.filter(
			( update ) => DE_RTC_RESOLVED_TYPE === update.type
		);
		expect( resolvedRows ).toHaveLength( 1 );
		expect( JSON.parse( resolvedRows[ 0 ].data ) ).toEqual( {
			proposalId: 'p-9-1',
			resolution: 'dismissed',
		} );
	} );

	it( 'restore overlays the parked blocks, re-proposes them, reaches the editor, and resolves', () => {
		const { entity, session, sent } = makeEntity();
		const onRemoteChange = jest.fn();
		entity.observe( { onRemoteChange, onPeerSave: jest.fn() } );

		session.receiveUpdate(
			snapshotRow( 'v1', contentOf( BLOCK_A, BLOCK_B ) )
		);
		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [
				{ index: 1, html: contentOf( BLOCK_C ) },
			] )
		);
		onRemoteChange.mockClear();

		engine.review.restoreProposal( 'postType/post', '1', 'p-9-1' );

		// The overlay replaced the block at the recorded index…
		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_C ] );
		// …reached the editor like a remote change…
		expect( onRemoteChange ).toHaveBeenCalled();
		// …and went out as an ordinary proposal under the restorer, followed
		// by the restored resolution.
		const proposals = sent.filter(
			( update ) => DE_RTC_PROPOSAL_TYPE === update.type
		);
		expect( proposals ).toHaveLength( 1 );
		expect(
			JSON.parse( JSON.parse( proposals[ 0 ].data ).proposedContent )
		).toEqual( [ BLOCK_A, BLOCK_C ] );
		const resolvedRows = sent.filter(
			( update ) => DE_RTC_RESOLVED_TYPE === update.type
		);
		expect( JSON.parse( resolvedRows[ 0 ].data ) ).toEqual( {
			proposalId: 'p-9-1',
			resolution: 'restored',
		} );
		expect(
			engine.review.getOpenItems( 'postType/post', '1' )
		).toHaveLength( 0 );
	} );

	it( 'restore appends when the recorded index no longer matches by name', () => {
		const { entity, session } = makeEntity();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [
				{
					index: 4,
					html: contentOf( BLOCK_C ),
				},
			] )
		);

		engine.review.restoreProposal( 'postType/post', '1', 'p-9-1' );

		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_C ] );
	} );

	it( 'a subscription made BEFORE the entity exists still fires (the decorator subscribes during load)', () => {
		const changed = jest.fn();
		engine.review.subscribe( 'postType/post', '1', changed );

		// The entity is created only after the subscription — the real
		// ordering: decorateManagerWithReview captures handlers and
		// subscribes before delegating to the inner manager's load().
		const { session } = makeEntity();
		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] )
		);

		expect( changed ).toHaveBeenCalled();
		expect(
			engine.review.getOpenItems( 'postType/post', '1' )
		).toHaveLength( 1 );
	} );

	it( 'an unknown entity yields an empty review surface', () => {
		expect( engine.review.getOpenItems( 'postType/post', '999' ) ).toEqual(
			[]
		);
		expect( () =>
			engine.review.resolveProposal(
				'postType/post',
				'999',
				'p-x',
				'dismissed'
			)
		).not.toThrow();
	} );
} );
