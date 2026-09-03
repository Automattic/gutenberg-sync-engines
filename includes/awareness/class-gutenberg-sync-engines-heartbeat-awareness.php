<?php
/**
 * Gutenberg_Sync_Engines_Heartbeat_Awareness class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Heartbeat_Awareness' ) ) {

	/**
	 * Carries high-latency awareness beacons over WordPress Heartbeat, a
	 * transport separate from the sync transport.
	 *
	 * Each editor sends its latest activity beacon with every Heartbeat
	 * request; the server keeps one entry per client in a transient per
	 * post and answers with every other live client's entry. The server
	 * never interprets a beacon (it is opaque JSON, like the sync
	 * transport's awareness) beyond a size cap and binding each client id
	 * to the user that first used it.
	 *
	 * This is a prototype: the transient read-modify-write is not atomic,
	 * so two clients writing in the same instant can drop one another's
	 * entry for one tick. A durable version belongs in the framework's
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
		 * Largest accepted beacon, in encoded bytes.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const MAX_BEACON_BYTES = 16384;

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
			return max( 0, (int) get_option( Gutenberg_Sync_Engines_Settings::AWARENESS_INTERVAL_OPTION, 0 ) );
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
				&& 'heartbeat' === get_option( Gutenberg_Sync_Engines_Settings::AWARENESS_CHANNEL_OPTION, 'sync' );
		}

		/**
		 * Sets Heartbeat's interval to the awareness cadence on editor
		 * screens, so beacons move at the configured pace. Heartbeat clamps
		 * to 1-3600 seconds itself.
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
		 * Stores the sender's beacon and returns the other live peers'.
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
			$beacon    = isset( $request['beacon'] ) && is_array( $request['beacon'] ) ? $request['beacon'] : null;
			$user_id   = get_current_user_id();

			if ( $post_id <= 0 || $client_id <= 0 || ! $user_id || ! current_user_can( 'edit_post', $post_id ) ) {
				return $response;
			}
			if ( null !== $beacon && strlen( (string) wp_json_encode( $beacon ) ) > self::MAX_BEACON_BYTES ) {
				$beacon = null;
			}

			$ttl     = max( 60, 4 * self::interval_seconds() );
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

			if ( null !== $beacon ) {
				$user                  = wp_get_current_user();
				$entries[ $client_id ] = array(
					'user_id'    => $user_id,
					'name'       => $user->display_name,
					'avatar'     => get_avatar_url( $user_id, array( 'size' => 48 ) ),
					'beacon'     => $beacon,
					'updated_at' => $now,
				);
			}
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
					'beacon'    => $entry['beacon'],
					'age_ms'    => 1000 * ( $now - (int) $entry['updated_at'] ),
				);
			}

			$response[ self::KEY ] = array(
				'peers'       => $peers,
				'server_time' => $now,
			);
			return $response;
		}

		/**
		 * The transient holding a post's beacons.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $post_id Post ID.
		 * @return string Transient name.
		 */
		private static function transient_key( int $post_id ): string {
			return 'gse_awareness_' . $post_id;
		}
	}
}
