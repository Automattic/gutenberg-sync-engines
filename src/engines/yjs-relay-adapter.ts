/**
 * Internal dependencies
 */
import { createSyncManager, createYjsEngine } from '../framework';
import {
	YJS_RELAY_ENGINE_SLUG,
	YJS_RELAY_ENGINE_PROTOCOL,
} from './yjs-relay/session';

/**
 * The yjs-relay engine adapter (the incumbent): a naive relay of opaque Yjs
 * CRDT updates. It composes the framework's engine-neutral sync manager
 * (`createSyncManager`) with the framework's built-in Yjs engine. When the Yjs
 * stack moves into this plugin (PORTING.md §5), this will compose a local
 * `createYjsEngine` instead.
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
