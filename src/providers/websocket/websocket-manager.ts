/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import type {
	AwarenessState,
	ConnectionStatus,
	EngineSessionCodec,
	EngineUpdate,
} from '@wordpress/sync';
import {
	installSyncDebug,
	isSyncDebugEnabled,
	recordPoll,
	registerDebugSession,
	unregisterDebugSession,
} from '../../debug/inspector';
import {
	isSoloPresenceAvailable,
	onOthersArrived,
	othersLikely,
	setSyncClientId,
} from '../solo-presence';

/**
 * A codec-driven WebSocket transport, symmetric with the HTTP polling
 * manager but PUSH-based: one shared socket carries every room, the server
 * pushes peers' updates the instant they arrive, and the client speaks the
 * same room-request/room-response shape the REST endpoints use (wrapped in a
 * `{type:'sync'}` frame). The transport never interprets update payloads —
 * it moves the engine session codec's opaque updates and awareness, exactly
 * like the polling transport.
 *
 * The socket is authenticated with a one-time token minted over REST
 * (`/wp-sync/v1/ws-token`); the daemon validates it against the logged-in
 * cookie on handshake.
 */

const WS_TOKEN_API_PATH = '/wp-sync/v1/ws-token';
const RECONNECT_BASE_MS = 1000;
const RECONNECT_MAX_MS = 30000;
const AWARENESS_INTERVAL_MS = 10000;

interface RoomState {
	room: string;
	session: EngineSessionCodec;
	cursor: number;
	onStatusChange: ( status: ConnectionStatus ) => void;
}

interface ServerRoom {
	room: string;
	awareness: AwarenessState;
	updates: EngineUpdate[];
	end_cursor: number;
	dispositions?: unknown[];
}

const rooms = new Map< string, RoomState >();

// Console stub for the sync inspector (wpSync.enable() and friends).
installSyncDebug();

let socket: WebSocket | null = null;
let reconnectAttempts = 0;
let reconnectTimer: ReturnType< typeof setTimeout > | null = null;
let awarenessTimer: ReturnType< typeof setInterval > | null = null;
let connecting = false;

/*
 * Quiet-while-alone: a solo session whose socket has moved nothing and seen
 * no peers for this long closes the socket instead of keeping a per-tab
 * daemon connection alive forever. The solo-presence lane (riding the
 * WordPress heartbeat) reopens it when another participant appears; a local
 * update reopens it too (sendUpdate already reconnects a closed socket).
 * Only engages when the solo-presence lane is available on this page.
 */
const QUIET_AFTER_IDLE_MS = 30000;
let quietHold = false;
let lastActivityAt = 0;
let soloWakeInstalled = false;

function noteActivity(): void {
	lastActivityAt = Date.now();
}

/**
 * Fetches a one-time WebSocket token.
 *
 * @return {Promise<string>} The token.
 */
async function fetchToken(): Promise< string > {
	const response = ( await apiFetch( {
		method: 'POST',
		path: WS_TOKEN_API_PATH,
	} ) ) as { token?: string };
	if ( ! response?.token ) {
		throw new Error( 'Invalid ws-token response' );
	}
	return response.token;
}

/**
 * The announced socket URL. The one-time token deliberately does NOT
 * ride the URL (query strings land in server and proxy access logs);
 * it travels as a Sec-WebSocket-Protocol offer instead — the one
 * handshake header a browser page can influence.
 *
 * @return {string} Socket URL.
 */
function socketUrl(): string {
	// Announced through the framework's `wp_sync_transport_client_config`
	// filter (hooked by this plugin's PHP half).
	const transportConfig = (
		window as Window & {
			_wpCollaborationTransportConfig?: {
				websocket?: { url?: string };
			};
		}
	 )._wpCollaborationTransportConfig;
	const base = transportConfig?.websocket?.url;
	if ( ! base ) {
		throw new Error( 'WebSocket URL is not configured' );
	}
	return base;
}

/**
 * Builds the sync frame for a set of rooms, optionally carrying each room's
 * queued local update.
 *
 * @param {Map<string, EngineUpdate[]>} pending Per-room updates to send.
 * @return {string} JSON frame.
 */
