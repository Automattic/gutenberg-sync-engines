import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConflictBlockBody } from '../conflict-block';

describe( 'ConflictBlockBody', () => {
	it( 'shows the review action above a preview of the conflict', () => {
		render( <ConflictBlockBody onReview={ () => {} } /> );

		expect(
			screen.getByText( 'This block has conflicting edits.' )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Review conflict' } )
		).toBeVisible();

		// The preview shows the mock conflict's two versions with
		// add/remove highlighting: your version's text marked as added,
		// the current version's as removed.
		expect( screen.getByText( /adding something new/ ) ).toBeVisible();
		expect( screen.getByText( /This is my/ ) ).toBeVisible();
		expect( screen.getAllByRole( 'insertion' ) ).not.toHaveLength( 0 );
		expect( screen.getAllByRole( 'deletion' ) ).not.toHaveLength( 0 );
	} );

	it( 'Review conflict opens the review flow', async () => {
		const user = userEvent.setup();
		const onReview = jest.fn();
		render( <ConflictBlockBody onReview={ onReview } /> );

		await user.click(
			screen.getByRole( 'button', { name: 'Review conflict' } )
		);
		expect( onReview ).toHaveBeenCalled();
	} );
} );
