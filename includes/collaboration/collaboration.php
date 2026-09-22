<?php
/**
 * Collaboration bootstrap: the enable setting, storage and transport
 * lookup, the storage post type, the REST routes, and the post list
 * behavior while several people edit one post.
 *
 * These functions used to live in Gutenberg's collaboration library. The
 * plugin owns them now, under its own names, so they can never collide
 * with a Gutenberg release that still ships the old experiment.
 *
 * @package GutenbergSyncEngines
 */

/**
 * The option that turns real-time collaboration on or off for the site.
 *
 * @since n.e.x.t
 */
const GUTENBERG_SYNC_ENGINES_ENABLED_OPTION = 'gutenberg_sync_engines_enabled';

/**
 * Whether real-time collaboration is turned on for this site.
 *
 * Reads the plugin's own setting (on by default; the Settings >
 * Collaboration screen and `wp collaboration enable|disable` change it).
 *
 * @since n.e.x.t
 *
 * @return bool Whether collaboration is enabled.
 */
function gutenberg_sync_engines_is_enabled(): bool {
	return (bool) get_option( GUTENBERG_SYNC_ENGINES_ENABLED_OPTION, true );
}

/**
 * Registers the enable setting so the REST settings endpoint and the
 * settings screen can read and write it.
 *
 * @since n.e.x.t
 *
 * @return void
 */
function gutenberg_sync_engines_register_enabled_option(): void {
	register_setting(
		'gutenberg-sync-engines',
		GUTENBERG_SYNC_ENGINES_ENABLED_OPTION,
		array(
			'type'              => 'boolean',
			'description'       => __( 'Whether real-time collaboration is turned on.', 'gutenberg-sync-engines' ),
			'sanitize_callback' => 'rest_sanitize_boolean',
			'show_in_rest'      => true,
			'default'           => true,
		)
	);
}
add_action( 'init', 'gutenberg_sync_engines_register_enabled_option' );

/**
 * Determines whether real-time collaboration is disabled for a post type.
 *
 * @since n.e.x.t
 *
 * @param string $post_type Post type name.
 * @return bool Whether real-time collaboration is disabled for the post type.
 */
