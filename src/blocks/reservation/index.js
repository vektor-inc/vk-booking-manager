import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import edit from './edit';
import save from './save';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

const deprecated = [
	{
		attributes: {
			defaultMenuId: {
				type: 'string',
				source: 'attribute',
				selector: 'div',
				attribute: 'data-default-menu-id',
				default: '',
			},
			defaultResourceId: {
				type: 'string',
				source: 'attribute',
				selector: 'div',
				attribute: 'data-default-resource-id',
				default: '',
			},
			allowMenuSelection: {
				type: 'string',
				source: 'attribute',
				selector: 'div',
				attribute: 'data-allow-menu-selection',
				default: '1',
			},
			allowStaffSelection: {
				type: 'string',
				source: 'attribute',
				selector: 'div',
				attribute: 'data-allow-staff-selection',
				default: '1',
			},
		},
		save: ({ attributes }) => {
			const blockProps = useBlockProps.save({
				className: 'vkbm-reservation-block',
			});
			const defaultMenuId =
				typeof attributes?.defaultMenuId === 'string'
					? attributes.defaultMenuId
					: '';
			const defaultResourceId =
				typeof attributes?.defaultResourceId === 'string'
					? attributes.defaultResourceId
					: '';
			const allowMenuSelection =
				String(attributes?.allowMenuSelection ?? '1') === '0' ? '0' : '1';
			const allowStaffSelection =
				String(attributes?.allowStaffSelection ?? '1') === '0' ? '0' : '1';

			return (
				<div
					{...blockProps}
					data-default-menu-id={defaultMenuId}
					data-default-resource-id={defaultResourceId}
					data-allow-menu-selection={allowMenuSelection}
					data-allow-staff-selection={allowStaffSelection}
				/>
			);
		},
	},
];

registerBlockType(metadata, {
	edit,
	save,
	deprecated,
});
