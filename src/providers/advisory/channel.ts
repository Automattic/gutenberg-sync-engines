/**
 * Internal dependencies
 */
import { onLocalWrite } from './announce';
import {
	getDiscoveredPeers,
	getPresenceToken,
	getAdvisorySettings,
	getSyncClientId,
	installSignaling,
	isSignalingAvailable,
	onPeersChanged,
	onSignal,
	sendSignal,
	type DiscoveredPeer,
	type Signal,
} from './signaling';

/**
 * The advisory channel: a browser-to-browser mesh over WebRTC data channels
 * between the tabs editing one post. It carries two things and nothing
 * else: presence (cursors, selections, names) and the notice "I landed rows
 * on the server, go and poll". No document content ever crosses it, and
 * nothing that arrives on it is trusted for anything but display and a
 * decision to poll sooner. Every correctness property comes from the poll
 * the notice triggers (see docs/plan/advisory-channel.md).
 *
 * Handshake: for each pair of discovered tabs, the one with the LOWER
 * presence token initiates. ICE gathering completes before the offer or
 * the answer is sent, so a handshake costs two heartbeat hops (offer out,
 * answer back) instead of a trickle of candidates at heartbeat cadence.
 *
 * Coverage: the polling manager asks whether every peer it knows about —
 * every discovered token AND every client id in the last awareness map —
 * is reachable over an open channel. Only then does it stop polling on a
 * timer. A peer that cannot connect (no STUN reachable, a symmetric NAT
 * without TURN, WebRTC disabled by an extension) simply keeps everyone on
 * the timer cadence; nothing is lost.
 */

const DATA_CHANNEL_LABEL = 'wp-sync-advisory';
const ANSWER_TIMEOUT_MS = 15000;
const SEEN_SIGNAL_IDS_MAX = 500;
const DISCONNECT_GRACE_MS = 5000;
const MAX_CONNECT_ATTEMPTS = 3;
const RETRY_BASE_MS = 2000;
const PRESENCE_SEND_INTERVAL_MS = 250;
const DEFAULT_MAX_PEERS = 8;

type ChannelMessage =
	| { t: 'hello'; clientId: number | null }
	| { t: 'presence'; room: string; clientId: number; state: unknown }
	| { t: 'announce'; room: string }
	| { t: 'bye' };

interface PeerLink {
	token: string;
	clientId: number | null;
	initiator: boolean;
	pc: RTCPeerConnection | null;
	dc: RTCDataChannel | null;
	open: boolean;
	attempts: number;
	localSent: boolean;
	remoteSet: boolean;
	pendingCandidates: RTCIceCandidateInit[];
	/** Local candidates found before the description went out. */
	earlyCandidates: RTCIceCandidateInit[];
	retryTimer: ReturnType< typeof setTimeout > | null;
	answerTimer: ReturnType< typeof setTimeout > | null;
	disconnectTimer: ReturnType< typeof setTimeout > | null;
}

export interface PresenceEntry {
	room: string;
	clientId: number;
	state: unknown;
}

let started = false;
let subscribed = false;
let disabledByTransport = false;
let overCap = false;
const links: Map< string, PeerLink > = new Map();
const presence: Map< string, Map< number, unknown > > = new Map();
const lastSentPresence: Map< string, { json: string; entry: PresenceEntry } > =
	new Map();
let presenceTimer: ReturnType< typeof setInterval > | null = null;
let presenceSource: ( () => PresenceEntry[] ) | null = null;

const seenSignalIds: Set< string > = new Set();

const announceListeners: Array< ( room: string ) => void > = [];
const presenceListeners: Array< ( room: string ) => void > = [];
const coverageListeners: Array< () => void > = [];

function hasRTC(): boolean {
	return 'function' === typeof globalThis.RTCPeerConnection;
}

function maxPeers(): number {
	const value = Number(
		getAdvisorySettings()?.maxPeers ?? DEFAULT_MAX_PEERS
	);
	return Number.isFinite( value ) && value > 0 ? value : DEFAULT_MAX_PEERS;
}

