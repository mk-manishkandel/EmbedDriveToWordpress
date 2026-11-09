/**
 * Shortcode builder screen.
 */
( function ( wp ) {
	const form = document.getElementById( 'embed-drive-builder' );
	if ( ! form ) {
		return;
	}

	const { __ } = wp.i18n;
	const output = document.getElementById( 'ed-output' );
	const copy = document.getElementById( 'ed-copy' );
	const targetLabel = document.getElementById( 'ed-target' );
	const targetRow = targetLabel.parentElement;
	const accounts = window.EmbedDriveAccounts || [];
	let target = null;

	accounts.forEach( ( account ) => form.account.add( new Option( account.email, account.id ) ) );

	// With one account there is nothing to choose.
	document.getElementById( 'ed-account-field' ).hidden = accounts.length < 2;

	const wantsFolder = () => [ 'files', 'gallery', 'search' ].includes( form.mode.value );

	function attr( name, value ) {
		return value === '' ? '' : ` ${ name }="${ String( value ).replace( /["\[\]]/g, '' ) }"`;
	}

	function build() {
		const mode = form.mode.value;
		form.querySelectorAll( '[data-for]' ).forEach( ( element ) => {
			element.hidden = ! element.dataset.for.split( ' ' ).includes( mode );
		} );

		if ( ! target ) {
			output.value = '';
			copy.disabled = true;
			targetRow.classList.remove( 'is-chosen' );
			targetLabel.textContent = wantsFolder() ? __( 'No folder chosen yet', 'filefolio' ) : __( 'No file chosen yet', 'filefolio' );
			return;
		}
		targetRow.classList.add( 'is-chosen' );
		targetLabel.textContent = target.name;

		let code = `[embed_drive mode="${ mode }"`;
		if ( accounts.length > 1 ) {
			code += attr( 'account', form.account.value );
		}
		code += attr( wantsFolder() ? 'folder' : 'file', target.id );

		if ( mode === 'files' ) {
			code += form.view.value !== 'grid' ? attr( 'view', form.view.value ) : '';
		}
		if ( mode === 'files' || mode === 'gallery' ) {
			code += form.sort.value !== 'name' ? attr( 'sort', form.sort.value ) : '';
			code += form.order.value !== 'asc' ? attr( 'order', form.order.value ) : '';
		}
		if ( mode === 'files' ) {
			code += form.zip.checked ? '' : attr( 'zip', 'no' );
			code += form.search.checked ? '' : attr( 'search', 'no' );
		}
		if ( mode === 'files' || mode === 'gallery' ) {
			code += attr( 'min_height', form.min_height.value );
		}
		if ( mode === 'gallery' ) {
			code += form.download.checked ? '' : attr( 'download', 'no' );
		}
		if ( mode === 'files' || mode === 'search' ) {
			code += form.download.checked ? '' : attr( 'download', 'no' );
			code += form.preview.checked ? '' : attr( 'preview', 'no' );
		}
		if ( mode === 'embed' ) {
			code += form.download.checked ? attr( 'download', 'yes' ) : '';
			code += String( form.height.value ) !== '600' ? attr( 'height', form.height.value ) : '';
		}
		if ( mode === 'button' || mode === 'link' ) {
			code += attr( 'label', form.label.value );
		}

		output.value = code + ']';
		copy.disabled = false;
	}

	let previousFolderMode = wantsFolder();
	form.addEventListener( 'input', () => {
		// A folder can't be used as a file, or the reverse, so a stale choice is dropped.
		if ( wantsFolder() !== previousFolderMode ) {
			previousFolderMode = wantsFolder();
			target = null;
		}
		build();
	} );
	form.account.addEventListener( 'change', () => {
		target = null;
		build();
	} );

	document.getElementById( 'ed-pick' ).addEventListener( 'click', () => {
		window.EmbedDrivePicker.open( {
			account: form.account.value,
			want: wantsFolder() ? 'folder' : 'file',
			onSelect: ( item ) => {
				target = item;
				build();
			},
		} );
	} );

	copy.addEventListener( 'click', () => {
		const label = copy.textContent;
		const done = () => {
			copy.textContent = __( 'Copied', 'filefolio' );
			setTimeout( () => ( copy.textContent = label ), 1500 );
		};
		const failed = () => {
			output.select();
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
		};
		// The clipboard API is missing on plain http and can be refused, so there is always a fallback.
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( output.value ).then( done, failed );
		} else {
			failed();
		}
	} );

	// Embeds keep downloads off unless asked for, matching the shortcode default.
	form.addEventListener( 'change', ( event ) => {
		if ( event.target.name === 'mode' ) {
			form.download.checked = form.mode.value !== 'embed';
			build();
		}
	} );

	build();
} )( window.wp );
