<?php
/**
 * DE-RTC merge core: the block-aware three-way merge engine.
 *
 * Ported VERBATIM from the Gutenberg chriszarate/refreshed-de-rtc branch
 * (lib/compat/wordpress-7.1/distributed-editing/de-rtc.php, itself a
 * verbatim port of wordpress-develop add/distributed-editing, PR
 * WordPress/wordpress-develop#12334). This file contains the exact
 * call-graph closure of the engine-facing entry points — update
 * construction/normalization, the serialized-block and block-identity
 * three-way merges, the rich-text merge model, version snapshots,
 * sync-meta format/parse, canonicalization and hashing — extracted
 * token-exactly from the 28k-line original. Function names, signatures,
 * and bodies are unchanged so future diffs against upstream stay
 * mechanical.
 *
 * Deliberate deltas from upstream (the ONLY edits):
 * - wp_de_rtc_get_automerge_runtime_status() points at the plugin's
 *   vendored library (includes/lib/automerge-php) instead of a sibling
 *   directory.
 * - wp_de_rtc_load_automerge_runtime() delegates autoloader
 *   registration to gutenberg_sync_engines_load_automerge_php().
 *
 * Treat this file like the frozen intent-log core: do not edit casually;
 * changes must stay in lockstep with the client-side descriptor builder
 * (src/engines/de-rtc/) and with upstream DE-RTC. Excluded from phpcs
 * (upstream wordpress-develop style, core text domain).
 *
 * The caller must guard the require on
 * `! function_exists( 'wp_de_rtc_get_reason_codes' )` so a future Core
 * or Gutenberg build that ships DE-RTC itself wins without fatals.
 *
 * @package GutenbergSyncEngines
 */

/**
 * Returns the canonical Distributed Editing reason-code status map.
 *
 * This helper is intentionally inert. It only defines the authority vocabulary
 * for future DE-RTC server responses and does not register endpoints, settings,
 * filters, schema, save-path behavior, or post-lock behavior.
 *
 * @since 7.1.0
 *
 * @return int[] HTTP status codes keyed by canonical reason code.
 */
function wp_de_rtc_get_reason_codes() {
	return array(
		'de_rtc_missing_sync_meta'                     => 409,
		'de_rtc_sync_meta_restored_from_revision'      => 409,
		'de_rtc_sync_meta_repaired_from_body'          => 409,
		'de_rtc_sync_meta_empty_automerge_import'            => 409,
		'de_rtc_sync_meta_unrecoverable'               => 409,
		'de_rtc_external_content_mismatch'             => 409,
		'de_rtc_base_version_stale'                    => 409,
		'stale_base_version_rejected'                  => 409,
		'de_rtc_live_session_newer_than_restored_meta' => 409,
		'de_rtc_rebase_failed'                         => 409,
		'de_rtc_sync_meta_tampered'                    => 403,
		'de_rtc_unfiltered_html_would_change_content'  => 403,
		'de_rtc_review_approval_requires_unfiltered_html' => 403,
		'de_rtc_feature_disabled'                      => 403,
		'de_rtc_malformed_sync_payload'                => 400,
		'de_rtc_unknown_sync_meta_format'              => 400,
		'de_rtc_presence_storage_unavailable'          => 503,
		'de_rtc_review_item_storage_unavailable'       => 503,
		'de_rtc_review_item_limit_exceeded'            => 429,
		'de_rtc_review_item_payload_too_large'         => 413,
		'de_rtc_review_item_not_found'                 => 404,
		'de_rtc_storage_failure'                       => 500,
	);
}

/**
 * Returns supported Distributed Editing sync-meta format labels.
 *
 * These labels identify the payload grammar only. This helper does not choose
 * or initialize any synchronization algorithm.
 *
 * @since 7.1.0
 *
 * @return string[] Supported sync-meta format labels.
 */
function wp_de_rtc_get_supported_sync_meta_formats() {
	return array(
		'diff-match-patch',
		'automerge',
	);
}

/**
 * Removes whitespace-only freeform records from a parsed block tree.
 *
 * The older partial-safe writer rebuilt documents from serialized block tokens,
 * so empty separators were not durable save evidence. Keeping that canonical
 * shape prevents a review-only rejection from rewriting a post solely because
 * `parse_blocks()` preserved formatting whitespace around the unsafe block.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $blocks Parsed block list.
 * @return array Parsed block list without empty freeform records.
 */
function wp_de_rtc_remove_empty_freeform_blocks( $blocks ) {
	$next_blocks = array();

	foreach ( (array) $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$serialized_block = serialize_block( $block );

		if ( empty( $block['blockName'] ) && '' === trim( $serialized_block ) ) {
			continue;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = wp_de_rtc_remove_empty_freeform_blocks( $block['innerBlocks'] );
		}

		$next_blocks[] = $block;
	}

	return $next_blocks;
}

/**
 * Returns runtime availability for the native PHP Automerge integration.
 *
 * The pinned port intentionally uses PHP 8.2 syntax. WordPress still supports
 * older PHP versions, so the server must check the version before registering
 * the autoloader or touching any class file from the vendored runtime.
 *
 * @since 7.1.0
 *
 * @return array Availability details.
 */
function wp_de_rtc_get_automerge_runtime_status() {
	// DELTA from upstream: the library is vendored at includes/lib/automerge-php.
	$library_path = dirname( __DIR__, 2 ) . '/lib/automerge-php/src';

	return array(
		'available'        => PHP_VERSION_ID >= 80200 && function_exists( 'mb_convert_encoding' ) && file_exists( $library_path . '/NativePort.php' ),
		'php_version_id'   => PHP_VERSION_ID,
		'required_version' => 80200,
		'mbstring_loaded'  => function_exists( 'mb_convert_encoding' ),
		'library_path'     => $library_path,
		'format'           => 'native-automerge-blocks-v1',
	);
}

/**
 * Registers the native PHP Automerge autoloader when the runtime can parse it.
 *
 * @since 7.1.0
 *
 * @return true|WP_Error True on success, otherwise an error.
 */
function wp_de_rtc_load_automerge_runtime() {
	$status = wp_de_rtc_get_automerge_runtime_status();

	if ( empty( $status['available'] ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_feature_disabled',
			__( 'Distributed Editing Automerge support is not available on this server.' ),
			array(
				'detail'           => 'automerge_runtime_unavailable',
				'php_version_id'   => $status['php_version_id'],
				'required_version' => $status['required_version'],
				'mbstring_loaded'  => $status['mbstring_loaded'],
				'library_present'  => file_exists( $status['library_path'] . '/NativePort.php' ),
			)
		);
	}

	// DELTA from upstream: autoloading is delegated to the plugin's shim
	// (includes/lib/automerge-php-loader.php) instead of registering
	// wp_de_rtc_automerge_autoload(); the latter is kept for API parity.
	require_once dirname( __DIR__, 2 ) . '/lib/automerge-php-loader.php';
	gutenberg_sync_engines_load_automerge_php();

	if ( ! class_exists( 'WordPress\\DistributedEditing\\Automerge\\NativePort' ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_feature_disabled',
			__( 'Distributed Editing could not load the Automerge runtime.' ),
			array(
				'detail' => 'automerge_runtime_class_missing',
			)
		);
	}

	return true;
}

/**
 * Autoloads classes from the vendored native PHP Automerge runtime.
 *
 * @since 7.1.0
 *
 * @param string $class Fully-qualified class name.
 */
function wp_de_rtc_automerge_autoload( $class ) {
	$prefix = 'WordPress\\DistributedEditing\\Automerge\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	// DELTA from upstream: vendored library path.
	$path     = dirname( __DIR__, 2 ) . '/lib/automerge-php/src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

/**
 * Returns the native PHP Automerge adapter.
 *
 * @since 7.1.0
 *
 * @return object|WP_Error Adapter instance, or an error.
 */
function wp_de_rtc_get_automerge_native_port() {
	$loaded = wp_de_rtc_load_automerge_runtime();

	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}

	return new WordPress\DistributedEditing\Automerge\NativePort();
}

/**
 * Creates a native Automerge update for the single contiguous change between strings.
 *
 * This intentionally starts with the smallest trustworthy representation:
 * ordinary editor Saves currently submit a complete proposed post string, so
 * the server derives a bounded update from the base/proposed pair and rejects
 * overlapping stale ranges before merging.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base  Base post content without sync metadata.
 * @param string $next  Next post content without sync metadata.
 * @param string $actor Actor label for operation IDs.
 * @return array Native update plus server-derived range evidence.
 */
function wp_de_rtc_create_automerge_update_for_content_change( $base, $next, $actor ) {
	$base         = wp_de_rtc_canonicalize_post_content_core_block_names( $base );
	$next         = wp_de_rtc_canonicalize_post_content_core_block_names( $next );
	$base_records = wp_de_rtc_get_top_level_serialized_block_records( $base );
	$next_records = wp_de_rtc_get_top_level_serialized_block_records( $next );

	if ( is_wp_error( $base_records ) || is_wp_error( $next_records ) ) {
		$descriptor = wp_de_rtc_get_automerge_content_change_descriptor( $base, $next );

		return array(
			'format'              => 'native-automerge-blocks-v1',
			'schema'              => 'de-rtc-automerge-v1',
			'operations'          => array(
				array(
					'type'                => 'document.replace_unsupported',
					'automergePrimitive'        => 'Automerge.Map.set',
					'actor'               => $actor,
					'sequence'            => 0,
					'id'                  => $actor . ':0',
					'baseContentHash'     => wp_de_rtc_hash_content( $base ),
					'proposedContentHash' => wp_de_rtc_hash_content( $next ),
					'changeRange'         => wp_de_rtc_format_automerge_change_range_for_client( $descriptor['change_range'] ),
				),
			),
			'stateVector'         => array( $actor => 1 ),
			'baseContentHash'     => wp_de_rtc_hash_content( $base ),
			'proposedContentHash' => wp_de_rtc_hash_content( $next ),
			'baseBlockCount'      => null,
			'proposedBlockCount'  => null,
		);
	}

	$operations = wp_de_rtc_get_automerge_block_native_operations( $base_records, $next_records, $actor );

	return array(
		'format'              => 'native-automerge-blocks-v1',
		'schema'              => 'de-rtc-automerge-v1',
		'operations'          => $operations,
		'stateVector'         => count( $operations ) > 0 ? array( $actor => count( $operations ) ) : array(),
		'baseContentHash'     => wp_de_rtc_hash_content( $base ),
		'proposedContentHash' => wp_de_rtc_hash_content( $next ),
		'baseBlockCount'      => count( $base_records ),
		'proposedBlockCount'  => count( $next_records ),
		'change_range'        => wp_de_rtc_get_automerge_content_change_descriptor( $base, $next )['change_range'],
	);
}

/**
 * Formats a server-side change-range descriptor using the client payload keys.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $range Server change-range descriptor.
 * @return array Client-shaped descriptor.
 */
function wp_de_rtc_format_automerge_change_range_for_client( $range ) {
	return array(
		'start'        => isset( $range['start'] ) ? (int) $range['start'] : 0,
		'end'          => isset( $range['end'] ) ? (int) $range['end'] : 0,
		'deleteLength' => isset( $range['delete_length'] ) ? (int) $range['delete_length'] : 0,
		'insertLength' => isset( $range['insert_length'] ) ? (int) $range['insert_length'] : 0,
		'changed'      => ! empty( $range['changed'] ),
	);
}

/**
 * Builds block-native Automerge operation evidence for a serialized block change.
 *
 * The operations are intentionally block-scoped. They may carry the serialized
 * block needed to materialize a changed or inserted block, instead of treating
 * the whole post as one text range.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records Accepted-base serialized block records.
 * @param string[] $next_records Proposed serialized block records.
 * @param string   $actor        Actor label for operation IDs.
 * @return array[] Block-native operations.
 */
function wp_de_rtc_get_automerge_block_native_operations( $base_records, $next_records, $actor ) {
	$operations  = array();
	$base_hashes = array_map( 'wp_de_rtc_hash_content', $base_records );
	$next_hashes = array_map( 'wp_de_rtc_hash_content', $next_records );

	if (
		count( $base_records ) === count( $next_records ) &&
		$base_records !== $next_records &&
		wp_de_rtc_have_same_serialized_block_multiset( $base_records, $next_records ) &&
		count( array_unique( $base_hashes ) ) === count( $base_hashes )
	) {
		foreach ( $next_records as $index => $next_record ) {
			if ( $base_records[ $index ] === $next_record ) {
				continue;
			}

			$from_index = array_search( $next_hashes[ $index ], $base_hashes, true );

			if ( false !== $from_index && $from_index !== $index ) {
				wp_de_rtc_append_automerge_block_native_operation(
					$operations,
					$actor,
					array(
						'type'         => 'block.move',
						'automergePrimitive' => 'Automerge.List.move',
						'fromPath'     => array( (int) $from_index ),
						'toPath'       => array( (int) $index ),
						'blockUid'     => 'top:' . $next_hashes[ $index ],
						'blockHash'    => $next_hashes[ $index ],
					)
				);
			}
		}

		return $operations;
	}

	if ( count( $base_records ) === count( $next_records ) ) {
		foreach ( $base_records as $index => $base_record ) {
			if ( $base_record === $next_records[ $index ] ) {
				continue;
			}

			wp_de_rtc_append_automerge_block_native_operation(
				$operations,
				$actor,
				wp_de_rtc_get_automerge_block_native_update_operation(
					$base_record,
					$next_records[ $index ],
					$base_hashes[ $index ],
					$next_hashes[ $index ],
					array( (int) $index )
				)
			);
		}

		return $operations;
	}

	$prefix = 0;
	while (
		$prefix < count( $base_records ) &&
		$prefix < count( $next_records ) &&
		$base_records[ $prefix ] === $next_records[ $prefix ]
	) {
		++$prefix;
	}

	$suffix = 0;
	while (
		$suffix < count( $base_records ) - $prefix &&
		$suffix < count( $next_records ) - $prefix &&
		$base_records[ count( $base_records ) - 1 - $suffix ] === $next_records[ count( $next_records ) - 1 - $suffix ]
	) {
		++$suffix;
	}

	for ( $index = count( $base_records ) - $suffix - 1; $index >= $prefix; --$index ) {
		wp_de_rtc_append_automerge_block_native_operation(
			$operations,
			$actor,
			array(
				'type'         => 'block.delete',
				'automergePrimitive' => 'Automerge.List.delete',
				'path'         => array( (int) $index ),
				'index'        => (int) $index,
				'blockUid'     => 'top:' . $base_hashes[ $index ],
				'blockHash'    => $base_hashes[ $index ],
			)
		);
	}

	for ( $index = $prefix; $index < count( $next_records ) - $suffix; ++$index ) {
		wp_de_rtc_append_automerge_block_native_operation(
			$operations,
			$actor,
			array(
				'type'            => 'block.insert',
				'automergePrimitive'    => 'Automerge.List.insert',
				'path'            => array( (int) $index ),
				'index'           => (int) $index,
				'blockUid'        => 'top:' . $next_hashes[ $index ],
				'blockHash'       => $next_hashes[ $index ],
				'blockName'       => wp_de_rtc_get_serialized_block_record_name( $next_records[ $index ] ),
				'serializedBlock' => $next_records[ $index ],
			)
		);
	}

	return $operations;
}

/**
 * Appends actor and sequence metadata to a block-native operation.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array[] $operations Operations built so far.
 * @param string  $actor      Actor label.
 * @param array   $operation  Operation body.
 */
function wp_de_rtc_append_automerge_block_native_operation( &$operations, $actor, $operation ) {
	$sequence     = count( $operations );
	$operations[] = array_merge(
		array(
			'actor'    => $actor,
			'sequence' => $sequence,
			'id'       => $actor . ':' . $sequence,
		),
		$operation
	);
}

/**
 * Returns whether two serialized-block arrays have the same content multiset.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $first  First block list.
 * @param string[] $second Second block list.
 * @return bool Whether the lists contain the same serialized blocks.
 */
function wp_de_rtc_have_same_serialized_block_multiset( $first, $second ) {
	if ( count( $first ) !== count( $second ) ) {
		return false;
	}

	$counts = array();

	foreach ( $first as $block ) {
		$hash            = wp_de_rtc_hash_content( $block );
		$counts[ $hash ] = isset( $counts[ $hash ] ) ? $counts[ $hash ] + 1 : 1;
	}

	foreach ( $second as $block ) {
		$hash = wp_de_rtc_hash_content( $block );

		if ( empty( $counts[ $hash ] ) ) {
			return false;
		}

		--$counts[ $hash ];
	}

	foreach ( $counts as $count ) {
		if ( 0 !== $count ) {
			return false;
		}
	}

	return true;
}

/**
 * Builds an update operation for one changed serialized block.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_block          Base serialized block.
 * @param string $next_block          Proposed serialized block.
 * @param string $base_block_hash     Base block hash.
 * @param string $next_block_hash     Proposed block hash.
 * @param int[]  $path                Top-level ordinal path.
 * @return array Block-native operation body.
 */
function wp_de_rtc_get_automerge_block_native_update_operation( $base_block, $next_block, $base_block_hash, $next_block_hash, $path ) {
	$base_block_name = wp_de_rtc_get_serialized_block_record_name( $base_block );
	$next_block_name = wp_de_rtc_get_serialized_block_record_name( $next_block );

	if ( null !== $base_block_name && $base_block_name === $next_block_name ) {
		$rich_text_operation = wp_de_rtc_get_automerge_block_native_rich_text_operation(
			$base_block,
			$next_block,
			$base_block_hash,
			$next_block_hash,
			$base_block_name,
			$path
		);

		if ( null !== $rich_text_operation ) {
			return $rich_text_operation;
		}

		return array(
			'type'              => 'block.update_serialized',
			'automergePrimitive'      => 'Automerge.Map.set',
			'path'              => $path,
			'blockUid'          => 'top:' . $base_block_hash,
			'blockName'         => $base_block_name,
			'baseBlockHash'     => $base_block_hash,
			'proposedBlockHash' => $next_block_hash,
			'changeRange'       => wp_de_rtc_format_automerge_change_range_for_client( wp_de_rtc_get_automerge_content_change_descriptor( $base_block, $next_block )['change_range'] ),
			'serializedBlock'   => $next_block,
		);
	}

	return array(
		'type'              => 'block.replace',
		'automergePrimitive'      => 'Automerge.Map.set+Automerge.List.insert',
		'path'              => $path,
		'blockUid'          => 'top:' . $next_block_hash,
		'baseBlockHash'     => $base_block_hash,
		'proposedBlockHash' => $next_block_hash,
		'baseBlockName'     => $base_block_name,
		'proposedBlockName' => $next_block_name,
		'serializedBlock'   => $next_block,
	);
}

/**
 * Builds a block-native rich text formatting operation when safe to describe.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_block      Base serialized block.
 * @param string $next_block      Proposed serialized block.
 * @param string $base_hash       Base block hash.
 * @param string $next_hash       Proposed block hash.
 * @param string $block_name      Block name.
 * @param int[]  $path            Top-level ordinal path.
 * @return array|null Operation body, or null when not a rich-text mark edit.
 */
function wp_de_rtc_get_automerge_block_native_rich_text_operation( $base_block, $next_block, $base_hash, $next_hash, $block_name, $path ) {
	$base = wp_de_rtc_get_paragraph_rich_text_block_parts( $base_block );
	$next = wp_de_rtc_get_paragraph_rich_text_block_parts( $next_block );

	if ( ! $base || ! $next || $base['open'] !== $next['open'] || $base['close'] !== $next['close'] ) {
		return null;
	}

	$base_model = wp_de_rtc_get_rich_text_format_model( $base['html'] );
	$next_model = wp_de_rtc_get_rich_text_format_model( $next['html'] );

	if ( ! $base_model || ! $next_model ) {
		return null;
	}

	if ( $base_model['text'] !== $next_model['text'] ) {
		return array(
			'type'              => 'block.rich_text_content',
			'automergePrimitive'      => 'Automerge.Text.splice',
			'path'              => $path,
			'blockUid'          => 'top:' . $base_hash,
			'blockName'         => $block_name,
			'field'             => 'innerHTML',
			'baseBlockHash'     => $base_hash,
			'proposedBlockHash' => $next_hash,
			'textSplice'        => wp_de_rtc_format_rich_text_text_splice_for_client(
				wp_de_rtc_get_rich_text_text_splice( $base_model['text'], $next_model['text'] )
			),
			'serializedBlock'   => $next_block,
		);
	}

	return array(
		'type'               => 'block.rich_text_format',
		'automergePrimitive'       => 'Automerge.Text.mark',
		'path'               => $path,
		'blockUid'           => 'top:' . $base_hash,
		'blockName'          => $block_name,
		'field'              => 'innerHTML',
		'baseBlockHash'      => $base_hash,
		'proposedBlockHash'  => $next_hash,
		'changedTextIndexes' => array_values( wp_de_rtc_get_rich_text_changed_indexes( $base_model, $next_model ) ),
		'serializedBlock'    => $next_block,
	);
}

/**
 * Creates a whole-text Automerge update for the guarded fallback path.
 *
 * New DE-RTC save paths should use `native-automerge-blocks-v1`; this helper is
 * retained only for the current same-range fallback while block-native coverage
 * is expanded.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base  Base post content without sync metadata.
 * @param string $next  Next post content without sync metadata.
 * @param string $actor Actor label for operation IDs.
 * @return array Native text update plus server-derived range evidence.
 */
function wp_de_rtc_create_legacy_automerge_update_for_content_change( $base, $next, $actor ) {
	$base       = wp_de_rtc_canonicalize_post_content_core_block_names( $base );
	$next       = wp_de_rtc_canonicalize_post_content_core_block_names( $next );
	$descriptor = wp_de_rtc_get_automerge_content_change_descriptor( $base, $next );
	$operations = array();
	$sequence   = 0;

	if ( $descriptor['delete_length'] > 0 ) {
		$operations[] = array(
			'type'     => 'delete',
			'index'    => $descriptor['index'],
			'length'   => $descriptor['delete_length'],
			'actor'    => $actor,
			'sequence' => $sequence,
			'id'       => $actor . ':' . $sequence,
		);
		++$sequence;
	}

	if ( '' !== $descriptor['insert_text'] ) {
		$operations[] = array(
			'type'     => 'insert',
			'index'    => $descriptor['index'],
			'text'     => $descriptor['insert_text'],
			'actor'    => $actor,
			'sequence' => $sequence,
			'id'       => $actor . ':' . $sequence,
		);
		++$sequence;
	}

	return array(
		'format'       => 'native-automerge-php-v1',
		'operations'   => $operations,
		'stateVector'  => $sequence > 0 ? array( $actor => $sequence ) : array(),
		'change_range' => $descriptor['change_range'],
	);
}

/**
 * Returns the single changed range between two UTF-8 strings.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base Base string.
 * @param string $next Next string.
 * @return array Change descriptor.
 */
function wp_de_rtc_get_automerge_content_change_descriptor( $base, $next ) {
	$base_chars = wp_de_rtc_split_utf8_string( (string) $base );
	$next_chars = wp_de_rtc_split_utf8_string( (string) $next );
	$base_count = count( $base_chars );
	$next_count = count( $next_chars );
	$prefix     = 0;

	while (
		$prefix < $base_count &&
		$prefix < $next_count &&
		$base_chars[ $prefix ] === $next_chars[ $prefix ]
	) {
		++$prefix;
	}

	$suffix = 0;
	while (
		$suffix < $base_count - $prefix &&
		$suffix < $next_count - $prefix &&
		$base_chars[ $base_count - 1 - $suffix ] === $next_chars[ $next_count - 1 - $suffix ]
	) {
		++$suffix;
	}

	$deleted_text = implode( '', array_slice( $base_chars, $prefix, $base_count - $prefix - $suffix ) );
	$insert_text  = implode( '', array_slice( $next_chars, $prefix, $next_count - $prefix - $suffix ) );
	$before_text  = implode( '', array_slice( $base_chars, 0, $prefix ) );
	$index        = wp_de_rtc_get_automerge_utf16_length( $before_text );
	$delete_units = wp_de_rtc_get_automerge_utf16_length( $deleted_text );
	$insert_units = wp_de_rtc_get_automerge_utf16_length( $insert_text );

	return array(
		'index'         => $index,
		'delete_length' => $delete_units,
		'insert_text'   => $insert_text,
		'insert_length' => $insert_units,
		'change_range'  => array(
			'start'         => $index,
			'end'           => $index + $delete_units,
			'delete_length' => $delete_units,
			'insert_length' => $insert_units,
			'changed'       => $delete_units > 0 || $insert_text !== '',
		),
	);
}

/**
 * Splits a UTF-8 string into Unicode code points.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $text Text to split.
 * @return string[] Characters.
 */
function wp_de_rtc_split_utf8_string( $text ) {
	if ( '' === $text ) {
		return array();
	}

	$matched = preg_match_all( '/./us', $text, $matches );

	if ( false === $matched ) {
		return str_split( $text );
	}

	return $matches[0];
}

/**
 * Returns the UTF-16 code-unit length used by text-indexed update evidence.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $text Text to measure.
 * @return int UTF-16 code-unit length.
 */
function wp_de_rtc_get_automerge_utf16_length( $text ) {
	if ( '' === $text ) {
		return 0;
	}

	if ( ! function_exists( 'mb_convert_encoding' ) ) {
		return strlen( $text );
	}

	return (int) ( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
}

/**
 * Normalizes client-submitted native Automerge update evidence.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $update Client update.
 * @return array|WP_Error Normalized update or an error.
 */
function wp_de_rtc_normalize_automerge_client_update( $update ) {
	if ( null === $update ) {
		return null;
	}

	if ( ! is_array( $update ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'Distributed Editing rejected the Automerge update because it is malformed.' ),
			array(
				'detail' => 'automerge_client_update_not_object',
			)
		);
	}

	if ( isset( $update['postContent'] ) || isset( $update['post_content'] ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_sync_meta_tampered',
			__( 'Distributed Editing rejected the Automerge update because clients may not submit raw document state as Automerge metadata.' ),
			array(
				'detail' => 'automerge_client_update_raw_document_rejected',
			)
		);
	}

	$format = isset( $update['format'] ) ? (string) $update['format'] : 'native-automerge-php-v1';

	if ( ! in_array( $format, array( 'native-automerge-php-v1', 'native-automerge-blocks-v1' ), true ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'Distributed Editing rejected the Automerge update because the encoding is unsupported.' ),
			array(
				'detail' => 'automerge_client_update_unsupported_format',
				'format' => sanitize_text_field( $format ),
			)
		);
	}

	if ( ! isset( $update['operations'] ) || ! is_array( $update['operations'] ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'Distributed Editing rejected the Automerge update because operations are missing.' ),
			array(
				'detail' => 'automerge_client_update_missing_operations',
			)
		);
	}

	foreach ( $update['operations'] as $operation ) {
		if ( ! is_array( $operation ) || ! isset( $operation['type'] ) || ! is_string( $operation['type'] ) ) {
			return wp_de_rtc_get_reason_error(
				'de_rtc_malformed_sync_payload',
				__( 'Distributed Editing rejected the Automerge update because an operation is malformed.' ),
				array(
					'detail' => 'automerge_client_update_malformed_operation',
				)
			);
		}
	}

	return array(
		'format'      => $format,
		'schema'      => isset( $update['schema'] ) && is_string( $update['schema'] ) ? sanitize_text_field( $update['schema'] ) : null,
		'operations'  => array_values( $update['operations'] ),
		'stateVector' => isset( $update['stateVector'] ) && is_array( $update['stateVector'] ) ? $update['stateVector'] : array(),
		'baseContentHash' => isset( $update['baseContentHash'] ) && is_string( $update['baseContentHash'] ) ? sanitize_text_field( $update['baseContentHash'] ) : null,
		'proposedContentHash' => isset( $update['proposedContentHash'] ) && is_string( $update['proposedContentHash'] ) ? sanitize_text_field( $update['proposedContentHash'] ) : null,
		'baseBlockCount' => isset( $update['baseBlockCount'] ) ? (int) $update['baseBlockCount'] : null,
		'proposedBlockCount' => isset( $update['proposedBlockCount'] ) ? (int) $update['proposedBlockCount'] : null,
	);
}

