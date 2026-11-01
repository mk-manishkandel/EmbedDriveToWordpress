/**
 * Draws a Word, Excel or PDF file in the browser. This page only ever runs in a frame sandboxed
 * away from the site, because the documents are untrusted. The bytes arrive from the parent page.
 */
( function () {
	// Any site can frame this file. Without the sandbox a document's script would run as this
	// site, so refuse to draw anything unless the page really is sandboxed.
	if ( window.origin !== 'null' ) {
		return;
	}

	// The static policy can't name this folder, as it differs per site. Policies only ever add up.
	const policy = document.createElement( 'meta' );
	policy.httpEquiv = 'Content-Security-Policy';
	policy.content = `script-src ${ new URL( 'lib/', location.href ).href }`;
	document.head.append( policy );

	const MAX_ROWS = 2000;
	const root = document.getElementById( 'root' );

	function script( name ) {
		return new Promise( ( resolve, reject ) => {
			const tag = document.createElement( 'script' );
			tag.src = `lib/${ name }`;
			tag.onload = resolve;
			tag.onerror = reject;
			document.head.append( tag );
		} );
	}

	async function drawPdf( bytes ) {
		await script( 'pdf.min.js' );
		// Loading the worker code here keeps it on this thread, as a sandboxed page can't start a Worker from a URL.
		await script( 'pdf.worker.min.js' );
		pdfjsLib.GlobalWorkerOptions.workerSrc = 'lib/pdf.worker.min.js';

		const pdf = await pdfjsLib.getDocument( { data: bytes, isEvalSupported: false } ).promise;
		const first = await pdf.getPage( 1 );
		const shape = first.getViewport( { scale: 1 } );
		const holder = document.createElement( 'div' );
		holder.id = 'pages';
		root.append( holder );

		const draw = async ( canvas ) => {
			const page = await pdf.getPage( +canvas.dataset.page );
			const unscaled = page.getViewport( { scale: 1 } );
			const scale = ( Math.min( canvas.clientWidth, 980 ) * ( window.devicePixelRatio || 1 ) ) / unscaled.width;
			const viewport = page.getViewport( { scale } );
			canvas.width = viewport.width;
			canvas.height = viewport.height;
			canvas.style.aspectRatio = `${ unscaled.width } / ${ unscaled.height }`;
			await page.render( { canvasContext: canvas.getContext( '2d' ), viewport } ).promise;
			canvas.dataset.drawn = '1';
		};

		const watcher = new IntersectionObserver(
			( entries ) => {
				entries
					.filter( ( entry ) => entry.isIntersecting )
					.forEach( ( entry ) => {
						watcher.unobserve( entry.target );
						draw( entry.target );
					} );
			},
			{ rootMargin: '600px' }
		);
		for ( let number = 1; number <= pdf.numPages; number++ ) {
			const canvas = document.createElement( 'canvas' );
			canvas.className = 'page';
			canvas.dataset.page = number;
			canvas.style.aspectRatio = `${ shape.width } / ${ shape.height }`;
			holder.append( canvas );
			watcher.observe( canvas );
		}
	}

	async function drawWord( bytes ) {
		await script( 'jszip.min.js' );
		await script( 'docx-preview.min.js' );
		await docx.renderAsync( bytes, root, null, { ignoreLastRenderedPageBreak: false, useBase64URL: true } );
	}

	async function drawSheet( bytes ) {
		await script( 'xlsx.full.min.js' );
		const book = XLSX.read( bytes, { type: 'array', sheetRows: MAX_ROWS + 1 } );
		const tabs = document.createElement( 'div' );
		const holder = document.createElement( 'div' );
		tabs.id = 'tabs';
		holder.id = 'sheet';

		const show = ( name, button ) => {
			tabs.querySelectorAll( 'button' ).forEach( ( other ) => other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' ) );
			const rows = XLSX.utils.sheet_to_json( book.Sheets[ name ], { header: 1, raw: false, defval: '' } );
			const table = document.createElement( 'table' );
			rows.slice( 0, MAX_ROWS ).forEach( ( row ) => {
				const line = table.insertRow();
				row.forEach( ( value ) => ( line.insertCell().textContent = value ) );
			} );
			holder.replaceChildren( table );
			note.textContent = rows.length > MAX_ROWS ? `Showing the first ${ MAX_ROWS } rows.` : '';
		};

		const note = document.createElement( 'div' );
		note.id = 'note';
		book.SheetNames.forEach( ( name, index ) => {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.textContent = name;
			button.onclick = () => show( name, button );
			tabs.append( button );
			if ( index === 0 ) {
				show( name, button );
			}
		} );
		root.append( tabs, holder, note );
	}

	const DRAW = { pdf: drawPdf, docx: drawWord, sheet: drawSheet };

	window.addEventListener( 'message', async ( event ) => {
		if ( event.source !== window.parent || ! DRAW[ event.data?.kind ] ) {
			return;
		}
		try {
			await DRAW[ event.data.kind ]( event.data.bytes );
			window.parent.postMessage( { embedDrive: 'done' }, '*' );
		} catch ( error ) {
			window.parent.postMessage( { embedDrive: 'failed' }, '*' );
		}
	} );
	window.parent.postMessage( { embedDrive: 'ready' }, '*' );
} )();
