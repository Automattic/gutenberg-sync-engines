<?php
/**
 * Tests for the plugin's enable setting and what it gates.
 *
 * @package GutenbergSyncEngines
 *
 * @group collaboration
 */
class Tests_Collaboration_EnabledOption extends WP_UnitTestCase {

	public function tear_down() {
		remove_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );
		// A test below installs a bare REST server; hand the next user a fresh one.
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	public function test_collaboration_is_enabled_by_default() {
		delete_option( 'gutenberg_sync_engines_enabled' );

		$this->assertTrue( gutenberg_sync_engines_is_enabled() );
	}

	public function test_collaboration_follows_the_option() {
		update_option( 'gutenberg_sync_engines_enabled', false );
		$this->assertFalse( gutenberg_sync_engines_is_enabled() );

		update_option( 'gutenberg_sync_engines_enabled', true );
		$this->assertTrue( gutenberg_sync_engines_is_enabled() );
	}

	public function test_the_option_is_exposed_to_the_rest_settings_endpoint() {
		$settings = get_registered_settings();

		$this->assertArrayHasKey( 'gutenberg_sync_engines_enabled', $settings );
		$this->assertTrue( $settings['gutenberg_sync_engines_enabled']['show_in_rest'] );
		$this->assertSame( 'boolean', $settings['gutenberg_sync_engines_enabled']['type'] );
	}

	public function test_disabled_collaboration_does_not_register_the_storage_post_type() {
		unregister_post_type( 'wp_sync_storage' );
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );

		gutenberg_sync_engines_register_storage_post_type();
		$this->assertFalse( post_type_exists( 'wp_sync_storage' ) );

		remove_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );
		gutenberg_sync_engines_register_storage_post_type();
		$this->assertTrue( post_type_exists( 'wp_sync_storage' ) );
	}

	public function test_disabled_collaboration_does_not_override_the_autosaves_controller() {
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );

		$this->assertArrayNotHasKey(
			'autosave_rest_controller_class',
			gutenberg_sync_engines_override_autosaves_rest_controller( array() )
		);
	}

	public function test_disabled_collaboration_does_not_register_the_sync_routes() {
		add_filter( 'pre_option_gutenberg_sync_engines_enabled', '__return_zero' );

		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		gutenberg_sync_engines_register_rest_routes();

		$this->assertSame( array(), $server->get_routes( 'wp-sync/v1' ) );
	}
}
