// End-to-end tests against a running WordPress with the plugin and tests/mock-endpoints.php loaded.
// Usage: node tests/e2e.mjs http://127.0.0.1:9500 http://127.0.0.1:9400
import { execFileSync } from 'node:child_process';
import { createHash, generateKeyPairSync, sign } from 'node:crypto';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import assert from 'node:assert/strict';

const SITE = process.argv[ 2 ] || 'http://127.0.0.1:9500';
const MOCK = process.argv[ 3 ] || 'http://127.0.0.1:9400';

const api = ( route ) => `${ SITE }/?rest_route=/embed-drive/v1/${ route }`;
const test_ = async ( cmd, params = {} ) => {
	const r = await fetch( `${ SITE }/?rest_route=/embed-drive-test/v1/do`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { cmd, ...params } ),
	} );
	const text = await r.text();
	try {
		return JSON.parse( text );
	} catch {
		throw new Error( `${ cmd }: non-JSON (${ r.status }): ${ text.slice( 0, 800 ) }` );
	}
};
const get = ( route, params = {}, headers = {} ) => {
	const url = new URL( api( route ) );
	Object.entries( params ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
	return fetch( url, { headers, redirect: 'manual' } );
};
const tokenFrom = ( html ) => {
	const m = html.match( /data-embed-drive="([^"]+)"/ );
	assert.ok( m, 'module markup present: ' + html.slice( 0, 300 ) );
	return JSON.parse( m[ 1 ].replaceAll( '&quot;', '"' ) );
};

const results = [];
async function it( name, fn ) {
	try {
		await fn();
		results.push( [ true, name ] );
		console.log( 'ok   ', name );
	} catch ( error ) {
		results.push( [ false, name ] );
		console.log( 'FAIL ', name, '\n      ', String( error.message ).slice( 0, 600 ) );
	}
}

const connected = await test_( 'connect' );
assert.ok( connected.account, 'connect failed: ' + JSON.stringify( connected ).slice( 0, 900 ) );
const { account } = connected;
const render = async ( code ) => ( await test_( 'render', { code } ) ).html;
const files = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) );
const m = files.token;

await it( 'credentials are encrypted at rest', async () => {
	const { raw } = await test_( 'stored_token' );
	const decoded = Buffer.from( raw, 'base64' ).toString( 'latin1' );
	assert.ok( ! decoded.includes( 'refresh-1' ) && ! raw.includes( 'refresh-1' ) );
} );

await it( 'lists the chosen folder, with shortcuts hidden', async () => {
	const data = await ( await get( 'list', { m } ) ).json();
	const names = data.items.map( ( i ) => i.name );
	assert.ok( names.includes( 'guide.pdf' ) && names.includes( 'Sub Folder' ) );
	assert.ok( ! names.includes( 'link-to-secret' ) );
	assert.deepEqual( data.breadcrumbs.map( ( c ) => c.name ), [ 'Public Folder' ] );
} );

await it( 'browses into a subfolder with breadcrumbs', async () => {
	const data = await ( await get( 'list', { m, folder: 'sub' } ) ).json();
	assert.deepEqual( data.breadcrumbs.map( ( c ) => c.name ), [ 'Public Folder', 'Sub Folder' ] );
	assert.equal( data.items[ 0 ].name, "O'Brien notes.txt" );
} );

// --- The security boundary: a module can only reach its own folder. ---
await it( 'SECURITY: cannot list a sibling folder or the parent', async () => {
	assert.equal( ( await get( 'list', { m, folder: 'secret' } ) ).status, 404 );
	assert.equal( ( await get( 'list', { m, folder: 'root-id' } ) ).status, 404 );
	assert.equal( ( await get( 'list', { m, folder: 'root' } ) ).status, 404 );
} );
await it( 'SECURITY: cannot download or preview a file outside the folder', async () => {
	assert.equal( ( await get( 'file', { m, id: 'secret-file', disp: 'attachment' } ) ).status, 404 );
	assert.equal( ( await get( 'file', { m, id: 'secret-file', disp: 'inline' } ) ).status, 404 );
	assert.equal( ( await get( 'thumb', { m, id: 'secret-file' } ) ).status, 404 );
} );
await it( 'SECURITY: missing and forbidden files look identical', async () => {
	const a = await ( await get( 'file', { m, id: 'secret-file' } ) ).json();
	const b = await ( await get( 'file', { m, id: 'does-not-exist' } ) ).json();
	assert.equal( a.code, b.code );
	assert.equal( a.message, b.message );
} );
await it( 'SECURITY: tampered, unsigned and missing tokens are rejected', async () => {
	const [ payload, sig ] = m.split( '.' );
	const decoded = JSON.parse( Buffer.from( payload, 'base64url' ) );
	const widened = Buffer.from( JSON.stringify( { ...decoded, r: 'root-id' } ) ).toString( 'base64url' );
	assert.equal( ( await get( 'list', { m: `${ widened }.${ sig }` } ) ).status, 404 );
	assert.equal( ( await get( 'list', { m: widened } ) ).status, 404 );
	assert.equal( ( await get( 'list', {} ) ).status, 404 );
} );
await it( 'SECURITY: search cannot find files outside the folder', async () => {
	const data = await ( await get( 'search', { m, q: 'salaries' } ) ).json();
	assert.equal( data.items.length, 0 );
} );
await it( 'SECURITY: a ZIP cannot include files outside the folder', async () => {
	const r = await fetch( api( 'zip/prepare' ) + '&m=' + m, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { ids: [ 'pub-pdf', 'secret-file' ] } ),
	} );
	assert.equal( r.status, 404 );
} );
await it( 'SECURITY: a file module cannot reach anything but its file', async () => {
	const html = await render( '[embed_drive mode="button" file="pub-pdf"]' );
	const token = new URL( html.match( /href="([^"]+)"/ )[ 1 ].replaceAll( '&#038;', '&' ).replaceAll( '&amp;', '&' ) ).searchParams.get( 'm' );
	assert.equal( ( await get( 'file', { m: token, id: 'pub-pdf' } ) ).status, 200 );
	assert.equal( ( await get( 'file', { m: token, id: 'pub-img' } ) ).status, 404 );
	assert.equal( ( await get( 'list', { m: token } ) ).status, 404 );
} );
await it( 'SECURITY: download=no blocks attachments and ZIP, keeps previews', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public" download="no"]' ) );
	assert.equal( t.zip, false );
	assert.equal( ( await get( 'file', { m: t.token, id: 'pub-pdf', disp: 'attachment' } ) ).status, 404 );
	assert.equal( ( await get( 'file', { m: t.token, id: 'pub-pdf', disp: 'inline' } ) ).status, 200 );
	const r = await fetch( api( 'zip/prepare' ) + '&m=' + t.token, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { ids: [ 'pub-pdf' ] } ),
	} );
	assert.equal( r.status, 404 );
} );
await it( 'SECURITY: preview=no blocks inline viewing', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public" preview="no"]' ) );
	assert.equal( ( await get( 'file', { m: t.token, id: 'pub-pdf', disp: 'inline' } ) ).status, 404 );
} );
await it( 'SECURITY: search=no blocks the search route', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public" search="no"]' ) );
	assert.equal( ( await get( 'search', { m: t.token, q: 'guide' } ) ).status, 404 );
} );
await it( 'SECURITY: HTML and SVG are never served inline', async () => {
	for ( const id of [ 'pub-html', 'pub-svg' ] ) {
		const r = await get( 'file', { m, id, disp: 'inline' } );
		assert.equal( r.status, 200 );
		assert.match( r.headers.get( 'content-disposition' ), /^attachment/ );
		assert.equal( r.headers.get( 'content-type' ), 'application/octet-stream' );
		assert.equal( r.headers.get( 'x-content-type-options' ), 'nosniff' );
		await r.arrayBuffer();
	}
} );
await it( 'SECURITY: inline text is sandboxed', async () => {
	const r = await get( 'file', { m, id: 'pub-dup1', disp: 'inline' } );
	assert.match( r.headers.get( 'content-security-policy' ), /sandbox/ );
	await r.arrayBuffer();
} );
await it( 'SECURITY: the bearer token only goes to allowed thumbnail hosts', async () => {
	const r = await get( 'thumb', { m, id: 'pub-img' } );
	assert.equal( r.status, 200 );
	assert.equal( r.headers.get( 'content-type' ), 'image/png' );
	await r.arrayBuffer();
} );
await it( 'SECURITY: post authors who cannot embed get nothing', async () => {
	const code = '[embed_drive mode="files" folder="root-id"]';
	const author = await test_( 'render_as_post', { code, role: 'author' } );
	const blocked = await ( await fetch( author.url ) ).text();
	assert.ok( ! blocked.includes( 'data-embed-drive' ), 'author post must not render a module' );
	const editor = await test_( 'render_as_post', { code, role: 'editor' } );
	const allowed = await ( await fetch( editor.url ) ).text();
	assert.ok( allowed.includes( 'data-embed-drive' ), 'editor post renders' );
} );
await it( 'SECURITY: a synced pattern from someone who cannot embed shows nothing, even on an Editor\'s page', async () => {
	const block = '<!-- wp:embed-drive/module {"folder":"root-id"} /-->';
	const shortcode = '<!-- wp:paragraph --><p>[embed_drive mode="files" folder="root-id"] and [[embed_drive folder="x"]]</p><!-- /wp:paragraph -->';
	const page = async ( params ) => ( await fetch( ( await test_( 'pattern_page', params ) ).url ) ).text();
	const shows = ( html ) => html.includes( 'data-embed-drive' );

	assert.ok( shows( await page( { made_by: 'editor', code: block } ) ), "an Editor's pattern works" );
	assert.ok( shows( await page( { made_by: 'editor', code: shortcode } ) ), "an Editor's pattern with a shortcode works" );
	assert.ok( ! shows( await page( { made_by: 'author', code: block } ) ), "an Author's pattern with the block" );
	const viaShortcode = await page( { made_by: 'author', code: shortcode } );
	assert.ok( ! shows( viaShortcode ), "an Author's pattern with the shortcode" );
	assert.ok( ! viaShortcode.includes( 'root-id' ), 'the removed shortcode is not printed either' );
	assert.ok( viaShortcode.includes( '[embed_drive folder="x"]' ), 'an escaped shortcode is left as text' );
	assert.ok( ! shows( await page( { made_by: 'editor', changed_by: 'author', code: block } ) ), 'an Editor\'s pattern last changed by an Author' );
	assert.ok( ! shows( await page( { made_by: 'author', changed_by: 'editor', code: block } ) ), "an Editor changing an Author's pattern does not approve it" );
	assert.ok( ! shows( await page( { made_by: 'author', code: block, wrapped: true } ) ), "an Author's pattern inside an Editor's pattern" );
} );
await it( 'SECURITY: admin routes need login', async () => {
	assert.ok( [ 401, 403 ].includes( ( await get( 'admin/browse', { account } ) ).status ) );
} );
await it( 'SECURITY: quotes in a search cannot break the Drive query', async () => {
	const r = await get( 'search', { m, q: "O'Brien" } );
	assert.equal( r.status, 200 );
	const data = await r.json();
	assert.deepEqual( data.items.map( ( i ) => i.name ), [ "O'Brien notes.txt" ] );
} );

