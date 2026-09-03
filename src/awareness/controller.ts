/**
 * The slow-awareness controller: for the post being edited, run the
 * publisher on the chosen channel, feed peers' beacons into the store, and
 * keep the phantom list resolved against the local block tree.
 */

/**
 * WordPress dependencies
 */
import { dispatch, select, subscribe } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { buildIdentityIndex, resolveBlockRef } from './block-refs';
import type { BlockTreeReader } from './block-refs';
import {
	createHeartbeatChannel,
	isHeartbeatAvailable,
} from './channels/heartbeat-channel';
import {
	createSyncChannel,
	suppressRealtimeSelection,
} from './channels/sync-channel';
import type { AwarenessHost, Channel } from './channels/sync-channel';
import { getPeerColor } from './colors';
import { createActivityPublisher } from './publisher';
import { getRegisteredAwareness, onAwarenessRegistered } from './registry';
import { trailOpacity } from './staleness';
import { LEAVE_MS, registerAwarenessStore, store, trailOf } from './store';
import type { PhantomInput, PresenceRole } from './store';
import type {
	ActivityBeacon,
	PeerIdentity,
	SlowAwarenessSettings,
} from './types';

const TICK_MS = 1000;

interface EditorStoreSelectors {
	getCurrentPostId: () => number | null | undefined;
	getCurrentPostType: () => string | null | undefined;
}

/**
 * The block-editor store, narrowed to the reader interface.
 *
 * @return The reader.
 */
function blockTreeReader(): BlockTreeReader {
	return select( 'core/block-editor' ) as unknown as BlockTreeReader;
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
	dispatch( store ).setSettings( settings );

	let stopped = false;
	let stopSession: ( () => void ) | null = null;

	const editor = select( 'core/editor' ) as unknown as EditorStoreSelectors;

	function tryStart(): void {
		if ( stopped || stopSession ) {
			return;
		}
		const postId = editor.getCurrentPostId();
		const postType = editor.getCurrentPostType();
		if ( ! postId || ! postType ) {
			return;
		}
		const objectType = `postType/${ postType }`;
		const awareness = getRegisteredAwareness(
			objectType,
			String( postId )
		);
		if ( ! awareness ) {
			return;
		}
		stopSession = startSession( settings, postId, awareness );
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
	awareness: AwarenessHost
): () => void {
	const reader = blockTreeReader();
	const {
		receiveBeacon,
		removePeer,
		tick,
		pruneLeaving,
		setPhantoms,
		reset,
	} = dispatch( store );

	// Entries a beacon dropped linger at zero strength for their exit
	// animation; prune them once it has had time to play.
	let pruneTimer: ReturnType< typeof setTimeout > | null = null;
	function schedulePrune(): void {
		if ( pruneTimer ) {
			clearTimeout( pruneTimer );
		}
		pruneTimer = setTimeout( () => {
			pruneTimer = null;
			pruneLeaving();
		}, LEAVE_MS );
	}

	function onPeerBeacon(
		key: string,
		identity: PeerIdentity,
		beacon: ActivityBeacon
	): void {
		receiveBeacon(
			key,
			identity,
			getPeerColor( identity.userId, Number( key ) ),
			beacon,
			Date.now()
		);
		resolvePhantoms();
		schedulePrune();
	}

	function onPeerGone( key: string ): void {
		removePeer( key );
		resolvePhantoms();
		schedulePrune();
	}

	/*
	 * Phantoms: every peer reference with no local match. Re-resolved when
	 * a beacon arrives and whenever the tree's membership changes (the
	 * content the reference points at may have just landed).
	 */
	function resolvePhantoms(): void {
		const index = buildIdentityIndex( reader );
		const phantoms: PhantomInput[] = [];
		for ( const peer of select( store ).getPeers() ) {
			const { focus, edits } = peer.beacon;
			const seen = new Set< string >();
			for ( const { ref, ageMs } of trailOf( peer.beacon ) ) {
				const identity = ref.syncId ?? ref.clientId;
				const opacity = trailOpacity( ageMs );
				if ( seen.has( identity ) || 0 === opacity ) {
					continue;
				}
				seen.add( identity );
				const resolution = resolveBlockRef( ref, index );
				if ( 'phantom' !== resolution.kind ) {
					continue;
				}
				const edit = edits.find(
					( e ) =>
						'remove' !== e.kind && e.ref.clientId === ref.clientId
				);
				let role: PresenceRole = 'recent';
				if ( focus && focus.clientId === ref.clientId ) {
					role = 'focus';
				} else if ( edit ) {
					role = edit.kind;
				}
				phantoms.push( {
					peerKey: peer.key,
					role,
					ref,
					opacity,
					ageMs,
					anchorClientId: resolution.anchorClientId,
					placement: resolution.placement,
				} );
			}
		}
		setPhantoms( phantoms );
	}

	let lastIds = reader.getClientIdsWithDescendants();
	const unsubscribeTree = subscribe( () => {
		const ids = reader.getClientIdsWithDescendants();
		if ( ids !== lastIds ) {
			lastIds = ids;
			if ( select( store ).getPeers().length ) {
				resolvePhantoms();
			}
		}
	}, 'core/block-editor' );

	// The channel, then the publisher wired to it.
	let channel: Channel;
	let restoreSelection: ( () => void ) | null = null;
	const useHeartbeat =
		'heartbeat' === settings.channel && isHeartbeatAvailable();
	const publisher = createActivityPublisher( {
		reader,
		subscribe: ( listener ) => subscribe( listener, 'core/block-editor' ),
		intervalMs: settings.intervalMs,
		schedule: useHeartbeat ? 'manual' : 'timer',
		onBeacon: ( beacon ) => channel.publish( beacon ),
	} );
	if ( useHeartbeat ) {
		// Presence (who is here) still rides the sync transport; only the
		// activity beacon moves over Heartbeat. Suppress the realtime caret
		// on the sync side so peers see block-level activity only.
		restoreSelection = suppressRealtimeSelection( awareness );
		channel = createHeartbeatChannel( {
			postId,
			clientId: awareness.clientID,
			intervalMs: settings.intervalMs,
			beforeSend: () => publisher.flush(),
			onPeerBeacon,
			onPeerGone,
		} );
	} else {
		channel = createSyncChannel( { awareness, onPeerBeacon, onPeerGone } );
	}

	channel.start();
	publisher.start();
	const ticker = setInterval( () => tick( Date.now() ), TICK_MS );
	tick( Date.now() );

	return () => {
		clearInterval( ticker );
		if ( pruneTimer ) {
			clearTimeout( pruneTimer );
		}
		unsubscribeTree();
		publisher.stop();
		channel.stop();
		restoreSelection?.();
		reset();
	};
}
