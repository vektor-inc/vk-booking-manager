import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit, { FIELD_TEMPLATE } from './edit';
import { vkBookingManagerCategoryIcon } from '../../block-category';
import { getBlockTypeTemplateSettings } from '../shared/inner-blocks-template';
import './style.scss';
import './editor.scss';

vkBookingManagerCategoryIcon();

registerBlockType( metadata, {
	edit,
	save() {
		return null;
	},
	...getBlockTypeTemplateSettings( FIELD_TEMPLATE ),
} );
