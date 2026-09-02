/**
 * External dependencies
 */
import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import type { EngineSessionCodec } from '@wordpress/sync';

/**
 * The cadence rules of docs/plan/advisory-channel.md, with the signaling
 * lane and the advisory channel replaced by controllable fakes.
 */

let mockSignalingAvailable = true;
let mockOthers = false;
let mockCoverage = false;
let mockChannelPresence: Record< string, unknown > = {};
const mockCallbacks: {
	others: Array< ( others: boolean ) => void >;
	coverage: Array< () => void >;
	announce: Array< ( room: string ) => void >;
	presence: Array< ( room: string ) => void >;
} = { others: [], coverage: [], announce: [], presence: [] };
const mockSetDisabled = jest.fn();
const mockAnnounceLocalWrite = jest.fn();

jest.mock( '@wordpress/hooks', () => ( {
	addAction: jest.fn(),
	applyFilters: jest.fn(
		( _hook: string, defaultValue: unknown ) => defaultValue
	),
} ) );

jest.mock( '../../../../src/providers/advisory/signaling', () => ( {
	installSignaling: jest.fn(),
	installSignalingLifecycle: jest.fn(),
	isSignalingAvailable: () => mockSignalingAvailable,
	othersPresent: () => mockOthers,
	onOthersChanged: ( cb: ( others: boolean ) => void ) =>
		mockCallbacks.others.push( cb ),
	setSyncClientId: jest.fn(),
} ) );

jest.mock( '../../../../src/providers/advisory/channel', () => ( {
	advisoryCoversClients: () => mockCoverage,
	getChannelPresence: () => mockChannelPresence,
	onAdvisoryAnnounce: ( cb: ( room: string ) => void ) =>
		mockCallbacks.announce.push( cb ),
	onAdvisoryCoverageChanged: ( cb: () => void ) =>
		mockCallbacks.coverage.push( cb ),
	onAdvisoryPresence: ( cb: ( room: string ) => void ) =>
		mockCallbacks.presence.push( cb ),
	setAdvisoryDisabledByTransport: mockSetDisabled,
	setPresenceSource: jest.fn(),
	startAdvisoryChannel: jest.fn(),
	stopAdvisoryChannel: jest.fn(),
} ) );

jest.mock( '../../../../src/providers/advisory/announce', () => ( {
	announceLocalWrite: mockAnnounceLocalWrite,
} ) );

jest.mock( '../../../../src/providers/http-polling/utils', () => ( {
	...( jest.requireActual(
		'../../../../src/providers/http-polling/utils'
	) as object ),
	postSyncUpdate: jest.fn(),
	postSyncUpdateNonBlocking: jest.fn(),
} ) );

type Manager =
	typeof import('../../../../src/providers/http-polling/polling-manager');

function response( clients: number[], cursor = 1 ) {
	const awareness: Record< number, object > = {};
	for ( const id of clients ) {
		awareness[ id ] = { user: id };
	}
	return {
		rooms: [
			{ room: 'test-room', end_cursor: cursor, awareness, updates: [] },
		],
	};
}

function createMockSession( clientId = 1 ) {
	return {
		applyRemoteAwareness: jest.fn(),
		clientId,
		destroy: jest.fn(),
		getInitialUpdates: jest.fn( () => [] ),
		getLocalAwareness: jest.fn( () => ( { user: clientId } ) ),
		onLocalUpdate: jest.fn(),
		receiveUpdate: jest.fn(),
	};
}

