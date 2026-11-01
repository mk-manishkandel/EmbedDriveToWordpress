/**
 * Front-end file browser and search. File names come from Drive and are untrusted,
 * so everything here builds DOM nodes and never uses innerHTML.
 */
( function () {
	const { rest, i18n: t } = window.EmbedDriveConfig;

	const SORTS = [
		[ 'name', 'asc', t.name + ' ↑' ],
		[ 'name', 'desc', t.name + ' ↓' ],
		[ 'modified', 'desc', t.modified + ' ↓' ],
		[ 'modified', 'asc', t.modified + ' ↑' ],
		[ 'size', 'desc', t.size + ' ↓' ],
		[ 'size', 'asc', t.size + ' ↑' ],
	];

	function node( tag, props, ...children ) {
		const element = document.createElement( tag );
		Object.entries( props || {} ).forEach( ( [ key, value ] ) => {
			if ( key.startsWith( 'aria-' ) || key === 'role' ) {
				element.setAttribute( key, value );
			} else {
				element[ key ] = value;
			}
		} );
		children.forEach( ( child ) => child && element.append( child ) );
		return element;
	}

	function url( route, params ) {
		const target = new URL( rest + route, window.location.href );
		Object.entries( params ).forEach( ( [ key, value ] ) => {
			if ( value !== '' && value !== undefined ) {
				target.searchParams.set( key, value );
			}
		} );
		return target.toString();
	}

	async function json( response ) {
		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			throw new Error( data.message || t.error );
		}
		return data;
	}

	function formatSize( bytes ) {
		if ( bytes === null || bytes === undefined ) {
			return '';
		}
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let value = bytes;
		let unit = 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit++;
		}
		return `${ unit && value < 10 ? value.toFixed( 1 ) : Math.round( value ) } ${ units[ unit ] }`;
	}

	function formatDate( iso ) {
		const date = iso ? new Date( iso ) : null;
		return date && ! isNaN( date ) ? date.toLocaleDateString( undefined, { dateStyle: 'medium' } ) : '';
	}

	function iconFor( item ) {
		if ( item.type === 'folder' ) {
			return '📁';
		}
		const mime = item.mime || '';
		if ( mime.startsWith( 'image/' ) ) {
			return '🖼️';
		}
		if ( mime.startsWith( 'video/' ) ) {
			return '🎬';
		}
		if ( mime.startsWith( 'audio/' ) ) {
			return '🎵';
		}
		if ( mime.includes( 'spreadsheet' ) || mime.includes( 'excel' ) ) {
			return '📊';
		}
		if ( mime.includes( 'presentation' ) || mime.includes( 'powerpoint' ) ) {
			return '📽️';
		}
		if ( mime.includes( 'zip' ) || mime.includes( 'compressed' ) ) {
			return '🗜️';
		}
		return '📄';
	}

	class Browser {
		constructor( root, config ) {
			this.root = root;
			this.config = config;
			this.state = {
				folder: '',
				view: config.view,
				sort: config.sort,
				order: config.order,
				next: '',
				items: [],
				query: '',
				selected: new Set(),
			};
			this.build();
			if ( config.mode === 'files' ) {
				this.load();
			}
		}

		build() {
			const { config } = this;
			this.crumbs = node( 'nav', { className: 'embed-drive__crumbs', 'aria-label': t.crumbs } );
			this.status = node( 'p', { className: 'embed-drive__status', role: 'status' } );
			this.body = node( 'div', { className: 'embed-drive__body' } );
			this.more = node( 'button', {
				type: 'button',
				className: 'embed-drive__more',
				textContent: t.more,
				hidden: true,
				onclick: () => this.load( true ),
			} );
			this.zipButton = node( 'button', {
				type: 'button',
				className: 'embed-drive__zip',
				textContent: t.zip,
				hidden: true,
				onclick: () => this.downloadZip(),
			} );

			const tools = node( 'div', { className: 'embed-drive__tools' } );
			if ( config.search ) {
				const input = node( 'input', {
					type: 'search',
					className: 'embed-drive__search',
					placeholder: t.search,
					'aria-label': t.search,
				} );
				this.searchInput = input;
				input.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						this.search( input.value.trim() );
					}
				} );
				input.addEventListener( 'search', () => this.search( input.value.trim() ) );
				tools.append( input );
			}
			if ( config.mode === 'files' ) {
				const sort = node(
					'select',
					{
						className: 'embed-drive__sort',
						'aria-label': t.sort,
						onchange: ( event ) => {
							[ this.state.sort, this.state.order ] = event.target.value.split( ':' );
							this.load();
						},
					},
					...SORTS.map( ( [ field, order, label ] ) => node( 'option', { value: `${ field }:${ order }`, textContent: label } ) )
				);
				sort.value = `${ this.state.sort }:${ this.state.order }`;
				this.viewButton = node( 'button', {
					type: 'button',
					className: 'embed-drive__view',
					onclick: () => {
						this.state.view = this.state.view === 'grid' ? 'list' : 'grid';
						this.draw();
					},
				} );
				tools.append( sort, this.viewButton );
			}

			this.root.replaceChildren( this.crumbs, tools, this.status, this.body, this.more, this.zipButton );
		}

		async load( append = false ) {
			const { state, config } = this;
			state.query = '';
			if ( this.searchInput ) {
				this.searchInput.value = '';
			}
			if ( ! append ) {
				state.items = [];
				state.next = '';
				state.selected.clear();
				this.body.replaceChildren();
			}
			this.status.textContent = t.loading;
			this.more.hidden = true;

			try {
				const data = await fetch(
					url( 'list', {
						m: config.token,
						folder: state.folder,
						sort: state.sort,
						order: state.order,
						page: append ? state.next : '',
					} )
				).then( json );
				state.items = state.items.concat( data.items );
				state.next = data.next;
				this.drawCrumbs( data.breadcrumbs );
				this.status.textContent = state.items.length ? '' : t.empty;
				this.draw();
			} catch ( error ) {
				this.status.textContent = error.message;
			}
		}

		async search( term ) {
			const { state, config } = this;
			if ( ! term ) {
				if ( config.mode === 'files' ) {
					this.load();
				} else {
					state.items = [];
					this.status.textContent = '';
					this.draw();
				}
				return;
			}
			state.query = term;
			state.next = '';
			state.items = [];
			state.selected.clear();
			this.body.replaceChildren();
			this.more.hidden = true;
			this.status.textContent = t.loading;

			try {
				const data = await fetch( url( 'search', { m: config.token, q: term } ) ).then( json );
				if ( state.query !== term ) {
					return; // A newer search has started.
				}
				state.items = data.items;
				if ( config.mode === 'files' ) {
					this.crumbs.replaceChildren(
						node( 'button', {
							type: 'button',
							className: 'embed-drive__crumb',
							textContent: t.clear,
							onclick: () => this.load(),
						} )
					);
				}
				this.status.textContent = data.items.length ? ( data.truncated ? t.truncated : '' ) : t.noResults;
				this.draw();
			} catch ( error ) {
				this.status.textContent = error.message;
			}
		}

		drawCrumbs( path ) {
			this.crumbs.replaceChildren(
				...path.flatMap( ( crumb, index ) => {
					const last = index === path.length - 1;
					const label = last
						? node( 'span', { className: 'embed-drive__crumb is-current', textContent: crumb.name, 'aria-current': 'page' } )
						: node( 'button', {
								type: 'button',
								className: 'embed-drive__crumb',
								textContent: crumb.name,
								onclick: () => this.open( crumb.id ),
						  } );
					return last ? [ label ] : [ label, node( 'span', { className: 'embed-drive__sep', textContent: '/', 'aria-hidden': 'true' } ) ];
				} )
			);
		}

		open( folder ) {
			this.state.folder = folder;
			this.load();
		}

		draw() {
			const { state, config } = this;
			if ( this.viewButton ) {
				this.viewButton.textContent = state.view === 'grid' ? t.listView : t.gridView;
			}
			this.body.className = `embed-drive__body is-${ state.view }`;
			this.body.replaceChildren( ...state.items.map( ( item ) => this.drawItem( item ) ) );
			this.more.hidden = ! state.next;
			this.zipButton.hidden = ! config.zip || state.selected.size === 0;
			this.zipButton.textContent = `${ t.zip } (${ state.selected.size })`;
		}

		drawItem( item ) {
			const { state, config } = this;
			const canPreview = item.preview !== 'none';
			const title = node( 'button', {
				type: 'button',
				className: 'embed-drive__name',
				textContent: item.name,
				title: item.name,
				onclick: () => {
					if ( item.type === 'folder' ) {
						this.state.query = '';
						this.open( item.id );
					} else if ( canPreview ) {
						this.preview( item );
					} else if ( item.download ) {
						window.location.href = this.fileUrl( item, 'attachment' );
					}
				},
			} );
			title.disabled = item.type !== 'folder' && ! canPreview && ! item.download;

			const icon = node( 'span', { className: 'embed-drive__icon', textContent: iconFor( item ), 'aria-hidden': 'true' } );
			if ( item.thumb ) {
				const image = node( 'img', { loading: 'lazy', alt: '', src: url( 'thumb', { m: config.token, id: item.id } ) } );
				image.addEventListener( 'error', () => image.remove() );
				icon.append( image );
			}

			const row = node(
				'div',
				{ className: 'embed-drive__item' },
				config.zip &&
					node( 'input', {
						type: 'checkbox',
						className: 'embed-drive__check',
						checked: state.selected.has( item.id ),
						'aria-label': `${ t.select } ${ item.name }`,
						onchange: ( event ) => {
							if ( event.target.checked ) {
								state.selected.add( item.id );
							} else {
								state.selected.delete( item.id );
							}
							this.zipButton.hidden = state.selected.size === 0;
							this.zipButton.textContent = `${ t.zip } (${ state.selected.size })`;
						},
					} ),
				icon,
				title,
				node( 'span', { className: 'embed-drive__date', textContent: formatDate( item.modified ) } ),
				node( 'span', { className: 'embed-drive__size', textContent: formatSize( item.size ) } ),
				item.download
					? node( 'a', {
							className: 'embed-drive__download',
							href: this.fileUrl( item, 'attachment' ),
							rel: 'nofollow',
							textContent: '↓',
							title: t.download,
							'aria-label': `${ t.download } ${ item.name }`,
					  } )
					: node( 'span', { className: 'embed-drive__download' } )
			);
			return row;
		}

		fileUrl( item, disposition ) {
			return url( 'file', { m: this.config.token, id: item.id, disp: disposition } );
		}

		preview( item ) {
			const src = this.fileUrl( item, 'inline' );
			let media;
			switch ( item.preview ) {
				case 'image':
					media = node( 'img', { src, alt: item.name } );
					break;
				case 'video':
					media = node( 'video', { src, controls: true, autoplay: true } );
					break;
				case 'audio':
					media = node( 'audio', { src, controls: true, autoplay: true } );
					break;
				default:
					media = node( 'iframe', { src, title: item.name } );
			}

			const dialog = node(
				'dialog',
				{ className: 'embed-drive-preview' },
				node(
					'div',
					{ className: 'embed-drive-preview__bar' },
					node( 'span', { className: 'embed-drive-preview__title', textContent: item.name } ),
					item.download && node( 'a', { href: this.fileUrl( item, 'attachment' ), rel: 'nofollow', textContent: t.download } ),
					node( 'button', { type: 'button', textContent: t.close, onclick: () => dialog.close() } )
				),
				node( 'div', { className: 'embed-drive-preview__media' }, media )
			);
			dialog.addEventListener( 'click', ( event ) => {
				if ( event.target === dialog ) {
					dialog.close();
				}
			} );
			dialog.addEventListener( 'close', () => dialog.remove() );
			document.body.append( dialog );
			dialog.showModal();
		}

		async downloadZip() {
			const { state, config } = this;
			const label = this.zipButton.textContent;
			this.zipButton.disabled = true;
			this.zipButton.textContent = t.preparing;
			try {
				const data = await fetch( url( 'zip/prepare', { m: config.token } ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { ids: Array.from( state.selected ) } ),
				} ).then( json );
				window.location.href = data.url;
				this.status.textContent = '';
			} catch ( error ) {
				this.status.textContent = error.message;
			}
			this.zipButton.disabled = false;
			this.zipButton.textContent = label;
		}
	}

	function start() {
		document.querySelectorAll( '[data-embed-drive]' ).forEach( ( root ) => {
			try {
				new Browser( root, JSON.parse( root.dataset.embedDrive ) );
			} catch ( error ) {
				root.textContent = t.error;
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
