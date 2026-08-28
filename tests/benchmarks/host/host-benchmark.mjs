/**
 * The host cost report: what real-time collaboration adds to a server,
 * measured as the difference against the workflow it replaces — the
 * same people producing the same document by editing in series with
 * the plugin deactivated — printed as one baseline/sync/delta/delta-%
 * table per engine plus a whole-job total.
 *
 *   npm run bench                            # this report, defaults
 *   npm run bench -- engines=intent-log,yjs-server,de-rtc windows=3
 *   npm run bench -- metrics=requests,cpu json=host.json
 *
 * Phases, all real browser windows against a live site:
 *
 *   1. BASELINE — the plugin is deactivated (every active copy, via the
 *      REST plugins endpoint) and `windows` people edit the same draft
 *      IN SERIES: person i types their part for `edit` seconds, saves,
 *      and leaves, then the next person takes a turn; after the last
 *      turn the tab sits idle for `idle` seconds. This is the workflow
 *      the plugin replaces — the post lock forces turn-taking — and
 *      each person types the same script their window types in phase
 *      2, so both phases produce the same final document.
 *   2. PER ENGINE — the plugin is reactivated, real-time collaboration
 *      is enabled, and `windows` browser windows co-edit an identical
 *      draft simultaneously (each typing into its own paragraph, one
 *      save at the end) on that engine and the chosen transport.
 *
 * Every request each window makes is counted client-side (requests,
 * bytes). Server-side, EVERY request from the windows is tagged with
 * the community harness's headers, and the whole-request measurement
 * mu-plugin (tests/benchmarks/host/mu-bench-log.php — this repo's
 * wp-env configs map it into mu-plugins) records each tagged PHP
 * request from load to shutdown: wall time, CPU, DB queries, peak
 * memory, concurrency. Because the mu-plugin works with the plugin
 * DEACTIVATED, the baseline phase gets real server-side numbers too,
 * so CPU, worker share, and memory are true baseline/sync/delta
 * comparisons — not sync-requests-only. Without the mu-plugin the
 * server columns fall back to the plugin's own sync requests and the
 * baseline server column reads "—".
 *
 * Each engine's table reports, per person, for editing and idle spans:
 * requests per minute, network traffic, server CPU per minute, the
 * share of one PHP worker held, and peak PHP memory per request —
 * columns baseline | sync | delta | delta %. Under the table, one
 * whole-job line: what producing the same final document cost in
 * series vs collaboratively (requests, server CPU, network).
 *
 * Arguments (bare key=value, like every benchmark here):
 *
 *   engines=    comma list of engines to measure (default: the site's
 *               current engine); engine= is an alias for a single one
 *   transport=  http-polling | http-long-polling | websocket | current
 *   windows=    people per phase: collaborator windows, and the same
 *               number of one-after-the-other baseline turns (default 2)
 *   edit=       editing seconds per person (default 120, min 30)
 *   idle=       idle seconds per phase (default 120; 0 skips)
 *   poll=       override the HTTP short-polling interval for the run, in
 *               seconds 0-25 (0 = the plugin's defaults; default: leave
 *               the site's setting alone; restored afterwards)
 *   metrics=    comma list to report: requests,traffic,cpu,workers,memory
 *               (default all)
 *   json=       write full results as JSON to this path
 *   headed=1    visible browser (debugging)
 *   --help      print the argument list and exit
 *
 * Requires a running environment with the plugin active at start (the
 * tests env: npm run env:tests start — restart it once after pulling
 * this change so the mu-plugin mapping mounts), Playwright's chromium,
 * and — for the server-side columns — the diagnostics request log
 * (local/development sites, or GUTENBERG_SYNC_ENGINES_DIAGNOSTICS).
 * Environment: WP_BASE_URL / WP_USERNAME / WP_PASSWORD as usual.
 */
import fs from 'node:fs';
import { chromium } from '@playwright/test';

import {
	BASE,
	COLLABORATION_EXPERIMENT,
	attachCounters,
	canvasOf,
	configureSettings,
	dismissWelcomeGuide,
	ensureCollaborationEnabled,
	login,
	makeRestClient,
	observeTransport,
	parseCliOptions,
	restoreSettings,
	waitForSyncTraffic,
} from '../transport/lib.mjs';

const opts = parseCliOptions();

const HELP = `node tests/benchmarks/host/host-benchmark.mjs [key=value …]
(or: npm run bench -- [key=value …])

  engines=    comma list of engines to measure, one table each
              (intent-log | yjs-server | de-rtc | current; default: the
              site's current engine; engine= is an alias for one)
  transport=  http-polling | http-long-polling | websocket
              (default: the site's current transport)
  windows=    people per phase: collaborator windows, and the same
              number of one-after-the-other baseline turns (default 2)
  edit=       editing seconds per person (default 120, min 30)
  idle=       idle seconds per phase (default 120; 0 skips)
  poll=       override the HTTP short-polling interval for the run, in
              seconds 1-25 (0 = the plugin's defaults; default: leave
              the site's setting alone; restored afterwards)
  metrics=    comma list of table rows to print:
              requests,traffic,cpu,workers,memory (default all)
  json=       write full results as JSON to this path
  headed=1    visible browser (debugging)

Environment: WP_BASE_URL (default http://localhost:8889),
WP_USERNAME/WP_PASSWORD (default admin/password).
`;

