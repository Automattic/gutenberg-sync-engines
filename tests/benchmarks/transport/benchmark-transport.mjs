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
 *   engine=     intent-log | yjs-relay | current (default: current)
 *   trials=     measured token round-trips (default 30)
 *   warmup=     unmeasured leading trials (default 3)
 *   idle=       seconds of idle-traffic measurement (default 30, 0 skips)
 *   json=       write full results as JSON to this path
 *   headed=1    run with a visible browser (debugging)
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
		longPollRequests: 0,
		wsFramesSent: 0,
		wsFramesReceived: 0,
		wsBytesSent: 0,
		wsBytesReceived: 0,
	};
	const isSync = ( url ) => url.includes( 'wp-sync/v1' );
	page.on( 'request', ( request ) => {
		if ( ! isSync( request.url() ) ) {
			return;
		}
		c.requests += 1;
		c.requestBytes += request.postDataBuffer()?.length ?? 0;
		if ( request.url().includes( '/long-poll' ) ) {
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

async function main() {
	if ( ! Number.isFinite( TRIALS ) || TRIALS < 1 ) {
		throw new Error( 'trials must be a positive number' );
	}
	const browser = await chromium.launch( { headless: ! HEADED } );
	let settings = null;
	let pageA = null;
	try {
		const contextA = await browser.newContext();
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

		// Helper: wait until a window's sync session is live (completed
		// sync requests observed on the wire).
		const waitForSyncTraffic = async ( page, counters, label ) => {
			const deadline = Date.now() + 30000;
			while ( counters.snapshot().requests < 2 ) {
				if ( Date.now() > deadline ) {
					throw new Error(
						`Window ${ label } never started syncing (no ` +
							'/wp-sync/v1/ traffic in 30s). Is collaboration ' +
							'enabled and are both plugins active?'
					);
				}
				await page.waitForTimeout( 250 );
			}
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
		const countersB = attachCounters( pageB );
		const consoleErrors = { a: [], b: [] };
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

		// Window A types the anchor paragraph; window B must receive it
		// through the sync stack — this gates on collaboration working.
		await pageA
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.locator( 'role=button[name="Add default block"i]' )
			.click();
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
			await pageA.keyboard.insertText( ` ${ token }` );
			const sentAt = await pageA
				.waitForFunction(
					( t ) => window.__benchSeen[ t ] ?? false,
					token,
					{ timeout: 5000 }
				)
				.then( ( handle ) => handle.jsonValue() );
			const seenAt = await pageB
				.waitForFunction(
					( t ) => window.__benchSeen[ t ] ?? false,
					token,
					{ timeout: 30000, polling: 100 }
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

		// Self-label the transport from the traffic actually observed.
		const finalA = countersA.snapshot();
		let observedTransport = 'none';
		if ( finalA.wsFramesSent + finalA.wsFramesReceived > 0 ) {
			observedTransport = 'websocket';
		} else if ( finalA.longPollRequests > 0 ) {
			observedTransport = 'http-long-polling';
		} else if ( finalA.requests > 0 ) {
			observedTransport = 'http-polling';
		}
		if ( observedTransport !== settings.active.transport ) {
			console.warn(
				`WARNING: requested transport "${ settings.active.transport }" ` +
					`but observed "${ observedTransport }" on the wire — a ` +
					'WP_COLLABORATION_TRANSPORT constant/env override on the ' +
					'site, or a failed negotiation, is likely.'
			);
		}

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
			activePhase: { durationMs: trialMs, ...active },
			idlePhase: idle,
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
		for ( const [ label, phase ] of [
			[ 'editing', active ],
			...( idle ? [ [ 'idle   ', idle ] ] : [] ),
		] ) {
			for ( const key of [ 'a', 'b' ] ) {
				const t = phase[ key ];
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
		console.log(
			`── observed transport: ${ observedTransport } ` +
				`(engine ${ settings.active.engine }) ──`
		);

		if ( JSON_PATH ) {
			fs.writeFileSync( JSON_PATH, JSON.stringify( summary, null, 2 ) );
			console.log( `json written: ${ JSON_PATH }` );
		}
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
	console.error( String( error?.stack ?? error ) );
	process.exit( 1 );
} );