/**
 * Returns whether two server-derived Automerge change ranges overlap.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $left  First range.
 * @param array $right Second range.
 * @return bool Whether the ranges overlap.
 */
function wp_de_rtc_automerge_change_ranges_overlap( $left, $right ) {
	if ( empty( $left['changed'] ) || empty( $right['changed'] ) ) {
		return false;
	}

	$left_start  = (int) $left['start'];
	$left_end    = (int) $left['end'];
	$right_start = (int) $right['start'];
	$right_end   = (int) $right['end'];

	if ( $left_start === $left_end && $right_start === $right_end ) {
		return $left_start === $right_start;
	}

	if ( $left_start === $left_end ) {
		return $left_start >= $right_start && $left_start <= $right_end;
	}

	if ( $right_start === $right_end ) {
		return $right_start >= $left_start && $right_start <= $left_end;
	}

	return $left_start < $right_end && $right_start < $left_end;
}

/**
 * Returns whether a serialized block record can be unambiguously matched by content.
 *
 * This is a deliberately narrow guard for the Automerge idempotent-insert merge. In
 * the absence of durable block IDs, repeated serialized base blocks make
 * ordinal inference unsafe, so the server falls back to the normal conflict
 * path instead of guessing.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $records Serialized top-level block records.
 * @return bool Whether every record appears once.
 */
function wp_de_rtc_serialized_block_records_are_unique( $records ) {
	$seen = array();

	foreach ( $records as $record ) {
		$hash = wp_de_rtc_hash_content( (string) $record );

		if ( isset( $seen[ $hash ] ) ) {
			return false;
		}

		$seen[ $hash ] = true;
	}

	return true;
}

/**
 * Returns the parsed block name for a single serialized top-level block record.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $record Serialized block record.
 * @return string|null Block name, or null when parsing is ambiguous.
 */
	function wp_de_rtc_get_serialized_block_record_name( $record ) {
		$record = wp_de_rtc_canonicalize_post_content_for_hash( $record );
		$blocks = parse_blocks( (string) $record );

	if (
		1 !== count( $blocks ) ||
		empty( $blocks[0]['blockName'] ) ||
		! is_string( $blocks[0]['blockName'] ) ||
		serialize_block( $blocks[0] ) !== $record
	) {
		return null;
	}

	return $blocks[0]['blockName'];
}

/**
 * Returns whether two serialized block records represent the same block type.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $left  First serialized block record.
 * @param string $right Second serialized block record.
 * @return bool Whether the records share a parsed block name.
 */
function wp_de_rtc_serialized_block_record_names_match( $left, $right ) {
	$left_name  = wp_de_rtc_get_serialized_block_record_name( $left );
	$right_name = wp_de_rtc_get_serialized_block_record_name( $right );

	return null !== $left_name && $left_name === $right_name;
}

/**
 * Builds an insertion-only plan for a current body relative to an accepted base.
 *
 * Retained base blocks must be byte-identical and in order. Inserted blocks
 * that equal any base block are rejected because content-only matching cannot
 * distinguish insertion from movement or duplication of retained content.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records      Accepted-base serialized block records.
 * @param string[] $candidate_records Candidate serialized block records.
 * @return array|null Insertion plan by accepted-base gap, or null when unsafe.
 */
function wp_de_rtc_get_automerge_insertion_only_block_plan( $base_records, $candidate_records ) {
	if ( ! wp_de_rtc_serialized_block_records_are_unique( $base_records ) ) {
		return null;
	}

	$base_lookup = array_fill_keys(
		array_map(
			static function ( $record ) {
				return wp_de_rtc_hash_content( (string) $record );
			},
			$base_records
		),
		true
	);
	$base_count  = count( $base_records );
	$gaps        = array_fill( 0, $base_count + 1, array() );
	$index       = 0;

	for ( $base_index = 0; $base_index < $base_count; $base_index++ ) {
		while (
			$index < count( $candidate_records ) &&
			! hash_equals( $base_records[ $base_index ], $candidate_records[ $index ] )
		) {
			$inserted_record_hash = wp_de_rtc_hash_content( (string) $candidate_records[ $index ] );

			if ( isset( $base_lookup[ $inserted_record_hash ] ) ) {
				return null;
			}

			$gaps[ $base_index ][] = $candidate_records[ $index ];
			++$index;
		}

		if (
			$index >= count( $candidate_records ) ||
			! hash_equals( $base_records[ $base_index ], $candidate_records[ $index ] )
		) {
			return null;
		}

		++$index;
	}

	while ( $index < count( $candidate_records ) ) {
		$inserted_record_hash = wp_de_rtc_hash_content( (string) $candidate_records[ $index ] );

		if ( isset( $base_lookup[ $inserted_record_hash ] ) ) {
			return null;
		}

		$gaps[ $base_count ][] = $candidate_records[ $index ];
		++$index;
	}

	return array(
		'gaps'           => $gaps,
		'inserted_count' => count( $candidate_records ) - $base_count,
	);
}

/**
 * Applies an insertion-only server plan to a proposed body and keeps retained edits.
 *
 * The proposed side must contain exactly the same inserted blocks in exactly
 * the same accepted-base gaps as the already-saved server body. Only retained
 * base blocks may differ locally, and those retained edits must preserve the
 * parsed block type.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records     Accepted-base serialized block records.
 * @param string[] $proposed_records Proposed serialized block records.
 * @param array    $server_plan      Insertion-only plan from the current server body.
 * @return array|null Merge data or null when unsafe.
 */
function wp_de_rtc_apply_automerge_idempotent_insert_plan_to_proposed_blocks( $base_records, $proposed_records, $server_plan ) {
	if ( ! isset( $server_plan['gaps'] ) || ! is_array( $server_plan['gaps'] ) ) {
		return null;
	}

	$base_count            = count( $base_records );
	$proposed_index        = 0;
	$merged_blocks         = array();
	$server_changed_indexes = array();
	$local_changed_indexes = array();
	$retained_edit_indexes = array();

	for ( $gap_index = 0; $gap_index <= $base_count; $gap_index++ ) {
		$gap_records = isset( $server_plan['gaps'][ $gap_index ] ) && is_array( $server_plan['gaps'][ $gap_index ] )
			? $server_plan['gaps'][ $gap_index ]
			: array();

		foreach ( $gap_records as $gap_record ) {
			if (
				$proposed_index >= count( $proposed_records ) ||
				! hash_equals( $gap_record, $proposed_records[ $proposed_index ] )
			) {
				return null;
			}

			$server_changed_indexes[] = count( $merged_blocks );
			$merged_blocks[]          = $gap_record;
			++$proposed_index;
		}

		if ( $gap_index >= $base_count ) {
			continue;
		}

		if ( $proposed_index >= count( $proposed_records ) ) {
			return null;
		}

		$base_record     = $base_records[ $gap_index ];
		$proposed_record = $proposed_records[ $proposed_index ];

		if (
			! hash_equals( $base_record, $proposed_record ) &&
			! wp_de_rtc_serialized_block_record_names_match( $base_record, $proposed_record )
		) {
			return null;
		}

		if ( ! hash_equals( $base_record, $proposed_record ) ) {
			$local_changed_indexes[] = count( $merged_blocks );
			$retained_edit_indexes[] = count( $merged_blocks );
		}

		$merged_blocks[] = $proposed_record;
		++$proposed_index;
	}

	if ( $proposed_index !== count( $proposed_records ) || empty( $server_changed_indexes ) || empty( $local_changed_indexes ) ) {
		return null;
	}

	return array(
		'merged_blocks'          => $merged_blocks,
		'server_changed_indexes' => $server_changed_indexes,
		'local_changed_indexes'  => $local_changed_indexes,
		'retained_edit_indexes'  => $retained_edit_indexes,
	);
}

/**
 * Merges the idempotent Automerge case where a stale client repeats an inserted block.
 *
 * A common offline/realtime shape is: editor A inserts a block, editor B made
 * the same insertion from the same base and also edited a different retained
 * block. The server must absorb the already-saved duplicate insertion and
 * preserve B's separate edit. This helper accepts only that narrow shape.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content     Accepted-base stripped content.
 * @param string $current_content  Current server stripped content.
 * @param string $proposed_content Client-proposed stripped content.
 * @param array  $args             Optional merge evidence.
 * @return array|null Merge result, or null when the shape is unsafe.
 */
function wp_de_rtc_get_automerge_idempotent_block_insert_merge_result( $base_content, $current_content, $proposed_content, $args = array() ) {
	$base_records     = wp_de_rtc_get_top_level_serialized_block_records( $base_content );
	$current_records  = wp_de_rtc_get_top_level_serialized_block_records( $current_content );
	$proposed_records = wp_de_rtc_get_top_level_serialized_block_records( $proposed_content );

	foreach ( array( $base_records, $current_records, $proposed_records ) as $records ) {
		if ( is_wp_error( $records ) ) {
			return null;
		}
	}

	if ( count( $base_records ) >= count( $current_records ) || count( $current_records ) !== count( $proposed_records ) ) {
		return null;
	}

	$server_plan = wp_de_rtc_get_automerge_insertion_only_block_plan( $base_records, $current_records );

	if ( null === $server_plan || empty( $server_plan['inserted_count'] ) ) {
		return null;
	}

	$proposed_plan = wp_de_rtc_apply_automerge_idempotent_insert_plan_to_proposed_blocks( $base_records, $proposed_records, $server_plan );

	if ( null === $proposed_plan ) {
		return null;
	}

	$merged_content = implode( '', $proposed_plan['merged_blocks'] );

	return array(
		'merged_content'                        => $merged_content,
		'merge_status'                          => 'merged',
		'merge_strategy'                        => 'native_automerge_php_v1',
		'automerge_idempotent_duplicate_insert_absorbed' => true,
		'base_version'                          => isset( $args['base_version'] ) ? sanitize_text_field( (string) $args['base_version'] ) : null,
		'server_version'                        => isset( $args['server_version'] ) ? sanitize_text_field( (string) $args['server_version'] ) : null,
		'base_revision_id'                      => isset( $args['base_revision_id'] ) ? (int) $args['base_revision_id'] : 0,
		'block_count'                           => count( $proposed_plan['merged_blocks'] ),
		'base_block_count'                      => count( $base_records ),
		'server_block_count'                    => count( $current_records ),
		'proposed_block_count'                  => count( $proposed_records ),
		'merged_block_count'                    => count( $proposed_plan['merged_blocks'] ),
		'server_changed_indexes'                => $proposed_plan['server_changed_indexes'],
		'local_changed_indexes'                 => $proposed_plan['local_changed_indexes'],
		'server_changed_block_count'            => count( $proposed_plan['server_changed_indexes'] ),
		'local_changed_block_count'             => count( $proposed_plan['local_changed_indexes'] ),
		'block_identity_retained_edit_indexes'  => $proposed_plan['retained_edit_indexes'],
		'block_identity_retained_edit_block_count' => count( $proposed_plan['retained_edit_indexes'] ),
		'merged_stripped_content_hash'          => wp_de_rtc_hash_content( $merged_content ),
		'base_content_hash'                     => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'                   => wp_de_rtc_hash_content( $current_content ),
		'proposed_content_hash'                 => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Computes a Automerge-backed retry-save materialization.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content     Accepted-base stripped content.
 * @param string $current_content  Current server stripped content.
 * @param string $proposed_content Proposed stripped content.
 * @param mixed  $client_update    Optional client update evidence.
 * @return array|WP_Error Merge/materialization result.
 */
function wp_de_rtc_get_automerge_retry_save_result( $base_content, $current_content, $proposed_content, $client_update = null ) {
	$base_content     = wp_de_rtc_canonicalize_post_content_core_block_names( $base_content );
	$current_content  = wp_de_rtc_canonicalize_post_content_core_block_names( $current_content );
	$proposed_content = wp_de_rtc_canonicalize_post_content_core_block_names( $proposed_content );

	$normalized_client_update = wp_de_rtc_normalize_automerge_client_update( $client_update );

	if ( is_wp_error( $normalized_client_update ) ) {
		return $normalized_client_update;
	}

	if ( null === $normalized_client_update ) {
		$normalized_client_update = wp_de_rtc_create_automerge_update_for_content_change( $base_content, $proposed_content, 'client' );
	}

	if ( isset( $normalized_client_update['format'] ) && 'native-automerge-blocks-v1' === $normalized_client_update['format'] ) {
		return wp_de_rtc_get_automerge_block_native_retry_save_result(
			$base_content,
			$current_content,
			$proposed_content,
			$normalized_client_update
		);
	}

	$port = wp_de_rtc_get_automerge_native_port();

	if ( is_wp_error( $port ) ) {
		return $port;
	}

	$server_update      = wp_de_rtc_create_legacy_automerge_update_for_content_change( $base_content, $current_content, 'server' );
	$client_descriptor = wp_de_rtc_get_automerge_content_change_descriptor( $base_content, $proposed_content );
	$server_descriptor = wp_de_rtc_get_automerge_content_change_descriptor( $base_content, $current_content );
	$client_check      = $port->merge( $base_content, array( 'operations' => array() ), $normalized_client_update );

	if ( empty( $client_check['ok'] ) || ! isset( $client_check['postContent'] ) || $client_check['postContent'] !== $proposed_content ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_sync_meta_tampered',
			__( 'Distributed Editing rejected the retry save because the Automerge update did not match the proposed content.' ),
			array(
				'detail'               => 'automerge_client_update_materialization_mismatch',
				'saves_post'           => false,
				'mutates_post_content' => false,
				'creates_revision'     => false,
				'claims_saved'         => false,
			)
		);
	}

	if ( wp_de_rtc_automerge_change_ranges_overlap( $server_descriptor['change_range'], $client_descriptor['change_range'] ) ) {
		$idempotent_insert_merge = wp_de_rtc_get_automerge_idempotent_block_insert_merge_result(
			$base_content,
			$current_content,
			$proposed_content
		);

		if ( is_array( $idempotent_insert_merge ) ) {
			$effective_client_update = wp_de_rtc_create_legacy_automerge_update_for_content_change(
				$current_content,
				$idempotent_insert_merge['merged_content'],
				'client'
			);
			$effective_merge         = $port->merge( $current_content, array( 'operations' => array() ), $effective_client_update );

			if (
				! empty( $effective_merge['ok'] ) &&
				isset( $effective_merge['postContent'] ) &&
				$effective_merge['postContent'] === $idempotent_insert_merge['merged_content']
			) {
				$idempotent_insert_merge['automerge_metadata']          = isset( $effective_merge['metadata'] ) && is_array( $effective_merge['metadata'] ) ? $effective_merge['metadata'] : array();
				$idempotent_insert_merge['automerge_client_update']     = $normalized_client_update;
				$idempotent_insert_merge['automerge_effective_update']  = $effective_client_update;
				$idempotent_insert_merge['automerge_server_update']     = $server_update;
				$idempotent_insert_merge['server_change_range']   = $server_descriptor['change_range'];
				$idempotent_insert_merge['client_change_range']   = $client_descriptor['change_range'];
				$idempotent_insert_merge['server_merge_strategy'] = 'native_automerge_php_v1';

				return $idempotent_insert_merge;
			}
		}

		return wp_de_rtc_get_server_merge_conflict_error(
			'automerge_overlapping_change_ranges',
			array(
				'server_merge_strategy' => 'native_automerge_php_v1',
				'server_change_range'   => $server_descriptor['change_range'],
				'client_change_range'   => $client_descriptor['change_range'],
			)
		);
	}

	$merged = $port->merge( $base_content, $server_update, $normalized_client_update );

	if ( empty( $merged['ok'] ) || ! isset( $merged['postContent'] ) || ! is_string( $merged['postContent'] ) ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'automerge_merge_failed',
			array(
				'server_merge_strategy' => 'native_automerge_php_v1',
			)
		);
	}

	$base_records     = wp_de_rtc_get_top_level_serialized_block_records( $base_content );
	$current_records  = wp_de_rtc_get_top_level_serialized_block_records( $current_content );
	$proposed_records = wp_de_rtc_get_top_level_serialized_block_records( $proposed_content );
	$records          = wp_de_rtc_get_top_level_serialized_block_records( $merged['postContent'] );

	foreach ( array( $base_records, $current_records, $proposed_records, $records ) as $record_result ) {
		if ( is_wp_error( $record_result ) ) {
			return $record_result;
		}
	}

	return array(
		'merged_content'        => $merged['postContent'],
		'merge_status'          => 'merged',
		'merge_strategy'        => 'native_automerge_php_v1',
		'block_count'           => count( $records ),
		'base_block_count'      => count( $base_records ),
		'server_block_count'    => count( $current_records ),
		'proposed_block_count'  => count( $proposed_records ),
		'merged_block_count'    => count( $records ),
		'merged_stripped_content_hash' => wp_de_rtc_hash_content( $merged['postContent'] ),
		'base_content_hash'     => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'   => wp_de_rtc_hash_content( $current_content ),
		'proposed_content_hash' => wp_de_rtc_hash_content( $proposed_content ),
		'automerge_metadata'          => isset( $merged['metadata'] ) && is_array( $merged['metadata'] ) ? $merged['metadata'] : array(),
		'automerge_client_update'     => $normalized_client_update,
		'automerge_server_update'     => $server_update,
		'server_change_range'   => $server_descriptor['change_range'],
		'client_change_range'   => $client_descriptor['change_range'],
		'server_merge_strategy' => 'native_automerge_php_v1',
	);
}

/**
 * Computes a block-native Automerge retry-save materialization.
 *
 * This is the replacement path for new DE-RTC saves. The client update proves
 * block-scoped operations against the accepted base; WordPress still validates
 * that those operations materialize the submitted proposed content and then
 * owns the server merge and serialized-block persistence.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content     Accepted-base stripped content.
 * @param string $current_content  Current server stripped content.
 * @param string $proposed_content Client-proposed stripped content.
 * @param array  $client_update    Normalized block-native client update.
 * @return array|WP_Error Merge/materialization result.
 */
function wp_de_rtc_get_automerge_block_native_retry_save_result( $base_content, $current_content, $proposed_content, $client_update ) {
	$validation = wp_de_rtc_validate_automerge_block_native_update_matches_content( $base_content, $proposed_content, $client_update );

	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	if ( hash_equals( wp_de_rtc_hash_content( $base_content ), wp_de_rtc_hash_content( $current_content ) ) ) {
		$merge_result = wp_de_rtc_get_automerge_block_native_current_base_merge_result( $base_content, $proposed_content );
	} else {
		$merge_result = wp_de_rtc_get_automerge_idempotent_block_insert_merge_result( $base_content, $current_content, $proposed_content );

		if ( null === $merge_result ) {
			$merge_result = wp_de_rtc_get_serialized_block_server_merge_result( $base_content, $current_content, $proposed_content );
		}
	}

	if ( is_wp_error( $merge_result ) ) {
		return $merge_result;
	}

	$server_update = wp_de_rtc_create_automerge_update_for_content_change( $base_content, $current_content, 'server' );
	$operations    = array_merge(
		isset( $server_update['operations'] ) && is_array( $server_update['operations'] ) ? $server_update['operations'] : array(),
		isset( $client_update['operations'] ) && is_array( $client_update['operations'] ) ? $client_update['operations'] : array()
	);

	$merge_result['merge_strategy']        = 'native_automerge_blocks_v1';
	$merge_result['automerge_metadata']          = array(
		'format'      => 'native-automerge-blocks-v1',
		'schema'      => 'de-rtc-automerge-v1',
		'operations'  => $operations,
		'stateVector' => wp_de_rtc_merge_automerge_state_vectors(
			isset( $server_update['stateVector'] ) && is_array( $server_update['stateVector'] ) ? $server_update['stateVector'] : array(),
			isset( $client_update['stateVector'] ) && is_array( $client_update['stateVector'] ) ? $client_update['stateVector'] : array()
		),
	);
	$merge_result['automerge_client_update']     = $client_update;
	$merge_result['automerge_server_update']     = $server_update;
	$merge_result['server_merge_strategy'] = 'native_automerge_blocks_v1';

	return $merge_result;
}

/**
 * Validates block-native client evidence against the submitted body.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content     Accepted-base stripped content.
 * @param string $proposed_content Client-proposed stripped content.
 * @param array  $client_update    Normalized block-native update.
 * @return true|WP_Error True when valid, otherwise an error.
 */
function wp_de_rtc_validate_automerge_block_native_update_matches_content( $base_content, $proposed_content, $client_update ) {
	if ( ! is_array( $client_update ) || ! isset( $client_update['format'] ) || 'native-automerge-blocks-v1' !== $client_update['format'] ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'Distributed Editing rejected the retry save because the block-native Automerge update is malformed.' ),
			array(
				'detail' => 'automerge_block_native_update_format_missing',
			)
		);
	}

	$base_hash     = wp_de_rtc_hash_content( $base_content );
	$proposed_hash = wp_de_rtc_hash_content( $proposed_content );

	if ( isset( $client_update['baseContentHash'] ) && is_string( $client_update['baseContentHash'] ) && ! hash_equals( $base_hash, $client_update['baseContentHash'] ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_sync_meta_tampered',
			__( 'Distributed Editing rejected the retry save because the block-native base hash does not match.' ),
			array(
				'detail'               => 'automerge_block_native_base_hash_mismatch',
				'saves_post'           => false,
				'mutates_post_content' => false,
				'creates_revision'     => false,
				'claims_saved'         => false,
			)
		);
	}

	if ( isset( $client_update['proposedContentHash'] ) && is_string( $client_update['proposedContentHash'] ) && ! hash_equals( $proposed_hash, $client_update['proposedContentHash'] ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_sync_meta_tampered',
			__( 'Distributed Editing rejected the retry save because the block-native proposed hash does not match.' ),
			array(
				'detail'               => 'automerge_block_native_proposed_hash_mismatch',
				'saves_post'           => false,
				'mutates_post_content' => false,
				'creates_revision'     => false,
				'claims_saved'         => false,
			)
		);
	}

	$expected_update = wp_de_rtc_create_automerge_update_for_content_change( $base_content, $proposed_content, 'client' );
	$expected        = wp_de_rtc_get_automerge_block_native_operation_fingerprints( $expected_update['operations'] );
	$actual          = wp_de_rtc_get_automerge_block_native_operation_fingerprints( $client_update['operations'] );

	if ( $expected !== $actual ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_sync_meta_tampered',
			__( 'Distributed Editing rejected the retry save because the block-native Automerge update did not match the proposed content.' ),
			array(
				'detail'               => 'automerge_client_update_materialization_mismatch',
				'saves_post'           => false,
				'mutates_post_content' => false,
				'creates_revision'     => false,
				'claims_saved'         => false,
			)
		);
	}

	return true;
}

/**
 * Returns comparable operation fingerprints without actor/session metadata.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array[] $operations Block-native operations.
 * @return array[] Comparable fingerprints.
 */
function wp_de_rtc_get_automerge_block_native_operation_fingerprints( $operations ) {
	$fingerprints = array();

	foreach ( is_array( $operations ) ? $operations : array() as $operation ) {
		if ( ! is_array( $operation ) ) {
			continue;
		}

		$fingerprints[] = array(
			'type'              => isset( $operation['type'] ) ? (string) $operation['type'] : '',
			'path'              => isset( $operation['path'] ) && is_array( $operation['path'] ) ? array_map( 'intval', $operation['path'] ) : null,
			'fromPath'          => isset( $operation['fromPath'] ) && is_array( $operation['fromPath'] ) ? array_map( 'intval', $operation['fromPath'] ) : null,
			'toPath'            => isset( $operation['toPath'] ) && is_array( $operation['toPath'] ) ? array_map( 'intval', $operation['toPath'] ) : null,
			'index'             => isset( $operation['index'] ) ? (int) $operation['index'] : null,
			'blockHash'         => isset( $operation['blockHash'] ) ? (string) $operation['blockHash'] : null,
			'baseBlockHash'     => isset( $operation['baseBlockHash'] ) ? (string) $operation['baseBlockHash'] : null,
			'proposedBlockHash' => isset( $operation['proposedBlockHash'] ) ? (string) $operation['proposedBlockHash'] : null,
			'blockName'         => isset( $operation['blockName'] ) ? (string) $operation['blockName'] : null,
			'baseBlockName'     => isset( $operation['baseBlockName'] ) ? (string) $operation['baseBlockName'] : null,
			'proposedBlockName' => isset( $operation['proposedBlockName'] ) ? (string) $operation['proposedBlockName'] : null,
			'serializedBlockHash' => isset( $operation['serializedBlock'] ) && is_string( $operation['serializedBlock'] ) ? wp_de_rtc_hash_content( $operation['serializedBlock'] ) : null,
			'changedTextIndexes' => isset( $operation['changedTextIndexes'] ) && is_array( $operation['changedTextIndexes'] ) ? array_map( 'intval', $operation['changedTextIndexes'] ) : null,
			'textSplice'        => wp_de_rtc_get_automerge_block_native_text_splice_fingerprint( isset( $operation['textSplice'] ) ? $operation['textSplice'] : null ),
		);
	}

	return $fingerprints;
}

/**
 * Returns comparable text-splice evidence without exposing inserted text.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array|null $splice Client or server text-splice evidence.
 * @return array|null Comparable text-splice fingerprint.
 */
function wp_de_rtc_get_automerge_block_native_text_splice_fingerprint( $splice ) {
	if ( ! is_array( $splice ) ) {
		return null;
	}

	$insert_text = isset( $splice['insertText'] ) ? (string) $splice['insertText'] : ( isset( $splice['insert_text'] ) ? (string) $splice['insert_text'] : '' );

	return array(
		'changed'        => ! empty( $splice['changed'] ),
		'start'          => isset( $splice['start'] ) ? (int) $splice['start'] : 0,
		'deleteCount'    => isset( $splice['deleteCount'] ) ? (int) $splice['deleteCount'] : ( isset( $splice['delete_count'] ) ? (int) $splice['delete_count'] : 0 ),
		'insertTextHash' => hash( 'sha256', $insert_text ),
		'insertCount'    => isset( $splice['insertCount'] ) ? (int) $splice['insertCount'] : ( isset( $splice['insert_count'] ) ? (int) $splice['insert_count'] : strlen( $insert_text ) ),
		'end'            => isset( $splice['end'] ) ? (int) $splice['end'] : 0,
		'delta'          => isset( $splice['delta'] ) ? (int) $splice['delta'] : 0,
	);
}

/**
 * Builds a current-base block-native merge result.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content     Accepted-base stripped content.
 * @param string $proposed_content Client-proposed stripped content.
 * @return array|WP_Error Merge result.
 */
