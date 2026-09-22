import { describe, expect, it } from '@jest/globals';
import { render, screen, fireEvent } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import Avatar from '../../../../src/ui/collaborators-presence/avatar';

// The components package cannot load under this Jest setup (an ESM
// dependency). The tooltip mock keeps the contract the avatar relies on:
// the tooltip text shows only while the child is hovered.
jest.mock( '@wordpress/components', () => {
	const { createElement, cloneElement, useState } =
		jest.requireActual< typeof import('@wordpress/element') >(
			'@wordpress/element'
		);
	const Tooltip = ( {
		text,
		children,
	}: {
		text: string;
		children: ReactElement;
	} ) => {
		const [ open, setOpen ] = useState( false );
		return createElement(
			'span',
			{ 'data-tooltip-open': open ? 'true' : 'false' },
			cloneElement( children, {
				onMouseEnter: () => setOpen( true ),
				onMouseLeave: () => setOpen( false ),
			} ),
			open ? createElement( 'div', { role: 'tooltip' }, text ) : null
		);
	};
	return {
		Icon: ( { icon }: { icon: ReactNode } ) => icon ?? null,
		Tooltip,
	};
} );

/**
 * Hovers an element the way a pointer would, for the tooltip mock.
 *
 * @param element The element to hover.
 */
async function hover( element: Element ): Promise< void > {
	fireEvent.mouseEnter( element );
	await Promise.resolve();
}

/**
 * Renders an avatar. The tooltip is mocked above, so no provider or delay
 * settings are needed.
 *
 * @param ui The avatar element (or anything else) to render.
 */
function renderAvatar( ui: React.ReactElement ): ReturnType< typeof render > {
	return render( ui );
}

/**
 * In JSDOM, `<img>` elements never fire `load` or `error` events on their
 * own. We simulate them using `fireEvent` on the `<img>` element, which we
 * locate via `getByAltText('')` (the `<img>` has `alt=""`).
 */

