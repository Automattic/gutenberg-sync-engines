/**
 * Launches the plugin's REAL websocket transport lane for the
 * websocket-only e2e suite: selects the websocket transport on the TESTS
 * site and runs the `wp collaboration sync-server` PHP daemon in the
 * tests env's cli container with host port 8787 published (the daemon
 * must bind 0.0.0.0 — a loopback-bound or unpublished daemon is silently
 * unreachable from the browser).
 *
 * Meant to run as a Playwright webServer command: Playwright waits on the
 * daemon's own `http://localhost:8787/health` endpoint and terminates
 * this process at teardown, at which point the previous transport
 * selection is restored and the container removed. Mirrors the fuzzer's
 * daemon machinery (tests/fuzzer/run.mjs) and the `npm run rtc:ws` dev
 * flow.
 */

/**
 * External dependencies
 */
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../../..'
);
const TESTS_CONFIG = '.wp-env.tests.json';
const WS_PORT = 8787;
const CONTAINER = 'rtc-e2e-ws-daemon';
// Other holders of host port 8787 (the dev env's auto-started daemon
// serves the DEV database — the wrong one for this suite; a stale fuzz
// daemon may also linger).
const OTHER_PORT_HOLDERS = [ 'wp-sync-ws-daemon', 'rtc-fuzz-ws-daemon' ];
const TRANSPORT_OPTION = 'gutenberg_sync_engines_transport';

/**
 * The tests env's wp-env work directory (legacy md5 name, descriptive
 * name, then a compose-file scan), mirroring wp-env's own naming.
 */
function wpEnvWorkDirectory() {
	const home =
		process.env.WP_ENV_HOME || path.join( os.homedir(), '.wp-env' );
	const configFilePath = path.join( REPO_ROOT, TESTS_CONFIG );
	const hash = createHash( 'md5' ).update( configFilePath ).digest( 'hex' );

	const legacy = path.join( home, hash );
	if ( existsSync( legacy ) ) {
		return legacy;
	}
	const descriptive = path.join(
		home,
		`wp-env-${ path.basename( REPO_ROOT ) }-tests-${ hash.slice( 0, 8 ) }`
	);
	if ( existsSync( descriptive ) ) {
		return descriptive;
	}
	const needle = `${ REPO_ROOT }:`;
	try {
		for ( const entry of readdirSync( home ) ) {
			const composeFile = path.join( home, entry, 'docker-compose.yml' );
			try {
				if ( readFileSync( composeFile, 'utf8' ).includes( needle ) ) {
					return path.join( home, entry );
				}
			} catch {
				// Not a work directory; keep scanning.
			}
		}
	} catch {
		// No wp-env home yet.
	}
	return null;
}

const workDirectory = wpEnvWorkDirectory();
if ( ! workDirectory ) {
	// eslint-disable-next-line no-console
	console.error(
		'[rtc-real-ws-daemon] tests wp-env not found — run `npm run env:tests start` first.'
	);
	process.exit( 1 );
}
const COMPOSE_FILE = path.join( workDirectory, 'docker-compose.yml' );

function wpCli( wpArgs, { allowFailure = false } = {} ) {
	const result = spawnSync(
		'docker',
		[
			'compose',
			'-f',
			COMPOSE_FILE,
			'run',
			'--rm',
			'-T',
			'cli',
			'wp',
			...wpArgs,
		],
		{ encoding: 'utf8' }
	);
	if ( result.status !== 0 && ! allowFailure ) {
		throw new Error(
			`wp ${ wpArgs.join( ' ' ) } failed: ${ result.stderr }`
		);
	}
	return result;
}

function removeContainers( names ) {
	for ( const name of names ) {
		spawnSync( 'docker', [ 'rm', '-f', name ], { stdio: 'ignore' } );
	}
}

// Remember and select the transport (null = option unset).
const previous = ( () => {
	const result = wpCli( [ 'option', 'get', TRANSPORT_OPTION ], {
		allowFailure: true,
	} );
	return result.status === 0 ? result.stdout.trim() || null : null;
} )();
wpCli( [ 'option', 'update', TRANSPORT_OPTION, 'websocket' ] );
// eslint-disable-next-line no-console
console.log(
	`[rtc-real-ws-daemon] transport → websocket (was ${ previous ?? 'unset' })`
);

let restored = false;
function restore() {
	if ( restored ) {
		return;
	}
	restored = true;
	try {
		if ( null === previous ) {
			wpCli( [ 'option', 'delete', TRANSPORT_OPTION ], {
				allowFailure: true,
			} );
		} else {
			wpCli( [ 'option', 'update', TRANSPORT_OPTION, previous ], {
				allowFailure: true,
			} );
		}
	} finally {
		removeContainers( [ CONTAINER ] );
	}
	// eslint-disable-next-line no-console
	console.log( '[rtc-real-ws-daemon] transport restored; daemon removed.' );
}

removeContainers( [ CONTAINER, ...OTHER_PORT_HOLDERS ] );

const daemon = spawn(
	'docker',
	[
		'compose',
		'-f',
		COMPOSE_FILE,
		'run',
		'--rm',
		'--name',
		CONTAINER,
		'-p',
		`${ WS_PORT }:${ WS_PORT }`,
		'cli',
		'wp',
		'collaboration',
		'sync-server',
		'--host=0.0.0.0',
		`--port=${ WS_PORT }`,
	],
	{ stdio: [ 'ignore', 'inherit', 'inherit' ] }
);

daemon.on( 'exit', ( code ) => {
	restore();
	process.exit( code ?? 0 );
} );
for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
	process.on( signal, () => {
		restore();
		daemon.kill( 'SIGTERM' );
		// docker compose run needs a moment; the container rm in restore()
		// is the backstop.
		setTimeout( () => process.exit( 0 ), 2000 );
	} );
}
process.on( 'exit', restore );
