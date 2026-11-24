/**
 * REST API のエラーを、画面に表示するメッセージへ変換する（issue #489）。
 *
 * REST フォールバック（rest-fallback.js）でも通信が失敗した invalid_json エラーは、
 * コアのエラー文（「返答が正しい JSON レスポンスではありません。」等）をそのまま
 * 表示しても利用者には原因が伝わらない。ここでは invalid_json のときだけ、
 * 役割（一般の予約者 / 管理者）に応じた分かりやすい文言に差し替える。
 * invalid_json 以外のエラーコードは、従来どおり呼び出し側の fallbackMessage を使う
 * （挙動を変えない）。
 *
 * 管理者向けメッセージには、パーマリンク設定画面へのリンクを付ける。
 * `dangerouslySetInnerHTML` は使わず、React 要素として `<a href>` を描画する
 * （植草レビュー指摘）。そのため、この関数の戻り値は invalid_json かつ
 * 管理者のときだけ文字列ではなく React ノード（Fragment）になる。呼び出し側は
 * `{ resolveApiErrorMessage( ... ) }` のように JSX の子要素としてそのまま
 * 埋め込めば、文字列・React ノードのどちらでも正しく描画される。
 *
 * ログインしていないお客様の通信がセキュリティ系プラグインに止められたとき（401 / 403）も、
 * プラグインの英文のエラーを出さず、電話での問い合わせを案内する文言に差し替える（#1154）。
 */
import { __, sprintf } from '@wordpress/i18n';

import { resolveLoginState } from './auth';
import { isGuestAccessDeniedError } from './rest-access-denied';

/**
 * お客様1人あたりの予約数の上限に達したときの REST のエラーコード。
 *
 * サーバー（Customer_Booking_Limit::ERROR_CODE）が、下書きの保存と予約の確定で返す。
 */
export const BOOKING_LIMIT_REACHED_CODE = 'vkbm_booking_limit_reached';

/**
 * 今開いている予約ページで、持っている予約を確認する画面（予約内容確認）を開く URL を返す。
 *
 * 予約数の上限に達したことを伝える文言に添えるリンクのリンク先として使う。
 *
 * @return {string} URL。ブラウザ以外（テストなど）では空文字列。
 */
export const buildBookingsViewUrl = () => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const url = new URL( window.location.href );
	url.searchParams.set( 'vkbm_auth', 'bookings' );
	url.searchParams.delete( 'draft' );

	return url.toString();
};

/**
 * `window.vkbmReservationConfig.permalinkSettingsUrl` を読み取る。
 *
 * PHP 側（Reservation_Block::maybe_enqueue_reservation_config()）が
 * `esc_url_raw( admin_url( 'options-permalink.php' ) )` を渡している。
 *
 * @return {string} パーマリンク設定画面の URL。取得できない場合は空文字列。
 */
const getPermalinkSettingsUrl = () => {
	const config =
		typeof window !== 'undefined' && window.vkbmReservationConfig
			? window.vkbmReservationConfig
			: {};

	return typeof config.permalinkSettingsUrl === 'string'
		? config.permalinkSettingsUrl
		: '';
};

/**
 * `window.vkbmReservationConfig.providerPhone`（BM 設定の事業者の電話番号）を読み取る。
 *
 * 通信を止められると provider-settings の REST も読めないため、PHP 側
 * （Reservation_Block::maybe_enqueue_reservation_config()）がページに埋め込んだ値を使う。
 *
 * @return {string} 電話番号。未設定・取得できない場合は空文字列。
 */
const getProviderPhone = () => {
	const config =
		typeof window !== 'undefined' && window.vkbmReservationConfig
			? window.vkbmReservationConfig
			: {};

	return typeof config.providerPhone === 'string'
		? config.providerPhone.trim()
		: '';
};

/**
 * 通信を止められたときにお客様へ出す、問い合わせの案内を組み立てる。
 *
 * 案内は `.vkbm-guest-access-denied` の span で包み、文ごと（電話番号があれば TEL の行も）を
 * `__line` の span に分けて 1 行ずつ出す（狭い幅で語の途中で折り返さないための指定を、
 * 共通の `.vkbm-alert` ではなくこの案内だけに当てるため。style.scss を参照）。
 * 行どうしの間の半角スペースは、文字だけを取り出したとき（読み上げなど）に文がつながらないためのもの。
 *
 * @param {string} phone 事業者の電話番号（空なら番号を添えず、直接の問い合わせを案内する）。
 * @return {JSX.Element} 案内の文言（電話番号があるときは tel: リンクを含む）。
 */
