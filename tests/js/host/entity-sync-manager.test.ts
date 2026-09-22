/**
 * The host bridge: the seam manager this plugin registers with core-data,
 * built over a fake engine manager.
 */

/**
 * External dependencies
 */
import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

// The editor package drags most of the block editor in; the host modules
// only need its store handle.
jest.mock( '@wordpress/editor', () => ( {
	store: { name: 'core/editor' },
} ) );

/**
 * Internal dependencies
 */
import {
	createHostEntitySyncManager,
	getServerMutatedFields,
} from '../../../src/host/entity-sync-manager';
import { store as hostStore } from '../../../src/host/store';
import { resetEntitySyncConfigsForTesting } from '../../../src/host/sync-config';
import { ConnectionErrorCode } from '../../../src/sync';
import type { RecordHandlers, SyncManager } from '../../../src/sync';

function createFakeInner() {
	return {
		load: jest.fn( async () => {} ),
		loadCollection: jest.fn( async () => {} ),
		update: jest.fn(),
		unload: jest.fn(),
		unloadAll: jest.fn(),
		getAwareness: jest.fn( () => undefined ),
		undoManager: undefined,
	} as unknown as SyncManager & {
		load: jest.Mock;
		update: jest.Mock;
		unload: jest.Mock;
		unloadAll: jest.Mock;
	};
}

const seamHandlers = () => ( {
	editRecord: jest.fn(),
	getEditedRecord: jest.fn( async () => ( {} ) ),
	refetchRecord: jest.fn( async () => {} ),
	onUndoStackChange: jest.fn(),
} );

