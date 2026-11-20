/**
 * サービスメニューの REST レコードから、並べ替えに使うタイトル文字列を取り出す。
 *
 * @param {Object} menu REST のレコード。
 * @return {string} タイトル。取れなければ空文字。
 */
const getMenuTitle = ( menu ) => {
	const title = menu?.title?.rendered ?? menu?.title;
	return 'string' === typeof title ? title : '';
};

/**
 * REST のレコードから並び順（menu_order の値）を数値で取り出す。数値でなければ 0。
 *
 * vkbm_service_menu は page-attributes を持たないため、REST に menu_order は出ない。
 * プラグインが登録する REST フィールド order（Post_Order_Manager）を読む。
 * order が無いレコードだけ menu_order を見る。
 *
 * @param {Object} menu REST のレコード。
 * @return {number} 並び順の値。
 */
const getMenuOrder = ( menu ) => {
	const order = Number( menu?.order ?? menu?.menu_order );
	return Number.isFinite( order ) ? order : 0;
};

/**
 * サービスメニューの一覧を並び順（order）昇順（同値ならタイトル順）に並べ替える。
 *
 * vkbm_service_menu は page-attributes を持たず、REST の orderby に menu_order を
 * 指定すると 400 になるため、取得後に JavaScript 側で並べる。
 * 並びの規則は PHP 側（menu_order → タイトル）と同じ。元の配列は変更しない。
 *
 * @param {Array} menus REST から取得したレコードの配列。
 * @return {Array} 並べ替えた新しい配列。配列でなければ空配列。
 */
export const sortMenusByMenuOrder = ( menus ) => {
	if ( ! Array.isArray( menus ) ) {
		return [];
	}

	return [ ...menus ].sort( ( a, b ) => {
		// 1. menu_order の昇順。
		const orderDiff = getMenuOrder( a ) - getMenuOrder( b );
		if ( 0 !== orderDiff ) {
			return orderDiff;
		}

		// 2. 同値ならタイトル（コードポイント順。PHP の strcmp に合わせる）。
		const titleA = getMenuTitle( a );
		const titleB = getMenuTitle( b );
		if ( titleA === titleB ) {
			return 0;
		}
		return titleA < titleB ? -1 : 1;
	} );
};
