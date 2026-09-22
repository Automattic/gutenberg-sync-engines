import { render, screen } from '@testing-library/react';
import ReviewGroup from '../../../../src/ui/collaboration-review-panel/review-group';

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

const conflictItem = ( reason ) => ( {
	id: 'i1',
	unitId: 'u1',
	isLocal: false,
	actorId: 'a1',
	reason,
	intentType: 'insert_block',
	summary: 'core/html: <script>x</script>',
} );

describe( 'ReviewGroup', () => {
	afterEach( () => {
		delete window._gutenbergSyncEnginesSync;
	} );

	it( 'offers Adopt for ordinary conflicts regardless of capability', () => {
		window._gutenbergSyncEnginesSync = {
			engine: 'intent-log',
			engineProtocol: 1,
			canUnfilteredHtml: false,
		};
		render(
			<ReviewGroup
				items={ [ conflictItem( 'frame-conflict' ) ] }
				onResolve={ () => {} }
			/>
		);
		expect( screen.getByRole( 'button', { name: 'Adopt' } ) ).toBeTruthy();
	} );

	it( 'reserves Adopt of requires-approval conflicts for unfiltered_html holders', () => {
		window._gutenbergSyncEnginesSync = {
			engine: 'intent-log',
			engineProtocol: 1,
			canUnfilteredHtml: false,
		};
		render(
			<ReviewGroup
				items={ [ conflictItem( 'requires-approval' ) ] }
				onResolve={ () => {} }
			/>
		);
		expect( screen.queryByRole( 'button', { name: 'Adopt' } ) ).toBeNull();
		expect(
			screen.getByText( /Only someone allowed to publish unfiltered/ )
		).toBeTruthy();
		// Reject stays available to everyone.
		expect( screen.getByRole( 'button', { name: 'Reject' } ) ).toBeTruthy();
	} );

	it( 'explains that adopting a requires-approval conflict publishes under the adopter', () => {
		window._gutenbergSyncEnginesSync = {
			engine: 'intent-log',
			engineProtocol: 1,
			canUnfilteredHtml: true,
		};
		render(
			<ReviewGroup
				items={ [ conflictItem( 'requires-approval' ) ] }
				onResolve={ () => {} }
			/>
		);
		expect( screen.getByRole( 'button', { name: 'Adopt' } ) ).toBeTruthy();
		expect(
			screen.getByText( /publishes the content under your account/ )
		).toBeTruthy();
	} );

	it( 'summary-only renders no verbs: resolution lives at the inline block card', () => {
		render(
			<ReviewGroup
				items={ [ conflictItem( 'frame-conflict' ) ] }
				onResolve={ () => {} }
				summaryOnly
				onNavigate={ () => {} }
			/>
		);
		expect( screen.queryByRole( 'button', { name: 'Adopt' } ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Reject' } ) ).toBeNull();
		// The attribution remains a navigation link to the block.
		expect(
			screen.getByRole( 'button', {
				name: 'Go to the conflicted block',
			} )
		).toBeTruthy();
		expect( screen.getByText( /Lost content/ ) ).toBeTruthy();
	} );
} );
