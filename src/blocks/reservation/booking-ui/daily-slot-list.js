import { __, sprintf } from '@wordpress/i18n';

const formatDisplayTime = ( isoString ) => {
	if ( ! isoString ) {
		return '';
	}
	return isoString.slice( 11, 16 );
};

export const DailySlotList = ( {
	slots,
	selectedDate,
	onSelectSlot,
	selectedSlotId,
	isLoading,
	error,
	selectedStaffLabel = '',
	showStaffLabel = true,
	noNominationLabel = '',
} ) => {
	if ( error ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--alert">
				<p
					className="vkbm-alert vkbm-alert__danger vkbm-alert--compact"
					role="alert"
				>
					{ error }
				</p>
			</div>
		);
	}

	if ( ! selectedDate ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--alert">
				<p
					className="vkbm-alert vkbm-alert__info vkbm-alert--compact"
					role="status"
				>
					{ __(
						'When you select a date from the calendar, reservation candidates will be displayed.',
						'vk-booking-manager'
					) }
				</p>
			</div>
		);
	}

	if ( isLoading ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--placeholder">
				{ __( 'Loading empty slots…', 'vk-booking-manager' ) }
			</div>
		);
	}

	if ( ! slots?.length ) {
		return (
			<div className="vkbm-slot-list vkbm-slot-list--placeholder">
				{ __(
					'There are no available reservation times for the selected criteria.',
					'vk-booking-manager'
				) }
			</div>
		);
	}

	return (
		<div className="vkbm-slot-list">
			{ slots.map( ( slot ) => {
				// capacity > 1 の場合のみ残り枠数を表示する。
				// Show remaining count only when capacity is greater than 1.
				const showRemaining =
					slot.capacity > 1 && slot.remaining > 0;
				const isFull =
					slot.capacity > 1 && slot.remaining <= 0;

				return (
					<button
						type="button"
						key={ slot.slot_id }
						disabled={ isFull }
						className={ [
							'vkbm-slot-list__item',
							selectedSlotId === slot.slot_id &&
								'is-selected',
							isFull && 'is-full',
						]
							.filter( Boolean )
							.join( ' ' ) }
						onClick={ () =>
							! isFull && onSelectSlot( slot )
						}
					>
						<div className="vkbm-slot-list__time">
							{ formatDisplayTime( slot.start_at ) } -{ ' ' }
							{ formatDisplayTime(
								slot.service_end_at || slot.end_at
							) }
						</div>
						{ showStaffLabel && (
							<div className="vkbm-slot-list__staff">
								{ slot.staff_label ||
									slot.staff?.name ||
									selectedStaffLabel ||
									noNominationLabel ||
									__(
										'No preference',
										'vk-booking-manager'
									) }
							</div>
						) }
						{ showRemaining && (
							<div className="vkbm-slot-list__remaining">
								{ sprintf(
									/* translators: %d: number of remaining slots */
									__(
										'%d slots left',
										'vk-booking-manager'
									),
									slot.remaining
								) }
							</div>
						) }
						{ isFull && (
							<div className="vkbm-slot-list__remaining vkbm-slot-list__remaining--full">
								{ __(
									'Fully booked',
									'vk-booking-manager'
								) }
							</div>
						) }
					</button>
				);
			} ) }
		</div>
	);
};
