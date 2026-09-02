/**
 * WordPress dependencies
 */
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import {
	DEFAULT_CLIENT_LIMIT_PER_ROOM,
	ERROR_RETRY_DELAYS_SOLO_MS,
	ERROR_RETRY_DELAYS_WITH_COLLABORATORS_MS,
	MAX_SYNC_REQUEST_BODY_SIZE_IN_BYTES,
	MIN_SYNC_REQUEST_BODY_SIZE_LIMIT_IN_BYTES,
	MAX_ROOMS_PER_REQUEST,
	MAX_UPDATE_SIZE_IN_BYTES,
	POLLING_INTERVAL_IN_MS,
	POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS,
	POLLING_INTERVAL_BACKGROUND_TAB_IN_MS,
	DISCONNECT_DIALOG_RETRY_MS,
	MANUAL_RETRY_INTERVAL_MS,
	ADVISORY_SAFETY_POLL_INTERVAL_IN_MS,
	LOCAL_UPDATE_POLL_DELAY_MS,
	ANNOUNCE_POLL_COALESCE_MS,
	ANNOUNCE_POLL_MIN_GAP_MS,
} from './config';
import { ConnectionError, ConnectionErrorCode } from '../../framework';
import {
	advisoryCoversClients,
	getChannelPresence,
	onAdvisoryAnnounce,
	onAdvisoryCoverageChanged,
	onAdvisoryPresence,
	setAdvisoryDisabledByTransport,
	setPresenceSource,
	startAdvisoryChannel,
	stopAdvisoryChannel,
} from '../advisory/channel';
import { announceLocalWrite } from '../advisory/announce';
import {
	installSignaling,
	installSignalingLifecycle,
	isSignalingAvailable,
	onOthersChanged,
	othersPresent,
	setSyncClientId,
} from '../advisory/signaling';
import type { ConnectionStatus, EngineSessionCodec } from '@wordpress/sync';
import {
	installSyncDebug,
	isSyncDebugEnabled,
	recordPoll,
	registerDebugSession,
	unregisterDebugSession,
} from '../../debug/inspector';
import type {
	AwarenessState,
	SyncPayload,
	SyncUpdate,
	UpdateQueue,
} from './types';
import {
	createUpdateQueue,
	intValueOrDefault,
	postSyncUpdate,
	postSyncUpdateNonBlocking,
	rotateWindow,
} from './utils';

type LogFunction = (
	message: string,
	debug?: object,
	errorLevel?: 'error' | 'log' | 'warn',
	force?: boolean
) => void;

interface PollingManager {
	registerRoom: ( options: RegisterRoomOptions ) => void;
	retryNow: () => void;
	unregisterRoom: (
		room: string,
		options?: { sendDisconnectSignal?: boolean }
	) => void;
}

interface RegisterRoomOptions {
	room: string;
	session: EngineSessionCodec;
	log: LogFunction;
	onStatusChange: ( status: ConnectionStatus ) => void;
}

interface RoomState {
	endCursor: number;
	isPrimaryRoom: boolean;
	/** The awareness map the last poll response carried for this room. */
	lastServerAwareness: AwarenessState;
	log: LogFunction;
	onStatusChange: ( status: ConnectionStatus ) => void;
	room: string;
	session: EngineSessionCodec;
	unregister: () => void;
	updateQueue: UpdateQueue;
}

/**
 * Minimal shape of a WordPress REST API error as it arrives on the client
 * via apiFetch. WP_Error is serialized to JSON with a `data.status` field
 * containing the HTTP status code; `code` and `message` are best-effort.
 */
interface WPRestError {
	code?: string;
	message?: string;
	data: { status: number; rooms?: string[]; room?: string };
}

/**
 * Check if an error is a forbidden (403) response from the WordPress REST
 * API. These errors have a `data.status` property set by WP_Error.
 *
 * @param error The caught error to inspect.
 */
function isForbiddenError( error: unknown ): error is WPRestError {
	return ( error as WPRestError | undefined )?.data?.status === 403;
}

/**
 * Check if an error is the sync server's deterministic request-body-size
 * rejection. The server rejects this before the sync handler stores updates, so
 * the client can safely retry the exact same updates in smaller request bodies.
 *
 * @param error The caught error to inspect.
 */
function isRequestBodyTooLargeError( error: unknown ): error is WPRestError {
	return (
		( error as WPRestError | undefined )?.data?.status === 413 &&
		( error as WPRestError | undefined )?.code ===
			'rest_sync_body_too_large'
	);
}

/**
 * Check if an error is the sync server's protocol mismatch signal. This
 * indicates the client is running an outdated version of the code that is
 * incompatible with the server, and the user should refresh to recover.
 *
 * @param error The caught error to inspect.
 */
function isProtocolMismatchError( error: unknown ): error is WPRestError {
	return (
		( error as WPRestError | undefined )?.code ===
		'rest_sync_protocol_mismatch'
	);
}

/**
 * Check if an error is the sync server's engine mismatch signal (409). The
 * room is bound to a different sync engine than this client speaks — either
 * the site configuration changed mid-session (stale tab) or the room's
 * storage lineage predates an engine swap. Retrying cannot succeed; the
 * affected room must fall back to the lock posture.
 *
 * @param error The caught error to inspect.
 */
function isEngineMismatchError( error: unknown ): error is WPRestError {
	return (
		( error as WPRestError | undefined )?.code ===
		'rest_sync_engine_mismatch'
	);
}

/**
 * Handle a 403 from the sync endpoint. Silently unregisters the affected
 * rooms listed in the error data, and restores pending updates for the
 * remaining rooms so they retry on the next poll cycle.
 *
 * If the error does not include room details, it is treated as a generic auth
 * failure and all rooms are unregistered.
 *
 * @param error          The forbidden error, narrowed via isForbiddenError.
 * @param requestedRooms The rooms that were in the failing request.
 */
