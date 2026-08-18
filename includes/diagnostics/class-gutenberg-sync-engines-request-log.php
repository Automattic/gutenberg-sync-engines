<?php
/**
 * Gutenberg_Sync_Engines_Request_Log class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Request_Log' ) ) {

	/**
	 * Per-request server-side metrics for tagged `/wp-sync/` REST requests,
	 * following the conventions of the community RTC performance harness
	 * (WordPress/distributed-rtc-performance-testing) so numbers line up with
	 * community-published tables:
	 *
	 * - the same request tags (`X-RTC-Test`, `X-RTC-Scenario`,
	 *   `X-RTC-Approach`, `X-RTC-Poll-Delay`, `X-RTC-Update-Size` headers,
	 *   with `_rtctest` / `_rtcscenario` / `_rtcapproach` / `_rtcpolldelay` /
	 *   `_rtcupdatesize` query fallbacks for proxies that strip headers);
	 * - the same log columns (`ms` = dispatch wall time, `total_ms` = wall
	 *   time since REQUEST_TIME_FLOAT, `cpu_ms` / `total_cpu_ms` via
	 *   getrusage, `db_queries`, `db_time_ms` via SAVEQUERIES,
	 *   `memory_delta` / `peak_memory`, `updates_in` / `updates_out`,
	 *   `concurrent` = simultaneous tagged requests);
	 * - the same REST surface (`rtc-test/v1`: `/log` GET + DELETE, `/env`,
	 *   `/report`, `/report-all`) and the same report table layout, so the
	 *   community repo's `report` tooling reads this site natively.
	 *
	 * Differences are additive only: when no `X-RTC-Approach` label is sent,
	 * rows are auto-labeled `<engine>/<transport>` — the axis this plugin
	 * compares — instead of the community harness's storage-approach labels.
	 *
	 * Untagged requests pay one autoloaded-option-free header check and
	 * nothing else. This file only loads on local/development sites (or under
	 * the GUTENBERG_SYNC_ENGINES_DIAGNOSTICS constant) — see
	 * Gutenberg_Sync_Engines_Plugin::load().
	 *
	 * @since 0.4.0
	 */
	final class Gutenberg_Sync_Engines_Request_Log {

		/**
		 * Log table schema version (bumped when columns change; the table is
		 * dropped and recreated on mismatch — it holds only measurements).
		 *
		 * @since 0.4.0
		 * @var string
		 */
		const DB_VERSION = '1';

		/**
		 * Option holding the installed schema version.
		 *
		 * @since 0.4.0
		 * @var string
		 */
		const DB_VERSION_OPTION = 'gutenberg_sync_engines_request_log_db_version';

		/**
		 * Option backing the concurrent-tagged-requests counter. Written with
		 * direct atomic SQL (never the options API) so parallel requests
		 * cannot lose increments.
		 *
		 * @since 0.4.0
		 * @var string
		 */
		const CONCURRENT_OPTION = 'gutenberg_sync_engines_request_log_concurrent';

		/**
		 * REST namespace — deliberately the community harness's namespace so
		 * its report tooling works against this site unchanged.
		 *
		 * @since 0.4.0
		 * @var string
		 */
		const REST_NAMESPACE = 'rtc-test/v1';

		/**
		 * Wall-clock start of the tagged dispatch (microtime float), or null
		 * when the current request is not being measured.
		 *
		 * @since 0.4.0
		 * @var float|null
		 */
		private $wall_start = null;

		/**
		 * Baselines snapshotted at pre-dispatch for delta metrics.
		 *
		 * @since 0.4.0
		 * @var array{queries: int, db_time: float, memory: int, cpu_us: int, concurrent: int}
		 */
		private $start = array();

		/**
		 * Process CPU microseconds at register() time — the earliest point
		 * this plugin can observe. `total_cpu_ms` is measured from here, so
		 * it slightly understates full-request CPU compared to the community
		 * MU-plugin (which loads earlier); `cpu_ms` (dispatch only) is
		 * unaffected.
		 *
		 * @since 0.4.0
		 * @var int
		 */
		private $boot_cpu_us = 0;

		/**
		 * Whether an instance has hooked the filters (double registration
		 * would record every request twice).
		 *
		 * @since 0.4.0
		 * @var bool
		 */
		private static $registered = false;

		/**
		 * Hooks the measurement filters and REST routes. Idempotent: only
		 * the first instance registers.
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public function register(): void {
			if ( self::$registered ) {
				return;
			}
			self::$registered  = true;
			$this->boot_cpu_us = $this->cpu_us();
			add_filter( 'rest_pre_dispatch', array( $this, 'pre_dispatch' ), 5, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'post_dispatch' ), 99, 3 );
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * The log table name.
		 *
		 * @since 0.4.0
		 *
		 * @return string Table name.
		 */
		public static function table(): string {
			global $wpdb;
			return $wpdb->prefix . 'sync_bench_requests';
		}

		/**
		 * Creates (or recreates) the log table when missing or outdated.
		 *
		 * Column set mirrors the community harness's `rtctest_log` table so
		 * `/log` rows and reports are shape-compatible.
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public static function ensure_table(): void {
			global $wpdb;
			$table = self::table();

			if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema spot-check on a diagnostics-only table.
				if ( null !== $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'concurrent'" ) ) {
					return;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measurement-only table; recreating is always safe.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			delete_option( self::DB_VERSION_OPTION );

			$charset_collate = $wpdb->get_charset_collate();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct CREATE TABLE (dbDelta's format strictness can fail silently).
			$wpdb->query(
				"CREATE TABLE `{$table}` (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					ts int(11) NOT NULL DEFAULT 0,
					approach varchar(60) NOT NULL DEFAULT '',
					scenario varchar(100) NOT NULL DEFAULT 'unknown',
					poll_delay smallint NOT NULL DEFAULT -1,
					update_size varchar(20) NOT NULL DEFAULT '',
					ms float NOT NULL DEFAULT 0,
					total_ms float NOT NULL DEFAULT 0,
					cpu_ms float NOT NULL DEFAULT 0,
					total_cpu_ms float NOT NULL DEFAULT 0,
					db_queries int(11) NOT NULL DEFAULT 0,
					db_time_ms float NOT NULL DEFAULT 0,
					memory_delta bigint(20) NOT NULL DEFAULT 0,
					peak_memory bigint(20) NOT NULL DEFAULT 0,
					status int(11) NOT NULL DEFAULT 200,
					rooms int(11) NOT NULL DEFAULT 0,
					updates_in int(11) NOT NULL DEFAULT 0,
					updates_out int(11) NOT NULL DEFAULT 0,
					response_bytes int(11) NOT NULL DEFAULT 0,
					awareness_count int(11) NOT NULL DEFAULT 0,
					should_compact tinyint(1) NOT NULL DEFAULT 0,
					total_updates int(11) NOT NULL DEFAULT 0,
					concurrent int(11) NOT NULL DEFAULT 0,
					PRIMARY KEY (id),
					KEY approach_scenario (approach, scenario),
					KEY ts (ts)
				) {$charset_collate}"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verify creation before recording the version.
			if ( null !== $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'concurrent'" ) ) {
				update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
			}
		}

		/**
		 * Drops the log table and clears its version/counter options.
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public static function drop_table(): void {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Diagnostics-only table.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::table() . '`' );
			delete_option( self::DB_VERSION_OPTION );
			delete_option( self::CONCURRENT_OPTION );
		}

		/**
		 * Snapshots measurement baselines when a tagged wp-sync request
		 * enters dispatch.
		 *
		 * @since 0.4.0
		 *
		 * @param mixed           $result  Dispatch short-circuit value.
		 * @param WP_REST_Server  $server  REST server (unused).
		 * @param WP_REST_Request $request Request being dispatched.
		 * @return mixed Unmodified $result.
		 */
		public function pre_dispatch( $result, $server, $request ) {
			if ( ! $this->is_tagged( $request ) || false === strpos( $request->get_route(), '/wp-sync/' ) ) {
				return $result;
			}

			global $wpdb;

			self::ensure_table();

			$this->wall_start = microtime( true );
			$this->start      = array(
				'concurrent' => $this->increment_concurrent(),
				'queries'    => (int) $wpdb->num_queries,
				'db_time'    => $this->db_time_so_far(),
				'memory'     => memory_get_usage( true ),
				'cpu_us'     => $this->cpu_us(),
			);
			register_shutdown_function( array( $this, 'decrement_concurrent' ) );

			return $result;
		}

		/**
		 * Measures and logs the tagged request after dispatch.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Response $response Dispatched response.
		 * @param WP_REST_Server   $server   REST server (unused).
		 * @param WP_REST_Request  $request  Request that was dispatched.
		 * @return WP_REST_Response Unmodified response (headers annotated).
		 */
		public function post_dispatch( $response, $server, $request ) {
			if ( null === $this->wall_start || ! $response instanceof WP_REST_Response ) {
				return $response;
			}

			global $wpdb;

			$wall_ms      = round( ( microtime( true ) - $this->wall_start ) * 1000, 2 );
			$db_queries   = (int) $wpdb->num_queries - $this->start['queries'];
			$db_time_ms   = round( ( $this->db_time_so_far() - $this->start['db_time'] ) * 1000, 2 );
			$memory_delta = memory_get_usage( true ) - $this->start['memory'];

			$cpu_end      = $this->cpu_us();
			$cpu_ms       = round( ( $cpu_end - $this->start['cpu_us'] ) / 1000, 2 );
			$total_cpu_ms = round( ( $cpu_end - $this->boot_cpu_us ) / 1000, 2 );

			$request_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
			$total_ms      = $request_start > 0
				? round( ( microtime( true ) - $request_start ) * 1000, 2 )
				: 0.0;

			$data      = $response->get_data();
			$rooms_in  = $request->get_param( 'rooms' );
			$rooms_in  = is_array( $rooms_in ) ? $rooms_in : array();
			$rooms_out = isset( $data['rooms'] ) && is_array( $data['rooms'] ) ? $data['rooms'] : array();

			$updates_in = 0;
			foreach ( $rooms_in as $room ) {
				$updates_in += isset( $room['updates'] ) && is_array( $room['updates'] ) ? count( $room['updates'] ) : 0;
			}
			$updates_out = 0;
			foreach ( $rooms_out as $room ) {
				$updates_out += isset( $room['updates'] ) && is_array( $room['updates'] ) ? count( $room['updates'] ) : 0;
			}

			$first_out       = array() !== $rooms_out ? $rooms_out[0] : array();
			$awareness_count = isset( $first_out['awareness'] ) && is_array( $first_out['awareness'] )
				? count( $first_out['awareness'] )
				: 0;

			$row = array(
				'ts'              => time(),
				'approach'        => $this->approach_label( $request ),
				'scenario'        => $this->tag_value( $request, 'X-RTC-Scenario', '_rtcscenario', 'unknown' ),
				'poll_delay'      => $this->poll_delay_value( $request ),
				'update_size'     => $this->update_size_value( $request ),
				'ms'              => $wall_ms,
				'total_ms'        => $total_ms,
				'cpu_ms'          => $cpu_ms,
				'total_cpu_ms'    => $total_cpu_ms,
				'db_queries'      => $db_queries,
				'db_time_ms'      => $db_time_ms,
				'memory_delta'    => $memory_delta,
				'peak_memory'     => memory_get_peak_usage( true ),
				'status'          => $response->get_status(),
				'rooms'           => count( $rooms_in ),
				'updates_in'      => $updates_in,
				'updates_out'     => $updates_out,
				'response_bytes'  => strlen( (string) wp_json_encode( $data ) ),
				'awareness_count' => $awareness_count,
				'should_compact'  => empty( $first_out['should_compact'] ) ? 0 : 1,
				'total_updates'   => isset( $first_out['total_updates'] ) ? (int) $first_out['total_updates'] : 0,
				'concurrent'      => $this->start['concurrent'],
			);
			$fmt = array(
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%f',
				'%f',
				'%f',
				'%f',
				'%d',
				'%f',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Diagnostics-only table.
			$inserted = $wpdb->insert( self::table(), $row, $fmt );
			if ( false === $inserted ) {
				// Most likely: the table was dropped while the version option
				// survived. Recreate and retry once.
				delete_option( self::DB_VERSION_OPTION );
				self::ensure_table();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Diagnostics-only table.
				$inserted = $wpdb->insert( self::table(), $row, $fmt );
			}

			// Diagnostic headers, mirroring the community harness (visible in
			// curl -D output): the hook ran, and whether the row landed.
			$response->header( 'X-RTC-Test-Active', '1' );
			$response->header( 'X-RTC-DB-Insert', false !== $inserted ? '1' : '0' );

			$this->wall_start = null;
			$this->start      = array();

			return $response;
		}

		// -----------------------------------------------------------------
		// Tag extraction
		// -----------------------------------------------------------------

		/**
		 * Whether the request carries the measurement tag.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request Request.
		 * @return bool Whether to measure it.
		 */
		private function is_tagged( WP_REST_Request $request ): bool {
			return '1' === $this->tag_value( $request, 'X-RTC-Test', '_rtctest', '' );
		}

		/**
		 * Reads a tag from header first, query parameter second.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request      Request.
		 * @param string          $header       Header name.
		 * @param string          $param        Query-parameter fallback name.
		 * @param string          $default_value Value when neither is present.
		 * @return string Sanitized tag value.
		 */
		private function tag_value( WP_REST_Request $request, string $header, string $param, string $default_value ): string {
			$raw = $request->get_header( $header );
			if ( null === $raw || '' === $raw ) {
				$raw = $request->get_param( $param );
			}
			if ( null === $raw || '' === $raw ) {
				return $default_value;
			}
			return sanitize_text_field( (string) $raw );
		}

		/**
		 * The approach label: the community harness's storage-approach axis.
		 * When the client sends none, rows auto-label `<engine>/<transport>`
		 * — the axis this plugin compares.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request Request.
		 * @return string Approach label.
		 */
		private function approach_label( WP_REST_Request $request ): string {
			$tagged = $this->tag_value( $request, 'X-RTC-Approach', '_rtcapproach', '' );
			if ( '' !== $tagged ) {
				return $tagged;
			}

			$engine = (string) get_option( 'wp_sync_engine', '' );
			if ( '' === $engine && class_exists( 'WP_Sync_Engine_Registry' ) ) {
				$engine = WP_Sync_Engine_Registry::DEFAULT_ENGINE;
			}

			$transport = false !== strpos( $request->get_route(), 'long-poll' )
				? 'http-long-polling'
				: 'http-polling';

			return $engine . '/' . $transport;
		}

		/**
		 * The client-labeled poll delay, clamped like the community harness
		 * (-1 = not provided).
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request Request.
		 * @return int Poll delay in seconds, or -1.
		 */
		private function poll_delay_value( WP_REST_Request $request ): int {
			$raw = $this->tag_value( $request, 'X-RTC-Poll-Delay', '_rtcpolldelay', '' );
			if ( '' === $raw || ! is_numeric( $raw ) ) {
				return -1;
			}
			return max( -1, min( 86400, (int) $raw ) );
		}

		/**
		 * The client-labeled update-size bucket ('' = not provided).
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request Request.
		 * @return string One of small|medium|large or ''.
		 */
		private function update_size_value( WP_REST_Request $request ): string {
			$raw = strtolower( $this->tag_value( $request, 'X-RTC-Update-Size', '_rtcupdatesize', '' ) );
			return in_array( $raw, array( 'small', 'medium', 'large' ), true ) ? $raw : '';
		}

		// -----------------------------------------------------------------
		// Measurement primitives
		// -----------------------------------------------------------------

		/**
		 * Process CPU time (user + system) in microseconds, 0 where
		 * getrusage is unavailable.
		 *
		 * @since 0.4.0
		 *
		 * @return int CPU microseconds.
		 */
		private function cpu_us(): int {
			if ( ! function_exists( 'getrusage' ) ) {
				return 0;
			}
			$ru = getrusage();
			if ( ! is_array( $ru ) ) {
				return 0;
			}
			return (int) $ru['ru_utime.tv_sec'] * 1000000 + (int) $ru['ru_utime.tv_usec']
				+ (int) $ru['ru_stime.tv_sec'] * 1000000 + (int) $ru['ru_stime.tv_usec'];
		}

		/**
		 * Cumulative DB time so far (seconds). Requires SAVEQUERIES; always
		 * consumed as a delta so the cumulative baseline cancels out.
		 *
		 * @since 0.4.0
		 *
		 * @return float Seconds spent in queries, 0.0 without SAVEQUERIES.
		 */
		private function db_time_so_far(): float {
			global $wpdb;
			if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || ! is_array( $wpdb->queries ) ) {
				return 0.0;
			}
			$total = 0.0;
			foreach ( $wpdb->queries as $query ) {
				$total += isset( $query[1] ) ? (float) $query[1] : 0.0;
			}
			return $total;
		}

		/**
		 * Atomically increments the concurrent-requests counter and returns
		 * the post-increment value. Direct SQL on the options table — the
		 * options API would read-modify-write and lose parallel increments.
		 *
		 * @since 0.4.0
		 *
		 * @return int Concurrent tagged requests including this one.
		 */
		private function increment_concurrent(): int {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter; the options API cannot do this race-free.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
					 VALUES (%s, '1', 'no')
					 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
					self::CONCURRENT_OPTION
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the just-written atomic counter.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					self::CONCURRENT_OPTION
				)
			);
		}

		/**
		 * Decrements the concurrent-requests counter (shutdown handler, so
		 * held long-poll requests count for their full hold).
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public function decrement_concurrent(): void {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter decrement.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options}
					 SET option_value = GREATEST(CAST(option_value AS UNSIGNED), 1) - 1
					 WHERE option_name = %s",
					self::CONCURRENT_OPTION
				)
			);
		}

		// -----------------------------------------------------------------
		// REST surface (community-harness compatible)
		// -----------------------------------------------------------------

		/**
		 * Registers the `rtc-test/v1` routes.
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public function register_routes(): void {
			$can = static function () {
				return current_user_can( 'edit_posts' );
			};

			register_rest_route(
				self::REST_NAMESPACE,
				'/log',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'rest_log' ),
						'permission_callback' => $can,
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'rest_clear' ),
						'permission_callback' => $can,
					),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/env',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_env' ),
					'permission_callback' => $can,
				)
			);

			$report_args = array(
				'poll_delay'  => array(
					'required' => false,
					'type'     => 'integer',
				),
				'update_size' => array(
					'required' => false,
					'type'     => 'string',
				),
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/report',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_report' ),
					'permission_callback' => $can,
					'args'                => $report_args,
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/report-all',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_report_all' ),
					'permission_callback' => $can,
					'args'                => $report_args,
				)
			);
		}

		/**
		 * GET /log — every row, typed like the community harness's log route.
		 *
		 * @since 0.4.0
		 *
		 * @return WP_REST_Response Rows.
		 */
		public function rest_log() {
			return rest_ensure_response( self::fetch_rows() );
		}

		/**
		 * DELETE /log — clears rows, table intact.
		 *
		 * @since 0.4.0
		 *
		 * @return WP_REST_Response Confirmation.
		 */
		public function rest_clear() {
			global $wpdb;
			self::ensure_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Diagnostics-only table.
			$wpdb->query( 'DELETE FROM `' . self::table() . '`' );
			return rest_ensure_response( array( 'cleared' => true ) );
		}

		/**
		 * GET /env — environment snapshot (community keys, plus this
		 * plugin's engine/transport configuration).
		 *
		 * @since 0.4.0
		 *
		 * @return WP_REST_Response Environment description.
		 */
		public function rest_env() {
			return rest_ensure_response( self::environment() );
		}

		/**
		 * The environment snapshot payload.
		 *
		 * @since 0.4.0
		 *
		 * @return array Environment description.
		 */
		public static function environment(): array {
			global $wpdb;

			$engine = (string) get_option( 'wp_sync_engine', '' );
			if ( '' === $engine && class_exists( 'WP_Sync_Engine_Registry' ) ) {
				$engine = WP_Sync_Engine_Registry::DEFAULT_ENGINE;
			}

			return array(
				'php_version'          => PHP_VERSION,
				'wp_version'           => get_bloginfo( 'version' ),
				'mysql_version'        => $wpdb->db_version(),
				'ext_object_cache'     => (bool) wp_using_ext_object_cache(),
				'object_cache_type'    => wp_using_ext_object_cache() ? get_class( $GLOBALS['wp_object_cache'] ) : 'WP_Object_Cache (internal)',
				'savequeries'          => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
				'compaction_threshold' => class_exists( 'WP_HTTP_Polling_Sync_Server' ) ? WP_HTTP_Polling_Sync_Server::COMPACTION_THRESHOLD : null,
				'awareness_timeout_s'  => class_exists( 'WP_HTTP_Polling_Sync_Server' ) ? WP_HTTP_Polling_Sync_Server::AWARENESS_TIMEOUT : null,
				'captured_at'          => time(),
				// Additive keys (absent from the community harness): the
				// engine/transport configuration measurements ran under.
				'engine'               => $engine,
				'transport'            => (string) get_option( 'gutenberg_sync_engines_transport', 'http-polling' ),
				'plugin_version'       => defined( 'GUTENBERG_SYNC_ENGINES_VERSION' ) ? GUTENBERG_SYNC_ENGINES_VERSION : '',
			);
		}

		/**
		 * GET /report — aggregate by scenario × poll_delay × update_size,
		 * rendered as the community harness's text table (with its baseline
		 * ratio section).
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request Request (optional filters).
		 * @return WP_REST_Response { text: <table> }.
		 */
		public function rest_report( WP_REST_Request $request ) {
			return rest_ensure_response( array( 'text' => self::report_text( $request, false ) ) );
		}

		/**
		 * GET /report-all — the same aggregation grouped per approach.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request $request Request (optional filters).
		 * @return WP_REST_Response { text: <table> }.
		 */
		public function rest_report_all( WP_REST_Request $request ) {
			return rest_ensure_response( array( 'text' => self::report_text( $request, true ) ) );
		}

		/**
		 * Fetches log rows with community-harness typing, optionally
		 * filtered by poll_delay / update_size.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request|null $request Optional request with filters.
		 * @return array<int, array<string, mixed>> Typed rows.
		 */
		public static function fetch_rows( ?WP_REST_Request $request = null ): array {
			global $wpdb;
			self::ensure_table();

			$sql    = 'SELECT * FROM `' . self::table() . '`';
			$where  = array();
			$values = array();
			if ( null !== $request && null !== $request->get_param( 'poll_delay' ) ) {
				$where[]  = 'poll_delay = %d';
				$values[] = (int) $request->get_param( 'poll_delay' );
			}
			if ( null !== $request && null !== $request->get_param( 'update_size' ) ) {
				$where[]  = 'update_size = %s';
				$values[] = sanitize_text_field( (string) $request->get_param( 'update_size' ) );
			}
			if ( array() !== $where ) {
				$sql .= ' WHERE ' . implode( ' AND ', $where );
			}
			$sql .= ' ORDER BY id ASC';
			if ( array() !== $values ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built above.
				$sql = $wpdb->prepare( $sql, $values );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Diagnostics-only table read.
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			$int_cols   = array( 'id', 'ts', 'db_queries', 'memory_delta', 'peak_memory', 'status', 'rooms', 'updates_in', 'updates_out', 'response_bytes', 'awareness_count', 'total_updates', 'concurrent', 'poll_delay' );
			$float_cols = array( 'ms', 'total_ms', 'cpu_ms', 'total_cpu_ms', 'db_time_ms' );
			foreach ( $rows as &$row ) {
				foreach ( $int_cols as $col ) {
					$row[ $col ] = (int) $row[ $col ];
				}
				foreach ( $float_cols as $col ) {
					$row[ $col ] = (float) $row[ $col ];
				}
				$row['should_compact'] = (bool) $row['should_compact'];
			}
			unset( $row );

			return $rows;
		}

		/**
		 * Builds the aggregate report text.
		 *
		 * Layout mirrors the community harness: per group (scenario, poll
		 * delay, update size) the mean of `ms` (dispatch wall), `total_ms`,
		 * `cpu_ms`, `total_cpu_ms`, the stddev of `ms`, mean `db_queries`,
		 * `db_time_ms`, mean peak memory in MB, updates in/out sums,
		 * should_compact sum, max concurrent — then every non-baseline
		 * group's mean as a ratio to the `baseline` scenario's mean.
		 *
		 * @since 0.4.0
		 *
		 * @param WP_REST_Request|null $request      Optional filters.
		 * @param bool                 $per_approach Group per approach first.
		 * @return string Report text ('' when the log is empty).
		 */
		public static function report_text( ?WP_REST_Request $request, bool $per_approach ): string {
			$rows = self::fetch_rows( $request );
			if ( array() === $rows ) {
				return '';
			}

			// $agg[ approach ][ scenario ][ poll_delay ][ update_size ] = counters.
			$agg = array();
			foreach ( $rows as $row ) {
				$approach = $per_approach
					? ( '' !== $row['approach'] ? (string) $row['approach'] : '(untagged)' )
					: '';
				$scenario = (string) $row['scenario'];
				$pd       = (int) $row['poll_delay'];
				$sz       = (string) $row['update_size'];

				if ( ! isset( $agg[ $approach ][ $scenario ][ $pd ][ $sz ] ) ) {
					$agg[ $approach ][ $scenario ][ $pd ][ $sz ] = array(
						'n'                  => 0,
						'ms_sum'             => 0.0,
						'ms_sq_sum'          => 0.0,
						'total_ms_sum'       => 0.0,
						'cpu_ms_sum'         => 0.0,
						'total_cpu_ms_sum'   => 0.0,
						'db_queries_sum'     => 0.0,
						'db_time_ms_sum'     => 0.0,
						'peak_memory_sum'    => 0.0,
						'updates_in_sum'     => 0,
						'updates_out_sum'    => 0,
						'should_compact_sum' => 0,
						'max_concurrent'     => 0,
					);
				}
				$a  = &$agg[ $approach ][ $scenario ][ $pd ][ $sz ];
				$ms = (float) $row['ms'];
				++$a['n'];
				$a['ms_sum']             += $ms;
				$a['ms_sq_sum']          += $ms * $ms;
				$a['total_ms_sum']       += (float) $row['total_ms'];
				$a['cpu_ms_sum']         += (float) $row['cpu_ms'];
				$a['total_cpu_ms_sum']   += (float) $row['total_cpu_ms'];
				$a['db_queries_sum']     += (float) $row['db_queries'];
				$a['db_time_ms_sum']     += (float) $row['db_time_ms'];
				$a['peak_memory_sum']    += (float) $row['peak_memory'];
				$a['updates_in_sum']     += (int) $row['updates_in'];
				$a['updates_out_sum']    += (int) $row['updates_out'];
				$a['should_compact_sum'] += $row['should_compact'] ? 1 : 0;
				$a['max_concurrent']      = max( $a['max_concurrent'], (int) $row['concurrent'] );
				unset( $a );
			}

			$fmt_h = "%-18s %4s %-7s %6s %8s %8s %8s %11s %8s %8s %8s %6s %6s %6s %5s %5s\n";
			$fmt_r = "%-18s %4s %-7s %6d %8.1f %8.1f %8.1f %11.1f %8.1f %8.1f %8.1f %6.1f %6d %6d %5d %5d\n";
			$sep   = sprintf(
				$fmt_h,
				str_repeat( '-', 18 ),
				str_repeat( '-', 4 ),
				str_repeat( '-', 7 ),
				str_repeat( '-', 6 ),
				str_repeat( '-', 8 ),
				str_repeat( '-', 8 ),
				str_repeat( '-', 8 ),
				str_repeat( '-', 11 ),
				str_repeat( '-', 8 ),
				str_repeat( '-', 8 ),
				str_repeat( '-', 8 ),
				str_repeat( '-', 6 ),
				str_repeat( '-', 6 ),
				str_repeat( '-', 6 ),
				str_repeat( '-', 5 ),
				str_repeat( '-', 5 )
			);

			$out = '';
			foreach ( $agg as $approach => $scenarios ) {
				if ( $per_approach ) {
					// ASCII separators only: JSON encoders escape box-drawing
					// characters, which renders broken in terminal clients.
					$out .= sprintf( "\n--- Approach: %s ---\n", $approach );
				}
				$out .= sprintf( $fmt_h, 'Scenario', 'dly', 'sz', 'n', 'disp_ms', 'total_ms', 'cpu_ms', 'tot_cpu_ms', 'sd_disp', 'db_q', 'db_t_ms', 'mem_mb', 'ui_tot', 'uo_tot', 'sc', 'conc' );
				$out .= sprintf( $fmt_h, '', '', '', '', 'avg', 'avg', 'avg', 'avg', 'stddev', 'avg', 'avg', 'avg', 'sum', 'sum', 'sum', 'max' );
				$out .= $sep;

				$baseline_sum = 0.0;
				$baseline_n   = 0;
				if ( isset( $scenarios['baseline'] ) ) {
					foreach ( $scenarios['baseline'] as $by_sz ) {
						foreach ( $by_sz as $b ) {
							$baseline_sum += $b['ms_sum'];
							$baseline_n   += $b['n'];
						}
					}
				}
				$baseline_mean = $baseline_n > 0 ? $baseline_sum / $baseline_n : 0.0;

				foreach ( $scenarios as $scenario => $by_pd ) {
					foreach ( $by_pd as $pd => $by_sz ) {
						foreach ( $by_sz as $sz => $a ) {
							$n    = $a['n'];
							$mean = $a['ms_sum'] / $n;
							$var  = max( 0.0, ( $a['ms_sq_sum'] / $n ) - ( $mean * $mean ) );
							$out .= sprintf(
								$fmt_r,
								strlen( $scenario ) > 18 ? substr( $scenario, 0, 15 ) . '...' : $scenario,
								( $pd < 0 ) ? '-' : (string) $pd,
								( '' === $sz ) ? '-' : $sz,
								$n,
								$mean,
								$a['total_ms_sum'] / $n,
								$a['cpu_ms_sum'] / $n,
								$a['total_cpu_ms_sum'] / $n,
								sqrt( $var ),
								$a['db_queries_sum'] / $n,
								$a['db_time_ms_sum'] / $n,
								$a['peak_memory_sum'] / $n / 1048576,
								$a['updates_in_sum'],
								$a['updates_out_sum'],
								$a['should_compact_sum'],
								$a['max_concurrent']
							);
						}
					}
				}

				if ( $baseline_mean > 0.0 ) {
					$out .= sprintf( "\nRatio to baseline (disp_ms / baseline_disp_ms = %.1f):\n", $baseline_mean );
					foreach ( $scenarios as $scenario => $by_pd ) {
						if ( 'baseline' === $scenario ) {
							continue;
						}
						foreach ( $by_pd as $pd => $by_sz ) {
							foreach ( $by_sz as $sz => $a ) {
								$label = $scenario;
								if ( $pd >= 0 || '' !== $sz ) {
									$label .= sprintf( ' [%s,%s]', ( $pd < 0 ) ? '-' : (string) $pd, '' === $sz ? '-' : $sz );
								}
								$out .= sprintf( "  %-40s %.2fx\n", $label, ( $a['ms_sum'] / $a['n'] ) / $baseline_mean );
							}
						}
					}
				}
			}

			return trim( $out, "\n" );
		}
	}
}
