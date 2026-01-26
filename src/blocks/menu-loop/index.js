import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import edit from './edit';
import './style.scss';
import './editor.scss';

// ダミーの翻訳文字列を追加して、wp i18n make-jsonがindex.jsのJSONファイルを生成するようにする
// 実際の翻訳はedit.jsに含まれているため、この文字列は使用されない
__('Menu Loop Block', 'vk-booking-manager');

registerBlockType(metadata, {
	edit,
	save() {
		return null;
	},
});
