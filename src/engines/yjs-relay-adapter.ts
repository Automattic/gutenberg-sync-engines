/**
 * Internal dependencies
 */
import { createSyncManager } from '../framework';
import {
	YJS_RELAY_ENGINE_SLUG,
	YJS_RELAY_ENGINE_PROTOCOL,
	createYjsSessionCodec,
	type YjsSessionOptions,
} from './yjs-relay/session';

/**
 * The yjs-relay engine adapter (the incumbent): a naive relay of opaque Yjs
 * CRDT updates. It reuses the framework's generic sync manager
 * (`createSyncManager`) with this plugin's Yjs session codec.
 *
 * @return {Object} A SyncEngineAdapter for `registerSyncEngine`.
 */
export function createYjsRelayEngineAdapter() {
	return {
		slug: YJS_RELAY_ENGINE_SLUG,
		protocolVersion: YJS_RELAY_ENGINE_PROTOCOL,
		createManager: createSyncManager,
		createSessionCodec: ( options?: unknown ) =>
			createYjsSessionCodec( options as YjsSessionOptions ),
	};
}
