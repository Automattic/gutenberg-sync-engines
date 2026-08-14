<?php
/**
 * WP_Yjs_Server_Engine class
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Yjs_Server_Engine' ) ) {

	/**
	 * The server-authoritative Yjs sync engine.
	 *
	 * Where a naive relay engine (like the retired yjs-relay) stores opaque
	 * client blobs and lets the merge happen in each client's CRDT, this
	 * engine understands Yjs on the
	 * server (via the vendored y-php library): it maintains a canonical
	 * room document, merges every incoming update into it, performs
	 * compaction itself, and can materialize the document back to post
	 * content — the same server-side authority the intent-log engine has,
	 * built on CRDT merge semantics instead of a transform log.
	 *
	 * Storage model (the append-log-plus-canonical-document design):
	 *
	 * - The UPDATE LOG is the source of truth. Each accepted client update
	 *   is stored as an `update` row whose data is the base64 Yjs V2 diff of
	 *   what that update added beyond the server's state at ingest
	 *   (diffUpdateV2 against the pre-apply state vector), so redelivered
	 *   or overlapping payloads do not bloat rows with known structs.
	 * - The CANONICAL DOCUMENT is derived state: a compact V2 snapshot in
	 *   room meta, stamped with the log cursor it reflects. Loading applies
	 *   any rows past that cursor on top, so a canonical write that loses a
	 *   save race is repaired from the log on the next load. Ingest adds a
	 *   second repair lane: an update that references items the loaded
	 *   document lacks triggers a full log replay and a retry, and only a
	 *   client genuinely ahead of the log is voided `resync-required`. Yjs
	 *   updates are commutative and idempotent, which is what makes this
	 *   safe WITHOUT the per-room ingest lock the intent-log engine
	 *   requires: over-application converges, and no server-assigned total
	 *   order is needed.
	 * - `snapshot` rows carry the full canonical state (base64 V2): one at
	 *   genesis (built deterministically from post content — a fixed
	 *   per-room clientID and a fixed operation order make concurrent
	 *   genesis writers produce byte-identical, idempotently-mergeable
	 *   rows), and one per server-side compaction checkpoint.
	 *
	 * Compaction is server-driven: once enough rows accumulate past the
	 * previous checkpoint, the engine appends a checkpoint snapshot, trims
	 * history below the PREVIOUS checkpoint (the intent-log retention
	 * invariant: at least one full interval of history is always kept), and
	 * records the floor. Clients whose cursor falls below the floor are
	 * served from the retained checkpoint and re-bootstrap. `should_compact`
	 * is always false — no client is ever nominated.
	 *
	 * Clients may only SEND `update` rows (incremental Yjs V2 updates).
	 * There is no sync_step1/step2 peer dance: a joining client receives
	 * the snapshot row plus the update tail, and uploads its own full state
	 * as an ordinary update when it has local content the server lacks.
	 *
	 * KNOWN GAPS (relative to intent-log, tracked in
	 * docs/engine-comparison.md): no per-update kses/capability lane yet
	 * (a CRDT update is not attributable to a reviewable "intent" without
	 * further design), and no proposal/review lane — genuine conflicts
	 * resolve by CRDT rules (last-writer-wins on map registers) rather than
	 * escalating.
	 *
	 * Materialization mirrors the intent-log engine's Phase 2a
	 * simplification: a block's rich-text content maps opaquely onto the
	 * inner HTML of its single wrapper element, with the stripped wrapper
	 * kept server-side (room meta, keyed by block clientId) so post content
	 * can be rebuilt. Blocks born in-session fall back to a per-block-type
	 * default wrapper.
	 *
	 * @since 0.2.0
	 * @access private
	 */
	class WP_Yjs_Server_Engine implements WP_Sync_Engine {
		/**
		 * Engine slug.
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const SLUG = 'yjs-server';

		/**
		 * Engine protocol version.
		 *
		 * @since 0.2.0
		 * @var int
		 */
		const PROTOCOL_VERSION = 1;

		/**
		 * Update type: incremental Yjs V2 update (client → server → clients).
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const UPDATE_TYPE_UPDATE = 'update';

		/**
		 * Update type: full-state snapshot (server-emitted only): the genesis
		 * row and compaction checkpoints. Data is `{ doc: <base64 V2> }`.
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const UPDATE_TYPE_SNAPSHOT = 'snapshot';

		/**
		 * The Yjs clientID used for server-authored genesis items. A fixed
		 * value keeps the genesis build fully deterministic (the lock-free
		 * concurrent-genesis guarantee) AND byte-stable across rooms and
		 * benchmark repetitions. Above the editor's pseudo-random client id
		 * range (0..1e9), below 2^31.
		 *
		 * @since 0.2.0
		 * @var int
		 */
		const GENESIS_CLIENT_ID = 2000000000;

		/**
		 * Room meta key: canonical document snapshot + the log cursor it
		 * reflects (`{ doc: <base64 V2>, cursor: int }`).
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const META_DOC = 'yjs_server_doc';

		/**
		 * Room meta key: previous checkpoint (`{ cursor: int }`).
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const META_CHECKPOINT = 'yjs_server_checkpoint';

		/**
		 * Room meta key: trim floor (int cursor).
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const META_FLOOR = 'yjs_server_floor';

		/**
		 * Room meta key: genesis wrapper map (block clientId => open/close).
		 *
		 * @since 0.2.0
		 * @var string
		 */
		const META_WRAPPERS = 'yjs_server_wrappers';

		/**
		 * Storage backend.
		 *
		 * @since 0.2.0
		 * @var WP_Sync_Storage
		 */
		private WP_Sync_Storage $storage;

		/**
		 * Per-request cache of loaded room documents, keyed by room:
		 * `array( 'doc' => \Yjs\Utils\Doc, 'cursor' => int )`.
		 *
		 * @since 0.2.0
		 * @var array<string, array|null>
		 */
		private array $room_docs = array();

		/**
		 * Constructor. Loads the vendored y-php library on first use.
		 *
		 * @since 0.2.0
		 *
		 * @param WP_Sync_Storage $storage Storage backend.
		 */
		public function __construct( WP_Sync_Storage $storage ) {
			$this->storage = $storage;
			require_once dirname( __DIR__, 2 ) . '/lib/y-php-loader.php';
			gutenberg_sync_engines_load_y_php();
		}

		/**
		 * Returns the engine slug.
		 *
		 * @since 0.2.0
		 *
		 * @return string Engine slug.
		 */
		public function get_slug(): string {
			return self::SLUG;
		}

		/**
		 * Returns the engine protocol version.
		 *
		 * @since 0.2.0
		 *
		 * @return int Protocol version.
		 */
		public function get_protocol_version(): int {
			return self::PROTOCOL_VERSION;
		}

		/**
		 * Returns the update types this engine accepts on the route. Clients
		 * may only SEND `update`; `snapshot` is server-emitted (enforced in
		 * handle_updates).
		 *
		 * @since 0.2.0
		 *
		 * @return string[] Accepted update types.
		 */
		public function get_update_types(): array {
			return array(
				self::UPDATE_TYPE_UPDATE,
				self::UPDATE_TYPE_SNAPSHOT,
			);
		}

		/**
		 * Ingests one client's updates for a room.
		 *
		 * Each update is decoded and merged into the canonical document; the
		 * stored row is the diff of what the update contributed beyond the
		 * server's pre-apply state. A batch that changes nothing (a
		 * redelivery after an unknown outcome) settles as benign
		 * `already-merged` voids and appends no rows. Malformed payloads
		 * settle per-update as `invalid-payload` voids rather than failing
		 * the batch — one bad row must not starve valid edits.
		 *
		 * An update that PARSES but references items the loaded document
		 * lacks is not malformed: the canonical snapshot may have lost
		 * content to a save or read-visibility race. The log is the source
		 * of truth, so the document is rebuilt from the full retained log
		 * (bypassing the canonical snapshot) and the update retried, once
		 * per request. Only when even the log cannot supply the
		 * dependencies does the update settle as a `resync-required` void,
		 * telling the client an earlier send never landed and it must
		 * upload its full state (idempotent; the server stores only the
		 * diff).
		 *
		 * No ingest lock is taken: CRDT merge needs no server-assigned total
		 * order, and a concurrent canonical save that loses the race is
		 * repaired from the update log on the next load (see load_room())
		 * or by the in-request replay above.
		 *
		 * @since 0.2.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Client identifier.
		 * @param int    $cursor    Client cursor (unused: rows are self-contained).
		 * @param array  $updates   Typed updates.
		 * @param array  $context   Transport context (unused).
		 * @return array|WP_Error array( 'dispositions' => array ) or error.
		 */
		public function handle_updates( string $room, int $client_id, int $cursor, array $updates, array $context ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $cursor/$context are part of the WP_Sync_Engine contract.
			if ( array() === $updates ) {
				return array( 'dispositions' => null );
			}

			/*
			 * Ingest always reloads from storage: in production every request
			 * is a fresh process that pays the canonical-document load, and a
			 * warm in-memory doc would also mask rows written by concurrent
			 * requests since this instance last loaded. (This is also what
			 * keeps the benchmark honest about per-request cost.)
			 */
			$this->room_docs[ $room ] = null;
			$state                    = $this->load_room( $room );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$doc         = $state['doc'];
			$load_cursor = (int) $state['cursor'];

			$before_bytes = \Yjs\encodeStateAsUpdateV2( $doc )->toBinaryString();

			$dispositions = array();
			$diffs        = array();
			$replayed     = false;
			foreach ( $updates as $update ) {
				if ( self::UPDATE_TYPE_UPDATE !== $update['type'] ) {
					return new WP_Error(
						'rest_invalid_update_type',
						__( 'Clients may only send update rows to a yjs-server room.', 'gutenberg' ),
						array( 'status' => 400 )
					);
				}

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the client's binary CRDT update from the wire format.
				$binary = base64_decode( (string) $update['data'], true );
				if ( false === $binary || '' === $binary ) {
					$dispositions[] = array(
						'status' => 'voided',
						'reason' => 'invalid-payload',
					);
					continue;
				}
				$buffer = \Yjs\Lib0\Buffer::fromBinaryString( $binary );

				$diff = self::apply_update_for_row( $doc, $buffer );
				if ( null !== $diff ) {
					$diffs[ count( $dispositions ) ] = $diff;
					$dispositions[]                  = array( 'status' => 'applied' );
					continue;
				}

				/*
				 * The update did not integrate cleanly: a throw can leave
				 * the document partially mutated, and a missing-dependency
				 * apply can integrate a prefix and park the rest as pending.
				 * Restore the batch baseline exactly (batch-start state plus
				 * the diffs accepted so far) before deciding how to settle.
				 */
				$doc                      = self::rebuild_doc( $before_bytes, $diffs );
				$this->room_docs[ $room ] = null;

				if ( ! self::is_decodable( $buffer ) ) {
					// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
					do_action( 'qm/debug', "wp-sync: yjs-server rejected a malformed update in {$room}" );
					$dispositions[] = array(
						'status' => 'voided',
						'reason' => 'invalid-payload',
					);
					continue;
				}

				/*
				 * Decodable but not integrable: the update references items
				 * this document lacks. The canonical snapshot may have lost
				 * content to a save or read-visibility race, so rebuild from
				 * the full retained log (the source of truth, bypassing the
				 * canonical) and retry, once per request. The baseline and
				 * the stamp cursor move to the replay: the replayed document
				 * reflects every retained row at or below the fresh
				 * watermark, so the under-claim invariant holds.
				 */
				if ( ! $replayed ) {
					$replayed     = true;
					$replay       = $this->replay_room_log( $room );
					$doc          = $replay['doc'];
					$load_cursor  = $replay['cursor'];
					$before_bytes = \Yjs\encodeStateAsUpdateV2( $doc )->toBinaryString();
					foreach ( $diffs as $accepted ) {
						\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $accepted ) );
					}
				}

				$diff = self::apply_update_for_row( $doc, $buffer );
				if ( null !== $diff ) {
					// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
					do_action( 'qm/debug', "wp-sync: yjs-server repaired {$room} from the update log during ingest" );
					$diffs[ count( $dispositions ) ] = $diff;
					$dispositions[]                  = array( 'status' => 'applied' );
					continue;
				}

				/*
				 * Even the full log cannot supply this update's
				 * dependencies: the client is ahead of the room (an earlier
				 * send never landed). Only the client can close that gap,
				 * with a full-state recovery update.
				 */
				$doc                      = self::rebuild_doc( $before_bytes, $diffs );
				$this->room_docs[ $room ] = null;
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
				do_action( 'qm/debug', "wp-sync: yjs-server update depends on items missing from {$room}; client must resync" );
				$dispositions[] = array(
					'status' => 'voided',
					'reason' => 'resync-required',
				);
			}

			$after_bytes = \Yjs\encodeStateAsUpdateV2( $doc )->toBinaryString();
			if ( $after_bytes === $before_bytes ) {
				// Nothing new: settle would-be applies as benign idempotent
				// voids and leave the row log untouched. A replay-repaired
				// canonical is still worth persisting.
				if ( $replayed ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the canonical document's binary bytes for storage.
					$this->save_canonical( $room, $doc, $load_cursor, base64_encode( $after_bytes ) );
				}
				foreach ( $dispositions as $i => $disposition ) {
					if ( 'applied' === $disposition['status'] ) {
						$dispositions[ $i ] = array(
							'status' => 'voided',
							'reason' => 'already-merged',
						);
					}
				}
				return array( 'dispositions' => $dispositions );
			}

			if ( array() === $diffs ) {
				/*
				 * The document changed but no diff row was recorded: an update
				 * threw mid-apply and left partial state. Do NOT persist the
				 * canonical snapshot — the log is the source of truth, and the
				 * next load rebuilds a clean document from it.
				 */
				$this->room_docs[ $room ] = null;
				return array( 'dispositions' => $dispositions );
			}

			foreach ( $diffs as $diff ) {
				if ( ! $this->add_row( $room, $client_id, self::UPDATE_TYPE_UPDATE, $diff ) ) {
					return new WP_Error(
						'rest_sync_storage_error',
						__( 'Failed to store sync update.', 'gutenberg' ),
						array( 'status' => 500 )
					);
				}
			}

			// $after_bytes IS the canonical encoding at the new head; reuse
			// it rather than encoding the document a third time.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the canonical document's binary bytes for storage.
			$this->save_canonical( $room, $doc, $load_cursor, base64_encode( $after_bytes ) );
			$this->maybe_checkpoint( $room, $client_id, $doc );

			return array( 'dispositions' => $dispositions );
		}

		/**
		 * Returns rows after the cursor for a catching-up client.
		 *
		 * A client's own `update` rows are filtered out (it already holds its
		 * own changes); `snapshot` rows are always delivered. A cursor below
		 * the compaction floor is clamped to the retained checkpoint row so
		 * the client re-bootstraps from it.
		 *
		 * @since 0.2.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Client identifier.
		 * @param int    $cursor    Return rows after this cursor.
		 * @param array  $context   Transport context (unused).
		 * @return array Room response data.
		 */
		public function get_updates_since( string $room, int $client_id, int $cursor, array $context ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $context is part of the WP_Sync_Engine contract.
			if ( $cursor > 0 && method_exists( $this->storage, 'get_room_meta' ) ) {
				$floor = $this->storage->get_room_meta( $room, self::META_FLOOR );
				if ( is_numeric( $floor ) && $cursor < (int) $floor ) {
					$cursor = (int) $floor - 1;
				}
			}

			$rows = $this->storage->get_updates_after_cursor( $room, $cursor );

			/*
			 * Ensure genesis exists so first pollers receive the snapshot.
			 * The check runs AFTER the read because the storage's update
			 * count is a per-request cache that only that read refreshes: a
			 * cold cache reads 0 for rooms full of rows (making a
			 * before-the-read check trigger a needless O(doc) load on every
			 * request's first poll), and a warm one reads stale non-zero for
			 * a room the transport just reset after an engine switch. Fresh
			 * count of zero = the room truly has no rows: initialize it and
			 * re-read so this response carries the genesis snapshot.
			 */
			if ( 0 === $this->storage->get_update_count( $room ) ) {
				$this->room_docs[ $room ] = null;
				$this->load_room( $room );
				$rows = $this->storage->get_updates_after_cursor( $room, $cursor );
			}
			$typed_updates = array();
			foreach ( $rows as $row ) {
				if ( self::UPDATE_TYPE_UPDATE === $row['type'] && $client_id === $row['client_id'] ) {
					continue;
				}
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
		 * Serializes the room's canonical document back to post content.
		 *
		 * @since 0.2.0
		 *
		 * @param string $room Room identifier.
		 * @return string|null Serialized block content, or null on failure.
		 */
		public function materialize( string $room ): ?string {
			$state = $this->load_room( $room );
			if ( is_wp_error( $state ) ) {
				return null;
			}

			$record = $state['doc']->getMap( 'document' );
			$blocks = $record->get( 'blocks' );
			if ( ! ( $blocks instanceof \Yjs\Types\YArray ) ) {
				return '';
			}

			$wrappers = array();
			if ( method_exists( $this->storage, 'get_room_meta' ) ) {
				$stored   = $this->storage->get_room_meta( $room, self::META_WRAPPERS );
				$wrappers = is_array( $stored ) ? $stored : array();
			}

			$serialized = array();
			foreach ( $blocks->toJSON() as $block ) {
				$block = self::normalize_json( $block );
				if ( ! is_array( $block ) ) {
					continue;
				}
				$serialized[] = serialize_block( self::to_serializable_block( $block, $wrappers ) );
			}

			return implode( "\n\n", $serialized );
		}

		/**
		 * Loads (and lazily initializes) the canonical document for a room.
		 *
		 * The canonical snapshot in room meta reflects the log up to its
		 * stamped cursor; rows past that cursor are applied on top, which is
		 * both the catch-up path and the repair path for canonical writes
		 * that lost a save race. Without room-meta support the document
		 * rebuilds from the full log every time.
		 *
		 * @since 0.2.0
		 *
		 * @param string $room Room identifier.
		 * @return array|WP_Error array( 'doc' => \Yjs\Utils\Doc, 'cursor' => int ).
		 */
		private function load_room( string $room ) {
			if ( isset( $this->room_docs[ $room ] ) && null !== $this->room_docs[ $room ] ) {
				return $this->room_docs[ $room ];
			}

			$has_meta = method_exists( $this->storage, 'get_room_meta' );
			$meta     = $has_meta ? $this->storage->get_room_meta( $room, self::META_DOC ) : null;

			$doc         = new \Yjs\Utils\Doc();
			$meta_cursor = 0;
			if ( is_array( $meta ) && is_string( $meta['doc'] ?? null ) ) {
				try {
					\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $meta['doc'] ) );
					$meta_cursor = (int) ( $meta['cursor'] ?? 0 );
				} catch ( \Throwable $e ) {
					// A corrupt canonical snapshot falls back to a full log
					// replay below.
					$doc         = new \Yjs\Utils\Doc();
					$meta_cursor = 0;
					// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
					do_action( 'qm/debug', "wp-sync: yjs-server canonical snapshot corrupt for {$room}; replaying log" );
				}
			}

			$rows = $this->storage->get_updates_after_cursor( $room, $meta_cursor );

			if ( 0 === $meta_cursor && array() === $rows ) {
				$genesis = $this->initialize_room( $room, $doc );
				if ( is_wp_error( $genesis ) ) {
					return $genesis;
				}
				$state                    = array(
					'doc'    => $doc,
					'cursor' => $this->storage->get_cursor( $room ),
				);
				$this->room_docs[ $room ] = $state;
				return $state;
			}

			$clean = self::apply_rows_to_doc( $doc, $rows, $room );

			// A skipped row is above $meta_cursor but below the watermark:
			// the watermark would over-claim it, so fall back to the cursor
			// the document provably reflects.
			$state                    = array(
				'doc'    => $doc,
				'cursor' => $clean ? $this->storage->get_cursor( $room ) : $meta_cursor,
			);
			$this->room_docs[ $room ] = $state;

			return $state;
		}

		/**
		 * Applies stored rows onto a document in log order (snapshot rows
		 * carry `{ doc: <base64 V2> }`; update rows carry the base64 V2
		 * update directly). A row that fails to apply (malformed, or its
		 * dependency row was momentarily invisible to this read) is skipped
		 * rather than wedging the room.
		 *
		 * Returns whether every row applied cleanly. On a skip, callers
		 * MUST NOT stamp a canonical with a cursor covering the skipped
		 * row: rows do not carry their ids, so the safe stamp falls back to
		 * the pre-read cursor (under-claiming is always safe; the skipped
		 * row re-applies on a later load once its dependency is visible).
		 *
		 * @since 0.2.0
		 *
		 * @param \Yjs\Utils\Doc $doc  Document to apply onto.
		 * @param array          $rows Stored rows.
		 * @param string         $room Room identifier (diagnostics only).
		 * @return bool Whether every row applied cleanly.
		 */
		private static function apply_rows_to_doc( \Yjs\Utils\Doc $doc, array $rows, string $room ): bool {
			$clean = true;
			foreach ( $rows as $row ) {
				try {
					if ( self::UPDATE_TYPE_SNAPSHOT === $row['type'] ) {
						$decoded = json_decode( $row['data'], true );
						if ( is_array( $decoded ) && is_string( $decoded['doc'] ?? null ) ) {
							\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $decoded['doc'] ) );
						}
					} elseif ( self::UPDATE_TYPE_UPDATE === $row['type'] ) {
						\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( (string) $row['data'] ) );
					}
				} catch ( \Throwable $e ) {
					$clean = false;
					// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
					do_action( 'qm/debug', "wp-sync: yjs-server skipped a stored row that did not apply in {$room}" );
				}
			}
			return $clean;
		}

		/**
		 * Rebuilds the room document from the update log alone, bypassing
		 * the canonical snapshot. This is the ingest-side repair lane for a
		 * canonical that lost content to a save or read-visibility race:
		 * the log retains at least one full compaction interval plus its
		 * checkpoint snapshot, so everything a client update can reference
		 * is here unless the client is genuinely ahead of the room.
		 *
		 * @since 0.2.0
		 *
		 * @param string $room Room identifier.
		 * @return array array( 'doc' => \Yjs\Utils\Doc, 'cursor' => int ).
		 */
		private function replay_room_log( string $room ): array {
			$doc   = new \Yjs\Utils\Doc();
			$rows  = $this->storage->get_updates_after_cursor( $room, 0 );
			$clean = self::apply_rows_to_doc( $doc, $rows, $room );
			return array(
				'doc'    => $doc,
				// A skipped row would be over-claimed by the watermark;
				// cursor 0 forces the next load to replay everything, which
				// retries the skip once its dependency is visible.
				'cursor' => $clean ? $this->storage->get_cursor( $room ) : 0,
			);
		}

		/**
		 * Applies one incoming update and returns the diff row to store:
		 * only what the update added beyond the server's prior state (known
		 * structs stripped; the update's own delete set kept). Returns null
		 * when the update did not integrate cleanly, either because the
		 * apply threw or because y-php parked structs or deletes as pending
		 * (the update references items the document lacks). A null return
		 * leaves the document in a suspect state (partially integrated or
		 * carrying pending state); callers must rebuild it.
		 *
		 * @since 0.2.0
		 *
		 * @param \Yjs\Utils\Doc   $doc    Document to apply onto.
		 * @param \Yjs\Lib0\Buffer $buffer Incoming V2 update.
		 * @return string|null Base64 diff row, or null.
		 */
		private static function apply_update_for_row( \Yjs\Utils\Doc $doc, \Yjs\Lib0\Buffer $buffer ): ?string {
			try {
				$sv_before = \Yjs\encodeStateVector( $doc );
				\Yjs\applyUpdateV2( $doc, $buffer );
				$store = $doc->store;
				if ( null !== $store->pendingStructs || null !== $store->pendingDs ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- y-php mirrors the JS Yjs API.
					return null;
				}
				return \Yjs\diffUpdateV2( $buffer, $sv_before )->toBase64();
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		/**
		 * Rebuilds a document exactly from encoded state plus accepted diff
		 * rows, shedding any partial mutation or pending state a failed
		 * apply left behind. Rare path, so the O(doc) rebuild is fine.
		 *
		 * @since 0.2.0
		 *
		 * @param string $base_bytes Binary V2 encoding of the baseline.
		 * @param array  $diffs      Accepted base64 diff rows, in order.
		 * @return \Yjs\Utils\Doc Rebuilt document.
		 */
		private static function rebuild_doc( string $base_bytes, array $diffs ): \Yjs\Utils\Doc {
			$doc = new \Yjs\Utils\Doc();
			\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBinaryString( $base_bytes ) );
			foreach ( $diffs as $diff ) {
				\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $diff ) );
			}
			return $doc;
		}

		/**
		 * Whether the payload parses as a structurally valid V2 update.
		 * Distinguishes garbage bytes (settled as `invalid-payload`) from a
		 * valid update whose dependencies are missing (the resync lane)
		 * without touching any document, so malformed input never triggers
		 * an O(log) replay.
		 *
		 * @since 0.2.0
		 *
		 * @param \Yjs\Lib0\Buffer $buffer Incoming payload.
		 * @return bool Whether the payload decodes as a V2 update.
		 */
		private static function is_decodable( \Yjs\Lib0\Buffer $buffer ): bool {
			try {
				\Yjs\decodeUpdateV2( $buffer );
				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Persists the canonical document snapshot with the cursor it
		 * reflects. Skipped silently when the storage has no room meta, in
		 * which case the log alone remains authoritative.
		 *
		 * The stamped cursor MUST under-claim: every row at or below it is
		 * merged into $doc. Callers pass the LOAD-time watermark, never this
		 * request's own insert id. Concurrent ingests interleave row ids, so
		 * an insert-id stamp claims foreign rows this process never loaded,
		 * and the load-path repair (apply rows past the stamp) would then
		 * skip them forever: the losing writer's merged content vanishes
		 * from the canonical document while its row sits uselessly in the
		 * log, and that client's next update references items the canonical
		 * no longer has. Under-claiming instead re-applies this request's
		 * own rows on the next load, which is safe because Yjs updates are
		 * idempotent.
		 *
		 * @since 0.2.0
		 *
		 * @param string         $room       Room identifier.
		 * @param \Yjs\Utils\Doc $doc        Canonical document.
		 * @param int            $cursor     Load-time cursor $doc reflects.
		 *                                   Rows above it (including this
		 *                                   request's own) re-apply on the
		 *                                   next load.
		 * @param string|null    $doc_base64 Pre-encoded state (base64 V2), to
		 *                                   avoid re-encoding when the caller
		 *                                   already has it.
		 * @return void
		 */
		private function save_canonical( string $room, \Yjs\Utils\Doc $doc, int $cursor, ?string $doc_base64 = null ): void {
			if ( ! method_exists( $this->storage, 'set_room_meta' ) ) {
				return;
			}
			$this->storage->set_room_meta(
				$room,
				self::META_DOC,
				array(
					'doc'    => $doc_base64 ?? \Yjs\encodeStateAsUpdateV2( $doc )->toBase64(),
					'cursor' => $cursor,
				)
			);
			$this->room_docs[ $room ] = array(
				'doc'    => $doc,
				'cursor' => $cursor,
			);
		}

		/**
		 * Appends a compaction checkpoint and trims history behind the
		 * PREVIOUS checkpoint once enough rows accumulate — the intent-log
		 * retention invariant: rows from the previous checkpoint onward are
		 * always kept, so any client within one interval of the head resumes
		 * normally, and older clients re-bootstrap from the retained
		 * checkpoint row.
		 *
		 * Server-driven: no client is nominated, and `should_compact` never
		 * fires. Requires room-meta support; skips silently otherwise.
		 *
		 * @since 0.2.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string         $room      Room identifier.
		 * @param int            $client_id Requesting client id (row attribution).
		 * @param \Yjs\Utils\Doc $doc       Canonical document at the head.
		 * @return bool Whether a checkpoint was appended.
		 */
		private function maybe_checkpoint( string $room, int $client_id, \Yjs\Utils\Doc $doc ): bool {
			if ( ! method_exists( $this->storage, 'get_room_meta' ) || ! method_exists( $this->storage, 'set_room_meta' ) ) {
				return false;
			}

			/**
			 * Filters the yjs-server checkpoint interval: a compaction
			 * checkpoint is appended once this many rows accumulate past the
			 * previous one.
			 *
			 * @since 0.2.0
			 *
			 * @param int    $interval Interval in stored rows.
			 * @param string $room     Room identifier.
			 */
			$interval = (int) apply_filters( 'wp_sync_yjs_server_checkpoint_interval', 100, $room );
			if ( $interval < 1 || $this->storage->get_update_count( $room ) < $interval ) {
				return false;
			}

			$previous    = $this->storage->get_room_meta( $room, self::META_CHECKPOINT );
			$prev_cursor = is_array( $previous ) && isset( $previous['cursor'] ) ? (int) $previous['cursor'] : 0;
			$window_rows = $this->storage->get_updates_after_cursor( $room, $prev_cursor );
			if ( count( $window_rows ) < $interval ) {
				return false;
			}

			/*
			 * Fold the whole retained window into the document before
			 * snapshotting it. The document reflects what THIS request
			 * loaded; a row another writer interleaved (or one an earlier
			 * load had to skip) may be missing from it, and the trim below
			 * would otherwise make that loss durable. Re-application is
			 * idempotent, so folding is safe; a window row that still
			 * cannot apply defers the checkpoint to a later commit.
			 */
			if ( ! self::apply_rows_to_doc( $doc, $window_rows, $room ) ) {
				return false;
			}

			$stored = $this->add_row(
				$room,
				$client_id,
				self::UPDATE_TYPE_SNAPSHOT,
				wp_json_encode(
					array(
						'doc'        => \Yjs\encodeStateAsUpdateV2( $doc )->toBase64(),
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
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
			do_action( 'qm/debug', "wp-sync: yjs-server checkpoint for {$room}" );
			$this->storage->set_room_meta( $room, self::META_CHECKPOINT, array( 'cursor' => $cursor ) );

			if ( $prev_cursor > 0 ) {
				$this->storage->remove_updates_before_cursor( $room, $prev_cursor );
				$this->storage->set_room_meta( $room, self::META_FLOOR, $prev_cursor );
				// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's debug hook.
				do_action( 'qm/debug', "wp-sync: yjs-server trimmed history below cursor {$prev_cursor} for {$room}" );
			}

			return true;
		}

		/**
		 * Builds and stores the room's genesis snapshot from post content.
		 *
		 * The build is DETERMINISTIC: a fixed per-room clientID and a fixed
		 * operation order mean two racing initializers produce byte-identical
		 * CRDT items, so duplicate genesis rows merge idempotently instead of
		 * duplicating content — which is what makes lock-free genesis safe.
		 *
		 * @since 0.2.0
		 *
		 * @param string         $room Room identifier.
		 * @param \Yjs\Utils\Doc $doc  Empty document to populate in place.
		 * @return true|WP_Error True on success.
		 */
		private function initialize_room( string $room, \Yjs\Utils\Doc $doc ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- clientID is the y-php Doc property, mirroring JS Yjs naming.
			$doc->clientID = self::GENESIS_CLIENT_ID;

			$post     = null;
			$parsed   = WP_Sync_Config::parse_room( $room );
			$wrappers = array();
			if ( null !== $parsed && 'postType' === $parsed['entity_kind'] && ! empty( $parsed['object_id'] ) ) {
				$post = get_post( (int) $parsed['object_id'] );
			}

			$record = $doc->getMap( 'document' );
			$state  = $doc->getMap( 'state' );
			$state->set( 'version', 1 );

			if ( $post instanceof WP_Post ) {
				$title = $post->post_title;

				/*
				 * A fresh auto-draft is stored with the placeholder "Auto Draft"
				 * title while the editor shows an empty title (core blanks it
				 * with an initial edit in edit-form-blocks.php). Seeding the
				 * placeholder into the canonical document would push the "Auto Draft"
				 * title to everyone as a title change.
				 */
				if ( 'auto-draft' === $post->post_status && ( 'Auto Draft' === $title || __( 'Auto Draft', 'default' ) === $title ) ) {
					$title = '';
				}

				$record->set( 'title', new \Yjs\Types\YText( $title ) );
				$yblocks = new \Yjs\Types\YArray();
				$record->set( 'blocks', $yblocks );
				if ( '' !== $post->post_content ) {
					$specs = self::blocks_to_yblocks( parse_blocks( $post->post_content ), 'srv', $wrappers );
					if ( array() !== $specs ) {
						$yblocks->push( $specs );
					}
				}
			}

			$stored = $this->add_row(
				$room,
				0,
				self::UPDATE_TYPE_SNAPSHOT,
				wp_json_encode( array( 'doc' => \Yjs\encodeStateAsUpdateV2( $doc )->toBase64() ) )
			);
			if ( ! $stored ) {
				return new WP_Error(
					'rest_sync_storage_error',
					__( 'Failed to store the room genesis snapshot.', 'gutenberg' ),
					array( 'status' => 500 )
				);
			}

			// The genesis row is the room's first stored row: stamp lineage
			// (see the intent-log engine's identical rationale).
			$this->storage->set_room_engine( $room, $this->get_slug() );

			if ( method_exists( $this->storage, 'set_room_meta' ) ) {
				if ( array() !== $wrappers ) {
					$this->storage->set_room_meta( $room, self::META_WRAPPERS, $wrappers );
				}
				global $wpdb;
				$cursor = isset( $wpdb ) ? (int) $wpdb->insert_id : 0;

				/*
				 * The canonical stamp must under-claim (see save_canonical):
				 * with two racing initializers, a client of the faster one
				 * can append an update row with a LOWER id than this genesis
				 * row, and stamping the genesis row id would hide that row
				 * from the load-path repair forever. Cursor 0 re-applies the
				 * genesis row itself on the next load, a no-op against the
				 * identical canonical. The checkpoint stamp keeps the row id;
				 * it only paces compaction windows.
				 */
				$this->storage->set_room_meta(
					$room,
					self::META_DOC,
					array(
						'doc'    => \Yjs\encodeStateAsUpdateV2( $doc )->toBase64(),
						'cursor' => 0,
					)
				);
				$this->storage->set_room_meta( $room, self::META_CHECKPOINT, array( 'cursor' => $cursor ) );
			}

			return true;
		}

		/**
		 * Maps parsed blocks onto YBlock shared types (the schema the client
		 * sync layer uses: a Y.Map per block with `name`, `clientId`,
		 * `attributes` (rich-text attrs as Y.Text), `innerBlocks`).
		 *
		 * Mirrors the intent-log genesis simplification: a block's inner HTML
		 * maps onto its rich-text content attribute after stripping the
		 * single wrapper element, which is recorded in $wrappers (keyed by
		 * the minted clientId) for materialization.
		 *
		 * @since 0.2.0
		 *
		 * @param array  $blocks   Output of parse_blocks().
		 * @param string $id_base  Deterministic clientId prefix.
		 * @param array  $wrappers Collects clientId => wrapper (by reference).
		 * @return \Yjs\Types\YMap[] YBlock shared types.
		 */
		private static function blocks_to_yblocks( array $blocks, string $id_base, array &$wrappers ): array {
			$yblocks = array();
			$index   = 0;
			foreach ( $blocks as $block ) {
				$client_id = $id_base . '-' . $index;

				if ( empty( $block['blockName'] ) ) {
					// Classic content: preserved as core/freeform, full inner
					// HTML on the content attribute, no wrapper stripping
					// (mirrors the intent-log genesis).
					$text = trim( $block['innerHTML'] );
					if ( '' === $text ) {
						continue;
					}
					$yblocks[] = self::make_yblock( $client_id, 'core/freeform', array(), $text, array() );
					++$index;
					continue;
				}

				$attrs = is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$text  = trim( $block['innerHTML'] );
				if ( preg_match( '/^<([a-zA-Z][a-zA-Z0-9-]*)(\s[^>]*)?>(.*)<\/\1>$/s', $text, $matches ) ) {
					$wrappers[ $client_id ] = array(
						'open'  => '<' . $matches[1] . ( $matches[2] ?? '' ) . '>',
						'close' => '</' . $matches[1] . '>',
					);
					$text                   = $matches[3];
				}

				$children  = self::blocks_to_yblocks( $block['innerBlocks'], $client_id, $wrappers );
				$yblocks[] = self::make_yblock( $client_id, $block['blockName'], $attrs, $text, $children );
				++$index;
			}

			return $yblocks;
		}

		/**
		 * Builds one YBlock Y.Map.
		 *
		 * @since 0.2.0
		 *
		 * @param string $client_id Block clientId.
		 * @param string $name      Block name.
		 * @param array  $attrs     Comment-delimiter attributes.
		 * @param string $content   Rich-text content (inner HTML, wrapper stripped).
		 * @param array  $children  Child YBlocks.
		 * @return \Yjs\Types\YMap YBlock.
		 */
		private static function make_yblock( string $client_id, string $name, array $attrs, string $content, array $children ): \Yjs\Types\YMap {
			$attributes = new \Yjs\Types\YMap();
			foreach ( $attrs as $key => $value ) {
				$attributes->set( (string) $key, $value );
			}
			$content_attr = self::rich_text_attribute( $name );
			if ( null !== $content_attr ) {
				$attributes->set( $content_attr, new \Yjs\Types\YText( $content ) );
			}

			$inner = new \Yjs\Types\YArray();

			$yblock = new \Yjs\Types\YMap();
			$yblock->set( 'name', $name );
			$yblock->set( 'clientId', $client_id );
			// Editor-authored blocks carry isValid from the parser; without
			// it, the dispatched block renders as "invalid content" in
			// recovery mode and cannot be edited.
			$yblock->set( 'isValid', true );
			$yblock->set( 'attributes', $attributes );
			$yblock->set( 'innerBlocks', $inner );
			if ( array() !== $children ) {
				$inner->push( $children );
			}

			return $yblock;
		}

		/**
		 * The markup-sourced rich-text attribute for a block type, per the
		 * server-side block registry (`content` for paragraphs, headings and
		 * most text blocks), or `content` as the fallback for unregistered
		 * blocks and core/freeform.
		 *
		 * @since 0.2.0
		 *
		 * @param string $name Block name.
		 * @return string|null Attribute key, or null when the block has none.
		 */
		private static function rich_text_attribute( string $name ): ?string {
			$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
			if ( null === $block_type || ! is_array( $block_type->attributes ) ) {
				return 'content';
			}
			foreach ( $block_type->attributes as $key => $schema ) {
				$source = is_array( $schema ) ? ( $schema['source'] ?? null ) : null;
				$type   = is_array( $schema ) ? ( $schema['type'] ?? null ) : null;
				if ( 'rich-text' === $source || 'html' === $source || 'rich-text' === $type ) {
					return (string) $key;
				}
			}
			return null;
		}

		/**
		 * Maps a YBlock (as JSON) back to a serialize_block()-compatible
		 * array, rebuilding inner HTML from the rich-text content attribute
		 * and the recorded (or default) wrapper.
		 *
		 * @since 0.2.0
		 *
		 * @param array $block    YBlock JSON (name, clientId, attributes, innerBlocks).
		 * @param array $wrappers Genesis wrapper map (clientId => open/close).
		 * @return array WP_Block_Parser_Block-shaped array.
		 */
		private static function to_serializable_block( array $block, array $wrappers ): array {
			$name      = is_string( $block['name'] ?? null ) ? $block['name'] : 'core/freeform';
			$client_id = is_string( $block['clientId'] ?? null ) ? $block['clientId'] : '';
			$attrs     = is_array( $block['attributes'] ?? null ) ? $block['attributes'] : array();

			$content_attr = self::rich_text_attribute( $name );
			$text         = '';
			if ( null !== $content_attr && array_key_exists( $content_attr, $attrs ) ) {
				$text = (string) $attrs[ $content_attr ];
				unset( $attrs[ $content_attr ] );
			}

			// Classic content serializes bare (no comment delimiters).
			if ( 'core/freeform' === $name ) {
				return array(
					'blockName'    => null,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $text,
					'innerContent' => '' === $text ? array() : array( $text ),
				);
			}

			$wrapper = $wrappers[ $client_id ] ?? self::default_wrapper( $name, $attrs );
			if ( is_array( $wrapper ) ) {
				// The surrounding newlines match core's block serializer
				// convention, so genesis content round-trips byte-identically.
				$text = "\n" . ( $wrapper['open'] ?? '' ) . $text . ( $wrapper['close'] ?? '' ) . "\n";
			}

			$inner_blocks = array();
			foreach ( ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ) as $child ) {
				$child = self::normalize_json( $child );
				if ( is_array( $child ) ) {
					$inner_blocks[] = self::to_serializable_block( $child, $wrappers );
				}
			}

			$inner_content = array();
			if ( '' !== $text ) {
				$inner_content[] = $text;
			}
			foreach ( $inner_blocks as $unused ) {
				$inner_content[] = null;
			}

			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => $text,
				'innerContent' => $inner_content,
			);
		}

		/**
		 * The default wrapper for a block type whose genesis wrapper is
		 * unknown (a block born in-session). Covers the common text blocks;
		 * anything else serializes bare.
		 *
		 * @since 0.2.0
		 *
		 * @param string $name  Block name.
		 * @param array  $attrs Block attributes (for heading levels).
		 * @return array|null Wrapper open/close, or null.
		 */
		private static function default_wrapper( string $name, array $attrs ): ?array {
			switch ( $name ) {
				case 'core/paragraph':
					return array(
						'open'  => '<p>',
						'close' => '</p>',
					);
				case 'core/heading':
					$level = is_numeric( $attrs['level'] ?? null ) ? (int) $attrs['level'] : 2;
					$level = min( 6, max( 1, $level ) );
					return array(
						'open'  => '<h' . $level . '>',
						'close' => '</h' . $level . '>',
					);
				case 'core/list-item':
					return array(
						'open'  => '<li>',
						'close' => '</li>',
					);
				case 'core/quote':
					return array(
						'open'  => '<blockquote class="wp-block-quote">',
						'close' => '</blockquote>',
					);
				default:
					return null;
			}
		}

		/**
		 * Normalizes y-php toJSON() output: YMap serializes to stdClass so
		 * empty maps round-trip as `{}`; PHP-side consumers want arrays.
		 *
		 * @since 0.2.0
		 *
		 * @param mixed $value JSON value.
		 * @return mixed Value with stdClass converted to arrays, recursively.
		 */
		private static function normalize_json( $value ) {
			if ( $value instanceof \stdClass ) {
				$value = (array) $value;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$value[ $key ] = self::normalize_json( $item );
				}
			}
			return $value;
		}

		/**
		 * Stores one typed row for a room.
		 *
		 * @since 0.2.0
		 *
		 * @param string $room      Room identifier.
		 * @param int    $client_id Originating client id (0 = server).
		 * @param string $type      Row type.
		 * @param string $data      Row data (base64 update, or snapshot JSON).
		 * @return bool Whether the row was stored.
		 */
		private function add_row( string $room, int $client_id, string $type, string $data ): bool {
			return $this->storage->add_update(
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
