<?php
/**
 * The editor announcement: what the server tells the client bundle about
 * collaboration on the current screen.
 *
 * @package GutenbergSyncEngines
 */

/**
 * Decides whether a post screen can collaborate, and why not otherwise.
 *
 * The verdict is what the editor reads to decide between collaboration and
 * the classic post lock, so it must only say "supported" when everything
 * the session needs is in place: the setting is on, an engine is resolved,
 * a transport is announced, the post type is allowed, and no meta box on
 * the screen is incompatible with collaboration.
 *
 * Memoized per post for the request: the announcement and the post lock
 * suppression both read it for the same screen.
 *
 * @since n.e.x.t
 *
 * @param WP_Post|null $post  The post being edited, or null.
 * @param bool         $reset Discard the memoized verdict (tests).
 * @return array{postType: ?string, postId: ?int, supported: bool, reason: string, lockedBy: ?array{name: string, avatar: string}} The verdict.
 */
function gutenberg_sync_engines_screen_verdict( $post, bool $reset = false ): array {
	static $cache = array();

	if ( $reset ) {
		$cache = array();
	}

	$post_id = $post instanceof WP_Post ? (int) $post->ID : 0;
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$verdict = array(
		'postType'  => $post instanceof WP_Post ? $post->post_type : null,
		'postId'    => $post instanceof WP_Post ? (int) $post->ID : null,
		'supported' => false,
		'reason'    => '',
		'lockedBy'  => null,
	);

	$reason = '';
	if ( ! gutenberg_sync_engines_is_enabled() ) {
		$reason = 'disabled';
	} elseif ( null === ( new WP_Sync_Engine_Registry( gutenberg_sync_engines_get_storage() ) )->get_engine_for_room( '' ) ) {
		$reason = 'no-engine';
	} elseif ( array() === wp_get_collaboration_transport_registry()->get_announced_slugs() ) {
		$reason = 'no-transport';
	} elseif ( ! $post instanceof WP_Post ) {
		$reason = 'no-post';
	} elseif ( gutenberg_sync_engines_is_post_type_disabled( $post->post_type ) ) {
		$reason = 'post-type-disabled';
	} elseif ( gutenberg_sync_engines_screen_has_incompatible_meta_box( $post ) ) {
		$reason = 'incompatible-meta-box';
	}

	$verdict['supported'] = '' === $reason;
	$verdict['reason']    = $reason;

	if ( $post instanceof WP_Post && function_exists( 'wp_check_post_lock' ) ) {
		$lock_user_id = wp_check_post_lock( $post->ID );
		$lock_user    = $lock_user_id ? get_userdata( $lock_user_id ) : false;
		if ( $lock_user ) {
			$verdict['lockedBy'] = array(
				'name'   => $lock_user->display_name,
				'avatar' => (string) get_avatar_url( $lock_user->ID, array( 'size' => 128 ) ),
			);
		}
	}

	$cache[ $post_id ] = $verdict;

	return $verdict;
}

/**
 * Whether the post's editor screen registers a meta box that cannot take
 * part in collaboration.
 *
 * Reads the same flags the editor used to: a box counts when it is
 * registered (not `false`), has a title, is not a back-compat box, and
 * does not declare `__rtc_compatible_meta_box`. The
 * `filter_block_editor_meta_boxes` filter is applied to a COPY of the
 * global, because core applies it again when it renders the boxes; the
 * plugin's own callback on that filter is idempotent for that reason.
 *
 * @since n.e.x.t
 *
 * @global array $wp_meta_boxes Registered meta boxes.
 *
 * @param WP_Post $post The post being edited.
 * @return bool Whether an incompatible meta box exists.
 */
