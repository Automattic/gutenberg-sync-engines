/**
 * Transport experience benchmark: edit-to-visible propagation latency and
 * wire traffic per collaborator, measured with two real browser clients.
 *
 *   node tests/benchmarks/transport/benchmark-transport.mjs \
 *       transport=http-polling trials=30 idle=30 json=out.json
 *
 * Two browser windows (same logged-in session — separate sessions trip the
 * same-user post-lock takeover flow) collaborate on a fresh post. Window A
 * inserts unique tokens; both windows record —
 * with in-page `Date.now()` stamps taken inside a `wp.data.subscribe`
 * watcher, so no automation IPC skew — when each token lands in their block
 * store. Latency = B's arrival stamp minus A's local-echo stamp. Trial
 * spacing is jittered so arrivals sample the transport's polling phase
 * rather than locking to it.
 *
 * Alongside latency it counts every `/wp-sync/v1/` request and WebSocket
 * frame per window (body bytes only — HTTP headers are not included), both
 * while editing and over a configurable idle phase. The idle numbers are the
 * per-collaborator carrying cost hosts care about.
 *
 * Arguments are bare `key=value` tokens (same convention as the engine
 * benchmark):
 *
 *   transport=  http-polling | http-long-polling | websocket | current
 *               Switched via the Settings → Collaboration screen and
 *               restored afterwards. Default: current (no switch).
 *   engine=     intent-log | yjs-server | current (default: current)
 *   trials=     measured token round-trips (default 30)
 *   warmup=     unmeasured leading trials (default 3)
 *   idle=       seconds of idle-traffic measurement (default 30, 0 skips)
 *   baseline=   ambient-overhead samples before the trials (default 10,
 *               0 skips): unauthenticated GET /wp/v2/types round-trips
 *               (client-timed, keep-alive) plus the same number of tagged
 *               empty RTC polls (scenario=baseline, approach=baseline) —
 *               the community harness's baseline convention, which its
 *               report normalizes every scenario against
 *   json=       write full results as JSON to this path
 *   headed=1    run with a visible browser (debugging)
 *
 * Every /wp-sync/ request the two windows make is tagged with the
 * community RTC performance harness's headers (X-RTC-Test,
 * X-RTC-Scenario: editing|idle), so a site with this plugin's diagnostics
 * enabled (local/development wp-env, or the
 * GUTENBERG_SYNC_ENGINES_DIAGNOSTICS constant) records per-request
 * server-side metrics — dispatch ms, CPU ms, db_queries, db_time (needs
 * SAVEQUERIES), peak memory, concurrency — which this tool clears before
 * the run and folds into the summary afterwards (`serverSide`). Without
 * the diagnostics module the tags are inert and `serverSide` is null.
 *
 * Requires: a running env with the Gutenberg subtree + this plugin active,
 * and an admin login (WP_USERNAME/WP_PASSWORD, default admin/password).
 * WP_BASE_URL defaults to the wp-env tests site (http://localhost:8889).
 * Collaboration is enabled automatically (writing settings) if it is not
 * already. The websocket transport additionally needs the sync-server
 * daemon running on a host-reachable address — see the README.
 *
 * Caveat: if the target site pins the transport via the
 * WP_COLLABORATION_TRANSPORT constant or env var, the settings screen value
 * is ignored; the benchmark detects the actually-negotiated transport from
 * the observed traffic and warns on a mismatch.
 */
import fs from 'node:fs';
import { chromium } from '@playwright/test';

const BASE = process.env.WP_BASE_URL ?? 'http://localhost:8889';
const USER = process.env.WP_USERNAME ?? 'admin';
const PASS = process.env.WP_PASSWORD ?? 'password';

const opts = Object.fromEntries(
	process.argv.slice( 2 ).map( ( token ) => {
		const eq = token.indexOf( '=' );
		return eq === -1
			? [ token, true ]
			: [ token.slice( 0, eq ), token.slice( eq + 1 ) ];
	} )
);

const TRANSPORT = String( opts.transport ?? 'current' );
const ENGINE = String( opts.engine ?? 'current' );
const TRIALS = Number( opts.trials ?? 30 );
const WARMUP = Number( opts.warmup ?? 3 );
const IDLE_SECONDS = Number( opts.idle ?? 30 );
const BASELINE_POLLS = Number( opts.baseline ?? 10 );
const JSON_PATH = opts.json ? String( opts.json ) : null;
const HEADED = Boolean( opts.headed );

const SETTINGS_PAGE =
	'/wp-admin/options-general.php?page=gutenberg-sync-engines';

/**
 * Attaches wire-traffic counters for `/wp-sync/v1/` requests and WebSocket
 * frames to a page. Counters are cumulative; phases diff snapshots.
 *
 * @param {import('@playwright/test').Page} page Target page.
 * @return {Object} Counter handle with a snapshot() method.
 */
