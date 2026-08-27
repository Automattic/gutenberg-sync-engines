import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MergeDialogBody } from '../merge-dialog';

const props = {
	baseText: 'paragraph',
	yourText: 'paragraph - adding something new',
	currentText: 'This is my paragraph.',
};

const mergedResult = () =>
	screen.getByRole( 'textbox', { name: 'Merged result' } );

describe( 'MergeDialogBody', () => {
	it( 'shows both versions, with the merged result seeded from the current version', () => {
		render(
			<MergeDialogBody
				{ ...props }
				onAccept={ () => {} }
				onCancel={ () => {} }
			/>
		);

		expect( screen.getByText( 'Your version' ) ).toBeVisible();
		expect( screen.getByText( 'Current version' ) ).toBeVisible();
		expect(
			screen.getAllByRole( 'button', { name: 'Restore this version' } )
		).toHaveLength( 2 );
		expect( mergedResult() ).toHaveValue( props.currentText );
	} );

	it( 'diffs each version against the shared base, not against each other', () => {
		render(
			<MergeDialogBody
				{ ...props }
				onAccept={ () => {} }
				onCancel={ () => {} }
			/>
		);

		// Each pane highlights only its own additions over the base
		// ("paragraph"): " - adding something new" on your side, "This is
		// my " and "." on the current side. The diff is whitespace
		// sensitive, so an added space is part of the highlight. Nothing
		// reads as removed.
		const additions = screen
			.getAllByRole( 'insertion' )
			.map( ( node ) => node.textContent );
		expect( additions ).toEqual(
			expect.arrayContaining( [
				expect.stringContaining( ' - adding something new' ),
				expect.stringContaining( 'This is my' ),
			] )
		);
		expect( screen.queryAllByRole( 'deletion' ) ).toHaveLength( 0 );
	} );

	it( 'Restore this version copies that version into the merged result', async () => {
		const user = userEvent.setup();
		render(
			<MergeDialogBody
				{ ...props }
				onAccept={ () => {} }
				onCancel={ () => {} }
			/>
		);

		// Your version is the left pane, the current version the right.
		const [ restoreYours, restoreCurrent ] = screen.getAllByRole(
			'button',
			{ name: 'Restore this version' }
		);

		await user.click( restoreYours );
		expect( mergedResult() ).toHaveValue( props.yourText );

		await user.click( restoreCurrent );
		expect( mergedResult() ).toHaveValue( props.currentText );
	} );

	it( 'Accept hands back the merged result, hand edits included', async () => {
		const user = userEvent.setup();
		const onAccept = jest.fn();
		render(
			<MergeDialogBody
				{ ...props }
				onAccept={ onAccept }
				onCancel={ () => {} }
			/>
		);

		await user.clear( mergedResult() );
		await user.type( mergedResult(), 'merged by hand' );
		await user.click( screen.getByRole( 'button', { name: 'Accept' } ) );
		expect( onAccept ).toHaveBeenCalledWith( 'merged by hand' );
	} );

	it( 'Accept after a restore hands back that version', async () => {
		const user = userEvent.setup();
		const onAccept = jest.fn();
		render(
			<MergeDialogBody
				{ ...props }
				onAccept={ onAccept }
				onCancel={ () => {} }
			/>
		);

		const [ restoreYours ] = screen.getAllByRole( 'button', {
			name: 'Restore this version',
		} );
		await user.click( restoreYours );
		await user.click( screen.getByRole( 'button', { name: 'Accept' } ) );
		expect( onAccept ).toHaveBeenCalledWith( props.yourText );
	} );

	it( 'Cancel closes without accepting', async () => {
		const user = userEvent.setup();
		const onAccept = jest.fn();
		const onCancel = jest.fn();
		render(
			<MergeDialogBody
				{ ...props }
				onAccept={ onAccept }
				onCancel={ onCancel }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onCancel ).toHaveBeenCalled();
		expect( onAccept ).not.toHaveBeenCalled();
	} );
} );
