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
 * The Heartbeat channel rides the advisory channel's discovery probe,
 * which the signaling lane attaches to `heartbeat.send` and reads back on
 * `heartbeat.tick`.
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
	isHeartbeatAvailable,
} from '../../../src/awareness/channels/heartbeat-channel';
import {
	HEARTBEAT_DATA_KEY,
	resetSignalingForTesting,
	setAdvisorySettingsForTesting,
	setSyncClientId,
} from '../../../src/providers/advisory/signaling';

describe( 'heartbeat channel', () => {
	let interval: jest.Mock;
	let connectNow: jest.Mock;

	beforeEach( () => {
		interval = jest.fn();
		connectNow = jest.fn();
		( window as { wp?: unknown } ).wp = {
			heartbeat: { interval, connectNow },
		};
		// The signaling lane installs its hooks once per module load, so
		// the hook table is left alone between tests.
		resetSignalingForTesting();
		setAdvisorySettingsForTesting( {
			room: 'postType/post:7',
			token: 't',
		} );
		setSyncClientId( 42 );
	} );
	afterEach( () => {
		delete ( window as { wp?: unknown } ).wp;
		resetSignalingForTesting();
	} );

	function setup( intervalMs = 15000 ) {
		const beforeSend = jest.fn();
		const onPeer = jest.fn();
		const onPeerGone = jest.fn();
		const channel = createHeartbeatChannel( {
			intervalMs,
			beforeSend,
			onPeer,
			onPeerGone,
		} );
		return { channel, beforeSend, onPeer, onPeerGone };
	}

	function answer( peers: unknown[] ): void {
		hooks[ 'heartbeat.tick' ]( {
			[ HEARTBEAT_DATA_KEY ]: { others: peers.length > 0, peers },
		} );
	}

	it( 'needs wp.heartbeat and the advisory probe', () => {
		expect( isHeartbeatAvailable() ).toBe( true );
		setAdvisorySettingsForTesting( null );
		expect( isHeartbeatAvailable() ).toBe( false );
		setAdvisorySettingsForTesting( {
			room: 'postType/post:7',
			token: 't',
		} );
		delete ( window as { wp?: unknown } ).wp;
		expect( isHeartbeatAvailable() ).toBe( false );
		const { channel } = setup();
		channel.start();
		expect( interval ).not.toHaveBeenCalled();
		expect( connectNow ).not.toHaveBeenCalled();
	} );

	it( 'sets the interval, connects, and stamps the latest block on each probe', () => {
		const { channel, beforeSend } = setup( 15000 );
		channel.start();
		expect( interval ).toHaveBeenCalledWith( 15 );
		expect( connectNow ).toHaveBeenCalled();

		// The publisher flushes inside beforeSend, so the probe carries
		// what it just published.
		beforeSend.mockImplementation( () => channel.publish( 's1' ) );
		const data: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( data );
		expect( data[ HEARTBEAT_DATA_KEY ] ).toMatchObject( {
			room: 'postType/post:7',
			token: 't',
			client_id: 42,
			block: 's1',
		} );

		beforeSend.mockImplementation( () => channel.publish( null ) );
		const next: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( next );
		expect( next[ HEARTBEAT_DATA_KEY ] ).toMatchObject( { block: null } );

		// Stopped: the probe carries no block any more.
		channel.stop();
		const after: Record< string, unknown > = {};
		hooks[ 'heartbeat.send' ]( after );
		expect( after[ HEARTBEAT_DATA_KEY ] ).not.toHaveProperty( 'block' );
	} );

	it( 'fans peers out of each answer and reports the ones that vanished', () => {
		const { channel, onPeer, onPeerGone } = setup( 15000 );
		channel.start();

		answer( [
			{
				token: 'a',
				client_id: 2,
				user_id: 5,
				block: 's1',
				name: 'Riley',
				avatar: 'https://a/r',
			},
			{
				token: 'b',
				client_id: 3,
				user_id: 6,
				block: null,
				name: 'Sam',
				avatar: '',
			},
			// A tab whose sync session has not started yet.
			{ token: 'c', client_id: 0, user_id: 7, block: 's9' },
		] );
		expect( onPeer ).toHaveBeenCalledTimes( 2 );
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

		// A tick without the advisory answer changes nothing.
		hooks[ 'heartbeat.tick' ]( {} );
		expect( onPeerGone ).not.toHaveBeenCalled();

		answer( [ { token: 'b', client_id: 3, user_id: 6, block: 's2' } ] );
		expect( onPeerGone ).toHaveBeenCalledWith( '2' );
		expect( onPeer ).toHaveBeenLastCalledWith(
			'3',
			{ userId: 6, name: '', avatarUrl: undefined },
			's2'
		);

		// Stopped: later answers reach nobody.
		channel.stop();
		answer( [] );
		expect( onPeerGone ).toHaveBeenCalledTimes( 1 );
	} );

	it( 're-arms the five-second fast mode on every answer', () => {
		const { channel } = setup( 5000 );
		channel.start();
		expect( interval ).toHaveBeenCalledTimes( 1 );
		answer( [] );
		answer( [] );
		expect( interval ).toHaveBeenCalledTimes( 3 );
		expect( interval ).toHaveBeenLastCalledWith( 5 );
		channel.stop();
	} );
} );
