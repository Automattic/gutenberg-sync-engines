<?php
/**
 * Gutenberg_Sync_Engines_Settings class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Settings' ) ) {

	/**
	 * The Settings → Collaboration admin screen: choose the active sync
	 * ENGINE and TRANSPORT.
	 *
	 * The engine choice is stored in the framework's own `wp_sync_engine`
	 * option (read by WP_Sync_Engine_Registry). The transport choice is
	 * stored here and fed to the framework through the
	 * `wp_collaboration_transport` filter, so a single screen drives both
	 * axes of the swappable stack.
	 *
	 * @since 0.1.0
	 */
	final class Gutenberg_Sync_Engines_Settings {
		/**
		 * Settings group / page slug.
		 *
		 * @since 0.1.0
		 * @var string
		 */
		const PAGE = 'gutenberg-sync-engines';

		/**
		 * Option storing the active transport slug.
		 *
		 * @since 0.1.0
		 * @var string
		 */
		const TRANSPORT_OPTION = 'gutenberg_sync_engines_transport';

		/**
		 * Option storing the de-rtc commit cadence in SECONDS. 0 keeps the
		 * settle cycle (pseudo-realtime); the Distributed Editing vision's
		 * operating point is 10.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const DE_RTC_COMMIT_INTERVAL_OPTION = 'gutenberg_sync_engines_de_rtc_commit_interval';

		/**
		 * Option storing the HTTP short-polling interval in SECONDS. 0 keeps
		 * the built-in defaults (1 second with collaborators, 4 seconds
		 * alone). Capped at 25 so polling always beats the server's
		 * 30-second awareness timeout.
		 *
		 * @since 0.4.0
		 * @var string
		 */
		const POLLING_INTERVAL_OPTION = 'gutenberg_sync_engines_polling_interval';

		/**
		 * Option holding the advisory channel choice: `webrtc-advisory` (the
		 * default browser-to-browser link), `websocket-advisory` (one socket
		 * per tab to the sync daemon, which relays between the tabs in a
		 * room) or the empty string for off. `web-rtc`, the slug the first
		 * release stored, still reads as `webrtc-advisory`.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		/**
		 * Option: the WebSocket URL editor tabs connect to, for the
		 * WebSocket transport and the WebSocket advisory channel alike —
		 * the plugin's sync daemon or a host's own relay. Empty (the
		 * default) means `ws://<WP_SYNC_WEBSOCKET_HOST>:<WP_SYNC_WEBSOCKET_PORT>`;
		 * the `wp_sync_websocket_url` filter still applies last.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const WEBSOCKET_URL_OPTION = 'gutenberg_sync_engines_websocket_url';

		const ADVISORY_OPTION        = 'gutenberg_sync_engines_advisory_channel';
		const ADVISORY_WEBRTC        = 'webrtc-advisory';
		const ADVISORY_WEBSOCKET     = 'websocket-advisory';
		const ADVISORY_LEGACY_WEBRTC = 'web-rtc';
		const ADVISORY_DEFAULT       = self::ADVISORY_WEBRTC;

		/**
		 * Option holding the unsaved-changes policy: `discard` (the saved post
		 * is the only durable copy; a room nobody is in is reset to it) or
		 * `keep` (rooms live on as a shared working copy). See
		 * docs/plan/room-lifetime.md.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const UNSAVED_OPTION  = 'gutenberg_sync_engines_unsaved_changes';
		const UNSAVED_DISCARD = 'discard';
		const UNSAVED_KEEP    = 'keep';
		const UNSAVED_DEFAULT = self::UNSAVED_DISCARD;

		/**
		 * Registers the admin page, settings, and the transport filter.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function register(): void {
			add_action( 'admin_menu', array( $this, 'add_menu' ) );
			add_action( 'init', array( $this, 'register_options' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );

			// Feed the stored transport choice to the framework.
			add_filter(
				'wp_collaboration_transport',
				function ( $default_slug ) {
					$stored = (string) get_option( self::TRANSPORT_OPTION, '' );
					return '' !== $stored ? $stored : $default_slug;
				}
			);
		}

		/**
		 * The engines this plugin provides, as slug => label. Filterable so
		 * additional engine plugins can appear on the screen.
		 *
		 * @since 0.1.0
		 *
		 * @return array<string, string> Engine choices.
		 */
		public static function engine_choices(): array {
			$choices = array(
				'intent-log' => __( 'Intent log (server-authoritative; conflicts go to review)', 'gutenberg-sync-engines' ),
				'yjs-server' => __( 'Yjs server (server-authoritative CRDT; concurrent conflicts merge silently, last writer wins — no review lane)', 'gutenberg-sync-engines' ),
				'de-rtc'     => __( 'DE-RTC (server-governed three-way merges of content proposals; conflicts escalate)', 'gutenberg-sync-engines' ),
			);

			/**
			 * Filters the sync engine choices shown on the settings screen.
			 *
			 * @since 0.1.0
			 *
			 * @param array<string, string> $choices Engine slug => label.
			 */
			return (array) apply_filters( 'gutenberg_sync_engines_engine_choices', $choices );
		}

		/**
		 * The transport choices, read from the framework's transport registry
		 * so every registered transport appears.
		 *
		 * @since 0.1.0
		 *
		 * @return array<string, string> Transport choices (slug => label).
		 */
		public static function transport_choices(): array {
			$labels  = array(
				'http-polling'      => __( 'Short-polling (default)', 'gutenberg-sync-engines' ),
				'http-long-polling' => __( 'Long-polling', 'gutenberg-sync-engines' ),
				'websocket'         => __( 'WebSocket', 'gutenberg-sync-engines' ),
			);
			$choices = array();

			if ( function_exists( 'wp_get_collaboration_transport_registry' ) ) {
				foreach ( array_keys( wp_get_collaboration_transport_registry()->get_transports() ) as $slug ) {
					$choices[ $slug ] = $labels[ $slug ] ?? $slug;
				}
			}
			if ( empty( $choices ) ) {
				$choices = $labels;
			}
			return $choices;
		}

		/**
		 * Adds the Settings → Collaboration submenu.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function add_menu(): void {
			add_options_page(
				__( 'Collaboration', 'gutenberg-sync-engines' ),
				__( 'Collaboration', 'gutenberg-sync-engines' ),
				'manage_options',
				self::PAGE,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Registers the engine + transport options.
		 *
		 * Hooked to `init` (not `admin_init`) so the options are registered
		 * during REST requests too: `show_in_rest` makes the engine swap
		 * scriptable (`POST /wp/v2/settings`, e2e fixtures), and REST never
		 * runs `admin_init`. This is the sole registration of
		 * `wp_sync_engine` — the framework does not register it.
		 *
		 * `wp_sync_engine` deliberately has NO registered default and only
		 * `sanitize_key` sanitization, mirroring the framework registration
		 * this replaces. A registered default is poison here: it makes
		 * `update_option( 'wp_sync_engine', <that-default> )` a silent no-op
		 * (the old-value lookup reports the default, so nothing is written)
		 * while WP_Sync_Engine_Registry — which passes its own explicit
		 * fallback to `get_option()` — never sees it. And unknown slugs are
		 * harmless: the registry falls back to its default engine.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function register_options(): void {
			register_setting(
				self::PAGE,
				'wp_sync_engine',
				array(
					'type'              => 'string',
					'description'       => __( 'Collaborative editing sync engine', 'gutenberg-sync-engines' ),
					'sanitize_callback' => 'sanitize_key',
					'show_in_rest'      => true,
				)
			);
			register_setting(
				self::PAGE,
				self::TRANSPORT_OPTION,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_transport' ),
				)
			);
			register_setting(
				self::PAGE,
				self::ADVISORY_OPTION,
				array(
					'type'              => 'string',
					'description'       => __( 'Advisory channel between editor tabs (webrtc-advisory, websocket-advisory, or empty for off)', 'gutenberg-sync-engines' ),
					'sanitize_callback' => array( $this, 'sanitize_advisory' ),
					'show_in_rest'      => true,
					'default'           => self::ADVISORY_DEFAULT,
				)
			);
			register_setting(
				self::PAGE,
				self::WEBSOCKET_URL_OPTION,
				array(
					'type'              => 'string',
					'description'       => __( 'WebSocket URL editor tabs connect to (ws:// or wss://; empty for the default host and port)', 'gutenberg-sync-engines' ),
					'sanitize_callback' => array( __CLASS__, 'sanitize_websocket_url' ),
					'show_in_rest'      => true,
					'default'           => '',
				)
			);
			register_setting(
				self::PAGE,
				self::UNSAVED_OPTION,
				array(
					'type'              => 'string',
					'description'       => __( 'What happens to unsaved changes when the last editor leaves a post (discard or keep)', 'gutenberg-sync-engines' ),
					'sanitize_callback' => array( $this, 'sanitize_unsaved' ),
					'show_in_rest'      => true,
					'default'           => self::UNSAVED_DEFAULT,
				)
			);
			register_setting(
				self::PAGE,
				self::POLLING_INTERVAL_OPTION,
				array(
					'type'              => 'integer',
					'description'       => __( 'HTTP short-polling interval in seconds (0 = defaults)', 'gutenberg-sync-engines' ),
					'sanitize_callback' => array( $this, 'sanitize_polling_interval' ),
					'show_in_rest'      => true,
					'default'           => 0,
				)
			);
			register_setting(
				self::PAGE,
				self::DE_RTC_COMMIT_INTERVAL_OPTION,
				array(
					'type'              => 'integer',
					'description'       => __( 'Distributed Editing commit cadence in seconds (0 = every settle)', 'gutenberg-sync-engines' ),
					'sanitize_callback' => array( $this, 'sanitize_commit_interval' ),
					'show_in_rest'      => true,
					'default'           => 0,
				)
			);
		}

		/**
		 * Sanitizes the polling interval: whole seconds, 0-25.
		 *
		 * @since 0.4.0
		 *
		 * @param mixed $value Submitted value.
		 * @return int Interval in seconds.
		 */
		public function sanitize_polling_interval( $value ): int {
			return max( 0, min( 25, (int) $value ) );
		}

		/**
		 * Sanitizes the de-rtc commit cadence: whole seconds, 0-300.
		 *
		 * @since 0.3.0
		 *
		 * @param mixed $value Submitted value.
		 * @return int Cadence in seconds.
		 */
		public function sanitize_commit_interval( $value ): int {
			return max( 0, min( 300, (int) $value ) );
		}

		/**
		 * Registers the settings screen sections and fields (admin only —
		 * the settings-field helpers exist only in wp-admin).
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function register_settings(): void {
			add_settings_section(
				'gutenberg_sync_engines_main',
				__( 'Real-time collaboration', 'gutenberg-sync-engines' ),
				array( $this, 'render_section_intro' ),
				self::PAGE
			);
			add_settings_field(
				'wp_sync_engine',
				__( 'Sync engine', 'gutenberg-sync-engines' ),
				array( $this, 'render_engine_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
			add_settings_field(
				self::TRANSPORT_OPTION,
				__( 'Transport', 'gutenberg-sync-engines' ),
				array( $this, 'render_transport_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
			add_settings_field(
				self::ADVISORY_OPTION,
				__( 'Advisory channel', 'gutenberg-sync-engines' ),
				array( $this, 'render_advisory_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
			add_settings_field(
				self::WEBSOCKET_URL_OPTION,
				__( 'WebSocket URL', 'gutenberg-sync-engines' ),
				array( $this, 'render_websocket_url_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
			add_settings_field(
				self::UNSAVED_OPTION,
				__( 'Unsaved changes', 'gutenberg-sync-engines' ),
				array( $this, 'render_unsaved_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
			add_settings_field(
				self::POLLING_INTERVAL_OPTION,
				__( 'Polling interval', 'gutenberg-sync-engines' ),
				array( $this, 'render_polling_interval_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
			add_settings_field(
				self::DE_RTC_COMMIT_INTERVAL_OPTION,
				__( 'Distributed Editing commit cadence', 'gutenberg-sync-engines' ),
				array( $this, 'render_commit_interval_field' ),
				self::PAGE,
				'gutenberg_sync_engines_main'
			);
		}

		/**
		 * Renders the polling interval field.
		 *
		 * @since 0.4.0
		 *
		 * @return void
		 */
		public function render_polling_interval_field(): void {
			$value = (int) get_option( self::POLLING_INTERVAL_OPTION, 0 );
			printf(
				'<input type="number" min="0" max="25" step="1" name="%1$s" id="%1$s" value="%2$d" class="small-text" /> %3$s<p class="description">%4$s</p>',
				esc_attr( self::POLLING_INTERVAL_OPTION ),
				(int) $value,
				esc_html__( 'seconds', 'gutenberg-sync-engines' ),
				esc_html__( 'HTTP short-polling only. How often each editor asks the server for new updates while collaborating. 0 keeps the defaults: every second with collaborators, every 4 seconds when editing alone. A larger interval reduces server load, but edits take that much longer to reach other editors.', 'gutenberg-sync-engines' )
			);

			/*
			 * The dial only applies to the short-polling transport: HIDE its
			 * row (live, following the transport select) rather than disable
			 * the input — a disabled input drops out of the POST and saving
			 * under another transport would silently reset the stored
			 * interval. A hidden row still submits, so the value survives
			 * transport round-trips. Without JS the row simply stays visible.
			 */
			printf(
				'<script>( function () {
					var input  = document.getElementById( %1$s );
					var select = document.getElementById( %2$s );
					if ( ! input || ! select ) {
						return;
					}
					var row    = input.closest( "tr" );
					var toggle = function () {
						row.style.display = "http-polling" === select.value ? "" : "none";
					};
					select.addEventListener( "change", toggle );
					toggle();
				} )();</script>',
				wp_json_encode( self::POLLING_INTERVAL_OPTION ),
				wp_json_encode( self::TRANSPORT_OPTION )
			);
		}

		/**
		 * Renders the de-rtc commit cadence field.
		 *
		 * @since 0.3.0
		 *
		 * @return void
		 */
		public function render_commit_interval_field(): void {
			$value = (int) get_option( self::DE_RTC_COMMIT_INTERVAL_OPTION, 0 );
			printf(
				'<input type="number" min="0" max="300" step="1" name="%1$s" id="%1$s" value="%2$d" class="small-text" /> %3$s<p class="description">%4$s</p>',
				esc_attr( self::DE_RTC_COMMIT_INTERVAL_OPTION ),
				(int) $value,
				esc_html__( 'seconds', 'gutenberg-sync-engines' ),
				esc_html__( 'Distributed Editing (de-rtc) only. 0 commits whenever edits settle (pseudo-realtime). 10 is the Distributed Editing vision\'s save-and-sync cadence: edits coalesce locally and the room advances every ten seconds, cutting request rate and upload bytes on constrained hosts. Peers see each other\'s work at this cadence.', 'gutenberg-sync-engines' )
			);

			/*
			 * The dial only applies to de-rtc: HIDE its row (live, following
			 * the engine select) rather than disable the input — a disabled
			 * input drops out of the POST and saving under another engine
			 * would silently reset the stored cadence. A hidden row still
			 * submits, so the value survives engine round-trips. Without JS
			 * the row simply stays visible.
			 */
			printf(
				'<script>( function () {
					var input  = document.getElementById( %1$s );
					var select = document.getElementById( "wp_sync_engine" );
					if ( ! input || ! select ) {
						return;
					}
					var row    = input.closest( "tr" );
					var toggle = function () {
						row.style.display = "de-rtc" === select.value ? "" : "none";
					};
					select.addEventListener( "change", toggle );
					toggle();
				} )();</script>',
				wp_json_encode( self::DE_RTC_COMMIT_INTERVAL_OPTION )
			);
		}

		/**
		 * Sanitizes the transport slug against the registered choices.
		 *
		 * @since 0.1.0
		 *
		 * @param string $value Submitted slug.
		 * @return string A valid transport slug.
		 */
		public function sanitize_transport( $value ): string {
			$value   = sanitize_key( (string) $value );
			$choices = self::transport_choices();
			return isset( $choices[ $value ] ) ? $value : 'http-polling';
		}

		/**
		 * Section intro copy.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function render_section_intro(): void {
			echo '<p>' . esc_html__( 'Choose how concurrent edits are merged (engine) and how updates travel between editors (transport). Both apply site-wide.', 'gutenberg-sync-engines' ) . '</p>';
		}

		/**
		 * Renders the engine <select>.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function render_engine_field(): void {
			$this->render_select( 'wp_sync_engine', self::engine_choices(), (string) get_option( 'wp_sync_engine', 'intent-log' ) );
		}

		/**
		 * Renders the transport <select>.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function render_transport_field(): void {
			$this->render_select( self::TRANSPORT_OPTION, self::transport_choices(), (string) get_option( self::TRANSPORT_OPTION, 'http-polling' ) );
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'The default short-polling transport is always available as a fallback.', 'gutenberg-sync-engines' )
			);
		}

		/**
		 * Sanitizes the WebSocket URL: a `ws://` or `wss://` URL, or the
		 * empty string for the default.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $value Submitted value.
		 * @return string The URL, or ''.
		 */
		public static function sanitize_websocket_url( $value ): string {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			return (string) esc_url_raw( $value, array( 'ws', 'wss' ) );
		}

		/**
		 * The configured WebSocket URL, or '' for the default.
		 *
		 * @since n.e.x.t
		 *
		 * @return string The URL, or ''.
		 */
		public static function websocket_url(): string {
			return self::sanitize_websocket_url( get_option( self::WEBSOCKET_URL_OPTION, '' ) );
		}

		/**
		 * Renders the WebSocket URL field.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function render_websocket_url_field(): void {
			$overridden = has_filter( 'wp_sync_websocket_url' );
			printf(
				'<input type="url" class="regular-text code" name="%1$s" id="%1$s" value="%2$s" placeholder="wss://relay.example.com" %3$s />',
				esc_attr( self::WEBSOCKET_URL_OPTION ),
				esc_attr( self::websocket_url() ),
				$overridden ? 'readonly' : ''
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Where editor tabs connect for the WebSocket transport and the WebSocket advisory channel: the sync daemon (wp collaboration sync-server), or a relay of your own when an access-token secret is configured. Leave empty for the default, ws://host:port from the WP_SYNC_WEBSOCKET_HOST and WP_SYNC_WEBSOCKET_PORT constants. Use wss:// outside development.', 'gutenberg-sync-engines' )
			);
			if ( $overridden && class_exists( 'WP_WebSocket_Sync_Transport' ) ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: the WebSocket URL set by code */
							__( 'Code sets this value through the wp_sync_websocket_url filter; tabs connect to %s.', 'gutenberg-sync-engines' ),
							WP_WebSocket_Sync_Transport::get_socket_url()
						)
					)
				);
			}
		}

		/**
		 * Sanitizes the advisory channel choice.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $value Submitted value.
		 * @return string `webrtc-advisory`, `websocket-advisory`, or the empty
		 *                string (off).
		 */
		public function sanitize_advisory( $value ): string {
			return self::normalize_advisory( $value );
		}

		/**
		 * Normalizes a stored or submitted advisory channel value to one of
		 * the known slugs.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $value Raw value.
		 * @return string `webrtc-advisory`, `websocket-advisory`, or the empty
		 *                string (off).
		 */
		public static function normalize_advisory( $value ): string {
			$value = (string) $value;
			if ( self::ADVISORY_LEGACY_WEBRTC === $value ) {
				return self::ADVISORY_WEBRTC;
			}
			return in_array( $value, array( self::ADVISORY_WEBRTC, self::ADVISORY_WEBSOCKET ), true ) ? $value : '';
		}

		/**
		 * The advisory channel the site chose: `webrtc-advisory`,
		 * `websocket-advisory`, or the empty string for off.
		 *
		 * @since n.e.x.t
		 *
		 * @return string The advisory channel slug, or the empty string.
		 */
		public static function advisory_channel(): string {
			return self::normalize_advisory( get_option( self::ADVISORY_OPTION, self::ADVISORY_DEFAULT ) );
		}

		/**
		 * Sanitizes the unsaved-changes policy.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $value Submitted value.
		 * @return string `discard` or `keep`.
		 */
		public function sanitize_unsaved( $value ): string {
			return self::UNSAVED_KEEP === (string) $value ? self::UNSAVED_KEEP : self::UNSAVED_DISCARD;
		}

		/**
		 * Renders the unsaved-changes policy field.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function render_unsaved_field(): void {
			$this->render_select(
				self::UNSAVED_OPTION,
				array(
					self::UNSAVED_DISCARD => __( 'Discarded when the last editor leaves (default)', 'gutenberg-sync-engines' ),
					self::UNSAVED_KEEP    => __( 'Kept as a shared working copy', 'gutenberg-sync-engines' ),
				),
				(string) get_option( self::UNSAVED_OPTION, self::UNSAVED_DEFAULT )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'While people are editing together, unsaved changes are shared live. When the last editor leaves the post, either they are discarded (the saved post and its autosaves are the only durable copy, as the unsaved-changes warning says) or they are kept and the next editor continues from them.', 'gutenberg-sync-engines' )
			);
		}

		/**
		 * Renders the advisory channel field.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function render_advisory_field(): void {
			$this->render_select(
				self::ADVISORY_OPTION,
				array(
					self::ADVISORY_WEBRTC    => __( 'WebRTC between editor tabs (default)', 'gutenberg-sync-engines' ),
					self::ADVISORY_WEBSOCKET => __( 'WebSocket to the sync daemon', 'gutenberg-sync-engines' ),
					''                       => __( 'Off', 'gutenberg-sync-engines' ),
				),
				self::advisory_channel()
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'An advisory channel reduces polling by signaling to peers when updates are available. WebRTC connects the tabs to each other directly; WebSocket relays through the same sync daemon the WebSocket transport uses (it must be running), which also reaches tabs that cannot connect directly.', 'gutenberg-sync-engines' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'It only applies while short polling is the transport in use: with long polling or WebSocket selected, the channel runs only while that transport is down and short polling is the fallback.', 'gutenberg-sync-engines' )
			);
			if ( class_exists( 'WP_WebSocket_Access_Token' ) && WP_WebSocket_Access_Token::is_enabled() ) {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'An access token secret is configured: each socket carries a signed, short-lived access token that a server checks without WordPress, so the WebSocket URL may point at a relay of your own (see examples/advisory-relay in the plugin) instead of the sync daemon.', 'gutenberg-sync-engines' )
				);
			}
		}

		/**
		 * Renders a labeled <select> for a setting.
		 *
		 * @since 0.1.0
		 *
		 * @param string                $name    Option name.
		 * @param array<string, string> $choices Slug => label.
		 * @param string                $current Current value.
		 * @return void
		 */
		private function render_select( string $name, array $choices, string $current ): void {
			echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
			foreach ( $choices as $slug => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $slug ),
					selected( $current, $slug, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		}

		/**
		 * Renders the settings page.
		 *
		 * @since 0.1.0
		 *
		 * @return void
		 */
		public function render_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="wrap">';
			echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

			/*
			 * Nothing on this screen does anything while real-time
			 * collaboration is off, and since WordPress/gutenberg#80658 it is
			 * off by default and lives behind a Gutenberg experiment rather
			 * than a Settings → Writing checkbox. Say so, and link there,
			 * instead of leaving an engine picker that quietly has no effect.
			 */
			if ( function_exists( 'wp_is_collaboration_enabled' ) && ! wp_is_collaboration_enabled() ) {
				printf(
					'<div class="notice notice-warning"><p>%1$s</p></div>',
					wp_kses(
						sprintf(
							/* translators: %s: link to the Gutenberg experiments screen. */
							__( 'Real-time collaboration is turned off, so these settings have no effect yet. Enable the <strong>Real-time collaboration</strong> experiment on the %s screen.', 'gutenberg-sync-engines' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=gutenberg-experiments' ) ) . '">' . esc_html__( 'Gutenberg experiments', 'gutenberg-sync-engines' ) . '</a>'
						),
						array(
							'strong' => array(),
							'a'      => array( 'href' => array() ),
						)
					)
				);
			}

			echo '<form action="options.php" method="post">';
			settings_fields( self::PAGE );
			do_settings_sections( self::PAGE );
			submit_button();
			echo '</form>';
			echo '</div>';
		}
	}
}
