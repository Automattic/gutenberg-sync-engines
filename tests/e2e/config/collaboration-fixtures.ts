/**
 * Plugin-local collaboration fixtures: the subtree's fixture wiring
 * (user setup, collaboration toggle, teardown) around a hardened
 * CollaborationUtils. The one override closes a full-suite-load flake
 * in the subtree fixture's login flow; the root-cause fix belongs
 * upstream in Gutenberg (human-owned), so the subtree stays pristine
 * and the specs import this module instead.
 */

/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/dependency-group
import { test as base } from '@wordpress/e2e-test-utils-playwright';
export { expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import CollaborationUtils, {
	SECOND_USER,
	setCollaboration,
} from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures/collaboration-utils';

class HardenedCollaborationUtils extends CollaborationUtils {
	/**
	 * The subtree fixture logs joining users in through wp-login.php,
	 * whose wp_attempt_focus() steals focus (and SELECTS the username
	 * field) on a timer after load. Under full-suite load that timer can
	 * fire between the fixture's two fill() calls, so the password is
	 * inserted into the still-selected username field, the mangled form
	 * submits, and the login page re-renders instead of navigating —
	 * observed in a retry-free full run as a username field holding the
	 * literal password, an empty password field, and a waitForURL
	 * timeout. One clean retry (a fresh context, a fresh login page)
	 * de-races the harness plumbing; the tests' assertion surfaces are
	 * untouched.
	 *
	 * @param args joinUser arguments (post ID, user credentials).
	 * @return The joined user's page and editor.
	 */
	async joinUser(
		...args: Parameters< CollaborationUtils[ 'joinUser' ] >
	): ReturnType< CollaborationUtils[ 'joinUser' ] > {
		try {
			return await super.joinUser( ...args );
		} catch {
			return await super.joinUser( ...args );
		}
	}
}

type Fixtures = {
	collaborationUtils: CollaborationUtils;
};

// Mirrors the subtree's fixtures/index.ts wiring exactly, constructing
// the hardened subclass instead.
export const test = base.extend< Fixtures >( {
	collaborationUtils: async (
		{ admin, editor, requestUtils, page },
		use
	) => {
		const utils = new HardenedCollaborationUtils( {
			admin,
			editor,
			requestUtils,
			page,
		} );
		// Clean up any leftover users from previous runs before creating.
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( SECOND_USER );
		await setCollaboration( requestUtils, true );
		try {
			await use( utils );
		} finally {
			try {
				await utils.teardown();
			} finally {
				await setCollaboration( requestUtils, false );
			}
		}
	},
} );
