/**
 * Drive folder/file picker used by the block and the shortcode builder.
 */
( function ( wp ) {
	const { __ } = wp.i18n;

	function node( tag, props, ...children ) {
		const element = document.createElement( tag );
		Object.assign( element, props || {} );
		children.forEach( ( child ) => child && element.append( child ) );
		return element;
	}

	/**
	 * @param {Object}   options
	 * @param {string}   options.account  Connected account id.
	 * @param {string}   options.want     'folder' or 'file'.
	 * @param {Function} options.onSelect Called with { id, name }.
	 */
	function open( { account, want, onSelect } ) {
		const trail = [ { id: '', name: __( 'Google Drive', 'embed-drive' ) } ];
		const crumbs = node( 'div', { className: 'embed-drive-picker__crumbs' } );
		const list = node( 'ul', { className: 'embed-drive-picker__list' } );
		const status = node( 'p', { className: 'embed-drive-picker__status' } );
		status.setAttribute( 'role', 'status' );
		const useFolder = node( 'button', {
			type: 'button',
			className: 'button button-primary',
			textContent: __( 'Use this folder', 'embed-drive' ),
			hidden: want !== 'folder',
			disabled: true,
		} );
		const dialog = node(
			'dialog',
			{ className: 'embed-drive-picker' },
			node(
				'div',
				{ className: 'embed-drive-picker__head' },
				node( 'h2', { textContent: want === 'folder' ? __( 'Choose a folder', 'embed-drive' ) : __( 'Choose a file', 'embed-drive' ) } ),
				node( 'button', {
					type: 'button',
					className: 'button-link',
					textContent: __( 'Close', 'embed-drive' ),
					onclick: () => dialog.close(),
				} )
			),
			crumbs,
			status,
			list,
			node( 'div', { className: 'embed-drive-picker__foot' }, useFolder )
		);
		dialog.addEventListener( 'close', () => dialog.remove() );

		const current = () => trail[ trail.length - 1 ];

		function pick( item ) {
			onSelect( { id: item.id, name: item.name } );
			dialog.close();
		}
		useFolder.onclick = () => pick( current() );

		function render( items ) {
			crumbs.replaceChildren(
				...trail.map( ( crumb, index ) =>
					index === trail.length - 1
						? node( 'span', { textContent: crumb.name } )
						: node( 'button', {
								type: 'button',
								className: 'button-link',
								textContent: crumb.name,
								onclick: () => {
									trail.length = index + 1;
									load();
								},
						  } )
				)
			);
			list.replaceChildren(
				...items
					.filter( ( item ) => want === 'file' || item.type !== 'file' )
					.map( ( item ) => {
						const isDir = item.type !== 'file';
						return node(
							'li',
							null,
							node( 'button', {
								type: 'button',
								className: 'embed-drive-picker__item',
								textContent: ( isDir ? '📁 ' : '📄 ' ) + item.name,
								onclick: () => {
									if ( isDir ) {
										trail.push( item );
										load();
									} else {
										pick( item );
									}
								},
							} )
						);
					} )
			);
			if ( ! list.children.length ) {
				status.textContent = __( 'Nothing to choose here.', 'embed-drive' );
			}
		}

		function load() {
			const here = current();
			useFolder.disabled = ! here.id || here.id === 'shared';
			status.textContent = __( 'Loading…', 'embed-drive' );
			list.replaceChildren();
			wp.apiFetch( {
				path:
					'/embed-drive/v1/admin/browse?account=' +
					encodeURIComponent( account ) +
					'&parent=' +
					encodeURIComponent( here.id ),
			} )
				.then( ( data ) => {
					status.textContent = '';
					render( data.items );
				} )
				.catch( ( error ) => {
					status.textContent = error.message || __( 'Could not load this folder.', 'embed-drive' );
				} );
		}

		document.body.append( dialog );
		dialog.showModal();
		load();
	}

	window.EmbedDrivePicker = { open };
} )( window.wp );
