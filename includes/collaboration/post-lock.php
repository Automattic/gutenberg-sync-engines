<?php
/**
 * Keeps WordPress's post lock from blocking collaborative sessions.
 *
 * The lock itself stays: the post list shows who is editing, Quick Edit is
 * still refused, and the lock is cleaned up as usual. Only the two places
 * that would turn it into a blocking modal are neutralized, and only on a
 * screen that can actually collaborate. Deactivating the plugin brings the
 * modal back by itself.
 *
 * @package GutenbergSyncEngines
 */

/**
 * Tells the editor the post is not locked when the screen can collaborate.
 *
 * Runs in `block_editor_settings_all`, after the meta boxes registered, so
 * the screen verdict is complete. The second user never takes the lock:
 * `activePostLock` is left alone and no lock is set here.
 *
 * @since n.e.x.t
 *
 * @param array                   $settings             Editor settings.
 * @param WP_Block_Editor_Context $block_editor_context The current block editor context.
 * @return array Editor settings.
 */
function gutenberg_sync_engines_suppress_post_lock( $settings, $block_editor_context ) {
	if ( ! $block_editor_context instanceof WP_Block_Editor_Context || ! $block_editor_context->post instanceof WP_Post ) {
		return $settings;
	}

	$verdict = gutenberg_sync_engines_screen_verdict( $block_editor_context->post );
	if ( ! $verdict['supported'] ) {
		return $settings;
	}

	if ( isset( $settings['postLock'] ) && is_array( $settings['postLock'] ) ) {
		$settings['postLock']['isLocked'] = false;
	}

	return $settings;
}
add_filter( 'block_editor_settings_all', 'gutenberg_sync_engines_suppress_post_lock', 10, 2 );

/**
 * Drops the "taken over" error from the post lock heartbeat while
 * collaboration is on for the post's type.
 *
 * Core answers a lock refresh with `lock_error` when another user holds
 * the lock, which the editor turns into the takeover modal. Under
 * collaboration both users keep editing, so the error is removed. No
 * `new_lock` is added: the holder keeps the lock until they leave.
 *
 * @since n.e.x.t
 *
 * @param array $response The heartbeat response.
 * @param array $data     The data sent by the client.
 * @return array Modified heartbeat response.
 */
function gutenberg_sync_engines_strip_post_lock_error( $response, $data = array() ) {
	if ( empty( $response['wp-refresh-post-lock']['lock_error'] ) || ! gutenberg_sync_engines_is_enabled() ) {
		return $response;
	}

	$post_id = isset( $data['wp-refresh-post-lock']['post_id'] ) ? absint( $data['wp-refresh-post-lock']['post_id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post || gutenberg_sync_engines_is_post_type_disabled( $post->post_type ) ) {
		return $response;
	}

	unset( $response['wp-refresh-post-lock']['lock_error'] );

	return $response;
}
add_filter( 'heartbeat_received', 'gutenberg_sync_engines_strip_post_lock_error', 20, 2 );
