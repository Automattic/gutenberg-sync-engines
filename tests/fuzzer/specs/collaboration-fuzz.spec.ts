/**
 * Seeded browser fuzz spec for real-time collaboration.
 *
 * Adapted from the Gutenberg RTC browser fuzzer
 * (danluu/gutenberg try/fuzz, test/e2e/specs/editor/collaboration/
 * collaboration-fuzz.spec.ts), reduced to the core that finds bugs and
 * reparameterized for this plugin's engine × transport matrix:
 *
 * - Every test is one SEED. The seed deterministically chooses the initial
 *   post content, the action at every step, the acting user, milestone
 *   (save/reload/late-join) placement, and fault injection. A failing seed
 *   replays exactly (same engine/transport/steps/users).
 * - Actions come from a bounded grammar (block inserts/edits/moves/deletes,
 *   nested structures, title edits, real typing, concurrent edits), not
 *   arbitrary DOM mutation.
 * - After every step, all participants must CONVERGE on the same
 *   SERIALIZED content + title (serialization is what persists; raw
 *   attribute objects legitimately differ across engine code paths), and
 *   no block may be in the invalid-content recovery state.
 * - One seeded step becomes a save milestone; another reloads a random
 *   participant mid-session (a server-authoritative engine must restore the
 *   live document). The run ends with save + reload + REST round-trip.
 * - Before some steps the next sync request from the acting page is delayed
 *   or failed with a retryable status (429/500/503). Faults are HTTP-only:
 *   route interception cannot touch WebSocket frames, so the runner disables
 *   them on the websocket transport.
 *
 * The engine/transport are NOT set here — the matrix runner
 * (tests/fuzzer/run.mjs) configures the site before invoking this spec and
 * passes RTC_FUZZ_ENGINE / RTC_FUZZ_TRANSPORT so the spec can record them
 * and adapt (fault injection, discovery waits).
 */

/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import type { Editor } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	test,
	expect,
} from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures';
import type CollaborationUtils from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures/collaboration-utils';
import { SECOND_USER } from '../../../gutenberg/test/e2e/specs/editor/collaboration/fixtures/collaboration-utils';

type Random = () => number;

interface TraceEntry {
	detail?: Record< string, unknown >;
	label: string;
	step: number;
	userIndex: number;
}

interface ActionContext {
	editor: Editor;
	editors: Editor[];
	page: Page;
	pages: Page[];
	rng: Random;
	seed: number;
	step: number;
	userIndex: number;
}

const SEED_START = getEnvInt( 'RTC_FUZZ_SEED_START', 1 );
const SEED_COUNT = getEnvInt( 'RTC_FUZZ_SEED_COUNT', 3 );
const SEEDS = getEnvIntList( 'RTC_FUZZ_SEEDS' );
const STEP_COUNT = getEnvInt( 'RTC_FUZZ_STEPS', 12 );
const USER_COUNT = Math.max( 2, getEnvInt( 'RTC_FUZZ_USERS', 2 ) );
const ENGINE = process.env.RTC_FUZZ_ENGINE || 'unknown';
const TRANSPORT = process.env.RTC_FUZZ_TRANSPORT || 'unknown';
const CONVERGENCE_TIMEOUT_MS = getEnvInt(
	'RTC_FUZZ_CONVERGENCE_TIMEOUT_MS',
	20000
);
// Long-polling can hold a quiet request up to 20s (DEFAULT_MAX_WAIT_MS), and
// discovery waits on THREE sync cycles — give that lane real headroom.
const DISCOVERY_TIMEOUT_MS = getEnvInt(
	'RTC_FUZZ_DISCOVERY_TIMEOUT_MS',
	TRANSPORT === 'http-long-polling' ? 90000 : 30000
);
const DISABLE_SYNC_FAULTS =
	process.env.RTC_FUZZ_DISABLE_SYNC_FAULTS === '1' ||
	TRANSPORT === 'websocket';
const DISABLE_RELOAD = process.env.RTC_FUZZ_DISABLE_RELOAD === '1';
// Engines with a documented no-title-sync gap (the runner sets this from
// its capability map) skip title actions; untouched titles stay converged,
// so the oracle needs no change.
const SYNC_TITLE = process.env.RTC_FUZZ_SYNC_TITLE !== '0';
const TEST_TIMEOUT_MS = getEnvInt(
	'RTC_FUZZ_TEST_TIMEOUT_MS',
	120000 + STEP_COUNT * 15000
);
const RETRIABLE_SYNC_FAILURE_STATUSES = [ 429, 500, 503 ];
// Probability that a step is preceded by a sync fault (60% of faults are
// delays, the rest retryable failures).
const FAULT_RATE = ( () => {
	const raw = Number.parseFloat( process.env.RTC_FUZZ_FAULT_RATE || '0.25' );
	return Number.isFinite( raw ) ? Math.min( Math.max( raw, 0 ), 1 ) : 0.25;
} )();
// Leave/re-join lifecycle milestones (a non-primary collaborator closes
// their tab mid-session and later rejoins with the same account).
const DISABLE_LIFECYCLE = process.env.RTC_FUZZ_DISABLE_LIFECYCLE === '1';
// Probability that a step becomes a BURST: several actions from distinct
// actors fired concurrently with NO convergence wait in between — the
// timing pressure a single-action-then-converge loop never produces.
const BURST_RATE = ( () => {
	const raw = Number.parseFloat( process.env.RTC_FUZZ_BURST_RATE || '0.2' );
	return Number.isFinite( raw ) ? Math.min( Math.max( raw, 0 ), 1 ) : 0.2;
} )();

