/**
 * Internal dependencies
 */
import {
	attachCounters,
	observeTransport,
	diffCounters,
	deliveryTransport,
} from '../../benchmarks/transport/lib.mjs';

it( 'counts SSE bytes before response completion and identifies the transport', async () => {
	const handlers = {};
	const page = {
		on: jest.fn(),
		context: () => ( {
			newCDPSession: async () => ( {
				on: ( name, handler ) => {
					handlers[ name ] = handler;
				},
				send: jest.fn(),
			} ),
		} ),
	};
	const counters = attachCounters( page );
	await counters.ready;
	const before = counters.snapshot();
	handlers[ 'Network.responseReceived' ]( {
		requestId: '1',
		response: {
			url: 'http://site/?rest_route=%2Fwp-sync%2Fv1%2Fsse',
			status: 200,
			mimeType: 'text/event-stream',
		},
	} );
	handlers[ 'Network.dataReceived' ]( { requestId: '1', dataLength: 120 } );
	expect( observeTransport( counters ) ).toBe( 'sse' );
	expect(
		diffCounters( before, counters.snapshot(), 60000 )
			.responseBytesPerMinute
	).toBe( 120 );
	handlers[ 'Network.loadingFailed' ]( { requestId: '1' } );
	handlers[ 'Network.dataReceived' ]( { requestId: '1', dataLength: 120 } );
	expect( counters.snapshot().sseBytesReceived ).toBe( 120 );
} );

it( 'maps the current settings radio choices', () => {
	expect( deliveryTransport( 'sse' ) ).toBe( 'sse' );
	expect( deliveryTransport( 'long-polling' ) ).toBe( 'http-long-polling' );
	expect( deliveryTransport( 'polling-webrtc' ) ).toBe( 'http-polling' );
} );
