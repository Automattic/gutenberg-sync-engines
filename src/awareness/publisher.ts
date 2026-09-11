/**
 * The publisher: samples which block the local selection is in and hands
 * it to the channel, once per interval (the sync channel) or whenever the
 * channel asks (the Heartbeat channel flushes right before each beat).
 */

/**
 * Internal dependencies
 */
import { focusedBlockId } from './block-id';
import type { BlockTreeReader } from './block-id';

export interface PublisherOptions {
	reader: BlockTreeReader;
	intervalMs: number;
	/**
	 * `timer`: publish on start and then every interval, only when the
	 * block changed. `manual`: publish on every `flush()` (the caller
	 * owns the cadence and wants the current value each time).
	 */
	schedule: 'timer' | 'manual';
	onPublish: ( block: string | null ) => void;
}

export interface Publisher {
	start: () => void;
	stop: () => void;
	/** Samples the selection now and publishes it; returns the block. */
	flush: () => string | null;
}

/**
 * Creates the publisher.
 *
 * @param options Publisher options.
 * @return The publisher.
 */
export function createPresencePublisher(
	options: PublisherOptions
): Publisher {
	const { reader, intervalMs, schedule, onPublish } = options;
	let timer: ReturnType< typeof setInterval > | null = null;
	let published = false;
	let lastBlock: string | null = null;

	function flush(): string | null {
		const block = focusedBlockId( reader );
		if ( 'timer' === schedule && published && block === lastBlock ) {
			return block;
		}
		published = true;
		lastBlock = block;
		onPublish( block );
		return block;
	}

	return {
		start() {
			if ( 'timer' !== schedule ) {
				return;
			}
			flush();
			timer = setInterval( flush, intervalMs );
		},
		stop() {
			if ( timer ) {
				clearInterval( timer );
				timer = null;
			}
			published = false;
			lastBlock = null;
		},
		flush,
	};
}
