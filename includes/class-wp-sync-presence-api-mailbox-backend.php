<?php
/**
 * WP_Sync_Presence_API_Mailbox_Backend class
 *
 * @package gutenberg-sync-engines
 */

if ( ! class_exists( 'WP_Sync_Presence_API_Mailbox_Backend' ) ) {

	/**
	 * Holds the advisory channel's mailboxes in the Presence API plugin's
	 * shared `wp_presence` table, one `gsemail-` row per message, beside
	 * the tab rows `WP_Sync_Presence_API_Tab_List_Backend` writes. Each
	 * row expires by itself, so nothing is swept.
	 *
	 * @since n.e.x.t
	 */
	final class WP_Sync_Presence_API_Mailbox_Backend implements WP_Sync_Mailbox_Backend {
		/**
		 * The client id prefix this backend's rows carry; the recipient
		 * hash and a sortable key are appended. It differs from
		 * awareness's `gse-` and the tab list's `gsetab-`, so none reads
		 * another's rows.
		 *
		 * @since n.e.x.t
		 * @var string
		 */
		const CLIENT_PREFIX = 'gsemail-';

		/**
		 * Whether the Presence API can hold the mailboxes, by the same
		 * test as awareness.
		 *
		 * @since n.e.x.t
		 *
		 * @return bool Whether this backend can serve.
		 */
		public static function is_available(): bool {
			return WP_Sync_Presence_API_Awareness_Backend::is_available();
		}

		/**
		 * Files messages for one recipient, one row each.
		 *
		 * @since n.e.x.t
		 *
		 * @param string                           $room       Room identifier.
		 * @param string                           $to         The recipient's token.
		 * @param array<int, array<string, mixed>> $messages   The messages, oldest
		 *                                                     first.
		 * @param int                              $user_id    The WordPress user
		 *                                                     sending them.
		 * @param int                              $expires_in Seconds a message
		 *                                                     waits for pickup.
		 * @return void
		 */
		public function send( string $room, string $to, array $messages, int $user_id, int $expires_in ): void {
			// The key sorts by send time, then by position in this batch;
			// the sender part keeps two senders in the same microsecond
			// from writing the same row.
			list( $usec, $sec ) = explode( ' ', microtime() );
			$stamp              = sprintf( '%010d%06d', (int) $sec, (int) round( (float) $usec * 1000000 ) % 1000000 );
			$sender             = substr( md5( (string) ( $messages[0]['from'] ?? '' ) ), 0, 8 );
			$date               = gmdate( 'Y-m-d H:i:s' );

			foreach ( array_values( $messages ) as $index => $message ) {
				$client_id = $this->recipient_prefix( $to ) . $stamp . '-' . $sender . '-' . sprintf( '%03d', $index );
				wp_set_presence( $room, $client_id, $message, $user_id, $date, $expires_in );
			}
		}

		/**
		 * Removes and returns one recipient's messages.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room       Room identifier.
		 * @param string $to         The recipient's token.
		 * @param int    $expires_in Seconds a message waits for pickup.
		 * @return array<int, array<string, mixed>> Messages, oldest first.
		 */
		public function take( string $room, string $to, int $expires_in ): array {
			$messages = array();
			foreach ( $this->rows( $room, $to, $expires_in ) as $client_id => $data ) {
				// A failed removal keeps the message for the next take.
				// Presence API 0.7.0 also reports success for a row that
				// was already gone, so two takes racing on one mailbox can
				// both return it; a tab's beats run one at a time, and the
				// tab drops a message it has seen by its id.
				if ( wp_remove_presence( $room, $client_id ) ) {
					$messages[] = $data;
				}
			}
			return $messages;
		}

		/**
		 * Deletes one recipient's messages.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room Room identifier.
		 * @param string $to   The recipient's token.
		 * @return void
		 */
		public function clear( string $room, string $to ): void {
			// No row outlives an hour, the Presence API's longest lifetime.
			foreach ( array_keys( $this->rows( $room, $to, HOUR_IN_SECONDS ) ) as $client_id ) {
				wp_remove_presence( $room, $client_id );
			}
		}

		/**
		 * One recipient's rows, oldest first.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room    Room identifier.
		 * @param string $to      The recipient's token.
		 * @param int    $timeout Age in seconds past which a row is gone.
		 * @return array<string, array<string, mixed>> client id => message.
		 */
		private function rows( string $room, string $to, int $timeout ): array {
			$prefix = $this->recipient_prefix( $to );
			$rows   = array();

			// Presence API 0.7.0 filters by the prefix itself; older
			// versions ignore the argument, so the check below stays.
			foreach ( wp_get_presence( $room, $timeout, $prefix ) as $row ) {
				$client_id = (string) $row->client_id;
				if ( str_starts_with( $client_id, $prefix ) ) {
					$rows[ $client_id ] = is_array( $row->data ) ? $row->data : array();
				}
			}

			ksort( $rows, SORT_STRING );
			return $rows;
		}

		/**
		 * The client id prefix shared by one recipient's rows.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $to The recipient's token.
		 * @return string Client id prefix.
		 */
		private function recipient_prefix( string $to ): string {
			return self::CLIENT_PREFIX . md5( $to ) . '-';
		}
	}
}
