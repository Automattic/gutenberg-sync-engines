<?php
/**
 * Gutenberg_Sync_Engines_Solo_Presence class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Solo_Presence' ) ) {

	/**
	 * The server half of the solo-presence lane: lets an editor tab stop all
	 * sync traffic while its user edits alone, without missing the moment a
	 * second person opens the same post.
	 *
	 * Every editor tab gets a per-tab token, stamped when the editor page
	 * renders and refreshed by a small probe riding the heartbeat WordPress
	 * already sends from every editor screen. The heartbeat answer says
	 * whether any OTHER tab or live sync session is present in the post's
	 * room; the client transports use it to decide when to go quiet and when
	 * to wake (see src/providers/solo-presence.ts).
	 *
	 * Tokens live in a transient keyed by the room, deliberately outside the
	 * sync storage: presence reads must never create a room's storage post
	 * (see the non-creating awareness read below).
	 *
	 * @since 0.1.0
	 */
	final class Gutenberg_Sync_Engines_Solo_Presence {
		/**
		 * Key used in both directions of the heartbeat payload. Mirrors
		 * HEARTBEAT_DATA_KEY in src/providers/solo-presence.ts.
		 *
		 * @since 0.1.0
		 * @var string
		 */
		const HEARTBEAT_KEY = 'gutenberg_sync_engines_presence';

		/**
		 * Transient name prefix; the room hash is appended.
		 *
		 * @since 0.1.0
		 * @var string
		 */
		const TRANSIENT_PREFIX = 'gse_solo_presence_';

		/**
		 * How long a token counts as a live tab, in seconds. Covers a couple
		 * of missed heartbeats (the editor heartbeat runs every 10 seconds;
		 * a hidden tab's heartbeat can slow well beyond that).
		 *
		 * @since 0.1.0
		 * @var int
		 */
		const PRESENCE_TTL = 90;

		/**
		 * How long the transient itself lives past the last write.
		 *
		 * @since 0.1.0
		 * @var int
		 */
		const TRANSIENT_EXPIRY = 300;

		/**
		 * Cap on stored tokens per room, to bound the transient's size.
		 *
		 * @since 0.1.0
		 * @var int
		 */
		const MAX_TOKENS_PER_ROOM = 50;

		/**
		 * Live-awareness window, in seconds. Mirrors the sync transports'
		 * AWARENESS_TIMEOUT: entries older than this count as disconnected.
		 *
		 * @since 0.1.0
		 * @var int
		 */
		const AWARENESS_TIMEOUT = 30;

		/**
		 * Hooks the heartbeat filter.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function register(): void {
			// The heartbeat can fire from any admin page, so the filter is
			// registered globally; the probe itself names the room.
			add_filter( 'heartbeat_received', array( $this, 'answer_heartbeat' ), 10, 2 );
		}

		/**
		 * Builds the client settings for a post's editor page: mints this
		 * tab's token, records it, and answers whether anyone else is
		 * already around. Returns null when the lane is disabled or the user
		 * cannot edit the post.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_Post $post The post being edited.
		 * @return array|null Settings for the client, or null.
		 */
		public function editor_settings( WP_Post $post ): ?array {
			/**
			 * Filters whether editor tabs may stop sync traffic while their
			 * user edits alone. Return false to keep the always-on cadence.
			 *
			 * @since 0.1.0
			 *
			 * @param bool    $enabled Whether the quiet-while-alone lane is on.
			 * @param WP_Post $post    The post being edited.
			 */
			if ( ! apply_filters( 'gutenberg_sync_engines_solo_quiet_enabled', true, $post ) ) {
				return null;
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return null;
			}

			$room  = 'postType/' . $post->post_type . ':' . $post->ID;
			$token = wp_generate_uuid4();

			// Stamp this tab before computing the answer, so a second tab
			// loading a moment later sees this one immediately — before this
			// tab's first heartbeat.
			$this->record_token( $room, $token );

			return array(
				'room'          => $room,
				'token'         => $token,
				'othersPresent' => $this->others_present( $room, $token, 0 ),
			);
		}

		/**
		 * Answers a heartbeat probe: refreshes the tab's token and reports
		 * whether any other tab or live sync session is in the room.
		 *
		 * @since 0.1.0
		 *
		 * @param mixed $response The heartbeat response being built.
		 * @param mixed $data     The data the client sent.
		 * @return mixed The response, with this lane's answer added.
		 */
		public function answer_heartbeat( $response, $data ) {
			if ( ! is_array( $response ) ) {
				$response = array();
			}
			if ( ! is_array( $data ) || ! isset( $data[ self::HEARTBEAT_KEY ] ) ) {
				return $response;
			}

			$probe = $data[ self::HEARTBEAT_KEY ];
			if ( ! is_array( $probe ) || empty( $probe['room'] ) || empty( $probe['token'] ) ) {
				return $response;
			}

			$room  = (string) $probe['room'];
			$token = (string) $probe['token'];
			if ( strlen( $token ) > 64 || ! $this->can_probe_room( $room ) ) {
				return $response;
			}

			$this->record_token( $room, $token );

			$client_id = isset( $probe['client_id'] ) ? absint( $probe['client_id'] ) : 0;

			$response[ self::HEARTBEAT_KEY ] = array(
				'others' => $this->others_present( $room, $token, $client_id ),
			);

			return $response;
		}

		/**
		 * Whether the current user may ask about a room. Only per-post
		 * entity rooms are probed, and only by users who can edit the post —
		 * the same gate the sync transports apply.
		 *
		 * @since 0.1.0
		 *
		 * @param string $room The room name.
		 * @return bool Whether the probe is allowed.
		 */
		private function can_probe_room( string $room ): bool {
			if ( ! preg_match( '#^postType/[^/:]+:(\d+)$#', $room, $matches ) ) {
				return false;
			}

			$post_id = (int) $matches[1];

			return $post_id > 0 && current_user_can( 'edit_post', $post_id );
		}

		/**
		 * Records (or refreshes) a tab's token for a room and prunes stale
		 * entries. A lost write under concurrent heartbeats only delays one
		 * tab's refresh by a cycle, which the TTL absorbs.
		 *
		 * @since 0.1.0
		 *
		 * @param string $room  The room name.
		 * @param string $token The tab's token.
		 * @return void
		 */
		private function record_token( string $room, string $token ): void {
			$entries = $this->read_tokens( $room );

			$entries[ $token ] = array(
				'user' => get_current_user_id(),
				't'    => time(),
			);

			if ( count( $entries ) > self::MAX_TOKENS_PER_ROOM ) {
				// Oldest first, keep the newest MAX_TOKENS_PER_ROOM.
				uasort(
					$entries,
					static function ( $a, $b ) {
						return $a['t'] <=> $b['t'];
					}
				);
				$entries = array_slice( $entries, -self::MAX_TOKENS_PER_ROOM, null, true );
			}

			set_transient( self::TRANSIENT_PREFIX . md5( $room ), $entries, self::TRANSIENT_EXPIRY );
		}

		/**
		 * Reads a room's tokens, dropping entries past the TTL.
		 *
		 * @since 0.1.0
		 *
		 * @param string $room The room name.
		 * @return array Fresh entries keyed by token.
		 */
		private function read_tokens( string $room ): array {
			$entries = get_transient( self::TRANSIENT_PREFIX . md5( $room ) );
			if ( ! is_array( $entries ) ) {
				return array();
			}

			$now = time();
			foreach ( $entries as $token => $entry ) {
				$stamped = isset( $entry['t'] ) ? (int) $entry['t'] : 0;
				if ( $now - $stamped >= self::PRESENCE_TTL ) {
					unset( $entries[ $token ] );
				}
			}

			return $entries;
		}

		/**
		 * Whether anyone besides this tab is in the room: another tab's
		 * fresh token, or a live sync awareness entry from another client.
		 *
		 * @since 0.1.0
		 *
		 * @param string $room      The room name.
		 * @param string $token     This tab's token, excluded from the count.
		 * @param int    $client_id This tab's sync client id (0 when no
		 *                          session has run), excluded from the count.
		 * @return bool Whether another participant is present.
		 */
		private function others_present( string $room, string $token, int $client_id ): bool {
			$entries = $this->read_tokens( $room );
			unset( $entries[ $token ] );
			if ( count( $entries ) > 0 ) {
				return true;
			}

			return $this->has_live_awareness_besides( $room, $client_id );
		}

		/**
		 * Non-creating live-awareness read: whether the room's sync storage
		 * holds a fresh awareness entry from a client other than the given
		 * one.
		 *
		 * The storage API's own room lookup CREATES the room's storage post
		 * (its callers are about to write), and its awareness writes bypass
		 * the meta cache — so this reads both the post and the meta row
		 * directly. A missing storage post simply means nobody has synced.
		 *
		 * @since 0.1.0
		 *
		 * @param string $room      The room name.
		 * @param int    $client_id Sync client id to exclude (0 excludes none).
		 * @return bool Whether another client's awareness is live.
		 */
		private function has_live_awareness_besides( string $room, int $client_id ): bool {
			global $wpdb;

			$storage_post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s LIMIT 1",
					md5( $room ),
					'wp_sync_storage'
				)
			);
			if ( ! $storage_post_id ) {
				return false;
			}

			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
					(int) $storage_post_id,
					'wp_sync_awareness_state'
				)
			);
			if ( ! $raw ) {
				return false;
			}

			$entries = json_decode( $raw, true );
			if ( ! is_array( $entries ) ) {
				return false;
			}

			$now = time();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$updated_at      = isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0;
				$entry_client    = isset( $entry['client_id'] ) ? (int) $entry['client_id'] : 0;
				$is_live         = $now - $updated_at < self::AWARENESS_TIMEOUT;
				$is_someone_else = 0 === $client_id || $entry_client !== $client_id;
				if ( $is_live && $is_someone_else ) {
					return true;
				}
			}

			return false;
		}
	}
}
