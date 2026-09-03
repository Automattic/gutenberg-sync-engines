<?php
/**
 * Tests for DE-RTC sync-meta co-location with post_content:
 * the save path embeds the room's sync metadata, revisions carry it,
 * and genesis adopts it back — version lineage included.
 *
 * @package Gutenberg
 */

/**
 * @group collaboration
 */
class Tests_Collaboration_WpDeRtcSyncMetaColocation extends WP_UnitTestCase {
	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	const GENESIS_CONTENT = "<!-- wp:paragraph -->\n<p>Alpha block original text.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Beta block original text.</p>\n<!-- /wp:paragraph -->";

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$editor_id );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
		WP_De_RTC_Sync_Meta_Colocation::register();
	}

	private function engine(): WP_De_RTC_Engine {
		return new WP_De_RTC_Engine( new WP_Sync_Post_Meta_Storage() );
	}

	private function make_post(): int {
		return self::factory()->post->create(
			array(
				'post_author'  => self::$editor_id,
				'post_title'   => 'Co-location test post',
				'post_content' => self::GENESIS_CONTENT,
			)
		);
	}

	private function proposal( string $proposal_id, string $base_version, string $proposed ): array {
		return array(
			'data' => wp_json_encode(
				array(
					'proposalId'      => $proposal_id,
					'baseVersion'     => $base_version,
					'proposedContent' => $proposed,
					'clientUpdate'    => null,
				)
			),
			'type' => WP_De_RTC_Engine::UPDATE_TYPE_PROPOSAL,
		);
	}

	/**
	 * Bootstraps a de-rtc room for a post and advances it one version.
	 *
	 * @param int $post_id Post ID.
	 * @return string The room identifier.
	 */
	private function bootstrap_room( int $post_id ): string {
		$room   = 'postType/post:' . $post_id;
		$engine = $this->engine();
		$this->assertSame( $this->genesis( $post_id ), $engine->materialize( $room ) );

		$proposed = str_replace( 'Alpha block original text.', 'Alpha block collaborated text.', $this->genesis( $post_id ) );
		$result   = $engine->handle_updates( $room, 201, 0, array( $this->proposal( 'p-1', 'v1', $proposed ) ), array() );
		$this->assertSame( 'applied', $result['dispositions'][0]['status'] );
		$this->assertSame( 'v2', $result['dispositions'][0]['version'] );

		return $room;
	}

	public function test_save_embeds_sync_meta_for_de_rtc_rooms() {
		$post_id = $this->make_post();
		$this->bootstrap_room( $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => str_replace( 'Alpha block original text.', 'Alpha block collaborated text.', $this->genesis( $post_id ) ),
			)
		);

		$saved = get_post( $post_id )->post_content;
		$this->assertStringContainsString( 'data-wp-sync-meta="distributed-editing"', $saved );

		$parsed = wp_de_rtc_parse_post_content_sync_meta( $saved );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'trailer', $parsed['sync_meta_position'] );
		$this->assertSame( 'v2', $parsed['sync_meta']['room_version'] );
		$this->assertSame( 2, $parsed['sync_meta']['room_version_seq'] );
		$this->assertArrayHasKey( 'version_snapshots', $parsed['sync_meta'] );
		$this->assertStringContainsString( 'Alpha block collaborated text.', $parsed['content'] );
	}

	public function test_save_without_room_is_untouched() {
		$post_id = $this->make_post();

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => self::GENESIS_CONTENT,
			)
		);

		$this->assertStringNotContainsString( 'data-wp-sync-meta', get_post( $post_id )->post_content );
	}

	public function test_repeated_saves_do_not_accumulate_embeds() {
		$post_id = $this->make_post();
		$this->bootstrap_room( $post_id );

		wp_update_post( array( 'ID' => $post_id ) );
		$content_once = get_post( $post_id )->post_content;
		wp_update_post( array( 'ID' => $post_id ) );
		$content_twice = get_post( $post_id )->post_content;

		$this->assertSame( 1, wp_de_rtc_count_post_content_sync_meta_scripts( $content_once ) );
		$this->assertSame( 1, wp_de_rtc_count_post_content_sync_meta_scripts( $content_twice ) );
	}

	public function test_revisions_carry_the_embedded_sync_meta() {
		$post_id = $this->make_post();
		$this->bootstrap_room( $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => str_replace( 'Beta block original text.', 'Beta revised for the revision.', $this->genesis( $post_id ) ),
			)
		);

		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotEmpty( $revisions, 'The save must have produced a revision.' );
		$latest = array_shift( $revisions );
		$this->assertStringContainsString(
			'data-wp-sync-meta="distributed-editing"',
			$latest->post_content,
			'Revisions are the backup mechanism: they must carry the lineage.'
		);
	}

	public function test_genesis_resumes_lineage_from_embedded_meta() {
		$post_id = $this->make_post();
		$room    = $this->bootstrap_room( $post_id );

		// Save so post_content carries v2 lineage.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => str_replace( 'Alpha block original text.', 'Alpha block collaborated text.', $this->genesis( $post_id ) ),
			)
		);

		// Simulate a room reset (engine flip / stale-room cleanup): the
		// storage post disappears, the saved post is all that remains.
		$storage_ids = get_posts(
			array(
				'post_type'      => 'wp_sync_storage',
				'post_status'    => 'publish',
				'name'           => md5( $room ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotEmpty( $storage_ids );
		wp_delete_post( (int) $storage_ids[0], true );

		// A fresh engine re-runs genesis from the saved post: lineage must
		// RESUME at v2 (adopted), not restart at v1.
		$engine       = $this->engine();
		$materialized = $engine->materialize( $room );
		$this->assertStringContainsString( 'Alpha block collaborated text.', $materialized );
		$this->assertStringNotContainsString( 'data-wp-sync-meta', $materialized, 'Genesis must strip the pseudo-block from canonical content.' );

		$proposed = str_replace( 'Alpha block collaborated text.', 'Alpha block after the reset.', $materialized );
		$result   = $engine->handle_updates( $room, 202, 0, array( $this->proposal( 'p-2', 'v2', $proposed ) ), array() );
		$this->assertSame( 'applied', $result['dispositions'][0]['status'] );
		$this->assertSame( 'v3', $result['dispositions'][0]['version'], 'The resumed lineage must continue past the adopted version.' );
	}

	/**
	 * The room's genesis content: the saved post with every block stamped
	 * with its deterministic identity (what the room actually serves).
	 *
	 * @param int $post_id Post ID.
	 * @return string Stamped genesis content.
	 */
	private function genesis( int $post_id ): string {
		return WP_De_RTC_Block_Identity::stamp_genesis( self::GENESIS_CONTENT, $post_id );
	}
}
