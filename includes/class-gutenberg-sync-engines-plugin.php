<?php
/**
 * Gutenberg_Sync_Engines_Plugin class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Plugin' ) ) {

	/**
	 * Plugin bootstrap: loads and registers the sync engines and transports
	 * this plugin provides, and the admin settings screen for selecting them.
	 *
	 * The collaborative-editing FRAMEWORK — the WP_Sync_Engine /
	 * WP_Sync_Transport / WP_Sync_Storage contracts, the two registries, room
	 * config, storage, and the client @wordpress/sync package — lives in
	 * Gutenberg (WordPress core). This plugin supplies the IMPLEMENTATIONS
	 * that register through the framework's extension filters
	 * (`wp_sync_engines`, `wp_sync_transports`). With this plugin inactive the
	 * framework registers nothing, so real-time collaboration degrades to the
	 * classic post lock — effectively disabled.
	 *
	 * @since 0.1.0
	 */
	final class Gutenberg_Sync_Engines_Plugin {
		/**
		 * Singleton instance.
		 *
		 * @since 0.1.0
		 * @var Gutenberg_Sync_Engines_Plugin|null
		 */
		private static ?Gutenberg_Sync_Engines_Plugin $instance = null;

		/**
		 * Whether the collaborative-editing framework is present.
		 *
		 * @since 0.1.0
		 * @var bool
		 */
		private bool $framework_available = false;

		/**
		 * Returns the singleton instance.
		 *
		 * @since 0.1.0
		 *
		 * @return Gutenberg_Sync_Engines_Plugin Instance.
		 */
		public static function instance(): Gutenberg_Sync_Engines_Plugin {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor (use instance()).
		 *
		 * @since 0.1.0
		 */
		private function __construct() {}

		/**
		 * Boots the plugin: feature-detects the framework, then loads and
		 * registers implementations. A missing framework is not an error —
		 * the plugin simply stays dormant and surfaces an admin notice.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function boot(): void {
			// The framework's engine contract is the canonical presence check.
			$this->framework_available = interface_exists( 'WP_Sync_Engine' )
				&& interface_exists( 'WP_Sync_Transport' )
				&& class_exists( 'WP_Sync_Post_Meta_Storage' );

			if ( ! $this->framework_available ) {
				add_action( 'admin_notices', array( $this, 'render_missing_framework_notice' ) );
				return;
			}

			$this->load();
			$this->register();
		}

		/**
		 * Requires the plugin's PHP classes.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		private function load(): void {
			// The automerge-php support gate (tiny; the library itself stays lazy).
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/lib/automerge-php-loader.php';

			// Shared concurrency primitives (Core-style lock + optimistic
			// CAS), each with a filterable drop-in backend seam.
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/interface-wp-sync-lock-backend.php';
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/interface-wp-sync-cas-backend.php';
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/class-wp-sync-room-lock.php';
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/class-wp-sync-atomic-option.php';

			$engines = GUTENBERG_SYNC_ENGINES_PATH . 'includes/engines/';
			require_once $engines . 'class-wp-sync-post-genesis-props.php';
			require_once $engines . 'intent-log/class-wp-intent-log-document.php';
			require_once $engines . 'intent-log/class-wp-intent-log-planner.php';
			require_once $engines . 'intent-log/class-wp-intent-log-rich-text.php';
			require_once $engines . 'intent-log/class-wp-intent-log-engine.php';
			require_once $engines . 'intent-log/class-wp-intent-log-base-seq-preflight.php';
			require_once $engines . 'yjs-server/class-wp-yjs-server-engine.php';
			// The DE-RTC merge core is ported verbatim from wordpress-develop;
			// a Core/Gutenberg build that ships DE-RTC itself wins the guard.
			if ( ! function_exists( 'wp_de_rtc_get_reason_codes' ) ) {
				require_once $engines . 'de-rtc/merge-core.php';
			}
			require_once $engines . 'de-rtc/class-wp-de-rtc-engine.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-sync-meta-colocation.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-base-version-preflight.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-autosave-commits.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-review-controller.php';

			$transports = GUTENBERG_SYNC_ENGINES_PATH . 'includes/transports/';
			require_once $transports . 'class-wp-http-polling-sync-server.php';
			require_once $transports . 'class-wp-http-long-polling-sync-server.php';
			require_once $transports . 'websocket/class-wp-websocket-token-controller.php';
			require_once $transports . 'websocket/class-wp-websocket-connection.php';
			require_once $transports . 'websocket/class-wp-websocket-sync-server.php';
			require_once $transports . 'websocket/class-wp-websocket-sync-transport.php';
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once $transports . 'websocket/class-wp-sync-server-cli-command.php';
			}

			/*
			 * Diagnostics are development tools, deliberately kept OUT of the
			 * production path: these files only load on local/development
			 * sites (wp-env reports 'local'), or when a site opts in
			 * explicitly by defining the GUTENBERG_SYNC_ENGINES_DIAGNOSTICS
			 * constant. The room CLI is WP-CLI-only; session capture and the
			 * per-request benchmark log hook web requests too (both are
			 * no-ops until a capture session is started / a request carries
			 * the X-RTC-Test tag).
			 */
			$diagnostics_allowed = in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
				|| ( defined( 'GUTENBERG_SYNC_ENGINES_DIAGNOSTICS' ) && GUTENBERG_SYNC_ENGINES_DIAGNOSTICS );
			if ( $diagnostics_allowed ) {
				$diagnostics = GUTENBERG_SYNC_ENGINES_PATH . 'includes/diagnostics/';
				require_once $diagnostics . 'class-gutenberg-sync-engines-request-log.php';
				require_once $diagnostics . 'class-gutenberg-sync-engines-session-capture.php';
				( new Gutenberg_Sync_Engines_Request_Log() )->register();
				( new Gutenberg_Sync_Engines_Session_Capture() )->register();
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					require_once $diagnostics . 'class-gutenberg-sync-engines-rooms-cli-command.php';
					require_once $diagnostics . 'class-gutenberg-sync-engines-capture-cli-command.php';
					require_once $diagnostics . 'class-gutenberg-sync-engines-bench-log-cli-command.php';
				}
			}

			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/admin/class-gutenberg-sync-engines-settings.php';

			// TEMPORARY: server half of the demo sync shortcut (see the file).
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/temporary/class-gutenberg-sync-engines-demo-sync.php';
		}

		/**
		 * Registers engines and transports through the framework's filters,
		 * and wires the admin settings screen.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		private function register(): void {
			add_filter( 'wp_sync_engines', array( $this, 'register_engines' ), 10, 2 );
			WP_De_RTC_Sync_Meta_Colocation::register();
			WP_De_RTC_Base_Version_Preflight::register();
			WP_De_RTC_Autosave_Commits::register();
			add_action( 'rest_api_init', array( new WP_De_RTC_Review_Controller(), 'register_routes' ) );
			WP_Intent_Log_Base_Seq_Preflight::register();
			add_filter( 'wp_sync_transports', array( $this, 'register_transports' ), 10, 3 );
			add_filter( 'wp_sync_transport_client_config', array( $this, 'filter_transport_client_config' ), 10, 2 );
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
			// TEMPORARY: see suppress_autosave_notice().
			add_filter( 'block_editor_settings_all', array( $this, 'suppress_autosave_notice' ), 100 );

			( new Gutenberg_Sync_Engines_Settings() )->register();

			// TEMPORARY: see includes/temporary/class-gutenberg-sync-engines-demo-sync.php.
			( new Gutenberg_Sync_Engines_Demo_Sync() )->register();
		}

		/**
		 * Adds this plugin's engines to the framework's engine registry.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_Sync_Engine[] $engines Engines to register.
		 * @param WP_Sync_Storage  $storage Storage backend.
		 * @return WP_Sync_Engine[] Engines including this plugin's.
		 */
		public function register_engines( array $engines, WP_Sync_Storage $storage ): array {
			// The framework's conventional default is intent-log
			// (WP_Sync_Engine_Registry::DEFAULT_ENGINE), used when the
			// wp_sync_engine option is unset. Registration order only
			// matters as the fallback when a CONFIGURED slug is not
			// registered (misconfiguration degrades to the first engine).
			$engines[] = new WP_Yjs_Server_Engine( $storage );
			$engines[] = new WP_Intent_Log_Engine( $storage );
			$engines[] = new WP_De_RTC_Engine( $storage );
			return $engines;
		}

		/**
		 * Adds this plugin's transports to the framework's transport registry.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_Sync_Transport[]     $transports Transports to register.
		 * @param WP_Sync_Storage         $storage    Storage backend.
		 * @param WP_Sync_Engine_Registry $engines    Engine registry.
		 * @return WP_Sync_Transport[] Transports including this plugin's.
		 */
		public function register_transports( array $transports, WP_Sync_Storage $storage, WP_Sync_Engine_Registry $engines ): array {
			$transports[] = new WP_HTTP_Polling_Sync_Server( $storage, $engines );
			$transports[] = new WP_HTTP_Long_Polling_Sync_Server( $storage, $engines );
			$transports[] = new WP_WebSocket_Sync_Transport( $storage, $engines );
			return $transports;
		}

		/**
		 * Supplies transport-specific client connection metadata for the
		 * framework's collaboration announcement
		 * (`window._wpCollaborationTransportConfig`): the framework carries no
		 * transport-specific knowledge, so the WebSocket transport's socket
		 * URL must be announced from here. Without it the client's socket
		 * provider has no URL to connect to and the websocket transport
		 * cannot establish a session.
		 *
		 * @since 0.1.0
		 *
		 * @param array    $config     Client config keyed by transport slug.
		 * @param string[] $transports Announced transport slugs.
		 * @return array Config including the socket URL when announced.
		 */
		public function filter_transport_client_config( $config, $transports ): array {
			$config = is_array( $config ) ? $config : array();
			if ( in_array( WP_WebSocket_Sync_Transport::TRANSPORT_SLUG, (array) $transports, true ) ) {
				$config[ WP_WebSocket_Sync_Transport::TRANSPORT_SLUG ] = array(
					'url' => WP_WebSocket_Sync_Transport::get_socket_url(),
				);
			}
			return $config;
		}

		/**
		 * Enqueues the client engine/transport bundle in the editor when
		 * collaboration is enabled, plus the intent-log block-identity stamper
		 * when intent-log is the active engine.
		 *
		 * The bundle registers this plugin's engine adapters and transport
		 * providers with the framework (`wp.sync`) at load time, so the client
		 * can supply whichever engine the server announces. Without it the
		 * framework has no engines or transports and RTC stays disabled.
		 *
		 * The stamper fills `metadata.syncId` for blocks that lack one and
		 * re-mints duplicates directly in the editor store, making block
		 * identity durable. It is a raw script (no build), specific to the
		 * intent-log engine.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function enqueue_editor_assets(): void {
			if ( function_exists( 'wp_is_collaboration_enabled' ) && ! wp_is_collaboration_enabled() ) {
				return;
			}

			$bundle = GUTENBERG_SYNC_ENGINES_PATH . 'build/sync-engines.js';
			$asset  = GUTENBERG_SYNC_ENGINES_PATH . 'build/sync-engines.asset.php';
			if ( file_exists( $bundle ) && file_exists( $asset ) ) {
				$meta = require $asset;
				wp_enqueue_script(
					'gutenberg-sync-engines',
					GUTENBERG_SYNC_ENGINES_URL . 'build/sync-engines.js',
					isset( $meta['dependencies'] ) ? $meta['dependencies'] : array(),
					isset( $meta['version'] ) ? $meta['version'] : GUTENBERG_SYNC_ENGINES_VERSION,
					true
				);

				/*
				 * Plugin-owned client settings (the framework announcement
				 * stays untouched): the de-rtc commit cadence dial and the
				 * short-polling interval, both stored in seconds and passed
				 * in milliseconds for the client's timers.
				 *
				 * TEMPORARY: `currentUserId` feeds the clock-aligned sync demo
				 * tooling (src/temporary/clock-aligned-sync.ts); drop it with
				 * that module.
				 */
				$commit_interval  = 0;
				$polling_interval = 0;
				if ( class_exists( 'Gutenberg_Sync_Engines_Settings' ) ) {
					$commit_interval  = (int) get_option( Gutenberg_Sync_Engines_Settings::DE_RTC_COMMIT_INTERVAL_OPTION, 0 );
					$polling_interval = (int) get_option( Gutenberg_Sync_Engines_Settings::POLLING_INTERVAL_OPTION, 0 );
				}
				wp_add_inline_script(
					'gutenberg-sync-engines',
					'window._gutenbergSyncEnginesSettings = ' . wp_json_encode(
						array(
							'currentUserId'         => get_current_user_id(),
							'deRtcCommitIntervalMs' => max( 0, $commit_interval ) * 1000,
							'httpPollingIntervalMs' => max( 0, min( 25, $polling_interval ) ) * 1000,
						)
					) . ';',
					'before'
				);
			}

			$storage = gutenberg_sync_engines_storage();
			$engines = new WP_Sync_Engine_Registry( $storage );
			if ( 'intent-log' !== $engines->get_engine_slug_for_room( '' ) ) {
				return;
			}
			$stamper = GUTENBERG_SYNC_ENGINES_PATH . 'includes/engines/intent-log/sync-id.js';
			wp_enqueue_script(
				'gutenberg-sync-engines-intent-log-stamper',
				GUTENBERG_SYNC_ENGINES_URL . 'includes/engines/intent-log/sync-id.js',
				array( 'wp-data' ),
				file_exists( $stamper ) ? (string) filemtime( $stamper ) : GUTENBERG_SYNC_ENGINES_VERSION,
				true
			);
		}

		/**
		 * TEMPORARY: keeps the "There is an autosave of this post that is more
		 * recent than the version below" notice from ever showing in the editor.
		 *
		 * Core flags a newer autosave through the `autosave` editor setting
		 * (wp-admin/edit-form-blocks.php), and the editor turns that setting
		 * into the notice. Dropping the setting drops the notice. Runs after
		 * the framework's own filter (priority 10), which only annotates the
		 * same setting, so nothing downstream depends on the key.
		 *
		 * Remove this hook and method once the notice is wanted again.
		 *
		 * @since n.e.x.t
		 *
		 * @param array $settings Block editor settings.
		 * @return array Settings without the autosave flag.
		 */
		public function suppress_autosave_notice( $settings ) {
			if ( is_array( $settings ) ) {
				unset( $settings['autosave'] );
			}
			return $settings;
		}

		/**
		 * Admin notice shown when the collaborative-editing framework is not
		 * available (Gutenberg / a supporting WordPress version is required).
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function render_missing_framework_notice(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Gutenberg Sync Engines needs the collaborative-editing framework from Gutenberg (or a supporting WordPress version). Real-time collaboration is inactive until it is available.', 'gutenberg-sync-engines' );
			echo '</p></div>';
		}
	}
}
