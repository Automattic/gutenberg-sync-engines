<?php
/**
 * Engine-level tests for the server-authoritative Yjs sync engine
 * (WP_Yjs_Server_Engine), driving the production WP_Sync_Engine seam
 * against the postmeta storage with real y-php documents on both sides.
 *
 * @package Gutenberg
 */

/**
 * @group collaboration
 */
class Tests_Collaboration_WpYjsServerEngine extends WP_UnitTestCase {
	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Post ID used for room targets.
	 *
	 * @var int
	 */
	protected static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$post_id   = $factory->post->create(
			array(
				'post_author'  => self::$editor_id,
				'post_title'   => 'Yjs server test post',
				'post_content' => "<!-- wp:paragraph {\"align\":\"wide\"} -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->",
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$editor_id );
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
	 * @return WP_Yjs_Server_Engine Engine.
	 */
	private function engine(): WP_Yjs_Server_Engine {
		return new WP_Yjs_Server_Engine( new WP_Sync_Post_Meta_Storage() );
	}

	/**
	 * Builds a client-side y-php doc from a room response (applies snapshot
	 * and update rows in order).
	 *
	 * @param array $response Room response from get_updates_since().
	 * @return \Yjs\Utils\Doc Client document.
	 */
	private function client_doc_from_response( array $response ): \Yjs\Utils\Doc {
		$doc = new \Yjs\Utils\Doc();
		$this->apply_response( $doc, $response );
		return $doc;
	}

	/**
	 * Applies a room response's rows to a client doc.
	 *
	 * @param \Yjs\Utils\Doc $doc      Client document.
	 * @param array           $response Room response.
	 */
	private function apply_response( \Yjs\Utils\Doc $doc, array $response ): void {
		foreach ( $response['updates'] as $update ) {
			if ( WP_Yjs_Server_Engine::UPDATE_TYPE_SNAPSHOT === $update['type'] ) {
				$decoded = json_decode( $update['data'], true );
				\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $decoded['doc'] ) );
			} else {
				\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $update['data'] ) );
			}
		}
	}

	/**
	 * The content Y.Text of the first block in a client doc.
	 *
	 * @param \Yjs\Utils\Doc $doc Client document.
	 * @return \Yjs\Types\YText Content text.
	 */
	private function first_block_content( \Yjs\Utils\Doc $doc ): \Yjs\Types\YText {
		return $doc->getMap( 'document' )->get( 'blocks' )->get( 0 )->get( 'attributes' )->get( 'content' );
	}

	/**
	 * Encodes a client edit as the incremental V2 update a real client
	 * sends: everything past the pre-edit state vector.
	 *
	 * @param \Yjs\Utils\Doc $doc  Client document.
	 * @param callable        $edit Edit to perform on the doc.
	 * @return string Base64 V2 update.
	 */
	private function encode_edit( \Yjs\Utils\Doc $doc, callable $edit ): string {
		$sv_before = \Yjs\encodeStateVector( $doc );
		$edit( $doc );
		return \Yjs\encodeStateAsUpdateV2( $doc, $sv_before )->toBase64();
	}

	public function test_identity() {
		$engine = $this->engine();
		$this->assertSame( 'yjs-server', $engine->get_slug() );
		$this->assertSame( 1, $engine->get_protocol_version() );
		$this->assertSame( array( 'update', 'snapshot' ), $engine->get_update_types() );
	}

	public function test_genesis_snapshot_on_first_read() {
		$engine   = $this->engine();
		$response = $engine->get_updates_since( $this->room(), 101, 0, array() );

		$this->assertCount( 1, $response['updates'] );
		$this->assertSame( WP_Yjs_Server_Engine::UPDATE_TYPE_SNAPSHOT, $response['updates'][0]['type'] );
		$this->assertGreaterThan( 0, $response['end_cursor'] );

		$doc    = $this->client_doc_from_response( $response );
		$record = $doc->getMap( 'document' );

		$this->assertSame( 'Yjs server test post', $record->get( 'title' )->toString() );
		$this->assertSame( 1, $record->get( 'blocks' )->length );

		$block = $record->get( 'blocks' )->get( 0 );
		$this->assertSame( 'core/paragraph', $block->get( 'name' ) );
		$this->assertSame( 'wide', $block->get( 'attributes' )->get( 'align' ) );
		$this->assertSame( 'Hello world', $block->get( 'attributes' )->get( 'content' )->toString() );

		// The genesis row stamps the engine lineage.
		$storage = new WP_Sync_Post_Meta_Storage();
		$this->assertSame( 'yjs-server', $storage->get_room_engine( $this->room() ) );
	}

	public function test_update_merges_into_canonical_and_relays_to_peers() {
		$engine = $this->engine();

		// Client A bootstraps.
		$response_a = $engine->get_updates_since( $this->room(), 101, 0, array() );
		$doc_a      = $this->client_doc_from_response( $response_a );
		$cursor_a   = (int) $response_a['end_cursor'];

		// Client A types.
		$update = $this->encode_edit(
			$doc_a,
			function ( $doc ) {
				$this->first_block_content( $doc )->insert( 11, ', friends' );
			}
		);

		$result = $this->engine()->handle_updates( $this->room(), 101, $cursor_a, array(
			array(
				'type' => 'update',
				'data' => $update,
			),
		), array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( array( 'status' => 'applied' ) ), $result['dispositions'] );

		// Client B (fresh) sees genesis + the update and converges.
		$engine_b   = $this->engine();
		$response_b = $engine_b->get_updates_since( $this->room(), 202, 0, array() );
		$doc_b      = $this->client_doc_from_response( $response_b );
		$this->assertSame( 'Hello world, friends', $this->first_block_content( $doc_b )->toString() );

		// The canonical document materializes with the merged edit.
		$materialized = $this->engine()->materialize( $this->room() );
		$this->assertStringContainsString( '<p>Hello world, friends</p>', $materialized );
		$this->assertStringContainsString( '"align":"wide"', $materialized );
	}

	public function test_materialize_roundtrips_genesis_content() {
		$engine = $this->engine();
		$engine->get_updates_since( $this->room(), 101, 0, array() );

		$this->assertSame(
			get_post( self::$post_id )->post_content,
			$engine->materialize( $this->room() )
		);
	}

	public function test_redelivered_update_settles_as_already_merged() {
		$engine     = $this->engine();
		$response_a = $engine->get_updates_since( $this->room(), 101, 0, array() );
		$doc_a      = $this->client_doc_from_response( $response_a );

		$update = $this->encode_edit(
			$doc_a,
			function ( $doc ) {
				$this->first_block_content( $doc )->insert( 0, 'A' );
			}
		);
		$row    = array(
			'type' => 'update',
			'data' => $update,
		);

		$first = $this->engine()->handle_updates( $this->room(), 101, 0, array( $row ), array() );
		$this->assertSame( 'applied', $first['dispositions'][0]['status'] );

		$storage    = new WP_Sync_Post_Meta_Storage();
		$row_count  = $storage->get_update_count( $this->room() );
		$redelivery = $this->engine()->handle_updates( $this->room(), 101, 0, array( $row ), array() );

		$this->assertSame(
			array(
				array(
					'status' => 'voided',
					'reason' => 'already-merged',
				),
			),
			$redelivery['dispositions']
		);
		// Nothing new was stored.
		$this->assertSame( $row_count, ( new WP_Sync_Post_Meta_Storage() )->get_update_count( $this->room() ) );
	}

	public function test_malformed_update_voids_per_update_without_starving_the_batch() {
		$engine     = $this->engine();
		$response_a = $engine->get_updates_since( $this->room(), 101, 0, array() );
		$doc_a      = $this->client_doc_from_response( $response_a );

		$valid = $this->encode_edit(
			$doc_a,
			function ( $doc ) {
				$this->first_block_content( $doc )->insert( 0, 'B' );
			}
		);

		$result = $this->engine()->handle_updates( $this->room(), 101, 0, array(
			array(
				'type' => 'update',
				'data' => 'not!!valid@@base64',
			),
			array(
				'type' => 'update',
				'data' => base64_encode( 'valid base64, junk yjs bytes' ),
			),
			array(
				'type' => 'update',
				'data' => $valid,
			),
		), array() );

		$this->assertSame( 'voided', $result['dispositions'][0]['status'] );
		$this->assertSame( 'invalid-payload', $result['dispositions'][0]['reason'] );
		$this->assertSame( 'voided', $result['dispositions'][1]['status'] );
		$this->assertSame( 'invalid-payload', $result['dispositions'][1]['reason'] );
		$this->assertSame( 'applied', $result['dispositions'][2]['status'] );

		// The valid edit survived the malformed neighbors.
		$this->assertStringContainsString( 'BHello world', (string) $this->engine()->materialize( $this->room() ) );
	}

	public function test_rejects_non_update_types_from_clients() {
		$engine = $this->engine();
		$engine->get_updates_since( $this->room(), 101, 0, array() );

		$result = $engine->handle_updates( $this->room(), 101, 0, array(
			array(
				'type' => 'sync_step1',
				'data' => base64_encode( 'x' ),
			),
		), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_invalid_update_type', $result->get_error_code() );
	}

	public function test_own_update_rows_are_filtered_on_read() {
		$engine     = $this->engine();
		$response_a = $engine->get_updates_since( $this->room(), 101, 0, array() );
		$doc_a      = $this->client_doc_from_response( $response_a );
		$cursor_a   = (int) $response_a['end_cursor'];

		$update = $this->encode_edit(
			$doc_a,
			function ( $doc ) {
				$this->first_block_content( $doc )->insert( 0, 'C' );
			}
		);
		$this->engine()->handle_updates( $this->room(), 101, $cursor_a, array(
			array(
				'type' => 'update',
				'data' => $update,
			),
		), array() );

		$own = $this->engine()->get_updates_since( $this->room(), 101, $cursor_a, array() );
		$this->assertSame( array(), $own['updates'] );

		$peer = $this->engine()->get_updates_since( $this->room(), 202, $cursor_a, array() );
		$this->assertCount( 1, $peer['updates'] );
		$this->assertSame( 'update', $peer['updates'][0]['type'] );
	}

	public function test_server_checkpoints_trims_and_serves_stale_cursors_from_the_floor() {
		$interval = static function () {
			return 5;
		};
		add_filter( 'wp_sync_yjs_server_checkpoint_interval', $interval );

		try {
			$engine     = $this->engine();
			$response_a = $engine->get_updates_since( $this->room(), 101, 0, array() );
			$doc_a      = $this->client_doc_from_response( $response_a );
			$cursor_a   = (int) $response_a['end_cursor'];

			// Never nominated: the server compacts by itself.
			for ( $i = 0; $i < 12; $i++ ) {
				$update   = $this->encode_edit(
					$doc_a,
					function ( $doc ) use ( $i ) {
						$this->first_block_content( $doc )->insert( 0, 'x' . $i . ';' );
					}
				);
				$result   = $this->engine()->handle_updates( $this->room(), 101, $cursor_a, array(
					array(
						'type' => 'update',
						'data' => $update,
					),
				), array() );
				$this->assertSame( 'applied', $result['dispositions'][0]['status'] );
				$read     = $this->engine()->get_updates_since( $this->room(), 101, $cursor_a, array() );
				$cursor_a = (int) $read['end_cursor'];
				$this->assertFalse( $read['should_compact'] );
			}

			$storage = new WP_Sync_Post_Meta_Storage();
			$rows    = $storage->get_updates_after_cursor( $this->room(), 0 );

			// Checkpoint snapshots were appended by the server.
			$checkpoints = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						if ( WP_Yjs_Server_Engine::UPDATE_TYPE_SNAPSHOT !== $row['type'] ) {
							return false;
						}
						$decoded = json_decode( $row['data'], true );
						return is_array( $decoded ) && ! empty( $decoded['checkpoint'] );
					}
				)
			);
			$this->assertNotEmpty( $checkpoints );

			// History below the previous checkpoint was trimmed: the genesis
			// row is gone, and total row count stays bounded near the
			// interval instead of growing one row per edit.
			$this->assertLessThan( 12, count( $rows ) );
			$floor = $storage->get_room_meta( $this->room(), WP_Yjs_Server_Engine::META_FLOOR );
			$this->assertIsNumeric( $floor );

			// A cursor below the floor is served from the retained
			// checkpoint snapshot and still converges.
			$stale = $this->engine()->get_updates_since( $this->room(), 999, 1, array() );
			$this->assertSame( WP_Yjs_Server_Engine::UPDATE_TYPE_SNAPSHOT, $stale['updates'][0]['type'] );

			$doc_stale = $this->client_doc_from_response( $stale );
			$this->assertSame(
				$this->first_block_content( $doc_a )->toString(),
				$this->first_block_content( $doc_stale )->toString()
			);
		} finally {
			remove_filter( 'wp_sync_yjs_server_checkpoint_interval', $interval );
		}
	}

	public function test_concurrent_genesis_writers_merge_idempotently() {
		// Two engines race the same empty room: both build genesis. The
		// deterministic build (fixed per-room clientID, fixed op order) must
		// make the duplicate rows byte-identical so applying both cannot
		// duplicate content.
		$engine_a = $this->engine();
		$engine_b = $this->engine();

		$initialize = new ReflectionMethod( WP_Yjs_Server_Engine::class, 'initialize_room' );
		$initialize->setAccessible( true );

		$doc_a = new \Yjs\Utils\Doc();
		$doc_b = new \Yjs\Utils\Doc();
		$initialize->invoke( $engine_a, $this->room(), $doc_a );
		$initialize->invoke( $engine_b, $this->room(), $doc_b );

		$storage = new WP_Sync_Post_Meta_Storage();
		$rows    = $storage->get_updates_after_cursor( $this->room(), 0 );
		$this->assertCount( 2, $rows );
		$this->assertSame( $rows[0]['data'], $rows[1]['data'] );

		// A client applying BOTH genesis rows converges to one paragraph.
		$doc = new \Yjs\Utils\Doc();
		foreach ( $rows as $row ) {
			$decoded = json_decode( $row['data'], true );
			\Yjs\applyUpdateV2( $doc, \Yjs\Lib0\Buffer::fromBase64( $decoded['doc'] ) );
		}
		$this->assertSame( 1, $doc->getMap( 'document' )->get( 'blocks' )->length );
	}
}
