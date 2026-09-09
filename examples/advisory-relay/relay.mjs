#!/usr/bin/env node
/**
 * A bring-your-own relay for the advisory channel's WebSocket link.
 *
 * Editor tabs on short polling open one socket each; the relay tells the
 * tabs in a room who is present and passes "I saved a change, go and
 * poll" notices between them. It never sees post content, never writes
 * anything, and never calls WordPress: each tab proves who it is with a
 * signed ticket WordPress minted (a JSON Web Token, HS256), which the
 * relay checks with the secret it shares with WordPress. The message
 * formats and the ticket are described in docs/plan/advisory-channel.md
 * ("Bring your own relay"); this file is the reference implementation,
 * small enough to copy or to port to another language.
 *
 * Configuration (environment):
 *
 * - WP_SYNC_WEBSOCKET_TICKET_SECRET  the secret WordPress signs tickets
 *                                    with (the same constant name on the
 *                                    WordPress side). Required.
 * - ALLOWED_ORIGINS                  comma-separated page origins allowed
 *                                    to connect, e.g.
 *                                    `https://example.com`. Required: a
 *                                    socket from any other origin is
 *                                    refused before the ticket is read.
 * - PORT (8790), HOST (0.0.0.0)       where to listen. Terminate TLS in
 *                                    front of it and point WordPress's
 *                                    `wp_sync_websocket_url` filter at the
 *                                    `wss://` address.
 * - BLOG_ID                          optional; when set, tickets for any
 *                                    other site are refused.
 *
 * `GET /health` answers `200 OK` for monitoring.
 *
 * Runs on Node 20+ with the `ws` package (`npm install ws`).
 */

/**
 * External dependencies
 */
import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { WebSocketServer } from 'ws';

const SECRET = process.env.WP_SYNC_WEBSOCKET_TICKET_SECRET || '';
const PORT = Number( process.env.PORT || 8790 );
const HOST = process.env.HOST || '0.0.0.0';
const ALLOWED_ORIGINS = ( process.env.ALLOWED_ORIGINS || '' )
	.split( ',' )
	.map( ( origin ) => origin.trim() )
	.filter( Boolean );
const BLOG_ID = process.env.BLOG_ID ? Number( process.env.BLOG_ID ) : null;

/** The subprotocol every tab offers and the relay echoes. */
const SUBPROTOCOL = 'wp-sync';
/** The offer entry that carries the ticket: `wp-sync-token.<ticket>`. */
const TOKEN_PROTOCOL_PREFIX = 'wp-sync-token.';
/** Seconds of clock skew tolerated when checking a ticket's expiry. */
const LEEWAY_S = 30;
/** The rule for `<kind>/*` entries in a ticket's rooms. */
const COLLECTION_WILDCARD = '/*';
/** Limits, matching the plugin's own daemon. */
const MAX_TICKET_LENGTH = 4096;
const MAX_ROOM_LENGTH = 200;
const MAX_PRESENCE_BYTES = 16384;
const MAX_PRESENCE_TOKEN_LENGTH = 64;
const MESSAGE_RATE_LIMIT = 200;
const MESSAGE_RATE_WINDOW_MS = 5000;
const PING_INTERVAL_MS = 15000;
const MAX_PAYLOAD_BYTES = 64 * 1024;
const ROOM_PATTERN = /^[^/]+\/[^/:]+(?::\S+)?$/;

if ( ! SECRET ) {
	// eslint-disable-next-line no-console
	console.error(
		'[advisory-relay] WP_SYNC_WEBSOCKET_TICKET_SECRET is required.'
	);
	process.exit( 1 );
}
if ( 0 === ALLOWED_ORIGINS.length ) {
	// eslint-disable-next-line no-console
	console.error(
		'[advisory-relay] ALLOWED_ORIGINS is required (comma-separated page origins).'
	);
	process.exit( 1 );
}

/**
 * Verifies a ticket and returns its claims, or null.
 *
 * A ticket is `<header>.<payload>.<signature>`, each part base64url
 * without padding; the signature is HMAC-SHA256 over `<header>.<payload>`
 * with the shared secret; the header's `alg` must be `HS256`. Claims:
 * `user_id` (positive integer), `blog_id` (integer), `rooms` (list of
 * room names or `<kind>/*` entries), `iat` and `exp` (Unix seconds).
 *
 * @param {string} ticket The offered ticket.
 * @param {number} now    Unix seconds.
 * @return {null|{user_id: number, blog_id: number, rooms: string[], iat: number, exp: number}} The claims, or null.
 */
