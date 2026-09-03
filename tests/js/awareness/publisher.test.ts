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
 * Internal dependencies
 */
import { createActivityPublisher } from '../../../src/awareness/publisher';
import type { ActivityBeacon } from '../../../src/awareness/types';
import { block, createFakeTree } from './fake-tree';

describe( 'activity publisher', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( 100_000 );
	} );
	afterEach( () => {
		jest.useRealTimers();
	} );

	function setup() {
		const tree = createFakeTree( [
			block( 'p1', 'core/paragraph', {
				content: 'one',
				metadata: { syncId: 's1' },
			} ),
			block( 'p2', 'core/paragraph', {
				content: 'two',
				metadata: { syncId: 's2' },
			} ),
			block( 'p3', 'core/paragraph', {
				content: 'three',
				metadata: { syncId: 's3' },
			} ),
		] );
		const beacons: ActivityBeacon[] = [];
		const publisher = createActivityPublisher( {
			reader: tree,
			subscribe: tree.subscribe,
			intervalMs: 5000,
			onBeacon: ( beacon ) => beacons.push( beacon ),
		} );
		return { tree, beacons, publisher };
	}

	it( 'announces on start and then once per interval', () => {
		const { beacons, publisher } = setup();
		publisher.start();
		expect( beacons ).toHaveLength( 1 );
		expect( beacons[ 0 ] ).toMatchObject( {
			v: 2,
			seq: 1,
			intervalMs: 5000,
			focus: null,
			recent: [],
			edits: [],
		} );
		jest.advanceTimersByTime( 5000 );
		expect( beacons ).toHaveLength( 2 );
		expect( beacons[ 1 ].seq ).toBe( 2 );
		publisher.stop();
		jest.advanceTimersByTime( 20_000 );
		expect( beacons ).toHaveLength( 2 );
	} );

	it( 'reports the focused block and edits typed into it in the window', () => {
		const { tree, beacons, publisher } = setup();
		publisher.start();
		tree.select( 'p2' );
		tree.notify();
		tree.setTyping( true );
		tree.edit( 'p2', { content: 'two more', metadata: { syncId: 's2' } } );
		tree.notify();
		tree.edit( 'p2', {
			content: 'two more words',
			metadata: { syncId: 's2' },
		} );
		tree.notify();

		jest.advanceTimersByTime( 5000 );
		const beacon = beacons[ 1 ];
		expect( beacon.focus ).toMatchObject( {
			syncId: 's2',
			excerpt: 'two more words',
		} );
		expect( beacon.recent ).toEqual( [
			{ ref: expect.objectContaining( { syncId: 's2' } ), ageMs: 0 },
		] );
		expect( beacon.edits ).toEqual( [
			{
				kind: 'edit',
				count: 2,
				ref: expect.objectContaining( {
					syncId: 's2',
					excerpt: 'two more words',
				} ),
			},
		] );

		// The next window starts with no edits; the trail persists.
		jest.advanceTimersByTime( 5000 );
		expect( beacons[ 2 ].edits ).toEqual( [] );
		expect( beacons[ 2 ].recent ).toHaveLength( 1 );
	} );

	it( 'keeps a trail of blocks for 30 seconds, aged from the last interaction', () => {
		const { tree, publisher } = setup();
		publisher.start();

		// Sit in block 1 for a while, then move to block 2: leaving block 1
		// is its last interaction.
		tree.select( 'p1' );
		tree.notify();
		jest.setSystemTime( 100_000 + 20_000 );
		tree.select( 'p2' );
		tree.notify();

		// 15 s later: block 2 is current (age 0), block 1 is 15 s old.
		jest.setSystemTime( 100_000 + 35_000 );
		let beacon = publisher.flush();
		expect(
			beacon.recent.map( ( e ) => [ e.ref.syncId, e.ageMs ] )
		).toEqual( [
			[ 's2', 0 ],
			[ 's1', 15_000 ],
		] );

		// 30 s after leaving block 1 it drops out of the trail.
		jest.setSystemTime( 100_000 + 50_000 );
		beacon = publisher.flush();
		expect( beacon.recent.map( ( e ) => e.ref.syncId ) ).toEqual( [
			's2',
		] );

		// Moving on again: the block just left is age 0, the current too.
		tree.select( 'p3' );
		tree.notify();
		beacon = publisher.flush();
		expect(
			beacon.recent.map( ( e ) => [ e.ref.syncId, e.ageMs ] )
		).toEqual( [
			[ 's3', 0 ],
			[ 's2', 0 ],
		] );
	} );

	it( 'ignores block changes that are not local activity', () => {
		const { tree, beacons, publisher } = setup();
		publisher.start();
		tree.select( 'p1' );
		tree.notify();
		// Well after the interaction window, with no typing: a remote edit.
		jest.setSystemTime( 100_000 + 10_000 );
		tree.edit( 'p1', {
			content: 'changed elsewhere',
			metadata: { syncId: 's1' },
		} );
		tree.notify();
		expect( publisher.getPendingEdits() ).toEqual( [] );
		publisher.stop();
		expect( beacons ).toHaveLength( 1 );
	} );

	it( 'reports local inserts and removals with a usable reference', () => {
		const { tree, publisher } = setup();
		publisher.start();
		tree.select( 'p1' );
		tree.notify();
		tree.setTyping( true );
		tree.insertAfter(
			'p1',
			block( 'p4', 'core/paragraph', {
				content: 'new',
				metadata: { syncId: 's4' },
			} )
		);
		tree.select( 'p4' );
		tree.notify();
		expect( publisher.getPendingEdits() ).toEqual( [
			{
				kind: 'insert',
				count: 1,
				ref: expect.objectContaining( {
					syncId: 's4',
					after: 's1',
					path: [ 1 ],
				} ),
			},
		] );

		// Removing a block we had a reference for keeps that reference,
		// and takes the block out of the trail.
		tree.remove( 'p4' );
		tree.select( 'p1' );
		tree.notify();
		const beacon = publisher.flush();
		expect( beacon.edits ).toEqual( [
			{
				kind: 'remove',
				count: 2,
				ref: expect.objectContaining( { syncId: 's4' } ),
			},
		] );
		expect( beacon.recent.map( ( e ) => e.ref.syncId ) ).toEqual( [
			's1',
		] );
	} );
} );