function gutenberg_sync_engines_screen_has_incompatible_meta_box( WP_Post $post ): bool {
	global $wp_meta_boxes;

	if ( empty( $wp_meta_boxes ) || ! is_array( $wp_meta_boxes ) ) {
		return false;
	}

	$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$screen_id = $screen instanceof WP_Screen ? $screen->id : $post->post_type;

	/** This filter is documented in wp-admin/includes/post.php */
	$boxes = apply_filters( 'filter_block_editor_meta_boxes', $wp_meta_boxes ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

	if ( empty( $boxes[ $screen_id ] ) || ! is_array( $boxes[ $screen_id ] ) ) {
		return false;
	}

	foreach ( $boxes[ $screen_id ] as $priorities ) {
		foreach ( (array) $priorities as $priority_boxes ) {
			foreach ( (array) $priority_boxes as $meta_box ) {
				if ( false === $meta_box || empty( $meta_box['title'] ) ) {
					continue;
				}
				if ( ! empty( $meta_box['args']['__back_compat_meta_box'] ) ) {
					continue;
				}
				if ( ! empty( $meta_box['args']['__rtc_compatible_meta_box'] ) ) {
					continue;
				}
				return true;
			}
		}
	}

	return false;
}

/**
 * Builds the announcement the client bundle reads.
 *
 * @since n.e.x.t
 *
 * @param WP_Post|null $post The post being edited, or null.
 * @return array The announcement.
 */
function gutenberg_sync_engines_announcement( $post ): array {
	$registry           = new WP_Sync_Engine_Registry( gutenberg_sync_engines_get_storage() );
	$engine             = gutenberg_sync_engines_is_enabled() ? $registry->get_engine_for_room( '' ) : null;
	$transport_registry = wp_get_collaboration_transport_registry();
	$active_transport   = $transport_registry->get_transport( $transport_registry->get_active_slug() );
	$transports         = gutenberg_sync_engines_is_enabled() ? $transport_registry->get_announced_slugs() : array();

	$disabled_post_types = array_values(
		array_filter(
			get_post_types( array( 'show_in_rest' => true ) ),
			'gutenberg_sync_engines_is_post_type_disabled'
		)
	);

	return array(
		'engine'            => $engine ? $engine->get_slug() : '',
		'engineProtocol'    => $engine ? $engine->get_protocol_version() : 0,
		// Announced active FIRST; the client picks the first slug it can
		// provide (see wp_get_collaboration_transport()).
		'transports'        => $transports,
		'transportProtocol' => $active_transport ? $active_transport->get_protocol_version() : 1,
		/**
		 * Filters transport-specific connection metadata (for example a
		 * WebSocket URL) announced to the client, keyed by transport slug.
		 *
		 * @since n.e.x.t
		 *
		 * @param array    $config     Client config keyed by transport slug.
		 * @param string[] $transports Announced transport slugs.
		 */
		'transportConfig'   => (object) apply_filters( 'wp_sync_transport_client_config', array(), $transports ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inherited filter name.
		// Informational half of the actor id; the server stamps the
		// authoritative value from the authenticated request.
		'userId'            => get_current_user_id(),
		// UI hint only: restore/approval is enforced at ingest per the
		// authoring user's capability regardless of what the client shows.
		'canUnfilteredHtml' => current_user_can( 'unfiltered_html' ),
		'disabledPostTypes' => $disabled_post_types,
		'screen'            => gutenberg_sync_engines_screen_verdict( $post ),
	);
}

/**
 * Prints the announcement ahead of the client bundle.
 *
 * Hooked to `block_editor_settings_all` rather than the asset hook because
 * it runs after the screen's meta boxes are registered, so the verdict can
 * take them into account. The settings pass through unchanged.
 *
 * @since n.e.x.t
 *
 * @param array                   $settings             Editor settings.
 * @param WP_Block_Editor_Context $block_editor_context The current block editor context.
 * @return array The settings, unchanged.
 */
function gutenberg_sync_engines_print_announcement( $settings, $block_editor_context ) {
	if ( ! gutenberg_sync_engines_is_enabled() ) {
		return $settings;
	}

	$post = $block_editor_context instanceof WP_Block_Editor_Context ? $block_editor_context->post : null;

	wp_add_inline_script(
		'gutenberg-sync-engines',
		'window._gutenbergSyncEnginesSync = ' . wp_json_encode( gutenberg_sync_engines_announcement( $post ) ) . ';',
		'before'
	);

	return $settings;
}
add_filter( 'block_editor_settings_all', 'gutenberg_sync_engines_print_announcement', 10, 2 );
