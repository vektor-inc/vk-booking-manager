import { __ } from '@wordpress/i18n';

/**
 * 泊の予約かどうかを、API が返す泊数（予約に保存した値）で判定する。
 *
 * @param {Object} booking マイページの予約1件。
 * @return {boolean} 泊の予約なら true。
 */
export const isStayBooking = ( booking ) => Number( booking?.nights ) > 0;

/**
 * 泊の予約のチェックイン／チェックアウトを2行で表示する。
 *
 * @param {Object} props
 * @param {string} props.checkIn  チェックイン日時の表示文字列。
 * @param {string} props.checkOut チェックアウト日時の表示文字列。
 */
export const BookingStayDatetime = ( { checkIn, checkOut } ) => (
	<>
		<span className="vkbm-confirm__stay-line">
			{ __( 'Check-in', 'vk-booking-manager' ) } { checkIn }
		</span>
		<span className="vkbm-confirm__stay-line">
			{ __( 'Check-out', 'vk-booking-manager' ) } { checkOut }
		</span>
	</>
);