export const buildGuestAccessDeniedMessage = ( phone ) => {
	const unavailableLine = (
		<span className="vkbm-guest-access-denied__line">
			{ __(
				'Online reservations are not available at the moment.',
				'vk-booking-manager'
			) }
		</span>
	);

	// 電話番号が無いときは、電話番号を添えずに直接の問い合わせを案内する。
	if ( ! phone ) {
		return (
			<span className="vkbm-guest-access-denied">
				{ unavailableLine }{ ' ' }
				<span className="vkbm-guest-access-denied__line">
					{ __(
						'Sorry for the inconvenience, but please contact us directly.',
						'vk-booking-manager'
					) }
				</span>
			</span>
		);
	}

	// 電話番号があるときは、電話での問い合わせの案内と tel: リンクを添える。
	return (
		<span className="vkbm-guest-access-denied">
			{ unavailableLine }{ ' ' }
			<span className="vkbm-guest-access-denied__line">
				{ __(
					'Sorry for the inconvenience, but please contact us by phone.',
					'vk-booking-manager'
				) }
			</span>{ ' ' }
			<span className="vkbm-guest-access-denied__line">
				<a href={ `tel:${ phone.replace( /[^0-9+]/g, '' ) }` }>
					{ sprintf(
						/* translators: %s: Provider phone number. */
						__( 'TEL: %s', 'vk-booking-manager' ),
						phone
					) }
				</a>
			</span>
		</span>
	);
};

/**
 * apiFetch が reject したエラーから、画面に表示するメッセージを解決する。
 *
 * @param {Object}  error                 apiFetch が reject したエラーオブジェクト。
 * @param {string}  fallbackMessage       invalid_json 以外のときに使う、呼び出し側の通常のフォールバック文言。
 * @param {boolean} canManageReservations 現在のユーザーが予約管理権限を持つか（管理者向け文言の出し分けに使う）。
 * @param {string}  bookingsUrl           予約数の上限に達したときに、持っている予約を確認できる画面（予約内容確認）へ案内するリンク先。空なら文言だけを返す。
 * @return {string|JSX.Element} 画面に表示するエラーメッセージ（文字列、または管理者向け・予約数の上限のリンクを含む React ノード）。
 */
export const resolveApiErrorMessage = (
	error,
	fallbackMessage,
	canManageReservations = false,
	bookingsUrl = ''
) => {
	// 予約数の上限に達したときは、持っている予約を確認してキャンセルできる画面へのリンクを添える。
	if ( error?.code === BOOKING_LIMIT_REACHED_CODE && error?.message ) {
		if ( ! bookingsUrl ) {
			return error.message;
		}

		return (
			<>
				{ error.message }{ ' ' }
				<a href={ bookingsUrl }>
					{ __( 'Confirm reservation', 'vk-booking-manager' ) }
				</a>
			</>
		);
	}

	// ログインしていないお客様の通信が止められたときは、プラグインの英文を出さずに電話での問い合わせを案内する。
	// 止められた通信先とステータスは registerAccessDeniedLogger()（rest-access-denied.js）がコンソールへ出す。
	if ( isGuestAccessDeniedError( error, resolveLoginState() ) ) {
		return buildGuestAccessDeniedMessage( getProviderPhone() );
	}

	if ( error?.code !== 'invalid_json' ) {
		return error?.message || fallbackMessage;
	}

	const generalMessage = __(
		'The booking system is temporarily unavailable. Please try again later.',
		'vk-booking-manager'
	);

	if ( ! canManageReservations ) {
		return generalMessage;
	}

	// 原因を一つに決めつけず、パーマリンク設定の保存を第一候補としつつ、
	// 解消しない場合の他の確認先（セキュリティ系プラグイン・サーバー設定）にも触れる
	// （植草レビュー指摘）。
	const adminMessage = __(
		'For administrators: The REST API is not responding. Try saving the permalink settings. If that does not help, check your security plugins or server settings.',
		'vk-booking-manager'
	);

	const permalinkSettingsUrl = getPermalinkSettingsUrl();

	if ( ! permalinkSettingsUrl ) {
		// URL が取得できない場合は、これまでどおりテキストのみで表示する。
		return `${ generalMessage } ${ adminMessage }`;
	}

	return (
		<>
			{ generalMessage } { adminMessage }{ ' ' }
			<a href={ permalinkSettingsUrl }>
				{
					/* 管理画面通知（class-setup-notices.php）のボタン文言と
					 * 同じ msgid を再利用する。 */
					__( 'Open permalink settings', 'vk-booking-manager' )
				}
			</a>
		</>
	);
};
