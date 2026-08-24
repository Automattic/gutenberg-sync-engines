/**
 * WordPress dependencies
 */
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { test, expect } from '../config/collaboration-fixtures';

/*
 * The plugin bundles Gutenberg (the collaborative-editing framework) and
 * loads that bundled copy when no other Gutenberg is present — the release
 * arrangement, and since the gutenberg mount left the wp-env configs, also
 * how every other spec in this suite gets its framework. This spec covers
 * the OTHER production arrangement: a site where a standalone Gutenberg was
 * already installed and active before gutenberg-sync-engines. The bundled
 * copy must defer — loading both is a fatal redeclare.
 *
 * The standalone install is materialized by the `gutenberg-stub` fixture
 * (tests/e2e/plugins/gutenberg-stub), which the tests wp-env mounts at
 * wp-content/plugins/gutenberg. It declares the same top-level symbol as
 * the real gutenberg.php (so a wrongly-loaded bundled copy still collides
 * fatally) plus a REST route reporting which file supplied that symbol.
 *
 * Activation order matters and mirrors real installs: a standalone
 * Gutenberg cannot be activated while the bundled copy is loaded (the
 * activation sandbox hits the redeclare), so the spec deactivates this
 * plugin first — "Gutenberg was there first" — then reactivates it on top.
 */

interface PluginRecord {
	plugin: string;
	status: string;
	name: string;
}

async function listPlugins(
	requestUtils: RequestUtils
): Promise< PluginRecord[] > {
	return ( await requestUtils.rest( {
		path: '/wp/v2/plugins',
	} ) ) as PluginRecord[];
}

async function setPluginStatus(
	requestUtils: RequestUtils,
	plugin: string,
	status: 'active' | 'inactive'
) {
	await requestUtils.rest( {
		method: 'PUT',
		path: `/wp/v2/plugins/${ plugin }`,
		data: { status },
	} );
}

test.describe( 'Standalone Gutenberg precedence', () => {
	test( 'a pre-existing standalone Gutenberg loads instead of the bundled copy', async ( {
		requestUtils,
	} ) => {
		const plugins = await listPlugins( requestUtils );
		// Worktree checkouts mount this plugin twice; exactly one copy is
		// active (the global setup guarantees it). Track that copy by its
		// path-shaped identifier so cleanup restores the same arrangement.
		const ourCopy = plugins.find(
			( { name, status } ) =>
				'Gutenberg Sync Engines' === name && 'active' === status
		);
		expect( ourCopy ).toBeDefined();
		const stub = plugins.find( ( { plugin } ) =>
			plugin.startsWith( 'gutenberg/' )
		);
		expect( stub ).toBeDefined();

		try {
			// "Gutenberg was there first": deactivate this plugin, then
			// activate the standalone copy, then install ours on top.
			await setPluginStatus( requestUtils, ourCopy!.plugin, 'inactive' );
			await setPluginStatus( requestUtils, stub!.plugin, 'active' );
			await setPluginStatus( requestUtils, ourCopy!.plugin, 'active' );

			// The stub's probe reports which file declared Gutenberg's entry
			// symbol. If the bundled copy had loaded too, the site would have
			// fataled on the redeclare before this request could answer.
			const status = ( await requestUtils.rest( {
				path: '/sync-engines-test/v1/gutenberg-status',
			} ) ) as {
				pre_init_file: string;
				is_stub: boolean;
				sync_engines_loaded: boolean;
			};

			expect( status.is_stub ).toBe( true );
			expect( status.sync_engines_loaded ).toBe( true );
			// The standalone path — NOT <plugin-dir>/gutenberg/gutenberg.php,
			// which also ends in /gutenberg/gutenberg.php but sits one
			// directory deeper.
			expect(
				status.pre_init_file.endsWith(
					'/plugins/gutenberg/gutenberg.php'
				)
			).toBe( true );
		} finally {
			// Restore the suite's arrangement: our plugin active, stub
			// inactive. Reactivate ours FIRST (safe while the stub is
			// active — the loader defers), so the site never sits without
			// the framework if a step above failed.
			await setPluginStatus( requestUtils, ourCopy!.plugin, 'active' );
			await setPluginStatus( requestUtils, stub!.plugin, 'inactive' );
		}
	} );
} );