if ( opts.help || opts.h ) {
	process.stdout.write( HELP );
	process.exit( 0 );
}

const ENGINES = String( opts.engines ?? opts.engine ?? 'current' )
	.split( ',' )
	.map( ( slug ) => slug.trim() )
	.filter( Boolean );
const TRANSPORT = String( opts.transport ?? 'current' );
const WINDOWS = Math.max( 1, Number( opts.windows ?? 2 ) );
const EDIT_SECONDS = Number( opts.edit ?? 120 );
const IDLE_SECONDS = Number( opts.idle ?? 120 );
const POLL_OVERRIDE =
	undefined === opts.poll
		? null
		: Math.max( 0, Math.min( 25, Number( opts.poll ) ) );
const JSON_PATH = opts.json ? String( opts.json ) : null;
const HEADED = Boolean( opts.headed );
const ALL_METRICS = [ 'requests', 'traffic', 'cpu', 'workers', 'memory' ];
const METRICS = opts.metrics
	? String( opts.metrics )
			.split( ',' )
			.map( ( metric ) => metric.trim() )
			.filter( Boolean )
	: ALL_METRICS;

const POLLING_INTERVAL_SETTING = 'gutenberg_sync_engines_polling_interval';

/**
 * Deterministic jitter (the soak's convention — reruns pace the same).
 *
 * @param {number} step Monotonic counter.
 * @param {number} min  Minimum value.
 * @param {number} max  Maximum value.
 * @return {number} Value in [min, max].
 */
const jitter = ( step, min, max ) =>
	min +
	( ( ( step * 2654435761 ) % 4294967296 ) / 4294967296 ) * ( max - min );

/**
 * Counts EVERY HTTP request a page makes (any route), unlike lib.mjs's
 * attachCounters, which counts sync traffic only. The baseline phase
 * has no sync traffic at all, so the host comparison needs the whole
 * wire. Counters are cumulative; phases diff snapshot() results.
 *
 * @param {import('@playwright/test').Page} page Target page.
 * @return {Object} Counter handle with a snapshot() method.
 */
function attachAllTrafficCounters( page ) {
	const c = { requests: 0, requestBytes: 0, responseBytes: 0 };
	page.on( 'request', ( request ) => {
		c.requests += 1;
		c.requestBytes += request.postDataBuffer()?.length ?? 0;
	} );
	page.on( 'response', async ( response ) => {
		try {
			c.responseBytes += ( await response.body() ).length;
		} catch {
			// Body unavailable (navigation, abort): skip its bytes.
		}
	} );
	return { snapshot: () => ( { ...c } ) };
}

/**
 * Tags EVERY same-site request from a MEASURED page with the community
 * harness's measurement headers, labeled with the current phase's
 * scenario and approach. The whole-request mu-plugin measures every
 * tagged PHP request server-side — page loads and admin-ajax included,
 * with the plugin active or not. Only pages in the `measured` set are
 * tagged: the admin chore page's own heartbeat must not pollute the
 * per-person numbers.
 *
 * @param {import('@playwright/test').BrowserContext} context  Browser context.
 * @param {{ scenario: string, approach: string }}    tag      Mutable labels.
 * @param {Set<Object>}                               measured Pages to tag.
 */
async function installGlobalTagging( context, tag, measured ) {
	const origin = new URL( BASE ).origin;
	await context.route(
		( url ) => url.origin === origin,
		async ( route ) => {
			let page = null;
			try {
				page = route.request().frame().page();
			} catch {
				// Service-worker or detached-frame request: not measured.
			}
			if ( ! page || ! measured.has( page ) ) {
				return route.continue();
			}
			await route.continue( {
				headers: {
					...route.request().headers(),
					'x-rtc-test': '1',
					'x-rtc-scenario': tag.scenario,
					'x-rtc-approach': tag.approach,
					...( null !== POLL_OVERRIDE
						? { 'x-rtc-poll-delay': String( POLL_OVERRIDE ) }
						: {} ),
				},
			} );
		}
	);
}

/**
 * One window's editing driver: think briefly, type a burst into its own
 * anchor paragraph, repeat until the deadline.
 *
 * @param {Object} win      Window record { page, canvas, index }.
 * @param {number} deadline Epoch ms to stop at.
 * @param {Object} stats    Mutable { tokensTyped, bursts } tally.
 */
async function editingDriver( win, deadline, stats ) {
	let step = win.index * 97 + 1;
	while ( Date.now() < deadline ) {
		const thinkMs = jitter( step++, 2000, 6000 );
		await win.page.waitForTimeout(
			Math.min( thinkMs, Math.max( 0, deadline - Date.now() ) )
		);
		if ( Date.now() >= deadline ) {
			return;
		}
		const burst = Math.round( jitter( step++, 4, 9 ) );
		const paragraph = win.canvas
			.locator( '[data-type="core/paragraph"]', {
				hasText: `hostw${ win.index }anchor`,
			} )
			.first();
		try {
			await paragraph.click( { timeout: 5000 } );
			await win.page.keyboard.press( 'End' );
			for ( let i = 0; i < burst && Date.now() < deadline; i++ ) {
				await win.page.keyboard.insertText(
					` w${ win.index }t${ stats.tokensTyped++ }x`
				);
				await win.page.waitForTimeout( jitter( step++, 250, 550 ) );
			}
			stats.bursts += 1;
		} catch {
			stats.burstErrors = ( stats.burstErrors ?? 0 ) + 1;
		}
	}
}