describe( 'polling-manager cadence', () => {
	let pollingManager: Manager[ 'pollingManager' ];
	let setLongPollMode: Manager[ 'setLongPollMode' ];
	let mockPostSyncUpdate: jest.Mock<
		typeof import('../../../../src/providers/http-polling/utils').postSyncUpdate
	>;

	beforeEach( () => {
		jest.useFakeTimers();
		mockSignalingAvailable = true;
		mockOthers = false;
		mockCoverage = false;
		mockChannelPresence = {};
		mockCallbacks.others.length = 0;
		mockCallbacks.coverage.length = 0;
		mockCallbacks.announce.length = 0;
		mockCallbacks.presence.length = 0;
		mockSetDisabled.mockClear();
		mockAnnounceLocalWrite.mockClear();
		jest.isolateModules( () => {
			const managerModule: Manager = require( '../../../../src/providers/http-polling/polling-manager' );
			pollingManager = managerModule.pollingManager;
			setLongPollMode = managerModule.setLongPollMode;
			mockPostSyncUpdate =
				require( '../../../../src/providers/http-polling/utils' ).postSyncUpdate;
		} );
	} );

	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
	} );

	function register( session = createMockSession() ) {
		pollingManager.registerRoom( {
			room: 'test-room',
			session: session as unknown as EngineSessionCodec,
			log: jest.fn(),
			onStatusChange: jest.fn(),
		} );
		return session;
	}

	it( 'a lone tab stops scheduling polls after the first successful poll', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 60000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a lone tab still sends its own updates shortly after they are queued', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 299 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 1 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 2 );
		// Rows landed: the peers on the channel are told to poll.
		expect( mockAnnounceLocalWrite ).toHaveBeenCalledWith( 'test-room' );
		// And the loop is quiet again.
		await jest.advanceTimersByTimeAsync( 60000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( "updates queued while a lone tab's poll is in flight go out right after it returns", async () => {
		let release!: ( value: ReturnType< typeof response > ) => void;
		mockPostSyncUpdate.mockResolvedValueOnce( response( [ 1 ] ) );
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;

		// The on-demand poll is held open; more updates arrive meanwhile.
		mockPostSyncUpdate.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					release = resolve;
				} )
		);
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );

		mockPostSyncUpdate.mockResolvedValueOnce( response( [ 1 ], 2 ) );
		release( response( [ 1 ], 2 ) );
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
		expect(
			mockPostSyncUpdate.mock.calls[ 2 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 1 );
	} );

	it( 'company from the heartbeat wakes a quiet tab onto the timer cadence', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		await jest.advanceTimersByTimeAsync( 10000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );

		mockOthers = true;
		mockCallbacks.others.forEach( ( cb ) => cb( true ) );
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		// Nobody on the channel yet: the with-collaborators cadence.
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );

		// The heartbeat says they left: quiet again after the next poll.
		mockOthers = false;
		mockCallbacks.others.forEach( ( cb ) => cb( false ) );
		await jest.advanceTimersByTimeAsync( 60000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'an awareness map with company keeps the timer cadence even when the heartbeat is silent', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'full channel coverage slows the timer to the safety cadence and polls on announcements', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 24999 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 1 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );

		// A burst of announcements collapses into one poll after the
		// coalescing delay.
		mockCallbacks.announce.forEach( ( cb ) => cb( 'test-room' ) );
		mockCallbacks.announce.forEach( ( cb ) => cb( 'test-room' ) );
		await jest.advanceTimersByTimeAsync( 150 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
		// Another right away waits out the floor.
		mockCallbacks.announce.forEach( ( cb ) => cb( 'test-room' ) );
		await jest.advanceTimersByTimeAsync( 150 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
		await jest.advanceTimersByTimeAsync( 100 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'a local update under coverage polls on demand instead of waiting for the safety timer', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'a coverage flip never parks queued updates behind the safety timer', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		// On the 1 s timer; an undo queues inverse intents, which the
		// pending timer would send.
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'intent', data: 'AA==' }, 1 );
		// The channel connects right then: coverage flips and the timer
		// is re-evaluated. The queued work must still go out promptly.
		mockCoverage = true;
		mockCallbacks.coverage.forEach( ( cb ) => cb() );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 1 );
	} );

	it( 'updates queued during a poll under coverage go out right after it', async () => {
		let release!: ( value: ReturnType< typeof response > ) => void;
		mockOthers = true;
		mockCoverage = true;
		mockPostSyncUpdate.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					release = resolve;
				} )
		);
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ], 2 ) );
		release( response( [ 1, 2 ] ) );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'losing coverage returns the pending timer to the with-collaborators cadence', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		mockCoverage = false;
		mockCallbacks.coverage.forEach( ( cb ) => cb() );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'without the signaling lane the always-on cadence is unchanged', async () => {
		mockSignalingAvailable = false;
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		await jest.advanceTimersByTimeAsync( 4000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'long polling switches the channel off while connected and back on after a failure', async () => {
		setLongPollMode( true );
		mockOthers = true;
		mockPostSyncUpdate.mockResolvedValueOnce( response( [ 1, 2 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockSetDisabled ).toHaveBeenLastCalledWith( true );

		mockPostSyncUpdate.mockRejectedValueOnce( new Error( 'down' ) );
		await jest.advanceTimersByTimeAsync( 50 );
		expect( mockSetDisabled ).toHaveBeenLastCalledWith( false );
	} );

	it( 'overlays channel presence on the poll response and re-applies it when it changes', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockChannelPresence = { 2: { user: 2, cursor: 'live' } };
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( session.applyRemoteAwareness ).toHaveBeenLastCalledWith( {
			1: { user: 1 },
			2: { user: 2, cursor: 'live' },
		} );

		mockChannelPresence = { 2: { user: 2, cursor: 'moved' } };
		mockCallbacks.presence.forEach( ( cb ) => cb( 'test-room' ) );
		expect( session.applyRemoteAwareness ).toHaveBeenLastCalledWith( {
			1: { user: 1 },
			2: { user: 2, cursor: 'moved' },
		} );
	} );
} );
