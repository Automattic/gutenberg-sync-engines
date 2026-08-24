<?php
/**
 * Plugin Name:       Gutenberg Sync Engines
 * Plugin URI:        https://github.com/WordPress/gutenberg
 * Description:       Pluggable real-time collaboration engines and transports for the Gutenberg collaborative-editing framework. Without this plugin active, real-time collaboration is effectively disabled.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.0.0
 * Author:            WordPress Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gutenberg-sync-engines
 *
 * @package GutenbergSyncEngines
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * In a git worktree, wp-env mounts this plugin TWICE (under the checkout's
 * directory name and as gutenberg-sync-engines), and both copies can end up
 * activated — wp-env re-activates its plugins list on every start. Loading
 * a second copy used to be a fatal redeclare; instead, let the first loaded
 * copy win and make any other mount a no-op. (The bootstrap declaration
 * below must stay inside its function_exists guard: PHP early-binds
 * unconditional top-level functions at COMPILE time, before this return
 * could run.)
 */
if ( function_exists( 'gutenberg_sync_engines_bootstrap' ) ) {
	return;
}

define( 'GUTENBERG_SYNC_ENGINES_VERSION', '0.0.0' );
define( 'GUTENBERG_SYNC_ENGINES_PATH', plugin_dir_path( __FILE__ ) );
define( 'GUTENBERG_SYNC_ENGINES_URL', plugin_dir_url( __FILE__ ) );
define( 'GUTENBERG_SYNC_ENGINES_FILE', __FILE__ );

if ( ! function_exists( 'gutenberg_sync_engines_load_bundled_gutenberg' ) ) {
	/**
	 * Loads the bundled Gutenberg plugin (the collaborative-editing framework)
	 * when no other copy of Gutenberg is present, so the release zip works on
	 * any WordPress installation with nothing else installed.
	 *
	 * The standalone-Gutenberg check must read the active-plugins options, not
	 * just look for loaded symbols: 'gutenberg-sync-engines/…' sorts BEFORE
	 * 'gutenberg/gutenberg.php' in the active-plugins list, so this plugin
	 * loads first, and loading the bundled copy while a standalone Gutenberg
	 * is about to load would fatal with duplicate declarations. (Corollary:
	 * activating a standalone Gutenberg while the bundled copy is already
	 * loaded fails that activation request safely — WordPress's plugin
	 * sandbox catches the redeclare — and the next request defers to it.)
	 *
	 * @since n.e.x.t
	 *
	 * @return void
	 */
	function gutenberg_sync_engines_load_bundled_gutenberg() {
		if ( defined( 'GUTENBERG_VERSION' ) || function_exists( 'gutenberg_pre_init' ) ) {
			return; // Gutenberg is already loaded.
		}

		$entry = GUTENBERG_SYNC_ENGINES_PATH . 'gutenberg/gutenberg.php';
		if ( ! file_exists( $entry ) ) {
			return;
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge(
				$active,
				array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
			);
		}
		if (
			in_array( 'gutenberg/gutenberg.php', $active, true )
			// A stale activation entry whose plugin file is gone (deleted
			// over FTP, an unmounted dev checkout) must not disable bundled
			// loading — WordPress itself skips missing active plugins.
			&& file_exists( WP_PLUGIN_DIR . '/gutenberg/gutenberg.php' )
		) {
			return; // A standalone Gutenberg will load; defer to it.
		}

		require_once $entry;
	}
}
gutenberg_sync_engines_load_bundled_gutenberg();

require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/class-gutenberg-sync-engines-plugin.php';

if ( ! function_exists( 'gutenberg_sync_engines_storage' ) ) {
	/**
	 * The sync storage the plugin's engines, transports, and tools use.
	 *
	 * Prefers the framework's filterable factory (`wp_get_sync_storage`,
	 * `__unstable_wp_sync_storage` filter) so a drop-in storage backend applies
	 * everywhere at once; falls back to the post-meta default on a
	 * framework build that predates the factory. Only called from
	 * framework-gated code paths.
	 *
	 * @since 0.4.0
	 *
	 * @return WP_Sync_Storage Storage implementation.
	 */
	function gutenberg_sync_engines_storage() {
		return function_exists( 'wp_get_sync_storage' )
			? wp_get_sync_storage()
			: new WP_Sync_Post_Meta_Storage();
	}
}

if ( ! function_exists( 'gutenberg_sync_engines_bootstrap' ) ) {
	/**
	 * Boots the plugin once all plugins are loaded, so the collaborative-editing
	 * framework (shipped in Gutenberg / WordPress core) is already available to
	 * feature-detect.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function gutenberg_sync_engines_bootstrap() {
		Gutenberg_Sync_Engines_Plugin::instance()->boot();
	}
}
add_action( 'plugins_loaded', 'gutenberg_sync_engines_bootstrap' );
