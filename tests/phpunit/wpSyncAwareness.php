<?php
/**
 * Tests for WP_Sync_Awareness.
 *
 * @package gutenberg-sync-engines
 *
 * @group collaboration
 */

class Tests_Collaboration_WpSyncAwareness extends WP_UnitTestCase {
	protected static int $editor_id;
	protected static int $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$post_id   = $factory->post->create( array( 'post_author' => self::$editor_id ) );
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$editor_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
	}

	private function room(): string {
		return 'postType/post:' . self::$post_id;
	}

	private function awareness(): WP_Sync_Awareness {
		return new WP_Sync_Awareness( new WP_Sync_Table_Storage() );
	}

	/**
	 * A write to the room array is readable, and an entry past the timeout
	 * is not.
	 */
	public function test_the_built_in_store_round_trips_and_expires(): void {
		$awareness = $this->awareness();
		$room      = $this->room();

		$awareness->put( $room, 7, array( 'name' => 'Ada' ), self::$editor_id, 30 );

		$entries = $awareness->entries( $room, 30 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 7, $entries[0]['client_id'] );
		$this->assertSame( array( 'name' => 'Ada' ), $entries[0]['state'] );
		$this->assertSame( self::$editor_id, $entries[0]['wp_user_id'] );

		// A tab that stopped polling drops out without anyone sweeping.
		( new WP_Sync_Table_Storage() )->set_awareness_state(
			$room,
			array(
				array(
					'client_id'  => 7,
					'state'      => array( 'name' => 'Ada' ),
					'updated_at' => time() - 31,
					'wp_user_id' => self::$editor_id,
				),
			)
		);
		$this->assertSame( array(), $awareness->entries( $room, 30 ) );
	}

	/**
	 * A client repeating its state inside one timestamp bucket writes
	 * nothing, which is what keeps an idle poll read-only.
	 */
	public function test_repeating_the_same_state_does_not_write(): void {
		global $wpdb;
		$awareness = $this->awareness();
		$room      = $this->room();

		$awareness->put( $room, 7, array( 'name' => 'Ada' ), self::$editor_id, 30 );

		$before = $wpdb->num_queries;
		$awareness->put( $room, 7, array( 'name' => 'Ada' ), self::$editor_id, 30 );
		$writes = $wpdb->num_queries - $before;

		$this->assertLessThanOrEqual( 1, $writes, 'A repeated put should read, not write.' );
	}
}
