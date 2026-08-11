#!/usr/bin/env node

/**
 * Local RTC transport switcher.
 *
 * Two modes, selected by --mode=<websockets|http>:
 *
 *   websockets: one-command start for the real websocket transport. Ensures
 *   wp-env is running, points the site at the websocket transport, and runs
 *   the `wp collaboration sync-server` PHP daemon inside the wp-env cli
 *   container with its port published to the host (wp-env alone cannot
 *   publish extra container ports, and the daemon must bind 0.0.0.0 for the
 *   published port to reach it — a loopback-bound daemon is unreachable even
 *   through a published port). Open two browser windows on the dev site and
 *   edit the same post to collaborate over the socket.
 *
 *   http: switch the site back to the HTTP polling transport and stop the
 *   daemon (if running).
 *
 * --detach starts the daemon container in the background and exits once it
 * answers its health check, instead of staying attached. That makes the
 * websockets mode usable as a wp-env afterStart lifecycle script — put this
 * in .wp-env.override.json (gitignored, personal) to have the daemon start
 * automatically with `wp-env start`:
 *
 *   {
 *     "lifecycleScripts": {
 *       "afterStart": "node tests/e2e/bin/rtc-dev.mjs --mode=websockets --detach || true"
 *     }
 *   }
 *
 * (The `|| true` keeps a daemon failure — e.g. an unbuilt subtree — from
 * failing `wp-env start` itself; the diagnosis still prints.)
 *
 * The serverless peer-relay test WebSocket provider this script used to
 * manage is only useful to client-merging engines; since the yjs-relay
 * engine was removed, both remaining engines are server-authoritative and
 * the relay demonstrates nothing. The e2e websocket suite manages that
 * fixture itself (tests/e2e/config/rtc-websocket-setup.ts); this script now
 * deactivates the fixture plugin in both modes so it never shadows the real
 * transport.
 */

import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';

const REPO_ROOT = process.cwd();
const TEST_PROVIDER_PLUGIN_SLUG =
	'sync-engines-test-plugins/rtc-websocket-provider';
const TRANSPORT_OPTION = 'gutenberg_sync_engines_transport';
const DAEMON_CONTAINER_NAME = 'wp-sync-ws-daemon';

const DEFAULT_PORT = 8787;
const WS_PORT = Number.parseInt(
	getArg( 'port' ) || process.env.WP_SYNC_WEBSOCKET_PORT || '',
	10
);
// The announced socket URL (ws://<WP_SYNC_WEBSOCKET_HOST>:<WP_SYNC_WEBSOCKET_PORT>)
// comes from wp-config constants, so a non-default port here must be
// matched by WP_SYNC_WEBSOCKET_PORT in .wp-env.json's config.
const PORT = Number.isNaN( WS_PORT ) ? DEFAULT_PORT : WS_PORT;

function getArg( name ) {
	const arg = process.argv.find( ( a ) => a.startsWith( `--${ name }=` ) );
	return arg ? arg.slice( name.length + 3 ) : undefined;
}

function parseMode() {
	const mode = getArg( 'mode' ) || 'websockets';
	if ( mode !== 'websockets' && mode !== 'http' ) {
		throw new Error(
			`Unknown --mode=${ mode }. Expected "websockets" or "http".`
		);
	}
	return mode;
}

function runCommand( command, args, options = {} ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn( command, args, {
			cwd: REPO_ROOT,
			stdio: options.stdio || [ 'ignore', 'pipe', 'pipe' ],
		} );

		let stdout = '';
		let stderr = '';
		if ( child.stdout ) {
			child.stdout.on( 'data', ( chunk ) => {
				stdout += chunk.toString();
			} );
		}
		if ( child.stderr ) {
			child.stderr.on( 'data', ( chunk ) => {
				stderr += chunk.toString();
			} );
		}
		child.on( 'error', reject );
		child.on( 'exit', ( code ) => {
			if ( code === 0 ) {
				resolve( stdout );
				return;
			}
			reject(
				new Error(
					`${ command } ${ args.join(
						' '
					) } exited with code ${ code }\n${ stderr }`
				)
			);
		} );
	} );
}

