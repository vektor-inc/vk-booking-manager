/**
 * 予約画面の REST 通信に、ページの言語（ロケール）を添える apiFetch ミドルウェア（#1186）。
 *
 * apiFetch の通信先（/wp-json/）はページの言語を含まないため、Bogo の英語ページでも REST はサイトの言語で処理される。
 * このミドルウェアは、このプラグインの REST（/vkbm/v1/）にだけ `vkbm_locale` を付け、
 * サーバー側（VKBookingManager\Multilingual\Rest_Locale）がその言語で呼び方を返せるようにする。
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

// このモジュールを import した各エントリで二重登録しないためのフラグ。
let middlewareRegistered = false;

/**
 * このプラグインの REST の path か（`/vkbm/v1/` で始まるか）を返す。
 *
 * @param {unknown} path apiFetch に渡された path。
 * @return {boolean} このプラグインの REST なら true。
 */
export const isPluginRestPath = ( path ) =>
	typeof path === 'string' && /^\/?vkbm\/v1(\/|\?|$)/.test( path );

/**
 * path にページの言語を添える。
 *
 * @param {string} path   apiFetch に渡された path。
 * @param {string} locale ページのロケール（例: en_US）。
 * @return {string} `vkbm_locale` を付けた path。
 */
export const addLocaleToPath = ( path, locale ) =>
	addQueryArgs( path, { vkbm_locale: locale } );

/**
 * ページの言語を添える apiFetch ミドルウェアを登録する。
 *
 * `window.vkbmReservationConfig.locale`（Reservation_Block がページに埋め込む determine_locale() の値）が無いときは何もしない。
 *
 * @return {void}
 */
export const registerRestLocaleMiddleware = () => {
	if ( middlewareRegistered ) {
		return;
	}
	middlewareRegistered = true;

	const config =
		typeof window !== 'undefined' && window.vkbmReservationConfig
			? window.vkbmReservationConfig
			: {};
	const locale = typeof config.locale === 'string' ? config.locale : '';
	if ( '' === locale ) {
		return;
	}

	apiFetch.use( ( options, next ) => {
		if ( ! isPluginRestPath( options.path ) ) {
			return next( options );
		}

		return next( {
			...options,
			path: addLocaleToPath( options.path, locale ),
		} );
	} );
};

/**
 * テスト用: 登録済みフラグをリセットする。
 *
 * @return {void}
 */
export const __resetRestLocaleStateForTests = () => {
	middlewareRegistered = false;
};
