/**
 * External dependencies
 */
import { describe, expect, it } from '@jest/globals';

/**
 * Internal dependencies
 */
import {
	ageInSeconds,
	getPeerStatus,
	secondsUntilNextBeacon,
	trailOpacity,
} from '../../../src/awareness/staleness';

describe( 'freshness rules', () => {
	it( 'turns a trail age into a stripe strength', () => {
		expect( trailOpacity( 0 ) ).toBe( 1 );
		expect( trailOpacity( 14_999 ) ).toBe( 1 );
		expect( trailOpacity( 15_000 ) ).toBe( 0.5 );
		expect( trailOpacity( 29_999 ) ).toBe( 0.5 );
		expect( trailOpacity( 30_000 ) ).toBe( 0 );
	} );

	it( 'drops a silent peer only after a generous wait', () => {
		const at = 1000;
		expect( getPeerStatus( at, 5000, at ) ).toBe( 'active' );
		// Four intervals is 20 s, but never sooner than a minute.
		expect( getPeerStatus( at, 5000, at + 59_000 ) ).toBe( 'active' );
		expect( getPeerStatus( at, 5000, at + 61_000 ) ).toBe( 'expired' );
		// A slower sender gets four of its own intervals.
		expect( getPeerStatus( at, 30_000, at + 119_000 ) ).toBe( 'active' );
		expect( getPeerStatus( at, 30_000, at + 121_000 ) ).toBe( 'expired' );
	} );

	it( 'reports ages and countdowns in whole seconds', () => {
		expect( ageInSeconds( 1000, 3999 ) ).toBe( 2 );
		expect( ageInSeconds( 5000, 1000 ) ).toBe( 0 );
		expect( secondsUntilNextBeacon( 1000, 5000, 3000 ) ).toBe( 3 );
		expect( secondsUntilNextBeacon( 1000, 5000, 9000 ) ).toBe( 0 );
	} );
} );
