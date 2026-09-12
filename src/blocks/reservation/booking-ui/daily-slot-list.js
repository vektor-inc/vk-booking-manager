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
	// #392: 指名を使うメニューか。true のときは残数（「残 X / 満 Y」の数値バッジ）・催行状態
	// （「開催決定」「あとN名で開催」）を表示しない。指名を使うメニューは1枠1組（貸切）扱いのため、
	// これらの「複数の別々の予約が相乗りする」ことを前提にした表示が成立しないため。
	// 満枠かどうかの二値表示（「Fully booked」ラベル・disabled 状態）は、指名を使うメニューでも
	// 従来どおり表示する（#392: 理由なく押せないボタンにしないため）。
	isNominationMenu = false,
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
				// #392: 指名を使うメニュー（1枠1組＝貸切）では、capacity（定員＝1組の最大人数）が
				// 2以上でも「残 X / 満 Y」の数値バッジは表示しない（相乗りの概念が無いため）。
				// Show remaining count only when capacity is greater than 1 and the menu is not a
				// nomination (private-per-group) menu.
				const showRemaining =
					! isNominationMenu &&
					slot.capacity > 1 &&
					slot.remaining > 0 &&
					! isExclusiveClosed;
				// 満枠かどうかの判定は指名メニューでも従来どおり行い、ボタンを disabled にするだけでなく
				// 「Fully booked」ラベルも表示する（#392）。
				// issue が求めているのは「残数（残X/満Yの数値）」と「催行状態（あとN人で開催確定）」を
				// 出さないことであり、満席かどうかの二値表示まで消す指示ではない。ここを隠すと、
				// 指名を使うメニューで「指名なし」を選び候補スタッフが全員埋まっている時間帯では、
				// 時間とスタッフ名だけの空欄ボタンが理由も分からず灰色で押せない状態になってしまう
				// （バックエンドの collapse_slots_for_auto_assignment() も「満枠スロットもフロントエンド側で
				// 『満枠』表示するため除外しない」という前提でそのスロットを残している）。
				const isFull =
					! isExclusiveClosed &&
					slot.capacity > 1 &&
					slot.remaining <= 0;
				// 貸し切り受付終了・満席のいずれも選択不可（グレーアウトの無効状態で残す）。
				const isDisabled = isFull || isExclusiveClosed;

				// 最小催行人数（グループ開催型）。0以下は制約なしのため催行状態は表示しない。
				// #392: 指名を使うメニューはバックエンド（Availability_Service::get_menu_min_capacity()）が
				// 常に min_capacity=0 を返すため通常はここで自動的に非表示になるが、念のため
				// isNominationMenu でも明示的に除外する（多層防御）。
				const minCapacity = isNominationMenu
					? 0
					: Number( slot.min_capacity ) || 0;
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
