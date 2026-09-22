<?php
/**
 * Tests for the plugin activation hook and the one-time upgrade routine.
 *
 * @package GutenbergSyncEngines
 */

/**
 * @group collaboration
 */
class Tests_Collaboration_GutenbergSyncEnginesActivation extends WP_UnitTestCase {
	const OPTION = 'gutenberg_sync_engines_enabled';

	public function tear_down() {
		delete_option( 'gutenberg-experiments' );
		parent::tear_down();
	}

	/**
	 * The plugin entry registers the activation callback on its own file.
	 */
	public function test_activation_hook_is_registered() {
		$this->assertSame(
			10,
			has_action( 'activate_' . plugin_basename( GUTENBERG_SYNC_ENGINES_FILE ), 'gutenberg_sync_engines_activate' )
		);
	}

	/**
	 * Activation turns collaboration on, even when it was turned off before.
	 */
	public function test_activation_turns_collaboration_on() {
		update_option( self::OPTION, false );
		$this->assertFalse( gutenberg_sync_engines_is_enabled() );

		gutenberg_sync_engines_activate( false );

		$this->assertTrue( gutenberg_sync_engines_is_enabled() );
	}

	/**
	 * Activation no longer touches Gutenberg's experiments.
	 */
	public function test_activation_leaves_the_gutenberg_experiments_alone() {
		update_option( 'gutenberg-experiments', array( 'gutenberg-something-else' => true ) );

		gutenberg_sync_engines_activate( false );

		$this->assertSame( array( 'gutenberg-something-else' => true ), get_option( 'gutenberg-experiments' ) );
	}

	/**
	 * The upgrade routine turns the retired experiment off, once.
	 */
	public function test_upgrade_turns_the_old_experiment_off_once() {
		update_option(
			'gutenberg-experiments',
			array(
				'gutenberg-real-time-collaboration' => true,
				'gutenberg-something-else'          => true,
			)
		);
		delete_option( Gutenberg_Sync_Engines_Plugin::UPGRADE_OPTION );

		$run = new ReflectionMethod( 'Gutenberg_Sync_Engines_Plugin', 'maybe_upgrade' );
		$run->setAccessible( true );
		$run->invoke( Gutenberg_Sync_Engines_Plugin::instance() );

		$this->assertSame( array( 'gutenberg-something-else' => true ), get_option( 'gutenberg-experiments' ) );
		$this->assertSame( Gutenberg_Sync_Engines_Plugin::UPGRADE_VERSION, (int) get_option( Gutenberg_Sync_Engines_Plugin::UPGRADE_OPTION ) );

		// A later re-enable by the user is not undone.
		update_option( 'gutenberg-experiments', array( 'gutenberg-real-time-collaboration' => true ) );
		$run->invoke( Gutenberg_Sync_Engines_Plugin::instance() );
		$this->assertSame( array( 'gutenberg-real-time-collaboration' => true ), get_option( 'gutenberg-experiments' ) );
	}
}
