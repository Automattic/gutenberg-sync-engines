/**
 * External dependencies
 */
import { describe, expect, it, jest, beforeEach } from '@jest/globals';
import * as Y from 'yjs';
import * as buffer from 'lib0/buffer';

/**
 * Internal dependencies
 */
import { createYjsServerEngine } from '../../../../src/engines/yjs-server/engine';
import { YJS_SERVER_SNAPSHOT_TYPE } from '../../../../src/engines/yjs-server/session';
import {
	CRDT_RECORD_MAP_KEY,
	CRDT_STATE_MAP_KEY,
	CRDT_STATE_MAP_VERSION_KEY as VERSION_KEY,
} from '../../../../src/engines/yjs-relay/constants';
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.sync.
import type { SyncConfig } from '@wordpress/sync';

/**
 * A minimal sync config: changes are applied as record-map keys, and editor
 * changes are the record map's JSON.
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

/**
 * The genesis snapshot row a yjs-server room emits: server-authored state
 * with the schema version stamped.
 * @param title
 */
function genesisRow( title = 'Server genesis' ) {
	const serverDoc = new Y.Doc();
	serverDoc.getMap( CRDT_RECORD_MAP_KEY ).set( 'title', title );
	serverDoc.getMap( CRDT_STATE_MAP_KEY ).set( VERSION_KEY, 1 );
	return {
		type: YJS_SERVER_SNAPSHOT_TYPE,
		data: JSON.stringify( {
			doc: buffer.toBase64( Y.encodeStateAsUpdateV2( serverDoc ) ),
		} ),
	};
}

describe( 'createYjsServerEngine › createEntity', () => {
	let syncConfig: jest.MockedObject< SyncConfig >;

	beforeEach( () => {
		syncConfig = makeSyncConfig();
	} );

	function makeEntity() {
		return createYjsServerEngine().createEntity( {
			syncConfig,
			objectType: 'postType/post',
			objectId: '1',
		} as any );
	}

	it( 'does NOT seed the document from the loaded record on hydrate', () => {
		const entity = makeEntity();
		const persist = jest.fn();

		entity.hydrate( { title: 'Loaded from REST' } as any, persist );

		// No seeding, no persistence request: the server owns genesis.
		expect( syncConfig.applyChangesToCRDTDoc ).not.toHaveBeenCalled();
		expect( persist ).not.toHaveBeenCalled();
	} );

	it( 'reports no editor changes before the server snapshot arrives', () => {
		const entity = makeEntity();
		entity.hydrate( {} as any, jest.fn() );

		// An empty pre-bootstrap doc must never be dispatched into the
		// editor (it would read as a mass deletion).
		expect( entity.getEditorChanges( { title: 'Loaded' } as any ) ).toEqual(
			{}
		);
		expect( syncConfig.getChangesFromCRDTDoc ).not.toHaveBeenCalled();
	} );

	it( 'buffers pre-bootstrap local changes and merges them once the snapshot lands', () => {
		const entity = makeEntity();
		entity.hydrate( {} as any, jest.fn() );
		const session = entity.createSession();

		// Typed before the first poll answered:
		entity.applyLocalChanges( { subtitle: 'Early edit' } as any, 'editor', {
			isSave: false,
		} as any );
		expect( syncConfig.applyChangesToCRDTDoc ).not.toHaveBeenCalled();

		// The genesis snapshot arrives.
		session.receiveUpdate( genesisRow() );

		// The buffered edit merged AFTER the server state.
		expect( syncConfig.applyChangesToCRDTDoc ).toHaveBeenCalledTimes( 1 );
		const changes = entity.getEditorChanges( {} as any ) as any;
		expect( changes.title ).toBe( 'Server genesis' );
		expect( changes.subtitle ).toBe( 'Early edit' );
	} );

	it( 'applies local changes immediately once bootstrapped', () => {
		const entity = makeEntity();
		entity.hydrate( {} as any, jest.fn() );
		entity.createSession().receiveUpdate( genesisRow() );

		entity.applyLocalChanges( { subtitle: 'Live edit' } as any, 'editor', {
			isSave: false,
		} as any );

		expect( syncConfig.applyChangesToCRDTDoc ).toHaveBeenCalledTimes( 1 );
		expect( ( entity.getEditorChanges( {} as any ) as any ).subtitle ).toBe(
			'Live edit'
		);
	} );

	it( 'surfaces remote changes through observers after bootstrap', () => {
		const entity = makeEntity();
		entity.hydrate( {} as any, jest.fn() );
		const session = entity.createSession();

		const onRemoteChange = jest.fn();
		entity.observe( {
			onRemoteChange,
			onPeerSave: jest.fn(),
		} as any );

		session.receiveUpdate( genesisRow() );

		expect( onRemoteChange ).toHaveBeenCalled();
	} );
} );
