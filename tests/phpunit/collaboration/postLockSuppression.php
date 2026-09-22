<?php
/**
 * Tests for the post lock handling on collaborative screens.
 *
 * @package GutenbergSyncEngines
 *
 * @group collaboration
 */
class Tests_Collaboration_PostLockSuppression extends WP_UnitTestCase {

	private static int $admin_id;
	private static int $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$post_id  = $factory->post->create( array( 'post_type' => 'post' ) );
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$admin_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$admin_id );
		gutenberg_sync_engines_screen_verdict( null, true );
	}

	public function tear_down() {
		remove_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );
		remove_filter( 'wp_is_post_type_collaboration_disabled', '__return_true' );
		gutenberg_sync_engines_screen_verdict( null, true );
		parent::tear_down();
	}

	private function locked_settings(): array {
		return array(
			'postLock' => array(
				'isLocked' => true,
				'user'     => array( 'name' => 'Someone' ),
			),
		);
	}

	private function context(): WP_Block_Editor_Context {
		return new WP_Block_Editor_Context( array( 'post' => get_post( self::$post_id ) ) );
	}

	public function test_the_filters_are_hooked() {
		$this->assertSame( 10, has_filter( 'block_editor_settings_all', 'gutenberg_sync_engines_suppress_post_lock' ) );
		$this->assertSame( 20, has_filter( 'heartbeat_received', 'gutenberg_sync_engines_strip_post_lock_error' ) );
	}

	public function test_a_supported_screen_is_reported_unlocked() {
		$settings = gutenberg_sync_engines_suppress_post_lock( $this->locked_settings(), $this->context() );

		$this->assertFalse( $settings['postLock']['isLocked'] );
		$this->assertSame( array( 'name' => 'Someone' ), $settings['postLock']['user'] );
	}

	public function test_the_lock_stays_when_collaboration_is_off() {
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );

		$settings = gutenberg_sync_engines_suppress_post_lock( $this->locked_settings(), $this->context() );

		$this->assertTrue( $settings['postLock']['isLocked'] );
	}

	public function test_the_lock_stays_for_a_disabled_post_type() {
		add_filter( 'wp_is_post_type_collaboration_disabled', '__return_true' );

		$settings = gutenberg_sync_engines_suppress_post_lock( $this->locked_settings(), $this->context() );

		$this->assertTrue( $settings['postLock']['isLocked'] );
	}

	public function test_the_lock_stays_without_a_post_context() {
		$settings = gutenberg_sync_engines_suppress_post_lock( $this->locked_settings(), new WP_Block_Editor_Context() );

		$this->assertTrue( $settings['postLock']['isLocked'] );
	}

	private function heartbeat( array $response ): array {
		return gutenberg_sync_engines_strip_post_lock_error(
			$response,
			array( 'wp-refresh-post-lock' => array( 'post_id' => self::$post_id ) )
		);
	}

	public function test_the_takeover_error_is_removed_from_the_heartbeat() {
		$response = $this->heartbeat( array( 'wp-refresh-post-lock' => array( 'lock_error' => array( 'name' => 'Someone' ) ) ) );

		$this->assertSame( array( 'wp-refresh-post-lock' => array() ), $response );
	}

	public function test_a_new_lock_passes_through_the_heartbeat() {
		$response = $this->heartbeat( array( 'wp-refresh-post-lock' => array( 'new_lock' => '1:2' ) ) );

		$this->assertSame( array( 'wp-refresh-post-lock' => array( 'new_lock' => '1:2' ) ), $response );
	}

	public function test_the_takeover_error_stays_when_collaboration_is_off() {
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );

		$response = $this->heartbeat( array( 'wp-refresh-post-lock' => array( 'lock_error' => array( 'name' => 'Someone' ) ) ) );

		$this->assertArrayHasKey( 'lock_error', $response['wp-refresh-post-lock'] );
	}

	public function test_the_takeover_error_stays_for_a_disabled_post_type() {
		add_filter( 'wp_is_post_type_collaboration_disabled', '__return_true' );

		$response = $this->heartbeat( array( 'wp-refresh-post-lock' => array( 'lock_error' => array( 'name' => 'Someone' ) ) ) );

		$this->assertArrayHasKey( 'lock_error', $response['wp-refresh-post-lock'] );
	}
}
