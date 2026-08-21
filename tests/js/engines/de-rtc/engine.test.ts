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
	DE_RTC_CONTENT_TYPE,
	DE_RTC_PROPOSAL_TYPE,
	DE_RTC_SNAPSHOT_TYPE,
} from '../../../../src/engines/de-rtc/session';
import { CRDT_RECORD_MAP_KEY } from '../../../../src/engines/yjs/constants';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

// The doc bridge serializes/parses through the editor's block library
// (wp.blocks at runtime). The codec treats content as an opaque string, so
// a JSON representation is a faithful stand-in.
jest.mock( '@wordpress/blocks', () => ( {
	parse: ( content: string ) => ( content ? JSON.parse( content ) : [] ),
	__unstableSerializeAndClean: ( blocks: unknown[] ) =>
		JSON.stringify( blocks ),
} ) );

/**
 * A minimal sync config: changes are applied as record-map keys, and editor
 * changes are the record map's JSON (the yjs-server test convention).
 */
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

function contentOf( ...blocks: unknown[] ): string {
	return JSON.stringify( blocks );
}

function snapshotRow( version: string, content: string ) {
	return {
		type: DE_RTC_SNAPSHOT_TYPE,
		data: JSON.stringify( { version, content } ),
	};
}

function contentRow(
	version: string,
	content: string,
	authorClientId: number,
	proposalId = 'p-x'
) {
	return {
		type: DE_RTC_CONTENT_TYPE,
		data: JSON.stringify( {
			version,
			baseVersion: 'v1',
			content,
			authorClientId,
			proposalId,
		} ),
	};
}

