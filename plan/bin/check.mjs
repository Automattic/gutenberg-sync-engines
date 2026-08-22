#!/usr/bin/env node
/**
 * Checks a plan issue against the rules in plan/README.md.
 *
 * Usage: node plan/bin/check.mjs [file...]
 * With no arguments it checks every issue in plan/issues/.
 *
 * The most useful check is the last one: it reads docs/glossary.md and
 * reports any of our invented words used above the notes section, where
 * a newcomer would hit them. That check is deliberately eager — it
 * flags words for a human to look at, it does not know what you meant.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const planDir = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const issuesDir = join( planDir, 'issues' );
const glossaryPath = resolve( planDir, '..', 'docs', 'glossary.md' );

const NOTES_HEADING = '## Notes for whoever picks this up';

const REQUIRED_SECTIONS = [
	'## What happens now',
	'## Example',
	'## What should happen instead',
	'## How we will know it is done',
	NOTES_HEADING,
];

const REQUIRED_FIELDS = [ 'id', 'title', 'status', 'size' ];
const VALID_STATUS = [ 'shaping', 'ready', 'in progress', 'done' ];
const VALID_SIZE = [ 'small', 'medium', 'large' ];

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

function frontmatter( text ) {
	const match = text.match( /^---\n([\s\S]+?)\n---\n/ );
	if ( ! match ) {
		return null;
	}

	const fields = {};
	for ( const line of match[ 1 ].split( '\n' ) ) {
		const pair = line.match( /^(\w+):\s*(.*)$/ );
		if ( pair ) {
			fields[ pair[ 1 ] ] = pair[ 2 ].trim();
		}
	}
	return fields;
}

function checkIssue( path, terms ) {
	const text = readFileSync( path, 'utf8' );
	const problems = [];
	const warnings = [];

	const fields = frontmatter( text );
	if ( ! fields ) {
		problems.push( 'No frontmatter block at the top of the file.' );
	} else {
		for ( const field of REQUIRED_FIELDS ) {
			if ( ! fields[ field ] ) {
				problems.push( `Frontmatter is missing "${ field }".` );
			}
		}
		if ( fields.status && ! VALID_STATUS.includes( fields.status ) ) {
			problems.push(
				`Status "${ fields.status }" is not one of: ${ VALID_STATUS.join( ', ' ) }.`
			);
		}
		if ( fields.size && ! VALID_SIZE.includes( fields.size ) ) {
			problems.push(
				`Size "${ fields.size }" is not one of: ${ VALID_SIZE.join( ', ' ) }.`
			);
		}
		if ( fields.status === 'shaping' && ! /missing|decide|decision/i.test( text ) ) {
			warnings.push(
				'Status is "shaping" but the file never says which decision is missing.'
			);
		}
	}

	for ( const section of REQUIRED_SECTIONS ) {
		if ( ! text.includes( section ) ) {
			problems.push( `Missing section: ${ section }` );
		}
	}

	const [ top, notes ] = text.split( NOTES_HEADING );
	if ( ! notes ) {
		// Already reported as a missing section.
	} else if ( ! /^\s*1\.\s+\S/m.test( top ) ) {
		problems.push( 'The example has no numbered steps.' );
	}

	const heading = text.match( /^# (.+)$/m );
	if ( heading && fields?.title && heading[ 1 ].trim() !== fields.title ) {
		warnings.push( 'The heading and the frontmatter title do not match.' );
	}

	// File paths, commands and identifiers legitimately contain our
	// vocabulary, so only prose is checked for jargon.
	const prose = ( top ?? text )
		.replace( /```[\s\S]*?```/g, ' ' )
		.replace( /`[^`]*`/g, ' ' );

	const found = new Map();
	for ( const term of terms ) {
		const hits = prose.match( termPattern( term ) );
		if ( hits ) {
			found.set( term, hits.length );
		}
	}

	return { problems, warnings, found };
}

const files = process.argv.slice( 2 );
const targets = files.length
	? files.map( ( file ) => resolve( file ) )
	: readdirSync( issuesDir )
			.filter( ( name ) => name.endsWith( '.md' ) )
			.map( ( name ) => join( issuesDir, name ) );

const terms = glossaryTerms();
let failed = 0;

for ( const path of targets ) {
	const { problems, warnings, found } = checkIssue( path, terms );
	const label = path.replace( `${ process.cwd() }/`, '' );

	if ( ! problems.length && ! warnings.length && ! found.size ) {
		console.log( `ok    ${ label }` );
		continue;
	}

	console.log( `\n${ label }` );
	for ( const problem of problems ) {
		console.log( `  problem  ${ problem }` );
	}
	for ( const warning of warnings ) {
		console.log( `  warning  ${ warning }` );
	}
	if ( found.size ) {
		console.log(
			'  jargon   These glossary words appear before the notes section.'
		);
		console.log(
			'           Say what they mean instead, or move the sentence down:'
		);
		for ( const [ term, count ] of found ) {
			console.log( `             ${ term }${ count > 1 ? ` (x${ count })` : '' }` );
		}
	}

	if ( problems.length || found.size ) {
		failed += 1;
	}
}

if ( failed ) {
	console.log( `\n${ failed } issue(s) need attention.` );
	process.exit( 1 );
}

console.log( `\nAll ${ targets.length } issue(s) look fine.` );
