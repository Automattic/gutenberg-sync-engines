<?php
/**
 * WP_Sync_Presence_API_Awareness_Backend class
 *
 * @package gutenberg-sync-engines
 */

if ( ! class_exists( 'WP_Sync_Presence_API_Awareness_Backend' ) ) {

	/**
	 * Holds awareness in the Presence API plugin's shared `wp_presence`
	 * table instead of this plugin's room array.
	 *
	 * Why defer to it rather than keep our own store:
	 *
	 * - One row per client, upserted on a unique (room, client_id), so two
	 *   clients writing in the same instant cannot drop each other.
	 * - A table with a TTL behaves the same on every host, where the room
	 *   array lives only in the object cache a site may not have (P5).
	 * - Both sides already speak `postType/{type}:{id}`, so no room mapping.
	 * - Presence API surfaces the same rows in Who's Online and the post
	 *   list, so a collaborator in the editor is visible outside it.
	 *
	 * Presence API writes `user-{id}` and `editor-{id}` rows of its own, so
	 * this backend writes and reads `gse-{id}` and ignores every other row.
	 *
	 * @since 0.0.2
	 */
	final class WP_Sync_Presence_API_Awareness_Backend implements WP_Sync_Awareness_Backend {
		/**
		 * The client id prefix this plugin's rows carry.
		 *
		 * @since 0.0.2
		 * @var string
		 */
		const CLIENT_PREFIX = 'gse-';

		/**
		 * How much of the caller's window an entry may spend unwritten.
		 *
		 * A third of it, so two refreshes are missed before anyone counts
		 * the client as gone. This matches the built-in store, which rewrites
		 * a room on every 10-second timestamp bucket inside the same
		 * 30-second window.
		 *
		 * @since 0.0.2
		 * @var int
		 */
		const REFRESH_FRACTION = 3;

		/**
		 * Whether the Presence API is present, has its table, and is recording.
		 *
		 * All three matter. With recording off `wp_set_presence()` writes
		 * nothing, and without the table every read comes back empty and
		 * every write fails; a backend that kept answering in either case
		 * would report an empty room forever, to the avatars in the editor
		 * and to the callers that decide a room's lifetime from it. The room
		 * array works in both cases, so stand down and let it serve.
		 *
		 * `wp_presence_has_table()` is private to that plugin, so its absence
		 * is not treated as a failure: an older or newer Presence API without
		 * it still passes this gate on its public functions alone.
		 *
		 * @since 0.0.2
		 *
		 * @return bool Whether this backend can serve.
		 */
		public static function is_available(): bool {
			return function_exists( 'wp_get_presence' )
				&& function_exists( 'wp_set_presence' )
				&& function_exists( 'wp_remove_presence' )
				&& ( ! function_exists( 'wp_presence_has_table' ) || wp_presence_has_table() )
				&& ( ! function_exists( 'wp_presence_recording_enabled' ) || wp_presence_recording_enabled() );
		}

		/**
		 * Every live entry in a room.
		 *
		 * A site's `wp_presence_default_ttl` filter can return anything, so
		 * the entries are aged again here against the caller's own window.
		 *
		 * @since 0.0.2
		 *
		 * @param string $room    Room identifier.
		 * @param int    $timeout Age in seconds past which an entry is gone.
		 * @return array<int, array<string, mixed>> Entries, lowest client id first.
		 */
		public function entries( string $room, int $timeout ): array {
			$now     = time();
			$entries = array();

			foreach ( wp_get_presence( $room, $timeout ) as $row ) {
				if ( ! str_starts_with( (string) $row->client_id, self::CLIENT_PREFIX ) ) {
					continue;
				}

				$updated_at = (int) strtotime( $row->date_gmt . ' UTC' );
				if ( $now - $updated_at >= $timeout ) {
					continue;
				}

				$entries[] = array(
					// Columns come back as strings; client ids compare strictly.
					'client_id'  => (int) substr( (string) $row->client_id, strlen( self::CLIENT_PREFIX ) ),
					'state'      => is_array( $row->data ) ? $row->data : array(),
					'updated_at' => $updated_at,
					'wp_user_id' => (int) $row->user_id,
				);
			}

			return self::sorted( $entries );
		}

		/**
		 * Entries in client id order, the order every caller expects.
		 *
		 * @since 0.0.2
		 *
		 * @param array<int, array<string, mixed>> $entries Entries to order.
		 * @return array<int, array<string, mixed>> The same entries, ordered.
		 */
		private static function sorted( array $entries ): array {
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return $a['client_id'] <=> $b['client_id'];
				}
			);

			return $entries;
		}

		/**
		 * Records one client's awareness state.
		 *
		 * One upsert, so a client writing here never rewrites anyone else's
		 * row.
		 *
		 * Whether the write is needed at all is decided HERE rather than left
		 * to the Presence API, and the row is stamped with an explicit
		 * timestamp, which is what turns that plugin's own skip off. Its skip
		 * is measured against its own 150-second lifetime, so it can leave an
		 * unchanged row unwritten for far longer than the caller's window
		 * here, which is 30 seconds. The client is still sitting in the
		 * editor, but its row ages past the window and everyone else stops
		 * seeing it; the WebSocket sweep, which re-records exactly so a quiet
		 * socket is not expired, would announce it as gone. Raising
		 * `wp_presence_default_ttl` widens that gap without limit.
		 *
		 * So: refresh once the entry has spent a third of the window
		 * unwritten, and skip otherwise, which keeps an idle poll read-only
		 * the way the room array does.
		 *
		 * @since 0.0.2
		 *
		 * @param string               $room      Room identifier.
		 * @param int                  $client_id The client's sync id.
		 * @param array<string, mixed> $state     The state to store.
		 * @param int                  $user_id   The WordPress user behind it.
		 * @param int                  $timeout   Age in seconds past which an
		 *                                        entry is gone.
		 * @return array<int, array<string, mixed>> The room's live entries.
		 */
		public function put( string $room, int $client_id, array $state, int $user_id, int $timeout ): array {
			$now     = time();
			$entries = $this->entries( $room, $timeout );
			$refresh = max( 1, intdiv( $timeout, self::REFRESH_FRACTION ) );

			foreach ( $entries as $index => $entry ) {
				if ( $entry['client_id'] !== $client_id ) {
					continue;
				}

				// Comparing the encoded state, as the Presence API does: what
				// comes back has been through JSON and is not identical to
				// what went in.
				if ( $now - $entry['updated_at'] < $refresh
					&& $entry['wp_user_id'] === $user_id
					&& wp_json_encode( $entry['state'] ) === wp_json_encode( $state )
				) {
					return $entries;
				}

				unset( $entries[ $index ] );
				break;
			}

			wp_set_presence( $room, self::CLIENT_PREFIX . $client_id, $state, $user_id, gmdate( 'Y-m-d H:i:s', $now ) );

			// The room as it now stands, without a second read: the entries
			// just read, with this client's own entry as written.
			$entries[] = array(
				'client_id'  => $client_id,
				'state'      => $state,
				'updated_at' => $now,
				'wp_user_id' => $user_id,
			);

			return self::sorted( $entries );
		}

		/**
		 * Removes one client's entry.
		 *
		 * @since 0.0.2
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id The client's sync id.
		 * @param int    $timeout   Age in seconds past which an entry is gone.
		 * @return array<int, array<string, mixed>> The room's live entries.
		 */
		public function forget( string $room, int $client_id, int $timeout ): array {
			wp_remove_presence( $room, self::CLIENT_PREFIX . $client_id );

			return $this->entries( $room, $timeout );
		}
	}
}
