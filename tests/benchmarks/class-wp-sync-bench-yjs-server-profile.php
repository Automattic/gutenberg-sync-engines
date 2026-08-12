<?php
/**
 * The yjs-server authoring profile: real y-php clients + the CRDT oracle.
 *
 * Each simulated client holds a y-php document. Edits happen in that
 * document (text inserts into the paragraph's content Y.Text; align set on
 * the attributes Y.Map — exactly what the editor's session codec sends),
 * and the submitted update is the genuine incremental V2 encoding of the
 * edit, so payload and storage bytes are REAL for this engine. Read
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
		 * redelivery (the server diffs out what it already has) or a
		 * malformed row.
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
			$paragraph = (int) $edit['paragraph'];
			$block     = $doc->getMap( 'document' )->get( 'blocks' )->get( $paragraph );
			if ( 'attr' === ( $edit['op'] ?? 'text' ) ) {
				$block->get( 'attributes' )->set( 'align', $edit['align'] );
			} else {
				$block->get( 'attributes' )->get( 'content' )->insert( 0, $edit['text'] );
			}
			return array(
				array(
					'type' => 'update',
					'data' => \Yjs\encodeStateAsUpdateV2( $doc, $sv_before )->toBase64(),
				),
			);
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
		public function record_disposition( int $client, array $edit, array $disposition ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $client is part of the profile contract.
			if ( 'attr' !== ( $edit['op'] ?? 'text' ) ) {
				$this->expected_texts[] = array(
					'text'   => (string) $edit['text'],
					'status' => $disposition['status'] ?? 'unknown',
				);
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
		 * The server compacts by itself and CRDT merges never reject a
		 * stale base; clients send no follow-ups.
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
				$this->ydocs
			);
		}

		/**
		 * Applies a room response's rows to a client-side y-php document
		 * (snapshot rows carry `{ doc: <base64 V2> }`; update rows carry the
		 * base64 V2 update directly).
		 *
		 * @param \Yjs\Utils\Doc $doc  Client document.
		 * @param array          $rows Typed rows from get_updates_since().
		 */
		private static function apply_yjs_rows( $doc, array $rows ): void {
			foreach ( $rows as $row ) {
				if ( 'snapshot' === ( $row['type'] ?? '' ) ) {
					$decoded = json_decode( (string) $row['data'], true );
					if ( is_array( $decoded ) && is_string( $decoded['doc'] ?? null ) ) {
						\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $decoded['doc'] ) );
					}
				} elseif ( 'update' === ( $row['type'] ?? '' ) ) {
					\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( (string) $row['data'] ) );
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
		 * @param string $content         Materialized post content.
		 * @param int    $paragraph_count Paragraphs the document started with.
		 * @param array  $expected_texts  List of array( 'text', 'status' ).
		 * @param array  $ydocs           Caught-up client documents.
		 * @return array Failures (empty when converged).
		 */
		public static function verify_crdt_convergence( string $content, int $paragraph_count, array $expected_texts, array $ydocs ): array {
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
			if ( count( $blocks ) !== $paragraph_count ) {
				$failures[] = array(
					'check'  => 'structure',
					'detail' => sprintf( 'expected %d paragraph blocks, found %d', $paragraph_count, count( $blocks ) ),
				);
			}

			foreach ( $expected_texts as $entry ) {
				$found = substr_count( $content, $entry['text'] );
				if ( 'applied' === $entry['status'] && 1 !== $found ) {
					$failures[] = array(
						'check'  => 'applied-text',
						'detail' => sprintf( "applied token '%s' found %d times (expected exactly 1)", $entry['text'], $found ),
					);
				}
			}

			// The materialized attribute registers match the converged CRDT
			// state (client 0 is representative once client convergence held).
			$reference = reset( $ydocs );
			if ( $reference ) {
				$yblocks     = $reference->getMap( 'document' )->get( 'blocks' );
				$block_count = min( $paragraph_count, count( $blocks ) );
				for ( $i = 0; $i < $block_count; $i++ ) {
					$expected = null;
					try {
						$expected = $yblocks->get( $i )->get( 'attributes' )->get( 'align' );
					} catch ( \Throwable $e ) {
						$expected = null;
					}
					if ( ! is_string( $expected ) ) {
						// A never-set key comes back as y-php's UndefinedValue
						// singleton: an absent register.
						$expected = null;
					}
					$actual = $blocks[ $i ]['attrs']['align'] ?? null;
					if ( $actual !== $expected ) {
						$failures[] = array(
							'check'  => 'attr-register',
							'detail' => sprintf( "paragraph %d align is '%s', converged CRDT value is '%s'", $i, (string) $actual, (string) $expected ),
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