function runWpCli( wpArgs, { allowFailure = false } = {} ) {
	const promise = runCommand( 'npx', [
		'wp-env',
		'run',
		'cli',
		'wp',
		...wpArgs,
	] );
	if ( ! allowFailure ) {
		return promise;
	}
	return promise.catch( () => undefined );
}

/**
 * The wp-env work directory for this checkout, mirroring wp-env's own
 * naming: the legacy md5( config file path ) directory when it exists,
 * else `wp-env-<project-dir>-<md5 short hash>`.
 *
 * @return {string} Absolute path to the work directory.
 */
function wpEnvWorkDirectory() {
	const home =
		process.env.WP_ENV_HOME || path.join( os.homedir(), '.wp-env' );
	const configFilePath = path.join( REPO_ROOT, '.wp-env.json' );
	const hash = createHash( 'md5' ).update( configFilePath ).digest( 'hex' );

	const legacy = path.join( home, hash );
	if ( existsSync( legacy ) ) {
		return legacy;
	}

	const descriptive = path.join(
		home,
		`wp-env-${ path.basename( REPO_ROOT ) }-${ hash.slice( 0, 8 ) }`
	);
	if ( existsSync( descriptive ) ) {
		return descriptive;
	}

	// Last resort (renamed checkout, older wp-env): scan for the compose
	// file that mounts this checkout as the plugin.
	const needle = `${ REPO_ROOT }:/var/www/html/wp-content/plugins/gutenberg-sync-engines`;
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

	throw new Error(
		`Could not find the wp-env work directory for ${ REPO_ROOT } under ${ home }. Has \`npm run env start\` ever run here?`
	);
}

/**
 * The dev site port, read from the compose file's `${WP_ENV_PORT:-<port>}`
 * mapping (autoPort bakes the chosen port into the default).
 *
 * @param {string} composeFile Path to docker-compose.yml.
 * @return {number} Dev site port.
 */
function devSitePort( composeFile ) {
	try {
		const match = readFileSync( composeFile, 'utf8' ).match(
			/\$\{WP_ENV_PORT:-(\d+)\}:80/
		);
		if ( match ) {
			return Number.parseInt( match[ 1 ], 10 );
		}
	} catch {
		// Fall through to the default.
	}
	return 8888;
}

async function waitForHealth( url, timeoutMs ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		try {
			const response = await fetch( url, {
				signal: AbortSignal.timeout( 2000 ),
			} );
			if ( response.ok ) {
				return true;
			}
		} catch {
			// Not up yet.
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
	}
	return false;
}

/**
 * Whether the wp-env WordPress service is already running (so a slow —
 * and, with autoPort, potentially port-shuffling — `wp-env start` can be
 * skipped).
 *
 * @param {string} composeFile Path to docker-compose.yml.
 * @return {Promise<boolean>} True when the dev site container is running.
 */
async function isEnvRunning( composeFile ) {
	try {
		const services = await runCommand( 'docker', [
			'compose',
			'-f',
			composeFile,
			'ps',
			'--services',
			'--status',
			'running',
		] );
		return services.split( '\n' ).includes( 'wordpress' );
	} catch {
		return false;
	}
}

/**
 * Verifies the pieces the daemon needs and activates the right plugins,
 * failing with a diagnosis instead of wp-cli's opaque "'collaboration' is
 * not a registered wp command".
 *
 * The framework only exists when the vendored Gutenberg subtree is BUILT
 * (an unbuilt gutenberg.php bails before loading lib/, so the plugin goes
 * dormant), and in a git worktree wp-env mounts this plugin twice — under
 * the checkout's directory name (the plugins-list entry) AND as
 * gutenberg-sync-engines (the mapping). Activating a second copy while one
 * is active is a fatal redeclare, and `wp-env start` re-activates the
 * plugins-list copy on EVERY start — so the only steady state that
 * survives a restart is: directory-name copy active, mapping copy
 * inactive. Keep exactly one copy active, preferring that one.
 */
