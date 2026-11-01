#!/usr/bin/env node
// Checks the preview libraries we ship against GitHub's security advisories. Exits non-zero on a
// finding, or if the check itself can't be trusted, so the weekly workflow fails and emails the owner.
// Versions come from assets/viewer/lib/README.md and are checked against the files actually shipped.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const LIB = join( ROOT, 'assets/viewer/lib' );

// docx-preview doesn't carry its version in its bundle, so it is compared with the build script instead.
const LIBRARIES = [
	{ pkg: 'pdfjs-dist', file: 'pdf.min.js', built: 'PDFJS' },
	{ pkg: 'pdfjs-dist', file: 'pdf.worker.min.js', built: 'PDFJS' },
	{ pkg: 'docx-preview', file: 'docx-preview.min.js', built: 'DOCX', unmarked: true },
	{ pkg: 'jszip', file: 'jszip.min.js' },
	{ pkg: 'xlsx', file: 'xlsx.full.min.js' },
];

// Old versions with published flaws. If these stop being reported, a clean result means nothing.
const KNOWN_BAD = [
	[ 'pdfjs-dist', '3.11.174', 'GHSA-wgrm-67xf-hhpq' ],
	[ 'xlsx', '0.18.5', 'GHSA-5pgg-2g8v-p4x9' ],
];

async function advisories( pkg, version ) {
	const headers = { Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28' };
	if ( process.env.GITHUB_TOKEN ) {
		headers.Authorization = `Bearer ${ process.env.GITHUB_TOKEN }`;
	}
	const query = new URLSearchParams( { ecosystem: 'npm', affects: `${ pkg }@${ version }`, per_page: '100' } );
	const response = await fetch( `https://api.github.com/advisories?${ query }`, { headers } );
	if ( ! response.ok ) {
		throw new Error( `GitHub advisories answered ${ response.status } for ${ pkg }@${ version }` );
	}
	return response.json();
}

const readme = readFileSync( join( LIB, 'README.md' ), 'utf8' );
const build = readFileSync( join( ROOT, 'bin/build-viewer-libs.sh' ), 'utf8' );
const problems = [];
const versions = new Map();

for ( const { pkg, file, built, unmarked } of LIBRARIES ) {
	const row = readme.split( '\n' ).find( ( line ) => line.startsWith( '|' ) && line.includes( `\`${ file }\`` ) );
	const version = row?.split( '|' )[ 3 ]?.trim();
	if ( ! /^\d+\.\d+\.\d+$/.test( version ?? '' ) ) {
		problems.push( `${ file }: no version listed in the library README` );
		continue;
	}
	versions.set( pkg, version );
	if ( ! unmarked && ! readFileSync( join( LIB, file ), 'latin1' ).includes( version ) ) {
		problems.push( `${ file }: the README says ${ version }, but that isn't the version in the file` );
	}
	if ( built && build.match( new RegExp( `^${ built }=(.+)$`, 'm' ) )?.[ 1 ] !== version ) {
		problems.push( `${ file }: the README says ${ version }, but bin/build-viewer-libs.sh builds another version` );
	}
}

for ( const [ pkg, version, expected ] of KNOWN_BAD ) {
	if ( ! ( await advisories( pkg, version ) ).some( ( found ) => found.ghsa_id === expected ) ) {
		problems.push( `The advisory lookup missed ${ expected } in ${ pkg }@${ version }, so it can't be trusted` );
	}
}

for ( const [ pkg, version ] of versions ) {
	const found = await advisories( pkg, version );
	console.log( `${ pkg }@${ version }: ${ found.length } known flaw(s)` );
	for ( const { ghsa_id: id, severity, summary, html_url: url } of found ) {
		problems.push( `${ pkg }@${ version } has a known flaw (${ severity }): ${ summary } ${ id } ${ url }` );
	}
}

if ( problems.length ) {
	console.error( '\nProblems found:\n' + problems.map( ( problem ) => `- ${ problem }` ).join( '\n' ) );
	process.exit( 1 );
}
console.log( '\nNo known flaws in the bundled libraries.' );
