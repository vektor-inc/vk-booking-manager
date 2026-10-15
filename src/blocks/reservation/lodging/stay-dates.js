/**
 * 宿泊用の画面で使う日付の計算（チェックイン日・チェックアウト日の範囲選択）。
 *
 * 日付はすべて 'YYYY-MM-DD' の文字列で持ち、new Date( 文字列 ) では作らない
 * （ブラウザのタイムゾーンで日付がずれるのを防ぐため）。日数の計算は UTC の暦で行う。
 */

const MS_PER_DAY = 24 * 60 * 60 * 1000;

const padZero = ( value ) => String( value ).padStart( 2, '0' );

/**
 * 'YYYY-MM-DD' を年月日に分解する。
 *
 * @param {string} iso 日付文字列。
 * @return {{year: number, month: number, day: number}|null} 年月日。形式が違えば null。
 */
export const parseIsoDate = ( iso ) => {
	const matched = /^(\d{4})-(\d{2})-(\d{2})$/.exec( iso || '' );
	if ( ! matched ) {
		return null;
	}

	return {
		year: Number( matched[ 1 ] ),
		month: Number( matched[ 2 ] ),
		day: Number( matched[ 3 ] ),
	};
};

/**
 * UTC の Date を 'YYYY-MM-DD' にする。
 *
 * @param {Date} date UTC の暦で扱う Date。
 * @return {string} 日付文字列。
 */
const utcDateToIso = ( date ) =>
	[
		date.getUTCFullYear(),
		padZero( date.getUTCMonth() + 1 ),
		padZero( date.getUTCDate() ),
	].join( '-' );

/**
 * 年月日から UTC の Date を作る。
 *
 * @param {{year: number, month: number, day: number}} parts 年月日。
 * @return {Date} その日の UTC 0 時の Date。
 */
const partsToUtcDate = ( { year, month, day } ) =>
	new Date( Date.UTC( year, month - 1, day ) );

/**
 * 日付に日数を足す（負の値は引く）。
 *
 * @param {string} iso  日付文字列。
 * @param {number} days 足す日数。
 * @return {string} 計算後の日付文字列。入力が不正なら空文字。
 */
export const addDays = ( iso, days ) => {
	const parts = parseIsoDate( iso );
	if ( ! parts ) {
		return '';
	}

	return utcDateToIso(
		new Date( partsToUtcDate( parts ).getTime() + days * MS_PER_DAY )
	);
};

/**
 * 日付を月単位で動かす。移動先の月に同じ日が無ければ、その月の末日にする。
 *
 * @param {string} iso    日付文字列。
 * @param {number} months 動かす月数（負の値は前の月）。
 * @return {string} 動かした後の日付文字列。入力が不正なら空文字。
 */
export const addMonths = ( iso, months ) => {
	const parts = parseIsoDate( iso );
	if ( ! parts ) {
		return '';
	}

	const target = shiftMonth( parts, months );
	// Date.UTC の日に 0 を渡すと前の月の末日になるため、翌月の 0 日で移動先の月の日数を求める。
	const daysInTargetMonth = new Date(
		Date.UTC( target.year, target.month, 0 )
	).getUTCDate();

	return utcDateToIso(
		new Date(
			Date.UTC(
				target.year,
				target.month - 1,
				Math.min( parts.day, daysInTargetMonth )
			)
		)
	);
};

/**
 * チェックイン日からチェックアウト日までの泊数を返す。
 *
 * @param {string} checkIn  チェックイン日。
 * @param {string} checkOut チェックアウト日。
 * @return {number} 泊数。日付が不正・チェックアウトがチェックイン以前なら 0。
 */
export const countNights = ( checkIn, checkOut ) => {
	const from = parseIsoDate( checkIn );
	const to = parseIsoDate( checkOut );
	if ( ! from || ! to ) {
		return 0;
	}

	const nights = Math.round(
		( partsToUtcDate( to ).getTime() - partsToUtcDate( from ).getTime() ) /
			MS_PER_DAY
	);

	return Math.max( 0, nights );
};

/**
 * 日付の曜日（0: 日曜〜6: 土曜）を返す。
 *
 * @param {string} iso 日付文字列。
 * @return {number} 曜日。日付が不正なら 0。
 */
export const getWeekday = ( iso ) => {
	const parts = parseIsoDate( iso );
	return parts ? partsToUtcDate( parts ).getUTCDay() : 0;
};

/**
 * ブラウザの現在日付（端末のローカル日付）を 'YYYY-MM-DD' で返す。
 *
 * @param {Date} now 現在時刻（テストで差し替える）。
 * @return {string} 今日の日付文字列。
 */
export const getTodayIso = ( now = new Date() ) =>
	[
		now.getFullYear(),
		padZero( now.getMonth() + 1 ),
		padZero( now.getDate() ),
	].join( '-' );

