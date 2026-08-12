<?php
/**
 * The benchmark's authoring-profile SPI: how the runner speaks one engine's
 * wire language.
 *
 * The measurement loop (WP_Sync_Bench_Runner) is engine-neutral — it times
 * whatever requests a profile hands it and feeds every response back. A
 * profile owns everything engine-specific: translating the workload's
 * abstract edits into the engine's update vocabulary, playing the client's
 * part between requests (applying read responses, tracking observed state,
 * answering compaction nominations), classifying void reasons, and scoring
 * quality with an oracle matched to the engine's merge semantics.
 *
 * Profiles are resolved by engine slug through WP_Sync_Bench_Profiles, so
 * an engine plugin can ship its own profile (see the
 * `wp_sync_bench_authoring_profiles` filter there). Implementations must be
 * constructible as `new $class( int $post_id, array $workload )` — the
 * factory's contract — and must be deterministic for a given workload:
 * the CLI replays the identical workload across repetitions and asserts
 * counted metrics do not move.
 *
 * @package gutenberg
 */

if ( ! interface_exists( 'WP_Sync_Bench_Authoring_Profile' ) ) {

	/**
	 * One engine's simulated clients: authoring, observation, and oracle.
	 */
	interface WP_Sync_Bench_Authoring_Profile {
		/**
		 * The profile's name, reported as `profile` so a reader can tell how
		 * the engine was driven (a dedicated profile vs the opaque fallback).
		 *
		 * @return string Profile name.
		 */
		public function name(): string;

		/**
		 * Untimed setup before the first round: build per-client state, and
		 * optionally perform join reads (e.g. bootstrapping client documents
		 * from the genesis snapshot). Runs after the runner's genesis-priming
		 * read.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array<int, int> Initial read cursor per client index.
		 */
		public function bootstrap( WP_Sync_Engine $engine, string $room ): array;

		/**
		 * The context array every read (get_updates_since) carries — e.g. an
		 * awareness roster for engines that nominate a compactor from session
		 * presence.
		 *
		 * @return array Read context.
		 */
		public function read_context(): array;

		/**
		 * Translates one abstract workload edit into the engine's updates
		 * array for handle_updates(). This is client work and is untimed;
		 * only the server call the runner makes with the result is measured.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        Workload edit (client, paragraph, op, text, align).
		 * @param int   $round_index Round the edit belongs to.
		 * @return array Updates payload for handle_updates().
		 */
		public function author( int $client, array $edit, int $round_index ): array;

		/**
		 * Feeds back how the engine settled the edit just submitted, so the
		 * profile can track expectations for its oracle (and, for engines
		 * with server-ordered registers, advance its model of the head).
		 * Called once per disposition in the ingest response.
		 *
		 * @param int   $client      Authoring client index.
		 * @param array $edit        The workload edit the disposition settles.
		 * @param array $disposition Engine disposition (status, reason?, ...).
		 */
		public function record_disposition( int $client, array $edit, array $disposition ): void;

		/**
		 * Whether a voided reason is benign for THIS engine (idempotent
		 * convergence, a compacted-away base — never real content dropped).
		 * Non-benign voids count as lost work, the metric the project's
		 * never-lose-work policy asserts to zero.
		 *
		 * @param string $reason Void reason.
		 * @return bool True when the void is not lost work.
		 */
		public function is_benign_void( string $reason ): bool;

		/**
		 * Untimed client work after a read: apply the response to the
		 * client's local state (its CRDT document, its observed head). Called
		 * for every in-session read and the final catch-up read.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 */
		public function observe( int $client, array $response ): void;

		/**
		 * A follow-up ingest the client protocol requires after this read —
		 * e.g. a relay client answering a should_compact nomination with a
		 * full-state snapshot. The runner times and counts it like any other
		 * ingest. Null when the protocol asks nothing.
		 *
		 * @param int   $client   Reading client index.
		 * @param array $response get_updates_since() response.
		 * @return array|null Updates payload for handle_updates(), or null.
		 */
		public function compaction_request( int $client, array $response ): ?array;

		/**
		 * Scores quality after full catch-up, with an oracle matched to the
		 * engine's merge semantics (dispositions vs the materialized document
		 * for a server-transform engine; all-client convergence for a CRDT).
		 * Returns null when quality is NOT observable from the server side
		 * (the opaque fallback) — reported honestly, never faked.
		 *
		 * @param WP_Sync_Engine $engine Engine under test.
		 * @param string         $room   Room identifier.
		 * @return array|null Failures (empty array = converged), or null.
		 */
		public function score( WP_Sync_Engine $engine, string $room ): ?array;
	}
}
