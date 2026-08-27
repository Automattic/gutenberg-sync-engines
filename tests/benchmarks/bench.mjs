/**
 * One-command benchmark runner — the single front door to every
 * benchmark in this repo, selected with `suite=`:
 *
 *   npm run bench                        # DEFAULT: the host cost report —
 *                                        # what the plugin adds to a server,
 *                                        # measured against the same site
 *                                        # with the plugin deactivated
 *                                        # (tests/benchmarks/host/)
 *   npm run bench -- engine=de-rtc windows=3     # host report, targeted
 *   npm run bench -- suite=engines       # engine-decision matrix (below)
 *   npm run bench -- suite=transport transport=http-polling trials=30
 *   npm run bench -- suite=soak engine=de-rtc soak=3600
 *   npm run bench -- suite=replay my-session.json speed=1
 *
 * Every suite other than `engines` forwards the remaining arguments to
 * its own script (see each script's header for its argument list). The
 * engines-suite arguments `scenarios=`, `certify=`, and `concurrency=`
 * keep working without `suite=engines` — they imply it. `engines=` is
 * the HOST report's list (one table per engine); it selects the
 * engines suite only alongside one of those three.
 *
 * The engines suite: the whole engine-decision matrix, or an
 * invariant-certification sweep, from a single invocation.
 *
 *   npm run bench -- suite=engines       # every engine x the decision matrix
 *   npm run bench -- engines=de-rtc scenarios=editorial-session
 *   npm run bench -- certify=10          # invariant sweep across 10 seeds
 *
 * The matrix runs each engine over six complementary scenarios
 * (mixed-newsroom for steady concurrent editing, laggy-newsroom for
 * deep-lag settlement — stale-base tail offsets and the floor-reset
 * retry lane only bite at depth — structural-churn for block-structure
 * conflict policy, remove-contention for the edit-vs-remove conflict
 * class, field-sync for entity-field register traffic — scalar
 * properties, taxonomy term sets, post meta — and its contention policy,
 * editorial-session for wall-clock session behavior + the hosting cost
 * card), writes every JSON report to bench-results/, and renders the
 * per-scenario comparison tables. The run
 * FAILS (nonzero exit) if any engine loses work or fails convergence —
 * the invariants are gates, not observations.
 *
 * Certify mode re-runs the quality oracles across N seeds per engine and
 * scenario, reporting the total number of edits certified with zero lost
 * work — the "no edit is ever silently dropped" claim, continuously
 * enforceable in CI.
 *
 * Requires the tests wp-env running (npm run env:tests start) with the
 * subtree built. Plugins are activated automatically. Override the wp-env
 * config with BENCH_WPENV_CONFIG (default .wp-env.tests.json).
 */

import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(
	process.argv
		.slice( 2 )
		.filter( ( a ) => a.includes( '=' ) )
		.map( ( a ) => a.split( /=(.*)/s ).slice( 0, 2 ) )
);

const HELP = `npm run bench -- [suite=<name>] [key=value …]

Suites (suite=; default: host):
  host       The host cost report: what the plugin adds to a server, as one
             baseline/sync/delta/delta-% table per engine, measured with real
             browser sessions against the same site with the plugin
             deactivated. Arguments:
               engines=    comma list of engines to measure, one table each
                           (intent-log | yjs-server | de-rtc | current;
                           default: the site's current engine; engine= is an
                           alias for a single one)
               transport=  http-polling | http-long-polling | websocket
                           (default: the site's current transport)
               windows=    collaborator windows per engine phase (default 2)
               edit=       editing seconds per phase (default 120, min 30)
               idle=       idle seconds per phase (default 120; 0 skips)
               poll=       override the HTTP short-polling interval for the
                           run, in seconds 1-25 (0 = the plugin's defaults;
                           default: leave the site's setting alone)
               metrics=    comma list of table rows to print:
                           requests,traffic,cpu,workers,memory (default all)
               json=       write full results as JSON to this path
               headed=1    visible browser (debugging)
  engines    The engine-decision matrix and invariant sweeps (in-process,
             wp-env cli). Arguments: engines=, scenarios=, seed=, out=,
             certify=N (invariant sweep across N seeds), concurrency=N
             (multi-process latency probe; requests=, paragraphs=).
             scenarios=/certify=/concurrency= imply suite=engines.
  transport  Two-browser edit-to-visible latency + wire traffic for one
             transport (tests/benchmarks/transport/README.md).
  soak       N-window hour-scale co-editing soak (same README).
  replay     Replay a captured session as HTTP load
             (tests/benchmarks/replay/README.md).

Arguments after suite= are forwarded to the suite's script; each script's
header documents its full list. Environment: WP_BASE_URL (default
http://localhost:8889), WP_USERNAME/WP_PASSWORD.

Examples:
  npm run bench
  npm run bench -- engines=intent-log,de-rtc windows=3 poll=2
  npm run bench -- suite=engines scenarios=editorial-session
  npm run bench -- certify=10
`;