function verifyTicket( ticket, now = Math.floor( Date.now() / 1000 ) ) {
	if ( 'string' !== typeof ticket || ticket.length > MAX_TICKET_LENGTH ) {
		return null;
	}
	const parts = ticket.split( '.' );
	if ( 3 !== parts.length ) {
		return null;
	}
	const [ header, payload, signature ] = parts;
	const expected = createHmac( 'sha256', SECRET )
		.update( `${ header }.${ payload }` )
		.digest( 'base64url' );
	if (
		expected.length !== signature.length ||
		! timingSafeEqual( Buffer.from( expected ), Buffer.from( signature ) )
	) {
		return null;
	}
	let head;
	let claims;
	try {
		head = JSON.parse( Buffer.from( header, 'base64url' ).toString() );
		claims = JSON.parse( Buffer.from( payload, 'base64url' ).toString() );
	} catch {
		return null;
	}
	if ( 'HS256' !== head?.alg ) {
		return null;
	}
	if (
		! claims ||
		! Number.isInteger( claims.user_id ) ||
		claims.user_id < 1 ||
		! Number.isInteger( claims.blog_id ) ||
		! Number.isInteger( claims.iat ) ||
		! Number.isInteger( claims.exp ) ||
		! Array.isArray( claims.rooms ) ||
		claims.rooms.length > 50 ||
		! claims.rooms.every(
			( room ) =>
				'string' === typeof room &&
				room.length > 0 &&
				room.length <= MAX_ROOM_LENGTH
		)
	) {
		return null;
	}
	if ( now >= claims.exp + LEEWAY_S || claims.iat > now + LEEWAY_S ) {
		return null;
	}
	if ( null !== BLOG_ID && claims.blog_id !== BLOG_ID ) {
		return null;
	}
	return claims;
}

/**
 * Whether a ticket's rooms allow following a room: an exact entry, or a
 * `<kind>/*` entry when the room is a collection room (no `:`) of that
 * kind.
 *
 * @param {string[]} grants The ticket's rooms.
 * @param {string}   room   The room to follow.
 * @return {boolean} Whether the follow is allowed.
 */
function allows( grants, room ) {
	if ( grants.includes( room ) ) {
		return true;
	}
	if ( room.includes( ':' ) ) {
		return false;
	}
	const kind = room.slice( 0, room.indexOf( '/' ) );
	return grants.includes( kind + COLLECTION_WILDCARD );
}

/**
 * Refuses an upgrade request with a plain HTTP response.
 *
 * @param {import('node:net').Socket} socket The raw socket.
 * @param {number}                    status HTTP status.
 * @param {string}                    text   Status text and body.
 */
function refuse( socket, status, text ) {
	socket.write(
		`HTTP/1.1 ${ status } ${ text }\r\n` +
			'Connection: close\r\n' +
			'Content-Type: text/plain\r\n' +
			`Content-Length: ${ Buffer.byteLength( text ) }\r\n\r\n` +
			text
	);
	socket.destroy();
}

/**
 * Per room, the connections following it — keyed by SITE and room, so
 * one relay (and one secret) can serve several WordPress sites without
 * their tabs meeting: room names alone (`postType/post:12`) are not
 * site-qualified, and the site comes from the ticket's `blog_id`.
 *
 * @type {Map<string, Set<Follower>>}
 */
const rooms = new Map();

/**
 * The roster key for a site's room.
 *
 * @param {number} blogId The site, from the ticket.
 * @param {string} room   The room.
 * @return {string} The key.
 */
function scope( blogId, room ) {
	return `${ blogId }/${ room }`;
}

/**
 * Every open connection.
 *
 * @type {Set<Follower>}
 */
const followers = new Set();

/**
 * One connected tab.
 */
class Follower {
	/**
	 * @param {import('ws').WebSocket}                              ws     The socket.
	 * @param {{user_id: number, blog_id: number, rooms: string[]}} claims The ticket's claims.
	 */
	constructor( ws, claims ) {
		this.ws = ws;
		this.userId = claims.user_id;
		this.blogId = claims.blog_id;
		this.grants = claims.rooms;
		/** @type {Map<string, {clientId: number, token: string, presence: unknown}>} */
		this.follows = new Map();
		/** @type {number[]} */
		this.messageTimes = [];
		this.alive = true;
	}

	/**
	 * @param {Record<string, unknown>} frame The frame.
	 */
	send( frame ) {
		if ( this.ws.readyState === this.ws.OPEN ) {
			this.ws.send( JSON.stringify( frame ) );
		}
	}

	/**
	 * @param {string}   code     Error code (the plugin's REST codes).
	 * @param {string}   message  Human-readable reason.
	 * @param {string[]} roomList Rooms the error concerns.
	 */
	sendError( code, message, roomList = [] ) {
		this.send( { code, message, rooms: roomList, type: 'error' } );
	}
}

/**
 * Sends a room's roster — every follower's client id, presence token,
 * and latest presence — to each of its followers.
 *
 * @param {number} blogId The site.
 * @param {string} room   The room.
 */