/**
 * Creates a draft post with one anchor paragraph per window, returning
 * its id. Created over REST so no editor (and no sync session) is
 * involved.
 *
 * @param {Object} rest  REST client.
 * @param {string} label Title suffix distinguishing the phases.
 * @return {Promise<number>} Post id.
 */
async function createDraft( rest, label ) {
	const content = Array.from(
		{ length: WINDOWS },
		( _, index ) =>
			`<!-- wp:paragraph --><p>hostw${ index }anchor</p><!-- /wp:paragraph -->`
	).join( '\n' );
	const { status, data } = await rest.post( '/wp/v2/posts', {
		body: {
			title: `host benchmark ${ label }`,
			content,
			status: 'draft',
		},
	} );
	if ( 201 !== status || ! data?.id ) {
		throw new Error( `creating the ${ label } draft failed (${ status })` );
	}
	return data.id;
}

/**
 * Lists this plugin's entries on the REST plugins endpoint.
 *
 * @param {Object} rest REST client.
 * @return {Promise<Array<{plugin: string, status: string}>>} Entries.
 */
async function listPluginCopies( rest ) {
	const { status, data } = await rest.get( '/wp/v2/plugins' );
	if ( 200 !== status || ! Array.isArray( data ) ) {
		throw new Error(
			`GET /wp/v2/plugins returned ${ status } — cannot toggle the plugin for the baseline phase`
		);
	}
	return data
		.filter( ( row ) =>
			String( row.plugin ?? '' ).endsWith( '/gutenberg-sync-engines' )
		)
		.map( ( row ) => ( { plugin: row.plugin, status: row.status } ) );
}

/**
 * Sets one plugin's activation status over REST.
 *
 * @param {Object} rest       REST client.
 * @param {string} plugin     Plugin identifier (dir/file, no .php).
 * @param {string} wantStatus 'active' or 'inactive'.
 */
async function setPluginStatus( rest, plugin, wantStatus ) {
	const { status } = await rest.post( `/wp/v2/plugins/${ plugin }`, {
		body: { status: wantStatus },
	} );
	if ( 200 !== status ) {
		throw new Error(
			`setting ${ plugin } to ${ wantStatus } failed (${ status })`
		);
	}
}

/**
 * Opens one editor window on a post and waits for the editor to be
 * ready. The page joins the measured set before navigating, so its
 * page load is tagged (under the setup scenario) like everything else
 * it sends.
 *
 * @param {import('@playwright/test').BrowserContext} context  Browser context.
 * @param {Set<Object>}                               measured Measured pages.
 * @param {number}                                    postId   Post to open.
 * @param {number}                                    index    Window index.
 * @return {Promise<Object>} Window record.
 */
async function openEditorWindow( context, measured, postId, index ) {
	const page = await context.newPage();
	measured.add( page );
	page.on( 'close', () => measured.delete( page ) );
	const all = attachAllTrafficCounters( page );
	const sync = attachCounters( page );
	await page.goto(
		`${ BASE }/wp-admin/post.php?post=${ postId }&action=edit`
	);
	await dismissWelcomeGuide( page );
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	const win = { page, index, all, sync, canvas: null };
	win.canvas = await canvasOf( page );
	return win;
}

/**
 * Saves the post through the editor's own save action (the button's
 * path), so the save cost lands inside the measured span the way a
 * real person's save does.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 * @return {Promise<boolean>} Whether the save succeeded.
 */
async function saveViaEditor( page ) {
	return page
		.evaluate( async () => {
			await window.wp.data.dispatch( 'core/editor' ).savePost();
			return ! window.wp.data
				.select( 'core/editor' )
				.didPostSaveRequestFail();
		} )
		.catch( () => false );
}

/**
 * Runs one measured span set over a set of windows: an editing span
 * (drivers, then window 0 saves — the save is part of the editing
 * work), and optionally an idle span. Both phases run through this,
 * so the workload shape is identical; only who is present differs.
 *
 * @param {Object[]} wins     Window records.
 * @param {Object}   tag      Mutable { scenario, approach } labels.
 * @param {boolean}  withIdle Run the idle span after editing.
 * @return {Promise<Object>} Per-window counter deltas and durations.
 */
