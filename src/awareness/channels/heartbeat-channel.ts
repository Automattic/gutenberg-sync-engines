/**
 * The WordPress Heartbeat channel: the block name rides the advisory
 * channel's discovery probe, which travels on the admin Heartbeat request
 * (and on sync polls), fully separate from the sync transport's awareness
 * state. The server keeps it on the tab's presence token and answers with
 * every other tab's block, name, and avatar.
 *
 * This is the "awareness and content on different channels" shape. The
 * content still moves at the sync transport's pace (set the site's polling
 * interval high to see the gap); awareness moves at Heartbeat's pace. A
 * peer can therefore name a block whose content has not arrived here yet;
 * the receiver shows nothing for it until it does.
 *
 * Heartbeat's own rules apply: the interval is 1-3600 s, but 5 s is a
 * temporary "fast" mode that reverts after 30 ticks, so it is re-armed on
 * every answer; the server may enforce a minimum; Heartbeat slows down when
 * the window loses focus. The discovery probe rides the same beat, so its
 * cadence follows the awareness interval too.
 */

/**
 * Internal dependencies
 */
import {
	isSignalingAvailable,
	onAnswer,
	setProbeFields,
} from '../../providers/advisory/signaling';
import type { DiscoveredPeer } from '../../providers/advisory/signaling';
import type { Channel, PeerListener } from '../types';

interface HeartbeatApi {
	interval: ( speed: number | string, ticks?: number ) => number;
	connectNow: () => void;
}

export interface HeartbeatChannelOptions {
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
 * Whether this channel can run on this page: `wp.heartbeat` exists and
 * the advisory channel's probe rides it (the site has an advisory channel
 * selected and this is a per-post editor screen).
 *
 * @return True when available.
 */
export function isHeartbeatAvailable(): boolean {
	return null !== getHeartbeat() && isSignalingAvailable();
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
	const { intervalMs, beforeSend, onPeer, onPeerGone } = options;
	const seconds = Math.max( 1, Math.round( intervalMs / 1000 ) );
	const known = new Set< string >();
	let latest: string | null = null;
	let unsubscribe: ( () => void ) | null = null;

	function arm(): void {
		getHeartbeat()?.interval( seconds );
	}

	function onPeers( peers: DiscoveredPeer[] ): void {
		// Five seconds is Heartbeat's temporary fast mode; keep it armed.
		if ( 5 === seconds ) {
			arm();
		}
		const present = new Set< string >();
		for ( const peer of peers ) {
			// A tab whose sync session has not started yet has no client
			// id, and a peer without a block was answered to a probe
			// without one (not this channel's).
			if ( ! peer.clientId || ! ( 'block' in peer ) ) {
				continue;
			}
			const key = String( peer.clientId );
			present.add( key );
			known.add( key );
			onPeer(
				key,
				{
					userId: peer.userId || null,
					name: peer.name ?? '',
					avatarUrl: peer.avatar || undefined,
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
			if ( unsubscribe || ! isHeartbeatAvailable() ) {
				return;
			}
			setProbeFields( () => {
				beforeSend();
				return { block: latest };
			} );
			unsubscribe = onAnswer( onPeers );
			arm();
			// Announce the join without waiting a full interval.
			getHeartbeat()?.connectNow();
		},
		stop() {
			if ( ! unsubscribe ) {
				return;
			}
			setProbeFields( null );
			unsubscribe();
			unsubscribe = null;
			known.clear();
			latest = null;
		},
		publish( block ) {
			latest = block;
		},
	};
}
