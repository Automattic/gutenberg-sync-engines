<?php
/**
 * PHPUnit bootstrap.
 *
 * The plugin's bundled Gutenberg is required first (the block editor, and
 * the entity sync seam the client plugs into), then the plugin itself,
 * both on `muplugins_loaded`. `WP_SYNC_FRAMEWORK_PLUGIN` (env var or
 * constant) points at a different Gutenberg checkout when co-developing.
 *
 * @package GutenbergSyncEngines
 */

$gse_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $gse_tests_dir ) {
	$gse_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( ! $gse_tests_dir ) {
	$gse_tests_dir = '/wordpress-phpunit';
}

require_once $gse_tests_dir . '/includes/functions.php';

// The diagnostics module (request log, session capture) is environment-gated
// in the plugin bootstrap; force it on so its tests behave the same whether
// or not the test environment reports 'local'.
if ( ! defined( 'GUTENBERG_SYNC_ENGINES_DIAGNOSTICS' ) ) {
	define( 'GUTENBERG_SYNC_ENGINES_DIAGNOSTICS', true );
}

// As Gutenberg's own suite does: keeps the autosave controllers (core's and
// the plugin's) from defining DOING_AUTOSAVE, which would stick for the
// whole process and stop every later save from creating a revision.
if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
	define( 'WP_RUN_CORE_TESTS', true );
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$gutenberg = getenv( 'WP_SYNC_FRAMEWORK_PLUGIN' );
		if ( ! $gutenberg && defined( 'WP_SYNC_FRAMEWORK_PLUGIN' ) ) {
			$gutenberg = WP_SYNC_FRAMEWORK_PLUGIN;
		}
		if ( ! $gutenberg ) {
			$gutenberg = dirname( __DIR__ ) . '/gutenberg/gutenberg.php';
		}
		if ( $gutenberg && file_exists( $gutenberg ) ) {
			require $gutenberg;
		}
		require dirname( __DIR__ ) . '/gutenberg-sync-engines.php';
		// Test fixture engine (naive opaque relay) used by the transport and
		// registry machinery tests; registered per-test, never in production.
		// It implements the engine contract, which the plugin loads when it
		// boots on `plugins_loaded`, so it is required right after that.
		tests_add_filter(
			'plugins_loaded',
			static function () {
				require __DIR__ . '/phpunit/fixtures/class-test-opaque-relay-engine.php';
			},
			11
		);

		// Real-time collaboration is on for the whole suite; the gate is
		// consulted on `init` (the storage post type) and on `rest_api_init`
		// (the transport routes), earlier than any test's set_up.
		update_option( 'gutenberg_sync_engines_enabled', true );
	}
);

/*
 * The plugin's storage tables. Activation hooks never fire under PHPUnit,
 * and the WP test installer only recreates WordPress's own tables, so the
 * plugin's tables persist across runs while the options table (with the
 * recorded schema version) does not. The plugin's own upgrade path
 * recreates them on `plugins_loaded`; this runs after it and EMPTIES them,
 * so a row that escaped a previous run's transaction rollback cannot leak
 * into this one (post ids restart at 1 every run, so stale rooms would
 * collide). Outside any test transaction — dbDelta's DDL would commit one.
 */
tests_add_filter(
	'plugins_loaded',
	static function () {
		if ( ! WP_Sync_Table_Schema::install() ) {
			fwrite( STDERR, "Could not create the plugin's storage tables.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			exit( 1 );
		}
		WP_Sync_Table_Schema::delete_all_rows();
	},
	20
);

require $gse_tests_dir . '/includes/bootstrap.php';
