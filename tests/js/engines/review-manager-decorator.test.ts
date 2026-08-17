/**
 * External dependencies
 */
import { describe, expect, it, jest } from '@jest/globals';

/**
 * Internal dependencies
 */
import {
	decorateManagerWithReview,
	type EngineReviewSource,
} from '../../../src/engines/review-manager-decorator';

// eslint-disable-next-line import/no-unresolved -- Resolved to the subtree.
import type { SyncManager, SyncReviewItem } from '@wordpress/sync';

const flushMicrotasks = () => Promise.resolve().then( () => {} );

function makeInnerManager() {
	let lazyUndoManager: unknown;
	const calls: Record< string, unknown[][] > = {
		load: [],
		unload: [],
		unloadAll: [],
	};
	const inner = {
		createPersistedCRDTDoc: jest.fn(),
		getAwareness: jest.fn(),
		getEntitySnapshot: jest.fn(),
		entityContainsSnapshot: jest.fn(),
		load: jest.fn( async ( ...args: unknown[] ) => {
			calls.load.push( args );
		} ),
		loadCollection: jest.fn(),
		get undoManager() {
			return lazyUndoManager;
		},
		unload: jest.fn( ( ...args: unknown[] ) => {
			calls.unload.push( args );
		} ),
		unloadAll: jest.fn( () => {
			calls.unloadAll.push( [] );
		} ),
		update: jest.fn(),
	} as unknown as SyncManager;

	return {
		inner,
		calls,
		setUndoManager( value: unknown ) {
			lazyUndoManager = value;
		},
	};
}

function makeReviewSource( initialItems: SyncReviewItem[] = [] ) {
	let items = initialItems;
	const listeners = new Map< string, Set< () => void > >();
	const keyOf = ( objectType: string, objectId: unknown ) =>
		`${ objectType }:${ String( objectId ) }`;
	const source: EngineReviewSource = {
		getOpenItems: jest.fn( () => items ),
		subscribe: jest.fn(
			( objectType: string, objectId: unknown, listener: () => void ) => {
				const key = keyOf( objectType, objectId );
				if ( ! listeners.has( key ) ) {
					listeners.set( key, new Set() );
				}
				listeners.get( key )!.add( listener );
				return () => listeners.get( key )!.delete( listener );
			}
		),
		resolveProposal: jest.fn(),
		restoreProposal: jest.fn(),
	};

	return {
		source,
		setItems( next: SyncReviewItem[] ) {
			items = next;
		},
		emit( objectType: string, objectId: unknown ) {
			listeners
				.get( keyOf( objectType, objectId ) )
				?.forEach( ( listener ) => listener() );
		},
		listenerCount( objectType: string, objectId: unknown ) {
			return listeners.get( keyOf( objectType, objectId ) )?.size ?? 0;
		},
	};
}

const item = ( id: string, extra: Partial< SyncReviewItem > = {} ) =>
	( {
		id,
		unitId: id,
		isLocal: true,
		actorId: 'u1c1',
		reason: 'frame-conflict',
		intentType: 'proposal',
		...extra,
	} ) as SyncReviewItem;

