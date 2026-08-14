<?php
/**
 * Engine-level tests for the DE-RTC sync engine (WP_De_RTC_Engine),
 * driving the production WP_Sync_Engine seam against the postmeta storage
 * with real merge-core three-way merges.
 *
 * @package Gutenberg
 */

/**
 * @group collaboration
 */
class Tests_Collaboration_WpDeRtcEngine extends WP_UnitTestCase {
	/**
	 * Editor user ID (has unfiltered_html on single site).
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Author user ID (lacks unfiltered_html).
	 *
	 * @var int
	 */
	protected static $author_id;

	/**
	 * Post ID used for room targets.
	 *
	 * @var int
	 */
	protected static $post_id;

	const GENESIS_CONTENT = "<!-- wp:paragraph -->\n<p>Alpha block original text.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Beta block original text.</p>\n<!-- /wp:paragraph -->";

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$author_id = $factory->user->create( array( 'role' => 'author' ) );
		self::$post_id   = $factory->post->create(
			array(
				'post_author'  => self::$editor_id,
				'post_title'   => 'DE-RTC test post',
				'post_content' => self::GENESIS_CONTENT,
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$editor_id );
		self::delete_user( self::$author_id );
		wp_delete_post( self::$post_id, true );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
	}

	private function room(): string {
		return 'postType/post:' . self::$post_id;
	}

	/**
	 * A fresh engine over a fresh storage instance — the per-request state
	 * boundary. Sharing the DB while resetting in-memory caches mimics
	 * separate HTTP requests.
	 *
	 * @return WP_De_RTC_Engine Engine.
	 */
	private function engine(): WP_De_RTC_Engine {
		return new WP_De_RTC_Engine( new WP_Sync_Post_Meta_Storage() );
	}

	/**
	 * Extracts the latest canonical version/content a client would track
	 * from a room response.
	 *
	 * @param array $response Room response from get_updates_since().
	 * @return array{version: string, content: string}|null Latest state.
	 */
	private function latest_from_response( array $response ): ?array {
		$latest = null;
		foreach ( $response['updates'] as $update ) {
			$decoded = json_decode( $update['data'], true );
			if ( is_array( $decoded ) && is_string( $decoded['version'] ?? null ) && is_string( $decoded['content'] ?? null ) ) {
				$latest = array(
					'version' => $decoded['version'],
					'content' => $decoded['content'],
				);
			}
		}
		return $latest;
	}

	/**
	 * Builds a proposal update the way the client adapter does.
	 *
	 * @param string $proposal_id  Correlation id.
	 * @param string $base_version Base version label.
	 * @param string $base         Base content.
	 * @param string $proposed     Proposed content.
	 * @param bool   $with_update  Whether to attach the block-native update.
	 * @return array Typed update row.
	 */
	private function proposal( string $proposal_id, string $base_version, string $base, string $proposed, bool $with_update = true ): array {
		$payload = array(
			'proposalId'      => $proposal_id,
			'baseVersion'     => $base_version,
			'proposedContent' => $proposed,
			'clientUpdate'    => $with_update
				? wp_de_rtc_create_automerge_update_for_content_change( $base, $proposed, 'test-actor' )
				: null,
		);

		return array(
			'data' => wp_json_encode( $payload ),
			'type' => WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL,
		);
	}

	public function test_identity() {
		$engine = $this->engine();
		$this->assertSame( 'de-rtc', $engine->get_slug() );
		$this->assertSame( 1, $engine->get_protocol_version() );
		$this->assertSame( array( 'proposal', 'content', 'snapshot' ), $engine->get_update_types() );
	}

	public function test_genesis_snapshot_and_lineage() {
		$engine   = $this->engine();
		$storage  = new WP_Sync_Post_Meta_Storage();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );

		$this->assertGreaterThan( 0, $response['end_cursor'] );
		$this->assertNotEmpty( $response['updates'] );
		$this->assertSame( WP_De_RTC_Engine::UPDATE_TYPE_SNAPSHOT, $response['updates'][0]['type'] );

		$genesis = json_decode( $response['updates'][0]['data'], true );
		$this->assertSame( 'v1', $genesis['version'] );
		$this->assertSame( self::GENESIS_CONTENT, $genesis['content'] );

