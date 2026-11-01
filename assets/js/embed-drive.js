/**
 * Front-end file browser and search. File names come from Drive and are untrusted,
 * so everything here builds DOM nodes and never uses innerHTML.
 */
( function () {
	const { rest, i18n: t } = window.EmbedDriveConfig;

	const SVG = 'http://www.w3.org/2000/svg';
	const CLOSE_MS = 200;

	// Outline icons, drawn on a 24px grid.
	const ICONS = {
		home: 'M4 11l8-7 8 7M6 9.5V20h4.5v-5.5h3V20H18V9.5',
		search: 'M10.5 4a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM15.5 15.5L20 20',
		close: 'M6 6l12 12M18 6L6 18',
		sort: 'M4 7h9M17 7h3M4 17h3M11 17h9M15 5v4M9 15v4',
		more: 'M12 5.5v.01M12 12v.01M12 18.5v.01',
		refresh: 'M19.5 12a7.5 7.5 0 0 1-13.2 4.9M4.5 12a7.5 7.5 0 0 1 13.2-4.9M18 3.5v4h-4M6 20.5v-4h4',
		download: 'M12 4v11M7.5 11l4.5 4.5 4.5-4.5M5 20h14',
		up: 'M12 20V5M6 11l6-6 6 6',
		left: 'M15 5l-7 7 7 7',
		right: 'M9 5l7 7-7 7',
		chevron: 'M10 7l5 5-5 5',
		tick: 'M5 12.5l4.5 4.5L19 7.5',
		grid: 'M5 5h5.5v5.5H5zM13.5 5H19v5.5h-5.5zM5 13.5h5.5V19H5zM13.5 13.5H19V19h-5.5z',
		list: 'M5 7h14M5 12h14M5 17h14',
		selectAll: 'M5 5h14v14H5zM8.5 12l2.5 2.5 4.5-5',
		selectNone: 'M5 5h14v14H5z',
	};

	// File types: a colour and a white mark on a folded page.
	const TYPES = {
		pdf: [ '#d9483b', 'M8.5 12h7M8.5 15h7M8.5 18h4' ],
		doc: [ '#2f6fde', 'M8.5 12h7M8.5 15h7M8.5 18h4' ],
		text: [ '#6b7785', 'M8.5 12h7M8.5 15h7M8.5 18h4' ],
		sheet: [ '#1e9e5a', 'M8 11.5h8v7H8zM12 11.5v7M8 15h8' ],
		slides: [ '#e8912d', 'M8 11.5h8v5.5H8zM12 17v2' ],
		image: [ '#8455c9', 'M7.5 18l3-4 2 2.5 1.5-1.5 2.5 3zM14.5 11.5v.01' ],
		video: [ '#d6336c', 'M10 11.5l5.5 3.5-5.5 3.5z' ],
		audio: [ '#0f9aa8', 'M11 17.5v-6l4.5-1v5.5M11 17.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zM15.5 16a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z' ],
		archive: [ '#b5832a', 'M12 9.5v1.5M12 12v1.5M12 14.5V16M10.5 16.5h3v2.5h-3z' ],
		other: [ '#7b8794', '' ],
	};

	const reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	// Phones, and browsers set to download PDFs, can't show a PDF in a frame.
	const browserShowsPdf = () => navigator.pdfViewerEnabled !== false && ! window.matchMedia( '(hover: none)' ).matches;

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

	function svg( className, ...parts ) {
		const element = document.createElementNS( SVG, 'svg' );
		element.setAttribute( 'viewBox', '0 0 24 24' );
		element.setAttribute( 'class', className );
		element.setAttribute( 'aria-hidden', 'true' );
		parts.forEach( ( [ d, attributes ] ) => {
			const path = document.createElementNS( SVG, 'path' );
			path.setAttribute( 'd', d );
			Object.entries( attributes || {} ).forEach( ( [ key, value ] ) => path.setAttribute( key, value ) );
			element.append( path );
		} );
		return element;
	}

	const icon = ( name ) => svg( `embed-drive__glyph embed-drive__glyph--${ name }`, [ ICONS[ name ] ] );

	function typeOf( mime ) {
		if ( mime === 'application/pdf' ) {
			return 'pdf';
		}
		if ( mime.startsWith( 'image/' ) || mime.includes( 'drawing' ) ) {
			return 'image';
		}
		if ( mime.startsWith( 'video/' ) ) {
			return 'video';
		}
		if ( mime.startsWith( 'audio/' ) ) {
			return 'audio';
		}
		if ( mime.includes( 'spreadsheet' ) || mime.includes( 'excel' ) || mime === 'text/csv' ) {
			return 'sheet';
		}
		if ( mime.includes( 'presentation' ) || mime.includes( 'powerpoint' ) ) {
			return 'slides';
		}
		if ( mime.includes( 'zip' ) || mime.includes( 'compressed' ) || mime.includes( 'tar' ) ) {
			return 'archive';
		}
		if ( mime.includes( 'document' ) || mime.includes( 'msword' ) ) {
			return 'doc';
		}
		return mime.startsWith( 'text/' ) ? 'text' : 'other';
	}

	function typeIcon( item ) {
		if ( item.type === 'folder' ) {
			return svg( 'embed-drive__type', [ 'M3 7a2 2 0 0 1 2-2h4.2l2 2.2H19a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', { fill: '#8a919c' } ] );
		}
		const [ color, mark ] = TYPES[ typeOf( item.mime || '' ) ];
		return svg(
			'embed-drive__type',
			[ 'M6.5 2.5h7.5L19.5 8v12a1.5 1.5 0 0 1-1.5 1.5H6.5A1.5 1.5 0 0 1 5 20V4a1.5 1.5 0 0 1 1.5-1.5z', { fill: color } ],
			[ 'M14 2.5V8h5.5z', { fill: '#fff', 'fill-opacity': '.45' } ],
			[ mark, { fill: 'none', stroke: '#fff', 'stroke-width': '1.3', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } ]
		);
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

	function iconButton( name, label, onclick, className = '' ) {
		return node(
			'button',
			{ type: 'button', className: `embed-drive__tool ${ className }`.trim(), title: label, 'aria-label': label, onclick },
			icon( name )
		);
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
			this.request = 0;
			this.openPanel = null;
			this.build();
			if ( config.mode === 'files' ) {
				this.showSkeleton();
				this.load();
			}
		}

		build() {
			const { config } = this;
			const browsing = config.mode === 'files';
			const header = node( 'div', { className: 'embed-drive__header' } );

			if ( browsing ) {
				this.crumbs = node( 'ol', { className: 'embed-drive__crumbs' } );
				header.append(
					iconButton( 'home', t.home, () => this.open( '' ), 'embed-drive__home' ),
					node( 'nav', { className: 'embed-drive__path', 'aria-label': t.crumbs }, this.crumbs )
				);
			}

			if ( config.search ) {
				this.searchInput = node( 'input', {
					type: 'search',
					className: 'embed-drive__search',
					placeholder: t.search,
					autocomplete: 'off',
					'aria-label': t.search,
				} );
				this.searchInput.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						this.search( this.searchInput.value.trim() );
					}
				} );
				// Browsers fire this for their own clear button inside the box.
				this.searchInput.addEventListener( 'search', () => this.search( this.searchInput.value.trim() ) );

				const box = node(
					'div',
					{ className: 'embed-drive__searchbox' },
					iconButton( 'search', t.search, () => this.search( this.searchInput.value.trim() ) ),
					this.searchInput,
					browsing && iconButton( 'close', t.clear, () => this.clearSearch() )
				);
				if ( browsing ) {
					header.append( this.panel( 'search', t.search, box, 'embed-drive__panel--search', () => this.searchInput.focus() ) );
				} else {
					header.append( box );
				}
			}

			if ( browsing ) {
				header.append( this.panel( 'sort', t.sort, this.sortMenu() ), this.panel( 'more', t.actions, this.moreMenu() ) );
				this.refreshButton = iconButton( 'refresh', t.refresh, () => this.load() );
				header.append( this.refreshButton );
			}

			this.status = node( 'p', { className: 'embed-drive__status', role: 'status' } );
			this.folders = node( 'div', { className: 'embed-drive__folders' } );
			this.files = node( 'div', { className: 'embed-drive__files' } );
			this.more = node( 'button', {
				type: 'button',
				className: 'embed-drive__more',
				textContent: t.more,
				hidden: true,
				onclick: () => this.load( true ),
			} );
			this.body = node( 'div', { className: `embed-drive__body is-${ this.state.view }` }, this.folders, this.files, this.more );
			this.spinner = node( 'div', { className: 'embed-drive__spinner', 'aria-hidden': 'true' } );
			this.toTop = node(
				'button',
				{
					type: 'button',
					className: 'embed-drive__top',
					title: t.top,
					'aria-label': t.top,
					tabIndex: -1,
					onclick: () => this.content.scrollTo( { top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' } ),
				},
				icon( 'up' )
			);
			this.content = node( 'div', { className: 'embed-drive__content' }, this.status, this.body );

			this.content.addEventListener(
				'scroll',
				() => {
					const show = this.content.scrollTop > 240;
					this.toTop.classList.toggle( 'is-visible', show );
					this.toTop.tabIndex = show ? 0 : -1;
				},
				{ passive: true }
			);

			// Further pages load by themselves as they come into view; the button stays as a fallback.
			if ( browsing && window.IntersectionObserver ) {
				new IntersectionObserver(
					( entries ) => {
						if ( entries[ 0 ].isIntersecting && ! this.more.hidden && ! this.more.disabled ) {
							this.load( true );
						}
					},
					{ root: this.content, rootMargin: '200px' }
				).observe( this.more );
			}

			document.addEventListener( 'click', ( event ) => {
				if ( this.openPanel && ! this.openPanel.contains( event.target ) ) {
					this.closePanel();
				}
			} );
			header.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Escape' && this.openPanel ) {
					this.closePanel( true );
				}
			} );

			this.root.classList.add( browsing ? 'is-browser' : 'is-search-only' );
			this.root.replaceChildren( header, node( 'div', { className: 'embed-drive__stage' }, this.content, this.spinner, this.toTop ) );
			this.syncMenus();
		}

		/**
		 * A toolbar icon that opens a small floating panel.
		 */
		panel( name, label, content, className = '', onOpen = null ) {
			const holder = node( 'div', { className: 'embed-drive__popover' } );
			const toggle = iconButton( name, label, () => {
				const opening = this.openPanel !== holder;
				this.closePanel();
				if ( opening ) {
					holder.classList.add( 'is-open' );
					toggle.setAttribute( 'aria-expanded', 'true' );
					this.root.classList.add( 'has-panel' );
					this.openPanel = holder;
					if ( onOpen ) {
						onOpen();
					}
				}
			} );
			toggle.setAttribute( 'aria-expanded', 'false' );
			holder.append( toggle, node( 'div', { className: `embed-drive__panel ${ className }`.trim() }, content ) );
			return holder;
		}

		closePanel( returnFocus = false ) {
			if ( ! this.openPanel ) {
				return;
			}
			const toggle = this.openPanel.querySelector( '.embed-drive__tool' );
			this.openPanel.classList.remove( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );
			this.root.classList.remove( 'has-panel' );
			this.openPanel = null;
			if ( returnFocus ) {
				toggle.focus();
			}
		}

		menuItem( label, name, onclick, extra = {} ) {
			return node(
				'button',
				{ type: 'button', className: 'embed-drive__menuitem', onclick, ...extra },
				name ? icon( name ) : null,
				node( 'span', { textContent: label } )
			);
		}

		sortMenu() {
			this.sortItems = [];
			const radio = ( label, group, value ) => {
				const item = this.menuItem(
					label,
					'tick',
					() => {
						this.state[ group ] = value;
						this.syncMenus();
						this.load();
					},
					{ role: 'menuitemradio' }
				);
				item.dataset.group = group;
				item.dataset.value = value;
				this.sortItems.push( item );
				return item;
			};
			return node(
				'div',
				{ role: 'menu', className: 'embed-drive__menu', 'aria-label': t.sort },
				radio( t.name, 'sort', 'name' ),
				radio( t.size, 'sort', 'size' ),
				radio( t.modified, 'sort', 'modified' ),
				node( 'hr', { className: 'embed-drive__rule' } ),
				radio( t.ascending, 'order', 'asc' ),
				radio( t.descending, 'order', 'desc' )
			);
		}

		moreMenu() {
			const menu = node( 'div', { role: 'menu', className: 'embed-drive__menu', 'aria-label': t.actions } );
			if ( this.config.zip ) {
				this.selectAll = this.menuItem( t.selectAll, 'selectAll', () => this.setAllSelected( true ) );
				this.selectNone = this.menuItem( t.selectNone, 'selectNone', () => this.setAllSelected( false ) );
				this.zipItem = this.menuItem( t.zip, 'download', () => this.downloadZip() );
				this.zipItem.classList.add( 'embed-drive__zip' );
				menu.append( this.selectAll, this.selectNone, this.zipItem, node( 'hr', { className: 'embed-drive__rule' } ) );
			}
			this.viewItem = this.menuItem( '', null, () => {
				this.closePanel();
				this.setView( this.state.view === 'grid' ? 'list' : 'grid' );
			} );
			menu.append( this.viewItem );
			return menu;
		}

		syncMenus() {
			const { state } = this;
			( this.sortItems || [] ).forEach( ( item ) => {
				const on = state[ item.dataset.group ] === item.dataset.value;
				item.classList.toggle( 'is-checked', on );
				item.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			} );
			if ( this.viewItem ) {
				const grid = state.view === 'grid';
				this.viewItem.replaceChildren( icon( grid ? 'list' : 'grid' ), node( 'span', { textContent: grid ? t.listView : t.gridView } ) );
			}
			if ( this.zipItem ) {
				const count = state.selected.size;
				this.zipItem.disabled = count === 0;
				this.zipItem.querySelector( 'span' ).textContent = count ? `${ t.zip } (${ count })` : t.zip;
				const every = state.items.length > 0 && count === state.items.length;
				this.selectAll.hidden = every;
				this.selectNone.hidden = ! every;
			}
		}

		setView( view ) {
			this.state.view = view;
			this.syncMenus();
			this.body.classList.add( 'is-switching' );
			window.setTimeout(
				() => {
					this.body.className = `embed-drive__body is-${ view }`;
					this.replay( this.body.querySelectorAll( '.embed-drive__item' ) );
				},
				reducedMotion.matches ? 0 : 120
			);
		}

		setAllSelected( on ) {
			const { state } = this;
			state.selected.clear();
			if ( on ) {
				state.items.forEach( ( item ) => state.selected.add( item.id ) );
			}
			this.body.querySelectorAll( '.embed-drive__item' ).forEach( ( row ) => {
				row.classList.toggle( 'is-selected', on );
				const check = row.querySelector( '.embed-drive__check' );
				if ( check ) {
					check.checked = on;
				}
			} );
			this.syncMenus();
		}

		replay( rows ) {
			rows.forEach( ( row, index ) => {
				row.classList.remove( 'is-new' );
				void row.offsetWidth; // Restart the animation.
				row.style.setProperty( '--ed-delay', `${ Math.min( index, 12 ) * 18 }ms` );
				row.classList.add( 'is-new' );
			} );
		}

		showSkeleton() {
			const bone = ( className ) => node( 'span', { className: `embed-drive__bone ${ className }` } );
			const entry = ( kind ) =>
				node(
					'div',
					{ className: `embed-drive__item is-skeleton is-${ kind }`, 'aria-hidden': 'true' },
					kind === 'file' && node( 'div', { className: 'embed-drive__thumb' }, bone( 'embed-drive__bone--image' ) ),
					node(
						'div',
						{ className: 'embed-drive__info' },
						node( 'span', { className: 'embed-drive__lead' }, bone( 'embed-drive__bone--icon' ) ),
						bone( 'embed-drive__bone--text' )
					)
				);
			this.folders.replaceChildren( ...[ 1, 2, 3 ].map( () => entry( 'folder' ) ) );
			this.files.replaceChildren( ...[ 1, 2, 3, 4, 5 ].map( () => entry( 'file' ) ) );
		}

		setBusy( busy ) {
			this.root.classList.toggle( 'is-busy', busy );
			if ( this.refreshButton ) {
				this.refreshButton.classList.toggle( 'is-spinning', busy );
			}
		}

		setStatus( message = '', screenReaderOnly = false ) {
			this.status.textContent = message;
			this.status.classList.toggle( 'is-hidden', screenReaderOnly );
		}

		async load( append = false ) {
			const { state, config } = this;
			const request = ++this.request;
			state.query = '';
			if ( this.searchInput ) {
				this.searchInput.value = '';
			}
			if ( append ) {
				this.more.disabled = true;
				this.more.textContent = t.loading;
			} else {
				this.setBusy( true );
			}
			this.setStatus( t.loading, true );

			try {
				const data = await fetch(
					url( 'list', {
						m: config.token,
						folder: state.folder,
						sort: state.sort,
						order: state.order,
						cursor: append ? state.next : '',
					} )
				).then( json );
				if ( request !== this.request ) {
					return; // Someone has already moved on to another folder.
				}
				if ( ! append ) {
					this.reset();
				}
				this.drawCrumbs( data.breadcrumbs );
				const added = this.add( data.items );
				// A page with nothing new means something is replaying an old answer, so stop asking.
				state.next = append && ! added ? '' : data.next;
				this.setStatus( state.items.length ? '' : t.empty );
			} catch ( error ) {
				if ( request === this.request ) {
					this.dropSkeleton();
					this.setStatus( error.message );
				}
			}
			if ( request === this.request ) {
				this.setBusy( false );
				this.more.disabled = false;
				this.more.textContent = t.more;
				this.more.hidden = ! state.next;
			}
		}

		async search( term ) {
			const { state, config } = this;
			if ( ! term ) {
				this.clearSearch();
				return;
			}
			const request = ++this.request;
			state.query = term;
			this.setBusy( true );
			this.setStatus( t.loading, true );

			try {
				const data = await fetch( url( 'search', { m: config.token, q: term } ) ).then( json );
				if ( request !== this.request ) {
					return; // A newer search has started.
				}
				this.reset();
				state.next = '';
				if ( this.crumbs ) {
					this.crumbs.replaceChildren( node( 'li', {}, node( 'span', { className: 'embed-drive__crumb is-current', textContent: t.results, 'aria-current': 'page' } ) ) );
				}
				this.add( data.items );
				this.setStatus( data.items.length ? ( data.truncated ? t.truncated : '' ) : t.noResults );
			} catch ( error ) {
				if ( request === this.request ) {
					this.setStatus( error.message );
				}
			}
			if ( request === this.request ) {
				this.setBusy( false );
				this.more.hidden = true;
			}
		}

		clearSearch() {
			if ( this.config.mode === 'files' ) {
				this.load();
				return;
			}
			this.request++;
			this.reset();
			this.setStatus();
			this.setBusy( false );
		}

		reset() {
			this.state.items = [];
			this.state.selected.clear();
			this.folders.replaceChildren();
			this.files.replaceChildren();
			this.content.scrollTop = 0;
		}

		dropSkeleton() {
			this.body.querySelectorAll( '.is-skeleton' ).forEach( ( row ) => row.remove() );
		}

		add( items ) {
			const start = this.state.items.length;
			const known = new Set( this.state.items.map( ( item ) => item.id ) );
			const fresh = items.filter( ( item ) => ! known.has( item.id ) );
			this.state.items = this.state.items.concat( fresh );
			const rows = fresh.map( ( item ) => {
				const row = this.drawItem( item );
				( item.type === 'folder' ? this.folders : this.files ).append( row );
				return row;
			} );
			this.replay( rows.slice( 0, 40 ) );
			if ( start === 0 && ! items.length ) {
				this.dropSkeleton();
			}
			this.syncMenus();
			return fresh.length;
		}

		drawCrumbs( path ) {
			this.crumbs.replaceChildren(
				...path.map( ( crumb, index ) => {
					const last = index === path.length - 1;
					return node(
						'li',
						{},
						index > 0 && svg( 'embed-drive__sep', [ ICONS.chevron ] ),
						last
							? node( 'span', { className: 'embed-drive__crumb is-current', textContent: crumb.name, title: crumb.name, 'aria-current': 'page' } )
							: node( 'button', {
									type: 'button',
									className: 'embed-drive__crumb',
									textContent: crumb.name,
									title: crumb.name,
									onclick: () => this.open( crumb.id ),
							  } )
					);
				} )
			);
		}

		open( folder ) {
			this.state.folder = folder;
			this.closePanel();
			this.load();
		}

		drawItem( item ) {
			const { state, config } = this;
			const isFolder = item.type === 'folder';
			const canPreview = item.preview !== 'none';

			const title = node( 'button', {
				type: 'button',
				className: 'embed-drive__name',
				textContent: item.name,
				title: item.name,
				onclick: () => {
					if ( isFolder ) {
						state.query = '';
						this.open( item.id );
					} else if ( canPreview ) {
						this.preview( item );
					} else if ( item.download ) {
						window.location.href = this.fileUrl( item, 'attachment' );
					}
				},
			} );
			title.disabled = ! isFolder && ! canPreview && ! item.download;

			const lead = node( 'span', { className: 'embed-drive__lead' }, node( 'span', { className: 'embed-drive__icon' }, typeIcon( item ) ) );
			const row = node( 'div', { className: `embed-drive__item ${ isFolder ? 'is-folder' : 'is-file' }` } );

			if ( config.zip ) {
				row.classList.add( 'has-check' );
				lead.append(
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
							row.classList.toggle( 'is-selected', event.target.checked );
							this.syncMenus();
						},
					} )
				);
			}

			if ( ! isFolder ) {
				const thumb = node( 'div', { className: 'embed-drive__thumb' }, node( 'span', { className: 'embed-drive__placeholder' }, typeIcon( item ) ) );
				if ( item.thumb ) {
					const image = node( 'img', { loading: 'lazy', alt: '', src: url( 'thumb', { m: config.token, id: item.id } ) } );
					image.addEventListener( 'load', () => image.classList.add( 'is-loaded' ) );
					image.addEventListener( 'error', () => image.remove() );
					thumb.append( image );
				}
				row.append( thumb );
			}

			row.append(
				node(
					'div',
					{ className: 'embed-drive__info' },
					lead,
					title,
					! isFolder && node( 'span', { className: 'embed-drive__date', textContent: formatDate( item.modified ) } ),
					! isFolder && node( 'span', { className: 'embed-drive__size', textContent: formatSize( item.size ) } ),
					item.download &&
						node(
							'a',
							{
								className: 'embed-drive__download',
								href: this.fileUrl( item, 'attachment' ),
								rel: 'nofollow',
								title: t.download,
								'aria-label': `${ t.download } ${ item.name }`,
							},
							icon( 'download' )
						)
				)
			);
			return row;
		}

		fileUrl( item, disposition ) {
			return url( 'file', { m: this.config.token, id: item.id, disp: disposition } );
		}

		preview( item ) {
			const items = this.state.items.filter( ( entry ) => entry.preview !== 'none' );
			new Lightbox( items, items.indexOf( item ), ( entry, disposition ) => this.fileUrl( entry, disposition ), ( entry ) => url( 'thumb', { m: this.config.token, id: entry.id, large: 1 } ) );
		}

		async downloadZip() {
			const { state, config } = this;
			this.closePanel();
			this.zipItem.disabled = true;
			this.setStatus( t.preparing );
			try {
				const data = await fetch( url( 'zip/prepare', { m: config.token } ), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { ids: Array.from( state.selected ) } ),
				} ).then( json );
				window.location.href = data.url;
				this.setStatus();
			} catch ( error ) {
				this.setStatus( error.message );
			}
			this.syncMenus();
		}
	}

	/**
	 * Full-screen preview. Moves between the folder's files without closing.
	 */
	class Lightbox {
		constructor( items, index, fileUrl, largeThumb ) {
			this.items = items;
			this.index = index;
			this.fileUrl = fileUrl;
			this.largeThumb = largeThumb;

			const several = items.length > 1;
			this.counter = node( 'span', { className: 'embed-drive-preview__count' } );
			this.title = node( 'span', { className: 'embed-drive-preview__title' } );
			this.download = node(
				'a',
				{ className: 'embed-drive-preview__action', rel: 'nofollow', title: t.download, 'aria-label': t.download },
				icon( 'download' )
			);
			this.media = node( 'div', { className: 'embed-drive-preview__media' } );
			this.stage = node( 'div', { className: 'embed-drive-preview__stage' }, node( 'div', { className: 'embed-drive__spinner' } ), this.media );
			this.tip = node( 'p', { className: 'embed-drive-preview__tip', textContent: t.viewerTip, hidden: true } );

			const action = ( name, label, onclick, className = 'embed-drive-preview__action' ) =>
				node( 'button', { type: 'button', className, title: label, 'aria-label': label, onclick }, icon( name ) );
			const side = ( name, label, step, where ) => several && action( name, label, () => this.go( step ), `embed-drive-preview__nav embed-drive-preview__nav--${ where }` );

			this.dialog = node(
				'dialog',
				{ className: 'embed-drive-preview' },
				node(
					'div',
					{ className: 'embed-drive-preview__bar' },
					several && this.counter,
					this.title,
					this.download,
					action( 'close', t.close, () => this.close() )
				),
				this.tip,
				this.stage,
				side( 'left', t.previous, -1, 'prev' ),
				side( 'right', t.next, 1, 'next' )
			);

			this.dialog.addEventListener( 'cancel', ( event ) => {
				event.preventDefault();
				this.close();
			} );
			this.dialog.addEventListener( 'click', ( event ) => {
				if ( event.target.classList.contains( 'embed-drive-preview__stage' ) || event.target === this.media ) {
					this.close();
				}
			} );
			this.dialog.addEventListener( 'keydown', ( event ) => {
				// Arrow keys belong to a player that has focus.
				if ( ! several || [ 'VIDEO', 'AUDIO' ].includes( event.target.tagName ) ) {
					return;
				}
				if ( event.key === 'ArrowLeft' ) {
					this.go( -1 );
				} else if ( event.key === 'ArrowRight' ) {
					this.go( 1 );
				}
			} );
			if ( several ) {
				let startX = null;
				this.dialog.addEventListener( 'touchstart', ( event ) => ( startX = event.touches[ 0 ].clientX ), { passive: true } );
				this.dialog.addEventListener(
					'touchend',
					( event ) => {
						const moved = startX === null ? 0 : event.changedTouches[ 0 ].clientX - startX;
						if ( Math.abs( moved ) > 60 ) {
							this.go( moved > 0 ? -1 : 1 );
						}
					},
					{ passive: true }
				);
			}

			document.body.append( this.dialog );
			document.documentElement.classList.add( 'embed-drive-locked' );
			this.dialog.showModal();
			this.show();
		}

		go( step ) {
			this.index = ( this.index + step + this.items.length ) % this.items.length;
			this.show();
		}

		show() {
			const item = this.items[ this.index ];
			const src = this.fileUrl( item, 'inline' );
			const googleViewer = ( file ) => `https://docs.google.com/viewer?embedded=true&url=${ encodeURIComponent( file ) }`;
			const viaGoogle = item.preview === 'viewer' || ( item.preview === 'pdf' && ! browserShowsPdf() );
			let media;
			switch ( item.preview ) {
				case 'image':
					media = node( 'img', { src, alt: item.name } );
					break;
				case 'thumb':
					media = node( 'img', { src: this.largeThumb( item ), alt: item.name } );
					break;
				case 'video':
					media = node( 'video', { src, controls: true, autoplay: true } );
					break;
				case 'audio':
					media = node( 'audio', { src, controls: true, autoplay: true } );
					break;
				default:
					media = node( 'iframe', {
						src: viaGoogle ? googleViewer( item.preview === 'viewer' ? this.fileUrl( item, 'viewer' ) : src ) : src,
						title: item.name,
					} );
			}
			this.stage.classList.add( 'is-loading' );
			const ready = () => {
				media.classList.add( 'is-ready' );
				if ( this.media.contains( media ) ) {
					this.stage.classList.remove( 'is-loading' );
				}
			};
			media.addEventListener( [ 'VIDEO', 'AUDIO' ].includes( media.tagName ) ? 'loadedmetadata' : 'load', ready );
			media.addEventListener( 'error', ready );
			// Not every browser reports a PDF frame as loaded, so never leave it hidden.
			window.setTimeout( ready, 2500 );

			this.counter.textContent = `${ this.index + 1 } / ${ this.items.length }`;
			this.title.textContent = item.name;
			this.title.title = item.name;
			this.download.hidden = ! item.download;
			if ( item.download ) {
				this.download.href = this.fileUrl( item, 'attachment' );
			}
			this.tip.hidden = ! ( viaGoogle && item.download );
			this.media.replaceChildren( media );
		}

		close() {
			if ( this.closing ) {
				return;
			}
			this.closing = true;
			this.dialog.classList.add( 'is-closing' );
			window.setTimeout(
				() => {
					this.dialog.close();
					this.dialog.remove();
					document.documentElement.classList.remove( 'embed-drive-locked' );
				},
				reducedMotion.matches ? 0 : CLOSE_MS
			);
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