function wp_de_rtc_get_automerge_block_native_current_base_merge_result( $base_content, $proposed_content ) {
	$base_records     = wp_de_rtc_get_top_level_serialized_block_records( $base_content );
	$proposed_records = wp_de_rtc_get_top_level_serialized_block_records( $proposed_content );

	if ( is_wp_error( $proposed_records ) ) {
		return $proposed_records;
	}

	/*
	 * A current-base retry-save has no remote change to merge against. In that
	 * case, allow the editor to materialize a legacy/freeform server body as
	 * normal serialized blocks while still rejecting freeform boundaries in the
	 * proposed content and in stale three-way merge paths.
	 */
	$base_block_count = is_wp_error( $base_records ) ? null : count( $base_records );

	if ( is_wp_error( $base_records ) ) {
		$base_records = array();
	}

	return array(
		'merged_content'        => $proposed_content,
		'merge_status'          => 'merged',
		'merge_strategy'        => 'native_automerge_blocks_v1',
		'block_count'           => count( $proposed_records ),
		'base_block_count'      => $base_block_count,
		'server_block_count'    => $base_block_count,
		'proposed_block_count'  => count( $proposed_records ),
		'merged_block_count'    => count( $proposed_records ),
		'merged_stripped_content_hash' => wp_de_rtc_hash_content( $proposed_content ),
		'base_content_hash'     => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'   => wp_de_rtc_hash_content( $base_content ),
		'proposed_content_hash' => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Merges Automerge-style state vectors.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $left  First state vector.
 * @param array $right Second state vector.
 * @return array Merged state vector.
 */
	function wp_de_rtc_merge_automerge_state_vectors( $left, $right ) {
		$merged = array();

	foreach ( array( $left, $right ) as $vector ) {
		foreach ( is_array( $vector ) ? $vector : array() as $actor => $sequence ) {
			if ( ! is_string( $actor ) && ! is_int( $actor ) ) {
				continue;
			}

			$actor            = (string) $actor;
			$merged[ $actor ] = max( isset( $merged[ $actor ] ) ? (int) $merged[ $actor ] : 0, (int) $sequence );
		}
	}

		ksort( $merged );
		return $merged;
	}

/**
	 * Returns public Automerge encoding evidence for a retry-save result.
	 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $automerge_result Retry-save Automerge result.
 * @return string|null Encoding label, or null when no Automerge result exists.
 */
function wp_de_rtc_get_automerge_result_encoding( $automerge_result ) {
	if ( ! is_array( $automerge_result ) ) {
		return null;
	}

	if (
		isset( $automerge_result['automerge_metadata'] ) &&
		is_array( $automerge_result['automerge_metadata'] ) &&
		isset( $automerge_result['automerge_metadata']['format'] ) &&
		is_string( $automerge_result['automerge_metadata']['format'] )
	) {
		return sanitize_text_field( $automerge_result['automerge_metadata']['format'] );
	}

	return 'native-automerge-php-v1';
}

/**
 * Returns how many stripped-content snapshots Automerge sync meta should keep.
 *
 * These snapshots are a compatibility bridge for old REST clients that only
 * know a CRDT document version. Healthy saves resolve the referenced base
 * from current post_content metadata instead of scanning WordPress revisions.
 *
 * @since 7.1.0
 * @access private
 *
 * @return int Snapshot limit.
 */
function wp_de_rtc_get_automerge_version_snapshot_limit() {
	return 20;
}

/**
 * Adds bounded base-version snapshots to Automerge sync metadata.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array       $sync_meta        Existing sync metadata.
 * @param string      $previous_version Previous server version.
 * @param string      $previous_content Previous stripped post content.
 * @param string|null $next_version     Optional next server version.
 * @param string|null $next_content     Optional next stripped post content.
 * @return array Updated sync metadata.
 */
function wp_de_rtc_update_automerge_version_snapshots( $sync_meta, $previous_version, $previous_content, $next_version = null, $next_content = null ) {
	if ( ! is_array( $sync_meta ) ) {
		$sync_meta = array();
	}

	$snapshots = isset( $sync_meta['version_snapshots'] ) && is_array( $sync_meta['version_snapshots'] )
		? $sync_meta['version_snapshots']
		: array();

	$add_snapshot = static function ( $version, $content ) use ( &$snapshots ) {
		$version = sanitize_text_field( (string) $version );

		if ( '' === $version || ! is_string( $content ) ) {
			return;
		}

		unset( $snapshots[ $version ] );

		$snapshots[ $version ] = array(
			'encoding'         => 'base64',
			'content_base64'   => base64_encode( $content ),
			'content_hash'     => wp_de_rtc_hash_content( $content ),
			'raw_content_hash' => hash( 'sha256', $content ),
		);
	};

	$add_snapshot( $previous_version, $previous_content );

	if ( null !== $next_version && null !== $next_content ) {
		$add_snapshot( $next_version, $next_content );
	}

	$limit = wp_de_rtc_get_automerge_version_snapshot_limit();

	if ( count( $snapshots ) > $limit ) {
		$snapshots = array_slice( $snapshots, -$limit, null, true );
	}

	$sync_meta['version_snapshots']       = $snapshots;
	$sync_meta['version_snapshot_count'] = count( $snapshots );
	$sync_meta['version_snapshot_limit'] = $limit;

	return $sync_meta;
}

/**
 * Formats Distributed Editing sync metadata as a SCRIPT element.
 *
 * The JSON is encoded so that user-controlled values cannot produce a literal
 * `</script` sequence inside the script contents.
 *
 * @since 7.1.0
 *
 * @param string $format    Sync-meta format label.
 * @param mixed  $sync_meta Sync metadata to JSON-encode.
 * @return string|WP_Error SCRIPT element on success, otherwise a WP_Error.
 */
function wp_de_rtc_format_sync_meta( $format, $sync_meta ) {
	$format = wp_de_rtc_normalize_sync_meta_format( $format );

	if ( ! in_array( $format, wp_de_rtc_get_supported_sync_meta_formats(), true ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_unknown_sync_meta_format',
			__( 'The Distributed Editing sync metadata format is not supported.' ),
			array(
				'format' => $format,
			)
		);
	}

	$json = wp_json_encode(
		$sync_meta,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
	);

	if ( false === $json || false !== stripos( $json, '</script' ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'The Distributed Editing sync metadata could not be encoded.' ),
			array(
				'detail' => 'json_encode_failed',
			)
		);
	}

	$script = wp_get_inline_script_tag(
		$json,
		array(
			'type'                  => 'application/json',
			'data-wp-sync-meta'     => 'distributed-editing',
			'data-sync-meta-format' => $format,
		)
	);

	if ( '' === $script ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'The Distributed Editing sync metadata could not be embedded.' ),
			array(
				'detail' => 'script_embedding_failed',
			)
		);
	}

	return $script;
}

/**
 * Parses Distributed Editing sync metadata from the edge of post content.
 *
 * This recognizes sync metadata only at a content edge. Gutenberg may wrap an
 * otherwise inert SCRIPT element in paragraph/freeform markup after an editor
 * round-trip, so the parser accepts those wrappers without treating them as
 * human-authored post content.
 *
 * @since 7.1.0
 *
 * @param string $content Post content.
 * @param array  $args {
 *     Optional parser controls.
 *
 *     @type bool $allow_script_stripped_sync_meta Whether revision scans may accept a sync-meta pseudo-block whose
 *                                                SCRIPT wrapper was stripped by KSES. Default false.
 * }
 * @return array|WP_Error Parsed content data on success, otherwise a WP_Error.
 */
function wp_de_rtc_parse_post_content_sync_meta( $content, $args = array() ) {
	$allow_script_stripped_sync_meta = is_array( $args ) && ! empty( $args['allow_script_stripped_sync_meta'] );
	$sync_meta_script_count = wp_de_rtc_count_post_content_sync_meta_scripts( $content );

	if ( $sync_meta_script_count > 1 ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'Distributed Editing sync metadata must appear once.' ),
			array(
				'detail'                 => 'duplicate_sync_meta',
				'sync_meta_script_count' => $sync_meta_script_count,
			)
		);
	}

	$prefix = wp_de_rtc_match_edge_sync_meta_script( $content, 'prefix' );

	if ( false !== $prefix ) {
		$parsed = wp_de_rtc_parse_sync_meta_script( $prefix['script'], $prefix['json'] );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( false !== $parsed ) {
			return array(
					'content'            => wp_de_rtc_canonicalize_post_content_core_block_names( substr( $content, strlen( $prefix['match'] ) ) ),
					'sync_meta'          => $parsed['sync_meta'],
					'sync_meta_format'   => $parsed['sync_meta_format'],
					'sync_meta_position' => isset( $prefix['position'] ) ? $prefix['position'] : 'prefix',
				'raw_sync_meta'      => $prefix['script'],
			);
		}
	}

	$trailer = wp_de_rtc_match_edge_sync_meta_script( $content, 'trailer' );

	if ( false !== $trailer ) {
		$parsed = wp_de_rtc_parse_sync_meta_script( $trailer['script'], $trailer['json'] );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( false !== $parsed ) {
			return array(
					'content'            => wp_de_rtc_canonicalize_post_content_core_block_names( substr( $content, 0, strlen( $content ) - strlen( $trailer['match'] ) ) ),
					'sync_meta'          => $parsed['sync_meta'],
					'sync_meta_format'   => $parsed['sync_meta_format'],
					'sync_meta_position' => 'trailer',
				'raw_sync_meta'      => $trailer['script'],
			);
		}
	}

	if ( $allow_script_stripped_sync_meta ) {
		$prefix_block = wp_de_rtc_match_edge_script_stripped_sync_meta_block( $content, 'prefix' );

		if ( false !== $prefix_block ) {
			$parsed = wp_de_rtc_parse_script_stripped_sync_meta_block( $prefix_block );

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			return array(
					'content'            => wp_de_rtc_canonicalize_post_content_core_block_names( substr( $content, strlen( $prefix_block['match'] ) ) ),
					'sync_meta'          => $parsed['sync_meta'],
					'sync_meta_format'   => $parsed['sync_meta_format'],
					'sync_meta_position' => 'prefix-block',
				'raw_sync_meta'      => $prefix_block['match'],
			);
		}

		$trailer_block = wp_de_rtc_match_edge_script_stripped_sync_meta_block( $content, 'trailer' );

		if ( false !== $trailer_block ) {
			$parsed = wp_de_rtc_parse_script_stripped_sync_meta_block( $trailer_block );

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			return array(
					'content'            => wp_de_rtc_canonicalize_post_content_core_block_names( substr( $content, 0, strlen( $content ) - strlen( $trailer_block['match'] ) ) ),
					'sync_meta'          => $parsed['sync_meta'],
					'sync_meta_format'   => $parsed['sync_meta_format'],
					'sync_meta_position' => 'prefix-block',
				'raw_sync_meta'      => $trailer_block['match'],
			);
		}
	}

	if ( $sync_meta_script_count > 0 ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'Distributed Editing sync metadata must appear at a supported content edge.' ),
			array(
				'detail'                 => 'sync_meta_not_at_content_edge',
				'sync_meta_script_count' => $sync_meta_script_count,
			)
		);
	}

	return array(
			'content'            => wp_de_rtc_canonicalize_post_content_core_block_names( $content ),
			'sync_meta'          => null,
			'sync_meta_format'   => null,
		'sync_meta_position' => null,
		'raw_sync_meta'      => null,
	);
}

/**
 * Creates a WP_Error with canonical Distributed Editing reason data.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $reason_code Canonical DE-RTC reason code.
 * @param string $message     Error message.
 * @param array  $data        Optional. Additional error data.
 * @return WP_Error Error with status and reason-code data.
 */
function wp_de_rtc_get_reason_error( $reason_code, $message, $data = array() ) {
	$codes  = wp_de_rtc_get_reason_codes();
	$status = isset( $codes[ $reason_code ] ) ? $codes[ $reason_code ] : 500;

	return new WP_Error(
		$reason_code,
		$message,
		array_merge(
			array(
				'status'      => $status,
				'reason_code' => $reason_code,
			),
			$data
		)
	);
}

/**
 * Attempts a paragraph rich-text merge for one serialized block.
 *
 * This is deliberately narrower than a general HTML merge. It only accepts a
 * top-level paragraph with the same block shell. It can merge disjoint inline
 * strong/em mark changes, and the common editing shape where one side changes
 * paragraph text while the other side only changes marks on retained text.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_block     Accepted-base serialized block.
 * @param string $server_block   Current server serialized block.
 * @param string $proposed_block Client-proposed serialized block.
 * @return array|null Merge result or null when the block must stay a conflict.
 */
	function wp_de_rtc_get_rich_text_serialized_block_merge_candidate( $base_block, $server_block, $proposed_block ) {
	$base     = wp_de_rtc_get_paragraph_rich_text_block_parts( $base_block );
	$server   = wp_de_rtc_get_paragraph_rich_text_block_parts( $server_block );
	$proposed = wp_de_rtc_get_paragraph_rich_text_block_parts( $proposed_block );

	if ( ! $base || ! $server || ! $proposed ) {
		return null;
	}

	if (
		$base['open'] !== $server['open'] ||
		$base['open'] !== $proposed['open'] ||
		$base['close'] !== $server['close'] ||
		$base['close'] !== $proposed['close']
	) {
		return null;
	}

	$base_model     = wp_de_rtc_get_rich_text_format_model( $base['html'] );
	$server_model   = wp_de_rtc_get_rich_text_format_model( $server['html'] );
	$proposed_model = wp_de_rtc_get_rich_text_format_model( $proposed['html'] );

	if (
		! $base_model ||
		! $server_model ||
		! $proposed_model
	) {
		return null;
	}

	$server_splice   = wp_de_rtc_get_rich_text_text_splice( $base_model['text'], $server_model['text'] );
	$proposed_splice = wp_de_rtc_get_rich_text_text_splice( $base_model['text'], $proposed_model['text'] );
	$server_text_changed   = ! empty( $server_splice['changed'] );
	$proposed_text_changed = ! empty( $proposed_splice['changed'] );

	if ( ! $server_text_changed && ! $proposed_text_changed ) {
		$server_changed   = wp_de_rtc_get_rich_text_changed_indexes( $base_model, $server_model );
		$proposed_changed = wp_de_rtc_get_rich_text_changed_indexes( $base_model, $proposed_model );

		if ( wp_de_rtc_rich_text_changed_indexes_overlap( $server_changed, $proposed_changed ) ) {
			return null;
		}

		$merged_model = wp_de_rtc_merge_rich_text_format_models(
			$base_model,
			$server_model,
			$server_changed,
			$proposed_model,
			$proposed_changed
		);

		return array(
			'merged_block' => $base['open'] . wp_de_rtc_format_rich_text_model_html( $merged_model ) . $base['close'],
		);
	}

	if ( $server_text_changed && $proposed_text_changed ) {
		$merged_model = wp_de_rtc_merge_rich_text_text_splice_models(
			$base_model,
			$server_model,
			$server_splice,
			$proposed_model,
			$proposed_splice
		);

		if ( null === $merged_model ) {
			return null;
		}

		return array(
			'merged_block' => $base['open'] . wp_de_rtc_format_rich_text_model_html( $merged_model ) . $base['close'],
		);
	}

	if ( $server_text_changed ) {
		$format_changed = wp_de_rtc_get_rich_text_changed_indexes( $base_model, $proposed_model );
		$text_changed   = wp_de_rtc_get_retained_rich_text_mark_changed_indexes( $base_model, $server_model, $server_splice );

		if ( is_wp_error( $text_changed ) || wp_de_rtc_rich_text_changed_indexes_overlap( $text_changed, $format_changed ) ) {
			return null;
		}

		$merged_model = wp_de_rtc_merge_rich_text_text_and_format_models( $server_model, $proposed_model, $server_splice, $format_changed );
	} else {
		$format_changed = wp_de_rtc_get_rich_text_changed_indexes( $base_model, $server_model );
		$text_changed   = wp_de_rtc_get_retained_rich_text_mark_changed_indexes( $base_model, $proposed_model, $proposed_splice );

		if ( is_wp_error( $text_changed ) || wp_de_rtc_rich_text_changed_indexes_overlap( $text_changed, $format_changed ) ) {
			return null;
		}

		$merged_model = wp_de_rtc_merge_rich_text_text_and_format_models( $proposed_model, $server_model, $proposed_splice, $format_changed );
	}

	if ( null === $merged_model ) {
		return null;
	}

	return array(
		'merged_block' => $base['open'] . wp_de_rtc_format_rich_text_model_html( $merged_model ) . $base['close'],
	);
	}

/**
	 * Attempts a conservative table-cell merge for one serialized table block.
	 *
	 * Only cell inner HTML may differ. The table wrapper, row structure, cell
	 * tags, and cell attributes must match exactly, which keeps row/column
	 * edits, table settings changes, and same-cell edits out of this automatic
	 * path until the server has durable nested block identity.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $base_block     Accepted-base serialized block.
	 * @param string $server_block   Current server serialized block.
	 * @param string $proposed_block Client-proposed serialized block.
	 * @return array|null Merge result or null when the block must stay a conflict.
	 */
	function wp_de_rtc_get_table_cell_serialized_block_merge_candidate( $base_block, $server_block, $proposed_block ) {
		$base     = wp_de_rtc_get_table_cell_serialized_block_model( $base_block );
		$server   = wp_de_rtc_get_table_cell_serialized_block_model( $server_block );
		$proposed = wp_de_rtc_get_table_cell_serialized_block_model( $proposed_block );

		if ( ! $base || ! $server || ! $proposed ) {
			return null;
		}

		if (
			$base['shell'] !== $server['shell'] ||
			$base['shell'] !== $proposed['shell'] ||
			count( $base['cells'] ) !== count( $server['cells'] ) ||
			count( $base['cells'] ) !== count( $proposed['cells'] )
		) {
			return null;
		}

		$server_changed   = array();
		$proposed_changed = array();
		$cell_count       = count( $base['cells'] );

		for ( $index = 0; $index < $cell_count; $index++ ) {
			if ( $base['cells'][ $index ] !== $server['cells'][ $index ] ) {
				$server_changed[] = $index;
			}

			if ( $base['cells'][ $index ] !== $proposed['cells'][ $index ] ) {
				$proposed_changed[] = $index;
			}
		}

		if ( empty( $server_changed ) || empty( $proposed_changed ) ) {
			return null;
		}

		$server_changed_lookup = array_fill_keys( $server_changed, true );

		foreach ( $proposed_changed as $index ) {
			if ( isset( $server_changed_lookup[ $index ] ) && $server['cells'][ $index ] !== $proposed['cells'][ $index ] ) {
				return null;
			}
		}

		$merged_cells = $base['cells'];

		foreach ( $server_changed as $index ) {
			$merged_cells[ $index ] = $server['cells'][ $index ];
		}

		foreach ( $proposed_changed as $index ) {
			$merged_cells[ $index ] = $proposed['cells'][ $index ];
		}

		$merged_block = wp_de_rtc_render_table_cell_serialized_block_model( $base, $merged_cells );

		if ( null === $merged_block ) {
			return null;
		}

		return array(
			'merged_block'         => $merged_block,
			'server_changed_cells' => wp_de_rtc_get_table_cell_change_evidence( $base, $server_changed ),
			'local_changed_cells'  => wp_de_rtc_get_table_cell_change_evidence( $base, $proposed_changed ),
		);
	}

/**
	 * Builds a serialized table block model with cell contents separated.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $block Serialized block.
	 * @return array|null Table-cell model or null when unsupported.
	 */
	function wp_de_rtc_get_table_cell_serialized_block_model( $block ) {
		if ( ! is_string( $block ) || false === strpos( $block, '<!-- wp:table' ) ) {
			return null;
		}

		$parsed_blocks = wp_de_rtc_remove_empty_freeform_blocks( parse_blocks( $block ) );

		if (
			1 !== count( $parsed_blocks ) ||
			! isset( $parsed_blocks[0]['blockName'] ) ||
			'core/table' !== $parsed_blocks[0]['blockName']
		) {
			return null;
		}

		$matched = preg_match_all(
			'/<(?P<tag>t[dh])\b(?P<attrs>[^>]*)>(?P<content>.*?)<\/(?P=tag)>/is',
			$block,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);

		if ( false === $matched || 0 === $matched ) {
			return null;
		}

		$shell        = '';
		$cells        = array();
		$cell_offsets = array();
		$last_offset  = 0;

		foreach ( $matches as $match ) {
			$content        = $match['content'][0];
			$content_offset = (int) $match['content'][1];
			$content_length = strlen( $content );

			$shell          .= substr( $block, $last_offset, $content_offset - $last_offset ) . "\0DE_RTC_TABLE_CELL\0";
			$last_offset     = $content_offset + $content_length;
			$cells[]         = $content;
			$cell_offsets[]  = (int) $match[0][1];
		}

		$shell .= substr( $block, $last_offset );

		return array(
			'shell'       => $shell,
			'cells'       => $cells,
			'coordinates' => wp_de_rtc_get_table_cell_coordinates( $block, $cell_offsets ),
		);
	}

