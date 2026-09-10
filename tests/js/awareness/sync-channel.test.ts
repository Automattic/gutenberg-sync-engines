/**
 * External dependencies
 */
import { describe, expect, it, jest } from '@jest/globals';

/**
 * Internal dependencies
 */
import {
	areBlocksEqual,
	BLOCK_FIELD,
	createSyncChannel,
	suppressRealtimeSelection,
} from '../../../src/awareness/channels/sync-channel';
import type {
	AwarenessHost,
	AwarenessPeerState,
} from '../../../src/awareness/channels/sync-channel';
import { registerAwareness } from '../../../src/awareness/registry';

/**
 * A fake typed awareness: a local state, per-field equality checks, and a
 * subscriber list.
 */
function fakeAwareness() {
	const local: Record< string, unknown > = {};
	const subscribers: Array< ( states: AwarenessPeerState[] ) => void > = [];
	const host: AwarenessHost & {
		emit: ( states: AwarenessPeerState[] ) => void;
	} = {
		clientID: 1,
		setUp: jest.fn(),
		equalityFieldChecks: {},
		getLocalState: () => local,
		setLocalStateField: ( field, value ) => {
			local[ field ] = value;
		},
		onStateChange: ( callback ) => {
			subscribers.push( callback );
			return () => {
				subscribers.splice( subscribers.indexOf( callback ), 1 );
			};
		},
		emit: ( states ) => subscribers.forEach( ( cb ) => cb( states ) ),
	};
	return { host, local, subscribers };
}

const peer = (
	clientId: number,
	block: string | null | undefined,
	extra: Partial< AwarenessPeerState > = {}
): AwarenessPeerState => ( {
	clientId,
	isMe: false,
	isConnected: true,
	collaboratorInfo: {
		id: 100 + clientId,
		name: `User ${ clientId }`,
		avatar_urls: { '48': `https://a/${ clientId }` },
	},
	gseBlock: block,
	...extra,
} );

describe( 'sync channel', () => {
	it( 'treats absent and null blocks alike', () => {
		expect( areBlocksEqual( undefined, null ) ).toBe( true );
		expect( areBlocksEqual( 's1', 's1' ) ).toBe( true );
		expect( areBlocksEqual( 's1', 's2' ) ).toBe( false );
		expect( areBlocksEqual( 's1', undefined ) ).toBe( false );
	} );

	it( 'publishes the block as one awareness field and drops the live cursor', () => {
		const { host, local } = fakeAwareness();
		local.editorState = { selection: { type: 'cursor' } };
		const channel = createSyncChannel( {
			awareness: host,
			onPeer: jest.fn(),
			onPeerGone: jest.fn(),
		} );

		channel.start();
		expect( host.setUp ).toHaveBeenCalled();
		expect( host.equalityFieldChecks?.[ BLOCK_FIELD ] ).toBe(
			areBlocksEqual
		);
		expect( local.editorState ).toBeUndefined();

		// The framework's cursor publisher is silenced while active.
		host.setLocalStateField( 'editorState', { selection: {} } );
		expect( local.editorState ).toBeUndefined();

		channel.publish( 's1' );
		expect( local[ BLOCK_FIELD ] ).toBe( 's1' );
		channel.publish( null );
		expect( local[ BLOCK_FIELD ] ).toBeNull();

		channel.stop();
		expect( local[ BLOCK_FIELD ] ).toBeUndefined();
		host.setLocalStateField( 'editorState', { selection: {} } );
		expect( local.editorState ).toEqual( { selection: {} } );
	} );

	it( 'reports every connected peer’s block and departures', () => {
		const { host } = fakeAwareness();
		const onPeer = jest.fn();
		const onPeerGone = jest.fn();
		const channel = createSyncChannel( {
			awareness: host,
			onPeer,
			onPeerGone,
		} );
		channel.start();

		host.emit( [
			peer( 1, 'mine', { isMe: true } ),
			peer( 2, 's1' ),
			peer( 3, undefined ),
			peer( 4, 's4', { isConnected: false } ),
		] );
		expect( onPeer ).toHaveBeenCalledTimes( 2 );
		expect( onPeer ).toHaveBeenCalledWith(
			'2',
			{ userId: 102, name: 'User 2', avatarUrl: 'https://a/2' },
			's1'
		);
		expect( onPeer ).toHaveBeenCalledWith(
			'3',
			{ userId: 103, name: 'User 3', avatarUrl: 'https://a/3' },
			null
		);
		expect( onPeerGone ).not.toHaveBeenCalled();

		// Peer 3 leaves; peer 4 reconnects.
		host.emit( [ peer( 2, 's2' ), peer( 4, 's4' ) ] );
		expect( onPeerGone ).toHaveBeenCalledWith( '3' );
		expect( onPeer ).toHaveBeenLastCalledWith(
			'4',
			{ userId: 104, name: 'User 4', avatarUrl: 'https://a/4' },
			's4'
		);
		channel.stop();
	} );

	it( 'restores the original setter', () => {
		const { host, local } = fakeAwareness();
		const restore = suppressRealtimeSelection( host );
		host.setLocalStateField( 'editorState', 'x' );
		expect( local.editorState ).toBeUndefined();
		restore();
		host.setLocalStateField( 'editorState', 'x' );
		expect( local.editorState ).toBe( 'x' );
	} );

	it( 'installs the equality check on every registered instance', () => {
		const { host } = fakeAwareness();
		registerAwareness( 'postType/post', '7', host );
		expect( host.equalityFieldChecks?.[ BLOCK_FIELD ] ).toBe(
			areBlocksEqual
		);
		// Missing or falsy instances are ignored.
		registerAwareness( 'postType/post', '8', undefined );
	} );
} );