function attachCounters( page ) {
	const c = {
		requests: 0,
		requestBytes: 0,
		responseBytes: 0,
		// Data-plane requests only (/updates, /long-poll) — `requests`
		// also counts auxiliary routes like /ws-token, whose retry loop
		// must not read as a live session.
		dataRequests: 0,
		longPollRequests: 0,
		wsFramesSent: 0,
		wsFramesReceived: 0,
		wsBytesSent: 0,
		wsBytesReceived: 0,
	};
	// Decode before matching: on plain-permalink sites REST routes travel
	// URL-encoded (`index.php?rest_route=%2Fwp-sync%2Fv1%2Fupdates`).
	const decoded = ( url ) => {
		try {
			return decodeURIComponent( url );
		} catch {
			return url;
		}
	};
	const isSync = ( url ) => decoded( url ).includes( 'wp-sync/v1' );
	page.on( 'request', ( request ) => {
		const url = decoded( request.url() );
		if ( ! url.includes( 'wp-sync/v1' ) ) {
			return;
		}
		c.requests += 1;
		c.requestBytes += request.postDataBuffer()?.length ?? 0;
		if ( url.includes( '/updates' ) ) {
			c.dataRequests += 1;
		}
		if ( url.includes( '/long-poll' ) ) {
			c.dataRequests += 1;
			c.longPollRequests += 1;
		}
	} );
	page.on( 'response', async ( response ) => {
		if ( ! isSync( response.url() ) ) {
			return;
		}
		try {
			c.responseBytes += ( await response.body() ).length;
		} catch {
			// Response body unavailable (navigation, abort): skip its bytes.
		}
	} );
	page.on( 'websocket', ( socket ) => {
		const frameBytes = ( frame ) =>
			typeof frame.payload === 'string'
				? Buffer.byteLength( frame.payload )
				: frame.payload.length;
		socket.on( 'framesent', ( frame ) => {
			c.wsFramesSent += 1;
			c.wsBytesSent += frameBytes( frame );
		} );
		socket.on( 'framereceived', ( frame ) => {
			c.wsFramesReceived += 1;
			c.wsBytesReceived += frameBytes( frame );
		} );
	} );
	return {
		snapshot: () => ( { ...c } ),
	};
}

/**
 * Diffs two counter snapshots into per-minute rates.
 *
 * @param {Object} before    Earlier snapshot.
 * @param {Object} after     Later snapshot.
 * @param {number} elapsedMs Elapsed time between them.
 * @return {Object} Absolute deltas plus per-minute rates.
 */
function diffCounters( before, after, elapsedMs ) {
	const delta = {};
	for ( const key of Object.keys( before ) ) {
		delta[ key ] = after[ key ] - before[ key ];
	}
	const perMinute = ( value ) =>
		elapsedMs > 0 ? ( value / elapsedMs ) * 60000 : 0;
	return {
		...delta,
		requestsPerMinute: perMinute( delta.requests ),
		requestBytesPerMinute: perMinute( delta.requestBytes ),
		responseBytesPerMinute: perMinute( delta.responseBytes ),
		wsBytesPerMinute: perMinute(
			delta.wsBytesSent + delta.wsBytesReceived
		),
	};
}

/**
 * Floor-index percentile over a sorted array (matches the engine
 * benchmark's percentile method).
 *
 * @param {number[]} sorted Ascending values.
 * @param {number}   p      Percentile 0–100.
 * @return {number} The percentile value.
 */
function percentile( sorted, p ) {
	const index = Math.min(
		sorted.length - 1,
		Math.floor( ( p / 100 ) * sorted.length )
	);
	return sorted[ index ];
}

/**
 * Logs into wp-admin on a fresh page of the given context.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @return {Promise<import('@playwright/test').Page>} Logged-in page.
 */
async function login( context ) {
	const page = await context.newPage();
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	return page;
}

/**
 * Dismisses the editor welcome guide if it is showing.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function dismissWelcomeGuide( page ) {
	await page
		.getByRole( 'button', { name: /Close|Get started/i } )
		.first()
		.click( { timeout: 4000 } )
		.catch( () => {} );
}

/**
 * Reads current engine/transport from the settings screen and switches
 * either when requested. Returns previous and active values.
 *
 * @param {import('@playwright/test').Page} page      Logged-in admin page.
 * @param {string}                          engine    Requested engine slug or 'current'.
 * @param {string}                          transport Requested transport slug or 'current'.
 * @return {Promise<Object>} { previous, active } engine/transport values.
 */
