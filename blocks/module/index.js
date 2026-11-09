( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl, TextControl, Button, Placeholder } = wp.components;
	const { __ } = wp.i18n;

	const MODES = [
		{ value: 'files', label: __( 'File browser', 'filefolio' ) },
		{ value: 'gallery', label: __( 'Photo gallery', 'filefolio' ) },
		{ value: 'search', label: __( 'Search box', 'filefolio' ) },
		{ value: 'embed', label: __( 'Embed one file', 'filefolio' ) },
		{ value: 'button', label: __( 'Download button', 'filefolio' ) },
		{ value: 'link', label: __( 'Download link', 'filefolio' ) },
	];

	const wantsFolder = ( mode ) => [ 'files', 'gallery', 'search' ].includes( mode );

	function edit( { attributes: a, setAttributes: set } ) {
		const accounts = window.EmbedDriveAccounts || [];
		const account = a.account || ( accounts[ 0 ] && accounts[ 0 ].id ) || '';
		const folderMode = wantsFolder( a.mode );
		const target = folderMode ? a.folder : a.file;
		const modeLabel = MODES.find( ( m ) => m.value === a.mode )?.label;

		const choose = () =>
			window.EmbedDrivePicker.open( {
				account,
				want: folderMode ? 'folder' : 'file',
				onSelect: ( item ) =>
					set( {
						account,
						targetName: item.name,
						folder: folderMode ? item.id : '',
						file: folderMode ? '' : item.id,
					} ),
			} );

		const chooser = accounts.length
			? el(
					Button,
					{ variant: target ? 'secondary' : 'primary', onClick: choose },
					folderMode ? __( 'Choose a folder', 'filefolio' ) : __( 'Choose a file', 'filefolio' )
			  )
			: el( 'p', null, __( 'Connect a Google account under FileFolio, then come back.', 'filefolio' ) );

		const inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Module', 'filefolio' ) },
				el( SelectControl, {
					label: __( 'Type', 'filefolio' ),
					value: a.mode,
					options: MODES,
					onChange: ( mode ) =>
						set( wantsFolder( mode ) === folderMode ? { mode } : { mode, folder: '', file: '', targetName: '' } ),
				} ),
				accounts.length > 1 &&
					el( SelectControl, {
						label: __( 'Google account', 'filefolio' ),
						value: account,
						options: accounts.map( ( x ) => ( { value: x.id, label: x.email } ) ),
						onChange: ( value ) => set( { account: value, folder: '', file: '', targetName: '' } ),
					} ),
				chooser
			),
			el(
				PanelBody,
				{ title: __( 'Options', 'filefolio' ) },
				a.mode === 'files' &&
					el( SelectControl, {
						label: __( 'Layout', 'filefolio' ),
						value: a.view,
						options: [
							{ value: 'grid', label: __( 'Grid', 'filefolio' ) },
							{ value: 'list', label: __( 'List', 'filefolio' ) },
						],
						onChange: ( view ) => set( { view } ),
					} ),
				( a.mode === 'files' || a.mode === 'gallery' ) &&
					el( SelectControl, {
						label: __( 'Sort by', 'filefolio' ),
						value: a.sort,
						options: [
							{ value: 'name', label: __( 'Name', 'filefolio' ) },
							{ value: 'modified', label: __( 'Modified', 'filefolio' ) },
							{ value: 'size', label: __( 'Size', 'filefolio' ) },
						],
						onChange: ( sort ) => set( { sort } ),
					} ),
				( a.mode === 'files' || a.mode === 'gallery' ) &&
					el( SelectControl, {
						label: __( 'Order', 'filefolio' ),
						value: a.order,
						options: [
							{ value: 'asc', label: __( 'Ascending', 'filefolio' ) },
							{ value: 'desc', label: __( 'Descending', 'filefolio' ) },
						],
						onChange: ( order ) => set( { order } ),
					} ),
				( folderMode || a.mode === 'embed' ) &&
					el( ToggleControl, {
						label: __( 'Allow downloads', 'filefolio' ),
						checked: a.download === undefined ? a.mode !== 'embed' : a.download,
						onChange: ( download ) => set( { download } ),
					} ),
				a.mode === 'files' &&
					el( ToggleControl, {
						label: __( 'Allow ZIP downloads', 'filefolio' ),
						checked: a.zip,
						onChange: ( zip ) => set( { zip } ),
					} ),
				( a.mode === 'files' || a.mode === 'gallery' ) &&
					el( TextControl, {
						label: __( 'Minimum height (px)', 'filefolio' ),
						help: __( 'Use 0 to follow the plugin settings.', 'filefolio' ),
						type: 'number',
						value: a.min_height,
						onChange: ( value ) => set( { min_height: Math.max( 0, parseInt( value, 10 ) || 0 ) } ),
					} ),
				a.mode === 'files' &&
					el( ToggleControl, {
						label: __( 'Show search box', 'filefolio' ),
						checked: a.search,
						onChange: ( search ) => set( { search } ),
					} ),
				folderMode &&
					a.mode !== 'gallery' &&
					el( ToggleControl, {
						label: __( 'Allow previews', 'filefolio' ),
						checked: a.preview,
						onChange: ( preview ) => set( { preview } ),
					} ),
				( a.mode === 'button' || a.mode === 'link' ) &&
					el( TextControl, {
						label: __( 'Label', 'filefolio' ),
						value: a.label,
						onChange: ( label ) => set( { label } ),
					} ),
				a.mode === 'embed' &&
					el( TextControl, {
						label: __( 'Height for documents (px)', 'filefolio' ),
						type: 'number',
						value: a.height,
						onChange: ( value ) => set( { height: parseInt( value, 10 ) || 600 } ),
					} )
			)
		);

		return el(
			Fragment,
			null,
			inspector,
			el(
				'div',
				useBlockProps(),
				el(
					Placeholder,
					{
						icon: 'cloud',
						label: 'FileFolio' + ( modeLabel ? ': ' + modeLabel : '' ),
						instructions: target
							? a.targetName || target
							: __( 'Choose what to show from Google Drive.', 'filefolio' ),
					},
					chooser
				)
			)
		);
	}

	registerBlockType( 'embed-drive/module', {
		icon: el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 20 20' }, el( 'path', { fillRule: 'evenodd', d: 'M2 5.5A1.5 1.5 0 0 1 3.5 4h4.2l1.8 2h7A1.5 1.5 0 0 1 18 7.5v8a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 15.5zM7.7 8.6 4.8 11.5l2.9 2.9 1-1-1.9-1.9 1.9-1.9zM12.3 8.6l-1 1 1.9 1.9-1.9 1.9 1 1 2.9-2.9z' } ) ),
		edit,
		save: () => null,
	} );
} )( window.wp );