/**
	 * Returns best-effort row and column coordinates for table-cell evidence.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $block        Serialized table block.
	 * @param int[]  $cell_offsets Absolute offsets of table cell start tags.
	 * @return array[] Cell coordinates keyed by flat cell index.
	 */
	function wp_de_rtc_get_table_cell_coordinates( $block, $cell_offsets ) {
		$coordinates = array();

		foreach ( $cell_offsets as $index => $offset ) {
			$coordinates[ $index ] = array(
				'cell_index'   => (int) $index,
				'row_index'    => null,
				'column_index' => null,
			);
		}

		$offset_to_index = array();

		foreach ( $cell_offsets as $index => $offset ) {
			$offset_to_index[ (int) $offset ] = (int) $index;
		}

		$matched_rows = preg_match_all( '/<tr\b[^>]*>.*?<\/tr>/is', $block, $rows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		if ( false === $matched_rows || 0 === $matched_rows ) {
			return $coordinates;
		}

		foreach ( $rows as $row_index => $row ) {
			$row_html   = $row[0][0];
			$row_offset = (int) $row[0][1];
			$matched_cells = preg_match_all( '/<t[dh]\b[^>]*>.*?<\/t[dh]>/is', $row_html, $row_cells, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

			if ( false === $matched_cells || 0 === $matched_cells ) {
				continue;
			}

			foreach ( $row_cells as $column_index => $cell ) {
				$absolute_offset = $row_offset + (int) $cell[0][1];

				if ( isset( $offset_to_index[ $absolute_offset ] ) ) {
					$cell_index = $offset_to_index[ $absolute_offset ];
					$coordinates[ $cell_index ]['row_index']    = (int) $row_index;
					$coordinates[ $cell_index ]['column_index'] = (int) $column_index;
				}
			}
		}

		return $coordinates;
	}

/**
	 * Renders a serialized table block model with replacement cell contents.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param array    $model Table-cell model.
	 * @param string[] $cells Replacement cell contents.
	 * @return string|null Serialized block or null when malformed.
	 */
	function wp_de_rtc_render_table_cell_serialized_block_model( $model, $cells ) {
		if ( ! is_array( $model ) || ! isset( $model['shell'] ) || ! is_string( $model['shell'] ) || ! is_array( $cells ) ) {
			return null;
		}

		$parts = explode( "\0DE_RTC_TABLE_CELL\0", $model['shell'] );

		if ( count( $parts ) !== count( $cells ) + 1 ) {
			return null;
		}

		$block = '';

		foreach ( $cells as $index => $cell ) {
			$block .= $parts[ $index ] . $cell;
		}

		$block .= $parts[ count( $parts ) - 1 ];

		return $block;
	}

/**
	 * Returns content-free table-cell merge evidence.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param array $model           Table-cell model.
	 * @param int[] $changed_indexes Changed flat cell indexes.
	 * @return array[] Cell coordinate evidence.
	 */
	function wp_de_rtc_get_table_cell_change_evidence( $model, $changed_indexes ) {
		$evidence = array();

		foreach ( $changed_indexes as $index ) {
			$index      = (int) $index;
			$coordinate = isset( $model['coordinates'][ $index ] ) && is_array( $model['coordinates'][ $index ] )
				? $model['coordinates'][ $index ]
				: array(
					'cell_index'   => $index,
					'row_index'    => null,
					'column_index' => null,
				);

			$evidence[] = array(
				'cell_index'   => isset( $coordinate['cell_index'] ) ? (int) $coordinate['cell_index'] : $index,
				'row_index'    => isset( $coordinate['row_index'] ) && null !== $coordinate['row_index'] ? (int) $coordinate['row_index'] : null,
				'column_index' => isset( $coordinate['column_index'] ) && null !== $coordinate['column_index'] ? (int) $coordinate['column_index'] : null,
			);
		}

		return $evidence;
	}

/**
	 * Adds a top-level block index to table-cell evidence records.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param array[] $cells       Cell evidence.
	 * @param int     $block_index Top-level block index.
	 * @return array[] Indexed cell evidence.
	 */
	function wp_de_rtc_add_block_index_to_table_cell_evidence( $cells, $block_index ) {
		$indexed = array();

		foreach ( is_array( $cells ) ? $cells : array() as $cell ) {
			if ( ! is_array( $cell ) ) {
				continue;
			}

			$indexed[] = array(
				'block_index'  => (int) $block_index,
				'cell_index'   => isset( $cell['cell_index'] ) ? (int) $cell['cell_index'] : 0,
				'row_index'    => isset( $cell['row_index'] ) && null !== $cell['row_index'] ? (int) $cell['row_index'] : null,
				'column_index' => isset( $cell['column_index'] ) && null !== $cell['column_index'] ? (int) $cell['column_index'] : null,
			);
		}

		return $indexed;
	}

/**
	 * Splits a serialized core/paragraph block into shell and rich-text HTML.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $block Serialized block.
 * @return array|null Paragraph parts or null when unsupported.
 */
function wp_de_rtc_get_paragraph_rich_text_block_parts( $block ) {
	if ( ! is_string( $block ) ) {
		return null;
	}

	$matched = preg_match( '/^(<!--\\s+wp:paragraph\\b[\\s\\S]*?-->\\s*<p\\b[^>]*>)([\\s\\S]*?)(<\\/p>\\s*<!--\\s+\\/wp:paragraph\\s+-->)$/i', $block, $matches );

	if ( 1 !== $matched ) {
		return null;
	}

	return array(
		'open'  => $matches[1],
		'html'  => $matches[2],
		'close' => $matches[3],
	);
}

/**
 * Builds a minimal rich-text mark model from paragraph inner HTML.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $html Paragraph inner HTML.
 * @return array|null Rich-text model or null for unsupported HTML.
 */
function wp_de_rtc_get_rich_text_format_model( $html ) {
	$tokens = preg_split( '/(<[^>]+>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

	if ( ! is_array( $tokens ) ) {
		return null;
	}

	$text         = '';
	$marks        = array();
	$active_marks = array();
	$offset       = 0;

	foreach ( $tokens as $token ) {
		if ( '' === $token ) {
			continue;
		}

		if ( '<' === $token[0] ) {
			$normalized = strtolower( preg_replace( '/\\s+/', '', $token ) );

			if ( in_array( $normalized, array( '<strong>', '<b>' ), true ) ) {
				$active_marks[] = 'strong';
				continue;
			}

			if ( in_array( $normalized, array( '</strong>', '</b>' ), true ) ) {
				if ( ! wp_de_rtc_pop_rich_text_active_mark( $active_marks, 'strong' ) ) {
					return null;
				}
				continue;
			}

			if ( in_array( $normalized, array( '<em>', '<i>' ), true ) ) {
				$active_marks[] = 'em';
				continue;
			}

			if ( in_array( $normalized, array( '</em>', '</i>' ), true ) ) {
				if ( ! wp_de_rtc_pop_rich_text_active_mark( $active_marks, 'em' ) ) {
					return null;
				}
				continue;
			}

			return null;
		}

		$decoded = html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$chars   = wp_de_rtc_split_utf8_string( $decoded );
		foreach ( $chars as $char ) {
			$text .= $char;
			foreach ( $active_marks as $mark ) {
				$marks[] = array(
					'type'  => $mark,
					'start' => $offset,
					'end'   => $offset + 1,
				);
			}
			++$offset;
		}
	}

	if ( ! empty( $active_marks ) ) {
		return null;
	}

	return array(
		'text'  => $text,
		'marks' => wp_de_rtc_coalesce_rich_text_marks( $marks ),
	);
}

/**
 * Pops one active mark from the rich-text parser stack.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array  $active_marks Active mark stack.
 * @param string $mark         Mark to pop.
 * @return bool Whether the mark was popped.
 */
function wp_de_rtc_pop_rich_text_active_mark( &$active_marks, $mark ) {
	for ( $index = count( $active_marks ) - 1; $index >= 0; --$index ) {
		if ( $active_marks[ $index ] === $mark ) {
			array_splice( $active_marks, $index, 1 );
			return true;
		}
	}

	return false;
}

/**
 * Returns character indexes whose rich-text marks changed.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $base Base rich-text model.
 * @param array $next Candidate rich-text model.
 * @return int[] Changed character indexes.
 */
function wp_de_rtc_get_rich_text_changed_indexes( $base, $next ) {
	$changed = array();
	$chars   = wp_de_rtc_split_utf8_string( $base['text'] );
	$count   = count( $chars );

	for ( $index = 0; $index < $count; ++$index ) {
		foreach ( array( 'strong', 'em' ) as $mark ) {
			if (
				wp_de_rtc_rich_text_has_mark_at( $base, $mark, $index ) !==
				wp_de_rtc_rich_text_has_mark_at( $next, $mark, $index )
			) {
				$changed[ $index ] = $index;
			}
		}
	}

	return array_values( $changed );
}

/**
 * Returns whether two changed-index lists overlap.
 *
 * @since 7.1.0
 * @access private
 *
 * @param int[] $left  First changed indexes.
 * @param int[] $right Second changed indexes.
 * @return bool Whether they overlap.
 */
function wp_de_rtc_rich_text_changed_indexes_overlap( $left, $right ) {
	$right_lookup = array_fill_keys( array_map( 'intval', $right ), true );

	foreach ( $left as $index ) {
		if ( isset( $right_lookup[ (int) $index ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns the single text splice between two rich-text plain text values.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_text Base visible text.
 * @param string $next_text Next visible text.
 * @return array Text splice evidence.
 */
function wp_de_rtc_get_rich_text_text_splice( $base_text, $next_text ) {
	if ( $base_text === $next_text ) {
		return array(
			'changed'      => false,
			'start'        => 0,
			'delete_count' => 0,
			'insert_text'  => '',
			'insert_count' => 0,
			'end'          => 0,
			'delta'        => 0,
		);
	}

	$base_chars = wp_de_rtc_split_utf8_string( $base_text );
	$next_chars = wp_de_rtc_split_utf8_string( $next_text );
	$base_count = count( $base_chars );
	$next_count = count( $next_chars );
	$prefix     = 0;

	while ( $prefix < $base_count && $prefix < $next_count && $base_chars[ $prefix ] === $next_chars[ $prefix ] ) {
		++$prefix;
	}

	$suffix = 0;
	while (
		$suffix < $base_count - $prefix &&
		$suffix < $next_count - $prefix &&
		$base_chars[ $base_count - 1 - $suffix ] === $next_chars[ $next_count - 1 - $suffix ]
	) {
		++$suffix;
	}

	$delete_count = $base_count - $prefix - $suffix;
	$insert_chars = array_slice( $next_chars, $prefix, $next_count - $prefix - $suffix );
	$insert_count = count( $insert_chars );

	return array(
		'changed'      => true,
		'start'        => $prefix,
		'delete_count' => $delete_count,
		'insert_text'  => implode( '', $insert_chars ),
		'insert_count' => $insert_count,
		'end'          => $prefix + $delete_count,
		'delta'        => $insert_count - $delete_count,
	);
}

/**
 * Formats PHP text-splice evidence into the client operation vocabulary.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $splice PHP text-splice evidence.
 * @return array Client-shaped text-splice evidence.
 */
function wp_de_rtc_format_rich_text_text_splice_for_client( $splice ) {
	return array(
		'changed'     => ! empty( $splice['changed'] ),
		'start'       => isset( $splice['start'] ) ? (int) $splice['start'] : 0,
		'deleteCount' => isset( $splice['delete_count'] ) ? (int) $splice['delete_count'] : 0,
		'insertText'  => isset( $splice['insert_text'] ) ? (string) $splice['insert_text'] : '',
		'insertCount' => isset( $splice['insert_count'] ) ? (int) $splice['insert_count'] : 0,
		'end'         => isset( $splice['end'] ) ? (int) $splice['end'] : 0,
		'delta'       => isset( $splice['delta'] ) ? (int) $splice['delta'] : 0,
	);
}

/**
 * Maps a retained base-text index through a text splice.
 *
 * @since 7.1.0
 * @access private
 *
 * @param int   $index  Base text index.
 * @param array $splice Text splice evidence.
 * @return int|null Target index, or null when the base character was deleted.
 */
function wp_de_rtc_transform_rich_text_base_index( $index, $splice ) {
	$index = (int) $index;

	if ( empty( $splice['changed'] ) ) {
		return $index;
	}

	$start        = (int) $splice['start'];
	$delete_count = (int) $splice['delete_count'];
	$end          = $start + $delete_count;

	if ( $index < $start ) {
		return $index;
	}

	if ( $index >= $end ) {
		return $index + (int) $splice['delta'];
	}

	return null;
}

/**
 * Returns mark changes on retained text after accounting for a text splice.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $base   Base rich-text model.
 * @param array $next   Text-changing rich-text model.
 * @param array $splice Text splice evidence.
 * @return int[]|WP_Error Base indexes whose marks changed on retained text.
 */
function wp_de_rtc_get_retained_rich_text_mark_changed_indexes( $base, $next, $splice ) {
	$changed    = array();
	$base_chars = wp_de_rtc_split_utf8_string( $base['text'] );
	$next_chars = wp_de_rtc_split_utf8_string( $next['text'] );
	$next_count = count( $next_chars );

	foreach ( $base_chars as $index => $char ) {
		$target_index = wp_de_rtc_transform_rich_text_base_index( $index, $splice );

		if ( null === $target_index ) {
			continue;
		}

		if ( $target_index < 0 || $target_index >= $next_count ) {
			return wp_de_rtc_get_reason_error(
				'de_rtc_rebase_failed',
				__( 'Distributed Editing could not map rich text marks through a text edit.' ),
				array(
					'detail' => 'rich_text_mark_index_transform_failed',
				)
			);
		}

		foreach ( array( 'strong', 'em' ) as $mark ) {
			if ( wp_de_rtc_rich_text_has_mark_at( $base, $mark, $index ) !== wp_de_rtc_rich_text_has_mark_at( $next, $mark, $target_index ) ) {
				$changed[ $index ] = $index;
			}
		}
	}

	return array_values( $changed );
}

/**
 * Applies format-only changes onto the model produced by a text edit.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $text_changed_model    Model from the text-changing side.
 * @param array $format_changed_model  Model from the format-only side.
 * @param array $text_splice           Text splice evidence for the text-changing side.
 * @param int[] $format_changed_indexes Base indexes changed by the format-only side.
 * @return array|null Merged model, or null when indexes cannot be transformed.
 */
function wp_de_rtc_merge_rich_text_text_and_format_models( $text_changed_model, $format_changed_model, $text_splice, $format_changed_indexes ) {
	$target_chars = wp_de_rtc_split_utf8_string( $text_changed_model['text'] );
	$target_count = count( $target_chars );
	$marks        = array();

	for ( $index = 0; $index < $target_count; ++$index ) {
		foreach ( array( 'strong', 'em' ) as $mark ) {
			if ( wp_de_rtc_rich_text_has_mark_at( $text_changed_model, $mark, $index ) ) {
				$marks[ $index ][ $mark ] = true;
			}
		}
	}

	foreach ( $format_changed_indexes as $base_index ) {
		$target_index = wp_de_rtc_transform_rich_text_base_index( $base_index, $text_splice );

		if ( null === $target_index || $target_index < 0 || $target_index >= $target_count ) {
			return null;
		}

		foreach ( array( 'strong', 'em' ) as $mark ) {
			if ( wp_de_rtc_rich_text_has_mark_at( $format_changed_model, $mark, (int) $base_index ) ) {
				$marks[ $target_index ][ $mark ] = true;
			} elseif ( isset( $marks[ $target_index ][ $mark ] ) ) {
				unset( $marks[ $target_index ][ $mark ] );
			}
		}
	}

	$merged_marks = array();

	for ( $index = 0; $index < $target_count; ++$index ) {
		foreach ( array( 'strong', 'em' ) as $mark ) {
			if ( ! empty( $marks[ $index ][ $mark ] ) ) {
				$merged_marks[] = array(
					'type'  => $mark,
					'start' => $index,
					'end'   => $index + 1,
				);
			}
		}
	}

	return array(
		'text'  => $text_changed_model['text'],
		'marks' => wp_de_rtc_coalesce_rich_text_marks( $merged_marks ),
	);
}

/**
 * Merges two disjoint paragraph text splices against the same base model.
 *
 * This intentionally stays conservative. It only accepts two independently
 * mapped text edits when their base text ranges do not overlap; same-position
 * competing insertions remain a manual conflict until the product has an
 * explicit ordering policy.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $base             Accepted-base rich-text model.
 * @param array $server           Current-server rich-text model.
 * @param array $server_splice    Server text-splice evidence.
 * @param array $proposed         Client-proposed rich-text model.
 * @param array $proposed_splice  Client-proposed text-splice evidence.
 * @return array|null Merged rich-text model, or null when unsafe to merge.
 */
function wp_de_rtc_merge_rich_text_text_splice_models( $base, $server, $server_splice, $proposed, $proposed_splice ) {
	if ( wp_de_rtc_rich_text_text_splices_conflict( $server_splice, $proposed_splice ) ) {
		return null;
	}

	$server_mark_changed = wp_de_rtc_get_retained_rich_text_mark_changed_indexes( $base, $server, $server_splice );
	$proposed_mark_changed = wp_de_rtc_get_retained_rich_text_mark_changed_indexes( $base, $proposed, $proposed_splice );

	if (
		is_wp_error( $server_mark_changed ) ||
		is_wp_error( $proposed_mark_changed ) ||
		wp_de_rtc_rich_text_changed_indexes_overlap( $server_mark_changed, $proposed_mark_changed ) ||
		wp_de_rtc_rich_text_changed_indexes_touch_splice( $server_mark_changed, $proposed_splice ) ||
		wp_de_rtc_rich_text_changed_indexes_touch_splice( $proposed_mark_changed, $server_splice )
	) {
		return null;
	}

	$server_mark_lookup   = array_fill_keys( array_map( 'intval', $server_mark_changed ), true );
	$proposed_mark_lookup = array_fill_keys( array_map( 'intval', $proposed_mark_changed ), true );
	$base_chars           = wp_de_rtc_split_utf8_string( $base['text'] );
	$splices              = array(
		array(
			'kind'   => 'server',
			'model'  => $server,
			'splice' => $server_splice,
		),
		array(
			'kind'   => 'proposed',
			'model'  => $proposed,
			'splice' => $proposed_splice,
		),
	);

	usort(
		$splices,
		static function ( $left, $right ) {
			$left_start  = (int) $left['splice']['start'];
			$right_start = (int) $right['splice']['start'];

			if ( $left_start === $right_start ) {
				return (int) $left['splice']['end'] <=> (int) $right['splice']['end'];
			}

			return $left_start <=> $right_start;
		}
	);

	$sources = array();
	$cursor  = 0;

	foreach ( $splices as $entry ) {
		$splice = $entry['splice'];
		$start  = (int) $splice['start'];
		$end    = (int) $splice['end'];

		for ( ; $cursor < $start; ++$cursor ) {
			$sources[] = array(
				'kind'       => 'base',
				'base_index' => $cursor,
			);
		}

		$insert_count = (int) $splice['insert_count'];
		for ( $offset = 0; $offset < $insert_count; ++$offset ) {
			$sources[] = array(
				'kind'        => $entry['kind'],
				'model'       => $entry['model'],
				'model_index' => $start + $offset,
			);
		}

		$cursor = $end;
	}

	for ( $count = count( $base_chars ); $cursor < $count; ++$cursor ) {
		$sources[] = array(
			'kind'       => 'base',
			'base_index' => $cursor,
		);
	}

	$chars = array();
	$marks = array();

	foreach ( $sources as $target_index => $source ) {
		if ( 'base' === $source['kind'] ) {
			$base_index = (int) $source['base_index'];
			$chars[]    = $base_chars[ $base_index ];

			foreach ( array( 'strong', 'em' ) as $mark ) {
				if ( isset( $proposed_mark_lookup[ $base_index ] ) ) {
					$marked = wp_de_rtc_rich_text_has_mark_at_transformed_index( $proposed, $mark, $base_index, $proposed_splice );
				} elseif ( isset( $server_mark_lookup[ $base_index ] ) ) {
					$marked = wp_de_rtc_rich_text_has_mark_at_transformed_index( $server, $mark, $base_index, $server_splice );
				} else {
					$marked = wp_de_rtc_rich_text_has_mark_at( $base, $mark, $base_index );
				}

				if ( null === $marked ) {
					return null;
				}

				if ( $marked ) {
					$marks[] = array(
						'type'  => $mark,
						'start' => $target_index,
						'end'   => $target_index + 1,
					);
				}
			}

			continue;
		}

		$model       = $source['model'];
		$model_index = (int) $source['model_index'];
		$model_chars = wp_de_rtc_split_utf8_string( $model['text'] );

		if ( ! isset( $model_chars[ $model_index ] ) ) {
			return null;
		}

		$chars[] = $model_chars[ $model_index ];

		foreach ( array( 'strong', 'em' ) as $mark ) {
			if ( wp_de_rtc_rich_text_has_mark_at( $model, $mark, $model_index ) ) {
				$marks[] = array(
					'type'  => $mark,
					'start' => $target_index,
					'end'   => $target_index + 1,
				);
			}
		}
	}

	return array(
		'text'  => implode( '', $chars ),
		'marks' => wp_de_rtc_coalesce_rich_text_marks( $marks ),
	);
}

/**
 * Returns whether two base-relative rich-text splices must remain a conflict.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $left  First text-splice evidence.
 * @param array $right Second text-splice evidence.
 * @return bool Whether the splices conflict.
 */
function wp_de_rtc_rich_text_text_splices_conflict( $left, $right ) {
	$left_start  = (int) $left['start'];
	$left_end    = (int) $left['end'];
	$right_start = (int) $right['start'];
	$right_end   = (int) $right['end'];

	if ( $left_start < $right_end && $right_start < $left_end ) {
		return true;
	}

	return (
		0 === (int) $left['delete_count'] &&
		0 === (int) $right['delete_count'] &&
		$left_start === $right_start &&
		! empty( $left['insert_text'] ) &&
		! empty( $right['insert_text'] )
	);
}

/**
 * Returns whether changed retained indexes touch a deleted/replaced splice range.
 *
 * @since 7.1.0
 * @access private
 *
 * @param int[] $indexes Changed base indexes.
 * @param array $splice  Text-splice evidence.
 * @return bool Whether any index touches the splice replacement range.
 */
function wp_de_rtc_rich_text_changed_indexes_touch_splice( $indexes, $splice ) {
	$start = (int) $splice['start'];
	$end   = (int) $splice['end'];

	foreach ( $indexes as $index ) {
		$index = (int) $index;
		if ( $start <= $index && $index < $end ) {
			return true;
		}
	}

	return false;
}

/**
 * Reads mark state for a retained base index after a text splice.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array  $model      Text-spliced rich-text model.
 * @param string $mark       Mark type.
 * @param int    $base_index Base text index.
 * @param array  $splice     Text-splice evidence.
 * @return bool|null Mark state, or null when the index cannot be mapped.
 */
function wp_de_rtc_rich_text_has_mark_at_transformed_index( $model, $mark, $base_index, $splice ) {
	$target_index = wp_de_rtc_transform_rich_text_base_index( $base_index, $splice );

	if ( null === $target_index ) {
		return null;
	}

	return wp_de_rtc_rich_text_has_mark_at( $model, $mark, $target_index );
}

/**
 * Merges disjoint rich-text mark changes.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $base             Base rich-text model.
 * @param array $server           Server rich-text model.
 * @param int[] $server_changed   Server changed indexes.
 * @param array $proposed         Proposed rich-text model.
 * @param int[] $proposed_changed Proposed changed indexes.
 * @return array Merged rich-text model.
 */
function wp_de_rtc_merge_rich_text_format_models( $base, $server, $server_changed, $proposed, $proposed_changed ) {
	$server_lookup   = array_fill_keys( array_map( 'intval', $server_changed ), true );
	$proposed_lookup = array_fill_keys( array_map( 'intval', $proposed_changed ), true );
	$chars           = wp_de_rtc_split_utf8_string( $base['text'] );
	$marks           = array();

	foreach ( $chars as $index => $char ) {
		foreach ( array( 'strong', 'em' ) as $mark ) {
			if ( isset( $proposed_lookup[ $index ] ) ) {
				$marked = wp_de_rtc_rich_text_has_mark_at( $proposed, $mark, $index );
			} elseif ( isset( $server_lookup[ $index ] ) ) {
				$marked = wp_de_rtc_rich_text_has_mark_at( $server, $mark, $index );
			} else {
				$marked = wp_de_rtc_rich_text_has_mark_at( $base, $mark, $index );
			}

			if ( $marked ) {
				$marks[] = array(
					'type'  => $mark,
					'start' => $index,
					'end'   => $index + 1,
				);
			}
		}
	}

	return array(
		'text'  => $base['text'],
		'marks' => wp_de_rtc_coalesce_rich_text_marks( $marks ),
	);
}

/**
 * Returns whether a rich-text model has a mark at a character index.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array  $model Rich-text model.
 * @param string $mark  Mark type.
 * @param int    $index Character index.
 * @return bool Whether the mark is active.
 */
function wp_de_rtc_rich_text_has_mark_at( $model, $mark, $index ) {
	foreach ( $model['marks'] as $range ) {
		if ( $range['type'] === $mark && $range['start'] <= $index && $index < $range['end'] ) {
			return true;
		}
	}

	return false;
}

/**
 * Coalesces adjacent rich-text marks of the same type.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $marks Mark ranges.
 * @return array Coalesced mark ranges.
 */
function wp_de_rtc_coalesce_rich_text_marks( $marks ) {
	usort(
		$marks,
		static function ( $a, $b ) {
			if ( $a['type'] === $b['type'] ) {
				if ( $a['start'] === $b['start'] ) {
					return $a['end'] <=> $b['end'];
				}
				return $a['start'] <=> $b['start'];
			}
			return strcmp( $a['type'], $b['type'] );
		}
	);

	$coalesced = array();

	foreach ( $marks as $mark ) {
		$last_index = count( $coalesced ) - 1;
		if ( $last_index >= 0 && $coalesced[ $last_index ]['type'] === $mark['type'] && $coalesced[ $last_index ]['end'] === $mark['start'] ) {
			$coalesced[ $last_index ]['end'] = $mark['end'];
		} else {
			$coalesced[] = $mark;
		}
	}

	return $coalesced;
}

/**
 * Serializes a rich-text model into paragraph inner HTML.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $model Rich-text model.
 * @return string Serialized HTML.
 */
function wp_de_rtc_format_rich_text_model_html( $model ) {
	$events = array();

	foreach ( $model['marks'] as $mark ) {
		$start = (int) $mark['start'];
		$end   = (int) $mark['end'];

		if ( ! isset( $events[ $start ] ) ) {
			$events[ $start ] = array(
				'open'  => array(),
				'close' => array(),
			);
		}
		if ( ! isset( $events[ $end ] ) ) {
			$events[ $end ] = array(
				'open'  => array(),
				'close' => array(),
			);
		}

		$events[ $start ]['open'][]  = $mark['type'];
		$events[ $end ]['close'][] = $mark['type'];
	}

	$chars = wp_de_rtc_split_utf8_string( $model['text'] );
	$html  = '';
	$count = count( $chars );

	for ( $index = 0; $index <= $count; ++$index ) {
		if ( isset( $events[ $index ] ) ) {
			sort( $events[ $index ]['open'] );
			rsort( $events[ $index ]['close'] );

			foreach ( $events[ $index ]['close'] as $mark ) {
				$html .= '</' . $mark . '>';
			}
			foreach ( $events[ $index ]['open'] as $mark ) {
				$html .= '<' . $mark . '>';
			}
		}

		if ( $index < $count ) {
			$html .= esc_html( $chars[ $index ] );
		}
	}

	return $html;
}

/**
 * Attempts a conservative server-side merge across top-level serialized blocks.
 *
 * This helper is intentionally narrow: it only merges same-count top-level
 * serialized block edits, a strict one-sided edge insertion where the
 * inserting side's existing blocks still match the accepted base, or a strict
 * one-sided deletion where the retained blocks still match the accepted base
 * and the other side did not edit the deleted blocks. Anything else becomes a
 * conflict for the editor.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content     Stripped post content for the accepted proof version.
 * @param string $server_content   Current stripped server post content.
 * @param string $proposed_content Client-proposed stripped post content.
 * @param array  $args             Optional merge evidence.
 * @return array|WP_Error Merge result, or conflict error.
 */
function wp_de_rtc_get_serialized_block_server_merge_result( $base_content, $server_content, $proposed_content, $args = array() ) {
	$base_records     = wp_de_rtc_get_top_level_serialized_block_records( $base_content );
	$server_records   = wp_de_rtc_get_top_level_serialized_block_records( $server_content );
	$proposed_records = wp_de_rtc_get_top_level_serialized_block_records( $proposed_content );

	foreach ( array( $base_records, $server_records, $proposed_records ) as $record_set ) {
		if ( is_wp_error( $record_set ) ) {
			return $record_set;
		}
	}

	$base_count           = count( $base_records );
	$server_count         = count( $server_records );
	$proposed_count       = count( $proposed_records );
	$edge_insert_source   = null;
	$edge_insert_position = null;
	$edge_inserted_blocks = array();
	$server_base_offset   = 0;
	$local_base_offset    = 0;
	$merged_index_offset  = 0;

	if ( 0 === $base_count ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'top_level_serialized_block_count_changed',
			array(
				'base_block_count'           => $base_count,
				'server_block_count'         => $server_count,
				'proposed_block_count'       => $proposed_count,
				'server_block_count_changed' => $base_count !== $server_count,
				'local_block_count_changed'  => $base_count !== $proposed_count,
				'server_block_count_delta'   => $server_count - $base_count,
				'local_block_count_delta'    => $proposed_count - $base_count,
			)
		);
	}

	if ( $base_count !== $server_count || $base_count !== $proposed_count ) {
		$server_deleted = $server_count < $base_count && $proposed_count === $base_count;
		$local_deleted  = $proposed_count < $base_count && $server_count === $base_count;

		if ( $server_deleted || $local_deleted ) {
			return wp_de_rtc_get_serialized_block_deletion_merge_result(
				$base_records,
				$server_records,
				$proposed_records,
				$base_content,
				$server_content,
				$proposed_content,
				$args
			);
		}

		$server_edge_inserted = $server_count > $base_count && $proposed_count === $base_count;
		$local_edge_inserted  = $proposed_count > $base_count && $server_count === $base_count;

		if ( $server_edge_inserted || $local_edge_inserted ) {
			$edge_insert_records = $server_edge_inserted ? $server_records : $proposed_records;
			$edge_insert         = wp_de_rtc_get_serialized_block_edge_insertion( $base_records, $edge_insert_records );

			if ( null !== $edge_insert ) {
				$edge_insert_source   = $server_edge_inserted ? 'server' : 'local';
				$edge_insert_position = $edge_insert['position'];
				$edge_inserted_blocks = $edge_insert['blocks'];

				if ( 'ambiguous' === $edge_insert_position ) {
					return wp_de_rtc_get_server_merge_conflict_error(
						'ambiguous_edge_insertion',
						array(
							'base_block_count'           => $base_count,
							'server_block_count'         => $server_count,
							'proposed_block_count'       => $proposed_count,
							'server_block_count_changed' => $base_count !== $server_count,
							'local_block_count_changed'  => $base_count !== $proposed_count,
							'server_block_count_delta'   => $server_count - $base_count,
							'local_block_count_delta'    => $proposed_count - $base_count,
							'edge_insert_source'         => $edge_insert_source,
							'edge_insert_position'       => $edge_insert_position,
							'edge_insert_ambiguous'      => true,
						)
					);
				}
			}
		}

		if ( null === $edge_insert_source ) {
			return wp_de_rtc_get_server_merge_conflict_error(
				'top_level_serialized_block_count_changed',
				array(
					'base_block_count'           => $base_count,
					'server_block_count'         => $server_count,
					'proposed_block_count'       => $proposed_count,
					'server_block_count_changed' => $base_count !== $server_count,
					'local_block_count_changed'  => $base_count !== $proposed_count,
					'server_block_count_delta'   => $server_count - $base_count,
					'local_block_count_delta'    => $proposed_count - $base_count,
				)
			);
		}

		if ( 'prepend' === $edge_insert_position ) {
			$merged_index_offset = count( $edge_inserted_blocks );

			if ( 'server' === $edge_insert_source ) {
				$server_base_offset = $merged_index_offset;
			} else {
				$local_base_offset = $merged_index_offset;
			}
		}
	}

	if ( $base_count === $server_count && $base_count === $proposed_count ) {
		$server_reordered_indexes = wp_de_rtc_get_reordered_serialized_block_indexes( $base_records, $server_records );
		$local_reordered_indexes  = wp_de_rtc_get_reordered_serialized_block_indexes( $base_records, $proposed_records );

		if ( ! empty( $server_reordered_indexes ) || ! empty( $local_reordered_indexes ) ) {
			return wp_de_rtc_get_server_merge_conflict_error(
				'top_level_serialized_block_reordered',
				array(
					'base_block_count'              => $base_count,
					'server_block_count'            => $server_count,
					'proposed_block_count'          => $proposed_count,
					'server_block_count_changed'    => false,
					'local_block_count_changed'     => false,
					'server_block_count_delta'      => 0,
					'local_block_count_delta'       => 0,
					'server_block_order_changed'    => ! empty( $server_reordered_indexes ),
					'local_block_order_changed'     => ! empty( $local_reordered_indexes ),
					'server_reordered_block_indexes' => $server_reordered_indexes,
					'local_reordered_block_indexes' => $local_reordered_indexes,
				)
			);
		}
	}

	$merged_blocks            = array();
	$server_changed_indexes   = array();
	$local_changed_indexes    = array();
		$conflicting_indexes      = array();
		$conflicting_block_hashes = array();
		$rich_text_merged_indexes = array();
		$table_cell_merged_indexes = array();
		$table_cell_server_changed_cells = array();
		$table_cell_local_changed_cells  = array();

	if ( 'prepend' === $edge_insert_position && ! empty( $edge_inserted_blocks ) ) {
		foreach ( $edge_inserted_blocks as $offset => $edge_inserted_block ) {
			if ( 'server' === $edge_insert_source ) {
				$server_changed_indexes[] = $offset;
			} else {
				$local_changed_indexes[] = $offset;
			}

			$merged_blocks[] = $edge_inserted_block;
		}
	}

	for ( $index = 0; $index < $base_count; $index++ ) {
		$base_block     = $base_records[ $index ];
		$server_block   = $server_records[ $index + $server_base_offset ];
		$proposed_block = $proposed_records[ $index + $local_base_offset ];
		$merged_index   = $index + $merged_index_offset;
		$server_changed = ! hash_equals( $base_block, $server_block );
		$local_changed  = ! hash_equals( $base_block, $proposed_block );

		if ( $server_changed ) {
			$server_changed_indexes[] = $merged_index;
		}

		if ( $local_changed ) {
			$local_changed_indexes[] = $merged_index;
		}

			if ( $server_changed && $local_changed && ! hash_equals( $server_block, $proposed_block ) ) {
				$rich_text_merge = wp_de_rtc_get_rich_text_serialized_block_merge_candidate( $base_block, $server_block, $proposed_block );

				if ( is_array( $rich_text_merge ) && ! empty( $rich_text_merge['merged_block'] ) ) {
					$merged_blocks[]            = $rich_text_merge['merged_block'];
					$rich_text_merged_indexes[] = $merged_index;
					continue;
				}

				$table_cell_merge = wp_de_rtc_get_table_cell_serialized_block_merge_candidate( $base_block, $server_block, $proposed_block );

				if ( is_array( $table_cell_merge ) && ! empty( $table_cell_merge['merged_block'] ) ) {
					$merged_blocks[]              = $table_cell_merge['merged_block'];
					$table_cell_merged_indexes[]  = $merged_index;
					$table_cell_server_changed_cells = array_merge(
						$table_cell_server_changed_cells,
						wp_de_rtc_add_block_index_to_table_cell_evidence( $table_cell_merge['server_changed_cells'], $merged_index )
					);
					$table_cell_local_changed_cells = array_merge(
						$table_cell_local_changed_cells,
						wp_de_rtc_add_block_index_to_table_cell_evidence( $table_cell_merge['local_changed_cells'], $merged_index )
					);
					continue;
				}

				$conflicting_indexes[] = $merged_index;
				$conflicting_block_hashes[] = array(
				'block_index'         => $merged_index,
				'base_block_hash'     => wp_de_rtc_hash_content( $base_block ),
				'server_block_hash'   => wp_de_rtc_hash_content( $server_block ),
				'proposed_block_hash' => wp_de_rtc_hash_content( $proposed_block ),
			);
		}

		$merged_blocks[] = $local_changed ? $proposed_block : $server_block;
	}

	if ( ! empty( $conflicting_indexes ) ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'same_serialized_block_changed',
			array(
				'conflicting_block_index'    => (int) $conflicting_indexes[0],
				'conflicting_block_indexes'  => array_map( 'intval', $conflicting_indexes ),
				'conflicting_block_count'    => count( $conflicting_indexes ),
				'server_changed_indexes'     => $server_changed_indexes,
				'local_changed_indexes'      => $local_changed_indexes,
				'server_changed_block_count' => count( $server_changed_indexes ),
				'local_changed_block_count'  => count( $local_changed_indexes ),
				'conflicting_block_hashes'   => $conflicting_block_hashes,
			)
		);
	}

	if ( 'append' === $edge_insert_position && ! empty( $edge_inserted_blocks ) ) {
		foreach ( $edge_inserted_blocks as $offset => $appended_block ) {
			$appended_index = $base_count + $offset;

			if ( 'server' === $edge_insert_source ) {
				$server_changed_indexes[] = $appended_index;
			} else {
				$local_changed_indexes[] = $appended_index;
			}

			$merged_blocks[] = $appended_block;
		}
	}

	$merged_content = implode( '', $merged_blocks );
	$merged_count   = count( $merged_blocks );

	return array(
		'merge_status'                 => 'merged',
		'merge_strategy'               => 'top_level_serialized_block_three_way',
		'base_version'                 => isset( $args['base_version'] ) ? sanitize_text_field( (string) $args['base_version'] ) : null,
		'server_version'               => isset( $args['server_version'] ) ? sanitize_text_field( (string) $args['server_version'] ) : null,
		'base_revision_id'             => isset( $args['base_revision_id'] ) ? (int) $args['base_revision_id'] : 0,
		'block_count'                  => $merged_count,
		'base_block_count'             => $base_count,
		'server_block_count'           => $server_count,
		'proposed_block_count'         => $proposed_count,
		'merged_block_count'           => $merged_count,
		'edge_insert_source'           => $edge_insert_source,
		'edge_insert_position'         => $edge_insert_position,
		'edge_inserted_block_count'    => count( $edge_inserted_blocks ),
		'append_source'                => 'append' === $edge_insert_position ? $edge_insert_source : null,
		'appended_block_count'         => 'append' === $edge_insert_position ? count( $edge_inserted_blocks ) : 0,
		'prepend_source'               => 'prepend' === $edge_insert_position ? $edge_insert_source : null,
		'prepended_block_count'        => 'prepend' === $edge_insert_position ? count( $edge_inserted_blocks ) : 0,
		'server_changed_indexes'       => $server_changed_indexes,
			'local_changed_indexes'        => $local_changed_indexes,
			'rich_text_merged_indexes'     => array_map( 'intval', $rich_text_merged_indexes ),
			'rich_text_merged_block_count' => count( $rich_text_merged_indexes ),
			'table_cell_merged_indexes'    => array_map( 'intval', $table_cell_merged_indexes ),
			'table_cell_merged_block_count' => count( $table_cell_merged_indexes ),
			'table_cell_server_changed_cells' => $table_cell_server_changed_cells,
			'table_cell_local_changed_cells' => $table_cell_local_changed_cells,
			'server_changed_block_count'   => count( $server_changed_indexes ),
		'local_changed_block_count'    => count( $local_changed_indexes ),
		'merged_content'               => $merged_content,
		'merged_stripped_content_hash' => wp_de_rtc_hash_content( $merged_content ),
		'base_content_hash'            => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'          => wp_de_rtc_hash_content( $server_content ),
		'proposed_content_hash'        => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Returns a proof-backed block identity merge for a newer server body.
 *
 * This intentionally does not merge arbitrary changed bodies. It lets a stale
 * retry-save use a validated identity request proof when the current server
 * content and identity map still match the accepted-base revision, or when the
 * current server identity map proves server-only insertions in gaps different
 * from the client's identity-proven insertions.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $base_content                            Stripped post content for the accepted proof version.
 * @param string $server_content                          Current stripped server post content.
 * @param string $proposed_content                        Client-proposed stripped post content.
 * @param mixed  $block_identity_request_proof            Request proof candidate.
 * @param array  $block_identity_request_proof_validation Validated proof evidence.
 * @param array  $args                                    Optional merge evidence.
 * @return array|WP_Error Merge result, or conflict error.
 */
function wp_de_rtc_get_block_identity_server_merge_result( $base_content, $server_content, $proposed_content, $block_identity_request_proof, $block_identity_request_proof_validation, $args = array() ) {
	$base_records     = wp_de_rtc_get_top_level_serialized_block_records( $base_content );
	$server_records   = wp_de_rtc_get_top_level_serialized_block_records( $server_content );
	$proposed_records = wp_de_rtc_get_top_level_serialized_block_records( $proposed_content );

	foreach ( array( $base_records, $server_records, $proposed_records ) as $record_set ) {
		if ( is_wp_error( $record_set ) ) {
			return $record_set;
		}
	}

	$base_count     = count( $base_records );
	$server_count   = count( $server_records );
	$proposed_count = count( $proposed_records );

	$proof_map_check = wp_de_rtc_validate_block_identity_request_proof_matches_proposed_content(
		$block_identity_request_proof,
		isset( $args['base_sync_meta'] ) ? $args['base_sync_meta'] : null,
		$proposed_records
	);

	if ( is_wp_error( $proof_map_check ) ) {
		return $proof_map_check;
	}

	if (
		! is_array( $block_identity_request_proof_validation ) ||
		! isset( $block_identity_request_proof_validation['proposed_block_count'] ) ||
		(int) $block_identity_request_proof_validation['proposed_block_count'] !== $proposed_count
	) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'block_identity_proof_content_mismatch',
			array(
				'server_merge_strategy' => 'top_level_serialized_block_identity_map',
				'base_block_count'      => $base_count,
				'server_block_count'    => $server_count,
				'proposed_block_count'  => $proposed_count,
			)
		);
	}

	if (
		! hash_equals( $base_content, $server_content ) ||
		! wp_de_rtc_block_identity_sync_meta_stable_map_matches(
			isset( $args['base_sync_meta'] ) ? $args['base_sync_meta'] : null,
			isset( $args['current_sync_meta'] ) ? $args['current_sync_meta'] : null
		)
	) {
		if (
			$base_count === $server_count &&
			$base_count === $proposed_count &&
			isset( $block_identity_request_proof_validation['inserted_block_count'], $block_identity_request_proof_validation['deleted_block_count'], $block_identity_request_proof_validation['moved_block_count'] ) &&
			0 === (int) $block_identity_request_proof_validation['inserted_block_count'] &&
			0 === (int) $block_identity_request_proof_validation['deleted_block_count'] &&
			0 === (int) $block_identity_request_proof_validation['moved_block_count']
		) {
			return wp_de_rtc_get_block_identity_retained_edits_server_merge_result(
				$base_records,
				$server_records,
				$proposed_records,
				$base_content,
				$server_content,
				$proposed_content,
				$block_identity_request_proof,
				$block_identity_request_proof_validation,
				$args
			);
		}

		return wp_de_rtc_get_block_identity_insertions_only_server_merge_result(
			$base_records,
			$server_records,
			$proposed_records,
			$base_content,
			$server_content,
			$proposed_content,
			$block_identity_request_proof,
			$block_identity_request_proof_validation,
			$args
		);
	}

	if ( ! empty( $proof_map_check['retained_block_changed_indexes'] ) ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'block_identity_retained_block_changed',
			array(
				'server_merge_strategy' => 'top_level_serialized_block_identity_map',
				'block_index'           => (int) $proof_map_check['retained_block_changed_indexes'][0],
			)
		);
	}

	$local_changed_indexes = $proof_map_check['inserted_block_indexes'];

	return array(
		'merge_status'                        => 'merged',
		'merge_strategy'                      => 'top_level_serialized_block_identity_map',
		'base_version'                        => isset( $args['base_version'] ) ? sanitize_text_field( (string) $args['base_version'] ) : null,
		'server_version'                      => isset( $args['server_version'] ) ? sanitize_text_field( (string) $args['server_version'] ) : null,
		'base_revision_id'                    => isset( $args['base_revision_id'] ) ? (int) $args['base_revision_id'] : 0,
		'block_count'                         => $proposed_count,
		'base_block_count'                    => $base_count,
		'server_block_count'                  => $server_count,
		'proposed_block_count'                => $proposed_count,
		'merged_block_count'                  => $proposed_count,
		'edge_insert_source'                  => null,
		'edge_insert_position'                => null,
		'edge_inserted_block_count'           => 0,
		'append_source'                       => null,
		'appended_block_count'                => 0,
		'prepend_source'                      => null,
		'prepended_block_count'               => 0,
		'server_changed_indexes'              => array(),
		'local_changed_indexes'               => $local_changed_indexes,
		'server_changed_block_count'          => 0,
		'local_changed_block_count'           => count( $local_changed_indexes ),
		'block_identity_base_current_match'   => true,
		'block_identity_base_current_insertions_only' => false,
		'block_identity_inserted_indexes'     => $proof_map_check['inserted_block_indexes'],
		'block_identity_inserted_block_count' => $block_identity_request_proof_validation['inserted_block_count'],
		'block_identity_server_inserted_indexes' => array(),
		'block_identity_server_inserted_block_count' => 0,
		'block_identity_moved_block_count'    => $block_identity_request_proof_validation['moved_block_count'],
		'merged_content'                      => $proposed_content,
		'merged_stripped_content_hash'        => wp_de_rtc_hash_content( $proposed_content ),
		'base_content_hash'                   => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'                 => wp_de_rtc_hash_content( $server_content ),
		'proposed_content_hash'               => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Returns a proof-backed block identity merge for retained block edits.
 *
 * This helper accepts only same-count, same-order retained block edits. A
 * server edit to one retained block and a local edit to a different retained
 * block can merge. Competing edits to the same retained block require manual
 * resolution.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records                           Accepted-base serialized block records.
 * @param string[] $server_records                         Current server serialized block records.
 * @param string[] $proposed_records                       Client-proposed serialized block records.
 * @param string   $base_content                           Stripped post content for the accepted proof version.
 * @param string   $server_content                         Current stripped server post content.
 * @param string   $proposed_content                       Client-proposed stripped post content.
 * @param mixed    $block_identity_request_proof           Request proof candidate.
 * @param array    $block_identity_request_proof_validation Validated proof evidence.
 * @param array    $args                                   Optional merge evidence.
 * @return array|WP_Error Merge result, or conflict error.
 */
function wp_de_rtc_get_block_identity_retained_edits_server_merge_result( $base_records, $server_records, $proposed_records, $base_content, $server_content, $proposed_content, $block_identity_request_proof, $block_identity_request_proof_validation, $args ) {
	$base_sync_meta    = isset( $args['base_sync_meta'] ) ? $args['base_sync_meta'] : null;
	$current_sync_meta = isset( $args['current_sync_meta'] ) ? $args['current_sync_meta'] : null;
	$base_sequence     = wp_de_rtc_get_block_identity_base_sequence_for_merge( $base_sync_meta, $base_records );

	if ( is_wp_error( $base_sequence ) ) {
		return $base_sequence;
	}

	$server_sequence = wp_de_rtc_get_block_identity_current_sequence_for_merge(
		$current_sync_meta,
		$base_sequence,
		$server_records,
		$proposed_records,
		$server_content,
		array(
			'allow_retained_block_edits' => true,
		)
	);

	if ( is_wp_error( $server_sequence ) ) {
		return $server_sequence;
	}

	$proposed_sequence = wp_de_rtc_get_block_identity_proposed_sequence_for_merge(
		$block_identity_request_proof,
		$base_sequence,
		$proposed_records,
		$server_records,
		array(
			'allow_retained_block_edits' => true,
		)
	);

	if ( is_wp_error( $proposed_sequence ) ) {
		return $proposed_sequence;
	}

	$merged_blocks             = array();
		$merged_block_map          = array();
		$server_changed_indexes    = array();
		$local_changed_indexes     = array();
		$retained_edit_indexes     = array();
		$table_cell_merged_indexes = array();
		$table_cell_server_changed_cells = array();
		$table_cell_local_changed_cells  = array();
		$rich_text_merged_indexes        = array();
		$base_count                = count( $base_records );

	for ( $index = 0; $index < $base_count; $index++ ) {
		$base_item     = $base_sequence['items'][ $index ];
		$server_item   = $server_sequence[ $index ];
		$proposed_item = $proposed_sequence[ $index ];

		if (
			! isset( $server_item['block_uid'], $proposed_item['block_uid'], $base_item['block_uid'] ) ||
			$server_item['block_uid'] !== $base_item['block_uid'] ||
			$proposed_item['block_uid'] !== $base_item['block_uid']
		) {
			return wp_de_rtc_get_block_identity_insertions_only_conflict(
				'block_identity_base_drift',
				$base_records,
				$server_records,
				$proposed_records
			);
		}

		$server_changed = ! hash_equals( $base_item['serialized_hash'], $server_item['serialized_hash'] );
		$local_changed  = ! hash_equals( $base_item['serialized_hash'], $proposed_item['serialized_hash'] );
		$merged_index   = count( $merged_blocks );

			if ( $server_changed && $local_changed && ! hash_equals( $server_item['serialized_hash'], $proposed_item['serialized_hash'] ) ) {
				$rich_text_merge = wp_de_rtc_get_rich_text_serialized_block_merge_candidate( $base_item['record'], $server_item['record'], $proposed_item['record'] );

				if ( is_array( $rich_text_merge ) && ! empty( $rich_text_merge['merged_block'] ) ) {
					$merged_record                   = $rich_text_merge['merged_block'];
					$merged_item                     = $base_item;
					$merged_item['record']           = $merged_record;
					$merged_item['serialized_hash']  = wp_de_rtc_hash_content( $merged_record );
					$server_changed_indexes[]        = $merged_index;
					$local_changed_indexes[]         = $merged_index;
					$retained_edit_indexes[]         = $merged_index;
					$rich_text_merged_indexes[]      = $merged_index;
					$merged_blocks[]                 = $merged_item['record'];
					$merged_block_map[]              = wp_de_rtc_get_block_identity_merged_map_record( $merged_item, $merged_index );
					continue;
				}

				$table_cell_merge = wp_de_rtc_get_table_cell_serialized_block_merge_candidate( $base_item['record'], $server_item['record'], $proposed_item['record'] );

				if ( is_array( $table_cell_merge ) && ! empty( $table_cell_merge['merged_block'] ) ) {
					$merged_record                         = $table_cell_merge['merged_block'];
					$merged_item                           = $base_item;
					$merged_item['record']                 = $merged_record;
					$merged_item['serialized_hash']        = wp_de_rtc_hash_content( $merged_record );
					$server_changed_indexes[]              = $merged_index;
					$local_changed_indexes[]               = $merged_index;
					$retained_edit_indexes[]               = $merged_index;
					$table_cell_merged_indexes[]           = $merged_index;
					$table_cell_server_changed_cells       = array_merge(
						$table_cell_server_changed_cells,
						wp_de_rtc_add_block_index_to_table_cell_evidence( $table_cell_merge['server_changed_cells'], $merged_index )
					);
					$table_cell_local_changed_cells        = array_merge(
						$table_cell_local_changed_cells,
						wp_de_rtc_add_block_index_to_table_cell_evidence( $table_cell_merge['local_changed_cells'], $merged_index )
					);
					$merged_blocks[]                       = $merged_item['record'];
					$merged_block_map[]                    = wp_de_rtc_get_block_identity_merged_map_record( $merged_item, $merged_index );
					continue;
				}

				return wp_de_rtc_get_block_identity_insertions_only_conflict(
					'block_identity_retained_block_conflict',
				$base_records,
				$server_records,
				$proposed_records,
				array(
					'block_index'                  => (int) $index,
					'conflicting_block_index'      => (int) $index,
					'conflicting_block_indexes'    => array( (int) $index ),
					'conflicting_block_count'      => 1,
					'server_changed_indexes'       => array( (int) $index ),
					'local_changed_indexes'        => array( (int) $index ),
					'server_changed_block_count'   => 1,
					'local_changed_block_count'    => 1,
					'block_identity_base_current_retained_edits_only' => false,
				)
			);
		}

		if ( $server_changed ) {
			$merged_item              = $server_item;
			$server_changed_indexes[] = $merged_index;
			$retained_edit_indexes[]  = $merged_index;
		} elseif ( $local_changed ) {
			$merged_item             = $proposed_item;
			$local_changed_indexes[] = $merged_index;
			$retained_edit_indexes[] = $merged_index;
		} else {
			$merged_item = $base_item;
		}

		$merged_blocks[]    = $merged_item['record'];
		$merged_block_map[] = wp_de_rtc_get_block_identity_merged_map_record( $merged_item, $merged_index );
	}

	if ( empty( $server_changed_indexes ) || empty( $local_changed_indexes ) ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	$merged_content = implode( '', $merged_blocks );
	$merged_count   = count( $merged_blocks );

	return array(
		'merge_status'                        => 'merged',
		'merge_strategy'                      => 'top_level_serialized_block_identity_map',
		'base_version'                        => isset( $args['base_version'] ) ? sanitize_text_field( (string) $args['base_version'] ) : null,
		'server_version'                      => isset( $args['server_version'] ) ? sanitize_text_field( (string) $args['server_version'] ) : null,
		'base_revision_id'                    => isset( $args['base_revision_id'] ) ? (int) $args['base_revision_id'] : 0,
		'block_count'                         => $merged_count,
		'base_block_count'                    => $base_count,
		'server_block_count'                  => count( $server_records ),
		'proposed_block_count'                => count( $proposed_records ),
		'merged_block_count'                  => $merged_count,
		'edge_insert_source'                  => null,
		'edge_insert_position'                => null,
		'edge_inserted_block_count'           => 0,
		'append_source'                       => null,
		'appended_block_count'                => 0,
		'prepend_source'                      => null,
		'prepended_block_count'               => 0,
		'server_changed_indexes'              => $server_changed_indexes,
		'local_changed_indexes'               => $local_changed_indexes,
		'server_changed_block_count'          => count( $server_changed_indexes ),
		'local_changed_block_count'           => count( $local_changed_indexes ),
		'block_identity_base_current_match'   => false,
		'block_identity_base_current_insertions_only' => false,
			'block_identity_base_current_retained_edits_only' => true,
			'block_identity_retained_edit_indexes' => $retained_edit_indexes,
			'block_identity_retained_edit_block_count' => count( $retained_edit_indexes ),
			'table_cell_merged_indexes'          => array_map( 'intval', $table_cell_merged_indexes ),
			'table_cell_merged_block_count'      => count( $table_cell_merged_indexes ),
			'table_cell_server_changed_cells'    => $table_cell_server_changed_cells,
			'table_cell_local_changed_cells'     => $table_cell_local_changed_cells,
			'rich_text_merged_indexes'           => array_map( 'intval', $rich_text_merged_indexes ),
			'rich_text_merged_block_count'       => count( $rich_text_merged_indexes ),
			'block_identity_inserted_indexes'     => array(),
		'block_identity_inserted_block_count' => $block_identity_request_proof_validation['inserted_block_count'],
		'block_identity_server_inserted_indexes' => array(),
		'block_identity_server_inserted_block_count' => 0,
		'block_identity_moved_block_count'    => $block_identity_request_proof_validation['moved_block_count'],
		'block_identity_merged_block_map'     => $merged_block_map,
		'merged_content'                      => $merged_content,
		'merged_stripped_content_hash'        => wp_de_rtc_hash_content( $merged_content ),
		'base_content_hash'                   => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'                 => wp_de_rtc_hash_content( $server_content ),
		'proposed_content_hash'               => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Returns a proof-backed block identity merge for insertions in distinct gaps.
 *
 * This helper accepts only insertion-only divergence from the accepted base.
 * Retained base blocks must appear in the original order with unchanged hashes
 * on both the server and proposed sides. If both sides inserted into the same
 * gap, ordering is ambiguous and the merge is rejected for manual resolution.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records                           Accepted-base serialized block records.
 * @param string[] $server_records                         Current server serialized block records.
 * @param string[] $proposed_records                       Client-proposed serialized block records.
 * @param string   $base_content                           Stripped post content for the accepted proof version.
 * @param string   $server_content                         Current stripped server post content.
 * @param string   $proposed_content                       Client-proposed stripped post content.
 * @param mixed    $block_identity_request_proof           Request proof candidate.
 * @param array    $block_identity_request_proof_validation Validated proof evidence.
 * @param array    $args                                   Optional merge evidence.
 * @return array|WP_Error Merge result, or conflict error.
 */
function wp_de_rtc_get_block_identity_insertions_only_server_merge_result( $base_records, $server_records, $proposed_records, $base_content, $server_content, $proposed_content, $block_identity_request_proof, $block_identity_request_proof_validation, $args ) {
	$base_sync_meta    = isset( $args['base_sync_meta'] ) ? $args['base_sync_meta'] : null;
	$current_sync_meta = isset( $args['current_sync_meta'] ) ? $args['current_sync_meta'] : null;
	$base_sequence     = wp_de_rtc_get_block_identity_base_sequence_for_merge( $base_sync_meta, $base_records );

	if ( is_wp_error( $base_sequence ) ) {
		return $base_sequence;
	}

	if (
		! is_array( $block_identity_request_proof_validation ) ||
		! isset( $block_identity_request_proof_validation['deleted_block_count'] ) ||
		0 !== (int) $block_identity_request_proof_validation['deleted_block_count']
	) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_unsupported_delta',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	$server_sequence = wp_de_rtc_get_block_identity_current_sequence_for_merge(
		$current_sync_meta,
		$base_sequence,
		$server_records,
		$proposed_records,
		$server_content
	);

	if ( is_wp_error( $server_sequence ) ) {
		return $server_sequence;
	}

	$proposed_sequence = wp_de_rtc_get_block_identity_proposed_sequence_for_merge(
		$block_identity_request_proof,
		$base_sequence,
		$proposed_records,
		$server_records
	);

	if ( is_wp_error( $proposed_sequence ) ) {
		return $proposed_sequence;
	}

	$base_count       = count( $base_records );
	$server_gaps      = wp_de_rtc_get_block_identity_insertions_by_gap( $server_sequence, $base_count );
	$proposed_gaps    = wp_de_rtc_get_block_identity_insertions_by_gap( $proposed_sequence, $base_count );
	$merged_blocks    = array();
	$merged_block_map = array();
	$server_changed_indexes = array();
	$local_changed_indexes  = array();
	$server_inserted_indexes = array();
	$local_inserted_indexes  = array();

	for ( $gap_index = 0; $gap_index <= $base_count; $gap_index++ ) {
		$server_insertions   = $server_gaps[ $gap_index ];
		$proposed_insertions = $proposed_gaps[ $gap_index ];

		if ( ! empty( $server_insertions ) && ! empty( $proposed_insertions ) ) {
			return wp_de_rtc_get_block_identity_insertions_only_conflict(
				'block_identity_inserted_block_gap_conflict',
				$base_records,
				$server_records,
				$proposed_records,
				array(
					'block_identity_conflicting_gap_index' => $gap_index,
					'block_identity_server_inserted_block_count_in_gap' => count( $server_insertions ),
					'block_identity_local_inserted_block_count_in_gap' => count( $proposed_insertions ),
				)
			);
		}

		foreach ( $server_insertions as $inserted_item ) {
			$merged_index              = count( $merged_blocks );
			$merged_blocks[]           = $inserted_item['record'];
			$server_changed_indexes[]  = $merged_index;
			$server_inserted_indexes[] = $merged_index;
			$merged_block_map[]        = wp_de_rtc_get_block_identity_merged_map_record( $inserted_item, $merged_index );
		}

		foreach ( $proposed_insertions as $inserted_item ) {
			$merged_index             = count( $merged_blocks );
			$merged_blocks[]          = $inserted_item['record'];
			$local_changed_indexes[]  = $merged_index;
			$local_inserted_indexes[] = $merged_index;
			$merged_block_map[]       = wp_de_rtc_get_block_identity_merged_map_record( $inserted_item, $merged_index );
		}

		if ( $gap_index < $base_count ) {
			$base_item          = $base_sequence['items'][ $gap_index ];
			$merged_index       = count( $merged_blocks );
			$merged_blocks[]    = $base_item['record'];
			$merged_block_map[] = wp_de_rtc_get_block_identity_merged_map_record( $base_item, $merged_index );
		}
	}

	$merged_content = implode( '', $merged_blocks );
	$merged_count   = count( $merged_blocks );

	return array(
		'merge_status'                        => 'merged',
		'merge_strategy'                      => 'top_level_serialized_block_identity_map',
		'base_version'                        => isset( $args['base_version'] ) ? sanitize_text_field( (string) $args['base_version'] ) : null,
		'server_version'                      => isset( $args['server_version'] ) ? sanitize_text_field( (string) $args['server_version'] ) : null,
		'base_revision_id'                    => isset( $args['base_revision_id'] ) ? (int) $args['base_revision_id'] : 0,
		'block_count'                         => $merged_count,
		'base_block_count'                    => $base_count,
		'server_block_count'                  => count( $server_records ),
		'proposed_block_count'                => count( $proposed_records ),
		'merged_block_count'                  => $merged_count,
		'edge_insert_source'                  => null,
		'edge_insert_position'                => null,
		'edge_inserted_block_count'           => 0,
		'append_source'                       => null,
		'appended_block_count'                => 0,
		'prepend_source'                      => null,
		'prepended_block_count'               => 0,
		'server_changed_indexes'              => $server_changed_indexes,
		'local_changed_indexes'               => $local_changed_indexes,
		'server_changed_block_count'          => count( $server_changed_indexes ),
		'local_changed_block_count'           => count( $local_changed_indexes ),
		'block_identity_base_current_match'   => false,
		'block_identity_base_current_insertions_only' => true,
		'block_identity_inserted_indexes'     => $local_inserted_indexes,
		'block_identity_inserted_block_count' => $block_identity_request_proof_validation['inserted_block_count'],
		'block_identity_server_inserted_indexes' => $server_inserted_indexes,
		'block_identity_server_inserted_block_count' => count( $server_inserted_indexes ),
		'block_identity_moved_block_count'    => $block_identity_request_proof_validation['moved_block_count'],
		'block_identity_merged_block_map'     => $merged_block_map,
		'merged_content'                      => $merged_content,
		'merged_stripped_content_hash'        => wp_de_rtc_hash_content( $merged_content ),
		'base_content_hash'                   => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'                 => wp_de_rtc_hash_content( $server_content ),
		'proposed_content_hash'               => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Returns accepted-base block identity sequence data for a merge.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed    $base_sync_meta Accepted-base sync metadata.
 * @param string[] $base_records   Accepted-base serialized block records.
 * @return array|WP_Error Sequence data, or conflict error.
 */
function wp_de_rtc_get_block_identity_base_sequence_for_merge( $base_sync_meta, $base_records ) {
	$validation = wp_de_rtc_validate_block_identity_sync_meta_contract( $base_sync_meta );

	if ( is_wp_error( $validation ) ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			array(),
			array()
		);
	}

	$base_sync_meta = wp_de_rtc_normalize_block_identity_object( $base_sync_meta );
	$blocks         = isset( $base_sync_meta['blocks'] ) && is_array( $base_sync_meta['blocks'] ) ? $base_sync_meta['blocks'] : array();

	if ( count( $blocks ) !== count( $base_records ) ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			array(),
			array()
		);
	}

	$items  = array();
	$by_uid = array();
	$uids   = array();

	foreach ( $blocks as $index => $block ) {
		$block       = wp_de_rtc_normalize_block_identity_object( $block );
		$record_hash = wp_de_rtc_hash_content( $base_records[ $index ] );

		if (
			! is_array( $block ) ||
			! isset( $block['block_uid'], $block['block_name'], $block['serialized_hash'] ) ||
			! hash_equals( (string) $block['serialized_hash'], $record_hash )
		) {
			return wp_de_rtc_get_block_identity_insertions_only_conflict(
				'block_identity_base_drift',
				$base_records,
				array(),
				array()
			);
		}

		$item = array(
			'type'            => 'base',
			'base_index'      => (int) $index,
			'block_uid'       => sanitize_text_field( (string) $block['block_uid'] ),
			'block_name'      => sanitize_text_field( (string) $block['block_name'] ),
			'serialized_hash' => $record_hash,
			'record'          => $base_records[ $index ],
		);

		$items[]                  = $item;
		$by_uid[ $item['block_uid'] ] = $item;
		$uids[]                   = $item['block_uid'];
	}

	return array(
		'document_uuid' => $validation['document_uuid'],
		'records' => $base_records,
		'items'  => $items,
		'by_uid' => $by_uid,
		'uids'   => $uids,
	);
}

/**
 * Returns current-server block identity sequence data for a merge.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed    $current_sync_meta Current server sync metadata.
 * @param array    $base_sequence     Accepted-base sequence data.
 * @param string[] $server_records    Current server serialized block records.
 * @param string[] $proposed_records  Client-proposed serialized block records.
 * @param string   $server_content    Current stripped server post content.
 * @param array    $options           Optional validation controls.
 * @return array|WP_Error Sequence data, or conflict error.
 */
function wp_de_rtc_get_block_identity_current_sequence_for_merge( $current_sync_meta, $base_sequence, $server_records, $proposed_records = array(), $server_content = null, $options = array() ) {
	$validation = wp_de_rtc_validate_block_identity_sync_meta_contract( $current_sync_meta );
	$base_count = count( $base_sequence['items'] );
	$base_records = isset( $base_sequence['records'] ) && is_array( $base_sequence['records'] ) ? $base_sequence['records'] : $base_sequence['items'];
	$allow_retained_block_edits = ! empty( $options['allow_retained_block_edits'] );

	if ( is_wp_error( $validation ) ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	if (
		! isset( $base_sequence['document_uuid'] ) ||
		$validation['document_uuid'] !== $base_sequence['document_uuid'] ||
		( null !== $server_content && ! hash_equals( $validation['content_hash'], wp_de_rtc_hash_content( (string) $server_content ) ) )
	) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	$current_sync_meta = wp_de_rtc_normalize_block_identity_object( $current_sync_meta );
	$blocks            = isset( $current_sync_meta['blocks'] ) && is_array( $current_sync_meta['blocks'] ) ? $current_sync_meta['blocks'] : array();

	if ( count( $blocks ) !== count( $server_records ) ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	$sequence        = array();
	$next_base_index = 0;

	foreach ( $blocks as $index => $block ) {
		$block       = wp_de_rtc_normalize_block_identity_object( $block );
		$record_hash = wp_de_rtc_hash_content( $server_records[ $index ] );

		if (
			! is_array( $block ) ||
			! isset( $block['block_uid'], $block['block_name'], $block['serialized_hash'] ) ||
			! hash_equals( (string) $block['serialized_hash'], $record_hash )
		) {
			return wp_de_rtc_get_block_identity_insertions_only_conflict(
				'block_identity_base_drift',
				$base_records,
				$server_records,
				$proposed_records
			);
		}

		$block_uid = sanitize_text_field( (string) $block['block_uid'] );

		if ( isset( $base_sequence['by_uid'][ $block_uid ] ) ) {
			if (
				$next_base_index >= $base_count ||
				$base_sequence['uids'][ $next_base_index ] !== $block_uid ||
				( ! $allow_retained_block_edits && ! hash_equals( $base_sequence['by_uid'][ $block_uid ]['serialized_hash'], $record_hash ) )
			) {
				return wp_de_rtc_get_block_identity_insertions_only_conflict(
					'block_identity_base_drift',
					$base_records,
					$server_records,
					$proposed_records
				);
			}

			$sequence[] = array_merge(
				$base_sequence['by_uid'][ $block_uid ],
				array(
					'accepted_serialized_hash' => $base_sequence['by_uid'][ $block_uid ]['serialized_hash'],
					'serialized_hash'          => $record_hash,
					'record'                   => $server_records[ $index ],
				)
			);
			++$next_base_index;
			continue;
		}

		$sequence[] = array(
			'type'            => 'server_inserted',
			'base_index'      => null,
			'block_uid'       => $block_uid,
			'block_name'      => sanitize_text_field( (string) $block['block_name'] ),
			'serialized_hash' => $record_hash,
			'record'          => $server_records[ $index ],
		);
	}

	if ( $next_base_index !== $base_count ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_base_drift',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	return $sequence;
}

/**
 * Returns proposed-client block identity sequence data for a merge.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed    $request_proof    Validated request proof.
 * @param array    $base_sequence    Accepted-base sequence data.
 * @param string[] $proposed_records Client-proposed serialized block records.
 * @param string[] $server_records   Current server serialized block records.
 * @param array    $options          Optional validation controls.
 * @return array|WP_Error Sequence data, or conflict error.
 */
function wp_de_rtc_get_block_identity_proposed_sequence_for_merge( $request_proof, $base_sequence, $proposed_records, $server_records = array(), $options = array() ) {
	$request_proof = wp_de_rtc_normalize_block_identity_object( $request_proof );
	$proposed_map  = isset( $request_proof['proposed_block_map'] ) && is_array( $request_proof['proposed_block_map'] ) ? $request_proof['proposed_block_map'] : array();
	$base_count    = count( $base_sequence['items'] );
	$base_records  = isset( $base_sequence['records'] ) && is_array( $base_sequence['records'] ) ? $base_sequence['records'] : $base_sequence['items'];
	$allow_retained_block_edits = ! empty( $options['allow_retained_block_edits'] );

	if ( count( $proposed_map ) !== count( $proposed_records ) ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_proof_content_mismatch',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	$sequence        = array();
	$next_base_index = 0;

	foreach ( $proposed_map as $index => $block ) {
		$block       = wp_de_rtc_normalize_block_identity_object( $block );
		$record_hash = wp_de_rtc_hash_content( $proposed_records[ $index ] );

		if ( ! is_array( $block ) || ! isset( $block['block_name'], $block['serialized_hash'] ) || ! hash_equals( (string) $block['serialized_hash'], $record_hash ) ) {
			return wp_de_rtc_get_block_identity_insertions_only_conflict(
				'block_identity_proof_content_mismatch',
				$base_records,
				$server_records,
				$proposed_records
			);
		}

		if ( isset( $block['block_uid'] ) && '' !== (string) $block['block_uid'] ) {
			$block_uid = sanitize_text_field( (string) $block['block_uid'] );

			if (
				$next_base_index >= $base_count ||
				$base_sequence['uids'][ $next_base_index ] !== $block_uid ||
				( ! $allow_retained_block_edits && ! hash_equals( $base_sequence['by_uid'][ $block_uid ]['serialized_hash'], $record_hash ) )
			) {
				return wp_de_rtc_get_block_identity_insertions_only_conflict(
					'block_identity_base_drift',
					$base_records,
					$server_records,
					$proposed_records
				);
			}

			$sequence[] = array_merge(
				$base_sequence['by_uid'][ $block_uid ],
				array(
					'accepted_serialized_hash' => $base_sequence['by_uid'][ $block_uid ]['serialized_hash'],
					'serialized_hash'          => $record_hash,
					'record'                   => $proposed_records[ $index ],
				)
			);
			++$next_base_index;
			continue;
		}

		if ( ! isset( $block['inserted_block_nonce'] ) || '' === (string) $block['inserted_block_nonce'] ) {
			return wp_de_rtc_get_block_identity_insertions_only_conflict(
				'block_identity_proof_content_mismatch',
				$base_records,
				$server_records,
				$proposed_records
			);
		}

		$sequence[] = array(
			'type'                 => 'local_inserted',
			'base_index'           => null,
			'inserted_block_nonce' => sanitize_text_field( (string) $block['inserted_block_nonce'] ),
			'block_name'           => sanitize_text_field( (string) $block['block_name'] ),
			'serialized_hash'      => $record_hash,
			'record'               => $proposed_records[ $index ],
		);
	}

	if ( $next_base_index !== $base_count ) {
		return wp_de_rtc_get_block_identity_insertions_only_conflict(
			'block_identity_unsupported_delta',
			$base_records,
			$server_records,
			$proposed_records
		);
	}

	return $sequence;
}

/**
 * Returns inserted block sequence items grouped by accepted-base gap.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $sequence   Block identity sequence items.
 * @param int   $base_count Accepted-base block count.
 * @return array[] Insertions keyed by gap index.
 */
function wp_de_rtc_get_block_identity_insertions_by_gap( $sequence, $base_count ) {
	$gaps        = array_fill( 0, $base_count + 1, array() );
	$current_gap = 0;

	foreach ( $sequence as $item ) {
		if ( isset( $item['type'] ) && 'base' === $item['type'] ) {
			$current_gap = isset( $item['base_index'] ) ? (int) $item['base_index'] + 1 : $current_gap;
			continue;
		}

		$gaps[ $current_gap ][] = $item;
	}

	return $gaps;
}

/**
 * Returns a sync-meta block-map record for merged block identity content.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $item         Block identity sequence item.
 * @param int   $merged_index Merged block index.
 * @return array Block map record.
 */
function wp_de_rtc_get_block_identity_merged_map_record( $item, $merged_index ) {
	$record = array(
		'block_name'      => isset( $item['block_name'] ) ? sanitize_text_field( (string) $item['block_name'] ) : '',
		'ordinal_path'    => array( (int) $merged_index ),
		'serialized_hash' => isset( $item['serialized_hash'] ) ? sanitize_text_field( (string) $item['serialized_hash'] ) : '',
	);

	if ( isset( $item['block_uid'] ) && '' !== (string) $item['block_uid'] ) {
		$record['block_uid'] = sanitize_text_field( (string) $item['block_uid'] );
	} elseif ( isset( $item['inserted_block_nonce'] ) ) {
		$record['inserted_block_nonce'] = sanitize_text_field( (string) $item['inserted_block_nonce'] );
	}

	return $record;
}

/**
 * Creates an insertions-only block identity merge conflict.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $detail           Conflict detail.
 * @param array  $base_records     Accepted-base records or sequence items.
 * @param array  $server_records   Current server records.
 * @param array  $proposed_records Client-proposed records.
 * @param array  $extra            Optional extra data.
 * @return WP_Error Conflict error.
 */
function wp_de_rtc_get_block_identity_insertions_only_conflict( $detail, $base_records, $server_records, $proposed_records, $extra = array() ) {
	return wp_de_rtc_get_server_merge_conflict_error(
		$detail,
		array_merge(
			array(
				'server_merge_strategy'           => 'top_level_serialized_block_identity_map',
				'base_block_count'                => count( $base_records ),
				'server_block_count'              => count( $server_records ),
				'proposed_block_count'            => count( $proposed_records ),
				'server_block_count_changed'      => count( $base_records ) !== count( $server_records ),
				'local_block_count_changed'       => count( $base_records ) !== count( $proposed_records ),
				'server_block_count_delta'        => count( $server_records ) - count( $base_records ),
				'local_block_count_delta'         => count( $proposed_records ) - count( $base_records ),
				'block_identity_base_current_match' => false,
				'block_identity_base_current_insertions_only' => false,
			),
			$extra
		)
	);
}

/**
 * Attempts a conservative one-sided deletion merge across serialized blocks.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records     Accepted-base serialized block records.
 * @param string[] $server_records   Current server serialized block records.
 * @param string[] $proposed_records Client-proposed serialized block records.
 * @param string   $base_content     Stripped post content for the accepted proof version.
 * @param string   $server_content   Current stripped server post content.
 * @param string   $proposed_content Client-proposed stripped post content.
 * @param array    $args             Optional merge evidence.
 * @return array|WP_Error Merge result, or conflict error.
 */
function wp_de_rtc_get_serialized_block_deletion_merge_result( $base_records, $server_records, $proposed_records, $base_content, $server_content, $proposed_content, $args = array() ) {
	$base_count     = count( $base_records );
	$server_count   = count( $server_records );
	$proposed_count = count( $proposed_records );
	$server_deleted = $server_count < $base_count && $proposed_count === $base_count;
	$local_deleted  = $proposed_count < $base_count && $server_count === $base_count;

	if ( ! $server_deleted && ! $local_deleted ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'top_level_serialized_block_count_changed',
			array(
				'base_block_count'           => $base_count,
				'server_block_count'         => $server_count,
				'proposed_block_count'       => $proposed_count,
				'server_block_count_changed' => $base_count !== $server_count,
				'local_block_count_changed'  => $base_count !== $proposed_count,
				'server_block_count_delta'   => $server_count - $base_count,
				'local_block_count_delta'    => $proposed_count - $base_count,
			)
		);
	}

	$deletion_source  = $server_deleted ? 'server' : 'local';
	$stable_source    = $server_deleted ? 'local' : 'server';
	$deleting_records = $server_deleted ? $server_records : $proposed_records;
	$stable_records   = $server_deleted ? $proposed_records : $server_records;
	$deletion         = wp_de_rtc_get_serialized_block_deletion( $base_records, $deleting_records );

	if ( null === $deletion ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'top_level_serialized_block_count_changed',
			array(
				'base_block_count'           => $base_count,
				'server_block_count'         => $server_count,
				'proposed_block_count'       => $proposed_count,
				'server_block_count_changed' => $base_count !== $server_count,
				'local_block_count_changed'  => $base_count !== $proposed_count,
				'server_block_count_delta'   => $server_count - $base_count,
				'local_block_count_delta'    => $proposed_count - $base_count,
				'deletion_source'            => $deletion_source,
			)
		);
	}

	if ( 'ambiguous' === $deletion['status'] ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'ambiguous_block_deletion',
			array(
				'base_block_count'           => $base_count,
				'server_block_count'         => $server_count,
				'proposed_block_count'       => $proposed_count,
				'server_block_count_changed' => $base_count !== $server_count,
				'local_block_count_changed'  => $base_count !== $proposed_count,
				'server_block_count_delta'   => $server_count - $base_count,
				'local_block_count_delta'    => $proposed_count - $base_count,
				'deletion_source'            => $deletion_source,
				'deletion_ambiguous'         => true,
			)
		);
	}

	$stable_reordered_indexes = wp_de_rtc_get_reordered_serialized_block_indexes( $base_records, $stable_records );

	if ( ! empty( $stable_reordered_indexes ) ) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'top_level_serialized_block_reordered',
			array(
				'base_block_count'              => $base_count,
				'server_block_count'            => $server_count,
				'proposed_block_count'          => $proposed_count,
				'server_block_count_changed'    => $base_count !== $server_count,
				'local_block_count_changed'     => $base_count !== $proposed_count,
				'server_block_count_delta'      => $server_count - $base_count,
				'local_block_count_delta'       => $proposed_count - $base_count,
				'server_block_order_changed'    => 'server' === $stable_source,
				'local_block_order_changed'     => 'local' === $stable_source,
				'server_reordered_block_indexes' => 'server' === $stable_source ? $stable_reordered_indexes : array(),
				'local_reordered_block_indexes' => 'local' === $stable_source ? $stable_reordered_indexes : array(),
				'deletion_source'               => $deletion_source,
				'deleted_block_indexes'         => $deletion['deleted_indexes'],
				'deleted_block_count'           => count( $deletion['deleted_indexes'] ),
			)
		);
	}

	$deleted_lookup         = array_fill_keys( $deletion['deleted_indexes'], true );
	$merged_blocks          = array();
	$server_changed_indexes = array();
	$local_changed_indexes  = array();

	for ( $index = 0; $index < $base_count; $index++ ) {
		$base_block     = $base_records[ $index ];
		$stable_block   = $stable_records[ $index ];
		$stable_changed = ! hash_equals( $base_block, $stable_block );

		if ( isset( $deleted_lookup[ $index ] ) ) {
			if ( $stable_changed ) {
				return wp_de_rtc_get_server_merge_conflict_error(
					'deleted_serialized_block_changed',
					array(
						'conflicting_block_index'    => (int) $index,
						'conflicting_block_indexes'  => array( (int) $index ),
						'conflicting_block_count'    => 1,
						'base_block_count'           => $base_count,
						'server_block_count'         => $server_count,
						'proposed_block_count'       => $proposed_count,
						'server_block_count_changed' => $base_count !== $server_count,
						'local_block_count_changed'  => $base_count !== $proposed_count,
						'server_block_count_delta'   => $server_count - $base_count,
						'local_block_count_delta'    => $proposed_count - $base_count,
						'deletion_source'            => $deletion_source,
						'deleted_block_indexes'      => $deletion['deleted_indexes'],
						'deleted_block_count'        => count( $deletion['deleted_indexes'] ),
						'deleted_block_changed_source' => $stable_source,
						'server_changed_indexes'     => 'server' === $stable_source ? array( (int) $index ) : array(),
						'local_changed_indexes'      => 'local' === $stable_source ? array( (int) $index ) : array(),
						'server_changed_block_count' => ( 'server' === $stable_source ? 1 : 0 ) + ( 'server' === $deletion_source ? count( $deletion['deleted_indexes'] ) : 0 ),
						'local_changed_block_count'  => ( 'local' === $stable_source ? 1 : 0 ) + ( 'local' === $deletion_source ? count( $deletion['deleted_indexes'] ) : 0 ),
					)
				);
			}

			continue;
		}

		$merged_index = count( $merged_blocks );

		if ( $stable_changed ) {
			if ( 'server' === $stable_source ) {
				$server_changed_indexes[] = $merged_index;
			} else {
				$local_changed_indexes[] = $merged_index;
			}
		}

		$merged_blocks[] = $stable_changed ? $stable_block : $base_block;
	}

	$merged_content = implode( '', $merged_blocks );
	$merged_count   = count( $merged_blocks );

	return array(
		'merge_status'                 => 'merged',
		'merge_strategy'               => 'top_level_serialized_block_three_way',
		'base_version'                 => isset( $args['base_version'] ) ? sanitize_text_field( (string) $args['base_version'] ) : null,
		'server_version'               => isset( $args['server_version'] ) ? sanitize_text_field( (string) $args['server_version'] ) : null,
		'base_revision_id'             => isset( $args['base_revision_id'] ) ? (int) $args['base_revision_id'] : 0,
		'block_count'                  => $merged_count,
		'base_block_count'             => $base_count,
		'server_block_count'           => $server_count,
		'proposed_block_count'         => $proposed_count,
		'merged_block_count'           => $merged_count,
		'edge_insert_source'           => null,
		'edge_insert_position'         => null,
		'edge_inserted_block_count'    => 0,
		'append_source'                => null,
		'appended_block_count'         => 0,
		'prepend_source'               => null,
		'prepended_block_count'        => 0,
		'deletion_source'              => $deletion_source,
		'deleted_block_indexes'        => $deletion['deleted_indexes'],
		'deleted_block_count'          => count( $deletion['deleted_indexes'] ),
		'server_changed_indexes'       => $server_changed_indexes,
		'local_changed_indexes'        => $local_changed_indexes,
		'server_changed_block_count'   => count( $server_changed_indexes ) + ( 'server' === $deletion_source ? count( $deletion['deleted_indexes'] ) : 0 ),
		'local_changed_block_count'    => count( $local_changed_indexes ) + ( 'local' === $deletion_source ? count( $deletion['deleted_indexes'] ) : 0 ),
		'merged_content'               => $merged_content,
		'merged_stripped_content_hash' => wp_de_rtc_hash_content( $merged_content ),
		'base_content_hash'            => wp_de_rtc_hash_content( $base_content ),
		'server_content_hash'          => wp_de_rtc_hash_content( $server_content ),
		'proposed_content_hash'        => wp_de_rtc_hash_content( $proposed_content ),
	);
}

/**
 * Returns strict one-sided edge insertion data for serialized blocks.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records      Accepted-base serialized block records.
 * @param string[] $candidate_records Candidate serialized block records.
 * @return array|null Edge insertion data, or null when the candidate is not a strict edge insertion.
 */
function wp_de_rtc_get_serialized_block_edge_insertion( $base_records, $candidate_records ) {
	$base_count      = count( $base_records );
	$candidate_count = count( $candidate_records );

	if ( $candidate_count <= $base_count ) {
		return null;
	}

	$inserted_count = $candidate_count - $base_count;
	$matches_prefix = true;
	$matches_suffix = true;

	for ( $index = 0; $index < $base_count; $index++ ) {
		if ( ! hash_equals( $base_records[ $index ], $candidate_records[ $index ] ) ) {
			$matches_prefix = false;
			break;
		}
	}

	for ( $index = 0; $index < $base_count; $index++ ) {
		if ( ! hash_equals( $base_records[ $index ], $candidate_records[ $index + $inserted_count ] ) ) {
			$matches_suffix = false;
			break;
		}
	}

	if ( $matches_prefix && $matches_suffix ) {
		return array(
			'position' => 'ambiguous',
			'blocks'   => array(),
		);
	}

	if ( $matches_prefix ) {
		return array(
			'position' => 'append',
			'blocks'   => array_slice( $candidate_records, $base_count ),
		);
	}

	if ( $matches_suffix ) {
		return array(
			'position' => 'prepend',
			'blocks'   => array_slice( $candidate_records, 0, $inserted_count ),
		);
	}

	return null;
}

/**
 * Returns strict one-sided deletion data for serialized blocks.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records      Accepted-base serialized block records.
 * @param string[] $candidate_records Candidate serialized block records.
 * @return array|null Deletion data, or null when the candidate is not a strict deletion.
 */
function wp_de_rtc_get_serialized_block_deletion( $base_records, $candidate_records ) {
	$base_count      = count( $base_records );
	$candidate_count = count( $candidate_records );

	if ( $candidate_count >= $base_count ) {
		return null;
	}

	$leftmost_indexes = array();
	$candidate_index  = 0;

	for ( $base_index = 0; $base_index < $base_count && $candidate_index < $candidate_count; $base_index++ ) {
		if ( hash_equals( $base_records[ $base_index ], $candidate_records[ $candidate_index ] ) ) {
			$leftmost_indexes[] = $base_index;
			$candidate_index++;
		}
	}

	if ( $candidate_index !== $candidate_count ) {
		return null;
	}

	$rightmost_indexes = array_fill( 0, $candidate_count, null );
	$candidate_index   = $candidate_count - 1;

	for ( $base_index = $base_count - 1; $base_index >= 0 && $candidate_index >= 0; $base_index-- ) {
		if ( hash_equals( $base_records[ $base_index ], $candidate_records[ $candidate_index ] ) ) {
			$rightmost_indexes[ $candidate_index ] = $base_index;
			$candidate_index--;
		}
	}

	if ( -1 !== $candidate_index ) {
		return null;
	}

	if ( $leftmost_indexes !== $rightmost_indexes ) {
		return array(
			'status'          => 'ambiguous',
			'deleted_indexes' => array(),
		);
	}

	$matched_lookup            = array_fill_keys( $leftmost_indexes, true );
	$base_to_candidate_indexes = array();
	$deleted_indexes           = array();
	$candidate_index_by_base   = array();

	foreach ( $leftmost_indexes as $candidate_offset => $base_index ) {
		$candidate_index_by_base[ $base_index ] = $candidate_offset;
	}

	for ( $base_index = 0; $base_index < $base_count; $base_index++ ) {
		if ( isset( $matched_lookup[ $base_index ] ) ) {
			$base_to_candidate_indexes[ $base_index ] = $candidate_index_by_base[ $base_index ];
		} else {
			$base_to_candidate_indexes[ $base_index ] = null;
			$deleted_indexes[]                        = $base_index;
		}
	}

	return array(
		'status'                    => 'deleted',
		'deleted_indexes'           => $deleted_indexes,
		'base_to_candidate_indexes' => $base_to_candidate_indexes,
	);
}

/**
 * Returns exact top-level serialized block records for a content string.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $content Stripped post content.
 * @return string[]|WP_Error Serialized top-level records, or an unsafe-boundary error.
 */
	function wp_de_rtc_get_top_level_serialized_block_records( $content ) {
		$content   = wp_de_rtc_canonicalize_post_content_for_hash( $content );
		$blocks    = parse_blocks( $content );
	$records   = array();
	$roundtrip = '';

	foreach ( $blocks as $block ) {
		$serialized = serialize_block( $block );
		$roundtrip .= $serialized;

		if ( ! isset( $block['blockName'] ) ) {
			if ( '' !== trim( $serialized ) ) {
				return wp_de_rtc_get_server_merge_conflict_error( 'freeform_html_boundary' );
			}

			continue;
		}

		$records[] = $serialized;
	}

	if ( $roundtrip !== $content ) {
		return wp_de_rtc_get_server_merge_conflict_error( 'serialized_block_roundtrip_changed' );
	}

	return $records;
}

/**
 * Creates a server-merge conflict error for retry-save.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $detail Conflict detail.
 * @param array  $extra  Optional conflict data.
 * @return WP_Error Conflict error.
 */
function wp_de_rtc_get_server_merge_conflict_error( $detail, $extra = array() ) {
	return wp_de_rtc_get_reason_error(
		'de_rtc_rebase_failed',
		__( 'Distributed Editing could not merge the retry save with the current server content.' ),
		array_merge(
			array(
				'detail'                              => 'retry_save_server_merge_' . sanitize_key( $detail ),
				'server_merge_attempted'              => true,
				'server_merge_status'                 => 'manual_conflict_required',
				'server_merge_strategy'               => 'top_level_serialized_block_three_way',
				'requires_manual_conflict_resolution' => true,
				'saves_post'                          => false,
				'mutates_post_content'                => false,
				'creates_revision'                    => false,
				'claims_saved'                        => false,
			),
			$extra
		)
	);
}

/**
 * Returns indexes that are reordered relative to the accepted base.
 *
 * This is intentionally limited to content-identical block permutations. If a
 * block was edited while moving, the current merge proof cannot prove identity
 * without content-aware block IDs, so later same-block checks still protect the
 * save boundary conservatively.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string[] $base_records      Accepted-base serialized block records.
 * @param string[] $candidate_records Candidate serialized block records.
 * @return int[] Reordered top-level block indexes, or an empty array.
 */
function wp_de_rtc_get_reordered_serialized_block_indexes( $base_records, $candidate_records ) {
	if ( count( $base_records ) !== count( $candidate_records ) ) {
		return array();
	}

	$base_hash_counts      = array();
	$candidate_hash_counts = array();
	$reordered_indexes     = array();

	foreach ( $base_records as $index => $base_record ) {
		$candidate_record = $candidate_records[ $index ];
		$base_hash        = wp_de_rtc_hash_content( $base_record );
		$candidate_hash   = wp_de_rtc_hash_content( $candidate_record );

		if ( ! isset( $base_hash_counts[ $base_hash ] ) ) {
			$base_hash_counts[ $base_hash ] = 0;
		}

		if ( ! isset( $candidate_hash_counts[ $candidate_hash ] ) ) {
			$candidate_hash_counts[ $candidate_hash ] = 0;
		}

		$base_hash_counts[ $base_hash ]++;
		$candidate_hash_counts[ $candidate_hash ]++;

		if ( ! hash_equals( $base_record, $candidate_record ) ) {
			$reordered_indexes[] = (int) $index;
		}
	}

	if ( empty( $reordered_indexes ) ) {
		return array();
	}

	ksort( $base_hash_counts );
	ksort( $candidate_hash_counts );

	if ( $base_hash_counts !== $candidate_hash_counts ) {
		return array();
	}

	return $reordered_indexes;
}

/**
 * Returns whether two block identity sync-meta maps are stable equivalents.
 *
 * Version labels may differ because this helper is used across a stale retry
 * save. The document identity and per-block identity/hash/path evidence must
 * remain the same.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $base_sync_meta    Accepted-base sync metadata.
 * @param mixed $current_sync_meta Current server sync metadata.
 * @return bool Whether the stable identity map matches.
 */
function wp_de_rtc_block_identity_sync_meta_stable_map_matches( $base_sync_meta, $current_sync_meta ) {
	$base_validation    = wp_de_rtc_validate_block_identity_sync_meta_contract( $base_sync_meta );
	$current_validation = wp_de_rtc_validate_block_identity_sync_meta_contract( $current_sync_meta );

	if ( is_wp_error( $base_validation ) || is_wp_error( $current_validation ) ) {
		return false;
	}

	if (
		$base_validation['document_uuid'] !== $current_validation['document_uuid'] ||
		$base_validation['content_hash'] !== $current_validation['content_hash'] ||
		(int) $base_validation['block_count'] !== (int) $current_validation['block_count'] ||
		$base_validation['block_uids'] !== $current_validation['block_uids']
	) {
		return false;
	}

	$base_sync_meta    = wp_de_rtc_normalize_block_identity_object( $base_sync_meta );
	$current_sync_meta = wp_de_rtc_normalize_block_identity_object( $current_sync_meta );

	foreach ( $base_sync_meta['blocks'] as $index => $base_block ) {
		$base_block    = wp_de_rtc_normalize_block_identity_object( $base_block );
		$current_block = wp_de_rtc_normalize_block_identity_object( $current_sync_meta['blocks'][ $index ] );

		foreach ( array( 'block_uid', 'parent_uid', 'block_name', 'ordinal_path', 'serialized_hash' ) as $field ) {
			if ( $base_block[ $field ] !== $current_block[ $field ] ) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Validates that identity request proof describes the proposed stripped content.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed    $request_proof    Request proof candidate.
 * @param mixed    $base_sync_meta   Accepted-base sync metadata.
 * @param string[] $proposed_records Proposed top-level serialized block records.
 * @return array|WP_Error Content-free proof match evidence, or conflict error.
 */
function wp_de_rtc_validate_block_identity_request_proof_matches_proposed_content( $request_proof, $base_sync_meta, $proposed_records ) {
	$request_proof = wp_de_rtc_normalize_block_identity_object( $request_proof );
	$base_sync_meta = wp_de_rtc_normalize_block_identity_object( $base_sync_meta );

	if (
		! is_array( $request_proof ) ||
		! isset( $request_proof['proposed_block_map'] ) ||
		! is_array( $request_proof['proposed_block_map'] ) ||
		! is_array( $base_sync_meta ) ||
		! isset( $base_sync_meta['blocks'] ) ||
		! is_array( $base_sync_meta['blocks'] ) ||
		count( $request_proof['proposed_block_map'] ) !== count( $proposed_records )
	) {
		return wp_de_rtc_get_server_merge_conflict_error(
			'block_identity_proof_content_mismatch',
			array(
				'server_merge_strategy' => 'top_level_serialized_block_identity_map',
			)
		);
	}

	$accepted_hashes_by_uid = array();

	foreach ( $base_sync_meta['blocks'] as $block ) {
		$block = wp_de_rtc_normalize_block_identity_object( $block );

		if (
			is_array( $block ) &&
			isset( $block['block_uid'], $block['serialized_hash'] ) &&
			is_string( $block['block_uid'] )
		) {
			$accepted_hashes_by_uid[ $block['block_uid'] ] = (string) $block['serialized_hash'];
		}
	}

	$inserted_block_indexes         = array();
	$retained_block_changed_indexes = array();

	foreach ( $request_proof['proposed_block_map'] as $index => $proof_block ) {
		$proof_block    = wp_de_rtc_normalize_block_identity_object( $proof_block );
		$record_hash    = wp_de_rtc_hash_content( $proposed_records[ $index ] );

		if ( ! is_array( $proof_block ) ) {
			return wp_de_rtc_get_server_merge_conflict_error(
				'block_identity_proof_content_mismatch',
				array(
					'server_merge_strategy' => 'top_level_serialized_block_identity_map',
					'block_index'           => (int) $index,
				)
			);
		}

		$proof_hash     = isset( $proof_block['serialized_hash'] ) ? sanitize_text_field( (string) $proof_block['serialized_hash'] ) : '';
		$block_uid      = isset( $proof_block['block_uid'] ) ? sanitize_text_field( (string) $proof_block['block_uid'] ) : '';
		$inserted_nonce = isset( $proof_block['inserted_block_nonce'] ) ? sanitize_text_field( (string) $proof_block['inserted_block_nonce'] ) : '';

		if ( ! hash_equals( $record_hash, $proof_hash ) ) {
			return wp_de_rtc_get_server_merge_conflict_error(
				'block_identity_proof_content_mismatch',
				array(
					'server_merge_strategy' => 'top_level_serialized_block_identity_map',
					'block_index'           => (int) $index,
				)
			);
		}

		if ( '' !== $block_uid ) {
			if ( ! isset( $accepted_hashes_by_uid[ $block_uid ] ) ) {
				return wp_de_rtc_get_server_merge_conflict_error(
					'block_identity_proof_content_mismatch',
					array(
						'server_merge_strategy' => 'top_level_serialized_block_identity_map',
						'block_index'           => (int) $index,
					)
				);
			}

			if ( ! hash_equals( $accepted_hashes_by_uid[ $block_uid ], $proof_hash ) ) {
				$retained_block_changed_indexes[] = (int) $index;
			}

			continue;
		}

		if ( '' !== $inserted_nonce ) {
			$inserted_block_indexes[] = (int) $index;
			continue;
		}

		return wp_de_rtc_get_server_merge_conflict_error(
			'block_identity_proof_content_mismatch',
			array(
				'server_merge_strategy' => 'top_level_serialized_block_identity_map',
				'block_index'           => (int) $index,
			)
		);
	}

	return array(
		'inserted_block_indexes'         => $inserted_block_indexes,
		'retained_block_changed_indexes' => $retained_block_changed_indexes,
	);
}

/**
 * Returns content-free merge evidence safe for REST responses and sync meta.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $server_merge_result Internal server merge result.
 * @return array Public merge evidence without raw post content.
 */
function wp_de_rtc_get_public_server_merge_evidence( $server_merge_result ) {
	$server_changed_indexes          = isset( $server_merge_result['server_changed_indexes'] ) ? array_map( 'intval', $server_merge_result['server_changed_indexes'] ) : array();
	$local_changed_indexes           = isset( $server_merge_result['local_changed_indexes'] ) ? array_map( 'intval', $server_merge_result['local_changed_indexes'] ) : array();
	$block_identity_inserted_indexes = (
		isset( $server_merge_result['block_identity_inserted_indexes'] ) &&
		is_array( $server_merge_result['block_identity_inserted_indexes'] )
	) ? array_map( 'intval', $server_merge_result['block_identity_inserted_indexes'] ) : array();
	$block_identity_server_inserted_indexes = (
		isset( $server_merge_result['block_identity_server_inserted_indexes'] ) &&
		is_array( $server_merge_result['block_identity_server_inserted_indexes'] )
	) ? array_map( 'intval', $server_merge_result['block_identity_server_inserted_indexes'] ) : array();
	$block_identity_retained_edit_indexes = (
		isset( $server_merge_result['block_identity_retained_edit_indexes'] ) &&
		is_array( $server_merge_result['block_identity_retained_edit_indexes'] )
	) ? array_map( 'intval', $server_merge_result['block_identity_retained_edit_indexes'] ) : array();
		$rich_text_merged_indexes = (
			isset( $server_merge_result['rich_text_merged_indexes'] ) &&
			is_array( $server_merge_result['rich_text_merged_indexes'] )
		) ? array_map( 'intval', $server_merge_result['rich_text_merged_indexes'] ) : array();
		$table_cell_merged_indexes = (
			isset( $server_merge_result['table_cell_merged_indexes'] ) &&
			is_array( $server_merge_result['table_cell_merged_indexes'] )
		) ? array_map( 'intval', $server_merge_result['table_cell_merged_indexes'] ) : array();

		return array(
		'merge_status'                 => isset( $server_merge_result['merge_status'] ) ? $server_merge_result['merge_status'] : null,
		'merge_strategy'               => isset( $server_merge_result['merge_strategy'] ) ? $server_merge_result['merge_strategy'] : null,
		'base_version'                 => isset( $server_merge_result['base_version'] ) ? $server_merge_result['base_version'] : null,
		'server_version'               => isset( $server_merge_result['server_version'] ) ? $server_merge_result['server_version'] : null,
		'base_revision_id'             => isset( $server_merge_result['base_revision_id'] ) ? (int) $server_merge_result['base_revision_id'] : 0,
		'block_count'                  => isset( $server_merge_result['block_count'] ) ? (int) $server_merge_result['block_count'] : 0,
		'base_block_count'             => isset( $server_merge_result['base_block_count'] ) ? (int) $server_merge_result['base_block_count'] : 0,
		'server_block_count'           => isset( $server_merge_result['server_block_count'] ) ? (int) $server_merge_result['server_block_count'] : 0,
		'proposed_block_count'         => isset( $server_merge_result['proposed_block_count'] ) ? (int) $server_merge_result['proposed_block_count'] : 0,
		'merged_block_count'           => isset( $server_merge_result['merged_block_count'] ) ? (int) $server_merge_result['merged_block_count'] : 0,
		'edge_insert_source'           => isset( $server_merge_result['edge_insert_source'] ) ? $server_merge_result['edge_insert_source'] : null,
		'edge_insert_position'         => isset( $server_merge_result['edge_insert_position'] ) ? $server_merge_result['edge_insert_position'] : null,
		'edge_inserted_block_count'    => isset( $server_merge_result['edge_inserted_block_count'] ) ? (int) $server_merge_result['edge_inserted_block_count'] : 0,
		'append_source'                => isset( $server_merge_result['append_source'] ) ? $server_merge_result['append_source'] : null,
		'appended_block_count'         => isset( $server_merge_result['appended_block_count'] ) ? (int) $server_merge_result['appended_block_count'] : 0,
		'prepend_source'               => isset( $server_merge_result['prepend_source'] ) ? $server_merge_result['prepend_source'] : null,
		'prepended_block_count'        => isset( $server_merge_result['prepended_block_count'] ) ? (int) $server_merge_result['prepended_block_count'] : 0,
		'deletion_source'              => isset( $server_merge_result['deletion_source'] ) ? $server_merge_result['deletion_source'] : null,
		'deleted_block_indexes'        => isset( $server_merge_result['deleted_block_indexes'] ) && is_array( $server_merge_result['deleted_block_indexes'] ) ? array_map( 'intval', $server_merge_result['deleted_block_indexes'] ) : array(),
		'deleted_block_count'          => isset( $server_merge_result['deleted_block_count'] ) ? (int) $server_merge_result['deleted_block_count'] : 0,
		'server_changed_indexes'       => $server_changed_indexes,
		'local_changed_indexes'        => $local_changed_indexes,
			'rich_text_merged_indexes'     => $rich_text_merged_indexes,
			'rich_text_merged_block_count' => isset( $server_merge_result['rich_text_merged_block_count'] ) ? (int) $server_merge_result['rich_text_merged_block_count'] : count( $rich_text_merged_indexes ),
			'table_cell_merged_indexes'    => $table_cell_merged_indexes,
			'table_cell_merged_block_count' => isset( $server_merge_result['table_cell_merged_block_count'] ) ? (int) $server_merge_result['table_cell_merged_block_count'] : count( $table_cell_merged_indexes ),
			'table_cell_server_changed_cells' => isset( $server_merge_result['table_cell_server_changed_cells'] ) && is_array( $server_merge_result['table_cell_server_changed_cells'] ) ? $server_merge_result['table_cell_server_changed_cells'] : array(),
			'table_cell_local_changed_cells' => isset( $server_merge_result['table_cell_local_changed_cells'] ) && is_array( $server_merge_result['table_cell_local_changed_cells'] ) ? $server_merge_result['table_cell_local_changed_cells'] : array(),
			'server_changed_block_count'   => isset( $server_merge_result['server_changed_block_count'] ) ? (int) $server_merge_result['server_changed_block_count'] : count( $server_changed_indexes ),
		'local_changed_block_count'    => isset( $server_merge_result['local_changed_block_count'] ) ? (int) $server_merge_result['local_changed_block_count'] : count( $local_changed_indexes ),
		'block_identity_base_current_match'   => isset( $server_merge_result['block_identity_base_current_match'] ) ? (bool) $server_merge_result['block_identity_base_current_match'] : null,
		'block_identity_base_current_insertions_only' => isset( $server_merge_result['block_identity_base_current_insertions_only'] ) ? (bool) $server_merge_result['block_identity_base_current_insertions_only'] : null,
		'block_identity_base_current_retained_edits_only' => isset( $server_merge_result['block_identity_base_current_retained_edits_only'] ) ? (bool) $server_merge_result['block_identity_base_current_retained_edits_only'] : null,
		'block_identity_retained_edit_indexes' => $block_identity_retained_edit_indexes,
		'block_identity_retained_edit_block_count' => isset( $server_merge_result['block_identity_retained_edit_block_count'] ) ? (int) $server_merge_result['block_identity_retained_edit_block_count'] : count( $block_identity_retained_edit_indexes ),
		'block_identity_inserted_indexes'     => $block_identity_inserted_indexes,
		'block_identity_inserted_block_count' => isset( $server_merge_result['block_identity_inserted_block_count'] ) ? (int) $server_merge_result['block_identity_inserted_block_count'] : 0,
		'block_identity_server_inserted_indexes' => $block_identity_server_inserted_indexes,
		'block_identity_server_inserted_block_count' => isset( $server_merge_result['block_identity_server_inserted_block_count'] ) ? (int) $server_merge_result['block_identity_server_inserted_block_count'] : 0,
		'block_identity_moved_block_count'    => isset( $server_merge_result['block_identity_moved_block_count'] ) ? (int) $server_merge_result['block_identity_moved_block_count'] : 0,
		'merged_stripped_content_hash' => isset( $server_merge_result['merged_stripped_content_hash'] ) ? $server_merge_result['merged_stripped_content_hash'] : null,
		'base_content_hash'            => isset( $server_merge_result['base_content_hash'] ) ? $server_merge_result['base_content_hash'] : null,
		'server_content_hash'          => isset( $server_merge_result['server_content_hash'] ) ? $server_merge_result['server_content_hash'] : null,
		'proposed_content_hash'        => isset( $server_merge_result['proposed_content_hash'] ) ? $server_merge_result['proposed_content_hash'] : null,
	);
}

/**
 * Returns whether a value is lowercase SHA-256 hash evidence.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $hash Hash candidate.
 * @return bool Whether the value is a SHA-256 hash.
 */
function wp_de_rtc_is_sha256_hash( $hash ) {
	return is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $hash );
}

/**
 * Finds raw post-content parameter paths in a request payload.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed  $payload Request payload.
 * @param string $prefix  Current parameter path.
 * @return string[] Raw post-content parameter paths.
 */
function wp_de_rtc_find_raw_post_content_param_paths( $payload, $prefix = '' ) {
	if ( is_object( $payload ) ) {
		$payload = get_object_vars( $payload );
	}

	if ( ! is_array( $payload ) ) {
		return array();
	}

	$raw_content_keys = array(
		'content',
		'post_content',
		'proposed_post_content',
		'proposedPostContent',
		'candidate_post_content',
		'candidatePostContent',
		'saved_post_content',
		'savedPostContent',
		'raw_content',
		'rawContent',
		'raw_post_content',
		'rawPostContent',
	);
	$paths            = array();

	foreach ( $payload as $key => $value ) {
		$key_string = is_string( $key ) ? $key : (string) $key;
		$path       = '' === $prefix ? $key_string : $prefix . '.' . $key_string;

		if ( in_array( $key_string, $raw_content_keys, true ) ) {
			$paths[] = $path;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$paths = array_merge( $paths, wp_de_rtc_find_raw_post_content_param_paths( $value, $path ) );
		}
	}

	return $paths;
}

/**
 * Returns the minimum durable block-identity contract fields.
 *
 * @since 7.1.0
 * @access private
 *
 * @return array Required sync-meta, block, and request-proof fields.
 */
function wp_de_rtc_get_block_identity_contract_required_fields() {
	return array(
		'sync_meta'     => array(
			'schema',
			'document_uuid',
			'version',
			'content_hash',
			'blocks',
		),
		'block'         => array(
			'block_uid',
			'parent_uid',
			'block_name',
			'ordinal_path',
			'serialized_hash',
		),
		'request_proof' => array(
			'client_base_version',
			'proposed_post_content_hash',
			'proposed_block_map',
			'retained_block_uids',
			'inserted_block_nonces',
			'deleted_block_uids',
			'moved_block_uids',
		),
	);
}

/**
 * Validates block identity proof at the guarded retry-save boundary.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed       $request_proof              Request proof candidate.
 * @param mixed       $accepted_sync_meta          Accepted-base sync metadata.
 * @param string      $proposed_post_content_hash SHA-256 hash of proposed stripped content.
 * @param int|WP_Post $post                       Post ID or object.
 * @param string[]    $alternate_proposed_post_content_hashes Optional accepted equivalent proposed-content hashes.
 * @return array|WP_Error Validation evidence, or a no-write error.
 */
function wp_de_rtc_validate_retry_save_block_identity_request_proof( $request_proof, $accepted_sync_meta, $proposed_post_content_hash, $post, $alternate_proposed_post_content_hashes = array() ) {
	$post       = get_post( $post );
	$validation = wp_de_rtc_validate_block_identity_request_proof( $request_proof, $accepted_sync_meta );

	if ( is_wp_error( $validation ) ) {
		$data = $validation->get_error_data();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['post_id']    = $post ? (int) $post->ID : 0;
		$data['rest_route'] = 'post_retry_save_block_identity';

		$validation->add_data( $data, $validation->get_error_code() );

		return $validation;
	}

	$proposed_hash_matches = hash_equals( $validation['proposed_post_content_hash'], $proposed_post_content_hash );

	foreach ( is_array( $alternate_proposed_post_content_hashes ) ? $alternate_proposed_post_content_hashes : array() as $alternate_hash ) {
		if ( is_string( $alternate_hash ) && wp_de_rtc_is_sha256_hash( $alternate_hash ) && hash_equals( $validation['proposed_post_content_hash'], $alternate_hash ) ) {
			$proposed_hash_matches = true;
			break;
		}
	}

	if ( ! $proposed_hash_matches ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_sync_meta_tampered',
			__( 'Distributed Editing rejected the retry save because block identity proof targeted different proposed content.' ),
			wp_de_rtc_get_block_identity_no_write_data(
				array(
					'detail'                               => 'retry_save_block_identity_request_proof_hash_mismatch',
					'post_id'                              => $post ? (int) $post->ID : 0,
					'rest_route'                           => 'post_retry_save_block_identity',
					'proposed_post_content_hash'           => $proposed_post_content_hash,
					'block_identity_proposed_content_hash' => $validation['proposed_post_content_hash'],
				)
			)
		);
	}

	$validation['validated_for_retry_save'] = true;
	$validation['rest_route']               = 'post_retry_save_block_identity';
	$validation['post_id']                  = $post ? (int) $post->ID : 0;

	return $validation;
}

/**
 * Applies validated block identity request proof to server-owned sync metadata.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array  $sync_meta                 Current sync metadata.
 * @param mixed  $request_proof             Validated request proof.
 * @param string $next_version              Next server sync version.
 * @param string $save_post_content_hash    SHA-256 hash of saved stripped content.
 * @return array Updated sync metadata.
 */
function wp_de_rtc_apply_block_identity_request_proof_to_sync_meta( $sync_meta, $request_proof, $next_version, $save_post_content_hash ) {
	$request_proof = wp_de_rtc_normalize_block_identity_object( $request_proof );

	if ( ! is_array( $sync_meta ) || ! is_array( $request_proof ) || empty( $request_proof['proposed_block_map'] ) || ! is_array( $request_proof['proposed_block_map'] ) ) {
		return $sync_meta;
	}

	$document_uuid = isset( $sync_meta['document_uuid'] ) ? sanitize_text_field( (string) $sync_meta['document_uuid'] ) : '';
	$blocks        = array();

	foreach ( $request_proof['proposed_block_map'] as $index => $block ) {
		$block = wp_de_rtc_normalize_block_identity_object( $block );

		if ( ! is_array( $block ) ) {
			continue;
		}

		$block_uid = isset( $block['block_uid'] )
			? sanitize_text_field( (string) $block['block_uid'] )
			: wp_de_rtc_generate_inserted_block_identity_uid(
				$document_uuid,
				$next_version,
				isset( $block['inserted_block_nonce'] ) ? (string) $block['inserted_block_nonce'] : '',
				isset( $block['serialized_hash'] ) ? (string) $block['serialized_hash'] : '',
				$index
			);

		$blocks[] = array(
			'block_uid'       => $block_uid,
			'parent_uid'      => null,
			'block_name'      => isset( $block['block_name'] ) ? sanitize_text_field( (string) $block['block_name'] ) : '',
			'ordinal_path'    => array_map( 'intval', isset( $block['ordinal_path'] ) && is_array( $block['ordinal_path'] ) ? $block['ordinal_path'] : array( $index ) ),
			'serialized_hash' => isset( $block['serialized_hash'] ) ? sanitize_text_field( (string) $block['serialized_hash'] ) : '',
		);
	}

	$sync_meta['schema']       = 'de-rtc-block-identity-v1';
	$sync_meta['content_hash'] = $save_post_content_hash;
	$sync_meta['blocks']       = $blocks;

	return $sync_meta;
}

/**
 * Generates a server-owned UID for an inserted block from content-free proof.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $document_uuid        Document UUID.
 * @param string $next_version         Next server sync version.
 * @param string $inserted_block_nonce Client nonce for this inserted block.
 * @param string $serialized_hash      Serialized block SHA-256 hash.
 * @param int    $index                Proposed block index.
 * @return string Server-owned block UID.
 */
function wp_de_rtc_generate_inserted_block_identity_uid( $document_uuid, $next_version, $inserted_block_nonce, $serialized_hash, $index ) {
	return 'block-' . substr(
		hash(
			'sha256',
			implode(
				'|',
				array(
					'wp-de-rtc-block-identity-v1',
					sanitize_text_field( (string) $document_uuid ),
					sanitize_text_field( (string) $next_version ),
					sanitize_text_field( (string) $inserted_block_nonce ),
					sanitize_text_field( (string) $serialized_hash ),
					(int) $index,
				)
			)
		),
		0,
		24
	);
}

/**
 * Validates read-only block identity sync metadata.
 *
 * This helper only validates shape. It must not save, mutate post content,
 * create revisions, change locks, or claim saved state.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $sync_meta Sync metadata candidate.
 * @return array|WP_Error Validation evidence, or malformed-payload error.
 */
function wp_de_rtc_validate_block_identity_sync_meta_contract( $sync_meta ) {
	$sync_meta = wp_de_rtc_normalize_block_identity_object( $sync_meta );

	if ( ! is_array( $sync_meta ) ) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_sync_meta_missing' );
	}

	$raw_content_paths = wp_de_rtc_find_raw_post_content_param_paths( $sync_meta );

	if ( ! empty( $raw_content_paths ) ) {
		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_raw_content_rejected',
			array(
				'raw_content_param_paths' => $raw_content_paths,
			)
		);
	}

	$client_id_paths = wp_de_rtc_find_gutenberg_client_id_param_paths( $sync_meta );

	if ( ! empty( $client_id_paths ) ) {
		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_client_id_rejected',
			array(
				'client_id_param_paths' => $client_id_paths,
			)
		);
	}

	$required = wp_de_rtc_get_block_identity_contract_required_fields();

	foreach ( $required['sync_meta'] as $field ) {
		if ( ! array_key_exists( $field, $sync_meta ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_sync_meta_missing_required_field',
				array(
					'missing_field' => $field,
				)
			);
		}
	}

	if ( 'de-rtc-block-identity-v1' !== $sync_meta['schema'] ) {
		$schema = is_scalar( $sync_meta['schema'] ) ? sanitize_text_field( (string) $sync_meta['schema'] ) : null;

		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_schema_mismatch',
			array(
				'schema' => $schema,
			)
		);
	}

	if (
		! is_string( $sync_meta['document_uuid'] ) ||
		'' === $sync_meta['document_uuid'] ||
		! wp_de_rtc_is_block_identity_version_label( $sync_meta['version'] ) ||
		! wp_de_rtc_is_sha256_hash( $sync_meta['content_hash'] ) ||
		! is_array( $sync_meta['blocks'] )
	) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_sync_meta_invalid_required_field' );
	}

	$block_uids = array();

	foreach ( $sync_meta['blocks'] as $index => $block ) {
		$block_validation = wp_de_rtc_validate_block_identity_block_record( $block );

		if ( is_wp_error( $block_validation ) ) {
			$data                = $block_validation->get_error_data();
			$data['block_index'] = (int) $index;

			return wp_de_rtc_get_block_identity_validation_error(
				isset( $data['detail'] ) ? $data['detail'] : 'block_identity_block_invalid',
				$data
			);
		}

		$block_uid = $block_validation['block_uid'];

		if ( isset( $block_uids[ $block_uid ] ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_duplicate_block_uid',
				array(
					'block_uid'    => $block_uid,
					'block_index'  => (int) $index,
					'first_index'  => $block_uids[ $block_uid ],
				)
			);
		}

		$block_uids[ $block_uid ] = (int) $index;
	}

	return wp_de_rtc_get_block_identity_no_write_data(
		array(
			'status'          => 'valid',
			'detail'          => null,
			'schema'          => $sync_meta['schema'],
			'document_uuid'   => $sync_meta['document_uuid'],
			'version'         => sanitize_text_field( (string) $sync_meta['version'] ),
			'content_hash'    => $sync_meta['content_hash'],
			'block_count'     => count( $sync_meta['blocks'] ),
			'block_uids'      => array_keys( $block_uids ),
			'required_fields' => $required,
		)
	);
}

/**
 * Validates read-only block identity request proof against accepted sync meta.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $request_proof      Request proof candidate.
 * @param mixed $accepted_sync_meta Accepted-base sync metadata candidate.
 * @return array|WP_Error Validation evidence, or malformed-payload error.
 */
function wp_de_rtc_validate_block_identity_request_proof( $request_proof, $accepted_sync_meta ) {
	$sync_meta_validation = wp_de_rtc_validate_block_identity_sync_meta_contract( $accepted_sync_meta );

	if ( is_wp_error( $sync_meta_validation ) ) {
		$sync_meta_error_data = $sync_meta_validation->get_error_data();

		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_accepted_sync_meta_invalid',
			array(
				'accepted_sync_meta_error_code'   => $sync_meta_validation->get_error_code(),
				'accepted_sync_meta_error_detail' => isset( $sync_meta_error_data['detail'] ) ? $sync_meta_error_data['detail'] : null,
			)
		);
	}

	$request_proof = wp_de_rtc_normalize_block_identity_object( $request_proof );

	if ( ! is_array( $request_proof ) ) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_request_proof_missing' );
	}

	$raw_content_paths = wp_de_rtc_find_raw_post_content_param_paths( $request_proof );

	if ( ! empty( $raw_content_paths ) ) {
		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_raw_content_rejected',
			array(
				'raw_content_param_paths' => $raw_content_paths,
			)
		);
	}

	$client_id_paths = wp_de_rtc_find_gutenberg_client_id_param_paths( $request_proof );

	if ( ! empty( $client_id_paths ) ) {
		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_client_id_rejected',
			array(
				'client_id_param_paths' => $client_id_paths,
			)
		);
	}

	$required = wp_de_rtc_get_block_identity_contract_required_fields();

	foreach ( $required['request_proof'] as $field ) {
		if ( ! array_key_exists( $field, $request_proof ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_request_proof_missing_required_field',
				array(
					'missing_field' => $field,
				)
			);
		}
	}

	if ( ! wp_de_rtc_is_block_identity_version_label( $request_proof['client_base_version'] ) ) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_request_proof_invalid_required_field' );
	}

	$client_base_version = sanitize_text_field( (string) $request_proof['client_base_version'] );

	if ( $client_base_version !== $sync_meta_validation['version'] ) {
		return wp_de_rtc_get_block_identity_validation_error(
			'block_identity_request_proof_base_version_mismatch',
			array(
				'client_base_version' => $client_base_version,
				'server_version'      => $sync_meta_validation['version'],
			)
		);
	}

	if (
		! wp_de_rtc_is_sha256_hash( $request_proof['proposed_post_content_hash'] ) ||
		! is_array( $request_proof['proposed_block_map'] ) ||
		! is_array( $request_proof['retained_block_uids'] ) ||
		! is_array( $request_proof['inserted_block_nonces'] ) ||
		! is_array( $request_proof['deleted_block_uids'] ) ||
		! is_array( $request_proof['moved_block_uids'] )
	) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_request_proof_invalid_required_field' );
	}

	$accepted_block_uids   = array_fill_keys( $sync_meta_validation['block_uids'], true );
	$inserted_block_nonces = wp_de_rtc_validate_block_identity_string_list(
		$request_proof['inserted_block_nonces'],
		'inserted_block_nonces'
	);

	if ( is_wp_error( $inserted_block_nonces ) ) {
		return $inserted_block_nonces;
	}

	$inserted_block_nonce_lookup = array_fill_keys( $inserted_block_nonces, true );
	$retained_block_uids         = wp_de_rtc_validate_block_identity_uid_list(
		$request_proof['retained_block_uids'],
		'retained_block_uids',
		$accepted_block_uids
	);
	$deleted_block_uids          = wp_de_rtc_validate_block_identity_uid_list(
		$request_proof['deleted_block_uids'],
		'deleted_block_uids',
		$accepted_block_uids
	);
	$moved_block_uids            = wp_de_rtc_validate_block_identity_uid_list(
		$request_proof['moved_block_uids'],
		'moved_block_uids',
		$accepted_block_uids
	);

	foreach ( array( $retained_block_uids, $deleted_block_uids, $moved_block_uids ) as $validated_uids ) {
		if ( is_wp_error( $validated_uids ) ) {
			return $validated_uids;
		}
	}

	foreach ( $request_proof['proposed_block_map'] as $index => $block ) {
		$block_validation = wp_de_rtc_validate_block_identity_proposed_block_record(
			$block,
			$accepted_block_uids,
			$inserted_block_nonce_lookup
		);

		if ( is_wp_error( $block_validation ) ) {
			$data                = $block_validation->get_error_data();
			$data['block_index'] = (int) $index;

			return wp_de_rtc_get_block_identity_validation_error(
				isset( $data['detail'] ) ? $data['detail'] : 'block_identity_proposed_block_invalid',
				$data
			);
		}
	}

	return wp_de_rtc_get_block_identity_no_write_data(
		array(
			'status'                     => 'valid',
			'detail'                     => null,
			'client_base_version'        => $client_base_version,
			'proposed_post_content_hash' => $request_proof['proposed_post_content_hash'],
			'proposed_block_count'       => count( $request_proof['proposed_block_map'] ),
			'retained_block_count'       => count( $retained_block_uids ),
			'inserted_block_count'       => count( $inserted_block_nonces ),
			'deleted_block_count'        => count( $deleted_block_uids ),
			'moved_block_count'          => count( $moved_block_uids ),
			'required_fields'            => $required,
		)
	);
}

