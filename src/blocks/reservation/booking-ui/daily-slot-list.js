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
				// 貸し切り予約が入って受付終了になった枠。満席とは区別して別文言・別状態で表示する。
				// Slot closed because an exclusive (private) booking was placed.
				const isExclusiveClosed = Boolean( slot.exclusive_closed );
				// capacity > 1 の場合のみ残り枠数を表示する。
				// Show remaining count only when capacity is greater than 1.
				const showRemaining =
					slot.capacity > 1 &&
					slot.remaining > 0 &&
					! isExclusiveClosed;
				const isFull =
					! isExclusiveClosed &&
					slot.capacity > 1 &&
					slot.remaining <= 0;
				// 貸し切り受付終了・満席のいずれも選択不可（グレーアウトの無効状態で残す）。
				const isDisabled = isFull || isExclusiveClosed;

				// 最小催行人数（グループ開催型）。0以下は制約なしのため催行状態は表示しない。
				const minCapacity = Number( slot.min_capacity ) || 0;
				const bookedGuests = Math.max(
					0,
					Number( slot.booked_guests ) || 0
				);
				const showFulfillment = minCapacity > 0;
				const isFulfilled =
					showFulfillment && bookedGuests >= minCapacity;
				// 開催までに不足している人数（達成済み・制約なしは表示しない）。
				const shortfall = isFulfilled
					? 0
					: Math.max( 0, minCapacity - bookedGuests );

				return (
					<button
						type="button"
						key={ slot.slot_id }
						disabled={ isDisabled }
						className={ [
							'vkbm-slot-list__item',
							selectedSlotId === slot.slot_id && 'is-selected',
							isFull && 'is-full',
							isExclusiveClosed && 'is-exclusive-closed',
						]
							.filter( Boolean )
							.join( ' ' ) }
						onClick={ () => ! isDisabled && onSelectSlot( slot ) }
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
									/* translators: 1: remaining count, 2: capacity (max per booking). リソースが人とは限らないため単位語は付けない。 */
									__(
										'Remaining %1$d / %2$d',
										'vk-booking-manager'
									),
									slot.remaining,
									slot.capacity
								) }
							</div>
						) }
						{ isFull && (
							<div className="vkbm-slot-list__remaining vkbm-slot-list__remaining--full">
								{ __( 'Fully booked', 'vk-booking-manager' ) }
							</div>
						) }
						{ isExclusiveClosed && (
							<div className="vkbm-slot-list__remaining vkbm-slot-list__remaining--closed">
								{ __(
									'Reservations closed',
									'vk-booking-manager'
								) }
							</div>
						) }
						{ showFulfillment &&
							! isExclusiveClosed &&
							( isFulfilled ? (
								<div className="vkbm-slot-list__fulfillment vkbm-slot-list__fulfillment--confirmed">
									{ __(
										'Session confirmed',
										'vk-booking-manager'
									) }
								</div>
							) : (
								<div className="vkbm-slot-list__fulfillment vkbm-slot-list__fulfillment--pending">
									{ sprintf(
										/* translators: %d: number of additional participants needed to confirm the session. */
										__(
											'%d more to confirm',
											'vk-booking-manager'
										),
										shortfall
									) }
								</div>
							) ) }
					</button>
				);
			} ) }
		</div>
	);
};