function gutenberg_sync_engines_is_post_type_disabled( $post_type ): bool {
	if ( ! post_type_exists( $post_type ) ) {
		return true;
	}

	/**
	 * Filters whether real-time collaboration is disabled for a post type.
	 *
	 * @since n.e.x.t
	 *
	 * @param bool   $disabled  Whether real-time collaboration is disabled for the post type.
	 * @param string $post_type Post type name.
	 */
	return (bool) apply_filters( 'wp_is_post_type_collaboration_disabled', false, $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inherited filter name.
}

/**
 * The room/update storage the collaboration stack reads and writes.
 *
 * Filterable so a plugin can substitute a different backend (an object
 * cache, Redis, a dedicated table, an external service) by returning its
 * own WP_Sync_Engines_Storage implementation. Every engine and transport
 * obtains storage here, so a substitution applies everywhere at once. A
 * substitute must uphold the contract documented on the interface:
 * per-room cursors that only ever grow (and survive trims), a write
 * acknowledged to one request visible to the next on any server, and a
 * write-once engine lineage stamp.
 *
 * Built fresh per call, like the registries: the default storage keeps
 * per-request in-memory caches that must not outlive a request.
 *
 * @since n.e.x.t
 *
 * @return WP_Sync_Engines_Storage Storage implementation.
 */
function gutenberg_sync_engines_get_storage(): WP_Sync_Engines_Storage {
	/**
	 * Filters the sync storage implementation for collaborative editing.
	 *
	 * The plugin itself substitutes its table storage here (see
	 * Gutenberg_Sync_Engines_Plugin::filter_sync_storage()).
	 *
	 * @since n.e.x.t
	 *
	 * @param WP_Sync_Engines_Storage $storage Storage implementation.
	 */
	$storage = apply_filters( '__unstable_wp_sync_storage', new WP_Sync_Engines_Post_Meta_Storage() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inherited filter name.

	if ( ! $storage instanceof WP_Sync_Engines_Storage ) {
		$storage = new WP_Sync_Engines_Post_Meta_Storage();
	}

	return $storage;
}

/**
 * The single config value that selects the active collaboration
 * transport. One source of truth: the `WP_COLLABORATION_TRANSPORT`
 * constant, else the environment variable of the same name, else the
 * `wp_collaboration_transport` filter, defaulting to HTTP polling. The
 * value must be a registered transport slug; an unknown value falls back
 * to the default in the registry.
 *
 * @since n.e.x.t
 *
 * @return string Configured transport slug.
 */
function wp_get_collaboration_transport(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Inherited name.
	$transport = 'http-polling';
	if ( defined( 'WP_COLLABORATION_TRANSPORT' ) && is_string( WP_COLLABORATION_TRANSPORT ) && '' !== WP_COLLABORATION_TRANSPORT ) {
		$transport = WP_COLLABORATION_TRANSPORT;
	} else {
		$from_env = getenv( 'WP_COLLABORATION_TRANSPORT' );
		if ( is_string( $from_env ) && '' !== $from_env ) {
			$transport = $from_env;
		}
	}

	/**
	 * Filters the active collaboration transport slug.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $transport Transport slug.
	 */
	return (string) apply_filters( 'wp_collaboration_transport', $transport ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inherited filter name.
}

/**
 * Builds a transport registry over the default storage and engine
 * registry. Built fresh per call: the storage keeps per-request in-memory
 * caches (room cursors, storage post ids) that must not outlive a request,
 * so this is never memoized.
 *
 * @since n.e.x.t
 *
 * @return WP_Sync_Transport_Registry Transport registry.
 */
function wp_get_collaboration_transport_registry(): WP_Sync_Transport_Registry { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Inherited name.
	$storage = gutenberg_sync_engines_get_storage();
	$engines = new WP_Sync_Engine_Registry( $storage );
	return new WP_Sync_Transport_Registry( $storage, $engines );
}

/**
 * Registers the custom post type the post-meta storage keeps rooms in.
 *
 * @since n.e.x.t
 *
 * @return void
 */
function gutenberg_sync_engines_register_storage_post_type(): void {
	if ( ! gutenberg_sync_engines_is_enabled() ) {
		return;
	}

	register_post_type(
		'wp_sync_storage', // phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.ReservedPrefix -- Inherited post type slug; existing rows keep it.
		array(
			'labels'             => array(
				'name'          => __( 'Sync Updates', 'gutenberg-sync-engines' ),
				'singular_name' => __( 'Sync Update', 'gutenberg-sync-engines' ),
			),
			'public'             => false,
			'hierarchical'       => false,
			'capabilities'       => array(
				'read'                   => 'do_not_allow',
				'read_private_posts'     => 'do_not_allow',
				'create_posts'           => 'do_not_allow',
				'publish_posts'          => 'do_not_allow',
				'edit_posts'             => 'do_not_allow',
				'edit_others_posts'      => 'do_not_allow',
				'edit_published_posts'   => 'do_not_allow',
				'delete_posts'           => 'do_not_allow',
				'delete_others_posts'    => 'do_not_allow',
				'delete_published_posts' => 'do_not_allow',
			),
			'map_meta_cap'       => false,
			'publicly_queryable' => false,
			'query_var'          => false,
			'rewrite'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'show_ui'            => false,
			'supports'           => array( 'custom-fields' ),
		)
	);
}
add_action( 'init', 'gutenberg_sync_engines_register_storage_post_type' );

/**
 * Registers the REST API routes of every registered transport. The client
 * uses the one the server announces as active.
 *
 * @since n.e.x.t
 *
 * @return void
 */
function gutenberg_sync_engines_register_rest_routes(): void {
	if ( ! gutenberg_sync_engines_is_enabled() ) {
		return;
	}

	wp_get_collaboration_transport_registry()->register_all_routes();
}
add_action( 'rest_api_init', 'gutenberg_sync_engines_register_rest_routes' );

/**
 * Returns the user ID recorded in a fresh edit lock.
 *
 * Unlike wp_check_post_lock(), this includes locks owned by the current user.
 *
 * @since n.e.x.t
 *
 * @param int $post_id Post ID.
 * @return int User ID from a fresh lock, or 0 if none exists.
 */
function gutenberg_sync_engines_get_active_edit_lock_user( $post_id ): int {
	$lock = get_post_meta( $post_id, '_edit_lock', true );
	if ( ! $lock ) {
		return 0;
	}

	$lock = explode( ':', $lock );
	$time = (int) $lock[0];
	$user = isset( $lock[1] ) ? (int) $lock[1] : (int) get_post_meta( $post_id, '_edit_last', true );

	if ( ! $time || ! $user || ! get_userdata( $user ) ) {
		return 0;
	}

	/** This filter is documented in wp-admin/includes/ajax-actions.php */
	$time_window = apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

	if ( $time > time() - $time_window ) {
		return $user;
	}

	return 0;
}

/**
 * Modifies the post list UI and heartbeat responses for real-time collaboration.
 *
 * When collaboration is enabled, hides the lock icon and user avatar,
 * replaces the user-specific lock text with "Currently being edited",
 * changes the "Edit" row action to "Join", and re-enables bulk-edit
 * checkboxes that core normally hides for locked posts (Quick Edit
 * intentionally stays hidden, as it is not collaboration-aware).
 *
 * @since n.e.x.t
 *
 * @global string $pagenow The filename of the current screen.
 *
 * @return void
 */
function gutenberg_sync_engines_post_list_ui(): void {
	global $pagenow;

	if ( ! gutenberg_sync_engines_is_enabled() ) {
		return;
	}

	// Heartbeat filter applies globally (not just edit.php) since the
	// heartbeat API can fire from any admin page.
	add_filter( 'heartbeat_received', 'gutenberg_sync_engines_filter_locked_posts_heartbeat', 20, 2 );

	// Register globally because Quick Edit submits `action=inline-save` through admin-ajax.php.
	add_action( 'wp_ajax_inline-save', 'gutenberg_sync_engines_block_quick_edit_for_active_lock', 0 );

	// CSS, JS, and row action overrides only apply on the posts list page.
	if ( 'edit.php' !== $pagenow ) {
		return;
	}

	add_action( 'admin_head', 'gutenberg_sync_engines_post_list_styles' );
	add_filter( 'gettext', 'gutenberg_sync_engines_filter_locked_post_text', 10, 3 );
	add_filter( 'post_row_actions', 'gutenberg_sync_engines_post_list_row_actions', 10, 2 );
	add_filter( 'page_row_actions', 'gutenberg_sync_engines_post_list_row_actions', 10, 2 );
}
add_action( 'admin_init', 'gutenberg_sync_engines_post_list_ui' );

/**
 * Removes user-specific details from post lock heartbeat responses and adds
 * fresh locks owned by the current user when collaboration is enabled.
 *
 * Core populates other-user lock data at priority 10 and excludes locks owned
 * by the current user. This filter runs at priority 20 to replace those details
 * with generic text and add the current user's own locks.
 *
 * @since n.e.x.t
 *
 * @param array $response The heartbeat response.
 * @param array $data     The data sent by the client.
 * @return array Modified heartbeat response.
 */
function gutenberg_sync_engines_filter_locked_posts_heartbeat( $response, $data = array() ) {
	if ( ! empty( $response['wp-check-locked-posts'] ) ) {
		foreach ( $response['wp-check-locked-posts'] as $key => $lock_data ) {
			$response['wp-check-locked-posts'][ $key ]['text'] = __( 'Currently being edited', 'gutenberg-sync-engines' );
			unset( $response['wp-check-locked-posts'][ $key ]['avatar_src'] );
			unset( $response['wp-check-locked-posts'][ $key ]['avatar_src_2x'] );
		}
	}

	if ( ! empty( $data['wp-check-locked-posts'] ) && is_array( $data['wp-check-locked-posts'] ) ) {
		foreach ( $data['wp-check-locked-posts'] as $key ) {
			if ( isset( $response['wp-check-locked-posts'][ $key ] ) ) {
				continue;
			}

			$post_id = absint( substr( $key, 5 ) );
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || gutenberg_sync_engines_is_post_type_disabled( $post->post_type ) ) {
				continue;
			}

			$lock_user = gutenberg_sync_engines_get_active_edit_lock_user( $post_id );
			if ( $lock_user && get_current_user_id() === $lock_user ) {
				$response['wp-check-locked-posts'][ $key ] = array(
					'text' => __( 'Currently being edited', 'gutenberg-sync-engines' ),
				);
			}
		}
	}

	return $response;
}

/**
 * Rejects Quick Edit while the current user holds a fresh edit lock.
 *
 * Core handles locks owned by other users but excludes the current user's
 * locks. Rejecting them prevents Quick Edit changes from diverging from the
 * editing session. The server check also covers post lists loaded before the
 * lock was created.
 *
 * @since n.e.x.t
 *
 * @return void
 */
function gutenberg_sync_engines_block_quick_edit_for_active_lock(): void {
	check_ajax_referer( 'inlineeditnonce', '_inline_edit' );

	$post_id = isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0;
	if ( ! $post_id ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post || gutenberg_sync_engines_is_post_type_disabled( $post->post_type ) ) {
		return;
	}

	$lock_user = gutenberg_sync_engines_get_active_edit_lock_user( $post_id );
	if ( ! $lock_user ) {
		/*
		 * Core creates a lock during inline save. Prevent that specific write
		 * so a later Quick Edit is not mistaken for an active editor session.
		 */
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) use ( $post_id ) {
				if ( $post_id === (int) $object_id && '_edit_lock' === $meta_key ) {
					return false;
				}

				return $check;
			},
			10,
			3
		);
		return;
	}

	if ( get_current_user_id() !== $lock_user ) {
		// Core handles locks owned by another user.
		return;
	}

	wp_die( esc_html__( 'Quick Edit is disabled: You are currently editing this post in another tab or window.', 'gutenberg-sync-engines' ) );
}

