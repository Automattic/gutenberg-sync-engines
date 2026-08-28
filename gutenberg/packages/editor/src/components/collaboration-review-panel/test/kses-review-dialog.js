import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { KsesReviewDialogBody } from '../kses-review-dialog';
import { MOCK_KSES_NEW, MOCK_KSES_UPDATE } from '../mock-kses';

const noop = () => {};

describe( 'KsesReviewDialogBody', () => {
	describe( 'new-block proposal', () => {
		it( 'shows one proposed pane, without an original to compare', () => {
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_NEW }
					onApprove={ noop }
					onRemove={ noop }
				/>
			);

			expect( screen.getByText( 'Proposed block' ) ).toBeVisible();
			expect( screen.queryByText( 'Original' ) ).not.toBeInTheDocument();
			expect(
				screen.getByText( /<script>alert\(0\);<\/script>/ )
			).toBeVisible();
			expect( screen.queryAllByRole( 'insertion' ) ).toHaveLength( 0 );
			expect( screen.queryAllByRole( 'deletion' ) ).toHaveLength( 0 );
		} );

		it( 'Approve hands back the proposed markup', async () => {
			const user = userEvent.setup();
			const onApprove = jest.fn();
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_NEW }
					onApprove={ onApprove }
					onRemove={ noop }
				/>
			);

			await user.click(
				screen.getByRole( 'button', { name: 'Approve' } )
			);
			expect( onApprove ).toHaveBeenCalledWith( MOCK_KSES_NEW.proposed );
		} );

		it( 'Edit opens the markup as plain text, and Approve hands back the edited markup', async () => {
			const user = userEvent.setup();
			const onApprove = jest.fn();
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_NEW }
					onApprove={ onApprove }
					onRemove={ noop }
				/>
			);

			await user.click( screen.getByRole( 'button', { name: 'Edit' } ) );

			const textarea = screen.getByRole( 'textbox', {
				name: 'Proposed block HTML',
			} );
			expect( textarea ).toHaveValue( MOCK_KSES_NEW.proposed );

			await user.clear( textarea );
			await user.type( textarea, '<p>safe</p>' );
			await user.click(
				screen.getByRole( 'button', { name: 'Approve' } )
			);
			expect( onApprove ).toHaveBeenCalledWith( '<p>safe</p>' );
		} );

		it( 'Remove block asks for the block to be removed', async () => {
			const user = userEvent.setup();
			const onRemove = jest.fn();
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_NEW }
					onApprove={ noop }
					onRemove={ onRemove }
				/>
			);

			await user.click(
				screen.getByRole( 'button', { name: 'Remove block' } )
			);
			expect( onRemove ).toHaveBeenCalled();
		} );
	} );

	describe( 'update proposal', () => {
		it( 'shows the original and the proposal as a split diff', () => {
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_UPDATE }
					onApprove={ noop }
					onRemove={ noop }
				/>
			);

			expect( screen.getByText( 'Original' ) ).toBeVisible();
			expect( screen.getByText( 'Proposed block' ) ).toBeVisible();

			// The scenario's only change is alert(0) becoming
			// alert('changed'): the original pane keeps the removal, the
			// proposed pane the addition.
			const additions = screen
				.getAllByRole( 'insertion' )
				.map( ( node ) => node.textContent );
			expect( additions ).toEqual(
				expect.arrayContaining( [
					expect.stringContaining( 'changed' ),
				] )
			);

			const removals = screen
				.getAllByRole( 'deletion' )
				.map( ( node ) => node.textContent );
			expect( removals ).toEqual(
				expect.arrayContaining( [ expect.stringContaining( '0' ) ] )
			);
		} );

		it( 're-diffs the panes live while editing', async () => {
			const user = userEvent.setup();
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_UPDATE }
					onApprove={ noop }
					onRemove={ noop }
				/>
			);

			await user.click( screen.getByRole( 'button', { name: 'Edit' } ) );

			const textarea = screen.getByRole( 'textbox', {
				name: 'Proposed block HTML',
			} );
			await user.clear( textarea );
			await user.type( textarea, '<p>safe</p>' );

			const additions = screen
				.getAllByRole( 'insertion' )
				.map( ( node ) => node.textContent );
			expect( additions ).toEqual(
				expect.arrayContaining( [ expect.stringContaining( 'safe' ) ] )
			);
		} );

		it( 'Approve hands back the proposed markup', async () => {
			const user = userEvent.setup();
			const onApprove = jest.fn();
			render(
				<KsesReviewDialogBody
					sequestration={ MOCK_KSES_UPDATE }
					onApprove={ onApprove }
					onRemove={ noop }
				/>
			);

			await user.click(
				screen.getByRole( 'button', { name: 'Approve' } )
			);
			expect( onApprove ).toHaveBeenCalledWith(
				MOCK_KSES_UPDATE.proposed
			);
		} );
	} );
} );
