/**
 * Private-API consent for `@wordpress/core-data`.
 *
 * The entity sync seam (`registerEntitySyncManager` / `getEntitySyncManager`)
 * is a private core-data API. Gutenberg's lock/unlock pattern lets a caller
 * that names a core module and repeats the consent string reach it. The
 * same `unlock` also opens the other packages' private APIs this plugin
 * needs (the block editor's private selectors and components).
 */

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Provided at runtime as wp.privateApis.
import { __dangerousOptInToUnstableAPIsOnlyForCoreModules } from '@wordpress/private-apis';

export const { lock, unlock } =
	__dangerousOptInToUnstableAPIsOnlyForCoreModules(
		'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.',
		'@wordpress/core-data'
	);
