import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';
import metadata from './block.json';

const EditComponent = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	// サービスメニュー（vkbm_service_menu）の一覧を取得し、手動指定用の選択肢を作る。
	const serviceMenus = useSelect( ( select ) => {
		const records = select( 'core' ).getEntityRecords(
			'postType',
			'vkbm_service_menu',
			{
				per_page: -1,
				orderby: 'menu_order',
				order: 'asc',
				status: 'publish,private',
			}
		);
		return Array.isArray( records ) ? records : [];
	}, [] );

	const selectedMenuId = Number( attributes.selectedMenuId ) || 0;
	const isAutoMode = selectedMenuId === 0;

	// 手動指定用の選択肢。先頭は自動取得（表示中のサービス）。
	const menuOptions = [
		{
			label: __(
				'Auto (the service being displayed)',
				'vk-booking-manager'
			),
			value: 0,
		},
		...serviceMenus.map( ( menu ) => ( {
			label:
				menu?.title?.rendered ||
				__( '(no title)', 'vk-booking-manager' ),
			value: menu.id,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Service to display', 'vk-booking-manager' ) }
				>
					<SelectControl
						label={ __( 'Target service', 'vk-booking-manager' ) }
						help={ __(
							'Auto: shows the service being viewed. Selected: shows the chosen service.',
							'vk-booking-manager'
						) }
						value={ selectedMenuId }
						options={ menuOptions }
						onChange={ ( value ) =>
							setAttributes( {
								selectedMenuId: Number( value ) || 0,
							} )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display items', 'vk-booking-manager' ) }
				>
					<ToggleControl
						label={ __(
							'Display featured image',
							'vk-booking-manager'
						) }
						checked={ attributes.showImage }
						onChange={ ( value ) =>
							setAttributes( { showImage: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show service tags',
							'vk-booking-manager'
						) }
						checked={ attributes.showCategories }
						onChange={ ( value ) =>
							setAttributes( { showCategories: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show excerpt', 'vk-booking-manager' ) }
						checked={ attributes.showExcerpt }
						onChange={ ( value ) =>
							setAttributes( { showExcerpt: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show required time/price',
							'vk-booking-manager'
						) }
						checked={ attributes.showMeta }
						onChange={ ( value ) =>
							setAttributes( { showMeta: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show reservation button',
							'vk-booking-manager'
						) }
						checked={ attributes.showReserveButton }
						onChange={ ( value ) =>
							setAttributes( { showReserveButton: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ isAutoMode && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'On the front end, the service being displayed will be shown.',
							'vk-booking-manager'
						) }
					</Notice>
				) }
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default EditComponent;
