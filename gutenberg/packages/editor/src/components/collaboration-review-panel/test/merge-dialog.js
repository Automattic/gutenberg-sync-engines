import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MergeDialogBody } from '../merge-dialog';

const description = ( overrides = {} ) => ( {
	baseText: 'Hello world',
	proposedText: 'Hello world 123',
	currentText: 'abc Hello world ',
	runs: [ { kind: 'insert', text: '123' } ],
	...overrides,
} );

describe( 'MergeDialogBody', () => {
	it( 'renders both panes and all three verbs when every text is known', () => {
		render(
			<MergeDialogBody
				description={ description() }
				isStale={ false }
				onKeepCurrent={ () => {} }
				onRestoreMine={ () => {} }
				onApplyMerged={ () => {} }
			/>
		);
		expect( screen.getByText( 'Your version' ) ).toBeVisible();
		expect( screen.getByText( 'Current version' ) ).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Keep current version' } )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Restore my version' } )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Edit a merged result' } )
		).toBeVisible();
	} );

	it( 'degrades to two panes when the base is unknown', () => {
		render(
			<MergeDialogBody
				description={ description( { baseText: null } ) }
				isStale={ false }
				onKeepCurrent={ () => {} }
				onRestoreMine={ () => {} }
				onApplyMerged={ () => {} }
			/>
		);
		expect( screen.getByText( 'Your version' ) ).toBeVisible();
		expect( screen.getByText( 'Current version' ) ).toBeVisible();
	} );

	it( 'lists lost-content runs and hides Restore when the intended text is unknown', () => {
		render(
			<MergeDialogBody
				description={ description( {
					baseText: null,
					proposedText: null,
				} ) }
				isStale={ false }
				onKeepCurrent={ () => {} }
				onRestoreMine={ () => {} }
				onApplyMerged={ () => {} }
			/>
		);
		expect( screen.getByText( 'Your set-aside changes' ) ).toBeVisible();
		expect( screen.getByText( '123' ) ).toBeVisible();
		expect(
			screen.queryByRole( 'button', { name: 'Restore my version' } )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Keep current version' } )
		).toBeVisible();
	} );

	it( 'Restore and Keep call their verbs', async () => {
		const user = userEvent.setup();
		const onKeepCurrent = jest.fn();
		const onRestoreMine = jest.fn();
		render(
			<MergeDialogBody
				description={ description() }
				isStale={ false }
				onKeepCurrent={ onKeepCurrent }
				onRestoreMine={ onRestoreMine }
				onApplyMerged={ () => {} }
			/>
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Restore my version' } )
		);
		expect( onRestoreMine ).toHaveBeenCalled();
		await user.click(
			screen.getByRole( 'button', { name: 'Keep current version' } )
		);
		expect( onKeepCurrent ).toHaveBeenCalled();
	} );

	it( 'the merge editor seeds with the current text and applies the edited result', async () => {
		const user = userEvent.setup();
		const onApplyMerged = jest.fn();
		render(
			<MergeDialogBody
				description={ description( { currentText: 'seed text' } ) }
				isStale={ false }
				onKeepCurrent={ () => {} }
				onRestoreMine={ () => {} }
				onApplyMerged={ onApplyMerged }
			/>
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Edit a merged result' } )
		);
		const textarea = screen.getByRole( 'textbox', {
			name: 'Merged result',
		} );
		expect( textarea ).toHaveValue( 'seed text' );
		// Editing replaces Restore with Apply.
		expect(
			screen.queryByRole( 'button', { name: 'Restore my version' } )
		).not.toBeInTheDocument();
		await user.clear( textarea );
		await user.type( textarea, 'merged by hand' );
		await user.click(
			screen.getByRole( 'button', { name: 'Apply merged result' } )
		);
		expect( onApplyMerged ).toHaveBeenCalledWith( 'merged by hand' );
	} );

	it( 'seeds the merge editor from the html form when the engine supplies one', async () => {
		const user = userEvent.setup();
		render(
			<MergeDialogBody
				description={ description( {
					currentHtml:
						'<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
				} ) }
				isStale={ false }
				onKeepCurrent={ () => {} }
				onRestoreMine={ () => {} }
				onApplyMerged={ () => {} }
			/>
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Edit a merged result' } )
		);
		expect(
			screen.getByRole( 'textbox', { name: 'Merged result' } )
		).toHaveValue( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
	} );

	it( 'shows the refreshed-panes warning after a stale confirm', () => {
		render(
			<MergeDialogBody
				description={ description() }
				isStale
				onKeepCurrent={ () => {} }
				onRestoreMine={ () => {} }
				onApplyMerged={ () => {} }
			/>
		);
		// The Notice renders its message twice (content plus the polite
		// live region), so assert presence rather than uniqueness.
		expect(
			screen.getAllByText(
				/The document changed while this dialog was open/
			).length
		).toBeGreaterThan( 0 );
	} );
} );
