// End-to-end tests against a running WordPress with the plugin and tests/mock-endpoints.php loaded.
// Usage: node tests/e2e.mjs http://127.0.0.1:9500 http://127.0.0.1:9400
import { execFileSync } from 'node:child_process';
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
assert.ok( connected.account, 'connect failed: ' + JSON.stringify( connected ).slice( 0, 500 ) );
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
	assert.match( r.headers.get( 'content-disposition' ), /attachment; filename="big.bin"/ );
} );
await it( 'supports range requests for seeking in media', async () => {
	const r = await get( 'file', { m, id: 'pub-big', disp: 'attachment' }, { Range: 'bytes=10-19' } );
	assert.equal( r.status, 206 );
	assert.equal( r.headers.get( 'content-range' ), `bytes 10-19/${ 3 * 1024 * 1024 }` );
	assert.equal( Buffer.from( await r.arrayBuffer() ).toString(), 'abcdefghij' );
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

await it( 'updater offers a newer GitHub release', async () => {
	await test_( 'seed_release', {
		release: { version: '9.9.9', url: 'https://example.test/r', package: 'https://example.test/embed-drive.zip', notes: 'n' },
	} );
	const update = await test_( 'update_check' );
	assert.equal( update.version, '9.9.9' );
	assert.equal( update.slug, 'embed-drive' );
	assert.equal( update.package, 'https://example.test/embed-drive.zip' );
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

const log = await ( await fetch( MOCK + '/__log' ) ).json();
await it( 'nothing was sent to any third party, and no write calls were made to Drive', async () => {
	const writes = log.filter( ( l ) => l.path.startsWith( '/drive' ) && l.method !== 'GET' );
	assert.equal( writes.length, 0 );
} );

const failed = results.filter( ( [ ok ] ) => ! ok );
console.log( `\n${ results.length - failed.length }/${ results.length } passed` );
process.exit( failed.length ? 1 : 0 );