		$this->assertSame( 'de-rtc', $storage->get_room_engine( $this->room() ) );
	}

	public function test_proposal_is_applied_and_broadcast() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$latest   = $this->latest_from_response( $response );

		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $latest['content'] );
		$result   = $engine->handle_updates(
			$this->room(),
			1,
			0,
			array( $this->proposal( 'p-1', $latest['version'], $latest['content'], $proposed ) ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['dispositions'] );
		$this->assertSame( 'p-1', $result['dispositions'][0]['intentId'] );
		$this->assertSame( 'applied', $result['dispositions'][0]['status'] );
		$this->assertSame( 'v2', $result['dispositions'][0]['version'] );

		// A second client sees the accepted content row.
		$peer_response = $this->engine()->get_updates_since( $this->room(), 2, 0, array() );
		$peer_latest   = $this->latest_from_response( $peer_response );
		$this->assertSame( 'v2', $peer_latest['version'] );
		$this->assertStringContainsString( 'Beta block CLIENT-EDITED', $peer_latest['content'] );

		$this->assertStringContainsString( 'Beta block CLIENT-EDITED', $this->engine()->materialize( $this->room() ) );
	}

	public function test_stale_base_proposal_merges_three_way() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$genesis  = $this->latest_from_response( $response );

		// Client A lands an Alpha edit (canonical moves to v2).
		$a_proposed = str_replace( 'Alpha block original', 'Alpha block A-EDITED', $genesis['content'] );
		$a_result   = $engine->handle_updates(
			$this->room(),
			1,
			0,
			array( $this->proposal( 'p-a', $genesis['version'], $genesis['content'], $a_proposed ) ),
			array()
		);
		$this->assertSame( 'applied', $a_result['dispositions'][0]['status'] );

		// Client B proposes a Beta edit still based on genesis v1: the server
		// rebases it over A's accepted edit via the three-way merge.
		$b_proposed = str_replace( 'Beta block original', 'Beta block B-EDITED', $genesis['content'] );
		$b_result   = $this->engine()->handle_updates(
			$this->room(),
			2,
			0,
			array( $this->proposal( 'p-b', $genesis['version'], $genesis['content'], $b_proposed ) ),
			array()
		);

		$this->assertSame( 'applied', $b_result['dispositions'][0]['status'] );
		$this->assertSame( 'v3', $b_result['dispositions'][0]['version'] );

		$materialized = $this->engine()->materialize( $this->room() );
		$this->assertStringContainsString( 'Alpha block A-EDITED', $materialized );
		$this->assertStringContainsString( 'Beta block B-EDITED', $materialized );
	}

	public function test_conflicting_proposal_escalates() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$genesis  = $this->latest_from_response( $response );

		$a_proposed = str_replace( 'Alpha block original text', 'Alpha block A-REWRITE text', $genesis['content'] );
		$engine->handle_updates(
			$this->room(),
			1,
			0,
			array( $this->proposal( 'p-a', $genesis['version'], $genesis['content'], $a_proposed ) ),
			array()
		);

		// B rewrites the SAME words from the same base: a genuine conflict.
		$b_proposed = str_replace( 'Alpha block original text', 'Alpha block B-REWRITE text', $genesis['content'] );
		$b_result   = $this->engine()->handle_updates(
			$this->room(),
			2,
			0,
			array( $this->proposal( 'p-b', $genesis['version'], $genesis['content'], $b_proposed ) ),
			array()
		);

		$this->assertSame( 'escalated', $b_result['dispositions'][0]['status'] );
		$this->assertSame( 'manual-conflict-required', $b_result['dispositions'][0]['reason'] );

		// Canonical keeps A's accepted state.
		$this->assertStringContainsString( 'Alpha block A-REWRITE', $this->engine()->materialize( $this->room() ) );
		$this->assertStringNotContainsString( 'B-REWRITE', $this->engine()->materialize( $this->room() ) );
	}

	public function test_unknown_base_version_is_voided() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$genesis  = $this->latest_from_response( $response );

		$result = $engine->handle_updates(
			$this->room(),
			1,
			0,
			array( $this->proposal( 'p-x', 'v999', $genesis['content'], $genesis['content'] . "\n" ) ),
			array()
		);

		$this->assertSame( 'voided', $result['dispositions'][0]['status'] );
		$this->assertSame( 'unknown-base-version', $result['dispositions'][0]['reason'] );
	}

	public function test_malformed_proposal_is_voided_per_row() {
		$engine = $this->engine();
		$engine->get_updates_since( $this->room(), 1, 0, array() );

		$result = $engine->handle_updates(
			$this->room(),
			1,
			0,
			array(
				array(
					'data' => wp_json_encode( array( 'proposalId' => 'p-bad' ) ),
					'type' => WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL,
				),
				array(
					'data' => 'not json at all',
					'type' => WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL,
				),
			),
			array()
		);

		$this->assertIsArray( $result );
		// The correlatable row voids; the uncorrelatable row is dropped.
		$this->assertCount( 1, $result['dispositions'] );
		$this->assertSame( 'p-bad', $result['dispositions'][0]['intentId'] );
		$this->assertSame( 'voided', $result['dispositions'][0]['status'] );
		$this->assertSame( 'invalid-payload', $result['dispositions'][0]['reason'] );
	}

	public function test_non_proposal_update_type_is_rejected() {
		$engine = $this->engine();
		$result = $engine->handle_updates(
			$this->room(),
			1,
			0,
			array(
				array(
					'data' => '{}',
					'type' => WP_De_RTC_Engine::UPDATE_TYPE_CONTENT,
				),
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'rest_invalid_update_type', $result->get_error_code() );
	}

	public function test_proposal_without_client_update_is_accepted() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$latest   = $this->latest_from_response( $response );

		$proposed = str_replace( 'Alpha block original', 'Alpha block PLAIN-EDITED', $latest['content'] );
		$result   = $engine->handle_updates(
			$this->room(),
			1,
			0,
			array( $this->proposal( 'p-plain', $latest['version'], $latest['content'], $proposed, false ) ),
			array()
		);

		$this->assertSame( 'applied', $result['dispositions'][0]['status'] );
		$this->assertStringContainsString( 'Alpha block PLAIN-EDITED', $this->engine()->materialize( $this->room() ) );
	}

	public function test_author_without_unfiltered_html_escalates_risky_content() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$latest   = $this->latest_from_response( $response );

		wp_set_current_user( self::$author_id );

		$proposed = $latest['content'] . "\n\n<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->";
		$result   = $engine->handle_updates(
			$this->room(),
			3,
			0,
			array( $this->proposal( 'p-risky', $latest['version'], $latest['content'], $proposed ) ),
			array()
		);

		$this->assertSame( 'escalated', $result['dispositions'][0]['status'] );
		$this->assertSame( 'requires-unfiltered-html', $result['dispositions'][0]['reason'] );
		$this->assertStringNotContainsString( '<script>', $this->engine()->materialize( $this->room() ) );
	}

	public function test_canonical_state_survives_engine_instances() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
		$latest   = $this->latest_from_response( $response );

		$proposed = str_replace( 'Beta block original', 'Beta block PERSISTED', $latest['content'] );
		$engine->handle_updates(
			$this->room(),
			1,
			0,
			array( $this->proposal( 'p-persist', $latest['version'], $latest['content'], $proposed ) ),
			array()
		);

		// A completely fresh engine (new request) reads the same canonical.
		$this->assertStringContainsString( 'Beta block PERSISTED', $this->engine()->materialize( $this->room() ) );
	}

	public function test_checkpoint_trims_history_and_floors_cursor() {
		add_filter( 'wp_sync_de_rtc_checkpoint_interval', $interval_filter = static fn() => 4 );
		try {
			$engine   = $this->engine();
			$response = $engine->get_updates_since( $this->room(), 1, 0, array() );
			$latest   = $this->latest_from_response( $response );

			$content = $latest['content'];
			$version = $latest['version'];
			// Enough accepted proposals for TWO checkpoints (the first one
			// never trims — rows from the previous checkpoint onward are
			// always kept; the floor appears at the second).
			for ( $i = 1; $i <= 12; $i++ ) {
				$proposed = $content . "\n\n<!-- wp:paragraph -->\n<p>Row {$i}.</p>\n<!-- /wp:paragraph -->";
				$result   = $this->engine()->handle_updates(
					$this->room(),
					1,
					0,
					array( $this->proposal( 'p-' . $i, $version, $content, $proposed ) ),
					array()
				);
				$this->assertSame( 'applied', $result['dispositions'][0]['status'], "proposal {$i} should apply" );
				$version = $result['dispositions'][0]['version'];
				$content = $proposed;
			}

			$storage = new WP_Sync_Post_Meta_Storage();
			$floor   = $storage->get_room_meta( $this->room(), WP_De_RTC_Engine::META_FLOOR );
			$this->assertIsNumeric( $floor, 'compaction should have recorded a floor' );

			// A client behind the floor is clamped and still converges.
			$stale_response = $this->engine()->get_updates_since( $this->room(), 9, 1, array() );
			$stale_latest   = $this->latest_from_response( $stale_response );
			$this->assertNotNull( $stale_latest );
			$this->assertStringContainsString( 'Row 12.', $stale_latest['content'] );
		} finally {
			remove_filter( 'wp_sync_de_rtc_checkpoint_interval', $interval_filter );
		}
	}
}
