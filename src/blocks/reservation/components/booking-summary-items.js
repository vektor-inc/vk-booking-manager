import { __, _n, sprintf } from '@wordpress/i18n';
import { formatCurrency } from '../../shared/pricing';
import { formatGuestsCount } from '../../shared/guests';

export const BookingSummaryItems = ( {
	booking,
	resourceLabel,
	otherConditionsLabel,
	// 数量の見出し（複数人予約）。空のときは翻訳デフォルトへフォールバックする。
	guestsCountLabel = '',
	// 数量の単位（実効値。空文字は単位なし）。
	guestsUnitLabel = '',
	// メニュー名・時間枠の担当者名が空のときの表記。
	emptyValue = '',
	// 泊の予約で客室名が取れないときの表記（#827）。emptyValue とは分けて持つ。
	stayRoomEmptyValue = '—',
	currencySymbol = null,
} ) => {
	const resolvedLabel =
		typeof resourceLabel === 'string' && resourceLabel.trim() !== ''
			? resourceLabel
			: __( 'Staff', 'vk-booking-manager' );
	const menuName =
		typeof booking?.menu_name === 'string' &&
		booking.menu_name.trim() !== ''
			? booking.menu_name
			: emptyValue;
	// 泊の予約かどうかは、予約に保存された泊数で判定する。
	const isStayBooking = Number( booking?.nights ) > 0;
	// #827: emptyValue を共有すると、呼び出し側が「—」を渡したときにメニュー名など他の空欄の表示まで変わるため、泊の予約の客室名だけ別の表記にする。
	let staffName = isStayBooking ? stayRoomEmptyValue : emptyValue;
	if (
		typeof booking?.resource_name === 'string' &&
		booking.resource_name.trim() !== ''
	) {
		staffName = booking.resource_name;
	}

	return (
		<>
			<dl className="vkbm-confirm__summary-item">
				<dt className="vkbm-confirm__summary-item-title">
					{ __( 'Menu', 'vk-booking-manager' ) }
				</dt>
				<dd className="vkbm-confirm__summary-item-value">
					{ menuName }
				</dd>
			</dl>
			{ /* 料金区分の予約: 区分ごとの人数を表示する（0名の区分は省略）。 */ }
			{ Array.isArray( booking?.guest_tiers ) &&
				booking.guest_tiers.length > 0 &&
				booking.guest_tiers
					.filter( ( tier ) => Number( tier?.count ) > 0 )
					.map( ( tier, index ) => (
						<dl
							key={ index }
							className="vkbm-confirm__summary-item"
						>
							<dt className="vkbm-confirm__summary-item-title">
								{ tier.label }
							</dt>
							<dd className="vkbm-confirm__summary-item-value">
								{ formatGuestsCount(
									Number( tier.count ),
									guestsUnitLabel
								) }
							</dd>
						</dl>
					) ) }
			{ /* 泊の予約: 泊数を表示する（時間枠の予約には nights が無いため出ない）。 */ }
			{ Number( booking?.nights ) > 0 && (
				<dl className="vkbm-confirm__summary-item">
					<dt className="vkbm-confirm__summary-item-title">
						{ __( 'Number of nights', 'vk-booking-manager' ) }
					</dt>
					<dd className="vkbm-confirm__summary-item-value">
						{ sprintf(
							/* translators: %d: number of nights */
							_n(
								'%d night',
								'%d nights',
								Number( booking.nights ),
								'vk-booking-manager'
							),
							Number( booking.nights )
						) }
					</dd>
				</dl>
			) }
			{ Number( booking?.guests ) > 1 && (
				<dl className="vkbm-confirm__summary-item">
					<dt className="vkbm-confirm__summary-item-title">
						{ typeof guestsCountLabel === 'string' &&
						guestsCountLabel.trim() !== ''
							? guestsCountLabel
							: __( 'Number of guests', 'vk-booking-manager' ) }
					</dt>
					<dd className="vkbm-confirm__summary-item-value">
						{ formatGuestsCount(
							Number( booking.guests ),
							guestsUnitLabel
						) }
					</dd>
				</dl>
			) }
			{ /* #827: 泊の予約は指名フラグを保存しないため、泊数があれば客室の行を出す。 */ }
			{ ( booking?.is_staff_preferred || isStayBooking ) && (
				<dl className="vkbm-confirm__summary-item">
					<dt className="vkbm-confirm__summary-item-title">
						{ resolvedLabel }
					</dt>
					<dd className="vkbm-confirm__summary-item-value">
						{ staffName }
					</dd>
				</dl>
			) }
			<dl className="vkbm-confirm__summary-item">
				<dt className="vkbm-confirm__summary-item-title">
					{ __( 'Total basic fee', 'vk-booking-manager' ) }
				</dt>
				<dd className="vkbm-confirm__summary-item-value vkbm-confirm__summary-item-value--price">
					{ formatCurrency(
						booking?.total_price || 0,
						currencySymbol
					) }
				</dd>
			</dl>
			{ typeof booking?.other_conditions === 'string' &&
				booking.other_conditions.trim() !== '' && (
					<dl className="vkbm-confirm__summary-item vkbm-confirm__summary-item--other-conditions">
						<dt className="vkbm-confirm__summary-item-title">
							{ typeof otherConditionsLabel === 'string' &&
							otherConditionsLabel.trim() !== ''
								? otherConditionsLabel
								: __(
										'Other conditions',
										'vk-booking-manager'
								  ) }
						</dt>
						<dd className="vkbm-confirm__summary-value--multiline">
							{ booking.other_conditions }
						</dd>
					</dl>
				) }
		</>
	);
};