function sendRoster( blogId, room ) {
	const roomFollowers = rooms.get( scope( blogId, room ) );
	if ( ! roomFollowers ) {
		return;
	}
	const peers = [];
	for ( const follower of roomFollowers ) {
		const follow = follower.follows.get( room );
		peers.push( {
			client_id: follow.clientId,
			presence: follow.presence,
			token: follow.token,
		} );
	}
	const frame = { event: 'roster', peers, room, type: 'advisory' };
	for ( const follower of roomFollowers ) {
		follower.send( frame );
	}
}

/**
 * Relays a "rows landed, go and poll" notice to a room's other followers.
 *
 * @param {string}   room      The room whose followers are told.
 * @param {string}   announced The room the notice names (or `*`).
 * @param {Follower} sender    The follower to skip (its site scopes the room).
 */
function sendAnnounce( room, announced, sender ) {
	const frame = { event: 'announce', room: announced, type: 'advisory' };
	for ( const follower of rooms.get( scope( sender.blogId, room ) ) ?? [] ) {
		if ( follower !== sender ) {
			follower.send( frame );
		}
	}
}

/**
 * Validates an advisory frame the way the plugin's daemon does.
 *
 * @param {unknown} message The decoded frame.
 * @return {{ok: true, value: Record<string, unknown>}|{ok: false, error: string, room?: string}} The normalized fields, or the reason.
 */
function validate( message ) {
	if ( ! message || 'object' !== typeof message ) {
		return { ok: false, error: 'Expected an advisory message.' };
	}
	const { room, client_id: clientId } = message;
	if ( 'string' !== typeof room || ! ROOM_PATTERN.test( room ) ) {
		return { ok: false, error: 'Invalid room identifier.' };
	}
	if ( ! Number.isInteger( clientId ) || clientId < 1 ) {
		return { ok: false, error: 'Invalid client_id.', room };
	}
	const value = { room, clientId };
	if (
		undefined !== message.presence_token &&
		null !== message.presence_token
	) {
		const token = message.presence_token;
		if (
			'string' !== typeof token ||
			'' === token ||
			token.length > MAX_PRESENCE_TOKEN_LENGTH
		) {
			return { ok: false, error: 'Invalid presence token.', room };
		}
		value.presenceToken = token;
	}
	if ( 'presence' in message ) {
		const presence = message.presence;
		if (
			null !== presence &&
			( 'object' !== typeof presence || Array.isArray( presence ) )
		) {
			return { ok: false, error: 'Invalid presence state.', room };
		}
		if (
			null !== presence &&
			Buffer.byteLength( JSON.stringify( presence ) ) > MAX_PRESENCE_BYTES
		) {
			return { ok: false, error: 'Presence state too large.', room };
		}
		value.presence = presence;
		value.hasPresence = true;
	}
	if ( undefined !== message.announce && null !== message.announce ) {
		const announce = message.announce;
		if (
			'string' !== typeof announce ||
			'' === announce ||
			announce.length > MAX_ROOM_LENGTH
		) {
			return { ok: false, error: 'Invalid announce.', room };
		}
		value.announce = announce;
	}
	return { ok: true, value };
}

/**
 * Handles one advisory frame: the first frame for a room follows it
 * (allowed by the ticket, bound to one client id), `presence` replaces
 * this tab's presence in the roster, `announce` names a room the tab
 * just landed rows in.
 *
 * @param {Follower} follower The sender.
 * @param {unknown}  message  The decoded frame.
 */
function handleMessage( follower, message ) {
	const result = validate( message );
	if ( ! result.ok ) {
		follower.sendError(
			'websocket_invalid_advisory',
			result.error,
			result.room ? [ result.room ] : []
		);
		return;
	}
	const { room, clientId, presenceToken, presence, hasPresence, announce } =
		result.value;

	let rosterChanged = false;
	let follow = follower.follows.get( room );
	if ( ! follow ) {
		if ( ! allows( follower.grants, room ) ) {
			follower.sendError(
				'rest_cannot_edit',
				'You do not have permission to sync this room.',
				[ room ]
			);
			return;
		}
		follow = { clientId, token: presenceToken ?? '', presence: null };
		follower.follows.set( room, follow );
		const key = scope( follower.blogId, room );
		if ( ! rooms.has( key ) ) {
			rooms.set( key, new Set() );
		}
		rooms.get( key ).add( follower );
		rosterChanged = true;
	} else if ( follow.clientId !== clientId ) {
		// One client id per socket and room: a different id could
		// impersonate another tab in the roster.
		follower.ws.close( 1008, 'client_id mismatch' );
		return;
	}

	if ( undefined !== presenceToken && presenceToken !== follow.token ) {
		follow.token = presenceToken;
		rosterChanged = true;
	}
	if ( hasPresence ) {
		follow.presence = presence;
		rosterChanged = true;
	}
	if ( rosterChanged ) {
		sendRoster( follower.blogId, room );
	}
	if ( undefined !== announce ) {
		sendAnnounce( room, announce, follower );
	}
}

