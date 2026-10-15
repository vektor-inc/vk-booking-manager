import { addDays, countNights, parseIsoDate } from '../lodging/stay-dates';

/**
 * 絞り込み検索の日付指定で使う純関数。
 *
 * 日付は 'YYYY-MM-DD' の文字列で持つ。宿泊のサイトでは、日付1つの代わりにチェックイン日・チェックアウト日・泊数を
 * 持つ（STAY_ で始まる定数と、validateStay・applyCheckInChange など）。
 */

/**
 * 実在する 'YYYY-MM-DD' かを返す（2026-02-31 のような存在しない日付は false）。
 *
 * @param {string} iso 日付文字列。
 * @return {boolean} 実在する日付なら true。
 */
export const isValidIsoDate = ( iso ) => {
	const parts = parseIsoDate( iso );
	if ( ! parts || parts.month < 1 || parts.month > 12 || parts.day < 1 ) {
		return false;
	}

	// Date は 0〜99 年を 1900 年代として扱うため、うるう年の判定を自前で行う。
	const isLeapYear =
		( parts.year % 4 === 0 && parts.year % 100 !== 0 ) ||
		parts.year % 400 === 0;
	const daysInMonth = [
		31,
		isLeapYear ? 29 : 28,
		31,
		30,
		31,
		30,
		31,
		31,
		30,
		31,
		30,
		31,
	][ parts.month - 1 ];

	return parts.day <= daysInMonth;
};

/**
 * 日付欄に入れてよい値へ整える。実在しない日付・今日より前の日付は空文字にする。
 *
 * @param {string} value    入力された日付（URL の date パラメーターなど）。
 * @param {string} todayIso 今日の日付（'YYYY-MM-DD'）。
 * @return {string} 使える日付。使えなければ空文字。
 */
export const normalizeFilterDate = ( value, todayIso ) => {
	if ( typeof value !== 'string' || ! isValidIsoDate( value ) ) {
		return '';
	}

	return value < todayIso ? '' : value;
};

/**
 * 日付欄で選べる最後の日付（サーバーが受け付ける年の上限に合わせる）。
 */
export const FILTER_DATE_MAX = '2100-12-31';

/**
 * 日付欄に入力された値を、絞り込みに使うかどうかの判断に分類する。
 *
 * 年をキーボードで打っている途中は、0002-10-13 のように今日より前の値が一時的にできる。
 * そのため欄に出す値と絞り込みに使う値を分け、使うのは「実在する日付で、今日から 2100 年までの値」だけにする。
 *
 * - apply: 絞り込みに使う。
 * - clear: 日付の指定を外す（入力途中ではなく、空になったとき）。
 * - ignore: 何もしない（入力途中、または範囲外。直前の絞り込みのまま）。
 *
 * @param {string}  value    日付入力の値（YYYY-MM-DD。入力途中は空文字）。
 * @param {boolean} badInput 入力途中の不完全な値か（日付入力の validity.badInput）。
 * @param {string}  todayIso 今日の日付（'YYYY-MM-DD'）。
 * @return {{action: string, isPast: boolean, isYearComplete: boolean}} 分類結果。isPast は過去日、isYearComplete は年が 4 桁そろっているか。
 */
export const classifyDateInput = ( value, badInput, todayIso ) => {
	if ( value === '' ) {
		return {
			action: badInput ? 'ignore' : 'clear',
			isPast: false,
			isYearComplete: false,
		};
	}

	const parts = parseIsoDate( value );
	if ( ! parts || ! isValidIsoDate( value ) ) {
		return { action: 'ignore', isPast: false, isYearComplete: false };
	}

	const isYearComplete = parts.year >= 1000;
	if ( value < todayIso ) {
		return { action: 'ignore', isPast: true, isYearComplete };
	}

	return {
		action: value > FILTER_DATE_MAX ? 'ignore' : 'apply',
		isPast: false,
		isYearComplete,
	};
};

