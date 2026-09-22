/**
 * Whether an element is visible: not visually hidden, laid out with a
 * size, and not hidden by CSS. Copied from the block editor's private
 * `isElementVisible` so the plugin does not depend on that private API.
 *
 * @param element The element.
 * @return Whether it is visible.
 */
export function isElementVisible( element: Element ): boolean {
	const viewport = element.ownerDocument.defaultView;
	if ( ! viewport ) {
		return false;
	}

	// Check for <VisuallyHidden> components.
	if ( element.hasAttribute( 'data-visually-hidden' ) ) {
		return false;
	}

	const bounds = element.getBoundingClientRect();
	if ( bounds.width === 0 || bounds.height === 0 ) {
		return false;
	}

	// Older browsers, e.g. Safari < 17.4 may not support `checkVisibility`.
	if ( element.checkVisibility ) {
		return element.checkVisibility( {
			opacityProperty: true,
			contentVisibilityAuto: true,
			visibilityProperty: true,
		} );
	}

	const style = viewport.getComputedStyle( element );
	return ! (
		style.display === 'none' ||
		style.visibility === 'hidden' ||
		style.opacity === '0'
	);
}
