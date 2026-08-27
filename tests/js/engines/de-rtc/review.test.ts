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
import * as Y from 'yjs';

/**
 * Internal dependencies
 */
import { createDeRtcEngine } from '../../../../src/engines/de-rtc/engine';
import {
	DE_RTC_ANNOUNCE_TYPE,
	DE_RTC_PROPOSAL_PARKED_TYPE,
	DE_RTC_PROPOSAL_TYPE,
	DE_RTC_RESOLVED_TYPE,
	DE_RTC_SNAPSHOT_TYPE,
	setDeRtcBurstQuietMsForTesting,
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

// The REST review lane (B5) POSTs resolutions through apiFetch. The
// mock also carries `use` (a no-op) — commit-route entities register the
// save-base-version middleware on creation.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: Object.assign( jest.fn(), { use: jest.fn() } ),
} ) );
// eslint-disable-next-line import/first, import/order -- After the mock.
import apiFetch from '@wordpress/api-fetch';
const apiFetchMock = apiFetch as jest.MockedFunction< any >;

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
			// A type WITHOUT a commit route: these suites pin the transport
			// proposal lane (collections/unsupported types still use it).
			objectType: 'postType/book',
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
		engine.review.subscribe( 'postType/book', '1', changed );

		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [
				{ index: 0, html: contentOf( BLOCK_C ) },
			] )
		);

		expect( changed ).toHaveBeenCalled();
		const items = engine.review.getOpenItems( 'postType/book', '1' );
		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ] ).toMatchObject( {
			id: 'p-9-1',
			unitId: 'p-9-1',
			isLocal: false,
			actorId: 'u7c9',
			reason: 'frame-conflict',
			summary: 'lost words',
			// The first changed block anchors the inline card (B3).
			targetIndex: 0,
		} );

		// The kses reason maps to the panel's capability-gated vocabulary.
		session.receiveUpdate(
			parkedRow( 'p-9-2', 'requires-unfiltered-html', 9, [] )
		);
		const updated = engine.review.getOpenItems( 'postType/book', '1' );
		expect( updated.find( ( item ) => 'p-9-2' === item.id )?.reason ).toBe(
			'requires-approval'
		);
		// No changed blocks → no canvas anchor: the item is panel-only.
		expect(
			updated.find( ( item ) => 'p-9-2' === item.id )?.targetIndex
		).toBeUndefined();
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
			engine.review.getOpenItems( 'postType/book', '1' )[ 0 ].isLocal
		).toBe( true );
	} );

	it( 'a redelivered parked row never duplicates, and a resolved row closes it', () => {
		const { session } = makeEntity();
		const row = parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] );
		session.receiveUpdate( row );
		session.receiveUpdate( row );
		expect(
			engine.review.getOpenItems( 'postType/book', '1' )
		).toHaveLength( 1 );

		session.receiveUpdate( {
			type: DE_RTC_RESOLVED_TYPE,
			data: JSON.stringify( {
				proposalId: 'p-9-1',
				resolution: 'dismissed',
			} ),
		} );
		expect(
			engine.review.getOpenItems( 'postType/book', '1' )
		).toHaveLength( 0 );

		// A parked row replayed AFTER its resolution stays closed (bootstrap
		// replays deliver both rows).
		session.receiveUpdate( row );
		expect(
			engine.review.getOpenItems( 'postType/book', '1' )
		).toHaveLength( 0 );
	} );

	it( 'dismiss emits a resolved row through the session wire', () => {
		const { session, sent } = makeEntity();
		session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] )
		);

		engine.review.resolveProposal(
			'postType/book',
			'1',
			'p-9-1',
			'dismissed'
		);

		expect(
			engine.review.getOpenItems( 'postType/book', '1' )
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

		engine.review.restoreProposal( 'postType/book', '1', 'p-9-1' );

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
			engine.review.getOpenItems( 'postType/book', '1' )
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

		engine.review.restoreProposal( 'postType/book', '1', 'p-9-1' );

		const changes = entity.getEditorChanges( { blocks: [] } as any ) as any;
		expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_C ] );
	} );

	it( 'a subscription made BEFORE the entity exists still fires (the manager subscribes during load)', () => {
		const changed = jest.fn();
		engine.review.subscribe( 'postType/book', '1', changed );

		// The entity is created only after the subscription — the real
		// ordering: createSyncManager wires the review source before it
		// asks the engine for the entity.
		const { session } = makeEntity();
		session.receiveUpdate(
			parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] )
		);

		expect( changed ).toHaveBeenCalled();
		expect(
			engine.review.getOpenItems( 'postType/book', '1' )
		).toHaveLength( 1 );
	} );

	describe( 'REST resolution lane (B5, commit-route types)', () => {
		function makePostEntity() {
			const entity = engine.createEntity( {
				syncConfig,
				// postType/post HAS a commit route, so resolutions POST to
				// the REST review route instead of riding the transport.
				objectType: 'postType/post',
				objectId: '1',
			} as any );
			const session = entity.createSession();
			const sent: any[] = [];
			session.onLocalUpdate( ( update: any ) => sent.push( update ) );
			return { entity, session, sent };
		}

		beforeEach( () => {
			apiFetchMock.mockReset();
		} );

		it( 'dismiss POSTs the resolution and sends NO transport row', async () => {
			apiFetchMock.mockResolvedValue( {
				disposition: { intentId: 'p-9-1', status: 'resolved' },
			} );
			const { session, sent } = makePostEntity();
			session.receiveUpdate(
				parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] )
			);

			engine.review.resolveProposal(
				'postType/post',
				'1',
				'p-9-1',
				'dismissed'
			);
			// Optimistic close is synchronous either way.
			expect(
				engine.review.getOpenItems( 'postType/post', '1' )
			).toHaveLength( 0 );
			await Promise.resolve();

			expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
			expect( apiFetchMock ).toHaveBeenCalledWith( {
				data: {
					client_id: session.clientId,
					proposalId: 'p-9-1',
					resolution: 'dismissed',
					room: 'postType/post:1',
				},
				method: 'POST',
				path: '/wp-sync/v1/de-rtc/resolve',
			} );
			expect(
				sent.filter(
					( update ) => DE_RTC_RESOLVED_TYPE === update.type
				)
			).toHaveLength( 0 );
		} );

		it( 'falls back to the transport row when the POST rejects', async () => {
			apiFetchMock.mockRejectedValue( new Error( 'offline' ) );
			const { session, sent } = makePostEntity();
			session.receiveUpdate(
				parkedRow( 'p-9-1', 'manual-conflict-required', 9, [] )
			);

			engine.review.resolveProposal(
				'postType/post',
				'1',
				'p-9-1',
				'dismissed'
			);
			// Let the rejection settle and the fallback fire.
			await Promise.resolve();
			await Promise.resolve();

			const resolvedRows = sent.filter(
				( update ) => DE_RTC_RESOLVED_TYPE === update.type
			);
			expect( resolvedRows ).toHaveLength( 1 );
			expect( JSON.parse( resolvedRows[ 0 ].data ) ).toEqual( {
				proposalId: 'p-9-1',
				resolution: 'dismissed',
			} );
		} );
	} );

	describe( 'contested-item review surface (contests at the engine level)', () => {
		const A_LOCAL = {
			name: 'core/paragraph',
			attributes: { content: 'Alpha local' },
		};
		const A_NEWER = {
			name: 'core/paragraph',
			attributes: { content: 'Alpha local newer' },
		};
		const A_PEER = {
			name: 'core/paragraph',
			attributes: { content: 'Alpha peer' },
		};

		beforeEach( () => {
			// Edits and snapshots land in the same tick here; the typing-
			// burst quiet gate would defer every snapshot otherwise.
			setDeRtcBurstQuietMsForTesting( 0 );
		} );

		afterEach( () => {
			setDeRtcBurstQuietMsForTesting( 500 );
		} );

		/**
		 * Drives the real collision choreography through the session codec:
		 * propose an edit to block 0, edit block 0 AGAIN while the proposal
		 * is in flight, then have the server merge a PEER's change to the
		 * same block (merged own announce → fetch → snapshot). The
		 * incorporation keeps our newer local block and raises a contest.
		 */
		function raiseContest() {
			const { entity, session, sent } = makeEntity();
			session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
			entity.applyLocalChanges(
				{ blocks: [ A_LOCAL ] } as any,
				'editor',
				{}
			);
			const proposal = JSON.parse( sent[ 0 ].data );
			const clientId = Number( proposal.proposalId.split( '-' )[ 1 ] );
			// A newer edit to the SAME block while the proposal is in
			// flight (local now differs from what we proposed).
			entity.applyLocalChanges(
				{ blocks: [ A_NEWER ] } as any,
				'editor',
				{}
			);
			// The server merged a peer's change to block 0 into v2.
			session.receiveUpdate( {
				type: DE_RTC_ANNOUNCE_TYPE,
				data: JSON.stringify( {
					version: 'v2',
					baseVersion: 'v1',
					contentHash: 'merged-differs',
					authorClientId: clientId,
					proposalId: proposal.proposalId,
				} ),
			} );
			session.receiveUpdate( snapshotRow( 'v2', contentOf( A_PEER ) ) );
			return { entity };
		}

		it( 'a contest surfaces as a review item anchored by targetIndex', () => {
			const changed = jest.fn();
			engine.review.subscribe( 'postType/book', '1', changed );
			raiseContest();

			const items = engine.review.getOpenItems( 'postType/book', '1' );
			expect( items ).toHaveLength( 1 );
			expect( items[ 0 ] ).toMatchObject( {
				id: 'contested-0',
				unitId: 'contested-0',
				isLocal: false,
				reason: 'frame-conflict',
				// The positional anchor the inline card renders at (B3).
				targetIndex: 0,
			} );
			expect( items[ 0 ].summary ).toContain( 'Alpha peer' );
			expect( changed ).toHaveBeenCalled();
		} );

		it( 'dismiss routes to REJECT: the local block survives, the item closes', () => {
			const { entity } = raiseContest();

			engine.review.resolveProposal(
				'postType/book',
				'1',
				'contested-0',
				'dismissed'
			);

			expect(
				engine.review.getOpenItems( 'postType/book', '1' )
			).toHaveLength( 0 );
			const changes = entity.getEditorChanges( {
				blocks: [],
			} as any ) as any;
			expect( changes.blocks ).toEqual( [ A_NEWER ] );
		} );

		it( 'restore routes to ADOPT: the canonical block lands, the item closes', () => {
			const { entity } = raiseContest();

			engine.review.restoreProposal(
				'postType/book',
				'1',
				'contested-0'
			);

			expect(
				engine.review.getOpenItems( 'postType/book', '1' )
			).toHaveLength( 0 );
			const changes = entity.getEditorChanges( {
				blocks: [],
			} as any ) as any;
			expect( changes.blocks ).toEqual( [ A_PEER ] );
		} );
	} );

	it( 'an unknown entity yields an empty review surface', () => {
		expect( engine.review.getOpenItems( 'postType/book', '999' ) ).toEqual(
			[]
		);
		expect( () =>
			engine.review.resolveProposal(
				'postType/book',
				'999',
				'p-x',
				'dismissed'
			)
		).not.toThrow();
	} );
} );

