import { __ } from '@wordpress/i18n';
import { formatCurrency } from '../../shared/pricing';

export const BookingSummaryItems = ({
	booking,
	resourceLabel,
	emptyValue = '',
	currencySymbol = null,
}) => {
	const resolvedLabel =
		typeof resourceLabel === 'string' && resourceLabel.trim() !== ''
			? resourceLabel
			: __('Staff', 'vk-booking-manager');
	const menuName =
		typeof booking?.menu_name === 'string' && booking.menu_name.trim() !== ''
			? booking.menu_name
			: emptyValue;
	const staffName =
		typeof booking?.resource_name === 'string' &&
		booking.resource_name.trim() !== ''
			? booking.resource_name
			: emptyValue;

	return (
		<>
			<dl className="vkbm-confirm__summary-item">
				<dt className="vkbm-confirm__summary-item-title">
					{__('Menu', 'vk-booking-manager')}
				</dt>
				<dd className="vkbm-confirm__summary-item-value">{menuName}</dd>
			</dl>
			{booking?.is_staff_preferred && (
				<dl className="vkbm-confirm__summary-item">
					<dt className="vkbm-confirm__summary-item-title">{resolvedLabel}</dt>
					<dd className="vkbm-confirm__summary-item-value">{staffName}</dd>
				</dl>
			)}
			<dl className="vkbm-confirm__summary-item">
				<dt className="vkbm-confirm__summary-item-title">
					{__('Total basic fee', 'vk-booking-manager')}
				</dt>
				<dd className="vkbm-confirm__summary-item-value vkbm-confirm__summary-item-value--price">
					{formatCurrency(booking?.total_price || 0, currencySymbol)}
				</dd>
			</dl>
			{typeof booking?.other_conditions === 'string' &&
				booking.other_conditions.trim() !== '' && (
					<dl className="vkbm-confirm__summary-item vkbm-confirm__summary-item--other-conditions">
						<dt className="vkbm-confirm__summary-item-title">
							{__('Other conditions', 'vk-booking-manager')}
						</dt>
						<dd className="vkbm-confirm__summary-value--multiline">
							{booking.other_conditions}
						</dd>
					</dl>
				)}
		</>
	);
};
