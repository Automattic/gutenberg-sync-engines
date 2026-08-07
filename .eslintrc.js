/**
 * ESLint config, mirroring Gutenberg's TypeScript setup: the WordPress
 * eslint plugin's recommended ruleset, with the sync framework externalized
 * to the `wp.sync` global at build time (see the dependency-extraction note
 * in README).
 *
 * @type {import('eslint').Linter.Config}
 */
module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	settings: {
		'import/resolver': {
			node: {
				extensions: [ '.js', '.ts', '.tsx' ],
			},
		},
	},
	overrides: [
		{
			files: [ '**/*.ts', '**/*.tsx' ],
			parser: '@typescript-eslint/parser',
		},
		{
			// The frozen JS engine core is a vendored cross-language contract
			// (byte-matched against its PHP twin and JSON vectors); do not
			// reformat or relint it here.
			files: [ 'src/engines/intent-log/**/*.js' ],
			rules: {
				'@wordpress/no-unused-vars-before-return': 'off',
			},
		},
	],
};
