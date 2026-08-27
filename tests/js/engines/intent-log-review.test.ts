/**
 * External dependencies
 */
import { describe, expect, it } from '@jest/globals';

/**
 * Internal dependencies
 */
import {
	candidateHoldsParkedText,
	coalesceRuns,
	isMergeReviewProposal,
	mergeReviewGroupKey,
	replayIntendedField,
} from '../../../src/engines/intent-log-review';
import { createDocument } from '../../../src/engines/intent-log/document.js';
import type { IntentEnvelope } from '../../../src/engines/intent-log/engine-types';
import type { IntentLogProposal } from '../../../src/engines/intent-log-session';

const envelope = (
	type: string,
	payload: Record< string, unknown >,
	intentId = `i-${ Math.random() }`
): IntentEnvelope => ( {
	intentId,
	actorId: 'u1c1',
	baseSeq: 0,
	txnId: null,
	type,
	payload,
} );

const textIntent = (
	type: string,
	payload: Record< string, unknown >,
	intentId?: string
) => envelope( type, { syncId: 'p1', field: 'content', ...payload }, intentId );

const proposal = (
	type: string,
	payload: Record< string, unknown >,
	reason: string,
	actorId = 'u1c1'
): IntentLogProposal => ( {
	intent: { ...envelope( type, payload ), actorId },
	actorId,
	reason,
} );

describe( 'isMergeReviewProposal', () => {
	it( 'accepts the local author’s parked text edits on a field', () => {
		expect(
			isMergeReviewProposal(
				proposal(
					'insert_text',
					{ syncId: 'p1', field: 'content', offset: 3, text: 'x' },
					'frame-conflict'
				),
				'u1c1'
			)
		).toBe( true );
	} );

	it( 'rejects remote authors, kses items, and structural intents', () => {
		const local = proposal(
			'insert_text',
			{ syncId: 'p1', field: 'content', offset: 3, text: 'x' },
			'frame-conflict'
		);
		expect( isMergeReviewProposal( local, 'u2c2' ) ).toBe( false );
		expect(
			isMergeReviewProposal(
				proposal(
					'insert_text',
					{ syncId: 'p1', field: 'content', offset: 3, text: 'x' },
					'requires-approval'
				),
				'u1c1'
			)
		).toBe( false );
		expect(
			isMergeReviewProposal(
				proposal(
					'insert_block',
					{ block: { syncId: 'n1' } },
					'frame-conflict'
				),
				'u1c1'
			)
		).toBe( false );
	} );

	it( 'accepts property-conflict register losses (the two-pane variant)', () => {
		expect(
			isMergeReviewProposal(
				proposal(
					'set_property',
					{ name: 'title', value: 'mine', observedVersion: 0 },
					'property-conflict'
				),
				'u1c1'
			)
		).toBe( true );
	} );
} );

describe( 'mergeReviewGroupKey', () => {
	it( 'groups per author, block, and field', () => {
		const a = proposal(
			'insert_text',
			{ syncId: 'p1', field: 'content', offset: 1, text: 'x' },
			'frame-conflict'
		);
		const b = proposal(
			'delete_text',
			{
				syncId: 'p1',
				field: 'content',
				start: 0,
				end: 1,
				removedText: 'H',
			},
			'dependent-on-escalated'
		);
		const otherField = proposal(
			'insert_text',
			{ syncId: 'p1', field: 'citation', offset: 1, text: 'x' },
			'frame-conflict'
		);
		expect( mergeReviewGroupKey( a ) ).toBe( mergeReviewGroupKey( b ) );
		expect( mergeReviewGroupKey( a ) ).not.toBe(
			mergeReviewGroupKey( otherField )
		);
	} );
} );

describe( 'coalesceRuns', () => {
	it( 'merges a typing burst into one insert run', () => {
		const runs = coalesceRuns( [
			textIntent( 'insert_text', { offset: 11, text: ' ' } ),
			textIntent( 'insert_text', { offset: 12, text: '1' } ),
			textIntent( 'insert_text', { offset: 13, text: '2' } ),
			textIntent( 'insert_text', { offset: 14, text: '3' } ),
		] );
		expect( runs ).toEqual( [ { kind: 'insert', text: ' 123' } ] );
	} );

	it( 'merges a backspace run walking backwards', () => {
		const runs = coalesceRuns( [
			textIntent( 'delete_text', {
				start: 4,
				end: 5,
				removedText: 'o',
			} ),
			textIntent( 'delete_text', {
				start: 3,
				end: 4,
				removedText: 'l',
			} ),
			textIntent( 'delete_text', {
				start: 2,
				end: 3,
				removedText: 'l',
			} ),
		] );
		expect( runs ).toEqual( [ { kind: 'delete', text: 'llo' } ] );
	} );

	it( 'keeps disjoint edits as separate runs', () => {
		const runs = coalesceRuns( [
			textIntent( 'insert_text', { offset: 0, text: 'A' } ),
			textIntent( 'insert_text', { offset: 50, text: 'B' } ),
		] );
		expect( runs ).toEqual( [
			{ kind: 'insert', text: 'A' },
			{ kind: 'insert', text: 'B' },
		] );
	} );

	it( 'expands replace_text into a delete and an insert run', () => {
		const runs = coalesceRuns( [
			textIntent( 'replace_text', {
				start: 0,
				end: 5,
				removedText: 'Hello',
				text: 'Howdy',
			} ),
		] );
		expect( runs ).toEqual( [
			{ kind: 'delete', text: 'Hello' },
			{ kind: 'insert', text: 'Howdy' },
		] );
	} );
} );

