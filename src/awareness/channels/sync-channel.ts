/**
 * The sync-transport channel: the block name rides the framework's own
 * awareness state, as one more field beside `collaboratorInfo`.
 *
 * Nothing between `setLocalStateField` and the peers' `onStateChange`
 * inspects awareness content (the server stores it opaquely), so this
 * needs no engine, transport, or PHP change and works over http-polling,
 * long-polling, and websocket alike. The field goes out with the next
 * request that carries awareness: the next poll (under short polling with
 * an advisory channel covering every peer, that is the next poll a content
 * change causes), the next long-poll reissue, or the websocket's periodic
 * awareness frame. The Heartbeat channel is the one with its own cadence.
 *
 * While this channel is active the framework's live-cursor field
 * (`editorState`) is suppressed on the local state, so peers see the block
 * outline only, never a cursor that jumps every few seconds.
 */

/**
 * Internal dependencies
 */
import { announceLocalAwarenessChange } from '../../providers/advisory/announce';
import type { Channel, PeerIdentity, PeerListener } from '../types';

/**
 * The awareness-instance surface this channel relies on. Structurally
 * matches core-data's `PostEditorAwareness` (a typed y-protocols Awareness
 * with subscription helpers).
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
	gseBlock?: string | null;
}

/** The awareness field the block name travels in. */
export const BLOCK_FIELD = 'gseBlock';

export interface SyncChannelOptions {
	awareness: AwarenessHost;
	onPeer: PeerListener;
	onPeerGone: ( key: string ) => void;
}

/**
 * Whether two block-field values are the same (absent and null alike).
 *
 * @param a A value.
 * @param b Another value.
 * @return True when equal.
 */
export function areBlocksEqual( a?: unknown, b?: unknown ): boolean {
	return ( a ?? null ) === ( b ?? null );
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
 * Stops the framework's live-cursor field (`editorState`) from being
 * published on the local awareness state, and clears the one already
 * published. Peers then see the block outline only.
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
	const { awareness, onPeer, onPeerGone } = options;
	let unsubscribe: ( () => void ) | null = null;
	let restoreSetter: ( () => void ) | null = null;
	const known = new Set< string >();

	return {
		start() {
			if ( awareness.equalityFieldChecks ) {
				awareness.equalityFieldChecks[ BLOCK_FIELD ] = areBlocksEqual;
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
						known.add( key );
						onPeer(
							key,
							identityFromState( state ),
							state.gseBlock ?? null
						);
					}
					for ( const key of Array.from( known ) ) {
						if ( ! present.has( key ) ) {
							known.delete( key );
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
			awareness.setLocalStateField( BLOCK_FIELD, undefined );
			known.clear();
		},
		publish( block ) {
			awareness.setLocalStateField( BLOCK_FIELD, block );
			// The transport may have no request due for a while (short
			// polling with every peer on the advisory channel): ask it to
			// carry the new block now rather than with the next content.
			announceLocalAwarenessChange();
		},
	};
}
