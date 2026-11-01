#!/usr/bin/env node
// Signs a draft release and publishes it. Installed sites refuse an update without this signature.
// Usage: node bin/sign-release.mjs 1.2.3 ~/embed-drive-signing.pem
import { execFileSync } from 'node:child_process';
import { createHash, createPrivateKey, createPublicKey, sign } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { createInterface } from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const [ version, keyFile ] = process.argv.slice( 2 );
const run = ( command, ...args ) => execFileSync( command, args, { cwd: ROOT, encoding: 'utf8' } ).trim();
const fail = ( message ) => {
	console.error( message );
	process.exit( 1 );
};

if ( ! /^\d+\.\d+\.\d+$/.test( version || '' ) || ! keyFile ) {
	fail( 'Usage: node bin/sign-release.mjs 1.2.3 ~/embed-drive-signing.pem' );
}
const tag = `v${ version }`;

const key = createPrivateKey( readFileSync( keyFile ) );
const publicKey = createPublicKey( key ).export( { type: 'spki', format: 'der' } ).subarray( -32 ).toString( 'base64' );
const expected = readFileSync( join( ROOT, 'includes/Updater.php' ), 'utf8' ).match( /const PUBLIC_KEY = '([^']+)'/ )?.[ 1 ];
if ( publicKey !== expected ) {
	fail( 'This private key does not match PUBLIC_KEY in includes/Updater.php. Nothing was signed.' );
}

const release = JSON.parse( run( 'gh', 'release', 'view', tag, '--json', 'isDraft' ) );
if ( ! release.isDraft ) {
	fail( `${ tag } is not a draft, so sites can already see it. Nothing was signed.` );
}

const dir = mkdtempSync( join( tmpdir(), 'embed-drive-sign-' ) );
try {
	run( 'gh', 'release', 'download', tag, '-p', 'embed-drive.zip', '-D', dir );
	const hash = createHash( 'sha256' ).update( readFileSync( join( dir, 'embed-drive.zip' ) ) ).digest( 'hex' );
	const signature = join( dir, 'embed-drive.zip.sig' );
	writeFileSync( signature, sign( null, Buffer.from( `embed-drive:${ version }:${ hash }` ), key ).toString( 'base64' ) );

	run( 'git', 'fetch', '--quiet', '--tags', 'origin' );
	console.log( `\nRelease ${ tag } was built from:\n  ${ run( 'git', 'log', '-1', '--format=%h %an: %s', tag ) }\n` );
	const prompt = createInterface( { input: process.stdin, output: process.stdout } );
	const answer = await prompt.question( 'Is that your commit? Type yes to sign and publish: ' );
	prompt.close();
	if ( answer.trim().toLowerCase() !== 'yes' ) {
		fail( 'Stopped. Nothing was published.' );
	}

	run( 'gh', 'release', 'upload', tag, signature );
	run( 'gh', 'release', 'edit', tag, '--draft=false', '--latest' );
	console.log( `Signed and published ${ tag }.` );
} finally {
	rmSync( dir, { recursive: true, force: true } );
}
