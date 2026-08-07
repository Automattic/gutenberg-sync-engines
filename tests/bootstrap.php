<?php
/**
 * PHPUnit bootstrap.
 *
 * These tests exercise the plugin's engines and transports through the
 * collaborative-editing FRAMEWORK (the WP_Sync_* contracts, registries, and
 * REST transport routes that ship in Gutenberg / WordPress core). The
 * framework must therefore load BEFORE this plugin — both are required in on
 * `muplugins_loaded` below. The framework's plugin entry path is taken from
 * the `WP_SYNC_FRAMEWORK_PLUGIN` env var (the bundled `.wp-env.json` points
 * it at the mapped Gutenberg plugin); adjust it for other environments.
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
		if ( $framework && file_exists( $framework ) ) {
			require $framework;
		}
		require dirname( __DIR__ ) . '/gutenberg-sync-engines.php';
	}
);

require $gse_tests_dir . '/includes/bootstrap.php';
