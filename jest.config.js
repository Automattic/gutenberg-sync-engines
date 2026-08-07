/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

// The framework (`@wordpress/sync`) is a sibling checkout. At runtime WordPress
// provides it (and Yjs) as `wp.sync`; under Jest there is no such global, so we
// resolve the framework from the sibling checkout and pin `yjs` itself to the
// framework's SINGLE copy so the plugin and the framework share one Yjs
// instance (https://github.com/yjs/yjs/issues/438). y-protocols and lib0 are
// left to normal resolution so their package `exports` pick the CommonJS build
// Jest can load; being stateless, a duplicate of them is harmless as long as
// they bind to the one shared `yjs`.
const FRAMEWORK_ROOT = path.resolve(
	__dirname,
	'../workspaces/gutenberg/try-intent-log'
);
const FRAMEWORK_MODULES = path.join( FRAMEWORK_ROOT, 'node_modules' );
const SYNC_SRC = path.join( FRAMEWORK_ROOT, 'packages/sync/src' );

module.exports = {
	...defaultConfig,
	// The frozen cross-language vector replay locates its fixture through
	// `import.meta.url`; Jest runs CommonJS, so transform it away with the same
	// plugin the framework's build uses. Overriding `transform` (rather than
	// adding a project babel config) keeps this scoped to Jest and leaves the
	// webpack build's babel untouched.
	transform: {
		'\\.[jt]sx?$': [
			require.resolve( 'babel-jest' ),
			{
				presets: [
					require.resolve( '@wordpress/babel-preset-default' ),
				],
				plugins: [
					require.resolve( 'babel-plugin-transform-import-meta' ),
				],
			},
		],
	},
	moduleNameMapper: {
		...( defaultConfig.moduleNameMapper || {} ),
		'^@wordpress/sync$': SYNC_SRC,
		'^yjs$': path.join( FRAMEWORK_MODULES, 'yjs' ),
		// The private-API lock lives in a module-scoped WeakMap; the framework
		// locks and the plugin unlocks, so both MUST share one copy of
		// @wordpress/private-apis (at runtime this is the single wp.privateApis).
		'^@wordpress/private-apis$': path.join(
			FRAMEWORK_MODULES,
			'@wordpress/private-apis'
		),
		// The framework reads provider creators through the `sync.providers`
		// hook filter that the tests write to; both sides must share one hooks
		// registry (the single wp.hooks at runtime).
		'^@wordpress/hooks$': path.join(
			FRAMEWORK_MODULES,
			'@wordpress/hooks'
		),
	},
	setupFiles: [
		...( defaultConfig.setupFiles || [] ),
		path.join( __dirname, 'tests/js/jest-setup.js' ),
	],
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv || [] ),
		path.join( __dirname, 'tests/js/jest-setup-after-env.js' ),
	],
};
