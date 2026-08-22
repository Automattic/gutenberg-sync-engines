<?php
/**
 * Generates the DE-RTC descriptor cross-language vector fixture.
 *
 * The TS descriptor builder (src/engines/de-rtc/descriptor.ts) must
 * byte-match the frozen merge core's server-side derivation — a mismatch
 * is a FALSE TAMPER REJECTION of a legitimate save under the engine's
 * full descriptor enforcement. This script runs the PHP side of the
 * contract over an exhaustive (base, proposed) matrix and records the
 * fingerprints the server would expect; the Jest suite
 * (tests/js/engines/de-rtc/descriptor-vectors.test.ts) replays the same
 * matrix through the TS builder.
 *
 * Regenerate (tests env must be running with the plugin active):
 *
 *   node tests/tools/generate-de-rtc-descriptor-vectors.mjs
 *
 * (which runs `wp eval-file` on this script in the tests env cli
 * container and rewrites the vector file at
 * tests/js/engines/de-rtc/test-vectors/descriptor-vectors.json).
 *
 * @package gutenberg-sync-engines
 */

if ( ! function_exists( 'wp_de_rtc_create_automerge_update_for_content_change' ) ) {
	echo "ERROR: DE-RTC merge core not loaded (is the plugin active?)\n";
	exit( 1 );
}

/**
 * Serializes a paragraph block.
 *
 * @param string $html  Inner HTML.
 * @param string $attrs Serialized attribute JSON (with trailing space) or ''.
 * @return string Serialized block.
 */
function gse_vec_p( $html, $attrs = '' ) {
	return "<!-- wp:paragraph {$attrs}-->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
}

/**
 * Serializes a level-2 heading block.
 *
 * @param string $html Inner HTML.
 * @return string Serialized block.
 */
function gse_vec_h( $html ) {
	return "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">{$html}</h2>\n<!-- /wp:heading -->";
}

/**
 * Joins blocks the way the editor serializer does.
 *
 * @param string ...$blocks Serialized blocks.
 * @return string Serialized content.
 */
function gse_vec_doc( ...$blocks ) {
	return implode( "\n\n", $blocks );
}

$gse_sep    = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
$gse_img    = "<!-- wp:image {\"id\":42,\"url\":\"https://example.com/a/b.png\",\"alt\":\"caf\u{00e9}\"} -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/a/b.png\" alt=\"caf\u{00e9}\"/></figure>\n<!-- /wp:image -->";
$gse_img2   = "<!-- wp:image {\"id\":42,\"url\":\"https://example.com/a/b.png\",\"alt\":\"tea\"} -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/a/b.png\" alt=\"tea\"/></figure>\n<!-- /wp:image -->";
$gse_group  = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">" . gse_vec_p( 'inner' ) . "</div>\n<!-- /wp:group -->";
$gse_group2 = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">" . gse_vec_p( 'inner edited' ) . "</div>\n<!-- /wp:group -->";

