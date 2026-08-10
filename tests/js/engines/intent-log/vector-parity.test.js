/**
 * Guard for the two copies of the frozen intent-log vectors.
 *
 * The Jest harness replays `tests/js/engines/intent-log/test-vectors/` and
 * the PHPUnit harness replays `tests/phpunit/test-vectors/`; each copy sits
 * with the suite that consumes it, and nothing else keeps them in sync. The
 * generators in `src/engines/intent-log/tools/` write one file — whoever
 * regenerates must copy it to both locations, and this suite is what fails
 * when a regeneration (or a hand edit) touches only one side.
 */

import { describe, expect, it } from '@jest/globals';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const JS_VECTORS = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'test-vectors'
);
const PHP_VECTORS = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'../../../phpunit/test-vectors'
);

describe( 'frozen vector copies (JS/PHP parity)', () => {
	it( 'ships the same set of vector files on both sides', () => {
		expect( readdirSync( PHP_VECTORS ).sort() ).toEqual(
			readdirSync( JS_VECTORS ).sort()
		);
	} );

	for ( const name of readdirSync( JS_VECTORS ).sort() ) {
		it( `is byte-identical on both sides: ${ name }`, () => {
			const js = readFileSync( join( JS_VECTORS, name ), 'utf8' );
			const php = readFileSync( join( PHP_VECTORS, name ), 'utf8' );
			expect( php ).toBe( js );
		} );
	}
} );