// --- Features ---
await it( 'search finds nested files by name and by content', async () => {
	const byContent = await ( await get( 'search', { m, q: 'needle' } ) ).json();
	assert.deepEqual( byContent.items.map( ( i ) => i.id ), [ 'sub-file' ] );
	assert.equal( ( await get( 'search', { m, q: 'x' } ) ).status, 400 );
} );
await it( 'search-only module works but cannot list', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="search" folder="public"]' ) );
	assert.equal( ( await get( 'list', { m: t.token } ) ).status, 404 );
	assert.equal( ( await get( 'search', { m: t.token, q: 'guide' } ) ).status, 200 );
	assert.equal( t.zip, false );
} );
await it( 'downloads the exact bytes as an attachment', async () => {
	const r = await get( 'file', { m, id: 'pub-big', disp: 'attachment' } );
	assert.equal( r.status, 200 );
	const buf = Buffer.from( await r.arrayBuffer() );
	assert.equal( buf.length, 3 * 1024 * 1024 );
	assert.ok( buf.equals( Buffer.alloc( 3 * 1024 * 1024, 'abcdefghij' ) ), 'every piece arrives, in order' );
	assert.equal( r.headers.get( 'content-length' ), String( 3 * 1024 * 1024 ) );
	assert.match( r.headers.get( 'content-disposition' ), /attachment; filename="big.bin"/ );
} );
await it( 'supports range requests for seeking in media', async () => {
	const r = await get( 'file', { m, id: 'pub-big', disp: 'attachment' }, { Range: 'bytes=10-19' } );
	assert.equal( r.status, 206 );
	assert.equal( r.headers.get( 'content-range' ), `bytes 10-19/${ 3 * 1024 * 1024 }` );
	assert.equal( Buffer.from( await r.arrayBuffer() ).toString(), 'abcdefghij' );

	const size = 3 * 1024 * 1024;
	const whole = Buffer.alloc( size, 'abcdefghij' );
	for ( const [ asked, from, to ] of [
		[ 'bytes=262140-262159', 262140, 262159 ], // Across the join between two pieces.
		[ 'bytes=3000000-', 3000000, size - 1 ], // From a point to the end, as video players ask.
		[ 'bytes=-15', size - 15, size - 1 ], // The final bytes.
		[ 'bytes=-600000', size - 600000, size - 1 ], // Final bytes spanning several pieces.
		[ 'bytes=100-99999999', 100, size - 1 ], // An end past the file.
	] ) {
		const part = await get( 'file', { m, id: 'pub-big', disp: 'attachment' }, { Range: asked } );
		assert.equal( part.status, 206, asked );
		assert.equal( part.headers.get( 'content-range' ), `bytes ${ from }-${ to }/${ size }`, asked );
		assert.ok( Buffer.from( await part.arrayBuffer() ).equals( whole.subarray( from, to + 1 ) ), asked );
	}
	assert.equal( ( await get( 'file', { m, id: 'pub-big', disp: 'attachment' }, { Range: `bytes=${ size }-` } ) ).status, 416 );

	const empty = await get( 'file', { m, id: 'pub-empty', disp: 'attachment' } );
	assert.equal( empty.status, 200, 'an empty file downloads as an empty file' );
	assert.equal( ( await empty.arrayBuffer() ).byteLength, 0 );
} );
await it( 'exports Google Docs: Word for download, PDF for preview', async () => {
	const dl = await get( 'file', { m, id: 'pub-doc', disp: 'attachment' } );
	assert.match( dl.headers.get( 'content-disposition' ), /Report\.docx/ );
	assert.match( await dl.text(), /wordprocessingml/ );
	const view = await get( 'file', { m, id: 'pub-doc', disp: 'inline' } );
	assert.equal( view.headers.get( 'content-type' ), 'application/pdf' );
	assert.match( await view.text(), /application\/pdf/ );
} );
await it( 'unsupported Google types are refused clearly', async () => {
	assert.equal( ( await get( 'file', { m, id: 'pub-form' } ) ).status, 415 );
} );
await it( 'single-file embed, button and link render', async () => {
	assert.match( await render( '[embed_drive mode="embed" file="pub-pdf" height="400"]' ), /<iframe[^>]+height="400"/ );
	assert.match( await render( '[embed_drive mode="embed" file="pub-img"]' ), /<img[^>]+alt="photo.png"/ );
	assert.match( await render( '[embed_drive mode="button" file="pub-pdf" label="Get it"]' ), />Get it<\/a>/ );
	assert.match( await render( '[embed_drive mode="link" file="pub-pdf"]' ), />guide.pdf<\/a>/ );
	assert.ok( ! ( await render( '[embed_drive mode="embed" file="pub-pdf"]' ) ).includes( 'Download' ), 'embed has no download by default' );
	assert.match( await render( '[embed_drive mode="embed" file="pub-pdf" download="yes"]' ), /Download guide.pdf/ );
} );
await it( 'block renders on a page through the same code path', async () => {
	const code = '<!-- wp:embed-drive/module {"folder":"public"} /-->';
	const page = await test_( 'create_page', { code } );
	const html = await ( await fetch( page.url ) ).text();
	assert.ok( html.includes( 'data-embed-drive' ) );
	assert.match( html, /assets\/js\/embed-drive\.js/ );
	assert.match( html, /assets\/css\/embed-drive\.css/ );
} );

