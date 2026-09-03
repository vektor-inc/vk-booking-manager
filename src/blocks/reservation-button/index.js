import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import edit from './edit';
import { vkBookingManagerCategoryIcon } from '../../block-category';
import './style.scss';
import './editor.scss';

vkBookingManagerCategoryIcon();

// ダミーの翻訳文字列を追加して、wp i18n make-json が index.js の JSON ファイルを生成するようにする。
// 実際の翻訳は edit.js に含まれているため、この文字列は使用されない。
__( 'Proceed to Reservation Button Block', 'vk-booking-manager' );

registerBlockType( metadata, {
	edit,
	save() {
		return null;
	},
} );