describe( 'replayIntendedField', () => {
	const baseDoc = createDocument( [
		{
			syncId: 'p1',
			blockType: 'core/paragraph',
			text: 'Hello world',
			formats: [ { start: 0, end: 5, format: '<strong>' } ],
		},
	] );

	it( 'reproduces the motivating scenario: accepted first keystroke plus parked remainder', () => {
		/*
		 * The author typed " 123" at the end of "Hello world". The first
		 * keystroke merged; its ACCEPTED row carries an offset transformed
		 * over the peer's concurrent start-insert ("abc ", +4). The later
		 * keystrokes parked with their ORIGINAL offsets. Replayed over the
		 * base (which lacks the peer's text), the transformed offset
		 * clamps back to the field end and the intended text comes out
		 * exactly.
		 */
		const order = new Map< string, number >( [
			[ 'k1', 0 ],
			[ 'k2', 1 ],
			[ 'k3', 2 ],
			[ 'k4', 3 ],
		] );
		const field = replayIntendedField(
			baseDoc,
			[
				{
					// Transformed form: offset 11 + the peer's 4 chars.
					seq: 1,
					entry: textIntent(
						'insert_text',
						{ offset: 15, text: ' ' },
						'k1'
					),
				},
			],
			[
				textIntent( 'insert_text', { offset: 12, text: '1' }, 'k2' ),
				textIntent( 'insert_text', { offset: 13, text: '2' }, 'k3' ),
				textIntent( 'insert_text', { offset: 14, text: '3' }, 'k4' ),
			],
			( intentId ) => order.get( intentId ) ?? null,
			'p1',
			'content'
		);
		expect( field?.text ).toBe( 'Hello world 123' );
		// The base field's formats survive the replay untouched.
		expect( field?.formats ).toEqual( [
			{ start: 0, end: 5, format: '<strong>' },
		] );
	} );

	it( 'interleaves by authored order, not by settlement position', () => {
		// A parked keystroke authored BETWEEN two accepted ones must land
		// between them, even though parked rows settle later.
		const order = new Map< string, number >( [
			[ 'a1', 0 ],
			[ 'parked', 1 ],
			[ 'a2', 2 ],
		] );
		const field = replayIntendedField(
			createDocument( [
				{ syncId: 'p1', blockType: 'core/paragraph', text: '' },
			] ),
			[
				{
					seq: 1,
					entry: textIntent(
						'insert_text',
						{ offset: 0, text: 'A' },
						'a1'
					),
				},
				{
					seq: 2,
					entry: textIntent(
						'insert_text',
						{ offset: 2, text: 'C' },
						'a2'
					),
				},
			],
			[ textIntent( 'insert_text', { offset: 1, text: 'B' }, 'parked' ) ],
			( intentId ) => order.get( intentId ) ?? null,
			'p1',
			'content'
		);
		expect( field?.text ).toBe( 'ABC' );
	} );

	it( 'returns null when the block is gone in the author’s own timeline', () => {
		const field = replayIntendedField(
			baseDoc,
			[
				{
					seq: 1,
					entry: envelope( 'remove_block', { syncId: 'p1' }, 'rm' ),
				},
			],
			[],
			() => 0,
			'p1',
			'content'
		);
		expect( field ).toBeNull();
	} );
} );

describe( 'candidateHoldsParkedText', () => {
	const parked = [
		textIntent( 'insert_text', { offset: 12, text: '1' } ),
		textIntent( 'insert_text', { offset: 13, text: '2' } ),
		textIntent( 'insert_text', { offset: 14, text: '3' } ),
	];

	it( 'trusts a candidate holding the coalesced parked text', () => {
		expect( candidateHoldsParkedText( 'Hello world 123', parked ) ).toBe(
			true
		);
	} );

	it( 'rejects a candidate without it (an already-converged view)', () => {
		expect( candidateHoldsParkedText( 'abc Hello world ', parked ) ).toBe(
			false
		);
	} );
} );
