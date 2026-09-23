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
		 * Whether the Presence API is present and recording.
		 *
		 * With recording off `wp_set_presence()` writes nothing, so a backend
		 * that kept answering would report an empty room to the callers that
		 * decide room lifetime from it.
		 *
		 * @since 0.0.2
		 *
		 * @return bool Whether this backend can serve.
		 */
		public static function is_available(): bool {
			return function_exists( 'wp_get_presence' )
				&& function_exists( 'wp_set_presence' )
				&& function_exists( 'wp_remove_presence' )
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
		 * row, and the Presence API skips an unchanged young row itself.
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
			wp_set_presence( $room, self::CLIENT_PREFIX . $client_id, $state, $user_id );

			return $this->entries( $room, $timeout );
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