/**
 * A socket closed: leave every room it followed and tell the others.
 *
 * @param {Follower} follower The follower.
 */
function handleClose( follower ) {
	followers.delete( follower );
	for ( const room of follower.follows.keys() ) {
		const key = scope( follower.blogId, room );
		const roomFollowers = rooms.get( key );
		if ( ! roomFollowers ) {
			continue;
		}
		roomFollowers.delete( follower );
		if ( 0 === roomFollowers.size ) {
			rooms.delete( key );
		} else {
			sendRoster( follower.blogId, room );
		}
	}
	follower.follows.clear();
}

const server = createServer( ( request, response ) => {
	if ( 'GET' === request.method && '/health' === request.url ) {
		response.writeHead( 200, { 'Content-Type': 'text/plain' } );
		response.end( 'OK' );
		return;
	}
	response.writeHead( 400, { 'Content-Type': 'text/plain' } );
	response.end( 'WebSocket upgrade required.' );
} );

const wss = new WebSocketServer( {
	noServer: true,
	maxPayload: MAX_PAYLOAD_BYTES,
	// Echo only the base subprotocol, never the ticket entry.
	handleProtocols: ( protocols ) =>
		protocols.has( SUBPROTOCOL ) ? SUBPROTOCOL : false,
} );

server.on( 'upgrade', ( request, socket, head ) => {
	const origin = request.headers.origin ?? '';
	if ( ! ALLOWED_ORIGINS.includes( origin ) ) {
		refuse( socket, 403, 'Forbidden' );
		return;
	}
	const offers = String( request.headers[ 'sec-websocket-protocol' ] ?? '' )
		.split( ',' )
		.map( ( offer ) => offer.trim() );
	const offer = offers.find( ( entry ) =>
		entry.startsWith( TOKEN_PROTOCOL_PREFIX )
	);
	const claims =
		offers.includes( SUBPROTOCOL ) && offer
			? verifyTicket( offer.slice( TOKEN_PROTOCOL_PREFIX.length ) )
			: null;
	if ( ! claims ) {
		refuse( socket, 403, 'Forbidden' );
		return;
	}
	wss.handleUpgrade( request, socket, head, ( ws ) => {
		wss.emit( 'connection', ws, request, claims );
	} );
} );

wss.on( 'connection', ( ws, request, claims ) => {
	const follower = new Follower( ws, claims );
	followers.add( follower );
	ws.on( 'pong', () => {
		follower.alive = true;
	} );
	ws.on( 'message', ( data, isBinary ) => {
		if ( isBinary ) {
			ws.close( 1003, 'Text frames only' );
			return;
		}
		// A rolling per-socket rate budget; a well-behaved tab coalesces
		// its sends and stays far below it.
		const now = Date.now();
		follower.messageTimes = follower.messageTimes.filter(
			( time ) => time > now - MESSAGE_RATE_WINDOW_MS
		);
		follower.messageTimes.push( now );
		if ( follower.messageTimes.length > MESSAGE_RATE_LIMIT ) {
			ws.close( 1008, 'Message rate exceeded' );
			return;
		}
		let message;
		try {
			message = JSON.parse( data.toString() );
		} catch {
			follower.sendError(
				'websocket_invalid_message',
				'Expected an advisory message.'
			);
			return;
		}
		if ( 'advisory' !== message?.type ) {
			follower.sendError(
				'websocket_invalid_message',
				'Expected an advisory message.'
			);
			return;
		}
		handleMessage( follower, message );
	} );
	ws.on( 'close', () => handleClose( follower ) );
	ws.on( 'error', () => ws.terminate() );
} );

// Keepalive: a tab that misses a whole ping interval is gone.
const pinger = setInterval( () => {
	for ( const follower of followers ) {
		if ( ! follower.alive ) {
			follower.ws.terminate();
			continue;
		}
		follower.alive = false;
		follower.ws.ping();
	}
}, PING_INTERVAL_MS );

function shutdown() {
	clearInterval( pinger );
	for ( const ws of wss.clients ) {
		ws.close( 1001, 'Server shutting down' );
	}
	server.close( () => process.exit( 0 ) );
	setTimeout( () => process.exit( 0 ), 1000 ).unref();
}
process.on( 'SIGINT', shutdown );
process.on( 'SIGTERM', shutdown );

server.listen( PORT, HOST, () => {
	// eslint-disable-next-line no-console
	console.log(
		`[advisory-relay] listening on ws://${ HOST }:${ PORT } for ${ ALLOWED_ORIGINS.join(
			', '
		) }`
	);
} );
