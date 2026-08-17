<?php
/**
 * The yjs-server authoring profile: real y-php clients + the CRDT oracle.
 *
 * Each simulated client holds a y-php document. Edits happen in that
 * document (text inserts into the paragraph's content Y.Text; align set on
 * the attributes Y.Map; entity-property registers as plain values on the
 * document map — exactly what the editor's session codec sends), and the
 * submitted update is the genuine incremental V2 encoding of the edit, so
 * payload and storage bytes are REAL for this engine. Read
 * responses are applied back into the client document (untimed client
 * work, like authoring).
 *
 * Quality is scored with an oracle matched to CRDT semantics: after full
 * catch-up every client document must be identical (the convergence
 * guarantee), applied text tokens must appear in the server-materialized
 * content exactly once (text merges are lossless), the block structure
 * must be intact, and the materialized attribute registers must equal the
 * converged CRDT value. Attribute conflicts resolve by CRDT rules
 * (deterministic, but NOT server arrival order) rather than escalating —
 * the policy difference with intent-log, reported honestly.
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_Yjs_Server_Profile' ) ) {

	/**
	 * Simulated y-php clients.
	 */
	class WP_Sync_Bench_Yjs_Server_Profile implements WP_Sync_Bench_Authoring_Profile {
		/** Void reasons that are NOT lost work for this engine: idempotent
		 * redelivery (the server diffs out what it already has), or the
		 * resync lane (this profile models the real client's recovery: the
		 * next authored update carries the client's full state, which
		 * re-delivers the voided content). `invalid-payload` is NOT benign:
		 * the engine reserves it for genuinely malformed bytes, which the
		 * profile never sends, so its appearance means work was rejected.
		 * The edit-vs-remove contention class adds nothing here: a text
		 * update into a concurrently deleted block still APPLIES (CRDT
		 * deletion semantics resolve it; the token dissolves with the
		 * block), so no new void reasons surface for this engine.
		 *
		 * @var string[]
		 */
		const BENIGN_VOID_REASONS = array(
			'already-merged',
			'already-deleted',
			'already-removed',
			'stale-base',
			'resync-required',
		);

		/**
		 * Workload from the generator.
		 *
		 * @var array
		 */
		private $workload;

		/**
		 * Clients whose last submission voided `resync-required`; their next
		 * authored update is a full-state upload (the real client's recovery
		 * lane), which the server diffs and applies idempotently.
		 *
		 * @var array<int, bool>
		 */
		private $needs_resync = array();

		/**
		 * Edits whose submission voided `resync-required`, per client. The
		 * client's recovery upload re-delivers them, so once it lands the
		 * oracle must expect their content exactly as if they had applied
		 * directly. Edits still pending when the session ends stay
		 * unexpected (their voids were benign only because recovery was
		 * coming; the oracle does not demand content the room never got).
		 *
		 * @var array<int, array<int, array>>
		 */
		private $resync_pending = array();

		/**
		 * Simulated client count.
		 *
		 * @var int
		 */
		private $client_count;

		/**
		 * Per-client y-php documents.
		 *
		 * @var array<int, \Yjs\Utils\Doc>
		 */
		private $ydocs = array();

		/**
		 * Oracle input: text tokens and how the engine settled them.
		 *
		 * @var array<int, array{text: string, status: string}>
		 */
		private $expected_texts = array();

		/**
		 * Oracle input: inserted-block marker => 'alive' | 'absent', from
		 * insert/remove dispositions.
		 *
		 * @var array<string, string>
		 */
		private $expected_markers = array();

		/**
		 * Oracle input: property name => every value the session wrote to
		 * that register. Register conflicts resolve by CRDT rules (NOT
		 * server order), so the oracle cannot name ONE expected winner; it
		 * asserts the converged value is a value somebody actually wrote
		 * (and client convergence covers the rest).
		 *
		 * @var array<string, array>
		 */
		private $written_props = array();

		/**
		 * Constructor (the factory contract).
		 *
		 * @param int   $post_id  Seeded post (room target, unused here).
		 * @param array $workload Workload from the generator.
		 */
		public function __construct( int $post_id, array $workload ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $post_id is part of the factory contract.
			$this->workload     = $workload;
			$this->client_count = max( 1, (int) $workload['clients'] );
		}

		/**
		 * Profile name.
		 *
		 * @return string Profile name.
		 */
		public function name(): string {
			return 'yjs-server';
		}

		/**
		 * Bootstraps each client's document from the genesis snapshot (an
		 * untimed setup read — the intent-log profile has no equivalent
		 * timed join read either). Deterministic clientIDs keep counted
		 * metrics identical across repetitions.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array<int, int> Initial read cursor per client.
		 */
		public function bootstrap( WP_Sync_Engine $engine, string $room ): array {
			require_once dirname( __DIR__, 2 ) . '/includes/lib/y-php-loader.php';
			gutenberg_sync_engines_load_y_php();

			$cursors = array();
			for ( $client = 0; $client < $this->client_count; $client++ ) {
				$doc           = new \Yjs\Utils\Doc();
				$doc->clientID = 1000 + $client; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- y-php mirrors the JS Yjs API.
				$bootstrap     = $engine->get_updates_since( $room, $client, 0, array() );
				self::apply_yjs_rows( $doc, $bootstrap['updates'] );
				$cursors[ $client ]     = (int) $bootstrap['end_cursor'];
				$this->ydocs[ $client ] = $doc;
			}
			return $cursors;
		}

		/**
		 * Reads carry the session awareness roster — in production this is
		 * the presence list the transport merges into the read context.
		 *
		 * @return array Read context.
		 */
		public function read_context(): array {
			return array( 'awareness' => array_fill_keys( range( 0, $this->client_count - 1 ), array() ) );
		}

		/**
		 * Authors the edit in the client's own CRDT document; the submitted
		 * update is the genuine incremental V2 encoding of it (everything
		 * past the pre-edit state vector).
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        Workload edit.
		 * @param int   $round_index Round the edit belongs to (unused).
		 * @return array Updates payload.
		 */
		public function author( int $client, array $edit, int $round_index ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $round_index is part of the profile contract.
			$doc       = $this->ydocs[ $client ];
			$sv_before = \Yjs\encodeStateVector( $doc );
			$yblocks   = $doc->getMap( 'document' )->get( 'blocks' );
			$op        = $edit['op'] ?? 'text';

			if ( 'attr' === $op ) {
				$this->find_block( $yblocks, 'srv-' . (int) $edit['paragraph'] )->get( 'attributes' )->set( 'align', $edit['align'] );
			} elseif ( 'set_property' === $op ) {
				// A scalar entity-property register: a plain value on the
				// document map, exactly what the core-data CRDT codec's
				// updateMapValue writes for non-rich-text properties
				// (title/excerpt are Y.Text and are a different op class).
				$name = (string) $edit['name'];
				$doc->getMap( 'document' )->set( $name, $edit['value'] );
				$this->written_props[ $name ][] = $edit['value'];
			} elseif ( 'insert_block' === $op ) {
				// A client-born paragraph, mirroring the codec's shape (the
				// server materializes it with the per-type default wrapper).
				// Anchored after the genesis block IN THIS CLIENT'S doc —
				// concurrent inserts at the same anchor interleave by CRDT
				// order.
				$index = $this->find_block_index( $yblocks, 'srv-' . (int) $edit['after'] );
				$yblocks->insert(
					null === $index ? $yblocks->length : $index + 1,
					array( self::make_client_yblock( 'ins-' . $edit['block_id'], $edit['marker'] ) )
				);
			} elseif ( 'remove_block' === $op ) {
				$index = $this->find_block_index( $yblocks, 'ins-' . $edit['block_id'] );
				if ( null !== $index ) {
					$yblocks->delete( $index, 1 );
				}
			} else {
				// Blocks are addressed by their stable clientId, not their
				// position; concurrent structural edits shift indexes.
				// Genesis paragraphs carry srv-N ids; an inserted block
				// (remove-contention's contended target) its ins-<block_id>.
				// The editor's own doc always still holds the target: the
				// workload only contends blocks every client has observed,
				// and a concurrent remove is invisible to this client until
				// its next read.
				if ( isset( $edit['block_id'] ) ) {
					$target_id = 'ins-' . $edit['block_id'];
				} else {
					$target_id = 'srv-' . (int) $edit['paragraph'];
				}

				$this->find_block( $yblocks, $target_id )->get( 'attributes' )->get( 'content' )->insert( 0, $edit['text'] );
			}

			// The recovery lane: after a `resync-required` void the real
			// client uploads its full state (self-contained, so it carries
			// the voided content too); the server diffs out what it already
			// has. Otherwise the submission is the genuine incremental
			// encoding of this edit.
			if ( ! empty( $this->needs_resync[ $client ] ) ) {
				unset( $this->needs_resync[ $client ] );
				$encoded = \Yjs\encodeStateAsUpdateV2( $doc );
			} else {
				$encoded = \Yjs\encodeStateAsUpdateV2( $doc, $sv_before );
			}

			return array(
				array(
					'type' => 'update',
					'data' => $encoded->toBase64(),
				),
			);
		}

		/**
		 * Finds a block Y.Map by its clientId.
		 *
		 * @param \Yjs\Types\YArray $yblocks   Blocks array.
		 * @param string            $client_id Block clientId.
		 * @return \Yjs\Types\YMap|null The block, or null when absent.
		 */
		private function find_block( $yblocks, string $client_id ) {
			$index = $this->find_block_index( $yblocks, $client_id );
			return null === $index ? null : $yblocks->get( $index );
		}

		/**
		 * Finds a block's index by its clientId.
		 *
		 * @param \Yjs\Types\YArray $yblocks   Blocks array.
		 * @param string            $client_id Block clientId.
		 * @return int|null The index, or null when absent.
		 */
		private function find_block_index( $yblocks, string $client_id ): ?int {
			$length = (int) $yblocks->length;
			for ( $i = 0; $i < $length; $i++ ) {
				$block = $yblocks->get( $i );
				if ( $block instanceof \Yjs\Types\YMap && $block->get( 'clientId' ) === $client_id ) {
					return $i;
				}
			}
			return null;
		}

		/**
		 * Builds a client-born paragraph YBlock, mirroring the engine's
		 * genesis shape (name/clientId/isValid/attributes/innerBlocks; the
		 * body rides the content Y.Text; isValid MUST be true or the editor
		 * renders the block in invalid-content recovery mode).
		 *
		 * @param string $client_id Block clientId.
		 * @param string $marker    Identity marker (the block body).
		 * @return \Yjs\Types\YMap YBlock.
		 */
		private static function make_client_yblock( string $client_id, string $marker ): \Yjs\Types\YMap {
			$attributes = new \Yjs\Types\YMap();
			$attributes->set( 'content', new \Yjs\Types\YText( $marker ) );

			$yblock = new \Yjs\Types\YMap();
			$yblock->set( 'name', 'core/paragraph' );
			$yblock->set( 'clientId', $client_id );
			$yblock->set( 'isValid', true );
			$yblock->set( 'attributes', $attributes );
			$yblock->set( 'innerBlocks', new \Yjs\Types\YArray() );
			return $yblock;
		}

		/**
		 * Accumulates oracle expectations: text merges are lossless under
		 * CRDT rules, so every applied token must appear in the materialized
		 * document. Attribute registers resolve by CRDT conflict rules (NOT
		 * server order), so their oracle is the converged client documents,
		 * checked after the session — nothing to track here.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        The workload edit the disposition settles.
		 * @param array $disposition Engine disposition.
		 */
		public function record_disposition( int $client, array $edit, array $disposition ): void {
			$status = $disposition['status'] ?? 'unknown';
			$reason = (string) ( $disposition['reason'] ?? '' );

			if ( 'voided' === $status && 'resync-required' === $reason ) {
				// The next authored update from this client is a full-state
				// recovery upload that re-delivers this edit; park it until
				// the recovery lands.
				$this->needs_resync[ $client ]     = true;
				$this->resync_pending[ $client ][] = $edit;
			} elseif ( ( 'applied' === $status || 'already-merged' === $reason ) && array() !== ( $this->resync_pending[ $client ] ?? array() ) ) {
				// The recovery landed (applied, or the server already held
				// everything it carried): every parked edit is now in the
				// room and the oracle must expect it exactly as if it had
				// applied directly.
				foreach ( $this->resync_pending[ $client ] as $recovered ) {
					$this->record_expectation( $recovered, 'applied' );
				}
				$this->resync_pending[ $client ] = array();
			}

			$this->record_expectation( $edit, $status );
		}

		/**
		 * Records one edit's oracle expectation: applied text tokens must
		 * appear in the materialized content exactly once; block markers
		 * flip between alive and absent. Attribute registers resolve by
		 * CRDT conflict rules (NOT server order), so their oracle is the
		 * converged client documents, checked after the session.
		 *
		 * @param array  $edit   The workload edit.
		 * @param string $status The status it settled with.
		 */
		private function record_expectation( array $edit, string $status ): void {
			$op = $edit['op'] ?? 'text';
			if ( 'text' === $op ) {
				// A text edit into an INSERTED block records its target
				// marker: under CRDT deletion semantics an applied token
				// dissolves with its concurrently deleted block, so the
				// oracle scopes the token's expectation to the block's final
				// state.
				$this->expected_texts[] = array(
					'text'   => (string) $edit['text'],
					'status' => $status,
					'target' => isset( $edit['block_id'] ) ? (string) $edit['marker'] : null,
				);
			} elseif ( 'insert_block' === $op ) {
				$this->expected_markers[ $edit['marker'] ] = 'applied' === $status ? 'alive' : 'absent';
			} elseif ( 'remove_block' === $op && 'applied' === $status ) {
				$this->expected_markers[ $edit['marker'] ] = 'absent';
			}
		}

		/**
		 * Benign-void classification for yjs-server.
		 *
		 * @param string $reason Void reason.
		 * @return bool True when not lost work.
		 */
		public function is_benign_void( string $reason ): bool {
			return in_array( $reason, self::BENIGN_VOID_REASONS, true );
		}

		/**
		 * Applies the delivered rows into the client CRDT — client work,
		 * untimed, like authoring.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 */
		public function observe( int $client, array $response ): void {
			self::apply_yjs_rows( $this->ydocs[ $client ], $response['updates'] ?? array() );
		}

		/**
		 * The server compacts by itself and never nominates a client, so
		 * reads trigger no follow-up ingest. The one recovery this engine
		 * asks for (`resync-required`) surfaces on an INGEST response, not
		 * a read, and rides the client's next authored submission as a
		 * full-state upload (see author()).
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 * @return array|null Always null.
		 */
		public function followup_request( int $client, array $response ): ?array {
			return null;
		}

		/**
		 * No follow-ups are ever requested; nothing to settle.
		 *
		 * @param int            $client Client index.
		 * @param array|WP_Error $result handle_updates() result.
		 */
		public function record_followup_result( int $client, $result ): void {
		}

		/**
		 * Scores CRDT convergence over the caught-up client documents and
		 * the server-materialized content.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array Failures (empty when converged).
		 */
		public function score( WP_Sync_Engine $engine, string $room ): ?array {
			return self::verify_crdt_convergence(
				(string) $engine->materialize( $room ),
				(int) $this->workload['paragraphs'],
				$this->expected_texts,
				$this->ydocs,
				$this->expected_markers,
				$this->written_props
			);
		}

		/**
		 * Applies a room response's rows to a client-side y-php document
		 * (snapshot rows carry `{ doc: <base64 V2> }`; update rows carry the
		 * base64 V2 update directly).
		 *
		 * Per-row apply failures are skipped rather than fataling the
		 * simulated client. y-php parks missing-dependency rows as pending
		 * (JS Yjs parity), so under the multi-process concurrency probe a
		 * row whose dependency was skipped by a read-visibility race simply
		 * waits for the gap to fill; the catch remains as a guard against
		 * malformed rows. Unreachable in the single-process harness (rows
		 * always arrive in causal order there), so the convergence oracle
		 * is unaffected.
		 *
		 * @param \Yjs\Utils\Doc $doc  Client document.
		 * @param array          $rows Typed rows from get_updates_since().
		 */
		private static function apply_yjs_rows( $doc, array $rows ): void {
			foreach ( $rows as $row ) {
				try {
					if ( 'snapshot' === ( $row['type'] ?? '' ) ) {
						$decoded = json_decode( (string) $row['data'], true );
						if ( is_array( $decoded ) && is_string( $decoded['doc'] ?? null ) ) {
							\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $decoded['doc'] ) );
						}
					} elseif ( 'update' === ( $row['type'] ?? '' ) ) {
						\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( (string) $row['data'] ) );
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		/**
		 * Checks CRDT convergence: after full catch-up, every client's
		 * document must be identical (the CRDT guarantee), the server's
		 * materialized content must carry every applied text token exactly
		 * once (text merges are lossless — nothing lost), keep the block
		 * structure intact, and agree with the converged documents on each
		 * attribute register. Attribute conflicts resolve by CRDT rules
		 * (deterministic, but NOT server arrival order), so the oracle for
		 * them is the converged client state, not the submission log.
		 *
		 * @param string $content          Materialized post content.
		 * @param int    $paragraph_count  Paragraphs the document started with.
		 * @param array  $expected_texts   List of array( 'text', 'status', 'target'? );
		 *                                 'target' is the inserted-block marker a
		 *                                 block-targeted text edit wrote into, null
		 *                                 for genesis-targeted edits.
		 * @param array  $ydocs            Caught-up client documents.
		 * @param array  $expected_markers Inserted marker => 'alive' | 'absent'.
		 * @param array  $written_props    Property name => every value written to
		 *                                 that entity-property register.
		 * @return array Failures (empty when converged).
		 */
		public static function verify_crdt_convergence( string $content, int $paragraph_count, array $expected_texts, array $ydocs, array $expected_markers = array(), array $written_props = array() ): array {
			if ( '' === $content ) {
				return array(
					array(
						'check'  => 'materialize',
						'detail' => 'materialized content is empty',
					),
				);
			}

			$failures = array();

			// All clients converged to the same document.
			$fingerprints = array();
			foreach ( $ydocs as $client => $doc ) {
				$fingerprints[ $client ] = wp_json_encode(
					self::normalize_for_compare( $doc->getMap( 'document' )->toJSON() )
				);
			}
			if ( count( array_unique( $fingerprints ) ) > 1 ) {
				$failures[] = array(
					'check'  => 'client-convergence',
					'detail' => 'client documents diverged after full catch-up',
				);
			}

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
				// Scoping rule for the edit-vs-remove class: CRDT deletion
				// wins over a concurrent text insert INTO the deleted block.
				// The applied token dissolves with the block (deterministic
				// merge semantics, not lost work), so its expectation follows
				// the target block's final state.
				$target         = $entry['target'] ?? null;
				$target_removed = null !== $target && 'absent' === ( $expected_markers[ $target ] ?? null );
				if ( 'applied' === $entry['status'] && $target_removed ) {
					if ( 0 !== $found ) {
						$failures[] = array(
							'check'  => 'applied-text',
							'detail' => sprintf( "token '%s' survived the deletion of its target block '%s'", $entry['text'], $target ),
						);
					}
				} elseif ( 'applied' === $entry['status'] && 1 !== $found ) {
					$failures[] = array(
						'check'  => 'applied-text',
						'detail' => sprintf( "applied token '%s' found %d times (expected exactly 1)", $entry['text'], $found ),
					);
				}
			}

			// The materialized attribute registers match the converged CRDT
			// state (client 0 is representative once client convergence
			// held). Genesis blocks are located by clientId in the CRDT and
			// by identity marker in the materialized content — never by
			// position, which structural edits shift.
			$reference = reset( $ydocs );
			if ( $reference ) {
				$doc_json    = self::normalize_for_compare( $reference->getMap( 'document' )->toJSON() );
				$json_blocks = is_array( $doc_json ) && is_array( $doc_json['blocks'] ?? null )
					? $doc_json['blocks']
					: array();
				for ( $i = 0; $i < $paragraph_count; $i++ ) {
					$expected = null;
					foreach ( $json_blocks as $json_block ) {
						if ( is_array( $json_block ) && ( $json_block['clientId'] ?? null ) === 'srv-' . $i ) {
							$expected = $json_block['attributes']['align'] ?? null;
							break;
						}
					}
					if ( ! is_string( $expected ) ) {
						$expected = null;
					}
					$block  = null;
					$marker = WP_Sync_Bench_Workload::genesis_marker( $i );
					foreach ( $blocks as $candidate ) {
						if ( false !== strpos( (string) ( $candidate['innerHTML'] ?? '' ), $marker ) ) {
							$block = $candidate;
							break;
						}
					}
					$actual = null === $block ? null : ( $block['attrs']['align'] ?? null );
					if ( $actual !== $expected ) {
						$failures[] = array(
							'check'  => 'attr-register',
							'detail' => sprintf( "paragraph %d align is '%s', converged CRDT value is '%s'", $i, (string) $actual, (string) $expected ),
						);
					}
				}

				// Entity-property registers never materialize into post
				// content, and their conflicts resolve by CRDT rules (NOT
				// server order) — so the assertable expectation is that each
				// written register converged to a value somebody actually
				// wrote (the client-convergence check above already
				// guarantees every client agrees on it).
				foreach ( $written_props as $name => $values ) {
					$converged = $doc_json[ $name ] ?? null;
					if ( ! in_array( $converged, $values, true ) ) {
						$failures[] = array(
							'check'  => 'prop-register',
							'detail' => sprintf( "property '%s' converged to '%s', a value no client wrote", $name, (string) wp_json_encode( $converged ) ),
						);
					}
				}
			}

			return $failures;
		}

		/**
		 * Normalizes y-php toJSON() output for comparison: stdClass becomes
		 * a key-sorted array recursively, so map key iteration order (which
		 * can differ between replicas) does not affect equality.
		 *
		 * @param mixed $value JSON value.
		 * @return mixed Normalized value.
		 */
		private static function normalize_for_compare( $value ) {
			if ( $value instanceof \stdClass ) {
				$value = (array) $value;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$value[ $key ] = self::normalize_for_compare( $item );
				}
				if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					ksort( $value );
				}
			}
			return $value;
		}
	}
}