async function measurePhase( wins, tag, withIdle = true ) {
	// Let the just-loaded pages settle so page-load assets and session
	// setup stay out of the rates.
	await wins[ 0 ].page.waitForTimeout( 3000 );

	tag.scenario = 'host-editing';
	const editStats = wins.map( () => ( { tokensTyped: 0, bursts: 0 } ) );
	const editStart = Date.now();
	const startAll = wins.map( ( win ) => win.all.snapshot() );
	const startSync = wins.map( ( win ) => win.sync.snapshot() );
	const deadline = editStart + EDIT_SECONDS * 1000;
	await Promise.all(
		wins.map( ( win, position ) =>
			editingDriver( win, deadline, editStats[ position ] )
		)
	);
	const saveOk = await saveViaEditor( wins[ 0 ].page );
	const editMs = Date.now() - editStart;
	const editAll = wins.map( ( win ) => win.all.snapshot() );
	const editSync = wins.map( ( win ) => win.sync.snapshot() );

	tag.scenario = 'host-idle';
	const idleStart = Date.now();
	if ( withIdle && IDLE_SECONDS > 0 ) {
		await wins[ 0 ].page.waitForTimeout( IDLE_SECONDS * 1000 );
	}
	const idleMs = Date.now() - idleStart;
	const idleAll = wins.map( ( win ) => win.all.snapshot() );
	const idleSync = wins.map( ( win ) => win.sync.snapshot() );
	tag.scenario = 'setup';

	const span = ( before, after ) => {
		const delta = {};
		for ( const key of Object.keys( before ) ) {
			delta[ key ] = after[ key ] - before[ key ];
		}
		return delta;
	};
	return {
		editMs,
		idleMs,
		saveOk,
		perWindow: wins.map( ( win, index ) => ( {
			window: index,
			tokensTyped: editStats[ index ].tokensTyped,
			bursts: editStats[ index ].bursts,
			burstErrors: editStats[ index ].burstErrors ?? 0,
			editing: {
				all: span( startAll[ index ], editAll[ index ] ),
				sync: span( startSync[ index ], editSync[ index ] ),
			},
			idle: {
				all: span( editAll[ index ], idleAll[ index ] ),
				sync: span( editSync[ index ], idleSync[ index ] ),
			},
		} ) ),
	};
}

/**
 * Sums one client counter over a span across a phase's windows.
 *
 * @param {Object} phase   measurePhase result.
 * @param {string} spanKey 'editing' or 'idle'.
 * @param {string} counter Counter name.
 * @return {number} Total across windows.
 */
function spanTotal( phase, spanKey, counter ) {
	return phase.perWindow.reduce(
		( total, win ) => total + win[ spanKey ].all[ counter ],
		0
	);
}

/**
 * Per-person-per-minute client rate for a span. `ms` is the span's
 * wall time and `persons` how many people were active in it — for the
 * serial baseline each accumulated session minute has exactly one
 * active person, so persons is 1 over the summed session time.
 *
 * @param {number} total   Counter total over the span.
 * @param {number} ms      Span wall time (summed for serial sessions).
 * @param {number} persons Active people sharing the span.
 * @return {number} Rate per person-minute.
 */
function ratePerPersonMinute( total, ms, persons ) {
	return ms > 0 ? ( total / ( ms * persons ) ) * 60000 : 0;
}

/**
 * Aggregates request-log rows for one approach + scenario.
 *
 * @param {Array<Object>} rows     Raw log rows.
 * @param {string}        approach Approach label to keep.
 * @param {string}        scenario Scenario label to keep.
 * @return {Object} Sums, count, and memory extremes.
 */
function aggregateServerRows( rows, approach, scenario ) {
	const kept = rows.filter(
		( row ) => row.approach === approach && row.scenario === scenario
	);
	const sum = ( key ) =>
		kept.reduce( ( total, row ) => total + ( row[ key ] ?? 0 ), 0 );
	return {
		n: kept.length,
		cpuMsSum: sum( 'total_cpu_ms' ),
		totalMsSum: sum( 'total_ms' ),
		dbQueriesSum: sum( 'db_queries' ),
		peakMemoryMax: kept.reduce(
			( max, row ) => Math.max( max, row.peak_memory ?? 0 ),
			0
		),
		peakMemoryMean: kept.length ? sum( 'peak_memory' ) / kept.length : 0,
	};
}

/**
 * Server-side per-person-per-minute figures for one measured span.
 *
 * @param {Object} agg     aggregateServerRows result.
 * @param {number} ms      Span duration.
 * @param {number} persons Windows sharing the span's traffic.
 * @return {Object|null} Rates, or null when the span has no rows.
 */
function serverRates( agg, ms, persons ) {
	if ( ! agg || 0 === agg.n || ms <= 0 ) {
		return null;
	}
	const minutes = ms / 60000;
	return {
		requestsPerMinute: agg.n / minutes / persons,
		cpuMsPerMinute: agg.cpuMsSum / minutes / persons,
		workerShare: agg.totalMsSum / ms / persons,
		dbQueriesPerMinute: agg.dbQueriesSum / minutes / persons,
		peakMemoryMaxMb: agg.peakMemoryMax / 1048576,
		peakMemoryMeanMb: agg.peakMemoryMean / 1048576,
	};
}

/**
 * Builds one engine's summary from its phase + the shared serial
 * baseline: per-span baseline/sync rates, plus the whole-job totals —
 * both phases produce the same final document, so "what did producing
 * this document cost" is directly comparable.
 *
 * @param {Object} phase    The engine's measurePhase result.
 * @param {Object} baseline Serial baseline { sessions, editMs, idleMs }.
 * @param {Array}  rows     All server rows (may be empty).
 * @param {string} engine   Engine slug (the approach label).
 * @return {Object} { spans, job } for the report.
 */
