/**
 * The collaboration UI, mounted into the editor through public extension
 * points only: a `PinnedItems` fill for the header avatars, a portal into
 * the block canvas for carets and conflict cards, the more menu for the
 * preferences, a document settings panel for the review list, and the
 * notices store for join, leave and save messages.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Fill } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { store as hostStore } from '../host/store';
import CollaborationReviewPanel from './collaboration-review-panel';
import { useReviewData } from './collaboration-review-panel/review-data';
import CollaborationConflictMarkers from './collaboration-review-panel/markers';
import { CollaboratorsPresence } from './collaborators-presence';
import { useCollaboratorNotifications } from './collaborators-presence/use-collaborator-notifications';
import {
	CollaborationPreferencesMenuItem,
	registerPreferenceDefaults,
} from './preferences';
import { SyncConnectionErrorModal } from './sync-connection-error-modal';
import { editorSelectors } from './stores';

export const PLUGIN_NAME = 'gutenberg-sync-engines';

/**
 * The current post the editor shows, or nulls before the editor is set up.
 *
 * @return The post type and id.
 */
function useCurrentPost(): { postType: string | null; postId: number | null } {
	return useSelect( ( select ) => {
		const { getCurrentPostType, getCurrentPostId } =
			editorSelectors( select );
		return {
			postType: getCurrentPostType() ?? null,
			postId: getCurrentPostId() ?? null,
		};
	}, [] );
}

/**
 * Join, leave and save snackbars. Runs unconditionally so the messages are
 * dispatched whatever the viewport width or header layout.
 *
 * @param props          Props.
 * @param props.postId   The post id.
 * @param props.postType The post type.
 */
function CollaboratorNotifications( {
	postId,
	postType,
}: {
	postId: number | null;
	postType: string | null;
} ) {
	useCollaboratorNotifications( postId, postType );
	return null;
}

/**
 * The document-sidebar panel listing the parked conflicts. Mounted only
 * while there is something to review, so the sidebar stays as it was
 * for everyone else (and the class name the specs look for means "a
 * review is pending").
 */
function ReviewSettingPanel() {
	const { items } = useReviewData();

	if ( ! items.length ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="collaboration-review"
			title={ sprintf(
				/* translators: %d: number of conflicting edits awaiting review. */
				__( 'Collaboration conflicts (%d)' ),
				items.length
			) }
			className="editor-collaboration-review-panel"
		>
			<CollaborationReviewPanel />
		</PluginDocumentSettingPanel>
	);
}

/**
 * Everything the plugin renders into the editor.
 */
export function CollaborationUi() {
	const { postType, postId } = useCurrentPost();
	const isEnabled = useSelect(
		( select ) =>
			select( hostStore ).isCollaborationEnabledForCurrentPost(),
		[]
	);

	return (
		<>
			<CollaborationPreferencesMenuItem />
			<SyncConnectionErrorModal />
			{ isEnabled && (
				<>
					<CollaboratorNotifications
						postId={ postId }
						postType={ postType }
					/>
					{ /*
					 * The header's pinned-items area is a named slot of the
					 * shared components slot-fill registry; filling it by name
					 * avoids bundling a second copy of the interface package
					 * (its store would register twice).
					 */ }
					<Fill name="PinnedItems/core">
						<CollaboratorsPresence
							postType={ postType }
							postId={ postId }
						/>
					</Fill>
					<CollaborationConflictMarkers />
					<ReviewSettingPanel />
				</>
			) }
		</>
	);
}

let installed = false;

/**
 * Mounts the collaboration UI. Safe to call once; later calls do nothing.
 */
export function installUi(): void {
	if ( installed ) {
		return;
	}
	installed = true;
	registerPreferenceDefaults();
	registerPlugin( PLUGIN_NAME, { render: CollaborationUi } );
}