async function ensurePluginsReady() {
	if ( ! existsSync( path.join( REPO_ROOT, 'gutenberg/build' ) ) ) {
		throw new Error(
			'The vendored Gutenberg subtree is not built, so the collaboration framework cannot load.\n' +
				'Run: cd gutenberg && npm install --ignore-scripts && npm run build'
		);
	}
	if ( ! existsSync( path.join( REPO_ROOT, 'build/sync-engines.js' ) ) ) {
		throw new Error(
			"The plugin's client bundle is not built, so the editor has no engines or transports.\n" +
				'Run: npm run build'
		);
	}

	process.stdout.write( 'Activating plugins... ' );
	await runWpCli( [ 'plugin', 'activate', 'gutenberg' ] );

	const plugins = JSON.parse(
		await runWpCli( [
			'plugin',
			'list',
			'--fields=name,status,file',
			'--format=json',
		] )
	);
	const copies = plugins.filter( ( plugin ) =>
		plugin.file.endsWith( '/gutenberg-sync-engines.php' )
	);
	const keep =
		copies.find( ( p ) => p.name === path.basename( REPO_ROOT ) ) ||
		copies.find( ( p ) => p.name === 'gutenberg-sync-engines' ) ||
		copies[ 0 ];
	if ( ! keep ) {
		throw new Error(
			'The gutenberg-sync-engines plugin is not installed in this wp-env.'
		);
	}
	for ( const plugin of copies ) {
		if ( plugin !== keep && 'active' === plugin.status ) {
			await runWpCli( [ 'plugin', 'deactivate', plugin.name ] );
		}
	}
	if ( 'active' !== keep.status ) {
		await runWpCli( [ 'plugin', 'activate', keep.name ] );
	}
	process.stdout.write( 'done\n' );

	try {
		await runWpCli( [ 'cli', 'has-command', 'collaboration sync-server' ] );
	} catch {
		throw new Error(
			'The `wp collaboration sync-server` command is still not registered even with the plugins active.\n' +
				'Check `wp plugin list` and PHP notices on the dev site.'
		);
	}
}

