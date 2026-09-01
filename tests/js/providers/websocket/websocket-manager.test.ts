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

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	websocketManager,
	resetWebSocketManagerForTesting,
} from '../../../../src/providers/websocket/websocket-manager';
import type { EngineSessionCodec } from '@wordpress/sync';

jest.mock( '@wordpress/api-fetch' );

// The solo-presence lane defaults to unavailable, so tests outside the
// quiet-while-alone describe keep today's always-connected behavior.
jest.mock( '../../../../src/providers/solo-presence', () => ( {
	isSoloPresenceAvailable: jest.fn( () => false ),
	othersLikely: jest.fn( () => false ),
	onOthersArrived: jest.fn(),
	setSyncClientId: jest.fn(),
} ) );

// eslint-disable-next-line @wordpress/dependency-group -- test-only import of the mock above.
import * as soloPresence from '../../../../src/providers/solo-presence';

const mockSoloPresence = soloPresence as unknown as {
	isSoloPresenceAvailable: jest.Mock< () => boolean >;
	othersLikely: jest.Mock< () => boolean >;
	onOthersArrived: jest.Mock< ( cb: () => void ) => void >;
	setSyncClientId: jest.Mock< ( id: number ) => void >;
};

// A minimal fake WebSocket capturing sends and exposing lifecycle triggers.
class FakeWebSocket {
	static instances: FakeWebSocket[] = [];
	static OPEN = 1;
	public readyState = 0;
	public sent: string[] = [];
	private listeners: Record< string, ( ( e: unknown ) => void )[] > = {};

	public constructor(
		public url: string,
		public protocols?: string[]
	) {
		FakeWebSocket.instances.push( this );
	}
	public addEventListener( type: string, cb: ( e: unknown ) => void ): void {
		( this.listeners[ type ] ??= [] ).push( cb );
	}
	public send( data: string ): void {
		this.sent.push( data );
	}
	public close(): void {
		this.readyState = 3;
		this.emit( 'close', {} );
	}
	public open(): void {
		this.readyState = FakeWebSocket.OPEN;
		this.emit( 'open', {} );
	}
	public receive( data: unknown ): void {
		this.emit( 'message', { data: JSON.stringify( data ) } );
	}
	private emit( type: string, e: unknown ): void {
		( this.listeners[ type ] ?? [] ).forEach( ( cb ) => cb( e ) );
	}
}

function fakeSession(
	overrides: Partial< EngineSessionCodec > = {}
): EngineSessionCodec {
	let localListener: ( ( u: unknown ) => void ) | null = null;
	return {
		clientId: 101,
		engineSlug: 'intent-log',
		engineProtocol: 1,
		getInitialUpdates: () => [],
		onLocalUpdate: ( cb: ( u: unknown ) => void ) => {
			localListener = cb;
		},
		emitLocal: ( u: unknown ) => localListener?.( u ),
		receiveUpdate: jest.fn< ( update: unknown ) => undefined >(),
		receiveDispositions: jest.fn(),
		getLocalAwareness: () => ( {} ),
		applyRemoteAwareness: jest.fn(),
		destroy: jest.fn(),
		...overrides,
	} as unknown as EngineSessionCodec;
}

