<?php
/**
 * Gutenberg_Sync_Engines_Bench_Log_CLI_Command class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Bench_Log_CLI_Command' ) && defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Reads and manages the per-request benchmark log (see
	 * Gutenberg_Sync_Engines_Request_Log): the community-harness-convention
	 * server-side metrics recorded for tagged /wp-sync/ requests.
	 *
	 * A development tool, loaded only where diagnostics are allowed — see
	 * Gutenberg_Sync_Engines_Plugin::load().
	 *
	 * @since 0.4.0
	 */
	final class Gutenberg_Sync_Engines_Bench_Log_CLI_Command {

		/**
		 * Prints the aggregate report (the community harness's table
		 * layout: per scenario × poll-delay × update-size, with a ratio to
		 * the `baseline` scenario).
		 *
		 * ## OPTIONS
		 *
		 * [--all]
		 * : Group per approach label (engine/transport) first, like the
		 * community harness's report-all.
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration bench-log report
		 *     wp collaboration bench-log report --all
		 *
		 * @since 0.4.0
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function report( $args, $assoc_args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $args is part of the WP-CLI command signature.
			$text = Gutenberg_Sync_Engines_Request_Log::report_text( null, isset( $assoc_args['all'] ) );
			if ( '' === $text ) {
				WP_CLI::log( 'The benchmark log is empty. Tagged requests (X-RTC-Test: 1) populate it.' );
				return;
			}
			WP_CLI::line( $text );
		}

		/**
		 * Clears the benchmark log rows (table intact).
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration bench-log clear
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public function clear() {
			global $wpdb;
			Gutenberg_Sync_Engines_Request_Log::ensure_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Diagnostics-only table.
			$deleted = (int) $wpdb->query( 'DELETE FROM `' . Gutenberg_Sync_Engines_Request_Log::table() . '`' );
			WP_CLI::success( "Cleared {$deleted} log rows." );
		}

		/**
		 * Prints the environment snapshot (JSON).
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration bench-log env
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public function env() {
			WP_CLI::line( (string) wp_json_encode( Gutenberg_Sync_Engines_Request_Log::environment(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}

	WP_CLI::add_command( 'collaboration bench-log', 'Gutenberg_Sync_Engines_Bench_Log_CLI_Command' );
}
