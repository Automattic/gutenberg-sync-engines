/**
 * The sync core: the engine-neutral manager, the engine and transport
 * registries with client/server negotiation, and the shared Yjs export.
 *
 * This used to be Gutenberg's `@wordpress/sync` package. It now lives in
 * this plugin, which bundles Yjs itself. Third-party engine and transport
 * plugins must reuse THIS Yjs instance (see https://github.com/yjs/yjs/issues/438);
 * it is exposed on `window.gutenbergSyncEngines.Y` together with the two
 * registration functions.
 */

/**
 * External dependencies
 */
export * as Y from 'yjs';
export { Awareness } from 'y-protocols/awareness';

/**
 * The major version of Yjs bundled by this plugin. Third-party code can
 * check it before reusing the shared instance.
 */
export const YJS_VERSION = '13';

/**
 * Internal dependencies
 */
export { createSyncManager } from './manager';
export {
	getAnnouncedSync,
	getEngineAdapters,
	registerSyncEngine,
	resetEngineAdaptersForTesting,
	resolveEngineAdapter,
} from './engines';
export type { AnnouncedSync, SyncEngineAdapter } from './engines';
export {
	getProviderCreators,
	registerSyncTransport,
	resetProviderCreatorsForTesting,
} from './providers';
export type { TransportRegistration } from './providers';
export { ConnectionError, ConnectionErrorCode } from './errors';
export {
	CRDT_DOC_META_PERSISTENCE_KEY,
	CRDT_RECORD_MAP_KEY,
	LOCAL_EDITOR_ORIGIN,
	LOCAL_UNDO_IGNORED_ORIGIN,
} from './config';
export { default as Delta } from './quill-delta/Delta';

export type * from './types';

// The engine/transport SEAM types, so engine and transport plugins can type
// their adapters, session codecs, and providers against the core.
export type {
	LocalAwarenessState,
	AwarenessState,
	EngineUpdate,
	EngineDisposition,
	EngineLocalUpdateListener,
	EngineSessionCodec,
} from './engines/session';

// The engine SPI: an engine implements `SyncEngine` (a factory of
// per-entity/collection cores) and composes it with `createSyncManager`.
export type {
	SyncEngine,
	EngineEntity,
	EngineCollection,
	EngineEntityObservers,
} from './engines/engine';
