<?php
/**
 * Tests for the editor announcement and its screen verdict.
 *
 * @package GutenbergSyncEngines
 *
 * @group collaboration
 */
class Tests_Collaboration_Announcement extends WP_UnitTestCase {

	private static int $admin_id;
	private static int $other_id;
	private static int $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$other_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$post_id  = $factory->post->create( array( 'post_type' => 'post' ) );
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$admin_id );
		self::delete_user( self::$other_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$admin_id );
		gutenberg_sync_engines_screen_verdict( null, true );
		wp_deregister_script( 'gutenberg-sync-engines' );
		wp_register_script( 'gutenberg-sync-engines', 'https://example.com/sync-engines.js', array(), '1', true );
	}

	public function tear_down() {
		remove_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );
		remove_filter( 'wp_is_post_type_collaboration_disabled', '__return_true' );
		remove_filter( 'wp_sync_engines', '__return_empty_array', 20 );
		remove_filter( 'wp_sync_transports', '__return_empty_array', 20 );
		delete_post_meta( self::$post_id, '_edit_lock' );
		$GLOBALS['wp_meta_boxes'] = array();
		gutenberg_sync_engines_screen_verdict( null, true );
		wp_deregister_script( 'gutenberg-sync-engines' );
		parent::tear_down();
	}

	/**
	 * The inline script attached before the bundle, without the trailing
	 * source URL comment WordPress appends.
	 */
	private function inline_before(): string {
		$data = wp_scripts()->get_data( 'gutenberg-sync-engines', 'before' );
		return is_array( $data ) ? implode( "\n", array_filter( $data ) ) : '';
	}

	private function verdict(): array {
		return gutenberg_sync_engines_screen_verdict( get_post( self::$post_id ) );
	}

	public function test_a_plain_post_screen_is_supported() {
		$verdict = $this->verdict();

		$this->assertTrue( $verdict['supported'] );
		$this->assertSame( '', $verdict['reason'] );
		$this->assertSame( 'post', $verdict['postType'] );
		$this->assertSame( self::$post_id, $verdict['postId'] );
		$this->assertNull( $verdict['lockedBy'] );
	}

	public function test_disabled_collaboration_is_not_supported() {
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );

		$verdict = $this->verdict();

		$this->assertFalse( $verdict['supported'] );
		$this->assertSame( 'disabled', $verdict['reason'] );
	}

	public function test_no_engine_is_not_supported() {
		add_filter( 'wp_sync_engines', '__return_empty_array', 20 );

		$this->assertSame( 'no-engine', $this->verdict()['reason'] );
	}

	public function test_no_transport_is_not_supported() {
		add_filter( 'wp_sync_transports', '__return_empty_array', 20 );

		$this->assertSame( 'no-transport', $this->verdict()['reason'] );
	}

	public function test_no_post_is_not_supported() {
		$verdict = gutenberg_sync_engines_screen_verdict( null );

		$this->assertFalse( $verdict['supported'] );
		$this->assertSame( 'no-post', $verdict['reason'] );
		$this->assertNull( $verdict['postType'] );
	}

	public function test_a_disabled_post_type_is_not_supported() {
		add_filter( 'wp_is_post_type_collaboration_disabled', '__return_true' );

		$this->assertSame( 'post-type-disabled', $this->verdict()['reason'] );
	}

	public function test_an_incompatible_meta_box_is_not_supported() {
		set_current_screen( 'post' );
		add_meta_box( 'legacy-box', 'Legacy', '__return_null', 'post' );

		$this->assertSame( 'incompatible-meta-box', $this->verdict()['reason'] );
	}

	public function test_compatible_and_back_compat_meta_boxes_are_fine() {
		set_current_screen( 'post' );
		add_meta_box( 'compatible-box', 'Compatible', '__return_null', 'post', 'normal', 'default', array( '__rtc_compatible_meta_box' => true ) );
		add_meta_box( 'core-box', 'Core', '__return_null', 'post', 'normal', 'default', array( '__back_compat_meta_box' => true ) );

		$this->assertTrue( $this->verdict()['supported'] );
	}

	public function test_the_verdict_names_the_user_holding_the_lock() {
		update_post_meta( self::$post_id, '_edit_lock', time() . ':' . self::$other_id );

		$locked_by = $this->verdict()['lockedBy'];

		$this->assertSame( get_userdata( self::$other_id )->display_name, $locked_by['name'] );
		$this->assertNotEmpty( $locked_by['avatar'] );
	}

	public function test_the_announcement_carries_the_engine_transports_and_screen() {
		$announcement = gutenberg_sync_engines_announcement( get_post( self::$post_id ) );

		$this->assertSame(
			array( 'engine', 'engineProtocol', 'transports', 'transportProtocol', 'transportConfig', 'userId', 'canUnfilteredHtml', 'disabledPostTypes', 'screen' ),
			array_keys( $announcement )
		);
		$this->assertSame( 'intent-log', $announcement['engine'] );
		$this->assertContains( 'http-polling', $announcement['transports'] );
		$this->assertSame( self::$admin_id, $announcement['userId'] );
		$this->assertTrue( $announcement['screen']['supported'] );
	}

	public function test_the_announcement_is_printed_before_the_bundle() {
		$context = new WP_Block_Editor_Context( array( 'post' => get_post( self::$post_id ) ) );

		$settings = gutenberg_sync_engines_print_announcement( array( 'a' => 1 ), $context );
		$inline   = $this->inline_before();

		$this->assertSame( array( 'a' => 1 ), $settings );
		$this->assertStringStartsWith( 'window._gutenbergSyncEnginesSync = ', $inline );
		$decoded = json_decode( substr( trim( $inline ), strlen( 'window._gutenbergSyncEnginesSync = ' ), -1 ), true );
		$this->assertSame( self::$post_id, $decoded['screen']['postId'] );
	}

	public function test_nothing_is_printed_while_collaboration_is_off() {
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );
		$context = new WP_Block_Editor_Context( array( 'post' => get_post( self::$post_id ) ) );

		gutenberg_sync_engines_print_announcement( array(), $context );

		$this->assertSame( '', $this->inline_before() );
	}
}
