import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { ReservationApp } from './app';

const providerSettingsUrl =
	typeof window !== 'undefined' && window.vkbmReservationBlock?.providerSettingsUrl
		? window.vkbmReservationBlock.providerSettingsUrl
		: '';

const SettingsMessage = () => (
	<p className="vkbm-reservation-block-settings-message">
		{/* translators: Part before the link. Full message: "Configure reservation block settings from the «BM basic settings» screen." */}
		{__(
			'Configure reservation block settings from the "',
			'vk-booking-manager'
		)}
		{providerSettingsUrl ? (
			<a
				href={providerSettingsUrl}
				target="_blank"
				rel="noopener noreferrer"
			>
				{/* translators: Link text; opens BM basic settings in a new window. */}
				{__('BM basic settings', 'vk-booking-manager')}
			</a>
		) : (
			__('BM basic settings', 'vk-booking-manager')
		)}
		{/* translators: Part after the link. Full message: "Configure reservation block settings from the «BM basic settings» screen." */}
		{__('" screen.', 'vk-booking-manager')}
	</p>
);

const Edit = () => {
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={__('Block settings', 'vk-booking-manager')}
					initialOpen={true}
				>
					<SettingsMessage />
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
				<ReservationApp
					defaultMenuId={0}
					defaultStaffId={0}
					allowStaffSelection={true}
					isEditor
				/>
			</div>
		</>
	);
};

export default Edit;
