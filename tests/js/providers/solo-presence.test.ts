/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';

type SoloPresenceModule = typeof import('../../../src/providers/solo-presence');

// Captured from inside the isolated module registry, so it drives the same
// hooks instance the module under test registered with.
let doAction: ( hookName: string, ...args: unknown[] ) => void;

interface TestWindow {
	_gutenbergSyncEnginesSettings?: {
		soloSession?: { room: string; token: string; othersPresent?: boolean };
	};
	wp?: { heartbeat?: { interval: () => void } };
}

const testWindow = window as unknown as TestWindow;

function setSettings( soloSession?: {
	room: string;
	token: string;
	othersPresent?: boolean;
} ): void {
	testWindow._gutenbergSyncEnginesSettings = soloSession
		? { soloSession }
		: {};
}

function setHeartbeat( present: boolean ): void {
	testWindow.wp = present ? { heartbeat: { interval: () => {} } } : {};
}

describe( 'solo-presence', () => {
	let presence: SoloPresenceModule;

	beforeEach( () => {
		jest.isolateModules( () => {
			presence = require( '../../../src/providers/solo-presence' );
			doAction = require( '@wordpress/hooks' ).doAction;
		} );
		setSettings( { room: 'postType/post:7', token: 'tab-token' } );
		setHeartbeat( true );
	} );

	it( 'is unavailable without injected settings', () => {
		setSettings( undefined );
		expect( presence.isSoloPresenceAvailable() ).toBe( false );
	} );

	it( 'is unavailable without the heartbeat API', () => {
		setHeartbeat( false );
		expect( presence.isSoloPresenceAvailable() ).toBe( false );
	} );

	it( 'is available with settings and heartbeat', () => {
		expect( presence.isSoloPresenceAvailable() ).toBe( true );
	} );

	it( 'starts from the page-load flag', () => {
		setSettings( {
			room: 'postType/post:7',
			token: 'tab-token',
			othersPresent: true,
		} );
		expect( presence.othersLikely() ).toBe( true );
	} );

	it( 'attaches the probe to outgoing heartbeats', () => {
		presence.onOthersArrived( () => {} );
		presence.setSyncClientId( 42 );

		const data: Record< string, unknown > = {};
		doAction( 'heartbeat.send', data );

		expect( data.gutenberg_sync_engines_presence ).toEqual( {
			room: 'postType/post:7',
			token: 'tab-token',
			client_id: 42,
		} );
	} );

	it( 'omits the client id until a session names one', () => {
		presence.onOthersArrived( () => {} );

		const data: Record< string, unknown > = {};
		doAction( 'heartbeat.send', data );

		expect( data.gutenberg_sync_engines_presence ).toEqual( {
			room: 'postType/post:7',
			token: 'tab-token',
		} );
	} );

	it( 'fires arrival callbacks when the answer flips to accompanied', () => {
		const callback = jest.fn();
		presence.onOthersArrived( callback );

		doAction( 'heartbeat.tick', {
			gutenberg_sync_engines_presence: { others: false },
		} );
		expect( callback ).not.toHaveBeenCalled();
		expect( presence.othersLikely() ).toBe( false );

		doAction( 'heartbeat.tick', {
			gutenberg_sync_engines_presence: { others: true },
		} );
		expect( callback ).toHaveBeenCalledTimes( 1 );
		expect( presence.othersLikely() ).toBe( true );

		// Still accompanied: no second firing.
		doAction( 'heartbeat.tick', {
			gutenberg_sync_engines_presence: { others: true },
		} );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears the belief when the answer reports alone again', () => {
		const callback = jest.fn();
		presence.onOthersArrived( callback );

		doAction( 'heartbeat.tick', {
			gutenberg_sync_engines_presence: { others: true },
		} );
		doAction( 'heartbeat.tick', {
			gutenberg_sync_engines_presence: { others: false },
		} );
		expect( presence.othersLikely() ).toBe( false );

		// A later arrival fires again.
		doAction( 'heartbeat.tick', {
			gutenberg_sync_engines_presence: { others: true },
		} );
		expect( callback ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'ignores heartbeat answers without this lane', () => {
		const callback = jest.fn();
		presence.onOthersArrived( callback );

		doAction( 'heartbeat.tick', { 'wp-refresh-post-lock': {} } );
		expect( callback ).not.toHaveBeenCalled();
	} );
} );