if (
	process.argv
		.slice( 2 )
		.some( ( token ) => [ '--help', '-h', 'help' ].includes( token ) )
) {
	process.stdout.write( HELP );
	process.exit( 0 );
}

// ---------------------------------------------------------------------
// Suite dispatch: this file is the single benchmark entry point. The
// default suite is the host cost report; the engine matrix and the
// browser-driven lanes are selected with suite= (legacy engines-suite
// arguments imply suite=engines so documented invocations keep working).
// ---------------------------------------------------------------------
const SUITE_SCRIPTS = {
	host: 'tests/benchmarks/host/host-benchmark.mjs',
	transport: 'tests/benchmarks/transport/benchmark-transport.mjs',
	soak: 'tests/benchmarks/transport/soak-transport.mjs',
	replay: 'tests/benchmarks/replay/replay.mjs',
};
// engines= deliberately does NOT imply the engines suite: it is the
// host report's per-engine-table list. scenarios=/certify=/concurrency=
// are unambiguous engines-suite modes and keep working without suite=.
const impliesEngines = args.certify || args.concurrency || args.scenarios;
const SUITE = String( args.suite ?? ( impliesEngines ? 'engines' : 'host' ) );
// Say which suite is running and why, so a surprising suite selection is
// visible in the first line rather than minutes into the wrong run.
const suiteReason = args.suite
	? `suite=${ SUITE }`
	: `${
			impliesEngines
				? 'implied by scenarios=/certify=/concurrency='
				: 'default'
	  } — npm run bench -- --help for arguments`;
console.log( `suite: ${ SUITE } (${ suiteReason })` );
if ( 'engines' !== SUITE ) {
	const script = SUITE_SCRIPTS[ SUITE ];
	if ( ! script ) {
		console.error(
			`unknown suite "${ SUITE }" — known: host (default), engines, ${ Object.keys(
				SUITE_SCRIPTS
			)
				.filter( ( name ) => 'host' !== name )
				.join( ', ' ) }`
		);
		process.exit( 1 );
	}
	const forwarded = process.argv
		.slice( 2 )
		.filter( ( token ) => ! token.startsWith( 'suite=' ) );
	const child = spawnSync( 'node', [ script, ...forwarded ], {
		stdio: 'inherit',
	} );
	process.exit( child.status ?? 1 );
}

const ENV_CONFIG = process.env.BENCH_WPENV_CONFIG ?? '.wp-env.tests.json';
const ENV_CWD = `wp-content/plugins/${ path.basename( process.cwd() ) }`;
const OUT_DIR = args.out ?? 'bench-results';
const SEED = Number( args.seed ?? 42 );
const ENGINES = ( args.engines ?? 'intent-log,yjs-server,de-rtc' )
	.split( ',' )
	.filter( Boolean );

