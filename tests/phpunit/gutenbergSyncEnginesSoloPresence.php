<?php
/**
 * Tests for the solo-presence lane (quiet while editing alone).
 *
 * @package Gutenberg
 *
 * @group collaboration
 */
class Tests_Collaboration_GutenbergSyncEnginesSoloPresence extends WP_UnitTestCase {
	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * A user who cannot edit the post.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Post ID used for room targets.
	 *
	 * @var int
	 */
	protected static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$post_id       = $factory->post->create(
			array(
				'post_author'  => self::$editor_id,
				'post_content' => "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$editor_id );
		self::delete_user( self::$subscriber_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
	}

	private function presence(): Gutenberg_Sync_Engines_Solo_Presence {
		return new Gutenberg_Sync_Engines_Solo_Presence();
	}

	private function room(): string {
		return 'postType/post:' . self::$post_id;
	}

	private function probe( string $token, array $extra = array() ): array {
		return array(
			Gutenberg_Sync_Engines_Solo_Presence::HEARTBEAT_KEY => array_merge(
				array(
					'room'  => $this->room(),
					'token' => $token,
				),
				$extra
			),
		);
	}

	private function answer_for( array $response ): ?array {
		$key = Gutenberg_Sync_Engines_Solo_Presence::HEARTBEAT_KEY;
		return isset( $response[ $key ] ) ? $response[ $key ] : null;
	}

	public function test_heartbeat_without_probe_is_untouched() {
		$response = $this->presence()->answer_heartbeat( array( 'x' => 1 ), array() );
		$this->assertSame( array( 'x' => 1 ), $response );
	}

	public function test_first_tab_is_alone() {
		$response = $this->presence()->answer_heartbeat( array(), $this->probe( 'tab-a' ) );
		$this->assertSame( array( 'others' => false ), $this->answer_for( $response ) );
	}

	public function test_second_tab_makes_both_accompanied() {
		$presence = $this->presence();
		$presence->answer_heartbeat( array(), $this->probe( 'tab-a' ) );

		$response_b = $presence->answer_heartbeat( array(), $this->probe( 'tab-b' ) );
		$this->assertSame( array( 'others' => true ), $this->answer_for( $response_b ) );

		$response_a = $presence->answer_heartbeat( array(), $this->probe( 'tab-a' ) );
		$this->assertSame( array( 'others' => true ), $this->answer_for( $response_a ) );
	}

	public function test_expired_tokens_do_not_count() {
		$presence = $this->presence();
		$presence->answer_heartbeat( array(), $this->probe( 'tab-a' ) );

		// Age the stored entries past the TTL.
		$transient_key = Gutenberg_Sync_Engines_Solo_Presence::TRANSIENT_PREFIX . md5( $this->room() );
		$entries       = get_transient( $transient_key );
		foreach ( $entries as $token => $entry ) {
			$entries[ $token ]['t'] = time() - Gutenberg_Sync_Engines_Solo_Presence::PRESENCE_TTL - 1;
		}
		set_transient( $transient_key, $entries, 300 );

		$response = $presence->answer_heartbeat( array(), $this->probe( 'tab-b' ) );
		$this->assertSame( array( 'others' => false ), $this->answer_for( $response ) );
	}

	public function test_probe_never_creates_a_storage_post() {
		$this->presence()->answer_heartbeat( array(), $this->probe( 'tab-a' ) );

		$storage_posts = get_posts(
			array(
				'post_type'   => 'wp_sync_storage',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		$this->assertSame( array(), $storage_posts );
	}

	public function test_user_without_edit_rights_gets_no_answer() {
		wp_set_current_user( self::$subscriber_id );
		$response = $this->presence()->answer_heartbeat( array(), $this->probe( 'tab-x' ) );
		$this->assertNull( $this->answer_for( $response ) );
	}

	public function test_collection_rooms_are_rejected() {
		$data     = array(
			Gutenberg_Sync_Engines_Solo_Presence::HEARTBEAT_KEY => array(
				'room'  => 'taxonomy/wp_pattern_category',
				'token' => 'tab-x',
			),
		);
		$response = $this->presence()->answer_heartbeat( array(), $data );
		$this->assertNull( $this->answer_for( $response ) );
	}

	public function test_live_awareness_from_another_client_counts() {
		$storage = new WP_Sync_Post_Meta_Storage();
		$storage->set_awareness_state(
			$this->room(),
			array(
				array(
					'client_id'  => 555,
					'state'      => array(),
					'updated_at' => time(),
					'wp_user_id' => self::$editor_id,
				),
			)
		);

		$response = $this->presence()->answer_heartbeat( array(), $this->probe( 'tab-a' ) );
		$this->assertSame( array( 'others' => true ), $this->answer_for( $response ) );
	}

	public function test_own_awareness_entry_does_not_count() {
		$storage = new WP_Sync_Post_Meta_Storage();
		$storage->set_awareness_state(
			$this->room(),
			array(
				array(
					'client_id'  => 555,
					'state'      => array(),
					'updated_at' => time(),
					'wp_user_id' => self::$editor_id,
				),
			)
		);

		$response = $this->presence()->answer_heartbeat(
			array(),
			$this->probe( 'tab-a', array( 'client_id' => 555 ) )
		);
		$this->assertSame( array( 'others' => false ), $this->answer_for( $response ) );
	}

	public function test_stale_awareness_does_not_count() {
		$storage = new WP_Sync_Post_Meta_Storage();
		$storage->set_awareness_state(
			$this->room(),
			array(
				array(
					'client_id'  => 555,
					'state'      => array(),
					'updated_at' => time() - Gutenberg_Sync_Engines_Solo_Presence::AWARENESS_TIMEOUT - 1,
					'wp_user_id' => self::$editor_id,
				),
			)
		);

		$response = $this->presence()->answer_heartbeat( array(), $this->probe( 'tab-a' ) );
		$this->assertSame( array( 'others' => false ), $this->answer_for( $response ) );
	}

	public function test_editor_settings_shape_and_flag() {
		$post     = get_post( self::$post_id );
		$presence = $this->presence();

		$first = $presence->editor_settings( $post );
		$this->assertSame( $this->room(), $first['room'] );
		$this->assertNotEmpty( $first['token'] );
		$this->assertFalse( $first['othersPresent'] );

		// A second tab loading now sees the first immediately.
		$second = $presence->editor_settings( $post );
		$this->assertTrue( $second['othersPresent'] );
		$this->assertNotSame( $first['token'], $second['token'] );
	}

	public function test_editor_settings_respects_the_kill_switch() {
		add_filter( 'gutenberg_sync_engines_solo_quiet_enabled', '__return_false' );
		try {
			$this->assertNull( $this->presence()->editor_settings( get_post( self::$post_id ) ) );
		} finally {
			remove_filter( 'gutenberg_sync_engines_solo_quiet_enabled', '__return_false' );
		}
	}
}