const THIRD_USER = {
	username: 'fuzz-collaborator-3',
	email: 'fuzz-collaborator-3@example.com',
	firstName: 'Fuzz',
	lastName: 'Third',
	password: 'password',
	roles: [ 'editor' ],
};

function getEnvInt( name: string, fallback: number ): number {
	const raw = process.env[ name ];
	if ( ! raw ) {
		return fallback;
	}
	const value = Number.parseInt( raw, 10 );
	return Number.isFinite( value ) ? value : fallback;
}

function getEnvIntList( name: string ): number[] | null {
	const raw = process.env[ name ];
	if ( ! raw ) {
		return null;
	}
	const values = raw
		.split( ',' )
		.map( ( token ) => Number.parseInt( token.trim(), 10 ) )
		.filter( ( value ) => Number.isFinite( value ) );
	return values.length ? values : null;
}

/**
 * mulberry32: small deterministic PRNG so a seed replays the exact run.
 *
 * @param seed Seed integer.
 */
function createRng( seed: number ): Random {
	/* eslint-disable no-bitwise -- mulberry32 is inherently bitwise. */
	let state = seed >>> 0;
	return () => {
		state = ( state + 0x6d2b79f5 ) | 0;
		let t = Math.imul( state ^ ( state >>> 15 ), 1 | state );
		t = ( t + Math.imul( t ^ ( t >>> 7 ), 61 | t ) ) ^ t;
		return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
	};
	/* eslint-enable no-bitwise */
}

function pick< T >( rng: Random, values: T[] ): T {
	return values[ Math.floor( rng() * values.length ) ];
}

function pickIndex( rng: Random, length: number ): number {
	return Math.floor( rng() * length );
}

/**
 * Deterministic content marker: unique per seed/step/user so convergence
 * mismatches identify the exact action that produced the text.
 *
 * @param seed  Run seed.
 * @param step  Step index.
 * @param user  Acting user index.
 * @param label Short action tag.
 */
function marker(
	seed: number,
	step: number,
	user: number,
	label: string
): string {
	return `f${ seed }s${ step }u${ user }-${ label }`;
}

const MULTIBYTE_SUFFIXES = [ ' é–ü', ' 日本語', ' 😀🎉', ' Ωмega' ];

const PARAGRAPH = ( content: string ) =>
	`<!-- wp:paragraph -->\n<p>${ content }</p>\n<!-- /wp:paragraph -->`;

/**
 * The seeded initial-content templates. Mirrors the upstream fuzzer's
 * profiles: plain, empty (concurrent genesis), multibyte, nested structures,
 * and a long shared paragraph for same-block concurrent edits.
 *
 * @param seed Run seed.
 */
function getInitialContent( seed: number ): string {
	const templates = [
		[
			PARAGRAPH( 'Alpha paragraph' ),
			PARAGRAPH( 'Beta paragraph' ),
			PARAGRAPH( 'Gamma paragraph' ),
		].join( '\n\n' ),
		'',
		[
			PARAGRAPH( 'Multibyte é–ü seed 日本語' ),
			PARAGRAPH( 'Emoji 😀🎉 paragraph' ),
		].join( '\n\n' ),
		[
			'<!-- wp:group {"layout":{"type":"constrained"}} -->',
			'<div class="wp-block-group">',
			PARAGRAPH( 'Nested opener' ),
			'<!-- wp:list -->',
			'<ul class="wp-block-list"><!-- wp:list-item -->',
			'<li>Nested list item</li>',
			'<!-- /wp:list-item --></ul>',
			'<!-- /wp:list -->',
			'</div>',
			'<!-- /wp:group -->',
			PARAGRAPH( 'Sibling paragraph' ),
		].join( '\n' ),
		PARAGRAPH(
			'Shared long paragraph where both collaborators will type ' +
				'concurrently and the merged sentence must keep every token.'
		),
	];
	return templates[ seed % templates.length ];
}

interface FlatBlock {
	clientId: string;
	content: string;
	name: string;
	rootClientId: string;
}

/**
 * Flattened block list (clientId, name, rootClientId) for target selection.
 *
 * @param page Page to inspect.
 */