// The decision matrix: steady concurrency, deep-lag settlement,
// structural conflict policy, edit-vs-remove conflict policy, field-sync
// register traffic, and a wall-clock session (which also emits the
// hosting cost card).
const MATRIX = {
	'mixed-newsroom': {
		rounds: 150,
		clients: 4,
		paragraphs: 8,
		reps: 3,
		warmup: 1,
	},
	// Deep-lag settlement: the laggy client's stale-base tail offsets and
	// the floor-reset retry lane only bite at depth (the first pre-fix
	// convergence failure appeared past round 85), so this runs at full
	// mixed-newsroom size, not the short-scenario size.
	'laggy-newsroom': {
		rounds: 150,
		clients: 4,
		paragraphs: 8,
		reps: 3,
		warmup: 1,
	},
	'structural-churn': {
		rounds: 60,
		clients: 4,
		paragraphs: 4,
		reps: 3,
		warmup: 1,
	},
	'remove-contention': {
		rounds: 60,
		clients: 4,
		paragraphs: 4,
		reps: 3,
		warmup: 1,
	},
	'field-sync': {
		rounds: 60,
		clients: 4,
		paragraphs: 4,
		reps: 3,
		warmup: 1,
	},
	// DE-RTC's native cadence applied to every engine: two
	// minutes of staggered ~10s save beats + 10s sync reads. The fair
	// measurement for the save-centric design; compare its byte and
	// escalation shapes against the per-second scenarios above.
	'save-sync-session': {
		rounds: 120,
		clients: 3,
		paragraphs: 6,
		reps: 3,
		warmup: 1,
	},
	'editorial-session': {
		rounds: 600,
		clients: 3,
		paragraphs: 6,
		reps: 1,
		warmup: 0,
	},
};
const SCENARIOS = ( args.scenarios ?? Object.keys( MATRIX ).join( ',' ) )
	.split( ',' )
	.filter( Boolean );

// Async variant for the concurrency mode: N workers must be IN FLIGHT
// simultaneously, which spawnSync cannot do. Deliberately NOT `wp-env
// run`: every wp-env invocation read-modify-writes the shared
// ~/.wp-env/<env>/wp-env-cache.json, and N concurrent invocations race
// on it — a torn read drops the saved `runtime` key and every LATER
// wp-env command dies with "Environment not initialized" (bitten
// 2026-08-18). The workers exec straight into the already-running cli
// container instead; wp-env is only used serially, before and after.
let cachedCliContainer = null;
function cliContainer() {
	if ( cachedCliContainer ) {
		return cachedCliContainer;
	}
	const names = spawnSync( 'docker', [ 'ps', '--format', '{{.Names}}' ], {
		encoding: 'utf8',
	} )
		.stdout.split( '\n' )
		.filter( Boolean );
	const project = path.basename( process.cwd() );
	const suffix = ENV_CONFIG.includes( 'tests' ) ? '-tests-' : '-';
	cachedCliContainer = names.find(
		( name ) =>
			name.startsWith( `wp-env-${ project }${ suffix }` ) &&
			name.endsWith( '-cli-1' ) &&
			( '-tests-' === suffix || ! name.includes( '-tests-' ) )
	);
	if ( ! cachedCliContainer ) {
		throw new Error(
			`could not find the running wp-env cli container for ${ project }`
		);
	}
	return cachedCliContainer;
}

function wpAsync( ...wpArgs ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn(
			'docker',
			[
				'exec',
				'-w',
				`/var/www/html/${ ENV_CWD }`,
				cliContainer(),
				...wpArgs,
			],
			{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
		);
		let out = '';
		child.stdout.on( 'data', ( chunk ) => ( out += chunk ) );
		child.stderr.on( 'data', ( chunk ) => ( out += chunk ) );
		child.on( 'close', ( code ) =>
			0 === code
				? resolve( out )
				: reject(
						new Error( `exit ${ code }: ${ out.slice( -400 ) }` )
				  )
		);
	} );
}

