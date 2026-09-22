import { fireEvent, render, screen } from '@testing-library/react';
import { BlockCardBody } from '../../../../src/ui/collaboration-review-panel/markers';

// The components package cannot load under this Jest setup (an ESM
// dependency); a plain button carries what these tests need.
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, label, ...props } ) => (
		<button
			type="button"
			onClick={ onClick }
			aria-label={ label }
			data-variant={ props.variant }
		>
			{ children }
		</button>
	),
	Popover: ( { children } ) => <div>{ children }</div>,
} ) );
// The editor stores pull in the components package too; the tests only
// need the store names.
jest.mock( '@wordpress/editor', () => ( { store: 'core/editor' } ) );
jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

const item = ( overrides = {} ) => ( {
	id: 'i1',
	unitId: 'u1',
	isLocal: false,
	actorId: 'a1',
	reason: 'frame-conflict',
	intentType: 'proposal',
	summary: 'lost words',
	...overrides,
} );

describe( 'BlockCardBody', () => {
	afterEach( () => {
		delete window._gutenbergSyncEnginesSync;
	} );

	it( 'renders ONE merged task with the two verbs and no count chip', () => {
		render(
			<BlockCardBody
				groups={ [
					[ item() ],
					[ item( { id: 'i2', unitId: 'u2', summary: 'more' } ) ],
				] }
				onResolve={ () => {} }
			/>
		);
		// One card: one Adopt, one Reject — never per-group verb pairs.
		expect(
			screen.getAllByRole( 'button', { name: 'Adopt' } )
		).toHaveLength( 1 );
		expect(
			screen.getAllByRole( 'button', { name: 'Reject' } )
		).toHaveLength( 1 );
		// The merged summary carries both groups' content; no numeric badge.
		expect( screen.getByText( /lost words more/ ) ).toBeTruthy();
		expect( screen.queryByText( '2' ) ).toBeNull();
	} );

	it( 'Adopt resolves every item on the block as restored', async () => {
		const onResolve = jest.fn();
		const items = [ item(), item( { id: 'i2', unitId: 'u2' } ) ];
		render(
			<BlockCardBody
				groups={ [ [ items[ 0 ] ], [ items[ 1 ] ] ] }
				onResolve={ onResolve }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Adopt' } ) );
		expect( onResolve ).toHaveBeenCalledWith( items, 'restored' );
	} );

	it( 'Reject resolves every item on the block as dismissed', async () => {
		const onResolve = jest.fn();
		const items = [ item(), item( { id: 'i2', unitId: 'u2' } ) ];
		render(
			<BlockCardBody
				groups={ [ [ items[ 0 ] ], [ items[ 1 ] ] ] }
				onResolve={ onResolve }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Reject' } ) );
		expect( onResolve ).toHaveBeenCalledWith( items, 'dismissed' );
	} );

	it( 'attributes local pending edits as yours', () => {
		render(
			<BlockCardBody
				groups={ [ [ item( { isLocal: true } ) ] ] }
				onResolve={ () => {} }
			/>
		);
		expect(
			screen.getByText( /Your edit on this block is pending/ )
		).toBeTruthy();
	} );

	it( 'reserves Adopt for unfiltered_html holders when any item requires approval', () => {
		window._gutenbergSyncEnginesSync = {
			engine: 'intent-log',
			engineProtocol: 1,
			canUnfilteredHtml: false,
		};
		render(
			<BlockCardBody
				groups={ [
					[ item() ],
					[
						item( {
							id: 'i2',
							unitId: 'u2',
							reason: 'requires-approval',
						} ),
					],
				] }
				onResolve={ () => {} }
			/>
		);
		expect( screen.queryByRole( 'button', { name: 'Adopt' } ) ).toBeNull();
		expect(
			screen.getByText( /Only someone allowed to publish unfiltered/ )
		).toBeTruthy();
		expect( screen.getByRole( 'button', { name: 'Reject' } ) ).toBeTruthy();
	} );
} );
