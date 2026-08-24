<?php
/**
 * WP_Sync_CAS_Backend interface
 *
 * @package gutenberg-sync-engines
 */

if ( ! interface_exists( 'WP_Sync_CAS_Backend' ) ) {
	/**
	 * A substitute compare-and-swap implementation (e.g. Redis), plugged
	 * in via the `wp_sync_cas_backend` filter. The contract — each clause
	 * is load-bearing:
	 *
	 * - `swap` and `swap_prefixed` must be ATOMIC compare-and-set
	 *   operations (on Redis, `swap_prefixed` needs a Lua script: read,
	 *   check the prefix, set — as one step). Reads are never served from
	 *   a stale cache.
	 * - Seeding semantics differ and matter: `swap` seeds a missing row
	 *   with the EXPECTED value (pre-existing state starts claimable at
	 *   its current value); `swap_prefixed` seeds with the NEXT value
	 *   (the first chained write of state that predates the chain).
	 * - Values can be large — de-rtc's chained canonical row carries the
	 *   whole document. A backend with small item limits or eviction
	 *   under memory pressure (memcached) must NOT hold these rows;
	 *   losing one silently discards canonical content.
	 * - `reset` (delete + seed) must be idempotent under racing
	 *   re-seeders writing the same value.
	 */
	interface WP_Sync_CAS_Backend {
		/**
		 * Atomically swaps from an expected value to the next one.
		 *
		 * @param string $name     Row name.
		 * @param string $expected Value the caller believes is current.
		 * @param string $next     Replacement value.
		 * @return bool Whether this caller performed the swap.
		 */
		public function swap( string $name, string $expected, string $next ): bool;

		/**
		 * Atomically swaps when the current value has the expected prefix.
		 *
		 * @param string $name            Row name.
		 * @param string $expected_prefix Prefix the current value must have.
		 * @param string $next            Replacement value.
		 * @return bool Whether this caller performed the swap (or seed).
		 */
		public function swap_prefixed( string $name, string $expected_prefix, string $next ): bool;

		/**
		 * Reads the current raw value, uncached.
		 *
		 * @param string $name Row name.
		 * @return string|null Current value, or null when absent.
		 */
		public function read( string $name ): ?string;

		/**
		 * Unconditionally re-seeds the row (delete + insert).
		 *
		 * @param string $name  Row name.
		 * @param string $value Fresh value.
		 * @return void
		 */
		public function reset( string $name, string $value ): void;
	}
}