/**
 * Outputs CSS to hide the post lock icon and user avatar in the post list
 * when real-time collaboration is enabled.
 *
 * Also re-enables checkboxes that WordPress core hides for locked posts, since
 * collaborative editing means the post is not exclusively locked. It toggles
 * "Edit" / "Join" action link text using the `.wp-locked` class managed by
 * heartbeat.
 *
 * @since n.e.x.t
 *
 * @return void
 */
function gutenberg_sync_engines_post_list_styles(): void {
	?>
	<style type="text/css">
		/*
		 * Hide the lock indicator icon in the checkbox column.
		 * WordPress core shows it via .wp-locked .locked-indicator { display: block },
		 * so we match that specificity to override it.
		 */
		.wp-locked .locked-indicator {
			display: none;
		}
		/* Hide the user avatar in the locked info area. */
		.wp-locked .locked-info .locked-avatar {
			display: none;
		}
		/*
		 * Re-enable bulk-edit checkboxes that core hides for locked posts,
		 * since collaboration allows several people to edit at once.
		 * Must use `tr.wp-locked` to match core's specificity in
		 * list-tables.css and actually override its `display: none`.
		 * Quick Edit intentionally stays hidden: it is not collaboration-aware,
		 * so edits made through it diverge from the content in an active editor session.
		 */
		tr.wp-locked .check-column label,
		tr.wp-locked .check-column input[type="checkbox"] {
			display: revert;
		}
		/*
		 * Toggle "Edit" / "Join" action link text based on lock state.
		 * The heartbeat adds/removes .wp-locked on locked rows. This
		 * CSS only runs when collaboration is enabled, so .wp-locked here
		 * always means collaborative editing, not exclusive locking.
		 */
		.join-action-text {
			display: none;
		}
		.wp-locked .edit-action-text {
			display: none;
		}
		.wp-locked .join-action-text {
			display: inline;
		}
	</style>
	<?php
}

