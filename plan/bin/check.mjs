#!/usr/bin/env node
/**
 * Checks an issue body against the rules in plan/README.md.
 *
 * Usage:
 *   node plan/bin/check.mjs draft.md               a drafted body, before filing
 *   node plan/bin/check.mjs --issue 12             one issue already on GitHub
 *   node plan/bin/check.mjs --label agent:ready    every open issue with a label
 *
 * The most useful check is the last one: it reads docs/glossary.md and
 * reports any of our invented words used above the notes section, where
 * a newcomer would hit them. That check is deliberately eager — it
 * flags words for a human to look at, it does not know what you meant.
 *
 * Run it before `gh issue create` or `gh issue edit`. Reports filed by
 * humans are NOT held to this: the rule exists to stop agents writing
 * like the inside of the codebase, not to police anyone describing a
 * problem in their own words.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const planDir = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const glossaryPath = resolve( planDir, '..', 'docs', 'glossary.md' );

const NOTES_HEADING = '## Notes for whoever picks this up';

const REQUIRED_SECTIONS = [
	'## What happens now',
	'## Example',
	'## What should happen instead',
	'## How we will know it is done',
	NOTES_HEADING,
];

function gh( ...args ) {
	return execFileSync( 'gh', args, { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 } );
}

/**
 * Pulls the defined terms out of the glossary.
 *
 * Entries look like `- **Void / voided** — the server threw it away`.
 * Slashes separate spellings of one term and parentheses hold an
 * expansion, so both become terms of their own.
 */
function glossaryTerms() {
	const terms = new Set();

	for ( const line of readFileSync( glossaryPath, 'utf8' ).split( '\n' ) ) {
		const match = line.match( /^- \*\*(.+?)\*\*/ );
		if ( ! match ) {
			continue;
		}

		for ( const part of match[ 1 ].split( '/' ) ) {
			const expansion = part.match( /\((.+?)\)/ );
			if ( expansion ) {
				terms.add( expansion[ 1 ].trim() );
			}
			const bare = part.replace( /\(.*?\)/g, '' ).trim();
			if ( bare ) {
				terms.add( bare );
			}
		}
	}

	return [ ...terms ].filter( ( term ) => term.length > 2 );
}

/**
 * Matches a term and its ordinary endings, so "materialize" also
 * catches "materializes" and "materialized".
 */
function termPattern( term ) {
	const escaped = term.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const stem = escaped.replace( /e$/i, '' );
	return new RegExp( `\\b${ stem }(e|es|ed|ing|s)?\\b`, 'gi' );
}

function checkBody( title, body, terms ) {
	const problems = [];
	const warnings = [];

	for ( const section of REQUIRED_SECTIONS ) {
		if ( ! body.includes( section ) ) {
			problems.push( `Missing section: ${ section }` );
		}
	}

	const [ top, notes ] = body.split( NOTES_HEADING );
	if ( notes && ! /^\s*1\.\s+\S/m.test( top ) ) {
		problems.push( 'The example has no numbered steps.' );
	}

	if ( /still being shaped|not yet decided/i.test( body )
		&& ! /decision (that is )?missing|missing decision|decision to make/i.test( body ) ) {
		warnings.push(
			'Says it is still being shaped but never names the missing decision.'
		);
	}

	// File paths, commands, quotes and identifiers legitimately contain
	// our vocabulary, so only prose is checked. The title counts: it is
	// the part most people read.
	const prose = `${ title }\n${ top ?? body }`
		.replace( /```[\s\S]*?```/g, ' ' )
		.replace( /`[^`]*`/g, ' ' )
		.replace( /^\s*>.*$/gm, ' ' );

	const found = new Map();
	for ( const term of terms ) {
		const hits = prose.match( termPattern( term ) );
		if ( hits ) {
			found.set( term, hits.length );
		}
	}

	return { problems, warnings, found };
}

const args = process.argv.slice( 2 );
const targets = [];

for ( let i = 0; i < args.length; i++ ) {
	if ( args[ i ] === '--issue' ) {
		const number = args[ ++i ];
		const data = JSON.parse( gh( 'issue', 'view', number, '--json', 'title,body' ) );
		targets.push( { label: `#${ number }`, title: data.title, body: data.body ?? '' } );
	} else if ( args[ i ] === '--label' ) {
		const wanted = args[ ++i ];
		const list = JSON.parse(
			gh(
				'issue', 'list', '--label', wanted, '--state', 'open',
				'--json', 'number,title,body', '--limit', '100'
			)
		);
		for ( const issue of list ) {
			targets.push( {
				label: `#${ issue.number } ${ issue.title }`,
				title: issue.title,
				body: issue.body ?? '',
			} );
		}
	} else {
		targets.push( { label: args[ i ], title: '', body: readFileSync( args[ i ], 'utf8' ) } );
	}
}

if ( ! targets.length ) {
	console.log( 'Nothing to check. Pass a file, --issue <number>, or --label <name>.' );
	process.exit( 2 );
}

const terms = glossaryTerms();
let failed = 0;

for ( const target of targets ) {
	const { problems, warnings, found } = checkBody( target.title, target.body, terms );

	if ( ! problems.length && ! warnings.length && ! found.size ) {
		console.log( `ok    ${ target.label }` );
		continue;
	}

	console.log( `\n${ target.label }` );
	for ( const problem of problems ) {
		console.log( `  problem  ${ problem }` );
	}
	for ( const warning of warnings ) {
		console.log( `  warning  ${ warning }` );
	}
	if ( found.size ) {
		console.log( '  jargon   These glossary words appear before the notes section.' );
		console.log( '           Say what they mean instead, or move the sentence down:' );
		for ( const [ term, count ] of found ) {
			console.log( `             ${ term }${ count > 1 ? ` (x${ count })` : '' }` );
		}
	}

	if ( problems.length || found.size ) {
		failed += 1;
	}
}

if ( failed ) {
	console.log( `\n${ failed } of ${ targets.length } need attention.` );
	process.exit( 1 );
}

console.log( `\nAll ${ targets.length } look fine.` );
