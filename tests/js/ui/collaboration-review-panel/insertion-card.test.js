import { render, screen } from '@testing-library/react';
import { InsertionCardBody } from '../../../../src/ui/collaboration-review-panel/markers';

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

const insertionItem = () => ( {
	id: 'ins-1',
	unitId: 'ins-1',
	isLocal: false,
	actorId: 'a1',
	reason: 'requires-approval',
	intentType: 'insert_block',
	proposedInsertion: {
		blockType: 'core/html',
		html: '<script>alert(1)</script>',
		afterSiblingId: 'p1',
	},
} );

describe( 'InsertionCardBody', () => {
	afterEach( () => {
		delete window._gutenbergSyncEnginesSync;
	} );

	it( 'previews the proposed markup as inert text, never live DOM', () => {
		render(
			<InsertionCardBody
				item={ insertionItem() }
				onResolve={ () => {} }
			/>
		);
		// Finding the literal tag as TEXT proves it was not parsed into a
		// live element — an innerHTML'd script would not have a matching
		// text node.
		expect( screen.getByText( '<script>alert(1)</script>' ) ).toBeTruthy();
	} );

	it( 'gates Approve on the unfiltered_html capability; Discard is universal', () => {
		window._gutenbergSyncEnginesSync = {
			engine: 'intent-log',
			engineProtocol: 1,
			canUnfilteredHtml: false,
		};
		const { rerender } = render(
			<InsertionCardBody
				item={ insertionItem() }
				onResolve={ () => {} }
			/>
		);
		expect(
			screen.queryByRole( 'button', { name: 'Approve' } )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Discard' } )
		).toBeTruthy();

		window._gutenbergSyncEnginesSync = {
			engine: 'intent-log',
			engineProtocol: 1,
			canUnfilteredHtml: true,
		};
		rerender(
			<InsertionCardBody
				item={ insertionItem() }
				onResolve={ () => {} }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: 'Approve' } )
		).toBeTruthy();
	} );
} );
