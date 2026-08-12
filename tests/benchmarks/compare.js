/**
 * Side-by-side comparison of benchmark JSON outputs.
 *
 *   node tests/benchmarks/compare.js a.json b.json [c.json …] [md=1]
 *
 * Accepts any mix of ENGINE benchmark outputs (`wp eval-file
 * tests/benchmarks/benchmark.php … json=…`) and TRANSPORT benchmark outputs
 * (`node tests/benchmarks/transport/benchmark-transport.mjs … json=…`) —
 * the shape is detected per file and each kind renders as its own table,
 * one column per run.
 *
 * Warns when engine runs used different workloads (scenario/seed/rounds/
 * clients/paragraphs) or different environments — numbers from different
 * workloads or machines are not comparable and the warning says so rather
 * than silently lining them up.
 *
 * `md=1` emits GitHub-flavored Markdown tables (for sharing) instead of the
 * aligned console layout.
 */

const fs = require( 'node:fs' );

const args = process.argv.slice( 2 );
const files = args.filter( ( a ) => ! a.includes( '=' ) );
const flags = Object.fromEntries(
	args
		.filter( ( a ) => a.includes( '=' ) )
		.map( ( a ) => a.split( /=(.*)/s ).slice( 0, 2 ) )
);
const MD = Boolean( flags.md && '0' !== flags.md );

if ( files.length < 1 ) {
	process.stderr.write(
		'Usage: node tests/benchmarks/compare.js a.json b.json [c.json …] [md=1]\n'
	);
	process.exit( 1 );
}

const runs = files.map( ( file ) => {
	const data = JSON.parse( fs.readFileSync( file, 'utf8' ) );
	let kind = null;
	if ( data.service_us ) {
		kind = 'engine';
	} else if ( data.latencyMs ) {
		kind = 'transport';
	}
	if ( ! kind ) {
		process.stderr.write( `Unrecognized benchmark JSON: ${ file }\n` );
		process.exit( 1 );
	}
	return { file, kind, data };
} );

const fmt = ( value ) =>
	typeof value === 'number'
		? String(
				Number.isInteger( value ) ? value : Number( value.toFixed( 4 ) )
		  )
		: String( value ?? '—' );
const kb = ( bytes ) => `${ ( bytes / 1024 ).toFixed( 1 ) }K`;

/**
 * Renders rows (label + one value per run) as an aligned console table or a
 * Markdown table.
 *
 * @param {string}   title   Table title.
 * @param {string[]} headers Column headers (one per run).
 * @param {Array}    rows    Array of [label, ...values].
 */
function renderTable( title, headers, rows ) {
	const head = [ title, ...headers ];
	const all = [ head, ...rows.map( ( r ) => r.map( fmt ) ) ];
	const widths = head.map( ( _, col ) =>
		Math.max( ...all.map( ( row ) => String( row[ col ] ).length ) )
	);
	if ( MD ) {
		const line = ( row ) => `| ${ row.map( fmt ).join( ' | ' ) } |`;
		console.log( line( head ) );
		console.log( `|${ head.map( () => '---' ).join( '|' ) }|` );
		rows.forEach( ( row ) => console.log( line( row ) ) );
	} else {
		const line = ( row ) =>
			row
				.map( ( cell, col ) =>
					String( fmt( cell ) )[ 0 === col ? 'padEnd' : 'padStart' ](
						widths[ col ]
					)
				)
				.join( '  ' );
		console.log( line( head ) );
		console.log( widths.map( ( w ) => '-'.repeat( w ) ).join( '  ' ) );
		rows.forEach( ( row ) => console.log( line( row ) ) );
	}
	console.log( '' );
}

/**
 * Warns when a field differs across runs.
 *
 * @param {Array}    group  Runs to check.
 * @param {string}   label  Human label for the warning.
 * @param {Function} getter Extracts the compared value from a run's data.
 */
function warnOnMismatch( group, label, getter ) {
	const values = [
		...new Set( group.map( ( r ) => fmt( getter( r.data ) ) ) ),
	];
	if ( values.length > 1 ) {
		console.log(
			`WARNING: runs differ in ${ label } (${ values.join(
				' vs '
			) }) — their numbers are not directly comparable.`
		);
	}
}

const engineRuns = runs.filter( ( r ) => 'engine' === r.kind );
const transportRuns = runs.filter( ( r ) => 'transport' === r.kind );

