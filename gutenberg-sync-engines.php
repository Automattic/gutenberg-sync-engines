<?php
/**
 * Plugin Name:       Gutenberg Sync Engines
 * Plugin URI:        https://github.com/WordPress/gutenberg
 * Description:       Pluggable real-time collaboration engines and transports for the Gutenberg collaborative-editing framework. Without this plugin active, real-time collaboration is effectively disabled.
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Version:           0.1.0
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

define( 'GUTENBERG_SYNC_ENGINES_VERSION', '0.1.0' );
define( 'GUTENBERG_SYNC_ENGINES_PATH', plugin_dir_path( __FILE__ ) );
define( 'GUTENBERG_SYNC_ENGINES_URL', plugin_dir_url( __FILE__ ) );
define( 'GUTENBERG_SYNC_ENGINES_FILE', __FILE__ );

require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/class-gutenberg-sync-engines-plugin.php';

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
add_action( 'plugins_loaded', 'gutenberg_sync_engines_bootstrap' );
