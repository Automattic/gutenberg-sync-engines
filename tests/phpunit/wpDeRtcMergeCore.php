<?php
/**
 * Tests for the ported DE-RTC merge core (includes/engines/de-rtc/merge-core.php):
 * the block-aware three-way merge, client-update construction/validation, the
 * legacy automerge-php merge path, and version snapshots.
 *
 * These drive the pure merge seam the WP_De_RTC_Engine builds on, without any
 * room/transport machinery.
 *
 * @package Gutenberg
 */

/**
 * @group collaboration
 */
class Tests_Collaboration_WpDeRtcMergeCore extends WP_UnitTestCase {

	const PARA_A = "<!-- wp:paragraph -->\n<p>Alpha block original text.</p>\n<!-- /wp:paragraph -->";
	const PARA_B = "<!-- wp:paragraph -->\n<p>Beta block original text.</p>\n<!-- /wp:paragraph -->";

	/**
	 * Base document used by most scenarios: two paragraphs.
	 *
	 * @return string
	 */
	private function base_content() {
		return self::PARA_A . "\n\n" . self::PARA_B;
	}

	/**
	 * Builds a block-native client update the way the client would.
	 *
	 * @param string $base     Base content.
	 * @param string $proposed Proposed content.
	 * @return array
	 */
	private function client_update( $base, $proposed ) {
		$update = wp_de_rtc_create_automerge_update_for_content_change( $base, $proposed, 'client-actor' );
		$this->assertIsArray( $update, 'client update construction should succeed' );
		return $update;
	}

	public function test_merge_core_is_loaded() {
		$this->assertTrue( function_exists( 'wp_de_rtc_get_automerge_retry_save_result' ) );
		$this->assertTrue( function_exists( 'wp_de_rtc_get_serialized_block_server_merge_result' ) );
	}

	public function test_automerge_runtime_status_reports_vendored_library() {
		$status = wp_de_rtc_get_automerge_runtime_status();

		$this->assertSame( PHP_VERSION_ID >= 80200 && function_exists( 'mb_convert_encoding' ), $status['available'] );
		$this->assertStringContainsString( 'lib/automerge-php/src', $status['library_path'] );
		$this->assertFileExists( $status['library_path'] . '/NativePort.php' );
	}

	public function test_disjoint_block_edits_merge() {
		$base     = $this->base_content();
		$server   = str_replace( 'Alpha block original', 'Alpha block SERVER-EDITED', $base );
		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $base );

		$result = wp_de_rtc_get_automerge_retry_save_result(
			$base,
			$server,
			$proposed,
			$this->client_update( $base, $proposed )
		);

