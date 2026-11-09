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
			button.textContent = __( 'Copied', 'filefolio' );
			setTimeout( () => ( button.textContent = label ), 1500 );
		};
		const code = button.parentElement.querySelector( 'code' );
		// Leaves the text selected if copying fails, so it can still be copied by hand.
		const failed = () => {
			const range = document.createRange();
			range.selectNodeContents( code );
			window.getSelection().removeAllRanges();
			window.getSelection().addRange( range );
			if ( document.execCommand( 'copy' ) ) {
				window.getSelection().removeAllRanges();
				done();
			}
		};
		// The clipboard API is missing on plain http and can be refused, so there is always a fallback.
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( code.textContent ).then( done, failed );
		} else {
			failed();
		}
	} );
} )( window.wp );