function wp( ...wpArgs ) {
	const result = spawnSync(
		'npx',
		[
			'wp-env',
			'--config',
			ENV_CONFIG,
			'run',
			'cli',
			`--env-cwd=${ ENV_CWD }`,
			...wpArgs,
		],
		{ encoding: 'utf8' }
	);
	if ( 0 !== result.status ) {
		process.stderr.write( result.stdout ?? '' );
		process.stderr.write( result.stderr ?? '' );
		throw new Error( `wp-env command failed: wp ${ wpArgs.join( ' ' ) }` );
	}
	return result.stdout;
}

// Best-effort variant: null on failure instead of throwing (for cleanup
// commands whose target may legitimately be absent or already inactive).
function wpTry( ...wpArgs ) {
	try {
		return wp( ...wpArgs );
	} catch {
		return null;
	}
}

// Returns the parsed report, or null when the run CRASHED — a fatal
// engine error is itself a certification result (recorded as a
// violation), and one crash must not abort the rest of the sweep.
function runBenchmark( engine, scenario, config, seed, jsonName, violations ) {
	const tokens = [
		`engine=${ engine }`,
		`scenario=${ scenario }`,
		`rounds=${ config.rounds }`,
		`clients=${ config.clients }`,
		`paragraphs=${ config.paragraphs }`,
		`reps=${ config.reps }`,
		`warmup=${ config.warmup }`,
		`seed=${ seed }`,
		`json=${ OUT_DIR }/${ jsonName }`,
	];
	try {
		fs.rmSync( path.join( OUT_DIR, jsonName ), { force: true } );
		wp( 'wp', 'eval-file', 'tests/benchmarks/benchmark.php', ...tokens );
		return JSON.parse(
			fs.readFileSync( path.join( OUT_DIR, jsonName ), 'utf8' )
		);
	} catch ( error ) {
		violations.push(
			`${ engine }/${ scenario }/seed=${ seed }: run CRASHED (${ error.message })`
		);
		return null;
	}
}

function checkInvariants( report, label, violations ) {
	if ( report.quality.lost_work > 0 ) {
		violations.push(
			`${ label }: ${ report.quality.lost_work } edit(s) LOST`
		);
	}
	if ( false === report.quality.converged ) {
		violations.push(
			`${ label }: convergence FAILED (${ (
				report.quality.convergence_failures ?? []
			)
				.map( ( f ) => f.check )
				.join( ', ' ) })`
		);
	}
}

fs.mkdirSync( OUT_DIR, { recursive: true } );
console.log( `env: ${ ENV_CONFIG } (${ ENV_CWD }); activating plugins…` );
/*
 * The framework is BUNDLED: the plugin loads the vendored Gutenberg subtree
 * itself, so only the plugin needs activating — and only its DIRECTORY-NAME
 * copy. wp-env mounts the plugin twice (the directory name via `plugins`,
 * plus the fixed `gutenberg-sync-engines` mapping); in a worktree those are
 * two distinct plugin entries and activating both is a fatal redeclaration,
 * so activate the directory-name copy (the arrangement `wp-env start`
 * re-creates) and make sure the mapped copy is off. A stale `gutenberg`
 * stub activation (the precedence e2e fixture, left behind by an aborted
 * run) blocks the bundled framework, so switch it off too.
 */
const PLUGIN_DIR = path.basename( process.cwd() );
wp( 'wp', 'plugin', 'activate', PLUGIN_DIR );
if ( 'gutenberg-sync-engines' !== PLUGIN_DIR ) {
	wpTry( 'wp', 'plugin', 'deactivate', 'gutenberg-sync-engines' );
}
const gutenbergVersion = wpTry(
	'wp',
	'plugin',
	'get',
	'gutenberg',
	'--field=version'
);
if ( gutenbergVersion && gutenbergVersion.includes( 'stub' ) ) {
	wpTry( 'wp', 'plugin', 'deactivate', 'gutenberg' );
}

const violations = [];
const started = Date.now();

function percentile( sorted, fraction ) {
	return sorted[ Math.floor( fraction * ( sorted.length - 1 ) ) ];
}

