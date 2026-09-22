/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

// At runtime WordPress provides the `@wordpress/*` packages; under Jest we
// resolve them from the pinned Gutenberg subtree in `./gutenberg` (the same
// copy wp-env and e2e run against), so the plugin and the editor share one
// copy of every stateful package (private-apis locks, hooks, data). Yjs is
// the plugin's own dependency and resolves normally. Requires the subtree to
// be installed and built (see Setup in AGENTS.md). Set WP_SYNC_FRAMEWORK_ROOT
// to test against a live Gutenberg checkout instead (co-development).
const FRAMEWORK_ROOT =
	process.env.WP_SYNC_FRAMEWORK_ROOT ||
	path.resolve( __dirname, 'gutenberg' );
const FRAMEWORK_MODULES = path.join( FRAMEWORK_ROOT, 'node_modules' );

const SUBTREE_PACKAGES = [
	'a11y',
	'api-fetch',
	'block-editor',
	'block-serialization-default-parser',
	'blocks',
	'components',
	'compose',
	'core-data',
	'data',
	'editor',
	'element',
	'hooks',
	'i18n',
	'icons',
	'interface',
	'notices',
	'preferences',
	'private-apis',
	'rich-text',
	'ui',
	'undo-manager',
];

module.exports = {
	...defaultConfig,
	// Discover ONLY this plugin's own tests. The pinned Gutenberg subtree in
	// `gutenberg/` carries thousands of the monorepo's own test files; without
	// this restriction Jest would recurse into it and run (and fail) them.
	roots: [ '<rootDir>/src', '<rootDir>/tests' ],
	// The frozen cross-language vector replay locates its fixture through
	// `import.meta.url`; Jest runs CommonJS, so transform it away with the same
	// plugin the framework's build uses. Overriding `transform` (rather than
	// adding a project babel config) keeps this scoped to Jest and leaves the
	// webpack build's babel untouched.
	transform: {
		'\\.m?[jt]sx?$': [
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
		...Object.fromEntries(
			SUBTREE_PACKAGES.map( ( name ) => [
				`^@wordpress/${ name }$`,
				path.join( FRAMEWORK_MODULES, '@wordpress', name ),
			] )
		),
		// One React: the subtree's packages render with its copy of React,
		// so the test renderer and any React import here must use the same
		// one (two copies break hooks with "Cannot read ... 'useState'").
		'^react$': path.join( FRAMEWORK_MODULES, 'react' ),
		'^react/(.*)$': path.join( FRAMEWORK_MODULES, 'react/$1' ),
		'^react-dom$': path.join( FRAMEWORK_MODULES, 'react-dom' ),
		'^react-dom/(.*)$': path.join( FRAMEWORK_MODULES, 'react-dom/$1' ),
		'^@testing-library/(.*)$': path.join(
			FRAMEWORK_MODULES,
			'@testing-library/$1'
		),
	},
	// Packages that ship only ES modules and are required through the
	// subtree's built packages (uuid through @wordpress/blocks, diff through
	// the quill-delta port). Jest leaves node_modules untransformed by
	// default; these need the same Babel pass as our own sources.
	transformIgnorePatterns: [ '/node_modules/(?!(uuid|diff|marked|parsel-js)/)' ],
	setupFiles: [
		...( defaultConfig.setupFiles || [] ),
		path.join( __dirname, 'tests/js/jest-setup.js' ),
	],
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv || [] ),
		path.join( __dirname, 'tests/js/jest-setup-after-env.js' ),
	],
};
