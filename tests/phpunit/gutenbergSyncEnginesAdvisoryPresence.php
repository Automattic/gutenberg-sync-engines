<?php
/**
 * Tests for Gutenberg_Sync_Engines_Advisory_Presence: the per-tab presence
 * tokens, the heartbeat discovery answer, the handshake mailbox, and the
 * leave beacon behind the advisory channel.
 *
 * @package gutenberg-sync-engines
 *
 * @group collaboration
 */
class Tests_Collaboration_GutenbergSyncEnginesAdvisoryPresence extends WP_UnitTestCase {

	protected static int $editor_id;
	protected static int $other_editor_id;
	protected static int $subscriber_id;
	protected static int $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id       = $factory->user->create( array( 'role' => 'editor' ) );
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

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
		$this->presence = new Gutenberg_Sync_Engines_Advisory_Presence();
	}

	/**
	 * @var Gutenberg_Sync_Engines_Advisory_Presence
	 */
	private $presence;

	private function room(): string {
		return 'postType/post:' . self::$post_id;
	}

	private function beat( string $token, array $extra = array() ): array {
		$data     = array(
			Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY => array_merge(
				array(
					'room'  => $this->room(),
					'token' => $token,
				),
				$extra
			),
		);
		$response = $this->presence->answer_heartbeat( array(), $data );
		return $response[ Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY ] ?? array();
	}

	public function test_editor_settings_stamp_a_token_and_report_no_company_for_the_first_tab() {
		$settings = $this->presence->editor_settings( get_post( self::$post_id ) );

		$this->assertSame( $this->room(), $settings['room'] );
		$this->assertNotEmpty( $settings['token'] );
		$this->assertFalse( $settings['othersPresent'] );
		$this->assertSame( 8, $settings['maxPeers'] );
		$this->assertNotEmpty( $settings['iceServers'] );
		$this->assertStringContainsString( '/advisory/leave', $settings['leaveUrl'] );

		// The stamped token is visible to a second tab at once.
		$second = $this->presence->editor_settings( get_post( self::$post_id ) );
		$this->assertTrue( $second['othersPresent'] );
	}

	public function test_editor_settings_are_withheld_from_users_who_may_not_sync_and_when_disabled() {
		wp_set_current_user( self::$subscriber_id );
		$this->assertNull( $this->presence->editor_settings( get_post( self::$post_id ) ) );

		wp_set_current_user( self::$editor_id );
		add_filter( 'gutenberg_sync_engines_advisory_enabled', '__return_false' );
		$this->assertNull( $this->presence->editor_settings( get_post( self::$post_id ) ) );
		remove_filter( 'gutenberg_sync_engines_advisory_enabled', '__return_false' );
	}

	public function test_heartbeat_answers_company_and_peers_from_tokens() {
		$alone = $this->beat( 'tok-a', array( 'client_id' => 11 ) );
		$this->assertFalse( $alone['others'] );
		$this->assertSame( array(), $alone['peers'] );
		$this->assertSame( array(), $alone['signals'] );

		wp_set_current_user( self::$other_editor_id );
		$joined = $this->beat( 'tok-b', array( 'client_id' => 22 ) );
		$this->assertTrue( $joined['others'] );
		$this->assertSame(
			array(
				array(
					'token'     => 'tok-a',
					'client_id' => 11,
					'user_id'   => self::$editor_id,
				),
			),
			$joined['peers']
		);

		wp_set_current_user( self::$editor_id );
		$again = $this->beat( 'tok-a', array( 'client_id' => 11 ) );
		$this->assertTrue( $again['others'] );
		$this->assertSame( 'tok-b', $again['peers'][0]['token'] );
		$this->assertSame( 22, $again['peers'][0]['client_id'] );
	}

	public function test_heartbeat_ignores_probes_without_permission_or_for_non_post_rooms() {
		wp_set_current_user( self::$subscriber_id );
		$response = $this->presence->answer_heartbeat(
			array( 'keep' => 1 ),
			array(
				Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY => array(
					'room'  => $this->room(),
					'token' => 'tok-x',
				),
			)
		);
		$this->assertSame( array( 'keep' => 1 ), $response );

		wp_set_current_user( self::$editor_id );
		$response = $this->presence->answer_heartbeat(
			array(),
			array(
				Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY => array(
					'room'  => 'taxonomy/category',
					'token' => 'tok-x',
				),
			)
		);
		$this->assertArrayNotHasKey( Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY, $response );

		// The rejected probes left no trace: the first real tab is alone.
		$this->assertFalse( $this->beat( 'tok-a' )['others'] );
	}

	public function test_heartbeat_relays_signals_to_live_peers_only_and_drains_the_mailbox() {
		$this->beat( 'tok-a' );
		wp_set_current_user( self::$other_editor_id );
		$this->beat( 'tok-b' );

		// A sends B an offer, plus junk: to itself, to an unknown token, an
		// unknown kind, an oversized payload.
		wp_set_current_user( self::$editor_id );
		$this->beat(
			'tok-a',
			array(
				'signals' => array(
					array(
						'to'   => 'tok-b',
						'kind' => 'offer',
						'data' => 'sdp-offer',
					),
					array(
						'to'   => 'tok-a',
						'kind' => 'offer',
						'data' => 'self',
					),
					array(
						'to'   => 'tok-nobody',
						'kind' => 'offer',
						'data' => 'nobody',
					),
					array(
						'to'   => 'tok-b',
						'kind' => 'shout',
						'data' => 'x',
					),
					array(
						'to'   => 'tok-b',
						'kind' => 'ice',
						'data' => str_repeat( 'x', Gutenberg_Sync_Engines_Advisory_Presence::MAX_SIGNAL_DATA_BYTES + 1 ),
					),
				),
			)
		);

		wp_set_current_user( self::$other_editor_id );
		$answer = $this->beat( 'tok-b' );
		$this->assertSame(
			array(
				array(
					'from' => 'tok-a',
					'kind' => 'offer',
					'data' => 'sdp-offer',
				),
			),
			$answer['signals']
		);

		// Delivered once.
		$this->assertSame( array(), $this->beat( 'tok-b' )['signals'] );
	}

	public function test_leave_forgets_the_token_and_its_mail() {
		$this->beat( 'tok-a' );
		wp_set_current_user( self::$other_editor_id );
		$this->beat(
			'tok-b',
			array(
				'signals' => array(
					array(
						'to'   => 'tok-a',
						'kind' => 'offer',
						'data' => 'o',
					),
				),
			)
		);

		wp_set_current_user( self::$editor_id );
		$request = new WP_REST_Request( 'POST', '/gutenberg-sync-engines/v1/advisory/leave' );
		$request->set_param( 'room', $this->room() );
		$request->set_param( 'token', 'tok-a' );
		$response = $this->presence->handle_leave( $request );
		$this->assertSame( 204, $response->get_status() );

		wp_set_current_user( self::$other_editor_id );
		$answer = $this->beat( 'tok-b' );
		$this->assertFalse( $answer['others'] );
		$this->assertSame( array(), $answer['peers'] );

		// A returning tab with the old token starts with an empty mailbox.
		wp_set_current_user( self::$editor_id );
		$this->assertSame( array(), $this->beat( 'tok-a' )['signals'] );
	}

	public function test_company_is_also_seen_through_live_sync_awareness() {
		// A tab without the presence lane (an older bundle) that still
		// polls shows up in the room's awareness; that counts as company.
		$storage = gutenberg_sync_engines_storage();
		$storage->set_awareness_state(
			$this->room(),
			array(
				array(
					'client_id'  => 77,
					'state'      => array( 'user' => 'x' ),
					'updated_at' => time(),
					'wp_user_id' => self::$other_editor_id,
				),
			)
		);
		$presence = new Gutenberg_Sync_Engines_Advisory_Presence( $storage );
		$response = $presence->answer_heartbeat(
			array(),
			array(
				Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY => array(
					'room'      => $this->room(),
					'token'     => 'tok-a',
					'client_id' => 11,
				),
			)
		);
		$answer   = $response[ Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY ];
		$this->assertTrue( $answer['others'] );
		$this->assertSame( array(), $answer['peers'] );

		// The tab's own awareness entry is not company.
		$storage->set_awareness_state(
			$this->room(),
			array(
				array(
					'client_id'  => 11,
					'state'      => array( 'user' => 'me' ),
					'updated_at' => time(),
					'wp_user_id' => self::$editor_id,
				),
			)
		);
		$response = $presence->answer_heartbeat(
			array(),
			array(
				Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY => array(
					'room'      => $this->room(),
					'token'     => 'tok-a',
					'client_id' => 11,
				),
			)
		);
		$this->assertFalse( $response[ Gutenberg_Sync_Engines_Advisory_Presence::HEARTBEAT_KEY ]['others'] );
	}

	public function test_presence_reads_never_create_a_storage_post() {
		$this->beat( 'tok-a' );
		$posts = get_posts(
			array(
				'post_type'   => 'wp_sync_storage',
				'post_status' => 'any',
				'name'        => md5( $this->room() ),
				'fields'      => 'ids',
			)
		);
		$this->assertSame( array(), $posts );
	}
}