describe( 'Avatar', () => {
	it( 'should render with default props', () => {
		render( <Avatar data-testid="avatar" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar ).toBeTruthy();
		expect( avatar.tagName ).toBe( 'DIV' );
		expect( avatar.classList.contains( 'editor-avatar' ) ).toBe( true );
	} );

	it( 'should set the accessible name from the name prop', () => {
		render( <Avatar name="Jane Doe" /> );
		const avatar = screen.getByRole( 'img', { name: 'Jane Doe' } );
		expect( avatar ).toBeTruthy();
	} );

	it( 'should not set role or aria-label without a name', () => {
		render( <Avatar data-testid="avatar" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.hasAttribute( 'role' ) ).toBe( false );
		expect( avatar.hasAttribute( 'aria-label' ) ).toBe( false );
	} );

	it( 'should render an img element when src is provided', () => {
		render(
			<Avatar
				data-testid="avatar"
				name="Jane Doe"
				src="https://example.com/avatar.jpg"
			/>
		);
		// The <img> should be in the DOM (hidden until loaded).
		const img = screen.getByAltText( '' );
		expect( img.tagName ).toBe( 'IMG' );
		expect( img.getAttribute( 'src' ) ).toBe(
			'https://example.com/avatar.jpg'
		);
	} );

	it( 'should apply has-src class only after image loads', () => {
		render(
			<Avatar
				data-testid="avatar"
				name="Jane Doe"
				src="https://example.com/avatar.jpg"
			/>
		);
		const avatar = screen.getByTestId( 'avatar' );
		// Before load fires, has-src should not be set.
		expect( avatar.classList.contains( 'has-src' ) ).toBe( false );

		// Simulate image load.
		fireEvent.load( screen.getByAltText( '' ) );
		expect( avatar.classList.contains( 'has-src' ) ).toBe( true );
	} );

	it( 'should apply is-small class for small size', () => {
		render( <Avatar data-testid="avatar" size="small" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.classList.contains( 'is-small' ) ).toBe( true );
	} );

	it( 'should not apply is-small class for default size', () => {
		render( <Avatar data-testid="avatar" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.classList.contains( 'is-small' ) ).toBe( false );
	} );

	it( 'should apply border color when provided', () => {
		render( <Avatar data-testid="avatar" borderColor="#3858e9" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.classList.contains( 'has-avatar-border-color' ) ).toBe(
			true
		);
		expect(
			avatar.style.getPropertyValue( '--editor-avatar-outline-color' )
		).toBe( '#3858e9' );
	} );

	it( 'should set name color custom property when borderColor is provided', () => {
		render( <Avatar data-testid="avatar" borderColor="#3858e9" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect(
			avatar.style.getPropertyValue( '--editor-avatar-name-color' )
		).toBeTruthy();
	} );

	it( 'should not have has-src class when src is not provided', () => {
		render( <Avatar data-testid="avatar" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.classList.contains( 'has-src' ) ).toBe( false );
	} );

	it( 'should combine custom className with default class', () => {
		render( <Avatar data-testid="avatar" className="custom" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.classList.contains( 'editor-avatar' ) ).toBe( true );
		expect( avatar.classList.contains( 'custom' ) ).toBe( true );
	} );

	it( 'should pass through additional HTML attributes', () => {
		render( <Avatar data-testid="avatar" data-custom="value" /> );
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.getAttribute( 'data-custom' ) ).toBe( 'value' );
	} );

	it( 'should merge style prop with custom properties', () => {
		render(
			<Avatar
				data-testid="avatar"
				borderColor="#3858e9"
				style={ { left: '10px' } }
			/>
		);
		const avatar = screen.getByTestId( 'avatar' );
		expect( avatar.style ).toMatchObject( { left: '10px' } );
		expect(
			avatar.style.getPropertyValue( '--editor-avatar-outline-color' )
		).toBe( '#3858e9' );
	} );

	describe( 'variant: badge', () => {
		it( 'should not show badge by default', () => {
			render( <Avatar data-testid="avatar" name="Zoraya" /> );
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'is-badge' ) ).toBe( false );
			expect( screen.queryByText( 'Zoraya' ) ).toBeNull();
		} );

		it( 'should render name span with badge variant', () => {
			render(
				<Avatar data-testid="avatar" name="Zoraya" variant="badge" />
			);
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'is-badge' ) ).toBe( true );
			expect( screen.getByText( 'Zoraya' ) ).toBeTruthy();
		} );

		it( 'should render name span with borderColor too', () => {
			render(
				<Avatar
					data-testid="avatar"
					name="Zoraya"
					borderColor="#3d5eef"
					variant="badge"
				/>
			);
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'is-badge' ) ).toBe( true );
			expect( screen.getByText( 'Zoraya' ) ).toBeTruthy();
		} );

		it( 'should not show badge when name is missing', () => {
			render( <Avatar data-testid="avatar" variant="badge" /> );
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'is-badge' ) ).toBe( false );
		} );

		it( 'should still set aria-label even when badge is visible', () => {
			render( <Avatar name="Zoraya" variant="badge" /> );
			const avatar = screen.getByRole( 'img', { name: 'Zoraya' } );
			expect( avatar ).toBeTruthy();
		} );
	} );

	describe( 'label', () => {
		it( 'should show label text instead of name in the badge', () => {
			render( <Avatar name="Jane Doe" label="You" variant="badge" /> );
			expect( screen.getByText( 'You' ) ).toBeTruthy();
			expect( screen.queryByText( 'Jane Doe' ) ).toBeNull();
		} );

		it( 'should keep aria-label as name when label is provided', () => {
			render( <Avatar name="Jane Doe" label="You" variant="badge" /> );
			const avatar = screen.getByRole( 'img', { name: 'Jane Doe' } );
			expect( avatar ).toBeTruthy();
		} );

		it( 'should wrap in tooltip when label differs from name', async () => {
			renderAvatar(
				<Avatar name="Jane Doe" label="You" variant="badge" />
			);
			await hover( screen.getByRole( 'img', { name: 'Jane Doe' } ) );
			expect( await screen.findByText( 'Jane Doe' ) ).toBeTruthy();
		} );
	} );

	describe( 'dimmed', () => {
		it( 'should apply is-dimmed class when dimmed', () => {
			render( <Avatar data-testid="avatar" dimmed /> );
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'is-dimmed' ) ).toBe( true );
		} );

		it( 'should not apply is-dimmed class by default', () => {
			render( <Avatar data-testid="avatar" /> );
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'is-dimmed' ) ).toBe( false );
		} );

		it( 'should render statusIndicator when dimmed', () => {
			render(
				<Avatar
					data-testid="avatar"
					dimmed
					statusIndicator={ <span>icon</span> }
				/>
			);
			expect( screen.getByText( 'icon' ) ).toBeTruthy();
		} );

		it( 'should not render statusIndicator when not dimmed', () => {
			render(
				<Avatar
					data-testid="avatar"
					statusIndicator={ <span>icon</span> }
				/>
			);
			expect( screen.queryByText( 'icon' ) ).toBeNull();
		} );

		it( 'should apply has-src class when dimmed after image loads', () => {
			render(
				<Avatar
					data-testid="avatar"
					src="https://example.com/avatar.jpg"
					dimmed
				/>
			);
			fireEvent.load( screen.getByAltText( '' ) );
			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'has-src' ) ).toBe( true );
			expect( avatar.classList.contains( 'is-dimmed' ) ).toBe( true );
		} );
	} );

	describe( 'initials', () => {
		it( 'should show initials when no src is provided', () => {
			render( <Avatar name="Tanner Robinson" /> );
			expect( screen.getByText( 'TR' ) ).toBeTruthy();
		} );

		it( 'should show single initial for single-word name', () => {
			render( <Avatar name="Zoraya" /> );
			expect( screen.getByText( 'Z' ) ).toBeTruthy();
		} );

		it( 'should limit initials to two characters', () => {
			render( <Avatar name="Jane Marie Doe" /> );
			expect( screen.getByText( 'JM' ) ).toBeTruthy();
		} );

		it( 'should uppercase initials', () => {
			render( <Avatar name="jane doe" /> );
			expect( screen.getByText( 'JD' ) ).toBeTruthy();
		} );

		it( 'should not show initials after image loads', () => {
			render(
				<Avatar
					name="Tanner Robinson"
					src="https://example.com/avatar.jpg"
				/>
			);
			fireEvent.load( screen.getByAltText( '' ) );
			expect( screen.queryByText( 'TR' ) ).toBeNull();
		} );

		it( 'should not render initials when name is not provided', () => {
			render( <Avatar data-testid="avatar" /> );
			const avatar = screen.getByTestId( 'avatar' );
			// Without a name, the image span should be empty (no initials).
			expect( avatar.textContent ).toBe( '' );
		} );
	} );

	describe( 'image loading', () => {
		it( 'should reset to loading state when src changes', () => {
			const { rerender } = render(
				<Avatar
					data-testid="avatar"
					name="Jane Doe"
					src="https://example.com/a.jpg"
				/>
			);
			fireEvent.load( screen.getByAltText( '' ) );
			expect(
				screen.getByTestId( 'avatar' ).classList.contains( 'has-src' )
			).toBe( true );

			rerender(
				<Avatar
					data-testid="avatar"
					name="Jane Doe"
					src="https://example.com/b.jpg"
				/>
			);
			// New src should reset to loading — initials visible again.
			expect(
				screen.getByTestId( 'avatar' ).classList.contains( 'has-src' )
			).toBe( false );
			expect( screen.getByText( 'JD' ) ).toBeTruthy();
		} );

		it( 'should show initials while image is loading', () => {
			render(
				<Avatar
					data-testid="avatar"
					name="Jane Doe"
					src="https://example.com/avatar.jpg"
				/>
			);
			const avatar = screen.getByTestId( 'avatar' );
			// Before load event, initials should show.
			expect( avatar.classList.contains( 'has-src' ) ).toBe( false );
			expect( screen.getByText( 'JD' ) ).toBeTruthy();
		} );

		it( 'should show image after successful load', () => {
			render(
				<Avatar
					data-testid="avatar"
					name="Jane Doe"
					src="https://example.com/avatar.jpg"
				/>
			);

			fireEvent.load( screen.getByAltText( '' ) );

			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'has-src' ) ).toBe( true );
			expect( screen.queryByText( 'JD' ) ).toBeNull();
		} );

		it( 'should fall back to initials when image fails to load', () => {
			render(
				<Avatar
					data-testid="avatar"
					name="Jane Doe"
					src="https://example.com/bad.jpg"
				/>
			);

			fireEvent.error( screen.getByAltText( '' ) );

			const avatar = screen.getByTestId( 'avatar' );
			expect( avatar.classList.contains( 'has-src' ) ).toBe( false );
			expect( screen.getByText( 'JD' ) ).toBeTruthy();
		} );

		it( 'should not render img element when no src is provided', () => {
			render( <Avatar data-testid="avatar" name="Jane Doe" /> );
			expect( screen.queryByAltText( '' ) ).toBeNull();
		} );
	} );

	describe( 'tooltip', () => {
		it( 'should wrap in tooltip when name is provided without badge', async () => {
			renderAvatar( <Avatar name="Jane Doe" /> );
			await hover( screen.getByRole( 'img', { name: 'Jane Doe' } ) );
			expect( await screen.findByText( 'Jane Doe' ) ).toBeTruthy();
		} );

		it( 'should not wrap in tooltip for badge without label', async () => {
			renderAvatar( <Avatar name="Jane Doe" variant="badge" /> );
			// Before hovering: the single "Jane Doe" occurrence is the
			// badge text — that's what the next assertion is allowed to
			// match. Hovering should not add a second occurrence.
			expect( screen.getAllByText( 'Jane Doe' ) ).toHaveLength( 1 );
			await hover( screen.getByRole( 'img', { name: 'Jane Doe' } ) );
			expect( screen.getAllByText( 'Jane Doe' ) ).toHaveLength( 1 );
		} );

		it( 'should not wrap in tooltip when name is not provided', async () => {
			renderAvatar( <Avatar data-testid="avatar" /> );
			const avatar = screen.getByTestId( 'avatar' );
			const bodyTextBefore = document.body.textContent;
			await hover( avatar );
			// No name → no tooltip wrapper at all, so hovering
			// cannot reveal any additional text content anywhere in the
			// document (no popup mounts). Strict equality is what we
			// want here — a substring `toHaveTextContent` would still
			// pass if the popup added text.
			expect( document.body.textContent ).toBe( bodyTextBefore );
		} );
	} );
} );
