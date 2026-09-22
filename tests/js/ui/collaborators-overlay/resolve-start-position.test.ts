import { describe, expect, it, jest } from '@jest/globals';
import { resolveStartPosition } from '../../../../src/ui/collaborators-overlay/resolve-start-position';

// The selection types module reaches for the block editor store; the
// editor packages are not needed to test a pure resolver.
jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );
jest.mock( '@wordpress/rich-text', () => ( {
	RichTextData: class {},
	create: jest.fn(),
	insert: jest.fn(),
	toHTMLString: jest.fn(),
} ) );
jest.mock( '@wordpress/data', () => ( { select: jest.fn() } ) );
import { SelectionType } from '../../../../src/engines/yjs/crdt/crdt-user-selections';
import type { ResolvedSelection } from '../../../../src/engines/yjs/crdt/types';

const RESOLVED: ResolvedSelection = {
	richTextOffset: 3,
	localClientId: 'the-clientid',
	attributeKey: 'content',
};

describe( 'resolveStartPosition', () => {
	it( 'returns null when there is no selection', () => {
		const resolveSelection = jest.fn();

		expect(
			resolveStartPosition( undefined, resolveSelection )
		).toBeNull();
		expect( resolveSelection ).not.toHaveBeenCalled();
	} );

	it( 'resolves a Cursor selection directly', () => {
		const selection = {
			type: SelectionType.Cursor,
			cursorPosition: {},
		} as any;
		const resolveSelection = jest.fn().mockReturnValue( RESOLVED );

		expect( resolveStartPosition( selection, resolveSelection ) ).toBe(
			RESOLVED
		);
		expect( resolveSelection ).toHaveBeenCalledWith( selection );
	} );

	it( 'resolves a WholeBlock selection directly', () => {
		const selection = {
			type: SelectionType.WholeBlock,
			blockPosition: {},
		} as any;
		const resolveSelection = jest.fn().mockReturnValue( RESOLVED );

		expect( resolveStartPosition( selection, resolveSelection ) ).toBe(
			RESOLVED
		);
		expect( resolveSelection ).toHaveBeenCalledWith( selection );
	} );

	it( 'resolves a SelectionInOneBlock selection from its start position', () => {
		const cursorStartPosition = { marker: 'start' };
		const selection = {
			type: SelectionType.SelectionInOneBlock,
			cursorStartPosition,
			cursorEndPosition: { marker: 'end' },
		} as any;
		const resolveSelection = jest.fn().mockReturnValue( RESOLVED );

		expect( resolveStartPosition( selection, resolveSelection ) ).toBe(
			RESOLVED
		);
		expect( resolveSelection ).toHaveBeenCalledWith( {
			type: SelectionType.Cursor,
			cursorPosition: cursorStartPosition,
		} );
	} );

	it( 'resolves a SelectionInMultipleBlocks selection from its start endpoint', () => {
		const startEndpoint = {
			type: SelectionType.Cursor,
			cursorPosition: {},
		};
		const selection = {
			type: SelectionType.SelectionInMultipleBlocks,
			startEndpoint,
			endEndpoint: { type: SelectionType.WholeBlock, blockPosition: {} },
		} as any;
		const resolveSelection = jest.fn().mockReturnValue( RESOLVED );

		expect( resolveStartPosition( selection, resolveSelection ) ).toBe(
			RESOLVED
		);
		expect( resolveSelection ).toHaveBeenCalledWith( startEndpoint );
	} );

	it( 'returns null for a None selection without calling the resolver', () => {
		const selection = { type: SelectionType.None } as any;
		const resolveSelection = jest.fn();

		expect(
			resolveStartPosition( selection, resolveSelection )
		).toBeNull();
		expect( resolveSelection ).not.toHaveBeenCalled();
	} );

	it( 'returns null instead of throwing when the resolver throws (stale Yjs position)', () => {
		const selection = {
			type: SelectionType.Cursor,
			cursorPosition: {},
		} as any;
		const resolveSelection = jest.fn().mockImplementation( () => {
			throw new Error( 'stale position' );
		} );

		expect(
			resolveStartPosition( selection, resolveSelection )
		).toBeNull();
	} );
} );
