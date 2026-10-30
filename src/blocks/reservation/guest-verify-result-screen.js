import { useEffect, useRef } from '@wordpress/element';
import {
	GuestResultFrame,
	VERIFY_QUERY_VAR,
	buildReservationPageUrl,
	getSummaryLabels,
	useReservationPageUrl,
} from './components/guest-result-frame';
import { GuestVerifyResult } from './components/guest-verify-result';

export { buildReservationPageUrl };

const QUERY_VARS = [ VERIFY_QUERY_VAR ];

/**
 * 確認リンクを開いたときの画面全体（ブランド表示・結果・フッター）。
 *
 * 基本設定（店名・ロゴ・フッター・仮予約の案内文・各種の見出し）は、予約確認画面と同じ REST から読む。
 *
 * @param {Object} props        プロパティ。
 * @param {Object} props.result サーバーから渡された結果（window.vkbmReservationConfig.guestVerifyResult）。
 * @return {JSX.Element} 画面。
 */
export const GuestVerifyResultScreen = ( { result } ) => {
	const resultRef = useRef( null );
	const reservationPageUrl = useReservationPageUrl( QUERY_VARS );

	// 結果が出たら、結果の枠へフォーカスを移して読み上げさせる。
	useEffect( () => {
		resultRef.current?.focus();
	}, [] );

	return (
		<GuestResultFrame reservationPageUrl={ reservationPageUrl }>
			{ ( settings ) => (
				<GuestVerifyResult
					result={ result }
					pendingMessage={
						typeof settings?.booking_complete_message_pending ===
						'string'
							? settings.booking_complete_message_pending
							: ''
					}
					reservationPageUrl={ reservationPageUrl }
					{ ...getSummaryLabels( settings ) }
					containerRef={ resultRef }
				/>
			) }
		</GuestResultFrame>
	);
};
