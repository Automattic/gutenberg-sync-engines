/**
 * Bootstraps high-latency awareness when the site has turned it on.
 *
 * The server passes the mode in `window._gutenbergSyncEnginesSettings`:
 * `awarenessIntervalMs` (0 keeps the framework's realtime awareness) and
 * `awarenessChannel` (`sync` rides the sync transport's awareness envelope;
 * `heartbeat` rides WordPress Heartbeat, a separate transport).
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { startSlowAwareness } from './controller';
import { registerAwarenessStore } from './store';
import type { SlowAwarenessSettings } from './types';
import { ActivityPanel } from './ui/activity-panel';
import { registerBlockIndicator } from './ui/block-indicator';
import { PhantomLayer } from './ui/phantom-layer';

/**
 * Reads the settings the server injected.
 *
 * @return The settings; `intervalMs` is 0 when the mode is off.
 */
export function readSlowAwarenessSettings(): SlowAwarenessSettings {
	const settings = (
		window as {
			_gutenbergSyncEnginesSettings?: {
				awarenessIntervalMs?: number;
				awarenessChannel?: string;
			};
		}
	 )._gutenbergSyncEnginesSettings;
	const intervalMs = Number( settings?.awarenessIntervalMs ?? 0 );
	return {
		intervalMs:
			Number.isFinite( intervalMs ) && intervalMs > 0 ? intervalMs : 0,
		channel:
			'heartbeat' === settings?.awarenessChannel ? 'heartbeat' : 'sync',
	};
}

function AwarenessRoot() {
	return createElement(
		'div',
		null,
		createElement( ActivityPanel ),
		createElement( PhantomLayer )
	);
}

/**
 * Turns the mode on for this editor page when configured.
 */
export function bootstrapSlowAwareness(): void {
	const settings = readSlowAwarenessSettings();
	if ( ! settings.intervalMs ) {
		return;
	}
	registerAwarenessStore();
	registerBlockIndicator();
	registerPlugin( 'gutenberg-sync-engines-awareness', {
		render: AwarenessRoot,
	} );
	startSlowAwareness( settings );
}
