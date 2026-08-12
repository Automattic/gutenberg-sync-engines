/**
 * Internal dependencies
 */
import { createSyncManager } from '../framework';
import {
	createDeRtcEngine,
	DE_RTC_ENGINE_PROTOCOL,
	DE_RTC_ENGINE_SLUG,
} from './de-rtc';

/**
 * The de-rtc engine adapter: Distributed Editing's save-centric model on
 * the room protocol. The client proposes whole content against the
 * version it last incorporated; the SERVER three-way-merges every
 * proposal with the ported DE-RTC merge core and broadcasts canonical
 * content rows. It composes the framework's engine-neutral sync manager
 * with this plugin's proposal-based engine.
 *
 * @return {Object} A SyncEngineAdapter for `registerSyncEngine`.
 */
export function createDeRtcEngineAdapter() {
	return {
		slug: DE_RTC_ENGINE_SLUG,
		protocolVersion: DE_RTC_ENGINE_PROTOCOL,
		createManager: ( debug?: boolean ) =>
			createSyncManager( createDeRtcEngine(), { debug } ),
	};
}
