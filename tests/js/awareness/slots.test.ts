/**
 * External dependencies
 */
import { describe, expect, it } from '@jest/globals';

/**
 * Internal dependencies
 */
import { layoutBar, withAlpha } from '../../../src/awareness/slots';
import type { BlockPresence } from '../../../src/awareness/store';

const entry = (
	peerKey: string,
	color: string,
	opacity = 1,
	extra: Partial< BlockPresence > = {}
): BlockPresence => ( {
	peerKey,
	name: peerKey,
	userId: null,
	color,
	role: 'focus',
	typing: false,
	opacity,
	ageMs: 0,
	receivedAt: 0,
	intervalMs: 5000,
	...extra,
} );

describe( 'bar layout', () => {
	it( 'gives one peer the whole bar', () => {
		const layout = layoutBar( [ entry( 'a', '#d94145' ) ] );
		expect( layout.boundaries ).toEqual( [ 100, 100, 100 ] );
		expect( layout.colors ).toEqual( [
			'rgba(217, 65, 69, 1)',
			'transparent',
			'transparent',
			'transparent',
		] );
	} );

	it( 'splits the bar evenly, first arrival on top', () => {
		const layout = layoutBar( [
			entry( 'a', '#d94145' ),
			entry( 'b', '#0f766e', 0.5 ),
		] );
		expect( layout.boundaries ).toEqual( [ 50, 100, 100 ] );
		expect( layout.colors[ 0 ] ).toBe( 'rgba(217, 65, 69, 1)' );
		expect( layout.colors[ 1 ] ).toBe( 'rgba(15, 118, 110, 0.5)' );
	} );

	it( 'collapses a leaving segment to nothing while keeping its slot', () => {
		const layout = layoutBar( [
			entry( 'a', '#d94145', 0, { leaving: true, lastOpacity: 0.5 } ),
			entry( 'b', '#0f766e' ),
		] );
		expect( layout.boundaries ).toEqual( [ 0, 100, 100 ] );
		// The departing color stays visible at its last strength as it goes.
		expect( layout.colors[ 0 ] ).toBe( 'rgba(217, 65, 69, 0.5)' );
		expect( layout.colors[ 1 ] ).toBe( 'rgba(15, 118, 110, 1)' );
	} );

	it( 'caps the bar at four peers and lists the rest', () => {
		const layout = layoutBar(
			[ 'a', 'b', 'c', 'd', 'e', 'f' ].map( ( key ) =>
				entry( key, '#6f42c1' )
			)
		);
		expect( layout.slots.map( ( e ) => e.peerKey ) ).toEqual( [
			'a',
			'b',
			'c',
			'd',
		] );
		expect( layout.extras.map( ( e ) => e.peerKey ) ).toEqual( [
			'e',
			'f',
		] );
		expect( layout.boundaries ).toEqual( [ 25, 50, 75 ] );
	} );

	it( 'formats colors with alpha', () => {
		expect( withAlpha( '#FBBF24', 0.5 ) ).toBe( 'rgba(251, 191, 36, 0.5)' );
		expect( withAlpha( 'red', 0.5 ) ).toBe( 'red' );
	} );
} );
