<?php
/**
 * Tests for the WebSocket transport's web-process half (registration,
 * token route, announced socket URL). The daemon itself is a long-running
 * process verified by a live smoke, not here.
 *
 * @package Gutenberg
 *
 * @group collaboration
 */
class Tests_Collaboration_WpWebSocketSyncTransport extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function transport(): WP_WebSocket_Sync_Transport {
		$storage = new WP_Sync_Post_Meta_Storage();
		return new WP_WebSocket_Sync_Transport( $storage, new WP_Sync_Engine_Registry( $storage ) );
	}

	public function test_slug_and_protocol() {
		$transport = $this->transport();
		$this->assertSame( 'websocket', $transport->get_slug() );
		$this->assertSame( 1, $transport->get_protocol_version() );
	}

	public function test_registers_the_one_time_token_route() {
		$this->transport()->register_routes();
		$this->assertArrayHasKey(
			'/wp-sync/v1/ws-token',
			rest_get_server()->get_routes()
		);
	}

	public function test_default_socket_url_is_ws_and_filterable_to_wss() {
		$this->assertStringStartsWith(
			'ws://',
			WP_WebSocket_Sync_Transport::get_socket_url()
		);

		add_filter(
			'wp_sync_websocket_url',
			static fn() => 'wss://example.com/collab'
		);
		$this->assertSame(
			'wss://example.com/collab',
			WP_WebSocket_Sync_Transport::get_socket_url()
		);
	}

	public function test_selectable_as_the_active_transport_and_announced_first() {
		add_filter( 'wp_collaboration_transport', static fn() => 'websocket' );
		$registry = wp_get_collaboration_transport_registry();

		$this->assertSame( 'websocket', $registry->get_active_slug() );
		$this->assertSame( 'websocket', $registry->get_announced_slugs()[0] );
		$this->assertInstanceOf(
			'WP_WebSocket_Sync_Transport',
			$registry->get_transport( 'websocket' )
		);
	}

	public function test_the_daemon_binds_to_the_shared_engine_seam() {
		// The daemon is constructed over the polling server (the shared
		// engine seam), NOT a Yjs-specific core — the whole point of the
		// port. Constructing it must not fatal.
		$storage = new WP_Sync_Post_Meta_Storage();
		$sync    = new WP_HTTP_Polling_Sync_Server( $storage );
		$server  = new WP_WebSocket_Sync_Server( $sync, '127.0.0.1', 8799 );
		$this->assertInstanceOf( 'WP_WebSocket_Sync_Server', $server );
	}

	/**
	 * Invokes the daemon's private room-request validator.
	 *
	 * @param array $room_request Raw room request.
	 * @return array|WP_Error Validated request or error.
	 */
	private function validate( array $room_request ) {
		$storage = new WP_Sync_Post_Meta_Storage();
		$sync    = new WP_HTTP_Polling_Sync_Server( $storage );
		$server  = new WP_WebSocket_Sync_Server( $sync, '127.0.0.1', 8799 );
		$method  = new ReflectionMethod( WP_WebSocket_Sync_Server::class, 'validate_room_request' );
		$method->setAccessible( true );
		return $method->invoke( $server, $room_request );
	}

	public function test_room_request_validation_forwards_engine_stamps() {
		$validated = $this->validate(
			array(
				'room'            => 'postType/post:1',
				'client_id'       => 7,
				'after'           => 0,
				'updates'         => array(),
				'engine'          => 'intent-log',
				'engine_protocol' => 1,
			)
		);

		// The stamps power the stale-tab fence and the switched-engine
		// collection-room healing; the daemon used to strip them, so
		// neither could ever fire over websocket.
		$this->assertIsArray( $validated );
		$this->assertSame( 'intent-log', $validated['engine'] );
		$this->assertSame( 1, $validated['engine_protocol'] );
	}

	public function test_room_request_validation_leaves_absent_engine_stamps_absent() {
		$validated = $this->validate(
			array(
				'room'      => 'postType/post:1',
				'client_id' => 7,
				'after'     => 0,
				'updates'   => array(),
			)
		);

		// The fence keys on PRESENCE (mirroring the REST schema): an
		// engine key set to null would read as a stamp of null.
		$this->assertIsArray( $validated );
		$this->assertArrayNotHasKey( 'engine', $validated );
		$this->assertArrayNotHasKey( 'engine_protocol', $validated );
	}

	public function test_room_request_validation_rejects_malformed_engine_stamps() {
		$bad_engine = $this->validate(
			array(
				'room'      => 'postType/post:1',
				'client_id' => 7,
				'after'     => 0,
				'updates'   => array(),
				'engine'    => array( 'not-a-string' ),
			)
		);
		$this->assertWPError( $bad_engine );

		$bad_protocol = $this->validate(
			array(
				'room'            => 'postType/post:1',
				'client_id'       => 7,
				'after'           => 0,
				'updates'         => array(),
				'engine'          => 'intent-log',
				'engine_protocol' => 'one',
			)
		);
		$this->assertWPError( $bad_protocol );
	}

	public function test_forwarded_mismatched_stamp_fences_through_the_shared_seam() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$post_id = self::factory()->post->create( array( 'post_author' => $editor_id ) );
		$room    = 'postType/post:' . $post_id;

		$storage = new WP_Sync_Post_Meta_Storage();
		$sync    = new WP_HTTP_Polling_Sync_Server( $storage );

		// Establish the room under the resolved (default) engine first.
		$bootstrap = $sync->process_room_request(
			array(
				'room'      => $room,
				'client_id' => 7,
				'after'     => 0,
				'awareness' => null,
				'updates'   => array(),
			)
		);
		$this->assertIsArray( $bootstrap );

		// A stale tab speaking ANOTHER engine, through the daemon's
		// validator and into the shared seam: the fence must 409.
		$validated = $this->validate(
			array(
				'room'            => $room,
				'client_id'       => 8,
				'after'           => 0,
				'updates'         => array(),
				'engine'          => 'yjs-server',
				'engine_protocol' => 1,
			)
		);
		$this->assertIsArray( $validated );
		$result = $sync->process_room_request( $validated );
		$this->assertWPError( $result );
		$this->assertSame( 'rest_sync_engine_mismatch', $result->get_error_code() );

		self::delete_user( $editor_id );
		wp_delete_post( $post_id, true );
	}
}
