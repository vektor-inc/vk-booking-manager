import { useBlockProps } from '@wordpress/block-editor';

const save = ({ attributes }) => {
	const {
		defaultMenuId = 0,
		defaultResourceId = 0,
		allowMenuSelection = true,
		allowStaffSelection = true,
	} = attributes;

	const blockProps = useBlockProps.save({
		className: 'vkbm-reservation-block',
		'data-default-menu-id': defaultMenuId || '',
		'data-default-resource-id': defaultResourceId || '',
		'data-allow-menu-selection': allowMenuSelection ? '1' : '0',
		'data-allow-staff-selection': allowStaffSelection ? '1' : '0',
	});

	return <div {...blockProps} />;
};

export default save;
