import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	Notice,
	Button,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

const SERVICE_MENU_POST_TYPE = 'vkbm_service_menu';

// PHP 側（class-reservation-button-block.php）から localize された設定値。
const blockConfig =
	typeof window !== 'undefined' && window.vkbmReservationButtonBlock
		? window.vkbmReservationButtonBlock
		: {};

const providerSettingsUrl = blockConfig.providerSettingsUrl || '';
const hasReservationPageUrl = Boolean( blockConfig.hasReservationPageUrl );
const defaultReserveLabel = blockConfig.defaultReserveLabel || '';

const EditComponent = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();
	const { menuId, label } = attributes;

	// エディタの現在の投稿タイプと、選択肢にするサービスメニュー一覧を取得する。
	const { currentPostType, serviceMenus } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const menus = select( 'core' ).getEntityRecords(
			'postType',
			SERVICE_MENU_POST_TYPE,
			{ per_page: -1, orderby: 'menu_order', order: 'asc' }
		);
		return {
			currentPostType: editor ? editor.getCurrentPostType() : '',
			serviceMenus: Array.isArray( menus ) ? menus : [],
		};
	}, [] );

	// プラン詳細ページ（サービスメニュー投稿の編集画面）かどうか。
	const isOnServiceMenu = currentPostType === SERVICE_MENU_POST_TYPE;

	// 手動指定が無く、かつプラン詳細ページ以外に配置されている場合は
	// 「現在のプラン」を特定できない。
	const cannotResolveCurrentPlan = ! menuId && ! isOnServiceMenu;

	// プラン選択肢（先頭に「現在のプランを自動参照」を置く）。
	const menuOptions = [
		{
			label: __( 'Auto (current plan)', 'vk-booking-manager' ),
			value: 0,
		},
		...serviceMenus.map( ( menu ) => ( {
			label: menu?.title?.rendered || `#${ menu.id }`,
			value: menu.id,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Button settings', 'vk-booking-manager' ) }
					initialOpen={ true }
				>
					{ ! hasReservationPageUrl && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ __(
									'The reservation page has not been set.',
									'vk-booking-manager'
								) }
							</p>
							{ providerSettingsUrl && (
								<Button
									variant="secondary"
									href={ providerSettingsUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __(
										'Open BM basic settings',
										'vk-booking-manager'
									) }
									<span className="screen-reader-text">
										{ ' ' }
										{ __(
											'(opens in a new tab)',
											'vk-booking-manager'
										) }
									</span>
								</Button>
							) }
						</Notice>
					) }

					<SelectControl
						label={ __( 'Target plan', 'vk-booking-manager' ) }
						help={ __(
							'When set to Auto, the current plan detail page is used.',
							'vk-booking-manager'
						) }
						value={ menuId || 0 }
						options={ menuOptions }
						onChange={ ( value ) =>
							setAttributes( { menuId: Number( value ) || 0 } )
						}
					/>

					{ cannotResolveCurrentPlan && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The current plan cannot be determined on this page. Please select a target plan above.',
								'vk-booking-manager'
							) }
						</Notice>
					) }

					<TextControl
						label={ __( 'Button label', 'vk-booking-manager' ) }
						placeholder={
							defaultReserveLabel ||
							__( 'Proceed to Reservation', 'vk-booking-manager' )
						}
						help={ __(
							'If left blank, the default label from BM basic settings is used.',
							'vk-booking-manager'
						) }
						value={ label || '' }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default EditComponent;
