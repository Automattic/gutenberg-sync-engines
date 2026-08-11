<?php
/**
 * Sync-engine benchmark runner — drives the production seam.
 *
 * For each round, every active editor authors its edits from the state it
 * observed at its own LAST read (concurrent authorship; a laggy client's
 * base can be many rounds stale), the runner submits each as one
 * `handle_updates` request (the benchmarked server operation), then every
 * editor whose poll is due reads with `get_updates_since` and advances.
 * After the session, every client catches up and then polls the idle room
 * (the steady-state request in a live deployment). Reads and idle polls
 * are timed separately from ingest. This is exactly the polling
 * transport's call pattern, so the numbers are the real engine's — only
 * storage is in-memory (see the memory storage's note).
 *
 * COST is per-request service time, request/response payload bytes, and
 * stored row/byte growth. QUALITY is policy-correct: the intent log reports
 * how every submitted edit settled — merged (applied), preserved for review
 * (escalated), or a benign idempotent/stale void — and asserts NO edit was
 * lost. That inverts the old DE-RTC harness's "silent-merge retention"
 * score, which rewarded exactly the last-write-wins behaviour this project
 * rejects. The yjs relay does its merge on the client, so the server cannot
 * observe quality here — reported honestly as unavailable, not faked.
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_Runner' ) ) {

	/**
	 * Runs one workload against one engine and reports cost + quality.
	 */
	class WP_Sync_Bench_Runner {
		/** Void reasons that are NOT lost work: idempotent convergence, a
		 * compacted-away base, or a malformed row (never real content).
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

		/** Idle polls per client after the session (the steady-state read). */
		const IDLE_POLLS_PER_CLIENT = 25;

		/**
		 * Runs the workload and returns a report array.
		 *
		 * @param WP_Sync_Engine               $engine  Engine under test.
		 * @param WP_Sync_Bench_Memory_Storage $storage The engine's storage.
		 * @param int                          $post_id Seeded post (room target).
		 * @param array                        $workload Workload from the generator.
		 * @return array Report.
		 */
		public static function run( WP_Sync_Engine $engine, WP_Sync_Bench_Memory_Storage $storage, int $post_id, array $workload ): array {
			$room      = 'postType/post:' . $post_id;
			$slug      = $engine->get_slug();
			$is_intent = 'intent-log' === $slug;
			// yjs-server gets a REAL-Yjs authoring profile: each simulated
			// client holds a y-php document and submits genuine incremental
			// V2 updates, so payload/storage bytes are real and quality is
			// server-observable (see verify_crdt_convergence). Every other
			// non-intent engine keeps the opaque-relay profile.
			$is_yjs = 'yjs-server' === $slug;
			if ( $is_yjs ) {
				require_once dirname( __DIR__, 2 ) . '/includes/lib/y-php-loader.php';
				gutenberg_sync_engines_load_y_php();
			}

			// Prime genesis (a read at cursor 0 initializes the room) and note
			// the starting head. The intent log's head is the log length; the
			// relay has no sequence, so clients just track their read cursor.
			$engine->get_updates_since( $room, 999, 0, array() );
			$paragraph_ids = array();
			for ( $i = 0; $i < $workload['paragraphs']; $i++ ) {
				$paragraph_ids[] = WP_Intent_Log_Planner::genesis_sync_id( $post_id, 0, array( $i ) );
			}

			$client_count = max( 1, (int) $workload['clients'] );
			$read_cursor  = array_fill( 0, $client_count, 0 );  // Storage cursor each client has consumed.

			// yjs-server: bootstrap each client's document from the genesis
			// snapshot (an untimed setup read — the intent-log profile has no
			// equivalent timed join read either). Deterministic clientIDs keep
			// counted metrics identical across repetitions.
			$ydocs = array();
			if ( $is_yjs ) {
				for ( $client = 0; $client < $client_count; $client++ ) {
					$doc           = new \Yjs\Utils\Doc();
					$doc->clientID = 1000 + $client;
					$bootstrap     = $engine->get_updates_since( $room, $client, 0, array() );
					self::apply_yjs_rows( $doc, $bootstrap['updates'] );
					$read_cursor[ $client ] = (int) $bootstrap['end_cursor'];
					$ydocs[ $client ]       = $doc;
				}
			}

			// Relay reads carry an awareness roster so the engine can nominate
			// a compactor (the lowest client id) — in production this is the
			// session presence list. The runner then plays the compactor's
			// part: when a read answers should_compact, it submits a synthetic
			// full-state snapshot at its cursor, which is what a real Yjs
			// client does past the threshold. Without this, relay storage
			// growth measures a session with no live clients — not the
			// deployed system.
			$awareness_ctx   = $is_intent
				? array()
				: array( 'awareness' => array_fill_keys( range( 0, $client_count - 1 ), array() ) );
			$relay_doc_bytes = strlen( (string) $workload['post_content'] );
			$compactions     = 0;

			// The intent-log head is the number of APPLIED intents (base_seq
			// is 0 in these runs). Every editor authors from the state it
			// OBSERVED at its own last read — concurrent authorship, so a
			// same-register collision escalates the later writer, and a
			// client that reads rarely (workload `read_every` > 1) authors
			// from bases up to that many rounds stale. The per-paragraph
			// alignment version is tracked the same way (a versioned
			// register), so concurrent restyles collide.
			$head              = 0;
			$attr_version      = array_fill( 0, max( 1, (int) $workload['paragraphs'] ), 0 );
			$observed_head     = array_fill( 0, $client_count, 0 );
			$observed_versions = array_fill( 0, $client_count, $attr_version );
			$read_every        = (array) ( $workload['read_every'] ?? array() );

			$service_us   = array();
			$read_us      = array();
			$idle_poll_us = array();
			$request_b    = array();
			$response_b   = array();
			$dispositions = array(
				'applied'   => 0,
				'escalated' => 0,
				'voided'    => 0,
				'unknown'   => 0,
			);
			$lost_work    = array();
			$intent_seq   = 0;

			// Convergence oracle inputs: what the engine SAID it did with each
			// edit (its disposition), checked later against what the
			// materialized document actually contains.
			$expected_texts = array();
			$expected_align = array();

			foreach ( $workload['rounds'] as $round_index => $edits ) {
				$active = array();

				foreach ( $edits as $edit ) {
					$client            = (int) $edit['client'];
					$active[ $client ] = true;

					if ( $is_intent ) {
						$paragraph = (int) $edit['paragraph'];
						if ( 'attr' === ( $edit['op'] ?? 'text' ) ) {
							$payload = array(
								'type'    => 'set_attr',
								'payload' => array(
									'syncId'          => $paragraph_ids[ $paragraph ],
									'key'             => 'align',
									'value'           => $edit['align'],
									'observedVersion' => $observed_versions[ $client ][ $paragraph ],
								),
							);
						} else {
							$payload = array(
								'type'    => 'insert_text',
								'payload' => array(
									'syncId' => $paragraph_ids[ $paragraph ],
									'field'  => 'content',
									'offset' => 0,
									'text'   => $edit['text'],
								),
							);
						}
						$updates = array(
							array(
								'type' => WP_Intent_Log_Engine::UPDATE_TYPE_INTENT,
								'data' => wp_json_encode(
									array_merge(
										array(
											'intentId' => 'b' . $round_index . '-' . ( $intent_seq++ ),
											'baseSeq'  => $observed_head[ $client ],
											'txnId'    => null,
										),
										$payload
									)
								),
							),
						);
					} elseif ( $is_yjs ) {
						// Real-Yjs profile: the edit happens in the client's
						// own CRDT document, and the submitted update is the
						// genuine incremental V2 encoding of it (everything
						// past the pre-edit state vector) — exactly what the
						// editor's session codec sends. Authoring is untimed;
						// only the server call below is measured.
						$doc       = $ydocs[ $client ];
						$sv_before = \Yjs\encodeStateVector( $doc );
						$paragraph = (int) $edit['paragraph'];
						$block     = $doc->getMap( 'document' )->get( 'blocks' )->get( $paragraph );
						if ( 'attr' === ( $edit['op'] ?? 'text' ) ) {
							$block->get( 'attributes' )->set( 'align', $edit['align'] );
						} else {
							$block->get( 'attributes' )->get( 'content' )->insert( 0, $edit['text'] );
						}
						$updates = array(
							array(
								'type' => 'update',
								'data' => \Yjs\encodeStateAsUpdateV2( $doc, $sv_before )->toBase64(),
							),
						);
					} else {
						// Opaque-relay profile (yjs-relay and any engine
						// without a dedicated authoring profile): an opaque
						// client-computed update of comparable size (a real
						// yjs update for a few inserted chars). The literal
						// 'update'/'compaction' types are the relay
						// convention; an engine that rejects them will show
						// it in the dispositions/storage counts.
						$updates = array(
							array(
								'type' => 'update',
								'data' => base64_encode( 'yjs-update:' . $edit['text'] . str_repeat( "\x01", 24 ) ),
							),
						);
						// Every edit lands in the client CRDT, so the eventual
						// compaction snapshot grows with the document.
						$relay_doc_bytes += strlen( (string) $edit['text'] );
					}

					$request_b[] = strlen( (string) wp_json_encode( $updates ) );

					// hrtime: monotonic, ns resolution — microtime() is neither,
					// and the relay's per-request cost sits near µs scale.
					$start        = hrtime( true );
					$result       = $engine->handle_updates( $room, $client, $read_cursor[ $client ], $updates, array() );
					$service_us[] = ( hrtime( true ) - $start ) / 1e3;

					if ( is_wp_error( $result ) ) {
						$lost_work[] = array(
							'round'  => $round_index,
							'client' => $client,
							'error'  => $result->get_error_code(),
						);
						continue;
					}

					foreach ( (array) ( $result['dispositions'] ?? array() ) as $disposition ) {
						$status = $disposition['status'] ?? 'unknown';
						if ( isset( $dispositions[ $status ] ) ) {
							++$dispositions[ $status ];
						}
						if ( 'applied' === $status ) {
							++$head; // A new log entry: the head advances.
							if ( $is_intent && 'attr' === ( $edit['op'] ?? 'text' ) ) {
								++$attr_version[ (int) $edit['paragraph'] ];
							}
						}
						if ( 'voided' === $status && ! in_array( $disposition['reason'] ?? '', self::BENIGN_VOID_REASONS, true ) ) {
							$lost_work[] = $disposition;
						}
						if ( $is_intent ) {
							// Each request carries exactly one update, so this
							// disposition settles the edit just submitted.
							if ( 'attr' === ( $edit['op'] ?? 'text' ) ) {
								if ( 'applied' === $status ) {
									// Ingest is serialized, so processing order
									// IS server order: last applied write wins.
									$expected_align[ (int) $edit['paragraph'] ] = $edit['align'];
								}
							} else {
								$expected_texts[] = array(
									'text'   => (string) $edit['text'],
									'status' => $status,
								);
							}
						} elseif ( $is_yjs && 'attr' !== ( $edit['op'] ?? 'text' ) ) {
							// Text merges are lossless under CRDT rules, so
							// every applied token must appear in the
							// materialized document. Attribute registers
							// resolve by CRDT conflict rules (NOT server
							// order), so their oracle is the converged client
							// documents, checked after the session.
							$expected_texts[] = array(
								'text'   => (string) $edit['text'],
								'status' => $status,
							);
						}
					}
				}

				// Every active editor whose poll is due reads and advances its
				// cursor and observed state; a laggy client skips and keeps
				// authoring from its stale base.
				foreach ( array_keys( $active ) as $client ) {
					$every = max( 1, (int) ( $read_every[ $client ] ?? 1 ) );
					if ( 0 !== ( ( $round_index + 1 ) % $every ) ) {
						continue;
					}

					$start                  = hrtime( true );
					$response               = $engine->get_updates_since( $room, $client, $read_cursor[ $client ], $awareness_ctx );
					$read_us[]              = ( hrtime( true ) - $start ) / 1e3;
					$response_b[]           = strlen( (string) wp_json_encode( $response['updates'] ?? array() ) );
					$read_cursor[ $client ] = (int) ( $response['end_cursor'] ?? $read_cursor[ $client ] );

					// The read is what a client observes: it now authors
					// against the server head as of this point.
					$observed_head[ $client ]     = $head;
					$observed_versions[ $client ] = $attr_version;
					if ( $is_yjs ) {
						// Applying the delivered rows into the client CRDT is
						// client work — untimed, like authoring.
						self::apply_yjs_rows( $ydocs[ $client ], $response['updates'] ?? array() );
					}

					// The nominated relay client answers should_compact with a
					// full-state snapshot at its cursor — a real, timed request
					// the deployed protocol makes (compaction is not free).
					if ( ! $is_intent && ! $is_yjs && ! empty( $response['should_compact'] ) ) {
						$compaction   = array(
							array(
								'type' => 'compaction',
								'data' => base64_encode( 'yjs-compaction:' . str_repeat( "\x01", $relay_doc_bytes ) ),
							),
						);
						$request_b[]  = strlen( (string) wp_json_encode( $compaction ) );
						$start        = hrtime( true );
						$result       = $engine->handle_updates( $room, $client, $read_cursor[ $client ], $compaction, array() );
						$service_us[] = ( hrtime( true ) - $start ) / 1e3;
						if ( ! is_wp_error( $result ) ) {
							++$compactions;
						}
					}
				}
			}

			// Session end: every client catches up (the laggy client's backlog
			// read is a real, potentially heavy request), then each client
			// polls the idle room a few times — in a live deployment idle
			// polls are the DOMINANT request type, so their cost is reported
			// on its own.
			for ( $client = 0; $client < $client_count; $client++ ) {
				$start                  = hrtime( true );
				$response               = $engine->get_updates_since( $room, $client, $read_cursor[ $client ], $awareness_ctx );
				$read_us[]              = ( hrtime( true ) - $start ) / 1e3;
				$response_b[]           = strlen( (string) wp_json_encode( $response['updates'] ?? array() ) );
				$read_cursor[ $client ] = (int) ( $response['end_cursor'] ?? $read_cursor[ $client ] );
				if ( $is_yjs ) {
					self::apply_yjs_rows( $ydocs[ $client ], $response['updates'] ?? array() );
				}
			}
			for ( $i = 0; $i < self::IDLE_POLLS_PER_CLIENT; $i++ ) {
				for ( $client = 0; $client < $client_count; $client++ ) {
					$start = hrtime( true );
					$engine->get_updates_since( $room, $client, $read_cursor[ $client ], $awareness_ctx );
					$idle_poll_us[] = ( hrtime( true ) - $start ) / 1e3;
				}
			}

			// Convergence: the materialized document must MATCH the engine's
			// own account of the session — every applied edit's unique token
			// present exactly once, no escalated edit leaked into content,
			// block structure intact, final attribute values equal to the
			// last applied write. (Intent log only; the relay needs a client
			// CRDT the server does not have.)
			$converged            = null;
			$convergence_failures = array();
			if ( $is_intent ) {
				$server_content       = (string) $engine->materialize( $room );
				$convergence_failures = self::verify_convergence(
					$server_content,
					(int) $workload['paragraphs'],
					$expected_texts,
					$expected_align
				);
				$converged            = array() === $convergence_failures;
			} elseif ( $is_yjs ) {
				$server_content       = (string) $engine->materialize( $room );
				$convergence_failures = self::verify_crdt_convergence(
					$server_content,
					(int) $workload['paragraphs'],
					$expected_texts,
					$ydocs
				);
				$converged            = array() === $convergence_failures;
			}

			$total_edits = count( $service_us );
			$profile     = 'opaque-relay';
			if ( $is_intent ) {
				$profile = 'intent-log';
			} elseif ( $is_yjs ) {
				$profile = 'yjs-server';
			}
			return array(
				'engine'              => $slug,
				// Authoring profile: how the runner speaks to the engine.
				// Engines without a dedicated profile get the relay-style
				// opaque updates and unobservable quality.
				'profile'             => $profile,
				'scenario'            => $workload['scenario'],
				'rounds'              => count( $workload['rounds'] ),
				'clients'             => $client_count,
				'requests'            => $total_edits,
				'service_us'          => self::summary( $service_us ),
				'read_us'             => self::summary( $read_us ),
				'idle_poll_us'        => self::summary( $idle_poll_us ),
				// Raw µs series, for cross-repetition aggregation by the CLI.
				'service_us_series'   => $service_us,
				'read_us_series'      => $read_us,
				'idle_poll_us_series' => $idle_poll_us,
				'payload_bytes'       => array(
					'request_p50'  => self::percentile( $request_b, 0.5 ),
					'request_max'  => empty( $request_b ) ? 0 : max( $request_b ),
					'response_p50' => self::percentile( $response_b, 0.5 ),
					'response_max' => empty( $response_b ) ? 0 : max( $response_b ),
				),
				'storage'             => array(
					'rows'        => $storage->get_update_count( $room ),
					'bytes'       => $storage->stored_bytes( $room ),
					'compactions' => $compactions,
				),
				'quality'             => array(
					'observable'           => $is_intent || $is_yjs,
					'converged'            => $converged,
					'convergence_failures' => array_slice( $convergence_failures, 0, 5 ),
					'dispositions'         => $dispositions,
					'escalation_rate'      => $total_edits > 0
						? round( $dispositions['escalated'] / $total_edits, 4 )
						: 0.0,
					'lost_work'            => count( $lost_work ),
					'lost_detail'          => array_slice( $lost_work, 0, 5 ),
				),
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

		/**
		 * Applies a room response's rows to a client-side y-php document
		 * (snapshot rows carry `{ doc: <base64 V2> }`; update rows carry the
		 * base64 V2 update directly).
		 *
		 * @param \Yjs\Utils\Doc $doc  Client document.
		 * @param array           $rows Typed rows from get_updates_since().
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
				$yblocks = $reference->getMap( 'document' )->get( 'blocks' );
				for ( $i = 0; $i < min( $paragraph_count, count( $blocks ) ); $i++ ) {
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

		/**
		 * p50/p90/p99/max/mean of a microsecond series (reported in ms).
		 *
		 * Public so the CLI can aggregate series across repetitions.
		 *
		 * @param float[] $series Microsecond samples.
		 * @return array Summary in milliseconds.
		 */
		public static function summary( array $series ): array {
			if ( empty( $series ) ) {
				return array(
					'p50'  => 0,
					'p90'  => 0,
					'p99'  => 0,
					'max'  => 0,
					'mean' => 0,
				);
			}
			sort( $series );
			return array(
				'p50'  => round( self::percentile( $series, 0.5 ) / 1000, 4 ),
				'p90'  => round( self::percentile( $series, 0.9 ) / 1000, 4 ),
				'p99'  => round( self::percentile( $series, 0.99 ) / 1000, 4 ),
				'max'  => round( end( $series ) / 1000, 4 ),
				'mean' => round( array_sum( $series ) / count( $series ) / 1000, 4 ),
			);
		}

		/**
		 * A percentile of a numeric series.
		 *
		 * @param array $series   Numeric series.
		 * @param float $fraction Percentile fraction.
		 * @return float Percentile value.
		 */
		private static function percentile( array $series, float $fraction ): float {
			if ( empty( $series ) ) {
				return 0.0;
			}
			sort( $series );
			$index = (int) floor( $fraction * ( count( $series ) - 1 ) );
			return (float) $series[ $index ];
		}
	}
}
