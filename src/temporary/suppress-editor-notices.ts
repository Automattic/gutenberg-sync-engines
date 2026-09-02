/**
 * TEMPORARY: keeps a few editor notices from ever showing:
 *
 * - "The backup of this post in your browser is different from the version
 *   below." The local autosave monitor creates it (id
 *   `wpEditorAutosaveRestore`) when a backup in sessionStorage differs from
 *   the post. Nothing lets a plugin turn it off. The backup itself is left
 *   alone; only the notice goes.
 * - "X has joined the post." and "X has left the post." The collaboration
 *   presence toasts (ids `collab-user-entered-<user>` and
 *   `collab-user-exited-<user>`). They do have preference switches, but the
 *   editor registers those preferences as on during its own initialization,
 *   after this bundle has loaded, so plugin-set defaults get overwritten.
 *   Setting the preference values instead would persist into the user's
 *   saved settings and outlive this code.
 *
 * So this watches the notices store and removes each of these notices as
 * soon as it is created. The removal runs in the same call stack as the
 * creation, before the editor re-renders, so nothing ever paints.
 *
 * The related "more recent autosave" notice is suppressed server-side
 * instead (Gutenberg_Sync_Engines_Plugin::suppress_autosave_notice()).
 *
 * Delete this file and its import in src/index.ts once the notices are
 * wanted again.
 */

/**
 * WordPress dependencies
 */
import { dispatch, select, subscribe } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

const SUPPRESSED_NOTICE_IDS = [ 'wpEditorAutosaveRestore' ];
const SUPPRESSED_NOTICE_ID_PREFIXES = [
	'collab-user-entered-',
	'collab-user-exited-',
];

function isSuppressed( id: string ): boolean {
	if ( SUPPRESSED_NOTICE_IDS.includes( id ) ) {
		return true;
	}

	return SUPPRESSED_NOTICE_ID_PREFIXES.some( ( prefix ) =>
		id.startsWith( prefix )
	);
}

/**
 * Starts removing the suppressed notices whenever they appear.
 */
export function suppressEditorNotices(): void {
	subscribe( () => {
		const notices = select( noticesStore ).getNotices();
		for ( const notice of notices ) {
			if ( isSuppressed( notice.id ) ) {
				dispatch( noticesStore ).removeNotice( notice.id );
			}
		}
	}, noticesStore );
}