if ( engineRuns.length ) {
	for ( const [ label, getter ] of [
		[ 'workload scenario', ( d ) => d.config?.scenario ],
		[ 'seed', ( d ) => d.config?.seed ],
		[ 'rounds', ( d ) => d.config?.rounds ],
		[ 'clients', ( d ) => d.config?.clients ],
		[ 'paragraphs', ( d ) => d.config?.paragraphs ],
		[ 'PHP version', ( d ) => d.environment?.php ],
		[ 'database', ( d ) => d.environment?.db ],
		[ 'opcache', ( d ) => d.environment?.opcache ],
	] ) {
		warnOnMismatch( engineRuns, label, getter );
	}
	const d = ( run ) => run.data;
	renderTable(
		'engine benchmark',
		engineRuns.map( ( r ) => r.data.engine ?? r.file ),
		[
			[ 'scenario', ...engineRuns.map( ( r ) => d( r ).scenario ) ],
			[ 'requests/rep', ...engineRuns.map( ( r ) => d( r ).requests ) ],
			[
				'service p50 ms',
				...engineRuns.map( ( r ) => d( r ).service_us.p50 ),
			],
			[
				'service p90 ms',
				...engineRuns.map( ( r ) => d( r ).service_us.p90 ),
			],
			[
				'service p99 ms',
				...engineRuns.map( ( r ) => d( r ).service_us.p99 ),
			],
			[
				'service mean ms',
				...engineRuns.map( ( r ) => d( r ).service_us.mean ),
			],
			[ 'read p50 ms', ...engineRuns.map( ( r ) => d( r ).read_us.p50 ) ],
			[
				'idle poll p50 ms',
				...engineRuns.map( ( r ) => d( r ).idle_poll_us.p50 ),
			],
			[
				'join p50 ms',
				...engineRuns.map( ( r ) => d( r ).join_us?.p50 ?? '—' ),
			],
			[
				'materialize p50 ms',
				...engineRuns.map( ( r ) => d( r ).materialize_us?.p50 ?? '—' ),
			],
			[
				'req bytes p50/max',
				...engineRuns.map(
					( r ) =>
						`${ d( r ).payload_bytes.request_p50 }/${
							d( r ).payload_bytes.request_max
						}`
				),
			],
			[
				'resp bytes p50/max',
				...engineRuns.map(
					( r ) =>
						`${ d( r ).payload_bytes.response_p50 }/${
							d( r ).payload_bytes.response_max
						}`
				),
			],
			[
				'storage rows',
				...engineRuns.map( ( r ) => d( r ).storage.rows ),
			],
			[
				'storage bytes',
				...engineRuns.map( ( r ) => d( r ).storage.bytes ),
			],
			[
				'followups',
				...engineRuns.map(
					( r ) =>
						d( r ).storage.followups ?? d( r ).storage.compactions
				),
			],
			[
				'trims',
				...engineRuns.map( ( r ) => d( r ).storage.trims ?? '—' ),
			],
			[
				'ingest peak MB',
				...engineRuns.map( ( r ) =>
					typeof d( r ).memory?.ingest_peak_bytes === 'number'
						? ( d( r ).memory.ingest_peak_bytes / 1048576 ).toFixed(
								2
						  )
						: '—'
				),
			],
			[
				'lock pair p50 ms',
				...engineRuns.map(
					( r ) => d( r ).calibration?.lock_pair_p50_ms ?? '—'
				),
			],
			[
				'quality',
				...engineRuns.map( ( r ) =>
					d( r ).quality.observable
						? `${
								d( r ).quality.converged
									? 'converged'
									: 'NOT CONVERGED'
						  }, esc ${ d( r ).quality.escalation_rate }, lost ${
								d( r ).quality.lost_work
						  }`
						: 'not server-observable'
				),
			],
			[
				'deterministic',
				...engineRuns.map( ( r ) =>
					d( r ).timing?.deterministic_across_reps ? 'yes' : 'NO'
				),
			],
		]
	);
}

if ( transportRuns.length ) {
	for ( const [ label, getter ] of [
		[ 'engine', ( d ) => d.environment?.engine ],
		[ 'trial count', ( d ) => d.environment?.trials ],
		[ 'base URL', ( d ) => d.environment?.baseUrl ],
	] ) {
		warnOnMismatch( transportRuns, label, getter );
	}
	const d = ( run ) => run.data;
	const idleTraffic = ( data ) => {
		const idle = data.idlePhase?.a;
		if ( ! idle ) {
			return '—';
		}
		return 'websocket' === data.environment.transportObserved
			? `${ idle.wsFramesSent + idle.wsFramesReceived } frames, ${ kb(
					idle.wsBytesSent + idle.wsBytesReceived
			  ) }`
			: `${ idle.requestsPerMinute.toFixed( 0 ) } req/min, ${ kb(
					idle.requestBytesPerMinute + idle.responseBytesPerMinute
			  ) }/min`;
	};
	renderTable(
		'transport benchmark',
		transportRuns.map(
			( r ) => d( r ).environment.transportObserved ?? r.file
		),
		[
			[
				'engine',
				...transportRuns.map( ( r ) => d( r ).environment.engine ),
			],
			[
				'latency min ms',
				...transportRuns.map( ( r ) => d( r ).latencyMs.min ),
			],
			[
				'latency p50 ms',
				...transportRuns.map( ( r ) => d( r ).latencyMs.p50 ),
			],
			[
				'latency p90 ms',
				...transportRuns.map( ( r ) => d( r ).latencyMs.p90 ),
			],
			[
				'latency max ms',
				...transportRuns.map( ( r ) => d( r ).latencyMs.max ),
			],
			[
				'latency mean ms',
				...transportRuns.map( ( r ) => d( r ).latencyMs.mean ),
			],
			[
				'idle traffic (win A)',
				...transportRuns.map( ( r ) => idleTraffic( d( r ) ) ),
			],
			[
				'trials',
				...transportRuns.map( ( r ) => d( r ).environment.trials ),
			],
		]
	);
}
