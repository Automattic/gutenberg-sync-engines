<?php
/**
 * Opaque-relay authoring profile: the fallback for engines without a
 * dedicated profile.
 *
 * Submits relay-convention `update`/`compaction` blobs — opaque
 * client-computed updates of comparable size to a real Yjs update for a
 * few inserted characters. A third-party relay-style engine benchmarks
 * meaningfully out of the box; an engine with its own wire vocabulary will
 * void or reject the generic updates, and the dispositions/storage counts
 * will show that rather than fake a result. Payload and storage bytes on
 * this profile are size-modelled, not real (see the README's Limitations).
 *
 * The profile plays the nominated compactor's part: when a read answers
 * `should_compact`, it submits a synthetic full-state snapshot sized to
 * the accumulated document — what a real relay client does past the
 * threshold. Without this, relay storage growth would measure a session
 * with no live clients, not the deployed system.
 *
 * Quality is reported as NOT server-observable (a client-merging engine's
 * merge runs in browser clients, outside the harness) — honestly, never
 * faked.
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_Opaque_Relay_Profile' ) ) {

	/**
	 * Simulated relay clients (size-modelled payloads).
	 */
	class WP_Sync_Bench_Opaque_Relay_Profile implements WP_Sync_Bench_Authoring_Profile {
		/** Void reasons that are NOT lost work: the shared benign set — an
		 * unknown engine's other reasons count as lost until its own profile
		 * says otherwise.
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
		 * Simulated client count.
		 *
		 * @var int
		 */
		private $client_count;

		/**
		 * Accumulated client-side document size: every edit lands in the
		 * client CRDT, so the eventual compaction snapshot grows with it.
		 *
		 * @var int
		 */
		private $relay_doc_bytes;

		/**
		 * Constructor (the factory contract).
		 *
		 * @param int   $post_id  Seeded post (room target, unused here).
		 * @param array $workload Workload from the generator.
		 */
		public function __construct( int $post_id, array $workload ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $post_id is part of the factory contract.
			$this->client_count    = max( 1, (int) $workload['clients'] );
			$this->relay_doc_bytes = strlen( (string) $workload['post_content'] );
		}

		/**
		 * Profile name.
		 *
		 * @return string Profile name.
		 */
		public function name(): string {
			return 'opaque-relay';
		}

		/**
		 * No client documents to bootstrap; clients track read cursors only.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array<int, int> Initial read cursor per client.
		 */
		public function bootstrap( WP_Sync_Engine $engine, string $room ): array {
			return array_fill( 0, $this->client_count, 0 );
		}

		/**
		 * Relay reads carry an awareness roster so the engine can nominate a
		 * compactor (the lowest client id) — in production this is the
		 * session presence list.
		 *
		 * @return array Read context.
		 */
		public function read_context(): array {
			return array( 'awareness' => array_fill_keys( range( 0, $this->client_count - 1 ), array() ) );
		}

		/**
		 * An opaque client-computed update of comparable size (a real yjs
		 * update for a few inserted chars). The literal 'update'/'compaction'
		 * types are the retired yjs-relay engine's convention.
		 *
		 * @param int   $client      Authoring client index (unused).
		 * @param array $edit        Workload edit.
		 * @param int   $round_index Round the edit belongs to (unused).
		 * @return array Updates payload.
		 */
		public function author( int $client, array $edit, int $round_index ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $client and $round_index are part of the profile contract.
			// Every edit lands in the client CRDT, so the eventual compaction
			// snapshot grows with the document.
			$this->relay_doc_bytes += strlen( (string) $edit['text'] );
			return array(
				array(
					'type' => 'update',
					'data' => base64_encode( 'yjs-update:' . $edit['text'] . str_repeat( "\x01", 24 ) ),
				),
			);
		}

		/**
		 * The server cannot say how a client-merged edit settled; there is
		 * nothing to track.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        The workload edit.
		 * @param array $disposition Engine disposition.
		 */
		public function record_disposition( int $client, array $edit, array $disposition ): void {
		}

		/**
		 * Benign-void classification (the shared conservative set).
		 *
		 * @param string $reason Void reason.
		 * @return bool True when not lost work.
		 */
		public function is_benign_void( string $reason ): bool {
			return in_array( $reason, self::BENIGN_VOID_REASONS, true );
		}

		/**
		 * The simulated client applies rows into a CRDT the harness does not
		 * model; only the cursor (tracked by the runner) matters.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 */
		public function observe( int $client, array $response ): void {
		}

		/**
		 * The nominated relay client answers should_compact with a
		 * full-state snapshot at its cursor — a real, timed request the
		 * deployed protocol makes (compaction is not free).
		 *
		 * @param int   $client   Reading client index (the engine nominated it).
		 * @param array $response get_updates_since() response.
		 * @return array|null Compaction payload, or null when not nominated.
		 */
		public function followup_request( int $client, array $response ): ?array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $client is part of the profile contract.
			if ( empty( $response['should_compact'] ) ) {
				return null;
			}
			return array(
				array(
					'type' => 'compaction',
					'data' => base64_encode( 'yjs-compaction:' . str_repeat( "\x01", $this->relay_doc_bytes ) ),
				),
			);
		}

		/**
		 * The relay returns no dispositions; nothing to settle.
		 *
		 * @param int            $client Client index.
		 * @param array|WP_Error $result handle_updates() result.
		 */
		public function record_followup_result( int $client, $result ): void {
		}

		/**
		 * Quality is not server-observable on this profile — there is no PHP
		 * CRDT in the loop to score convergence or conflict outcome, so the
		 * harness says so rather than inventing a number.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return null Always null.
		 */
		public function score( WP_Sync_Engine $engine, string $room ): ?array {
			return null;
		}
	}
}
