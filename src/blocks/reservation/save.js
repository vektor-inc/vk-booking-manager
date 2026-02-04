import { useBlockProps } from '@wordpress/block-editor';

const save = () => {
	const blockProps = useBlockProps.save({
		className: 'vkbm-reservation-block',
	});

	return <div {...blockProps} />;
};

export default save;
