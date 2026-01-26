import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
} from '@wordpress/block-editor';
	import {
		PanelBody,
		RangeControl,
		SelectControl,
		TextControl,
		ToggleControl,
		CheckboxControl,
		Notice,
	} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useEffect, useMemo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
	import metadata from './block.json';
	import {
		flattenBlocks,
		sanitizeIdentifier,
	} from '../shared/utils';

const ORDER_BY_OPTIONS = [
	{ label: __('Menu order', 'vk-booking-manager'), value: 'menu_order' },
	{ label: __('title', 'vk-booking-manager'), value: 'title' },
	{ label: __('Release date', 'vk-booking-manager'), value: 'date' },
	{ label: __('Update date', 'vk-booking-manager'), value: 'modified' },
	{ label: __('random', 'vk-booking-manager'), value: 'rand' },
];

	const ORDER_OPTIONS = [
		{ label: __('ascending order', 'vk-booking-manager'), value: 'ASC' },
		{ label: __('descending order', 'vk-booking-manager'), value: 'DESC' },
	];

	const DISPLAY_MODE_OPTIONS = [
		{ label: __('card', 'vk-booking-manager'), value: 'card' },
		{ label: __('text', 'vk-booking-manager'), value: 'text' },
	];

	const GROUP_FILTER_MODE_OPTIONS = [
		{ label: __('All service groups', 'vk-booking-manager'), value: 'all' },
		{ label: __('Specified service group', 'vk-booking-manager'), value: 'selected' },
	];

	const generateLoopId = (takenIds) => {
		let suffix = 1;
		while (takenIds.has(`loop-${suffix}`)) {
			suffix += 1;
		}
	return `loop-${suffix}`;
};

	const EditComponent = ({ attributes, setAttributes, clientId }) => {
		const blockProps = useBlockProps();
		const { loopBlocks, serviceGroups } = useSelect(
			(select) => {
				const groups = select('core').getEntityRecords(
					'taxonomy',
					'vkbm_service_menu_group',
					{ per_page: -1, orderby: 'name', order: 'asc' }
				);
				const allBlocks = flattenBlocks(select('core/block-editor').getBlocks());
				return {
					loopBlocks: allBlocks.filter(
						(block) => block?.name === metadata.name
					),
					serviceGroups: Array.isArray(groups) ? groups : [],
				};
			},
			[]
		);

	const otherLoopIds = useMemo(() => {
		const ids = new Set();
		loopBlocks
			.filter((block) => block.clientId !== clientId)
			.forEach((block) => {
				if (block?.attributes?.loopId) {
					ids.add(block.attributes.loopId);
				}
			});
		return ids;
	}, [loopBlocks, clientId]);

	useEffect(() => {
		if (attributes.loopId) {
			return;
		}
		setAttributes({ loopId: generateLoopId(otherLoopIds) });
	}, [attributes.loopId, otherLoopIds, setAttributes]);

	const duplicateId =
		attributes.loopId &&
		loopBlocks.some(
			(block) =>
				block.clientId !== clientId &&
				block?.attributes?.loopId === attributes.loopId
		);

	const setNumericAttribute = (key, value, fallback) => {
		if (typeof value === 'number') {
			setAttributes({ [key]: value });
		} else if (fallback !== undefined) {
				setAttributes({ [key]: fallback });
			}
		};

		const selectedGroupIds = Array.isArray(attributes.selectedGroupIds)
			? attributes.selectedGroupIds
			: [];

		const toggleGroupSelection = (termId, checked) => {
			const id = Number(termId);
			if (!id) {
				return;
			}
			const next = checked
				? Array.from(new Set([...selectedGroupIds, id]))
				: selectedGroupIds.filter((existing) => existing !== id);
			setAttributes({ selectedGroupIds: next });
		};

		return (
			<>
				<InspectorControls>
					<PanelBody title={__('Menu display settings', 'vk-booking-manager')}>
						<TextControl
							label={__('Menu loop ID', 'vk-booking-manager')}
							help={__('This is an identifier for linking with the search block.', 'vk-booking-manager')}
							value={attributes.loopId || ''}
							onChange={(value) =>
								setAttributes({ loopId: sanitizeIdentifier(value) })
							}
						/>
						{duplicateId && (
							<Notice status="error" isDismissible={false}>
								{__(
									'A menu loop with the same ID exists. Please change to another ID.',
									'vk-booking-manager'
								)}
							</Notice>
						)}
						<SelectControl
							label={__('display mode', 'vk-booking-manager')}
							value={attributes.displayMode || 'card'}
							options={DISPLAY_MODE_OPTIONS}
							onChange={(value) => setAttributes({ displayMode: value })}
						/>
						<ToggleControl
							label={__('Do not display the proceed to reservation button', 'vk-booking-manager')}
							checked={!attributes.showReserveButton}
							onChange={(value) =>
								setAttributes({ showReserveButton: !value })
							}
						/>
						<SelectControl
							label={__('Sort', 'vk-booking-manager')}
							value={attributes.orderBy}
							options={ORDER_BY_OPTIONS}
						onChange={(value) => setAttributes({ orderBy: value })}
					/>
					<SelectControl
						label={__('sort order', 'vk-booking-manager')}
						value={attributes.order}
						options={ORDER_OPTIONS}
							onChange={(value) => setAttributes({ order: value })}
						/>
						<SelectControl
							label={__('service group', 'vk-booking-manager')}
							value={attributes.groupFilterMode || 'all'}
							options={GROUP_FILTER_MODE_OPTIONS}
							onChange={(value) => setAttributes({ groupFilterMode: value })}
						/>
						{(attributes.groupFilterMode || 'all') === 'selected' && (
							<div className="vkbm-menu-loop__group-filter">
								{serviceGroups.length === 0 ? (
									<Notice status="warning" isDismissible={false}>
										{__(
											'Service group has not been registered yet.',
											'vk-booking-manager'
										)}
									</Notice>
								) : (
									serviceGroups.map((group) => (
										<CheckboxControl
											key={group.id}
											label={group.name}
											checked={selectedGroupIds.includes(group.id)}
											onChange={(checked) =>
												toggleGroupSelection(group.id, checked)
											}
										/>
									))
								)}
							</div>
						)}
						<ToggleControl
							label={__('Do not display service group name', 'vk-booking-manager')}
							checked={Boolean(attributes.hideGroupTitle)}
							onChange={(value) => setAttributes({ hideGroupTitle: value })}
						/>
					</PanelBody>
					<PanelBody
						title={__('Display element', 'vk-booking-manager')}
						initialOpen={false}
					>
					<ToggleControl
						label={__('Display featured image', 'vk-booking-manager')}
						checked={attributes.showImage}
						onChange={(value) => setAttributes({ showImage: value })}
					/>
					<ToggleControl
						label={__('View Service Tag', 'vk-booking-manager')}
						checked={attributes.showCategories}
						onChange={(value) => setAttributes({ showCategories: value })}
					/>
					<ToggleControl
						label={__('Show excerpt', 'vk-booking-manager')}
						checked={attributes.showExcerpt}
						onChange={(value) => setAttributes({ showExcerpt: value })}
					/>
					<ToggleControl
						label={__('Show required time/price', 'vk-booking-manager')}
						checked={attributes.showMeta}
						onChange={(value) => setAttributes({ showMeta: value })}
					/>
					<ToggleControl
						label={__('Show details button', 'vk-booking-manager')}
						checked={attributes.showDetailButton}
						onChange={(value) => setAttributes({ showDetailButton: value })}
					/>
					<TextControl
						label={__('Zero messages', 'vk-booking-manager')}
						help={__('If the field is left blank, automatic text will be displayed.', 'vk-booking-manager')}
						value={attributes.emptyMessage}
						onChange={(value) => setAttributes({ emptyMessage: value })}
					/>
				</PanelBody>
				</InspectorControls>
				<div {...blockProps}>
					<ServerSideRender block={metadata.name} attributes={attributes} />
				</div>
		</>
	);
};

export default EditComponent;