async function configureSettings( page, engine, transport ) {
	await page.goto( `${ BASE }${ SETTINGS_PAGE }` );
	const engineSelect = page.locator( '#wp_sync_engine' );
	const transportSelect = page.locator( '#gutenberg_sync_engines_transport' );
	if ( ! ( await engineSelect.count() ) ) {
		throw new Error(
			'Settings → Collaboration screen not found. Are the gutenberg ' +
				'and gutenberg-sync-engines plugins active on ' +
				`${ BASE }?`
		);
	}
	const previous = {
		engine: await engineSelect.inputValue(),
		transport: await transportSelect.inputValue(),
	};
	const wanted = {
		engine: engine === 'current' ? previous.engine : engine,
		transport: transport === 'current' ? previous.transport : transport,
	};
	if (
		wanted.engine !== previous.engine ||
		wanted.transport !== previous.transport
	) {
		await engineSelect.selectOption( wanted.engine );
		await transportSelect.selectOption( wanted.transport );
		await page.click( '#submit' );
		await page.waitForURL( /settings-updated=true/ );
	}
	return { previous, active: wanted };
}

/**
 * Restores previously stored engine/transport settings.
 *
 * @param {import('@playwright/test').Page} page     Logged-in admin page.
 * @param {Object}                          previous { engine, transport } to restore.
 */
async function restoreSettings( page, previous ) {
	await page.goto( `${ BASE }${ SETTINGS_PAGE }` );
	await page.locator( '#wp_sync_engine' ).selectOption( previous.engine );
	await page
		.locator( '#gutenberg_sync_engines_transport' )
		.selectOption( previous.transport );
	await page.click( '#submit' );
	await page.waitForURL( /settings-updated=true/ );
}

/**
 * Ensures the framework's collaboration option is enabled (writing
 * settings checkbox), as the e2e fixtures do.
 *
 * @param {import('@playwright/test').Page} page Logged-in admin page.
 */
async function ensureCollaborationEnabled( page ) {
	await page.goto( `${ BASE }/wp-admin/options-writing.php` );
	const checkbox = page.locator( '#wp_collaboration_enabled' );
	if ( ! ( await checkbox.count() ) ) {
		throw new Error(
			'The collaboration checkbox is missing from Settings → Writing. ' +
				'Is the Gutenberg framework active and collaboration allowed ' +
				'(wp_is_collaboration_allowed)?'
		);
	}
	if ( ! ( await checkbox.isChecked() ) ) {
		await checkbox.check();
		await page.click( '#submit' );
		await page.waitForURL( /settings-updated=true/ );
	}
}

/**
 * Installs the in-page arrival watcher: a `wp.data.subscribe` listener that
 * stamps `Date.now()` the first time each token in `window.__benchTokens`
 * appears in the block store. Stamps are taken inside the page, so
 * automation IPC latency never contaminates the measurement.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function installWatcher( page ) {
	await page.evaluate( () => {
		window.__benchSeen = {};
		window.__benchTokens = [];
		const text = () => {
			let out = '';
			const walk = ( blocks ) => {
				for ( const block of blocks ) {
					out += String( block.attributes?.content ?? '' ) + '\n';
					if ( block.innerBlocks?.length ) {
						walk( block.innerBlocks );
					}
				}
			};
			walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
			return out;
		};
		window.wp.data.subscribe( () => {
			if ( ! window.__benchTokens.length ) {
				return;
			}
			const current = text();
			for ( const token of window.__benchTokens ) {
				if (
					! window.__benchSeen[ token ] &&
					current.includes( token )
				) {
					window.__benchSeen[ token ] = Date.now();
				}
			}
		} );
	} );
}

/**
 * Formats a byte count as KB with one decimal.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted value.
 */
const kb = ( bytes ) => ( bytes / 1024 ).toFixed( 1 );

/**
 * A REST path as a rest_route URL (works under every permalink structure).
 *
 * @param {string} path REST route path (e.g. /wp-sync/v1/updates).
 * @return {string} Absolute URL.
 */
const restUrl = ( path ) =>
	`${ BASE }/index.php?rest_route=${ encodeURIComponent( path ) }`;

/**
 * Tags every /wp-sync/ request from the context with the community RTC
 * performance harness's headers (X-RTC-Test, X-RTC-Scenario), so the
 * plugin's diagnostics request log attributes rows to the current phase.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {{ value: string }}                         phase   Mutable phase label.
 */
