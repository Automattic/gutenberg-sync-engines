/**
 * Joins class names, skipping falsy entries and picking the keys of an
 * object whose values are truthy. A small stand-in for `clsx`, which the
 * plugin does not bundle.
 *
 * @param parts Strings, or objects mapping class names to conditions.
 * @return The joined class names.
 */
export function classNames(
	...parts: Array<
		string | undefined | null | false | Record< string, unknown >
	>
): string {
	const names: string[] = [];
	for ( const part of parts ) {
		if ( ! part ) {
			continue;
		}
		if ( 'string' === typeof part ) {
			names.push( part );
			continue;
		}
		for ( const [ name, condition ] of Object.entries( part ) ) {
			if ( condition ) {
				names.push( name );
			}
		}
	}
	return names.join( ' ' );
}