// Multi-process concurrency measurement (opt-in, concurrency=N): N worker
// processes hammer the SAME room through the real postmeta storage
// simultaneously, so latency includes genuine lock waits, 503 timeouts,
// and MySQL under concurrent writers — everything the single-process
// harness structurally cannot see. A 1-worker pass on a fresh room is the
// uncontended baseline; the delta IS the queueing cost.
async function runConcurrencyPhase( engine, workers, requests, paragraphs ) {
	const setup = wp(
		'wp',
		'eval-file',
		'tests/benchmarks/concurrency-setup.php',
		`engine=${ engine }`,
		`paragraphs=${ paragraphs }`
	);
	const postId = Number( ( setup.match( /BENCH_POST (\d+)/ ) ?? [] )[ 1 ] );
	if ( ! postId ) {
		throw new Error(
			`setup failed for ${ engine }: ${ setup.slice( -200 ) }`
		);
	}

	const phaseStarted = Date.now();
	const outputs = await Promise.all(
		Array.from( { length: workers }, ( _, worker ) =>
			wpAsync(
				'wp',
				'eval-file',
				'tests/benchmarks/concurrency-worker.php',
				`engine=${ engine }`,
				`post=${ postId }`,
				`worker=${ worker }`,
				`workers=${ workers }`,
				`requests=${ requests }`,
				`paragraphs=${ paragraphs }`
			)
		)
	);
	const wallMs = Date.now() - phaseStarted;
	wp(
		'wp',
		'eval-file',
		'tests/benchmarks/concurrency-setup.php',
		`teardown=${ postId }`
	);

	const latencies = [];
	const errors = {};
	const voidReasons = {};
	const dispositions = { applied: 0, escalated: 0, voided: 0 };
	let nonBenignVoids = 0;
	for ( const out of outputs ) {
		const line = out.match( /BENCH_WORKER (\{.*\})/ );
		if ( ! line ) {
			throw new Error(
				`worker emitted no result for ${ engine }: ${ out.slice(
					-200
				) }`
			);
		}
		const parsed = JSON.parse( line[ 1 ] );
		latencies.push( ...parsed.latency_us );
		for ( const [ code, count ] of Object.entries( parsed.errors ) ) {
			errors[ code ] = ( errors[ code ] ?? 0 ) + count;
		}
		for ( const [ reason, count ] of Object.entries(
			parsed.void_reasons ?? {}
		) ) {
			voidReasons[ reason ] = ( voidReasons[ reason ] ?? 0 ) + count;
		}
		nonBenignVoids += parsed.non_benign_voids ?? 0;
		for ( const key of Object.keys( dispositions ) ) {
			dispositions[ key ] += parsed.dispositions[ key ];
		}
	}
	latencies.sort( ( a, b ) => a - b );
	return {
		workers,
		requests: latencies.length,
		wall_ms: wallMs,
		p50_ms: percentile( latencies, 0.5 ) / 1000,
		p90_ms: percentile( latencies, 0.9 ) / 1000,
		p99_ms: percentile( latencies, 0.99 ) / 1000,
		max_ms: latencies[ latencies.length - 1 ] / 1000,
		errors,
		void_reasons: voidReasons,
		non_benign_voids: nonBenignVoids,
		dispositions,
	};
}