/**
 * 絞り込み検索に日付欄を出してよいかを返す。
 *
 * 基本設定の「日付で絞り込む」が ON で、絞り込み検索が表示される構成のときだけ true。
 * 宿泊のサイトでは、サーバーが基本設定の応答でこの項目を false にして返す。
 *
 * @param {boolean} dateSearchEnabled 基本設定の「日付で絞り込む」（サーバーが実効値にして返す）。
 * @param {boolean} menuSearchVisible 絞り込み検索を表示する構成か。
 * @return {boolean} 日付欄を出してよければ true。
 */
export const isDateFilterAvailable = ( dateSearchEnabled, menuSearchVisible ) =>
	Boolean( dateSearchEnabled ) && Boolean( menuSearchVisible );

/**
 * メニューを、表示するメニュー ID の一覧で絞り込む。
 *
 * ID 一覧が null（まだ取得していない・日付を指定していない）のときは絞り込まずそのまま返す。
 *
 * @param {Array<{id: number}>} menus   メニューの配列。
 * @param {Array<number>|null}  menuIds 表示するメニューの ID。null は絞り込みなし。
 * @return {Array<{id: number}>} 絞り込み後のメニュー。
 */
export const filterMenusByIds = ( menus, menuIds ) => {
	if ( ! Array.isArray( menuIds ) ) {
		return menus;
	}

	const allowed = new Set( menuIds.map( Number ) );
	return menus.filter( ( menu ) => allowed.has( Number( menu.id ) ) );
};

/**
 * メニューを選んだときに、予約カレンダーへ引き継ぐ日付と表示月を決める。
 *
 * 絞り込みで日付を指定していれば、その日を選択日、その月を表示月にする。
 * 指定していなければ選択日は空、表示月は今日の月にする。
 *
 * @param {string} filterDate 絞り込み検索で選んだ日付（未指定は空文字）。
 * @param {string} todayIso   今日の日付（'YYYY-MM-DD'）。
 * @return {{date: string, year: number, month: number}} 引き継ぐ日付と表示月。
 */
export const resolveCarriedDate = ( filterDate, todayIso ) => {
	const date = isValidIsoDate( filterDate ) ? filterDate : '';
	const parts = parseIsoDate( date || todayIso );

	return {
		date,
		year: parts ? parts.year : new Date().getFullYear(),
		month: parts ? parts.month : new Date().getMonth() + 1,
	};
};

/**
 * 引き継いだ日付が、選んだメニューの予約カレンダーで選べないかを返す。
 *
 * 表示月のカレンダーが、選んだメニューのものとして読み込み済みのときだけ判定する。
 * 読み込み前・別のメニューのカレンダーが残っているときは false（まだ判定できない）。
 *
 * @param {string}      date         引き継いだ日付。
 * @param {number}      menuId       選んだメニューの ID。
 * @param {Object|null} calendarData カレンダー取得（calendar-meta）の応答。
 * @return {boolean} 読み込み済みで、その日が選べない（無い・受付不可）なら true。
 */
export const isCarriedDateUnselectable = ( date, menuId, calendarData ) => {
	const parts = parseIsoDate( date );
	if ( ! parts || ! calendarData || ! Array.isArray( calendarData.days ) ) {
		return false;
	}

	const isSameCalendar =
		Number( calendarData?.meta?.menu_id ) === Number( menuId ) &&
		Number( calendarData.year ) === parts.year &&
		Number( calendarData.month ) === parts.month;
	if ( ! isSameCalendar ) {
		return false;
	}

	const day = calendarData.days.find( ( entry ) => entry.date === date );
	return ! day || Boolean( day.is_disabled );
};

/**
 * 宿泊の日程で選べる最大の泊数の既定値（サーバーが基本設定の応答で返す値が無いときに使う）。
 * サーバー側の Night_Strategy::MAX_NIGHTS と同じ値。
 */
export const STAY_MAX_NIGHTS = 30;

/**
 * 宿泊の日程が矛盾しているときのエラーの種類。
 */
export const STAY_ERROR_CHECK_OUT_BEFORE = 'check-out-before';
export const STAY_ERROR_TOO_MANY_NIGHTS = 'too-many-nights';

