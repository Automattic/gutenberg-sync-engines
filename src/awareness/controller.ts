/**
 * The slow-awareness controller: for the post being edited, run the
 * publisher on the chosen channel and feed peers' blocks into the store.
 */

/**
 * WordPress dependencies
 */
import { dispatch, select, subscribe } from '@wordpress/data';

/**
 * Internal dependencies
 */
import type { BlockTreeReader } from './block-id';
import {
	createHeartbeatChannel,
	isHeartbeatAvailable,
} from './channels/heartbeat-channel';
import {
	createSyncChannel,
	suppressRealtimeSelection,
} from './channels/sync-channel';
import type { AwarenessHost } from './channels/sync-channel';
import { getPeerColor } from './colors';
import { createPresencePublisher } from './publisher';
import { getRegisteredAwareness, onAwarenessRegistered } from './registry';
import { registerAwarenessStore, store } from './store';
import type { Channel, PeerIdentity, SlowAwarenessSettings } from './types';

interface EditorStoreSelectors {
	getCurrentPostId: () => number | null | undefined;
	getCurrentPostType: () => string | null | undefined;
}

/**
 * A registered store's selectors, narrowed to the slice this module reads
 * (`select` types a store named by string as an open record).
 *
 * @param name Store name.
 * @return The selectors, or undefined before the store is registered.
 */
function selectStore< Selectors >( name: string ): Selectors | undefined {
	return select( name ) as Selectors | undefined;
}

/**
 * Starts slow awareness for the editor on this page. Waits for the editor
 * to know its post and for the engine to have created the post's awareness
 * instance, then runs until stopped.
 *
 * @param settings The mode settings (interval and channel).
 * @return A stop function.
 */
export function startSlowAwareness(
	settings: SlowAwarenessSettings
): () => void {
	registerAwarenessStore();

	let stopped = false;
	let stopSession: ( () => void ) | null = null;

	function tryStart(): void {
		if ( stopped || stopSession ) {
			return;
		}
		const editor = selectStore< EditorStoreSelectors >( 'core/editor' );
		const reader = selectStore< BlockTreeReader >( 'core/block-editor' );
		if ( ! editor || ! reader ) {
			return;
		}
		const postId = editor.getCurrentPostId();
		const postType = editor.getCurrentPostType();
		if ( ! postId || ! postType ) {
			return;
		}
		const awareness = getRegisteredAwareness(
			`postType/${ postType }`,
			String( postId )
		);
		if ( ! awareness ) {
			return;
		}
		stopSession = startSession( settings, postId, awareness, reader );
	}

	const unsubscribeEditor = subscribe( tryStart, 'core/editor' );
	const unsubscribeRegistry = onAwarenessRegistered( tryStart );
	tryStart();

	return () => {
		stopped = true;
		unsubscribeEditor();
		unsubscribeRegistry();
		stopSession?.();
		stopSession = null;
	};
}

function startSession(
	settings: SlowAwarenessSettings,
	postId: number,
	awareness: AwarenessHost,
	reader: BlockTreeReader
): () => void {
	const { setPeer, removePeer, reset } = dispatch( store );

	function onPeer(
		key: string,
		identity: PeerIdentity,
		block: string | null
	): void {
		setPeer(
			key,
			identity,
			getPeerColor( identity.userId, Number( key ) ),
			block
		);
	}

	function onPeerGone( key: string ): void {
		removePeer( key );
	}

	// The channel, then the publisher wired to it.
	let channel: Channel;
	let restoreSelection: ( () => void ) | null = null;
	const useHeartbeat =
		'heartbeat' === settings.channel && isHeartbeatAvailable();
	const publisher = createPresencePublisher( {
		reader,
		intervalMs: settings.intervalMs,
		schedule: useHeartbeat ? 'manual' : 'timer',
		onPublish: ( block ) => channel.publish( block ),
	} );
	if ( useHeartbeat ) {
		// Presence (who is here) still rides the sync transport; only the
		// block name moves over Heartbeat. Suppress the live cursor on the
		// sync side so peers see the block outline only.
		restoreSelection = suppressRealtimeSelection( awareness );
		channel = createHeartbeatChannel( {
			postId,
			clientId: awareness.clientID,
			intervalMs: settings.intervalMs,
			beforeSend: () => publisher.flush(),
			onPeer,
			onPeerGone,
		} );
	} else {
		channel = createSyncChannel( { awareness, onPeer, onPeerGone } );
	}

	channel.start();
	publisher.start();

	return () => {
		publisher.stop();
		channel.stop();
		restoreSelection?.();
		reset();
	};
}