async function runConcurrencyMode() {
	const workers = Math.max( 2, Number( args.concurrency ) );
	const requests = Number( args.requests ?? 40 );
	const paragraphs = Number( args.paragraphs ?? 4 );
	console.log(
		`\nmulti-process concurrency: ${ workers } workers x ${ requests } requests, same room, REAL postmeta storage` +
			`\n(latency includes genuine lock waits and DB I/O — not comparable with the in-memory single-process numbers)`
	);

	for ( const engine of ENGINES ) {
		try {
			const baseline = await runConcurrencyPhase(
				engine,
				1,
				requests,
				paragraphs
			);
			const loaded = await runConcurrencyPhase(
				engine,
				workers,
				requests,
				paragraphs
			);
			const voidList = Object.entries( loaded.void_reasons )
				.map( ( [ reason, count ] ) => `${ reason }x${ count }` )
				.join( ', ' );
			const errorList = Object.entries( loaded.errors )
				.map( ( [ code, count ] ) => `${ code }x${ count }` )
				.join( ', ' );
			console.log( `\n  ${ engine }:` );
			console.log(
				`    1 worker : p50 ${ baseline.p50_ms.toFixed(
					2
				) } ms, p99 ${ baseline.p99_ms.toFixed(
					2
				) } ms (uncontended baseline)`
			);
			console.log(
				`    ${ workers } workers: p50 ${ loaded.p50_ms.toFixed(
					2
				) } ms, p99 ${ loaded.p99_ms.toFixed(
					2
				) } ms, max ${ loaded.max_ms.toFixed( 2 ) } ms` +
					` — measured queueing +${ (
						loaded.p50_ms - baseline.p50_ms
					).toFixed( 2 ) } ms p50 / +${ (
						loaded.p99_ms - baseline.p99_ms
					).toFixed( 2 ) } ms p99`
			);
			console.log(
				`    ${ loaded.requests } requests in ${ (
					loaded.wall_ms / 1000
				).toFixed( 1 ) } s wall; ` +
					`${ loaded.dispositions.applied } applied / ${ loaded.dispositions.escalated } escalated / ${ loaded.dispositions.voided } voided` +
					`${ voidList ? ` (${ voidList })` : '' }; errors: ${
						errorList || 'none'
					}`
			);
			const nonBenign =
				baseline.non_benign_voids + loaded.non_benign_voids;
			if ( nonBenign > 0 ) {
				violations.push(
					`${ engine }/concurrency: ${ nonBenign } non-benign void(s); the client protocol cannot absorb these, so real work was lost`
				);
			}
			fs.writeFileSync(
				path.join( OUT_DIR, `concurrency--${ engine }.json` ),
				JSON.stringify( { engine, baseline, loaded }, null, '\t' )
			);
		} catch ( error ) {
			violations.push( `${ engine }/concurrency: ${ error.message }` );
			console.log(
				`  ${ engine }: FAILED (${ error.message.split( '\n' )[ 0 ] })`
			);
		}
	}
}

