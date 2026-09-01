/**
 * Shared plumbing for the browser-driven tools:
 * benchmark-transport.mjs (two-window latency/traffic benchmark), the
 * host benchmark (../host/), and the N-window soak
 * (tests/debugging/soak-transport.mjs). Everything here was extracted
 * verbatim from benchmark-transport.mjs — behavior changes belong in
 * the tools, not the library.
 */

export const BASE = process.env.WP_BASE_URL ?? 'http://localhost:8889';
export const USER = process.env.WP_USERNAME ?? 'admin';
export const PASS = process.env.WP_PASSWORD ?? 'password';

export const SETTINGS_PAGE =
	'/wp-admin/options-general.php?page=gutenberg-sync-engines';

// The Gutenberg experiment that turns real-time collaboration on.
export const COLLABORATION_EXPERIMENT = 'gutenberg-real-time-collaboration';

/**
 * Parses bare `key=value` CLI tokens (the engine benchmark's convention).
 * Leading dashes are accepted and stripped — `--poll=3` means `poll=3`,
 * and a bare `--headed` means `headed` — because the dashed habit is too
 * strong to fight and silently ignoring a mistyped flag costs a whole
 * benchmark run.
 *
 * @param {string[]} argv Argument vector (defaults to process.argv).
 * @return {Object} Parsed options.
 */
export function parseCliOptions( argv = process.argv.slice( 2 ) ) {
	return Object.fromEntries(
		argv.map( ( token ) => {
			const bare = token.replace( /^--?/, '' );
			const eq = bare.indexOf( '=' );
			return eq === -1
				? [ bare, true ]
				: [ bare.slice( 0, eq ), bare.slice( eq + 1 ) ];
		} )
	);
}

/**
 * Attaches wire-traffic counters for `/wp-sync/v1/` requests and WebSocket
 * frames to a page. Counters are cumulative; phases diff snapshots.
 *
 * @param {import('@playwright/test').Page} page Target page.
 * @return {Object} Counter handle with a snapshot() method.
 */
export function attachCounters( page ) {
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
	// De-rtc commits ride the autosave endpoint — count
	// them as sync traffic (they replaced transport proposal rows). The
	// commit shape is identified by its body, so editor-native autosaves
	// stay out of the tally.
	const isCommit = ( request ) =>
		decoded( request.url() ).includes( '/autosaves' ) &&
		( request.postDataBuffer()?.includes( 'proposal_id' ) ?? false );
	page.on( 'request', ( request ) => {
		const url = decoded( request.url() );
		if ( ! url.includes( 'wp-sync/v1' ) && ! isCommit( request ) ) {
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
		if ( ! isSync( response.url() ) && ! isCommit( response.request() ) ) {
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
export function diffCounters( before, after, elapsedMs ) {
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
export function percentile( sorted, p ) {
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
export async function login( context ) {
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
export async function dismissWelcomeGuide( page ) {
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
export async function configureSettings( page, engine, transport ) {
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
export async function restoreSettings( page, previous ) {
	await page.goto( `${ BASE }${ SETTINGS_PAGE }` );
	await page.locator( '#wp_sync_engine' ).selectOption( previous.engine );
	await page
		.locator( '#gutenberg_sync_engines_transport' )
		.selectOption( previous.transport );
	await page.click( '#submit' );
	await page.waitForURL( /settings-updated=true/ );
}

/**
 * Ensures real-time collaboration is on, as the e2e fixtures do. Since
 * WordPress/gutenberg#80658 the framework gates RTC on the
 * `gutenberg-real-time-collaboration` experiment rather than a Settings →
 * Writing checkbox, so this flips that experiment through the REST
 * settings endpoint and leaves the other experiments alone.
 *
 * @param {import('@playwright/test').Page} page Logged-in admin page.
 */
export async function ensureCollaborationEnabled( page ) {
	const rest = await makeRestClient( page );
	if ( ! rest ) {
		throw new Error(
			'Could not obtain a REST nonce for the admin session, so the ' +
				'collaboration experiment cannot be enabled.'
		);
	}
	const { status, data } = await rest.get( '/wp/v2/settings' );
	if ( 200 !== status || ! data ) {
		throw new Error(
			`GET /wp/v2/settings returned ${ status }. Are the gutenberg and ` +
				`gutenberg-sync-engines plugins active on ${ BASE }?`
		);
	}
	const experiments = { ...( data[ 'gutenberg-experiments' ] || {} ) };
	if ( experiments[ COLLABORATION_EXPERIMENT ] ) {
		return;
	}
	experiments[ COLLABORATION_EXPERIMENT ] = true;
	const updated = await rest.post( '/wp/v2/settings', {
		body: { 'gutenberg-experiments': experiments },
	} );
	if ( 200 !== updated.status ) {
		throw new Error(
			`Enabling the ${ COLLABORATION_EXPERIMENT } experiment failed ` +
				`(POST /wp/v2/settings returned ${ updated.status }).`
		);
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
export async function installWatcher( page ) {
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
export const kb = ( bytes ) => ( bytes / 1024 ).toFixed( 1 );

/**
 * A REST path as a rest_route URL (works under every permalink structure).
 *
 * @param {string} path REST route path (e.g. /wp-sync/v1/updates).
 * @return {string} Absolute URL.
 */
export const restUrl = ( path ) =>
	`${ BASE }/index.php?rest_route=${ encodeURIComponent( path ) }`;

/**
 * Tags every /wp-sync/ request from the context with the community RTC
 * performance harness's headers (X-RTC-Test, X-RTC-Scenario), so the
 * plugin's diagnostics request log attributes rows to the current phase.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {{ value: string }}                         phase   Mutable phase label.
 */
export async function installScenarioTagging( context, phase ) {
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
export async function makeRestClient( page ) {
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
		// A path may carry its own query (`/route?a=b`): only the route
		// part belongs inside rest_route; the query rides alongside it.
		const [ route, query ] = path.split( '?' );
		const url = restUrl( route ) + ( query ? `&${ query }` : '' );
		const response = await page.request.fetch( url, {
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
export async function runBaseline( rest, room, polls ) {
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
export async function collectServerSide( rest ) {
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

/**
 * Waits until a window's sync session is live — gated on DATA-PLANE
 * traffic only (/updates, /long-poll POSTs or socket frames). Auxiliary
 * requests must not count: a dead websocket setup retries /ws-token
 * forever, which would read as "live".
 *
 * @param {import('@playwright/test').Page} page     Editor page.
 * @param {Object}                          counters Counter handle for the page.
 * @param {string}                          label    Window label for errors.
 */
export async function waitForSyncTraffic( page, counters, label ) {
	const deadline = Date.now() + 30000;
	const live = () => {
		const c = counters.snapshot();
		return c.dataRequests >= 2 || c.wsFramesSent + c.wsFramesReceived >= 2;
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
}

/**
 * Identifies the transport a counter set has actually used.
 *
 * @param {Object} counters Counter handle.
 * @return {string} Observed transport slug or 'none'.
 */
export function observeTransport( counters ) {
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
}

/**
 * The editor canvas locator root for a page (iframed or not).
 *
 * @param {import('@playwright/test').Page} page Editor page.
 * @return {Promise<Object>} Frame locator or the page itself.
 */
export async function canvasOf( page ) {
	return ( await page.locator( 'iframe[name="editor-canvas"]' ).count() )
		? page.frameLocator( 'iframe[name="editor-canvas"]' )
		: page;
}
