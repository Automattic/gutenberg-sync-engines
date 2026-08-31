/**
 * Manual sync button (demo tooling for the http-polling transport).
 *
 * While the button is mounted, automatic short polling is held: nothing moves
 * over the wire until the user clicks "Sync", which runs exactly one poll
 * cycle (send everything queued, receive everything pending). This makes
 * conflict demos reproducible: edit in two windows, then click Sync in each
 * window in the order the story needs.
 *
 * The button renders in the editor header's pinned-items cluster, immediately
 * left of the block/post settings toggle. It shows the number of queued
 * outgoing updates so the presenter can see when a just-typed edit has
 * reached the outbox (the intent-log engine captures typing bursts on a
 * short delay).
 */

/**
 * WordPress dependencies
 */
import { Button, Fill } from '@wordpress/components';
import { useEffect, useSyncExternalStore } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import {
	getQueuedUpdateCount,
	isSyncInFlight,
	setManualSyncMode,
	subscribeManualSync,
	syncNow,
} from '../providers/http-polling/polling-manager';

function ManualSyncButton() {
	/*
	 * Read the transport gate at render time, not module-load time: the
	 * announcement is an inline script on wp-core-data, and this bundle is
	 * not guaranteed to load after it. By the time the editor renders
	 * plugins, the announcement is set. The server lists the active
	 * transport first.
	 */
	const isShortPolling =
		'http-polling' === window._wpCollaborationSync?.transports?.[ 0 ];

	const queued = useSyncExternalStore(
		subscribeManualSync,
		getQueuedUpdateCount
	);
	const inFlight = useSyncExternalStore(
		subscribeManualSync,
		isSyncInFlight
	);

	// Hold automatic polling for as long as the button is on screen.
	useEffect( () => {
		if ( ! isShortPolling ) {
			return;
		}

		setManualSyncMode( true );

		return () => {
			setManualSyncMode( false );
		};
	}, [ isShortPolling ] );

	if ( ! isShortPolling ) {
		return null;
	}

	let label: string = __( 'Sync', 'gutenberg-sync-engines' );
	if ( inFlight ) {
		label = __( 'Syncing…', 'gutenberg-sync-engines' );
	} else if ( queued > 0 ) {
		label = sprintf(
			/* translators: %d: number of queued sync updates. */
			__( 'Sync (%d)', 'gutenberg-sync-engines' ),
			queued
		);
	}

	return (
		<Fill name="PinnedItems/core">
			<Button
				variant="secondary"
				size="compact"
				isBusy={ inFlight }
				onClick={ () => syncNow() }
				// Keep the button leftmost in the pinned cluster, before the
				// block/post settings toggle.
				style={ { order: -1 } }
			>
				{ label }
			</Button>
		</Fill>
	);
}

/**
 * Registers the manual sync button. The component itself renders nothing
 * unless the active transport is short polling, so other transports keep
 * their normal cadence.
 */
export function registerManualSyncButton(): void {
	registerPlugin( 'gutenberg-sync-engines-manual-sync', {
		render: ManualSyncButton,
	} );
}