describe( 'websocket manager', () => {
	afterEach( () => {
		resetWebSocketManagerForTesting();
		FakeWebSocket.instances = [];
		delete (
			window as Window & {
				_wpCollaborationTransportConfig?: unknown;
			}
		 )._wpCollaborationTransportConfig;
		( apiFetch as unknown as jest.Mock ).mockReset();
	} );

	const setup = () => {
		(
			window as Window & {
				_wpCollaborationTransportConfig?: unknown;
			}
		 )._wpCollaborationTransportConfig = {
			websocket: { url: 'ws://localhost:8787' },
		};
		( window as unknown as { WebSocket: unknown } ).WebSocket =
			FakeWebSocket as unknown;
		( apiFetch as unknown as jest.Mock ).mockResolvedValue( {
			token: 't0ken',
		} as never );
	};

	it( 'fetches a token, connects to the announced URL, and sends the initial sync', async () => {
		setup();
		const session = fakeSession();
		const onStatusChange = jest.fn();

		websocketManager.registerRoom( {
			room: 'postType/post:1',
			session,
			onStatusChange,
		} );
		// Let the token promise resolve.
		await Promise.resolve();
		await Promise.resolve();

		const ws = FakeWebSocket.instances[ 0 ];
		expect( ws ).toBeDefined();
		// The token rides the subprotocol offer, never the URL
		// (query strings land in server/proxy access logs).
		expect( ws.url ).not.toContain( 't0ken' );
		expect( ws.protocols ).toEqual( [ 'wp-sync', 'wp-sync-token.t0ken' ] );

		ws.open();
		expect( onStatusChange ).toHaveBeenCalledWith( {
			status: 'connected',
		} );
		// The initial sync frame carries the room, cursor, and engine stamp.
		const frame = JSON.parse( ws.sent[ 0 ] );
		expect( frame.type ).toBe( 'sync' );
		expect( frame.rooms[ 0 ] ).toMatchObject( {
			room: 'postType/post:1',
			after: 0,
			client_id: 101,
			engine: 'intent-log',
		} );
	} );

	it( 'feeds pushed updates to the codec and advances the cursor', async () => {
		setup();
		const receiveUpdate = jest.fn< ( update: unknown ) => undefined >();
		const session = fakeSession( {
			receiveUpdate,
		} as Partial< EngineSessionCodec > );

		websocketManager.registerRoom( {
			room: 'postType/post:1',
			session,
			onStatusChange: jest.fn(),
		} );
		await Promise.resolve();
		await Promise.resolve();
		const ws = FakeWebSocket.instances[ 0 ];
		ws.open();
		ws.sent = [];

		// The server pushes a peer's update.
		ws.receive( {
			type: 'sync',
			rooms: [
				{
					room: 'postType/post:1',
					awareness: { 2: {} },
					updates: [ { type: 'intent', data: 'AAAA' } ],
					end_cursor: 7,
				},
			],
		} );

		expect( receiveUpdate ).toHaveBeenCalledWith( {
			type: 'intent',
			data: 'AAAA',
		} );
		expect( session.applyRemoteAwareness ).toHaveBeenCalledWith( {
			2: {},
		} );

		// The next outgoing frame carries the advanced cursor.
		(
			session as unknown as { emitLocal: ( u: unknown ) => void }
		 ).emitLocal( { type: 'intent', data: 'BBBB' } );
		const frame = JSON.parse( ws.sent.at( -1 ) as string );
		expect( frame.rooms[ 0 ].after ).toBe( 7 );
		expect( frame.rooms[ 0 ].updates ).toEqual( [
			{ type: 'intent', data: 'BBBB' },
		] );
	} );

	it( 'closes the socket when the last room unregisters', async () => {
		setup();
		websocketManager.registerRoom( {
			room: 'postType/post:1',
			session: fakeSession(),
			onStatusChange: jest.fn(),
		} );
		await Promise.resolve();
		await Promise.resolve();
		const ws = FakeWebSocket.instances[ 0 ];
		ws.open();

		websocketManager.unregisterRoom( 'postType/post:1' );
		expect( ws.readyState ).toBe( 3 );
	} );

	describe( 'quiet while alone', () => {
		beforeEach( () => {
			jest.useFakeTimers();
			mockSoloPresence.isSoloPresenceAvailable.mockReturnValue( true );
			mockSoloPresence.othersLikely.mockReturnValue( false );
		} );

		afterEach( () => {
			jest.clearAllTimers();
			jest.useRealTimers();
			mockSoloPresence.isSoloPresenceAvailable.mockReturnValue( false );
			mockSoloPresence.othersLikely.mockReturnValue( false );
			mockSoloPresence.onOthersArrived.mockReset();
		} );

		async function openSoloSocket() {
			setup();
			const onStatusChange = jest.fn();
			const session = fakeSession();
			websocketManager.registerRoom( {
				room: 'postType/post:1',
				session,
				onStatusChange,
			} );
			await Promise.resolve();
			await Promise.resolve();
			const ws = FakeWebSocket.instances[ 0 ];
			ws.open();
			onStatusChange.mockClear();
			return { ws, session, onStatusChange };
		}

		it( 'closes the idle solo socket without a reconnect loop', async () => {
			const { ws, onStatusChange } = await openSoloSocket();

			// Idle past the quiet threshold: the keepalive tick closes the
			// socket instead of refreshing awareness forever.
			await jest.advanceTimersByTimeAsync( 40000 );
			expect( ws.readyState ).toBe( 3 );

			// A deliberate quiet close: no disconnect status, no reconnect.
			expect( onStatusChange ).not.toHaveBeenCalled();
			await jest.advanceTimersByTimeAsync( 60000 );
			expect( FakeWebSocket.instances ).toHaveLength( 1 );
		} );

		it( 'stays connected while peers or traffic are around', async () => {
			const { ws } = await openSoloSocket();

			// A peer's awareness arrives just before the threshold.
			await jest.advanceTimersByTimeAsync( 25000 );
			ws.receive( {
				type: 'sync',
				rooms: [
					{
						room: 'postType/post:1',
						awareness: { 101: {}, 2: {} },
						updates: [],
						end_cursor: 1,
					},
				],
			} );

			// The idle clock restarted: 25 more seconds is not enough.
			await jest.advanceTimersByTimeAsync( 25000 );
			expect( ws.readyState ).toBe( FakeWebSocket.OPEN );

			// Ten more with nothing new crosses it.
			await jest.advanceTimersByTimeAsync( 10000 );
			expect( ws.readyState ).toBe( 3 );
		} );

		it( 'reconnects when the presence lane reports an arrival', async () => {
			const { ws } = await openSoloSocket();
			await jest.advanceTimersByTimeAsync( 40000 );
			expect( ws.readyState ).toBe( 3 );

			expect( mockSoloPresence.onOthersArrived ).toHaveBeenCalled();
			mockSoloPresence.othersLikely.mockReturnValue( true );
			mockSoloPresence.onOthersArrived.mock.calls[ 0 ][ 0 ]();
			await Promise.resolve();
			await Promise.resolve();

			expect( FakeWebSocket.instances ).toHaveLength( 2 );
		} );

		it( 'reconnects for a local update made while quiet', async () => {
			const { ws, session } = await openSoloSocket();
			await jest.advanceTimersByTimeAsync( 40000 );
			expect( ws.readyState ).toBe( 3 );

			(
				session as unknown as { emitLocal: ( u: unknown ) => void }
			 ).emitLocal( { type: 'intent', data: 'CCCC' } );
			await Promise.resolve();
			await Promise.resolve();

			expect( FakeWebSocket.instances ).toHaveLength( 2 );
		} );
	} );
} );
