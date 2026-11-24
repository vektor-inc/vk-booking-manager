import { __, sprintf } from '@wordpress/i18n';

/**
 * 呼び方を変えていないとみなす値。
 * REST は空欄のとき翻訳済みの「メニュー」を返すため、翻訳 JSON が読めず英語のまま動く場合に備えて原文と日本語の両方を含める。
 *
 * @return {string[]} 既定の呼び方の一覧。
 */
const getDefaultMenuLabels = () => [
	'Menu',
	'メニュー',
	__( 'Menu', 'vk-booking-manager' ),
];

/**
 * 「メニューの呼び方」を、文に差し込む呼び方へそろえる。
 *
 * 空欄・「メニュー」のときは空文字を返す。呼び出し側は空文字を見て、今ある文言をそのまま出す
 * （設定を変えていないサイトの表示と、追加プラグインでの文言の差し替えを変えないため）。
 *
 * @param {unknown} value 設定値（REST の menu_label など）。
 * @return {string} 差し込む呼び方。既定のままなら空文字。
 */
export const normalizeMenuLabel = ( value ) => {
	if ( typeof value !== 'string' ) {
		return '';
	}
	const label = value.trim();
	if ( label === '' || getDefaultMenuLabels().includes( label ) ) {
		return '';
	}
	return label;
};

/**
 * 単数形と複数形の呼び方をまとめて決める。
 *
 * 呼び方の要否は単数形で判定し、複数形が空なら単数形を使う（複数形の欄は複数形のある言語の画面にしか出ないため）。
 *
 * @param {unknown} singular 単数形の設定値。
 * @param {unknown} plural   複数形の設定値。
 * @return {{menuLabel: string, menuLabelPlural: string}} 差し込む呼び方。既定のままなら両方とも空文字。
 */
export const buildMenuLabels = ( singular, plural ) => {
	const menuLabel = normalizeMenuLabel( singular );
	if ( menuLabel === '' ) {
		return { menuLabel: '', menuLabelPlural: '' };
	}
	const pluralLabel = typeof plural === 'string' ? plural.trim() : '';
	return {
		menuLabel,
		menuLabelPlural: pluralLabel !== '' ? pluralLabel : menuLabel,
	};
};

/**
 * ページに埋め込まれた予約ブロックの設定（window.vkbmReservationConfig）から呼び方を読む。
 *
 * REST の応答を待たずに最初の描画から呼び方を出すため、初期値に使う。
 *
 * @return {{menuLabel: string, menuLabelPlural: string}} 差し込む呼び方。
 */
export const readMenuLabelsFromConfig = () => {
	const config =
		typeof window !== 'undefined' && window.vkbmReservationConfig
			? window.vkbmReservationConfig
			: {};
	return buildMenuLabels( config.menuLabel, config.menuLabelPlural );
};

/**
 * REST（/vkbm/v1/provider-settings）の応答から呼び方を読む。
 *
 * 応答に menu_label が無い（読み込み前・取得失敗）ときは、ページに埋め込まれた値を使う。
 *
 * @param {?Object} settings REST の応答。
 * @return {{menuLabel: string, menuLabelPlural: string}} 差し込む呼び方。
 */
export const resolveMenuLabels = ( settings ) => {
	if ( typeof settings?.menu_label !== 'string' ) {
		return readMenuLabelsFromConfig();
	}
	return buildMenuLabels( settings.menu_label, settings.menu_label_plural );
};

/**
 * 呼び方があれば差し込み用の文に入れ、無ければ今ある文言を返す。
 *
 * @param {string} label           差し込む呼び方（既定のままなら空文字）。
 * @param {string} defaultText     呼び方を変えていないときに出す、今ある文言。
 * @param {string} labeledTemplate 呼び方を %s で差し込む文。
 * @return {string} 画面に出す文言。
 */
export const menuLabelText = ( label, defaultText, labeledTemplate ) =>
	label ? sprintf( labeledTemplate, label ) : defaultText;

/**
 * 見出しの「メニュー」を返す。呼び方を変えていれば、その呼び方を返す。
 *
 * @param {string} label 差し込む呼び方（既定のままなら空文字）。
 * @return {string} 見出し。
 */
export const getMenuHeading = ( label ) =>
	label || __( 'Menu', 'vk-booking-manager' );