if ( args.concurrency ) {
	await runConcurrencyMode();
} else if ( args.certify ) {
	// Invariant sweep: many seeds, every engine, the adversarial
	// scenarios — cheap per run, additive as evidence.
	const seeds = Math.max( 1, Number( args.certify ) );
	const shortConfig = {
		rounds: 40,
		clients: 3,
		paragraphs: 4,
		reps: 1,
		warmup: 0,
	};
	/*
	 * The short config keeps the adversarial scenarios cheap per seed. The
	 * two SAVE-lane scenarios need their matrix depth to mean anything:
	 * editorial-session's first mid-session autosave fires at round 60 and
	 * the pre-#70 room wipe needed enough versions before it for genesis to
	 * age out of the snapshot window (green at 120 rounds even before the
	 * fix), and save-sync-session's pre-#70 rollback needed a compaction
	 * checkpoint (~100 stored rows, so ~120 rounds). Both configs below
	 * reproduce those failures on the pre-fix engine at seed 42 — do not
	 * shrink them without re-verifying that.
	 */
	const certifyScenarios = [
		{ scenario: 'mixed-newsroom', config: shortConfig },
		{ scenario: 'structural-churn', config: shortConfig },
		{ scenario: 'remove-contention', config: shortConfig },
		{ scenario: 'field-sync', config: shortConfig },
		{
			scenario: 'save-sync-session',
			config: {
				rounds: 120,
				clients: 3,
				paragraphs: 6,
				reps: 1,
				warmup: 0,
			},
		},
		{
			scenario: 'editorial-session',
			config: {
				rounds: 600,
				clients: 3,
				paragraphs: 6,
				reps: 1,
				warmup: 0,
			},
		},
	];
	let edits = 0;
	const dispositions = { applied: 0, escalated: 0, voided: 0 };

	for ( const { scenario, config } of certifyScenarios ) {
		for ( const engine of ENGINES ) {
			for ( let i = 0; i < seeds; i++ ) {
				const seed = SEED + i;
				const label = `${ engine }/${ scenario }/seed=${ seed }`;
				const report = runBenchmark(
					engine,
					scenario,
					config,
					seed,
					'certify.json',
					violations
				);
				if ( null === report ) {
					console.log( `  ${ label }: CRASHED` );
					continue;
				}
				checkInvariants( report, label, violations );
				edits += report.requests;
				for ( const key of Object.keys( dispositions ) ) {
					dispositions[ key ] += report.quality.dispositions[ key ];
				}
				console.log(
					`  ${ label }: ${ report.requests } requests, ` +
						`${ report.quality.dispositions.applied } applied / ` +
						`${ report.quality.dispositions.escalated } escalated / ` +
						`${ report.quality.dispositions.voided } voided, ` +
						`lost ${ report.quality.lost_work }, converged ${
							report.quality.converged ? 'yes' : 'NO'
						}`
				);
			}
		}
	}

	console.log( '' );
	console.log(
		`certified ${ edits } edits across ${ seeds } seed(s) x ${ ENGINES.length } engine(s) x ${ certifyScenarios.length } scenarios: ` +
			`${ dispositions.applied } applied, ${ dispositions.escalated } escalated for review, ` +
			`${ dispositions.voided } voided — ${
				violations.length
					? 'INVARIANTS VIOLATED'
					: '0 lost, all convergence-verified'
			}`
	);
} else {
	for ( const scenario of SCENARIOS ) {
		const config = MATRIX[ scenario ] ?? {
			rounds: 100,
			clients: 3,
			paragraphs: 4,
			reps: 2,
			warmup: 1,
		};
		const jsons = [];
		console.log(
			`\n=== ${ scenario } (rounds=${ config.rounds } clients=${ config.clients } seed=${ SEED }) ===`
		);

		for ( const engine of ENGINES ) {
			const jsonName = `${ engine }--${ scenario }.json`;
			process.stdout.write( `  ${ engine }… ` );
			const report = runBenchmark(
				engine,
				scenario,
				config,
				SEED,
				jsonName,
				violations
			);
			if ( null === report ) {
				console.log( 'CRASHED' );
				continue;
			}
			jsons.push( path.join( OUT_DIR, jsonName ) );
			checkInvariants( report, `${ engine }/${ scenario }`, violations );
			let convergedLabel = 'n/a';
			if ( null !== report.quality.converged ) {
				convergedLabel = report.quality.converged ? 'yes' : 'NO';
			}
			console.log(
				`service mean ${ report.service_us.mean } ms, ` +
					`${ report.quality.dispositions.applied } applied / ${ report.quality.dispositions.escalated } escalated, ` +
					`lost ${ report.quality.lost_work }, converged ${ convergedLabel }`
			);
			if ( report.hosting ) {
				const h = report.hosting;
				console.log(
					`    hosting: ${ h.requests_per_client_hour } req / ${ h.cpu_seconds_per_client_hour } CPU-s / ` +
						`${ h.wire_mb_per_client_hour } MB per user-hour; ` +
						`${ Math.round(
							h.storage_bytes_at_rest / 1024
						) } KB at rest, ` +
						`${ Math.round(
							h.join_payload_bytes / 1024
						) } KB to join`
				);
			}
		}

		const compare = spawnSync(
			'node',
			[ 'tests/benchmarks/compare.js', ...jsons ],
			{ encoding: 'utf8' }
		);
		console.log( ( compare.stdout ?? '' ).trimEnd() );
	}
}

console.log(
	`\ntotal wall time: ${ Math.round(
		( Date.now() - started ) / 1000
	) } s; reports in ${ OUT_DIR }/`
);
if ( violations.length ) {
	console.error( '\nINVARIANT VIOLATIONS:' );
	for ( const violation of violations ) {
		console.error( `  - ${ violation }` );
	}
	process.exit( 1 );
}
console.log(
	'invariants held: zero lost work, every observable engine convergence-verified.'
);
