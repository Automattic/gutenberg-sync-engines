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
 * The Heartbeat channel rides `wp.heartbeat` through the `heartbeat.send`
 * and `heartbeat.tick` actions, like the advisory signaling lane.
 */

type Hook = ( data: Record< string, unknown > ) => void;
const hooks: Record< string, Hook > = {};

jest.mock( '@wordpress/hooks', () => ( {
	addAction: jest.fn( ( hook: string, _ns: string, callback: Hook ) => {
		hooks[ hook ] = callback;
	} ),
	removeAction: jest.fn( ( hook: string ) => {
		delete hooks[ hook ];
	} ),
} ) );

/**
 * Internal dependencies
 */
import {
	createHeartbeatChannel,
	HEARTBEAT_KEY,
	isHeartbeatAvailable,
} from '../../../src/awareness/channels/heartbeat-channel';

describe( 'heartbeat channel', () => {
	let interval: jest.Mock;
	let connectNow: jest.Mock;

	beforeEach( () => {
		interval = jest.fn();
		connectNow = jest.fn();
		( window as { wp?: unknown } ).wp = {
			heartbeat: { interval, connectNow },
		};
		for ( const key of Object.keys( hooks ) ) {
			delete hooks[ key ];
		}
	} );
	afterEach( () => {
		delete ( window as { wp?: unknown } ).wp;
	} );

	function setup( intervalMs = 15000 ) {
		const beforeSend = jest.fn();
		const onPeer = jest.fn();
		const onPeerGone = jest.fn();
		const channel = createHeartbeatChannel( {
			postId: 7,
			clientId: 42,
			intervalMs,
			beforeSend,
			onPeer,
			onPeerGone,
		} );
		return { channel, beforeSend, onPeer, onPeerGone };
	}

	it( 'needs wp.heartbeat', () => {
		expect( isHeartbeatAvailable() ).toBe( true );
		delete ( window as { wp?: unknown } ).wp;
		expect( isHeartbeatAvailable() ).toBe( false );
		const { channel } = setup();
		channel.start();
		expect( hooks[ 'heartbeat.send' ] ).toBeUndefined();
	} );

	it( 'sets the interval, connects, and stamps the latest block on each send', () => {
		const { channel, beforeSend } = setup( 15000 );
		channel.start();
		expect( interval ).toHaveBeenCalledWith( 15 );
		expect( connectNow ).toHaveBeenCalled();

		// The publisher flushes inside beforeSend, so the send carries
		// what it just published.
		beforeSend.mockImplementation( () => channel.publish( 's1' ) );
		const data: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( data );
		expect( data[ HEARTBEAT_KEY ] ).toEqual( {
			post_id: 7,
			client_id: 42,
			block: 's1',
		} );

		beforeSend.mockImplementation( () => channel.publish( null ) );
		const next: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( next );
		expect( next[ HEARTBEAT_KEY ] ).toEqual( {
			post_id: 7,
			client_id: 42,
			block: null,
		} );

		channel.stop();
		expect( hooks[ 'heartbeat.send' ] ).toBeUndefined();
		expect( hooks[ 'heartbeat.tick' ] ).toBeUndefined();
	} );

	it( 'fans peers out of each tick and reports the ones that vanished', () => {
		const { channel, onPeer, onPeerGone } = setup( 15000 );
		channel.start();

		hooks[ 'heartbeat.tick' ]( {
			[ HEARTBEAT_KEY ]: {
				peers: [
					{
						client_id: 2,
						user: { id: 5, name: 'Riley', avatar: 'https://a/r' },
						block: 's1',
					},
					{ client_id: 3, user: { id: 6, name: 'Sam' }, block: null },
				],
			},
		} );
		expect( onPeer ).toHaveBeenCalledWith(
			'2',
			{ userId: 5, name: 'Riley', avatarUrl: 'https://a/r' },
			's1'
		);
		expect( onPeer ).toHaveBeenCalledWith(
			'3',
			{ userId: 6, name: 'Sam', avatarUrl: undefined },
			null
		);

		// A tick without our key (another plugin's beat) changes nothing.
		hooks[ 'heartbeat.tick' ]( {} );
		expect( onPeerGone ).not.toHaveBeenCalled();

		hooks[ 'heartbeat.tick' ]( {
			[ HEARTBEAT_KEY ]: { peers: [ { client_id: 3, block: 's2' } ] },
		} );
		expect( onPeerGone ).toHaveBeenCalledWith( '2' );
		expect( onPeer ).toHaveBeenLastCalledWith(
			'3',
			{ userId: null, name: '', avatarUrl: undefined },
			's2'
		);
		channel.stop();
	} );

	it( 're-arms the five-second fast mode on every tick', () => {
		const { channel } = setup( 5000 );
		channel.start();
		expect( interval ).toHaveBeenCalledTimes( 1 );
		hooks[ 'heartbeat.tick' ]( {} );
		hooks[ 'heartbeat.tick' ]( {} );
		expect( interval ).toHaveBeenCalledTimes( 3 );
		expect( interval ).toHaveBeenLastCalledWith( 5 );
		channel.stop();
	} );
} );