await it( 'ZIP: valid archive with folders, exports, safe unique names and a failure report', async () => {
	const r = await fetch( api( 'zip/prepare' ) + '&m=' + m, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { ids: [ 'public' ] } ),
	} );
	assert.equal( r.status, 200, await r.clone().text() );
	const { url } = await r.json();
	const zipResponse = await fetch( url );
	assert.equal( zipResponse.status, 200 );
	assert.equal( zipResponse.headers.get( 'content-type' ), 'application/zip' );
	const bytes = Buffer.from( await zipResponse.arrayBuffer() );

	const dir = mkdtempSync( join( tmpdir(), 'ed-zip-' ) );
	const file = join( dir, 'a.zip' );
	writeFileSync( file, bytes );

	// Two independent readers must accept it: Info-ZIP and Python's zipfile (which also checks CRCs).
	assert.match( execFileSync( 'unzip', [ '-t', file ], { encoding: 'utf8' } ), /No errors detected/ );
	const listing = JSON.parse(
		execFileSync(
			'python3',
			[ '-I', '-c', `import zipfile,json,sys
z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None
print(json.dumps({n:(len(z.read(n)), z.read(n).decode('latin1')[:80]) for n in z.namelist()}))`, file ],
			{ encoding: 'utf8' }
		)
	);
	const names = Object.keys( listing );
	assert.ok( names.includes( 'Public Folder/guide.pdf' ) );
	assert.ok( names.includes( 'Public Folder/Report.docx' ) );
	assert.ok( names.includes( "Public Folder/Sub Folder/O'Brien notes.txt" ) );
	assert.ok( names.includes( 'Public Folder/same.txt' ) && names.includes( 'Public Folder/same (2).txt' ) );
	assert.equal( listing[ 'Public Folder/big.bin' ][ 0 ], 3 * 1024 * 1024 );
	assert.ok( ! names.some( ( n ) => n.includes( 'Survey' ) ), 'forms are skipped' );
	assert.ok( ! names.some( ( n ) => n.split( '/' ).includes( '..' ) || n.startsWith( '/' ) ), 'no path traversal: ' + names );
	assert.ok( names.includes( 'files-that-could-not-be-downloaded.txt' ) );
	assert.match( listing[ 'files-that-could-not-be-downloaded.txt' ][ 1 ], /broken\.txt/ );
	assert.ok( ! names.includes( 'Public Folder/broken.txt' ), 'failed file leaves no broken entry' );

	// The link is one-time.
	assert.equal( ( await fetch( url ) ).status, 410 );
} );

await it( 'ZIP: over-limit selections get a clear message before any download', async () => {
	const prepare = () =>
		fetch( api( 'zip/prepare' ) + '&m=' + m, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { ids: [ 'public' ] } ),
		} );
	await test_( 'settings', { values: { zip_max_files: 2 } } );
	let r = await prepare();
	assert.equal( r.status, 413 );
	assert.match( ( await r.json() ).message, /limit is 2 files/ );
	await test_( 'settings', { values: { zip_max_files: 200, zip_max_mb: 1 } } );
	r = await prepare();
	assert.equal( r.status, 413 );
	await test_( 'settings', { values: { zip_max_mb: 1024 } } );
} );

await it( 'ZIP: a module without ZIP enabled is refused', async () => {
	const t = await test_( 'forge', { payload: { a: account, r: 'public', m: 'files', d: true, z: false } } );
	const r = await fetch( api( 'zip/prepare' ) + '&m=' + t.token, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { ids: [ 'pub-pdf' ] } ),
	} );
	assert.equal( r.status, 404 );
} );

await it( 'activity log records events without IP addresses', async () => {
	const rows = await test_( 'log' );
	const events = new Set( rows.map( ( r ) => r.event ) );
	for ( const e of [ 'download', 'preview', 'search', 'zip' ] ) {
		assert.ok( events.has( e ), 'has ' + e );
	}
	assert.ok( rows.some( ( r ) => r.event === 'download' && r.detail === 'big.bin' ) );
	assert.ok( ! JSON.stringify( rows ).includes( '127.0.0.1' ) );
	// A seek past the start isn't a new download: only the two full big.bin requests above plus the bytes=10-19 test.
	const bigDownloads = rows.filter( ( r ) => r.detail === 'big.bin' && r.event === 'download' );
	assert.equal( bigDownloads.length, 1, 'range requests from the middle are not counted' );
} );

await it( 'expired access tokens refresh silently', async () => {
	await test_( 'expire_token' );
	const r = await get( 'list', { m } );
	assert.equal( r.status, 200 );
	await r.json();
} );
await it( 'a token Google rejects early is refreshed and the request retried', async () => {
	await fetch( MOCK + '/__expire' );
	const r = await get( 'file', { m, id: 'pub-pdf', disp: 'attachment' } );
	assert.equal( r.status, 200 );
	assert.equal( await r.text(), '%PDF-guide' );
	await fetch( MOCK + '/__restore' );
	await test_( 'expire_token' );
	await ( await get( 'list', { m } ) ).json();
} );
await it( 'lost access: marked once, emailed once, visitors see a neutral error', async () => {
	await fetch( MOCK + '/__lose_access' );
	const first = await test_( 'check_all' );
	assert.equal( first.accounts[ account ].status, 'lost' );
	assert.equal( first.mails.length, 1 );
	assert.match( first.mails[ 0 ].message, /owner@example\.com/ );
	const second = await test_( 'check_all' );
	assert.equal( second.mails.length, 0, 'no repeat email' );

	const body = await ( await get( 'list', { m } ) ).text();
	assert.ok( ! /invalid_grant|refresh|owner@example/.test( body ), 'no details leak: ' + body );

	await fetch( MOCK + '/__restore' );
	const restored = await test_( 'check_all' );
	assert.equal( restored.accounts[ account ].status, 'ok', 'recovers by itself' );
} );