/**
 * Finds Gutenberg clientId paths in block identity proof.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed  $payload Payload to inspect.
 * @param string $prefix  Current parameter path.
 * @return string[] Client ID paths.
 */
function wp_de_rtc_find_gutenberg_client_id_param_paths( $payload, $prefix = '' ) {
	if ( is_object( $payload ) ) {
		$payload = get_object_vars( $payload );
	}

	if ( ! is_array( $payload ) ) {
		return array();
	}

	$paths = array();

	foreach ( $payload as $key => $value ) {
		$key_string = is_string( $key ) ? $key : (string) $key;
		$path       = '' === $prefix ? $key_string : $prefix . '.' . $key_string;

		if ( in_array( $key_string, array( 'clientId', 'client_id' ), true ) ) {
			$paths[] = $path;
		}

		$paths = array_merge( $paths, wp_de_rtc_find_gutenberg_client_id_param_paths( $value, $path ) );
	}

	return $paths;
}

/**
 * Normalizes object payloads into arrays for block identity validation.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $value Payload value.
 * @return mixed Normalized payload.
 */
function wp_de_rtc_normalize_block_identity_object( $value ) {
	if ( is_object( $value ) ) {
		return get_object_vars( $value );
	}

	return $value;
}

/**
 * Validates a durable block identity record.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $block Block record candidate.
 * @return array|WP_Error Validated block evidence, or malformed-payload error.
 */
