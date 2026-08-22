#!/usr/bin/env node
/**
 * Mirrors plan items to GitHub Issues.
 *
 * The Markdown file is the source of truth. This pushes it to an issue
 * and writes the issue number back into the file's frontmatter. It
 * never reads changes back from GitHub, because two-way sync rots.
 *
 * Usage:
 *   node docs/plan/mirror.mjs --dry-run          preview every change
 *   node docs/plan/mirror.mjs                    push every item
 *   node docs/plan/mirror.mjs items/0001-*.md    push specific items
 *
 * This creates and edits issues on the plugin repository, which is a
 * public action. Run it yourself; do not ask an agent to run it for
 * you unless you have just told it to, in those words.
 *
 * Needs the gh CLI, signed in (gh auth status).
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const planDir = dirname( fileURLToPath( import.meta.url ) );
const itemsDir = join( planDir, 'items' );
const repoRoot = resolve( planDir, '..', '..' );

const args = process.argv.slice( 2 );
const dryRun = args.includes( '--dry-run' );
const paths = args.filter( ( arg ) => ! arg.startsWith( '--' ) );

const STATUS_LABELS = {
	shaping: 'needs shaping',
	ready: 'ready',
	'in progress': 'in progress',
	done: 'done',
};

function gh( ...cliArgs ) {
	return execFileSync( 'gh', cliArgs, {
		cwd: repoRoot,
		encoding: 'utf8',
		maxBuffer: 10 * 1024 * 1024,
	} ).trim();
}

function parse( path ) {
	const text = readFileSync( path, 'utf8' );
	const match = text.match( /^---\n([\s\S]+?)\n---\n([\s\S]*)$/ );
	if ( ! match ) {
		throw new Error( `${ path }: no frontmatter` );
	}

	const fields = {};
	for ( const line of match[ 1 ].split( '\n' ) ) {
		const pair = line.match( /^(\w+):\s*(.*)$/ );
		if ( pair ) {
			fields[ pair[ 1 ] ] = pair[ 2 ].trim();
		}
	}

	return { text, fields, body: match[ 2 ].trim() };
}

/**
 * The issue body is the item's own Markdown, with a line at the top
 * pointing back at the file so nobody edits the copy by mistake.
 */
function issueBody( path, body ) {
	const rel = relative( repoRoot, path );
	return [
		`> Mirrored from [\`${ rel }\`](${ rel }) — **edit the file, not this issue.**`,
		'> Discussion belongs here; the specification lives in the file.',
		'',
		body,
	].join( '\n' );
}

function writeIssueNumber( path, text, number ) {
	const updated = text.replace( /^(---\n[\s\S]*?)github:.*$/m, `$1github: ${ number }` );
	writeFileSync( path, updated );
}

const targets = paths.length
	? paths.map( ( path ) => resolve( path ) )
	: readdirSync( itemsDir )
			.filter( ( name ) => name.endsWith( '.md' ) )
			.map( ( name ) => join( itemsDir, name ) );

for ( const path of targets ) {
	const { text, fields, body } = parse( path );
	const label = STATUS_LABELS[ fields.status ];
	const rendered = issueBody( path, body );

	if ( fields.github ) {
		console.log( `update  #${ fields.github }  ${ fields.title }` );
		if ( ! dryRun ) {
			gh( 'issue', 'edit', fields.github, '--title', fields.title, '--body', rendered );
		}
		continue;
	}

	console.log( `create        ${ fields.title }` );
	if ( dryRun ) {
		continue;
	}

	const url = gh(
		'issue',
		'create',
		'--title',
		fields.title,
		'--body',
		rendered,
		...( label ? [ '--label', label ] : [] )
	);
	const number = url.trim().split( '/' ).pop();
	writeIssueNumber( path, text, number );
	console.log( `        ->  ${ url }` );
}

if ( dryRun ) {
	console.log( '\nDry run. Nothing was sent to GitHub.' );
} else {
	console.log( '\nDone. Commit the issue numbers written back into the files.' );
}