function summarize( phase, baseline, rows, engine ) {
	const lastSession = baseline.sessions[ baseline.sessions.length - 1 ];
	const baseTotal = ( spanKey, counter ) =>
		'editing' === spanKey
			? baseline.sessions.reduce(
					( total, session ) =>
						total + spanTotal( session, spanKey, counter ),
					0
			  )
			: spanTotal( lastSession, spanKey, counter );

	const spans = {};
	for ( const spanKey of [ 'editing', 'idle' ] ) {
		const ms = 'editing' === spanKey ? phase.editMs : phase.idleMs;
		const baseMs =
			'editing' === spanKey ? baseline.editMs : baseline.idleMs;
		// Serial baseline: every accumulated minute has ONE active
		// person; the idle tab is one person's in both phases' baselines.
		const basePersons = 1;
		const rate = ( counter ) =>
			ratePerPersonMinute(
				spanTotal( phase, spanKey, counter ),
				ms,
				WINDOWS
			);
		const baseRate = ( counter ) =>
			ratePerPersonMinute(
				baseTotal( spanKey, counter ),
				baseMs,
				basePersons
			);
		spans[ spanKey ] = {
			client: {
				requestsPerMinute: rate( 'requests' ),
				kbPerMinute:
					( rate( 'requestBytes' ) + rate( 'responseBytes' ) ) / 1024,
			},
			baseClient: {
				requestsPerMinute: baseRate( 'requests' ),
				kbPerMinute:
					( baseRate( 'requestBytes' ) +
						baseRate( 'responseBytes' ) ) /
					1024,
			},
			server: serverRates(
				aggregateServerRows( rows, engine, `host-${ spanKey }` ),
				ms,
				WINDOWS
			),
			baseServer: serverRates(
				aggregateServerRows( rows, 'baseline', `host-${ spanKey }` ),
				baseMs,
				basePersons
			),
		};
	}

	// Whole-job totals over the editing spans (saves included; idle
	// excluded): the cost of producing the same final document once in
	// series and once collaboratively.
	const baseServerJob = aggregateServerRows(
		rows,
		'baseline',
		'host-editing'
	);
	const engineServerJob = aggregateServerRows( rows, engine, 'host-editing' );
	const job = {
		base: {
			requests: baseTotal( 'editing', 'requests' ),
			kb:
				( baseTotal( 'editing', 'requestBytes' ) +
					baseTotal( 'editing', 'responseBytes' ) ) /
				1024,
			serverCpuS: baseServerJob.n ? baseServerJob.cpuMsSum / 1000 : null,
		},
		sync: {
			requests: spanTotal( phase, 'editing', 'requests' ),
			kb:
				( spanTotal( phase, 'editing', 'requestBytes' ) +
					spanTotal( phase, 'editing', 'responseBytes' ) ) /
				1024,
			serverCpuS: engineServerJob.n
				? engineServerJob.cpuMsSum / 1000
				: null,
		},
	};
	return { spans, job };
}

