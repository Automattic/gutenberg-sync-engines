<?php
/**
 * Tests for WP_Sync_Awareness and the drop-in backend seam.
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
		Test_Awareness_Backend::reset();
		Fake_Presence_API::reset();
		WP_Sync_Awareness::reset_backend_for_testing();
	}

	public function tear_down() {
		Fake_Presence_API::reset();
		WP_Sync_Awareness::reset_backend_for_testing();
		parent::tear_down();
	}

	private function room(): string {
		return 'postType/post:' . self::$post_id;
	}

	private function awareness(): WP_Sync_Awareness {
		return new WP_Sync_Awareness( new WP_Sync_Table_Storage() );
	}

	private function use_test_backend(): void {
		add_filter(
			'wp_sync_awareness_backend',
			static function () {
				return new Test_Awareness_Backend();
			}
		);
		WP_Sync_Awareness::reset_backend_for_testing();
	}

	/**
	 * With no backend on the filter, a write to the room array is readable
	 * and an entry past the timeout is not.
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

	/**
	 * A backend on the filter takes over every read and write, so nothing
	 * reaches the room array.
	 */
	public function test_a_backend_takes_over_reads_and_writes(): void {
		$this->use_test_backend();
		$room = $this->room();

		$this->awareness()->put( $room, 7, array( 'name' => 'Ada' ), self::$editor_id, 30 );

		$this->assertTrue( WP_Sync_Awareness::has_substitute_backend() );
		$this->assertSame( 1, Test_Awareness_Backend::$writes );
		$this->assertSame( array( 7 ), array_column( $this->awareness()->entries( $room, 30 ), 'client_id' ) );
		$this->assertSame(
			array(),
			( new WP_Sync_Table_Storage() )->get_awareness_state( $room ),
			'The room array must stay untouched while a backend serves.'
		);
	}

	/**
	 * The REST transport answers from the backend, not the room array.
	 */
	public function test_the_rest_transport_reads_and_writes_through_the_backend(): void {
		$this->use_test_backend();
		$room = $this->room();

		$server = new WP_HTTP_Polling_Sync_Server( new WP_Sync_Table_Storage() );

		$map = $server->update_awareness( $room, 7, array( 'name' => 'Ada' ) );
		$this->assertSame( array( 7 => array( 'name' => 'Ada' ) ), $map );
		$this->assertArrayHasKey( 7, Test_Awareness_Backend::$rooms[ $room ] );

		// A null update is a disconnect: one entry goes, the room stays.
		$server->update_awareness( $room, 9, array( 'name' => 'Grace' ) );
		$map = $server->update_awareness( $room, 7, null );
		$this->assertSame( array( 9 => array( 'name' => 'Grace' ) ), $map );
	}

	/**
	 * A second client's write leaves the first's entry alone, so a backend
	 * that rewrote the whole room fails here.
	 */
	public function test_a_write_does_not_disturb_another_client(): void {
		$this->use_test_backend();
		$room      = $this->room();
		$awareness = $this->awareness();

		$awareness->put( $room, 7, array( 'name' => 'Ada' ), self::$editor_id, 30 );
		$entries = $awareness->put( $room, 9, array( 'name' => 'Grace' ), self::$editor_id, 30 );

		$this->assertSame( array( 7, 9 ), array_column( $entries, 'client_id' ) );

		$entries = $awareness->forget( $room, 7, 30 );
		$this->assertSame( array( 9 ), array_column( $entries, 'client_id' ) );
	}

	/**
	 * The Presence API backend stands down while that plugin is not
	 * recording, so the room array keeps serving.
	 */
	public function test_the_presence_api_backend_stands_down_when_not_recording(): void {
		$this->assertFalse( WP_Sync_Presence_API_Awareness_Backend::is_available() );
		$this->assertFalse( WP_Sync_Awareness::has_substitute_backend() );
	}

	/**
	 * With the Presence API recording, awareness round trips through its
	 * table and the room array is never touched.
	 */
	public function test_the_presence_api_backend_round_trips_through_the_presence_table(): void {
		Fake_Presence_API::$enabled = true;
		$room                       = $this->room();
		$awareness                  = $this->awareness();

		$this->assertTrue( WP_Sync_Awareness::has_substitute_backend() );

		$entries = $awareness->put( $room, 7, array( 'name' => 'Ada' ), self::$editor_id, 30 );
		$this->assertSame( array( 7 ), array_column( $entries, 'client_id' ) );
		$this->assertSame( array( 'name' => 'Ada' ), $entries[0]['state'] );
		$this->assertSame( self::$editor_id, $entries[0]['wp_user_id'] );

		$this->assertArrayHasKey( 'gse-7', Fake_Presence_API::$rows[ $room ] );
		$this->assertSame( array(), ( new WP_Sync_Table_Storage() )->get_awareness_state( $room ) );

		$entries = $awareness->put( $room, 9, array( 'name' => 'Grace' ), self::$editor_id, 30 );
		$this->assertSame( array( 7, 9 ), array_column( $entries, 'client_id' ) );

		$entries = $awareness->forget( $room, 7, 30 );
		$this->assertSame( array( 9 ), array_column( $entries, 'client_id' ) );
	}

	/**
	 * Rows the Presence API keeps for its own screens are not collaborators.
	 */
	public function test_the_presence_api_backend_ignores_rows_without_its_prefix(): void {
		Fake_Presence_API::$enabled = true;
		$room                       = $this->room();

		wp_set_presence( $room, 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );

		$this->assertSame( array(), $this->awareness()->entries( $room, 30 ) );
	}

	/**
	 * A site TTL longer than the awareness window cannot keep a departed
	 * collaborator in the room.
	 */
	public function test_a_long_site_ttl_does_not_widen_the_awareness_window(): void {
		Fake_Presence_API::$enabled = true;
		$room                       = $this->room();

		add_filter( 'wp_presence_default_ttl', static fn() => DAY_IN_SECONDS );
		Fake_Presence_API::$rows[ $room ]['gse-7'] = array(
			'client_id' => 'gse-7',
			'user_id'   => self::$editor_id,
			'data'      => array( 'name' => 'Ada' ),
			'date_gmt'  => gmdate( 'Y-m-d H:i:s', time() - 31 ),
		);

		$this->assertSame( array(), $this->awareness()->entries( $room, 30 ) );
	}
}

