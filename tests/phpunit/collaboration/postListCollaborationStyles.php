<?php
/**
 * Tests for collaborative editing post list styles.
 *
 * @package GutenbergSyncEngines
 * @subpackage Collaboration
 *
 * @group collaboration
 */
class Tests_Collaboration_PostListCollaborationStyles extends WP_UnitTestCase {

	public function test_styles_do_not_reenable_quick_edit_on_locked_rows() {
		ob_start();
		gutenberg_sync_engines_post_list_styles();
		$css = ob_get_clean();

		// Core hides Quick Edit on locked rows; RTC must not revert that.
		$this->assertStringNotContainsString( 'tr.wp-locked .row-actions .inline', $css );
		// Bulk-edit checkboxes stay re-enabled.
		$this->assertStringContainsString( '.check-column input[type="checkbox"]', $css );
	}
}