function handleForbiddenError(
	error: WPRestError,
	requestedRooms: SyncPayload[ 'rooms' ]
): void {
	const requestedRoomNames = new Set(
		requestedRooms.map( ( room ) => room.room )
	);
	const forbiddenRooms = Array.isArray( error.data.rooms )
		? error.data.rooms.filter( ( room ) => requestedRoomNames.has( room ) )
		: [];

	if ( forbiddenRooms.length > 0 ) {
		for ( const room of forbiddenRooms ) {
			const state = roomStates.get( room );
			if ( state ) {
				state.log(
					'Permission denied, unregistering room',
					{ error },
					'error',
					true // force
				);
				unregisterRoom( room, { sendDisconnectSignal: false } );
			}
		}

		// Restore updates for remaining rooms so they can be retried on
		// the next poll cycle.
		for ( const room of requestedRooms ) {
			if ( forbiddenRooms.includes( room.room ) ) {
				continue;
			}
			if ( ! roomStates.has( room.room ) ) {
				continue;
			}
			const remainingState = roomStates.get( room.room )!;
			if ( room.updates.length > 0 ) {
				remainingState.updateQueue.restore( room.updates );
			}
		}
	} else {
		// Generic auth failure (e.g. not logged in) — unregister all rooms.
		const rooms = [ ...roomStates.keys() ];
		for ( const room of rooms ) {
			const state = roomStates.get( room );
			if ( state ) {
				state.log(
					'Permission denied, unregistering room',
					{ error },
					'error',
					true // force
				);
				unregisterRoom( room, { sendDisconnectSignal: false } );
			}
		}
	}
}

/**
 * Handle a 409 engine mismatch from the sync endpoint. The server rejects the
 * whole request on the first mismatched room, naming it in the error data.
 * That room is terminally incompatible — its status is set to disconnected
 * with an ENGINE_MISMATCH error (the lock posture) and it is unregistered
 * without a disconnect signal (the server would 409 that request too).
 * Pending updates for the other rooms in the request are restored so they
 * retry on the next poll cycle.
 *
 * Without a room in the error data (defensively), all requested rooms are
 * treated as mismatched.
 *
 * @param error          The mismatch error, narrowed via isEngineMismatchError.
 * @param requestedRooms The rooms that were in the failing request.
 */
function handleEngineMismatchError(
	error: WPRestError,
	requestedRooms: SyncPayload[ 'rooms' ]
): void {
	const mismatchedRooms =
		'string' === typeof error.data.room
			? [ error.data.room ]
			: requestedRooms.map( ( room ) => room.room );

	for ( const room of mismatchedRooms ) {
		const state = roomStates.get( room );
		if ( ! state ) {
			continue;
		}
		state.log(
			'Sync engine mismatch, unregistering room',
			{ error },
			'error',
			true // force
		);
		state.onStatusChange( {
			status: 'disconnected',
			error: new ConnectionError(
				ConnectionErrorCode.ENGINE_MISMATCH,
				'Sync engine mismatch between client and server'
			),
		} );
		unregisterRoom( room, { sendDisconnectSignal: false } );
	}

	// Restore updates for remaining rooms so they can be retried on the
	// next poll cycle.
	for ( const room of requestedRooms ) {
		if ( mismatchedRooms.includes( room.room ) ) {
			continue;
		}
		if ( ! roomStates.has( room.room ) ) {
			continue;
		}
		const remainingState = roomStates.get( room.room )!;
		if ( room.updates.length > 0 ) {
			remainingState.updateQueue.restore( room.updates );
		}
	}
}

const roomStates: Map< string, RoomState > = new Map();

// Console stub for the sync inspector (wpSync.enable() and friends).
installSyncDebug();

/**
 * Check whether the awareness state exceeds the configured connection limit.
 *
 * @param awareness The awareness state from the server response.
 * @param roomState The room state corresponding to the awareness state
 * @return True if a peer limit has been exceeded.
 */
function checkConnectionLimit(
	awareness: AwarenessState,
	roomState: RoomState
): boolean {
	if ( ! roomState.isPrimaryRoom || hasCheckedConnectionLimit ) {
		return false;
	}

	// Limits are only enforced on the initial connection.
	hasCheckedConnectionLimit = true;

	const maxClientsPerRoom = applyFilters(
		'sync.pollingProvider.maxClientsPerRoom',
		DEFAULT_CLIENT_LIMIT_PER_ROOM,
		roomState.room
	);

	const clientCount = Object.keys( awareness ).length;
	const validatedLimit = intValueOrDefault(
		maxClientsPerRoom,
		DEFAULT_CLIENT_LIMIT_PER_ROOM
	);

	if ( clientCount > validatedLimit ) {
		roomState.log( 'Connection limit exceeded', {
			clientCount,
			maxClientsPerRoom: validatedLimit,
			room: roomState.room,
		} );

		return true;
	}

	return false;
}

let areListenersRegistered = false;
let consecutiveFailures = 0;
let hasCheckedConnectionLimit = false;
let isManualRetry = false;
let hasCollaborators = false;
let isActiveBrowser = 'visible' === document.visibilityState;
let isPolling = false;
let isUnloadPending = false;
let pollInterval = POLLING_INTERVAL_IN_MS;
let pollingTimeoutId: ReturnType< typeof setTimeout > | null = null;
let syncRequestBodySizeLimit = MAX_SYNC_REQUEST_BODY_SIZE_IN_BYTES;

/*
 * THE CADENCE RULES (docs/plan/advisory-channel.md).
 *
 * Short polling is the base transport everyone has. What changes is WHEN
 * the loop polls:
 *
 * - Alone (the signaling lane says nobody else is in this post's room):
 *   no scheduled polls at all once the first poll has bootstrapped the
 *   session. Local edits still go out — one request shortly after the
 *   first queued update — so the room stays in step with what a reload
 *   would load. Company (a heartbeat answer, or an awareness map with
 *   more than one client) restarts the loop.
 * - Company, but some known peer is NOT reachable over the advisory
 *   channel: today's timer cadence (the configured interval).
 * - Company, every known peer reachable over the channel: a slow SAFETY
 *   poll (25 s, which also keeps the server's awareness record alive)
 *   plus polls on demand — after a queued local update, and after a
 *   peer announces new rows.
 * - No signaling lane on this page (a screen with no per-post room, or
 *   the channel disabled site-wide): the always-on cadence, unchanged.
 *
 * Long polling keeps its own re-issue cadence and turns the channel off
 * while its held request is connected; the alone rule still applies to
 * it (a held request for a lone editor pins a PHP worker for nothing).
 */
