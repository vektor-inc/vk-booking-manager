/**
 * ログインしていないお客様の REST 通信が、セキュリティ系プラグイン（SiteGuard WP Plugin の
 * 「ユーザー名漏えい防御」など）に止められたときの判定と、開発者向けのコンソール出力（#1154）。
 *
 * 画面に出す文言の差し替えは rest-error-message.js の resolveApiErrorMessage() が行う。
 */
import apiFetch from '@wordpress/api-fetch';

import { resolveLoginState } from './auth';

/**
 * 通信を止められたとみなす HTTP ステータス（未ログインは 401、ログイン中は 403 を返すプラグインが多い）。
 */
const ACCESS_DENIED_STATUSES = [ 401, 403 ];

/**
 * 当プラグイン自身が返すエラーコードの接頭辞。これで始まるエラーは、文言の差し替えの対象にしない。
 */
const OWN_ERROR_CODE_PREFIX = 'vkbm_';

/**
 * 当プラグイン自身が 401 / 403 で返す、接頭辞 `vkbm_` の付いていないエラーコード。
 *
 * これらはお客様へ出す文言をプラグイン側で用意しているため、差し替えない。
 * PHP 側で 401 / 403 のエラーコードを足したときは、`vkbm_` で始めるか、ここに足すこと
 * （__tests__/rest-access-denied.test.js が PHP のソースと突き合わせて確かめる）。
 */
export const OWN_ACCESS_ERROR_CODES = [
	'attendee_roster_forbidden',
	'cancel_deadline_passed',
	'cancel_disabled',
	'forbidden',
	'forbidden_draft',
	'guest_booking_disabled',
	'guest_booking_logged_in',
	'guest_cancel_invalid',
	'not_logged_in',
];

let loggerRegistered = false;

/**
 * エラーから HTTP ステータスを取り出す。
 *
 * apiFetch のエラーは data.status に、予約の確定で normalizeBookingError()（booking-confirm-error.js）が
 * そろえた失敗内容は status に持つため、両方を見る。
 *
 * @param {Object} error apiFetch が reject したエラー、または normalizeBookingError() の戻り値。
 * @return {number} ステータス。取り出せないときは 0。
 */
const getErrorStatus = ( error ) =>
	Number( error?.data?.status || error?.status ) || 0;

/**
 * ログインしていないお客様の通信が、当プラグイン以外の理由（セキュリティ系プラグインなど）で 401 / 403 になったかを返す。
 *
 * @param {Object}  error      apiFetch が reject したエラーオブジェクト。
 * @param {boolean} isLoggedIn ログインしているか。
 * @return {boolean} 差し替えの対象なら true。
 */
export const isGuestAccessDeniedError = ( error, isLoggedIn ) => {
	if ( isLoggedIn ) {
		return false;
	}

	if ( ! ACCESS_DENIED_STATUSES.includes( getErrorStatus( error ) ) ) {
		return false;
	}

	const code = typeof error?.code === 'string' ? error.code : '';

	return (
		! code.startsWith( OWN_ERROR_CODE_PREFIX ) &&
		! OWN_ACCESS_ERROR_CODES.includes( code )
	);
};

/**
 * コンソールに出す、止められた通信の説明を組み立てる。
 *
 * @param {Object} options apiFetch に渡したオプション（path または url）。
 * @param {Object} error   apiFetch が reject したエラーオブジェクト。
 * @return {string} 説明の文。
 */
export const buildAccessDeniedLogMessage = ( options, error ) => {
	const method =
		typeof options?.method === 'string'
			? options.method.toUpperCase()
			: 'GET';
	const target = options?.path || options?.url || '';

	return `[VK Booking Manager] The REST API request was denied: ${ method } ${ target } (status ${ getErrorStatus(
		error
	) }, code ${
		error?.code || ''
	}). A security plugin may be blocking REST API requests from visitors who are not logged in.`;
};

/**
 * apiFetch が reject した値を、判定・コンソール出力に使える `{ status, code }` の形にそろえる。
 *
 * `parse: false` で呼んだ通信（予約の確定など）は、HTTP エラーのとき Response のまま reject される。
 * そのままではエラーコードが読めず、当プラグイン自身のエラーまで止められた扱いになるため、
 * 複製（clone()）した Response の本文からコードを読む。元の Response の本文は消費しない（呼び出し側が読むため）。
 *
 * @param {Object|Response} error apiFetch が reject した値。
 * @return {Promise<Object>} Response なら `{ status, code }`、それ以外は受け取った値そのもの。
 */
export const readErrorForAccessCheck = async ( error ) => {
	const isResponseLike =
		!! error &&
		typeof error === 'object' &&
		typeof error.status === 'number' &&
		typeof error.clone === 'function';
	if ( ! isResponseLike ) {
		return error;
	}

	let body = null;
	try {
		body = await error.clone().json();
	} catch ( readError ) {
		body = null;
	}

	return {
		status: error.status,
		code: typeof body?.code === 'string' ? body.code : '',
	};
};

/**
 * 止められた通信を、開発者向けにコンソールへ出す apiFetch のミドルウェアを登録する（1 ページで 1 回だけ）。
 *
 * エラーはそのまま（Response のときも本文を読まずに）投げ直し、呼び出し側の処理は変えない。
 */
export const registerAccessDeniedLogger = () => {
	if ( loggerRegistered ) {
		return;
	}
	loggerRegistered = true;

	apiFetch.use( ( options, next ) =>
		next( options ).catch( async ( error ) => {
			const checked = await readErrorForAccessCheck( error );
			if ( isGuestAccessDeniedError( checked, resolveLoginState() ) ) {
				// eslint-disable-next-line no-console
				console.warn( buildAccessDeniedLogMessage( options, checked ) );
			}
			throw error;
		} )
	);
};

/**
 * テスト用: 登録済みの状態を戻す。
 */
export const __resetAccessDeniedLoggerForTests = () => {
	loggerRegistered = false;
};