/**
 * Whether the channel is running and allowed to connect: started by a
 * transport, not disabled by the active transport, under the peer cap, and
 * the page has both the signaling lane and WebRTC.
 */
export function isAdvisoryActive(): boolean {
	return (
		started &&
		! disabledByTransport &&
		! overCap &&
		isSignalingAvailable() &&
		hasRTC()
	);
}

function emit( listeners: Array< ( room: string ) => void >, room: string ) {
	for ( const callback of listeners ) {
		callback( room );
	}
}

function emitCoverage(): void {
	for ( const callback of coverageListeners ) {
		callback();
	}
}

function clearTimer(
	link: PeerLink,
	name: 'retryTimer' | 'answerTimer' | 'disconnectTimer'
): void {
	const timer = link[ name ];
	if ( timer ) {
		clearTimeout( timer );
		link[ name ] = null;
	}
}

function forgetPresenceOf( link: PeerLink ): void {
	if ( null === link.clientId ) {
		return;
	}
	for ( const [ room, states ] of presence ) {
		if ( states.delete( link.clientId ) ) {
			emit( presenceListeners, room );
		}
	}
}

function closePeerConnection( link: PeerLink ): void {
	clearTimer( link, 'answerTimer' );
	clearTimer( link, 'disconnectTimer' );
	const { pc, dc } = link;
	link.pc = null;
	link.dc = null;
	link.open = false;
	link.localSent = false;
	link.remoteSet = false;
	link.pendingCandidates = [];
	link.earlyCandidates = [];
	try {
		dc?.close();
	} catch {
		// Already closed.
	}
	try {
		pc?.close();
	} catch {
		// Already closed.
	}
}

function send( link: PeerLink, message: ChannelMessage ): void {
	if ( ! link.open || ! link.dc || 'open' !== link.dc.readyState ) {
		return;
	}
	try {
		link.dc.send( JSON.stringify( message ) );
	} catch {
		// A closing channel; the close handler takes it from here.
	}
}

function broadcast( message: ChannelMessage ): void {
	for ( const link of links.values() ) {
		send( link, message );
	}
}

/**
 * A peer connection went down. Initiators retry with backoff up to a cap;
 * responders wait for the initiator's next offer. A peer whose token is
 * gone is not retried at all.
 *
 * @param link    The link.
 * @param noRetry Whether the peer said goodbye (no retry).
 */
function linkDown( link: PeerLink, noRetry = false ): void {
	closePeerConnection( link );
	forgetPresenceOf( link );
	emitCoverage();

	if ( noRetry || links.get( link.token ) !== link || ! isAdvisoryActive() ) {
		return;
	}
	link.attempts++;
	if ( link.attempts >= MAX_CONNECT_ATTEMPTS || ! link.initiator ) {
		return;
	}
	clearTimer( link, 'retryTimer' );
	link.retryTimer = setTimeout(
		() => {
			link.retryTimer = null;
			if ( links.get( link.token ) === link && isAdvisoryActive() ) {
				void connect( link );
			}
		},
		RETRY_BASE_MS * 2 ** ( link.attempts - 1 )
	);
}

function destroyLink( link: PeerLink ): void {
	clearTimer( link, 'retryTimer' );
	closePeerConnection( link );
	links.delete( link.token );
	forgetPresenceOf( link );
	emitCoverage();
}

function closeAll(): void {
	broadcast( { t: 'bye' } );
	for ( const link of Array.from( links.values() ) ) {
		destroyLink( link );
	}
}

