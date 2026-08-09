/**
 * Internal dependencies
 */
import { createSyncManager } from '../framework';
import {
	YJS_RELAY_ENGINE_SLUG,
	YJS_RELAY_ENGINE_PROTOCOL,
	createYjsEngine,
} from './yjs-relay';

/**
 * The yjs-relay engine adapter (the incumbent): a naive relay of opaque Yjs
 * CRDT updates. It composes the framework's engine-neutral sync manager
 * (`createSyncManager`) with this plugin's own Yjs engine (`createYjsEngine`).
 *
 * @return {Object} A SyncEngineAdapter for `registerSyncEngine`.
 */
export function createYjsRelayEngineAdapter() {
	return {
		slug: YJS_RELAY_ENGINE_SLUG,
		protocolVersion: YJS_RELAY_ENGINE_PROTOCOL,
		createManager: ( debug?: boolean ) =>
			createSyncManager( createYjsEngine(), { debug } ),
	};
}
