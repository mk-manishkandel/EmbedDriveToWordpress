( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl, TextControl, Button, Placeholder } = wp.components;
	const { __ } = wp.i18n;

	const MODES = [
		{ value: 'files', label: __( 'File browser', 'embed-drive' ) },
		{ value: 'search', label: __( 'Search box', 'embed-drive' ) },
		{ value: 'embed', label: __( 'Embed one file', 'embed-drive' ) },
		{ value: 'button', label: __( 'Download button', 'embed-drive' ) },
		{ value: 'link', label: __( 'Download link', 'embed-drive' ) },
	];

	const wantsFolder = ( mode ) => mode === 'files' || mode === 'search';

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
					folderMode ? __( 'Choose a folder', 'embed-drive' ) : __( 'Choose a file', 'embed-drive' )
			  )
			: el( 'p', null, __( 'Connect a Google account under Embed Drive, then come back.', 'embed-drive' ) );

		const inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Module', 'embed-drive' ) },
				el( SelectControl, {
					label: __( 'Type', 'embed-drive' ),
					value: a.mode,
					options: MODES,
					onChange: ( mode ) =>
						set( wantsFolder( mode ) === folderMode ? { mode } : { mode, folder: '', file: '', targetName: '' } ),
				} ),
				accounts.length > 1 &&
					el( SelectControl, {
						label: __( 'Google account', 'embed-drive' ),
						value: account,
						options: accounts.map( ( x ) => ( { value: x.id, label: x.email } ) ),
						onChange: ( value ) => set( { account: value, folder: '', file: '', targetName: '' } ),
					} ),
				chooser
			),
			el(
				PanelBody,
				{ title: __( 'Options', 'embed-drive' ) },
				a.mode === 'files' &&
					el( SelectControl, {
						label: __( 'Layout', 'embed-drive' ),
						value: a.view,
						options: [
							{ value: 'grid', label: __( 'Grid', 'embed-drive' ) },
							{ value: 'list', label: __( 'List', 'embed-drive' ) },
						],
						onChange: ( view ) => set( { view } ),
					} ),
				a.mode === 'files' &&
					el( SelectControl, {
						label: __( 'Sort by', 'embed-drive' ),
						value: a.sort,
						options: [
							{ value: 'name', label: __( 'Name', 'embed-drive' ) },
							{ value: 'modified', label: __( 'Modified', 'embed-drive' ) },
							{ value: 'size', label: __( 'Size', 'embed-drive' ) },
						],
						onChange: ( sort ) => set( { sort } ),
					} ),
				( folderMode || a.mode === 'embed' ) &&
					el( ToggleControl, {
						label: __( 'Allow downloads', 'embed-drive' ),
						checked: a.download === undefined ? a.mode !== 'embed' : a.download,
						onChange: ( download ) => set( { download } ),
					} ),
				a.mode === 'files' &&
					el( ToggleControl, {
						label: __( 'Allow ZIP downloads', 'embed-drive' ),
						checked: a.zip,
						onChange: ( zip ) => set( { zip } ),
					} ),
				a.mode === 'files' &&
					el( TextControl, {
						label: __( 'Minimum height (px)', 'embed-drive' ),
						help: __( 'Use 0 to follow the plugin settings.', 'embed-drive' ),
						type: 'number',
						value: a.min_height,
						onChange: ( value ) => set( { min_height: Math.max( 0, parseInt( value, 10 ) || 0 ) } ),
					} ),
				a.mode === 'files' &&
					el( ToggleControl, {
						label: __( 'Show search box', 'embed-drive' ),
						checked: a.search,
						onChange: ( search ) => set( { search } ),
					} ),
				folderMode &&
					el( ToggleControl, {
						label: __( 'Allow previews', 'embed-drive' ),
						checked: a.preview,
						onChange: ( preview ) => set( { preview } ),
					} ),
				( a.mode === 'button' || a.mode === 'link' ) &&
					el( TextControl, {
						label: __( 'Label', 'embed-drive' ),
						value: a.label,
						onChange: ( label ) => set( { label } ),
					} ),
				a.mode === 'embed' &&
					el( TextControl, {
						label: __( 'Height for documents (px)', 'embed-drive' ),
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
						label: 'Embed Drive' + ( modeLabel ? ': ' + modeLabel : '' ),
						instructions: target
							? a.targetName || target
							: __( 'Choose what to show from Google Drive.', 'embed-drive' ),
					},
					chooser
				)
			)
		);
	}

	registerBlockType( 'embed-drive/module', {
		edit,
		save: () => null,
	} );
} )( window.wp );
