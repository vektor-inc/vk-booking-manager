import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	InnerBlocks,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, Notice, Button } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import metadata from './block.json';
import {
	flattenBlocks,
	sanitizeIdentifier,
	usePostSavingLock,
} from '../shared/utils';
import { getInnerBlocksTemplate } from '../shared/inner-blocks-template';

// 新しく置いたときに最初に並べる子ブロック（渡し先は ../shared/inner-blocks-template.js の判定で InnerBlocks か index.js のブロックの設定に切り替わる）
export const FIELD_TEMPLATE = [
	[ 'vk-booking-manager/menu-search-field-staff' ],
	[ 'vk-booking-manager/menu-search-field-category' ],
	[ 'vk-booking-manager/menu-search-field-keyword' ],
];
const ALLOWED_BLOCKS = [
	'vk-booking-manager/menu-search-field-staff',
	'vk-booking-manager/menu-search-field-category',
	'vk-booking-manager/menu-search-field-keyword',
	'core/columns',
	'core/column',
	'core/group',
	'core/row',
	'core/spacer',
	'core/paragraph',
	'core/heading',
];

const EditComponent = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps( { className: 'vkbm-menu-search' } );

	// ページ内のブロック一覧を読む。
	// useSelect の中で絞り込むと毎回新しい配列になり、エディタで何か変わるたびに再描画されて
	// コンソールに警告が出るため、ここでは getBlocks() の結果だけを返し、絞り込みは下の useMemo で行う。
	const blocks = useSelect(
		( select ) => select( 'core/block-editor' ).getBlocks(),
		[]
	);

	// 入れ子のブロックを平らに並べ、メニューループと検索ブロックを取り出す。
	const { loopBlocks, searchBlocks } = useMemo( () => {
		const allBlocks = flattenBlocks( blocks );
		return {
			loopBlocks: allBlocks.filter(
				( block ) => block?.name === 'vk-booking-manager/menu-loop'
			),
			searchBlocks: allBlocks.filter(
				( block ) => block?.name === metadata.name
			),
		};
	}, [ blocks ] );

	const loopIds = useMemo(
		() =>
			loopBlocks
				.map( ( block ) => block?.attributes?.loopId )
				.filter( Boolean ),
		[ loopBlocks ]
	);

	const hasInvalidTarget =
		! attributes.targetId ||
		( loopIds.length > 0 && ! loopIds.includes( attributes.targetId ) );

	const multipleSearchBlocks = searchBlocks.length > 1;

	usePostSavingLock(
		multipleSearchBlocks,
		'vkbm-menu-search-limit',
		__(
			'Only one scheduled search block can be placed on this page.',
			'vk-booking-manager'
		)
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Cooperation settings', 'vk-booking-manager' ) }
				>
					<TextControl
						label={ __( 'target ID', 'vk-booking-manager' ) }
						value={ attributes.targetId }
						onChange={ ( value ) =>
							setAttributes( {
								targetId: sanitizeIdentifier( value ),
							} )
						}
					/>
					{ hasInvalidTarget && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'There are no menu loop blocks to display.',
								'vk-booking-manager'
							) }
							{ loopIds.length > 0 && (
								<div style={ { marginTop: '0.75rem' } }>
									<Button
										variant="secondary"
										onClick={ () => {
											if ( loopIds.length ) {
												setAttributes( {
													targetId: loopIds[ 0 ],
												} );
											}
										} }
									>
										{ __(
											'Automatically obtain menu loop ID',
											'vk-booking-manager'
										) }
									</Button>
								</div>
							) }
						</Notice>
					) }
					<TextControl
						label={ __(
							'send button label',
							'vk-booking-manager'
						) }
						value={ attributes.submitLabel }
						onChange={ ( value ) =>
							setAttributes( { submitLabel: value } )
						}
					/>
				</PanelBody>
				{ multipleSearchBlocks && (
					<PanelBody
						title={ __( 'caveat', 'vk-booking-manager' ) }
						initialOpen={ true }
					>
						<Notice status="error" isDismissible={ false }>
							{ __(
								'Only one scheduled search block can be used per page. Delete extra blocks.',
								'vk-booking-manager'
							) }
						</Notice>
					</PanelBody>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				<div className="vkbm-menu-search__fields">
					<InnerBlocks
						allowedBlocks={ ALLOWED_BLOCKS }
						template={ getInnerBlocksTemplate( FIELD_TEMPLATE ) }
						templateLock={ false }
						renderAppender={ InnerBlocks.ButtonBlockAppender }
					/>
				</div>
				<div className="vkbm-menu-search__actions vkbm-buttons vkbm-buttons__center">
					<button
						type="button"
						className="vkbm-button vkbm-button__primary"
					>
						{ attributes.submitLabel ||
							metadata.attributes.submitLabel.default }
					</button>
				</div>
			</div>
		</>
	);
};

export default EditComponent;