function handleMessage( link: PeerLink, raw: unknown ): void {
	let message: ChannelMessage;
	try {
		message = JSON.parse( String( raw ) ) as ChannelMessage;
	} catch {
		return;
	}
	if ( ! message || 'object' !== typeof message ) {
		return;
	}
	switch ( message.t ) {
		case 'hello': {
			const clientId = Number( message.clientId );
			if ( Number.isFinite( clientId ) && clientId > 0 ) {
				link.clientId = clientId;
			}
			emitCoverage();
			break;
		}
		case 'presence': {
			const clientId = Number( message.clientId );
			if (
				'string' !== typeof message.room ||
				! Number.isFinite( clientId ) ||
				clientId <= 0
			) {
				return;
			}
			if ( null === link.clientId ) {
				link.clientId = clientId;
			}
			let states = presence.get( message.room );
			if ( ! states ) {
				states = new Map();
				presence.set( message.room, states );
			}
			if ( null === message.state || undefined === message.state ) {
				states.delete( clientId );
			} else {
				states.set( clientId, message.state );
			}
			emit( presenceListeners, message.room );
			break;
		}
		case 'announce':
			if ( 'string' === typeof message.room ) {
				emit( announceListeners, message.room );
			}
			break;
		case 'bye':
			linkDown( link, true );
			break;
		default:
			break;
	}
}

function wireDataChannel( link: PeerLink, dc: RTCDataChannel ): void {
	link.dc = dc;
	dc.onopen = () => {
		if ( link.dc !== dc ) {
			return;
		}
		link.open = true;
		link.attempts = 0;
		clearTimer( link, 'answerTimer' );
		send( link, { t: 'hello', clientId: getSyncClientId() } );
		// A new peer gets this tab's current presence right away instead
		// of waiting for the next change.
		for ( const { entry } of lastSentPresence.values() ) {
			send( link, { t: 'presence', ...entry } );
		}
		emitCoverage();
	};
	dc.onmessage = ( event ) => {
		if ( link.dc === dc ) {
			handleMessage( link, event.data );
		}
	};
	dc.onclose = () => {
		if ( link.dc === dc ) {
			linkDown( link );
		}
	};
}

function createPeerConnection( link: PeerLink ): RTCPeerConnection {
	const pc = new RTCPeerConnection( {
		iceServers: getAdvisorySettings()?.iceServers ?? [],
	} );
	pc.onicecandidate = ( event ) => {
		// Trickle: the description goes out at once and candidates follow
		// as they are found, each on the next carrier (a poll at the
		// company cadence, else a heartbeat beat). Candidates found before
		// the description is sent wait for it, so order holds.
		if ( ! event.candidate || link.pc !== pc ) {
			return;
		}
		const candidate = event.candidate.toJSON();
		if ( link.localSent ) {
			sendSignal( link.token, 'ice', JSON.stringify( candidate ) );
		} else {
			link.earlyCandidates.push( candidate );
		}
	};
	pc.onconnectionstatechange = () => {
		if ( link.pc !== pc ) {
			return;
		}
		switch ( pc.connectionState ) {
			case 'failed':
			case 'closed':
				linkDown( link );
				break;
			case 'disconnected':
				if ( ! link.disconnectTimer ) {
					link.disconnectTimer = setTimeout( () => {
						link.disconnectTimer = null;
						if (
							link.pc === pc &&
							'disconnected' === pc.connectionState
						) {
							linkDown( link );
						}
					}, DISCONNECT_GRACE_MS );
				}
				break;
			case 'connected':
				clearTimer( link, 'disconnectTimer' );
				break;
			default:
				break;
		}
	};
	pc.ondatachannel = ( event ) => {
		if ( link.pc === pc ) {
			wireDataChannel( link, event.channel );
		}
	};
	return pc;
}

/**
 * Marks the local description as sent and flushes the candidates found
 * before it went out.
 *
 * @param link The link.
 */
function localDescriptionSent( link: PeerLink ): void {
	link.localSent = true;
	for ( const candidate of link.earlyCandidates.splice( 0 ) ) {
		sendSignal( link.token, 'ice', JSON.stringify( candidate ) );
	}
}

async function flushPendingCandidates( link: PeerLink ): Promise< void > {
	const pc = link.pc;
	const candidates = link.pendingCandidates;
	link.pendingCandidates = [];
	for ( const candidate of candidates ) {
		if ( link.pc !== pc || ! pc ) {
			return;
		}
		try {
			await pc.addIceCandidate( candidate );
		} catch {
			// A stale candidate for a description that was replaced.
		}
	}
}

/**
 * Initiator side: offer to a peer.
 *
 * @param link The link to connect.
 */