describe( 'de-rtc merge-view group surface (describe and resolve)', () => {
	let syncConfig: jest.MockedObject< SyncConfig >;
	let engine: ReturnType< typeof createDeRtcEngine >;

	beforeEach( () => {
		syncConfig = makeSyncConfig();
		engine = createDeRtcEngine();
		// Edits and snapshots land in the same tick here; the typing-burst
		// quiet gate would defer every snapshot otherwise.
		setDeRtcBurstQuietMsForTesting( 0 );
	} );

	afterEach( () => {
		setDeRtcBurstQuietMsForTesting( 500 );
	} );

	function makeEntity() {
		const entity = engine.createEntity( {
			syncConfig,
			// A type WITHOUT a commit route: these suites pin the transport
			// proposal lane.
			objectType: 'postType/book',
			objectId: '1',
		} as any );
		const session = entity.createSession();
		const sent: any[] = [];
		session.onLocalUpdate( ( update: any ) => sent.push( update ) );
		return { entity, session, sent };
	}

	const A_LOCAL = {
		name: 'core/paragraph',
		attributes: { content: 'Alpha local' },
	};
	const A_MERGED = {
		name: 'core/paragraph',
		attributes: { content: 'Alpha merged by hand' },
	};

	describe( 'parked rows', () => {
		it( 'stamps supportsMergeView on the LOCAL author’s genuine conflicts only', () => {
			const { session } = makeEntity();
			session.receiveUpdate(
				snapshotRow( 'v1', contentOf( BLOCK_A, BLOCK_B ) )
			);
			const ownClientId = ( session as any ).clientId as number;
			session.receiveUpdate(
				parkedRow( 'p-own', 'manual-conflict-required', ownClientId, [
					{ index: 0, html: contentOf( A_LOCAL ) },
				] )
			);
			session.receiveUpdate(
				parkedRow( 'p-peer', 'manual-conflict-required', 424242, [
					{ index: 1, html: contentOf( BLOCK_B ) },
				] )
			);
			session.receiveUpdate(
				parkedRow( 'p-kses', 'requires-unfiltered-html', ownClientId, [
					{ index: 1, html: contentOf( BLOCK_B ) },
				] )
			);

			const byId = new Map(
				engine.review
					.getOpenItems( 'postType/book', '1' )
					.map( ( item ) => [ item.id, item ] )
			);
			expect( byId.get( 'p-own' )?.supportsMergeView ).toBe( true );
			expect( byId.get( 'p-peer' )?.supportsMergeView ).toBe( false );
			expect( byId.get( 'p-kses' )?.supportsMergeView ).toBe( false );
		} );

		it( 'describes a parked block group: stored mine, live current, no base', () => {
			const { session } = makeEntity();
			session.receiveUpdate(
				snapshotRow( 'v1', contentOf( BLOCK_A, BLOCK_B ) )
			);
			const ownClientId = ( session as any ).clientId as number;
			session.receiveUpdate(
				parkedRow( 'p-own', 'manual-conflict-required', ownClientId, [
					{ index: 0, html: contentOf( A_LOCAL ) },
				] )
			);

			const description = engine.review.describeReviewGroup!(
				'postType/book',
				'1',
				[ 'p-own' ]
			);
			expect( description ).toMatchObject( {
				baseText: null,
				proposedHtml: contentOf( A_LOCAL ),
				currentHtml: JSON.stringify( [ BLOCK_A ] ),
			} );
			expect( description!.proposedText ).toContain( 'Alpha local' );
		} );

		it( 'a hand-merged resolution overlays the merged html and resolves restored', () => {
			const { entity, session, sent } = makeEntity();
			session.receiveUpdate(
				snapshotRow( 'v1', contentOf( BLOCK_A, BLOCK_B ) )
			);
			const ownClientId = ( session as any ).clientId as number;
			session.receiveUpdate(
				parkedRow( 'p-own', 'manual-conflict-required', ownClientId, [
					{ index: 0, html: contentOf( A_LOCAL ) },
				] )
			);
			sent.length = 0;

			engine.review.resolveReviewGroup!(
				'postType/book',
				'1',
				[ 'p-own' ],
				'restored',
				contentOf( A_MERGED )
			);

			const changes = entity.getEditorChanges( {
				blocks: [],
			} as any ) as any;
			expect( changes.blocks ).toEqual( [ A_MERGED, BLOCK_B ] );
			const resolvedRows = sent
				.filter( ( update ) => DE_RTC_RESOLVED_TYPE === update.type )
				.map( ( update ) => JSON.parse( update.data ) );
			expect( resolvedRows ).toEqual( [
				{ proposalId: 'p-own', resolution: 'restored' },
			] );
			expect(
				engine.review.getOpenItems( 'postType/book', '1' )
			).toHaveLength( 0 );
		} );

		it( 'keep-current dismisses without touching the doc', () => {
			const { entity, session, sent } = makeEntity();
			session.receiveUpdate(
				snapshotRow( 'v1', contentOf( BLOCK_A, BLOCK_B ) )
			);
			const ownClientId = ( session as any ).clientId as number;
			session.receiveUpdate(
				parkedRow( 'p-own', 'manual-conflict-required', ownClientId, [
					{ index: 0, html: contentOf( A_LOCAL ) },
				] )
			);
			sent.length = 0;

			engine.review.resolveReviewGroup!(
				'postType/book',
				'1',
				[ 'p-own' ],
				'dismissed'
			);

			const changes = entity.getEditorChanges( {
				blocks: [],
			} as any ) as any;
			expect( changes.blocks ).toEqual( [ BLOCK_A, BLOCK_B ] );
			const resolvedRows = sent
				.filter( ( update ) => DE_RTC_RESOLVED_TYPE === update.type )
				.map( ( update ) => JSON.parse( update.data ) );
			expect( resolvedRows ).toEqual( [
				{ proposalId: 'p-own', resolution: 'dismissed' },
			] );
		} );

		it( 'a lost property register gets the two-pane variant and a merged value re-applies', () => {
			const { entity, session } = makeEntity();
			session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
			entity.applyLocalChanges(
				{ title: 'Theirs' } as any,
				'editor',
				{}
			);
			const ownClientId = ( session as any ).clientId as number;
			session.receiveUpdate( {
				type: DE_RTC_PROPOSAL_PARKED_TYPE,
				data: JSON.stringify( {
					proposalId: 'p-prop',
					reason: 'property-conflict',
					authorClientId: ownClientId,
					author: 7,
					changedBlocks: [],
					property: { name: 'title', value: 'Mine' },
					excerpt: 'Mine',
				} ),
			} );

			const description = engine.review.describeReviewGroup!(
				'postType/book',
				'1',
				[ 'p-prop' ]
			);
			expect( description ).toEqual( {
				baseText: null,
				proposedText: 'Mine',
				currentText: 'Theirs',
			} );

			engine.review.resolveReviewGroup!(
				'postType/book',
				'1',
				[ 'p-prop' ],
				'restored',
				'Merged title'
			);
			const changes = entity.getEditorChanges( {} as any ) as any;
			expect( changes.title ).toBe( 'Merged title' );
		} );
	} );

	describe( 'contested blocks', () => {
		const A_NEWER = {
			name: 'core/paragraph',
			attributes: { content: 'Alpha local newer' },
		};
		const A_PEER = {
			name: 'core/paragraph',
			attributes: { content: 'Alpha peer' },
		};

		/**
		 * Same collision choreography as the contested-item suite above:
		 * a newer local edit collides with a server-merged peer change.
		 */
		function raiseContest() {
			const { entity, session, sent } = makeEntity();
			session.receiveUpdate( snapshotRow( 'v1', contentOf( BLOCK_A ) ) );
			entity.applyLocalChanges(
				{ blocks: [ A_LOCAL ] } as any,
				'editor',
				{}
			);
			const proposal = JSON.parse( sent[ 0 ].data );
			const clientId = Number( proposal.proposalId.split( '-' )[ 1 ] );
			entity.applyLocalChanges(
				{ blocks: [ A_NEWER ] } as any,
				'editor',
				{}
			);
			session.receiveUpdate( {
				type: DE_RTC_ANNOUNCE_TYPE,
				data: JSON.stringify( {
					version: 'v2',
					baseVersion: 'v1',
					contentHash: 'merged-differs',
					authorClientId: clientId,
					proposalId: proposal.proposalId,
				} ),
			} );
			session.receiveUpdate( snapshotRow( 'v2', contentOf( A_PEER ) ) );
			return { entity, session, sent };
		}

		it( 'is the three-pane-guaranteed case: base, live mine, cached current', () => {
			raiseContest();
			const items = engine.review.getOpenItems( 'postType/book', '1' );
			expect( items[ 0 ] ).toMatchObject( {
				id: 'contested-0',
				supportsMergeView: true,
			} );

			const description = engine.review.describeReviewGroup!(
				'postType/book',
				'1',
				[ 'contested-0' ]
			);
			expect( description ).toMatchObject( {
				proposedHtml: JSON.stringify( [ A_NEWER ] ),
				currentHtml: JSON.stringify( [ A_PEER ] ),
			} );
			// The base block's exact form was recorded when the contest
			// arose.
			expect( description!.baseText ).toContain( 'Alpha' );
			expect( description!.baseText ).not.toContain( 'peer' );
		} );

		it( 'keep-current ADOPTS canonical; restore-mine KEEPS the local block', () => {
			const first = raiseContest();
			engine.review.resolveReviewGroup!(
				'postType/book',
				'1',
				[ 'contested-0' ],
				'dismissed'
			);
			expect(
				(
					first.entity.getEditorChanges( {
						blocks: [],
					} as any ) as any
				 ).blocks
			).toEqual( [ A_PEER ] );

			// A fresh entity for the restore-mine direction. The engine's
			// review registry keys by entity, so re-create it.
			engine = createDeRtcEngine();
			const second = raiseContest();
			engine.review.resolveReviewGroup!(
				'postType/book',
				'1',
				[ 'contested-0' ],
				'restored'
			);
			expect(
				(
					second.entity.getEditorChanges( {
						blocks: [],
					} as any ) as any
				 ).blocks
			).toEqual( [ A_NEWER ] );
			expect(
				engine.review.getOpenItems( 'postType/book', '1' )
			).toHaveLength( 0 );
		} );

		it( 'a hand-merged contest re-proposes the merged block and clears the recorded base', () => {
			const { entity, session, sent } = raiseContest();
			const proposalRows = () =>
				sent
					.filter(
						( update ) => DE_RTC_PROPOSAL_TYPE === update.type
					)
					.map( ( update ) => JSON.parse( update.data ) );
			const proposalsBefore = proposalRows().length;

			engine.review.resolveReviewGroup!(
				'postType/book',
				'1',
				[ 'contested-0' ],
				'restored',
				contentOf( A_MERGED )
			);

			expect(
				( entity.getEditorChanges( { blocks: [] } as any ) as any )
					.blocks
			).toEqual( [ A_MERGED ] );
			expect(
				engine.review.getOpenItems( 'postType/book', '1' )
			).toHaveLength( 0 );
			// The restore origin marks the doc dirty; the merged form waits
			// on the one-in-flight rule, so settle the pending proposal and
			// the merged one goes out as an ordinary proposal, declaring
			// the current version (the recorded per-block base cleared).
			const pending = proposalRows().at( -1 );
			session.receiveDispositions!( [
				{ intentId: pending.proposalId, status: 'applied' },
			] as never );
			const proposals = proposalRows();
			expect( proposals.length ).toBeGreaterThan( proposalsBefore );
			const lastProposal = proposals[ proposals.length - 1 ];
			expect( lastProposal.baseVersion ).toBe( 'v2' );
			expect( lastProposal.blockBaseVersions ).toBeUndefined();
			expect( lastProposal.proposedContent ).toContain(
				'Alpha merged by hand'
			);
		} );
	} );
} );
