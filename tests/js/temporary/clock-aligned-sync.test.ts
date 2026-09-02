/**
 * External dependencies
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';

jest.mock( '../../../src/providers/http-polling/polling-manager', () => ( {
	setClockAlignedPolling: jest.fn(),
} ) );

/**
 * Internal dependencies
 */
import {
	alignSyncToClock,
	getSyncOffsetsSeconds,
} from '../../../src/temporary/clock-aligned-sync';
import { setClockAlignedPolling } from '../../../src/providers/http-polling/polling-manager';

const mockSetClockAlignedPolling = setClockAlignedPolling as jest.Mock;

type WindowWithSettings = Window & {
	_gutenbergSyncEnginesSettings?: { currentUserId?: number };
};

function setCurrentUserId( currentUserId: number | undefined ): void {
	( window as WindowWithSettings )._gutenbergSyncEnginesSettings = {
		currentUserId,
	};
}

describe( 'clock-aligned sync (demo tooling)', () => {
	afterEach( () => {
		delete ( window as WindowWithSettings )._gutenbergSyncEnginesSettings;
		mockSetClockAlignedPolling.mockClear();
	} );

	it( 'syncs user 1 at :00 and :02, user 2 at :01, everyone else at :00', () => {
		expect( getSyncOffsetsSeconds( 1 ) ).toEqual( [ 0, 2 ] );
		expect( getSyncOffsetsSeconds( 2 ) ).toEqual( [ 1 ] );
		expect( getSyncOffsetsSeconds( 3 ) ).toEqual( [ 0 ] );
		expect( getSyncOffsetsSeconds( undefined ) ).toEqual( [ 0 ] );
	} );

	it( 'aligns to a 10-second grid with the offset of the current user', () => {
		setCurrentUserId( 2 );
		alignSyncToClock();
		expect( mockSetClockAlignedPolling ).toHaveBeenCalledWith( {
			periodMs: 10000,
			offsetsMs: [ 1000 ],
		} );

		setCurrentUserId( 1 );
		alignSyncToClock();
		expect( mockSetClockAlignedPolling ).toHaveBeenLastCalledWith( {
			periodMs: 10000,
			offsetsMs: [ 0, 2000 ],
		} );
	} );

	it( 'falls back to the default offset without a user ID', () => {
		alignSyncToClock();
		expect( mockSetClockAlignedPolling ).toHaveBeenCalledWith( {
			periodMs: 10000,
			offsetsMs: [ 0 ],
		} );
	} );
} );
