<?php
/**
 * The DE-RTC sync engine: server-governed three-way merges of content
 * proposals, behind the framework's WP_Sync_Engine SPI.
 *
 * Distributed Editing's model, adapted to the room/update-log substrate:
 * the server owns a canonical document per room; clients submit whole
 * proposals (proposed content + a block-native update descriptor proving
 * the edit against a named base version); the server merges each proposal
 * against the current canonical content with the ported DE-RTC merge core
 * (includes/engines/de-rtc/merge-core.php); most edits merge cleanly, and
 * genuine conflicts are escalated for human decision instead of silently
 * merged. Peers receive the merged canonical content as server-authored
 * rows.
 *
 * Where the upstream prototype co-located sync metadata inside
 * post_content (the wp:sync-meta pseudo-block) and rode wp_update_post(),
 * this engine keeps canonical state in sync-storage room meta — the
 * revision-scan/kses-allowance/preservation periphery that existed to
 * protect in-content metadata is unnecessary here. Genesis still adopts
 * (and strips) an existing sync-meta block, so documents written by an
 * upstream DE-RTC install keep their version lineage.
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'WP_De_RTC_Engine' ) && interface_exists( 'WP_Sync_Engine' ) ) {

	/**
	 * Server-authoritative DE-RTC merge engine.
	 *
	 * @since 0.3.0
	 */
	class WP_De_RTC_Engine implements WP_Sync_Engine {

		/**
		 * Engine slug (must byte-match the client adapter).
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const SLUG = 'de-rtc';

		/**
		 * Engine protocol version (bump on breaking payload changes).
		 *
		 * @since 0.3.0
		 * @var int
		 */
		const PROTOCOL_VERSION = 1;

		/**
		 * Client-sent update type: a content proposal against a base version.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const UPDATE_TYPE_PROPOSAL = 'proposal';

		/**
		 * Server-emitted update type: accepted canonical content at a version.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const UPDATE_TYPE_CONTENT = 'content';

		/**
		 * Server-emitted update type: genesis/checkpoint snapshot.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const UPDATE_TYPE_SNAPSHOT = 'snapshot';

		/**
		 * Attribution client id for server-authored rows. Outside the
		 * transport's client id range, mirroring the yjs-server genesis id
		 * convention (which uses 2000000000).
		 *
		 * @since 0.3.0
		 * @var int
		 */
		const SERVER_CLIENT_ID = 2000000001;

		/**
		 * Room meta key: canonical document state.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const META_DOC = 'de_rtc_doc';

		/**
		 * Room meta key: last checkpoint cursor.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const META_CHECKPOINT = 'de_rtc_checkpoint';

		/**
		 * Room meta key: compaction floor cursor.
		 *
		 * @since 0.3.0
		 * @var string
		 */
		const META_FLOOR = 'de_rtc_floor';

		/**
		 * Sync storage backend.
		 *
		 * @since 0.3.0
		 * @var WP_Sync_Storage
		 */
		private $storage;

		/**
		 * Per-request room state cache.
		 *
		 * @since 0.3.0
		 * @var array<string, array|null>
		 */
		private $room_states = array();

		/**
		 * Constructor.
		 *
		 * @since 0.3.0
		 *
		 * @param WP_Sync_Storage $storage Storage backend.
		 */
		public function __construct( WP_Sync_Storage $storage ) {
			$this->storage = $storage;
			if ( ! function_exists( 'wp_de_rtc_get_reason_codes' ) ) {
				require_once __DIR__ . '/merge-core.php';
			}
		}

		/**
		 * Engine slug.
		 *
		 * @since 0.3.0
		 *
		 * @return string Engine slug.
		 */
		public function get_slug(): string {
			return self::SLUG;
		}

		/**
		 * Engine protocol version.
		 *
		 * @since 0.3.0
		 *
		 * @return int Protocol version.
		 */
		public function get_protocol_version(): int {
			return self::PROTOCOL_VERSION;
		}

		/**
		 * Update types this engine reads or writes.
		 *
		 * @since 0.3.0
		 *
		 * @return string[] Update type identifiers.
		 */
		public function get_update_types(): array {
			return array(
				self::UPDATE_TYPE_PROPOSAL,
				self::UPDATE_TYPE_CONTENT,
				self::UPDATE_TYPE_SNAPSHOT,
			);
		}

		/**
		 * Ingests a batch of proposals from one client.
		 *
		 * Each proposal is merged against the canonical document under the
		 * per-room ingest lock (three-way merges are order-dependent, unlike
		 * CRDT merges, so ingest must serialize per room — same rationale as
		 * the intent-log engine). Accepted proposals advance the canonical
		 * version and append a server-authored `content` row; conflicts and
		 * invalid proposals settle per-proposal as dispositions.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Client identifier.
		 * @param int    $cursor    Client transport cursor (unused).
		 * @param array  $updates   Typed updates.
		 * @param array  $context   Transport context.
		 * @return array|WP_Error array( 'dispositions' => array|null ) or error.
		 */
		public function handle_updates( string $room, int $client_id, int $cursor, array $updates, array $context ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $cursor and $context are part of the WP_Sync_Engine contract.
			if ( array() === $updates ) {
				return array( 'dispositions' => null );
			}

			foreach ( $updates as $update ) {
				if ( self::UPDATE_TYPE_PROPOSAL !== $update['type'] ) {
					return new WP_Error(
						'rest_invalid_update_type',
						__( 'Clients may only send proposal updates to a de-rtc room.', 'gutenberg' ),
						array( 'status' => 400 )
					);
				}
			}

			$lock = $this->acquire_room_lock( $room );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			try {
				return $this->handle_updates_locked( $room, $client_id, $updates );
			} finally {
				$this->release_room_lock( $room );
			}
		}

		/**
		 * The body of handle_updates(), run under the per-room ingest lock.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Client identifier.
		 * @param array  $updates   Proposal updates.
		 * @return array|WP_Error array( 'dispositions' => array ) or error.
		 */
		private function handle_updates_locked( string $room, int $client_id, array $updates ) {
			$state = $this->load_room( $room );
			if ( is_wp_error( $state ) ) {
				return $state;
			}

			$dispositions = array();
			foreach ( $updates as $update ) {
				$proposal    = json_decode( (string) $update['data'], true );
				$proposal_id = is_array( $proposal ) && is_string( $proposal['proposalId'] ?? null ) && '' !== $proposal['proposalId']
					? $proposal['proposalId']
					: null;

				/*
				 * Malformed proposals settle per-proposal as voids instead of
				 * failing the request (the intent-log rationale: one bad row
				 * must not starve the batch). Rows without a proposalId are
				 * dropped — nothing could correlate their disposition.
				 */
				if (
					null === $proposal_id ||
					! is_string( $proposal['baseVersion'] ?? null ) || '' === $proposal['baseVersion'] ||
					! is_string( $proposal['proposedContent'] ?? null ) ||
					( null !== ( $proposal['clientUpdate'] ?? null ) && ! is_array( $proposal['clientUpdate'] ) )
				) {
					if ( null !== $proposal_id ) {
						$dispositions[] = array(
							'intentId' => $proposal_id,
							'status'   => 'voided',
							'reason'   => 'invalid-payload',
						);
					}
					continue;
				}

				$disposition    = $this->ingest_proposal( $room, $client_id, $state, $proposal );
				$disposition    = array_merge( array( 'intentId' => $proposal_id ), $disposition );
				$dispositions[] = $disposition;
			}

			$this->maybe_checkpoint( $room, $state );

			return array( 'dispositions' => $dispositions );
		}

		/**
		 * Merges one proposal into the canonical document.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Client identifier.
		 * @param array  $state     Room state (by reference via room cache).
		 * @param array  $proposal  Decoded proposal payload.
		 * @return array Disposition fields (status, reason?, version?).
		 */
		private function ingest_proposal( string $room, int $client_id, array &$state, array $proposal ) {
			$base_content = $this->resolve_base_content( $state, $proposal['baseVersion'] );
			if ( null === $base_content ) {
				return array(
					'status' => 'voided',
					'reason' => 'unknown-base-version',
				);
			}

			$proposed_content = $proposal['proposedContent'];

			/*
			 * The capability lane, at ingest: an author without
			 * unfiltered_html cannot land content that kses would rewrite.
			 * Upstream DE-RTC sequesters exactly the risky blocks for a
			 * privileged reviewer; this engine does not have the review lane
			 * yet, so the whole proposal escalates (the author keeps their
			 * local copy and the disposition names the reason). Documented as
			 * a gap in docs/engine-comparison.md.
			 */
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$sanitized = wp_kses_post( $proposed_content );
				if ( $sanitized !== $proposed_content ) {
					return array(
						'status' => 'escalated',
						'reason' => 'requires-unfiltered-html',
					);
				}
			}

			$result = wp_de_rtc_get_automerge_retry_save_result(
				$base_content,
				$state['content'],
				$proposed_content,
				$proposal['clientUpdate'] ?? null
			);

			if ( is_wp_error( $result ) ) {
				if ( 'de_rtc_rebase_failed' === $result->get_error_code() ) {
					// A genuine conflict: DE-RTC policy is a human decision,
					// not a silent merge. The author rebases onto the latest
					// content row and resubmits (or resolves by hand).
					return array(
						'status' => 'escalated',
						'reason' => 'manual-conflict-required',
					);
				}

				$data = $result->get_error_data();
				return array(
					'status' => 'voided',
					'reason' => is_array( $data ) && is_string( $data['detail'] ?? null )
						? $data['detail']
						: $result->get_error_code(),
				);
			}

			// Accepted: advance the canonical version.
			$next_seq     = (int) $state['version_seq'] + 1;
			$next_version = 'v' . $next_seq;
			$merged       = (string) $result['merged_content'];

			$state['sync_meta'] = wp_de_rtc_update_automerge_version_snapshots(
				is_array( $state['sync_meta'] ) ? $state['sync_meta'] : array(),
				$state['version'],
				$state['content'],
				$next_version,
				$merged
			);

			$stored = $this->add_row(
				$room,
				self::SERVER_CLIENT_ID,
				self::UPDATE_TYPE_CONTENT,
				wp_json_encode(
					array(
						'version'        => $next_version,
						'baseVersion'    => $state['version'],
						'content'        => $merged,
						'authorClientId' => $client_id,
						'proposalId'     => $proposal['proposalId'],
					)
				)
			);
			if ( ! $stored ) {
				return array(
					'status' => 'voided',
					'reason' => 'storage-error',
				);
			}

			$state['version']     = $next_version;
			$state['version_seq'] = $next_seq;
			$state['content']     = $merged;
			$this->save_canonical( $room, $state );

			return array(
				'status'  => 'applied',
				'version' => $next_version,
			);
		}

		/**
		 * Resolves the content a proposal was authored against.
		 *
		 * @since 0.3.0
		 *
		 * @param array  $state        Room state.
		 * @param string $base_version Proposal base version label.
		 * @return string|null Base content, or null when unknown.
		 */
		private function resolve_base_content( array $state, string $base_version ) {
			if ( $base_version === $state['version'] ) {
				return $state['content'];
			}

			$snapshots = isset( $state['sync_meta']['version_snapshots'] ) && is_array( $state['sync_meta']['version_snapshots'] )
				? $state['sync_meta']['version_snapshots']
				: array();
			$snapshot  = $snapshots[ $base_version ] ?? null;
			if ( is_array( $snapshot ) && is_string( $snapshot['content_base64'] ?? null ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a stored version snapshot's content.
				$decoded = base64_decode( $snapshot['content_base64'], true );
				if ( false !== $decoded ) {
					return $decoded;
				}
			}

			return null;
		}

		/**
		 * Returns stored rows after a cursor, lazily initializing genesis.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Client identifier.
		 * @param int    $cursor    Client cursor.
		 * @param array  $context   Transport context.
		 * @return array Response envelope.
		 */
		public function get_updates_since( string $room, int $client_id, int $cursor, array $context ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $client_id and $context are part of the WP_Sync_Engine contract.
			if ( $cursor > 0 && method_exists( $this->storage, 'get_room_meta' ) ) {
				$floor = $this->storage->get_room_meta( $room, self::META_FLOOR );
				if ( is_numeric( $floor ) && $cursor < (int) $floor ) {
					$cursor = (int) $floor - 1;
				}
			}

			$rows = $this->storage->get_updates_after_cursor( $room, $cursor );

			// See the yjs-server engine for why this check must run AFTER the
			// read (the storage's update count is a per-request cache that
			// only the read refreshes).
			if ( 0 === $this->storage->get_update_count( $room ) ) {
				$this->room_states[ $room ] = null;
				$this->load_room( $room );
				$rows = $this->storage->get_updates_after_cursor( $room, $cursor );
			}

			$typed_updates = array();
			foreach ( $rows as $row ) {
				// All stored rows are server-authored (content/snapshot) and
				// relevant to every client, including the proposal's author —
				// an accepted content row is the authoritative confirmation.
				$typed_updates[] = array(
					'data' => $row['data'],
					'type' => $row['type'],
				);
			}

			return array(
				'end_cursor'     => $this->storage->get_cursor( $room ),
				'room'           => $room,
				'should_compact' => false,
				'total_updates'  => $this->storage->get_update_count( $room ),
				'updates'        => $typed_updates,
			);
		}

		/**
		 * Returns the canonical post content for a room.
		 *
		 * Convention shared with the other engines (used by tests and the
		 * benchmark's convergence oracle; not part of the SPI).
		 *
		 * @since 0.3.0
		 *
		 * @param string $room Room identifier.
		 * @return string|null Canonical content, or null on failure.
		 */
		public function materialize( string $room ): ?string {
			$state = $this->load_room( $room );
			if ( is_wp_error( $state ) ) {
				return null;
			}

			return (string) $state['content'];
		}

		/**
		 * Loads (and lazily initializes) the canonical state for a room.
		 *
		 * The canonical snapshot in room meta reflects the log up to its
		 * stamped cursor; `content` rows past that cursor are applied on top
		 * (catch-up and lost-save-race repair). Without room-meta support the
		 * state rebuilds from the retained rows every time.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room Room identifier.
		 * @return array|WP_Error Room state or error.
		 */
		private function load_room( string $room ) {
			if ( isset( $this->room_states[ $room ] ) && null !== $this->room_states[ $room ] ) {
				return $this->room_states[ $room ];
			}

			$has_meta = method_exists( $this->storage, 'get_room_meta' );
			$meta     = $has_meta ? $this->storage->get_room_meta( $room, self::META_DOC ) : null;

			$state       = null;
			$meta_cursor = 0;
			if ( is_array( $meta ) && is_string( $meta['version'] ?? null ) && is_string( $meta['content'] ?? null ) ) {
				$state       = array(
					'version'     => $meta['version'],
					'version_seq' => (int) ( $meta['version_seq'] ?? 0 ),
					'content'     => $meta['content'],
					'sync_meta'   => is_array( $meta['sync_meta'] ?? null ) ? $meta['sync_meta'] : array(),
				);
				$meta_cursor = (int) ( $meta['cursor'] ?? 0 );
			}

			$rows = $this->storage->get_updates_after_cursor( $room, $meta_cursor );

			if ( null === $state && array() === $rows ) {
				return $this->initialize_room( $room );
			}

			if ( null === $state ) {
				$state = array(
					'version'     => 'v0',
					'version_seq' => 0,
					'content'     => '',
					'sync_meta'   => array(),
				);
			}

			foreach ( $rows as $row ) {
				if ( self::UPDATE_TYPE_PROPOSAL === $row['type'] ) {
					continue; // Not stored by this engine, but be tolerant.
				}
				$decoded = json_decode( (string) $row['data'], true );
				if ( ! is_array( $decoded ) || ! is_string( $decoded['version'] ?? null ) || ! is_string( $decoded['content'] ?? null ) ) {
					continue;
				}
				$row_seq = (int) ltrim( $decoded['version'], 'v' );
				if ( $row_seq <= (int) $state['version_seq'] && 'v0' !== $state['version'] ) {
					continue; // Already reflected in the canonical snapshot.
				}
				$state['sync_meta']   = wp_de_rtc_update_automerge_version_snapshots(
					$state['sync_meta'],
					$state['version'],
					$state['content'],
					$decoded['version'],
					$decoded['content']
				);
				$state['version']     = $decoded['version'];
				$state['version_seq'] = $row_seq;
				$state['content']     = $decoded['content'];
			}

			$this->room_states[ $room ] = $state;

			return $state;
		}

		/**
		 * Builds and stores the room's genesis snapshot from post content.
		 *
		 * Deterministic: derived purely from the saved post, so racing
		 * initializers append byte-identical rows that replay idempotently.
		 * A sync-meta block left in post_content by an upstream DE-RTC
		 * install is adopted (version lineage continues) and stripped from
		 * the canonical content.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room Room identifier.
		 * @return array|WP_Error Room state or error.
		 */
		private function initialize_room( string $room ) {
			$content   = '';
			$sync_meta = array();
			$parsed    = class_exists( 'WP_Sync_Config' ) ? WP_Sync_Config::parse_room( $room ) : null;
			if ( null !== $parsed && 'postType' === $parsed['entity_kind'] && ! empty( $parsed['object_id'] ) ) {
				$post = get_post( (int) $parsed['object_id'] );
				if ( $post instanceof WP_Post ) {
					$content = (string) $post->post_content;
				}
			}

			if ( '' !== $content ) {
				$stripped = wp_de_rtc_parse_post_content_sync_meta( $content, array( 'allow_script_stripped_sync_meta' => true ) );
				if ( is_array( $stripped ) && is_string( $stripped['content'] ?? null ) ) {
					$content = $stripped['content'];
					if ( is_array( $stripped['sync_meta'] ?? null ) ) {
						$sync_meta = $stripped['sync_meta'];
					}
				} else {
					$content = wp_de_rtc_canonicalize_post_content_core_block_names( $content );
				}
			}

			$version = 'v1';
			$state   = array(
				'version'     => $version,
				'version_seq' => 1,
				'content'     => $content,
				'sync_meta'   => wp_de_rtc_update_automerge_version_snapshots( $sync_meta, $version, $content ),
			);

			$stored = $this->add_row(
				$room,
				self::SERVER_CLIENT_ID,
				self::UPDATE_TYPE_SNAPSHOT,
				wp_json_encode(
					array(
						'version' => $version,
						'content' => $content,
					)
				)
			);
			if ( ! $stored ) {
				return new WP_Error(
					'rest_sync_storage_error',
					__( 'Failed to store the room genesis snapshot.', 'gutenberg' ),
					array( 'status' => 500 )
				);
			}

			// The genesis row is the room's first stored row: stamp lineage.
			$this->storage->set_room_engine( $room, $this->get_slug() );

			$this->save_canonical( $room, $state );

			return $state;
		}

		/**
		 * Persists the canonical state with the cursor it reflects.
		 *
		 * @since 0.3.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room  Room identifier.
		 * @param array  $state Room state.
		 * @return void
		 */
		private function save_canonical( string $room, array $state ): void {
			$this->room_states[ $room ] = $state;
			if ( ! method_exists( $this->storage, 'set_room_meta' ) ) {
				return;
			}
			global $wpdb;
			$cursor = isset( $wpdb ) ? (int) $wpdb->insert_id : 0;
			if ( $cursor <= 0 ) {
				$cursor = $this->storage->get_cursor( $room );
			}
			$this->storage->set_room_meta(
				$room,
				self::META_DOC,
				array(
					'version'     => $state['version'],
					'version_seq' => (int) $state['version_seq'],
					'content'     => $state['content'],
					'sync_meta'   => $state['sync_meta'],
					'cursor'      => $cursor,
				)
			);
		}

		/**
		 * Appends a compaction checkpoint and trims history behind the
		 * previous one (the shared retention invariant: rows from the
		 * previous checkpoint onward are always kept).
		 *
		 * @since 0.3.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room  Room identifier.
		 * @param array  $state Room state at the head.
		 * @return bool Whether a checkpoint was appended.
		 */
		private function maybe_checkpoint( string $room, array $state ): bool {
			if ( ! method_exists( $this->storage, 'get_room_meta' ) || ! method_exists( $this->storage, 'set_room_meta' ) ) {
				return false;
			}

			/**
			 * Filters the de-rtc checkpoint interval: a compaction checkpoint
			 * is appended once this many rows accumulate past the previous one.
			 *
			 * @since 0.3.0
			 *
			 * @param int    $interval Interval in stored rows.
			 * @param string $room     Room identifier.
			 */
			$interval = (int) apply_filters( 'wp_sync_de_rtc_checkpoint_interval', 100, $room );
			if ( $interval < 1 || $this->storage->get_update_count( $room ) < $interval ) {
				return false;
			}

			$previous    = $this->storage->get_room_meta( $room, self::META_CHECKPOINT );
			$prev_cursor = is_array( $previous ) && isset( $previous['cursor'] ) ? (int) $previous['cursor'] : 0;
			$window      = count( $this->storage->get_updates_after_cursor( $room, $prev_cursor ) );
			if ( $window < $interval ) {
				return false;
			}

			$stored = $this->add_row(
				$room,
				self::SERVER_CLIENT_ID,
				self::UPDATE_TYPE_SNAPSHOT,
				wp_json_encode(
					array(
						'version'    => $state['version'],
						'content'    => $state['content'],
						'checkpoint' => true,
					)
				)
			);
			if ( ! $stored ) {
				return false; // Non-fatal: the next commit retries.
			}

			global $wpdb;
			$cursor = isset( $wpdb ) ? (int) $wpdb->insert_id : 0;
			if ( $cursor <= 0 ) {
				return true;
			}
			$this->storage->set_room_meta( $room, self::META_CHECKPOINT, array( 'cursor' => $cursor ) );

			if ( $prev_cursor > 0 ) {
				$this->storage->remove_updates_before_cursor( $room, $prev_cursor );
				$this->storage->set_room_meta( $room, self::META_FLOOR, $prev_cursor );
			}

			return true;
		}

		/**
		 * Acquires the per-room ingest lock (MySQL GET_LOCK).
		 *
		 * @since 0.3.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room Room identifier.
		 * @return true|WP_Error True when held, retryable error otherwise.
		 */
		private function acquire_room_lock( string $room ) {
			global $wpdb;

			$acquired = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT GET_LOCK(%s, %d)',
					$this->room_lock_name( $room ),
					5
				)
			);
			if ( '1' === (string) $acquired ) {
				return true;
			}

			return new WP_Error(
				'rest_sync_room_busy',
				__( 'The room is busy processing another request. Retry shortly.', 'gutenberg' ),
				array( 'status' => 503 )
			);
		}

		/**
		 * Releases the per-room ingest lock.
		 *
		 * @since 0.3.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room Room identifier.
		 * @return void
		 */
		private function release_room_lock( string $room ): void {
			global $wpdb;

			$wpdb->query(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->room_lock_name( $room ) )
			);
		}

		/**
		 * The MySQL user-lock name for a room (shared shape with the
		 * intent-log engine, so the two engines cannot deadlock each other).
		 *
		 * @since 0.3.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room Room identifier.
		 * @return string Lock name.
		 */
		private function room_lock_name( string $room ): string {
			global $wpdb;

			return $wpdb->prefix . 'sync_ingest_' . md5( $room );
		}

		/**
		 * Stores one typed row.
		 *
		 * @since 0.3.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Attributed client id.
		 * @param string $type      Update type.
		 * @param string $data      Update payload.
		 * @return bool Whether the row was stored.
		 */
		private function add_row( string $room, int $client_id, string $type, string $data ): bool {
			return (bool) $this->storage->add_update(
				$room,
				array(
					'client_id' => $client_id,
					'data'      => $data,
					'type'      => $type,
				)
			);
		}
	}
}