function buildSyncFrame( pending: Map< string, EngineUpdate[] > ): string {
	const payloadRooms = [];
	for ( const state of rooms.values() ) {
		payloadRooms.push( {
			after: state.cursor,
			awareness: state.session.getLocalAwareness(),
			client_id: state.session.clientId,
			room: state.room,
			updates: pending.get( state.room ) ?? [],
			...( state.session.engineSlug
				? {
						engine: state.session.engineSlug,
						engine_protocol: state.session.engineProtocol,
				  }
				: {} ),
			// The inspector's server-envelope opt-in (see debug/inspector.ts).
			...( isSyncDebugEnabled() ? { debug: true } : {} ),
		} );
	}
	return JSON.stringify( { type: 'sync', rooms: payloadRooms } );
}

/**
 * Sends a frame carrying the given per-room updates, feeding the inspector's
 * wire tap. The socket is push-based, so sends and receives are recorded as
 * separate one-directional entries (unlike the polling transport's paired
 * request/response records).
 *
 * @param {Map<string, EngineUpdate[]>} pending Per-room updates to send.
 */
function sendFrame( pending: Map< string, EngineUpdate[] > ): void {
	if ( ! socket || WebSocket.OPEN !== socket.readyState ) {
		return;
	}
	socket.send( buildSyncFrame( pending ) );
	for ( const updates of pending.values() ) {
		if ( updates.length > 0 ) {
			noteActivity();
			break;
		}
	}
	if ( ! isSyncDebugEnabled() ) {
		return;
	}
	for ( const [ room, updates ] of pending ) {
		recordPoll( {
			room,
			sent: updates,
			received: [],
			cursorBefore: rooms.get( room )?.cursor,
		} );
	}
}

/**
 * Sends a room's local update over the socket (or opens the socket first).
 *
 * @param {string}       room   Room identifier.
 * @param {EngineUpdate} update The update to send.
 */
function sendUpdate( room: string, update: EngineUpdate ): void {
	if ( ! socket || WebSocket.OPEN !== socket.readyState ) {
		// The initial sync on (re)connect will carry the codec's queued
		// updates; nothing to do until the socket is open.
		connect();
		return;
	}
	sendFrame( new Map( [ [ room, [ update ] ] ] ) );
}

/**
 * Test/diagnostic observability: mirrors the socket and per-room sync
 * state onto a window global, the way the retired test WS provider
 * exposed `__gutenbergTestWebSocketSync`. The websocket lane has no HTTP
 * responses to await, so the e2e fixtures (and humans in devtools) read
 * this instead. Cheap: a plain object rebuilt on lifecycle edges.
 */
function publishDebugState(): void {
	const roomsState: Record< string, { synced: boolean } > = {};
	for ( const state of rooms.values() ) {
		roomsState[ state.room ] = { synced: state.cursor > 0 };
	}
	(
		window as Window & {
			__wpSyncWsState?: { open: boolean; rooms: typeof roomsState };
		}
	 ).__wpSyncWsState = {
		open: !! socket && WebSocket.OPEN === socket.readyState,
		rooms: roomsState,
	};
}

/**
 * Applies a server room response to its room's codec.
 *
 * @param serverRoom One room's response.
 */
