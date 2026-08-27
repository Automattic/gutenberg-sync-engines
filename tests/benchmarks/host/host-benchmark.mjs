/**
 * The host cost report: what activating this plugin adds to a server,
 * measured as the difference against the SAME site with the plugin
 * deactivated, printed as one baseline/sync/delta table per engine.
 *
 *   npm run bench                            # this report, defaults
 *   npm run bench -- engines=intent-log,yjs-server,de-rtc windows=3
 *   npm run bench -- metrics=requests,cpu json=host.json
 *
 * Phases, all real browser windows against a live site:
 *
 *   1. BASELINE — the plugin is deactivated (every active copy, via the
 *      REST plugins endpoint) and one window edits a draft post in the
 *      block editor for `edit` seconds, then sits idle for `idle`
 *      seconds. This is the site a host runs today.
 *   2. PER ENGINE — the plugin is reactivated, real-time collaboration
 *      is enabled, and `windows` browser windows co-edit an identical
 *      draft (each typing into its own paragraph) on that engine and
 *      the chosen transport, same durations.
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
 * columns baseline | sync | delta.
 *
 * Arguments (bare key=value, like every benchmark here):
 *
 *   engines=    comma list of engines to measure (default: the site's
 *               current engine); engine= is an alias for a single one
 *   transport=  http-polling | http-long-polling | websocket | current
 *   windows=    collaborator windows per engine phase (default 2)
 *   edit=       editing seconds per phase (default 120, min 30)
 *   idle=       idle seconds per phase (default 120; 0 skips)
 *   metrics=    comma list to report: requests,traffic,cpu,workers,memory
 *               (default all)
 *   json=       write full results as JSON to this path
 *   headed=1    visible browser (debugging)
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

const ENGINES = String( opts.engines ?? opts.engine ?? 'current' )
	.split( ',' )
	.map( ( slug ) => slug.trim() )
	.filter( Boolean );
const TRANSPORT = String( opts.transport ?? 'current' );
const WINDOWS = Math.max( 1, Number( opts.windows ?? 2 ) );
const EDIT_SECONDS = Number( opts.edit ?? 120 );
const IDLE_SECONDS = Number( opts.idle ?? 120 );
const JSON_PATH = opts.json ? String( opts.json ) : null;
const HEADED = Boolean( opts.headed );
const ALL_METRICS = [ 'requests', 'traffic', 'cpu', 'workers', 'memory' ];
const METRICS = opts.metrics
	? String( opts.metrics )
			.split( ',' )
			.map( ( metric ) => metric.trim() )
			.filter( Boolean )
	: ALL_METRICS;

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
 * Runs one phase's editing + idle measurement over a set of windows.
 *
 * @param {Object[]} wins Window records.
 * @param {Object}   tag  Mutable { scenario, approach } labels.
 * @return {Promise<Object>} Per-window counter deltas and durations.
 */