/**
 * Filters the translation of the lock text to replace user-specific
 * "%s is currently editing" with a generic "Currently being edited"
 * message on initial page render.
 *
 * WordPress core outputs this text server-side in WP_Posts_List_Table.
 * Using a gettext filter replaces it before it reaches the browser,
 * avoiding a flash of the original text.
 *
 * @since n.e.x.t
 *
 * @param string $translation Translated text.
 * @param string $text        Original text to translate.
 * @param string $domain      Text domain.
 * @return string Modified translation.
 */
function gutenberg_sync_engines_filter_locked_post_text( $translation, $text, $domain ) {
	if ( 'default' === $domain && '%s is currently editing' === $text ) {
		return __( 'Currently being edited', 'gutenberg-sync-engines' );
	}

	return $translation;
}

/**
 * Filters post row actions to render both "Edit" and "Join" link text
 * when real-time collaboration is enabled.
 *
 * Both labels are always present in the markup; CSS toggles visibility using
 * the `.wp-locked` class managed by heartbeat. This updates the link text when
 * the lock state changes without requiring a page reload.
 *
 * @since n.e.x.t
 *
 * @param string[] $actions An array of row action links.
 * @param WP_Post  $post    The post object.
 * @return string[] Modified row action links.
 */
function gutenberg_sync_engines_post_list_row_actions( $actions, $post ) {
	if ( ! isset( $actions['edit'] ) ) {
		return $actions;
	}

	if ( gutenberg_sync_engines_is_post_type_disabled( $post->post_type ) ) {
		return $actions;
	}

	$title = _draft_or_post_title( $post->ID );

	/*
	 * Each state is rendered as `<span class="…-action-text"><a>…</a></span>`.
	 * The toggle classes sit on the outer <span> rather than the <a> so they
	 * fall outside core's responsive selector `.row-actions span a` at
	 * <=782px, which otherwise outranks our class selectors and (a) leaves
	 * both labels visible on unlocked rows and (b) forces `display: inline`
	 * on the visible Join link to misalign with sibling row actions. The
	 * visible label is still a direct text child of <a>, so core's mobile
	 * font-size rule
	 *     .row-actions span   { font-size: 0;  }
	 *     .row-actions span a { font-size: 13px; }
	 * still reaches the visible label. CSS in
	 * gutenberg_sync_engines_post_list_styles() flips visibility on the
	 * outer spans based on the row's `wp-locked` class, which core's
	 * inline-edit-post.js maintains in response to heartbeat ticks.
	 */
	$actions['edit'] = sprintf(
		'<span class="edit-action-text"><a href="%1$s" aria-label="%2$s">%3$s</a></span>'
		. '<span class="join-action-text"><a href="%1$s" aria-label="%4$s">%5$s</a></span>',
		esc_url( get_edit_post_link( $post->ID ) ),
		/* translators: %s: Post title. */
		esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;', 'default' ), $title ) ),
		__( 'Edit', 'default' ),
		/* translators: %s: Post title. */
		esc_attr( sprintf( __( 'Join editing &#8220;%s&#8221;', 'gutenberg-sync-engines' ), $title ) ),
		/* translators: Action link text for a singular post in the post list. Can be any type of post. */
		_x( 'Join', 'post list', 'gutenberg-sync-engines' )
	);

	return $actions;
}