function wp_de_rtc_validate_block_identity_block_record( $block ) {
	$block = wp_de_rtc_normalize_block_identity_object( $block );

	if ( ! is_array( $block ) ) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_block_invalid' );
	}

	$required = wp_de_rtc_get_block_identity_contract_required_fields();

	foreach ( $required['block'] as $field ) {
		if ( ! array_key_exists( $field, $block ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_block_missing_required_field',
				array(
					'missing_field' => $field,
				)
			);
		}
	}

	if (
		! is_string( $block['block_uid'] ) ||
		'' === $block['block_uid'] ||
		! ( is_string( $block['parent_uid'] ) || null === $block['parent_uid'] ) ||
		! is_string( $block['block_name'] ) ||
		'' === $block['block_name'] ||
		! wp_de_rtc_is_block_identity_ordinal_path( $block['ordinal_path'] ) ||
		! wp_de_rtc_is_sha256_hash( $block['serialized_hash'] )
	) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_block_invalid_required_field' );
	}

	return array(
		'block_uid'       => $block['block_uid'],
		'parent_uid'      => $block['parent_uid'],
		'block_name'      => $block['block_name'],
		'ordinal_path'    => array_map( 'intval', $block['ordinal_path'] ),
		'serialized_hash' => $block['serialized_hash'],
	);
}

