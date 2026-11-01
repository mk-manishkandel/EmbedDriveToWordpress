/**
 * Copy buttons on the settings screen.
 */
( function ( wp ) {
	const { __ } = wp.i18n;

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-ed-copy]' );
		if ( ! button ) {
			return;
		}
		const label = button.textContent;
		const done = () => {
			button.textContent = __( 'Copied', 'embed-drive' );
			setTimeout( () => ( button.textContent = label ), 1500 );
		};
		const text = button.parentElement.querySelector( 'code' ).textContent;
		// Browsers only offer the clipboard API on https, and some sites run on plain http.
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			const range = document.createRange();
			range.selectNodeContents( button.parentElement.querySelector( 'code' ) );
			window.getSelection().removeAllRanges();
			window.getSelection().addRange( range );
			document.execCommand( 'copy' );
			window.getSelection().removeAllRanges();
			done();
		}
	} );
} )( window.wp );