async function connect( link: PeerLink ): Promise< void > {
	closePeerConnection( link );
	const pc = createPeerConnection( link );
	link.pc = pc;
	try {
		wireDataChannel( link, pc.createDataChannel( DATA_CHANNEL_LABEL ) );
		const offer = await pc.createOffer();
		await pc.setLocalDescription( offer );
		if ( link.pc !== pc || ! pc.localDescription ) {
			return;
		}
		sendSignal(
			link.token,
			'offer',
			JSON.stringify( pc.localDescription )
		);
		localDescriptionSent( link );
		clearTimer( link, 'answerTimer' );
		link.answerTimer = setTimeout( () => {
			link.answerTimer = null;
			if ( link.pc === pc && ! link.open ) {
				linkDown( link );
			}
		}, ANSWER_TIMEOUT_MS );
	} catch {
		if ( link.pc === pc ) {
			linkDown( link );
		}
	}
}

/**
 * Responder side: answer a peer's offer.
 *
 * @param link  The link.
 * @param offer The serialized offer description.
 */
async function respond( link: PeerLink, offer: string ): Promise< void > {
	closePeerConnection( link );
	const pc = createPeerConnection( link );
	link.pc = pc;
	try {
		await pc.setRemoteDescription(
			JSON.parse( offer ) as RTCSessionDescriptionInit
		);
		if ( link.pc !== pc ) {
			return;
		}
		link.remoteSet = true;
		await flushPendingCandidates( link );
		const answer = await pc.createAnswer();
		await pc.setLocalDescription( answer );
		if ( link.pc !== pc || ! pc.localDescription ) {
			return;
		}
		sendSignal(
			link.token,
			'answer',
			JSON.stringify( pc.localDescription )
		);
		localDescriptionSent( link );
	} catch {
		if ( link.pc === pc ) {
			linkDown( link );
		}
	}
}

function createLink( token: string, clientId: number ): PeerLink {
	const mine = getPresenceToken() ?? '';
	const link: PeerLink = {
		token,
		clientId: clientId > 0 ? clientId : null,
		initiator: mine < token,
		pc: null,
		dc: null,
		open: false,
		attempts: 0,
		localSent: false,
		remoteSet: false,
		pendingCandidates: [],
		earlyCandidates: [],
		retryTimer: null,
		answerTimer: null,
		disconnectTimer: null,
	};
	links.set( token, link );
	return link;
}

/**
 * Brings the mesh in line with the discovered peer list: new peers get a
 * link (initiators offer at once), departed peers are dropped, and a room
 * over the peer cap stands the channel down entirely.
 *
 * @param discovered The peers the last heartbeat reported.
 */
function reconcile( discovered: DiscoveredPeer[] ): void {
	if ( ! started || disabledByTransport ) {
		return;
	}
	if ( discovered.length > maxPeers() ) {
		if ( ! overCap ) {
			overCap = true;
			closeAll();
		}
		return;
	}
	overCap = false;

	const seen = new Set( discovered.map( ( peer ) => peer.token ) );
	for ( const link of Array.from( links.values() ) ) {
		if ( ! seen.has( link.token ) ) {
			destroyLink( link );
		}
	}
	for ( const peer of discovered ) {
		const link =
			links.get( peer.token ) ?? createLink( peer.token, peer.clientId );
		if ( peer.clientId > 0 && null === link.clientId ) {
			link.clientId = peer.clientId;
		}
		if (
			link.initiator &&
			! link.pc &&
			! link.retryTimer &&
			link.attempts < MAX_CONNECT_ATTEMPTS &&
			isAdvisoryActive()
		) {
			void connect( link );
		}
	}
	emitCoverage();
}