describe( 'createDeRtcEngine', () => {
	let syncConfig: jest.MockedObject< SyncConfig >;

	beforeEach( () => {
		syncConfig = makeSyncConfig();
	} );

	function makeEntity() {
		return createDeRtcEngine().createEntity( {
			syncConfig,
			// A type WITHOUT a commit route: these suites pin the transport
			// proposal lane (collections/unsupported types still use it).
			objectType: 'postType/book',
			objectId: '1',
		} as any );
	}

	it( 'announces the identity the server negotiates against', () => {
		const engine = createDeRtcEngine();
		expect( engine.slug ).toBe( 'de-rtc' );
		expect( engine.protocolVersion ).toBe( 2 );

		const session = makeEntity().createSession();
		expect( session.engineSlug ).toBe( 'de-rtc' );
		expect( session.engineProtocol ).toBe( 2 );
	} );

	it( 'does NOT seed the document on hydrate; genesis bootstraps it', () => {
		const entity = makeEntity();
		const persist = jest.fn();
		entity.hydrate( { blocks: [ BLOCK_A ] } as any, persist );
		expect( syncConfig.applyChangesToCRDTDoc ).not.toHaveBeenCalled();

		// Pre-bootstrap, an empty doc must not reach the editor.
		expect(
			entity.getEditorChanges( { blocks: [ BLOCK_A ] } as any )
		).toEqual( {} );

		const session = entity.createSession();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A ] );
	} );

	it( 'buffers pre-bootstrap local changes and replays them after genesis', () => {
		const entity = makeEntity();
		const session = entity.createSession();

		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		expect( syncConfig.applyChangesToCRDTDoc ).not.toHaveBeenCalled();

		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		// Genesis application + replayed buffered change.
		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_B ] );
	} );

	it( 'coalesces local edits into ONE in-flight proposal against the last applied version', () => {
		const entity = makeEntity();
		const session = entity.createSession();
		const sent: any[] = [];
		session.onLocalUpdate( ( update ) => sent.push( update ) );

		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );

		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B, BLOCK_C ] } as any,
			'editor',
			{}
		);

		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].type ).toBe( DE_RTC_PROPOSAL_TYPE );
		const payload = JSON.parse( sent[ 0 ].data );
		expect( payload.baseVersion ).toBe( 'v1' );
		// The proposal carries the tamper-evidence descriptor,
		// built from the base version's canonical content. (This suite
		// mocks @wordpress/blocks, so content is not real block grammar
		// and the builder emits the hash-pinned unsupported fallback.)
		expect( payload.clientUpdate?.format ).toBe(
			'native-automerge-blocks-v1'
		);
		expect( typeof payload.clientUpdate?.baseContentHash ).toBe( 'string' );
		expect( typeof payload.proposalId ).toBe( 'string' );

		// The disposition settles the slot; the coalesced newer edits go out
		// as the next proposal, carrying the doc's CURRENT content.
		session.receiveDispositions?.( [
			{ intentId: payload.proposalId, status: 'applied' },
		] );
		expect( sent ).toHaveLength( 2 );
		const second = JSON.parse( sent[ 1 ].data );
		expect( JSON.parse( second.proposedContent ) ).toEqual( [
			BLOCK_B,
			BLOCK_C,
		] );
	} );

	it( 'applies a remote content row when clean and reports a remote change', () => {
		const entity = makeEntity();
		const session = entity.createSession();
		const onRemoteChange = jest.fn();
		entity.observe( { onRemoteChange, onPeerSave: jest.fn() } );

		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
		onRemoteChange.mockClear();

		session.receiveUpdate(
			contentRow( 'v2', contentOf( BLOCK_A, BLOCK_C ), 999 )
		);

		expect( onRemoteChange ).toHaveBeenCalled();
		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_C ] );

		// The next proposal is based on the newly applied version.
		const sent: any[] = [];
		session.onLocalUpdate( ( update ) => sent.push( update ) );
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		expect( JSON.parse( sent[ 0 ].data ).baseVersion ).toBe( 'v2' );
	} );

	it( 'defers a remote content row while a proposal is in flight, then adopts it', () => {
		const entity = makeEntity();
		const session = entity.createSession();
		const sent: any[] = [];
		session.onLocalUpdate( ( update ) => sent.push( update ) );

		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		expect( sent ).toHaveLength( 1 );

		// A peer's row arrives while ours is in flight: the local doc must
		// NOT lose the un-acked local state.
		session.receiveUpdate(
			contentRow( 'v2', contentOf( BLOCK_A, BLOCK_C ), 999 )
		);
		let changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_B ] );

		// Once the proposal settles (clean), the deferred canonical applies.
		session.receiveDispositions?.( [
			{
				intentId: JSON.parse( sent[ 0 ].data ).proposalId,
				status: 'escalated',
				reason: 'manual-conflict-required',
			},
		] );
		changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_C ] );
	} );

	it( 'clears the in-flight slot when its own accepted row arrives', () => {
		const entity = makeEntity();
		const session = entity.createSession();
		const sent: any[] = [];
		session.onLocalUpdate( ( update ) => sent.push( update ) );

		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B ] } as any,
			'editor',
			{}
		);
		expect( sent ).toHaveLength( 1 );

		// The server merged and broadcast OUR proposal (echoing its id).
		session.receiveUpdate(
			contentRow(
				'v2',
				contentOf( BLOCK_B ),
				session.clientId,
				JSON.parse( sent[ 0 ].data ).proposalId
			)
		);

		// The slot is free: a new local edit proposes against v2.
		entity.applyLocalChanges(
			{ blocks: [ BLOCK_B, BLOCK_C ] } as any,
			'editor',
			{}
		);
		expect( sent ).toHaveLength( 2 );
		expect( JSON.parse( sent[ 1 ].data ).baseVersion ).toBe( 'v2' );
	} );

	it( 'creates an inert collection: rows ignored, nothing proposed', () => {
		const collection = createDeRtcEngine().createCollection( {
			syncConfig,
			objectType: 'taxonomy/category',
		} as any );
		const session = collection.createSession();
		const sent: any[] = [];
		session.onLocalUpdate( ( update: any ) => sent.push( update ) );

		expect( () =>
			session.receiveUpdate( snapshotRow( 'v1', '' ) )
		).not.toThrow();
		expect( session.getInitialUpdates() ).toEqual( [] );
		expect( sent ).toHaveLength( 0 );
		expect( syncConfig.applyChangesToCRDTDoc ).not.toHaveBeenCalled();
	} );
} );
