<?php
/**
 * PHPUnit bootstrap.
 *
 * These tests exercise the plugin's engines and transports through the
 * collaborative-editing FRAMEWORK (the WP_Sync_* contracts, registries, and
 * REST transport routes that ship in Gutenberg / WordPress core). The
 * framework must therefore load BEFORE this plugin — both are required in on
 * `muplugins_loaded` below. The framework's plugin entry path resolves from,
 * in order: the `WP_SYNC_FRAMEWORK_PLUGIN` env var, a same-named constant, or
 * — for the bundled `.wp-env.json`, which mounts the pinned Gutenberg subtree
 * as the `gutenberg` plugin — the conventional `WP_PLUGIN_DIR/gutenberg`
 * path. Override the env var to point at a different Gutenberg checkout.
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

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$framework = getenv( 'WP_SYNC_FRAMEWORK_PLUGIN' );
		if ( ! $framework && defined( 'WP_SYNC_FRAMEWORK_PLUGIN' ) ) {
			$framework = WP_SYNC_FRAMEWORK_PLUGIN;
		}
		if ( ! $framework ) {
			// The bundled .wp-env.json mounts the Gutenberg subtree here.
			$framework = WP_PLUGIN_DIR . '/gutenberg/gutenberg.php';
		}
		if ( $framework && file_exists( $framework ) ) {
			require $framework;
		}
		require dirname( __DIR__ ) . '/gutenberg-sync-engines.php';
		// Test fixture engine (naive opaque relay) used by the transport and
		// registry machinery tests; registered per-test, never in production.
		require __DIR__ . '/phpunit/fixtures/class-test-opaque-relay-engine.php';
	}
);

require $gse_tests_dir . '/includes/bootstrap.php';
