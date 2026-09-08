<?php
/**
 * WP_WebSocket_Ticket class
 *
 * @package gutenberg-sync-engines
 */

if ( ! class_exists( 'WP_WebSocket_Ticket' ) ) {

	/**
	 * Signed, short-lived WebSocket tickets: the handshake credential a
	 * server can check WITHOUT WordPress.
	 *
	 * The plugin's own daemon authenticates a socket with the logged_in
	 * cookie plus a one-time token it looks up in the database. A relay a
	 * host runs elsewhere (Node, Go, a hosted service — see
	 * `examples/advisory-relay/`) has neither the cookie (it does not
	 * reach another domain) nor the database. Ticket mode replaces the
	 * one-time token with a JSON Web Token signed with a secret the
	 * relay shares with WordPress (HS256): the token route mints it, the
	 * browser offers it on the same `Sec-WebSocket-Protocol` entry as
	 * before, and the server verifies the signature and the expiry on
	 * its own. The plugin's daemon accepts tickets too, so one switch
	 * serves both.
	 *
	 * Claims: `user_id`, `blog_id`, `rooms`, `iat`, `exp`. The claim
	 * names follow the VIP real-time collaboration server's tokens so a
	 * verifier written for those parses these; `rooms` (a list) is the
	 * one addition. Each entry is an exact room name (`postType/post:12`)
	 * or `<kind>/*`, which allows every COLLECTION room of that kind
	 * (a room name without an object id, e.g. `taxonomy/category`).
	 *
	 * Ticket mode is on when a secret is configured: the
	 * `WP_SYNC_WEBSOCKET_TICKET_SECRET` constant, else the environment
	 * variable of the same name, else the `wp_sync_websocket_ticket_secret`
	 * filter. Use at least 32 random bytes and rotate by restarting the
	 * relay with the new value; tickets outlive a rotation by at most
	 * their two-minute lifetime.
	 *
	 * @since n.e.x.t
	 * @access private
	 */
	class WP_WebSocket_Ticket {
		/**
		 * Ticket lifetime in seconds (the same as the one-time token's).
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const TTL = 2 * MINUTE_IN_SECONDS;

		/**
		 * Clock skew tolerated between the minting host and the verifier.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const LEEWAY = 30;

		/**
		 * Longest ticket a verifier reads.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const MAX_LENGTH = 4096;

		/**
		 * Most rooms a ticket names.
		 *
		 * @since n.e.x.t
		 * @var int
		 */
		const MAX_ROOMS = 50;

		/**
		 * The collection wildcard suffix: `<kind>/*`.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const COLLECTION_WILDCARD = '/*';

		/**
		 * The entity kinds whose collection rooms every ticket allows.
		 * Collection rooms carry presence only over this lane; the
		 * framework lets any user with `edit_posts` sync the `taxonomy`
		 * and `root` collections, and a post type's collection when they
		 * can edit that type — this grant rounds the last one up, for a
		 * lane that carries no content.
		 *
		 * @since n.e.x.t
		 * @var string[]
		 */
		const COLLECTION_KINDS = array( 'postType', 'taxonomy', 'root' );

		/**
		 * The configured secret, or the empty string when ticket mode is
		 * off.
		 *
		 * @since n.e.x.t
		 *
		 * @return string The secret.
		 */
		public static function secret(): string {
			$secret = '';
			if ( defined( 'WP_SYNC_WEBSOCKET_TICKET_SECRET' ) && is_string( WP_SYNC_WEBSOCKET_TICKET_SECRET ) ) {
				$secret = WP_SYNC_WEBSOCKET_TICKET_SECRET;
			} else {
				$from_env = getenv( 'WP_SYNC_WEBSOCKET_TICKET_SECRET' );
				if ( is_string( $from_env ) ) {
					$secret = $from_env;
				}
			}

			/**
			 * Filters the secret WebSocket tickets are signed with. A
			 * non-empty value switches ticket mode on: the token route
			 * mints signed tickets instead of one-time tokens, and the
			 * daemon (or a host's own relay sharing the secret) verifies
			 * them without a database read.
			 *
			 * @since n.e.x.t
			 *
			 * @param string $secret The secret, or '' for off.
			 */
			$secret = apply_filters( 'wp_sync_websocket_ticket_secret', $secret );

			return is_string( $secret ) ? $secret : '';
		}

		/**
		 * Whether ticket mode is on.
		 *
		 * @since n.e.x.t
		 *
		 * @return bool Whether a secret is configured.
		 */
		public static function is_enabled(): bool {
			return '' !== self::secret();
		}

		/**
		 * Whether a handshake credential has a ticket's shape (three
		 * base64url segments) rather than a one-time token's (hex).
		 *
		 * @since n.e.x.t
		 *
		 * @param string $token The offered credential.
		 * @return bool Whether it looks like a ticket.
		 */
		public static function looks_like_ticket( string $token ): bool {
			return strlen( $token ) <= self::MAX_LENGTH
				&& 1 === preg_match( '#^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$#', $token );
		}

		/**
		 * The rooms a ticket for one editor tab allows: its post's room,
		 * when given, plus the collection rooms of every allowed kind.
		 *
		 * @since n.e.x.t
		 *
		 * @param string|null $room The tab's post room, or null.
		 * @return string[] Room grants.
		 */
		public static function grants( ?string $room ): array {
			$rooms = array();
			if ( is_string( $room ) && '' !== $room ) {
				$rooms[] = $room;
			}
			foreach ( self::COLLECTION_KINDS as $kind ) {
				$rooms[] = $kind . self::COLLECTION_WILDCARD;
			}
			return $rooms;
		}

		/**
		 * Whether a ticket's grants allow following a room: an exact
		 * entry, or a `<kind>/*` entry when the room is a collection room
		 * (no object id) of that kind. The rule every relay implements.
		 *
		 * @since n.e.x.t
		 *
		 * @param string[] $rooms The ticket's `rooms` claim.
		 * @param string   $room  The room to follow.
		 * @return bool Whether the follow is allowed.
		 */
		public static function allows( array $rooms, string $room ): bool {
			if ( in_array( $room, $rooms, true ) ) {
				return true;
			}
			if ( false !== strpos( $room, ':' ) ) {
				return false;
			}
			$kind = strstr( $room, '/', true );
			return false !== $kind && '' !== $kind && in_array( $kind . self::COLLECTION_WILDCARD, $rooms, true );
		}

		/**
		 * Mints a ticket.
		 *
		 * @since n.e.x.t
		 *
		 * @param int      $user_id The user.
		 * @param string[] $rooms   Room grants (see grants()).
		 * @param int|null $now     The current time, for tests.
		 * @return string The ticket.
		 */
		public static function mint( int $user_id, array $rooms, ?int $now = null ): string {
			$now     = $now ?? time();
			$header  = self::encode(
				wp_json_encode(
					array(
						'alg' => 'HS256',
						'typ' => 'JWT',
					)
				)
			);
			$payload = self::encode(
				wp_json_encode(
					array(
						'user_id' => $user_id,
						'blog_id' => get_current_blog_id(),
						'rooms'   => array_values( array_slice( $rooms, 0, self::MAX_ROOMS ) ),
						'iat'     => $now,
						'exp'     => $now + self::TTL,
					)
				)
			);
			return $header . '.' . $payload . '.' . self::sign( $header . '.' . $payload );
		}

		/**
		 * Verifies a ticket: the signature (HS256 only), the expiry with
		 * leeway, the blog, and the claim shapes.
		 *
		 * @since n.e.x.t
		 *
		 * @param string   $ticket The ticket.
		 * @param int|null $now    The current time, for tests.
		 * @return array{user_id: int, blog_id: int, rooms: string[], iat: int, exp: int}|WP_Error
		 *         The claims, or why the ticket was refused.
		 */
		public static function verify( string $ticket, ?int $now = null ) {
			if ( ! self::is_enabled() ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Ticket mode is off.' );
			}
			if ( ! self::looks_like_ticket( $ticket ) ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Malformed ticket.' );
			}
			list( $header, $payload, $signature ) = explode( '.', $ticket );

			if ( ! hash_equals( self::sign( $header . '.' . $payload ), $signature ) ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Bad ticket signature.' );
			}

			$decoded_header = json_decode( (string) self::decode( $header ), true );
			if ( ! is_array( $decoded_header ) || 'HS256' !== ( $decoded_header['alg'] ?? '' ) ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Unsupported ticket algorithm.' );
			}

			$claims = json_decode( (string) self::decode( $payload ), true );
			if (
				! is_array( $claims )
				|| ! is_int( $claims['user_id'] ?? null ) || $claims['user_id'] < 1
				|| ! is_int( $claims['blog_id'] ?? null )
				|| ! is_int( $claims['iat'] ?? null )
				|| ! is_int( $claims['exp'] ?? null )
				|| ! is_array( $claims['rooms'] ?? null )
				|| count( $claims['rooms'] ) > self::MAX_ROOMS
			) {
				return new WP_Error( 'websocket_invalid_ticket', 'Malformed ticket claims.' );
			}
			foreach ( $claims['rooms'] as $room ) {
				if ( ! is_string( $room ) || '' === $room || strlen( $room ) > 200 ) {
					return new WP_Error( 'websocket_invalid_ticket', 'Malformed ticket rooms.' );
				}
			}

			$now = $now ?? time();
			if ( $now >= $claims['exp'] + self::LEEWAY ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Expired ticket.' );
			}
			if ( $claims['iat'] > $now + self::LEEWAY ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Ticket from the future.' );
			}
			if ( get_current_blog_id() !== $claims['blog_id'] ) {
				return new WP_Error( 'websocket_invalid_ticket', 'Ticket for another site.' );
			}

			return array(
				'user_id' => $claims['user_id'],
				'blog_id' => $claims['blog_id'],
				'rooms'   => array_values( $claims['rooms'] ),
				'iat'     => $claims['iat'],
				'exp'     => $claims['exp'],
			);
		}

		/**
		 * The base64url HMAC-SHA256 signature of the signing input.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $input `<header>.<payload>`.
		 * @return string The signature.
		 */
		private static function sign( string $input ): string {
			return self::encode( hash_hmac( 'sha256', $input, self::secret(), true ) );
		}

		/**
		 * Base64url without padding.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $bytes Raw bytes.
		 * @return string Encoded.
		 */
		private static function encode( string $bytes ): string {
			return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT segment encoding, not obfuscation.
		}

		/**
		 * The inverse of encode().
		 *
		 * @since n.e.x.t
		 *
		 * @param string $segment Encoded.
		 * @return string|false Raw bytes, or false when not base64url.
		 */
		private static function decode( string $segment ) {
			return base64_decode( strtr( $segment, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT segment decoding.
		}
	}
}
