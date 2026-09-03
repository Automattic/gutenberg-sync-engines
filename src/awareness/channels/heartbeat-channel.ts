/**
 * The WordPress Heartbeat channel: the beacon travels on the admin
 * Heartbeat request, fully separate from the sync transport.
 *
 * This is the "awareness and document on different transports" shape. The
 * document still moves at the sync transport's pace (set the site's polling
 * interval high to see the gap); awareness moves at Heartbeat's pace. A
 * peer can therefore announce activity in a block whose content has not
 * arrived here yet, which the receiver renders as a phantom.
 *
 * Heartbeat's own rules apply: the interval is 1-3600 s, but 5 s is a
 * temporary "fast" mode that reverts after 30 ticks, so it is re-armed on
 * every tick; the server may enforce a minimum; Heartbeat slows or
 * suspends when the window loses focus for long.
 */

/**
 * Internal dependencies
 */
import type { ActivityBeacon, PeerIdentity } from '../types';
import type { Channel } from './sync-channel';

/** The Heartbeat data key, on both the request and the response. */
export const HEARTBEAT_KEY = 'gutenberg_sync_engines_awareness';

interface HeartbeatPeer {
	client_id: number;
	user: { id: number | null; name: string; avatar?: string };
	beacon: ActivityBeacon;
}

interface HeartbeatResponse {
	peers?: HeartbeatPeer[];
}

interface HeartbeatApi {
	interval: ( speed: number | string, ticks?: number ) => number;
	connectNow: () => void;
}

interface JQueryLike {
	( target: Document ): {
		on: (
			event: string,
			handler: ( event: unknown, data: Record< string, unknown > ) => void
		) => void;
		off: ( event: string ) => void;
	};
}

declare global {
	interface Window {
		jQuery?: JQueryLike;
		wp?: { heartbeat?: HeartbeatApi } & Record< string, unknown >;
	}
}

export interface HeartbeatChannelOptions {
	postId: number;
	clientId: number;
	intervalMs: number;
	/** Called right before each send so the publisher can flush. */
	beforeSend: () => void;
	onPeerBeacon: (
		key: string,
		identity: PeerIdentity,
		beacon: ActivityBeacon
	) => void;
	onPeerGone: ( key: string ) => void;
}

/**
 * Whether Heartbeat is available on this page.
 *
 * @return True when both jQuery and wp.heartbeat exist.
 */
export function isHeartbeatAvailable(): boolean {
	return Boolean( window.jQuery && window.wp?.heartbeat );
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
	const {
		postId,
		clientId,
		intervalMs,
		beforeSend,
		onPeerBeacon,
		onPeerGone,
	} = options;
	const seconds = Math.max( 1, Math.round( intervalMs / 1000 ) );
	const seen = new Map< string, number >();
	let latest: ActivityBeacon | null = null;
	let started = false;

	function arm(): void {
		window.wp?.heartbeat?.interval( seconds );
	}

	function onSend( _event: unknown, data: Record< string, unknown > ): void {
		beforeSend();
		data[ HEARTBEAT_KEY ] = {
			post_id: postId,
			client_id: clientId,
			beacon: latest,
		};
	}

	function onTick( _event: unknown, data: Record< string, unknown > ): void {
		// Five seconds is Heartbeat's temporary fast mode; keep it armed.
		if ( 5 === seconds ) {
			arm();
		}
		const payload = data[ HEARTBEAT_KEY ] as HeartbeatResponse | undefined;
		if ( ! payload ) {
			return;
		}
		const present = new Set< string >();
		for ( const peer of payload.peers ?? [] ) {
			const key = String( peer.client_id );
			present.add( key );
			if ( ! peer.beacon || seen.get( key ) === peer.beacon.seq ) {
				continue;
			}
			seen.set( key, peer.beacon.seq );
			onPeerBeacon(
				key,
				{
					userId: peer.user?.id ?? null,
					name: peer.user?.name ?? '',
					avatarUrl: peer.user?.avatar,
				},
				peer.beacon
			);
		}
		for ( const key of Array.from( seen.keys() ) ) {
			if ( ! present.has( key ) ) {
				seen.delete( key );
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
			const $ = window.jQuery as JQueryLike;
			$( document ).on( 'heartbeat-send.gseAwareness', onSend );
			$( document ).on( 'heartbeat-tick.gseAwareness', onTick );
			arm();
			// Announce the join without waiting a full interval.
			window.wp?.heartbeat?.connectNow();
		},
		stop() {
			if ( ! started ) {
				return;
			}
			started = false;
			const $ = window.jQuery as JQueryLike;
			$( document ).off( 'heartbeat-send.gseAwareness' );
			$( document ).off( 'heartbeat-tick.gseAwareness' );
			seen.clear();
			latest = null;
		},
		publish( beacon ) {
			latest = beacon;
		},
	};
}
