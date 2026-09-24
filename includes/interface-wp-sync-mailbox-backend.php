<?php
/**
 * WP_Sync_Mailbox_Backend interface
 *
 * @package gutenberg-sync-engines
 */

if ( ! interface_exists( 'WP_Sync_Mailbox_Backend' ) ) {
	/**
	 * A substitute store for the advisory channel's mailboxes, where one
	 * tab leaves the WebRTC handshake messages another tab picks up on its
	 * next beat, plugged in via the `wp_sync_mailbox_backend` filter.
	 *
	 * The built-in store keeps one options row per recipient and sweeps
	 * the rows of tabs that left without saying so.
	 *
	 * The contract:
	 *
	 * - Two senders writing to one recipient at once must both land.
	 * - `take` returns the recipient's messages oldest first and removes
	 *   them; a message filed during a take is kept for the next one.
	 * - A message older than its `$expires_in` is never returned, and
	 *   expiry is the backend's job, as no caller sweeps.
	 * - Callers have already authorized the room, so a backend must not
	 *   add a capability check.
	 *
	 * @since n.e.x.t
	 */
	interface WP_Sync_Mailbox_Backend {
		/**
		 * Files messages for one recipient.
		 *
		 * @since n.e.x.t
		 *
		 * @param string                           $room       Room identifier.
		 * @param string                           $to         The recipient's token.
		 * @param array<int, array<string, mixed>> $messages   The messages, oldest
		 *                                                     first, each with id,
		 *                                                     from, kind and data.
		 * @param int                              $user_id    The WordPress user
		 *                                                     sending them.
		 * @param int                              $expires_in Seconds a message
		 *                                                     waits for pickup.
		 * @return void
		 */
		public function send( string $room, string $to, array $messages, int $user_id, int $expires_in ): void;

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
		public function take( string $room, string $to, int $expires_in ): array;

		/**
		 * Deletes one recipient's messages (on leave).
		 *
		 * @since n.e.x.t
		 *
		 * @param string $room Room identifier.
		 * @param string $to   The recipient's token.
		 * @return void
		 */
		public function clear( string $room, string $to ): void;
	}
}
