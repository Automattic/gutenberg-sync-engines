/**
 * Freshness rules.
 *
 * Two clocks are involved and they are kept apart on purpose:
 *
 * - The SENDER's clock decides what a stripe looks like: every trail entry
 *   carries the age of the last interaction at the moment the beacon was
 *   built, and the receiver turns that age into an opacity when the beacon
 *   arrives. Nothing changes between beacons, so stripes never fade on
 *   their own.
 * - The RECEIVER's clock only decides when a silent peer is dropped
 *   entirely, as a safety net for a tab that vanished without a goodbye.
 */

/**
 * Internal dependencies
 */
import { TRAIL_HALF_MS, TRAIL_WINDOW_MS } from './types';
import type { PeerStatus } from './types';

/** A peer with no beacon for this many of its intervals is dropped... */
const EXPIRE_INTERVALS = 4;
/** ...but never sooner than this, so a backgrounded tab's slow polls hold. */
const EXPIRE_MIN_MS = 60_000;

/**
 * The stripe strength for a trail entry: full under 15 s, half under
 * 30 s, gone after that.
 *
 * @param ageMs Age of the last interaction, as reported by the sender.
 * @return 1, 0.5, or 0.
 */
export function trailOpacity( ageMs: number ): number {
	if ( ageMs < TRAIL_HALF_MS ) {
		return 1;
	}
	if ( ageMs < TRAIL_WINDOW_MS ) {
		return 0.5;
	}
	return 0;
}

/**
 * Whether a peer's last beacon is still worth showing.
 *
 * @param receivedAt When the beacon arrived (receiver clock, ms).
 * @param intervalMs The sender's cadence.
 * @param now        The receiver's clock now.
 * @return The status.
 */
export function getPeerStatus(
	receivedAt: number,
	intervalMs: number,
	now: number
): PeerStatus {
	const age = Math.max( 0, now - receivedAt );
	const limit = Math.max( EXPIRE_MIN_MS, intervalMs * EXPIRE_INTERVALS );
	return age <= limit ? 'active' : 'expired';
}

/**
 * Whole seconds of age, floored, never negative.
 *
 * @param receivedAt When the beacon arrived.
 * @param now        Now.
 * @return Seconds.
 */
export function ageInSeconds( receivedAt: number, now: number ): number {
	return Math.max( 0, Math.floor( ( now - receivedAt ) / 1000 ) );
}

/**
 * Whole seconds until the peer's next beacon is due (0 when overdue).
 *
 * @param receivedAt When the last beacon arrived.
 * @param intervalMs The sender's cadence.
 * @param now        Now.
 * @return Seconds.
 */
export function secondsUntilNextBeacon(
	receivedAt: number,
	intervalMs: number,
	now: number
): number {
	return Math.max( 0, Math.ceil( ( receivedAt + intervalMs - now ) / 1000 ) );
}
