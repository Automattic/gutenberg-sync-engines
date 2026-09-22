/**
 * The host store: the collaboration state the editor UI reads, which
 * used to live in core-data's reducer while Gutenberg shipped the
 * experiment. Connection status per synced entity, the review list of
 * parked conflicts per entity, and whether the page still supports
 * collaboration at all (it stops when a legacy meta box shows up or a
 * document outgrows the transport).
 */

/**
 * WordPress dependencies
 */
import {
	createReduxStore,
	register,
	createRegistrySelector,
} from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { getActiveSyncManager } from './manager';
import { getAnnouncedSync } from '../sync';
import type { ConnectionStatus, SyncReviewItem } from '../sync';

export const STORE_NAME = 'gutenberg-sync-engines/host';

export interface HostState {
	connectionStatuses: Record< string, ConnectionStatus >;
	reviewItems: Record< string, SyncReviewItem[] >;
	supported: boolean;
}

type RecordKey = string | number | null;

function entityKey( kind: string, name: string, key: RecordKey ): string {
	return `${ kind }/${ name }:${ key }`;
}

const EMPTY_REVIEW_ITEMS: SyncReviewItem[] = [];

const DEFAULT_STATE: HostState = {
	connectionStatuses: {},
	reviewItems: {},
	supported: true,
};

type Action =
	| {
			type: 'SET_SYNC_CONNECTION_STATUS';
			kind: string;
			name: string;
			key: RecordKey;
			status: ConnectionStatus;
	  }
	| {
			type: 'CLEAR_SYNC_CONNECTION_STATUS';
			kind: string;
			name: string;
			key: RecordKey;
	  }
	| {
			type: 'SET_SYNC_REVIEW_ITEMS';
			kind: string;
			name: string;
			key: RecordKey;
			items: SyncReviewItem[];
	  }
	| { type: 'SET_COLLABORATION_SUPPORTED'; supported: boolean };

function reducer(
	state: HostState = DEFAULT_STATE,
	action: Action
): HostState {
	switch ( action.type ) {
		case 'SET_SYNC_CONNECTION_STATUS': {
			const key = entityKey( action.kind, action.name, action.key );
			return {
				...state,
				connectionStatuses: {
					...state.connectionStatuses,
					[ key ]: action.status,
				},
			};
		}
		case 'CLEAR_SYNC_CONNECTION_STATUS': {
			const key = entityKey( action.kind, action.name, action.key );
			const { [ key ]: _removed, ...rest } = state.connectionStatuses;
			void _removed;
			return { ...state, connectionStatuses: rest };
		}
		case 'SET_SYNC_REVIEW_ITEMS': {
			const key = entityKey( action.kind, action.name, action.key );
			if ( 0 === action.items.length ) {
				const { [ key ]: _removed, ...rest } = state.reviewItems;
				void _removed;
				return { ...state, reviewItems: rest };
			}
			return {
				...state,
				reviewItems: { ...state.reviewItems, [ key ]: action.items },
			};
		}
		case 'SET_COLLABORATION_SUPPORTED':
			return { ...state, supported: action.supported };
	}
	return state;
}