async function getFlatBlocks( page: Page ): Promise< FlatBlock[] > {
	return page.evaluate( () => {
		const flat: FlatBlock[] = [];
		const walk = ( blocks: any[], rootClientId: string ) => {
			for ( const block of blocks ) {
				flat.push( {
					clientId: block.clientId,
					content: String( block.attributes?.content ?? '' ),
					name: block.name,
					rootClientId,
				} );
				walk( block.innerBlocks ?? [], block.clientId );
			}
		};
		walk(
			( window as any ).wp.data.select( 'core/block-editor' ).getBlocks(),
			''
		);
		return flat;
	} );
}

async function getTopLevelCount( page: Page ): Promise< number > {
	return page.evaluate(
		() =>
			( window as any ).wp.data.select( 'core/block-editor' ).getBlocks()
				.length
	);
}

interface BlockSpec {
	attributes?: Record< string, unknown >;
	inner?: BlockSpec[];
	name: string;
}

/**
 * Insert a block via the store. `spec` is a serializable block description
 * ({ name, attributes, inner: [...] }); index -1 appends.
 *
 * @param page         Acting page.
 * @param spec         Serializable block description.
 * @param index        Top-level insertion index; -1 appends.
 * @param rootClientId
 */
async function insertBlockAt(
	page: Page,
	spec: BlockSpec,
	index: number,
	rootClientId = ''
): Promise< void > {
	await page.evaluate(
		( { blockSpec, insertIndex, root } ) => {
			const { createBlock } = ( window as any ).wp.blocks;
			const build = ( node: any ): any =>
				createBlock(
					node.name,
					node.attributes ?? {},
					( node.inner ?? [] ).map( build )
				);
			const { dispatch, select } = ( window as any ).wp.data;
			const count =
				select( 'core/block-editor' ).getBlocks( root ).length;
			dispatch( 'core/block-editor' ).insertBlock(
				build( blockSpec ),
				insertIndex < 0 || insertIndex > count ? count : insertIndex,
				root,
				false
			);
		},
		{ blockSpec: spec, insertIndex: index, root: rootClientId }
	);
}

async function updateBlockContent(
	page: Page,
	clientId: string,
	content: string
): Promise< void > {
	await page.evaluate(
		( args ) => {
			( window as any ).wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes( args.clientId, {
					content: args.content,
				} );
		},
		{ clientId, content }
	);
}

/**
 * Arm a one-shot fault on the acting page's next sync request. Matches both
 * pretty and plain-permalink sync routes (wp-env uses
 * `?rest_route=%2Fwp-sync%2F...`). HTTP transports only.
 *
 * @param page          Acting page.
 * @param fault         The fault to inject.
 * @param fault.delayMs Delay in ms (delay fault).
 * @param fault.status  HTTP status to fail with (failure fault).
 */
async function armSyncFault(
	page: Page,
	fault: { delayMs?: number; status?: number }
): Promise< void > {
	let armed = true;
	const matcher = ( url: URL ) =>
		url.href.includes( 'wp-sync' ) ||
		decodeURIComponent( url.search ).includes( '/wp-sync/' );
	await page.route( matcher, async ( route ) => {
		if ( ! armed ) {
			await route.fallback();
			return;
		}
		armed = false;
		if ( fault.status ) {
			await route.fulfill( {
				body: JSON.stringify( {
					code: 'rtc_fuzz_injected_fault',
					message: 'Injected transient sync failure.',
				} ),
				contentType: 'application/json',
				status: fault.status,
			} );
		} else {
			await new Promise( ( resolve ) =>
				setTimeout( resolve, fault.delayMs ?? 500 )
			);
			await route.fallback();
		}
		await page.unroute( matcher ).catch( () => undefined );
	} );
}

/**
 * No block anywhere in the tree may be an invalid-content recovery block —
 * the classic engine-genesis failure mode (a server genesis that forgets
 * isValid renders every block as recovery UI).
 *
 * @param page  Page to inspect.
 * @param label Context for the failure message.
 */
async function assertNoInvalidBlocks( page: Page, label: string ) {
	const invalid = await page.evaluate( () => {
		const bad: string[] = [];
		const walk = ( blocks: any[] ) => {
			for ( const block of blocks ) {
				if ( block.isValid === false ) {
					bad.push( block.name );
				}
				walk( block.innerBlocks ?? [] );
			}
		};
		walk(
			( window as any ).wp.data.select( 'core/block-editor' ).getBlocks()
		);
		return bad;
	} );
	expect(
		invalid,
		`invalid-content recovery blocks after ${ label }: ${ invalid.join(
			', '
		) }`
	).toEqual( [] );
}

/**
 * The bounded action grammar. Each action receives an ActionContext and
 * returns the trace detail it wants recorded. Actions must leave the
 * document with at least one block.
 */
