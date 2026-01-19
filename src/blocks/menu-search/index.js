import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType(metadata, {
	edit,
	save() {
		return null;
	},
});