async function measurePhase( wins, tag ) {
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
		wins.map( ( win ) =>
			editingDriver( win, deadline, editStats[ win.index ] )
		)
	);
	const editMs = Date.now() - editStart;
	const editAll = wins.map( ( win ) => win.all.snapshot() );
	const editSync = wins.map( ( win ) => win.sync.snapshot() );

	tag.scenario = 'host-idle';
	const idleStart = Date.now();
	if ( IDLE_SECONDS > 0 ) {
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
 * Mean per-minute rate of one client counter across a phase's windows.
 *
 * @param {Object} phase   measurePhase result.
 * @param {string} spanKey 'editing' or 'idle'.
 * @param {string} counter Counter name.
 * @return {number} Mean per-window rate per minute.
 */
function clientRatePerMinute( phase, spanKey, counter ) {
	const ms = 'editing' === spanKey ? phase.editMs : phase.idleMs;
	if ( ms <= 0 ) {
		return 0;
	}
	const sum = phase.perWindow.reduce(
		( total, win ) => total + win[ spanKey ].all[ counter ],
		0
	);
	return ( sum / phase.perWindow.length / ms ) * 60000;
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
 * Builds one engine's summary from its phase + the shared baseline.
 *
 * @param {Object} phase    The engine's measurePhase result.
 * @param {Object} baseline The baseline measurePhase result.
 * @param {Array}  rows     All server rows (may be empty).
 * @param {string} engine   Engine slug (the approach label).
 * @return {Object} Per-span baseline/sync/delta metric rows.
 */
function summarize( phase, baseline, rows, engine ) {
	const spans = {};
	for ( const spanKey of [ 'editing', 'idle' ] ) {
		const ms = 'editing' === spanKey ? phase.editMs : phase.idleMs;
		const baseMs =
			'editing' === spanKey ? baseline.editMs : baseline.idleMs;
		const client = {
			requestsPerMinute: clientRatePerMinute(
				phase,
				spanKey,
				'requests'
			),
			kbPerMinute:
				( clientRatePerMinute( phase, spanKey, 'requestBytes' ) +
					clientRatePerMinute( phase, spanKey, 'responseBytes' ) ) /
				1024,
		};
		const baseClient = {
			requestsPerMinute: clientRatePerMinute(
				baseline,
				spanKey,
				'requests'
			),
			kbPerMinute:
				( clientRatePerMinute( baseline, spanKey, 'requestBytes' ) +
					clientRatePerMinute(
						baseline,
						spanKey,
						'responseBytes'
					) ) /
				1024,
		};
		spans[ spanKey ] = {
			client,
			baseClient,
			server: serverRates(
				aggregateServerRows( rows, engine, `host-${ spanKey }` ),
				ms,
				WINDOWS
			),
			baseServer: serverRates(
				aggregateServerRows( rows, 'baseline', `host-${ spanKey }` ),
				baseMs,
				1
			),
		};
	}
	return spans;
}

async function main() {
	if ( ! Number.isFinite( EDIT_SECONDS ) || EDIT_SECONDS < 30 ) {
		throw new Error( 'edit must be at least 30 seconds' );
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
		await ensureCollaborationEnabled( adminPage );
		await rest.del( '/rtc-test/v1/log' ).catch( () => null );

		// ---------------- Phase 1: baseline (plugin deactivated) --------
		const baselinePost = await createDraft( rest, 'baseline' );
		console.log(
			`baseline phase: deactivating ${ activeCopies
				.map( ( copy ) => copy.plugin )
				.join(
					', '
				) } and editing post ${ baselinePost } in 1 window ` +
				`(${ EDIT_SECONDS }s editing, ${ IDLE_SECONDS }s idle)…`
		);
		for ( const copy of activeCopies ) {
			await setPluginStatus( rest, copy.plugin, 'inactive' );
			deactivated.push( copy.plugin );
		}

		const baselineWin = await openEditorWindow(
			context,
			measuredPages,
			baselinePost,
			0
		);
		const baseline = await measurePhase( [ baselineWin ], tag );
		await baselineWin.page.close();

		if ( baseline.perWindow[ 0 ].editing.sync.requests > 0 ) {
			throw new Error(
				'the baseline window made sync requests — the plugin was still active, so the comparison is meaningless'
			);
		}

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
				spans: summarize(
					entry.phase,
					baseline,
					serverRows,
					entry.engine
				),
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

	for ( const entry of report.engines ) {
		const rows = [];
		const push = ( metric, label, spanKey, base, sync, decimals ) => {
			if ( METRICS.includes( metric ) ) {
				rows.push( [
					`${ label } — ${ spanKey }`,
					fmt( base, decimals ),
					fmt( sync, decimals ),
					delta( base, sync, decimals ),
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
				`(${ env.windows } collaborating vs 1 baseline editor, ` +
				`${ env.editSeconds }s editing + ${ env.idleSeconds }s idle) ──`
		);
		console.log(
			`${ ''.padEnd( labelWidth ) }   ${ 'baseline'.padEnd(
				col
			) }${ 'sync'.padEnd( col ) }delta`
		);
		for ( const row of rows ) {
			console.log(
				`${ row[ 0 ].padEnd( labelWidth ) }   ${ row[ 1 ].padEnd(
					col
				) }${ row[ 2 ].padEnd( col ) }${ row[ 3 ] }`
			);
		}
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