const ACTIONS: Array< {
	label: string;
	run: ( ctx: ActionContext ) => Promise< Record< string, unknown > | void >;
} > = [
	{
		label: 'insert-paragraph',
		run: async ( { page, seed, step, userIndex, rng } ) => {
			const count = await getTopLevelCount( page );
			const index = pickIndex( rng, count + 1 );
			const text =
				marker( seed, step, userIndex, 'ins' ) +
				( rng() < 0.3 ? pick( rng, MULTIBYTE_SUFFIXES ) : '' );
			await insertBlockAt(
				page,
				{ attributes: { content: text }, name: 'core/paragraph' },
				index
			);
			return { index, text };
		},
	},
	{
		label: 'append-paragraph',
		run: async ( { page, seed, step, userIndex } ) => {
			const text = marker( seed, step, userIndex, 'app' );
			await insertBlockAt(
				page,
				{ attributes: { content: text }, name: 'core/paragraph' },
				-1
			);
			return { text };
		},
	},
	{
		label: 'edit-paragraph',
		run: async ( { page, seed, step, userIndex, rng } ) => {
			const paragraphs = ( await getFlatBlocks( page ) ).filter(
				( block ) => block.name === 'core/paragraph'
			);
			if ( ! paragraphs.length ) {
				return { skipped: 'no paragraphs' };
			}
			const target = pick( rng, paragraphs );
			const text = `${ target.content } ${ marker(
				seed,
				step,
				userIndex,
				'edit'
			) }`;
			await updateBlockContent( page, target.clientId, text );
			return { clientId: target.clientId };
		},
	},
	{
		label: 'edit-title',
		run: async ( { page, seed, step, userIndex } ) => {
			const title = marker( seed, step, userIndex, 'title' );
			await page.evaluate( ( value ) => {
				( window as any ).wp.data
					.dispatch( 'core/editor' )
					.editPost( { title: value } );
			}, title );
			return { title };
		},
	},
	{
		label: 'insert-heading',
		run: async ( { page, seed, step, userIndex, rng } ) => {
			const level = pick( rng, [ 2, 3, 4 ] );
			const text = marker( seed, step, userIndex, 'head' );
			await insertBlockAt(
				page,
				{
					attributes: { content: text, level },
					name: 'core/heading',
				},
				-1
			);
			return { level, text };
		},
	},
	{
		label: 'insert-list',
		run: async ( { page, seed, step, userIndex } ) => {
			const text = marker( seed, step, userIndex, 'list' );
			await insertBlockAt(
				page,
				{
					inner: [
						{
							attributes: { content: text },
							name: 'core/list-item',
						},
					],
					name: 'core/list',
				},
				-1
			);
			return { text };
		},
	},
	{
		label: 'insert-quote',
		run: async ( { page, seed, step, userIndex } ) => {
			const text = marker( seed, step, userIndex, 'quote' );
			await insertBlockAt(
				page,
				{
					inner: [
						{
							attributes: { content: text },
							name: 'core/paragraph',
						},
					],
					name: 'core/quote',
				},
				-1
			);
			return { text };
		},
	},
	{
		label: 'insert-nested-group',
		run: async ( { page, seed, step, userIndex } ) => {
			const text = marker( seed, step, userIndex, 'group' );
			await insertBlockAt(
				page,
				{
					inner: [
						{
							attributes: { content: text },
							name: 'core/paragraph',
						},
						{
							inner: [
								{
									attributes: {
										content: `${ text }-inner`,
									},
									name: 'core/paragraph',
								},
							],
							name: 'core/group',
						},
					],
					name: 'core/group',
				},
				-1
			);
			return { text };
		},
	},
	{
		label: 'delete-block',
		run: async ( { page, rng } ) => {
			const count = await getTopLevelCount( page );
			if ( count < 2 ) {
				return { skipped: 'single block' };
			}
			const index = pickIndex( rng, count );
			await page.evaluate( ( blockIndex ) => {
				const { dispatch, select } = ( window as any ).wp.data;
				const block =
					select( 'core/block-editor' ).getBlocks()[ blockIndex ];
				if ( block ) {
					dispatch( 'core/block-editor' ).removeBlock(
						block.clientId,
						false
					);
				}
			}, index );
			return { index };
		},
	},
	{
		label: 'move-block',
		run: async ( { page, rng } ) => {
			const count = await getTopLevelCount( page );
			if ( count < 2 ) {
				return { skipped: 'single block' };
			}
			const index = pickIndex( rng, count );
			const direction = rng() < 0.5 ? 'up' : 'down';
			await page.evaluate(
				( args ) => {
					const { dispatch, select } = ( window as any ).wp.data;
					const blocks = select( 'core/block-editor' ).getBlocks();
					const block = blocks[ args.index ];
					if ( ! block ) {
						return;
					}
					if ( args.direction === 'up' ) {
						dispatch( 'core/block-editor' ).moveBlocksUp(
							[ block.clientId ],
							''
						);
					} else {
						dispatch( 'core/block-editor' ).moveBlocksDown(
							[ block.clientId ],
							''
						);
					}
				},
				{ direction, index }
			);
			return { direction, index };
		},
	},
	{
		label: 'ui-type-paragraph',
		run: async ( { page, editor, seed, step, userIndex } ) => {
			const text = ` ${ marker( seed, step, userIndex, 'type' ) }`;
			const target = editor.canvas
				.locator( '[data-type="core/paragraph"]' )
				.last();
			if ( ( await target.count() ) === 0 ) {
				return { skipped: 'no paragraph to type into' };
			}
			await target.click();
			await page.keyboard.press( 'End' );
			await page.keyboard.type( text );
			return { text };
		},
	},
	{
		label: 'ui-type-title',
		run: async ( { page, editor, seed, step, userIndex } ) => {
			const text = marker( seed, step, userIndex, 'uititle' );
			const title = editor.canvas.getByRole( 'textbox', {
				name: 'Add title',
			} );
			await title.click();
			await page.keyboard.press( 'ControlOrMeta+a' );
			await page.keyboard.type( text );
			return { text };
		},
	},
	{
		label: 'insert-into-group',
		run: async ( { page, seed, step, userIndex, rng } ) => {
			const groups = ( await getFlatBlocks( page ) ).filter(
				( block ) => block.name === 'core/group'
			);
			if ( ! groups.length ) {
				return { skipped: 'no groups' };
			}
			const target = pick( rng, groups );
			const text = marker( seed, step, userIndex, 'ingrp' );
			await insertBlockAt(
				page,
				{ attributes: { content: text }, name: 'core/paragraph' },
				0,
				target.clientId
			);
			return { group: target.clientId, text };
		},
	},
	{
		label: 'move-into-group',
		run: async ( { page, rng } ) => {
			const moved = await page.evaluate( ( random ) => {
				const { dispatch, select } = ( window as any ).wp.data;
				const top = select( 'core/block-editor' ).getBlocks();
				const groups = top.filter(
					( block: any ) => 'core/group' === block.name
				);
				const movable = top.filter(
					( block: any ) => 'core/group' !== block.name
				);
				if ( ! groups.length || movable.length < 2 ) {
					return null;
				}
				const source = movable[ Math.floor( random * movable.length ) ];
				const group = groups[ 0 ];
				dispatch( 'core/block-editor' ).moveBlocksToPosition(
					[ source.clientId ],
					'',
					group.clientId,
					0
				);
				return { group: group.clientId, source: source.clientId };
			}, rng() );
			return moved ?? { skipped: 'no group/movable pair' };
		},
	},
	{
		label: 'deep-nest-group',
		run: async ( { page, seed, step, userIndex } ) => {
			const text = marker( seed, step, userIndex, 'deep' );
			await insertBlockAt(
				page,
				{
					inner: [
						{
							attributes: { content: `${ text }-1` },
							name: 'core/paragraph',
						},
						{
							inner: [
								{
									attributes: { content: `${ text }-2` },
									name: 'core/paragraph',
								},
								{
									inner: [
										{
											attributes: {
												content: `${ text }-3`,
											},
											name: 'core/paragraph',
										},
									],
									name: 'core/group',
								},
							],
							name: 'core/group',
						},
					],
					name: 'core/group',
				},
				-1
			);
			return { text };
		},
	},
	{
		label: 'concurrent-append',
		run: async ( { pages, seed, step, rng } ) => {
			const secondIndex = pages.length > 2 && rng() < 0.5 ? 2 : 1;
			await Promise.all( [
				insertBlockAt(
					pages[ 0 ],
					{
						attributes: {
							content: marker( seed, step, 0, 'conc' ),
						},
						name: 'core/paragraph',
					},
					-1
				),
				insertBlockAt(
					pages[ secondIndex ],
					{
						attributes: {
							content: marker( seed, step, secondIndex, 'conc' ),
						},
						name: 'core/paragraph',
					},
					-1
				),
			] );
			return { secondIndex };
		},
	},
];

