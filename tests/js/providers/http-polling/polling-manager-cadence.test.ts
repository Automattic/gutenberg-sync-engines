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
	cursor: Array< ( cursor: number ) => void >;
	engine: Array< ( engine: string ) => void >;
} = {
	others: [],
	coverage: [],
	announce: [],
	presence: [],
	cursor: [],
	engine: [],
};
const mockSetDisabled = jest.fn();
const mockAnnounceLocalWrite = jest.fn();

jest.mock( '@wordpress/hooks', () => ( {
	addAction: jest.fn(),
	applyFilters: jest.fn(
		( _hook: string, defaultValue: unknown ) => defaultValue
	),
} ) );

jest.mock( '../../../../src/providers/advisory/signaling', () => ( {
	applyAnswer: jest.fn(),
	buildProbe: () => ( { seq: 1, room: 'postType/post:1', token: 'tok' } ),
	probeFailed: jest.fn(),
	installSignaling: jest.fn(),
	installSignalingLifecycle: jest.fn(),
	isSignalingAvailable: () => mockSignalingAvailable,
	othersPresent: () => mockOthers,
	onOthersChanged: ( cb: ( others: boolean ) => void ) =>
		mockCallbacks.others.push( cb ),
	onRoomCursor: ( cb: ( cursor: number ) => void ) =>
		mockCallbacks.cursor.push( cb ),
	onRoomEngine: ( cb: ( engine: string ) => void ) =>
		mockCallbacks.engine.push( cb ),
	setSignalCarrier: jest.fn(),
	setSyncClientId: jest.fn(),
} ) );

