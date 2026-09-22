<?php
/**
 * WP-CLI: turn real-time collaboration on or off.
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_CLI_Command' ) ) {
	/**
	 * `wp collaboration enable` and `wp collaboration disable`: the site
	 * setting behind the Settings > Collaboration checkbox.
	 *
	 * @since n.e.x.t
	 */
	class Gutenberg_Sync_Engines_CLI_Command {
		/**
		 * Turns real-time collaboration on for the site.
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration enable
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function enable(): void {
			update_option( GUTENBERG_SYNC_ENGINES_ENABLED_OPTION, true );
			WP_CLI::success( 'Real-time collaboration is on.' );
		}

		/**
		 * Turns real-time collaboration off for the site. The editor falls
		 * back to the classic post lock.
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration disable
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function disable(): void {
			update_option( GUTENBERG_SYNC_ENGINES_ENABLED_OPTION, false );
			WP_CLI::success( 'Real-time collaboration is off.' );
		}
	}

	WP_CLI::add_command( 'collaboration enable', array( new Gutenberg_Sync_Engines_CLI_Command(), 'enable' ) );
	WP_CLI::add_command( 'collaboration disable', array( new Gutenberg_Sync_Engines_CLI_Command(), 'disable' ) );
}