const actions = {
	/**
	 * Records a provider's connection status for an entity or collection
	 * (null clears it, on unload).
	 *
	 * @param kind   Entity kind.
	 * @param name   Entity name.
	 * @param key    Record id, or null for a collection.
	 * @param status The status, or null.
	 */
	setSyncConnectionStatus(
		kind: string,
		name: string,
		key: RecordKey,
		status: ConnectionStatus | null
	): Action {
		if ( ! status ) {
			return { type: 'CLEAR_SYNC_CONNECTION_STATUS', kind, name, key };
		}
		return { type: 'SET_SYNC_CONNECTION_STATUS', kind, name, key, status };
	},

	/**
	 * Stores an entity's review list (the open parked conflicts).
	 *
	 * @param kind  Entity kind.
	 * @param name  Entity name.
	 * @param key   Record id.
	 * @param items Review items.
	 */
	setSyncReviewItems(
		kind: string,
		name: string,
		key: RecordKey,
		items: SyncReviewItem[]
	): Action {
		return { type: 'SET_SYNC_REVIEW_ITEMS', kind, name, key, items };
	},

	/**
	 * Records whether this page can still collaborate. Setting it to false
	 * stops syncing everything; the host bridge listens for that.
	 *
	 * @param supported Whether collaboration is supported.
	 */
	setCollaborationSupported( supported: boolean ): Action {
		return { type: 'SET_COLLABORATION_SUPPORTED', supported };
	},

	/**
	 * Closes a parked conflict.
	 *
	 * @param kind       Entity kind.
	 * @param name       Entity name.
	 * @param key        Record id.
	 * @param proposalId Proposal id.
	 * @param resolution 'restored' or 'dismissed'.
	 */
	resolveSyncProposal:
		(
			kind: string,
			name: string,
			key: RecordKey,
			proposalId: string,
			resolution: 'restored' | 'dismissed'
		) =>
		() => {
			getActiveSyncManager()?.resolveProposal?.(
				`${ kind }/${ name }`,
				null === key ? null : String( key ),
				proposalId,
				resolution
			);
		},

	/**
	 * Restores a parked conflict's content as ordinary edits and closes it.
	 *
	 * @param kind       Entity kind.
	 * @param name       Entity name.
	 * @param key        Record id.
	 * @param proposalId Proposal id.
	 */
	restoreSyncProposal:
		( kind: string, name: string, key: RecordKey, proposalId: string ) =>
		() => {
			getActiveSyncManager()?.restoreProposal?.(
				`${ kind }/${ name }`,
				null === key ? null : String( key ),
				proposalId
			);
		},

	/**
	 * Asks every live transport to reconnect after a connection error.
	 */
	retrySyncConnection: () => () => {
		getActiveSyncManager()?.retry?.();
	},
};

const PRIORITIZED_STATUSES = [ 'disconnected', 'connecting', 'connected' ];

const selectors = {
	/**
	 * The connection status across every synced entity: disconnected wins
	 * over connecting, which wins over connected.
	 *
	 * @param state Host state.
	 * @return The status, or undefined when nothing is connected.
	 */
	getSyncConnectionStatus( state: HostState ): ConnectionStatus | undefined {
		let coalesced: ConnectionStatus | undefined;
		for ( const status of Object.values( state.connectionStatuses ) ) {
			if (
				! coalesced ||
				PRIORITIZED_STATUSES.indexOf( status.status ) <
					PRIORITIZED_STATUSES.indexOf( coalesced.status )
			) {
				coalesced = status;
			}
		}
		return coalesced;
	},

	/**
	 * The connection status of one entity.
	 *
	 * @param state Host state.
	 * @param kind  Entity kind.
	 * @param name  Entity name.
	 * @param key   Record id.
	 * @return The status, or undefined.
	 */
	getEntitySyncConnectionStatus(
		state: HostState,
		kind: string,
		name: string,
		key: RecordKey
	): ConnectionStatus | undefined {
		return state.connectionStatuses[ entityKey( kind, name, key ) ];
	},

	/**
	 * Whether this page can still collaborate.
	 *
	 * @param state Host state.
	 * @return Whether collaboration is supported.
	 */
	isCollaborationSupported( state: HostState ): boolean {
		return state.supported;
	},

	/**
	 * The open parked conflicts of an entity.
	 *
	 * @param state Host state.
	 * @param kind  Entity kind.
	 * @param name  Entity name.
	 * @param key   Record id.
	 * @return Review items.
	 */
	getSyncReviewItems(
		state: HostState,
		kind: string,
		name: string,
		key: RecordKey
	): SyncReviewItem[] {
		return (
			state.reviewItems[ entityKey( kind, name, key ) ] ??
			EMPTY_REVIEW_ITEMS
		);
	},

	/**
	 * Whether collaboration runs for the post open in the editor: the
	 * server said the screen supports it, this page still does, and the
	 * bridge could create a manager.
	 */
	isCollaborationEnabledForCurrentPost: createRegistrySelector(
		( select ) =>
			( state: HostState ): boolean => {
				if ( ! state.supported || ! getActiveSyncManager() ) {
					return false;
				}
				const screen = getAnnouncedSync()?.screen;
				if ( ! screen?.supported ) {
					return false;
				}
				const { getCurrentPostType, getCurrentPostId } =
					select( editorStore );
				return (
					getCurrentPostType() === screen.postType &&
					getCurrentPostId() === screen.postId
				);
			}
	),
};

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

register( store );
