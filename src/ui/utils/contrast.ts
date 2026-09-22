/**
 * WCAG contrast between two `#rrggbb` colors, without a color library.
 */

function relativeLuminance( hex: string ): number | null {
	const match = /^#([0-9a-f]{6})$/i.exec( hex.trim() );
	if ( ! match ) {
		return null;
	}
	const value = parseInt( match[ 1 ], 16 );
	// eslint-disable-next-line no-bitwise
	const channels = [ value >> 16, ( value >> 8 ) & 255, value & 255 ];
	const [ r, g, b ] = channels.map( ( channel ) => {
		const c = channel / 255;
		return c <= 0.03928
			? c / 12.92
			: Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * Whether normal-size text in `textColor` reads on a `background` at the
 * WCAG AA level (a contrast ratio of at least 4.5). Colors that are not
 * six-digit hex strings (for example a CSS variable) count as unreadable,
 * which is what the color library the editor used reported for them.
 *
 * @param textColor  The text color, `#rrggbb`.
 * @param background The background color, `#rrggbb`.
 * @return Whether the pair is readable.
 */
export function isReadable( textColor: string, background: string ): boolean {
	const text = relativeLuminance( textColor );
	const back = relativeLuminance( background );
	if ( null === text || null === back ) {
		return false;
	}
	const lighter = Math.max( text, back );
	const darker = Math.min( text, back );
	return ( lighter + 0.05 ) / ( darker + 0.05 ) >= 4.5;
}
