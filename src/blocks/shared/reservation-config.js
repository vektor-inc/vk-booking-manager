/**
 * 予約ブロックの設定から宿泊業種かどうかを返す。
 *
 * @return {boolean} 宿泊業種なら true。
 */
export const isLodgingReservation = () =>
	Boolean(
		typeof window !== 'undefined' && window.vkbmReservationConfig?.isLodging
	);
