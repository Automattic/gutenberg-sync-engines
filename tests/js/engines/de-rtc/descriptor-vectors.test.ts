/**
 * TODO-2a: the descriptor cross-language vector contract.
 *
 * The TS builder must produce operations whose server-side fingerprints
 * (`wp_de_rtc_get_automerge_block_native_operation_fingerprints`) match
 * what the frozen merge core derives for the same (base, proposed) pair
 * — a mismatch is a FALSE TAMPER REJECTION under full enforcement. The
 * fixture is generated from the PHP side by
 * `node tests/tools/generate-de-rtc-descriptor-vectors.mjs`; regenerate
 * it whenever the matrix or either implementation changes.
 *
 * Uses the REAL grammar parser from the built subtree (no mocks): the
 * builder's parse/serialize round trip is exactly what's under test.
 */
import { describe, expect, it } from '@jest/globals';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import {
	buildDeRtcClientUpdate,
	fingerprintDeRtcOperations,
	hashDeRtcContent,
} from '../../../../src/engines/de-rtc/descriptor';

interface DescriptorVector {
	name: string;
	base: string;
	next: string;
	expected: {
		fingerprints: Array< Record< string, unknown > >;
		baseContentHash: string;
		proposedContentHash: string;
		baseBlockCount: number | null;
		proposedBlockCount: number | null;
		operationTypes: string[];
	};
}

const vectors: DescriptorVector[] = JSON.parse(
	readFileSync(
		join( __dirname, 'test-vectors/descriptor-vectors.json' ),
		'utf8'
	)
);

describe( 'de-rtc descriptor builder ↔ PHP merge core parity', () => {
	it.each(
		vectors.map( ( vector ): [ string, DescriptorVector ] => [
			vector.name,
			vector,
		] )
	)( '%s', ( _name, vector ) => {
		const update = buildDeRtcClientUpdate(
			vector.base,
			vector.next,
			'client'
		);

		expect( update.baseContentHash ).toBe(
			vector.expected.baseContentHash
		);
		expect( update.proposedContentHash ).toBe(
			vector.expected.proposedContentHash
		);
		expect( update.baseBlockCount ).toBe( vector.expected.baseBlockCount );
		expect( update.proposedBlockCount ).toBe(
			vector.expected.proposedBlockCount
		);
		expect(
			update.operations.map( ( operation ) => operation.type )
		).toEqual( vector.expected.operationTypes );
		expect( fingerprintDeRtcOperations( update.operations ) ).toEqual(
			vector.expected.fingerprints
		);
	} );

	it( 'hashes match the PHP canonicalized sha256 evidence', () => {
		// One spot value pinned so a hash regression cannot hide behind a
		// builder regression in the same direction.
		expect( hashDeRtcContent( '' ) ).toBe(
			'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
		);
	} );
} );