await it( 'Office files reach the viewer typed, but never render on this site', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) );
	const r = await get( 'file', { m: t.token, id: 'pub-word', disp: 'viewer' } );
	assert.equal( r.status, 200 );
	assert.match( r.headers.get( 'content-type' ), /wordprocessingml/ );
	assert.match( r.headers.get( 'content-disposition' ), /^attachment/ );
	assert.match( r.headers.get( 'content-security-policy' ), /sandbox/ );
} );

await it( 'SECURITY: the viewer link only serves viewer file types when downloads are off', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public" download="no"]' ) );
	assert.equal( ( await get( 'file', { m: t.token, id: 'pub-word', disp: 'viewer' } ) ).status, 200 );
	assert.equal( ( await get( 'file', { m: t.token, id: 'pub-big', disp: 'viewer' } ) ).status, 404 );
	assert.equal( ( await get( 'file', { m: t.token, id: 'pub-big', disp: 'inline' } ) ).status, 404 );
} );

await it( 'CSV shows as text instead of downloading', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) );
	const r = await get( 'file', { m: t.token, id: 'pub-csv', disp: 'inline' } );
	assert.match( r.headers.get( 'content-type' ), /^text\/plain/ );
	assert.match( r.headers.get( 'content-disposition' ), /^inline/ );
} );

await it( 'listing pages move forward, and caches are told not to keep them', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="many"]' ) );
	const first = await get( 'list', { m: t.token } );
	assert.equal( first.headers.get( 'cache-control' ), 'no-store' );
	const one = await first.json();
	assert.equal( one.items.length, 100 );
	const two = await ( await get( 'list', { m: t.token, cursor: one.next } ) ).json();
	assert.equal( two.items.length, 30 );
	assert.notEqual( two.items[ 0 ].id, one.items[ 0 ].id );
} );

await it( 'a file shared by link is flagged for Google\'s own preview, and others are not', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) );
	const { items } = await ( await get( 'list', { m: t.token } ) ).json();
	assert.equal( items.find( ( i ) => i.id === 'pub-shared' ).shared, true );
	assert.equal( items.find( ( i ) => i.id === 'pub-word' ).shared, false );
} );

await it( 'minimum height comes from Settings, and a module can override it', async () => {
	await test_( 'settings', { values: { min_height: 450 } } );
	assert.match( await render( '[embed_drive mode="files" folder="public"]' ), /--ed-min-height:450px/ );
	assert.match( await render( '[embed_drive mode="files" folder="public" min_height="700"]' ), /--ed-min-height:700px/ );
	await test_( 'settings', { values: { min_height: 0 } } );
	assert.doesNotMatch( await render( '[embed_drive mode="files" folder="public"]' ), /--ed-min-height/ );
} );

await it( 'updater offers a newer GitHub release', async () => {
	await test_( 'seed_release', {
		release: { version: '9.9.9', url: 'https://example.test/r', package: 'https://example.test/embed-drive.zip', notes: 'n' },
	} );
	const update = await test_( 'update_check' );
	assert.equal( update.version, '9.9.9' );
	assert.equal( update.slug, 'embed-drive' );
	assert.equal( update.package, 'https://example.test/embed-drive.zip' );
} );

await it( 'updater falls back to the release page when the GitHub API refuses', async () => {
	await test_( 'github_mode', { mode: 'refused' } );
	const update = await test_( 'update_check' );
	assert.equal( update.version, '9.9.8' );
	assert.match( update.package, /\/releases\/download\/v9\.9\.8\/embed-drive\.zip$/ );
} );

await it( 'updater offers nothing when the release has no zip or GitHub is down', async () => {
	for ( const mode of [ 'no_asset', 'down' ] ) {
		await test_( 'github_mode', { mode } );
		assert.ok( ! ( await test_( 'update_check' ) )?.version, mode );
	}
} );

await it( 'Check again drops the saved release answer, for admins only', async () => {
	const seeded = { version: '9.9.9', url: 'https://example.test/r', package: 'https://example.test/embed-drive.zip', notes: 'n' };
	await test_( 'github_mode', { mode: 'refused' } );
	await test_( 'seed_release', { release: seeded } );
	assert.equal( ( await test_( 'check_again', { user: 0 } ) ).release.version, '9.9.9', 'kept for a visitor' );
	assert.equal( ( await test_( 'check_again', { user: 1 } ) ).release.version, '9.9.8', 'fresh for an admin' );
	await test_( 'github_mode', { mode: '' } );
} );

// --- Sign-in, as a real admin in a browser-like session ---
const jar = new Map();
const session = async ( url, init = {} ) => {
	const headers = { ...( init.headers || {} ), Cookie: [ ...jar ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' ) };
	const r = await fetch( url, { ...init, headers, redirect: 'manual' } );
	for ( const c of r.headers.getSetCookie() ) {
		const [ pair ] = c.split( ';' );
		const i = pair.indexOf( '=' );
		jar.set( pair.slice( 0, i ), pair.slice( i + 1 ) );
	}
	return r;
};
// Follow a redirect chain through the site, the relay and mock Google.
const follow = async ( url ) => {
	let r = await session( url );
	const seen = [ url ];
	while ( r.status >= 300 && r.status < 400 ) {
		const next = new URL( r.headers.get( 'location' ), seen.at( -1 ) ).toString();
		seen.push( next );
		await r.arrayBuffer();
		r = await session( next );
	}
	return { response: r, seen };
};
const login = async () => {
	await session( `${ SITE }/wp-login.php` );
	jar.set( 'wordpress_test_cookie', 'WP%20Cookie%20check' );
	const r = await session( `${ SITE }/wp-login.php`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1' } ),
	} );
	await r.arrayBuffer();
	assert.ok( [ ...jar.keys() ].some( ( k ) => k.startsWith( 'wordpress_logged_in' ) ), 'logged in' );
};
const startLink = async ( via ) => {
	const html = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	const links = [ ...html.matchAll( /href="([^"]*embed_drive_oauth_start[^"]*)"/g ) ].map( ( x ) => x[ 1 ].replaceAll( '&amp;', '&' ).replaceAll( '&#038;', '&' ) );
	const link = links.find( ( l ) => l.includes( `via=${ via }` ) );
	assert.ok( link, `start link for ${ via } in: ${ links }` );
	return new URL( link, SITE ).toString();
};
const RELAY = `${ SITE }/relay-test/index.php`;

await it( 'sign-in with own Google app connects the account, using PKCE', async () => {
	await login();
	await test_( 'disconnect_all' );
	const { response, seen } = await follow( await startLink( 'own' ) );
	assert.equal( response.status, 200 );
	assert.ok( seen.some( ( u ) => u.includes( '/auth?' ) && u.includes( 'code_challenge=' ) ), 'went to Google with PKCE' );
	assert.match( await response.text(), /Connected owner@example\.com/ );
} );

await it( 'sign-in through the relay: confirmation screen first, then connected', async () => {
	await test_( 'relay', { url: RELAY } );
	await test_( 'disconnect_all' );
	const start = await follow( await startLink( 'relay' ) );
	const page = await start.response.text();
	assert.equal( start.response.status, 200 );
	assert.match( page, /Only continue if this is your website/, 'shows the warning' );
	assert.match( page, /class="site">127\.0\.0\.1</, 'names the requesting site' );

	const go = page.match( /class="go" href="([^"]+)"/ )[ 1 ].replaceAll( '&amp;', '&' );
	const { response, seen } = await follow( go );
	assert.equal( response.status, 200, seen.join( '\n' ) );
	assert.match( await response.text(), /Connected owner@example\.com/ );

	const { accounts } = await test_( 'check_all' );
	assert.equal( accounts[ account ].via, 'relay' );
	assert.equal( accounts[ account ].status, 'ok', 'refreshing through the relay works' );
} );

