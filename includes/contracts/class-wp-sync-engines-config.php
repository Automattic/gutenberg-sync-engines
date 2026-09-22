<?php
/**
 * WP_Sync_Engines_Config class
 *
 * @package GutenbergSyncEngines
 */

if ( ! class_exists( 'WP_Sync_Engines_Config' ) ) {

	/**
	 * Configuration helpers for sync entities.
	 *
	 * @since 7.1.0
	 * @access private
	 */
	class WP_Sync_Engines_Config {
		/**
		 * Entity sync configuration.
		 *
		 * Used to convert sync entity rooms (e.g. `postType/post:100`) to
		 * entity types and reject sync or save requests for unsupported entity
		 * types.
		 *
		 * @since 7.1.0
		 * @var array
		 */
		const ENTITY_CONFIG = array(
			'postType' => array(
				'object_type' => 'post',
			),
		);

		/**
		 * Parses a sync room identifier into entity parts.
		 *
		 * @since 7.1.0
		 *
		 * @param string $room Room identifier.
		 * @return array{entity_kind:string, entity_name:string, object_id:string|null}|null Parsed room, or null if invalid.
		 */
		public static function parse_room( string $room ): ?array {
			$type_parts = explode( '/', $room, 2 );
			if ( 2 !== count( $type_parts ) || '' === $type_parts[0] || '' === $type_parts[1] ) {
				return null;
			}

			if ( str_contains( $type_parts[1], '/' ) ) {
				return null;
			}

			$object_parts = explode( ':', $type_parts[1], 2 );
			if ( '' === $object_parts[0] ) {
				return null;
			}

			$object_id = $object_parts[1] ?? null;
			if ( '' === $object_id ) {
				return null;
			}

			return array(
				'entity_kind' => $type_parts[0],
				'entity_name' => $object_parts[0],
				'object_id'   => $object_id,
			);
		}

		/**
		 * Checks if the current user can sync a specific entity type.
		 *
		 * @since 7.1.0
		 *
		 * @param string      $entity_kind The entity kind, e.g. 'postType', 'taxonomy', 'root'.
		 * @param string      $entity_name The entity name, e.g. 'post', 'category', 'site'.
		 * @param string|null $object_id   The numeric object ID / entity key for single entities, null for collections.
		 * @return bool True if user has permission, otherwise false.
		 */
		public static function can_user_sync_entity_type( string $entity_kind, string $entity_name, ?string $object_id ): bool {
			if ( is_string( $object_id ) ) {
				if ( ! ctype_digit( $object_id ) ) {
					return false;
				}
				$object_id = (int) $object_id;
			}

			if ( null !== $object_id && $object_id <= 0 ) {
				return false;
			}

			if ( is_int( $object_id ) ) {
				if ( 'postType' === $entity_kind ) {
					if ( get_post_type( $object_id ) !== $entity_name ) {
						return false;
					}
					return current_user_can( 'edit_post', $object_id );
				}

				if ( 'taxonomy' === $entity_kind ) {
					$term_exists = term_exists( $object_id, $entity_name );
					if ( ! is_array( $term_exists ) || ! isset( $term_exists['term_id'] ) ) {
						return false;
					}

					return current_user_can( 'edit_term', $object_id );
				}

				if ( 'root' === $entity_kind && 'comment' === $entity_name ) {
					return current_user_can( 'edit_comment', $object_id );
				}
			}

			if ( null !== $object_id ) {
				return false;
			}

			if ( 'postType' === $entity_kind ) {
				$post_type_object = get_post_type_object( $entity_name );
				if ( ! isset( $post_type_object->cap->edit_posts ) ) {
					return false;
				}

				return current_user_can( $post_type_object->cap->edit_posts );
			}

			$allowed_collection_entity_kinds = array(
				'postType',
				'root',
				'taxonomy',
			);

			return in_array( $entity_kind, $allowed_collection_entity_kinds, true );
		}

		/**
		 * Gets the object type of an entity kind.
		 *
		 * @since 7.1.0
		 *
		 * @param string $entity_kind The entity kind.
		 * @return string|null Object type, or null if not supported.
		 */
		private static function get_object_type( string $entity_kind ): ?string {
			return self::ENTITY_CONFIG[ $entity_kind ]['object_type'] ?? null;
		}
	}
}