		$this->assertIsArray( $result, 'disjoint block edits should merge cleanly' );
		$this->assertSame( 'merged', $result['merge_status'] );
		$this->assertStringContainsString( 'Alpha block SERVER-EDITED', $result['merged_content'] );
		$this->assertStringContainsString( 'Beta block CLIENT-EDITED', $result['merged_content'] );
	}

	public function test_rich_text_edits_in_one_paragraph_merge() {
		$base     = self::PARA_A;
		$server   = str_replace( 'Alpha block', 'Alpha (server) block', $base );
		$proposed = str_replace( 'original text.', 'original text, client-extended.', $base );

		$result = wp_de_rtc_get_automerge_retry_save_result(
			$base,
			$server,
			$proposed,
			$this->client_update( $base, $proposed )
		);

		$this->assertIsArray( $result, 'non-overlapping rich-text edits in one paragraph should merge' );
		$this->assertStringContainsString( 'Alpha (server) block', $result['merged_content'] );
		$this->assertStringContainsString( 'client-extended', $result['merged_content'] );
	}

	public function test_overlapping_edits_report_manual_conflict() {
		$base     = self::PARA_A;
		$server   = str_replace( 'original text', 'server rewrite of the text', $base );
		$proposed = str_replace( 'original text', 'client rewrite of the text', $base );

		$result = wp_de_rtc_get_automerge_retry_save_result(
			$base,
			$server,
			$proposed,
			$this->client_update( $base, $proposed )
		);

		$this->assertWPError( $result, 'overlapping edits to the same text must not auto-merge' );
		$this->assertSame( 'de_rtc_rebase_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'manual_conflict_required', $data['server_merge_status'] );
		$this->assertFalse( $data['saves_post'] );
	}

	public function test_client_insertion_merges_with_server_edit() {
		$base     = $this->base_content();
		$server   = str_replace( 'Alpha block original', 'Alpha block SERVER-EDITED', $base );
		$added    = "<!-- wp:paragraph -->\n<p>Client-appended paragraph.</p>\n<!-- /wp:paragraph -->";
		$proposed = $base . "\n\n" . $added;

		$result = wp_de_rtc_get_automerge_retry_save_result(
			$base,
			$server,
			$proposed,
			$this->client_update( $base, $proposed )
		);

		$this->assertIsArray( $result, 'a client append should merge over a server text edit' );
		$this->assertStringContainsString( 'Alpha block SERVER-EDITED', $result['merged_content'] );
		$this->assertStringContainsString( 'Client-appended paragraph.', $result['merged_content'] );
	}

	public function test_concurrent_differing_appends_escalate_by_design() {
		// Both sides appended DIFFERENT blocks at the same edge: ordering is
		// ambiguous, and DE-RTC's policy is human review over silent guessing.
		$base     = $this->base_content();
		$server   = $base . "\n\n<!-- wp:paragraph -->\n<p>Server-appended paragraph.</p>\n<!-- /wp:paragraph -->";
		$proposed = $base . "\n\n<!-- wp:paragraph -->\n<p>Client-appended paragraph.</p>\n<!-- /wp:paragraph -->";

		$result = wp_de_rtc_get_automerge_retry_save_result(
			$base,
			$server,
			$proposed,
			$this->client_update( $base, $proposed )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'de_rtc_rebase_failed', $result->get_error_code() );
		$this->assertSame( 'manual_conflict_required', $result->get_error_data()['server_merge_status'] );
	}

	public function test_identical_current_and_base_fast_path() {
		$base     = $this->base_content();
		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $base );

		$result = wp_de_rtc_get_automerge_retry_save_result(
			$base,
			$base,
			$proposed,
			$this->client_update( $base, $proposed )
		);

		$this->assertIsArray( $result );
		$this->assertSame( wp_de_rtc_hash_content( $proposed ), wp_de_rtc_hash_content( $result['merged_content'] ) );
	}

	public function test_tampered_base_hash_is_rejected() {
		$base     = $this->base_content();
		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $base );

		$update                    = $this->client_update( $base, $proposed );
		$update['baseContentHash'] = hash( 'sha256', 'some other document' );

		$result = wp_de_rtc_get_automerge_retry_save_result( $base, $base, $proposed, $update );

		$this->assertWPError( $result );
		$this->assertSame( 'de_rtc_sync_meta_tampered', $result->get_error_code() );
	}

	public function test_update_not_matching_proposed_content_is_rejected() {
		$base     = $this->base_content();
		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $base );
		$other    = str_replace( 'Alpha block original', 'Alpha block DIFFERENT', $base );

		// Update authored for a different proposal than the one submitted.
		$update = $this->client_update( $base, $other );

		$result = wp_de_rtc_get_automerge_retry_save_result( $base, $base, $proposed, $update );

		$this->assertWPError( $result );
		$this->assertSame( 'de_rtc_sync_meta_tampered', $result->get_error_code() );
	}

	public function test_missing_client_update_is_server_generated() {
		$base     = $this->base_content();
		$server   = str_replace( 'Alpha block original', 'Alpha block SERVER-EDITED', $base );
		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $base );

		// DE-RTC accepts engine-unaware writers: no client update supplied.
		$result = wp_de_rtc_get_automerge_retry_save_result( $base, $server, $proposed, null );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Alpha block SERVER-EDITED', $result['merged_content'] );
		$this->assertStringContainsString( 'Beta block CLIENT-EDITED', $result['merged_content'] );
	}

	public function test_legacy_format_updates_are_rejected_upstream_faithfully() {
		if ( ! gutenberg_sync_engines_automerge_php_is_supported() ) {
			$this->markTestSkipped( 'automerge-php requires PHP 8.2+ with mbstring.' );
		}

		// The legacy native-automerge-php-v1 lane is dead code upstream:
		// NativePort::merge() accepts only {document, lastPostContentEdit}
		// pairs or Document instances, so operations-array updates fail its
		// replay check and are rejected as unverifiable. The port preserves
		// that behavior verbatim; the engine speaks only the block-native
		// format (the shipping DE-RTC save path).
		$base     = $this->base_content();
		$server   = str_replace( 'Alpha block original', 'Alpha block SERVER-EDITED', $base );
		$proposed = str_replace( 'Beta block original', 'Beta block CLIENT-EDITED', $base );

		$legacy_update = wp_de_rtc_create_legacy_automerge_update_for_content_change( $base, $proposed, 'client-actor' );
		$this->assertIsArray( $legacy_update );
		$this->assertSame( 'native-automerge-php-v1', $legacy_update['format'] );

		$result = wp_de_rtc_get_automerge_retry_save_result( $base, $server, $proposed, $legacy_update );

		$this->assertWPError( $result );
		$this->assertSame( 'de_rtc_sync_meta_tampered', $result->get_error_code() );
	}

	public function test_native_port_round_trip() {
		if ( ! gutenberg_sync_engines_automerge_php_is_supported() ) {
			$this->markTestSkipped( 'automerge-php requires PHP 8.2+ with mbstring.' );
		}

		$port = wp_de_rtc_get_automerge_native_port();
		$this->assertNotWPError( $port );

		$doc    = $port->from( array( 'postContent' => 'Hello' ), substr( md5( 'server' ), 0, 16 ) );
		$loaded = WordPress\DistributedEditing\Automerge\Document::load( $doc->save() );
		$value  = $loaded->toArray()['postContent'];

		$this->assertSame( 'Hello', (string) $value );
	}

	public function test_version_snapshots_accumulate_and_trim() {
		$sync_meta = array();
		$limit     = wp_de_rtc_get_automerge_version_snapshot_limit();

		for ( $i = 1; $i <= $limit + 2; $i++ ) {
			$sync_meta = wp_de_rtc_update_automerge_version_snapshots(
				$sync_meta,
				'v' . $i,
				'content version ' . $i,
				'v' . ( $i + 1 ),
				'content version ' . ( $i + 1 )
			);
		}

		$this->assertArrayHasKey( 'version_snapshots', $sync_meta );
		$this->assertLessThanOrEqual( $limit, count( $sync_meta['version_snapshots'] ) );
	}
}
