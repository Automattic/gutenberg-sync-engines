/**
 * External dependencies
 */
import { describe, expect, it, jest } from '@jest/globals';

type SyncConfig =
	typeof import('../../../../src/providers/http-polling/config');

function loadConfigWithFilteredIntervals(
	filteredIntervals: Record< string, unknown >,
	siteSettings?: Record< string, unknown >
): SyncConfig {
	jest.resetModules();
	jest.doMock( '@wordpress/hooks', () => ( {
		applyFilters: jest.fn( ( hookName: string, defaultValue: unknown ) => {
			if (
				Object.prototype.hasOwnProperty.call(
					filteredIntervals,
					hookName
				)
			) {
				return filteredIntervals[ hookName ];
			}

			return defaultValue;
		} ),
	} ) );

	if ( siteSettings ) {
		( window as any )._gutenbergSyncEnginesSettings = siteSettings;
	} else {
		delete ( window as any )._gutenbergSyncEnginesSettings;
	}

	return require( '../../../../src/providers/http-polling/config' ) as SyncConfig;
}

describe( 'http-polling config', () => {
	it( 'uses default polling intervals when filters do not change them', () => {
		const config = loadConfigWithFilteredIntervals( {} );

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 4000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe( 1000 );
	} );

	it( 'allows filters to make active polling intervals faster', () => {
		const config = loadConfigWithFilteredIntervals( {
			'sync.pollingManager.pollingInterval': 1000,
			'sync.pollingManager.pollingIntervalWithCollaborators': 250,
		} );

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 1000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe( 250 );
	} );

	it( 'caps filters that would make active polling intervals slower', () => {
		const config = loadConfigWithFilteredIntervals( {
			'sync.pollingManager.pollingInterval': 10000,
			'sync.pollingManager.pollingIntervalWithCollaborators': 2500,
		} );

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 4000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe( 1000 );
	} );

	it.each( [
		[ 'zero', 0 ],
		[ 'negative', -1 ],
		[ 'non-finite', Infinity ],
		[ 'non-number', '100' ],
	] )(
		'uses default intervals when filters return %s values',
		( _label, filteredValue ) => {
			const config = loadConfigWithFilteredIntervals( {
				'sync.pollingManager.pollingInterval': filteredValue,
				'sync.pollingManager.pollingIntervalWithCollaborators':
					filteredValue,
			} );

			expect( config.POLLING_INTERVAL_IN_MS ).toBe( 4000 );
			expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe(
				1000
			);
		}
	);

	it( 'applies the site polling-interval setting to both active cadences when slower than the solo default', () => {
		const config = loadConfigWithFilteredIntervals(
			{},
			{ httpPollingIntervalMs: 10000 }
		);

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 10000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe(
			10000
		);
	} );

	it( 'keeps the slower solo default when the site setting only slows the collaborating cadence', () => {
		const config = loadConfigWithFilteredIntervals(
			{},
			{ httpPollingIntervalMs: 2000 }
		);

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 4000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe( 2000 );
	} );

	it( 'caps the site setting at the background-tab cadence', () => {
		const config = loadConfigWithFilteredIntervals(
			{},
			{ httpPollingIntervalMs: 60000 }
		);

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 25000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe(
			25000
		);
	} );

	it.each( [
		[ 'zero', 0 ],
		[ 'negative', -500 ],
		[ 'non-finite', NaN ],
		[ 'non-numeric', 'fast' ],
	] )(
		'ignores a %s site setting and keeps the defaults',
		( _label, siteValue ) => {
			const config = loadConfigWithFilteredIntervals(
				{},
				{ httpPollingIntervalMs: siteValue }
			);

			expect( config.POLLING_INTERVAL_IN_MS ).toBe( 4000 );
			expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe(
				1000
			);
		}
	);

	it( 'lets filters lower the intervals below the site setting but not above it', () => {
		const config = loadConfigWithFilteredIntervals(
			{
				'sync.pollingManager.pollingInterval': 5000,
				'sync.pollingManager.pollingIntervalWithCollaborators': 20000,
			},
			{ httpPollingIntervalMs: 10000 }
		);

		expect( config.POLLING_INTERVAL_IN_MS ).toBe( 5000 );
		expect( config.POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS ).toBe(
			10000
		);
	} );
} );
