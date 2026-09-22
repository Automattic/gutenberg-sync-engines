<?php
/**
 * Gutenberg_Sync_Engines_Plugin class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Plugin' ) ) {

	/**
	 * Plugin bootstrap: loads the collaboration contracts, the engines and
	 * transports, the collaboration behavior around the editor, and the
	 * admin settings screen.
	 *
	 * The plugin owns the whole server side of collaboration: the
	 * WP_Sync_Engine / WP_Sync_Transport / WP_Sync_Engines_Storage contracts,
	 * the two registries, room config, storage, the editor announcement and
	 * the post lock handling. Gutenberg only offers the entity sync seam the
	 * client bundle plugs into. With this plugin inactive the editor keeps
	 * the classic post lock.
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
		 * The option that records which one-time upgrade routines ran.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const UPGRADE_OPTION = 'gutenberg_sync_engines_upgrade';

		/**
		 * The current upgrade routine version. Bump it to run a new routine
		 * once on every site.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const UPGRADE_VERSION = 1;

		/**
		 * Boots the plugin: loads and registers everything.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function boot(): void {
			/*
			 * The storage tables come first: a plugin update that ships a
			 * newer schema upgrades here (activation hooks do not fire on
			 * updates), and the lifecycle CLI (`wp collaboration storage …`)
			 * must always work. A site that cannot create the tables keeps
			 * working on the post-meta storage (see filter_sync_storage())
			 * and is told so.
			 */
			if ( ! WP_Sync_Table_Schema::maybe_upgrade() ) {
				add_action( 'admin_notices', array( $this, 'render_storage_unavailable_notice' ) );
			}
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/storage/class-wp-sync-table-storage-cli-command.php';
				require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/class-gutenberg-sync-engines-cli-command.php';
			}

			$this->load();
			$this->register();
			$this->maybe_upgrade();
		}

		/**
		 * One-time upgrade routines, run once per site.
		 *
		 * Version 1: earlier plugin versions turned Gutenberg's
		 * real-time collaboration experiment on. That experiment is gone
		 * from the bundled Gutenberg, but a standalone Gutenberg that still
		 * ships it would register a second collaboration manager next to
		 * this plugin's, so the stored flag is turned off.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		private function maybe_upgrade(): void {
			if ( (int) get_option( self::UPGRADE_OPTION, 0 ) >= self::UPGRADE_VERSION ) {
				return;
			}

			$experiments = get_option( 'gutenberg-experiments', array() );
			if ( is_array( $experiments ) && ! empty( $experiments['gutenberg-real-time-collaboration'] ) ) {
				unset( $experiments['gutenberg-real-time-collaboration'] );
				update_option( 'gutenberg-experiments', $experiments );
			}

			update_option( self::UPGRADE_OPTION, self::UPGRADE_VERSION );
		}

		/**
		 * Requires the plugin's PHP classes.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		private function load(): void {
			// The collaboration contracts: storage, engine and transport
			// interfaces, the two registries, room config, and the
			// post-meta storage that is the storage default.
			$contracts = GUTENBERG_SYNC_ENGINES_PATH . 'includes/contracts/';
			require_once $contracts . 'interface-wp-sync-storage.php';
			require_once $contracts . 'interface-wp-sync-engine.php';
			require_once $contracts . 'interface-wp-sync-transport.php';
			require_once $contracts . 'class-wp-sync-engines-config.php';
			require_once $contracts . 'class-wp-sync-engines-post-meta-storage.php';
			require_once $contracts . 'class-wp-sync-engine-registry.php';
			require_once $contracts . 'class-wp-sync-transport-registry.php';

			// The collaboration behavior around the editor: the enable
			// setting, storage and transport lookup, REST routes, the post
			// list, the editor announcement, the post lock, meta boxes and
			// autosaves.
			$collaboration = GUTENBERG_SYNC_ENGINES_PATH . 'includes/collaboration/';
			require_once $collaboration . 'collaboration.php';
			require_once $collaboration . 'announcement.php';
			require_once $collaboration . 'post-lock.php';
			require_once $collaboration . 'meta-box-rtc-compat.php';
			require_once $collaboration . 'class-wp-sync-engines-rest-autosaves-controller.php';
			require_once $collaboration . 'rest-api.php';

			// The automerge-php support gate (tiny; the library itself stays lazy).
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/lib/automerge-php-loader.php';

			// Room storage over the plugin's own tables (the schema class is
			// loaded by the plugin entry, ahead of activation).
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/storage/class-wp-sync-table-storage.php';

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
			require_once $engines . 'de-rtc/class-wp-de-rtc-block-identity.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-identity-merge.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-engine.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-sync-meta-colocation.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-base-version-preflight.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-autosave-commits.php';
			require_once $engines . 'de-rtc/class-wp-de-rtc-review-controller.php';

			$transports = GUTENBERG_SYNC_ENGINES_PATH . 'includes/transports/';
			require_once $transports . 'class-wp-sync-engines-http-polling-sync-server.php';
			require_once $transports . 'class-wp-http-long-polling-sync-server.php';
			require_once $transports . 'websocket/class-wp-websocket-access-token.php';
			require_once $transports . 'websocket/class-wp-websocket-token-controller.php';
			require_once $transports . 'websocket/class-wp-websocket-connection.php';
			require_once $transports . 'websocket/class-wp-websocket-sync-server.php';
			require_once $transports . 'websocket/class-wp-websocket-sync-transport.php';
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once $transports . 'websocket/class-wp-sync-server-cli-command.php';
			}
			require_once GUTENBERG_SYNC_ENGINES_PATH . 'includes/class-gutenberg-sync-engines-advisory-presence.php';

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
		}

		/**
		 * Registers engines and transports through the registry filters,
		 * and wires the admin settings screen.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		private function register(): void {
			add_filter( '__unstable_wp_sync_storage', array( $this, 'filter_sync_storage' ) );
			add_filter( 'wp_sync_engines', array( $this, 'register_engines' ), 10, 2 );
			WP_De_RTC_Sync_Meta_Colocation::register();
			WP_De_RTC_Base_Version_Preflight::register();
			WP_De_RTC_Autosave_Commits::register();
			add_action( 'rest_api_init', array( new WP_De_RTC_Review_Controller(), 'register_routes' ) );
			WP_Intent_Log_Base_Seq_Preflight::register();
			// A reset room must not resume from de-rtc's out-of-row canonical.
			add_action( 'gutenberg_sync_engines_room_reset', array( 'WP_De_RTC_Engine', 'forget_room_state' ) );
			add_filter( 'wp_sync_transports', array( $this, 'register_transports' ), 10, 3 );
			add_filter( 'wp_sync_transport_client_config', array( $this, 'filter_transport_client_config' ), 10, 2 );
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
			( new Gutenberg_Sync_Engines_Advisory_Presence() )->register();

			( new Gutenberg_Sync_Engines_Settings() )->register();
		}

		/**
		 * Substitutes the plugin's table storage for the post-meta default
		 * (`__unstable_wp_sync_storage`).
		 *
		 * Only the DEFAULT is replaced: a storage another plugin has
		 * already substituted, at any priority, is respected. And only
		 * when the tables are usable (the recorded schema version is
		 * current — an autoloaded option, since this runs on every
		 * `gutenberg_sync_engines_get_storage()` call): a site that could not create them
		 * keeps collaborating on post meta rather than failing every
		 * request, with an admin notice saying so.
		 *
		 * @since n.e.x.t
		 *
		 * @param WP_Sync_Engines_Storage $storage The default storage.
		 * @return WP_Sync_Engines_Storage Storage to use.
		 */
		public function filter_sync_storage( $storage ) {
			if ( $storage instanceof WP_Sync_Engines_Post_Meta_Storage && WP_Sync_Table_Schema::is_ready() ) {
				return new WP_Sync_Table_Storage();
			}
			return $storage;
		}

		/**
		 * Adds this plugin's engines to the engine registry.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_Sync_Engine[]        $engines Engines to register.
		 * @param WP_Sync_Engines_Storage $storage Storage backend.
		 * @return WP_Sync_Engine[] Engines including this plugin's.
		 */
		public function register_engines( array $engines, WP_Sync_Engines_Storage $storage ): array {
			// The conventional default is intent-log
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
		 * Adds this plugin's transports to the transport registry.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_Sync_Transport[]     $transports Transports to register.
		 * @param WP_Sync_Engines_Storage $storage    Storage backend.
		 * @param WP_Sync_Engine_Registry $engines    Engine registry.
		 * @return WP_Sync_Transport[] Transports including this plugin's.
		 */
		public function register_transports( array $transports, WP_Sync_Engines_Storage $storage, WP_Sync_Engine_Registry $engines ): array {
			$transports[] = new WP_Sync_Engines_HTTP_Polling_Sync_Server( $storage, $engines );
			$transports[] = new WP_HTTP_Long_Polling_Sync_Server( $storage, $engines );
			$transports[] = new WP_WebSocket_Sync_Transport( $storage, $engines );
			return $transports;
		}

		/**
		 * Supplies transport-specific client connection metadata for the
		 * editor announcement (`window._gutenbergSyncEnginesSync.transportConfig`):
		 * the announcement carries no transport-specific knowledge, so the
		 * WebSocket transport's socket URL must be announced from here.
		 * Without it the client's socket provider has no URL to connect to
		 * and the websocket transport cannot establish a session.
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
		 * The bundle reads the announcement (`window._gutenbergSyncEnginesSync`,
		 * printed by gutenberg_sync_engines_print_announcement()) and plugs
		 * the announced engine and transport into Gutenberg's entity sync
		 * seam. Without it the editor keeps the classic post lock.
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
			if ( ! gutenberg_sync_engines_is_enabled() ) {
				return;
			}

			$bundle = GUTENBERG_SYNC_ENGINES_PATH . 'build/sync-engines.js';
			$asset  = GUTENBERG_SYNC_ENGINES_PATH . 'build/sync-engines.asset.php';
			if ( file_exists( $bundle ) && file_exists( $asset ) ) {
				$meta = require $asset;

				/*
				 * Plugin-owned client settings (the announcement is printed
				 * separately): the de-rtc commit cadence dial, the
				 * short-polling interval, and the slow awareness mode, all
				 * stored in seconds and passed in milliseconds for the
				 * client's timers.
				 */
				$commit_interval    = 0;
				$polling_interval   = 0;
				$awareness_interval = 0;
				$awareness_channel  = 'sync';
				if ( class_exists( 'Gutenberg_Sync_Engines_Settings' ) ) {
					$commit_interval    = (int) get_option( Gutenberg_Sync_Engines_Settings::DE_RTC_COMMIT_INTERVAL_OPTION, Gutenberg_Sync_Engines_Settings::DE_RTC_COMMIT_INTERVAL_DEFAULT );
					$polling_interval   = Gutenberg_Sync_Engines_Settings::polling_interval();
					$awareness_interval = Gutenberg_Sync_Engines_Settings::awareness_interval();
					$awareness_channel  = Gutenberg_Sync_Engines_Settings::awareness_channel();
				}

				// Slow awareness over Heartbeat rides the advisory channel's
				// discovery probe and needs wp.heartbeat on the page.
				$dependencies = isset( $meta['dependencies'] ) ? $meta['dependencies'] : array();
				if ( $awareness_interval > 0 && 'heartbeat' === $awareness_channel ) {
					$dependencies[] = 'heartbeat';
				}

				wp_enqueue_script(
					'gutenberg-sync-engines',
					GUTENBERG_SYNC_ENGINES_URL . 'build/sync-engines.js',
					$dependencies,
					isset( $meta['version'] ) ? $meta['version'] : GUTENBERG_SYNC_ENGINES_VERSION,
					true
				);

				/*
				 * The collaboration UI's styles (presence avatars, canvas
				 * carets, the review cards, the connection error modal).
				 * The bundler writes two sheets: one for the components'
				 * `style.scss` files and one for the rest.
				 */
				foreach ( array( 'style-sync-engines', 'sync-engines' ) as $stylesheet ) {
					if ( file_exists( GUTENBERG_SYNC_ENGINES_PATH . 'build/' . $stylesheet . '.css' ) ) {
						wp_enqueue_style(
							'gutenberg-sync-engines-' . $stylesheet,
							GUTENBERG_SYNC_ENGINES_URL . 'build/' . $stylesheet . '.css',
							array( 'wp-components' ),
							isset( $meta['version'] ) ? $meta['version'] : GUTENBERG_SYNC_ENGINES_VERSION
						);
					}
				}

				$settings = array(
					'deRtcCommitIntervalMs' => max( 0, $commit_interval ) * 1000,
					'httpPollingIntervalMs' => max( 0, min( 25, $polling_interval ) ) * 1000,
					'awarenessIntervalMs'   => $awareness_interval * 1000,
					'awarenessChannel'      => $awareness_channel,
				);

				/*
				 * The advisory channel's per-tab settings: this tab's
				 * presence token (stamped now, so a joiner is visible to the
				 * first tab's next heartbeat), whether anyone else is already
				 * there, and the WebRTC configuration. Only on a single-post
				 * editor screen; other block-editor screens (widgets, site
				 * editor) have no per-post room to discover peers in, and
				 * keep the always-on polling cadence.
				 */
				$post = get_post();
				if ( $post instanceof WP_Post ) {
					$advisory = ( new Gutenberg_Sync_Engines_Advisory_Presence() )->editor_settings( $post );
					if ( null !== $advisory ) {
						$settings['advisory'] = $advisory;
					}
				}

				wp_add_inline_script(
					'gutenberg-sync-engines',
					'window._gutenbergSyncEnginesSettings = ' . wp_json_encode( $settings ) . ';',
					'before'
				);
			}

			$storage = gutenberg_sync_engines_get_storage();
			$engines = new WP_Sync_Engine_Registry( $storage );
			// The editor-side identity stamper serves every engine whose
			// blocks carry `metadata.syncId` — intent-log and de-rtc.
			if ( ! in_array( $engines->get_engine_slug_for_room( '' ), array( 'intent-log', 'de-rtc' ), true ) ) {
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
		 * Admin notice shown when the storage tables could not be created:
		 * collaboration keeps working on the post-meta storage until they
		 * can be.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function render_storage_unavailable_notice(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Gutenberg Sync Engines could not create its collaboration storage tables (the database user may lack the CREATE TABLE privilege). Real-time collaboration is running on the slower post-meta storage until they exist; run "wp collaboration storage install" once the privilege is granted.', 'gutenberg-sync-engines' );
			echo '</p></div>';
		}
	}
}
