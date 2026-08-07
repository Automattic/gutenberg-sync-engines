/**
 * Internal dependencies
 */
import { createIntentLogManager } from './intent-log-manager';
import {
	INTENT_LOG_ENGINE_SLUG,
	INTENT_LOG_ENGINE_PROTOCOL,
} from './intent-log-session';

/**
 * The intent-log engine adapter: a server-authoritative log of typed
 * intents. Its manager owns the capture bridge and session codec.
 *
 * @return {Object} A SyncEngineAdapter for `registerSyncEngine`.
 */
export function createIntentLogEngineAdapter() {
	return {
		slug: INTENT_LOG_ENGINE_SLUG,
		protocolVersion: INTENT_LOG_ENGINE_PROTOCOL,
		createManager: createIntentLogManager,
	};
}
