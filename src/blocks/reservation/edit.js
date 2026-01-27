import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { ReservationApp } from './app';

const toNumber = (value) => {
	const parsed = Number(value);
	return Number.isNaN(parsed) ? 0 : parsed;
};

const Edit = ({ attributes, setAttributes }) => {
	const blockProps = useBlockProps();
	const {
		defaultMenuId,
		defaultResourceId,
		allowMenuSelection,
		allowStaffSelection,
	} =
		attributes;

	const [resourceLabelSingular, setResourceLabelSingular] = useState(__('Staff', 'vk-booking-manager'));
	useEffect(() => {
		let isMounted = true;

		apiFetch({ path: '/vkbm/v1/provider-settings' })
			.then((settings) => {
				if (!isMounted) {
					return;
				}
				const label =
					typeof settings?.resource_label_singular === 'string' &&
					settings.resource_label_singular.trim() !== ''
						? settings.resource_label_singular
						: __('Staff', 'vk-booking-manager');
				setResourceLabelSingular(label);
			})
			.catch(() => {
				// ignore (fallback to default label)
			});

		return () => {
			isMounted = false;
		};
	}, []);

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('Initial settings', 'vk-booking-manager')}>
					<TextControl
						label={__('Default menu ID', 'vk-booking-manager')}
						type="number"
						value={defaultMenuId || ''}
						onChange={(value) => setAttributes({ defaultMenuId: toNumber(value) })}
						help={__(
							'This is the menu ID that is set when there is no URL query.',
							'vk-booking-manager'
						)}
					/>
					<TextControl
						label={sprintf(
							/* translators: %s: resource label (singular). */
							__('Default %sID', 'vk-booking-manager'),
							resourceLabelSingular
						)}
						type="number"
						value={defaultResourceId || ''}
						onChange={(value) => setAttributes({ defaultResourceId: toNumber(value) })}
					/>
					<ToggleControl
						label={__('Allow menu selection', 'vk-booking-manager')}
						checked={allowMenuSelection}
						onChange={(value) => setAttributes({ allowMenuSelection: value })}
					/>
					<ToggleControl
						label={sprintf(
							/* translators: %s: resource label (singular). */
							__('%s Allow nomination', 'vk-booking-manager'),
							resourceLabelSingular
						)}
						checked={allowStaffSelection}
						onChange={(value) => setAttributes({ allowStaffSelection: value })}
					/>
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
					<ReservationApp
						defaultMenuId={defaultMenuId}
						defaultStaffId={defaultResourceId}
						allowMenuSelection={allowMenuSelection}
						allowStaffSelection={allowStaffSelection}
						isEditor
					/>
			</div>
		</>
	);
};

export default Edit;
