<?php
/**
 * SSE subscription ordering, restart recovery, authentication, and storage notices.
 *
 * @package GutenbergSyncEngines
 * @group collaboration
 */
class Tests_Collaboration_WpSseSyncServer extends WP_Test_REST_TestCase {
	private $post_id;
	private $server;
	private $redis;
	private $frames;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->post_id = self::factory()->post->create( array( 'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ) );
		update_option( 'wp_sync_engine', 'intent-log' );
		$this->redis         = new class() extends WP_Sync_Redis {
			public $waiting;
			public $closed = false;
			public function __construct() {}
			public function wait( float $seconds ): bool {
				if ( $this->waiting ) {
					$callback      = $this->waiting;
					$this->waiting = null;
					$callback();
					return true;
				}
				throw new RuntimeException( 'Simulated Redis disconnect' );
			}
			public function close(): void {
				$this->closed = true; }
		};
		$this->server        = new class( new WP_Sync_Table_Storage() ) extends WP_Sync_SSE_Server {
			public $redis;
			public $on_subscribe;
			public $sleep;
			public $clock     = 0.0;
			public $refreshes = array();
			public function update_awareness( string $room, int $client_id, ?array $state ): array {
				$this->refreshes[] = array( $this->clock, $state );
				return parent::update_awareness( $room, $client_id, $state );
			}
			public function waiter() {
				return $this->subscriber;
			}
			protected function now(): float {
				return $this->clock;
			}
			protected function subscribe( array $rooms ): WP_Sync_Change_Waiter {
				if ( $this->on_subscribe ) {
					( $this->on_subscribe )(); }
				return $this->redis ? $this->redis : parent::subscribe( $rooms );
			}
			protected function storage_waiter( callable $has_changes ): WP_Sync_Storage_Change_Waiter {
				return new WP_Sync_Storage_Change_Waiter( $has_changes, $this->sleep );
			}
		};
		$this->server->redis = $this->redis;
		$this->frames        = array();
	}

	private function request( int $after = 0 ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wp-sync/v1/sse' );
		$request->set_body_params(
			array(
				'rooms' => array(
					array(
						'room'      => 'postType/post:' . $this->post_id,
						'after'     => $after,
						'client_id' => 17,
						'awareness' => array( 'name' => 'editor' ),
						'updates'   => array(),
					),
				),
			)
		);
		return $request;
	}

	public function test_registered_and_selectable() {
		add_filter( 'wp_collaboration_transport', static fn() => 'sse' );
		$this->assertSame( 'sse', wp_get_collaboration_transport_registry()->get_active_slug() );
		$this->assertSame( 'sse', wp_get_collaboration_transport_registry()->get_announced_slugs()[0] );
		$this->assertArrayHasKey( '/wp-sync/v1/sse', rest_get_server()->get_routes() );
	}

	public function test_subscribes_before_initial_read() {
		$this->server->on_subscribe = function () {
			$this->assertSame( array(), ( new WP_Sync_Table_Storage() )->get_updates_after_cursor( 'postType/post:' . $this->post_id, 0 ) );
		};
		$result                     = $this->server->handle_request( $this->request() );
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertNotEmpty( $result->get_data()['rooms'][0]['updates'] );
	}

	public function test_stream_delivers_a_notice_and_ends_cleanly_on_redis_loss() {
		$request              = $this->request();
		$initial              = $this->server->handle_request( $request )->get_data();
		$this->redis->waiting = function () {
			$this->server->update_awareness( 'postType/post:' . $this->post_id, 42, array( 'name' => 'peer' ) );
		};
		$this->server->stream(
			$request,
			$initial,
			function ( $frame ) {
				$this->frames[] = $frame;
			}
		);
		$this->assertCount( 3, $this->frames );
		$this->assertStringContainsString( 'peer', $this->frames[1] );
		$this->assertStringContainsString( 'event: retry', $this->frames[2] );
		$data = json_decode( explode( 'data: ', $this->frames[1], 2 )[1], true );
		$this->assertSame( array(), $data['rooms'][0]['updates'], 'The next event must not replay the initial snapshot.' );
	}

	public function test_a_new_request_catches_up_without_a_redis_notice() {
		$initial = $this->server->handle_request( $this->request() )->get_data();
		$cursor  = $initial['rooms'][0]['end_cursor'];
		$storage = new WP_Sync_Table_Storage();
		$storage->add_update(
			'postType/post:' . $this->post_id,
			array(
				'type' => WP_Intent_Log_Engine::UPDATE_TYPE_SNAPSHOT,
				'data' => 'missed while disconnected',
			)
		);
		$next = $this->server->handle_request( $this->request( $cursor ) )->get_data();
		$this->assertSame( 'missed while disconnected', $next['rooms'][0]['updates'][0]['data'] );
		$this->assertGreaterThan( $cursor, $next['rooms'][0]['end_cursor'] );
	}

	public function test_without_redis_the_stream_notices_a_new_row_by_checking_storage() {
		add_filter( 'wp_sync_sse_redis_url', '__return_empty_string' );
		$this->server->redis = null;
		$sleeps              = 0;
		$this->server->sleep = function ( float $seconds ) use ( &$sleeps ) {
			$this->server->clock += $seconds;
			++$sleeps;
			if ( 2 === $sleeps ) {
				( new WP_Sync_Table_Storage() )->add_update(
					'postType/post:' . $this->post_id,
					array(
						'type' => WP_Intent_Log_Engine::UPDATE_TYPE_SNAPSHOT,
						'data' => 'seen by a storage check',
					)
				);
			}
			if ( $sleeps > 6 ) {
				throw new RuntimeException( 'End test stream' );
			}
		};
		$request             = $this->request();
		$initial             = $this->server->handle_request( $request )->get_data();
		$this->assertInstanceOf( WP_Sync_Storage_Change_Waiter::class, $this->server->waiter() );
		$events = array();
		$this->server->stream(
			$request,
			$initial,
			function ( $frame ) use ( &$events ) {
				if ( 0 === strpos( $frame, 'event: sync' ) ) {
					$events[] = array( $this->server->clock, json_decode( explode( 'data: ', $frame, 2 )[1], true ) );
				}
			}
		);
		$this->assertCount( 2, $events, 'The initial read, then the read the storage check woke.' );
		$this->assertSame( 1.0, $events[1][0], 'Noticed on the check right after the write (two half-second steps).' );
		$this->assertSame( 'seen by a storage check', $events[1][1]['rooms'][0]['updates'][0]['data'] );
	}

	public function test_a_configured_but_unreachable_redis_falls_back_to_storage_checks() {
		add_filter( 'wp_sync_sse_redis_url', static fn() => 'redis://127.0.0.1:1' );
		$this->server->redis = null;
		$failures            = array();
		add_action(
			'gutenberg_sync_engines_sse_redis_failed',
			static function ( $error ) use ( &$failures ) {
				$failures[] = $error->getMessage();
			}
		);
		$result = $this->server->handle_request( $this->request() );
		$this->assertInstanceOf( WP_REST_Response::class, $result, 'The stream still opens.' );
		$this->assertInstanceOf( WP_Sync_Storage_Change_Waiter::class, $this->server->waiter() );
		$this->assertSame( array( 'Collaboration Redis is unavailable.' ), $failures );
	}

	public function test_storage_waiter_sleeps_in_half_second_steps_until_a_change() {
		$checks = 0;
		$slept  = array();
		$waiter = new WP_Sync_Storage_Change_Waiter(
			static function () use ( &$checks ): bool {
				return ++$checks >= 3;
			},
			static function ( float $seconds ) use ( &$slept ): void {
				$slept[] = $seconds;
			}
		);
		$this->assertTrue( $waiter->wait( 5.0 ) );
		$this->assertSame( array( 0.5, 0.5, 0.5 ), $slept );

		$slept  = array();
		$waiter = new WP_Sync_Storage_Change_Waiter(
			'__return_false',
			static function ( float $seconds ) use ( &$slept ): void {
				$slept[] = $seconds;
			}
		);
		$this->assertFalse( $waiter->wait( 1.2 ) );
		$this->assertSame( array( 0.5, 0.5, 0.2 ), array_map( static fn( $seconds ) => round( $seconds, 6 ), $slept ), 'Never oversleeps the budget.' );
	}

	public function test_stream_rejects_writes() {
		$request             = $this->request();
		$rooms               = $request['rooms'];
		$rooms[0]['updates'] = array(
			array(
				'type' => 'edit',
				'data' => 'x',
			),
		);
		$request->set_param( 'rooms', $rooms );
		$this->assertSame( 'rest_sse_read_only', $this->server->handle_request( $request )->get_error_code() );
	}

	public function test_route_rejects_anonymous_readers() {
		wp_set_current_user( 0 );
		$response = rest_get_server()->dispatch( $this->request() );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_storage_notifies_for_updates_presence_and_reset() {
		$changed = array();
		add_action(
			'gutenberg_sync_engines_room_changed',
			static function ( $room ) use ( &$changed ) {
				$changed[] = $room;
			}
		);
		$storage = new WP_Sync_Table_Storage();
		$room    = 'postType/post:' . $this->post_id;
		$storage->add_update(
			$room,
			array(
				'data' => 'x',
				'type' => 'test',
			)
		);
		$storage->set_awareness_state( $room, array() );
		$storage->reset_room( $room );
		$this->assertSame( array( $room, $room, $room ), $changed );
	}
	public function test_idle_heartbeats_do_not_query_storage() {
		global $wpdb;
		$request             = $this->request();
		$initial             = $this->server->handle_request( $request )->get_data();
		$this->server->redis = new class() extends WP_Sync_Redis {
			private $calls = 0;
			public function __construct() {}
			public function wait( float $seconds ): bool {
				if ( ++$this->calls < 3 ) {
					return false; }
				throw new RuntimeException( 'End test stream' );
			}
			public function close(): void {}
		};
		// Reopen with the fake subscriber; the initial request is the only
		// storage read. Heartbeats while waiting must not read the tables.
		$initial = $this->server->handle_request( $request )->get_data();
		WP_Sync_Redis_Notifications::flush();
		$before = $wpdb->num_queries;
		$this->server->stream(
			$request,
			$initial,
			function ( $frame ) {
				$this->frames[] = $frame;
			}
		);
		$this->assertSame( $before, $wpdb->num_queries );
		$this->assertSame( ": keepalive\n\n", $this->frames[1] );
		$this->assertCount( 4, $this->frames );
	}

	public function test_failed_storage_write_does_not_publish_a_notice() {
		$changed = array();
		add_action(
			'gutenberg_sync_engines_room_changed',
			static function ( $room ) use ( &$changed ) {
				$changed[] = $room;
			}
		);
		$this->assertFalse( ( new WP_Sync_Table_Storage() )->add_update( str_repeat( 'x', 1000 ), array() ) );
		$this->assertSame( array(), $changed );
	}
	public function test_long_stream_recovers_a_missed_notice_without_reconnecting() {
		$previous = ini_get( 'max_execution_time' );
		set_time_limit( 0 );
		try {
			$request             = $this->request();
			$this->server->redis = new class( $this->server, $this->post_id ) extends WP_Sync_Redis {
				private $server;
				private $post_id;
				public $waits = 0;
				public function __construct( $server, $post_id ) {
					$this->server  = $server;
					$this->post_id = $post_id;
				}
				public function wait( float $seconds ): bool {
					$this->server->clock += $seconds;
					if ( 1 === ++$this->waits ) {
						$storage = new WP_Sync_Table_Storage();
						$storage->add_update(
							'postType/post:' . $this->post_id,
							array(
								'type' => WP_Intent_Log_Engine::UPDATE_TYPE_SNAPSHOT,
								'data' => 'no notification',
							)
						);
						// A leave must not be undone by the old stream.
						$this->server->update_awareness( 'postType/post:' . $this->post_id, 17, null );
					}
					return false;
				}
				public function close(): void {}
			};
			$initial             = $this->server->handle_request( $request )->get_data();
			$events              = array();
			$this->server->stream(
				$request,
				$initial,
				function ( $frame ) use ( &$events ) {
					if ( 0 === strpos( $frame, 'event: sync' ) ) {
						$events[] = array( $this->server->clock, json_decode( explode( 'data: ', $frame, 2 )[1], true ) );
					}
				}
			);
			$this->assertSame( 300.0, $this->server->clock );
			$this->assertSame( 20.0, $events[1][0] );
			$this->assertSame( 'no notification', $events[1][1]['rooms'][0]['updates'][0]['data'] );
			$this->assertArrayNotHasKey( 17, $events[1][1]['rooms'][0]['awareness'] );
			$this->assertSame( array(), $events[2][1]['rooms'][0]['updates'], 'Catch-up advances the cursor.' );
			$this->assertCount( 15, $events, 'One initial read, then catch-up every 20 seconds.' );
		} finally {
			set_time_limit( (int) $previous );
		}
	}
	/** @dataProvider stream_limits */
	public function test_stream_limits_and_presence_refresh( $php_limit, $requested, $expected ) {
		$previous = ini_get( 'max_execution_time' );
		set_time_limit( (int) $php_limit );
		add_filter( 'wp_sync_sse_max_seconds', static fn() => $requested );
		try {
			$this->server->redis = new class( $this->server ) extends WP_Sync_Redis {
				private $server;
				public function __construct( $server ) {
					$this->server = $server; }
				public function wait( float $seconds ): bool {
					$this->server->clock += $seconds;
					return false;
				}
				public function close(): void {}
			};
			$request             = $this->request();
			$initial             = $this->server->handle_request( $request )->get_data();
			$this->server->stream( $request, $initial, static function () {} );
			$this->assertSame( $expected, $this->server->clock );
			$this->assertSame( array( 20.0, array( 'name' => 'editor' ) ), $this->server->refreshes[0] );
		} finally {
			set_time_limit( (int) $previous );
		}
	}

	public function stream_limits(): array {
		return array(
			'PHP limit leaves five seconds'   => array( 35, 300.0, 30.0 ),
			'host can shorten streams'        => array( 0, 45.0, 45.0 ),
			'five minutes is the upper bound' => array( 0, 600.0, 300.0 ),
		);
	}
}