/**
 * Validates a proposed block identity record in request proof.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $block                       Proposed block record.
 * @param array $accepted_block_uids         Accepted-base block UID lookup.
 * @param array $inserted_block_nonce_lookup Client insertion nonce lookup.
 * @return array|WP_Error Validated block evidence, or malformed-payload error.
 */
function wp_de_rtc_validate_block_identity_proposed_block_record(
	$block,
	$accepted_block_uids,
	$inserted_block_nonce_lookup
) {
	$block = wp_de_rtc_normalize_block_identity_object( $block );

	if ( ! is_array( $block ) ) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_proposed_block_invalid' );
	}

	foreach ( array( 'block_name', 'ordinal_path', 'serialized_hash' ) as $field ) {
		if ( ! array_key_exists( $field, $block ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_proposed_block_missing_required_field',
				array(
					'missing_field' => $field,
				)
			);
		}
	}

	if (
		! is_string( $block['block_name'] ) ||
		'' === $block['block_name'] ||
		! wp_de_rtc_is_block_identity_ordinal_path( $block['ordinal_path'] ) ||
		! wp_de_rtc_is_sha256_hash( $block['serialized_hash'] )
	) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_proposed_block_invalid_required_field' );
	}

	$has_block_uid      = isset( $block['block_uid'] ) && is_string( $block['block_uid'] ) && '' !== $block['block_uid'];
	$has_inserted_nonce = (
		isset( $block['inserted_block_nonce'] ) &&
		is_string( $block['inserted_block_nonce'] ) &&
		'' !== $block['inserted_block_nonce']
	);

	if ( $has_block_uid && $has_inserted_nonce ) {
		return wp_de_rtc_get_block_identity_validation_error( 'block_identity_proposed_block_ambiguous_identity' );
	}

	if ( $has_block_uid ) {
		if ( ! isset( $accepted_block_uids[ $block['block_uid'] ] ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_request_proof_unknown_block_uid',
				array(
					'unknown_block_uid' => $block['block_uid'],
					'field'             => 'proposed_block_map',
				)
			);
		}

		return array(
			'identity_kind' => 'retained',
			'block_uid'     => $block['block_uid'],
		);
	}

	if ( $has_inserted_nonce ) {
		if ( ! isset( $inserted_block_nonce_lookup[ $block['inserted_block_nonce'] ] ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_request_proof_unknown_inserted_nonce',
				array(
					'unknown_inserted_block_nonce' => $block['inserted_block_nonce'],
					'field'                        => 'proposed_block_map',
				)
			);
		}

		return array(
			'identity_kind'        => 'inserted',
			'inserted_block_nonce' => $block['inserted_block_nonce'],
		);
	}

	return wp_de_rtc_get_block_identity_validation_error( 'block_identity_proposed_block_unknown_identity' );
}

