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
class Tests_Collaboration_WpSyncEngineBenchmark extends WP_UnitTestCase {
	public static function set_up_before_class() {
		parent::set_up_before_class();
		$base = dirname( __DIR__ ) . '/benchmarks/';
		require_once $base . 'class-wp-sync-bench-memory-storage.php';
		require_once $base . 'class-wp-sync-bench-workload.php';
		require_once $base . 'class-wp-sync-bench-runner.php';
	}

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

	public function test_relay_reports_quality_as_unobservable_but_measures_cost() {
		$workload = WP_Sync_Bench_Workload::build( 'parallel-paragraphs', 7, 8, 3, 4 );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => $workload['post_content'] )
		);
		$storage  = new WP_Sync_Bench_Memory_Storage();
		$engine   = new WP_Yjs_Relay_Engine( $storage );

		$report = WP_Sync_Bench_Runner::run( $engine, $storage, $post_id, $workload );

		$this->assertSame( 'yjs-relay', $report['engine'] );
		// The relay merges on the client: the server cannot score quality.
		$this->assertFalse( $report['quality']['observable'] );
		$this->assertNull( $report['quality']['converged'] );
		// Cost and growth ARE measured.
		$this->assertGreaterThan( 0, $report['requests'] );
		$this->assertGreaterThan( 0, $report['storage']['rows'] );
	}

	public function test_relay_scripted_compactor_bounds_storage() {
		// 40 rounds x 3 clients = 120 updates, past the 50-row threshold.
		$workload = WP_Sync_Bench_Workload::build( 'parallel-paragraphs', 7, 40, 3, 4 );
		$post_id  = self::factory()->post->create(
			array( 'post_content' => $workload['post_content'] )
		);
		$storage  = new WP_Sync_Bench_Memory_Storage();
		$engine   = new WP_Yjs_Relay_Engine( $storage );

		$report = WP_Sync_Bench_Runner::run( $engine, $storage, $post_id, $workload );

		// The nominated client compacted at least once, and the room stayed
		// bounded instead of keeping all 120 edit rows.
		$this->assertGreaterThan( 0, $report['storage']['compactions'] );
		$this->assertLessThan( 120, $report['storage']['rows'] );
	}

	public function test_convergence_oracle_accepts_matching_content() {
		$content = "<!-- wp:paragraph {\"align\":\"wide\"} -->\n<p> r0c0.0;Paragraph 1</p>\n<!-- /wp:paragraph -->";

		$failures = WP_Sync_Bench_Runner::verify_convergence(
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

		$failures = WP_Sync_Bench_Runner::verify_convergence(
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
}
