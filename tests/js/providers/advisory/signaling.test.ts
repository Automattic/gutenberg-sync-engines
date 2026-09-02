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
 * The signaling lane rides `wp.heartbeat`: `heartbeat.send` carries this
 * tab's token (and any queued handshake messages), `heartbeat.tick`
 * brings back company, the discovered peers, and this tab's mailbox.
 */

type Hook = ( data: Record< string, unknown > ) => void;
const hooks: Record< string, Hook > = {};

jest.mock( '@wordpress/hooks', () => ( {
	addAction: jest.fn( ( hook: string, _ns: string, callback: Hook ) => {
		hooks[ hook ] = callback;
	} ),
	applyFilters: jest.fn(
		( _hook: string, defaultValue: unknown ) => defaultValue
	),
} ) );

type Signaling = typeof import('../../../../src/providers/advisory/signaling');

function setSettings( advisory: Record< string, unknown > | null ): void {
	(
		window as { _gutenbergSyncEnginesSettings?: unknown }
	 )._gutenbergSyncEnginesSettings = advisory ? { advisory } : {};
}

describe( 'advisory signaling', () => {
	let signaling: Signaling;
	let connectNow: jest.Mock;

	beforeEach( () => {
		jest.useFakeTimers();
		connectNow = jest.fn();
		( window as { wp?: unknown } ).wp = {
			heartbeat: { interval: jest.fn(), connectNow },
		};
		setSettings( {
			room: 'postType/post:7',
			token: 'tok-b',
			othersPresent: false,
			leaveUrl:
				'https://example.test/wp-json/gutenberg-sync-engines/v1/advisory/leave',
			nonce: 'nonce-1',
		} );
		for ( const key of Object.keys( hooks ) ) {
			delete hooks[ key ];
		}
		jest.isolateModules( () => {
			signaling = require( '../../../../src/providers/advisory/signaling' );
		} );
	} );

	afterEach( () => {
		signaling.resetSignalingForTesting();
		jest.useRealTimers();
		delete ( window as { wp?: unknown } ).wp;
		setSettings( null );
	} );

	it( 'is unavailable without settings or without the heartbeat API', () => {
		expect( signaling.isSignalingAvailable() ).toBe( true );
		delete ( window as { wp?: unknown } ).wp;
		expect( signaling.isSignalingAvailable() ).toBe( false );
		( window as { wp?: unknown } ).wp = {
			heartbeat: { interval: jest.fn() },
		};
		setSettings( null );
		expect( signaling.isSignalingAvailable() ).toBe( false );
	} );

	it( 'stamps the room, token, client id, and queued signals on each beat', () => {
		signaling.installSignaling();
		signaling.setSyncClientId( 42 );
		signaling.sendSignal( 'tok-a', 'offer', 'sdp-offer' );

		const data: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( data );
		expect( data[ signaling.HEARTBEAT_DATA_KEY ] ).toEqual( {
			room: 'postType/post:7',
			token: 'tok-b',
			client_id: 42,
			signals: [ { to: 'tok-a', kind: 'offer', data: 'sdp-offer' } ],
		} );

		// The outbox drains: the next beat carries no signals.
		const next: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( next );
		expect( next[ signaling.HEARTBEAT_DATA_KEY ] ).toEqual( {
			room: 'postType/post:7',
			token: 'tok-b',
			client_id: 42,
		} );
	} );

	it( 'asks the heartbeat to beat now when a signal is queued, once per burst', () => {
		signaling.installSignaling();
		signaling.sendSignal( 'tok-a', 'offer', 'o' );
		signaling.sendSignal( 'tok-a', 'ice', 'c' );
		expect( connectNow ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 50 );
		expect( connectNow ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reads company, peers, and the mailbox from a tick and notifies listeners', () => {
		const onOthers = jest.fn();
		const onPeers = jest.fn();
		const onSignal = jest.fn();
		signaling.onOthersChanged( onOthers );
		signaling.onPeersChanged( onPeers );
		signaling.onSignal( onSignal );

		expect( signaling.othersPresent() ).toBe( false );
		hooks[ 'heartbeat.tick' ]( {
			[ signaling.HEARTBEAT_DATA_KEY ]: {
				others: true,
				peers: [ { token: 'tok-c', client_id: 9, user_id: 2 } ],
				signals: [ { from: 'tok-c', kind: 'answer', data: 'sdp' } ],
			},
		} );

		expect( signaling.othersPresent() ).toBe( true );
		expect( onOthers ).toHaveBeenCalledWith( true );
		expect( signaling.getDiscoveredPeers() ).toEqual( [
			{ token: 'tok-c', clientId: 9, userId: 2 },
		] );
		expect( onPeers ).toHaveBeenCalledTimes( 1 );
		expect( onSignal ).toHaveBeenCalledWith( {
			from: 'tok-c',
			kind: 'answer',
			data: 'sdp',
		} );

		// An unchanged peer list does not re-notify; a flip back does.
		hooks[ 'heartbeat.tick' ]( {
			[ signaling.HEARTBEAT_DATA_KEY ]: {
				others: true,
				peers: [ { token: 'tok-c', client_id: 9, user_id: 2 } ],
			},
		} );
		expect( onPeers ).toHaveBeenCalledTimes( 1 );
		expect( onOthers ).toHaveBeenCalledTimes( 1 );
		hooks[ 'heartbeat.tick' ]( {
			[ signaling.HEARTBEAT_DATA_KEY ]: { others: false, peers: [] },
		} );
		expect( onOthers ).toHaveBeenLastCalledWith( false );
		expect( signaling.getDiscoveredPeers() ).toEqual( [] );
	} );

	it( 'ignores ticks without our key and malformed answers', () => {
		const onOthers = jest.fn();
		signaling.onOthersChanged( onOthers );
		hooks[ 'heartbeat.tick' ]( { wp_autosave: {} } );
		hooks[ 'heartbeat.tick' ]( {
			[ signaling.HEARTBEAT_DATA_KEY ]: { others: 'yes' },
		} );
		expect( onOthers ).not.toHaveBeenCalled();
		expect( signaling.othersPresent() ).toBe( false );
	} );

	it( 'starts out believing the page-render company flag', () => {
		setSettings( {
			room: 'postType/post:7',
			token: 'tok-b',
			othersPresent: true,
		} );
		expect( signaling.othersPresent() ).toBe( true );
	} );

	it( 'sends the leave beacon once with the room and token', () => {
		const sendBeacon = jest.fn( () => true );
		Object.defineProperty( navigator, 'sendBeacon', {
			configurable: true,
			value: sendBeacon,
		} );
		try {
			expect( signaling.sendLeaveBeacon() ).toBe( true );
			expect( signaling.sendLeaveBeacon() ).toBe( false );
			expect( sendBeacon ).toHaveBeenCalledTimes( 1 );
			const [ url ] = sendBeacon.mock.calls[ 0 ] as unknown as [
				string,
				Blob,
			];
			expect( url ).toContain( '/advisory/leave?_wpnonce=nonce-1' );
		} finally {
			delete ( navigator as { sendBeacon?: unknown } ).sendBeacon;
		}
	} );
} );
