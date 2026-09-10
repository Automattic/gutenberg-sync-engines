<?php
/**
 * Tests for Gutenberg_Sync_Engines_Heartbeat_Awareness: the slow awareness
 * beacon (which block an editor is in) carried over WordPress Heartbeat.
 *
 * @package gutenberg-sync-engines
 *
 * @group collaboration
 */
class Tests_Collaboration_GutenbergSyncEnginesHeartbeatAwareness extends WP_UnitTestCase {

	protected static int $editor_id;
	protected static int $other_editor_id;
	protected static int $subscriber_id;
	protected static int $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id       = $factory->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Riley',
			)
		);
		self::$other_editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id   = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$post_id         = $factory->post->create( array( 'post_author' => self::$editor_id ) );
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$editor_id );
		self::delete_user( self::$other_editor_id );
		self::delete_user( self::$subscriber_id );
		wp_delete_post( self::$post_id, true );
	}

	/**
	 * @var Gutenberg_Sync_Engines_Heartbeat_Awareness
	 */
	private $awareness;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
		$this->awareness = new Gutenberg_Sync_Engines_Heartbeat_Awareness();
	}

	public function tear_down() {
		delete_transient( Gutenberg_Sync_Engines_Heartbeat_Awareness::transient_key( self::$post_id ) );
		delete_option( Gutenberg_Sync_Engines_Settings::AWARENESS_INTERVAL_OPTION );
		delete_option( Gutenberg_Sync_Engines_Settings::AWARENESS_CHANNEL_OPTION );
		unset( $GLOBALS['pagenow'] );
		parent::tear_down();
	}

	private function beat( int $client_id, $block, ?int $post_id = null ): array {
		$data = array(
			Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY => array(
				'post_id'   => null === $post_id ? self::$post_id : $post_id,
				'client_id' => $client_id,
				'block'     => $block,
			),
		);
		return $this->awareness->handle( array(), $data );
	}

	private function peers( array $response ): array {
		return $response[ Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY ]['peers'] ?? array();
	}

	public function test_a_beat_stores_the_block_and_answers_with_the_other_peers_only() {
		$this->assertSame( array(), $this->peers( $this->beat( 1, 's-one' ) ), 'Alone: nobody to report' );

		wp_set_current_user( self::$other_editor_id );
		$peers = $this->peers( $this->beat( 2, 's-two' ) );
		$this->assertCount( 1, $peers );
		$this->assertSame( 1, $peers[0]['client_id'] );
		$this->assertSame( 's-one', $peers[0]['block'] );
		$this->assertSame( self::$editor_id, $peers[0]['user']['id'] );
		$this->assertSame( 'Riley', $peers[0]['user']['name'] );
		$this->assertNotEmpty( $peers[0]['user']['avatar'] );
		$this->assertSame( array( 'client_id', 'user', 'block' ), array_keys( $peers[0] ), 'Nothing else rides the answer' );

		// A null block is stored as such: the peer is present but in no block.
		wp_set_current_user( self::$editor_id );
		$this->beat( 1, null );
		wp_set_current_user( self::$other_editor_id );
		$peers = $this->peers( $this->beat( 2, 's-two' ) );
		$this->assertNull( $peers[0]['block'] );
	}

	public function test_a_beat_without_the_key_or_with_bad_ids_is_ignored() {
		$this->assertSame( array( 'x' => 1 ), $this->awareness->handle( array( 'x' => 1 ), array() ) );
		$this->assertArrayNotHasKey( Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY, $this->beat( 0, 's-one' ) );
		$this->assertArrayNotHasKey( Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY, $this->beat( 1, 's-one', -5 ) );
		$this->assertFalse( get_transient( Gutenberg_Sync_Engines_Heartbeat_Awareness::transient_key( self::$post_id ) ) );
	}

	public function test_a_user_who_cannot_edit_the_post_is_ignored() {
		wp_set_current_user( self::$subscriber_id );
		$this->assertArrayNotHasKey( Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY, $this->beat( 1, 's-one' ) );

		wp_set_current_user( 0 );
		$this->assertArrayNotHasKey( Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY, $this->beat( 1, 's-one' ) );
	}

	public function test_a_client_id_stays_bound_to_its_first_user() {
		$this->beat( 7, 's-one' );

		wp_set_current_user( self::$other_editor_id );
		$this->assertArrayNotHasKey( Gutenberg_Sync_Engines_Heartbeat_Awareness::KEY, $this->beat( 7, 's-hijack' ) );

		$entries = get_transient( Gutenberg_Sync_Engines_Heartbeat_Awareness::transient_key( self::$post_id ) );
		$this->assertSame( self::$editor_id, $entries[7]['user_id'] );
		$this->assertSame( 's-one', $entries[7]['block'] );
	}

	public function test_an_unusable_block_value_is_stored_as_null() {
		$cases = array(
			str_repeat( 'a', Gutenberg_Sync_Engines_Heartbeat_Awareness::MAX_BLOCK_BYTES + 1 ),
			array( 'nested' => true ),
			'',
			42,
		);
		foreach ( $cases as $index => $bad ) {
			wp_set_current_user( self::$editor_id );
			$this->beat( 1, $bad );
			wp_set_current_user( self::$other_editor_id );
			$peers = $this->peers( $this->beat( 2, 's-two' ) );
			$this->assertNull( $peers[0]['block'], "case $index" );
		}
	}

	public function test_expired_entries_are_dropped() {
		$key     = Gutenberg_Sync_Engines_Heartbeat_Awareness::transient_key( self::$post_id );
		$expired = time() - Gutenberg_Sync_Engines_Heartbeat_Awareness::ttl_seconds() - 1;
		set_transient(
			$key,
			array(
				9 => array(
					'user_id'    => self::$other_editor_id,
					'name'       => 'Old',
					'avatar'     => '',
					'block'      => 's-old',
					'updated_at' => $expired,
				),
			),
			60
		);

		$this->assertSame( array(), $this->peers( $this->beat( 1, 's-one' ) ) );
		$this->assertArrayNotHasKey( 9, get_transient( $key ) );
	}

	public function test_is_active_needs_an_interval_and_the_heartbeat_channel() {
		$this->assertFalse( Gutenberg_Sync_Engines_Heartbeat_Awareness::is_active() );

		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_INTERVAL_OPTION, 5 );
		$this->assertFalse( Gutenberg_Sync_Engines_Heartbeat_Awareness::is_active(), 'Sync channel by default' );

		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_CHANNEL_OPTION, 'heartbeat' );
		$this->assertTrue( Gutenberg_Sync_Engines_Heartbeat_Awareness::is_active() );
		$this->assertSame( 60, Gutenberg_Sync_Engines_Heartbeat_Awareness::ttl_seconds(), 'Never below a minute' );

		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_INTERVAL_OPTION, 30 );
		$this->assertSame( 120, Gutenberg_Sync_Engines_Heartbeat_Awareness::ttl_seconds(), 'Four intervals' );

		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_INTERVAL_OPTION, 0 );
		$this->assertFalse( Gutenberg_Sync_Engines_Heartbeat_Awareness::is_active() );
	}

	public function test_the_heartbeat_interval_follows_the_setting_on_editor_screens_only() {
		$GLOBALS['pagenow'] = 'post.php';
		$this->assertSame( array( 'x' => 1 ), $this->awareness->filter_settings( array( 'x' => 1 ) ), 'Off: untouched' );

		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_INTERVAL_OPTION, 15 );
		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_CHANNEL_OPTION, 'heartbeat' );
		$this->assertSame( 15, $this->awareness->filter_settings( array() )['interval'] );

		$GLOBALS['pagenow'] = 'post-new.php';
		$this->assertSame( 15, $this->awareness->filter_settings( array() )['interval'] );

		$GLOBALS['pagenow'] = 'index.php';
		$this->assertArrayNotHasKey( 'interval', $this->awareness->filter_settings( array() ) );

		update_option( Gutenberg_Sync_Engines_Settings::AWARENESS_CHANNEL_OPTION, 'sync' );
		$GLOBALS['pagenow'] = 'post.php';
		$this->assertArrayNotHasKey( 'interval', $this->awareness->filter_settings( array() ), 'Sync channel leaves Heartbeat alone' );
	}
}