await it( 'SECURITY: sign-in callback rejects a forged or replayed state', async () => {
	const forged = await follow( `${ SITE }/wp-admin/admin-post.php?action=embed_drive_oauth_callback&state=forged&code=x` );
	assert.match( await forged.response.text(), /expired or was not started here/ );
} );
await it( 'SECURITY: sign-in needs an admin session and a nonce', async () => {
	const anon = await fetch( `${ SITE }/wp-admin/admin-post.php?action=embed_drive_oauth_start&via=own`, { redirect: 'manual' } );
	assert.notEqual( ( anon.headers.get( 'location' ) || '' ).includes( '/auth' ), true );
	await anon.arrayBuffer();
	const noNonce = await session( `${ SITE }/wp-admin/admin-post.php?action=embed_drive_oauth_start&via=own` );
	assert.notEqual( noNonce.status, 302, 'no redirect to Google without a nonce' );
	await noNonce.arrayBuffer();
} );

const relayStart = ( params ) => fetch( `${ RELAY }?${ new URLSearchParams( { action: 'start', state: 'abcdefghijklmnop1234', code_challenge: 'a'.repeat( 43 ), ...params } ) }`, { redirect: 'manual' } );
const relayPost = ( params ) =>
	fetch( RELAY, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams( params ) } );

await it( 'SECURITY: relay refuses return URLs that are not a plugin callback over https', async () => {
	for ( const return_url of [
		'http://evil.example/wp-admin/admin-post.php?action=embed_drive_oauth_callback',
		'https://evil.example/steal',
		'https://evil.example/wp-admin/admin-post.php?action=other',
		'https://user@evil.example/wp-admin/admin-post.php?action=embed_drive_oauth_callback',
		'javascript:alert(1)',
		'',
	] ) {
		const r = await relayStart( { return_url } );
		assert.equal( r.status, 400, return_url );
		await r.arrayBuffer();
	}
} );
await it( 'SECURITY: relay names the real host and escapes it', async () => {
	const r = await relayStart( { return_url: 'https://site.example/wp-admin/admin-post.php?action=embed_drive_oauth_callback' } );
	const page = await r.text();
	assert.match( page, /class="site">site\.example</ );
	assert.equal( r.headers.get( 'x-frame-options' ), 'DENY' );
	assert.ok( ! page.includes( 'test-secret' ), 'secret is not exposed' );
} );
await it( 'SECURITY: relay rejects a tampered return state', async () => {
	const r = await fetch( `${ RELAY }?state=${ Buffer.from( '{"r":"https://evil.example/","s":"x","e":9999999999}' ).toString( 'base64url' ) }.bad&code=c`, { redirect: 'manual' } );
	assert.equal( r.status, 400 );
	assert.equal( r.headers.get( 'location' ), null );
	await r.arrayBuffer();
} );
await it( 'SECURITY: a code cannot be redeemed without the PKCE verifier', async () => {
	let r = await relayPost( { action: 'exchange', code: 'code/whatever' } );
	assert.equal( r.status, 400 );
	await r.arrayBuffer();
	r = await relayPost( { action: 'exchange', code: 'code/whatever', code_verifier: 'x'.repeat( 50 ) } );
	assert.equal( ( await r.json() ).error, 'invalid_grant' );
} );
await it( 'SECURITY: relay never hands out tokens for a bad refresh token', async () => {
	const r = await relayPost( { action: 'refresh', refresh_token: 'stolen-guess' } );
	assert.equal( ( await r.json() ).error, 'invalid_grant' );
} );

await it( 'admin screens load for an admin, with no PHP errors or leaked secrets', async () => {
	const pages = {};
	for ( const page of [ 'embed-drive', 'embed-drive-builder', 'embed-drive-activity' ] ) {
		const r = await session( `${ SITE }/wp-admin/admin.php?page=${ page }` );
		assert.equal( r.status, 200, page );
		pages[ page ] = await r.text();
		assert.ok( ! /Fatal error|Warning:|Notice:|Deprecated:|critical error/.test( pages[ page ] ), page + ' has a PHP error' );
	}
	assert.match( pages[ 'embed-drive' ], /owner@example\.com/ );
	assert.match( pages[ 'embed-drive' ], /admin-post\.php\?action=embed_drive_oauth_callback/ );
	assert.ok( ! pages[ 'embed-drive' ].includes( 'test-secret' ), 'the secret is never printed' );
	assert.match( pages[ 'embed-drive-builder' ], /id="embed-drive-builder"/ );
	assert.match( pages[ 'embed-drive-activity' ], /big\.bin/ );
	assert.ok( ! /pricing|upgrade to pro|licen[sc]e key/i.test( Object.values( pages ).join( '' ) ), 'no upsells' );
} );

await it( 'logged-in users are attributed in the activity log', async () => {
	await test_( 'connect' );
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) );
	const r = await session( api( 'file' ) + '&' + new URLSearchParams( { m: t.token, id: 'pub-dup1', disp: 'attachment' } ) );
	await r.arrayBuffer();
	const rows = await test_( 'log' );
	assert.equal( +rows.at( -1 ).user_id, 1 );
} );

await it( 'disconnect removes the account and its stored token', async () => {
	await test_( 'disconnect_all' );
	const { raw } = await test_( 'stored_token' );
	assert.ok( ! raw );
	const after = await get( 'list', { m } );
	assert.equal( after.status, 404, 'old page tokens stop working' );
	await after.arrayBuffer();
} );

await it( 'a deleted or changed Google app is reported as lost access', async () => {
	await test_( 'connect' );
	await test_( 'settings', { values: { client_secret: '' } } );
	const { accounts, mails } = await test_( 'check_all' );
	assert.equal( accounts[ account ].status, 'lost' );
	assert.equal( mails.length, 1 );
	await test_( 'disconnect_all' );
} );

await it( 'a fresh site gets the Google app guide and no sign-in button', async () => {
	await test_( 'relay', { url: '' } );
	await test_( 'settings', { values: { client_id: '', client_secret: '' } } );
	const html = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	assert.match( html, /<details class="embed-drive-advanced" open>/ );
	assert.match( html, /auth\/drive\.readonly/ );
	assert.match( html, /<code>[^<]*admin-post\.php\?action=embed_drive_oauth_callback<\/code>/ );
	assert.ok( ! html.includes( 'embed_drive_oauth_start' ), 'no sign-in link until the app is saved' );
	assert.ok( ! html.includes( 'name="relay_url"' ), 'no relay field' );
} );

await it( 'the settings form saves a Google app, and the secret stays encrypted', async () => {
	const page = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	const nonce = page.match( /name="_wpnonce" value="([^"]+)"[^>]*>\s*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<details/ )[ 1 ];
	const r = await session( `${ SITE }/wp-admin/admin-post.php`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { action: 'embed_drive_save', _wpnonce: nonce, client_id: 'owner-client', client_secret: 'owner-secret', zip_max_mb: '1024', zip_max_files: '200', log_days: '90' } ),
	} );
	await r.arrayBuffer();
	const html = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	assert.ok( html.includes( 'embed_drive_oauth_start' ), 'sign-in appears once saved' );
	assert.ok( html.includes( 'value="owner-client"' ) );
	assert.ok( ! html.includes( 'owner-secret' ), 'secret is never printed' );
	const { settings } = await test_( 'read_settings' );
	assert.ok( settings.client_secret && ! settings.client_secret.includes( 'owner-secret' ) );
} );

// Tests above leave the account disconnected.
await test_( 'connect' );

