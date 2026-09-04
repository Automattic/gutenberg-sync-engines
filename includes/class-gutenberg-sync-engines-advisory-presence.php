<?php
/**
 * Gutenberg_Sync_Engines_Advisory_Presence class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Advisory_Presence' ) ) {

	/**
	 * The signaling service behind the advisory channel: who is in a
	 * post's room, tab by tab, and a mailbox that carries the WebRTC
	 * handshake between those tabs. Both ride the heartbeat WordPress
	 * already sends from every editor screen, so no new request cadence is
	 * added to the site.
	 *
	 * Every editor tab gets a per-tab token, stamped when the page renders,
	 * refreshed on every heartbeat, removed by a leave beacon on `pagehide`,
	 * and expired after PRESENCE_TTL if the tab never beats again. The
	 * heartbeat answer lists the OTHER tokens in the room (discovery), says
	 * whether anyone else is present (tokens plus live sync awareness), and
	 * delivers the handshake messages addressed to this tab. The transports
	 * use the answer to decide when to go quiet, when to poll on a timer,
	 * and when to poll only on demand (see docs/plan/advisory-channel.md).
	 *
	 * Tokens live in a transient keyed by the room and mailboxes in options
	 * rows updated by compare-and-swap, both outside the sync storage on
	 * purpose: a presence read must never create a room's storage post
	 * (the storage API's own room lookup does).
	 *
	 * @since n.e.x.t
	 */
	final class Gutenberg_Sync_Engines_Advisory_Presence {
		/**
		 * Key used in both directions of the heartbeat payload. Mirrors
		 * HEARTBEAT_DATA_KEY in src/providers/advisory/signaling.ts.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const HEARTBEAT_KEY = 'gutenberg_sync_engines_advisory';

		/**
		 * REST namespace and route of the leave beacon.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const REST_NAMESPACE   = 'gutenberg-sync-engines/v1';
		const REST_LEAVE_ROUTE = '/advisory/leave';

		/**
		 * Storage name prefixes; the room hash (and, for mailboxes, the
		 * recipient token) is appended.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const TOKENS_TRANSIENT_PREFIX = 'gse_adv_tokens_';
		const MAILBOX_OPTION_PREFIX   = 'gse_adv_mail_';

		/**
		 * How long a token counts as a live tab, in seconds. A hidden tab's
		 * heartbeat slows to a hard 120 seconds, so a live-but-hidden tab
		 * must survive at least two of those beats. Normal closes never wait
		 * this out: the leave beacon removes the token at once.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const PRESENCE_TTL = 300;

		/**
		 * How long the token transient itself lives past the last write.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const TOKENS_TRANSIENT_EXPIRY = 600;

		/**
		 * How long an undelivered handshake message waits, in seconds. A
		 * recipient beats at least every 120 seconds unless suspended; a
		 * message older than this is stale (its sender has moved on).
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const MAILBOX_EXPIRY = 90;

		/**
		 * Caps that bound transient sizes and per-beat work.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const MAX_TOKENS_PER_ROOM   = 50;
		const MAX_MAILBOX_ENTRIES   = 50;
		const MAX_SIGNALS_PER_BEAT  = 40;
		const MAX_SIGNAL_ID_LENGTH  = 96;
		const CAS_ATTEMPTS          = 8;
		const MAX_SIGNAL_DATA_BYTES = 16384;
		const MAX_TOKEN_LENGTH      = 64;

		/**
		 * Default cap on advisory peers per tab. A full mesh is N(N-1)/2
		 * connections; above this the client stands the channel down and
		 * everyone polls.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const DEFAULT_MAX_PEERS = 8;

		/**
		 * Live-awareness window, in seconds. Mirrors the sync transports'
		 * AWARENESS_TIMEOUT: entries older than this count as disconnected.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const AWARENESS_TIMEOUT = 30;

		/**
		 * The handshake message kinds the mailbox relays.
		 *
		 * @since n.e.x.t
		 * @var string[]
		 */
		const SIGNAL_KINDS = array( 'offer', 'answer', 'ice', 'bye' );

		/**
		 * The sync storage the live-awareness check reads (injected for
		 * tests; defaults to the plugin's).
		 *
		 * @since n.e.x.t
		 * @var WP_Sync_Storage|null
		 */
		private $storage;

		/**
		 * Constructor.
		 *
		 * @since n.e.x.t
		 *
		 * @param WP_Sync_Storage|null $storage Sync storage, or null for the plugin's.
		 */
		public function __construct( ?WP_Sync_Storage $storage = null ) {
			$this->storage = $storage;
		}

		/**
		 * Hooks the heartbeat filter and the leave route.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function register(): void {
			// The heartbeat can fire from any admin page, so the filter is
			// global; it is inert unless the payload carries our key.
			add_filter( 'heartbeat_received', array( $this, 'answer_heartbeat' ), 10, 2 );
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Whether the advisory channel is enabled on this site.
		 *
		 * @since n.e.x.t
		 *
		 * @return bool Enabled state.
		 */
		public static function is_enabled(): bool {
			$enabled = true;
			if ( class_exists( 'Gutenberg_Sync_Engines_Settings' ) ) {
				// The settings screen's choice. Independent of the transport
				// choice: the channel serves whenever short polling does,
				// which under a preferred transport (long polling, websocket)
				// is only while that transport is down.
				$choice  = (string) get_option( Gutenberg_Sync_Engines_Settings::ADVISORY_OPTION, Gutenberg_Sync_Engines_Settings::ADVISORY_DEFAULT );
				$enabled = '' !== $choice;
			}

			/**
			 * Filters whether editor tabs open the advisory channel (browser
			 * to browser presence and "new rows" nudges over WebRTC). When
			 * false, tabs keep the timer polling cadence.
			 *
			 * @since n.e.x.t
			 *
			 * @param bool $enabled Defaults to the settings screen's choice.
			 */
			return (bool) apply_filters( 'gutenberg_sync_engines_advisory_enabled', $enabled );
		}

		/**
		 * The ICE servers handed to the browser's RTCPeerConnection.
		 *
		 * @since n.e.x.t
		 *
		 * @return array<int, array<string, mixed>> RTCIceServer-shaped entries.
		 */
		public static function ice_servers(): array {
			/**
			 * Filters the ICE servers used to connect editor tabs to each
			 * other. Defaults to a public STUN server; a TURN server may be
			 * added for networks that block direct connections, but is
			 * never required — tabs that cannot connect keep polling.
			 *
			 * @since n.e.x.t
			 *
			 * @param array<int, array<string, mixed>> $servers RTCIceServer-shaped entries.
			 */
			$servers = apply_filters(
				'gutenberg_sync_engines_advisory_ice_servers',
				array( array( 'urls' => 'stun:stun.l.google.com:19302' ) )
			);
			return is_array( $servers ) ? array_values( $servers ) : array();
		}

		/**
		 * The per-tab settings injected when an editor page renders: the
		 * post's room, a fresh token (stamped as present right away, so a
		 * joiner is visible to the first tab's next heartbeat), whether
		 * anyone else is there, and the channel configuration. Null when the
		 * channel is disabled or the user may not sync the post.
		 *
		 * @since n.e.x.t
		 *
		 * @param WP_Post $post The post being edited.
		 * @return array<string, mixed>|null Settings for the client, or null.
		 */
		public function editor_settings( WP_Post $post ): ?array {
			if ( ! self::is_enabled() ) {
				return null;
			}
			$room = 'postType/' . $post->post_type . ':' . $post->ID;
			if ( ! $this->can_probe_room( $room ) ) {
				return null;
			}
			$token = wp_generate_password( 32, false );
			$this->record_token( $room, $token, 0 );

			/**
			 * Filters the cap on advisory peers per tab.
			 *
			 * @since n.e.x.t
			 *
			 * @param int $max_peers Defaults to 8.
			 */
			$max_peers = (int) apply_filters( 'gutenberg_sync_engines_advisory_max_peers', self::DEFAULT_MAX_PEERS );

			return array(
				'room'          => $room,
				'token'         => $token,
				'othersPresent' => $this->others_present( $room, $token, 0 ),
				'iceServers'    => self::ice_servers(),
				'maxPeers'      => max( 1, $max_peers ),
				'leaveUrl'      => rest_url( self::REST_NAMESPACE . self::REST_LEAVE_ROUTE ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			);
		}

		/**
		 * Answers a heartbeat probe: refreshes the tab's token, files the
		 * handshake messages it sent, and reports the other tabs in the
		 * room plus this tab's mailbox.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $response The heartbeat response being built.
		 * @param mixed $data     The data the client sent.
		 * @return mixed The response, with this lane's answer added.
		 */
		public function answer_heartbeat( $response, $data ) {
			if ( ! is_array( $response ) ) {
				$response = array();
			}
			if ( ! is_array( $data ) || ! isset( $data[ self::HEARTBEAT_KEY ] ) ) {
				return $response;
			}
			$answer = $this->answer_probe( $data[ self::HEARTBEAT_KEY ] );
			if ( null !== $answer ) {
				$response[ self::HEARTBEAT_KEY ] = $answer;
			}
			return $response;
		}

		/**
		 * Answers one probe, whichever request carried it (a heartbeat beat
		 * or a sync poll): refreshes the tab's token, files the handshake
		 * messages it sent, and reports the other tabs in the room plus
		 * this tab's mailbox. Null for a malformed, disabled, or
		 * unauthorized probe.
		 *
		 * @since n.e.x.t
		 *
		 * @param mixed $probe The probe payload.
		 * @return array<string, mixed>|null The answer, or null.
		 */
		public function answer_probe( $probe ): ?array {
			if ( ! is_array( $probe ) || empty( $probe['room'] ) || empty( $probe['token'] ) ) {
				return null;
			}
			$room  = (string) $probe['room'];
			$token = (string) $probe['token'];
			if ( ! self::is_enabled() || ! $this->valid_token( $token ) || ! $this->can_probe_room( $room ) ) {
				return null;
			}

			$client_id = isset( $probe['client_id'] ) ? absint( $probe['client_id'] ) : 0;
			$this->record_token( $room, $token, $client_id );

			$tokens = $this->read_tokens( $room );
			if ( isset( $probe['signals'] ) && is_array( $probe['signals'] ) ) {
				$this->file_signals( $room, $token, $tokens, $probe['signals'] );
			}

			$peers = array();
			foreach ( $tokens as $peer_token => $entry ) {
				if ( $peer_token === $token ) {
					continue;
				}
				$peers[] = array(
					'token'     => (string) $peer_token,
					'client_id' => (int) $entry['c'],
					'user_id'   => (int) $entry['u'],
				);
			}

			return array(
				'others'  => count( $peers ) > 0 || $this->has_live_awareness_besides( $room, $client_id ),
				'peers'   => $peers,
				'signals' => $this->take_mailbox( $room, $token ),
			);
		}

		/**
		 * Registers the leave beacon route.
		 *
		 * @since n.e.x.t
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				self::REST_NAMESPACE,
				self::REST_LEAVE_ROUTE,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_leave' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'room'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'token' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				)
			);
		}

		/**
		 * A tab left its room: forget its token so peers stop counting it
		 * and stop trying to connect to it.
		 *
		 * @since n.e.x.t
		 *
		 * @param WP_REST_Request $request The beacon request.
		 * @return WP_REST_Response The (empty) answer.
		 */
		public function handle_leave( WP_REST_Request $request ): WP_REST_Response {
			$room  = (string) $request->get_param( 'room' );
			$token = (string) $request->get_param( 'token' );
			if ( $this->valid_token( $token ) && $this->can_probe_room( $room ) ) {
				$this->forget_token( $room, $token );
			}
			return new WP_REST_Response( null, 204 );
		}

		/**
		 * Whether the current user may take part in a post's room. Only
		 * per-post entity rooms have an advisory channel; collection rooms
		 * ride along on the same tab's polls.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room The room name.
		 * @return bool Allowed state.
		 */
		private function can_probe_room( string $room ): bool {
			if ( ! class_exists( 'WP_Sync_Config' ) ) {
				return false;
			}
			$parsed = WP_Sync_Config::parse_room( $room );
			if ( null === $parsed || 'postType' !== $parsed['entity_kind'] || empty( $parsed['object_id'] ) ) {
				return false;
			}
			return WP_Sync_Config::can_user_sync_entity_type( $parsed['entity_kind'], $parsed['entity_name'], $parsed['object_id'] );
		}

		/**
		 * Whether a token is well-formed.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $token The token.
		 * @return bool Validity.
		 */
		private function valid_token( string $token ): bool {
			return '' !== $token && strlen( $token ) <= self::MAX_TOKEN_LENGTH && (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $token );
		}

		/**
		 * Files the handshake messages one tab sent into the recipients'
		 * mailboxes. Recipients must be live tokens in the same room; the
		 * kind must be known; the payload is size-capped.
		 *
		 * @since n.e.x.t
		 *
		 * @param string                           $room    The room name.
		 * @param string                           $from    The sender's token.
		 * @param array<string, array<string,int>> $tokens  Live tokens in the room.
		 * @param array<int, mixed>                $signals The messages sent.
		 * @return void
		 */
		private function file_signals( string $room, string $from, array $tokens, array $signals ): void {
			$count = 0;
			foreach ( $signals as $signal ) {
				if ( ++$count > self::MAX_SIGNALS_PER_BEAT ) {
					break;
				}
				if ( ! is_array( $signal ) || empty( $signal['to'] ) || empty( $signal['kind'] ) || ! isset( $signal['data'] ) ) {
					continue;
				}
				$to   = (string) $signal['to'];
				$kind = (string) $signal['kind'];
				$data = $signal['data'];
				if ( $to === $from || ! isset( $tokens[ $to ] ) || ! in_array( $kind, self::SIGNAL_KINDS, true ) ) {
					continue;
				}
				if ( ! is_string( $data ) || strlen( $data ) > self::MAX_SIGNAL_DATA_BYTES ) {
					continue;
				}
				$id = isset( $signal['id'] ) && is_string( $signal['id'] ) && strlen( $signal['id'] ) <= self::MAX_SIGNAL_ID_LENGTH ? $signal['id'] : '';
				$this->append_mail(
					$room,
					$to,
					array(
						'id'   => $id,
						'from' => $from,
						'kind' => $kind,
						'data' => $data,
						't'    => time(),
					)
				);
			}
		}

		/**
		 * Appends one message to a recipient's mailbox, atomically: the
		 * mailbox is an options row updated by compare-and-swap
		 * (WP_Sync_Atomic_Option), so two senders filing at once cannot
		 * overwrite each other and a take cannot delete a message filed
		 * between its read and its write. Expired entries are dropped and
		 * the oldest past the cap.
		 *
		 * @since n.e.x.t
		 *
		 * @param string               $room    The room name.
		 * @param string               $to      The recipient's token.
		 * @param array<string, mixed> $message The message.
		 * @return void
		 */
		private function append_mail( string $room, string $to, array $message ): void {
			$name = $this->mailbox_key( $room, $to );
			for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
				$current = WP_Sync_Atomic_Option::read( $name );
				$mail    = $this->decode_mail( $current );
				$mail[]  = $message;
				if ( count( $mail ) > self::MAX_MAILBOX_ENTRIES ) {
					$mail = array_slice( $mail, -self::MAX_MAILBOX_ENTRIES );
				}
				if ( WP_Sync_Atomic_Option::swap( $name, (string) $current, (string) wp_json_encode( $mail ) ) ) {
					return;
				}
			}
		}

		/**
		 * Empties a tab's mailbox and returns the fresh messages in it. The
		 * swap to an empty box is atomic, so a message filed meanwhile is
		 * seen by the retry, never dropped.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room  The room name.
		 * @param string $token The tab's token.
		 * @return array<int, array<string, mixed>> Messages, oldest first.
		 */
		private function take_mailbox( string $room, string $token ): array {
			$name = $this->mailbox_key( $room, $token );
			for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
				$current = WP_Sync_Atomic_Option::read( $name );
				$mail    = $this->decode_mail( $current );
				if ( 0 === count( $mail ) ) {
					return array();
				}
				if ( ! WP_Sync_Atomic_Option::swap( $name, (string) $current, '[]' ) ) {
					continue;
				}
				$out = array();
				foreach ( $mail as $entry ) {
					$out[] = array(
						'id'   => (string) ( $entry['id'] ?? '' ),
						'from' => (string) $entry['from'],
						'kind' => (string) $entry['kind'],
						'data' => (string) $entry['data'],
					);
				}
				return $out;
			}
			return array();
		}

		/**
		 * Decodes a stored mailbox, dropping malformed and expired entries.
		 *
		 * @since n.e.x.t
		 *
		 * @param string|null $stored The stored JSON, or null for none.
		 * @return array<int, array<string, mixed>> Live entries.
		 */
		private function decode_mail( ?string $stored ): array {
			$mail = '' !== (string) $stored ? json_decode( (string) $stored, true ) : array();
			if ( ! is_array( $mail ) ) {
				return array();
			}
			$now = time();
			return array_values(
				array_filter(
					$mail,
					static function ( $entry ) use ( $now ) {
						return is_array( $entry ) && isset( $entry['t'], $entry['from'], $entry['kind'], $entry['data'] ) && $now - (int) $entry['t'] < self::MAILBOX_EXPIRY;
					}
				)
			);
		}

		/**
		 * Deletes a tab's mailbox row (on leave).
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room  The room name.
		 * @param string $token The tab's token.
		 * @return void
		 */
		private function delete_mailbox( string $room, string $token ): void {
			delete_option( $this->mailbox_key( $room, $token ) );
		}

		/**
		 * The mailbox option name for one recipient.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room  The room name.
		 * @param string $token The recipient's token.
		 * @return string Option name.
		 */
		private function mailbox_key( string $room, string $token ): string {
			return self::MAILBOX_OPTION_PREFIX . md5( $room . '|' . $token );
		}

		/**
		 * The token transient name for one room.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room The room name.
		 * @return string Transient name.
		 */
		private function tokens_key( string $room ): string {
			return self::TOKENS_TRANSIENT_PREFIX . md5( $room );
		}

		/**
		 * The live tokens in a room, expired entries dropped.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room The room name.
		 * @return array<string, array<string, int>> token => { t, u, c }.
		 */
		private function read_tokens( string $room ): array {
			$stored = get_transient( $this->tokens_key( $room ) );
			if ( ! is_array( $stored ) ) {
				return array();
			}
			$now  = time();
			$live = array();
			foreach ( $stored as $token => $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['t'] ) || $now - (int) $entry['t'] >= self::PRESENCE_TTL ) {
					continue;
				}
				$live[ (string) $token ] = array(
					't' => (int) $entry['t'],
					'u' => isset( $entry['u'] ) ? (int) $entry['u'] : 0,
					'c' => isset( $entry['c'] ) ? (int) $entry['c'] : 0,
				);
			}
			return $live;
		}

		/**
		 * Records (or refreshes) a tab's token. A lost write under
		 * concurrent heartbeats only delays one refresh by a beat.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room      The room name.
		 * @param string $token     The tab's token.
		 * @param int    $client_id The tab's sync client id (0 when unknown).
		 * @return void
		 */
		private function record_token( string $room, string $token, int $client_id ): void {
			$tokens = $this->read_tokens( $room );
			$known  = $tokens[ $token ] ?? null;

			$tokens[ $token ] = array(
				't' => time(),
				'u' => get_current_user_id(),
				// A page-render stamp has no client id yet; keep the last
				// known one rather than regressing to 0.
				'c' => $client_id > 0 ? $client_id : ( $known['c'] ?? 0 ),
			);

			if ( count( $tokens ) > self::MAX_TOKENS_PER_ROOM ) {
				uasort(
					$tokens,
					static function ( $a, $b ) {
						return $b['t'] <=> $a['t'];
					}
				);
				$tokens = array_slice( $tokens, 0, self::MAX_TOKENS_PER_ROOM, true );
			}

			set_transient( $this->tokens_key( $room ), $tokens, self::TOKENS_TRANSIENT_EXPIRY );
		}

		/**
		 * Removes a tab's token and its pending mail.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room  The room name.
		 * @param string $token The tab's token.
		 * @return void
		 */
		private function forget_token( string $room, string $token ): void {
			$tokens = $this->read_tokens( $room );
			unset( $tokens[ $token ] );
			if ( 0 === count( $tokens ) ) {
				delete_transient( $this->tokens_key( $room ) );
			} else {
				set_transient( $this->tokens_key( $room ), $tokens, self::TOKENS_TRANSIENT_EXPIRY );
			}
			$this->delete_mailbox( $room, $token );
		}

		/**
		 * Whether any OTHER tab or live sync session is in the room.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room      The room name.
		 * @param string $token     This tab's token.
		 * @param int    $client_id This tab's sync client id (0 when unknown).
		 * @return bool Company.
		 */
		private function others_present( string $room, string $token, int $client_id ): bool {
			$tokens = $this->read_tokens( $room );
			unset( $tokens[ $token ] );
			if ( count( $tokens ) > 0 ) {
				return true;
			}
			return $this->has_live_awareness_besides( $room, $client_id );
		}

		/**
		 * Whether the room's sync awareness holds a live entry for someone
		 * other than the given client. Covers tabs without the presence
		 * lane (an older bundle, a different editor screen) that still poll.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room      The room name.
		 * @param int    $client_id This tab's sync client id (0 when unknown).
		 * @return bool Company.
		 */
		private function has_live_awareness_besides( string $room, int $client_id ): bool {
			$now = time();
			foreach ( $this->read_awareness( $room ) as $entry ) {
				$updated_at   = isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0;
				$entry_client = isset( $entry['client_id'] ) ? (int) $entry['client_id'] : 0;
				if ( $now - $updated_at < self::AWARENESS_TIMEOUT && ( 0 === $client_id || $entry_client !== $client_id ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Reads a room's awareness entries WITHOUT creating its storage
		 * post: the storage API's own room lookup creates the post (its
		 * callers are about to write), which presence must never do.
		 *
		 * @since n.e.x.t
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room The room name.
		 * @return array<int, array<string, mixed>> Awareness entries.
		 */
		private function read_awareness( string $room ): array {
			global $wpdb;

			$post_type = class_exists( 'WP_Sync_Post_Meta_Storage' ) ? WP_Sync_Post_Meta_Storage::POST_TYPE : 'wp_sync_storage';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Non-creating existence check; see docblock.
			$storage_post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s ORDER BY ID ASC LIMIT 1",
					md5( $room ),
					$post_type
				)
			);
			if ( empty( $storage_post_id ) ) {
				return array();
			}

			$storage = $this->storage;
			if ( null === $storage && function_exists( 'gutenberg_sync_engines_storage' ) ) {
				$storage = gutenberg_sync_engines_storage();
			}
			if ( null === $storage ) {
				return array();
			}
			$entries = $storage->get_awareness_state( $room );
			return is_array( $entries ) ? $entries : array();
		}
	}
}
