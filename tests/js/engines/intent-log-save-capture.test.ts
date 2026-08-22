/**
 * Save-accurate `_wrapper`/`content` authoring. With a
 * save-markup adapter, capture refreshes the wrapper (alignment/class
 * changes survive materialization) and, for block types without a
 * resolver-named content field, authors the save-derived inner HTML as
 * the engine content field — so sourced attributes (image url/alt)
 * round-trip server materialization.
 */
import { describe, expect, it } from '@jest/globals';

import {
	applyDerivedIntents,
	blockToEngineSpec,
	deriveIntents,
	type BridgeBlock,
	type RichTextFieldsResolver,
	type SaveMarkupAdapter,
} from '../../../src/engines/intent-log-bridge';
import { createDocument } from '../../../src/engines/intent-log/document.js';
import { fieldToHtml } from '../../../src/engines/intent-log/rich-text.js';
import type { EngineDocument } from '../../../src/engines/intent-log/engine-types';

/**
 * Framework-shaped resolver: paragraphs carry content; images do not.
 * @param name
 */
const resolver: RichTextFieldsResolver = ( name ) =>
	'core/paragraph' === name ? [ 'content' ] : [];

/**
 * A save renderer standing in for getSaveContent.
 * @param block
 */
const save: SaveMarkupAdapter = ( block ) => {
	if ( 'core/image' === block.name ) {
		const { url, alt } = block.attributes as { url: string; alt: string };
		return `<figure class="wp-block-image"><img src="${ url }" alt="${ alt }"/></figure>`;
	}
	if ( 'core/paragraph' === block.name ) {
		const align = ( block.attributes as { align?: string } ).align;
		const className = align ? ` class="has-text-align-${ align }"` : '';
		return `<p${ className }>${ String(
			block.attributes.content ?? ''
		) }</p>`;
	}
	if ( 'core/separator' === block.name ) {
		return '<hr class="wp-block-separator"/>';
	}
	return null;
};

const image = ( syncId: string, url: string, alt: string ): BridgeBlock => ( {
	name: 'core/image',
	attributes: { id: 5, url, alt, metadata: { syncId } },
	innerBlocks: [],
} );

const paragraph = (
	syncId: string,
	content: string,
	extra: Record< string, unknown > = {}
): BridgeBlock => ( {
	name: 'core/paragraph',
	attributes: { ...extra, content, metadata: { syncId } },
	innerBlocks: [],
} );

function docFromBlocks(
	blocks: BridgeBlock[],
	adapter?: SaveMarkupAdapter
): EngineDocument {
	return createDocument(
		blocks.map( ( block ) =>
			blockToEngineSpec(
				block,
				resolver,
				undefined,
				undefined,
				undefined,
				adapter
			)
		)
	);
}

const contentHtml = ( doc: EngineDocument, index: number ): string =>
	fieldToHtml(
		doc.root[ index ].fields.content ?? { text: '', formats: [] }
	);

describe( 'save-accurate capture', () => {
	it( 'a sourced-attribute change rewrites the authored content field', () => {
		const doc = docFromBlocks(
			[ image( 'i1', 'https://x/a.png', 'old alt' ) ],
			save
		);
		expect( contentHtml( doc, 0 ) ).toContain( 'old alt' );
		expect( doc.root[ 0 ].attrs._wrapper ).toEqual( {
			open: '<figure class="wp-block-image">',
			close: '</figure>',
		} );

		const derived = deriveIntents(
			doc,
			[ image( 'i1', 'https://x/a.png', 'new alt' ) ],
			{ richTextFields: resolver, saveMarkup: save }
		);
		expect( derived ).not.toBeNull();
		const applied = applyDerivedIntents( doc, derived!.intents );
		expect( applied.root[ 0 ].attrs.alt ).toBe( 'new alt' );
		expect( contentHtml( applied, 0 ) ).toBe(
			'<img src="https://x/a.png" alt="new alt"/>'
		);
	} );

	it( 'a wrapper-affecting change refreshes the _wrapper attr', () => {
		const doc = docFromBlocks( [ paragraph( 'p1', 'Hello' ) ], save );
		expect( doc.root[ 0 ].attrs._wrapper ).toEqual( {
			open: '<p>',
			close: '</p>',
		} );

		const derived = deriveIntents(
			doc,
			[ paragraph( 'p1', 'Hello', { align: 'right' } ) ],
			{ richTextFields: resolver, saveMarkup: save }
		);
		expect( derived ).not.toBeNull();
		const applied = applyDerivedIntents( doc, derived!.intents );
		expect( applied.root[ 0 ].attrs._wrapper ).toEqual( {
			open: '<p class="has-text-align-right">',
			close: '</p>',
		} );
		// The content path stays attribute-driven for resolver-named types.
		expect( applied.root[ 0 ].fields.content?.text ).toBe( 'Hello' );
	} );

	it( 'a void-root block authors its whole markup wrapper-less', () => {
		const doc = docFromBlocks(
			[
				{
					name: 'core/separator',
					attributes: { metadata: { syncId: 's1' } },
					innerBlocks: [],
				},
			],
			save
		);
		expect( doc.root[ 0 ].attrs._wrapper ).toBeUndefined();
		expect( contentHtml( doc, 0 ) ).toBe(
			'<hr class="wp-block-separator"/>'
		);
	} );

	it( 'an adapter miss never deletes existing document content', () => {
		// The document carries server-genesis content for a type the
		// adapter cannot render (returns null): capture must not read the
		// spec's absent content field as a deletion.
		const genesisAdapter: SaveMarkupAdapter = () =>
			'<figure class="wp-block-image"><img src="https://x/a.png" alt="keep"/></figure>';
		const doc = docFromBlocks(
			[ image( 'i1', 'https://x/a.png', 'keep' ) ],
			genesisAdapter
		);
		const derived = deriveIntents(
			doc,
			[ image( 'i1', 'https://x/a.png', 'keep' ) ],
			{ richTextFields: resolver, saveMarkup: () => null }
		);
		// No content deletion derived; the only acceptable outcome is a
		// no-op (or an empty batch).
		if ( null !== derived ) {
			expect( derived.intents ).toHaveLength( 0 );
		}
		expect( contentHtml( doc, 0 ) ).toContain( 'keep' );
	} );

	it( 'derivation verifies (no coarse degrade) for a sourced-attr edit', () => {
		const doc = docFromBlocks(
			[
				paragraph( 'p1', 'Intro' ),
				image( 'i1', 'https://x/a.png', 'old' ),
			],
			save
		);
		const derived = deriveIntents(
			doc,
			[
				paragraph( 'p1', 'Intro' ),
				image( 'i1', 'https://x/b.png', 'old' ),
			],
			{ richTextFields: resolver, saveMarkup: save }
		);
		expect( derived ).not.toBeNull();
		expect( derived!.coarseBlockCount ).toBe( 0 );
		const applied = applyDerivedIntents( doc, derived!.intents );
		expect( contentHtml( applied, 1 ) ).toBe(
			'<img src="https://x/b.png" alt="old"/>'
		);
	} );
} );