await it( 'SECURITY: each role reaches only what it should', async () => {
	const expected = { subscriber: [ 403, false ], contributor: [ 403, false ], author: [ 403, false ], editor: [ 200, true ] };
	for ( const [ role, [ browse, embed ] ] of Object.entries( expected ) ) {
		const result = await test_( 'role_probe', { role } );
		assert.deepEqual( [ result.browse, result.embed, result.settings ], [ browse, embed, false ], role );
	}
} );

await it( 'a widget still shows beside a post by a low-level author, but not that post\'s own module', async () => {
	assert.deepEqual( await test_( 'widget_probe' ), { in_widget: true, post_in_widget: false, in_author_content: false } );
} );

await it( 'SECURITY: a theme template can show a module beside a low-level author\'s post, but that author cannot', async () => {
	const seen = await ( await fetch( `${ SITE }/?ed_context_probe=1` ) ).json();
	assert.deepEqual( seen, {
		template_shortcode: true,
		template_block: true,
		content_filter: false,
		own_content: false,
		own_block: false,
		in_custom_field: false,
		in_excerpt: false,
		synced_pattern: false,
		comment_low_author: false,
		comment_admin_post: false,
		admin_post_content: true,
	} );
} );

await it( 'SECURITY: the block preview of an author\'s own post gives them no link', async () => {
	for ( const role of [ 'contributor', 'author' ] ) {
		assert.equal( ( await test_( 'attack_contributor', { role, own_post: true } ) ).token, null, role );
	}
} );

await it( 'odd filters on the activity screen do not break it', async () => {
	for ( const query of [ 'event[]=x', 'paged[]=1', 'event=nope', 'paged=-5' ] ) {
		const r = await session( `${ SITE }/wp-admin/admin.php?page=embed-drive-activity&${ query }` );
		assert.doesNotMatch( await r.text(), /critical error|Fatal|TypeError|Warning:/, query );
	}
} );

await it( 'SECURITY: low-level accounts cannot get a link from the block preview', async () => {
	for ( const role of [ 'subscriber', 'contributor', 'author' ] ) {
		const result = await test_( 'attack_contributor', { role } );
		assert.equal( result.token, null, role );
	}
	assert.ok( ( await test_( 'attack_contributor', { role: 'editor' } ) ).token, 'editors still can' );
} );

await it( 'SECURITY: a file in the trash is gone', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) ).token;
	assert.equal( ( await get( 'file', { m: t, id: 'pub-trashed', disp: 'attachment' } ) ).status, 404 );
	assert.equal( ( await get( 'file', { m: t, id: 'pub-dup1', disp: 'attachment' } ) ).status, 200 );
} );

await it( 'SECURITY: preview=no also blocks the large picture', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public" preview="no"]' ) ).token;
	assert.equal( ( await get( 'thumb', { m: t, id: 'pub-img', large: 1 } ) ).status, 404 );
	assert.equal( ( await get( 'thumb', { m: t, id: 'pub-img' } ) ).status, 200 );
} );

await it( 'SECURITY: redirects never carry the bearer token off Google', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="redirects"]' ) ).token;
	assert.equal( ( await get( 'thumb', { m: t, id: 'redir-away' } ) ).status, 404 );
	assert.equal( ( await get( 'file', { m: t, id: 'redir-file', disp: 'attachment' } ) ).status, 502 );
	assert.equal( ( await get( 'thumb', { m: t, id: 'redir-hop' } ) ).status, 200, 'a redirect within Google still works' );
	const seen = await ( await fetch( MOCK + '/__log' ) ).json();
	assert.deepEqual( seen.filter( ( l ) => l.path === '/__steal' && l.auth ), [] );
} );

await it( 'SECURITY: files and thumbnails are rate limited', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) ).token;
	await test_( 'set_rate', { limit: 2 } );
	const codes = {};
	for ( const route of [ 'thumb', 'file' ] ) {
		codes[ route ] = [];
		for ( let i = 0; i < 4; i++ ) {
			const r = await get( route, { m: t, id: 'pub-img', disp: 'attachment' } );
			codes[ route ].push( r.status );
			await r.arrayBuffer();
		}
	}
	await test_( 'set_rate', { limit: 0 } );
	assert.ok( codes.thumb.includes( 429 ) && codes.file.includes( 429 ), JSON.stringify( codes ) );
} );

await it( 'SECURITY: an oversized ZIP selection is refused before calling Google', async () => {
	const t = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) ).token;
	const before = ( await ( await fetch( MOCK + '/__log' ) ).json() ).length;
	const ids = Array.from( { length: 500 }, ( _, i ) => `made-up-${ i }` );
	const r = await fetch( api( 'zip/prepare' ) + '&m=' + t, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { ids } ),
	} );
	assert.equal( r.status, 413 );
	const after = ( await ( await fetch( MOCK + '/__log' ) ).json() ).length;
	assert.ok( after - before < 3, `${ after - before } calls` );
} );

await it( 'SECURITY: array parameters are rejected cleanly', async () => {
	const url = api( 'list' ) + '&m[]=x&folder[]=y&sort[]=z&cursor[]=q';
	const r = await fetch( url );
	assert.equal( r.status, 404 );
	assert.doesNotMatch( await r.text(), /Warning|Array to string/ );
} );

await it( 'SECURITY: an update installs only with a good signature', async () => {
	const { publicKey, privateKey } = generateKeyPairSync( 'ed25519' );
	const other = generateKeyPairSync( 'ed25519' ).privateKey;
	const key = publicKey.export( { type: 'spki', format: 'der' } ).subarray( -32 ).toString( 'base64' );
	const zipBytes = Buffer.from( 'pretend plugin zip' );
	const zip = zipBytes.toString( 'base64' );
	const signed = ( message, signer = privateKey ) => sign( null, Buffer.from( message ), signer ).toString( 'base64' );
	const hash = createHash( 'sha256' ).update( zipBytes ).digest( 'hex' );
	const good = signed( `embed-drive:9.9.7:${ hash }` );
	const repo = ( await test_( 'repo' ) ).repo;
	const pkg = `https://github.com/${ repo }/releases/download/v9.9.7/embed-drive.zip`;
	const check = ( params ) => test_( 'verify_package', { package: pkg, key, zip, sig: good, ...params } );

	const passed = await check( {} );
	assert.equal( passed.reply, 'file', 'a good signature is accepted' );
	assert.equal( passed.bytes, zip );

	const refused = {
		'no signature': { sig: '' },
		'not a signature': { sig: 'hello' },
		'someone else signed it': { sig: signed( `embed-drive:9.9.7:${ hash }`, other ) },
		'the file was changed': { zip: Buffer.from( 'evil plugin zip' ).toString( 'base64' ) },
		'signed for another version': { sig: signed( `embed-drive:9.9.6:${ hash }` ) },
		'another address': { package: 'https://evil.example.test/embed-drive.zip' },
	};
	for ( const [ name, params ] of Object.entries( refused ) ) {
		assert.equal( ( await check( params ) ).reply, 'embed_drive_unsigned', name );
	}

	const elsewhere = await check( { package: 'https://example.test/other-plugin.zip', plugin: 'other/other.php' } );
	assert.equal( elsewhere.reply, false, 'other plugins are left alone' );
} );

await it( 'updater offers nothing for a release that has no signature', async () => {
	await test_( 'github_mode', { mode: 'no_sig' } );
	assert.ok( ! ( await test_( 'update_check' ) )?.version );
	await test_( 'github_mode', { mode: '' } );
} );

