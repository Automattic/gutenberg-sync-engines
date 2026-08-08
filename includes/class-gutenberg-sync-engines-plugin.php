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
			$engines = GUTENBERG_SYNC_ENGINES_PATH . 'includes/engines/';
			require_once $engines . 'intent-log/class-wp-intent-log-document.php';
			require_once $engines . 'intent-log/class-wp-intent-log-planner.php';
			require_once $engines . 'intent-log/class-wp-intent-log-rich-text.php';
			require_once $engines . 'intent-log/class-wp-intent-log-engine.php';
			require_once $engines . 'yjs-relay/class-wp-yjs-relay-engine.php';

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

			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/admin/class-gutenberg-sync-engines-settings.php';
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
			add_filter( 'wp_sync_transports', array( $this, 'register_transports' ), 10, 3 );
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

			( new Gutenberg_Sync_Engines_Settings() )->register();
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
			$engines[] = new WP_Yjs_Relay_Engine( $storage );
			$engines[] = new WP_Intent_Log_Engine( $storage );
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
		 * Enqueues the client engine/transport bundle in the editor when
		 * collaboration is enabled, plus the intent-log block-identity stamper
		 * when intent-log is the active engine.
		 *
		 * The bundle registers this plugin's engine adapters and transport
		 * providers with the framework (`wp.sync`) at load time, so the client
		 * can supply whichever engine the server announces. Without it the
		 * framework ships only the inert Yjs relay and RTC stays disabled.
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
			}

			$storage = new WP_Sync_Post_Meta_Storage();
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
