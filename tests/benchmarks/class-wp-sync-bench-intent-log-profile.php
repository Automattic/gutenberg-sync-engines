<?php
/**
 * Intent-log authoring profile: typed intents + the disposition oracle.
 *
 * Speaks to the engine in typed intents (insert_text into a paragraph's
 * content field; set_attr on its align register; set_property on the
 * document's entity-property registers), authored from the state each
 * simulated client OBSERVED at its own last read. Observation is
 * READ-DRIVEN: observe() decodes the rows the engine actually delivered
 * and advances the client's observed head and register versions from the
 * wire, exactly as a production client derives its baseSeq from received
 * rows, so a laggy client genuinely authors from a stale base and a
 * same-register collision escalates the later writer. The profile also
 * keeps a disposition-driven model of the server head, and in the
 * single-process runner asserts model and wire agree at every read: a
 * dropped or mangled delivered row surfaces as a loud convergence failure
 * instead of silent drift.
 *
 * Retry is part of the protocol at compaction boundaries: a checkpoint
 * raises the retention floor mid-round, and intents authored below it void
 * `stale-base`; the production client then re-derives the outstanding work
 * from its editor tree and re-submits it. The profile models that as one
 * re-authoring follow-up ingest after the client's next read, skipping
 * edits whose target block no longer exists in the observed state (a real
 * client cannot retype into a block that left its canvas).
 *
 * Quality is scored with the disposition oracle: the materialized document
 * must match the engine's own account of the session (applied tokens
 * present exactly once, scoped to the target block's final state for
 * edits into inserted blocks; escalated tokens absent; structure intact;
 * each register equal to the last applied write in server order — ingest
 * is serialized, so processing order IS server order).
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_Intent_Log_Profile' ) ) {

	/**
	 * Simulated intent-log clients.
	 */
	class WP_Sync_Bench_Intent_Log_Profile implements WP_Sync_Bench_Authoring_Profile {
		/** Void reasons that are NOT lost work for this engine: idempotent
		 * convergence, a compacted-away base, or a malformed row (never real
		 * content). `stale-base` is benign because the profile models the
		 * client's recovery: the voided edit is re-authored once against the
		 * fresh base (see followup_request()), and an edit deliberately NOT
		 * re-authored (its target block left the document) dissolves with
		 * its target, which the scoping oracle expects. The edit-vs-remove
		 * contention class needs no additions here: a text edit that
		 * transforms over a concurrent remove of its target ESCALATES
		 * (`target-deleted`: preserved for review, counted as escalated,
		 * never voided), and `already-deleted` / `already-removed` (a
		 * delete/remove that converged idempotently) were already classified
		 * benign.
		 *
		 * @var string[]
		 */
		const BENIGN_VOID_REASONS = array(
			'already-merged',
			'already-deleted',
			'already-removed',
			'stale-base',
			'invalid-payload',
		);

		/** Void reasons stamped at APPLY time, after the planner accepted the
		 * intent: the engine appends the intent row to the log (the head
		 * advances) and then voids it with a marker row, so the disposition
		 * model must count the row like an applied one. Every other void
		 * (transform voids, stale-base, invalid-payload) appends nothing.
		 * `already-removed` exists in both variants and is classified as a
		 * non-logged transform void: the workloads remove each block at most
		 * once, so the rebase variant (a prior remove in the transform
		 * window) never fires, and the apply-time variant (removing a block
		 * the room never had) is fenced off by the floor-reset retry lane:
		 * a stale-voided insert is re-authored before its block is
		 * contended, and a remove whose target never landed is not
		 * re-authored at all (see followup_request()).
		 *
		 * @var string[]
		 */
		const LOGGED_VOID_REASONS = array(
			'missing-target',
			'missing-parent',
			'duplicate-id',
			'cycle',
			'self-merge',
			'empty-after-clamp',
		);

		/**
		 * Seeded post (room target).
		 *
		 * @var int
		 */
		private $post_id;

		/**
		 * Workload from the generator.
		 *
		 * @var array
		 */
		private $workload;

		/**
		 * Simulated client count.
		 *
		 * @var int
		 */
		private $client_count;

		/**
		 * Genesis syncIds per paragraph index.
		 *
		 * @var string[]
		 */
		private $paragraph_ids = array();

		/**
		 * Disposition model of the server head: intent rows appended to the
		 * log (applied intents plus apply-time voids). Used only to cross-
		 * check the wire-decoded head; clients author from what they READ.
		 *
		 * @var int
		 */
		private $head = 0;

		/**
		 * Per-paragraph align register version (advances on each applied
		 * attr write).
		 *
		 * @var int[]
		 */
		private $attr_version = array();

		/**
		 * Per-name entity-property register version (advances on each
		 * applied set_property write) — the attr_version analog for the
		 * document-level registers.
		 *
		 * @var array<string, int>
		 */
		private $prop_version = array();

		/**
		 * Head each client observed at its own last read.
		 *
		 * @var int[]
		 */
		private $observed_head = array();

		/**
		 * Attr versions each client observed at its own last read.
		 *
		 * @var array<int, int[]>
		 */
		private $observed_versions = array();

		/**
		 * Property register versions each client observed at its own last
		 * read ( client => name => version ).
		 *
		 * @var array<int, array<string, int>>
		 */
		private $observed_prop_versions = array();

		/**
		 * Property register VALUES each client decoded from delivered rows
		 * ( client => name => value ) — what a production client would
		 * display for each synced field.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private $observed_props = array();

		/**
		 * Genesis syncId => paragraph index (the inverse of $paragraph_ids),
		 * for decoding delivered rows.
		 *
		 * @var array<string, int>
		 */
		private $sync_id_to_paragraph = array();

		/**
		 * Model-vs-wire consistency failures recorded during reads, merged
		 * into score()'s result so drift fails the run loudly.
		 *
		 * @var array<int, array{check: string, detail: string}>
		 */
		private $consistency_failures = array();

		/**
		 * Whether the disposition model sees EVERY client's dispositions and
		 * can therefore be asserted against the wire. True in the single-
		 * process runner; false in the multi-process concurrency probe, where
		 * each process only observes its own ingests and the wire is the only
		 * truth.
		 *
		 * @var bool
		 */
		private $assert_model = true;

		/**
		 * Monotonic intentId counter.
		 *
		 * @var int
		 */
		private $intent_seq = 0;

		/**
		 * Per-client edits voided at the retention floor (`stale-base`),
		 * awaiting ONE re-authoring pass. A compaction checkpoint raises the
		 * floor mid-round, and every intent authored below it voids
		 * stale-base; the production client then re-derives the outstanding
		 * work from its editor tree and re-submits it against the fresh
		 * base. The profile models that as a follow-up ingest right after
		 * the client's next read (see followup_request()); without it,
		 * edits scripted against a stale-voided insert would target a block
		 * the room never had, producing phantom `missing-target` lost work.
		 *
		 * @var array<int, array<int, array>>
		 */
		private $retry_queue = array();

		/**
		 * In-flight retries: intentId => the workload edit it re-authors.
		 *
		 * @var array<string, array>
		 */
		private $pending_retries = array();

		/**
		 * Oracle input: what the engine SAID it did with each text edit.
		 *
		 * @var array<int, array{text: string, status: string}>
		 */
		private $expected_texts = array();

		/**
		 * Oracle input: paragraph index => last APPLIED align write.
		 *
		 * @var array<int, string>
		 */
		private $expected_align = array();

		/**
		 * Oracle input: property name => last APPLIED set_property write in
		 * server order (ingest is serialized, so processing order IS server
		 * order — the expected_align rule on document-level registers).
		 *
		 * @var array<string, mixed>
		 */
		private $expected_props = array();

		/**
		 * Oracle input: inserted-block marker => 'alive' | 'absent', from
		 * insert/remove dispositions.
		 *
		 * @var array<string, string>
		 */
		private $expected_markers = array();

		/**
		 * Constructor (the factory contract).
		 *
		 * @param int   $post_id  Seeded post (room target).
		 * @param array $workload Workload from the generator.
		 */
		public function __construct( int $post_id, array $workload ) {
			$this->post_id      = $post_id;
			$this->workload     = $workload;
			$this->client_count = max( 1, (int) $workload['clients'] );
			// The concurrency worker marks its workload multi_process: its
			// disposition model only sees the local process's ingests, so
			// the model-vs-wire assert would false-alarm there.
			$this->assert_model = empty( $workload['multi_process'] );
		}

		/**
		 * Profile name.
		 *
		 * @return string Profile name.
		 */
		public function name(): string {
			return 'intent-log';
		}

		/**
		 * Binds paragraph indexes to genesis syncIds and zeroes the observed
		 * state. No join reads: clients start at the genesis head.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array<int, int> Initial read cursor per client.
		 */
		public function bootstrap( WP_Sync_Engine $engine, string $room ): array {
			for ( $i = 0; $i < (int) $this->workload['paragraphs']; $i++ ) {
				$sync_id                                = WP_Intent_Log_Planner::genesis_sync_id( $this->post_id, 0, array( $i ) );
				$this->paragraph_ids[]                  = $sync_id;
				$this->sync_id_to_paragraph[ $sync_id ] = $i;
			}
			$this->attr_version           = array_fill( 0, max( 1, (int) $this->workload['paragraphs'] ), 0 );
			$this->observed_head          = array_fill( 0, $this->client_count, 0 );
			$this->observed_versions      = array_fill( 0, $this->client_count, $this->attr_version );
			$this->observed_prop_versions = array_fill( 0, $this->client_count, array() );
			$this->observed_props         = array_fill( 0, $this->client_count, array() );
			return array_fill( 0, $this->client_count, 0 );
		}

		/**
		 * Intent reads carry no extra context.
		 *
		 * @return array Read context.
		 */
		public function read_context(): array {
			return array();
		}

		/**
		 * Authors one typed intent from the client's observed state.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        Workload edit.
		 * @param int   $round_index Round the edit belongs to.
		 * @return array Updates payload.
		 */
		public function author( int $client, array $edit, int $round_index ): array {
			return array( $this->intent_update( $client, $edit, 'b' . $round_index . '-' . ( $this->intent_seq++ ) ) );
		}

		/**
		 * Builds one intent update row from the client's observed state,
		 * shared by author() and the stale-base retry lane.
		 *
		 * @param int    $client    Authoring client index.
		 * @param array  $edit      Workload edit.
		 * @param string $intent_id Intent id.
		 * @return array One update row.
		 */
		private function intent_update( int $client, array $edit, string $intent_id ): array {
			$op = $edit['op'] ?? 'text';
			if ( 'attr' === $op ) {
				$paragraph = (int) $edit['paragraph'];
				$payload   = array(
					'type'    => 'set_attr',
					'payload' => array(
						'syncId'          => $this->paragraph_ids[ $paragraph ],
						'key'             => 'align',
						'value'           => $edit['align'],
						'observedVersion' => $this->observed_versions[ $client ][ $paragraph ],
					),
				);
			} elseif ( 'set_property' === $op ) {
				// The document-level register write the production manager
				// authors for a synced entity field, versioned from the
				// state this client observed (a lost register race
				// escalates `property-conflict`, the attr-conflict analog).
				$payload = array(
					'type'    => 'set_property',
					'payload' => array(
						'name'            => (string) $edit['name'],
						'value'           => $edit['value'],
						'observedVersion' => $this->observed_prop_versions[ $client ][ $edit['name'] ] ?? 0,
					),
				);
			} elseif ( 'insert_block' === $op ) {
				// The codec model for a client-born paragraph: plain text
				// (the block's identity marker) with the wrapper element on
				// the internal attr, anchored after a genesis sibling.
				$payload = array(
					'type'    => 'insert_block',
					'payload' => array(
						'block'          => array(
							'syncId'    => 'ins-' . $edit['block_id'],
							'blockType' => 'core/paragraph',
							'text'      => $edit['marker'],
							'attrs'     => array(
								'_wrapper' => array(
									'open'  => '<p>',
									'close' => '</p>',
								),
							),
						),
						'parentId'       => null,
						'afterSiblingId' => $this->paragraph_ids[ (int) $edit['after'] ],
					),
				);
			} elseif ( 'remove_block' === $op ) {
				$payload = array(
					'type'    => 'remove_block',
					'payload' => array(
						'syncId' => 'ins-' . $edit['block_id'],
					),
				);
			} else {
				// Genesis paragraphs are addressed by index; an inserted
				// block (remove-contention's contended target) by its
				// block_id-derived syncId.
				if ( isset( $edit['block_id'] ) ) {
					$sync_id = 'ins-' . $edit['block_id'];
				} else {
					$sync_id = $this->paragraph_ids[ (int) $edit['paragraph'] ];
				}

				$payload = array(
					'type'    => 'insert_text',
					'payload' => array(
						'syncId' => $sync_id,
						'field'  => 'content',
						'offset' => 0,
						'text'   => $edit['text'],
					),
				);
			}
			return array(
				'type' => WP_Intent_Log_Engine::UPDATE_TYPE_INTENT,
				'data' => wp_json_encode(
					array_merge(
						array(
							'intentId' => $intent_id,
							'baseSeq'  => $this->observed_head[ $client ],
							'txnId'    => null,
						),
						$payload
					)
				),
			);
		}

		/**
		 * Advances the head/register model and accumulates oracle
		 * expectations from the engine's own account of the edit.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        The workload edit the disposition settles.
		 * @param array $disposition Engine disposition.
		 */
		public function record_disposition( int $client, array $edit, array $disposition ): void {
			$status = $disposition['status'] ?? 'unknown';
			$op     = $edit['op'] ?? 'text';

			if ( 'voided' === $status && 'stale-base' === ( $disposition['reason'] ?? '' ) && empty( $edit['_retried'] ) ) {
				// A compaction floor reset voided this intent below the
				// retention horizon. The production client re-derives the
				// work from its editor tree and re-submits it against the
				// fresh base; model that with ONE re-authoring pass after
				// this client's next read (see followup_request()).
				$edit['_retried']               = true;
				$this->retry_queue[ $client ][] = $edit;
			}

			if ( 'applied' === $status ) {
				++$this->head; // A new log entry: the head advances.
				if ( 'attr' === $op ) {
					++$this->attr_version[ (int) $edit['paragraph'] ];
					// Ingest is serialized, so processing order IS server
					// order: last applied write wins.
					$this->expected_align[ (int) $edit['paragraph'] ] = $edit['align'];
				} elseif ( 'set_property' === $op ) {
					$name                        = (string) $edit['name'];
					$this->prop_version[ $name ] = ( $this->prop_version[ $name ] ?? 0 ) + 1;
					// Same server-order rule as align registers.
					$this->expected_props[ $name ] = $edit['value'];
				}
			} elseif ( 'voided' === $status && in_array( $disposition['reason'] ?? '', self::LOGGED_VOID_REASONS, true ) ) {
				// Apply-time voids append their intent row before the void
				// marker (see LOGGED_VOID_REASONS): the head still advances.
				++$this->head;
			}
			if ( 'text' === $op ) {
				// A text edit into an INSERTED block records its target
				// marker: the oracle scopes the token's expectation to that
				// block's final state (an applied token vanishes with its
				// concurrently removed block; correct, not lost work).
				$this->expected_texts[] = array(
					'text'   => (string) $edit['text'],
					'status' => $status,
					'target' => isset( $edit['block_id'] ) ? (string) $edit['marker'] : null,
				);
			} elseif ( 'insert_block' === $op ) {
				// A parked/voided insert never landed: the marker must be
				// absent from the materialized document.
				$this->expected_markers[ $edit['marker'] ] = 'applied' === $status ? 'alive' : 'absent';
			} elseif ( 'remove_block' === $op && 'applied' === $status ) {
				// A non-applied remove leaves the block where the insert's
				// settlement put it (a benign already-deleted void means it
				// never landed in the first place).
				$this->expected_markers[ $edit['marker'] ] = 'absent';
			}
		}

		/**
		 * Benign-void classification for the intent log.
		 *
		 * @param string $reason Void reason.
		 * @return bool True when not lost work.
		 */
		public function is_benign_void( string $reason ): bool {
			return in_array( $reason, self::BENIGN_VOID_REASONS, true );
		}

		/**
		 * A read is what the client observes: decode the delivered rows and
		 * advance the client's observed head and register versions from the
		 * WIRE, exactly as a production client derives its baseSeq from
		 * received rows. Intent rows are the log, one seq step each
		 * (apply-time-voided ones included); snapshot rows (genesis, or a
		 * compaction checkpoint after a floor reset) reset the client to
		 * their seq and document state; proposal, voided, and resolved rows
		 * are not log entries.
		 *
		 * In the single-process runner every read fully catches up and the
		 * shared disposition model sees the whole session, so the decoded
		 * state must equal the model at every read; a mismatch means
		 * delivery dropped or mangled a row and is recorded as a loud
		 * convergence failure.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 */
		public function observe( int $client, array $response ): void {
			$head          = (int) $this->observed_head[ $client ];
			$versions      = $this->observed_versions[ $client ];
			$prop_versions = $this->observed_prop_versions[ $client ];
			$props         = $this->observed_props[ $client ];
			$rows          = (array) ( $response['updates'] ?? array() );

			// An apply-time-voided intent sits in the log WITHOUT bumping any
			// register version (replicas replay it as a void), and its voided
			// marker lands in the same locked ingest, hence the same read
			// window. Collect marker ids first; a marker follows its intent
			// row in the stream.
			$voided_ids = array();
			foreach ( $rows as $row ) {
				if ( WP_Intent_Log_Engine::UPDATE_TYPE_VOIDED !== ( $row['type'] ?? '' ) ) {
					continue;
				}
				$decoded = json_decode( (string) ( $row['data'] ?? '' ), true );
				if ( is_array( $decoded ) && is_string( $decoded['intentId'] ?? null ) ) {
					$voided_ids[ $decoded['intentId'] ] = true;
				}
			}

			foreach ( $rows as $row ) {
				$type    = (string) ( $row['type'] ?? '' );
				$decoded = json_decode( (string) ( $row['data'] ?? '' ), true );
				if ( ! is_array( $decoded ) ) {
					$this->record_consistency_failure(
						'wire-decode',
						sprintf( "client %d received an undecodable '%s' row", $client, $type )
					);
					continue;
				}

				if ( WP_Intent_Log_Engine::UPDATE_TYPE_SNAPSHOT === $type ) {
					// Genesis (seq 0) or a compaction checkpoint: the client
					// re-bootstraps at the snapshot's seq, with the register
					// versions and property state its document carries.
					$head          = (int) ( $decoded['seq'] ?? 0 );
					$doc           = is_array( $decoded['doc'] ?? null ) ? $decoded['doc'] : array();
					$versions      = $this->versions_from_doc( $doc );
					$prop_versions = is_array( $doc['propVersions'] ?? null ) ? $doc['propVersions'] : array();
					$props         = is_array( $doc['props'] ?? null ) ? $doc['props'] : array();
					continue;
				}

				if ( WP_Intent_Log_Engine::UPDATE_TYPE_INTENT !== $type ) {
					continue;
				}

				++$head;
				if ( isset( $voided_ids[ $decoded['intentId'] ?? '' ] ) ) {
					continue; // An apply-time void advances no register.
				}
				if ( 'set_attr' === ( $decoded['type'] ?? '' ) ) {
					$paragraph = $this->sync_id_to_paragraph[ $decoded['payload']['syncId'] ?? '' ] ?? null;
					if ( null !== $paragraph ) {
						++$versions[ $paragraph ];
					}
				} elseif ( 'set_property' === ( $decoded['type'] ?? '' ) ) {
					$name                   = (string) ( $decoded['payload']['name'] ?? '' );
					$prop_versions[ $name ] = ( $prop_versions[ $name ] ?? 0 ) + 1;
					$props[ $name ]         = $decoded['payload']['value'] ?? null;
				}
			}

			$this->observed_head[ $client ]          = $head;
			$this->observed_versions[ $client ]      = $versions;
			$this->observed_prop_versions[ $client ] = $prop_versions;
			$this->observed_props[ $client ]         = $props;

			if ( ! $this->assert_model ) {
				return;
			}

			if ( $head !== $this->head ) {
				$this->record_consistency_failure(
					'model-wire-head',
					sprintf( 'client %d decoded head %d from delivered rows, but dispositions account for %d', $client, $head, $this->head )
				);
			}

			if ( $versions !== $this->attr_version ) {
				$this->record_consistency_failure(
					'model-wire-versions',
					sprintf(
						'client %d decoded align versions [%s] from delivered rows, but dispositions account for [%s]',
						$client,
						implode( ',', $versions ),
						implode( ',', $this->attr_version )
					)
				);
			}

			// Property registers: key order is insertion order (names appear
			// as they are first written), so compare order-insensitively.
			$wire_props  = $prop_versions;
			$model_props = $this->prop_version;
			ksort( $wire_props );
			ksort( $model_props );
			if ( $wire_props !== $model_props ) {
				$this->record_consistency_failure(
					'model-wire-prop-versions',
					sprintf(
						'client %d decoded property versions {%s} from delivered rows, but dispositions account for {%s}',
						$client,
						(string) wp_json_encode( $wire_props ),
						(string) wp_json_encode( $model_props )
					)
				);
			}

			foreach ( $this->expected_props as $name => $value ) {
				if ( ( $props[ $name ] ?? null ) !== $value ) {
					$this->record_consistency_failure(
						'model-wire-prop-value',
						sprintf(
							"client %d decoded property '%s' as '%s', last applied write in server order was '%s'",
							$client,
							$name,
							(string) wp_json_encode( $props[ $name ] ?? null ),
							(string) wp_json_encode( $value )
						)
					);
				}
			}
		}

		/**
		 * Reads the per-paragraph align register versions out of a snapshot
		 * document. Genesis paragraphs live at the root; the workload never
		 * nests or moves them.
		 *
		 * @param array $doc Snapshot document.
		 * @return int[] Paragraph index => align version.
		 */
		private function versions_from_doc( array $doc ): array {
			$versions = array_fill( 0, max( 1, (int) $this->workload['paragraphs'] ), 0 );
			foreach ( (array) ( $doc['root'] ?? array() ) as $block ) {
				$paragraph = $this->sync_id_to_paragraph[ $block['syncId'] ?? '' ] ?? null;
				if ( null !== $paragraph ) {
					$versions[ $paragraph ] = (int) ( $block['attrVersions']['align'] ?? 0 );
				}
			}

			return $versions;
		}

		/**
		 * Records a model-vs-wire consistency failure, capped so a
		 * persistently broken run reports its first drift sites instead of
		 * ballooning. score() merges these into the convergence failures, so
		 * any entry fails the run.
		 *
		 * @param string $check  Failure kind.
		 * @param string $detail Human-readable detail.
		 */
		private function record_consistency_failure( string $check, string $detail ): void {
			if ( count( $this->consistency_failures ) >= 25 ) {
				return;
			}
			$this->consistency_failures[] = array(
				'check'  => $check,
				'detail' => $detail,
			);
		}

		/**
		 * Re-authors stale-base-voided edits against the base the client
		 * just observed: the production client's floor-reset recovery
		 * (after a compaction checkpoint raises the retention floor, the
		 * client re-derives outstanding work from its editor tree and
		 * re-submits it). One retry per edit; a real, timed request the
		 * deployed protocol makes.
		 *
		 * An edit whose target block no longer exists in the observed state
		 * is NOT re-authored: a real client cannot retype into (or remove) a
		 * block that is no longer on its canvas, so that edit settles as its
		 * original benign stale void instead, the same dissolve-with-the-
		 * block outcome the scoping oracle already models.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response (unused; the
		 *                        observation already happened in observe()).
		 * @return array|null Updates payload, or null when nothing is queued.
		 */
		public function followup_request( int $client, array $response ): ?array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $response is part of the profile contract.
			if ( empty( $this->retry_queue[ $client ] ) ) {
				return null;
			}

			$updates = array();
			foreach ( $this->retry_queue[ $client ] as $edit ) {
				$op = $edit['op'] ?? 'text';
				// Block-targeted text edits and removals need their target
				// alive; genesis-targeted edits and inserts always retry.
				$needs_target = ( 'text' === $op && isset( $edit['block_id'] ) ) || 'remove_block' === $op;
				if ( $needs_target && 'alive' !== ( $this->expected_markers[ $edit['marker'] ] ?? null ) ) {
					continue;
				}
				$intent_id                           = 'retry-' . ( $this->intent_seq++ );
				$this->pending_retries[ $intent_id ] = $edit;
				$updates[]                           = $this->intent_update( $client, $edit, $intent_id );
			}
			$this->retry_queue[ $client ] = array();

			return array() === $updates ? null : $updates;
		}

		/**
		 * Settles the retried intents' dispositions through the same
		 * bookkeeping as first-run submissions (head model, register
		 * versions, oracle expectations).
		 *
		 * @param int            $client Client index.
		 * @param array|WP_Error $result handle_updates() result.
		 */
		public function record_followup_result( int $client, $result ): void {
			if ( is_wp_error( $result ) ) {
				// The whole request failed (e.g. lock timeout): the retried
				// edits stay settled as their original benign stale voids.
				$this->pending_retries = array();
				return;
			}
			foreach ( (array) ( $result['dispositions'] ?? array() ) as $disposition ) {
				$intent_id = (string) ( $disposition['intentId'] ?? '' );
				if ( ! isset( $this->pending_retries[ $intent_id ] ) ) {
					continue;
				}
				$edit = $this->pending_retries[ $intent_id ];
				unset( $this->pending_retries[ $intent_id ] );
				$this->record_disposition( $client, $edit, $disposition );
			}
		}

		/**
		 * Scores the materialized document against the accumulated
		 * dispositions, plus any model-vs-wire consistency failures the
		 * reads recorded (a delivery bug fails the run even when the
		 * materialized content happens to look right).
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array Failures (empty when converged).
		 */
		public function score( WP_Sync_Engine $engine, string $room ): ?array {
			return array_merge(
				$this->consistency_failures,
				$this->verify_property_registers(),
				self::verify_convergence(
					(string) $engine->materialize( $room ),
					(int) $this->workload['paragraphs'],
					$this->expected_texts,
					$this->expected_align,
					$this->expected_markers
				)
			);
		}

		/**
		 * Checks every caught-up client's decoded property registers against
		 * the engine's own account (last applied set_property per name in
		 * server order). Entity properties never materialize into post
		 * content, so the observable artifact is the delivered wire state —
		 * what a production client's editor would display for each synced
		 * field after full catch-up.
		 *
		 * @return array Failures (empty when converged).
		 */
		private function verify_property_registers(): array {
			$failures = array();
			foreach ( $this->expected_props as $name => $value ) {
				foreach ( $this->observed_props as $client => $props ) {
					if ( ( $props[ $name ] ?? null ) !== $value ) {
						$failures[] = array(
							'check'  => 'prop-register',
							'detail' => sprintf(
								"client %d holds property '%s' as '%s' after full catch-up, last applied write was '%s'",
								(int) $client,
								$name,
								(string) wp_json_encode( $props[ $name ] ?? null ),
								(string) wp_json_encode( $value )
							),
						);
					}
				}
			}

			return $failures;
		}

		/**
		 * Checks the materialized document against the engine's own account
		 * of the session (the dispositions it returned).
		 *
		 * Pure content oracle — no engine state: applied text tokens must
		 * appear exactly once (unless their target block's final state is
		 * absent; an applied token vanishes with its concurrently removed
		 * block), escalated/voided tokens must be absent (they were preserved
		 * for review or dropped as benign, never auto-applied), the block
		 * structure must be intact (every genesis marker present, every
		 * applied insert's marker present, every removed/parked one absent,
		 * total paragraph count matching), and each genesis block's final
		 * attribute value must be the LAST applied write in server order.
		 * Blocks are located by their identity MARKERS, not their position —
		 * concurrent structural edits shift positions.
		 *
		 * @param string $content          Materialized post content.
		 * @param int    $paragraph_count  Paragraphs the document started with.
		 * @param array  $expected_texts   List of array( 'text', 'status', 'target'? );
		 *                                 'target' is the inserted-block marker a
		 *                                 block-targeted text edit wrote into, null
		 *                                 for genesis-targeted edits.
		 * @param array  $expected_align   Paragraph index => final align value.
		 * @param array  $expected_markers Inserted marker => 'alive' | 'absent'.
		 * @return array Failures (empty when converged), each array( 'check', 'detail' ).
		 */
		public static function verify_convergence( string $content, int $paragraph_count, array $expected_texts, array $expected_align, array $expected_markers = array() ): array {
			if ( '' === $content ) {
				return array(
					array(
						'check'  => 'materialize',
						'detail' => 'materialized content is empty',
					),
				);
			}

			$failures = array();

			$blocks = array_values(
				array_filter(
					parse_blocks( $content ),
					static function ( $block ) {
						return 'core/paragraph' === ( $block['blockName'] ?? null );
					}
				)
			);

			$alive = count(
				array_filter(
					$expected_markers,
					static function ( $state ) {
						return 'alive' === $state;
					}
				)
			);
			if ( count( $blocks ) !== $paragraph_count + $alive ) {
				$failures[] = array(
					'check'  => 'structure',
					'detail' => sprintf( 'expected %d paragraph blocks (%d genesis + %d live inserts), found %d', $paragraph_count + $alive, $paragraph_count, $alive, count( $blocks ) ),
				);
			}
			for ( $i = 0; $i < $paragraph_count; $i++ ) {
				if ( 1 !== substr_count( $content, WP_Sync_Bench_Workload::genesis_marker( $i ) ) ) {
					$failures[] = array(
						'check'  => 'structure',
						'detail' => sprintf( "genesis block '%s' is missing or duplicated", WP_Sync_Bench_Workload::genesis_marker( $i ) ),
					);
				}
			}
			foreach ( $expected_markers as $marker => $state ) {
				$found = substr_count( $content, $marker );
				if ( ( 'alive' === $state ? 1 : 0 ) !== $found ) {
					$failures[] = array(
						'check'  => 'structure',
						'detail' => sprintf( "inserted block '%s' should be %s, found %d times", $marker, $state, $found ),
					);
				}
			}

			foreach ( $expected_texts as $entry ) {
				$found = substr_count( $content, $entry['text'] );
				// Scoping rule for the edit-vs-remove class: a token whose
				// target block was concurrently REMOVED is expected in the
				// content iff its edit applied AND the block's final state is
				// alive; an applied token legitimately vanishes with its
				// removed block.
				$target         = $entry['target'] ?? null;
				$target_removed = null !== $target && 'absent' === ( $expected_markers[ $target ] ?? null );
				if ( 'applied' === $entry['status'] && $target_removed ) {
					if ( 0 !== $found ) {
						$failures[] = array(
							'check'  => 'applied-text',
							'detail' => sprintf( "token '%s' survived the removal of its target block '%s'", $entry['text'], $target ),
						);
					}
				} elseif ( 'applied' === $entry['status'] && 1 !== $found ) {
					$failures[] = array(
						'check'  => 'applied-text',
						'detail' => sprintf( "applied token '%s' found %d times (expected exactly 1)", $entry['text'], $found ),
					);
				} elseif ( 'escalated' === $entry['status'] && 0 !== $found ) {
					$failures[] = array(
						'check'  => 'escalated-text',
						'detail' => sprintf( "escalated token '%s' leaked into content (must be set aside, not merged)", $entry['text'] ),
					);
				}
			}

			foreach ( $expected_align as $paragraph => $align ) {
				$block  = self::find_block_by_marker( $blocks, WP_Sync_Bench_Workload::genesis_marker( (int) $paragraph ) );
				$actual = null === $block ? null : ( $block['attrs']['align'] ?? null );
				if ( $actual !== $align ) {
					$failures[] = array(
						'check'  => 'attr-register',
						'detail' => sprintf( "paragraph %d align is '%s', last applied write was '%s'", $paragraph, (string) $actual, $align ),
					);
				}
			}

			return $failures;
		}

		/**
		 * Finds the paragraph block whose body carries an identity marker.
		 *
		 * @param array  $blocks Parsed paragraph blocks.
		 * @param string $marker Identity marker.
		 * @return array|null The block, or null when absent.
		 */
		private static function find_block_by_marker( array $blocks, string $marker ): ?array {
			foreach ( $blocks as $block ) {
				if ( false !== strpos( (string) ( $block['innerHTML'] ?? '' ), $marker ) ) {
					return $block;
				}
			}
			return null;
		}
	}
}
