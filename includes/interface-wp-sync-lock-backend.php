<?php
/**
 * WP_Sync_Lock_Backend interface
 *
 * @package gutenberg-sync-engines
 */

if ( ! interface_exists( 'WP_Sync_Lock_Backend' ) ) {
	/**
	 * A substitute room-lock implementation (e.g. memcached, Redis),
	 * plugged in via the `wp_sync_lock_backend` filter. The contract a
	 * backend must uphold — each clause is load-bearing:
	 *
	 * - Mutual exclusion across web servers AND database connections
	 *   (connection-scoped locks like MySQL GET_LOCK fail this; see the
	 *   class docblock below).
	 * - A crashed holder's claim must expire (TTL), and the takeover must
	 *   be compare-and-swap-guarded so two waiters can never both steal
	 *   the same expired lock.
	 * - Release is token-checked: releasing with a stale token must not
	 *   disturb a lock someone else took over.
	 * - Probes must not be served from a stale cache, and should stay in
	 *   the low milliseconds — holders do millisecond-scale work.
	 */
	interface WP_Sync_Lock_Backend {
		/**
		 * Attempts to acquire the named lock within the wait budget.
		 *
		 * @param string $name         Lock name.
		 * @param float  $wait_seconds Wait budget in seconds; 0 means one
		 *                             non-blocking attempt.
		 * @return string|WP_Error Holder token on success, retryable
		 *                         rest_sync_room_busy (503) otherwise.
		 */
		public function acquire( string $name, float $wait_seconds );

		/**
		 * Releases a held lock. Must be token-checked.
		 *
		 * @param string $name  Lock name.
		 * @param string $token Token returned by acquire().
		 * @return void
		 */
		public function release( string $name, string $token ): void;
	}
}