const TITLE_ACTION_LABELS = new Set( [ 'edit-title', 'ui-type-title' ] );
const ACTIVE_ACTIONS = SYNC_TITLE
	? ACTIONS
	: ACTIONS.filter( ( action ) => ! TITLE_ACTION_LABELS.has( action.label ) );

/**
 * Reserve a distinct milestone step (save, reload, late join) so two
 * milestones never land on the same step.
 *
 * @param rng       Seeded RNG.
 * @param stepCount Total steps.
 * @param used      Already-reserved steps.
 */
function chooseMilestoneStep(
	rng: Random,
	stepCount: number,
	used: Set< number >
): number {
	if ( used.size >= stepCount ) {
		return -1;
	}
	for (;;) {
		const step = pickIndex( rng, stepCount );
		if ( ! used.has( step ) ) {
			used.add( step );
			return step;
		}
	}
}

interface ComparableState {
	blockCount: number;
	content: string;
	title: string;
}

/**
 * The persistence-relevant view of a page's editor: the SERIALIZED block
 * content plus the title. Raw attribute objects are deliberately not
 * compared — engines legitimately differ in in-memory representation (e.g.
 * intent-log's snapshot materialization reifies empty rich-text fields as
 * explicit "" attributes that createBlock-authored trees omit) while
 * serializing to identical markup. Serialization is what saves, so it is
 * the equality that matters.
 *
 * @param page Page to read.
 */
