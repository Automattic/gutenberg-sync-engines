/**
 * TEMPORARY: demo tooling that makes short-polling syncs land on a fixed
 * wall-clock grid, so a recorded two-browser session shows the two windows
 * syncing in a known order.
 *
 * Every automatic poll fires on a 10-second grid. User ID 1 fires at :00
 * and again at :02 (:10 and :12, :20 and :22, and so on); user ID 2 fires
 * at :01 (:11, :21); everyone else fires at :00 only. So with user 1 on the
 * left and user 2 on the right: the left browser sends its edits, one
 * second later the right browser sends its own and receives the left
 * browser's (and any conflict), and one second after that the left browser
 * receives the same. A conflict made inside one 10-second span shows in
 * both windows within two seconds of the grid moment. The initial poll on
 * join and any manual retry still run immediately.
 *
 * The current user's ID comes from the plugin's inline settings script
 * (`window._gutenbergSyncEnginesSettings.currentUserId`, see
 * Gutenberg_Sync_Engines_Plugin::enqueue_editor_assets()).
 *
 * Delete this file, its import in src/index.ts, and the `currentUserId`
 * setting once predictable timing is no longer wanted. The mechanism it
 * drives (`setClockAlignedPolling`) can stay.
 */

/**
 * Internal dependencies
 */
import { setClockAlignedPolling } from '../providers/http-polling/polling-manager';

const PERIOD_SECONDS = 10;
const DEFAULT_OFFSETS_SECONDS = [ 0 ];

// User ID => the seconds within each 10-second span at which that user's
// window syncs.
const USER_OFFSETS_SECONDS: Record< number, number[] > = {
	1: [ 0, 2 ],
	2: [ 1 ],
};

/**
 * Seconds within each grid period at which the given user's window syncs.
 *
 * @param userId The current user's ID (0 or undefined when logged out).
 * @return Offsets in seconds.
 */
export function getSyncOffsetsSeconds( userId: number | undefined ): number[] {
	if ( userId === undefined ) {
		return DEFAULT_OFFSETS_SECONDS;
	}

	return USER_OFFSETS_SECONDS[ userId ] ?? DEFAULT_OFFSETS_SECONDS;
}

function getCurrentUserId(): number | undefined {
	const settings = (
		window as {
			_gutenbergSyncEnginesSettings?: { currentUserId?: number };
		}
	 )._gutenbergSyncEnginesSettings;
	const value = Number( settings?.currentUserId );

	if ( ! Number.isInteger( value ) || value <= 0 ) {
		return undefined;
	}

	return value;
}

/**
 * Aligns automatic short-polling syncs to the wall-clock grid described above.
 */
export function alignSyncToClock(): void {
	setClockAlignedPolling( {
		periodMs: PERIOD_SECONDS * 1000,
		offsetsMs: getSyncOffsetsSeconds( getCurrentUserId() ).map(
			( seconds ) => seconds * 1000
		),
	} );
}
