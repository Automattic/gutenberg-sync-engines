<?php
/**
 * Correctness tests for the sync-engine benchmark harness.
 *
 * These assert what the harness MEASURES, not how fast it runs (timing is
 * for the CLI, not CI): the seam is driven correctly, the policy-correct
 * quality signal behaves (clean scenarios never escalate, contended ones
 * do, no workload loses work), and the relay path reports its quality as
 * unobservable rather than faking a score.
 *
 * @package Gutenberg
 *
 * @group collaboration
 */

// Loaded at file scope (not set_up_before_class): the fixture profile at
// the bottom of this file extends a benchmark class, and its definition
// executes when PHPUnit includes the file.
require_once dirname( __DIR__ ) . '/benchmarks/class-wp-sync-bench-memory-storage.php';
require_once dirname( __DIR__ ) . '/benchmarks/class-wp-sync-bench-workload.php';
require_once dirname( __DIR__ ) . '/benchmarks/class-wp-sync-bench-runner.php';

class Tests_Collaboration_WpSyncEngineBenchmark extends WP_UnitTestCase {

	/**
	 * Runs a workload for one scenario against the intent-log engine.
	 *
	 * @param string $scenario Scenario slug.
	 * @return array Report.
	 */
	private function run_intent_log( string $scenario ): array {
		$workload = WP_Sync_Bench_Workload::build( $scenario, 7, 8, 3, 4 );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => $workload['post_content'] )
		);
		$storage  = new WP_Sync_Bench_Memory_Storage();
		$engine   = new WP_Intent_Log_Engine( $storage );
		return WP_Sync_Bench_Runner::run( $engine, $storage, $post_id, $workload );
	}

	public function test_parallel_paragraphs_merge_clean_and_lose_nothing() {
		$report = $this->run_intent_log( 'parallel-paragraphs' );

		$this->assertSame( 'intent-log', $report['engine'] );
		$this->assertGreaterThan( 0, $report['requests'] );
		// Distinct paragraphs never contend: everything applies.
		$this->assertSame( 0, $report['quality']['dispositions']['escalated'] );
		$this->assertSame( 0.0, $report['quality']['escalation_rate'] );
		$this->assertSame( 0, $report['quality']['lost_work'] );
		$this->assertSame( array(), $report['quality']['convergence_failures'] );
		$this->assertTrue( $report['quality']['converged'] );
		$this->assertGreaterThan( 0, $report['storage']['rows'] );
	}

	public function test_contended_paragraph_escalates_but_loses_nothing() {
		$report = $this->run_intent_log( 'contended-paragraph' );

		// Concurrent same-field authorship must produce review escalations…
		$this->assertGreaterThan( 0, $report['quality']['dispositions']['escalated'] );
		$this->assertGreaterThan( 0, $report['quality']['escalation_rate'] );
		// …and STILL lose nothing (escalated edits are preserved, not dropped).
		$this->assertSame( 0, $report['quality']['lost_work'] );
		$this->assertSame( array(), $report['quality']['convergence_failures'] );
		$this->assertTrue( $report['quality']['converged'] );
	}

	public function test_solo_typing_is_all_applied() {
		$report = $this->run_intent_log( 'solo-typing' );

		$this->assertSame( $report['requests'], $report['quality']['dispositions']['applied'] );
		$this->assertSame( 0, $report['quality']['dispositions']['escalated'] );
		$this->assertSame( 0, $report['quality']['lost_work'] );
	}

	public function test_cost_and_payload_metrics_are_populated() {
		$report = $this->run_intent_log( 'mixed-newsroom' );

		$this->assertArrayHasKey( 'mean', $report['service_us'] );
		$this->assertGreaterThanOrEqual( 0, $report['service_us']['mean'] );
		// Reads and idle polls are timed separately from ingest.
		$this->assertGreaterThan( 0, $report['read_us']['mean'] );
		$this->assertGreaterThan( 0, $report['idle_poll_us']['mean'] );
		$this->assertGreaterThan( 0, $report['payload_bytes']['request_p50'] );
		$this->assertGreaterThan( 0, $report['storage']['bytes'] );
	}

	public function test_laggy_clients_escalate_or_settle_but_lose_nothing() {
		// 30 rounds so the laggy client (reads every 10th round) authors
		// from bases up to ~40 intents stale and catches up three times.
		$workload = WP_Sync_Bench_Workload::build( 'laggy-newsroom', 7, 30, 4, 4 );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => $workload['post_content'] )
		);
		$storage  = new WP_Sync_Bench_Memory_Storage();
		$engine   = new WP_Intent_Log_Engine( $storage );

		$report = WP_Sync_Bench_Runner::run( $engine, $storage, $post_id, $workload );

		// Stale bases may escalate or void benignly — but never lose work,
		// and the document must still match the engine's own dispositions.
		$this->assertSame( 0, $report['quality']['lost_work'] );
		$this->assertSame( array(), $report['quality']['convergence_failures'] );
		$this->assertTrue( $report['quality']['converged'] );
	}

	public function test_long_form_document_is_larger_and_converges() {
		$small = WP_Sync_Bench_Workload::build( 'solo-typing', 7, 8, 1, 4 );
		$large = WP_Sync_Bench_Workload::build( 'long-form', 7, 8, 1, 4 );
		$this->assertGreaterThan(
			5 * strlen( $small['post_content'] ),
			strlen( $large['post_content'] )
		);

		$report = $this->run_intent_log( 'long-form' );
		$this->assertSame( 0, $report['quality']['lost_work'] );
		$this->assertTrue( $report['quality']['converged'] );
	}

	public function test_convergence_oracle_accepts_matching_content() {
		$content = "<!-- wp:paragraph {\"align\":\"wide\"} -->\n<p> r0c0.0;Paragraph 1</p>\n<!-- /wp:paragraph -->";

		$failures = WP_Sync_Bench_Intent_Log_Profile::verify_convergence(
			$content,
			1,
			array(
				array(
					'text'   => ' r0c0.0;',
					'status' => 'applied',
				),
				array(
					'text'   => ' r1c1.1;',
					'status' => 'escalated',
				),
			),
			array( 0 => 'wide' )
		);

		$this->assertSame( array(), $failures );
	}

	public function test_convergence_oracle_detects_corruption() {
		$content = "<!-- wp:paragraph -->\n<p>Paragraph 1 r9c9.9;</p>\n<!-- /wp:paragraph -->";

		$failures = WP_Sync_Bench_Intent_Log_Profile::verify_convergence(
			$content,
			2, // A dropped paragraph block.
			array(
				array(
					'text'   => ' r0c0.0;',
					'status' => 'applied', // Missing from content: lost.
				),
				array(
					'text'   => ' r9c9.9;',
					'status' => 'escalated', // Present in content: leaked.
				),
			),
			array( 0 => 'full' ) // Align register not honored.
		);

		$checks = array_column( $failures, 'check' );
		$this->assertContains( 'structure', $checks );
		$this->assertContains( 'applied-text', $checks );
		$this->assertContains( 'escalated-text', $checks );
		$this->assertContains( 'attr-register', $checks );
	}

	public function test_workload_generation_is_deterministic() {
		$a = WP_Sync_Bench_Workload::build( 'mixed-newsroom', 123, 10, 3, 5 );
		$b = WP_Sync_Bench_Workload::build( 'mixed-newsroom', 123, 10, 3, 5 );
		$this->assertSame( $a['rounds'], $b['rounds'] );

		$c = WP_Sync_Bench_Workload::build( 'mixed-newsroom', 124, 10, 3, 5 );
		$this->assertNotSame( $a['rounds'], $c['rounds'] );
	}

	/**
	 * Runs a workload for one scenario against the yjs-server engine
	 * (the real-Yjs authoring profile).
	 *
	 * @param string $scenario Scenario slug.
	 * @param int    $rounds   Rounds.
	 * @param int    $clients  Clients.
	 * @return array Report.
	 */
	private function run_yjs_server( string $scenario, int $rounds = 8, int $clients = 3 ): array {
		$workload = WP_Sync_Bench_Workload::build( $scenario, 7, $rounds, $clients, 4 );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => $workload['post_content'] )
		);
		$storage  = new WP_Sync_Bench_Memory_Storage();
		$engine   = new WP_Yjs_Server_Engine( $storage );
		return WP_Sync_Bench_Runner::run( $engine, $storage, $post_id, $workload );
	}

	public function test_yjs_server_gets_the_real_yjs_profile_and_observable_quality() {
		$report = $this->run_yjs_server( 'parallel-paragraphs' );

		$this->assertSame( 'yjs-server', $report['engine'] );
		$this->assertSame( 'yjs-server', $report['profile'] );
		// The server merges, so quality IS observable — the relay's
		// structural limitation does not apply here.
		$this->assertTrue( $report['quality']['observable'] );
		$this->assertTrue( $report['quality']['converged'] );
		$this->assertSame( array(), $report['quality']['convergence_failures'] );
		$this->assertSame( 0, $report['quality']['lost_work'] );
		// Clean parallel edits all merge.
		$this->assertSame( $report['requests'], $report['quality']['dispositions']['applied'] );
		// Real Yjs payloads, real storage bytes.
		$this->assertGreaterThan( 0, $report['payload_bytes']['request_p50'] );
		$this->assertGreaterThan( 0, $report['storage']['bytes'] );
	}

	public function test_yjs_server_contended_paragraph_merges_silently_and_converges() {
		$report = $this->run_yjs_server( 'contended-paragraph' );

		// The CRDT auto-merges register conflicts (last-writer-wins by CRDT
		// rules) instead of escalating — the policy difference with
		// intent-log, reported honestly: zero escalations, still converged,
		// no text lost.
		$this->assertSame( 0, $report['quality']['dispositions']['escalated'] );
		$this->assertSame( 0.0, $report['quality']['escalation_rate'] );
		$this->assertTrue( $report['quality']['converged'] );
		$this->assertSame( array(), $report['quality']['convergence_failures'] );
		$this->assertSame( 0, $report['quality']['lost_work'] );
	}

	public function test_yjs_server_laggy_clients_converge() {
		// The laggy client reads every 10th round: it authors from stale
		// state and its catch-up reads replay deep tails.
		$report = $this->run_yjs_server( 'laggy-newsroom', 30, 4 );

		$this->assertTrue( $report['quality']['converged'] );
		$this->assertSame( array(), $report['quality']['convergence_failures'] );
		$this->assertSame( 0, $report['quality']['lost_work'] );
	}

	public function test_unknown_engine_falls_back_to_the_opaque_relay_profile() {
		// The relay fixture engine has no dedicated profile, so the registry
		// must resolve the opaque fallback: relay-convention updates that a
		// store-and-forward engine accepts, quality reported as
		// unobservable rather than faked, and the runner playing the
		// nominated compactor's part.
		$workload = WP_Sync_Bench_Workload::build( 'parallel-paragraphs', 7, 30, 3, 4 );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => $workload['post_content'] )
		);
		$storage  = new WP_Sync_Bench_Memory_Storage();
		$engine   = new Test_Opaque_Relay_Engine( $storage );

		$report = WP_Sync_Bench_Runner::run( $engine, $storage, $post_id, $workload );

		$this->assertSame( 'test-opaque-relay', $report['engine'] );
		$this->assertSame( 'opaque-relay', $report['profile'] );
		$this->assertFalse( $report['quality']['observable'] );
		$this->assertNull( $report['quality']['converged'] );
		$this->assertSame( 0, $report['quality']['lost_work'] );
		// 30 rounds x 3 clients = 90 updates against the 50-row threshold:
		// the nominated compactor answered should_compact.
		$this->assertGreaterThan( 0, $report['storage']['compactions'] );
		$this->assertGreaterThan( 0, $report['storage']['rows'] );
	}

	public function test_profile_registry_filter_maps_an_engine_to_a_profile() {
		$filter = static function ( array $profiles ): array {
			$profiles['test-opaque-relay'] = Bench_Test_Named_Relay_Profile::class;
			return $profiles;
		};
		add_filter( 'wp_sync_bench_authoring_profiles', $filter );

		try {
			$workload = WP_Sync_Bench_Workload::build( 'solo-typing', 7, 4, 1, 4 );
			$profile  = WP_Sync_Bench_Profiles::for_engine( 'test-opaque-relay', 0, $workload );
			$this->assertInstanceOf( Bench_Test_Named_Relay_Profile::class, $profile );
			$this->assertSame( 'bench-test-named-relay', $profile->name() );
		} finally {
			remove_filter( 'wp_sync_bench_authoring_profiles', $filter );
		}
	}

	public function test_profile_registry_rejects_a_broken_registration() {
		$filter = static function ( array $profiles ): array {
			// Not a class that implements the profile interface.
			$profiles['test-opaque-relay'] = 'Bench_Class_That_Does_Not_Exist';
			return $profiles;
		};
		add_filter( 'wp_sync_bench_authoring_profiles', $filter );

		try {
			$workload = WP_Sync_Bench_Workload::build( 'solo-typing', 7, 4, 1, 4 );
			$profile  = WP_Sync_Bench_Profiles::for_engine( 'test-opaque-relay', 0, $workload );
			// A broken registration must not fake a dedicated profile.
			$this->assertInstanceOf( WP_Sync_Bench_Opaque_Relay_Profile::class, $profile );
		} finally {
			remove_filter( 'wp_sync_bench_authoring_profiles', $filter );
		}
	}

	public function test_yjs_server_checkpoints_bound_storage_without_client_help() {
		$interval = static function () {
			return 20;
		};
		add_filter( 'wp_sync_yjs_server_checkpoint_interval', $interval );

		try {
			// 40 rounds x 3 clients = 120 updates against a 20-row interval.
			$report = $this->run_yjs_server( 'parallel-paragraphs', 40, 3 );

			// No client compactions (should_compact never fires)…
			$this->assertSame( 0, $report['storage']['compactions'] );
			// …yet the room stays bounded: the server checkpointed and
			// trimmed by itself.
			$this->assertLessThan( 60, $report['storage']['rows'] );
			$this->assertTrue( $report['quality']['converged'] );
			$this->assertSame( 0, $report['quality']['lost_work'] );
		} finally {
			remove_filter( 'wp_sync_yjs_server_checkpoint_interval', $interval );
		}
	}
}

if ( ! class_exists( 'Bench_Test_Named_Relay_Profile' ) ) {
	/**
	 * TEST FIXTURE: a distinctly-named profile, for asserting the
	 * `wp_sync_bench_authoring_profiles` filter resolves registrations.
	 */
	class Bench_Test_Named_Relay_Profile extends WP_Sync_Bench_Opaque_Relay_Profile {
		/**
		 * Profile name.
		 *
		 * @return string Profile name.
		 */
		public function name(): string {
			return 'bench-test-named-relay';
		}
	}
}
