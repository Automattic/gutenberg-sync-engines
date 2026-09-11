/**
 * The WordPress Heartbeat channel: the block name travels on the admin
 * Heartbeat request, fully separate from the sync transport.
 *
 * This is the "awareness and content on different channels" shape. The
 * content still moves at the sync transport's pace (set the site's polling
 * interval high to see the gap); awareness moves at Heartbeat's pace. A
 * peer can therefore name a block whose content has not arrived here yet;
 * the receiver shows nothing for it until it does.
 *
 * Heartbeat's own rules apply: the interval is 1-3600 s, but 5 s is a
 * temporary "fast" mode that reverts after 30 ticks, so it is re-armed on
 * every tick; the server may enforce a minimum; Heartbeat slows down when
 * the window loses focus. The advisory channel's discovery probe rides the
 * same beat, so its cadence follows the awareness interval too.
 */

/**
 * WordPress dependencies
 */
import { addAction, removeAction } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import type { Channel, PeerListener } from '../types';

/** The Heartbeat data key, on both the request and the response. */
export const HEARTBEAT_KEY = 'gutenberg_sync_engines_awareness';

const HOOK_NAMESPACE = 'gutenberg-sync-engines/awareness';

interface HeartbeatPeer {
	client_id: number;
	user?: { id?: number | null; name?: string; avatar?: string };
	block?: string | null;
}

interface HeartbeatResponse {
	peers?: HeartbeatPeer[];
}

interface HeartbeatApi {
	interval: ( speed: number | string, ticks?: number ) => number;
	connectNow: () => void;
}

export interface HeartbeatChannelOptions {
	postId: number;
	clientId: number;
	intervalMs: number;
	/** Called right before each send so the publisher can flush. */
	beforeSend: () => void;
	onPeer: PeerListener;
	onPeerGone: ( key: string ) => void;
}

function getHeartbeat(): HeartbeatApi | null {
	const heartbeat = ( window as { wp?: { heartbeat?: HeartbeatApi } } ).wp
		?.heartbeat;
	return 'function' === typeof heartbeat?.interval ? heartbeat : null;
}

/**
 * Whether Heartbeat is available on this page.
 *
 * @return True when `wp.heartbeat` exists.
 */
export function isHeartbeatAvailable(): boolean {
	return null !== getHeartbeat();
}

/**
 * Creates the Heartbeat channel.
 *
 * @param options Channel options.
 * @return The channel.
 */
export function createHeartbeatChannel(
	options: HeartbeatChannelOptions
): Channel {
	const { postId, clientId, intervalMs, beforeSend, onPeer, onPeerGone } =
		options;
	const seconds = Math.max( 1, Math.round( intervalMs / 1000 ) );
	const known = new Set< string >();
	let latest: string | null = null;
	let started = false;

	function arm(): void {
		getHeartbeat()?.interval( seconds );
	}

	function onSend( data: Record< string, unknown > ): void {
		beforeSend();
		data[ HEARTBEAT_KEY ] = {
			post_id: postId,
			client_id: clientId,
			block: latest,
		};
	}

	function onTick( data: Record< string, unknown > ): void {
		// Five seconds is Heartbeat's temporary fast mode; keep it armed.
		if ( 5 === seconds ) {
			arm();
		}
		const payload = data?.[ HEARTBEAT_KEY ] as
			| HeartbeatResponse
			| undefined;
		if ( ! payload ) {
			return;
		}
		const present = new Set< string >();
		for ( const peer of payload.peers ?? [] ) {
			const key = String( peer.client_id );
			present.add( key );
			known.add( key );
			onPeer(
				key,
				{
					userId: peer.user?.id ?? null,
					name: peer.user?.name ?? '',
					avatarUrl: peer.user?.avatar,
				},
				peer.block ?? null
			);
		}
		for ( const key of Array.from( known ) ) {
			if ( ! present.has( key ) ) {
				known.delete( key );
				onPeerGone( key );
			}
		}
	}

	return {
		start() {
			if ( started || ! isHeartbeatAvailable() ) {
				return;
			}
			started = true;
			addAction( 'heartbeat.send', HOOK_NAMESPACE, onSend );
			addAction( 'heartbeat.tick', HOOK_NAMESPACE, onTick );
			arm();
			// Announce the join without waiting a full interval.
			getHeartbeat()?.connectNow();
		},
		stop() {
			if ( ! started ) {
				return;
			}
			started = false;
			removeAction( 'heartbeat.send', HOOK_NAMESPACE );
			removeAction( 'heartbeat.tick', HOOK_NAMESPACE );
			known.clear();
			latest = null;
		},
		publish( block ) {
			latest = block;
		},
	};
}
