import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import { vkBookingManagerCategoryIcon } from '../../block-category';
import './style.scss';
import './editor.scss';

vkBookingManagerCategoryIcon();

registerBlockType( metadata, {
	edit,
	save() {
		return null;
	},
} );