/**
 * 宿泊の日程（チェックイン日・チェックアウト日）が絞り込みに使える状態かを調べる。
 *
 * 2 つとも実在する日付でそろっていて、チェックアウト日がチェックイン日の翌日以降、
 * かつ泊数が上限以内のときだけ isComplete が true になる。そろっているのに矛盾しているときは error に種類を返す
 * （日付が足りない間はエラーにしない）。
 *
 * @param {{checkIn: string, checkOut: string}} stay      チェックイン日とチェックアウト日。
 * @param {number}                              maxNights 1 回に予約できる最大泊数。
 * @return {{isComplete: boolean, error: string, nights: number}} 判定結果。error は空文字か STAY_ERROR_*。
 */
export const validateStay = ( stay, maxNights = STAY_MAX_NIGHTS ) => {
	const { checkIn, checkOut } = stay;
	if ( ! isValidIsoDate( checkIn ) || ! isValidIsoDate( checkOut ) ) {
		return { isComplete: false, error: '', nights: 0 };
	}

	if ( checkOut <= checkIn ) {
		return {
			isComplete: false,
			error: STAY_ERROR_CHECK_OUT_BEFORE,
			nights: 0,
		};
	}

	const nights = countNights( checkIn, checkOut );
	if ( nights > maxNights ) {
		return {
			isComplete: false,
			error: STAY_ERROR_TOO_MANY_NIGHTS,
			nights,
		};
	}

	// サーバーが受け付ける年の上限（2100 年）を越える日程は、エラーにはせず絞り込みに使わない。
	return {
		isComplete: checkOut <= FILTER_DATE_MAX,
		error: '',
		nights,
	};
};

/**
 * チェックアウト日として選べる範囲（チェックイン日の翌日〜チェックイン日 + 最大泊数）を返す。
 *
 * @param {string} checkIn   チェックイン日。
 * @param {number} maxNights 1 回に予約できる最大泊数。
 * @return {{min: string, max: string}} 範囲。チェックイン日が使えないときは両方空文字。
 */
export const getCheckOutRange = ( checkIn, maxNights = STAY_MAX_NIGHTS ) =>
	isValidIsoDate( checkIn )
		? { min: addDays( checkIn, 1 ), max: addDays( checkIn, maxNights ) }
		: { min: '', max: '' };

/**
 * チェックイン日を変えたときの、次のチェックイン日・チェックアウト日を返す。
 *
 * - チェックアウト日が空なら、翌日を自動で入れる。
 * - チェックアウト日が入っていて、泊数が 1 泊以上で上限以内なら、その泊数を保ってチェックアウト日を動かす。
 * - それ以外（矛盾している・チェックイン日がまだ無かった）は、チェックアウト日を変えない。
 * - 使えないチェックイン日（空・実在しない日付）は、チェックイン日を空にしてチェックアウト日はそのままにする。
 *
 * @param {{checkIn: string, checkOut: string}} stay        今のチェックイン日とチェックアウト日。
 * @param {string}                              nextCheckIn 新しいチェックイン日。
 * @param {number}                              maxNights   1 回に予約できる最大泊数。
 * @return {{checkIn: string, checkOut: string}} 次のチェックイン日とチェックアウト日。
 */
export const applyCheckInChange = (
	stay,
	nextCheckIn,
	maxNights = STAY_MAX_NIGHTS
) => {
	if ( ! isValidIsoDate( nextCheckIn ) ) {
		return { checkIn: '', checkOut: stay.checkOut };
	}

	if ( ! stay.checkOut ) {
		return { checkIn: nextCheckIn, checkOut: addDays( nextCheckIn, 1 ) };
	}

	const nights = countNights( stay.checkIn, stay.checkOut );
	if ( nights >= 1 && nights <= maxNights ) {
		return {
			checkIn: nextCheckIn,
			checkOut: addDays( nextCheckIn, nights ),
		};
	}

	return { checkIn: nextCheckIn, checkOut: stay.checkOut };
};

