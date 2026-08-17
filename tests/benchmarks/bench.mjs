/**
 * One-command benchmark runner: the whole engine-decision matrix, or an
 * invariant-certification sweep, from a single invocation.
 *
 *   npm run bench                        # every engine x the decision matrix
 *   npm run bench -- engines=de-rtc scenarios=editorial-session
 *   npm run bench -- certify=10          # invariant sweep across 10 seeds
 *
 * The matrix runs each engine over four complementary scenarios
 * (mixed-newsroom for steady concurrent editing, structural-churn for
 * block-structure conflict policy, remove-contention for the
 * edit-vs-remove conflict class, editorial-session for wall-clock
 * session behavior + the hosting cost card), writes every JSON report to
 * bench-results/, and renders the per-scenario comparison tables. The run
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

const ENV_CONFIG = process.env.BENCH_WPENV_CONFIG ?? '.wp-env.tests.json';
const ENV_CWD = `wp-content/plugins/${ path.basename( process.cwd() ) }`;
const OUT_DIR = args.out ?? 'bench-results';
const SEED = Number( args.seed ?? 42 );
const ENGINES = ( args.engines ?? 'intent-log,yjs-server,de-rtc' )
	.split( ',' )
	.filter( Boolean );

// The decision matrix: steady concurrency, structural conflict policy,
// edit-vs-remove conflict policy, and a wall-clock session (which also
// emits the hosting cost card).
const MATRIX = {
	'mixed-newsroom': {
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
// simultaneously, which spawnSync cannot do.
function wpAsync( ...wpArgs ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn(
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
wp( 'wp', 'plugin', 'activate', 'gutenberg', 'gutenberg-sync-engines' );

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
	const certifyScenarios = [
		'mixed-newsroom',
		'structural-churn',
		'remove-contention',
	];
	const config = {
		rounds: 40,
		clients: 3,
		paragraphs: 4,
		reps: 1,
		warmup: 0,
	};
	let edits = 0;
	const dispositions = { applied: 0, escalated: 0, voided: 0 };

	for ( const scenario of certifyScenarios ) {
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