async function runWebSocketsMode() {
	let workDirectory = null;
	try {
		workDirectory = wpEnvWorkDirectory();
	} catch {
		// Never started here; wp-env start below will create it.
	}

	if (
		workDirectory &&
		( await isEnvRunning(
			path.join( workDirectory, 'docker-compose.yml' )
		) )
	) {
		process.stdout.write( 'wp-env is already running.\n' );
	} else {
		process.stdout.write( 'Starting wp-env... ' );
		await runCommand( 'npx', [ 'wp-env', 'start' ] );
		process.stdout.write( 'done\n' );
		workDirectory = wpEnvWorkDirectory();
	}

	await ensurePluginsReady();

	process.stdout.write(
		'Deactivating the e2e test provider plugin (if active)... '
	);
	await runWpCli( [ 'plugin', 'deactivate', TEST_PROVIDER_PLUGIN_SLUG ], {
		allowFailure: true,
	} );
	process.stdout.write( 'done\n' );

	process.stdout.write( 'Selecting the websocket transport... ' );
	await runWpCli( [ 'option', 'update', 'wp_collaboration_enabled', '1' ] );
	await runWpCli( [ 'option', 'update', TRANSPORT_OPTION, 'websocket' ] );
	process.stdout.write( 'done\n' );

	// A previous daemon (crashed terminal, orphaned run) would still hold
	// the published port; replace it.
	await runCommand( 'docker', [ 'rm', '-f', DAEMON_CONTAINER_NAME ], {
		stdio: 'ignore',
	} ).catch( () => undefined );

	const composeFile = path.join( workDirectory, 'docker-compose.yml' );
	const sitePort = devSitePort( composeFile );
	const detach = process.argv.includes( '--detach' );

	const daemon = spawn(
		'docker',
		[
			'compose',
			'-f',
			composeFile,
			'run',
			'--rm',
			...( detach ? [ '-d' ] : [] ),
			'--name',
			DAEMON_CONTAINER_NAME,
			'-p',
			`${ PORT }:${ PORT }`,
			'cli',
			'wp',
			'collaboration',
			'sync-server',
			'--host=0.0.0.0',
			`--port=${ PORT }`,
		],
		{ stdio: 'inherit' }
	);

	if ( detach ) {
		const [ runExitCode ] = await once( daemon, 'exit' );
		if ( 0 !== runExitCode ) {
			throw new Error( 'Failed to start the daemon container.' );
		}
		const healthy = await waitForHealth(
			`http://localhost:${ PORT }/health`,
			30000
		);
		if ( ! healthy ) {
			throw new Error(
				`The daemon container started but never answered http://localhost:${ PORT }/health; check \`docker logs ${ DAEMON_CONTAINER_NAME }\`.`
			);
		}
		process.stdout.write(
			`RTC ready on the websocket transport (daemon detached, reachable at ws://localhost:${ PORT }).\n` +
				`Open two windows at http://localhost:${ sitePort }/wp-admin and edit the same post.\n` +
				'Stop the daemon with `npm run rtc:http` (or `docker rm -f ' +
				DAEMON_CONTAINER_NAME +
				'`).\n'
		);
		return;
	}

	// On a TTY, Ctrl+C reaches the attached docker client directly and stops
	// the container. This handler covers non-TTY kills (a crashed terminal,
	// `kill <pid>`): killing the docker CLIENT alone detaches and leaves the
	// container running, so remove the container explicitly.
	let shuttingDown = false;
	const shutdown = () => {
		if ( shuttingDown ) {
			return;
		}
		shuttingDown = true;
		spawnSync( 'docker', [ 'rm', '-f', DAEMON_CONTAINER_NAME ], {
			stdio: 'ignore',
		} );
		if ( ! daemon.killed ) {
			daemon.kill( 'SIGTERM' );
		}
	};
	process.on( 'SIGINT', shutdown );
	process.on( 'SIGTERM', shutdown );

	waitForHealth( `http://localhost:${ PORT }/health`, 30000 ).then(
		( healthy ) => {
			if ( healthy ) {
				process.stdout.write(
					`\nRTC ready on the websocket transport (daemon reachable at ws://localhost:${ PORT }).\n` +
						`Open two windows at http://localhost:${ sitePort }/wp-admin and edit the same post.\n` +
						'Press Ctrl+C to stop the daemon; `npm run rtc:http` stops it AND switches the site back to HTTP polling.\n\n'
				);
			} else if ( daemon.exitCode === null ) {
				process.stdout.write(
					`\nWARNING: the daemon did not answer http://localhost:${ PORT }/health within 30s.\n` +
						'The browser cannot reach it; check the daemon output above.\n\n'
				);
			}
		}
	);

	const [ code ] = await once( daemon, 'exit' );
	process.exit( code ?? 0 );
}

async function runHttpMode() {
	process.stdout.write(
		'Deactivating the e2e test provider plugin (if active)... '
	);
	await runWpCli( [ 'plugin', 'deactivate', TEST_PROVIDER_PLUGIN_SLUG ], {
		allowFailure: true,
	} );
	process.stdout.write( 'done\n' );

	process.stdout.write( 'Selecting the http-polling transport... ' );
	await runWpCli( [ 'option', 'update', TRANSPORT_OPTION, 'http-polling' ] );
	process.stdout.write( 'done\n' );

	process.stdout.write( 'Stopping the websocket daemon (if running)... ' );
	spawnSync( 'docker', [ 'rm', '-f', DAEMON_CONTAINER_NAME ], {
		stdio: 'ignore',
	} );
	process.stdout.write( 'done\n' );

	process.stdout.write( '\nRTC switched to HTTP polling.\n' );
}

async function main() {
	const mode = parseMode();
	if ( mode === 'websockets' ) {
		await runWebSocketsMode();
		return;
	}
	await runHttpMode();
}

main().catch( ( error ) => {
	process.stderr.write( `${ error.message || error }\n` );
	process.exit( 1 );
} );