async function main() {
	if ( ! Number.isFinite( EDIT_SECONDS ) || EDIT_SECONDS < 30 ) {
		throw new Error( 'edit must be at least 30 seconds' );
	}
	if ( null !== POLL_OVERRIDE && ! Number.isFinite( POLL_OVERRIDE ) ) {
		throw new Error( 'poll must be a number of seconds (0-25)' );
	}
	const unknownMetrics = METRICS.filter(
		( metric ) => ! ALL_METRICS.includes( metric )
	);
	if ( unknownMetrics.length ) {
		throw new Error(
			`unknown metrics: ${ unknownMetrics.join(
				', '
			) } (known: ${ ALL_METRICS.join( ', ' ) })`
		);
	}

	const browser = await chromium.launch( { headless: ! HEADED } );
	const context = await browser.newContext();
	const tag = { scenario: 'setup', approach: 'baseline' };
	const measuredPages = new Set();
	await installGlobalTagging( context, tag, measuredPages );

	let originalSettings = null;
	let lastActive = null;
	let deactivated = [];
	let experimentWasOn = null;
	let originalPoll = 0;
	let pollChanged = false;
	let adminPage = null;
	let rest = null;
	try {
		adminPage = await login( context );
		rest = await makeRestClient( adminPage );
		if ( ! rest ) {
			throw new Error( 'could not obtain a REST nonce' );
		}

		// The plugin must be active at start: its settings screen is how
		// engine/transport are chosen, and its diagnostics log is how the
		// server side is read out. When no copy is active (a PHPUnit run
		// wipes the tests-env database, activation included), activate one
		// — in a worktree the plugin is mounted twice, and the safe copy
		// is the directory-name one (wp-env re-activates it on every
		// start; the reverse arrangement fatals the next start), which is
		// the copy whose directory is NOT the canonical mapping name.
		const copies = await listPluginCopies( rest );
		let activeCopies = copies.filter(
			( copy ) => 'active' === copy.status
		);
		if ( ! activeCopies.length && copies.length ) {
			const preferred =
				copies.find(
					( copy ) =>
						! copy.plugin.startsWith( 'gutenberg-sync-engines/' )
				) ?? copies[ 0 ];
			console.log(
				`no active gutenberg-sync-engines copy — activating ${ preferred.plugin }…`
			);
			await setPluginStatus( rest, preferred.plugin, 'active' );
			activeCopies = [ { ...preferred, status: 'active' } ];
		}
		if ( ! activeCopies.length ) {
			throw new Error(
				`no gutenberg-sync-engines plugin on ${ BASE } — install and activate it, then rerun`
			);
		}

		// Record the site's engine/transport to restore at the end, and
		// whether the collaboration experiment was already on.
		originalSettings = await configureSettings(
			adminPage,
			'current',
			'current'
		);
		lastActive = originalSettings.active;
		const settingsBefore = await rest.get( '/wp/v2/settings' );
		experimentWasOn = Boolean(
			settingsBefore.data?.[ 'gutenberg-experiments' ]?.[
				COLLABORATION_EXPERIMENT
			]
		);
		originalPoll = Number(
			settingsBefore.data?.[ POLLING_INTERVAL_SETTING ] ?? 0
		);
		if ( null !== POLL_OVERRIDE && POLL_OVERRIDE !== originalPoll ) {
			const updated = await rest.post( '/wp/v2/settings', {
				body: { [ POLLING_INTERVAL_SETTING ]: POLL_OVERRIDE },
			} );
			if ( 200 !== updated.status ) {
				throw new Error(
					`setting the polling interval to ${ POLL_OVERRIDE }s failed (${ updated.status })`
				);
			}
			pollChanged = true;
		}
		await ensureCollaborationEnabled( adminPage );
		await rest.del( '/rtc-test/v1/log' ).catch( () => null );

		// Say exactly what this run will measure, and where each choice
		// came from, before spending minutes measuring it.
		const provided = ( key ) => undefined !== opts[ key ];
		const engineLabels = ENGINES.map( ( slug ) =>
			'current' === slug
				? `current → ${ originalSettings.active.engine } (site setting; engines= to target)`
				: slug
		);
		console.log( 'configuration:' );
		console.log(
			`  engines    ${ engineLabels.join( ', ' ) }${
				provided( 'engines' ) || provided( 'engine' )
					? ''
					: ' (default)'
			}`
		);
		console.log(
			`  transport  ${
				'current' === TRANSPORT
					? `current → ${ originalSettings.active.transport } (site setting; transport= to override)`
					: `${ TRANSPORT } (transport=)`
			}`
		);
		console.log(
			`  windows    ${ WINDOWS }${
				provided( 'windows' ) ? '' : ' (default)'
			}`
		);
		console.log(
			`  baseline   ${ WINDOWS } person(s) editing the same document one after the other, plugin deactivated`
		);
		console.log(
			`  editing    ${ EDIT_SECONDS } s${
				provided( 'edit' ) ? '' : ' (default)'
			}, idle ${ IDLE_SECONDS } s${
				provided( 'idle' ) ? '' : ' (default)'
			}`
		);
		console.log(
			`  polling    ${
				null === POLL_OVERRIDE
					? `site setting, ${ originalPoll } s (0 = plugin defaults; poll= to override)`
					: `${ POLL_OVERRIDE } s (poll=; restored afterwards)`
			}`
		);
		console.log(
			`  metrics    ${ METRICS.join( ',' ) }${
				provided( 'metrics' ) ? '' : ' (default: all)'
			}${ JSON_PATH ? `, json ${ JSON_PATH }` : '' }`
		);

		// ---------------- Phase 1: baseline (plugin deactivated) --------
		// The baseline is the workflow the plugin replaces: the same
		// number of people producing the same document by editing IN
		// SERIES — person i types their part, saves, and leaves, then
		// person i+1 takes a turn. Each person types the same script
		// their window types in the collaborative phase, so the final
		// document matches in size and shape and the whole-job totals
		// are directly comparable.
		const baselinePost = await createDraft( rest, 'baseline' );
		console.log(
			`baseline phase: deactivating ${ activeCopies
				.map( ( copy ) => copy.plugin )
				.join( ', ' ) }; ${ WINDOWS } person(s) editing post ` +
				`${ baselinePost } in series (${ EDIT_SECONDS }s + a save ` +
				`each, then ${ IDLE_SECONDS }s idle)…`
		);
		for ( const copy of activeCopies ) {
			await setPluginStatus( rest, copy.plugin, 'inactive' );
			deactivated.push( copy.plugin );
		}

		const baselineSessions = [];
		for ( let person = 0; person < WINDOWS; person++ ) {
			const isLast = person === WINDOWS - 1;
			console.log( `  baseline turn ${ person + 1 }/${ WINDOWS }…` );
			const win = await openEditorWindow(
				context,
				measuredPages,
				baselinePost,
				person
			);
			const session = await measurePhase( [ win ], tag, isLast );
			await win.page.close();
			if ( session.perWindow[ 0 ].editing.sync.requests > 0 ) {
				throw new Error(
					'a baseline turn made sync requests — the plugin was still active, so the comparison is meaningless'
				);
			}
			if ( ! session.saveOk ) {
				throw new Error(
					`baseline turn ${
						person + 1
					} failed to save — the next turn would edit a stale document, breaking the parity the comparison depends on`
				);
			}
			baselineSessions.push( session );
		}
		const baseline = {
			sessions: baselineSessions,
			editMs: baselineSessions.reduce(
				( total, session ) => total + session.editMs,
				0
			),
			idleMs: baselineSessions[ baselineSessions.length - 1 ].idleMs,
		};

		for ( const plugin of deactivated ) {
			await setPluginStatus( rest, plugin, 'active' );
		}
		deactivated = [];

		// ---------------- Phase 2: one measurement per engine -----------
		const engines = [];
		for ( const requested of ENGINES ) {
			const settings = await configureSettings(
				adminPage,
				requested,
				TRANSPORT
			);
			lastActive = settings.active;
			const engine = settings.active.engine;
			tag.approach = engine;

			const post = await createDraft( rest, engine );
			console.log(
				`${ engine } phase: ${ WINDOWS } window(s) on post ${ post }, ` +
					`transport=${ settings.active.transport }…`
			);

			const wins = [];
			for ( let index = 0; index < WINDOWS; index++ ) {
				const win = await openEditorWindow(
					context,
					measuredPages,
					post,
					index
				);
				await waitForSyncTraffic( win.page, win.sync, String( index ) );
				wins.push( win );
			}
			const observed = observeTransport( wins[ 0 ].sync );
			if (
				'current' !== TRANSPORT &&
				observed !== settings.active.transport
			) {
				throw new Error(
					`requested transport "${ settings.active.transport }" but the session negotiated "${ observed }"`
				);
			}

			const phase = await measurePhase( wins, tag );
			if ( ! phase.saveOk ) {
				console.warn(
					`WARNING: the ${ engine } phase's end-of-editing save failed — job totals still comparable, but the save cost is missing from the sync side`
				);
			}
			for ( const win of wins ) {
				const edited = phase.perWindow[ win.index ].editing.sync;
				if (
					0 === edited.dataRequests &&
					0 === edited.wsFramesSent + edited.wsFramesReceived
				) {
					throw new Error(
						`${ engine } window ${ win.index } made no sync data traffic while editing — dead session, numbers unusable`
					);
				}
				await win.page.close();
			}
			tag.approach = 'baseline';

			engines.push( {
				engine,
				transport: observed,
				postId: post,
				phase,
			} );
		}

		// ---------------- Collect and report ----------------------------
		const logResponse = await rest.get( '/rtc-test/v1/log' );
		const serverRows =
			200 === logResponse.status && Array.isArray( logResponse.data )
				? logResponse.data
				: [];
		const envResponse = await rest.get( '/rtc-test/v1/env' );
		const serverEnv = 200 === envResponse.status ? envResponse.data : null;

		const muPresent = serverRows.some(
			( row ) => 'baseline' === row.approach
		);

		const report = {
			environment: {
				date: new Date().toISOString(),
				baseUrl: BASE,
				windows: WINDOWS,
				editSeconds: EDIT_SECONDS,
				idleSeconds: IDLE_SECONDS,
				muMeasurement: muPresent,
				server: serverEnv,
			},
			baseline: { postId: baselinePost, detail: baseline },
			engines: engines.map( ( entry ) => ( {
				engine: entry.engine,
				transport: entry.transport,
				postId: entry.postId,
				...summarize( entry.phase, baseline, serverRows, entry.engine ),
				detail: entry.phase,
			} ) ),
			serverRows,
		};

		printReport( report );

		if ( JSON_PATH ) {
			fs.writeFileSync( JSON_PATH, JSON.stringify( report, null, 2 ) );
			console.log( `\njson written: ${ JSON_PATH }` );
		}
	} finally {
		// Whatever happened, put the site back: reactivate anything still
		// deactivated, restore engine/transport, restore the experiment.
		for ( const plugin of deactivated ) {
			await setPluginStatus( rest, plugin, 'active' ).catch( ( error ) =>
				console.warn(
					`WARNING: could not reactivate ${ plugin }: ${ error }`
				)
			);
		}
		if ( pollChanged && rest ) {
			await rest
				.post( '/wp/v2/settings', {
					body: { [ POLLING_INTERVAL_SETTING ]: originalPoll },
				} )
				.catch( ( error ) =>
					console.warn(
						`WARNING: failed to restore the polling interval: ${ error }`
					)
				);
		}
		if (
			originalSettings &&
			adminPage &&
			lastActive &&
			( originalSettings.previous.engine !== lastActive.engine ||
				originalSettings.previous.transport !== lastActive.transport )
		) {
			await restoreSettings( adminPage, originalSettings.previous ).catch(
				( error ) =>
					console.warn(
						`WARNING: failed to restore settings: ${ error }`
					)
			);
		}
		if ( false === experimentWasOn && rest ) {
			const current = await rest.get( '/wp/v2/settings' );
			const experiments = {
				...( current.data?.[ 'gutenberg-experiments' ] || {} ),
			};
			delete experiments[ COLLABORATION_EXPERIMENT ];
			await rest
				.post( '/wp/v2/settings', {
					body: { 'gutenberg-experiments': experiments },
				} )
				.catch( () => null );
		}
		await browser.close();
	}
}

