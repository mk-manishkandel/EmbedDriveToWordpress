// A stand-in for Google's OAuth and Drive APIs, so tests run without real credentials.
// Implements only what the plugin and relay call, and enforces bearer auth like the real API.
import http from 'node:http';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

const FOLDER = 'application/vnd.google-apps.folder';
const png = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==',
	'base64'
);

const body = ( text ) => Buffer.from( text );
const fixture = ( name ) => readFileSync( new URL( `fixtures/${ name }`, import.meta.url ) );
const blob = Buffer.alloc( 3 * 1024 * 1024, 'abcdefghij' ); // Big enough to prove streaming and ranges.

// id: [name, mime, parent, content]
const files = {
	'root-id': [ 'My Drive', FOLDER, null ],
	secret: [ 'Secret Folder', FOLDER, 'root-id' ],
	'secret-file': [ 'salaries.pdf', 'application/pdf', 'secret', body( '%PDF-secret' ) ],
	public: [ 'Public Folder', FOLDER, 'root-id' ],
	'pub-pdf': [ 'guide.pdf', 'application/pdf', 'public', body( '%PDF-guide' ) ],
	'pub-img': [ 'photo.png', 'image/png', 'public', png ],
	'pub-word': [ 'letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'public', fixture( 'letter.docx' ) ],
	'pub-sheet': [ 'numbers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'public', fixture( 'numbers.xlsx' ) ],
	'pub-real': [ 'real.pdf', 'application/pdf', 'public', fixture( 'real.pdf' ) ],
	'pub-jpx': [ 'jpeg2000.pdf', 'application/pdf', 'public', fixture( 'jpx.pdf' ) ],
	'pub-slides': [ 'deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'public', body( 'PK-slides' ) ],
	'pub-shared': [ 'shared.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'public', body( 'PK-shared' ) ],
	'pub-bad': [ 'broken.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'public', body( 'not a real document' ) ],
	'pub-csv': [ 'table.csv', 'text/csv', 'public', body( 'a,b' ) ],
	'pub-trashed': [ 'deleted.txt', 'text/plain', 'public', body( 'owner deleted this' ) ],
	'pub-big': [ 'big.bin', 'application/octet-stream', 'public', blob ],
	'pub-html': [ 'evil.html', 'text/html', 'public', body( '<script>alert(1)</script>' ) ],
	'pub-svg': [ 'evil.svg', 'image/svg+xml', 'public', body( '<svg onload="alert(1)"/>' ) ],
	'pub-doc': [ 'Report', 'application/vnd.google-apps.document', 'public' ],
	'pub-form': [ 'Survey', 'application/vnd.google-apps.form', 'public' ],
	'pub-dup1': [ 'same.txt', 'text/plain', 'public', body( 'first' ) ],
	'pub-dup2': [ 'same.txt', 'text/plain', 'public', body( 'second' ) ],
	'pub-traversal': [ '../../evil.txt', 'text/plain', 'public', body( 'traversal' ) ],
	'pub-xss': [ '<img src=x onerror=window.__xss=1>.txt', 'text/plain', 'public', body( 'x' ) ],
	'pub-broken': [ 'broken.txt', 'text/plain', 'public', 'FAIL' ],
	redirects: [ 'Redirects', FOLDER, 'root-id' ],
	'redir-away': [ 'away.png', 'image/png', 'redirects', body( 'x' ) ],
	'redir-hop': [ 'hop.png', 'image/png', 'redirects', body( 'x' ) ],
	'redir-file': [ 'away.txt', 'text/plain', 'redirects', body( 'x' ) ],
	sub: [ 'Sub Folder', FOLDER, 'public' ],
	'sub-file': [ "O'Brien notes.txt", 'text/plain', 'sub', body( 'nested needle content' ) ],
	'only-folders': [ 'QAA Documents (All)', FOLDER, 'root-id' ],
	'of-1': [ 'EQAAC-Further Progress Visit Report and Its Long Supporting Evidence', FOLDER, 'only-folders' ],
	'of-2': [ 'PRT Final Visit-Response', FOLDER, 'only-folders' ],
	'of-3': [ 'Sub Folder', FOLDER, 'only-folders' ],
	many: [ 'Many Files', FOLDER, 'root-id' ],
	...Object.fromEntries( Array.from( { length: 130 }, ( _, i ) => [ `many-${ i }`, [ `item ${ String( i ).padStart( 3, '0' ) }.txt`, 'text/plain', 'many', body( 'x' ) ] ] ) ),
	shortcut: [ 'link-to-secret', 'application/vnd.google-apps.shortcut', 'public' ],
	photos: [ 'Photos', FOLDER, 'root-id' ],
	'photo-a': [ 'beach.png', 'image/png', 'photos', png ],
	'photo-b': [ 'sunset.jpg', 'image/jpeg', 'photos', body( 'jpeg-bytes' ) ],
	'photo-pdf': [ 'invoice.pdf', 'application/pdf', 'photos', body( '%PDF-not-a-photo' ) ],
	'photo-svg': [ 'drawing.svg', 'image/svg+xml', 'photos', body( '<svg onload="alert(1)"/>' ) ],
	'photo-doc': [ 'Notes', 'application/vnd.google-apps.document', 'photos' ],
	album: [ 'Summer Album', FOLDER, 'photos' ],
	'album-photo': [ 'pool.png', 'image/png', 'album', png ],
	'album-text': [ 'captions.txt', 'text/plain', 'album', body( 'private captions' ) ],
};

let validToken = 'access-1';
let refreshCount = 0;
let revokeLost = false;
let failure = null;
const log = [];

function meta( id, port ) {
	const [ name, mimeType, parent, content ] = files[ id ];
	const m = { id, name, mimeType, modifiedTime: '2026-01-15T10:00:00.000Z', parents: parent ? [ parent ] : [] };
	if ( Buffer.isBuffer( content ) ) {
		m.size = String( content.length );
	}
	if ( id === 'pub-trashed' ) {
		m.trashed = true;
	}
	if ( id === 'pub-shared' ) {
		m.permissionIds = [ 'anyoneWithLink', '123' ];
	}
	if ( mimeType === 'image/png' || id === 'pub-slides' || id === 'pub-bad' ) {
		m.thumbnailLink = `http://127.0.0.1:${ port }/thumb/${ id }`;
	}
	return m;
}

// Only the query shapes the plugin builds are parsed.
function search( q, port ) {
	const parents = [ ...q.matchAll( /'([^']+)' in parents/g ) ].map( ( m ) => m[ 1 ] );
	const term = q.match( /name contains '((?:[^'\\]|\\.)*)'/ )?.[ 1 ]?.replace( /\\(.)/g, '$1' ).toLowerCase();
	// A list of allowed types, such as a gallery's: (mimeType = 'a' or mimeType = 'b').
	const anyOf = q.match( /\((mimeType = '[^']+'(?: or mimeType = '[^']+')+)\)/ )?.[ 1 ];
	const allowed = anyOf ? [ ...anyOf.matchAll( /mimeType = '([^']+)'/g ) ].map( ( m ) => m[ 1 ] ) : null;
	const foldersOnly = ! allowed && q.includes( `mimeType = '${ FOLDER }'` );
	const hideShortcuts = q.includes( "mimeType != 'application/vnd.google-apps.shortcut'" );

	return Object.keys( files )
		.filter( ( id ) => {
			const [ name, mime, parent, content ] = files[ id ];
			if ( parents.length && ! parents.includes( parent ) ) {
				return false;
			}
			if ( foldersOnly && mime !== FOLDER ) {
				return false;
			}
			if ( allowed && ! allowed.includes( mime ) ) {
				return false;
			}
			if ( hideShortcuts && mime.endsWith( '.shortcut' ) ) {
				return false;
			}
			if ( id === 'pub-trashed' ) {
				return false;
			}
			if ( term ) {
				const text = Buffer.isBuffer( content ) ? content.toString() : '';
				return name.toLowerCase().includes( term ) || text.toLowerCase().includes( term );
			}
			return true;
		} )
		.map( ( id ) => meta( id, port ) );
}

const server = http.createServer( async ( req, res ) => {
	const port = server.address().port;
	const url = new URL( req.url, 'http://x' );
	const chunks = [];
	for await ( const chunk of req ) {
		chunks.push( chunk );
	}
	const form = new URLSearchParams( Buffer.concat( chunks ).toString() );
	log.push( { method: req.method, path: url.pathname, auth: req.headers.authorization || '', range: req.headers.range || '', q: url.searchParams.get( 'q' ) || '' } );

	const send = ( status, data, headers = {} ) => {
		const isBuffer = Buffer.isBuffer( data );
		res.writeHead( status, { 'Content-Type': isBuffer ? 'application/octet-stream' : 'application/json', ...headers } );
		res.end( isBuffer ? data : JSON.stringify( data ) );
	};

	// Test controls.
	if ( url.pathname === '/__log' ) {
		return send( 200, log );
	}
	if ( url.pathname === '/__expire' ) {
		validToken = 'access-rotated-' + Date.now();
		return send( 200, { validToken } );
	}
	if ( url.pathname === '/__lose_access' ) {
		revokeLost = true;
		validToken = 'nobody-has-this';
		return send( 200, {} );
	}
	if ( url.pathname === '/__fail' ) {
		// status=0 drops the connection. media=1 fails file content only. token=1 fails sign-in renewal only.
		failure = url.searchParams.has( 'status' ) ? { status: Number( url.searchParams.get( 'status' ) ), media: url.searchParams.has( 'media' ), token: url.searchParams.has( 'token' ) } : null;
		return send( 200, {} );
	}
	if ( failure?.token && url.pathname === '/token' && form.get( 'grant_type' ) === 'refresh_token' ) {
		return send( failure.status, { error: 'backendError' } );
	}
	if ( failure && ! failure.token && url.pathname.startsWith( '/drive/' ) && ( ! failure.media || url.searchParams.get( 'alt' ) === 'media' || url.pathname.endsWith( '/export' ) ) ) {
		if ( ! failure.status ) {
			return req.socket.destroy();
		}
		return send( failure.status, { error: { code: failure.status, errors: [ { reason: 'backend<Error>' } ], message: 'secret-looking access-valid-token' } } );
	}
	if ( url.pathname === '/__restore' ) {
		revokeLost = false;
		validToken = 'access-1';
		return send( 200, {} );
	}

	if ( url.pathname === '/auth' ) {
		// Consent is instantly granted: go straight back with a code bound to the PKCE challenge.
		const target = new URL( url.searchParams.get( 'redirect_uri' ) );
		target.searchParams.set( 'code', 'code/' + url.searchParams.get( 'code_challenge' ) );
		target.searchParams.set( 'state', url.searchParams.get( 'state' ) );
		res.writeHead( 302, { Location: target.toString() } );
		return res.end();
	}

	if ( url.pathname === '/token' ) {
		if ( form.get( 'client_secret' ) !== 'test-secret' ) {
			return send( 401, { error: 'invalid_client' } );
		}
		if ( form.get( 'grant_type' ) === 'authorization_code' ) {
			const challenge = createHash( 'sha256' ).update( form.get( 'code_verifier' ) || '' ).digest( 'base64url' );
			if ( form.get( 'code' ) !== 'code/' + challenge ) {
				return send( 400, { error: 'invalid_grant' } );
			}
			return send( 200, {
				access_token: validToken,
				refresh_token: 'refresh-1',
				expires_in: 3600,
				scope: 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email',
			} );
		}
		if ( revokeLost || form.get( 'refresh_token' ) !== 'refresh-1' ) {
			return send( 400, { error: 'invalid_grant' } );
		}
		refreshCount++;
		return send( 200, { access_token: validToken, expires_in: 3600 } );
	}

	if ( url.pathname === '/revoke' ) {
		return send( 200, {} );
	}

	if ( req.headers.authorization !== 'Bearer ' + validToken ) {
		return send( 401, { error: { code: 401 } } );
	}

	if ( url.pathname === '/userinfo' ) {
		return send( 200, { sub: '12345', email: 'owner@example.com', name: 'Drive Owner' } );
	}

	if ( url.pathname === '/thumb/redir-away' ) {
		res.writeHead( 302, { Location: `http://localhost:${ port }/__steal` } );
		return res.end();
	}
	if ( url.pathname === '/thumb/redir-hop' ) {
		res.writeHead( 302, { Location: `http://127.0.0.1:${ port }/thumb/pub-img` } );
		return res.end();
	}
	if ( url.pathname.startsWith( '/thumb/' ) ) {
		res.writeHead( 200, { 'Content-Type': 'image/png' } );
		return res.end( png );
	}

	if ( url.pathname === '/drive/v3/drives' ) {
		return send( 200, { drives: [ { id: 'team-drive', name: 'Team Drive' } ] } );
	}

	if ( url.pathname === '/drive/v3/files' ) {
		const q = url.searchParams.get( 'q' ) || '';
		const all = q.includes( 'sharedWithMe' ) ? [] : search( q, port );
		const size = +url.searchParams.get( 'pageSize' ) || 100;
		const offset = +url.searchParams.get( 'pageToken' ) || 0;
		const page = { files: all.slice( offset, offset + size ) };
		if ( offset + size < all.length ) {
			page.nextPageToken = String( offset + size );
		}
		return send( 200, page );
	}

	const match = url.pathname.match( /^\/drive\/v3\/files\/([^/]+)(\/export)?$/ );
	if ( match ) {
		const id = decodeURIComponent( match[ 1 ] ) === 'root' ? 'root-id' : decodeURIComponent( match[ 1 ] );
		if ( ! files[ id ] ) {
			return send( 404, { error: { code: 404 } } );
		}
		if ( match[ 2 ] ) {
			const type = url.searchParams.get( 'mimeType' );
			res.writeHead( 200, { 'Content-Type': type } );
			return res.end( 'exported:' + type );
		}
		if ( url.searchParams.get( 'alt' ) !== 'media' ) {
			return send( 200, meta( id, port ) );
		}
		if ( id === 'redir-file' ) {
			res.writeHead( 302, { Location: `http://localhost:${ port }/__steal` } );
			return res.end();
		}
		const content = files[ id ][ 3 ];
		if ( content === 'FAIL' ) {
			return send( 403, { error: { code: 403 } } );
		}
		const type = files[ id ][ 1 ];
		const range = req.headers.range?.match( /^bytes=(\d*)-(\d*)$/ );
		if ( range ) {
			const start = +range[ 1 ] || 0;
			const end = range[ 2 ] ? +range[ 2 ] : content.length - 1;
			if ( start >= content.length ) {
				res.writeHead( 416, { 'Content-Range': `bytes */${ content.length }` } );
				return res.end();
			}
			res.writeHead( 206, {
				'Content-Type': type,
				'Content-Length': end - start + 1,
				'Content-Range': `bytes ${ start }-${ end }/${ content.length }`,
			} );
			return res.end( content.subarray( start, end + 1 ) );
		}
		res.writeHead( 200, { 'Content-Type': type, 'Content-Length': content.length } );
		return res.end( content );
	}

	send( 404, { error: 'unknown route ' + url.pathname } );
} );

server.listen( +process.env.MOCK_PORT || 9400, '127.0.0.1', () => {
	console.log( 'mock google on', server.address().port );
} );