function handleSignal( signal: Signal ): void {
	if ( ! isAdvisoryActive() ) {
		return;
	}
	// A retried request may deliver a signal twice; the second copy of an
	// offer would tear down the connection its first copy is building.
	if ( signal.id ) {
		if ( seenSignalIds.has( signal.id ) ) {
			return;
		}
		seenSignalIds.add( signal.id );
		if ( seenSignalIds.size > SEEN_SIGNAL_IDS_MAX ) {
			seenSignalIds.delete( seenSignalIds.values().next().value! );
		}
	}
	let link = links.get( signal.from );
	switch ( signal.kind ) {
		case 'offer':
			if ( ! link ) {
				link = createLink( signal.from, 0 );
			}
			if ( link.initiator ) {
				// Glare cannot happen (the lower token initiates); an
				// offer from a higher token is a stale message.
				return;
			}
			void respond( link, signal.data );
			break;
		case 'answer':
			if ( link?.pc && link.initiator && ! link.remoteSet ) {
				const pc = link.pc;
				link.remoteSet = true;
				void pc
					.setRemoteDescription(
						JSON.parse( signal.data ) as RTCSessionDescriptionInit
					)
					.then( () => flushPendingCandidates( link! ) )
					.catch( () => {
						if ( link!.pc === pc ) {
							linkDown( link! );
						}
					} );
			}
			break;
		case 'ice':
			if ( link?.pc ) {
				let candidate: RTCIceCandidateInit;
				try {
					candidate = JSON.parse(
						signal.data
					) as RTCIceCandidateInit;
				} catch {
					return;
				}
				if ( link.remoteSet ) {
					void link.pc.addIceCandidate( candidate ).catch( () => {} );
				} else {
					link.pendingCandidates.push( candidate );
				}
			}
			break;
		case 'bye':
			if ( link ) {
				linkDown( link, true );
			}
			break;
		default:
			break;
	}
}

function sendPresenceTick(): void {
	if ( ! presenceSource ) {
		return;
	}
	let anyOpen = false;
	for ( const link of links.values() ) {
		if ( link.open ) {
			anyOpen = true;
			break;
		}
	}
	if ( ! anyOpen ) {
		return;
	}
	for ( const entry of presenceSource() ) {
		const json = JSON.stringify( entry.state ?? null );
		if ( lastSentPresence.get( entry.room )?.json === json ) {
			continue;
		}
		lastSentPresence.set( entry.room, { json, entry } );
		broadcast( { t: 'presence', ...entry } );
	}
}

function onPageHide(): void {
	broadcast( { t: 'bye' } );
}

/**
 * Starts the channel: subscribes to discovery and signaling, connects to
 * the peers already discovered, and starts the presence loop. A page
 * without the signaling lane or without WebRTC stays inert.
 */
export function startAdvisoryChannel(): void {
	if ( started ) {
		return;
	}
	if ( ! isSignalingAvailable() || ! hasRTC() ) {
		return;
	}
	started = true;
	installSignaling();
	if ( ! subscribed ) {
		subscribed = true;
		onPeersChanged( reconcile );
		onSignal( handleSignal );
		onLocalWrite( ( room ) => broadcast( { t: 'announce', room } ) );
	}
	window.addEventListener( 'pagehide', onPageHide );
	presenceTimer = setInterval( sendPresenceTick, PRESENCE_SEND_INTERVAL_MS );
	reconcile( getDiscoveredPeers() );
}

/**
 * Stops the channel and says goodbye to every peer.
 */
export function stopAdvisoryChannel(): void {
	if ( ! started ) {
		return;
	}
	closeAll();
	started = false;
	overCap = false;
	window.removeEventListener( 'pagehide', onPageHide );
	if ( presenceTimer ) {
		clearInterval( presenceTimer );
		presenceTimer = null;
	}
	presence.clear();
	lastSentPresence.clear();
	emitCoverage();
}

/**
 * The active transport's switch. A transport that delivers its own wake
 * signal (long polling's held request) turns the channel off while it is
 * connected; the request is honored only while the transport says it is
 * connected, and turning it back on reconnects to the discovered peers.
 *
 * @param disabled Whether the transport wants the channel off.
 */
export function setAdvisoryDisabledByTransport( disabled: boolean ): void {
	if ( disabled === disabledByTransport ) {
		return;
	}
	disabledByTransport = disabled;
	if ( disabled ) {
		closeAll();
		emitCoverage();
	} else if ( started ) {
		reconcile( getDiscoveredPeers() );
	}
}

