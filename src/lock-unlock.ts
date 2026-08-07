/**
 * Private-API consent for `@wordpress/sync`.
 *
 * Mirrors Gutenberg's lock/unlock pattern: a plugin opts into a package's
 * unstable private APIs with the same consent string the package registers.
 * `@wordpress/sync` must call `registerPrivateApis` (or the shared
 * `@wordpress/private-apis` equivalent) with this exact string for the
 * unlock to succeed — see PORTING.md.
 */

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.privateApis.
import { __dangerousOptInToUnstableAPIsOnlyForCoreModules } from '@wordpress/private-apis';

export const { lock, unlock } =
	__dangerousOptInToUnstableAPIsOnlyForCoreModules(
		'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.',
		'@wordpress/sync'
	);
