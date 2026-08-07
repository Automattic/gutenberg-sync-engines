/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * The plugin ships a single client bundle that registers its engine adapters
 * and transport providers with the framework (`@wordpress/sync`) at load time.
 *
 * `@wordpress/sync` and Yjs are provided at runtime by WordPress as `wp.sync`
 * (and `wp.sync.Y`); they must NOT be bundled, so that this plugin consumes the
 * SAME Yjs instance as the framework (see https://github.com/yjs/yjs/issues/438)
 * and unlocks the same private-API registry.
 */
module.exports = {
	...defaultConfig,
	entry: {
		'sync-engines': './src/index.ts',
	},
	externals: {
		...defaultConfig.externals,
		'@wordpress/sync': 'wp.sync',
		yjs: 'wp.sync.Y',
	},
};
