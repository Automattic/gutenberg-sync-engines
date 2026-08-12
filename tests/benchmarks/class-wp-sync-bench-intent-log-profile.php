<?php
/**
 * Intent-log authoring profile: typed intents + the disposition oracle.
 *
 * Speaks to the engine in typed intents (insert_text into a paragraph's
 * content field; set_attr on its align register), authored from the state
 * each simulated client OBSERVED at its own last read: the profile tracks
 * the server head (every applied intent advances it) and per-paragraph
 * attribute versions, and stamps each intent with the author's observed
 * baseSeq/observedVersion — so a laggy client genuinely authors from a
 * stale base and a same-register collision escalates the later writer.
 *
 * Quality is scored with the disposition oracle: the materialized document
 * must match the engine's own account of the session (applied tokens
 * present exactly once, escalated tokens absent, structure intact, each
 * register equal to the last applied write in server order — ingest is
 * serialized, so processing order IS server order).
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
		 * content).
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
		 * Server head: the number of APPLIED intents (base_seq is 0 in these
		 * runs).
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
		 * Monotonic intentId counter.
		 *
		 * @var int
		 */
		private $intent_seq = 0;

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
		 * Constructor (the factory contract).
		 *
		 * @param int   $post_id  Seeded post (room target).
		 * @param array $workload Workload from the generator.
		 */
		public function __construct( int $post_id, array $workload ) {
			$this->post_id      = $post_id;
			$this->workload     = $workload;
			$this->client_count = max( 1, (int) $workload['clients'] );
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
				$this->paragraph_ids[] = WP_Intent_Log_Planner::genesis_sync_id( $this->post_id, 0, array( $i ) );
			}
			$this->attr_version      = array_fill( 0, max( 1, (int) $this->workload['paragraphs'] ), 0 );
			$this->observed_head     = array_fill( 0, $this->client_count, 0 );
			$this->observed_versions = array_fill( 0, $this->client_count, $this->attr_version );
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
			$paragraph = (int) $edit['paragraph'];
			if ( 'attr' === ( $edit['op'] ?? 'text' ) ) {
				$payload = array(
					'type'    => 'set_attr',
					'payload' => array(
						'syncId'          => $this->paragraph_ids[ $paragraph ],
						'key'             => 'align',
						'value'           => $edit['align'],
						'observedVersion' => $this->observed_versions[ $client ][ $paragraph ],
					),
				);
			} else {
				$payload = array(
					'type'    => 'insert_text',
					'payload' => array(
						'syncId' => $this->paragraph_ids[ $paragraph ],
						'field'  => 'content',
						'offset' => 0,
						'text'   => $edit['text'],
					),
				);
			}
			return array(
				array(
					'type' => WP_Intent_Log_Engine::UPDATE_TYPE_INTENT,
					'data' => wp_json_encode(
						array_merge(
							array(
								'intentId' => 'b' . $round_index . '-' . ( $this->intent_seq++ ),
								'baseSeq'  => $this->observed_head[ $client ],
								'txnId'    => null,
							),
							$payload
						)
					),
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
		public function record_disposition( int $client, array $edit, array $disposition ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $client is part of the profile contract.
			$status  = $disposition['status'] ?? 'unknown';
			$is_attr = 'attr' === ( $edit['op'] ?? 'text' );

			if ( 'applied' === $status ) {
				++$this->head; // A new log entry: the head advances.
				if ( $is_attr ) {
					++$this->attr_version[ (int) $edit['paragraph'] ];
					// Ingest is serialized, so processing order IS server
					// order: last applied write wins.
					$this->expected_align[ (int) $edit['paragraph'] ] = $edit['align'];
				}
			}
			if ( ! $is_attr ) {
				$this->expected_texts[] = array(
					'text'   => (string) $edit['text'],
					'status' => $status,
				);
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
		 * A read is what the client observes: it now authors against the
		 * server head (and register versions) as of this point.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 */
		public function observe( int $client, array $response ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $response is part of the profile contract.
			$this->observed_head[ $client ]     = $this->head;
			$this->observed_versions[ $client ] = $this->attr_version;
		}

		/**
		 * The intent log compacts server-side and never voids at a stale
		 * base (it transforms); clients send no follow-ups.
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
		 * Scores the materialized document against the accumulated
		 * dispositions.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array Failures (empty when converged).
		 */
		public function score( WP_Sync_Engine $engine, string $room ): ?array {
			return self::verify_convergence(
				(string) $engine->materialize( $room ),
				(int) $this->workload['paragraphs'],
				$this->expected_texts,
				$this->expected_align
			);
		}

		/**
		 * Checks the materialized document against the engine's own account
		 * of the session (the dispositions it returned).
		 *
		 * Pure content oracle — no engine state: applied text tokens must
		 * appear exactly once, escalated/voided tokens must be absent (they
		 * were preserved for review or dropped as benign, never auto-applied),
		 * the block structure must be intact, and each block's final
		 * attribute value must be the LAST applied write in server order.
		 *
		 * @param string $content         Materialized post content.
		 * @param int    $paragraph_count Paragraphs the document started with.
		 * @param array  $expected_texts  List of array( 'text', 'status' ).
		 * @param array  $expected_align  Paragraph index => final align value.
		 * @return array Failures (empty when converged), each array( 'check', 'detail' ).
		 */
		public static function verify_convergence( string $content, int $paragraph_count, array $expected_texts, array $expected_align ): array {
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
				} elseif ( 'escalated' === $entry['status'] && 0 !== $found ) {
					$failures[] = array(
						'check'  => 'escalated-text',
						'detail' => sprintf( "escalated token '%s' leaked into content (must be set aside, not merged)", $entry['text'] ),
					);
				}
			}

			foreach ( $expected_align as $paragraph => $align ) {
				$actual = $blocks[ $paragraph ]['attrs']['align'] ?? null;
				if ( $actual !== $align ) {
					$failures[] = array(
						'check'  => 'attr-register',
						'detail' => sprintf( "paragraph %d align is '%s', last applied write was '%s'", $paragraph, (string) $actual, $align ),
					);
				}
			}

			return $failures;
		}
	}
}
