<?php
/**
 * REST API adjustments for real-time collaboration.
 *
 * @package GutenbergSyncEngines
 */

/**
 * Overrides the default REST controller for autosaves so draft autosaves
 * become revisions instead of updating the shared draft.
 *
 * When collaboration is enabled, regular draft autosaves are stored as
 * revisions instead of updating the parent post based on its lock and
 * author. Auto-drafts are still promoted to drafts.
 *
 * Only overrides when collaboration is enabled and
 * `autosave_rest_controller_class` is not explicitly set, i.e. when
 * WP_REST_Autosaves_Controller would be used by default. Post types with
 * their own specialized autosave controller (e.g. templates) are left alone.
 *
 * @since n.e.x.t
 *
 * @param array $args Array of arguments for registering a post type.
 * @return array Modified array of arguments.
 */
function gutenberg_sync_engines_override_autosaves_rest_controller( $args ) {
	if ( empty( $args['autosave_rest_controller_class'] ) && gutenberg_sync_engines_is_enabled() ) {
		$args['autosave_rest_controller_class'] = 'WP_Sync_Engines_REST_Autosaves_Controller';
	}
	return $args;
}
add_filter( 'register_post_type_args', 'gutenberg_sync_engines_override_autosaves_rest_controller', 10, 1 );