/**
 * チェックアウト日を変えたときの、次のチェックイン日・チェックアウト日を返す。
 *
 * チェックイン日はそのまま。使えないチェックアウト日（空・実在しない日付）は空にする。
 *
 * @param {{checkIn: string, checkOut: string}} stay         今のチェックイン日とチェックアウト日。
 * @param {string}                              nextCheckOut 新しいチェックアウト日。
 * @return {{checkIn: string, checkOut: string}} 次のチェックイン日とチェックアウト日。
 */
export const applyCheckOutChange = ( stay, nextCheckOut ) => ( {
	checkIn: stay.checkIn,
	checkOut: isValidIsoDate( nextCheckOut ) ? nextCheckOut : '',
} );

/**
 * 泊数を変えたときの、次のチェックイン日・チェックアウト日を返す。
 *
 * チェックイン日からその泊数後をチェックアウト日にする。チェックイン日が無い、または泊数が 1 未満なら変えない。
 *
 * @param {{checkIn: string, checkOut: string}} stay   今のチェックイン日とチェックアウト日。
 * @param {number}                              nights 新しい泊数。
 * @return {{checkIn: string, checkOut: string}} 次のチェックイン日とチェックアウト日。
 */
export const applyNightsChange = ( stay, nights ) => {
	if ( ! isValidIsoDate( stay.checkIn ) || ! ( nights >= 1 ) ) {
		return stay;
	}

	return { checkIn: stay.checkIn, checkOut: addDays( stay.checkIn, nights ) };
};

/**
 * メニューを選んだときに、宿泊の日程選択へ引き継ぐチェックイン日・チェックアウト日を決める。
 *
 * 絞り込みに使える日程（validateStay が isComplete）のときだけ引き継ぎ、それ以外は両方空にする。
 *
 * @param {{checkIn: string, checkOut: string}} stay      絞り込み検索で選んだ日程。
 * @param {number}                              maxNights 1 回に予約できる最大泊数。
 * @return {{checkIn: string, checkOut: string}} 引き継ぐ日程。
 */
export const resolveCarriedStay = ( stay, maxNights = STAY_MAX_NIGHTS ) =>
	validateStay( stay, maxNights ).isComplete
		? { checkIn: stay.checkIn, checkOut: stay.checkOut }
		: { checkIn: '', checkOut: '' };

/**
 * URL から受け取ったチェックイン日・チェックアウト日を、絞り込み検索の初期値に使える日程へ整える。
 *
 * チェックイン日が今日以降の実在日で、チェックアウト日と矛盾が無い（validateStay が isComplete）ときだけ使い、
 * それ以外（過去日・実在しない日付・矛盾・片方だけ）は両方空にする。
 *
 * @param {string} checkIn   URL の date パラメーター。
 * @param {string} checkOut  URL の check_out_date パラメーター。
 * @param {string} todayIso  今日の日付（'YYYY-MM-DD'）。
 * @param {number} maxNights 1 回に予約できる最大泊数。
 * @return {{checkIn: string, checkOut: string}} 初期値に使う日程。
 */
export const resolveIncomingStay = (
	checkIn,
	checkOut,
	todayIso,
	maxNights = STAY_MAX_NIGHTS
) => {
	const normalizedCheckIn = normalizeFilterDate( checkIn, todayIso );
	if ( ! normalizedCheckIn ) {
		return { checkIn: '', checkOut: '' };
	}

	return resolveCarriedStay(
		{ checkIn: normalizedCheckIn, checkOut },
		maxNights
	);
};

/**
 * URL から受け取った日付が、実在するが今日より前の日付かを返す。
 *
 * 過去日は絞り込みの初期値としては捨てるため、「選んでいた日付は過ぎています」の案内を出す判断に使う。
 *
 * @param {string} value    URL の date パラメーター。
 * @param {string} todayIso 今日の日付（'YYYY-MM-DD'）。
 * @return {boolean} 実在する過去日なら true。
 */
export const isPastIncomingDate = ( value, todayIso ) =>
	typeof value === 'string' && isValidIsoDate( value ) && value < todayIso;
