#!/usr/bin/env node
/**
 * Prepares a release commit on a release/vX.Y.Z branch.
 *
 * Usage: npm run release -- <patch|minor|major>
 *
 * What it does, in order:
 *   1. Refuses to run on a dirty working tree.
 *   2. Bumps the version in package.json + package-lock.json (npm version).
 *   3. Stamps the new version into the plugin header and the
 *      GUTENBERG_SYNC_ENGINES_VERSION constant, and replaces any `n.e.x.t`
 *      placeholders in tracked source files.
 *   4. Renames the changelog's "Unreleased" section to the new version
 *      (refuses to release an empty changelog section).
 *   5. Commits everything on a new release/vX.Y.Z branch.
 *
 * It never pushes — the create-release-pr workflow (or a human) does that.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';

const run = ( cmd, args ) =>
	execFileSync( cmd, args, { encoding: 'utf8' } ).trim();

const die = ( message ) => {
	console.error( `release: ${ message }` );
	process.exit( 1 );
};

const type = process.argv[ 2 ];
if ( ! [ 'patch', 'minor', 'major' ].includes( type ) ) {
	die( 'usage: npm run release -- <patch|minor|major>' );
}

if ( run( 'git', [ 'status', '--porcelain' ] ) !== '' ) {
	die( 'working tree is dirty; commit or stash first' );
}

run( 'npm', [ 'version', type, '--no-git-tag-version' ] );
const version = JSON.parse( readFileSync( 'package.json', 'utf8' ) ).version;

// Plugin header + version constant.
const entryPath = 'gutenberg-sync-engines.php';
let entry = readFileSync( entryPath, 'utf8' );
const stamped = entry
	.replace( /^( \* Version:\s+).*$/m, `$1${ version }` )
	.replace(
		/(define\( 'GUTENBERG_SYNC_ENGINES_VERSION', ')[^']*(' \);)/,
		`$1${ version }$2`
	);
if ( stamped === entry ) {
	die( `could not stamp the version into ${ entryPath }` );
}
writeFileSync( entryPath, stamped );

// `n.e.x.t` placeholders in tracked sources (WordPress convention for
// "the next released version" in @since tags).
let placeholderFiles = [];
try {
	placeholderFiles = run( 'git', [
		'grep',
		'-l',
		'n.e.x.t',
		'--',
		'*.php',
		'*.js',
		'*.ts',
		'*.tsx',
	] )
		.split( '\n' )
		.filter( Boolean );
} catch {
	// git grep exits 1 when nothing matches — no placeholders to replace.
}
for ( const file of placeholderFiles ) {
	writeFileSync(
		file,
		readFileSync( file, 'utf8' ).replaceAll( 'n.e.x.t', version )
	);
}

// Changelog: rename "Unreleased" to the new version, dated.
const changelogPath = 'CHANGELOG.md';
const changelog = readFileSync( changelogPath, 'utf8' );
const headingIndex = changelog.search( /^## Unreleased$/m );
if ( headingIndex === -1 ) {
	die( 'CHANGELOG.md has no "## Unreleased" section' );
}
const body = changelog.slice( headingIndex + '## Unreleased'.length );
const nextHeading = body.search( /^## /m );
const section = nextHeading === -1 ? body : body.slice( 0, nextHeading );
if ( ! /^-/m.test( section ) ) {
	die( 'the "## Unreleased" changelog section is empty — nothing to release' );
}
const now = new Date();
const month = now.toLocaleString( 'en-US', { month: 'long' } );
writeFileSync(
	changelogPath,
	changelog.replace(
		/^## Unreleased$/m,
		`## ${ version } — ${ month } ${ now.getFullYear() }`
	)
);

const branch = `release/v${ version }`;
run( 'git', [ 'switch', '-c', branch ] );
run( 'git', [ 'add', '-A' ] );
run( 'git', [ 'commit', '--no-verify', '-m', `Release v${ version }` ] );

console.log( `Prepared ${ branch } (v${ version }).` );