function applyServerRoom( serverRoom: ServerRoom ): void {
	const state = rooms.get( serverRoom.room );
	if ( ! state ) {
		return;
	}

	// The inspector's wire tap: pushed traffic, decoded.
	if ( isSyncDebugEnabled() ) {
		recordPoll( {
			room: serverRoom.room,
			sent: [],
			received: serverRoom.updates ?? [],
			dispositions: serverRoom.dispositions as
				| Array< Record< string, unknown > >
				| undefined,
			cursorBefore: state.cursor,
			cursorAfter: serverRoom.end_cursor,
			serverDebug: (
				serverRoom as {
					_debug?: Record< string, unknown >;
				}
			 )._debug,
		} );
	}

	state.session.applyRemoteAwareness( serverRoom.awareness );

	// Peers in the room, delivered rows, or acks all count as company or
	// traffic for the quiet-while-alone idle clock.
	if (
		Object.keys( serverRoom.awareness ?? {} ).length > 1 ||
		( serverRoom.updates?.length ?? 0 ) > 0 ||
		( serverRoom.dispositions?.length ?? 0 ) > 0
	) {
		noteActivity();
	}

	const responses: EngineUpdate[] = [];
	for ( const update of serverRoom.updates ?? [] ) {
		try {
			const response = state.session.receiveUpdate( update );
			if ( response ) {
				responses.push( response );
			}
		} catch {
			// A malformed update must not tear down the socket.
		}
	}
	if ( serverRoom.dispositions && state.session.receiveDispositions ) {
		state.session.receiveDispositions( serverRoom.dispositions as never );
	}
	state.cursor = serverRoom.end_cursor;
	publishDebugState();

	// Updates produced while applying (an engine's ack/response) go back out.
	if ( responses.length > 0 ) {
		const pending = new Map< string, EngineUpdate[] >();
		pending.set( serverRoom.room, responses );
		sendFrame( pending );
	}
}

/**
 * Handles an incoming socket frame.
 *
 * @param {MessageEvent} event Socket message.
 */
function onMessage( event: MessageEvent ): void {
	let parsed: { type?: string; rooms?: ServerRoom[] };
	try {
		parsed = JSON.parse( String( event.data ) );
	} catch {
		return;
	}
	if ( 'sync' !== parsed.type || ! Array.isArray( parsed.rooms ) ) {
		return;
	}
	for ( const serverRoom of parsed.rooms ) {
		applyServerRoom( serverRoom );
	}
}

/**
 * Sends the initial sync for every room, carrying any queued local updates.
 */
function sendInitialSync(): void {
	if ( ! socket || WebSocket.OPEN !== socket.readyState ) {
		return;
	}
	const pending = new Map< string, EngineUpdate[] >();
	for ( const state of rooms.values() ) {
		pending.set( state.room, state.session.getInitialUpdates() );
	}
	sendFrame( pending );
}

/**
 * Periodically pushes local awareness so peers see presence and the server
 * keeps this client's entry fresh.
 */
function sendAwareness(): void {
	if (
		! socket ||
		WebSocket.OPEN !== socket.readyState ||
		0 === rooms.size
	) {
		return;
	}

	// Quiet-while-alone: instead of refreshing awareness forever for a tab
	// nobody shares a room with, close the socket once the session has been
	// idle and alone long enough. The solo-presence lane or a local update
	// reopens it.
	if (
		isSoloPresenceAvailable() &&
		! othersLikely() &&
		Date.now() - lastActivityAt > QUIET_AFTER_IDLE_MS
	) {
		quietHold = true;
		socket.close();
		return;
	}

	sendFrame( new Map() );
}

/**
 * Opens (or reuses) the shared socket.
 */
function connect(): void {
	if ( connecting || 0 === rooms.size ) {
		return;
	}
	if ( socket && WebSocket.OPEN === socket.readyState ) {
		return;
	}
	// Any explicit connect intent ends a quiet hold and restarts the idle
	// clock.
	quietHold = false;
	noteActivity();
	connecting = true;
	rooms.forEach( ( state ) =>
		state.onStatusChange( { status: 'connecting' } )
	);

	fetchToken()
		.then( ( token ) => {
			// The token rides the subprotocol offer list, never the URL:
			// the daemon consumes the `wp-sync-token.<hex>` offer and
			// echoes the base `wp-sync` protocol on accept.
			socket = new window.WebSocket( socketUrl(), [
				'wp-sync',
				`wp-sync-token.${ token }`,
			] );
			socket.addEventListener( 'open', () => {
				publishDebugState();
				connecting = false;
				reconnectAttempts = 0;
				rooms.forEach( ( state ) =>
					state.onStatusChange( { status: 'connected' } )
				);
				sendInitialSync();
				if ( ! awarenessTimer ) {
					awarenessTimer = setInterval(
						sendAwareness,
						AWARENESS_INTERVAL_MS
					);
				}
			} );
			socket.addEventListener( 'message', onMessage );
			socket.addEventListener( 'close', onClose );
			socket.addEventListener( 'error', () => socket?.close() );
		} )
		.catch( () => {
			connecting = false;
			scheduleReconnect();
		} );
}

