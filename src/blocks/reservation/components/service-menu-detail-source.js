/**
 * サービスメニュー詳細表示（#635）の、サーバー埋め込み要素の読み取りと URL 組み立て。
 *
 * 本文・カードはサーバー側で組み立てて予約ブロックの中へ非表示の要素として埋め込まれる
 * （Service_Menu_Detail_Renderer）。ここで読み取り、予約ブロックの JavaScript が表示する。
 */

import { isValidIsoDate } from '../booking-ui/date-filter';

export const DETAIL_SOURCE_SELECTOR = '.vkbm-reservation-detail-source';
export const DETAIL_STATUS_OK = 'ok';
export const DETAIL_STATUS_UNAVAILABLE = 'unavailable';

/**
 * 予約ブロックの要素から、サーバーが埋め込んだ詳細表示の元になる情報を読み取る。
 *
 * 本文・カードの要素は、描画のために中身が書き換わる前に取り出して返す。
 * 要素そのものを後から画面へ戻すことで、本文のブロックが付けた JavaScript の動作を保つ。
 *
 * @param {Element} root 予約ブロックの wrapper 要素。
 * @return {{status: string, menuId: number, title: string, listUrl: string, contentNode: ?Element, cardNode: ?Element, editLinkNode: ?Element}|null} 詳細表示の要求でなければ null。
 */
export const readDetailSource = ( root ) => {
	const source = root?.querySelector?.( DETAIL_SOURCE_SELECTOR );
	if ( ! source ) {
		return null;
	}

	const status = source.getAttribute( 'data-vkbm-detail-status' ) || '';
	if ( status !== DETAIL_STATUS_OK ) {
		return {
			status: DETAIL_STATUS_UNAVAILABLE,
			menuId: 0,
			title: '',
			listUrl: '',
			contentNode: null,
			cardNode: null,
			editLinkNode: null,
		};
	}

	const contentNode = source.querySelector(
		'.vkbm-reservation-detail-source__content'
	);
	const cardNode = source.querySelector(
		'.vkbm-reservation-detail-source__card'
	);

	return {
		status,
		menuId:
			Number( source.getAttribute( 'data-vkbm-detail-menu-id' ) ) || 0,
		title: source.getAttribute( 'data-vkbm-detail-title' ) || '',
		listUrl: source.getAttribute( 'data-vkbm-detail-list-url' ) || '',
		contentNode,
		cardNode,
		editLinkNode: source.querySelector(
			'.vkbm-reservation-detail-source__edit-link .vkbm-menu-loop__edit-link'
		),
	};
};

/**
 * 詳細表示から予約画面へ切り替えるときの URL を組み立てる。
 *
 * 詳細表示用の `vkbm_view` を外し、選んだメニューの `menu_id` を付ける。
 *
 * @param {string} currentUrl 現在の URL。
 * @param {number} menuId     予約画面で選択するサービスメニューの投稿 ID。
 * @return {string} 予約画面の URL。
 */
export const buildReservationUrlFromDetail = ( currentUrl, menuId ) => {
	const url = new URL( currentUrl );
	url.searchParams.delete( 'vkbm_view' );
	url.searchParams.set( 'menu_id', String( menuId ) );
	return url.toString();
};

/**
 * URL が詳細表示を指しているかを返す。
 *
 * @param {string} search `location.search` の値。
 * @return {boolean} `vkbm_view=detail` が付いていれば true。
 */
export const isDetailViewUrl = ( search ) =>
	new URLSearchParams( search ).get( 'vkbm_view' ) === 'detail';

/**
 * URL の日付パラメーター（date・check_out_date）を付け替えた URL を返す。
 *
 * 絞り込み検索で日付を選び直したときに、ブラウザの「戻る」で日付が残った一覧へ戻れるよう、表示中の URL へ反映するために使う。
 * 空文字の値は、そのパラメーターを外す。
 *
 * @param {string} currentUrl 現在の URL。
 * @param {string} date       date パラメーターの値（空文字で外す）。
 * @param {string} checkOut   check_out_date パラメーターの値（空文字で外す）。
 * @return {string} 日付パラメーターを付け替えた URL。
 */
export const applyDateQuery = ( currentUrl, date, checkOut ) => {
	const url = new URL( currentUrl );
	[
		[ 'date', date ],
		[ 'check_out_date', checkOut ],
	].forEach( ( [ key, value ] ) => {
		if ( value ) {
			url.searchParams.set( key, value );
		} else {
			url.searchParams.delete( key );
		}
	} );
	return url.toString();
};

/**
 * 詳細表示の「一覧に戻る」の行き先に、表示中の URL の日付（date・check_out_date）を引き継いだ URL を返す。
 *
 * 実在する日付だけを引き継ぐ。チェックアウト日は、チェックイン日の翌日以降のときだけ引き継ぐ。
 *
 * @param {string} listUrl 一覧（予約ページ）の URL。空文字のときは空文字を返す。
 * @param {string} search  `location.search` の値。
 * @return {string} 日付を引き継いだ一覧の URL。
 */
export const buildListReturnUrl = ( listUrl, search ) => {
	if ( ! listUrl ) {
		return '';
	}

	const params = new URLSearchParams( search );
	const date = params.get( 'date' ) || '';
	if ( ! isValidIsoDate( date ) ) {
		return listUrl;
	}

	const checkOut = params.get( 'check_out_date' ) || '';
	const hasCheckOut = isValidIsoDate( checkOut ) && checkOut > date;

	return applyDateQuery( listUrl, date, hasCheckOut ? checkOut : '' );
};
