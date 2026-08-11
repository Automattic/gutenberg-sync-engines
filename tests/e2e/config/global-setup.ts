/**
 * Global setup for the plugin's collaboration e2e.
 *
 * A trimmed version of the Gutenberg subtree's own e2e global setup: it
 * authenticates once and resets the site to a clean, predictable state. It
 * deliberately omits the monorepo-suite-specific steps (deactivating
 * Gutenberg's CSS-animation test plugin, provisioning the RTC WebSocket
 * daemon) that assume test plugins this environment does not map — the
 * intent-log spec runs over the default HTTP-polling transport.
 *
 * External dependencies
 */
import { request } from '@playwright/test';
import type { FullConfig } from '@playwright/test';

/**
 * WordPress dependencies
 */
// eslint-disable-next-line import/no-unresolved -- Resolved via the plugin's own devDependency.
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

async function globalSetup( config: FullConfig ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( { baseURL } );

	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	// Authenticate and persist the storage state to disk.
	await requestUtils.setupRest();

	// wp-env does not reliably activate mapped plugins on the *tests* site,
	// so ensure both the Gutenberg framework (the vendored subtree) and this
	// plugin are active — collaboration is inert without them.
	await requestUtils.activatePlugin( 'gutenberg' );

	/*
	 * Worktree checkouts mount this plugin TWICE (the directory name via
	 * `plugins: ["."]` plus the .wp-env.json mapping), and both copies share
	 * one plugin name — the utils' name-keyed activatePlugin() can pick the
	 * inactive copy and fatal with a redeclare. Activate by file path, and
	 * only when no copy is active yet (see the AGENTS.md gotcha).
	 */
	const plugins = ( await requestUtils.rest( {
		path: '/wp/v2/plugins',
	} ) ) as Array< { plugin: string; status: string; name: string } >;
	const copies = plugins.filter(
		( { name } ) => 'Gutenberg Sync Engines' === name
	);
	if ( ! copies.some( ( { status } ) => 'active' === status ) ) {
		const preferred =
			copies.find( ( { plugin } ) =>
				plugin.startsWith( 'gutenberg-sync-engines/' )
			) ?? copies[ 0 ];
		if ( ! preferred ) {
			throw new Error(
				'The gutenberg-sync-engines plugin is not installed on the tests site.'
			);
		}
		await requestUtils.rest( {
			method: 'PUT',
			path: `/wp/v2/plugins/${ preferred.plugin }`,
			data: { status: 'active' },
		} );
	}

	// Reset the environment to a clean slate before the tests run.
	await Promise.all( [
		requestUtils.activateTheme( 'twentytwentyone' ),
		requestUtils.deleteAllPosts(),
		requestUtils.deleteAllPages(),
		requestUtils.deleteAllBlocks(),
		requestUtils.resetPreferences(),
	] );

	await requestContext.dispose();
}

export default globalSetup;
