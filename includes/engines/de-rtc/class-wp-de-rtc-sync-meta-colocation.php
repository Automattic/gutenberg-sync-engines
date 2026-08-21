<?php
/**
 * DE-RTC sync-meta co-location with post_content.
 *
 * @package gutenberg-sync-engines
 */

if ( ! class_exists( 'WP_De_RTC_Sync_Meta_Colocation' ) ) {
	/**
	 * Ties DE-RTC sync metadata to saved post_content (the upstream
	 * vision's co-location rule: "sync metadata needed for
	 * version/pending-edit reconstruction must be tied to saved
	 * post_content").
	 *
	 * Every save of a post whose room carries de-rtc lineage gets the
	 * room's sync metadata embedded at the content's trailing edge as the
	 * upstream `data-wp-sync-meta` SCRIPT pseudo-block (the exact grammar
	 * `wp_de_rtc_parse_post_content_sync_meta()` reads back — genesis
	 * already adopts it). Because embedding happens in
	 * `wp_insert_post_data`, revisions copy it for free, which makes
	 * revisions the backup mechanism the vision prescribes: any writer
	 * that round-trips the post carries the version lineage with it, and
	 * recovery (self-healing, revision-mined bases) can mine revisions
	 * for the last-known state.
	 *
	 * Room state is read with a NON-creating storage lookup — the
	 * framework storage API's own room resolution creates storage posts,
	 * which a read-only save filter must never do.
	 */
	class WP_De_RTC_Sync_Meta_Colocation {

		/**
		 * Hooks the save-path embed.
		 *
		 * @since 0.5.0
		 *
		 * @return void
		 */
		public static function register(): void {
			add_filter( 'wp_insert_post_data', array( __CLASS__, 'embed_sync_meta' ), 20, 2 );
		}

		/**
		 * Embeds the room's sync metadata into the content being saved.
		 *
		 * Best-effort by design: a save must never fail or lose content
		 * because sync metadata could not be attached — every bail leaves
		 * the save untouched.
		 *
		 * @since 0.5.0
		 *
		 * @param array $data    Slashed, sanitized post data about to be saved.
		 * @param array $postarr Raw post array (carries ID on updates).
		 * @return array Post data, with sync meta embedded when applicable.
		 */
		public static function embed_sync_meta( array $data, array $postarr ): array {
			$post_id   = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
			$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';

			if (
				$post_id <= 0 ||
				'' === $post_type ||
				'revision' === $post_type ||
				( class_exists( 'WP_Sync_Post_Meta_Storage' ) && WP_Sync_Post_Meta_Storage::POST_TYPE === $post_type ) ||
				! function_exists( 'wp_de_rtc_format_sync_meta' )
			) {
				return $data;
			}

			$doc = self::room_doc_state( 'postType/' . $post_type . ':' . $post_id );
			if ( null === $doc ) {
				return $data;
			}

			$content  = (string) wp_unslash( $data['post_content'] );
			$stripped = $content;

			// Replace an existing embed (never accumulate); tolerate the
			// editor having wrapped it after a round-trip.
			if ( function_exists( 'wp_de_rtc_count_post_content_sync_meta_scripts' ) && wp_de_rtc_count_post_content_sync_meta_scripts( $content ) > 0 ) {
				$parsed = wp_de_rtc_parse_post_content_sync_meta( $content, array( 'allow_script_stripped_sync_meta' => true ) );
				if ( is_array( $parsed ) && is_string( $parsed['content'] ?? null ) ) {
					$stripped = $parsed['content'];
				}
			}

			$embed                     = is_array( $doc['sync_meta'] ?? null ) ? $doc['sync_meta'] : array();
			$embed['room_version']     = (string) $doc['version'];
			$embed['room_version_seq'] = (int) $doc['version_seq'];
			$embed['content_hash']     = wp_de_rtc_hash_content( $stripped );

			$script = wp_de_rtc_format_sync_meta( 'automerge', $embed );
			if ( is_wp_error( $script ) ) {
				return $data;
			}

			$data['post_content'] = wp_slash( rtrim( $stripped ) . "\n\n" . $script );

			return $data;
		}

		/**
		 * Reads a room's canonical doc state WITHOUT creating storage.
		 * (The framework storage API's own lookups create storage posts;
		 * read-only lanes — this embed, the base-version preflight — must
		 * never do that.)
		 *
		 * @since 0.5.0
		 *
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 * @param string $room Room identifier.
		 * @return array|null Doc state (version, version_seq, sync_meta, …),
		 *                    or null when the room has no de-rtc lineage.
		 */
		public static function room_doc_state( string $room ): ?array {
			global $wpdb;

			$storage_ids = get_posts(
				array(
					'post_type'      => 'wp_sync_storage',
					'post_status'    => 'publish',
					'name'           => md5( $room ),
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);
			if ( array() === $storage_ids ) {
				return null;
			}
			$storage_id = (int) $storage_ids[0];

			// Direct queries for cache hygiene, matching the framework
			// storage's own accessors (oldest engine stamp wins; newest
			// room-meta row wins).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
					$storage_id,
					'wp_sync_engine'
				)
			);
			if ( 'de-rtc' !== $engine ) {
				return null;
			}

			/*
			 * Canonical truth lives in the engine's chained options row
			 * (`<seq>|<json>`; the announce model's ordered store), with the
			 * legacy `de_rtc_doc` room meta as the pre-chain fallback.
			 */
			$doc_json = null;
			if ( class_exists( 'WP_Sync_Atomic_Option' ) ) {
				$chained = WP_Sync_Atomic_Option::read( $wpdb->prefix . 'sync_de_rtc_canonical_' . md5( $room ) );
				if ( is_string( $chained ) ) {
					$separator = strpos( $chained, '|' );
					if ( false !== $separator ) {
						$doc_json = substr( $chained, $separator + 1 );
					}
				}
			}
			if ( null === $doc_json ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$doc_json = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
						$storage_id,
						'wp_sync_room_meta_' . WP_De_RTC_Engine::META_DOC
					)
				);
			}
			if ( ! is_string( $doc_json ) || '' === $doc_json ) {
				return null;
			}

			$doc = json_decode( $doc_json, true );
			if ( ! is_array( $doc ) || ! is_string( $doc['version'] ?? null ) ) {
				return null;
			}

			return $doc;
		}
	}
}
