import { useEffect, useRef, useState } from '@wordpress/element';
import {
	CANCEL_QUERY_VARS,
	GuestResultFrame,
	getSummaryLabels,
	useReservationPageUrl,
} from './components/guest-result-frame';
import { GuestCancel } from './components/guest-cancel';

/** キャンセル用リンクのトークンのクエリ名（Guest_Cancel_Controller::QUERY_VAR）。 */
const TOKEN_QUERY_VAR = CANCEL_QUERY_VARS[ 0 ];

/**
 * 今のアドレスから、キャンセル用リンクのトークンを読む。
 *
 * ボタンを押したときに送るため、アドレスバーから外す前（最初の表示）に読んで画面の中だけで持つ。
 *
 * @return {string} トークン。無ければ空文字。
 */
const readTokenFromLocation = () => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	// 通常は、ページの先頭で動くスクリプト（Guest_Cancel_Controller::print_link_scrubber）が URL から外して退避した値を読む。
	// 退避が無いとき（スクリプトが動かなかった場合）は、アドレスから直接読む。
	return (
		window.vkbmGuestLinkParams?.[ TOKEN_QUERY_VAR ] ||
		new URLSearchParams( window.location.search ).get( TOKEN_QUERY_VAR ) ||
		''
	);
};

/**
 * ゲスト予約のキャンセル用リンクを開いたときの画面全体（ブランド表示・確認・フッター）。
 *
 * 基本設定（店名・ロゴ・フッター・各種の見出し）は、予約確認画面と同じ REST から読む。
 *
 * @param {Object} props      プロパティ。
 * @param {Object} props.view サーバーから渡された内容（window.vkbmReservationConfig.guestCancel）。
 * @return {JSX.Element} 画面。
 */
export const GuestCancelScreen = ( { view } ) => {
	const containerRef = useRef( null );
	const [ token ] = useState( readTokenFromLocation );
	const reservationPageUrl = useReservationPageUrl( CANCEL_QUERY_VARS );

	// 最初の表示で、見出しの枠へフォーカスを移して読み上げさせる。
	useEffect( () => {
		containerRef.current?.focus();
	}, [] );

	return (
		<GuestResultFrame reservationPageUrl={ reservationPageUrl }>
			{ ( settings ) => (
				<GuestCancel
					view={ view }
					token={ token }
					reservationPageUrl={ reservationPageUrl }
					{ ...getSummaryLabels( settings ) }
					containerRef={ containerRef }
				/>
			) }
		</GuestResultFrame>
	);
};
