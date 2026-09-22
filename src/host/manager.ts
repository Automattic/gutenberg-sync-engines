/**
 * The active sync manager of this page: the one the host bridge created
 * from the engine the server announced. Awareness hooks and the review
 * store read it from here; nothing else should hold on to it.
 */

/**
 * Internal dependencies
 */
import type { SyncManager } from '../sync';

let activeManager: SyncManager | undefined;

/**
 * Records the manager the host bridge created (or clears it).
 *
 * @param manager The manager, or undefined when the bridge stands down.
 */
export function setActiveSyncManager( manager: SyncManager | undefined ): void {
	activeManager = manager;
}

/**
 * The active sync manager, if the host bridge created one.
 *
 * @return The manager, or undefined.
 */
export function getActiveSyncManager(): SyncManager | undefined {
	return activeManager;
}