/**
 * Validates a content-free string list for block identity proof.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array  $values List values.
 * @param string $field  Request-proof field name.
 * @return string[]|WP_Error Sanitized strings, or malformed-payload error.
 */
function wp_de_rtc_validate_block_identity_string_list( $values, $field ) {
	$validated = array();
	$seen      = array();

	foreach ( $values as $index => $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_request_proof_invalid_required_field',
				array(
					'field' => $field,
					'index' => (int) $index,
				)
			);
		}

		if ( isset( $seen[ $value ] ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_request_proof_duplicate_value',
				array(
					'field' => $field,
					'value' => $value,
					'index' => (int) $index,
				)
			);
		}

		$seen[ $value ] = true;
		$validated[]   = $value;
	}

	return $validated;
}

/**
 * Validates an accepted-base block UID list for block identity proof.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array  $values              UID list values.
 * @param string $field               Request-proof field name.
 * @param array  $accepted_block_uids Accepted-base UID lookup.
 * @return string[]|WP_Error Validated UIDs, or malformed-payload error.
 */
function wp_de_rtc_validate_block_identity_uid_list( $values, $field, $accepted_block_uids ) {
	$validated = wp_de_rtc_validate_block_identity_string_list( $values, $field );

	if ( is_wp_error( $validated ) ) {
		return $validated;
	}

	foreach ( $validated as $index => $block_uid ) {
		if ( ! isset( $accepted_block_uids[ $block_uid ] ) ) {
			return wp_de_rtc_get_block_identity_validation_error(
				'block_identity_request_proof_unknown_block_uid',
				array(
					'field'             => $field,
					'index'             => (int) $index,
					'unknown_block_uid' => $block_uid,
				)
			);
		}
	}

	return $validated;
}

/**
 * Returns whether a block identity version label is acceptable.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $version Version candidate.
 * @return bool Whether the version is a non-empty scalar label.
 */
function wp_de_rtc_is_block_identity_version_label( $version ) {
	return ( is_string( $version ) || is_int( $version ) ) && '' !== (string) $version;
}

/**
 * Returns whether a block identity ordinal path is valid.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $path Ordinal path candidate.
 * @return bool Whether the path is an array of non-negative integers.
 */
function wp_de_rtc_is_block_identity_ordinal_path( $path ) {
	if ( ! is_array( $path ) ) {
		return false;
	}

	foreach ( $path as $part ) {
		if ( ! is_int( $part ) || $part < 0 ) {
			return false;
		}
	}

	return true;
}

/**
 * Adds no-write evidence to a block identity validation result.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $data Validation result data.
 * @return array Validation result data with no-write evidence.
 */
function wp_de_rtc_get_block_identity_no_write_data( $data ) {
	return array_merge(
		$data,
		array(
			'saves_post'           => false,
			'mutates_post_content' => false,
			'creates_revision'     => false,
			'changes_post_lock'    => false,
			'claims_saved'         => false,
		)
	);
}

/**
 * Creates a malformed-payload error for block identity validation.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $detail Validation detail.
 * @param array  $extra  Optional extra data.
 * @return WP_Error Validation error.
 */
function wp_de_rtc_get_block_identity_validation_error( $detail, $extra = array() ) {
	return wp_de_rtc_get_reason_error(
		'de_rtc_malformed_sync_payload',
		__( 'Distributed Editing block identity proof is incomplete or malformed.' ),
		wp_de_rtc_get_block_identity_no_write_data(
			array_merge(
				array(
					'detail' => $detail,
				),
				$extra
			)
		)
	);
}

/**
 * Hashes stripped post content for DE-RTC base-evidence comparisons.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $content Post content without sync metadata.
 * @return string SHA-256 content hash.
 */
	function wp_de_rtc_hash_content( $content ) {
		return hash( 'sha256', wp_de_rtc_canonicalize_post_content_for_hash( $content ) );
	}

/**
	 * Canonicalizes serialized post content for DE-RTC evidence hashes.
	 *
	 * Hash evidence must not depend on whether Gutenberg or WordPress emitted
	 * harmless whitespace around serialized block comment boundaries. Persistence
	 * still uses the caller's original content; this helper only normalizes the
	 * material fed into SHA-256 comparisons.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $content Serialized post content.
	 * @return string Canonicalized content for hash evidence.
	 */
	function wp_de_rtc_canonicalize_post_content_for_hash( $content ) {
		return wp_de_rtc_canonicalize_serialized_block_boundary_whitespace(
			wp_de_rtc_canonicalize_post_content_core_block_names( $content )
		);
	}

/**
	 * Removes insignificant whitespace around serialized block boundaries.
	 *
	 * WordPress may persist pretty serialized blocks while Gutenberg exposes the
	 * same blocks compactly in the editor store. DE-RTC evidence compares the
	 * block document, not typography between block delimiters and their saved
	 * HTML.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $content Serialized post content.
	 * @return string Content with block-boundary whitespace normalized.
	 */
	function wp_de_rtc_canonicalize_serialized_block_boundary_whitespace( $content ) {
		$content = (string) $content;
		$content = preg_replace( '~(<!--\s*wp:[\s\S]*?-->)\s+(?=<)~', '$1', $content );
		$content = preg_replace( '~(?<=>)\s+(<!--\s*/wp:[\s\S]*?-->)~', '$1', $content );
		$content = preg_replace( '~(<!--\s*/wp:[\s\S]*?-->)\s+(?=<!--\s*wp:)~', '$1', $content );

		return $content;
	}

/**
	 * Canonicalizes implicit-core serialized block delimiters for DE-RTC evidence.
	 *
	 * WordPress and Gutenberg serialize core blocks without the `core/` prefix,
	 * while parsed block names and block-identity metadata still use registered
	 * names such as `core/paragraph`. DE-RTC hashes and merge guards must compare
	 * the serialized form WordPress writes, without turning unrelated freeform
	 * HTML or unsafe block boundaries into accepted content.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $content Serialized post content.
	 * @return string Canonical content when only implicit-core delimiters differ; otherwise the original content.
	 */
	function wp_de_rtc_canonicalize_post_content_core_block_names( $content ) {
		$content = (string) $content;

		if ( false === stripos( $content, 'wp:core/' ) ) {
			return $content;
		}

		$blocks    = parse_blocks( $content );
		$roundtrip = '';

		foreach ( $blocks as $block ) {
			$roundtrip .= serialize_block( $block );
		}

		if ( $roundtrip === $content ) {
			return $content;
		}

		if ( wp_de_rtc_strip_core_block_namespace_from_serialized_comments( $content ) === $roundtrip ) {
			return $roundtrip;
		}

		return $content;
	}

/**
	 * Returns serialized content with only `wp:core/*` comment delimiters stripped.
	 *
	 * This helper is used to prove a parser/serializer round trip changed only
	 * the implicit core namespace spelling. Its output is never trusted by itself
	 * for persistence.
	 *
	 * @since 7.1.0
	 * @access private
	 *
	 * @param string $content Serialized post content.
	 * @return string Content with implicit-core delimiter spelling normalized.
	 */
	function wp_de_rtc_strip_core_block_namespace_from_serialized_comments( $content ) {
		$normalized = preg_replace(
			'~<!--\s*(/?)wp:core/([^\s>]+)([\s\S]*?)-->~',
			'<!-- $1wp:$2$3-->',
			(string) $content
		);

		return is_string( $normalized ) ? $normalized : (string) $content;
	}

/**
 * Normalizes a sync-meta format label.
 *
 * @since 7.1.0
 * @access private
 *
 * @param mixed $format Sync-meta format label.
 * @return string Normalized label.
 */
function wp_de_rtc_normalize_sync_meta_format( $format ) {
	if ( ! is_string( $format ) ) {
		return '';
	}

	return strtolower( trim( $format ) );
}

/**
 * Counts Distributed Editing sync-meta SCRIPT elements in post content.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $content Post content.
 * @return int Number of DE-RTC sync-meta SCRIPT elements.
 */
function wp_de_rtc_count_post_content_sync_meta_scripts( $content ) {
	if (
		! is_string( $content ) ||
		(
			false === stripos( $content, 'wp/post-sync-meta' ) &&
			false === stripos( $content, 'wp:core/sync-meta' ) &&
			false === stripos( $content, 'wp:sync-meta' ) &&
			false === stripos( $content, 'data-wp-sync-meta' )
		)
	) {
		return 0;
	}

	$legacy_count = preg_match_all(
		'~<script\b(?=[^>]*\btype\s*=\s*(["\'])\s*wp/post-sync-meta\s*\1)[^>]*>[\s\S]*?</script\s*>~i',
		$content
	);
	$json_count   = preg_match_all(
		'~<script\b(?=[^>]*\btype\s*=\s*(["\'])\s*application/json\s*\1)(?=[^>]*\bdata-wp-sync-meta\s*=\s*(["\'])\s*distributed-editing\s*\2)[^>]*>[\s\S]*?</script\s*>~i',
		$content
	);
	$core_count   = preg_match_all(
		'~<!--\s*wp:(?:core/)?sync-meta\b[\s\S]*?<!--\s*/wp:(?:core/)?sync-meta\s*-->~i',
		$content
	);

	if ( false === $legacy_count ) {
		$legacy_count = 0;
	}

	if ( false === $core_count ) {
		$core_count = 0;
	}

	if ( false === $json_count ) {
		$json_count = 0;
	}

	if ( $core_count > 0 ) {
		return (int) $legacy_count + (int) $core_count;
	}

	return (int) $legacy_count + (int) $json_count;
}

/**
 * Matches a possible sync-meta SCRIPT element at one content edge.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $content  Post content.
 * @param string $position Edge position, either 'prefix' or 'trailer'.
 * @return array|false Matched SCRIPT data, or false when no edge script exists.
 */
function wp_de_rtc_match_edge_sync_meta_script( $content, $position ) {
	$script_pattern             = '(<script\b[^>]*>(.*?)</script\s*>)';
	$paragraph_wrapped_pattern  = '<p>\s*' . $script_pattern . '\s*</p>';
	$freeform_wrapped_pattern   = '<!--\s*wp:freeform\s*-->\s*' . $paragraph_wrapped_pattern . '\s*<!--\s*/wp:freeform\s*-->';
	$html_block_wrapped_pattern = '<!--\s*wp:html\s*-->\s*' . $script_pattern . '\s*<!--\s*/wp:html\s*-->';
	$core_block_wrapped_pattern = '<!--\s*wp:(?:core/)?sync-meta\b[^>]*-->\s*' . $script_pattern . '\s*<!--\s*/wp:(?:core/)?sync-meta\s*-->';
	$wrapped_pattern            = '(?|' . $core_block_wrapped_pattern . '|' . $html_block_wrapped_pattern . '|' . $freeform_wrapped_pattern . '|' . $paragraph_wrapped_pattern . ')';

	if ( 'prefix' === $position ) {
		$pattern = '~\A[ \t\r\n]*(?:' . $wrapped_pattern . '|' . $script_pattern . ')[ \t\r\n]*~is';

		if ( ! preg_match( $pattern, $content, $matches ) ) {
			return false;
		}

		$script = isset( $matches[1] ) && '' !== $matches[1] ? $matches[1] : ( $matches[3] ?? '' );
		$json   = isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : ( $matches[4] ?? '' );

		return array(
			'match'  => $matches[0],
			'script' => $script,
			'json'   => $json,
			'position' => false !== stripos( $matches[0], 'wp:core/sync-meta' ) || false !== stripos( $matches[0], 'wp:sync-meta' ) || false !== stripos( $matches[0], 'wp:html' ) ? 'prefix-block' : 'prefix',
		);
	}

	$trimmed_content = rtrim( $content );

	$wrapped_trailer_pattern = '~[ \t\r\n]*' . $wrapped_pattern . '[ \t\r\n]*\z~is';

	if ( preg_match( $wrapped_trailer_pattern, $trimmed_content, $matches, PREG_OFFSET_CAPTURE ) ) {
		return array(
			'match'  => substr( $content, $matches[0][1] ),
			'script' => $matches[1][0],
			'json'   => $matches[2][0],
			'position' => 'trailer',
		);
	}

	$script_start    = strripos( $trimmed_content, '<script' );

	if ( false === $script_start ) {
		return false;
	}

	while ( $script_start > 0 && preg_match( '/[ \t\r\n]/', $content[ $script_start - 1 ] ) ) {
		--$script_start;
	}

	$trailer = substr( $content, $script_start );
	$pattern = '~\A[ \t\r\n]*' . $script_pattern . '[ \t\r\n]*\z~is';

	if ( ! preg_match( $pattern, $trailer, $matches ) ) {
		return false;
	}

	return array(
		'match'  => $trailer,
		'script' => $matches[1],
		'json'   => $matches[2],
		'position' => 'trailer',
	);
}

/**
 * Matches a sync-meta pseudo-block whose SCRIPT wrapper was stripped.
 *
 * This is intentionally not part of the normal parser path. It lets revision
 * scans recover accepted bases created before DE-RTC preserved SCRIPT through
 * author KSES, while live saves still require the server-owned SCRIPT wrapper.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $content  Post content.
 * @param string $position Edge position, either 'prefix' or 'trailer'.
 * @return array|false Matched pseudo-block data, or false when absent.
 */
function wp_de_rtc_match_edge_script_stripped_sync_meta_block( $content, $position ) {
	if ( ! is_string( $content ) || false === stripos( $content, 'sync-meta' ) ) {
		return false;
	}

	$block_pattern = '(<!--\s*wp:(?:core/)?sync-meta\b(?P<attrs>[^>]*)-->\s*(?P<json>[\s\S]*?)\s*<!--\s*/wp:(?:core/)?sync-meta\s*-->)';

	if ( 'prefix' === $position ) {
		$pattern = '~\A[ \t\r\n]*' . $block_pattern . '[ \t\r\n]*~i';

		if ( ! preg_match( $pattern, $content, $matches ) ) {
			return false;
		}

		return array(
			'match' => $matches[0],
			'json'  => isset( $matches['json'] ) ? trim( $matches['json'] ) : '',
			'attrs' => isset( $matches['attrs'] ) ? trim( $matches['attrs'] ) : '',
		);
	}

	$trimmed_content = rtrim( $content );
	$pattern         = '~[ \t\r\n]*' . $block_pattern . '[ \t\r\n]*\z~i';

	if ( ! preg_match( $pattern, $trimmed_content, $matches, PREG_OFFSET_CAPTURE ) ) {
		return false;
	}

	return array(
		'match' => substr( $content, $matches[1][1] ),
		'json'  => isset( $matches['json'][0] ) ? trim( $matches['json'][0] ) : '',
		'attrs' => isset( $matches['attrs'][0] ) ? trim( $matches['attrs'][0] ) : '',
	);
}

/**
 * Parses a KSES-stripped sync-meta pseudo-block match.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $block Matched block data.
 * @return array|WP_Error Parsed sync metadata.
 */
function wp_de_rtc_parse_script_stripped_sync_meta_block( $block ) {
	$json = isset( $block['json'] ) && is_string( $block['json'] ) ? trim( $block['json'] ) : '';

	if ( '' === $json || '{' !== $json[0] || '}' !== substr( $json, -1 ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'The Distributed Editing sync metadata JSON is malformed.' ),
			array(
				'detail' => 'malformed_script_stripped_json',
			)
		);
	}

	$format = wp_de_rtc_get_script_stripped_sync_meta_block_format( isset( $block['attrs'] ) ? $block['attrs'] : '' );
	$script = '<script type="application/json" data-wp-sync-meta="distributed-editing"';

	if ( '' !== $format ) {
		$script .= ' data-sync-meta-format="' . esc_attr( $format ) . '"';
	}

	$script .= '></script>';

	return wp_de_rtc_parse_sync_meta_script( $script, $json );
}

/**
 * Extracts the sync-meta format from pseudo-block attributes.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $attrs Serialized block attributes.
 * @return string Normalized sync-meta format, or empty when absent.
 */
function wp_de_rtc_get_script_stripped_sync_meta_block_format( $attrs ) {
	$attrs = trim( (string) $attrs );

	if ( '' === $attrs || '{' !== $attrs[0] ) {
		return '';
	}

	$decoded = json_decode( $attrs, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || empty( $decoded['format'] ) ) {
		return '';
	}

	return wp_de_rtc_normalize_sync_meta_format( $decoded['format'] );
}

/**
 * Parses a possible Distributed Editing sync-meta SCRIPT element.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $script SCRIPT element HTML.
 * @param string $json   SCRIPT text content.
 * @return array|false|WP_Error Parsed sync metadata, false for non-DE-RTC scripts, or a WP_Error.
 */
function wp_de_rtc_parse_sync_meta_script( $script, $json ) {
	$processor = new WP_HTML_Tag_Processor( $script );

	if ( ! $processor->next_tag( 'script' ) ) {
		return false;
	}

	$type = $processor->get_attribute( 'type' );

	$is_legacy_script = is_string( $type ) && 'wp/post-sync-meta' === strtolower( trim( $type ) );
	$is_json_script   = is_string( $type ) && 'application/json' === strtolower( trim( $type ) );
	$is_core_script   = $is_json_script && 'distributed-editing' === strtolower( trim( (string) $processor->get_attribute( 'data-wp-sync-meta' ) ) );

	if ( ! $is_legacy_script && ! $is_core_script ) {
		return false;
	}

	$format = wp_de_rtc_normalize_sync_meta_format( $processor->get_attribute( 'data-sync-meta-format' ) );
	$sync_meta = json_decode( trim( $json ), true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $sync_meta ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'The Distributed Editing sync metadata JSON is malformed.' ),
			array(
				'detail'          => 'malformed_json',
				'json_error_code' => json_last_error(),
			)
		);
	}

	if ( '' === $format && isset( $sync_meta['schema'] ) && 'de-rtc-automerge-v1' === $sync_meta['schema'] ) {
		$format = 'automerge';
	}

	if ( '' === $format ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_malformed_sync_payload',
			__( 'The Distributed Editing sync metadata format is missing.' ),
			array(
				'detail' => 'missing_sync_meta_format',
			)
		);
	}

	if ( ! in_array( $format, wp_de_rtc_get_supported_sync_meta_formats(), true ) ) {
		return wp_de_rtc_get_reason_error(
			'de_rtc_unknown_sync_meta_format',
			__( 'The Distributed Editing sync metadata format is not supported.' ),
			array(
				'format' => $format,
			)
		);
	}

	return array(
		'sync_meta'        => $sync_meta,
		'sync_meta_format' => $format,
	);
}