async function installScenarioTagging( context, phase ) {
	const isSync = ( url ) => {
		try {
			return decodeURIComponent( url ).includes( 'wp-sync/v1' );
		} catch {
			return url.includes( 'wp-sync/v1' );
		}
	};
	await context.route(
		( url ) => isSync( url.href ),
		async ( route ) => {
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
 * Authenticated REST helper over a logged-in page's request context (shares
 * its cookies; the nonce comes from the page's own wpApiSettings).
 *
 * @param {import('@playwright/test').Page} page Logged-in editor page.
 * @return {Promise<Object|null>} { get, post, del } helpers, or null when no
 *                                nonce is obtainable.
 */
async function makeRestClient( page ) {
	let nonce = await page
		.evaluate( () => window.wpApiSettings?.nonce )
		.catch( () => null );
	if ( ! nonce ) {
		const response = await page.request.get(
			`${ BASE }/wp-admin/admin-ajax.php?action=rest-nonce`
		);
		const text = ( await response.text() ).trim();
		nonce = /^[a-f0-9]{10}$/.test( text ) ? text : null;
	}
	if ( ! nonce ) {
		return null;
	}
	const call = async ( method, path, { body, headers } = {} ) => {
		const response = await page.request.fetch( restUrl( path ), {
			method,
			headers: {
				'content-type': 'application/json',
				'x-wp-nonce': nonce,
				...( headers ?? {} ),
			},
			data: body ? JSON.stringify( body ) : undefined,
		} );
		let data = null;
		try {
			data = await response.json();
		} catch {
			// Non-JSON body: leave data null.
		}
		return { status: response.status(), data };
	};
	return {
		get: ( path, init ) => call( 'GET', path, init ),
		post: ( path, init ) => call( 'POST', path, init ),
		del: ( path, init ) => call( 'DELETE', path, init ),
	};
}

/**
 * The community harness's baseline convention: N unauthenticated
 * GET /wp/v2/types round-trips (ambient REST overhead, client-timed over a
 * kept-alive connection) plus N tagged empty RTC polls
 * (scenario=baseline, approach=baseline) recorded by the server-side log.
 *
 * @param {Object} rest  REST client from makeRestClient (may be null).
 * @param {string} room  Room to poll.
 * @param {number} polls Sample count.
 * @return {Promise<Object>} Client-side baseline stats.
 */
async function runBaseline( rest, room, polls ) {
	const totals = [];
	for ( let i = 0; i < polls; i++ ) {
		const start = Date.now();
		await fetch( restUrl( '/wp/v2/types' ) );
		totals.push( Date.now() - start );
	}
	if ( rest ) {
		for ( let i = 0; i < polls; i++ ) {
			await rest.post( '/wp-sync/v1/updates', {
				body: {
					rooms: [
						{
							room,
							client_id: 10001,
							// null = publish no awareness state: a synthetic
							// state (no user object) crashes the editor's
							// collaborator-avatar UI in any open window.
							awareness: null,
							after: 0,
							updates: [],
						},
					],
				},
				headers: {
					'x-rtc-test': '1',
					'x-rtc-scenario': 'baseline',
					'x-rtc-approach': 'baseline',
				},
			} );
		}
	}
	totals.sort( ( a, b ) => a - b );
	const mean =
		totals.reduce( ( sum, value ) => sum + value, 0 ) / totals.length;
	return {
		polls,
		restTotalMs: {
			min: totals[ 0 ],
			p50: percentile( totals, 50 ),
			mean: Math.round( mean * 10 ) / 10,
			max: totals[ totals.length - 1 ],
		},
	};
}

/**
 * Folds the plugin's server-side request log into per-scenario aggregates
 * using the community harness's metric names. Returns null when the site
 * has no diagnostics module (404) or the log is unreadable.
 *
 * @param {Object} rest REST client from makeRestClient (may be null).
 * @return {Promise<Object|null>} Aggregates keyed by scenario.
 */
async function collectServerSide( rest ) {
	if ( ! rest ) {
		return null;
	}
	const response = await rest.get( '/rtc-test/v1/log' );
	if ( 200 !== response.status || ! Array.isArray( response.data ) ) {
		return null;
	}
	const byScenario = {};
	for ( const row of response.data ) {
		const scenario = row.scenario ?? 'unknown';
		const agg = ( byScenario[ scenario ] ??= {
			n: 0,
			ms_sum: 0,
			total_ms_sum: 0,
			cpu_ms_sum: 0,
			db_queries_sum: 0,
			db_time_ms_sum: 0,
			peak_memory_sum: 0,
			updates_in: 0,
			updates_out: 0,
			max_concurrent: 0,
		} );
		agg.n += 1;
		agg.ms_sum += row.ms;
		agg.total_ms_sum += row.total_ms;
		agg.cpu_ms_sum += row.cpu_ms;
		agg.db_queries_sum += row.db_queries;
		agg.db_time_ms_sum += row.db_time_ms;
		agg.peak_memory_sum += row.peak_memory;
		agg.updates_in += row.updates_in;
		agg.updates_out += row.updates_out;
		agg.max_concurrent = Math.max( agg.max_concurrent, row.concurrent );
	}
	const round1 = ( value ) => Math.round( value * 10 ) / 10;
	const out = {};
	for ( const [ scenario, agg ] of Object.entries( byScenario ) ) {
		out[ scenario ] = {
			n: agg.n,
			ms_avg: round1( agg.ms_sum / agg.n ),
			total_ms_avg: round1( agg.total_ms_sum / agg.n ),
			cpu_ms_avg: round1( agg.cpu_ms_sum / agg.n ),
			db_queries_avg: round1( agg.db_queries_sum / agg.n ),
			db_time_ms_avg: round1( agg.db_time_ms_sum / agg.n ),
			peak_memory_mb_avg: round1( agg.peak_memory_sum / agg.n / 1048576 ),
			updates_in: agg.updates_in,
			updates_out: agg.updates_out,
			max_concurrent: agg.max_concurrent,
		};
	}
	return out;
}

async function main() {
	if ( ! Number.isFinite( TRIALS ) || TRIALS < 1 ) {
		throw new Error( 'trials must be a positive number' );
	}
	const browser = await chromium.launch( { headless: ! HEADED } );
	let settings = null;
	let pageA = null;
	let pageBRef = null;
	const consoleErrors = { a: [], b: [] };
	try {
		const contextA = await browser.newContext();

		// Tag all /wp-sync/ traffic with the current phase so the plugin's
		// diagnostics request log (when present) attributes rows to it.
		const phase = { value: 'setup' };
		await installScenarioTagging( contextA, phase );

		pageA = await login( contextA );

		// Settings and collaboration are provisioned through window A's
		// session.
		settings = await configureSettings( pageA, ENGINE, TRANSPORT );
		await ensureCollaborationEnabled( pageA );

		// Second collaborator: a separate window in the same context (in
		// headless Chromium both report document.visibilityState ===
		// 'visible', which the tool asserts below — background-tab cadence
		// would invalidate the results).
		const contextB = contextA;

		const countersA = attachCounters( pageA );

		// Helper: wait until a window's sync session is live — gated on
		// DATA-PLANE traffic only (/updates, /long-poll POSTs or socket
		// frames). Auxiliary requests must not count: a dead websocket
		// setup retries /ws-token forever, which would read as "live".
		const waitForSyncTraffic = async ( page, counters, label ) => {
			const deadline = Date.now() + 30000;
			const live = () => {
				const c = counters.snapshot();
				return (
					c.dataRequests >= 2 ||
					c.wsFramesSent + c.wsFramesReceived >= 2
				);
			};
			while ( ! live() ) {
				if ( Date.now() > deadline ) {
					throw new Error(
						`Window ${ label } never started syncing (no ` +
							'/wp-sync/v1/ data traffic in 30s). Is ' +
							'collaboration enabled, are both plugins ' +
							'active, and — for websocket — is the daemon ' +
							'reachable?'
					);
				}
				await page.waitForTimeout( 250 );
			}
		};

		// Identifies the transport a counter set has actually used.
		const observeTransport = ( counters ) => {
			const c = counters.snapshot();
			if ( c.wsFramesSent + c.wsFramesReceived > 0 ) {
				return 'websocket';
			}
			if ( c.longPollRequests > 0 ) {
				return 'http-long-polling';
			}
			if ( c.dataRequests > 0 ) {
				return 'http-polling';
			}
			return 'none';
		};

		// Window A creates a fresh post.
		await pageA.goto( `${ BASE }/wp-admin/post-new.php` );
		await dismissWelcomeGuide( pageA );
		const postId = await pageA
			.waitForFunction( () =>
				window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
			)
			.then( ( handle ) => handle.jsonValue() );
		await waitForSyncTraffic( pageA, countersA, 'A' );

		// Window B joins BEFORE window A types anything. While a client is
		// solo its update queue is paused; content typed pre-join can be
		// clobbered when the joining peer's session initializes the room.
		// Typing only once both sessions are live avoids that race.
		const pageB = await contextB.newPage();
		pageBRef = pageB;
		const countersB = attachCounters( pageB );
		for ( const [ key, page ] of [
			[ 'a', pageA ],
			[ 'b', pageB ],
		] ) {
			page.on( 'pageerror', ( error ) =>
				consoleErrors[ key ].push( String( error ) )
			);
			page.on( 'console', ( message ) => {
				if ( 'error' === message.type() ) {
					consoleErrors[ key ].push( message.text() );
				}
			} );
		}
		await pageB.goto(
			`${ BASE }/wp-admin/post.php?post=${ postId }&action=edit`
		);
		await dismissWelcomeGuide( pageB );
		await waitForSyncTraffic( pageB, countersB, 'B' );
		await installWatcher( pageA );
		await installWatcher( pageB );

		// Let awareness register both clients: the update queues resume and
		// the transport switches to its with-collaborators cadence.
		await pageA.waitForTimeout( 3000 );

		// Verify the NEGOTIATED transport before measuring anything: a
		// WP_COLLABORATION_TRANSPORT constant/env override or a failed
		// negotiation must fail the run up front, not caveat it afterwards.
		const negotiated = observeTransport( countersA );
		if ( negotiated !== settings.active.transport ) {
			throw new Error(
				`Requested transport "${ settings.active.transport }" but ` +
					`the session negotiated "${ negotiated }" — check for a ` +
					'WP_COLLABORATION_TRANSPORT constant/env override on ' +
					'the site, or a failed client negotiation.'
			);
		}

		// Window A types the anchor paragraph; window B must receive it
		// through the sync stack — this gates on collaboration working.
		// The canvas may or may not be iframed (theme-dependent).
		const canvasA = ( await pageA
			.locator( 'iframe[name="editor-canvas"]' )
			.count() )
			? pageA.frameLocator( 'iframe[name="editor-canvas"]' )
			: pageA;
		try {
			await canvasA
				.locator( 'role=button[name="Add default block"i]' )
				.click();
		} catch ( error ) {
			await pageA
				.screenshot( { path: 'bench-fail-a.png' } )
				.catch( () => {} );
			await pageB
				.screenshot( { path: 'bench-fail-b.png' } )
				.catch( () => {} );
			throw new Error(
				'Could not click the block appender in window A ' +
					'(screenshots: bench-fail-a.png / bench-fail-b.png): ' +
					String( error ).split( '\n' )[ 0 ] +
					`\n  A console errors: ${ JSON.stringify(
						consoleErrors.a
					) }` +
					`\n  B console errors: ${ JSON.stringify(
						consoleErrors.b
					) }`
			);
		}
		await pageA.keyboard.type( 'benchanchor' );
		try {
			await pageA.waitForFunction(
				() =>
					(
						window.wp?.data
							?.select( 'core/block-editor' )
							?.getBlocks() ?? []
					).some( ( block ) =>
						String( block.attributes?.content ?? '' ).includes(
							'benchanchor'
						)
					),
				{ timeout: 10000 }
			);
		} catch {
			throw new Error(
				'The anchor paragraph did not survive in window A — its ' +
					'sync session may have been reset. Send-side failure; ' +
					'window B was open but never consulted.'
			);
		}
		try {
			await pageB.waitForFunction(
				() => {
					// RichText content is a RichTextData object; coerce with
					// String() rather than JSON-stringifying the block tree.
					const walk = ( blocks ) =>
						blocks.some(
							( block ) =>
								String(
									block.attributes?.content ?? ''
								).includes( 'benchanchor' ) ||
								( block.innerBlocks?.length &&
									walk( block.innerBlocks ) )
						);
					return walk(
						window.wp?.data
							?.select( 'core/block-editor' )
							?.getBlocks() ?? []
					);
				},
				{ timeout: 60000 }
			);
		} catch {
			const dump = async ( page ) =>
				page
					.evaluate( () =>
						window.wp?.data
							?.select( 'core/block-editor' )
							?.getBlocks()
							?.map( ( block ) =>
								String( block.attributes?.content ?? '' )
							)
					)
					.catch( ( error ) => `unreadable: ${ error }` );
			throw new Error(
				'Window B never received the anchor paragraph (60s). ' +
					'Collaboration is not syncing: check that both plugins ' +
					'are active, collaboration is enabled, and — for the ' +
					'websocket transport — that the sync-server daemon is ' +
					'running and reachable from this browser.\n' +
					`  A url: ${ pageA.url() }\n` +
					`  B url: ${ pageB.url() }\n` +
					`  A blocks: ${ JSON.stringify( await dump( pageA ) ) }\n` +
					`  B blocks: ${ JSON.stringify( await dump( pageB ) ) }\n` +
					`  A console errors: ${ JSON.stringify(
						consoleErrors.a
					) }\n` +
					`  B console errors: ${ JSON.stringify( consoleErrors.b ) }`
			);
		}

		const visibility = {
			a: await pageA.evaluate( () => document.visibilityState ),
			b: await pageB.evaluate( () => document.visibilityState ),
		};
		if ( 'visible' !== visibility.a || 'visible' !== visibility.b ) {
			console.warn(
				`WARNING: a window is not visible (A=${ visibility.a }, ` +
					`B=${ visibility.b }); background-tab cadence would ` +
					'invalidate latency results.'
			);
		}

		console.log(
			`transport benchmark: engine=${ settings.active.engine } ` +
				`transport=${ settings.active.transport } trials=${ TRIALS } ` +
				`warmup=${ WARMUP } post=${ postId }`
		);

		// Server-side log + baseline (community-harness conventions). The
		// REST client rides window A's session; a missing nonce or absent
		// diagnostics module degrades to client-side numbers only.
		const rest = await makeRestClient( pageA );
		if ( rest ) {
			// Isolate this run's server-side rows (404 when the site has no
			// diagnostics module — ignored).
			await rest.del( '/rtc-test/v1/log' );
		}
		let baseline = null;
		if ( BASELINE_POLLS > 0 ) {
			console.log( `baseline: ${ BASELINE_POLLS } ambient samples…` );
			baseline = await runBaseline(
				rest,
				`postType/post:${ postId }`,
				BASELINE_POLLS
			);
			console.log(
				`  GET /wp/v2/types total ms: min ${ baseline.restTotalMs.min } ` +
					`p50 ${ baseline.restTotalMs.p50 } mean ${ baseline.restTotalMs.mean } ` +
					`max ${ baseline.restTotalMs.max }`
			);
		}

		// The anchor paragraph, refocused before every insert: ambient
		// focus does not reliably survive the anchor gate (theme/editor
		// differences), and an unfocused insertText lands nowhere.
		const anchorParagraph = canvasA
			.locator( '[data-type="core/paragraph"]' )
			.last();

		phase.value = 'editing';
		const trialStart = Date.now();
		const startA = countersA.snapshot();
		const startB = countersB.snapshot();
		const latencies = [];
		const trials = [];
		const total = WARMUP + TRIALS;
		for ( let i = 0; i < total; i++ ) {
			const token = `bench${ String( i ).padStart( 3, '0' ) }x`;
			await pageA.evaluate( ( t ) => {
				window.__benchTokens = [ t ];
			}, token );
			await pageB.evaluate( ( t ) => {
				window.__benchTokens = [ t ];
			}, token );
			await anchorParagraph.click();
			await pageA.keyboard.press( 'End' );
			await pageA.keyboard.insertText( ` ${ token }` );
			const sentAt = await pageA
				.waitForFunction(
					( t ) => window.__benchSeen[ t ] ?? false,
					token,
					{
						timeout: 5000,
					}
				)
				.then( ( handle ) => handle.jsonValue() );
			const seenAt = await pageB
				.waitForFunction(
					( t ) => window.__benchSeen[ t ] ?? false,
					token,
					{
						timeout: 30000,
						polling: 100,
					}
				)
				.then( ( handle ) => handle.jsonValue() );
			const latency = seenAt - sentAt;
			const measured = i >= WARMUP;
			if ( measured ) {
				latencies.push( latency );
			}
			trials.push( { token, latency, measured } );
			console.log(
				`  ${ measured ? 'trial' : 'warmup' } ${ String( i ).padStart(
					3,
					'0'
				) }: ${ latency } ms`
			);
			// Jittered spacing (deterministic) samples the polling phase.
			await pageA.waitForTimeout( 400 + ( ( i * 137 ) % 1100 ) );
		}
		const trialMs = Date.now() - trialStart;
		const active = {
			a: diffCounters( startA, countersA.snapshot(), trialMs ),
			b: diffCounters( startB, countersB.snapshot(), trialMs ),
		};

		// Idle phase: both windows open and visible, nobody typing. This is
		// the steady-state carrying cost per collaborator.
		let idle = null;
		if ( IDLE_SECONDS > 0 ) {
			console.log( `idle phase: ${ IDLE_SECONDS }s…` );
			phase.value = 'idle';
			const idleStartA = countersA.snapshot();
			const idleStartB = countersB.snapshot();
			const idleStart = Date.now();
			await pageA.waitForTimeout( IDLE_SECONDS * 1000 );
			const idleMs = Date.now() - idleStart;
			idle = {
				a: diffCounters( idleStartA, countersA.snapshot(), idleMs ),
				b: diffCounters( idleStartB, countersB.snapshot(), idleMs ),
				seconds: idleMs / 1000,
			};
		}

		phase.value = 'post';

		// Self-label the transport from the traffic actually observed
		// (verified against the requested transport before trials above).
		const observedTransport = observeTransport( countersA );

		// Per-scenario server-side aggregates (community metric names) from
		// the plugin's diagnostics request log, when the site has one.
		const serverSide = await collectServerSide( rest );

		latencies.sort( ( x, y ) => x - y );
		const mean =
			latencies.reduce( ( sum, value ) => sum + value, 0 ) /
			latencies.length;
		const summary = {
			environment: {
				date: new Date().toISOString(),
				baseUrl: BASE,
				engine: settings.active.engine,
				transportRequested: settings.active.transport,
				transportObserved: observedTransport,
				trials: TRIALS,
				warmup: WARMUP,
				idleSeconds: IDLE_SECONDS,
				visibility,
			},
			latencyMs: {
				min: latencies[ 0 ],
				p50: percentile( latencies, 50 ),
				p90: percentile( latencies, 90 ),
				max: latencies[ latencies.length - 1 ],
				mean: Math.round( mean * 10 ) / 10,
			},
			baseline,
			activePhase: { durationMs: trialMs, ...active },
			idlePhase: idle,
			serverSide,
			trials,
		};

		console.log( '' );
		console.log( '── edit-to-visible latency (ms) ──' );
		console.log(
			`  min ${ summary.latencyMs.min }   p50 ${ summary.latencyMs.p50 }   ` +
				`p90 ${ summary.latencyMs.p90 }   max ${ summary.latencyMs.max }   ` +
				`mean ${ summary.latencyMs.mean }`
		);
		console.log( '── wire traffic (bodies only, per window) ──' );
		for ( const [ label, phaseCounters ] of [
			[ 'editing', active ],
			...( idle ? [ [ 'idle   ', idle ] ] : [] ),
		] ) {
			for ( const key of [ 'a', 'b' ] ) {
				const t = phaseCounters[ key ];
				const line =
					observedTransport === 'websocket'
						? `${ t.wsFramesSent + t.wsFramesReceived } frames, ` +
						  `${ kb( t.wsBytesSent + t.wsBytesReceived ) } KB ` +
						  `(${ kb( t.wsBytesPerMinute ) } KB/min)`
						: `${ t.requests } requests ` +
						  `(${ t.requestsPerMinute.toFixed( 1 ) }/min), ` +
						  `up ${ kb( t.requestBytes ) } KB, ` +
						  `down ${ kb( t.responseBytes ) } KB ` +
						  `(${ kb(
								t.requestBytesPerMinute +
									t.responseBytesPerMinute
						  ) } KB/min)`;
				console.log(
					`  ${ label }  window ${ key.toUpperCase() }: ${ line }`
				);
			}
		}
		if ( serverSide ) {
			console.log(
				'── server-side per-request metrics (per scenario) ──'
			);
			for ( const [ scenario, agg ] of Object.entries( serverSide ) ) {
				console.log(
					`  ${ scenario }: n=${ agg.n } disp_ms=${ agg.ms_avg } ` +
						`cpu_ms=${ agg.cpu_ms_avg } db_q=${ agg.db_queries_avg } ` +
						`db_t_ms=${ agg.db_time_ms_avg } mem_mb=${ agg.peak_memory_mb_avg } ` +
						`conc=${ agg.max_concurrent }`
				);
			}
		} else {
			console.log(
				'── no server-side metrics (site lacks the diagnostics request log; ' +
					'run against a local/development env or define ' +
					'GUTENBERG_SYNC_ENGINES_DIAGNOSTICS) ──'
			);
		}
		console.log(
			`── observed transport: ${ observedTransport } ` +
				`(engine ${ settings.active.engine }) ──`
		);

		if ( JSON_PATH ) {
			fs.writeFileSync( JSON_PATH, JSON.stringify( summary, null, 2 ) );
			console.log( `json written: ${ JSON_PATH }` );
		}
	} catch ( error ) {
		// Enrich any failure with the captured console errors and
		// screenshots — an editor crash (React error boundary) otherwise
		// surfaces only as an opaque timeout.
		await pageA
			?.screenshot( { path: 'bench-fail-a.png' } )
			.catch( () => {} );
		await pageBRef
			?.screenshot( { path: 'bench-fail-b.png' } )
			.catch( () => {} );
		error.message +=
			`\n  A console errors: ${ JSON.stringify( consoleErrors.a ) }` +
			`\n  B console errors: ${ JSON.stringify( consoleErrors.b ) }` +
			'\n  screenshots: bench-fail-a.png / bench-fail-b.png';
		throw error;
	} finally {
		const changedSettings =
			settings &&
			( settings.previous.engine !== settings.active.engine ||
				settings.previous.transport !== settings.active.transport );
		if ( changedSettings && pageA ) {
			await restoreSettings( pageA, settings.previous ).catch(
				( error ) =>
					console.warn(
						`WARNING: failed to restore settings (engine=` +
							`${ settings.previous.engine }, transport=` +
							`${ settings.previous.transport }): ${ error }`
					)
			);
		}
		await browser.close();
	}
}

main().catch( ( error ) => {
	// Message first: the failure paths append diagnostic context to it
	// after the stack string was captured.
	console.error( String( error?.message ?? error ) );
	if ( error?.stack ) {
		console.error(
			String( error.stack ).split( '\n' ).slice( 1 ).join( '\n' )
		);
	}
	process.exit( 1 );
} );
