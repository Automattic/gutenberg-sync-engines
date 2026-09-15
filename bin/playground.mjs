#!/usr/bin/env node
/**
 * Serves this checkout on a local WordPress Playground (PHP in WebAssembly,
 * SQLite, no Docker) with the plugin active and two accounts ready.
 *
 * Usage: npm run playground [-- <wp-playground-cli server flags>]
 *
 *   npm run playground                   # http://127.0.0.1:9400
 *   npm run playground -- --port=9500    # another port
 *   npm run playground -- --php=8.2      # another PHP version
 *
 * What it does, in order:
 *   1. Checks that the plugin bundle (build/) and the vendored Gutenberg
 *      (gutenberg/build) exist. Playground serves the checkout as-is —
 *      nothing is copied — so an unbuilt tree gives a blank editor with no
 *      obvious error. PHP edits are live; JS edits need `npm run build`
 *      (or `npm start`), exactly as with wp-env.
 *   2. Starts `wp-playground-cli server` with the checkout mounted at
 *      wp-content/plugins/gutenberg-sync-engines (that fixed name, whatever
 *      the directory is called, so a worktree works too) and applies
 *      blueprints/local.json: plugin activated, debug constants on, and a
 *      second account ("editor") so a second browser window can join the
 *      same post. Everything after `--` is passed to the CLI unchanged.
 *
 * The site is one PHP process behind one port, so every browser window
 * on it shares the same WordPress: open the same post in two windows
 * (one logged in as admin, a private window as editor) and they
 * collaborate over HTTP polling, the default transport. The WebSocket
 * transport needs the `wp collaboration sync-server` daemon, which the
 * wp-env setup runs (`npm run rtc:ws`); Playground has no daemon.
 *
 * Nothing persists: stopping the server (Ctrl+C) discards the site.
 */

import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const PLUGIN_DIR_NAME = 'gutenberg-sync-engines';
const BLUEPRINT = path.join( REPO_ROOT, 'blueprints', 'local.json' );

const die = ( message ) => {
	console.error( `playground: ${ message }` );
	process.exit( 1 );
};

const missing = [
	[ 'build/sync-engines.js', 'npm run build' ],
	[
		'gutenberg/build',
		'cd gutenberg && npm install --ignore-scripts && npm run build',
	],
].filter( ( [ file ] ) => ! existsSync( path.join( REPO_ROOT, file ) ) );

if ( missing.length ) {
	die(
		'the checkout is not built. Playground serves it as-is, so run:\n' +
			missing.map( ( [ , fix ] ) => `  ${ fix }` ).join( '\n' )
	);
}

const cli = path.join( REPO_ROOT, 'node_modules', '.bin', 'wp-playground-cli' );
if ( ! existsSync( cli ) ) {
	die( 'wp-playground-cli is not installed. Run: npm install' );
}

const passthrough = process.argv.slice( 2 );
const hasFlag = ( name ) =>
	passthrough.some(
		( arg ) => arg === name || arg.startsWith( `${ name }=` )
	);

const args = [
	'server',
	`--mount=${ REPO_ROOT }:/wordpress/wp-content/plugins/${ PLUGIN_DIR_NAME }`,
	`--blueprint=${ BLUEPRINT }`,
	// The blueprint's `login: true` only logs the CLI's internal cookie
	// store in; this flag is what logs a browser in on its first request.
	'--login',
];
if ( ! hasFlag( '--workers' ) ) {
	// One PHP worker: every request sees the same SQLite database and the
	// same login sessions. Several workers each answer from their own
	// copy, so a second tab is logged out ("Session expired") at random.
	args.push( '--workers=1' );
}
args.push( ...passthrough );

console.log( `Serving ${ REPO_ROOT }` );
console.log(
	`as wp-content/plugins/${ PLUGIN_DIR_NAME } on a local WordPress Playground.`
);
console.log( '' );
console.log( 'Accounts (both with the password "password"):' );
console.log( '  admin   — the browser is logged in as admin automatically' );
console.log( '  editor  — log in from a private window to collaborate' );
console.log( '' );

const child = spawn( cli, args, { cwd: REPO_ROOT, stdio: 'inherit' } );
child.on( 'exit', ( code, signal ) => {
	process.exit( signal ? 130 : code ?? 0 );
} );
for ( const sig of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( sig, () => child.kill( sig ) );
}
