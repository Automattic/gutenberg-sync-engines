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
 * The runner itself is ENGINE-NEUTRAL: everything engine-specific — how
 * the workload's abstract edits become wire updates, the client's part
 * between requests, void classification, and the quality oracle — lives in
 * the engine's authoring profile (WP_Sync_Bench_Authoring_Profile,
 * resolved by slug through WP_Sync_Bench_Profiles). The runner times
 * whatever the profile hands it and feeds every response back.
 *
 * COST is per-request service time, request/response payload bytes, and
 * stored row/byte growth. QUALITY is policy-correct and engine-matched:
 * a server-merging engine reports how every submitted edit settled —
 * merged (applied), preserved for review (escalated), or a benign void —
 * and asserts NO edit was lost. That inverts the old DE-RTC harness's
 * "silent-merge retention" score, which rewarded exactly the
 * last-write-wins behaviour this project rejects. An engine that merges on
 * the client (the retired yjs-relay did; the opaque fallback profile
 * models one) gives the server nothing to observe — quality is reported
 * honestly as unavailable, not faked.
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_Runner' ) ) {
	require_once __DIR__ . '/class-wp-sync-bench-profiles.php';

	/**
	 * Runs one workload against one engine and reports cost + quality.
	 */
	class WP_Sync_Bench_Runner {
		/** Idle polls per client after the session (the steady-state read). */
		const IDLE_POLLS_PER_CLIENT = 25;

		/** Cold materialize() samples after the session (the save path). */
		const MATERIALIZE_SAMPLES = 5;

		/**
		 * Runs the workload and returns a report array.
		 *
		 * @param WP_Sync_Engine                       $engine   Engine under test.
		 * @param WP_Sync_Bench_Memory_Storage         $storage  The engine's storage.
		 * @param int                                  $post_id  Seeded post (room target).
		 * @param array                                $workload Workload from the generator.
		 * @param WP_Sync_Bench_Authoring_Profile|null $profile  Authoring profile; resolved
		 *                                                       from the engine slug when null.
		 * @return array Report.
		 */
		public static function run( WP_Sync_Engine $engine, WP_Sync_Bench_Memory_Storage $storage, int $post_id, array $workload, ?WP_Sync_Bench_Authoring_Profile $profile = null ): array {
			$room = 'postType/post:' . $post_id;
			$slug = $engine->get_slug();
			if ( null === $profile ) {
				$profile = WP_Sync_Bench_Profiles::for_engine( $slug, $post_id, $workload );
			}

			// Prime genesis (a read at cursor 0 initializes the room), then
			// let the profile build its per-client state — including any
			// untimed join reads (e.g. bootstrapping client CRDT documents
			// from the genesis snapshot).
			$engine->get_updates_since( $room, 999, 0, array() );

			$client_count = max( 1, (int) $workload['clients'] );
			$read_cursor  = $profile->bootstrap( $engine, $room );
			for ( $client = 0; $client < $client_count; $client++ ) {
				$read_cursor[ $client ] = (int) ( $read_cursor[ $client ] ?? 0 );
			}

			$read_context = $profile->read_context();
			$read_every   = (array) ( $workload['read_every'] ?? array() );
			$followups    = 0;

			// Peak PHP memory an ingest allocates on top of the baseline —
			// the number a constrained PHP-FPM pool actually OOMs on.
			// Needs memory_reset_peak_usage() (PHP 8.2+); null otherwise.
			$track_memory = function_exists( 'memory_reset_peak_usage' );
			$ingest_peak  = null;

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

			// Counts a handle_updates() result's dispositions into the shared
			// tallies (per-status counts; non-benign voids are lost work).
			$count_dispositions = static function ( $result ) use ( $profile, &$dispositions, &$lost_work ): void {
				foreach ( (array) ( $result['dispositions'] ?? array() ) as $disposition ) {
					$status = $disposition['status'] ?? 'unknown';
					if ( isset( $dispositions[ $status ] ) ) {
						++$dispositions[ $status ];
					}
					if ( 'voided' === $status && ! $profile->is_benign_void( (string) ( $disposition['reason'] ?? '' ) ) ) {
						$lost_work[] = $disposition;
					}
				}
			};

			// A follow-up ingest the client protocol requires after a read
			// (a nominated relay compactor's snapshot; a proposal client's
			// retry at the base it just observed) — a real, timed request
			// the deployed protocol makes, with its dispositions counted
			// like any other ingest.
			$submit_followup = static function ( int $client, array $response ) use ( $engine, $room, $profile, &$read_cursor, &$request_b, &$service_us, &$followups, $count_dispositions, $track_memory, &$ingest_peak ): void {
				$followup = $profile->followup_request( $client, $response );
				if ( null === $followup ) {
					return;
				}
				$request_b[] = strlen( (string) wp_json_encode( $followup ) );
				$mem_before  = 0;
				if ( $track_memory ) {
					memory_reset_peak_usage();
					$mem_before = memory_get_usage();
				}
				$start        = hrtime( true );
				$result       = $engine->handle_updates( $room, $client, $read_cursor[ $client ], $followup, array() );
				$service_us[] = ( hrtime( true ) - $start ) / 1e3;
				if ( $track_memory ) {
					$ingest_peak = max( (int) $ingest_peak, memory_get_peak_usage() - $mem_before );
				}
				if ( ! is_wp_error( $result ) ) {
					++$followups;
					$count_dispositions( $result );
				}
				$profile->record_followup_result( $client, $result );
			};

			foreach ( $workload['rounds'] as $round_index => $edits ) {
				$active = array();

				foreach ( $edits as $edit ) {
					$client            = (int) $edit['client'];
					$active[ $client ] = true;

					// Authoring is client work — untimed; only the server
					// call below is measured.
					$updates     = $profile->author( $client, $edit, $round_index );
					$request_b[] = strlen( (string) wp_json_encode( $updates ) );

					$mem_before = 0;
					if ( $track_memory ) {
						memory_reset_peak_usage();
						$mem_before = memory_get_usage();
					}
					// hrtime: monotonic, ns resolution — microtime() is neither,
					// and a relay's per-request cost sits near µs scale.
					$start        = hrtime( true );
					$result       = $engine->handle_updates( $room, $client, $read_cursor[ $client ], $updates, array() );
					$service_us[] = ( hrtime( true ) - $start ) / 1e3;
					if ( $track_memory ) {
						$ingest_peak = max( (int) $ingest_peak, memory_get_peak_usage() - $mem_before );
					}

					if ( is_wp_error( $result ) ) {
						$lost_work[] = array(
							'round'  => $round_index,
							'client' => $client,
							'error'  => $result->get_error_code(),
						);
						continue;
					}

					$count_dispositions( $result );
					foreach ( (array) ( $result['dispositions'] ?? array() ) as $disposition ) {
						// Each request carries exactly one update, so this
						// disposition settles the edit just submitted; the
						// profile tracks its oracle expectations from it.
						$profile->record_disposition( $client, $edit, $disposition );
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
					$response               = $engine->get_updates_since( $room, $client, $read_cursor[ $client ], $read_context );
					$read_us[]              = ( hrtime( true ) - $start ) / 1e3;
					$response_b[]           = strlen( (string) wp_json_encode( $response['updates'] ?? array() ) );
					$read_cursor[ $client ] = (int) ( $response['end_cursor'] ?? $read_cursor[ $client ] );

					// The read is what the client observes: applying it is
					// client work — untimed, like authoring.
					$profile->observe( $client, $response );

					$submit_followup( $client, $response );
				}
			}

			// Session end: every client catches up (the laggy client's backlog
			// read is a real, potentially heavy request), then each client
			// polls the idle room a few times — in a live deployment idle
			// polls are the DOMINANT request type, so their cost is reported
			// on its own.
			for ( $client = 0; $client < $client_count; $client++ ) {
				$start                  = hrtime( true );
				$response               = $engine->get_updates_since( $room, $client, $read_cursor[ $client ], $read_context );
				$read_us[]              = ( hrtime( true ) - $start ) / 1e3;
				$response_b[]           = strlen( (string) wp_json_encode( $response['updates'] ?? array() ) );
				$read_cursor[ $client ] = (int) ( $response['end_cursor'] ?? $read_cursor[ $client ] );
				$profile->observe( $client, $response );
				// The catch-up read also answers protocol follow-ups, so a
				// retry queued near session end still settles before scoring.
				$submit_followup( $client, $response );
			}
			for ( $i = 0; $i < self::IDLE_POLLS_PER_CLIENT; $i++ ) {
				for ( $client = 0; $client < $client_count; $client++ ) {
					$start = hrtime( true );
					$engine->get_updates_since( $room, $client, $read_cursor[ $client ], $read_context );
					$idle_poll_us[] = ( hrtime( true ) - $start ) / 1e3;
				}
			}

			// Later-joiner cost: a COLD read at cursor 0 by a client that was
			// never in the session — what a fresh visitor pays to enter the
			// room after this much history (snapshot + tail, per the engine's
			// retention). Server cost only; nothing is applied client-side.
			$join_us = array();
			$join_b  = array();
			for ( $client = 0; $client < $client_count; $client++ ) {
				$start     = hrtime( true );
				$response  = $engine->get_updates_since( $room, 5000 + $client, 0, $read_context );
				$join_us[] = ( hrtime( true ) - $start ) / 1e3;
				$join_b[]  = strlen( (string) wp_json_encode( $response['updates'] ?? array() ) );
			}

			// Save-path cost: materialize() on a FRESH engine instance per
			// sample (a save request starts with no per-request room cache),
			// timed with its peak memory. Engines without the materialize
			// convention (an opaque relay has no document) report null.
			$materialize_us   = array();
			$materialize_peak = null;
			if ( method_exists( $engine, 'materialize' ) ) {
				$engine_class = get_class( $engine );
				for ( $i = 0; $i < self::MATERIALIZE_SAMPLES; $i++ ) {
					$cold       = new $engine_class( $storage );
					$mem_before = 0;
					if ( $track_memory ) {
						memory_reset_peak_usage();
						$mem_before = memory_get_usage();
					}
					$start = hrtime( true );
					$cold->materialize( $room );
					$materialize_us[] = ( hrtime( true ) - $start ) / 1e3;
					if ( $track_memory ) {
						$materialize_peak = max( (int) $materialize_peak, memory_get_peak_usage() - $mem_before );
					}
				}
			}

			// Quality: the profile scores with an oracle matched to the
			// engine's merge semantics, or answers null when the server side
			// has nothing to observe (reported honestly, never faked).
			$convergence_failures = $profile->score( $engine, $room );
			$observable           = null !== $convergence_failures;
			$converged            = $observable ? array() === $convergence_failures : null;

			$total_edits = count( $service_us );
			return array(
				'engine'                => $slug,
				// Authoring profile: how the runner spoke to the engine.
				// Engines without a dedicated profile get the relay-style
				// opaque updates and unobservable quality.
				'profile'               => $profile->name(),
				'scenario'              => $workload['scenario'],
				'rounds'                => count( $workload['rounds'] ),
				'clients'               => $client_count,
				'requests'              => $total_edits,
				'service_us'            => self::summary( $service_us ),
				'read_us'               => self::summary( $read_us ),
				'idle_poll_us'          => self::summary( $idle_poll_us ),
				'join_us'               => self::summary( $join_us ),
				'materialize_us'        => empty( $materialize_us ) ? null : self::summary( $materialize_us ),
				// Raw µs series, for cross-repetition aggregation by the CLI.
				'service_us_series'     => $service_us,
				'read_us_series'        => $read_us,
				'idle_poll_us_series'   => $idle_poll_us,
				'join_us_series'        => $join_us,
				'materialize_us_series' => $materialize_us,
				'payload_bytes'         => array(
					'request_p50'       => self::percentile( $request_b, 0.5 ),
					'request_max'       => empty( $request_b ) ? 0 : max( $request_b ),
					'response_p50'      => self::percentile( $response_b, 0.5 ),
					'response_max'      => empty( $response_b ) ? 0 : max( $response_b ),
					// The cold-join response: the payload a fresh visitor
					// downloads to enter the room after this much history.
					'join_response_p50' => self::percentile( $join_b, 0.5 ),
					'join_response_max' => empty( $join_b ) ? 0 : max( $join_b ),
				),
				'storage'               => array(
					'rows'      => $storage->get_update_count( $room ),
					'bytes'     => $storage->stored_bytes( $room ),
					'followups' => $followups,
					// History-trim events: server checkpoints (all engines
					// trim once per checkpoint) or the relay's accepted
					// client compactions.
					'trims'     => $storage->trim_count( $room ),
				),
				// Peak PHP memory allocated on top of the baseline (bytes);
				// null when memory_reset_peak_usage() is unavailable.
				'memory'                => array(
					'ingest_peak_bytes'      => $ingest_peak,
					'materialize_peak_bytes' => $materialize_peak,
				),
				'quality'               => array(
					'observable'           => $observable,
					'converged'            => $converged,
					'convergence_failures' => array_slice( (array) $convergence_failures, 0, 5 ),
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
		 * The p50/p90/p99/max/mean of a microsecond series (reported in ms).
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
