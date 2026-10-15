/**
 * 予約確認画面（booking-confirm-app.js）の予約作成 API（POST /vkbm/v1/bookings）の
 * 失敗を扱うヘルパー。
 *
 * 予約作成は apiFetch を `parse: false` で呼んでいるため、HTTP エラー（4xx/5xx）のときは
 * apiFetch がレスポンス本文を読まずに Response オブジェクトそのものを reject する。
 * ここでは Response・通信エラー（fetch_error 等のオブジェクト）・Error のいずれでも、
 * エラーコードとメッセージを同じ形で取り出せるようにする。
 */
import { __ } from '@wordpress/i18n';

/**
 * 「同じ日時の予約が既にある」ことを表す REST のエラーコード。
 *
 * サーバー（Booking_Confirmation_Controller::create_booking()）は、利用者本人に同じ日時の予約が
 * 既にある場合にだけ、このコードを HTTP 409 で返す。
 */
export const BOOKING_TIME_CONFLICT_CODE = 'booking_time_conflict';

/**
 * JSON 文字列を解析する。前後に余計な出力（PHP の Notice 等）が混ざっていても、
 * 最初の `{`（または `[`）から最後の `}`（または `]`）までを取り出して解析を試みる。
 *
 * @param {string} raw レスポンス本文。
 * @return {*} 解析結果。
 * @throws {Error} JSON として解析できない場合。
 */
export const parseJsonWithFallback = ( raw ) => {
	const fallbackError = new Error(
		__( 'The response is not a valid JSON response.', 'vk-booking-manager' )
	);

	if ( typeof raw !== 'string' ) {
		throw fallbackError;
	}

	const trimmed = raw.trim();
	if ( ! trimmed ) {
		throw fallbackError;
	}

	try {
		return JSON.parse( trimmed );
	} catch ( error ) {
		let start = trimmed.indexOf( '{' );
		let end = trimmed.lastIndexOf( '}' );

		if ( start === -1 || end === -1 || end < start ) {
			start = trimmed.indexOf( '[' );
			end = trimmed.lastIndexOf( ']' );
		}

		if ( start === -1 || end === -1 || end < start ) {
			throw fallbackError;
		}

		try {
			return JSON.parse( trimmed.slice( start, end + 1 ) );
		} catch ( innerError ) {
			throw fallbackError;
		}
	}
};

/**
 * 値が fetch の Response（または同じ形のオブジェクト）かどうかを判定する。
 *
 * @param {*} value 判定する値。
 * @return {boolean} status（数値）と text()（関数）を持っていれば true。
 */
const isResponseLike = ( value ) =>
	!! value &&
	typeof value === 'object' &&
	typeof value.status === 'number' &&
	typeof value.text === 'function';

/**
 * 予約作成 API の失敗を `{ status, code, message }` の形にそろえる。
 *
 * - Response（HTTP エラー）: 本文を読み、REST のエラー JSON（code / message）を取り出す。
 *   本文が JSON でない場合（サーバーの HTML エラー画面など）は code・message を空にする。
 * - それ以外（apiFetch の通信エラー `{ code, message }`、Error など）: 持っている値をそのまま使う。
 *
 * @param {*} error apiFetch が reject した値、または予約作成処理で throw された値。
 * @return {Promise<{status: number, code: string, message: string}>} そろえた失敗内容。
 *         status は HTTP ステータス（不明な場合は 0）。
 */
export const normalizeBookingError = async ( error ) => {
	if ( isResponseLike( error ) ) {
		let raw = '';
		try {
			raw = await error.text();
		} catch ( readError ) {
			raw = '';
		}

		let parsed = null;
		try {
			parsed = parseJsonWithFallback( raw );
		} catch ( parseError ) {
			parsed = null;
		}

		return {
			status: error.status,
			code: typeof parsed?.code === 'string' ? parsed.code : '',
			message: typeof parsed?.message === 'string' ? parsed.message : '',
		};
	}

	const status = Number( error?.data?.status || error?.status || 0 );

	return {
		status: Number.isFinite( status ) ? status : 0,
		code: typeof error?.code === 'string' ? error.code : '',
		message: typeof error?.message === 'string' ? error.message : '',
	};
};

/**
 * 失敗内容が「同じ日時の予約が既にある」ことによるものかを判定する。
 *
 * HTTP 409 は空き枠の変化・リソースタグの不一致・決済プラグインの二重使用防止などでも返るため、
 * ステータスやメッセージ文言ではなく、エラーコードだけで判定する。
 *
 * @param {{code: string}} failure normalizeBookingError() の戻り値。
 * @return {boolean} 同じ日時の予約との競合なら true。
 */
export const isBookingTimeConflict = ( failure ) =>
	failure?.code === BOOKING_TIME_CONFLICT_CODE;

/**
 * 仮予約データ（draft）を使えないことを表す REST のエラーコード。
 *
 * 予約作成 API（Booking_Confirmation_Controller::create_booking()）が、仮予約データの
 * トークンが無い（missing_token）・有効期限切れ等で見つからない（draft_not_found）・
 * 内容が不完全（invalid_draft）・本人のものではない（forbidden_draft）ときに返す。
 */
export const DRAFT_UNAVAILABLE_CODES = [
	'missing_token',
	'draft_not_found',
	'invalid_draft',
	'forbidden_draft',
];

/**
 * 失敗内容が「仮予約データを使えない」ことによるものかを判定する。
 *
 * 確認画面を開いたまま仮予約データの有効期限を過ぎた場合などが該当する。この場合は
 * サーバーの文言ではなく、予約ページからやり直すよう案内する文言を表示するために使う。
 *
 * @param {{code: string}} failure normalizeBookingError() の戻り値。
 * @return {boolean} 仮予約データ関連のエラーコードなら true。
 */
export const isDraftUnavailable = ( failure ) =>
	typeof failure?.code === 'string' &&
	DRAFT_UNAVAILABLE_CODES.includes( failure.code );

/**
 * 予約が作成されなかったことを決済プラグインへ伝える（window.vkbmOnBookingFailed を呼ぶ）。
 *
 * 決済プラグインはこのフックで、予約ボタン押下時に行った仮押さえ（オーソリ）を取り消す。
 * - フックが定義されていなければ何もしない。
 * - エラー表示を遅らせないよう、フックの完了は待たない（戻り値の Promise は待たずに捨てる）。
 *   取り消しが届かなくても、仮押さえは Stripe 側で自動失効するため売上にはならない。
 * - フック内で例外が起きても、Promise が reject されても、予約画面が壊れないよう握りつぶす。
 *
 * @param {Object}                                          payload 予約作成 API に送った payload（決済プラグインの結果をマージ済み）。
 *                                                                  フック側で書き換えられても予約画面に影響しないよう、コピーを渡す。
 * @param {{status: number, code: string, message: string}} failure normalizeBookingError() の戻り値。
 * @return {boolean} フックを呼び出した場合 true。
 */
export const notifyBookingFailed = ( payload, failure ) => {
	if (
		typeof window === 'undefined' ||
		typeof window.vkbmOnBookingFailed !== 'function'
	) {
		return false;
	}

	try {
		const hookResult = window.vkbmOnBookingFailed(
			{ ...payload },
			failure
		);
		// 非同期フックの reject が未処理のエラーとして扱われないようにする。
		if ( hookResult && typeof hookResult.catch === 'function' ) {
			hookResult.catch( () => {} );
		}
	} catch ( hookError ) {
		// 決済プラグイン側の不具合で予約画面の表示が崩れないよう、ここで止める。
	}

	return true;
};
