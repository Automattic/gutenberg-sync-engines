/**
 * WordPress dependencies
 */
import { addAction } from '@wordpress/hooks';

/**
 * The solo-presence lane: lets a transport stop talking to the server while
 * one person edits alone, without missing the moment a second person opens
 * the same post.
 *
 * The question "did someone join me?" rides the heartbeat WordPress already
 * sends from every editor screen. Each editor tab gets a per-tab token,
 * stamped server-side when the editor page renders and refreshed on every
 * heartbeat. The server answers each heartbeat with whether any OTHER tab or
 * live sync session is present in this post's room; the answer drives the
 * transports' quiet/wake decisions (see the polling and websocket managers).
 *
 * When the injected settings or `wp.heartbeat` are missing, the lane reports
 * itself unavailable and the transports keep today's always-on behavior.
 */

const HOOK_NAMESPACE = 'gutenberg-sync-engines/solo-presence';

/**
 * Key used in both directions of the heartbeat payload. Mirrors
 * Gutenberg_Sync_Engines_Solo_Presence::HEARTBEAT_KEY on the server.
 */
export const HEARTBEAT_DATA_KEY = 'gutenberg_sync_engines_presence';

interface SoloSessionSettings {
	room: string;
	token: string;
	othersPresent?: boolean;
}

interface HeartbeatApi {
	interval: ( ...args: unknown[] ) => unknown;
}

let installed = false;
let othersBelieved = false;
let believesInitialized = false;
let syncClientId: number | null = null;
const arrivalCallbacks: Array< () => void > = [];

function getSettings(): SoloSessionSettings | null {
	const settings = (
		window as {
			_gutenbergSyncEnginesSettings?: {
				soloSession?: SoloSessionSettings;
			};
		}
	 )._gutenbergSyncEnginesSettings?.soloSession;

	if ( ! settings?.room || ! settings?.token ) {
		return null;
	}

	return settings;
}

function getHeartbeat(): HeartbeatApi | null {
	const heartbeat = (
		window as {
			wp?: { heartbeat?: HeartbeatApi };
		}
	 ).wp?.heartbeat;

	return typeof heartbeat?.interval === 'function' ? heartbeat : null;
}

function initializeBelief(): void {
	if ( believesInitialized ) {
		return;
	}
	believesInitialized = true;
	othersBelieved = true === getSettings()?.othersPresent;
}

/**
 * Attaches this tab's presence probe to every outgoing heartbeat.
 *
 * @param data The heartbeat request data, mutated in place.
 */
function onHeartbeatSend( data: Record< string, unknown > ): void {
	const settings = getSettings();
	if ( ! settings ) {
		return;
	}

	data[ HEARTBEAT_DATA_KEY ] = {
		room: settings.room,
		token: settings.token,
		...( null !== syncClientId ? { client_id: syncClientId } : {} ),
	};
}

/**
 * Reads the server's presence answer from a heartbeat response.
 *
 * @param data The heartbeat response data.
 */
function onHeartbeatTick( data: Record< string, unknown > ): void {
	const answer = data?.[ HEARTBEAT_DATA_KEY ] as
		| { others?: unknown }
		| undefined;
	if ( ! answer || 'boolean' !== typeof answer.others ) {
		return;
	}

	initializeBelief();
	const hadOthers = othersBelieved;
	othersBelieved = answer.others;

	if ( ! hadOthers && othersBelieved ) {
		for ( const callback of arrivalCallbacks ) {
			callback();
		}
	}
}

function install(): void {
	if ( installed ) {
		return;
	}
	installed = true;

	addAction( 'heartbeat.send', HOOK_NAMESPACE, onHeartbeatSend );
	addAction( 'heartbeat.tick', HOOK_NAMESPACE, onHeartbeatTick );
}

/**
 * Whether the quiet-while-alone machinery can run on this page: the server
 * injected a room and per-tab token, and the heartbeat API is present to
 * carry the probe. When false, transports keep their always-on behavior.
 */
export function isSoloPresenceAvailable(): boolean {
	return null !== getSettings() && null !== getHeartbeat();
}

/**
 * The current belief about company: true when the page-load flag or the most
 * recent heartbeat answer said another tab or live session is in this post's
 * room. While true, transports must not go quiet.
 */
export function othersLikely(): boolean {
	initializeBelief();
	return othersBelieved;
}

/**
 * Records the primary room session's client id so the server can tell this
 * tab's own sync awareness entry apart from a peer's.
 *
 * @param clientId The engine session's client id.
 */
export function setSyncClientId( clientId: number ): void {
	syncClientId = clientId;
}

/**
 * Registers a callback fired when the belief flips from alone to accompanied
 * (a heartbeat answer reported company). Installs the heartbeat handlers on
 * first use.
 *
 * @param callback Called when another participant is first noticed.
 */
export function onOthersArrived( callback: () => void ): void {
	arrivalCallbacks.push( callback );
	install();
}

/**
 * Resets the module state. Test use only.
 */
export function resetSoloPresenceForTesting(): void {
	othersBelieved = false;
	believesInitialized = false;
	syncClientId = null;
	arrivalCallbacks.length = 0;
	// The heartbeat handlers stay attached (addAction dedupes by namespace);
	// they are inert without settings.
}
