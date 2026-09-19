<?php
/**
 * WP_Sync_Awareness class
 *
 * @package gutenberg-sync-engines
 */

if ( ! class_exists( 'WP_Sync_Awareness' ) ) {

	/**
	 * Who is in a room and what they are doing.
	 *
	 * The transports and the advisory channel each kept their own copy of
	 * this logic; it lives here once instead. The store is the room's whole
	 * array under one storage key, unchanged.
	 *
	 * @since 0.0.2
	 */
	final class WP_Sync_Awareness {
		/**
		 * The storage holding the room arrays.
		 *
		 * @since 0.0.2
		 * @var WP_Sync_Storage
		 */
		private $storage;

		/**
		 * Constructor.
		 *
		 * @since 0.0.2
		 *
		 * @param WP_Sync_Storage $storage Storage holding the room arrays.
		 */
		public function __construct( WP_Sync_Storage $storage ) {
			$this->storage = $storage;
		}

		/**
		 * Every live entry in a room.
		 *
		 * @since 0.0.2
		 *
		 * @param string $room    Room identifier.
		 * @param int    $timeout Age in seconds past which an entry is gone.
		 * @return array<int, array<string, mixed>> Entries, lowest client id first.
		 */
		public function entries( string $room, int $timeout ): array {
			return self::live( $this->storage->get_awareness_state( $room ), $timeout, 0 );
		}

		/**
		 * Records one client's awareness state and returns the room.
		 *
		 * @since 0.0.2
		 *
		 * @param string               $room      Room identifier.
		 * @param int                  $client_id The client's sync id.
		 * @param array<string, mixed> $state     The state to store.
		 * @param int                  $user_id   The WordPress user behind it.
		 * @param int                  $timeout   Age in seconds past which an
		 *                                        entry is gone.
		 * @return array<int, array<string, mixed>> The room's live entries.
		 */
		public function put( string $room, int $client_id, array $state, int $user_id, int $timeout ): array {
			$stored = $this->storage->get_awareness_state( $room );
			$live   = self::live( $stored, $timeout, $client_id );
			$live[] = array(
				'client_id'  => $client_id,
				'state'      => $state,
				'updated_at' => WP_HTTP_Polling_Sync_Server::awareness_timestamp( time() ),
				'wp_user_id' => $user_id,
			);

			return $this->store( $room, $stored, $live );
		}

		/**
		 * Removes one client's entry and returns the room.
		 *
		 * @since 0.0.2
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id The client's sync id.
		 * @param int    $timeout   Age in seconds past which an entry is gone.
		 * @return array<int, array<string, mixed>> The room's live entries.
		 */
		public function forget( string $room, int $client_id, int $timeout ): array {
			$stored = $this->storage->get_awareness_state( $room );

			return $this->store( $room, $stored, self::live( $stored, $timeout, $client_id ) );
		}

		/**
		 * Writes the room's array back, unless it is already what is stored.
		 *
		 * An idle poll carries the same state in the same timestamp bucket,
		 * so the comparison skips the write and the poll stays read-only.
		 *
		 * @since 0.0.2
		 *
		 * @param string                           $room    Room identifier.
		 * @param array<int, array<string, mixed>> $stored  What the store holds.
		 * @param array<int, array<string, mixed>> $entries What it should hold.
		 * @return array<int, array<string, mixed>> The entries, as written.
		 */
		private function store( string $room, array $stored, array $entries ): array {
			// A stable order makes "nothing changed" a plain comparison.
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return $a['client_id'] <=> $b['client_id'];
				}
			);

			if ( $entries !== $stored ) {
				$this->storage->set_awareness_state( $room, $entries );
			}

			return $entries;
		}

		/**
		 * The still-live entries of a stored array, without the client the
		 * caller is about to replace or remove.
		 *
		 * @since 0.0.2
		 *
		 * @param array<int, mixed> $stored  What the store holds.
		 * @param int               $timeout Age in seconds past which an entry is gone.
		 * @param int               $exclude Client id to leave out, or 0 for none.
		 * @return array<int, array<string, mixed>> Live entries.
		 */
		private static function live( array $stored, int $timeout, int $exclude ): array {
			$now  = time();
			$live = array();

			foreach ( $stored as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['client_id'], $entry['updated_at'] ) ) {
					continue;
				}
				if ( 0 !== $exclude && $exclude === (int) $entry['client_id'] ) {
					continue;
				}
				if ( $now - (int) $entry['updated_at'] >= $timeout ) {
					continue;
				}
				$live[] = $entry;
			}

			return $live;
		}
	}
}
