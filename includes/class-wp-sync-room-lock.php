<?php
/**
 * Core-style advisory room lock.
 *
 * @package gutenberg-sync-engines
 */

if ( ! class_exists( 'WP_Sync_Room_Lock' ) ) {
	/**
	 * A per-room advisory lock built the way WordPress Core builds locks.
	 *
	 * The primitive is the WP_Upgrader::create_lock() pattern: an atomic
	 * `INSERT IGNORE` of an options row claims the lock, a TTL lets a
	 * crashed holder's claim expire, and release deletes the row. This
	 * replaces MySQL GET_LOCK deliberately: GET_LOCK is connection-scoped
	 * and quietly loses mutual exclusion under connection
	 * pooling/multiplexing, under
	 * read/write-splitting drop-ins (`SELECT GET_LOCK(...)` pattern-matches
	 * as a read and can land on a replica), on multi-primary clusters (user
	 * locks are node-local), and on SQLite builds (the function does not
	 * exist). Options-row INSERTs are writes, so every topology routes them
	 * to the primary, and the SQLite integration supports INSERT IGNORE
	 * (Core's upgrader lock depends on it).
	 *
	 * All access is direct $wpdb — never get_option()/update_option() — so
	 * external object caches cannot serve a stale view of the lock row.
	 */
	class WP_Sync_Room_Lock {

		/**
		 * Seconds after which a held lock is treated as abandoned. Holders
		 * do millisecond-scale work; this only covers a crashed worker.
		 */
		const TTL_SECONDS = 30;

		/**
		 * Base milliseconds to sleep between claim attempts (jitter added).
		 * Holders do millisecond-scale work, so the spin must be fine-grained
		 * or waiters pay far more than the actual hold time; each probe is a
		 * primary-key options lookup, cheap even at this cadence.
		 */
		const RETRY_SLEEP_MS = 5;

		/**
		 * Attempts to acquire the named lock within the wait budget.
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $name         Lock option name.
		 * @param float  $wait_seconds Wait budget in seconds; 0 means one
		 *                             non-blocking attempt.
		 * @return string|WP_Error Holder token on success, retryable
		 *                         rest_sync_room_busy (503) otherwise.
		 */
		public static function acquire( string $name, float $wait_seconds = 5.0 ) {
			global $wpdb;

			$token    = sprintf( '%.6F:%s', microtime( true ), wp_generate_password( 12, false ) );
			$deadline = microtime( true ) + $wait_seconds;

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotSimple -- Atomic lock claim; deliberately bypasses the options cache.
				$claimed = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* LOCK */", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotSimple
						$name,
						$token
					)
				);
				if ( $claimed ) {
					return $token;
				}

				/*
				 * Held. Expire an abandoned holder by compare-and-swap so two
				 * waiters cannot both take the same stale lock over.
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotSimple -- Deliberately bypasses the options cache.
				$existing = $wpdb->get_var(
					$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotSimple
				);
				if ( is_string( $existing ) && '' !== $existing && microtime( true ) - (float) $existing > self::TTL_SECONDS ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotSimple -- CAS takeover of an abandoned lock.
					$taken = $wpdb->query(
						$wpdb->prepare( "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $existing ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotSimple
					);
					if ( $taken ) {
						return $token;
					}
				}

				if ( microtime( true ) >= $deadline ) {
					break;
				}
				usleep( 1000 * ( self::RETRY_SLEEP_MS + wp_rand( 0, self::RETRY_SLEEP_MS ) ) );
			} while ( true );

			return new WP_Error(
				'rest_sync_room_busy',
				__( 'The room is busy processing another request. Retry shortly.', 'gutenberg' ),
				array( 'status' => 503 )
			);
		}

		/**
		 * Releases a held lock. Token-checked: a lock taken over after this
		 * holder's TTL expiry is not disturbed.
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $name  Lock option name.
		 * @param string $token Token returned by acquire().
		 * @return void
		 */
		public static function release( string $name, string $token ): void {
			global $wpdb;

			if ( '' === $token ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotSimple -- Token-checked lock release.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", $name, $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotSimple
			);
		}
	}
}