let hasBootstrapped = false;
let pollAgainRequested = false;
let localUpdatePollTimer: ReturnType< typeof setTimeout > | null = null;
let announcePollTimer: ReturnType< typeof setTimeout > | null = null;
let lastAnnouncePollAt = 0;
let advisoryHooksInstalled = false;

function hasQueuedUpdates(): boolean {
	for ( const state of roomStates.values() ) {
		if ( state.updateQueue.peek().length > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Never let a slow (or absent) timer sit on queued local work: a safety
 * or background delay, or no timer at all, is cut down to the on-demand
 * send delay (the normal timer cadences send soon enough). The cadence
 * rules decide how often to LOOK for rows; queued rows go out promptly
 * regardless (found by a coverage flip that replaced a pending 1 s timer
 * with the 25 s safety timer while an undo's inverse intents were queued).
 *
 * @param delay The delay the cadence rules chose.
 */
function boundedByQueuedWork( delay: number | null ): number | null {
	if (
		hasQueuedUpdates() &&
		( null === delay || delay >= ADVISORY_SAFETY_POLL_INTERVAL_IN_MS )
	) {
		return LOCAL_UPDATE_POLL_DELAY_MS;
	}
	return delay;
}

function isAlone(): boolean {
	return isSignalingAvailable() && ! hasCollaborators && ! othersPresent();
}

function hasCompany(): boolean {
	return hasCollaborators || othersPresent();
}

/**
 * Whether every peer this tab knows about is reachable over the advisory
 * channel: the discovered tokens, and the client ids the primary room's
 * last awareness map carried.
 */
function advisoryCoversEveryone(): boolean {
	const clientIds: number[] = [];
	roomStates.forEach( ( state ) => {
		if ( state.isPrimaryRoom ) {
			for ( const id of Object.keys( state.lastServerAwareness ) ) {
				clientIds.push( Number( id ) );
			}
		}
	} );
	return advisoryCoversClients( clientIds );
}

/**
 * The delay before the next scheduled poll after a successful one, or null
 * for "do not schedule" (quiet while alone).
 */
function nextScheduledDelay(): number | null {
	if ( hasBootstrapped && isAlone() ) {
		return null;
	}
	if ( longPollMode ) {
		return isActiveBrowser
			? LONG_POLL_REISSUE_MS
			: POLLING_INTERVAL_BACKGROUND_TAB_IN_MS;
	}
	if ( ! isActiveBrowser ) {
		return POLLING_INTERVAL_BACKGROUND_TAB_IN_MS;
	}
	if ( advisoryCoversEveryone() ) {
		return ADVISORY_SAFETY_POLL_INTERVAL_IN_MS;
	}
	if ( hasCompany() ) {
		return POLLING_INTERVAL_WITH_COLLABORATORS_IN_MS;
	}
	return POLLING_INTERVAL_IN_MS;
}

/**
 * Schedules the next poll, or leaves the loop stopped when the cadence
 * rules say so. `isPolling` stays true only while a poll is scheduled or
 * in flight, so a stopped loop can be restarted by any wake path.
 *
 * @param delay Milliseconds until the next poll, or null to stop.
 */
function scheduleNext( delay: number | null ): void {
	if ( pollAgainRequested ) {
		// A wake arrived while the last request was in flight.
		pollAgainRequested = false;
		pollingTimeoutId = setTimeout( poll, 0 );
		return;
	}
	if ( null === delay ) {
		isPolling = false;
		pollingTimeoutId = null;
		return;
	}
	pollingTimeoutId = setTimeout( poll, delay );
}

/**
 * Polls now: a stopped loop restarts, a scheduled poll is brought forward,
 * an in-flight poll is followed by another as soon as it returns.
 */
function pollNow(): void {
	if ( 0 === roomStates.size ) {
		return;
	}
	if ( pollingTimeoutId ) {
		clearTimeout( pollingTimeoutId );
		pollingTimeoutId = null;
		poll();
		return;
	}
	if ( ! isPolling ) {
		poll();
		return;
	}
	pollAgainRequested = true;
}

/**
 * Re-evaluates a pending timer against the cadence rules (a peer joined
 * or left the channel, company arrived). A stopped loop restarts only
 * when there is company; an in-flight poll reschedules itself when it
 * returns.
 */
function reschedule(): void {
	if ( 0 === roomStates.size ) {
		return;
	}
	if ( pollingTimeoutId ) {
		clearTimeout( pollingTimeoutId );
		pollingTimeoutId = null;
		const delay = boundedByQueuedWork( nextScheduledDelay() );
		if ( null === delay ) {
			isPolling = false;
			return;
		}
		pollingTimeoutId = setTimeout( poll, delay );
		return;
	}
	if ( ! isPolling && hasCompany() ) {
		poll();
	}
}

/**
 * A local update was queued while the loop is quiet or on the slow safety
 * cadence: poll shortly. The delay lets the rest of a burst pile in; it is
 * NOT reset by later updates, so a long burst cannot starve the send.
 */
function pollSoonForLocalUpdate(): void {
	if ( localUpdatePollTimer ) {
		return;
	}
	localUpdatePollTimer = setTimeout( () => {
		localUpdatePollTimer = null;
		pollNow();
	}, LOCAL_UPDATE_POLL_DELAY_MS );
}

/**
 * A peer announced new rows over the advisory channel: poll, coalescing a
 * burst of announcements into one request and never faster than the floor.
 */
function pollSoonForAnnounce(): void {
	if ( announcePollTimer ) {
		return;
	}
	const sinceLast = Date.now() - lastAnnouncePollAt;
	const delay = Math.max(
		ANNOUNCE_POLL_COALESCE_MS,
		ANNOUNCE_POLL_MIN_GAP_MS - sinceLast
	);
	announcePollTimer = setTimeout( () => {
		announcePollTimer = null;
		lastAnnouncePollAt = Date.now();
		pollNow();
	}, delay );
}

/**
 * The awareness map to hand a session: the poll response's copy with the
 * fresher channel copy overlaid for peers on the channel (a 25-second-old
 * server cursor must not jump a live cursor back).
 *
 * @param state The room.
 */
function mergedAwareness( state: RoomState ): AwarenessState {
	return {
		...state.lastServerAwareness,
		...( getChannelPresence( state.room ) as AwarenessState ),
	};
}

function installAdvisoryHooks(): void {
	if ( advisoryHooksInstalled ) {
		return;
	}
	advisoryHooksInstalled = true;
	installSignaling();
	installSignalingLifecycle();
	onOthersChanged( ( others ) => {
		if ( others ) {
			roomStates.forEach( ( state ) => state.updateQueue.resume() );
			pollNow();
		} else {
			reschedule();
		}
	} );
	onAdvisoryCoverageChanged( reschedule );
	onAdvisoryAnnounce( pollSoonForAnnounce );
	onAdvisoryPresence( ( room ) => {
		const state = roomStates.get( room );
		if ( state ) {
			state.session.applyRemoteAwareness( mergedAwareness( state ) );
		}
	} );
	setPresenceSource( () =>
		Array.from( roomStates.values() ).map( ( state ) => ( {
			room: state.room,
			clientId: state.session.clientId,
			state: state.session.getLocalAwareness(),
		} ) )
	);
}

/*
 * Long-poll mode: the server holds each request open until it has something
 * to deliver, so on a successful response the client re-issues almost
 * immediately rather than waiting out a fixed interval. Failure backoff is
 * unchanged. Set once by the long-polling provider (a single site-wide
 * transport). See providers/http-long-polling.
 */
let longPollMode = false;

/*
 * A parked long-poll in flight (a request that carried NO updates and is
 * being held by the server). Local updates ABORT it so outgoing work never
 * waits out the hold: the server answers senders immediately, but only if
 * the client actually sends. Without the abort, an edit made right after a
 * quiet poll sat queued for up to the full wait budget.
 */
let inFlightParkController: AbortController | null = null;
let parkAbortedForLocalUpdate = false;

/**
 * Enables long-poll cadence on the shared manager.
 *
 * @param enabled Whether the active transport holds requests open.
 */
export function setLongPollMode( enabled: boolean ): void {
	longPollMode = enabled;
}

// Small delay between a released long-poll response and the next request, to
// yield to the event loop without idling.
const LONG_POLL_REISSUE_MS = 50;

// When more rooms are registered than the server allows per request
// (MAX_ROOMS_PER_REQUEST), the primary room is sent every poll and the
// remaining "overflow" rooms are rotated across polls. This offset
// points into the overflow list at the next room to include.
let roomOverflowOffset = 0;

/**
 * Mark that a page unload has been requested. This fires on
 * `beforeunload` which happens before the browser aborts in-flight
 * fetches, allowing us to distinguish poll failures caused by
 * navigation from genuine server errors in the catch block.
 *
 * If the user cancels the unload (e.g. by dismissing a "Save Changes?" dialog),
 * the flag is reset at the start of the next poll cycle so that polling can
 * resume.
 */
function handleBeforeUnload(): void {
	isUnloadPending = true;
}

/**
 * Send a disconnect signal for all registered rooms when the page is
 * being unloaded. Uses `sendBeacon` so the request survives navigation.
 */
function handlePageHide(): void {
	const rooms = Array.from( roomStates.entries() ).map(
		( [ room, state ] ) => ( {
			after: 0,
			awareness: null,
			client_id: state.session.clientId,
			room,
			updates: [],
		} )
	);

	for ( let i = 0; i < rooms.length; i += MAX_ROOMS_PER_REQUEST ) {
		postSyncUpdateNonBlocking( {
			rooms: rooms.slice( i, i + MAX_ROOMS_PER_REQUEST ),
		} );
	}
}

/**
 * Hangle change in visibility state of browser tab.
 *
 * Used to trigger a slow down of the collaboration syncs when the
 * browser tab becomes inactive (either the user switches tabs or the
 * screen saver comes on).
 *
 * Fires on the document's visibilitychange event.
 */
function handleVisibilityChange() {
	const wasActive = isActiveBrowser;
	isActiveBrowser = document.visibilityState === 'visible';

	if ( isActiveBrowser && ! wasActive ) {
		/*
		 * Remove scheduled polling and repoll immediately when reactivated.
		 *
		 * This ensures that any updates by collaborators are immediately
		 * reflected in the document once the browser tab becomes active.
		 * Otherwise there would be a delay of up to 30 seconds before the
		 * updates came through.
		 *
		 * Only repoll if we cleared a pending timeout, meaning the poll loop
		 * was idle between cycles. If no timeout is pending, a poll request
		 * is already in-flight and will pick up the updated isActiveBrowser
		 * value when it schedules the next cycle.
		 */
		if ( pollingTimeoutId ) {
			clearTimeout( pollingTimeoutId );
			pollingTimeoutId = null;
			poll();
		} else if ( ! isPolling && hasCompany() ) {
			// A quiet loop whose company arrived while hidden.
			poll();
		}
	}
}

/**
 * Select which rooms to include in the next sync request.
 *
 * The server caps requests at MAX_ROOMS_PER_REQUEST rooms. When fewer rooms are
 * registered than the cap, every room is included on every poll. When the cap
 * is exceeded, the primary room is sent on every poll (so the main document
 * stays fully synced) and the remaining overflow rooms are rotated across
 * successive polls so each one is included (at a reduced frequency).
 *
 * Rooms that are skipped on a given poll keep their queued updates; the updates
 * are drained on the next poll that includes them.
 *
 * @return The RoomStates to include in this request, in send order.
 */
function selectRoomsForRequest(): RoomState[] {
	const allRooms = Array.from( roomStates.values() );

	// Fast path: everything fits in a single request.
	if ( allRooms.length <= MAX_ROOMS_PER_REQUEST ) {
		return allRooms;
	}

	// Rotation path: pin the primary room to every request (if one exists)
	// and rotate the remaining overflow rooms across successive polls.
	const primaryRoom = allRooms.find( ( state ) => state.isPrimaryRoom );
	const overflowRooms = allRooms.filter( ( state ) => state !== primaryRoom );
	const overflowSlotsPerRequest =
		MAX_ROOMS_PER_REQUEST - ( primaryRoom ? 1 : 0 );

	const { window: overflowSlice, nextOffset } = rotateWindow(
		overflowRooms,
		roomOverflowOffset,
		overflowSlotsPerRequest
	);
	roomOverflowOffset = nextOffset;

	if ( primaryRoom ) {
		return [ primaryRoom, ...overflowSlice ];
	}

	return overflowSlice;
}

const textEncoder = new TextEncoder();

function getJsonByteLength( value: unknown ): number {
	return textEncoder.encode( JSON.stringify( value ) ).byteLength;
}

function createPayloadRoom(
	state: RoomState,
	updates: SyncUpdate[] = []
): SyncPayload[ 'rooms' ][ number ] {
	return {
		after: state.endCursor ?? 0,
		awareness: state.session.getLocalAwareness(),
		client_id: state.session.clientId,
		...( state.session.engineSlug
			? {
					engine: state.session.engineSlug,
					engine_protocol: state.session.engineProtocol,
			  }
			: {} ),
		// The inspector's server-envelope opt-in (see debug/inspector.ts).
		...( isSyncDebugEnabled() ? { debug: true } : {} ),
		room: state.room,
		updates,
	};
}

function getUpdatePayloadSizeDelta(
	existingUpdateCount: number,
	update: SyncUpdate
): number {
	const commaSize = existingUpdateCount === 0 ? 0 : 1;
	return commaSize + getJsonByteLength( update );
}

function buildPayloadForRequest( selectedRoomStates: RoomState[] ): {
	payload: SyncPayload;
	roomsInRequest: RoomState[];
} {
	const payload: SyncPayload = { rooms: [] };
	const roomsInRequest: RoomState[] = [];

	for ( const state of selectedRoomStates ) {
		const room = createPayloadRoom( state );
		const candidate = { rooms: [ ...payload.rooms, room ] };
		if (
			payload.rooms.length > 0 &&
			getJsonByteLength( candidate ) > syncRequestBodySizeLimit
		) {
			break;
		}

		payload.rooms.push( room );
		roomsInRequest.push( state );
	}

	const pendingUpdates = roomsInRequest.map( ( state ) =>
		state.updateQueue.peek()
	);
	const sentUpdateCounts = roomsInRequest.map( () => 0 );

	let payloadSize = getJsonByteLength( payload );
	let addedUpdate = true;

	while ( addedUpdate ) {
		addedUpdate = false;

		for ( let i = 0; i < roomsInRequest.length; i++ ) {
			const update = pendingUpdates[ i ][ sentUpdateCounts[ i ] ];

			if ( ! update ) {
				continue;
			}

			const sizeDelta = getUpdatePayloadSizeDelta(
				sentUpdateCounts[ i ],
				update
			);
			if ( payloadSize + sizeDelta > syncRequestBodySizeLimit ) {
				continue;
			}

			sentUpdateCounts[ i ]++;
			payloadSize += sizeDelta;
			addedUpdate = true;
		}
	}

	for ( let i = 0; i < roomsInRequest.length; i++ ) {
		payload.rooms[ i ].updates = roomsInRequest[ i ].updateQueue.take(
			sentUpdateCounts[ i ]
		);
	}

	return { payload, roomsInRequest };
}

function restoreExactUpdates( payload: SyncPayload ): void {
	for ( const room of payload.rooms ) {
		if ( ! roomStates.has( room.room ) || room.updates.length === 0 ) {
			continue;
		}

		roomStates.get( room.room )!.updateQueue.restoreExact( room.updates );
	}
}

function poll(): void {
	isPolling = true;
	pollingTimeoutId = null;

	async function start(): Promise< void > {
		if ( 0 === roomStates.size ) {
			isPolling = false;
			return;
		}

		// Reset the unloading flag at the start of each poll cycle so
		// it doesn't permanently suppress disconnect after the user
		// cancels a beforeunload dialog.
		isUnloadPending = false;

		// Create a payload with queued updates. We include rooms even if they
		// have no updates to ensure we receive any incoming updates, while keeping
		// the serialized body below the server's aggregate request-size limit.
		const { payload, roomsInRequest } = buildPayloadForRequest(
			selectRoomsForRequest()
		);

		// Emit 'connecting' status only for rooms in this request. Rooms
		// rotated out of this poll keep their prior status.
		roomsInRequest.forEach( ( state ) => {
			state.onStatusChange( { status: 'connecting' } );
		} );

		const pollStarted = Date.now();
		let succeeded = false;
		let nextDelay: number | null = null;
		const isPureReceive = payload.rooms.every(
			( room ) => 0 === room.updates.length
		);
		let parkSignal: AbortSignal | undefined;
		if ( longPollMode && isPureReceive ) {
			inFlightParkController = new AbortController();
			parkSignal = inFlightParkController.signal;
		}
		try {
			const { rooms } = await postSyncUpdate( payload, parkSignal );
			inFlightParkController = null;
			parkAbortedForLocalUpdate = false;

			// Emit 'connected' status.
			consecutiveFailures = 0;
			isManualRetry = false;
			syncRequestBodySizeLimit = MAX_SYNC_REQUEST_BODY_SIZE_IN_BYTES;
			roomsInRequest.forEach( ( state ) => {
				// Skip rooms unregistered during the await (e.g. the
				// size-limit handler in onDocUpdate). Their terminal
				// status was already set by whatever unregistered them.
				if ( roomStates.get( state.room ) !== state ) {
					return;
				}

				state.onStatusChange( { status: 'connected' } );
			} );

			// Reset before checking each room
			hasCollaborators = false;

			rooms.forEach( ( room ) => {
				if ( ! roomStates.has( room.room ) ) {
					return;
				}

				const roomState = roomStates.get( room.room )!;

				// The inspector's wire tap: decoded traffic, both ways.
				if ( isSyncDebugEnabled() ) {
					const requested = payload.rooms.find(
						( sent ) => sent.room === room.room
					);
					recordPoll( {
						room: room.room,
						sent: requested?.updates ?? [],
						received: room.updates,
						dispositions: room.dispositions as
							| Array< Record< string, unknown > >
							| undefined,
						cursorBefore: requested?.after,
						cursorAfter: room.end_cursor,
						durationMs: Date.now() - pollStarted,
						serverDebug: (
							room as {
								_debug?: Record< string, unknown >;
							}
						 )._debug,
					} );
				}

				roomState.endCursor = room.end_cursor;

				// If a limit is exceeded, disconnect immediately without processing updates.
				if ( checkConnectionLimit( room.awareness, roomState ) ) {
					roomState.onStatusChange( {
						status: 'disconnected',
						error: new ConnectionError(
							ConnectionErrorCode.CONNECTION_LIMIT_EXCEEDED,
							'Connection limit exceeded'
						),
					} );
					unregisterRoom( room.room );
					return;
				}

				// Process awareness update: the server's copy, with the
				// fresher channel copy overlaid for peers on the channel.
				roomState.lastServerAwareness = room.awareness ?? {};
				roomState.session.applyRemoteAwareness(
					mergedAwareness( roomState )
				);

				// Another collaborator on the primary entity means company:
				// the loop keeps its timer cadence (or the safety cadence
				// under full channel coverage). Only the primary room is
				// checked to avoid false positives from shared collection
				// rooms (e.g. taxonomy/category).
				if (
					roomState.isPrimaryRoom &&
					Object.keys( room.awareness ).length > 1
				) {
					hasCollaborators = true;
				}

				// Rows this tab just landed: tell the peers on the channel
				// to poll. A rumor only — the poll is what delivers them.
				const sentUpdates = payload.rooms.find(
					( sent ) => sent.room === room.room
				)?.updates.length;
				if ( sentUpdates ) {
					announceLocalWrite( room.room );
				}

				// Process each incoming update and collect any responses.
				const responseUpdates: SyncUpdate[] = [];
				for ( const update of room.updates ) {
					try {
						const response =
							roomState.session.receiveUpdate( update );
						if ( response ) {
							responseUpdates.push( response );
						}
					} catch ( error ) {
						roomState.log(
							'Failed to apply sync update',
							{ error, update },
							'error',
							true // force
						);
					}
				}

				roomState.updateQueue.addBulk( responseUpdates );

				/*
				 * Deliver per-update dispositions (the server's ack for the
				 * batch this client sent) AFTER the updates above: rows
				 * already settle the pending state they supersede, so the
				 * ack covers only outcomes without a row and the session's
				 * state never regresses mid-response.
				 */
				if (
					room.dispositions &&
					roomState.session.receiveDispositions
				) {
					try {
						roomState.session.receiveDispositions(
							room.dispositions
						);
					} catch ( error ) {
						roomState.log(
							'Failed to apply dispositions',
							{ error },
							'error',
							true // force
						);
					}
				}

				// Respond to compaction requests from server. The server asks only one
				// client at a time to compact (lowest active client ID). We encode our
				// full document state to replace all prior updates on the server.
				// (No current engine nominates a client — they all compact
				// server-side — so codecs without the optional method are
				// simply never asked, and a request to one is ignored.)
				if ( room.should_compact ) {
					roomState.log( 'Server requested compaction update' );
					try {
						// Create BEFORE clearing: a failed creation must not
						// destroy the queued updates for nothing.
						const compactionUpdate =
							roomState.session.createCompactionUpdate?.();
						if ( compactionUpdate ) {
							roomState.updateQueue.clear();
							roomState.updateQueue.add( compactionUpdate );
						}
					} catch ( error ) {
						roomState.log(
							'Failed to create compaction update',
							{ error },
							'error',
							true // force
						);
					}
				}
			} );

			/*
			 * Long polling delivers its own wake (the held request returns
			 * the instant a row lands), so while it is connected the
			 * advisory channel would only duplicate it: switch the channel
			 * off. A failed poll below switches it back on.
			 */
			if ( longPollMode ) {
				setAdvisoryDisabledByTransport( true );
			}

			// The first successful poll is the genesis handshake; from
			// here on the cadence rules decide whether to schedule at all.
			hasBootstrapped = true;
			succeeded = true;
			nextDelay = boundedByQueuedWork( nextScheduledDelay() );
			if ( null !== nextDelay ) {
				pollInterval = nextDelay;
			}
		} catch ( error ) {
			if ( parkAbortedForLocalUpdate ) {
				/*
				 * Deliberate wake: the parked request carried no updates, so
				 * there is nothing to restore and no failure to record —
				 * re-poll immediately to send the just-queued local work.
				 */
				parkAbortedForLocalUpdate = false;
				inFlightParkController = null;
				pollingTimeoutId = setTimeout( poll, 0 );
				return;
			}
			inFlightParkController = null;
			if ( isSyncDebugEnabled() ) {
				for ( const requested of payload.rooms ) {
					recordPoll( {
						room: requested.room,
						sent: requested.updates,
						received: [],
						durationMs: Date.now() - pollStarted,
						error: String( error ),
					} );
				}
			}
			// A 403 response means the user does not have permission to
			// sync a specific entity. Silently unregister the affected
			// room(s) and let polling continue for the rest.
			if ( isForbiddenError( error ) ) {
				handleForbiddenError( error, payload.rooms );

				// If every room was unregistered, stop the poll loop
				// instead of scheduling another tick. Reset isPolling
				// so a future registerRoom() call can restart it.
				if ( roomStates.size === 0 ) {
					isPolling = false;
					return;
				}
			} else if ( isEngineMismatchError( error ) ) {
				// A 409 means the room is bound to a different sync engine.
				// Retrying can never succeed — drop the affected room into
				// the lock posture and keep polling for the rest.
				handleEngineMismatchError( error, payload.rooms );

				if ( roomStates.size === 0 ) {
					isPolling = false;
					return;
				}
			} else if ( isRequestBodyTooLargeError( error ) ) {
				syncRequestBodySizeLimit = Math.max(
					MIN_SYNC_REQUEST_BODY_SIZE_LIMIT_IN_BYTES,
					Math.floor( syncRequestBodySizeLimit / 2 )
				);
				pollInterval = hasCollaborators
					? ERROR_RETRY_DELAYS_WITH_COLLABORATORS_MS[ 0 ]
					: ERROR_RETRY_DELAYS_SOLO_MS[ 0 ];
				restoreExactUpdates( payload );

				for ( const room of payload.rooms ) {
					if ( ! roomStates.has( room.room ) ) {
						continue;
					}

					roomStates.get( room.room )!.log(
						'Sync request body too large, retrying with smaller batches',
						{
							error,
							nextPoll: pollInterval,
							syncRequestBodySizeLimit,
						},
						'error',
						true // force
					);
				}
			} else if ( isProtocolMismatchError( error ) ) {
				// The server explicitly signaled a protocol mismatch, so we fail
				// gracefully instead of retrying indefinitely. This can happen if
				// the client is running an outdated version of the code that is
				// incompatible with the server.
				const affectedRooms = [ ...roomStates.entries() ];

				for ( const [ , state ] of affectedRooms ) {
					state.onStatusChange( {
						status: 'disconnected',
						error: new ConnectionError(
							ConnectionErrorCode.PROTOCOL_MISMATCH,
							'Protocol mismatch between client and server'
						),
					} );
				}

				// Skip the server-side disconnect signal: by definition the
				// server can't speak our protocol, so sending one is pointless.
				for ( const [ room ] of affectedRooms ) {
					unregisterRoom( room, { sendDisconnectSignal: false } );
				}

				isPolling = false;
				return;
			} else {
				// A disconnected transport has no wake of its own: let the
				// advisory channel back in (a no-op unless long polling
				// had switched it off).
				setAdvisoryDisabledByTransport( false );

				// Use the explicit retry delay schedule for backoff.
				consecutiveFailures++;
				const retrySchedule = hasCollaborators
					? ERROR_RETRY_DELAYS_WITH_COLLABORATORS_MS
					: ERROR_RETRY_DELAYS_SOLO_MS;
				if ( consecutiveFailures <= retrySchedule.length ) {
					pollInterval = retrySchedule[ consecutiveFailures - 1 ];
				} else {
					pollInterval = DISCONNECT_DIALOG_RETRY_MS;
				}

				// After a manual retry, use a shorter interval for one cycle.
				if ( isManualRetry ) {
					pollInterval = MANUAL_RETRY_INTERVAL_MS;
					isManualRetry = false;
				}

				// Recover from the failed request. We don't know whether the
				// server stored our updates before the error occurred (e.g. a
				// network timeout after a successful write). Recovery is
				// CODEC-DRIVEN: an engine whose updates are not idempotent on
				// the server (Yjs deltas) provides createRecoveryUpdate — a
				// full-state update that safely supersedes either outcome.
				// Engines without it (the intent log dedupes ingest by
				// intentId) get their exact updates restored and re-sent.
				// The recovery update is created BEFORE the queue is cleared
				// so a throwing codec can never lose queued work.
				for ( const room of payload.rooms ) {
					if ( ! roomStates.has( room.room ) ) {
						continue;
					}

					const state = roomStates.get( room.room )!;

					if ( room.updates.length > 0 ) {
						let recoveryUpdate: SyncUpdate | null = null;
						if (
							state.session.createRecoveryUpdate &&
							state.endCursor > 0
						) {
							try {
								recoveryUpdate =
									state.session.createRecoveryUpdate();
							} catch ( recoveryError ) {
								state.log(
									'Recovery update failed; restoring original updates',
									{ error: recoveryError },
									'error',
									true // force
								);
							}
						}
						if ( recoveryUpdate ) {
							state.updateQueue.clear();
							state.updateQueue.add( recoveryUpdate );
						} else {
							state.updateQueue.restore( room.updates );
						}
					}

					state.log(
						'Error posting sync update, will retry with backoff',
						{ error, nextPoll: pollInterval },
						'error',
						true // force
					);
				}

				// Don't report disconnected status when the request was aborted
				// due to page unload (e.g. during a refresh) to avoid briefly
				// flashing the disconnect dialog before the new page loads.
				if ( ! isUnloadPending ) {
					const backgroundRetriesFailed =
						consecutiveFailures > retrySchedule.length;

					roomsInRequest.forEach( ( state ) => {
						// Skip rooms unregistered during the await so
						// their terminal status isn't overwritten.
						if ( roomStates.get( state.room ) !== state ) {
							return;
						}

						state.onStatusChange( {
							status: 'disconnected',
							canManuallyRetry: true,
							consecutiveFailures,
							backgroundRetriesFailed,
							willAutoRetryInMs: pollInterval,
						} );
					} );
				}
			}
		}

		scheduleNext( succeeded ? nextDelay : pollInterval );
	}

	// Start polling.
	void start();
}

function registerRoom( {
	room,
	session,
	log,
	onStatusChange,
}: RegisterRoomOptions ): void {
	if ( roomStates.has( room ) ) {
		return;
	}

	// State accessors for the console inspector (duck-typed; inert unless
	// the inspector is enabled).
	registerDebugSession( room, session );

	/*
	 * The queue is never held: a lone editor's edits go out too (one
	 * request shortly after the first queued update), so the room stays in
	 * step with what a reload would load. What "alone" changes is the
	 * SCHEDULED polling, which stops (see the cadence rules above).
	 */
	const updateQueue = createUpdateQueue( session.getInitialUpdates(), false );

	/**
	 * Connection limits are enforced on the first entity to be loaded for sync.
	 * This is an inelegant solution to a hard problem: This sync provider and the
	 * sync package in general intentionally have no knowledge of the individual
	 * entities being synced.
	 *
	 * Let's say a user opens a document (Entity A) for editing. If you asked the
	 * user what they are doing, they would reply "I'm editing Entity A." You might
	 * say that Entity A is "primary."
	 *
	 * However, the action of editing Entity A also triggers the loading of a
	 * collection of document categories (Entity B) and another document (Entity C)
	 * that is embedded in Entity A. You might therefore say that Entity B and
	 * Entity C are "secondary" in this session.
	 *
	 * Meanwhile, a different user opens Entity C for editing, which also triggers
	 * the loading of Entity B. In this session, Entity C is "primary" and Entity B
	 * is "secondary."
	 *
	 * How do we enforce limits? The intuitive answer is that we only want to count
	 * connections when the entity is "primary." However, we have no ability to
	 * detect this. A document might be loaded as a primary entity in one session
	 * and a secondary entity in another.
	 *
	 * In practice, we can consider the first-loaded entity as "primary" and use it
	 * to enforce our connection limit. This is an imperfect assumption of consumer
	 * behavior.
	 *
	 * How might this approach be improved? We could develop some way to annotate
	 * entity loading so that the consumer can indicate which entity is primary.
	 */
	const isPrimaryRoom = 0 === roomStates.size;

	function onLocalUpdate( update: SyncUpdate, sizeInBytes: number ): void {
		if ( sizeInBytes > MAX_UPDATE_SIZE_IN_BYTES ) {
			const state = roomStates.get( room );
			if ( ! state ) {
				return;
			}

			state.log( 'Document size limit exceeded', {
				maxUpdateSizeInBytes: MAX_UPDATE_SIZE_IN_BYTES,
				updateSizeInBytes: sizeInBytes,
			} );

			state.onStatusChange( {
				status: 'disconnected',
				error: new ConnectionError(
					ConnectionErrorCode.DOCUMENT_SIZE_LIMIT_EXCEEDED,
					'Document size limit exceeded'
				),
			} );

			// This is an unrecoverable error. Unregister the room to prevent syncing.
			unregisterRoom( room );
			return;
		}

		updateQueue.add( update );

		/*
		 * Send on demand when no timer will pick this up soon: the loop is
		 * quiet (alone), a request is in flight with nothing scheduled
		 * behind it (alone, mid-poll), or the pending timer is the slow
		 * safety cadence (every peer on the channel). A scheduled timer
		 * at the normal cadence, or a long-poll re-issue, needs no help.
		 */
		const needsWake = longPollMode
			? ! isPolling
			: ! pollingTimeoutId || advisoryCoversEveryone();
		if ( needsWake ) {
			pollSoonForLocalUpdate();
		}

		if ( longPollMode && inFlightParkController ) {
			// Wake the parked poll: local work must not wait out the hold.
			parkAbortedForLocalUpdate = true;
			const controller = inFlightParkController;
			inFlightParkController = null;
			controller.abort();
		}
	}

	function unregister(): void {
		// Never destroy unsent local work silently: report what is being
		// discarded and give the session a chance to surface it to the
		// user before it is gone.
		const unsent = updateQueue.drain();
		if ( unsent.length > 0 ) {
			log(
				`Discarding ${ unsent.length } unsent sync update(s) at room teardown`,
				{ types: unsent.map( ( update ) => update.type ) },
				'error',
				true // force
			);
			(
				session as EngineSessionCodec & {
					onUpdatesDiscarded?: ( updates: SyncUpdate[] ) => void;
				}
			 ).onUpdatesDiscarded?.( unsent );
		}
		session.destroy();
	}

	const roomState: RoomState = {
		endCursor: 0,
		isPrimaryRoom,
		lastServerAwareness: {},
		log,
		onStatusChange,
		room,
		session,
		unregister,
		updateQueue,
	};

	session.onLocalUpdate( onLocalUpdate );
	roomStates.set( room, roomState );

	if ( ! areListenersRegistered ) {
		window.addEventListener( 'beforeunload', handleBeforeUnload );
		window.addEventListener( 'pagehide', handlePageHide );
		document.addEventListener( 'visibilitychange', handleVisibilityChange );
		areListenersRegistered = true;
	}

	if ( isPrimaryRoom ) {
		// The signaling lane and the advisory channel are per page, keyed
		// by the primary room's session (the post being edited).
		setSyncClientId( session.clientId );
		installAdvisoryHooks();
		startAdvisoryChannel();
	}

	if ( ! isPolling ) {
		poll();
	}
}

function unregisterRoom(
	room: string,
	{ sendDisconnectSignal = true }: { sendDisconnectSignal?: boolean } = {}
): void {
	const state = roomStates.get( room );
	if ( state ) {
		if ( sendDisconnectSignal ) {
			// Send a disconnect signal so the server removes this client's
			// awareness entry immediately instead of waiting for the timeout.
			const rooms = [
				{
					after: 0,
					awareness: null,
					client_id: state.session.clientId,
					room,
					updates: [],
				},
			];

			postSyncUpdateNonBlocking( { rooms } );
		}

		state.unregister();
		roomStates.delete( room );
	}
	unregisterDebugSession( room );

	if ( 0 === roomStates.size && areListenersRegistered ) {
		window.removeEventListener( 'beforeunload', handleBeforeUnload );
		window.removeEventListener( 'pagehide', handlePageHide );
		document.removeEventListener(
			'visibilitychange',
			handleVisibilityChange
		);
		areListenersRegistered = false;
		hasCheckedConnectionLimit = false;
		consecutiveFailures = 0;
		roomOverflowOffset = 0;
		syncRequestBodySizeLimit = MAX_SYNC_REQUEST_BODY_SIZE_IN_BYTES;
		hasBootstrapped = false;
		hasCollaborators = false;
		pollAgainRequested = false;
		if ( localUpdatePollTimer ) {
			clearTimeout( localUpdatePollTimer );
			localUpdatePollTimer = null;
		}
		if ( announcePollTimer ) {
			clearTimeout( announcePollTimer );
			announcePollTimer = null;
		}
		stopAdvisoryChannel();
	}
}

/**
 * Immediately retry the sync connection by cancelling any pending
 * timeout and triggering a new poll. If the retry fails, the next
 * auto-retry waits 15s (MANUAL_RETRY_INTERVAL_MS) instead of the
 * usual 30s, then falls back to 30s for subsequent auto-retries.
 */
function retryNow(): void {
	isManualRetry = true;
	pollNow();
}

export const pollingManager: PollingManager = {
	registerRoom,
	retryNow,
	unregisterRoom,
};
