<?php
/**
 * SSE over ordinary authenticated WordPress REST requests, woken by Redis.
 *
 * @package GutenbergSyncEngines
 * @since n.e.x.t
 */

/**
 * Authenticated receive streams backed by durable room cursors.
 *
 * @since n.e.x.t
 */
class WP_Sync_SSE_Server extends WP_HTTP_Polling_Sync_Server {
	/**
	 * Transport slug.
	 *
	 * @var string Transport slug.
	 */
	const TRANSPORT_SLUG = 'sse';

	/**
	 * Subscriber owned by this HTTP request.
	 *
	 * @var WP_Sync_Redis|null Subscriber owned by this HTTP request.
	 */
	private $subscriber;

	/**
	 * Transport slug.
	 *
	 * @return string Transport slug.
	 */
	public function get_slug(): string {
		return self::TRANSPORT_SLUG;
	}

	/** Register a POST stream so cookies, REST nonces, and room schemas apply. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/sse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'validate_callback'   => array( $this, 'validate_request' ),
				'args'                => $this->get_route_args(),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}

	/**
	 * Subscribe BEFORE the initial catch-up read, closing the check/wait race.
	 * Writes use /updates; streaming requests must never replay a write.
	 *
	 * @param WP_REST_Request $request Validated request.
	 * @return WP_REST_Response|WP_Error Initial response, or normal JSON error.
	 */
	public function handle_request( WP_REST_Request $request ) {
		foreach ( $request['rooms'] as $room ) {
			if ( ! empty( $room['updates'] ) ) {
				return new WP_Error( 'rest_sse_read_only', 'Send updates through the updates endpoint.', array( 'status' => 400 ) );
			}
		}
		try {
			$this->subscriber = $this->subscribe( array_column( $request['rooms'], 'room' ) );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'rest_sse_unavailable', 'SSE notifications are unavailable.', array( 'status' => 503 ) );
		}
		$response = parent::handle_request( $request );
		if ( is_wp_error( $response ) ) {
			$this->subscriber->close();
			$this->subscriber = null;
		}
		return $response;
	}

	/**
	 * Open the subscriber. Separate for deterministic unit tests.
	 *
	 * @param string[] $rooms Room names.
	 * @return WP_Sync_Redis Subscriber.
	 */
	protected function subscribe( array $rooms ): WP_Sync_Redis {
		$redis = new WP_Sync_Redis( WP_Sync_Redis_Notifications::url() );
		$redis->subscribe( array_map( array( WP_Sync_Redis_Notifications::class, 'channel' ), array_unique( $rooms ) ) );
		return $redis;
	}

	/**
	 * Stream only after REST has authenticated, validated, and dispatched.
	 *
	 * @param bool             $served Already served.
	 * @param WP_HTTP_Response $response Dispatched response.
	 * @param WP_REST_Request  $request Original request.
	 * @param WP_REST_Server   $server REST server.
	 * @return bool Whether the response was served.
	 */
	public function serve( $served, $response, $request, $server ): bool {
		if ( $served || '/wp-sync/v1/sse' !== $request->get_route() || ! $this->subscriber || 200 !== $response->get_status() ) {
			return (bool) $served;
		}
		$server->send_header( 'Content-Type', 'text/event-stream; charset=UTF-8' );
		$server->send_header( 'Cache-Control', 'no-cache, no-store, no-transform' );
		$server->send_header( 'X-Accel-Buffering', 'no' );
		// Disable PHP buffering; the host must also allow proxy streaming.
		while ( ob_get_level() > 0 ) {
			if ( ! ob_end_flush() ) {
				break;
			}
		}
		try {
			$this->stream(
				$request,
				$response->get_data(),
				function ( string $frame ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE frames contain JSON, not HTML.
					echo $frame;
					flush();
				}
			);
		} finally {
			$this->subscriber->close();
			$this->subscriber = null;
		}
		return true;
	}

	/**
	 * Bounded streams reconnect and reauthorize. A kill needs no cleanup to
	 * preserve edits: the client resumes from its last applied storage cursor.
	 *
	 * @param WP_REST_Request $request Request carrying room cursors.
	 * @param array           $initial Initial catch-up result.
	 * @param callable        $emit Frame writer.
	 */
	public function stream( WP_REST_Request $request, array $initial, callable $emit ): void {
		$limit     = min( 300.0, max( 0.1, (float) apply_filters( 'wp_sync_sse_max_seconds', 300.0 ) ) );
		$php_limit = (int) ini_get( 'max_execution_time' );
		if ( $php_limit > 0 ) {
			$limit = min( $limit, max( 0.1, $php_limit - 5.0 ) );
		}
		$deadline    = $this->now() + $limit;
		$presence_at = $this->now() + 20.0;
		$rooms       = $request['rooms'];
		$data        = $initial;
		try {
			while ( true ) {
				$emit( "event: sync\ndata: " . wp_json_encode( $data ) . "\n\n" );
				foreach ( $data['rooms'] as $response ) {
					foreach ( $rooms as &$room ) {
						if ( $room['room'] === $response['room'] ) {
							$room['after'] = $response['end_cursor'];
						}
					}
					unset( $room );
				}
				WP_Sync_Redis_Notifications::flush();
				$catch_up_at = $this->now() + 20.0;
				do {
					$remaining = $deadline - $this->now();
					if ( $remaining <= 0 || connection_aborted() ) {
						return;
					}
					$changed = $this->subscriber->wait( max( 0.0, min( 5.0, $remaining, min( $catch_up_at, $presence_at ) - $this->now() ) ) );
					if ( ! $changed ) {
						$emit( ": keepalive\n\n" );
					}
				} while ( ! $changed && $this->now() < min( $catch_up_at, $presence_at ) );
				if ( connection_aborted() || $this->now() >= $deadline ) {
					return;
				}
				// Clear only this process's cache, never the shared object cache.
				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}
				$data = array( 'rooms' => array() );
				foreach ( $rooms as $room ) {
					if ( ! $this->can_user_sync_room( $room['room'] ) ) {
						return;
					}
					// Refresh only a still-present client, using its current state.
					// Never recreate presence removed by a leave or room reset.
					if ( $this->now() >= $presence_at ) {
						foreach ( $this->awareness->entries( $room['room'], self::AWARENESS_TIMEOUT ) as $entry ) {
							if ( (int) $entry['client_id'] === (int) $room['client_id'] ) {
								$this->update_awareness( $room['room'], (int) $room['client_id'], $entry['state'] );
							}
						}
					}
					$engine                = $this->engines->get_engine_for_room( $room['room'] );
					$response              = $engine->get_updates_since( $room['room'], (int) $room['client_id'], (int) $room['after'], array() );
					$response['awareness'] = array();
					foreach ( $this->awareness->entries( $room['room'], self::AWARENESS_TIMEOUT ) as $entry ) {
						$response['awareness'][ $entry['client_id'] ] = $entry['state'];
					}
					$response['generation'] = $this->room_generation( $room['room'], (int) $response['end_cursor'] );
					$data['rooms'][]        = $response;
				}
				if ( $this->now() >= $presence_at ) {
					$presence_at = $this->now() + 20.0;
				}
			}
		} catch ( RuntimeException $error ) {
			// End the response. Reconnect does a full cursor catch-up, even
			// if Redis restarted and discarded every notification.
			$emit( "event: retry\ndata: {}\n\n" );
		}
	}
	/**
	 * Monotonic seconds, replaceable in tests without real waits.
	 *
	 * @return float Current time.
	 */
	protected function now(): float {
		return hrtime( true ) / 1e9;
	}
}