await it( 'updater only accepts a package released from this repository', async () => {
	await test_( 'github_mode', { mode: 'api_ok' } );
	assert.equal( ( await test_( 'update_check' ) ).version, '9.9.7' );
	await test_( 'github_mode', { mode: 'offsite' } );
	const update = await test_( 'update_check' );
	assert.doesNotMatch( update.package, /evil/ );
	assert.match( update.package, /^https:\/\/github\.com\/.+\/releases\/download\/v9\.9\.8\/embed-drive\.zip$/ );
	await test_( 'github_mode', { mode: '' } );
} );

await it( 'SECURITY: visitor links can be cancelled, by an admin only', async () => {
	const old = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) ).token;
	const refused = await fetch( `${ SITE }/wp-admin/admin-post.php`, {
		method: 'POST',
		body: new URLSearchParams( { action: 'embed_drive_reset_links' } ),
		redirect: 'manual',
	} );
	await refused.arrayBuffer();
	assert.equal( ( await get( 'list', { m: old } ) ).status, 200, 'a visitor cannot reset them' );
	await test_( 'reset_links' );
	assert.equal( ( await get( 'list', { m: old } ) ).status, 404 );
	const fresh = tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) ).token;
	assert.equal( ( await get( 'list', { m: fresh } ) ).status, 200 );
} );

// An earlier test saves the settings form without this box ticked.
await test_( 'settings', { values: { error_email: true } } );
const googleFails = async ( query = '' ) => ( await fetch( `${ MOCK }/__fail?${ query }` ) ).arrayBuffer();
const freshToken = async () => tokenFrom( await render( '[embed_drive mode="files" folder="public"]' ) ).token;
const failedSearch = async ( term ) => {
	const r = await get( 'search', { m: await freshToken(), q: 'visitor-term-' + term } );
	await r.arrayBuffer();
	return r.status;
};

await it( 'Google failures are counted once a minute, by kind, with no tokens or Google messages stored', async () => {
	await test_( 'health', { reset: true } );
	await googleFails( 'status=503' );
	assert.equal( await failedSearch( 'a1' ), 502 );
	assert.equal( await failedSearch( 'a2' ), 502 );
	const health = await test_( 'health' );
	await googleFails();
	assert.deepEqual( Object.keys( health ), [ 'server' ] );
	assert.equal( health.server.minutes, 1, 'the second failure in the same minute is not written' );
	assert.equal( health.server.detail, 'HTTP 503 backendError' );
	assert.ok( ! JSON.stringify( health ).includes( 'access-' ) );
} );

await it( 'a missing file or a rejected request from a visitor is not counted', async () => {
	await test_( 'health', { reset: true } );
	await googleFails( 'status=429' );
	await failedSearch( 'b429' );
	assert.equal( ( await test_( 'health' ) ).refused.detail, 'HTTP 429 backendError', 'the same request does count when Google refuses it' );
	await test_( 'health', { reset: true } );
	for ( const status of [ 404, 400 ] ) {
		await googleFails( `status=${ status }` );
		await failedSearch( 'b' + status );
	}
	await googleFails();
	assert.deepEqual( await test_( 'health' ), {} );
} );

await it( 'failures fetching a file, connection drops and failed renewals are counted', async () => {
	await test_( 'health', { reset: true } );
	await googleFails( 'status=500&media=1' );
	const r = await get( 'file', { m: await freshToken(), id: 'pub-pdf', disp: 'attachment' } );
	await r.arrayBuffer();
	assert.equal( r.status, 502 );
	assert.match( ( await test_( 'health' ) ).server.detail, /^HTTP 500/ );

	await googleFails( 'status=0' );
	await failedSearch( 'c1' );
	assert.equal( ( await test_( 'health' ) ).network.minutes, 1 );

	await googleFails( 'status=500&token=1' );
	await test_( 'check_all' );
	await googleFails();
	assert.equal( ( await test_( 'health' ) ).signin.minutes, 1 );
} );

await it( 'Google refusing to export a huge document is not counted', async () => {
	await test_( 'health', { reset: true } );
	const token = await freshToken();
	await googleFails( 'status=403&media=1' );
	const r = await get( 'file', { m: token, id: 'pub-doc', disp: 'attachment' } );
	await r.arrayBuffer();
	assert.equal( r.status, 502 );
	assert.deepEqual( await test_( 'health' ), {} );
	const plain = await get( 'file', { m: token, id: 'pub-pdf', disp: 'attachment' } );
	await plain.arrayBuffer();
	await googleFails();
	assert.equal( ( await test_( 'health' ) ).refused.minutes, 1, 'a normal file refused the same way does count' );
} );

await it( 'the summary email: not for a blip, once when repeated, then quiet, with no secrets', async () => {
	await test_( 'health', { reset: true } );
	await googleFails( 'status=503' );
	await failedSearch( 'd1' );
	let daily = await test_( 'health_summary' );
	assert.equal( daily.mails.length, 0, 'one failure sends nothing' );
	assert.equal( daily.health.server.minutes, 1, 'and is kept, in case it builds up' );

	await test_( 'health', { age: 120 } );
	await failedSearch( 'd2' );
	await test_( 'health', { age: 120 } );
	await failedSearch( 'd3' );
	await googleFails();

	daily = await test_( 'health_summary', { mail_works: false } );
	assert.equal( daily.mails.length, 1 );
	assert.equal( daily.health.server.minutes, 3, 'kept if the site could not send the email' );

	daily = await test_( 'health_summary' );
	assert.equal( daily.mails.length, 1 );
	const [ mail ] = daily.mails;
	assert.match( mail.subject, /trouble with Google Drive/ );
	assert.match( mail.message, /Google had an internal error: in 3 separate minutes/ );
	assert.match( mail.message, /HTTP 503 backendError/ );
	assert.match( mail.message, /FileFolio version: \d/ );
	for ( const secret of [ 'access-', 'refresh-1', 'Bearer', 'visitor-term', '<Error>' ] ) {
		assert.ok( ! mail.message.includes( secret ), `email contains ${ secret }` );
	}
	assert.equal( ( await test_( 'health_summary' ) ).mails.length, 0, 'nothing more to report' );
} );

await it( 'old blips are forgotten, and turning the email off stops both counting and sending', async () => {
	await test_( 'health', { reset: true } );
	await googleFails( 'status=503' );
	await failedSearch( 'e1' );
	await test_( 'health', { age: 8 * 86400 } );
	const old = await test_( 'health_summary' );
	assert.equal( old.mails.length, 0 );
	assert.deepEqual( old.health, {} );

	await test_( 'settings', { values: { error_email: false } } );
	await failedSearch( 'e2' );
	assert.deepEqual( await test_( 'health' ), {} );
	assert.equal( ( await test_( 'health_latest' ) ).latest.label, 'Google had an internal error', 'the settings screen still learns of it' );
	await googleFails();
	await test_( 'settings', { values: { error_email: true } } );
} );

await it( 'the email setting is on by default and can be switched off on the settings screen', async () => {
	const page = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	assert.match( page, /name="error_email"[^>]*checked/ );
	const nonce = page.match( /name="_wpnonce" value="([^"]+)"[^>]*>\s*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<details class="embed-drive-advanced"/ )?.[ 1 ];
	assert.ok( nonce );
	const saved = await session( `${ SITE }/wp-admin/admin-post.php`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { action: 'embed_drive_save', _wpnonce: nonce, client_id: 'owner-client', log_enabled: '1' } ),
		redirect: 'manual',
	} );
	await saved.arrayBuffer();
	assert.equal( ( await test_( 'read_settings' ) ).settings.error_email, false );
	await test_( 'settings', { values: { error_email: true } } );
} );

