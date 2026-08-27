/**
 * The host cost report: what activating this plugin adds to a server,
 * measured as the difference against the SAME site with the plugin
 * deactivated.
 *
 *   npm run bench                            # this report, defaults
 *   npm run bench -- engine=de-rtc windows=3 edit=180 idle=180
 *   npm run bench -- metrics=requests,cpu json=host.json
 *
 * Two phases, same scripted editing session, real browser windows:
 *
 *   1. BASELINE — the plugin is deactivated (every active copy, via the
 *      REST plugins endpoint) and one window edits a draft post in the
 *      block editor for `edit` seconds, then sits idle for `idle`
 *      seconds. Every HTTP request the window makes is counted
 *      client-side. This is the site a host runs today.
 *   2. WITH THE PLUGIN — the plugin is reactivated, real-time
 *      collaboration is enabled, and `windows` browser windows co-edit
 *      an identical draft (each typing into its own paragraph) on the
 *      chosen engine and transport, same durations. Client-side
 *      counting again, plus the plugin's own sync requests are tagged
 *      (community-harness headers) so the diagnostics request log
 *      records their server cost: CPU, wall time, memory, DB queries.
 *
 * The report is the per-editor difference: extra requests per minute,
 * extra network traffic, server CPU spent on sync, the share of a PHP
 * worker the sync traffic holds, and peak PHP memory per sync request.
 * Because the baseline site serves no sync endpoints at all, the
 * plugin's server-side cost IS the measured cost of its sync requests;
 * the client-side request delta additionally catches any change to
 * ordinary editor traffic. A caveat the numbers cannot see: any extra
 * server cost the plugin adds INSIDE ordinary requests (page loads,
 * saves) shows up only in the client-side counts, not in the CPU
 * figures — profiling those needs site-level tooling (the community
 * harness's MU-plugin approach).
 *
 * Arguments (bare key=value, like every benchmark here):
 *
 *   engine=     intent-log | yjs-server | de-rtc | current (default)
 *   transport=  http-polling | http-long-polling | websocket | current
 *   windows=    collaborator windows in phase 2 (default 2)
 *   edit=       editing seconds per phase (default 120, min 30)
 *   idle=       idle seconds per phase (default 120; 0 skips)
 *   metrics=    comma list to report: requests,traffic,cpu,workers,memory
 *               (default all)
 *   json=       write full results as JSON to this path
 *   headed=1    visible browser (debugging)
 *
 * Requires a running environment with the plugin active at start (the
 * tests env: npm run env:tests start), Playwright's chromium installed,
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
	installScenarioTagging,
	login,
	makeRestClient,
	observeTransport,
	parseCliOptions,
	restoreSettings,
	waitForSyncTraffic,
} from '../transport/lib.mjs';

const opts = parseCliOptions();

const ENGINE = String( opts.engine ?? 'current' );
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
 * Counts EVERY HTTP request a page makes (any route, any host), unlike
 * lib.mjs's attachCounters, which counts sync traffic only. The baseline
 * phase has no sync traffic at all, so the host comparison needs the
 * whole wire. Counters are cumulative; phases diff snapshot() results.
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
 * Tags commit-shaped autosave requests (de-rtc commits carry a
 * proposal_id through the ordinary autosave endpoint) so the request
 * log measures their server cost under the current phase label.
 * Editor-native autosaves stay untagged and unmeasured — the baseline
 * pays those too, so measuring them only on one side would skew the
 * comparison.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {{ value: string }}                         phase   Mutable phase label.
 */
