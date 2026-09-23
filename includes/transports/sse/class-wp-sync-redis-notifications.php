<?php
/**
 * Redis carries wake notices; room tables remain the durable source of truth.
 *
 * @package GutenbergSyncEngines
 * @since n.e.x.t
 */

/**
 * Publishes changes after the writer finishes.
 *
 * @since n.e.x.t
 */
class WP_Sync_Redis_Notifications {
	/**
	 * Rooms changed in this request.
	 *
	 * @var array<string, bool> Rooms changed in this request.
	 */
	private static $pending = array();

	/**
	 * Server-only Redis URL, or empty when disabled.
	 *
	 * @return string Server-only Redis URL, or empty when disabled.
	 */
	public static function url(): string {
		return (string) apply_filters( 'wp_sync_sse_redis_url', defined( 'WP_SYNC_SSE_REDIS_URL' ) ? WP_SYNC_SSE_REDIS_URL : '' );
	}

	/** Register storage notices and publish after request work is complete. */
	public static function register(): void {
		add_action( 'gutenberg_sync_engines_room_changed', array( self::class, 'changed' ) );
		add_action( 'shutdown', array( self::class, 'flush' ), PHP_INT_MAX );
	}

	/**
	 * Keep sites and rooms separate on a shared Redis service. The database
	 * identity (host, name, table prefix, blog id) is what makes a site
	 * distinct; the home URL is deliberately left out so a site reached
	 * through several hostnames publishes and subscribes on one channel.
	 *
	 * @param string $room Room name.
	 * @return string Channel.
	 */
	public static function channel( string $room ): string {
		global $wpdb;
		return 'wp-sync:' . hash( 'sha256', (string) wp_json_encode( array( DB_HOST, DB_NAME, $wpdb->prefix, get_current_blog_id(), $room ) ) );
	}

	/**
	 * Coalesce notices until the writer has finished updating engine metadata.
	 *
	 * @param string $room Changed room.
	 */
	public static function changed( string $room ): void {
		// Do not read options or site metadata on the storage write path.
		self::$pending[ $room ] = true;
	}

	/** Publish best-effort notices. Redis failure must never fail a saved edit. */
	public static function flush(): void {
		$rooms         = array_keys( self::$pending );
		self::$pending = array();
		if ( empty( $rooms ) || '' === self::url() || 'sse' !== wp_get_collaboration_transport_registry()->get_active_slug() ) {
			return;
		}
		try {
			$redis = new WP_Sync_Redis( self::url() );
			foreach ( $rooms as $room ) {
				$redis->command( array( 'PUBLISH', self::channel( $room ), 'changed' ) );
				$redis->read();
			}
			$redis->close();
		} catch ( RuntimeException $error ) {
			// Periodic catch-up reads recover missing notices even
			// when a writer dies or Redis loses a notice after the DB write.
			do_action( 'gutenberg_sync_engines_sse_publish_failed', $error );
		}
	}
}