describe( 'decorateManagerWithReview', () => {
	it( 'delegates load and captures review handlers before the inner rewrap', async () => {
		const { inner } = makeInnerManager();
		const review = makeReviewSource( [ item( 'p1' ) ] );
		const manager = decorateManagerWithReview( inner, review.source );

		const onProposalsChange = jest.fn();
		const onEscalation = jest.fn();
		const handlers = { onProposalsChange, onEscalation } as never;

		await manager.load(
			{} as never,
			'postType/post',
			'42',
			{} as never,
			handlers
		);

		expect( inner.load ).toHaveBeenCalledTimes( 1 );
		// The decorator passes the ORIGINAL handlers through untouched.
		expect( ( inner.load as jest.Mock ).mock.calls[ 0 ][ 4 ] ).toBe(
			handlers
		);

		review.emit( 'postType/post', '42' );
		await flushMicrotasks();

		expect( onProposalsChange ).toHaveBeenCalledWith( [
			expect.objectContaining( { id: 'p1' } ),
		] );
		expect( onEscalation ).toHaveBeenCalledWith(
			expect.objectContaining( { proposalId: 'p1', isLocal: true } )
		);
	} );

	it( 'coalesces bursts into one notification and escalates each id once', async () => {
		const { inner } = makeInnerManager();
		const review = makeReviewSource( [ item( 'p1' ) ] );
		const manager = decorateManagerWithReview( inner, review.source );

		const onProposalsChange = jest.fn();
		const onEscalation = jest.fn();
		await manager.load(
			{} as never,
			'postType/post',
			'42',
			{} as never,
			{
				onProposalsChange,
				onEscalation,
			} as never
		);

		review.emit( 'postType/post', '42' );
		review.emit( 'postType/post', '42' );
		review.emit( 'postType/post', '42' );
		await flushMicrotasks();

		expect( onProposalsChange ).toHaveBeenCalledTimes( 1 );
		expect( onEscalation ).toHaveBeenCalledTimes( 1 );

		// A later change re-notifies the list but not the seen escalation.
		review.setItems( [ item( 'p1' ), item( 'p2', { isLocal: false } ) ] );
		review.emit( 'postType/post', '42' );
		await flushMicrotasks();

		expect( onProposalsChange ).toHaveBeenCalledTimes( 2 );
		expect( onEscalation ).toHaveBeenCalledTimes( 2 );
		expect( onEscalation ).toHaveBeenLastCalledWith(
			expect.objectContaining( { proposalId: 'p2', isLocal: false } )
		);
	} );

	it( 'exposes the inner undoManager through a live getter', async () => {
		const { inner, setUndoManager } = makeInnerManager();
		const review = makeReviewSource();
		const manager = decorateManagerWithReview( inner, review.source );

		expect( manager.undoManager ).toBeUndefined();
		const undo = { undo: jest.fn() };
		setUndoManager( undo );
		// A spread-based decorator would still report undefined here.
		expect( manager.undoManager ).toBe( undo );
	} );

	it( 'routes resolveProposal and restoreProposal to the review source', () => {
		const { inner } = makeInnerManager();
		const review = makeReviewSource();
		const manager = decorateManagerWithReview( inner, review.source );

		manager.resolveProposal?.( 'postType/post', '42', 'p9', 'dismissed' );
		expect( review.source.resolveProposal ).toHaveBeenCalledWith(
			'postType/post',
			'42',
			'p9',
			'dismissed'
		);

		manager.restoreProposal?.( 'postType/post', '42', 'p9' );
		expect( review.source.restoreProposal ).toHaveBeenCalledWith(
			'postType/post',
			'42',
			'p9'
		);
	} );

	it( 'stops notifying after unload and cleans up subscriptions', async () => {
		const { inner } = makeInnerManager();
		const review = makeReviewSource( [ item( 'p1' ) ] );
		const manager = decorateManagerWithReview( inner, review.source );

		const onProposalsChange = jest.fn();
		await manager.load(
			{} as never,
			'postType/post',
			'42',
			{} as never,
			{
				onProposalsChange,
			} as never
		);
		expect( review.listenerCount( 'postType/post', '42' ) ).toBe( 1 );

		manager.unload( 'postType/post', '42' );
		expect( inner.unload ).toHaveBeenCalledWith( 'postType/post', '42' );
		expect( review.listenerCount( 'postType/post', '42' ) ).toBe( 0 );

		review.emit( 'postType/post', '42' );
		await flushMicrotasks();
		expect( onProposalsChange ).not.toHaveBeenCalled();
	} );

	it( 'a pending notification scheduled before unload never fires', async () => {
		const { inner } = makeInnerManager();
		const review = makeReviewSource( [ item( 'p1' ) ] );
		const manager = decorateManagerWithReview( inner, review.source );

		const onProposalsChange = jest.fn();
		await manager.load(
			{} as never,
			'postType/post',
			'42',
			{} as never,
			{
				onProposalsChange,
			} as never
		);

		review.emit( 'postType/post', '42' );
		manager.unload( 'postType/post', '42' );
		await flushMicrotasks();

		expect( onProposalsChange ).not.toHaveBeenCalled();
	} );

	it( 'unloadAll detaches every entity and delegates', async () => {
		const { inner } = makeInnerManager();
		const review = makeReviewSource();
		const manager = decorateManagerWithReview( inner, review.source );

		await manager.load(
			{} as never,
			'postType/post',
			'1',
			{} as never,
			{} as never
		);
		await manager.load(
			{} as never,
			'postType/page',
			'2',
			{} as never,
			{} as never
		);
		expect( review.listenerCount( 'postType/post', '1' ) ).toBe( 1 );
		expect( review.listenerCount( 'postType/page', '2' ) ).toBe( 1 );

		manager.unloadAll();
		expect( inner.unloadAll ).toHaveBeenCalledTimes( 1 );
		expect( review.listenerCount( 'postType/post', '1' ) ).toBe( 0 );
		expect( review.listenerCount( 'postType/page', '2' ) ).toBe( 0 );
	} );
} );
