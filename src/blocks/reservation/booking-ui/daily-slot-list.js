import { __ } from '@wordpress/i18n';

const formatDisplayTime = (isoString) => {
	if (!isoString) {
		return '';
	}
	return isoString.slice(11, 16);
};

export const DailySlotList = ({
	slots,
	selectedDate,
	onSelectSlot,
	selectedSlotId,
	isLoading,
	error,
	selectedStaffLabel = '',
}) => {
	if ( error ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--alert">
				<p className="vkbm-alert vkbm-alert__danger vkbm-alert--compact" role="alert">
					{error}
				</p>
			</div>
		);
	}

	if ( ! selectedDate ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--alert">
				<p className="vkbm-alert vkbm-alert__info vkbm-alert--compact" role="status">
					{__(
						'When you select a date from the calendar, reservation candidates will be displayed.',
						'vk-booking-manager'
					)}
				</p>
			</div>
		);
	}

	if ( isLoading ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--placeholder">
				{__('Loading empty slots...', 'vk-booking-manager')}
			</div>
		);
	}

	if ( ! slots?.length ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--placeholder">
				{__(
					'There are no available reservation times for the selected criteria.',
					'vk-booking-manager'
				)}
			</div>
		);
	}

	return (
		<div className="vkbm-slot-list">
			{slots.map((slot) => (
				<button
					type="button"
					key={slot.slot_id}
					className={[
						'vkbm-slot-list__item',
						selectedSlotId === slot.slot_id && 'is-selected',
					]
						.filter(Boolean)
						.join(' ')}
					onClick={() => onSelectSlot(slot)}
				>
					<div className="vkbm-slot-list__time">
						{formatDisplayTime(slot.start_at)} -{' '}
						{formatDisplayTime(slot.service_end_at || slot.end_at)}
					</div>
					<div className="vkbm-slot-list__staff">
						{slot.staff_label ||
							slot.staff?.name ||
							selectedStaffLabel ||
							__('No preference', 'vk-booking-manager')}
					</div>
				</button>
			))}
		</div>
	);
};
