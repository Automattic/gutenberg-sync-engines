/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * The plugin ships a single client bundle: the sync core (formerly
 * Gutenberg's `@wordpress/sync`), Yjs, the engines, the transports, the
 * presence and review UI, and the bridge into core-data's entity sync
 * seam. Yjs is bundled here on purpose: this is the one Yjs instance on
 * the page, exposed as `window.gutenbergSyncEngines.Y` for third-party
 * engine or transport plugins (https://github.com/yjs/yjs/issues/438).
 * The `@wordpress/*` packages stay externals, provided by WordPress.
 */
module.exports = {
	...defaultConfig,
	entry: {
		'sync-engines': './src/index.ts',
	},
};
