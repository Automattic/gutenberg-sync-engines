<?php
/**
 * The de-rtc authoring profile: whole-content proposals + the lineage
 * oracle.
 *
 * Each simulated client keeps a local working copy of the document and the
 * version whose canonical content that copy incorporates (base = last
 * version APPLIED to the doc — the client adapter's rule). An edit is
 * applied to the local copy and submitted as one proposal
 * `{proposalId, baseVersion, proposedContent, clientUpdate: null}` —
 * `clientUpdate: null` is what the shipping client sends; the server's
 * engine-unaware-writer lane derives operations. Reads deliver
 * server-authored canonical `content` rows, which the client adopts
 * wholesale (safe here because every edit settles synchronously: applied
 * work is already IN the canonical row, and parked work was reverted from
 * the local copy when its disposition arrived).
 *
 * Retry is part of the protocol: the engine voids proposals whose base
 * version aged out of the bounded snapshot window (`unknown-base-version`)
 * and expects the client to re-propose against a fresher base. Without
 * modeling that, a laggy client would show phantom lost work. The profile
 * queues such edits and re-submits them — coalesced into ONE proposal,
 * like the adapter's single in-flight proposal — as a follow-up ingest
 * right after the next read hands the client a fresh base. One retry per
 * edit: a second stale-base void parks the edit instead.
 *
 * Dispositions map to the shared accounting: `applied` (merged, canonical
 * advanced), `escalated` (parked for human decision — the author keeps
 * their local copy in production; the harness reverts it so later
 * proposals stay clean and the oracle can assert it stayed OUT of the
 * canonical), `voided` (stale base → retry; anything else is lost work).
 *
 * Quality is scored with an oracle matched to the engine's merge
 * semantics: applied tokens appear in the canonical exactly once, parked
 * tokens not at all, block structure intact, each align register equal to
 * the last APPLIED write that actually changed it against its own base
 * (three-way merges preserve untouched registers, so a no-op write must
 * not move the expectation), plus the version lineage: content rows chain
 * v(N)→v(N+1) with no gaps, their version set matches the applied
 * dispositions exactly, and after full catch-up every client's adopted
 * copy equals the materialized canonical.
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_De_RTC_Profile' ) ) {

	/**
	 * Simulated de-rtc proposal clients.
	 */
	class WP_Sync_Bench_De_RTC_Profile implements WP_Sync_Bench_Authoring_Profile {
		/** Void reasons that are NOT lost work for this engine: a stale base
		 * is retried against a fresher one (the protocol's contract), and a
		 * malformed row never carries real content.
		 *
		 * @var string[]
		 */
		const BENIGN_VOID_REASONS = array(
			'unknown-base-version',
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
		 * Per-client local working copy.
		 *
		 * @var string[]
		 */
		private $content = array();

		/**
		 * Per-client base: the version whose canonical content the local
		 * copy incorporates.
		 *
		 * @var string[]
		 */
		private $base_version = array();

		/**
		 * In-flight bookkeeping: client => proposalId => edit records.
		 *
		 * @var array<int, array<string, array>>
		 */
		private $pending = array();

		/**
		 * Per-client edits voided at a stale base, awaiting one retry.
		 *
		 * @var array<int, array>
		 */
		private $retry_queue = array();

		/**
		 * Monotonic proposalId counter.
		 *
		 * @var int
		 */
		private $proposal_seq = 0;

		/**
		 * Oracle input: token => final settlement ('applied' or 'parked').
		 *
		 * @var array<string, string>
		 */
		private $expected_texts = array();

		/**
		 * Oracle input: paragraph index => last applied CHANGED align write.
		 *
		 * @var array<int, string|null>
		 */
		private $expected_align = array();

		/**
		 * Lineage: content-row version => its baseVersion, from read rows.
		 *
		 * @var array<string, string>
		 */
		private $row_lineage = array();

		/**
		 * Lineage: applied-disposition version => proposalId.
		 *
		 * @var array<string, string>
		 */
		private $applied_versions = array();

		/**
		 * Content of the highest-version row any client received.
		 *
		 * @var array{seq: int, content: string}|null
		 */
		private $latest_row = null;

		/**
		 * Lineage failures detected while observing (inconsistent rows).
		 *
		 * @var array
		 */
		private $observe_failures = array();

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
			return 'de-rtc';
		}

		/**
		 * Bootstraps each client from the genesis snapshot row (an untimed
		 * join read, like the yjs-server profile's).
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array<int, int> Initial read cursor per client.
		 */
		public function bootstrap( WP_Sync_Engine $engine, string $room ): array {
			$cursors = array();
			for ( $client = 0; $client < $this->client_count; $client++ ) {
				$response = $engine->get_updates_since( $room, $client, 0, array() );
				$latest   = null;
				foreach ( $response['updates'] as $row ) {
					$decoded = json_decode( (string) $row['data'], true );
					if ( is_array( $decoded ) && is_string( $decoded['version'] ?? null ) && is_string( $decoded['content'] ?? null ) ) {
						$latest = $decoded;
					}
				}
				$this->content[ $client ]      = is_array( $latest ) ? (string) $latest['content'] : '';
				$this->base_version[ $client ] = is_array( $latest ) ? (string) $latest['version'] : 'v1';
				$this->pending[ $client ]      = array();
				$this->retry_queue[ $client ]  = array();
				$cursors[ $client ]            = (int) $response['end_cursor'];
			}
			return $cursors;
		}

		/**
		 * De-rtc reads carry no extra context (awareness rides the transport
		 * envelope, not the engine read).
		 *
		 * @return array Read context.
		 */
		public function read_context(): array {
			return array();
		}

		/**
		 * Applies the edit to the client's local copy and proposes the whole
		 * document against the client's base version.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        Workload edit.
		 * @param int   $round_index Round the edit belongs to.
		 * @return array Updates payload.
		 */
		public function author( int $client, array $edit, int $round_index ): array {
			$record                   = $this->build_edit_record( $edit );
			$this->content[ $client ] = $this->apply_record( $this->content[ $client ], $record );

			$proposal_id = 'b' . $round_index . '-' . ( $this->proposal_seq++ );

			$this->pending[ $client ][ $proposal_id ] = array( $record );

			return $this->proposal_updates( $client, $proposal_id );
		}

		/**
		 * Settles the primary proposal's disposition (1:1 with the edit).
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        The workload edit the disposition settles.
		 * @param array $disposition Engine disposition.
		 */
		public function record_disposition( int $client, array $edit, array $disposition ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $edit is tracked via the pending proposal record.
			$this->settle( $client, $disposition );
		}

		/**
		 * Benign-void classification for de-rtc: a stale base is retried,
		 * never lost.
		 *
		 * @param string $reason Void reason.
		 * @return bool True when not lost work.
		 */
		public function is_benign_void( string $reason ): bool {
			return in_array( $reason, self::BENIGN_VOID_REASONS, true );
		}

		/**
		 * Adopts the newest canonical row wholesale and collects lineage.
		 *
		 * Safe adoption: every one of this client's edits has settled by the
		 * time it reads (applied work is in the row; parked and queued work
		 * was already reverted from the local copy), so the canonical IS the
		 * client's correct next base.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 */
		public function observe( int $client, array $response ): void {
			$latest = null;
			foreach ( (array) ( $response['updates'] ?? array() ) as $row ) {
				$decoded = json_decode( (string) $row['data'], true );
				if ( ! is_array( $decoded ) || ! is_string( $decoded['version'] ?? null ) || ! is_string( $decoded['content'] ?? null ) ) {
					continue;
				}
				if ( 'content' === ( $row['type'] ?? '' ) && is_string( $decoded['baseVersion'] ?? null ) ) {
					$version = $decoded['version'];
					if ( isset( $this->row_lineage[ $version ] ) && $this->row_lineage[ $version ] !== $decoded['baseVersion'] ) {
						$this->observe_failures[] = array(
							'check'  => 'lineage',
							'detail' => sprintf( "content row '%s' was delivered with two different base versions ('%s', '%s')", $version, $this->row_lineage[ $version ], $decoded['baseVersion'] ),
						);
					}
					$this->row_lineage[ $version ] = $decoded['baseVersion'];
				}
				$latest = $decoded;

				$seq = (int) ltrim( $decoded['version'], 'v' );
				if ( null === $this->latest_row || $seq > $this->latest_row['seq'] ) {
					$this->latest_row = array(
						'seq'     => $seq,
						'content' => $decoded['content'],
					);
				}
			}
			if ( is_array( $latest ) ) {
				$this->content[ $client ]      = (string) $latest['content'];
				$this->base_version[ $client ] = (string) $latest['version'];
			}
		}

		/**
		 * Re-proposes stale-base-voided edits against the base the client
		 * just observed — ONE coalesced proposal, like the adapter's single
		 * in-flight proposal. A real, timed request the deployed protocol
		 * makes.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response (unused; the
		 *                        adoption already happened in observe()).
		 * @return array|null Proposal updates, or null when nothing queued.
		 */
		public function followup_request( int $client, array $response ): ?array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $response is part of the profile contract.
			if ( empty( $this->retry_queue[ $client ] ) ) {
				return null;
			}

			$records = array();
			foreach ( $this->retry_queue[ $client ] as $record ) {
				// apply_record() re-derives the attr baseline (prev_align /
				// changed) against the fresh base the retry authors from.
				$this->content[ $client ] = $this->apply_record( $this->content[ $client ], $record );
				$records[]                = $record;
			}
			$this->retry_queue[ $client ] = array();

			$proposal_id                              = 'retry-' . ( $this->proposal_seq++ );
			$this->pending[ $client ][ $proposal_id ] = $records;

			return $this->proposal_updates( $client, $proposal_id );
		}

		/**
		 * Settles the retry proposal's dispositions.
		 *
		 * @param int            $client Client index that sent the follow-up.
		 * @param array|WP_Error $result handle_updates() result.
		 */
		public function record_followup_result( int $client, $result ): void {
			if ( is_wp_error( $result ) ) {
				// The whole request failed (e.g. lock timeout): park every
				// in-flight retry so the oracle accounts for it — score()
				// reports any token that then leaks into the canonical.
				foreach ( $this->pending[ $client ] as $records ) {
					foreach ( $records as $record ) {
						$this->park( $client, $record );
					}
				}
				$this->pending[ $client ] = array();
				return;
			}
			foreach ( (array) ( $result['dispositions'] ?? array() ) as $disposition ) {
				$this->settle( $client, $disposition );

				/*
				 * A retry is authored against the canonical head the client
				 * observed on the read IMMEDIATELY before it, and the runner
				 * is synchronous — nothing can ingest in between. An applied
				 * retry is therefore always a fast-forward (the new
				 * canonical IS the proposed content), so the client can
				 * soundly advance its base to the applied version — the
				 * adapter's "own unchanged row → version-only advance" rule,
				 * settled from the disposition instead of the row. Without
				 * this, later proposals still author from the pre-retry base
				 * while carrying the retried text, and base→ours vs
				 * base→canonical then insert the SAME text twice — a
				 * duplication window production's row-driven advance closes.
				 */
				if ( 'applied' === ( $disposition['status'] ?? '' ) && is_string( $disposition['version'] ?? null ) ) {
					$this->base_version[ $client ] = $disposition['version'];
				}
			}
		}

		/**
		 * Scores the canonical against the accumulated dispositions and the
		 * version lineage.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array Failures (empty when converged).
		 */
		public function score( WP_Sync_Engine $engine, string $room ): ?array {
			$content = (string) $engine->materialize( $room );
			if ( '' === $content ) {
				return array(
					array(
						'check'  => 'materialize',
						'detail' => 'materialized content is empty',
					),
				);
			}

			$failures = $this->observe_failures;

			// Nothing may still be in flight: an edit that never settled
			// (or a retry that never got its follow-up) is unaccounted work.
			foreach ( $this->pending as $client => $proposals ) {
				if ( array() !== $proposals ) {
					$failures[] = array(
						'check'  => 'unsettled',
						'detail' => sprintf( 'client %d ended the session with %d unsettled proposal(s)', $client, count( $proposals ) ),
					);
				}
			}
			foreach ( $this->retry_queue as $client => $queue ) {
				if ( array() !== $queue ) {
					$failures[] = array(
						'check'  => 'unsettled',
						'detail' => sprintf( 'client %d ended the session with %d edit(s) still queued for retry', $client, count( $queue ) ),
					);
				}
			}

			$blocks = array_values(
				array_filter(
					parse_blocks( $content ),
					static function ( $block ) {
						return 'core/paragraph' === ( $block['blockName'] ?? null );
					}
				)
			);
			if ( count( $blocks ) !== (int) $this->workload['paragraphs'] ) {
				$failures[] = array(
					'check'  => 'structure',
					'detail' => sprintf( 'expected %d paragraph blocks, found %d', (int) $this->workload['paragraphs'], count( $blocks ) ),
				);
			}

			foreach ( $this->expected_texts as $token => $status ) {
				$found = substr_count( $content, $token );
				if ( 'applied' === $status && 1 !== $found ) {
					$failures[] = array(
						'check'  => 'applied-text',
						'detail' => sprintf( "applied token '%s' found %d times (expected exactly 1)", $token, $found ),
					);
				} elseif ( 'parked' === $status && 0 !== $found ) {
					$failures[] = array(
						'check'  => 'parked-text',
						'detail' => sprintf( "parked token '%s' leaked into the canonical (must be set aside, not merged)", $token ),
					);
				}
			}

			foreach ( $this->expected_align as $paragraph => $align ) {
				$actual = $blocks[ $paragraph ]['attrs']['align'] ?? null;
				if ( $actual !== $align ) {
					$failures[] = array(
						'check'  => 'attr-register',
						'detail' => sprintf( "paragraph %d align is '%s', last applied changed write was '%s'", $paragraph, (string) $actual, (string) $align ),
					);
				}
			}

			// Version lineage: content rows chain without gaps from genesis
			// (v1), and their version set matches the applied dispositions.
			foreach ( $this->row_lineage as $version => $base ) {
				$seq = (int) ltrim( (string) $version, 'v' );
				if ( 'v' . ( $seq - 1 ) !== $base ) {
					$failures[] = array(
						'check'  => 'lineage',
						'detail' => sprintf( "content row '%s' does not chain from its predecessor (baseVersion '%s')", $version, $base ),
					);
				}
			}
			$row_versions     = array_keys( $this->row_lineage );
			$applied_versions = array_keys( $this->applied_versions );
			sort( $row_versions );
			sort( $applied_versions );
			if ( $row_versions !== $applied_versions ) {
				$failures[] = array(
					'check'  => 'lineage',
					'detail' => sprintf(
						'content-row versions do not match applied dispositions (%d rows vs %d applied)',
						count( $row_versions ),
						count( $applied_versions )
					),
				);
			}

			// The materialized canonical is the newest broadcast row, and
			// after full catch-up every client adopted exactly it (this is
			// the floored/laggy delivery path check).
			if ( null !== $this->latest_row && $this->latest_row['content'] !== $content ) {
				$failures[] = array(
					'check'  => 'materialize',
					'detail' => 'materialized content differs from the newest broadcast content row',
				);
			}
			foreach ( $this->content as $client => $client_content ) {
				if ( $client_content !== $content ) {
					$failures[] = array(
						'check'  => 'client-convergence',
						'detail' => sprintf( 'client %d local copy differs from the canonical after full catch-up', $client ),
					);
				}
			}

			return $failures;
		}

		/**
		 * Settles one disposition against its pending proposal's records.
		 *
		 * @param int   $client      Client index.
		 * @param array $disposition Engine disposition.
		 */
		private function settle( int $client, array $disposition ): void {
			$proposal_id = (string) ( $disposition['intentId'] ?? '' );
			$records     = $this->pending[ $client ][ $proposal_id ] ?? null;
			if ( null === $records ) {
				return; // Not ours / already settled.
			}
			unset( $this->pending[ $client ][ $proposal_id ] );

			$status = $disposition['status'] ?? 'unknown';
			$reason = (string) ( $disposition['reason'] ?? '' );

			if ( 'applied' === $status ) {
				$version = (string) ( $disposition['version'] ?? '' );
				if ( '' !== $version ) {
					$this->applied_versions[ $version ] = $proposal_id;
				}
				foreach ( $records as $record ) {
					if ( 'text' === $record['op'] ) {
						$this->expected_texts[ $record['token'] ] = 'applied';
					} elseif ( $record['changed'] ) {
						// Three-way merges only move a register the proposal
						// actually changed against its own base; a no-op
						// write must not move the expectation.
						$this->expected_align[ $record['paragraph'] ] = $record['align'];
					}
				}
				return;
			}

			if ( 'voided' === $status && 'unknown-base-version' === $reason ) {
				foreach ( $records as $record ) {
					// Revert from the local copy now; the retry re-applies
					// against the base the next read hands this client.
					$this->content[ $client ] = $this->revert_record( $this->content[ $client ], $record );
					if ( empty( $record['retried'] ) ) {
						$record['retried']              = true;
						$this->retry_queue[ $client ][] = $record;
					} else {
						// One retry per edit: park a second stale-base void.
						$this->park_settled( $record );
					}
				}
				return;
			}

			// Escalated (parked for human decision) or a non-benign void
			// (counted as lost work by the runner): either way the canonical
			// must not contain it, and later proposals must not re-send it.
			foreach ( $records as $record ) {
				$this->park( $client, $record );
			}
		}

		/**
		 * Parks an edit: reverts it from the client's local copy and records
		 * the expectation that it stayed out of the canonical.
		 *
		 * @param int   $client Client index.
		 * @param array $record Edit record.
		 */
		private function park( int $client, array $record ): void {
			$this->content[ $client ] = $this->revert_record( $this->content[ $client ], $record );
			$this->park_settled( $record );
		}

		/**
		 * Records a parked edit's oracle expectation (local copy untouched).
		 *
		 * @param array $record Edit record.
		 */
		private function park_settled( array $record ): void {
			if ( 'text' === $record['op'] ) {
				$this->expected_texts[ $record['token'] ] = 'parked';
			}
			// A parked attr write leaves the register where it was: no
			// expectation to move.
		}

		/**
		 * Builds the internal record for a workload edit.
		 *
		 * @param array $edit Workload edit.
		 * @return array Edit record.
		 */
		private function build_edit_record( array $edit ): array {
			if ( 'attr' === ( $edit['op'] ?? 'text' ) ) {
				return array(
					'op'         => 'attr',
					'paragraph'  => (int) $edit['paragraph'],
					'align'      => (string) $edit['align'],
					'prev_align' => null,  // Captured by apply_record().
					'changed'    => true,  // Recomputed by apply_record().
					'retried'    => false,
				);
			}
			return array(
				'op'        => 'text',
				'paragraph' => (int) $edit['paragraph'],
				'token'     => (string) $edit['text'],
				'retried'   => false,
			);
		}

		/**
		 * Applies an edit record to serialized block content. Attr records
		 * capture their baseline (prev_align / changed) in the same pass, by
		 * reference into the record the caller stores.
		 *
		 * @param string $content Serialized blocks.
		 * @param array  $record  Edit record (modified: attr baseline).
		 * @return string Updated serialized blocks.
		 */
		private function apply_record( string $content, array &$record ): string {
			$op = $record['op'];
			return $this->with_paragraph(
				$content,
				$record['paragraph'],
				function ( array $block ) use ( $op, &$record ): array {
					if ( 'attr' === $op ) {
						$record['prev_align']    = $block['attrs']['align'] ?? null;
						$record['changed']       = ( $record['prev_align'] !== $record['align'] );
						$block['attrs']['align'] = $record['align'];
						return $block;
					}
					return $this->insert_paragraph_text( $block, $record['token'] );
				}
			);
		}

		/**
		 * Reverts an edit record from serialized block content.
		 *
		 * @param string $content Serialized blocks.
		 * @param array  $record  Edit record.
		 * @return string Updated serialized blocks.
		 */
		private function revert_record( string $content, array $record ): string {
			if ( 'text' === $record['op'] ) {
				// Tokens are unique and delimiter-terminated (the workload
				// generator's guarantee), so plain removal is exact.
				return str_replace( $record['token'], '', $content );
			}
			$prev = $record['prev_align'];
			return $this->with_paragraph(
				$content,
				$record['paragraph'],
				static function ( array $block ) use ( $prev ): array {
					if ( null === $prev ) {
						unset( $block['attrs']['align'] );
					} else {
						$block['attrs']['align'] = $prev;
					}
					return $block;
				}
			);
		}

		/**
		 * Rewrites the Nth core/paragraph block through a callback.
		 *
		 * @param string   $content         Serialized blocks.
		 * @param int      $paragraph_index Index among core/paragraph blocks.
		 * @param callable $callback        Receives and returns the block.
		 * @return string Updated serialized blocks.
		 */
		private function with_paragraph( string $content, int $paragraph_index, callable $callback ): string {
			$blocks = parse_blocks( $content );
			$seen   = -1;
			foreach ( $blocks as $i => $block ) {
				if ( 'core/paragraph' !== ( $block['blockName'] ?? null ) ) {
					continue;
				}
				++$seen;
				if ( $seen === $paragraph_index ) {
					$blocks[ $i ] = $callback( $block );
					break;
				}
			}
			return serialize_blocks( $blocks );
		}

		/**
		 * Inserts a token at offset 0 of a paragraph's content field (right
		 * after the opening tag — the same edit the intent-log profile's
		 * insert_text at offset 0 makes).
		 *
		 * @param array  $block Paragraph block.
		 * @param string $token Unique token.
		 * @return array Updated block.
		 */
		private function insert_paragraph_text( array $block, string $token ): array {
			$html = (string) $block['innerHTML'];
			$pos  = strpos( $html, '>' );
			if ( false !== $pos ) {
				$html = substr( $html, 0, $pos + 1 ) . $token . substr( $html, $pos + 1 );
			} else {
				$html .= $token;
			}
			$block['innerHTML']    = $html;
			$block['innerContent'] = array( $html );
			return $block;
		}

		/**
		 * Builds the proposal updates array for a pending proposal.
		 *
		 * @param int    $client      Client index.
		 * @param string $proposal_id Proposal id.
		 * @return array Updates payload.
		 */
		private function proposal_updates( int $client, string $proposal_id ): array {
			return array(
				array(
					'type' => WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL,
					'data' => wp_json_encode(
						array(
							'proposalId'      => $proposal_id,
							'baseVersion'     => $this->base_version[ $client ],
							'proposedContent' => $this->content[ $client ],
							'clientUpdate'    => null,
						)
					),
				),
			);
		}
	}
}
