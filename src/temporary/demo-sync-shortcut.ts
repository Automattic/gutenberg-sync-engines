/**
 * TEMPORARY: demo tooling. Cmd+Shift+S (Ctrl+Shift+S elsewhere) in EITHER
 * editor window runs one sync round: user 1's window syncs, user 2's window
 * syncs, then user 1's again, then user 2's again (SEQUENCE), with
 * STEP_PAUSE_MS before every step after the first. Two passes let both
 * windows see each other's edits and any conflict they raise. Automatic
 * polling is held the whole time, so nothing moves over the wire except on
 * the shortcut.
 *
 * The two windows are different browsers, so they talk through the server:
 *
 *   1. The shortcut POSTs `start` to the demo-sync route. The server bumps a
 *      trigger id and sets stage 1.
 *   2. Every window polls that route every TRIGGER_POLL_MS. Each stage names
 *      one role (SEQUENCE[stage - 1]). The window with that role waits (the
 *      first stage waits SETTLE_MS so the intent-log capture delay has folded
 *      the last keystrokes into the outbox; later stages wait STEP_PAUSE_MS),
 *      runs one sync, then POSTs `advance` for that stage, which moves the
 *      trigger to the next one.
 *   3. A stage nobody claims for FALLBACK_MS (that user's window is not open)
 *      is skipped by whoever notices, so the round still finishes.
 *
 * Meanwhile a presence-only keepalive runs every KEEPALIVE_MS so the server
 * does not expire either window's presence (30 s) and the avatars stay put.
 *
 * Roles come from the current user's ID (`_gutenbergSyncEnginesSettings.
 * currentUserId`): user 2 is "second", everyone else is "first". Two windows
 * logged in as the same user both act as "first" and sync together.
 *
 * Server half: includes/temporary/class-gutenberg-sync-engines-demo-sync.php.
 * Delete both together with the import in src/index.ts.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	isSyncInFlight,
	sendKeepalive,
	setManualSyncMode,
	subscribeManualSync,
	syncNow,
} from '../providers/http-polling/polling-manager';

type Role = 'first' | 'second';

const ROUTE = '/gutenberg-sync-engines/v1/demo-sync';
const TRIGGER_POLL_MS = 300;
const SETTLE_MS = 800;
const STEP_PAUSE_MS = 300;
const FALLBACK_MS = 6000;
const KEEPALIVE_MS = 10000;

// Who syncs at each stage of a round, in order.
const SEQUENCE: Role[] = [ 'first', 'second', 'first', 'second' ];

interface TriggerState {
	id: number;
	stage: number;
}

function log( message: string, ...rest: unknown[] ): void {
	// eslint-disable-next-line no-console
	console.log( `[demo-sync] ${ message }`, ...rest );
}

function getCurrentUserId(): number {
	const settings = (
		window as {
			_gutenbergSyncEnginesSettings?: { currentUserId?: number };
		}
	 )._gutenbergSyncEnginesSettings;

	return Number( settings?.currentUserId ?? 0 );
}

function getRole(): Role {
	if ( 2 === getCurrentUserId() ) {
		return 'second';
	}

	return 'first';
}

function fetchState(): Promise< TriggerState > {
	return apiFetch( { path: ROUTE } );
}

function postStart(): Promise< TriggerState > {
	return apiFetch( {
		path: ROUTE,
		method: 'POST',
		data: { action: 'start' },
	} );
}

function postAdvance( id: number, stage: number ): Promise< TriggerState > {
	return apiFetch( {
		path: ROUTE,
		method: 'POST',
		data: { action: 'advance', id, stage },
	} );
}

function sleep( ms: number ): Promise< void > {
	return new Promise( ( resolve ) => {
		setTimeout( resolve, ms );
	} );
}

function waitForSyncIdle(): Promise< void > {
	if ( ! isSyncInFlight() ) {
		return Promise.resolve();
	}

	return new Promise( ( resolve ) => {
		const unsubscribe = subscribeManualSync( () => {
			if ( ! isSyncInFlight() ) {
				unsubscribe();
				resolve();
			}
		} );
	} );
}

/**
 * Runs exactly one poll cycle and resolves when its request has finished.
 */
async function runOneSync(): Promise< void > {
	await waitForSyncIdle();
	syncNow();
	await waitForSyncIdle();
}

function isShortcut( event: KeyboardEvent ): boolean {
	return (
		( event.metaKey || event.ctrlKey ) &&
		event.shiftKey &&
		! event.altKey &&
		's' === event.key.toLowerCase()
	);
}

/**
 * Holds automatic polling and starts listening for the shortcut and for
 * triggers from the server.
 */
export function installDemoSyncShortcut(): void {
	const role = getRole();
	// The last (id, stage) this window has dealt with; null until the first
	// successful read.
	let handled: TriggerState | null = null;
	// When the current stage (someone else's) was first seen waiting.
	let waitingSince: TriggerState & { since: number } = {
		id: 0,
		stage: 0,
		since: 0,
	};
	let busy = false;

	setManualSyncMode( true );
	log( `installed as "${ role }" (user ${ getCurrentUserId() })` );

	// Keydown events inside the editor canvas iframe are re-dispatched on
	// the parent document by the block editor's iframe, so one listener
	// here covers the whole editor.
	document.addEventListener(
		'keydown',
		( event ) => {
			if ( ! isShortcut( event ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			log( 'shortcut pressed, starting a sync round' );
			postStart().catch( ( error ) => {
				log( 'could not start the sync round', error );
			} );
		},
		true
	);

	function isNewer( state: TriggerState, than: TriggerState ): boolean {
		return (
			state.id > than.id ||
			( state.id === than.id && state.stage > than.stage )
		);
	}

	async function handleStage( state: TriggerState ): Promise< void > {
		const stageRole = SEQUENCE[ state.stage - 1 ];
		if ( ! stageRole ) {
			// Past the end of the sequence: the round is over.
			handled = state;
			return;
		}

		const label = `round ${ state.id } step ${ state.stage }/${ SEQUENCE.length }`;

		if ( stageRole !== role ) {
			// Someone else's turn. Skip it for them if they never show up.
			if (
				waitingSince.id !== state.id ||
				waitingSince.stage !== state.stage
			) {
				waitingSince = { ...state, since: Date.now() };
				return;
			}

			if ( Date.now() - waitingSince.since > FALLBACK_MS ) {
				log( `${ label }: no "${ stageRole }" window, skipping it` );
				handled = state;
				await postAdvance( state.id, state.stage );
			}
			return;
		}

		handled = state;
		const pause = 1 === state.stage ? SETTLE_MS : STEP_PAUSE_MS;
		log( `${ label }: syncing as "${ role }" in ${ pause } ms` );
		await sleep( pause );
		await runOneSync();
		await postAdvance( state.id, state.stage );
		log( `${ label }: synced` );
	}

	async function tick(): Promise< void > {
		if ( busy ) {
			return;
		}

		busy = true;
		try {
			const state = await fetchState();

			// First successful read: adopt the current state so a round
			// from before this page loaded is not replayed.
			if ( null === handled ) {
				handled = state;
				return;
			}

			if ( isNewer( state, handled ) ) {
				await handleStage( state );
			}
		} catch ( error ) {
			log( 'trigger poll failed', error );
		} finally {
			busy = false;
		}
	}

	setInterval( () => {
		void tick();
	}, TRIGGER_POLL_MS );

	setInterval( () => {
		void sendKeepalive();
	}, KEEPALIVE_MS );
}