async function getComparableState( page: Page ): Promise< ComparableState > {
	return page.evaluate( () => {
		const blocks = ( window as any ).wp.data
			.select( 'core/block-editor' )
			.getBlocks();
		return {
			blockCount: blocks.length,
			content: ( window as any ).wp.blocks.serialize( blocks ),
			title:
				( window as any ).wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'title' ) ?? '',
		};
	} );
}

/**
 * Waits until every page exposes the same serialized content + title.
 *
 * @param pages   Pages to compare.
 * @param timeout Budget in ms.
 */
async function waitForConvergence(
	pages: Page[],
	timeout: number
): Promise< ComparableState > {
	const deadline = Date.now() + timeout;
	let states: ComparableState[] = [];
	for (;;) {
		states = await Promise.all( pages.map( getComparableState ) );
		const first = JSON.stringify( states[ 0 ] );
		if ( states.every( ( state ) => JSON.stringify( state ) === first ) ) {
			return states[ 0 ];
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`Serialized state did not converge within ${ timeout }ms: ${ JSON.stringify(
					states
				) }`
			);
		}
		await pages[ 0 ].waitForTimeout( 250 );
	}
}

/**
 * Wait for all participants to discover each other. The fixture's
 * waitForMutualDiscovery waits on wp-sync HTTP responses, which never occur
 * over the websocket transport (sync rides WS frames) — there, wait on the
 * awareness-driven Collaborators list instead and let waitForConvergence
 * cover document sync.
 *
 * @param collaborationUtils Fixture utils.
 * @param pages              All participant pages.
 */
async function waitForDiscovery(
	collaborationUtils: CollaborationUtils,
	pages: Page[]
) {
	await Promise.all(
		pages.map( ( pg ) =>
			pg
				.getByRole( 'button', { name: /Collaborators list/ } )
				.waitFor( { timeout: DISCOVERY_TIMEOUT_MS } )
		)
	);
	if ( TRANSPORT === 'websocket' ) {
		// Sync rides WS frames; waitForConvergence covers document sync.
		return;
	}
	// The fixture's waitForMutualDiscovery iterates ITS page list, which can
	// contain closed pages after a leave — run its per-page sync-cycle wait
	// on the active pages only.
	await Promise.all(
		pages.map( ( pg ) =>
			collaborationUtils.waitForSyncCycle( pg, 3, {
				timeout: DISCOVERY_TIMEOUT_MS,
			} )
		)
	);
}

/**
 * Save the post from a page via the store and wait for the save to settle.
 *
 * @param page Saving page.
 */
async function saveDraftFromPage( page: Page ) {
	await page.evaluate( () =>
		( window as any ).wp.data.dispatch( 'core/editor' ).savePost()
	);
	await page.waitForFunction(
		() => {
			const editorSelect = ( window as any ).wp.data.select(
				'core/editor'
			);
			return (
				! editorSelect.isSavingPost() &&
				! editorSelect.isAutosavingPost()
			);
		},
		undefined,
		{ timeout: 30000 }
	);
	const succeeded = await page.evaluate( () =>
		( window as any ).wp.data
			.select( 'core/editor' )
			.didPostSaveRequestSucceed()
	);
	expect( succeeded, 'save request must succeed' ).toBe( true );
}

/**
 * Reload a participant page and wait for the collaboration runtime and any
 * reconciliation save to settle.
 *
 * @param collaborationUtils Fixture utils.
 * @param page               Page to reload.
 */
async function reloadPage(
	collaborationUtils: CollaborationUtils,
	page: Page
) {
	await page.reload();
	await collaborationUtils.waitForCollaborationReady( page );
	await collaborationUtils.waitForEntityReadyAndSaveSettled( page );
}