/**
 * A per-client store standing in for the Presence API's table, with one
 * entry per (room, client) written in place.
 */
class Test_Awareness_Backend implements WP_Sync_Awareness_Backend {
	/**
	 * Rooms, each a map of client id to entry.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public static array $rooms = array();

	/**
	 * How many writes the backend has taken.
	 *
	 * @var int
	 */
	public static int $writes = 0;

	public static function reset(): void {
		self::$rooms  = array();
		self::$writes = 0;
	}

	public function entries( string $room, int $timeout ): array {
		$now     = time();
		$entries = array();
		foreach ( self::$rooms[ $room ] ?? array() as $entry ) {
			if ( $now - $entry['updated_at'] < $timeout ) {
				$entries[] = $entry;
			}
		}
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a['client_id'] <=> $b['client_id'];
			}
		);
		return $entries;
	}

	public function put( string $room, int $client_id, array $state, int $user_id, int $timeout ): array {
		++self::$writes;
		self::$rooms[ $room ][ $client_id ] = array(
			'client_id'  => $client_id,
			'state'      => $state,
			'updated_at' => time(),
			'wp_user_id' => $user_id,
		);
		return $this->entries( $room, $timeout );
	}

	public function forget( string $room, int $client_id, int $timeout ): array {
		++self::$writes;
		unset( self::$rooms[ $room ][ $client_id ] );
		return $this->entries( $room, $timeout );
	}
}


/**
 * A stand-in for the Presence API plugin, so this repo can exercise the
 * backend without depending on that plugin being installed.
 *
 * The functions below are only defined when the real plugin is absent, and
 * `$enabled` is off until a test asks for it, so `is_available()` answers no
 * and the rest of the suite keeps the room array.
 */
class Fake_Presence_API {
	/**
	 * Whether the stand-in is recording.
	 *
	 * @var bool
	 */
	public static bool $enabled = false;

	/**
	 * Rows, keyed by room and then client id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public static array $rows = array();

	public static function reset(): void {
		self::$enabled = false;
		self::$rows    = array();
	}
}

if ( ! function_exists( 'wp_set_presence' ) ) {
	function wp_presence_recording_enabled() {
		return Fake_Presence_API::$enabled;
	}

	function wp_get_presence( $room, $timeout = 150 ) {
		$cutoff  = time() - (int) apply_filters( 'wp_presence_default_ttl', $timeout );
		$entries = array();
		foreach ( Fake_Presence_API::$rows[ $room ] ?? array() as $row ) {
			if ( strtotime( $row['date_gmt'] . ' UTC' ) > $cutoff ) {
				$entries[] = (object) $row;
			}
		}
		return $entries;
	}

	function wp_set_presence( $room, $client_id, $state, $user_id = 0, $date_gmt = null ) {
		Fake_Presence_API::$rows[ $room ][ $client_id ] = array(
			'client_id' => $client_id,
			'user_id'   => $user_id,
			'data'      => $state,
			'date_gmt'  => $date_gmt ?? gmdate( 'Y-m-d H:i:s' ),
		);
	}

	function wp_remove_presence( $room, $client_id ) {
		unset( Fake_Presence_API::$rows[ $room ][ $client_id ] );
	}
}