jest.mock( '../../../../src/providers/http-polling/save-flush', () => ( {
	registerSaveFlush: jest.fn(),
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

function createMockSession( clientId = 1, sendsWhileAlone = false ) {
	return {
		applyRemoteAwareness: jest.fn(),
		clientId,
		engineSlug: 'intent-log',
		...( sendsWhileAlone ? { sendsWhileAlone: true } : {} ),
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
	let flushHeldUpdates: Manager[ 'flushHeldUpdates' ];
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
		mockCallbacks.cursor.length = 0;
		mockCallbacks.engine.length = 0;
		mockSetDisabled.mockClear();
		mockAnnounceLocalWrite.mockClear();
		jest.isolateModules( () => {
			const managerModule: Manager = require( '../../../../src/providers/http-polling/polling-manager' );
			pollingManager = managerModule.pollingManager;
			setLongPollMode = managerModule.setLongPollMode;
			flushHeldUpdates = managerModule.flushHeldUpdates;
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

	it( 'a lone tab keeps the solo cadence for a discovery window after load, then stops scheduling', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		// 30 s of 4 s polls: the moments a second person most often turns
		// up, found within seconds instead of a heartbeat.
		await jest.advanceTimersByTimeAsync( 4000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		// Past the window (plus the last 4 s poll scheduled inside it):
		// no timer at all.
		await jest.advanceTimersByTimeAsync( 30000 );
		const afterWindow = mockPostSyncUpdate.mock.calls.length;
		expect( afterWindow ).toBeGreaterThanOrEqual( 8 );
		await jest.advanceTimersByTimeAsync( 120000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( afterWindow );

		// Regaining focus reopens the window.
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'hidden',
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'visible',
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		await jest.advanceTimersByTimeAsync( 0 );
		const afterFocus = mockPostSyncUpdate.mock.calls.length;
		await jest.advanceTimersByTimeAsync( 4000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( afterFocus + 1 );
	} );

	it( 'a lone tab holds its updates; a flush (before a save) sends them and holds again', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		// Held: no on-demand poll, and the discovery-window polls carry
		// nothing.
		await jest.advanceTimersByTimeAsync( 4000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 0 );

		// The save middleware's flush releases the queue and waits for the
		// poll to return.
		const flushed = flushHeldUpdates();
		await jest.advanceTimersByTimeAsync( 0 );
		await flushed;
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
		expect(
			mockPostSyncUpdate.mock.calls[ 2 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 2 );
		expect( mockAnnounceLocalWrite ).toHaveBeenCalledWith( 'test-room' );

		// Still alone: the next update is held again (no on-demand poll
		// within the send delay).
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 500 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'a flush that lands while a poll is in flight waits for the successor that carries the work', async () => {
		let release!: ( value: ReturnType< typeof response > ) => void;
		mockPostSyncUpdate.mockResolvedValueOnce( response( [ 1 ] ) );
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );

		// A poll is in flight (built before the flush): hold it open.
		mockPostSyncUpdate.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					release = resolve;
				} )
		);
		await jest.advanceTimersByTimeAsync( 25000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		let flushed = false;
		void flushHeldUpdates().then( () => {
			flushed = true;
		} );
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ], 2 ) );
		release( response( [ 1 ] ) );
		await jest.advanceTimersByTimeAsync( 0 );
		// Not resolved by the in-flight poll's end...
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
		await jest.advanceTimersByTimeAsync( 0 );
		// ...but by the successor, which carried the held update.
		expect( flushed ).toBe( true );
		expect(
			mockPostSyncUpdate.mock.calls[ 2 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 1 );
	} );

	it( 'a lone tab going hidden flushes its held work once, unless a pagehide follows', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );

		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'hidden',
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		// A reload hides first, then unloads: pagehide cancels the flush.
		window.dispatchEvent( new Event( 'pagehide' ) );
		await jest.advanceTimersByTimeAsync( 2000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );

		// A real tab switch: the flush lands after the grace period.
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		await jest.advanceTimersByTimeAsync( 1500 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 1 );
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'visible',
		} );
	} );

	it( 'a codec that sends while alone (de-rtc) is exempt from the hold and polls on demand', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		const session = register( createMockSession( 1, true ) );
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'fetch', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 1 );
	} );

	it( "updates queued while a lone (exempt) tab's poll is in flight go out right after it returns", async () => {
		let release!: ( value: ReturnType< typeof response > ) => void;
		mockPostSyncUpdate.mockResolvedValueOnce( response( [ 1 ] ) );
		const session = register( createMockSession( 1, true ) );
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

	it( 'company from the heartbeat wakes a lone tab onto the timer cadence and releases its queue', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1 ] ) );
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		const onLocalUpdate = session.onLocalUpdate.mock.calls[ 0 ][ 0 ] as (
			update: unknown,
			size: number
		) => void;
		onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );
		await jest.advanceTimersByTimeAsync( 3000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );

		mockOthers = true;
		mockCallbacks.others.forEach( ( cb ) => cb( true ) );
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].updates
		).toHaveLength( 1 );
		// Nobody on the channel yet: the with-collaborators cadence.
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );

		// The heartbeat says they left: back to the solo cadence (the
		// discovery window is still open this soon after load).
		mockOthers = false;
		mockCallbacks.others.forEach( ( cb ) => cb( false ) );
		await jest.advanceTimersByTimeAsync( 3000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'an awareness map with company keeps the timer cadence even when the heartbeat is silent', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'full channel coverage stops the timer and polls on announcements', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 120000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		// (Two rounds follow: count from here.)
		mockCallbacks.announce.forEach( ( cb ) => cb( 'test-room' ) );
		await jest.advanceTimersByTimeAsync( 150 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		await jest.advanceTimersByTimeAsync( 300 );

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

	it( 'a local update under coverage polls on demand', async () => {
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

	it( 'a coverage flip never parks queued updates behind a stopped timer', async () => {
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

	it( 'a room registered mid-session polls soon even with the timer stopped', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		pollingManager.registerRoom( {
			room: 'taxonomy/category',
			session: createMockSession( 3 ) as unknown as EngineSessionCodec,
			log: jest.fn(),
			onStatusChange: jest.fn(),
		} );
		await jest.advanceTimersByTimeAsync( 300 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect(
			mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms.map( ( r ) => r.room )
		).toEqual( [ 'test-room', 'taxonomy/category' ] );
	} );

	it( 'a heartbeat answer reporting the room ahead of this tab polls, one that does not stays quiet', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ], 7 ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );

		// Same head as our cursor: nothing to fetch.
		mockCallbacks.cursor.forEach( ( cb ) => cb( 7 ) );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );

		// A script wrote rows nobody announced: the beat says the room
		// is ahead, so the tab polls.
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ], 9 ) );
		mockCallbacks.cursor.forEach( ( cb ) => cb( 9 ) );
		await jest.advanceTimersByTimeAsync( 150 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		expect( mockPostSyncUpdate.mock.calls[ 1 ][ 0 ].rooms[ 0 ].after ).toBe(
			7
		);
	} );

	it( 'a heartbeat answer naming a different engine polls into the fence; the same engine stays quiet', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		mockCallbacks.engine.forEach( ( cb ) => cb( 'intent-log' ) );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		mockCallbacks.engine.forEach( ( cb ) => cb( 'yjs-server' ) );
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'losing coverage restarts the stopped loop at the with-collaborators cadence', async () => {
		mockPostSyncUpdate.mockResolvedValue( response( [ 1, 2 ] ) );
		mockOthers = true;
		mockCoverage = true;
		register();
		await jest.advanceTimersByTimeAsync( 0 );
		await jest.advanceTimersByTimeAsync( 5000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		mockCoverage = false;
		mockCallbacks.coverage.forEach( ( cb ) => cb() );
		await jest.advanceTimersByTimeAsync( 0 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 2 );
		await jest.advanceTimersByTimeAsync( 1000 );
		expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 3 );
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
		mockChannelPresence = { 2: { name: 'live' }, 3: { name: 'new' } };
		const session = register();
		await jest.advanceTimersByTimeAsync( 0 );
		// The channel's base presence overlays the server's copy per
		// client (server-only fields such as cursors survive), and a peer
		// the server has not reported yet appears from the channel alone.
		expect( session.applyRemoteAwareness ).toHaveBeenLastCalledWith( {
			1: { user: 1 },
			2: { user: 2, name: 'live' },
			3: { name: 'new' },
		} );

		mockChannelPresence = { 2: { name: 'renamed' } };
		mockCallbacks.presence.forEach( ( cb ) => cb( 'test-room' ) );
		expect( session.applyRemoteAwareness ).toHaveBeenLastCalledWith( {
			1: { user: 1 },
			2: { user: 2, name: 'renamed' },
		} );
	} );

	describe( 'handing a room to and from a preferred transport', () => {
		it( 'resumes at the given cursor and releases the room with its cursor and unsent work, keeping the session', async () => {
			mockSignalingAvailable = false;
			mockPostSyncUpdate.mockResolvedValue( response( [ 1 ], 9 ) );
			const session = createMockSession();
			pollingManager.registerRoom( {
				room: 'test-room',
				session: session as unknown as EngineSessionCodec,
				log: jest.fn(),
				onStatusChange: jest.fn(),
				initialCursor: 5,
			} );
			await jest.advanceTimersByTimeAsync( 0 );
			expect(
				mockPostSyncUpdate.mock.calls[ 0 ][ 0 ].rooms[ 0 ].after
			).toBe( 5 );
			const onLocalUpdate = session.onLocalUpdate.mock
				.calls[ 0 ][ 0 ] as ( update: unknown, size: number ) => void;
			onLocalUpdate( { type: 'update', data: 'AA==' }, 1 );

			const released = await pollingManager.releaseRoom( 'test-room' );
			expect( released ).toEqual( {
				cursor: 9,
				unsent: [ { type: 'update', data: 'AA==' } ],
			} );
			expect( session.destroy ).not.toHaveBeenCalled();
			// The loop is empty: nothing polls any more.
			await jest.advanceTimersByTimeAsync( 10000 );
			expect( mockPostSyncUpdate ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'waits for a request in flight before releasing, so the cursor is final', async () => {
			mockSignalingAvailable = false;
			let release!: ( value: ReturnType< typeof response > ) => void;
			mockPostSyncUpdate.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						release = resolve;
					} )
			);
			register();
			await jest.advanceTimersByTimeAsync( 0 );
			let released: unknown = null;
			void pollingManager.releaseRoom( 'test-room' ).then( ( value ) => {
				released = value;
			} );
			await jest.advanceTimersByTimeAsync( 0 );
			expect( released ).toBeNull();
			release( response( [ 1 ], 3 ) );
			await jest.advanceTimersByTimeAsync( 0 );
			expect( released ).toEqual( { cursor: 3, unsent: [] } );
		} );
	} );
} );
