<?php
/**
 * Gutenberg_Sync_Engines_Heartbeat_Awareness class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Heartbeat_Awareness' ) ) {

	/**
	 * Carries the slow awareness beacon over WordPress Heartbeat, a request
	 * stream separate from the sync transport.
	 *
	 * With every Heartbeat request an editor tab sends the identity of the
	 * block its selection is in (or null). The server keeps one entry per
	 * client in a transient per post and answers with every other live
	 * client's entry, stamped with that client's user (name and avatar), so
	 * the receiver needs nothing else to draw the indicator. The server
	 * never interprets the block identity beyond a length cap, and binds
	 * each client id to the user that first used it.
	 *
	 * This is a prototype: the transient read-modify-write is not atomic,
	 * so two clients writing in the same instant can drop one another's
	 * entry for one tick. A durable version belongs in the plugin's room
	 * storage, where the sync transport already merges awareness.
	 *
	 * @since n.e.x.t
	 */
	final class Gutenberg_Sync_Engines_Heartbeat_Awareness {
		/**
		 * The Heartbeat data key, on both the request and the response.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const KEY = 'gutenberg_sync_engines_awareness';

		/**
		 * Longest accepted block identity, in bytes. Sync ids and editor
		 * client ids are both short (36-byte UUIDs); anything longer is not
		 * a block name this plugin minted.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const MAX_BLOCK_BYTES = 128;

		/**
		 * Hooks Heartbeat.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function register(): void {
			add_filter( 'heartbeat_received', array( $this, 'handle' ), 10, 2 );
			add_filter( 'heartbeat_settings', array( $this, 'filter_settings' ) );
		}

		/**
		 * The configured awareness interval in seconds (0 = mode off).
		 *
		 * @since n.e.x.t
		 *
		 * @return int Seconds.
		 */
		public static function interval_seconds(): int {
			if ( ! class_exists( 'Gutenberg_Sync_Engines_Settings' ) ) {
				return 0;
			}
			return Gutenberg_Sync_Engines_Settings::awareness_interval();
		}

		/**
		 * How long an entry lives without a refresh, in seconds: four
		 * intervals, never less than a minute (a hidden tab's Heartbeat
		 * slows down).
		 *
		 * @since n.e.x.t
		 *
		 * @return int Seconds.
		 */
		public static function ttl_seconds(): int {
			return max( 60, 4 * self::interval_seconds() );
		}

		/**
		 * Whether the Heartbeat channel is the configured one.
		 *
		 * @since n.e.x.t
		 *
		 * @return bool True when awareness rides Heartbeat.
		 */
		public static function is_active(): bool {
			return self::interval_seconds() > 0
				&& class_exists( 'Gutenberg_Sync_Engines_Settings' )
				&& Gutenberg_Sync_Engines_Settings::AWARENESS_CHANNEL_HEARTBEAT === Gutenberg_Sync_Engines_Settings::awareness_channel();
		}

		/**
		 * Sets Heartbeat's interval to the awareness cadence on the post
		 * editor screens, so beacons move at the configured pace. Note
		 * that the advisory channel's discovery probe rides the same beat,
		 * so its cadence changes too. Heartbeat clamps to 1-3600 seconds.
		 *
		 * @since n.e.x.t
		 *
		 * @param array $settings Heartbeat settings.
		 * @return array Settings with the interval applied.
		 */
		public function filter_settings( $settings ): array {
			$settings = is_array( $settings ) ? $settings : array();
			if ( ! self::is_active() ) {
				return $settings;
			}
			global $pagenow;
			if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
				return $settings;
			}
			$settings['interval'] = max( 1, min( 3600, self::interval_seconds() ) );
			return $settings;
		}

		/**
		 * Stores the sender's block and returns the other live peers'.
		 *
		 * Request shape under KEY: `{ post_id, client_id, block }` where
		 * `block` is a string or null. Response shape under KEY:
		 * `{ peers: [ { client_id, user: { id, name, avatar }, block } ] }`.
		 *
		 * @since n.e.x.t
		 *
		 * @param array $response Heartbeat response so far.
		 * @param array $data     Heartbeat request data.
		 * @return array Response including this plugin's peers list.
		 */
		public function handle( $response, $data ): array {
			$response = is_array( $response ) ? $response : array();
			if ( ! is_array( $data ) || empty( $data[ self::KEY ] ) || ! is_array( $data[ self::KEY ] ) ) {
				return $response;
			}
			$request   = $data[ self::KEY ];
			$post_id   = isset( $request['post_id'] ) ? (int) $request['post_id'] : 0;
			$client_id = isset( $request['client_id'] ) ? (int) $request['client_id'] : 0;
			$block     = self::sanitize_block( $request['block'] ?? null );
			$user_id   = get_current_user_id();

			if ( $post_id <= 0 || $client_id <= 0 || ! $user_id || ! current_user_can( 'edit_post', $post_id ) ) {
				return $response;
			}

			$ttl     = self::ttl_seconds();
			$now     = time();
			$key     = self::transient_key( $post_id );
			$entries = get_transient( $key );
			$entries = is_array( $entries ) ? $entries : array();

			// Drop stale entries; each client id stays bound to its first user.
			foreach ( $entries as $id => $entry ) {
				if ( ! is_array( $entry ) || $now - (int) ( $entry['updated_at'] ?? 0 ) > $ttl ) {
					unset( $entries[ $id ] );
				}
			}
			if ( isset( $entries[ $client_id ] ) && (int) $entries[ $client_id ]['user_id'] !== $user_id ) {
				return $response;
			}

			$user                  = wp_get_current_user();
			$entries[ $client_id ] = array(
				'user_id'    => $user_id,
				'name'       => $user->display_name,
				'avatar'     => get_avatar_url( $user_id, array( 'size' => 48 ) ),
				'block'      => $block,
				'updated_at' => $now,
			);
			set_transient( $key, $entries, $ttl );

			$peers = array();
			foreach ( $entries as $id => $entry ) {
				if ( (int) $id === $client_id ) {
					continue;
				}
				$peers[] = array(
					'client_id' => (int) $id,
					'user'      => array(
						'id'     => (int) $entry['user_id'],
						'name'   => (string) $entry['name'],
						'avatar' => (string) $entry['avatar'],
					),
					'block'     => isset( $entry['block'] ) ? $entry['block'] : null,
				);
			}

			$response[ self::KEY ] = array( 'peers' => $peers );
			return $response;
		}

		/**
		 * A block identity as sent by the client: a short string, or null.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $value Submitted value.
		 * @return string|null The identity, or null when absent or unusable.
		 */
		private static function sanitize_block( $value ) {
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_BLOCK_BYTES ) {
				return null;
			}
			return $value;
		}

		/**
		 * The transient holding a post's entries.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $post_id Post ID.
		 * @return string Transient name.
		 */
		public static function transient_key( int $post_id ): string {
			return 'gse_awareness_' . $post_id;
		}
	}
}
