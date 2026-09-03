import { __ } from '@wordpress/i18n';
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
	emptyValue = '',
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
	const staffName =
		typeof booking?.resource_name === 'string' &&
		booking.resource_name.trim() !== ''
			? booking.resource_name
			: emptyValue;

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
			{ booking?.is_staff_preferred && (
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