$gse_cases = array(
	// --- No-op and trivial shapes. ---
	array( 'identical', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ) ),
	array( 'empty-to-one-block', '', gse_vec_p( 'Born' ) ),
	array( 'one-block-to-empty', gse_vec_p( 'Doomed' ), '' ),
	array( 'whitespace-only', "  \n\n  ", gse_vec_p( 'Alpha' ) ),

	// --- Rich-text content splices (block.rich_text_content). ---
	array( 'text-append', gse_vec_p( 'Hello' ), gse_vec_p( 'Hello world' ) ),
	array( 'text-mid-edit', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'The quick fox' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'The quick brown fox' ) ) ),
	array( 'text-delete-region', gse_vec_p( 'The quick brown fox' ), gse_vec_p( 'The fox' ) ),
	array( 'text-emoji-astral', gse_vec_p( 'Party 🎉 time' ), gse_vec_p( 'Party 🎉🎊 time' ) ),
	array( 'text-emoji-zwj', gse_vec_p( 'Team 👩‍👩‍👧' ), gse_vec_p( 'Team 👩‍👩‍👧‍👦 grows' ) ),
	array( 'text-combining-accents', gse_vec_p( "re\u{0301}sume\u{0301}" ), gse_vec_p( "re\u{0301}sume\u{0301}s" ) ),
	array( 'text-entity-amp', gse_vec_p( 'Fish &amp; chips' ), gse_vec_p( 'Fish &amp; mushy peas' ) ),
	array( 'text-entity-nbsp-lt', gse_vec_p( 'a&nbsp;&lt;&nbsp;b' ), gse_vec_p( 'a&nbsp;&lt;&nbsp;c' ) ),
	array( 'text-entity-numeric', gse_vec_p( 'It&#8217;s fine' ), gse_vec_p( 'It&#8217;s great' ) ),
	array( 'text-inside-bold', gse_vec_p( 'plain <strong>bold</strong> tail' ), gse_vec_p( 'plain <strong>bolder</strong> tail' ) ),
	array( 'text-replace-everything', gse_vec_p( 'old words' ), gse_vec_p( 'entirely new phrasing' ) ),

	// --- Rich-text format changes (block.rich_text_format). ---
	array( 'format-add-bold', gse_vec_p( 'make this strong' ), gse_vec_p( 'make <strong>this</strong> strong' ) ),
	array( 'format-remove-bold', gse_vec_p( 'keep <strong>this</strong> plain' ), gse_vec_p( 'keep this plain' ) ),
	array( 'format-add-em-via-i', gse_vec_p( 'some words' ), gse_vec_p( 'some <i>words</i>' ) ),
	array( 'format-b-alias', gse_vec_p( 'aliased text' ), gse_vec_p( '<b>aliased</b> text' ) ),
	array( 'format-nested-overlap', gse_vec_p( '<strong>alpha beta</strong> gamma' ), gse_vec_p( '<strong>alpha <em>beta</em></strong> <em>gamma</em>' ) ),

	// --- Paragraph edits that leave the rich-text lane. ---
	array( 'paragraph-attrs-change', gse_vec_p( 'Cap', '{"dropCap":true} ' ), gse_vec_p( 'Cap', '{"dropCap":false} ' ) ),
	array( 'paragraph-unsupported-tag', gse_vec_p( 'a line' ), gse_vec_p( 'a line<br>break' ) ),
	array( 'paragraph-unbalanced-tag', gse_vec_p( 'tidy' ), gse_vec_p( '<strong>untidy' ) ),
	array( 'paragraph-stray-lt', gse_vec_p( 'x' ), gse_vec_p( '3 < 4 says math' ) ),
	array( 'paragraph-link', gse_vec_p( 'visit here' ), gse_vec_p( 'visit <a href="https://example.com">here</a>' ) ),

	// --- Non-paragraph updates and replaces. ---
	array( 'image-attr-change', gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_img ), gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_img2 ) ),
	array( 'heading-text-change', gse_vec_doc( gse_vec_h( 'Title' ), gse_vec_p( 'Body' ) ), gse_vec_doc( gse_vec_h( 'Better title' ), gse_vec_p( 'Body' ) ) ),
	array( 'block-replace-name', gse_vec_doc( gse_vec_p( 'Promoted' ), gse_vec_p( 'Body' ) ), gse_vec_doc( gse_vec_h( 'Promoted' ), gse_vec_p( 'Body' ) ) ),
	array( 'two-blocks-changed', gse_vec_doc( gse_vec_p( 'One' ), gse_vec_p( 'Two' ), gse_vec_p( 'Three' ) ), gse_vec_doc( gse_vec_p( 'One!' ), gse_vec_p( 'Two' ), gse_vec_p( 'Three!' ) ) ),
	array( 'nested-group-inner-edit', gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_group ), gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_group2 ) ),

	// --- Structural: inserts, deletes, moves. ---
	array( 'insert-at-end', gse_vec_doc( gse_vec_p( 'Alpha' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ) ),
	array( 'insert-at-start', gse_vec_doc( gse_vec_p( 'Alpha' ) ), gse_vec_doc( gse_vec_p( 'Zero' ), gse_vec_p( 'Alpha' ) ) ),
	array( 'insert-in-middle', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Gamma' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ), gse_vec_p( 'Gamma' ) ) ),
	array( 'insert-multiple', gse_vec_doc( gse_vec_p( 'Alpha' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_sep, gse_vec_p( 'Beta' ) ) ),
	array( 'insert-void-block', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_sep, gse_vec_p( 'Beta' ) ) ),
	array( 'delete-middle', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ), gse_vec_p( 'Gamma' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Gamma' ) ) ),
	array( 'delete-and-insert-region', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ), gse_vec_p( 'Gamma' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_h( 'New' ), gse_vec_p( 'Newer' ), gse_vec_p( 'Gamma' ) ) ),
	array( 'pure-move', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ), gse_vec_p( 'Gamma' ) ), gse_vec_doc( gse_vec_p( 'Gamma' ), gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ) ),
	array( 'move-swap-two', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ), gse_vec_doc( gse_vec_p( 'Beta' ), gse_vec_p( 'Alpha' ) ) ),
	array( 'move-with-duplicates', gse_vec_doc( gse_vec_p( 'Twin' ), gse_vec_p( 'Twin' ), gse_vec_p( 'Solo' ) ), gse_vec_doc( gse_vec_p( 'Solo' ), gse_vec_p( 'Twin' ), gse_vec_p( 'Twin' ) ) ),
	array( 'same-count-not-permutation', gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ), gse_vec_doc( gse_vec_p( 'Beta' ), gse_vec_p( 'Delta' ) ) ),

	// --- Unsupported / fallback boundaries. ---
	array( 'freeform-only', '<p>naked html, no delimiters</p>', '<p>still naked html</p>' ),
	array( 'freeform-mixed', gse_vec_doc( gse_vec_p( 'Alpha' ) ) . "\n\n<p>stray</p>", gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta' ) ) . "\n\n<p>stray</p>" ),
	array( 'noncanonical-delimiter-spacing', "<!--  wp:paragraph   -->\n<p>odd spacing</p>\n<!--   /wp:paragraph  -->", gse_vec_p( 'fixed spacing' ) ),

	// --- Canonicalization behaviors. ---
	array( 'core-prefixed-delimiters', "<!-- wp:core/paragraph -->\n<p>Alpha</p>\n<!-- /wp:core/paragraph -->", gse_vec_p( 'Alpha beta' ) ),
	array( 'pretty-boundary-whitespace', gse_vec_p( 'Alpha' ) . "\n\n\n\n" . gse_vec_p( 'Beta' ), gse_vec_doc( gse_vec_p( 'Alpha' ), gse_vec_p( 'Beta edited' ) ) ),

	// --- Attribute-escaping edges (serializer parity). ---
	array( 'attrs-unicode-and-slashes', gse_vec_doc( gse_vec_p( 'Alpha' ) ), gse_vec_doc( gse_vec_p( 'Alpha' ), $gse_img ) ),
	array( 'attrs-dashes-escaped', gse_vec_p( 'x', '{"className":"a--b"} ' ), gse_vec_p( 'y', '{"className":"a--b"} ' ) ),
	array( 'attrs-quote-escapes', gse_vec_p( 'x', '{"metadata":{"name":"Say \\u0022hi\\u0022"}} ' ), gse_vec_p( 'xy', '{"metadata":{"name":"Say \\u0022hi\\u0022"}} ' ) ),

	// --- Known-divergence probes (learn, do not assume). ---
	array( 'float-attr-trailing-zero', "<!-- wp:spacer {\"height\":1.0} -->\n<div style=\"height:1px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->", "<!-- wp:spacer {\"height\":2.0} -->\n<div style=\"height:2px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->" ),
	array( 'entity-no-semicolon', gse_vec_p( 'salt &amp pepper' ), gse_vec_p( 'salt &amp vinegar' ) ),
);