await it( 'the settings screen shows the most recent problem with Google', async () => {
	const { latest } = await test_( 'health_latest' );
	assert.ok( latest && latest.detail === 'HTTP 503 backendError' );
	const page = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	assert.match( page, /class="ed-health is-problem"[\s\S]*Last problem with Google: Google had an internal error, [^<]* ago\.\s*<code>HTTP 503 backendError<\/code>/ );
	await test_( 'health', { reset: true } );
	await test_( 'health', { age: 31 * 86400 } );
} );

await it( 'the block keeps its wide alignment on the page', async () => {
	const { url } = await test_( 'create_page', { code: '<!-- wp:embed-drive/module {"mode":"gallery","folder":"photos","align":"wide","className":"my-photos"} /-->' } );
	const html = await ( await fetch( url ) ).text();
	const wrapper = html.match( /<div class="([^"]*wp-block-embed-drive-module[^"]*)"[^>]*>\s*<div class="embed-drive"[^>]*data-embed-drive=/ );
	assert.ok( wrapper, html.slice( html.indexOf( 'embed-drive' ) - 300, html.indexOf( 'embed-drive' ) + 300 ) );
	assert.deepEqual( wrapper[ 1 ].split( ' ' ).sort(), [ 'alignwide', 'my-photos', 'wp-block-embed-drive-module' ] );
} );

const gallery = async ( extra = '' ) => tokenFrom( await render( `[embed_drive mode="gallery" folder="photos"${ extra }]` ) );
const listNames = async ( params ) => {
	const r = await get( 'list', params );
	return r.ok ? ( await r.json() ).items.map( ( i ) => i.name ).sort() : r.status;
};

await it( 'a gallery shows only the photos in its folder, with subfolders as albums', async () => {
	const config = await gallery();
	assert.equal( config.mode, 'gallery' );
	assert.equal( config.search, false );
	assert.equal( config.zip, false );
	assert.deepEqual( await listNames( { m: config.token } ), [ 'Summer Album', 'beach.png', 'sunset.jpg' ] );
	assert.deepEqual( await listNames( { m: config.token, folder: 'album' } ), [ 'pool.png' ] );
	const asked = ( await ( await fetch( MOCK + '/__log' ) ).json() ).filter( ( l ) => l.q.includes( "'photos' in parents" ) );
	assert.ok( asked.length && asked.every( ( l ) => l.q.includes( "mimeType = 'image/jpeg'" ) && ! l.q.includes( 'pdf' ) ), 'Google is only asked for photos and folders' );
	const items = ( await ( await get( 'list', { m: config.token } ) ).json() ).items;
	assert.ok( items.filter( ( i ) => i.type === 'file' ).every( ( i ) => i.preview === 'image' && i.download ) );
} );

await it( 'SECURITY: a gallery link reaches nothing but the photos in its folder', async () => {
	const { token } = await gallery();
	for ( const id of [ 'photo-pdf', 'photo-svg', 'photo-doc', 'album-text', 'secret-file', 'pub-pdf', 'pub-img' ] ) {
		for ( const disp of [ 'attachment', 'inline', 'viewer' ] ) {
			const r = await get( 'file', { m: token, id, disp } );
			await r.arrayBuffer();
			assert.equal( r.status, 404, `${ id } ${ disp }` );
		}
		for ( const large of [ '', '1' ] ) {
			const r = await get( 'thumb', { m: token, id, large } );
			await r.arrayBuffer();
			assert.equal( r.status, 404, `thumb ${ id }` );
		}
	}
	for ( const folder of [ 'secret', 'root-id', 'public', 'sub' ] ) {
		assert.equal( await listNames( { m: token, folder } ), 404, folder );
	}
	const search = await get( 'search', { m: token, q: 'invoice' } );
	await search.arrayBuffer();
	assert.equal( search.status, 404 );
	const zip = await fetch( `${ SITE }/?rest_route=/embed-drive/v1/zip/prepare&m=${ encodeURIComponent( token ) }`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { ids: [ 'photo-a', 'photo-pdf' ] } ),
	} );
	await zip.arrayBuffer();
	assert.equal( zip.status, 404 );

	const photo = await get( 'file', { m: token, id: 'album-photo', disp: 'inline' } );
	assert.equal( photo.status, 200 );
	assert.equal( photo.headers.get( 'content-type' ), 'image/png' );
	await photo.arrayBuffer();
	const large = await get( 'thumb', { m: token, id: 'photo-a', large: '1' } );
	assert.equal( large.status, 200 );
	await large.arrayBuffer();
} );

await it( 'SECURITY: a gallery without downloads still shows photos but will not send originals', async () => {
	const { token } = await gallery( ' download="no"' );
	const items = ( await ( await get( 'list', { m: token } ) ).json() ).items;
	assert.ok( items.every( ( i ) => ! i.download ) );
	const saved = await get( 'file', { m: token, id: 'photo-a', disp: 'attachment' } );
	await saved.arrayBuffer();
	assert.equal( saved.status, 404 );
	const shown = await get( 'file', { m: token, id: 'photo-a', disp: 'inline' } );
	await shown.arrayBuffer();
	assert.equal( shown.status, 200 );
} );

await it( 'SECURITY: a gallery token cannot be turned into a file browser or widened', async () => {
	const { token } = await gallery();
	const [ data, sig ] = token.split( '.' );
	const decoded = JSON.parse( Buffer.from( data, 'base64url' ).toString() );
	for ( const change of [ { m: 'files' }, { r: 'root-id' }, { s: true }, { z: true } ] ) {
		const forged = Buffer.from( JSON.stringify( { ...decoded, ...change } ) ).toString( 'base64url' ) + '.' + sig;
		assert.equal( await listNames( { m: forged } ), 404, JSON.stringify( change ) );
	}
} );

await it( 'the privacy tools export and erase one person\'s activity, and nobody else\'s', async () => {
	const { export: exported, nobody, erased, after, bob_left: bob } = await test_( 'privacy' );
	const values = exported.data.flatMap( ( item ) => item.data.map( ( d ) => d.value ) );
	assert.ok( values.includes( 'alice-file.pdf' ) && values.includes( 'alice secret term' ) );
	assert.ok( ! values.includes( 'bob-file.pdf' ) );
	assert.equal( exported.done, true );
	assert.deepEqual( nobody.data, [] );
	assert.equal( erased.items_removed, true );
	assert.deepEqual( after.data, [] );
	assert.equal( bob.data.length, 1, "another person's entries stay" );
} );

await it( 'with both copies installed, one runs, admins are told, and deleting either keeps the settings', async () => {
	const { both_active: both, running, kept } = await test_( 'two_copies' );
	assert.equal( both, true );
	assert.match( running, /\/embed-drive\/embed-drive\.php$/ );
	assert.deepEqual( kept, { filefolio: true, 'embed-drive': true } );
	const html = await ( await session( `${ SITE }/wp-admin/admin.php?page=embed-drive` ) ).text();
	assert.equal( ( html.match( /FileFolio is installed twice/g ) || [] ).length, 1 );
} );

const log = await ( await fetch( MOCK + '/__log' ) ).json();
await it( 'nothing was sent to any third party, and no write calls were made to Drive', async () => {
	const writes = log.filter( ( l ) => l.path.startsWith( '/drive' ) && l.method !== 'GET' );
	assert.equal( writes.length, 0 );
} );

const failed = results.filter( ( [ ok ] ) => ! ok );
console.log( `\n${ results.length - failed.length }/${ results.length } passed` );
process.exit( failed.length ? 1 : 0 );
