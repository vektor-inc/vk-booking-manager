/**
 * 宿泊の予約画面の「チェックイン予定時刻」の選択肢を作る（#1125）。
 *
 * 画面（lodging-booking-panel.js）から切り離した純粋関数だけを置く。刻みは引数で受け取り、
 * 刻みを変えるときは CHECK_IN_TIME_STEP_MINUTES だけを直せば済むようにしている。
 */

/**
 * チェックイン予定時刻の選択肢の刻み（分）。
 */
export const CHECK_IN_TIME_STEP_MINUTES = 30;

/**
 * 最終チェックイン時刻が未設定のとき、サーバーが受け付ける到着予定時刻の終わり（HH:MM）。
 * 選択肢はこの時刻以前の最後の区切り（30 分刻みなら 23:30）までにする。
 */
export const LATEST_ACCEPTED_CHECK_IN_TIME = '23:59';

const TIME_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

/**
 * HH:MM を 0 時からの分に変える。
 *
 * @param {string} time 時刻（HH:MM）。
 * @return {number|null} 分。HH:MM でなければ null。
 */
const toMinutes = ( time ) => {
	const match = typeof time === 'string' ? TIME_PATTERN.exec( time ) : null;
	if ( ! match ) {
		return null;
	}

	return Number( match[ 1 ] ) * 60 + Number( match[ 2 ] );
};

/**
 * 0 時からの分を HH:MM に変える。
 *
 * @param {number} minutes 分（0〜1439）。
 * @return {string} 時刻（HH:MM）。
 */
const toTime = ( minutes ) =>
	`${ String( Math.floor( minutes / 60 ) ).padStart( 2, '0' ) }:${ String(
		minutes % 60
	).padStart( 2, '0' ) }`;

/**
 * 最終チェックイン時刻として使える値かを判定する（チェックイン時刻以降の HH:MM）。
 *
 * @param {string} checkInTime       チェックイン時刻（HH:MM）。
 * @param {string} latestCheckInTime 最終チェックイン時刻（HH:MM）。未設定は空文字。
 * @return {boolean} 使えるなら true。
 */
export const hasLatestCheckInTime = ( checkInTime, latestCheckInTime ) => {
	const start = toMinutes( checkInTime );
	const latest = toMinutes( latestCheckInTime );

	return start !== null && latest !== null && latest >= start;
};

/**
 * チェックイン予定時刻の選択肢を作る。
 *
 * チェックイン時刻 → その間の刻みの区切り（30 分刻みなら :00 / :30）→ 最終チェックイン時刻、の順に並べる。
 * チェックイン時刻・最終チェックイン時刻が区切りでなくても（15:10 など）、両端は必ず選択肢に入れる。
 * 最終チェックイン時刻が未設定（またはチェックイン時刻より前）なら、23:59 以前の最後の区切りまでにする。
 *
 * @param {string} checkInTime       チェックイン時刻（HH:MM）。
 * @param {string} latestCheckInTime 最終チェックイン時刻（HH:MM）。未設定は空文字。
 * @param {number} stepMinutes       刻み（分。1 以上）。
 * @return {string[]} 選択肢の時刻（HH:MM）。チェックイン時刻が不正なら空配列。
 */
export const buildCheckInTimeOptions = (
	checkInTime,
	latestCheckInTime,
	stepMinutes
) => {
	const start = toMinutes( checkInTime );
	const step = Math.floor( Number( stepMinutes ) );
	if ( start === null || ! ( step > 0 ) ) {
		return [];
	}

	// 終わりの時刻を決める。未設定なら受付の終わり（23:59）以前の最後の区切り。
	let end = hasLatestCheckInTime( checkInTime, latestCheckInTime )
		? toMinutes( latestCheckInTime )
		: Math.floor( toMinutes( LATEST_ACCEPTED_CHECK_IN_TIME ) / step ) *
		  step;
	// チェックイン時刻が最後の区切りより後（未設定で 23:45 など）なら、チェックイン時刻だけを選択肢にする。
	end = Math.max( end, start );

	// 先頭はチェックイン時刻。続けて、その次の区切りから終わりの手前までの区切りを並べる。
	const options = [ start ];
	for (
		let minutes = Math.floor( start / step ) * step + step;
		minutes < end;
		minutes += step
	) {
		options.push( minutes );
	}
	// 終わりの時刻（最終チェックイン時刻、または最後の区切り）を末尾に入れる。
	if ( end > start ) {
		options.push( end );
	}

	return options.map( toTime );
};

/**
 * 到着予定時刻が、サーバーが受け付ける範囲（チェックイン時刻〜最終チェックイン時刻。未設定なら 23:59）にあるかを判定する。
 *
 * 刻みの区切りかどうかは見ない（この変更の前に作られた下書きの 16:45 なども範囲内なら残すため）。
 *
 * @param {string} time              到着予定時刻（HH:MM）。
 * @param {string} checkInTime       チェックイン時刻（HH:MM）。
 * @param {string} latestCheckInTime 最終チェックイン時刻（HH:MM）。未設定は空文字。
 * @return {boolean} 範囲内なら true。
 */
export const isCheckInTimeInRange = (
	time,
	checkInTime,
	latestCheckInTime
) => {
	const value = toMinutes( time );
	const start = toMinutes( checkInTime );
	if ( value === null || start === null || value < start ) {
		return false;
	}

	const end = hasLatestCheckInTime( checkInTime, latestCheckInTime )
		? toMinutes( latestCheckInTime )
		: toMinutes( LATEST_ACCEPTED_CHECK_IN_TIME );

	return value <= end;
};

/**
 * 選択肢に無い時刻（下書きから戻した 16:45 など）を、時刻順の位置に加えた選択肢を返す。
 *
 * @param {string[]} options 選択肢の時刻（HH:MM。昇順）。
 * @param {string}   time    加える時刻（HH:MM）。空文字や既にある時刻なら加えない。
 * @return {string[]} 選択肢。加える必要が無ければ渡した配列をそのまま返す。
 */
export const addCheckInTimeOption = ( options, time ) => {
	if ( toMinutes( time ) === null || options.includes( time ) ) {
		return options;
	}

	// HH:MM は文字列の比較で時刻順になる。
	return [ ...options, time ].sort();
};