/*
 * Seeded combinatorial sweep: deterministic random documents and 1–3
 * random mutations each (edit/insert/delete/move/replace), so lane
 * COMBINATIONS get coverage beyond the targeted cases. mt_rand's
 * algorithm is fixed (PHP ≥ 7.1), so the matrix is stable.
 */
$gse_pool = array(
	gse_vec_p( 'Alpha block' ),
	gse_vec_p( 'Beta with <strong>bold</strong>' ),
	gse_vec_p( 'Gamma &amp; friends' ),
	gse_vec_h( 'Delta heading' ),
	$gse_sep,
	$gse_img,
	gse_vec_p( 'Epsilon 🎉 emoji' ),
	$gse_group,
);

for ( $gse_seed = 0; $gse_seed < 24; $gse_seed++ ) {
	mt_srand( 1000 + $gse_seed );
	$gse_base_blocks = array();
	$gse_count       = mt_rand( 2, 6 );
	for ( $gse_i = 0; $gse_i < $gse_count; $gse_i++ ) {
		$gse_base_blocks[] = $gse_pool[ mt_rand( 0, count( $gse_pool ) - 1 ) ];
	}
	$gse_next_blocks = $gse_base_blocks;
	$gse_mutations   = mt_rand( 1, 3 );
	for ( $gse_m = 0; $gse_m < $gse_mutations; $gse_m++ ) {
		$gse_at = mt_rand( 0, max( 0, count( $gse_next_blocks ) - 1 ) );
		switch ( mt_rand( 0, 4 ) ) {
			case 0: // Edit: append seeded text into a paragraph (harmless no-op elsewhere).
				$gse_next_blocks[ $gse_at ] = str_replace( '</p>', " edit{$gse_seed}{$gse_m}</p>", $gse_next_blocks[ $gse_at ] );
				break;
			case 1: // Insert.
				array_splice( $gse_next_blocks, $gse_at, 0, array( gse_vec_p( "Inserted {$gse_seed}-{$gse_m}" ) ) );
				break;
			case 2: // Delete.
				if ( count( $gse_next_blocks ) > 1 ) {
					array_splice( $gse_next_blocks, $gse_at, 1 );
				}
				break;
			case 3: // Move to front.
				$gse_moved = array_splice( $gse_next_blocks, $gse_at, 1 );
				array_unshift( $gse_next_blocks, $gse_moved[0] );
				break;
			case 4: // Replace with a different block type.
				$gse_next_blocks[ $gse_at ] = gse_vec_h( "Replaced {$gse_seed}-{$gse_m}" );
				break;
		}
	}
	$gse_cases[] = array(
		"seeded-{$gse_seed}",
		gse_vec_doc( ...$gse_base_blocks ),
		gse_vec_doc( ...$gse_next_blocks ),
	);
}

$gse_out = array();
foreach ( $gse_cases as $gse_case ) {
	list( $gse_name, $gse_base, $gse_next ) = $gse_case;
	$gse_update                             = wp_de_rtc_create_automerge_update_for_content_change( $gse_base, $gse_next, 'client' );
	$gse_out[]                              = array(
		'name'     => $gse_name,
		'base'     => $gse_base,
		'next'     => $gse_next,
		'expected' => array(
			'fingerprints'        => wp_de_rtc_get_automerge_block_native_operation_fingerprints( $gse_update['operations'] ),
			'baseContentHash'     => $gse_update['baseContentHash'],
			'proposedContentHash' => $gse_update['proposedContentHash'],
			'baseBlockCount'      => $gse_update['baseBlockCount'],
			'proposedBlockCount'  => $gse_update['proposedBlockCount'],
			'operationTypes'      => array_map(
				static function ( $gse_op ) {
					return $gse_op['type'];
				},
				$gse_update['operations']
			),
		),
	);
}

echo "-----BEGIN DE-RTC DESCRIPTOR VECTORS-----\n";
echo wp_json_encode( $gse_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
echo "\n-----END DE-RTC DESCRIPTOR VECTORS-----\n";
