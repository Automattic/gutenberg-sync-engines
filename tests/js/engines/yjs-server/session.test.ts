/**
 * External dependencies
 */
import { describe, expect, it, jest } from '@jest/globals';
import * as Y from 'yjs';
import * as buffer from 'lib0/buffer';

/**
 * Internal dependencies
 */
import {
	createYjsServerSessionCodec,
	YJS_SERVER_ENGINE_SLUG,
	YJS_SERVER_SNAPSHOT_TYPE,
} from '../../../../src/engines/yjs-server/session';
import { SyncUpdateType } from '../../../../src/providers/http-polling/types';
import { base64ToUint8Array } from '../../../../src/providers/http-polling/utils';

/**
 * Encodes a document as the JSON snapshot row the PHP engine emits for
 * genesis and compaction checkpoints.
 * @param doc
 */
function snapshotRowFor( doc: Y.Doc ) {
	return {
		type: YJS_SERVER_SNAPSHOT_TYPE,
		data: JSON.stringify( {
			doc: buffer.toBase64( Y.encodeStateAsUpdateV2( doc ) ),
		} ),
	};
}

describe( 'createYjsServerSessionCodec', () => {
	it( 'exposes the engine identity and document clientID', () => {
		const doc = new Y.Doc();
		const session = createYjsServerSessionCodec( { doc } );

		expect( session.engineSlug ).toBe( YJS_SERVER_ENGINE_SLUG );
		expect( session.clientId ).toBe( doc.clientID );
	} );

	it( 'declares the syncWhileSolo transport capability', () => {
		const doc = new Y.Doc();
		const session = createYjsServerSessionCodec( { doc } );

		/*
		 * The server's document is the source of truth for every (re)joining
		 * client, so the room must track a solo session too. Without this
		 * capability the polling transport holds updates back until a second
		 * collaborator appears, and a solo type-save-reload session loses its
		 * content: the reload bootstraps from the room's stale snapshot and
		 * wipes the editor.
		 */
		expect( session.syncWhileSolo ).toBe( true );
	} );

	it( 'announces nothing on join with a fresh document (the server snapshot bootstraps it)', () => {
		const doc = new Y.Doc();
		const session = createYjsServerSessionCodec( { doc } );

		expect( session.getInitialUpdates() ).toEqual( [] );
	} );

	it( 'uploads full state on join when the document already holds content', () => {
		const doc = new Y.Doc();
		doc.getMap( 'document' ).set( 'title', 'Rejoin' );
		const session = createYjsServerSessionCodec( { doc } );

		const initial = session.getInitialUpdates();
		expect( initial ).toHaveLength( 1 );
		expect( initial[ 0 ].type ).toBe( SyncUpdateType.UPDATE );

		// The upload replays into an empty doc (what the server merges).
		const server = new Y.Doc();
		Y.applyUpdateV2( server, base64ToUint8Array( initial[ 0 ].data ) );
		expect( server.getMap( 'document' ).get( 'title' ) ).toBe( 'Rejoin' );
	} );

	it( 'applies a received snapshot row (JSON payload) into the document', () => {
		const serverDoc = new Y.Doc();
		serverDoc.getMap( 'document' ).set( 'title', 'Genesis' );
		serverDoc.getMap( 'state' ).set( 'version', 1 );

		const doc = new Y.Doc();
		const session = createYjsServerSessionCodec( { doc } );
		session.receiveUpdate( snapshotRowFor( serverDoc ) );

		expect( doc.getMap( 'document' ).get( 'title' ) ).toBe( 'Genesis' );
		expect( doc.getMap( 'state' ).get( 'version' ) ).toBe( 1 );
	} );

	it( 'applies received update rows and survives malformed snapshots', () => {
		const peer = new Y.Doc();
		const doc = new Y.Doc();
		const session = createYjsServerSessionCodec( { doc } );

		// A malformed snapshot must not throw or corrupt the doc.
		session.receiveUpdate( {
			type: YJS_SERVER_SNAPSHOT_TYPE,
			data: 'not-json',
		} );

		peer.getMap( 'document' ).set( 'title', 'From peer' );
		session.receiveUpdate( {
			type: SyncUpdateType.UPDATE,
			data: buffer.toBase64( Y.encodeStateAsUpdateV2( peer ) ),
		} );

		expect( doc.getMap( 'document' ).get( 'title' ) ).toBe( 'From peer' );
	} );

	it( 'reports local edits as update rows, but not remotely-applied ones', () => {
		const doc = new Y.Doc();
		const session = createYjsServerSessionCodec( { doc } );
		const listener = jest.fn();
		session.onLocalUpdate( listener );

		// A remote row applied through the session must not echo.
		const peer = new Y.Doc();
		peer.getMap( 'document' ).set( 'title', 'Remote' );
		session.receiveUpdate( {
			type: SyncUpdateType.UPDATE,
			data: buffer.toBase64( Y.encodeStateAsUpdateV2( peer ) ),
		} );
		expect( listener ).not.toHaveBeenCalled();

		// A local edit does.
		doc.getMap( 'document' ).set( 'title', 'Local' );
		expect( listener ).toHaveBeenCalledTimes( 1 );
		const [ update ] = listener.mock.calls[ 0 ] as [
			{ type: string; data: string },
		];
		expect( update.type ).toBe( SyncUpdateType.UPDATE );

		// The emitted update is INCREMENTAL: it depends on the prior state
		// (it overwrites the remote title), so the replica needs that state
		// first — exactly what a peer following the room's row order has.
		const replica = new Y.Doc();
		Y.applyUpdateV2( replica, Y.encodeStateAsUpdateV2( peer ) );
		Y.applyUpdateV2( replica, base64ToUint8Array( update.data ) );
		expect( replica.getMap( 'document' ).get( 'title' ) ).toBe( 'Local' );
	} );

	it( 'answers recovery (and the never-expected compaction) with idempotent full state', () => {
		const doc = new Y.Doc();
		doc.getMap( 'document' ).set( 'title', 'State' );
		const session = createYjsServerSessionCodec( { doc } );

		for ( const update of [
			session.createRecoveryUpdate!(),
			session.createCompactionUpdate!(),
		] ) {
			expect( update.type ).toBe( SyncUpdateType.UPDATE );
			const replica = new Y.Doc();
			Y.applyUpdateV2( replica, base64ToUint8Array( update.data ) );
			// Idempotent: applying twice changes nothing.
			Y.applyUpdateV2( replica, base64ToUint8Array( update.data ) );
			expect( replica.getMap( 'document' ).get( 'title' ) ).toBe(
				'State'
			);
		}
	} );
} );