/**
 * Whether every known peer is reachable over an open channel: every token
 * the heartbeat discovered, and every client id in the given list (the
 * last awareness map, own id excluded). False when nobody is known at all:
 * "alone" is the transports' decision, not the channel's.
 *
 * @param clientIds Client ids the last poll's awareness map reported.
 */
export function advisoryCoversClients( clientIds: number[] ): boolean {
	if ( ! isAdvisoryActive() ) {
		return false;
	}
	const own = getSyncClientId();
	const others = clientIds.filter( ( id ) => id !== own );
	const discovered = getDiscoveredPeers();
	if ( 0 === discovered.length && 0 === others.length ) {
		return false;
	}
	const openClientIds = new Set< number >();
	for ( const link of links.values() ) {
		if ( link.open && null !== link.clientId ) {
			openClientIds.add( link.clientId );
		}
	}
	for ( const peer of discovered ) {
		if ( ! links.get( peer.token )?.open ) {
			return false;
		}
	}
	for ( const id of others ) {
		if ( ! openClientIds.has( id ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The presence states peers sent over the channel for one room, keyed by
 * client id, for overlaying on the (older) copy a poll response carries.
 *
 * @param room The room name.
 */
export function getChannelPresence( room: string ): Record< string, unknown > {
	const states = presence.get( room );
	const result: Record< string, unknown > = {};
	if ( ! states ) {
		return result;
	}
	for ( const [ clientId, state ] of states ) {
		result[ String( clientId ) ] = state;
	}
	return result;
}

/**
 * Registers the source of this tab's presence: the polling manager's rooms
 * and their sessions' local awareness. Polled every 250 ms while any peer
 * is connected; only changes are sent.
 *
 * @param source Returns the current presence entries.
 */
export function setPresenceSource(
	source: ( () => PresenceEntry[] ) | null
): void {
	presenceSource = source;
}

/**
 * Registers a listener for "a peer landed rows" notices.
 *
 * @param callback Called with the room name (or `*`).
 */
export function onAdvisoryAnnounce( callback: ( room: string ) => void ): void {
	announceListeners.push( callback );
}

/**
 * Registers a listener for presence changes received over the channel.
 *
 * @param callback Called with the room whose presence changed.
 */
export function onAdvisoryPresence( callback: ( room: string ) => void ): void {
	presenceListeners.push( callback );
}

/**
 * Registers a listener for coverage changes (a peer connected, dropped,
 * said hello, or the channel was switched off or on).
 *
 * @param callback Called on every change.
 */
export function onAdvisoryCoverageChanged( callback: () => void ): void {
	coverageListeners.push( callback );
}

/**
 * A snapshot for the sync inspector.
 */
export function getAdvisoryDebugState(): Record< string, unknown > {
	return {
		active: isAdvisoryActive(),
		started,
		disabledByTransport,
		overCap,
		token: getPresenceToken(),
		peers: Array.from( links.values() ).map( ( link ) => ( {
			token: link.token,
			clientId: link.clientId,
			initiator: link.initiator,
			open: link.open,
			attempts: link.attempts,
			connectionState: link.pc?.connectionState ?? null,
		} ) ),
	};
}

/**
 * Resets the module state. Test use only.
 */
export function resetAdvisoryChannelForTesting(): void {
	for ( const link of Array.from( links.values() ) ) {
		clearTimer( link, 'retryTimer' );
		closePeerConnection( link );
	}
	links.clear();
	presence.clear();
	lastSentPresence.clear();
	seenSignalIds.clear();
	if ( presenceTimer ) {
		clearInterval( presenceTimer );
		presenceTimer = null;
	}
	if ( started ) {
		window.removeEventListener( 'pagehide', onPageHide );
	}
	started = false;
	subscribed = false;
	disabledByTransport = false;
	overCap = false;
	presenceSource = null;
	announceListeners.length = 0;
	presenceListeners.length = 0;
	coverageListeners.length = 0;
}
