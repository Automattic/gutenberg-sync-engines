<?php
/**
 * Gutenberg_Sync_Engines_Rooms_CLI_Command class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'Gutenberg_Sync_Engines_Rooms_CLI_Command' ) && defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Read-only room diagnostics: enumerate sync storage rooms and dump one
	 * room's server-side state (engine lineage, update rows, cursor,
	 * checkpoints, room meta, awareness).
	 *
	 * A development tool, deliberately kept OUT of the production path: the
	 * plugin only loads this file on local/development sites (or under the
	 * GUTENBERG_SYNC_ENGINES_DIAGNOSTICS constant) — see
	 * Gutenberg_Sync_Engines_Plugin::load().
	 *
	 * Every read resolves the room's storage post itself before touching the
	 * storage API: the storage's own room lookup CREATES the backing post for
	 * an unknown room, which a diagnostic command must never do.
	 *
	 * @since 0.3.0
	 */
	final class Gutenberg_Sync_Engines_Rooms_CLI_Command {

		/**
		 * Postmeta key holding one update row per meta row (the framework's
		 * WP_Sync_Post_Meta_Storage convention; meta_id is the cursor).
		 *
		 * @since 0.3.0
		 * @var string
		 */
		private const UPDATE_META_KEY = 'wp_sync_update_data';

		/**
		 * Prefix namespacing per-room meta in postmeta (hardcoded in the
		 * framework storage; it exposes no constant for it).
		 *
		 * @since 0.3.0
		 * @var string
		 */
		private const ROOM_META_PREFIX = 'wp_sync_room_meta_';

		/**
		 * Lists sync storage rooms.
		 *
		 * Room identifiers are stored one-way (the storage post's slug is
		 * md5( room )), so names are recovered by hashing candidate rooms for
		 * the site's post types, posts, and taxonomies; unmatched rooms show
		 * their hash.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format: table, json, csv, yaml, count.
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration rooms list
		 *     wp collaboration rooms list --format=json
		 *
		 * @subcommand list
		 *
		 * @since 0.3.0
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function list_rooms( $args, $assoc_args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $args is part of the WP-CLI command signature.
			global $wpdb;

			$post_ids = get_posts(
				array(
					'post_type'      => 'wp_sync_storage',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);
			if ( array() === $post_ids ) {
				WP_CLI::log( 'No sync storage rooms found.' );
				return;
			}

			$known_rooms = $this->build_room_hash_map();

			$items = array();
			foreach ( $post_ids as $post_id ) {
				$slug = get_post_field( 'post_name', $post_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostics over the storage's own raw-row convention.
				$stats = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS row_count, COALESCE( MAX( meta_id ), 0 ) AS max_cursor FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
						$post_id,
						self::UPDATE_META_KEY
					)
				);

				$items[] = array(
					'post_id'  => $post_id,
					'room'     => $known_rooms[ $slug ] ?? "(unresolved: {$slug})",
					'engine'   => (string) get_post_meta( $post_id, 'wp_sync_engine', true ),
					'rows'     => (int) $stats->row_count,
					'cursor'   => (int) $stats->max_cursor,
					'modified' => get_post_field( 'post_modified_gmt', $post_id ),
				);
			}

			$format = $assoc_args['format'] ?? 'table';
			WP_CLI\Utils\format_items( $format, $items, array( 'post_id', 'room', 'engine', 'rows', 'cursor', 'modified' ) );
		}

		/**
		 * Dumps one room's server-side state.
		 *
		 * ## OPTIONS
		 *
		 * <room>
		 * : Room identifier, e.g. `postType/post:123` or `postType/wp_block`.
		 *
		 * [--rows=<count>]
		 * : Also print the last <count> update rows, newest first.
		 *
		 * [--materialize]
		 * : Also print the engine's materialized post content.
		 *
		 * [--format=<format>]
		 * : Output format: summary (default) or json (the full decoded state).
		 *
		 * ## EXAMPLES
		 *
		 *     wp collaboration rooms inspect postType/post:123
		 *     wp collaboration rooms inspect postType/post:123 --rows=10
		 *     wp collaboration rooms inspect postType/post:123 --format=json
		 *
		 * @since 0.3.0
		 *
		 * @param array $args       Positional arguments: the room identifier.
		 * @param array $assoc_args Named arguments.
		 * @return void
		 */
		public function inspect( $args, $assoc_args ) {
			global $wpdb;

			$room    = (string) $args[0];
			$post_id = $this->find_room_post_id( $room );
			if ( null === $post_id ) {
				WP_CLI::error( "No storage room found for '{$room}' (rooms are only created once a client syncs; check `wp collaboration rooms list`)." );
			}

			$storage = new WP_Sync_Post_Meta_Storage();
			// Safe now: the storage post exists, so reads cannot create it.
			$all_rows = $storage->get_updates_after_cursor( $room, 0 );

			$type_counts = array();
			foreach ( $all_rows as $row ) {
				$type                 = (string) ( $row['type'] ?? '(untyped)' );
				$type_counts[ $type ] = ( $type_counts[ $type ] ?? 0 ) + 1;
			}

			$awareness = $storage->get_awareness_state( $room );

			$state = array(
				'room'      => $room,
				'post_id'   => $post_id,
				'engine'    => $storage->get_room_engine( $room ),
				'rows'      => $storage->get_update_count( $room ),
				'cursor'    => $storage->get_cursor( $room ),
				'row_types' => $type_counts,
				'awareness' => array(
					'clients' => array_keys( $awareness ),
				),
				'room_meta' => $this->collect_room_meta( $post_id ),
			);

			$row_limit = isset( $assoc_args['rows'] ) ? max( 0, (int) $assoc_args['rows'] ) : 0;
			if ( $row_limit > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostics; meta_id (the cursor) is not exposed by the storage API.
				$raw_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT %d",
						$post_id,
						self::UPDATE_META_KEY,
						$row_limit
					)
				);

				$state['last_rows'] = array();
				foreach ( $raw_rows as $raw ) {
					$state['last_rows'][] = $this->summarize_row( (int) $raw->meta_id, (string) $raw->meta_value );
				}
			}

			if ( isset( $assoc_args['materialize'] ) && array() !== $all_rows ) {
				$engine = ( new WP_HTTP_Polling_Sync_Server( $storage ) )
					->get_engine_registry()
					->get_engine_for_room( $room );
				if ( method_exists( $engine, 'materialize' ) ) {
					$state['materialized'] = $engine->materialize( $room );
				}
			}

			if ( 'json' === ( $assoc_args['format'] ?? 'summary' ) ) {
				WP_CLI::log( (string) wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			$this->print_summary( $state );
		}

		/**
		 * Prints the human-readable summary of an inspected room.
		 *
		 * @since 0.3.0
		 *
		 * @param array $state Assembled room state.
		 * @return void
		 */
		private function print_summary( array $state ): void {
			WP_CLI::log( "Room:      {$state['room']} (post {$state['post_id']})" );
			WP_CLI::log( 'Engine:    ' . ( $state['engine'] ? $state['engine'] : '(unstamped)' ) );
			WP_CLI::log( "Rows:      {$state['rows']} (cursor {$state['cursor']})" );

			$types = array();
			foreach ( $state['row_types'] as $type => $count ) {
				$types[] = "{$type}×{$count}";
			}
			WP_CLI::log( 'Row types: ' . ( $types ? implode( ', ', $types ) : '(none)' ) );
			WP_CLI::log( 'Awareness: ' . ( $state['awareness']['clients'] ? 'clients ' . implode( ', ', $state['awareness']['clients'] ) : '(empty)' ) );

			if ( array() === $state['room_meta'] ) {
				WP_CLI::log( 'Room meta: (none)' );
			} else {
				WP_CLI::log( 'Room meta:' );
				foreach ( $state['room_meta'] as $key => $summary ) {
					WP_CLI::log( "  {$key}: {$summary}" );
				}
			}

			foreach ( $state['last_rows'] ?? array() as $row ) {
				WP_CLI::log( "  row {$row['cursor']}: {$row['summary']}" );
			}

			if ( array_key_exists( 'materialized', $state ) ) {
				WP_CLI::log( 'Materialized content:' );
				WP_CLI::log( (string) $state['materialized'] );
			}
		}

		/**
		 * Resolves a room identifier to its storage post without creating one:
		 * the oldest published `wp_sync_storage` post whose slug is the room's
		 * md5 (the canonical-post rule the framework storage uses).
		 *
		 * @since 0.3.0
		 *
		 * @param string $room Room identifier.
		 * @return int|null Post ID, or null when the room has no storage post.
		 */
		private function find_room_post_id( string $room ): ?int {
			$ids = get_posts(
				array(
					'post_type'      => 'wp_sync_storage',
					'post_status'    => 'publish',
					'name'           => md5( $room ),
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);
			return array() === $ids ? null : (int) $ids[0];
		}

		/**
		 * Builds the md5(room) → room reverse map from the site's plausible
		 * room names: entity rooms for every post, and collection rooms for
		 * every post type and taxonomy. Best-effort — an unmatched hash is
		 * still listed, just unresolved.
		 *
		 * @since 0.3.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @return array<string, string> Hash → room identifier.
		 */
		private function build_room_hash_map(): array {
			global $wpdb;

			$map = array();
			foreach ( get_post_types() as $type ) {
				$map[ md5( "postType/{$type}" ) ] = "postType/{$type}";
			}
			foreach ( get_taxonomies() as $taxonomy ) {
				$map[ md5( "taxonomy/{$taxonomy}" ) ] = "taxonomy/{$taxonomy}";
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostics; one bounded scan beats a per-type query fan-out.
			$posts = $wpdb->get_results(
				"SELECT ID, post_type FROM {$wpdb->posts} WHERE post_type NOT IN ( 'wp_sync_storage', 'revision' ) ORDER BY ID DESC LIMIT 10000"
			);
			foreach ( $posts as $post ) {
				$candidate                = "postType/{$post->post_type}:{$post->ID}";
				$map[ md5( $candidate ) ] = $candidate;
			}

			return $map;
		}

		/**
		 * Collects and summarizes the room's namespaced meta (engine
		 * checkpoints, canonical documents, floors), one line per key.
		 *
		 * @since 0.3.0
		 *
		 * @param int $post_id Storage post ID.
		 * @return array<string, string> Meta key (unprefixed) → summary.
		 */
		private function collect_room_meta( int $post_id ): array {
			$summaries = array();
			foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
				if ( 0 !== strpos( $meta_key, self::ROOM_META_PREFIX ) ) {
					continue;
				}
				$key     = substr( $meta_key, strlen( self::ROOM_META_PREFIX ) );
				$decoded = json_decode( (string) ( $values[0] ?? '' ), true );

				$summaries[ $key ] = $this->summarize_room_meta( $key, $decoded );
			}
			return $summaries;
		}

		/**
		 * One-line summary of a known engine meta value; unknown keys fall
		 * back to their JSON.
		 *
		 * @since 0.3.0
		 *
		 * @param string $key     Unprefixed meta key.
		 * @param mixed  $decoded Decoded meta value.
		 * @return string Summary.
		 */
		private function summarize_room_meta( string $key, $decoded ): string {
			if ( is_array( $decoded ) ) {
				$parts = array();
				foreach ( array( 'seq', 'cursor', 'version', 'version_seq' ) as $field ) {
					if ( isset( $decoded[ $field ] ) && is_scalar( $decoded[ $field ] ) ) {
						$parts[] = "{$field} {$decoded[$field]}";
					}
				}
				foreach ( array( 'doc', 'content' ) as $field ) {
					if ( isset( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) ) {
						$parts[] = $field . ' ' . strlen( $decoded[ $field ] ) . 'b';
					}
				}
				if ( isset( $decoded['sync_meta']['version_snapshots'] ) && is_array( $decoded['sync_meta']['version_snapshots'] ) ) {
					$parts[] = 'snapshots ' . count( $decoded['sync_meta']['version_snapshots'] );
				}
				if ( array() !== $parts ) {
					return implode( ', ', $parts );
				}
			}
			if ( is_scalar( $decoded ) ) {
				return (string) $decoded;
			}
			return (string) wp_json_encode( $decoded );
		}

		/**
		 * One-line summary of a stored update row (cursor, type, author,
		 * size, and a payload hint when the inner JSON carries known keys).
		 *
		 * @since 0.3.0
		 *
		 * @param int    $cursor Row cursor (meta_id).
		 * @param string $raw    Raw meta value (the storage's JSON row).
		 * @return array{cursor: int, summary: string} Row summary.
		 */
		private function summarize_row( int $cursor, string $raw ): array {
			$row       = json_decode( $raw, true );
			$type      = is_array( $row ) ? (string) ( $row['type'] ?? '(untyped)' ) : '(undecodable)';
			$client_id = is_array( $row ) ? (int) ( $row['client_id'] ?? 0 ) : 0;
			$data      = is_array( $row ) && is_string( $row['data'] ?? null ) ? $row['data'] : '';

			$hints   = array();
			$payload = json_decode( $data, true );
			if ( is_array( $payload ) ) {
				foreach ( array( 'type', 'seq', 'version', 'baseVersion', 'reason', 'proposalId', 'intentId', 'checkpoint' ) as $field ) {
					if ( isset( $payload[ $field ] ) && is_scalar( $payload[ $field ] ) ) {
						$hints[] = "{$field}=" . ( is_bool( $payload[ $field ] ) ? 'true' : $payload[ $field ] );
					}
				}
			}

			return array(
				'cursor'  => $cursor,
				'summary' => "{$type} by client {$client_id}, " . strlen( $data ) . 'b'
					. ( $hints ? ' (' . implode( ', ', $hints ) . ')' : '' ),
			);
		}
	}

	WP_CLI::add_command( 'collaboration rooms', 'Gutenberg_Sync_Engines_Rooms_CLI_Command' );
}
