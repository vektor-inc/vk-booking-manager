import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	useBlockProps,
	InspectorControls,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import metadata from './block.json';

const Edit = ({ attributes, setAttributes }) => {
	const blockProps = useBlockProps({ className: 'vkbm-menu-search__field' });
	const label = attributes.label || metadata.attributes.label.default;

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('field settings', 'vk-booking-manager')}>
					<TextControl
						label={__('label', 'vk-booking-manager')}
						value={attributes.label}
						onChange={(value) => setAttributes({ label: value })}
					/>
					<TextControl
						label={__('placeholder', 'vk-booking-manager')}
						value={attributes.placeholder}
						onChange={(value) => setAttributes({ placeholder: value })}
					/>
				</PanelBody>
			</InspectorControls>
			<div {...blockProps}>
				<span className="vkbm-menu-search__field-label">{label}</span>
				<div className="vkbm-menu-search__field-preview">
					{__('Keyword input field', 'vk-booking-manager')}
				</div>
			</div>
		</>
	);
};

registerBlockType(metadata, {
	edit: Edit,
	save() {
		return null;
	},
});