describe( 'the host entity sync manager', () => {
	let inner: ReturnType< typeof createFakeInner >;

	beforeEach( () => {
		window._gutenbergSyncEnginesSync = {
			engine: 'test',
			engineProtocol: 1,
			transports: [ 'http-polling' ],
			transportProtocol: 1,
			disabledPostTypes: [ 'attachment' ],
			screen: {
				postType: 'post',
				postId: 7,
				supported: true,
				reason: '',
				lockedBy: null,
			},
		};
		resetEntitySyncConfigsForTesting();
		dispatch( hostStore ).setCollaborationSupported( true );
		inner = createFakeInner();
	} );

	afterEach( () => {
		delete window._gutenbergSyncEnginesSync;
	} );

	it( 'syncs post types, taxonomies and the comment collection only', () => {
		const manager = createHostEntitySyncManager( inner );

		expect( manager.shouldSync?.( 'postType', 'post', 7 ) ).toBe( true );
		expect( manager.shouldSync?.( 'taxonomy', 'category', 1 ) ).toBe(
			true
		);
		expect( manager.shouldSync?.( 'root', 'comment', 1 ) ).toBe( true );
		expect( manager.shouldSync?.( 'root', 'site', 1 ) ).toBe( false );
		// The server's disabled post types.
		expect( manager.shouldSync?.( 'postType', 'attachment', 7 ) ).toBe(
			false
		);
	} );

	it( 'declines everything once the page stopped supporting collaboration', () => {
		const manager = createHostEntitySyncManager( inner );
		dispatch( hostStore ).setCollaborationSupported( false );

		expect( manager.shouldSync?.( 'postType', 'post', 7 ) ).toBe( false );
	} );

	it( 'loads a record with the engine handlers added', async () => {
		const manager = createHostEntitySyncManager( inner );
		const handlers = seamHandlers();

		await manager.load( 'postType', 'post', 7, { id: 7 }, handlers );

		expect( inner.load ).toHaveBeenCalledTimes( 1 );
		const [ syncConfig, objectType, objectId, record, engineHandlers ] =
			inner.load.mock.calls[ 0 ] as [
				unknown,
				string,
				unknown,
				unknown,
				RecordHandlers,
			];
		expect( objectType ).toBe( 'postType/post' );
		expect( objectId ).toBe( 7 );
		expect( record ).toEqual( { id: 7 } );
		expect( syncConfig ).toEqual(
			expect.objectContaining( {
				applyChangesToCRDTDoc: expect.any( Function ),
				createAwareness: expect.any( Function ),
				richTextFields: expect.any( Function ),
			} )
		);
		expect( engineHandlers.editRecord ).toBe( handlers.editRecord );
		expect( engineHandlers.onUndoStackChange ).toBe(
			handlers.onUndoStackChange
		);
		expect( engineHandlers.onStatusChange ).toEqual(
			expect.any( Function )
		);
		expect( engineHandlers.onProposalsChange ).toEqual(
			expect.any( Function )
		);
	} );

	it( 'mirrors connection status into the host store and stands down on oversized documents', async () => {
		const manager = createHostEntitySyncManager( inner );
		await manager.load( 'postType', 'post', 7, { id: 7 }, seamHandlers() );
		const engineHandlers = inner.load.mock
			.calls[ 0 ][ 4 ] as RecordHandlers;

		engineHandlers.onStatusChange( { status: 'connected' } );
		expect(
			select( hostStore ).getEntitySyncConnectionStatus(
				'postType',
				'post',
				7
			)
		).toEqual( { status: 'connected' } );
		expect( select( hostStore ).isCollaborationSupported() ).toBe( true );

		engineHandlers.onStatusChange( {
			status: 'disconnected',
			error: {
				code: ConnectionErrorCode.DOCUMENT_SIZE_LIMIT_EXCEEDED,
			} as never,
		} );
		expect( select( hostStore ).isCollaborationSupported() ).toBe( false );

		engineHandlers.onStatusChange( null );
		expect(
			select( hostStore ).getEntitySyncConnectionStatus(
				'postType',
				'post',
				7
			)
		).toBeUndefined();
	} );

	it( 'keeps the review list in the host store and raises notices for new conflicts', async () => {
		const manager = createHostEntitySyncManager( inner );
		await manager.load( 'postType', 'post', 7, { id: 7 }, seamHandlers() );
		const engineHandlers = inner.load.mock
			.calls[ 0 ][ 4 ] as RecordHandlers;
		const item = {
			id: 'p1',
			unitId: 'p1',
			isLocal: true,
			actorId: 'a',
			reason: 'frame-conflict',
			intentType: 'insert_text',
			summary: 'lost',
		};

		engineHandlers.onProposalsChange?.( [ item ] );
		engineHandlers.onEscalation?.( {
			reason: item.reason,
			isLocal: true,
			proposalId: item.id,
			summary: item.summary,
		} );

		expect(
			select( hostStore ).getSyncReviewItems( 'postType', 'post', 7 )
		).toEqual( [ item ] );
		const notices = select( noticesStore ).getNotices();
		expect(
			notices.some( ( notice ) => notice.id.endsWith( '-p1' ) )
		).toBe( true );

		// Resolved elsewhere: the list empties and the notice goes away.
		engineHandlers.onProposalsChange?.( [] );
		expect(
			select( hostStore ).getSyncReviewItems( 'postType', 'post', 7 )
		).toEqual( [] );
		expect(
			select( noticesStore )
				.getNotices()
				.some( ( notice ) => notice.id.endsWith( '-p1' ) )
		).toBe( false );
	} );

	it( 'maps the edit intent to origins and undo levels', () => {
		const manager = createHostEntitySyncManager( inner );

		manager.update(
			'postType',
			'post',
			7,
			{ title: 'a' },
			{
				isCached: false,
				undoIgnore: false,
			}
		);
		manager.update(
			'postType',
			'post',
			7,
			{ title: 'b' },
			{
				isCached: true,
				undoIgnore: false,
			}
		);
		manager.update(
			'postType',
			'post',
			7,
			{ title: 'c' },
			{
				isCached: false,
				undoIgnore: true,
			}
		);
		// Not a synced entity.
		manager.update(
			'root',
			'site',
			1,
			{ title: 'd' },
			{
				isCached: false,
				undoIgnore: false,
			}
		);

		expect( inner.update.mock.calls ).toEqual( [
			[
				'postType/post',
				7,
				{ title: 'a' },
				'gutenberg',
				{ isNewUndoLevel: true },
			],
			[
				'postType/post',
				7,
				{ title: 'b' },
				'gutenberg',
				{ isNewUndoLevel: false },
			],
			[
				'postType/post',
				7,
				{ title: 'c' },
				'gutenberg-undo-ignored',
				{ isNewUndoLevel: false },
			],
		] );
	} );

	it( 'flushes direct save edits before the request and reports server changes after it', () => {
		const manager = createHostEntitySyncManager( inner );
		const persistedRecord = { id: 7, title: 'Old', slug: 'old' };
		const edits = { id: 7, title: 'New' };

		manager.beforeSave?.( 'postType', 'post', 7, edits, {
			persistedRecord,
			isAutosave: false,
		} );
		expect( inner.update ).toHaveBeenLastCalledWith(
			'postType/post',
			7,
			edits,
			'gutenberg-undo-ignored'
		);

		manager.afterSave?.( 'postType', 'post', 7, {
			savedRecord: { ...persistedRecord, ...edits, slug: 'new' },
			persistedRecord,
			edits,
		} );
		expect( inner.update ).toHaveBeenLastCalledWith(
			'postType/post',
			7,
			{ slug: 'new' },
			'gutenberg-undo-ignored',
			{ isSave: true }
		);

		// A new record: the whole response, addressed to the collection.
		manager.afterSave?.( 'postType', 'post', undefined, {
			savedRecord: { id: 8 },
			persistedRecord: undefined,
			edits: { title: 'x' },
		} );
		expect( inner.update ).toHaveBeenLastCalledWith(
			'postType/post',
			null,
			{ id: 8 },
			'gutenberg-undo-ignored',
			{ isSave: true }
		);
	} );

	it( 'unloads through the engine manager', () => {
		const manager = createHostEntitySyncManager( inner );

		manager.unload( 'postType', 'post', 7 );
		manager.unloadAll();

		expect( inner.unload ).toHaveBeenCalledWith( 'postType/post', 7 );
		expect( inner.unloadAll ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'getServerMutatedFields', () => {
	it( 'reports only what the server changed, comparing raw values', () => {
		expect(
			getServerMutatedFields(
				{
					id: 10,
					title: { raw: 'Initial', rendered: 'Initial' },
					slug: 'needs-normalizing',
					modified: '2026-07-02',
					meta: { a: 1, b: 2 },
				},
				{
					id: 10,
					title: 'Initial',
					slug: 'initial',
					modified: '2026-07-01',
					meta: { a: 1, b: 1 },
				},
				{ id: 10, slug: 'Needs Normalizing' }
			)
		).toEqual( {
			slug: 'needs-normalizing',
			modified: '2026-07-02',
			meta: { b: 2 },
		} );
	} );
} );