/**
 * Handles socket close: mark disconnected and schedule a reconnect while any
 * room is still registered.
 */
function onClose(): void {
	publishDebugState();
	connecting = false;
	socket = null;
	if ( awarenessTimer ) {
		clearInterval( awarenessTimer );
		awarenessTimer = null;
	}
	if ( quietHold ) {
		// A deliberate quiet close: no error to surface, no reconnect loop.
		// The session stays usable; wake paths call connect() directly.
		return;
	}
	rooms.forEach( ( state ) =>
		state.onStatusChange( { status: 'disconnected' } )
	);
	if ( rooms.size > 0 ) {
		scheduleReconnect();
	}
}

/**
 * Schedules a reconnect with exponential backoff.
 */
function scheduleReconnect(): void {
	if ( reconnectTimer || 0 === rooms.size ) {
		return;
	}
	const delay = Math.min(
		RECONNECT_MAX_MS,
		RECONNECT_BASE_MS * 2 ** reconnectAttempts
	);
	reconnectAttempts++;
	reconnectTimer = setTimeout( () => {
		reconnectTimer = null;
		connect();
	}, delay );
}

export interface WebSocketRoomOptions {
	room: string;
	session: EngineSessionCodec;
	onStatusChange: ( status: ConnectionStatus ) => void;
}

export interface WebSocketManager {
	registerRoom: ( options: WebSocketRoomOptions ) => void;
	unregisterRoom: ( room: string ) => void;
}

/**
 * Registers a room: track it, forward its local updates to the socket, and
 * ensure the socket is open.
 *
 * @param {WebSocketRoomOptions} options Room options.
 */
function registerRoom( options: WebSocketRoomOptions ): void {
	if ( isSoloPresenceAvailable() ) {
		// The first room is the primary entity; its client id lets the
		// server tell this tab's own awareness entry apart from a peer's.
		if ( 0 === rooms.size ) {
			setSyncClientId( options.session.clientId );
		}
		if ( ! soloWakeInstalled ) {
			soloWakeInstalled = true;
			onOthersArrived( () => {
				if ( rooms.size > 0 ) {
					connect();
				}
			} );
		}
	}

	const state: RoomState = {
		room: options.room,
		session: options.session,
		cursor: 0,
		onStatusChange: options.onStatusChange,
	};
	rooms.set( options.room, state );
	options.session.onLocalUpdate( ( update ) =>
		sendUpdate( options.room, update )
	);

	// State accessors for the console inspector (duck-typed; inert unless
	// the inspector is enabled).
	registerDebugSession( options.room, options.session );

	if ( socket && WebSocket.OPEN === socket.readyState ) {
		// Socket already open: send this room's initial sync now.
		sendFrame(
			new Map( [ [ options.room, options.session.getInitialUpdates() ] ] )
		);
	} else {
		connect();
	}
}

/**
 * Unregisters a room; closes the socket when the last room leaves.
 *
 * @param {string} room Room identifier.
 */
function unregisterRoom( room: string ): void {
	const state = rooms.get( room );
	if ( state ) {
		state.session.destroy();
		rooms.delete( room );
	}
	unregisterDebugSession( room );
	if ( 0 === rooms.size ) {
		if ( reconnectTimer ) {
			clearTimeout( reconnectTimer );
			reconnectTimer = null;
		}
		if ( awarenessTimer ) {
			clearInterval( awarenessTimer );
			awarenessTimer = null;
		}
		socket?.close();
		socket = null;
	}
}

export const websocketManager: WebSocketManager = {
	registerRoom,
	unregisterRoom,
};

/**
 * Resets the module state. Test use only.
 */
export function resetWebSocketManagerForTesting(): void {
	rooms.clear();
	if ( reconnectTimer ) {
		clearTimeout( reconnectTimer );
		reconnectTimer = null;
	}
	if ( awarenessTimer ) {
		clearInterval( awarenessTimer );
		awarenessTimer = null;
	}
	socket = null;
	connecting = false;
	reconnectAttempts = 0;
	quietHold = false;
	lastActivityAt = 0;
	soloWakeInstalled = false;
}