async function installCommitTagging( context, phase ) {
	const isAutosave = ( url ) => {
		try {
			return decodeURIComponent( url ).includes( '/autosaves' );
		} catch {
			return url.includes( '/autosaves' );
		}
	};
	await context.route(
		( url ) => isAutosave( url.href ),
		async ( route ) => {
			const isCommit =
				route.request().postData()?.includes( 'proposal_id' ) ?? false;
			if ( ! isCommit ) {
				return route.continue();
			}
			await route.continue( {
				headers: {
					...route.request().headers(),
					'x-rtc-test': '1',
					'x-rtc-scenario': phase.value,
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
 * @param {string} label Title suffix distinguishing the two phases.
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
 * ready. Counter attachment happens before navigation so the page-load
 * requests are countable (they are excluded by snapshotting after
 * settle).
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {number}                                    postId  Post to open.
 * @param {number}                                    index   Window index.
 * @return {Promise<Object>} Window record.
 */
async function openEditorWindow( context, postId, index ) {
	const page = await context.newPage();
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
 * @param {Object[]} wins      Window records.
 * @param {Object}   phase     Mutable scenario label { value } for tagging.
 * @param {string}   editLabel Scenario label for the editing span.
 * @param {string}   idleLabel Scenario label for the idle span.
 * @return {Promise<Object>} Per-window counter deltas and durations.
 */
async function measurePhase( wins, phase, editLabel, idleLabel ) {
	// Let the just-loaded pages settle so page-load assets and session
	// setup stay out of the rates.
	await wins[ 0 ].page.waitForTimeout( 3000 );

	phase.value = editLabel;
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

	phase.value = idleLabel;
	const idleStart = Date.now();
	if ( IDLE_SECONDS > 0 ) {
		await wins[ 0 ].page.waitForTimeout( IDLE_SECONDS * 1000 );
	}
	const idleMs = Date.now() - idleStart;
	const idleAll = wins.map( ( win ) => win.all.snapshot() );
	const idleSync = wins.map( ( win ) => win.sync.snapshot() );

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
 * Mean per-minute rate of one counter across a phase's windows.
 *
 * @param {Object} phase   measurePhase result.
 * @param {string} spanKey 'editing' or 'idle'.
 * @param {string} kind    'all' or 'sync'.
 * @param {string} counter Counter name.
 * @return {number} Mean per-window rate per minute.
 */
function ratePerMinute( phase, spanKey, kind, counter ) {
	const ms = 'editing' === spanKey ? phase.editMs : phase.idleMs;
	if ( ms <= 0 ) {
		return 0;
	}
	const sum = phase.perWindow.reduce(
		( total, win ) => total + win[ spanKey ][ kind ][ counter ],
		0
	);
	return ( sum / phase.perWindow.length / ms ) * 60000;
}

/**
 * Aggregates raw request-log rows for one scenario label.
 *
 * @param {Array<Object>} rows     Raw log rows.
 * @param {string}        scenario Scenario label to keep.
 * @return {Object} Sums, count, and memory extremes.
 */
function aggregateServerRows( rows, scenario ) {
	const kept = rows.filter( ( row ) => row.scenario === scenario );
	const sum = ( key ) =>
		kept.reduce( ( total, row ) => total + ( row[ key ] ?? 0 ), 0 );
	return {
		n: kept.length,
		cpuMsSum: sum( 'cpu_ms' ),
		totalMsSum: sum( 'total_ms' ),
		dbQueriesSum: sum( 'db_queries' ),
		peakMemoryMax: kept.reduce(
			( max, row ) => Math.max( max, row.peak_memory ?? 0 ),
			0
		),
		peakMemoryMean: kept.length ? sum( 'peak_memory' ) / kept.length : 0,
	};
}

const round1 = ( value ) => Math.round( value * 10 ) / 10;
const round3 = ( value ) => Math.round( value * 1000 ) / 1000;

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
	const phase = { value: 'setup' };
	await installScenarioTagging( context, phase );
	await installCommitTagging( context, phase );

	let settings = null;
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
		// server side is measured. When no copy is active (a PHPUnit run
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

		// Choose engine/transport and remember what to restore; record
		// whether the collaboration experiment was already on.
		settings = await configureSettings( adminPage, ENGINE, TRANSPORT );
		const settingsBefore = await rest.get( '/wp/v2/settings' );
		experimentWasOn = Boolean(
			settingsBefore.data?.[ 'gutenberg-experiments' ]?.[
				COLLABORATION_EXPERIMENT
			]
		);
		await ensureCollaborationEnabled( adminPage );

		const baselinePost = await createDraft( rest, 'baseline' );
		const pluginPost = await createDraft( rest, 'with-plugin' );

		// ---------------- Phase 1: baseline (plugin deactivated) --------
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

		const baselineWin = await openEditorWindow( context, baselinePost, 0 );
		const baseline = await measurePhase(
			[ baselineWin ],
			phase,
			'host-baseline-editing',
			'host-baseline-idle'
		);
		await baselineWin.page.close();

		if ( baseline.perWindow[ 0 ].editing.sync.requests > 0 ) {
			throw new Error(
				'the baseline window made sync requests — the plugin was still active, so the comparison is meaningless'
			);
		}

		// ---------------- Phase 2: with the plugin ----------------------
		for ( const plugin of deactivated ) {
			await setPluginStatus( rest, plugin, 'active' );
		}
		deactivated = [];

		console.log(
			`plugin phase: ${ WINDOWS } window(s) on post ${ pluginPost }, ` +
				`engine=${ settings.active.engine } transport=${ settings.active.transport }…`
		);
		await rest.del( '/rtc-test/v1/log' ).catch( () => null );

		phase.value = 'setup-join';
		const wins = [];
		for ( let index = 0; index < WINDOWS; index++ ) {
			const win = await openEditorWindow( context, pluginPost, index );
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

		const withPlugin = await measurePhase(
			wins,
			phase,
			'host-editing',
			'host-idle'
		);
		for ( const win of wins ) {
			// A window whose editing span produced no sync data traffic
			// had a dead session; its rates would understate the cost.
			const edited = win.sync
				? withPlugin.perWindow[ win.index ].editing.sync
				: null;
			if (
				edited &&
				0 === edited.dataRequests &&
				0 === edited.wsFramesSent + edited.wsFramesReceived
			) {
				throw new Error(
					`window ${ win.index } made no sync data traffic while editing — dead session, numbers unusable`
				);
			}
			await win.page.close();
		}

		phase.value = 'post';
		const logResponse = await rest.get( '/rtc-test/v1/log' );
		const serverRows =
			200 === logResponse.status && Array.isArray( logResponse.data )
				? logResponse.data
				: null;
		const envResponse = await rest.get( '/rtc-test/v1/env' );
		const serverEnv = 200 === envResponse.status ? envResponse.data : null;

		// ---------------- The report ------------------------------------
		const minutes = ( ms ) => ms / 60000;
		const serverEditing = serverRows
			? aggregateServerRows( serverRows, 'host-editing' )
			: null;
		const serverIdle = serverRows
			? aggregateServerRows( serverRows, 'host-idle' )
			: null;

		const clientDelta = ( spanKey ) => ( {
			requestsPerMinute: round1(
				ratePerMinute( withPlugin, spanKey, 'all', 'requests' ) -
					ratePerMinute( baseline, spanKey, 'all', 'requests' )
			),
			kbPerMinute: round1(
				( ratePerMinute( withPlugin, spanKey, 'all', 'requestBytes' ) +
					ratePerMinute(
						withPlugin,
						spanKey,
						'all',
						'responseBytes'
					) -
					ratePerMinute( baseline, spanKey, 'all', 'requestBytes' ) -
					ratePerMinute(
						baseline,
						spanKey,
						'all',
						'responseBytes'
					) ) /
					1024
			),
		} );
		const serverPerMinute = ( agg, ms ) =>
			agg && ms > 0
				? {
						cpuMsPerMinute: round1(
							agg.cpuMsSum / minutes( ms ) / WINDOWS
						),
						workerShare: round3( agg.totalMsSum / ms / WINDOWS ),
						dbQueriesPerMinute: round1(
							agg.dbQueriesSum / minutes( ms ) / WINDOWS
						),
						requestsPerMinute: round1(
							agg.n / minutes( ms ) / WINDOWS
						),
				  }
				: null;

		const report = {
			environment: {
				date: new Date().toISOString(),
				baseUrl: BASE,
				engine: settings.active.engine,
				transportRequested: settings.active.transport,
				transportObserved: observed,
				windows: WINDOWS,
				editSeconds: Math.round( withPlugin.editMs / 1000 ),
				idleSeconds: Math.round( withPlugin.idleMs / 1000 ),
				server: serverEnv,
			},
			baseline: {
				postId: baselinePost,
				windows: 1,
				editing: {
					requestsPerMinute: round1(
						ratePerMinute( baseline, 'editing', 'all', 'requests' )
					),
					kbPerMinute: round1(
						( ratePerMinute(
							baseline,
							'editing',
							'all',
							'requestBytes'
						) +
							ratePerMinute(
								baseline,
								'editing',
								'all',
								'responseBytes'
							) ) /
							1024
					),
				},
				idle: {
					requestsPerMinute: round1(
						ratePerMinute( baseline, 'idle', 'all', 'requests' )
					),
					kbPerMinute: round1(
						( ratePerMinute(
							baseline,
							'idle',
							'all',
							'requestBytes'
						) +
							ratePerMinute(
								baseline,
								'idle',
								'all',
								'responseBytes'
							) ) /
							1024
					),
				},
				detail: baseline,
			},
			withPlugin: {
				postId: pluginPost,
				detail: withPlugin,
			},
			perEditor: {
				editing: {
					...clientDelta( 'editing' ),
					server: serverPerMinute( serverEditing, withPlugin.editMs ),
				},
				idle: {
					...clientDelta( 'idle' ),
					server: serverPerMinute( serverIdle, withPlugin.idleMs ),
				},
				peakSyncMemoryMb:
					serverRows && serverEditing.n + serverIdle.n > 0
						? {
								max: round1(
									Math.max(
										serverEditing.peakMemoryMax,
										serverIdle.peakMemoryMax
									) / 1048576
								),
								mean: round1(
									( serverEditing.peakMemoryMean *
										serverEditing.n +
										serverIdle.peakMemoryMean *
											serverIdle.n ) /
										( serverEditing.n + serverIdle.n ) /
										1048576
								),
						  }
						: null,
			},
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
			settings &&
			adminPage &&
			( settings.previous.engine !== settings.active.engine ||
				settings.previous.transport !== settings.active.transport )
		) {
			await restoreSettings( adminPage, settings.previous ).catch(
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
 * Renders the host report to the console: one small table of deltas, a
 * context block, honest caveats.
 *
 * @param {Object} report Assembled report.
 */
function printReport( report ) {
	const env = report.environment;
	console.log( '' );
	console.log(
		`── what real-time collaboration adds, per person editing ──`
	);
	console.log(
		`engine ${ env.engine }, transport ${ env.transportObserved }, ` +
			`${ env.windows } collaborator(s), ${ env.editSeconds }s editing + ${ env.idleSeconds }s idle`
	);
	console.log(
		`baseline: the same site with the plugin deactivated (1 editor)`
	);
	console.log( '' );

	const rows = [];
	const editing = report.perEditor.editing;
	const idle = report.perEditor.idle;
	if ( METRICS.includes( 'requests' ) ) {
		rows.push( [
			'extra requests per minute',
			`+${ editing.requestsPerMinute }`,
			`+${ idle.requestsPerMinute }`,
		] );
	}
	if ( METRICS.includes( 'traffic' ) ) {
		rows.push( [
			'extra network traffic (KB/min)',
			`+${ editing.kbPerMinute }`,
			`+${ idle.kbPerMinute }`,
		] );
	}
	if ( METRICS.includes( 'cpu' ) && editing.server ) {
		rows.push( [
			'server CPU on sync (ms/min)',
			String( editing.server.cpuMsPerMinute ),
			String( idle.server?.cpuMsPerMinute ?? 0 ),
		] );
	}
	if ( METRICS.includes( 'workers' ) && editing.server ) {
		rows.push( [
			'share of one PHP worker held',
			String( editing.server.workerShare ),
			String( idle.server?.workerShare ?? 0 ),
		] );
	}
	if ( METRICS.includes( 'memory' ) && report.perEditor.peakSyncMemoryMb ) {
		rows.push( [
			'peak PHP memory per sync request',
			`${ report.perEditor.peakSyncMemoryMb.mean } MB`,
			`(max ${ report.perEditor.peakSyncMemoryMb.max } MB)`,
		] );
	}

	const width = Math.max( ...rows.map( ( row ) => row[ 0 ].length ), 30 );
	console.log( `${ ''.padEnd( width ) }   while editing   tab open, idle` );
	for ( const row of rows ) {
		console.log(
			`${ row[ 0 ].padEnd( width ) }   ${ row[ 1 ].padEnd( 13 ) }   ${
				row[ 2 ]
			}`
		);
	}

	console.log( '' );
	console.log(
		`baseline for context: ${ report.baseline.editing.requestsPerMinute } requests/min ` +
			`and ${ report.baseline.editing.kbPerMinute } KB/min while editing; ` +
			`${ report.baseline.idle.requestsPerMinute }/min and ${ report.baseline.idle.kbPerMinute } KB/min idle`
	);
	if ( ! editing.server ) {
		console.log(
			'server-side columns unavailable: this site has no diagnostics ' +
				'request log (local/development sites, or define ' +
				'GUTENBERG_SYNC_ENGINES_DIAGNOSTICS) — client-side deltas only'
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
		'server CPU/worker/memory figures cover the plugin’s own sync ' +
			'requests; cost added inside ordinary requests appears only in ' +
			'the request/traffic deltas'
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