/**
 * 表示する月を前後に動かす。
 *
 * @param {{year: number, month: number}} cursor 表示中の年月。
 * @param {number}                        delta  動かす月数。
 * @return {{year: number, month: number}} 動かした後の年月。
 */
export const shiftMonth = ( { year, month }, delta ) => {
	const index = year * 12 + ( month - 1 ) + delta;
	return { year: Math.floor( index / 12 ), month: ( index % 12 ) + 1 };
};

/**
 * 月のカレンダー（日曜始まり・6 週）を作る。
 *
 * @param {number} year  年。
 * @param {number} month 月（1〜12）。
 * @return {Array<Array<{iso: string, label: number, inMonth: boolean}>>} 週ごとの日の配列。
 */
export const buildMonthWeeks = ( year, month ) => {
	const firstIso = utcDateToIso( new Date( Date.UTC( year, month - 1, 1 ) ) );
	const startIso = addDays( firstIso, -getWeekday( firstIso ) );
	const weeks = [];

	for ( let week = 0; week < 6; week += 1 ) {
		const days = [];
		for ( let day = 0; day < 7; day += 1 ) {
			const iso = addDays( startIso, week * 7 + day );
			const parts = parseIsoDate( iso );
			days.push( {
				iso,
				label: parts.day,
				inMonth: parts.month === month,
			} );
		}
		weeks.push( days );
	}

	return weeks;
};

/**
 * 日付を「2026年10月10日(土)」のような表示用の文字列にする。
 *
 * @param {string}           iso    日付文字列。
 * @param {string|undefined} locale WordPress のロケール（'ja'、'en_US' など）。
 * @return {string} 表示用の日付。日付が不正なら入力をそのまま返す。
 */
export const formatStayDate = ( iso, locale ) => {
	const parts = parseIsoDate( iso );
	if ( ! parts ) {
		return iso || '';
	}

	const bcp47 =
		typeof locale === 'string' && locale !== ''
			? locale.replace( '_', '-' )
			: 'en';

	try {
		return new Intl.DateTimeFormat( bcp47, {
			timeZone: 'UTC',
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			weekday: 'short',
		} ).format( partsToUtcDate( parts ) );
	} catch ( error ) {
		return iso;
	}
};

/**
 * 月の見出し（「2026年10月」など）を作る。
 *
 * @param {number}           year   年。
 * @param {number}           month  月（1〜12）。
 * @param {string|undefined} locale WordPress のロケール。
 * @return {string} 月の見出し。
 */
export const formatStayMonth = ( year, month, locale ) => {
	const bcp47 =
		typeof locale === 'string' && locale !== ''
			? locale.replace( '_', '-' )
			: 'en';

	try {
		return new Intl.DateTimeFormat( bcp47, {
			timeZone: 'UTC',
			year: 'numeric',
			month: 'long',
		} ).format( new Date( Date.UTC( year, month - 1, 1 ) ) );
	} catch ( error ) {
		return `${ year }-${ padZero( month ) }`;
	}
};

/**
 * 日付を押したときの、チェックイン日・チェックアウト日の次の状態を返す。
 *
 * - 何も選んでいない／チェックアウトまで選び終えている: 押した日を新しいチェックイン日にする（選び直し）
 * - チェックインだけ選んでいる: チェックイン日より後なら押した日をチェックアウト日にする。
 *   チェックイン日と同じ日・より前の日なら、押した日を新しいチェックイン日にする（同じ日の宿泊は無い）
 *
 * チェックアウト日は販売日でなくても満室でも選べる（泊まるのはチェックアウト前日の夜まで）。
 *
 * @param {{checkIn: string, checkOut: string}} range 今の選択。
 * @param {string}                              iso   押した日。
 * @return {{checkIn: string, checkOut: string}} 次の選択。
 */
export const applyDateClick = ( range, iso ) => {
	const { checkIn, checkOut } = range;

	if ( ! checkIn || checkOut || iso <= checkIn ) {
		return { checkIn: iso, checkOut: '' };
	}

	return { checkIn, checkOut: iso };
};

/**
 * 日付が選択範囲のどこに当たるかを返す。
 *
 * @param {{checkIn: string, checkOut: string}} range 今の選択。
 * @param {string}                              iso   判定する日。
 * @return {'start'|'end'|'between'|''} 当たらなければ空文字。
 */
export const getRangePosition = ( range, iso ) => {
	const { checkIn, checkOut } = range;
	if ( iso === checkIn ) {
		return 'start';
	}
	if ( checkOut && iso === checkOut ) {
		return 'end';
	}
	if ( checkIn && checkOut && iso > checkIn && iso < checkOut ) {
		return 'between';
	}
	return '';
};
