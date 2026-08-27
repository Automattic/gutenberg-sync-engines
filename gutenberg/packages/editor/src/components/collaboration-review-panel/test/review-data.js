import {
	canOpenMergeView,
	itemSummaries,
	mergeViewGroupItems,
} from '../review-data';

const item = ( overrides = {} ) => ( {
	id: 'i1',
	unitId: 'u1',
	isLocal: true,
	actorId: 'a1',
	reason: 'frame-conflict',
	intentType: 'insert_text',
	targetId: 'p1',
	targetField: 'content',
	supportsMergeView: true,
	...overrides,
} );

describe( 'canOpenMergeView', () => {
	it( 'requires every item to be one the engine can serve', () => {
		expect( canOpenMergeView( [ item(), item( { id: 'i2' } ) ] ) ).toBe(
			true
		);
		expect(
			canOpenMergeView( [
				item(),
				item( { id: 'i2', supportsMergeView: false } ),
			] )
		).toBe( false );
		expect( canOpenMergeView( [] ) ).toBe( false );
	} );
} );

describe( 'mergeViewGroupItems', () => {
	it( 'expands a seed to the author’s whole group on the field', () => {
		const items = [
			item(),
			item( { id: 'i2' } ),
			// Another author on the same field: separate dialog.
			item( { id: 'i3', actorId: 'a2' } ),
			// Same author, another field: separate dialog.
			item( { id: 'i4', targetField: 'citation' } ),
			// Not servable: never joins a group.
			item( { id: 'i5', supportsMergeView: false } ),
		];
		expect(
			mergeViewGroupItems( items, [ 'i1' ] ).map( ( i ) => i.id )
		).toEqual( [ 'i1', 'i2' ] );
	} );

	it( 'keeps field-less items (whole blocks) to the seeds themselves', () => {
		const items = [
			item( { targetField: undefined, targetIndex: 0 } ),
			item( {
				id: 'i2',
				targetField: undefined,
				targetIndex: 1,
			} ),
		];
		expect(
			mergeViewGroupItems( items, [ 'i1' ] ).map( ( i ) => i.id )
		).toEqual( [ 'i1' ] );
	} );

	it( 'yields an empty group when no seed is open or servable', () => {
		expect( mergeViewGroupItems( [ item() ], [ 'gone' ] ) ).toEqual( [] );
		expect(
			mergeViewGroupItems(
				[ item( { supportsMergeView: false } ) ],
				[ 'i1' ]
			)
		).toEqual( [] );
	} );
} );

describe( 'itemSummaries', () => {
	it( 'shows a grouped burst ONCE, as its combined text, beside solo summaries', () => {
		const items = [
			item( { id: 'k1', summary: 'b', groupSummary: 'abc ' } ),
			item( { id: 'k2', summary: 'c', groupSummary: 'abc ' } ),
			item( { id: 'k3', summary: ' ', groupSummary: 'abc ' } ),
			item( {
				id: 's1',
				targetField: undefined,
				supportsMergeView: false,
				summary: 'solo words',
			} ),
		];
		// Never the per-keystroke "b c " join.
		expect( itemSummaries( items ) ).toEqual( [ 'abc ', 'solo words' ] );
	} );

	it( 'keeps separate changesets separate', () => {
		const items = [
			item( { id: 'k1', groupSummary: 'abc ' } ),
			item( {
				id: 'k2',
				actorId: 'a2',
				groupSummary: ' 123',
			} ),
		];
		expect( itemSummaries( items ) ).toEqual( [ 'abc ', ' 123' ] );
	} );
} );
