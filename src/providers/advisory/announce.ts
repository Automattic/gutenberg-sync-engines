/**
 * The "I just wrote rows" notice, decoupled from the channel that carries
 * it. Anything that lands rows on the server outside the polling manager
 * (de-rtc's commits ride the autosave endpoint) calls `announceLocalWrite`;
 * the advisory channel subscribes and tells the peers to poll.
 *
 * A notice is a rumor: it carries a room name (or `*` for "some room") and
 * nothing else. Receivers poll and find out.
 */

export const ANY_ROOM = '*';

const listeners: Array< ( room: string ) => void > = [];

/**
 * Announces that this tab landed rows on the server.
 *
 * @param room The room written to, or ANY_ROOM when the writer does not
 *             know its room name.
 */
export function announceLocalWrite( room: string = ANY_ROOM ): void {
	for ( const callback of listeners ) {
		callback( room );
	}
}

/**
 * Subscribes to local write notices.
 *
 * @param callback Called with the room name (or ANY_ROOM).
 */
export function onLocalWrite( callback: ( room: string ) => void ): void {
	listeners.push( callback );
}

/**
 * Resets the module state. Test use only.
 */
export function resetAnnounceForTesting(): void {
	listeners.length = 0;
}