/**
 * Renders one baseline/sync/delta table per engine.
 *
 * @param {Object} report Assembled report.
 */
function printReport( report ) {
	const env = report.environment;
	const fmt = ( value, decimals ) =>
		null === value || undefined === value ? '—' : value.toFixed( decimals );
	const delta = ( base, sync, decimals ) =>
		null === base || undefined === base || null === sync
			? '—'
			: `${ sync - base >= 0 ? '+' : '' }${ ( sync - base ).toFixed(
					decimals
			  ) }`;
	const pct = ( base, sync ) => {
		if ( null === base || undefined === base || null === sync ) {
			return '—';
		}
		if ( 0 === base ) {
			return 0 === sync ? '+0%' : '—';
		}
		const value = Math.round( ( ( sync - base ) / base ) * 100 );
		return `${ value >= 0 ? '+' : '' }${ value }%`;
	};

	for ( const entry of report.engines ) {
		const rows = [];
		const push = ( metric, label, spanKey, base, sync, decimals ) => {
			if ( METRICS.includes( metric ) ) {
				rows.push( [
					`${ label } — ${ spanKey }`,
					fmt( base, decimals ),
					fmt( sync, decimals ),
					delta( base, sync, decimals ),
					pct( base, sync ),
				] );
			}
		};
		for ( const spanKey of [ 'editing', 'idle' ] ) {
			const span = entry.spans[ spanKey ];
			push(
				'requests',
				'requests/min',
				spanKey,
				span.baseClient.requestsPerMinute,
				span.client.requestsPerMinute,
				1
			);
			push(
				'traffic',
				'network KB/min',
				spanKey,
				span.baseClient.kbPerMinute,
				span.client.kbPerMinute,
				1
			);
			push(
				'cpu',
				'server CPU ms/min',
				spanKey,
				span.baseServer?.cpuMsPerMinute ?? null,
				span.server?.cpuMsPerMinute ?? null,
				1
			);
			push(
				'workers',
				'PHP worker share',
				spanKey,
				span.baseServer?.workerShare ?? null,
				span.server?.workerShare ?? null,
				3
			);
		}
		if ( METRICS.includes( 'memory' ) ) {
			const base = entry.spans.editing.baseServer;
			const sync = entry.spans.editing.server;
			rows.push( [
				'peak PHP memory MB/request',
				base ? fmt( base.peakMemoryMaxMb, 1 ) : '—',
				sync ? fmt( sync.peakMemoryMaxMb, 1 ) : '—',
				base && sync
					? delta( base.peakMemoryMaxMb, sync.peakMemoryMaxMb, 1 )
					: '—',
				base && sync
					? pct( base.peakMemoryMaxMb, sync.peakMemoryMaxMb )
					: '—',
			] );
		}

		const labelWidth = Math.max(
			...rows.map( ( row ) => row[ 0 ].length ),
			26
		);
		const col = 12;
		console.log( '' );
		console.log(
			`── ${ entry.engine } over ${ entry.transport } — per person ` +
				`(${ env.windows } collaborating vs ${ env.windows } editing ` +
				`in series, ${ env.editSeconds }s editing + a save per ` +
				`person, ${ env.idleSeconds }s idle) ──`
		);
		console.log(
			`${ ''.padEnd( labelWidth ) }   ${ 'baseline'.padEnd(
				col
			) }${ 'sync'.padEnd( col ) }${ 'delta'.padEnd( col ) }delta %`
		);
		for ( const row of rows ) {
			console.log(
				`${ row[ 0 ].padEnd( labelWidth ) }   ${ row[ 1 ].padEnd(
					col
				) }${ row[ 2 ].padEnd( col ) }${ row[ 3 ].padEnd( col ) }${
					row[ 4 ]
				}`
			);
		}

		// The whole-job line: both phases produced the same final
		// document, so the totals are directly comparable.
		const job = entry.job;
		const cpu = ( value ) =>
			null === value ? '—' : `${ value.toFixed( 1 ) } CPU-s`;
		console.log(
			`producing the same document: ${ job.base.requests } requests / ` +
				`${ cpu( job.base.serverCpuS ) } / ${ Math.round(
					job.base.kb
				) } KB in series → ${ job.sync.requests } / ${ cpu(
					job.sync.serverCpuS
				) } / ${ Math.round( job.sync.kb ) } KB collaboratively ` +
				`(${ pct( job.base.requests, job.sync.requests ) } / ${ pct(
					job.base.serverCpuS,
					job.sync.serverCpuS
				) } / ${ pct( job.base.kb, job.sync.kb ) })`
		);
	}

	console.log( '' );
	if ( ! env.muMeasurement ) {
		console.log(
			'baseline server columns unavailable: the whole-request ' +
				'measurement mu-plugin recorded nothing — map ' +
				'tests/benchmarks/host/mu-bench-log.php into mu-plugins ' +
				'(this repo’s wp-env configs do; restart the env once) ' +
				'and rerun'
		);
	}
	if ( report.environment.server ) {
		const server = report.environment.server;
		console.log(
			`server: PHP ${ server.php_version }, WordPress ${ server.wp_version }, ` +
				`MySQL ${ server.mysql_version } — compare runs only across identical environments`
		);
	}
	console.log(
		'server rows cover every tagged PHP request from the editor ' +
			'windows (page loads, heartbeat, autosaves, sync); static ' +
			'files never reach PHP and appear only in the client-side rows'
	);
}

main().catch( ( error ) => {
	console.error( String( error?.message ?? error ) );
	if ( error?.stack ) {
		console.error(
			String( error.stack ).split( '\n' ).slice( 1 ).join( '\n' )
		);
	}
	process.exit( 1 );
} );
