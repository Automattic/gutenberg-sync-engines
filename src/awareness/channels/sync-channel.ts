/**
 * The sync-transport channel: the beacon rides the framework's own
 * awareness envelope, as one more field on the local awareness state.
 *
 * Nothing between `setLocalStateField` and the peers' `onStateChange`
 * inspects awareness content (the server stores it opaquely), so this
 * needs no engine, transport, or PHP change. It works over http-polling,
 * long-polling, and websocket alike. What it cannot do is separate the
 * awareness cadence from the document cadence: the field goes out with the
 * next poll after each beacon. The Heartbeat channel does that.
 *
 * While this channel is active the framework's realtime caret field
 * (`editorState`) is suppressed on the local state, so peers on the same
 * mode see block-level activity only, never a caret that jumps every few
 * seconds.
 */

/**
 * Internal dependencies
 */
import type { ActivityBeacon, PeerIdentity } from '../types';

/**
 * The awareness-instance surface this channel relies on. Structurally
 * matches core-data's `PostEditorAwareness` (a typed y-protocols
 * Awareness with subscription helpers).
 */
export interface AwarenessHost {
	clientID: number;
	setUp?: () => void;
	onStateChange?: (
		callback: ( states: AwarenessPeerState[] ) => void
	) => () => void;
	setLocalStateField: ( field: string, value: unknown ) => void;
	getLocalState: () => Record< string, unknown > | null;
	/** Per-field equality checks, on core-data's typed awareness. */
	equalityFieldChecks?: Record<
		string,
		( a?: unknown, b?: unknown ) => boolean
	>;
}

export interface AwarenessPeerState {
	clientId: number;
	isMe: boolean;
	isConnected: boolean;
	collaboratorInfo?: {
		id: number | null;
		name: string;
		avatar_urls?: Record< string, string >;
	};
	activity?: ActivityBeacon;
}

/** The awareness field the beacon travels in. */
export const ACTIVITY_FIELD = 'activity';

export interface Channel {
	start: () => void;
	stop: () => void;
	publish: ( beacon: ActivityBeacon ) => void;
}

export interface SyncChannelOptions {
	awareness: AwarenessHost;
	onPeerBeacon: (
		key: string,
		identity: PeerIdentity,
		beacon: ActivityBeacon
	) => void;
	onPeerGone: ( key: string ) => void;
}

/**
 * Whether two beacons are the same publication.
 *
 * @param a A beacon.
 * @param b Another beacon.
 * @return True when both are absent or share seq and timestamp.
 */
export function areBeaconsEqual( a?: unknown, b?: unknown ): boolean {
	const x = a as ActivityBeacon | undefined;
	const y = b as ActivityBeacon | undefined;
	if ( ! x || ! y ) {
		return x === y;
	}
	return x.seq === y.seq && x.at === y.at;
}

/**
 * Reads the identity a peer's collaborator info describes.
 *
 * @param state A peer's awareness state.
 * @return The identity.
 */
export function identityFromState( state: AwarenessPeerState ): PeerIdentity {
	const info = state.collaboratorInfo;
	const avatars = info?.avatar_urls ?? {};
	const avatarUrl = avatars[ '48' ] ?? avatars[ '96' ] ?? avatars[ '24' ];
	return {
		userId: info?.id ?? null,
		name: info?.name ?? '',
		avatarUrl,
	};
}

/**
 * Stops the framework's realtime caret field (`editorState`) from being
 * published on the local awareness state, and clears the one already
 * published. Peers then see block-level activity only.
 *
 * @param awareness The awareness instance.
 * @return A function restoring the original setter.
 */
export function suppressRealtimeSelection(
	awareness: AwarenessHost
): () => void {
	const original = awareness.setLocalStateField.bind( awareness );
	awareness.setLocalStateField = ( field, value ) => {
		if ( 'editorState' === field ) {
			return;
		}
		original( field, value );
	};
	if ( awareness.getLocalState()?.editorState ) {
		original( 'editorState', undefined );
	}
	return () => {
		awareness.setLocalStateField = original;
	};
}

/**
 * Creates the sync-transport channel.
 *
 * @param options Channel options.
 * @return The channel.
 */
export function createSyncChannel( options: SyncChannelOptions ): Channel {
	const { awareness, onPeerBeacon, onPeerGone } = options;
	let unsubscribe: ( () => void ) | null = null;
	let restoreSetter: ( () => void ) | null = null;
	const seen = new Map< string, number >();

	return {
		start() {
			if ( awareness.equalityFieldChecks ) {
				awareness.equalityFieldChecks[ ACTIVITY_FIELD ] =
					areBeaconsEqual;
			}
			awareness.setUp?.();
			restoreSetter = suppressRealtimeSelection( awareness );
			unsubscribe =
				awareness.onStateChange?.( ( states ) => {
					const present = new Set< string >();
					for ( const state of states ) {
						if ( state.isMe || ! state.isConnected ) {
							continue;
						}
						const key = String( state.clientId );
						present.add( key );
						const beacon = state.activity;
						if ( ! beacon ) {
							continue;
						}
						if ( seen.get( key ) === beacon.seq ) {
							continue;
						}
						seen.set( key, beacon.seq );
						onPeerBeacon( key, identityFromState( state ), beacon );
					}
					for ( const key of Array.from( seen.keys() ) ) {
						if ( ! present.has( key ) ) {
							seen.delete( key );
							onPeerGone( key );
						}
					}
				} ) ?? null;
		},
		stop() {
			unsubscribe?.();
			unsubscribe = null;
			restoreSetter?.();
			restoreSetter = null;
			awareness.setLocalStateField( ACTIVITY_FIELD, undefined );
			seen.clear();
		},
		publish( beacon ) {
			awareness.setLocalStateField( ACTIVITY_FIELD, beacon );
		},
	};
}