test.describe( `Collaboration fuzz [${ ENGINE }/${ TRANSPORT }]`, () => {
	// NOT 'serial': a failing seed must not abort the rest of the sweep.
	test.describe.configure( { timeout: TEST_TIMEOUT_MS } );

	const seeds =
		SEEDS ??
		Array.from(
			{ length: SEED_COUNT },
			( _value, offset ) => SEED_START + offset
		);

	for ( const seed of seeds ) {
		test( `seed ${ seed }`, async ( {
			collaborationUtils,
			requestUtils,
		}, testInfo ) => {
			test.setTimeout( TEST_TIMEOUT_MS );

			const rng = createRng( seed );
			const trace: TraceEntry[] = [];
			const record = ( entry: TraceEntry ) => {
				trace.push( entry );
			};

			try {
				const post = await requestUtils.createPost( {
					content: getInitialContent( seed ),
					date_gmt: new Date().toISOString(),
					status: 'draft',
					title: `RTC fuzz seed ${ seed }`,
				} );

				/*
				 * EXISTING-POST variant (~1/3 of seeds): before the
				 * collaborative session, the primary user opens the post
				 * ALONE, edits, saves, and navigates away. The room then
				 * already holds log history and saved content when the real
				 * session starts — the re-genesis path a brand-new post
				 * never exercises.
				 */
				const soloPhase = seed % 3 === 2;
				if ( soloPhase ) {
					record( { label: 'solo-phase', step: -1, userIndex: 0 } );
					await collaborationUtils.openPost( post.id );
					const soloPage = collaborationUtils.allPages[ 0 ];
					for ( let i = 0; i < 2; i++ ) {
						await insertBlockAt(
							soloPage,
							{
								attributes: {
									content: marker( seed, 90 + i, 0, 'pre' ),
								},
								name: 'core/paragraph',
							},
							-1
						);
					}
					await saveDraftFromPage( soloPage );
					// Leaving the editor ends the solo session; the next
					// openPost starts a fresh one against the same room.
					await soloPage.goto( '/wp-admin/index.php' );
				}

				await collaborationUtils.openPost( post.id );
				const second = await collaborationUtils.joinUser(
					post.id,
					SECOND_USER
				);
				// Participants are managed HERE (not via the fixture's
				// allPages): a leave closes a context, and closed pages must
				// drop out of every wait.
				const participants: Array< { editor: Editor; page: Page } > = [
					{
						editor: collaborationUtils.allEditors[ 0 ],
						page: collaborationUtils.allPages[ 0 ],
					},
					{ editor: second.editor, page: second.page },
				];
				const activePages = () =>
					participants.map( ( entry ) => entry.page );
				await waitForDiscovery( collaborationUtils, activePages() );
				await waitForConvergence(
					activePages(),
					CONVERGENCE_TIMEOUT_MS
				);
				await assertNoInvalidBlocks(
					participants[ 0 ].page,
					'initial load'
				);

				const usedMilestones = new Set< number >();
				const saveStep = chooseMilestoneStep(
					rng,
					STEP_COUNT,
					usedMilestones
				);
				const reloadStep = DISABLE_RELOAD
					? -1
					: chooseMilestoneStep( rng, STEP_COUNT, usedMilestones );
				const lateJoinStep =
					USER_COUNT > 2
						? chooseMilestoneStep( rng, STEP_COUNT, usedMilestones )
						: -1;
				/*
				 * LEAVE/RE-JOIN lifecycle (~60% of seeds, when enabled): the
				 * second collaborator closes their tab at one seeded step and
				 * rejoins with the same account at a later one. Their unacked
				 * local edits may legitimately be lost with the tab; the
				 * remaining participants must stay converged throughout, and
				 * the rejoiner must both receive the current document and be
				 * able to contribute.
				 */
				let leaveStep = -1;
				let rejoinStep = -1;
				if ( ! DISABLE_LIFECYCLE && rng() < 0.6 && STEP_COUNT >= 4 ) {
					const first = chooseMilestoneStep(
						rng,
						STEP_COUNT,
						usedMilestones
					);
					const secondStep = chooseMilestoneStep(
						rng,
						STEP_COUNT,
						usedMilestones
					);
					if ( first >= 0 && secondStep >= 0 ) {
						leaveStep = Math.min( first, secondStep );
						rejoinStep = Math.max( first, secondStep );
					}
				}
				let departed = false;

				const runSingleAction = async ( step: number ) => {
					const pages = activePages();
					const actorIndex = pickIndex( rng, pages.length );
					const actor = pages[ actorIndex ];
					const faultRoll = rng();

					if (
						! DISABLE_SYNC_FAULTS &&
						faultRoll < FAULT_RATE * 0.6
					) {
						const delayMs = 250 + Math.floor( rng() * 1250 );
						record( {
							detail: { delayMs },
							label: 'fault-delay',
							step,
							userIndex: actorIndex,
						} );
						await armSyncFault( actor, { delayMs } );
					} else if (
						! DISABLE_SYNC_FAULTS &&
						faultRoll < FAULT_RATE
					) {
						const status = pick(
							rng,
							RETRIABLE_SYNC_FAILURE_STATUSES
						);
						record( {
							detail: { status },
							label: 'fault-fail',
							step,
							userIndex: actorIndex,
						} );
						await armSyncFault( actor, { status } );
					}

					const action = pick( rng, ACTIVE_ACTIONS );
					const detail =
						await test.step( `seed ${ seed } step ${ step } ${ action.label } user ${ actorIndex }`, async () =>
							( await action.run( {
								editor: participants[ actorIndex ].editor,
								editors: participants.map(
									( entry ) => entry.editor
								),
								page: actor,
								pages,
								rng,
								seed,
								step,
								userIndex: actorIndex,
							} ) ) ?? {} );
					record( {
						detail: detail as Record< string, unknown >,
						label: action.label,
						step,
						userIndex: actorIndex,
					} );
				};

				for ( let step = 0; step < STEP_COUNT; step++ ) {
					if ( step === lateJoinStep ) {
						record( {
							label: 'late-join',
							step,
							userIndex: participants.length,
						} );
						await requestUtils.createUser( THIRD_USER );
						const third = await collaborationUtils.joinUser(
							post.id,
							THIRD_USER
						);
						participants.push( {
							editor: third.editor,
							page: third.page,
						} );
						await waitForDiscovery(
							collaborationUtils,
							activePages()
						);
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
						// A late joiner must be able to contribute, not just
						// receive.
						await insertBlockAt(
							third.page,
							{
								attributes: {
									content: marker(
										seed,
										step,
										participants.length - 1,
										'late'
									),
								},
								name: 'core/paragraph',
							},
							-1
						);
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
					}

					if ( step === leaveStep && participants.length > 1 ) {
						record( { label: 'leave', step, userIndex: 1 } );
						const [ leaver ] = participants.splice( 1, 1 );
						await leaver.page.context().close();
						departed = true;
						// Remaining participants must still be converged.
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
					}

					if ( step === rejoinStep && departed ) {
						record( { label: 'rejoin', step, userIndex: 1 } );
						const rejoined = await collaborationUtils.joinUser(
							post.id,
							SECOND_USER
						);
						participants.splice( 1, 0, {
							editor: rejoined.editor,
							page: rejoined.page,
						} );
						departed = false;
						await waitForDiscovery(
							collaborationUtils,
							activePages()
						);
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
						// The rejoiner must be able to contribute.
						await insertBlockAt(
							rejoined.page,
							{
								attributes: {
									content: marker( seed, step, 1, 'rejoin' ),
								},
								name: 'core/paragraph',
							},
							-1
						);
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
						await assertNoInvalidBlocks(
							rejoined.page,
							'post-rejoin'
						);
					}

					const burstRoll = rng();
					if ( burstRoll < BURST_RATE && activePages().length > 1 ) {
						// BURST: several actions, no convergence in between.
						const burstSize = 2 + pickIndex( rng, 2 );
						record( {
							detail: { burstSize },
							label: 'burst',
							step,
							userIndex: -1,
						} );
						for ( let i = 0; i < burstSize; i++ ) {
							await runSingleAction( step );
						}
					} else {
						await runSingleAction( step );
					}

					const state = await waitForConvergence(
						activePages(),
						CONVERGENCE_TIMEOUT_MS
					);
					expect( state.blockCount ).toBeGreaterThan( 0 );
					await assertNoInvalidBlocks(
						participants[ 0 ].page,
						`step ${ step }`
					);

					if ( step === saveStep ) {
						record( {
							label: 'save-milestone',
							step,
							userIndex: 0,
						} );
						await saveDraftFromPage( participants[ 0 ].page );
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
					}

					if ( step === reloadStep ) {
						const reloadIndex = pickIndex(
							rng,
							participants.length
						);
						record( {
							label: 'reload-milestone',
							step,
							userIndex: reloadIndex,
						} );
						await reloadPage(
							collaborationUtils,
							participants[ reloadIndex ].page
						);
						await waitForConvergence(
							activePages(),
							CONVERGENCE_TIMEOUT_MS
						);
						await assertNoInvalidBlocks(
							participants[ reloadIndex ].page,
							'post-reload'
						);
					}
				}

				// Final persistence round-trip: save from one page, reload
				// another, and require full convergence — a
				// server-authoritative engine must rebuild the same document
				// for a fresh session.
				record( {
					label: 'final-save',
					step: STEP_COUNT,
					userIndex: 0,
				} );
				await saveDraftFromPage( participants[ 0 ].page );
				const finalState = await waitForConvergence(
					activePages(),
					CONVERGENCE_TIMEOUT_MS
				);
				if ( ! DISABLE_RELOAD && participants.length > 1 ) {
					await reloadPage(
						collaborationUtils,
						participants[ 1 ].page
					);
					const settled = await waitForConvergence(
						activePages(),
						CONVERGENCE_TIMEOUT_MS
					);
					expect( JSON.stringify( settled ) ).toBe(
						JSON.stringify( finalState )
					);
					await assertNoInvalidBlocks(
						participants[ 1 ].page,
						'final reload'
					);
				}

				expect( finalState.blockCount ).toBeGreaterThan( 0 );
				const saved = ( await requestUtils.rest( {
					params: { context: 'edit' },
					path: `/wp/v2/posts/${ post.id }`,
				} ) ) as {
					content: { raw: string };
					title: { raw: string };
				};
				expect( saved.title.raw ).toBe( finalState.title );
				expect( saved.content.raw.length ).toBeGreaterThan( 0 );
			} finally {
				await testInfo.attach( 'fuzz-run.json', {
					body: JSON.stringify(
						{
							engine: ENGINE,
							seed,
							stepCount: STEP_COUNT,
							trace,
							transport: TRANSPORT,
							userCount: USER_COUNT,
						},
						null,
						'\t'
					),
					contentType: 'application/json',
				} );
			}
		} );
	}
} );
