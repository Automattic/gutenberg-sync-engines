import { describe, expect, it, jest } from '@jest/globals';
import { render, screen } from '@testing-library/react';
import AvatarGroup from '../../../../src/ui/collaborators-presence/avatar-group';
import Avatar from '../../../../src/ui/collaborators-presence/avatar';

// The components package cannot load under this Jest setup (an ESM
// dependency); the group only needs plain children.
jest.mock( '@wordpress/components', () => ( {
	Icon: () => null,
	Tooltip: ( { children }: { children: React.ReactNode } ) => children,
} ) );

describe( 'AvatarGroup', () => {
	it( 'should render all children when count is within max', () => {
		render(
			<AvatarGroup>
				<Avatar name="Alice" />
				<Avatar name="Bob" />
			</AvatarGroup>
		);
		expect( screen.getByRole( 'img', { name: 'Alice' } ) ).toBeTruthy();
		expect( screen.getByRole( 'img', { name: 'Bob' } ) ).toBeTruthy();
		expect( screen.queryByText( /^\+/ ) ).toBeNull();
	} );

	it( 'should show overflow indicator when children exceed max', () => {
		render(
			<AvatarGroup max={ 2 }>
				<Avatar name="Alice" />
				<Avatar name="Bob" />
				<Avatar name="Charlie" />
				<Avatar name="Diana" />
			</AvatarGroup>
		);
		expect( screen.getByRole( 'img', { name: 'Alice' } ) ).toBeTruthy();
		expect( screen.getByRole( 'img', { name: 'Bob' } ) ).toBeTruthy();
		expect( screen.queryByRole( 'img', { name: 'Charlie' } ) ).toBeNull();
		const overflow = screen.getByText( '+2' );
		expect( overflow ).toBeTruthy();
		expect( overflow.getAttribute( 'aria-label' ) ).toBe(
			'2 more collaborators'
		);
	} );

	it( 'should use singular form for one overflow collaborator', () => {
		render(
			<AvatarGroup max={ 2 }>
				<Avatar name="Alice" />
				<Avatar name="Bob" />
				<Avatar name="Charlie" />
			</AvatarGroup>
		);
		const overflow = screen.getByText( '+1' );
		expect( overflow.getAttribute( 'aria-label' ) ).toBe(
			'1 more collaborator'
		);
	} );

	it( 'should default max to 3', () => {
		render(
			<AvatarGroup>
				<Avatar name="A" />
				<Avatar name="B" />
				<Avatar name="C" />
				<Avatar name="D" />
				<Avatar name="E" />
			</AvatarGroup>
		);
		expect( screen.getByRole( 'img', { name: 'A' } ) ).toBeTruthy();
		expect( screen.getByRole( 'img', { name: 'B' } ) ).toBeTruthy();
		expect( screen.getByRole( 'img', { name: 'C' } ) ).toBeTruthy();
		expect( screen.queryByRole( 'img', { name: 'D' } ) ).toBeNull();
		expect( screen.getByText( '+2' ) ).toBeTruthy();
	} );

	it( 'should not show overflow when children equal max', () => {
		render(
			<AvatarGroup max={ 3 }>
				<Avatar name="A" />
				<Avatar name="B" />
				<Avatar name="C" />
			</AvatarGroup>
		);
		expect( screen.queryByText( /^\+/ ) ).toBeNull();
	} );

	it( 'should not show overflow when children are fewer than max', () => {
		render(
			<AvatarGroup max={ 5 }>
				<Avatar name="A" />
				<Avatar name="B" />
			</AvatarGroup>
		);
		expect( screen.queryByText( /^\+/ ) ).toBeNull();
	} );

	it( 'should combine custom className with default class', () => {
		render(
			<AvatarGroup data-testid="group" className="custom">
				<Avatar name="A" />
			</AvatarGroup>
		);
		const group = screen.getByTestId( 'group' );
		expect( group.classList.contains( 'editor-avatar-group' ) ).toBe(
			true
		);
		expect( group.classList.contains( 'custom' ) ).toBe( true );
	} );

	it( 'should have group role and support aria-label', () => {
		render(
			<AvatarGroup aria-label="Collaborators">
				<Avatar name="A" />
			</AvatarGroup>
		);
		const group = screen.getByRole( 'group', {
			name: 'Collaborators',
		} );
		expect( group ).toBeTruthy();
	} );

	it( 'should render with no children', () => {
		render( <AvatarGroup data-testid="group" /> );
		const group = screen.getByTestId( 'group' );
		expect( group ).toBeTruthy();
		expect( screen.queryByText( /^\+/ ) ).toBeNull();
	} );
} );
