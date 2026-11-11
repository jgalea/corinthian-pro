import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { ToggleControl } from '@wordpress/components';
import Formatting from './Formatting';
import './editor-pro.css';

function ExportToggle( { draft, patchSettings } ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ __( 'Allow export', 'corinthian-pro' ) }
			checked={ draft.settings.export !== false }
			onChange={ ( v ) => patchSettings( { export: v } ) }
		/>
	);
}

function FormattingPanel( { draft, patchSettings, screen } ) {
	const typeMap = useMemo(
		() => Object.fromEntries( screen.types.map( ( t ) => [ t.key, t ] ) ),
		[ screen ]
	);
	return (
		<Formatting
			rules={
				Array.isArray( draft.settings.formatting )
					? draft.settings.formatting
					: []
			}
			columns={ draft.columns }
			typeMap={ typeMap }
			onChange={ ( formatting ) => patchSettings( { formatting } ) }
		/>
	);
}

addFilter(
	'corinthian.editor.setPanels',
	'corinthian/export',
	( panels, ctx ) => [
		...panels,
		<ExportToggle key="export" slot="toggles" { ...ctx } />,
	]
);
addFilter(
	'corinthian.editor.setPanels',
	'corinthian/formatting',
	( panels, ctx ) => [
		...panels,
		<FormattingPanel key="formatting" { ...ctx } />,
	]
);

addFilter(
	'corinthian.editor.columnToggles',
	'corinthian/filterable',
	( toggles, typeInfo ) =>
		typeInfo?.filterable
			? [
					...toggles,
					{
						name: 'filterable',
						label: __( 'Filterable', 'corinthian-pro' ),
						def: true,
					},
			  ]
			: toggles
);
addFilter(
	'corinthian.editor.columnToggles',
	'corinthian/editable',
	( toggles, typeInfo ) =>
		typeInfo?.editable
			? [
					...toggles,
					{
						name: 'editable',
						label: __( 'Inline editable', 'corinthian-pro' ),
						def: true,
					},
			  ]
			: toggles
);
addFilter(
	'corinthian.editor.columnToggles',
	'corinthian/allow-create',
	( toggles, typeInfo ) =>
		typeInfo?.key === 'post.taxonomy'
			? [
					...toggles,
					{
						name: 'allow_create',
						label: __( 'Allow creating terms', 'corinthian-pro' ),
						def: false,
					},
			  ]
			: toggles
);
