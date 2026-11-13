const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * メニューの提供期間の開始日・終了日を返す（REST のメニューの meta から読む）。
 *
 * Y-m-d として読めない値は、その側を無期限（空文字）として扱う。
 *
 * @param {Object} menu REST のサービスメニュー。
 * @return {{start: string, end: string}} 開始日と終了日（YYYY-MM-DD。空文字は無期限）。
 */
export const getServicePeriod = ( menu ) => {
	const toIso = ( value ) =>
		typeof value === 'string' && ISO_DATE_PATTERN.test( value )
			? value
			: '';

	return {
		start: toIso( menu?.meta?._vkbm_service_period_start ),
		end: toIso( menu?.meta?._vkbm_service_period_end ),
	};
};

/**
 * メニューの提供期間が終了済み（終了日が今日より前）かを返す。
 *
 * @param {Object} menu  REST のサービスメニュー。
 * @param {string} today 今日の日付（YYYY-MM-DD。サイトのタイムゾーン）。
 * @return {boolean} 終了済みなら true。
 */
export const isServicePeriodEnded = ( menu, today ) => {
	const { end } = getServicePeriod( menu );

	return end !== '' && today !== '' && end < today;
};

/**
 * 提供期間の開始日が今日より後のとき、その開始日の年月を返す（カレンダーの初期表示月に使う）。
 *
 * @param {Object} menu  REST のサービスメニュー。
 * @param {string} today 今日の日付（YYYY-MM-DD。サイトのタイムゾーン）。
 * @return {{year: number, month: number}|null} 開始日の年月。開始日が無い・今日以前なら null。
 */
export const getUpcomingStartMonth = ( menu, today ) => {
	const { start } = getServicePeriod( menu );
	if ( start === '' || today === '' || start <= today ) {
		return null;
	}

	return {
		year: Number( start.slice( 0, 4 ) ),
		month: Number( start.slice( 5, 7 ) ),
	};
};

/**
 * 時間枠のカレンダー API（calendar-meta）が受け付ける最大の年（サーバー側の検証と同じ値）。
 */
export const TIME_SLOT_CALENDAR_MAX_YEAR = 2100;

/**
 * 宿泊カレンダー API（lodging-calendar-meta）が、今年から何年先まで受け付けるか（サーバー側の検証と同じ値）。
 */
export const LODGING_CALENDAR_MAX_YEARS_AHEAD = 2;

/**
 * 提供期間の開始月が、カレンダー API で取得できる範囲（最大の年まで）に収まるときだけ、その開始月を返す。
 *
 * 範囲より先の月へ表示を動かすと API がエラーになり、次の月へも進めなくなるため、範囲外なら null を返して移動させない。
 *
 * @param {?{year: number, month: number}} startMonth 提供期間の開始日の年月（なければ null）。
 * @param {number}                         maxYear    取得できる最大の年。
 * @return {?{year: number, month: number}} 取得できる範囲内なら開始月、範囲外・なしなら null。
 */
export const getFetchableStartMonth = ( startMonth, maxYear ) =>
	startMonth && startMonth.year <= maxYear ? startMonth : null;

/**
 * 時間枠のカレンダーを、提供期間の開始月から開くべきときの開始月を返す。
 *
 * メニューがあり、宿泊の泊プランではなく、開始日が今日より後で、開始月がカレンダー API で取得できる範囲内のときだけ開始月を返す。
 * 宿泊の泊プランは、宿泊用の画面が自分の初期表示月を決めるため対象外。
 *
 * @param {?Object} menu  REST のサービスメニュー（未選択なら null）。
 * @param {string}  today 今日の日付（YYYY-MM-DD。サイトのタイムゾーン）。
 * @return {?{year: number, month: number}} 開始月。該当しなければ null。
 */
export const getCalendarServicePeriodStartMonth = ( menu, today ) =>
	menu && ! menu.vkbm_lodging
		? getFetchableStartMonth(
				getUpcomingStartMonth( menu, today ),
				TIME_SLOT_CALENDAR_MAX_YEAR
		  )
		: null;

/**
 * 表示月が提供期間の開始月より前なら、開始月を返す（そうでなければ表示月をそのまま返す）。
 *
 * メニューを選んだ（選び直した）ときのカレンダーの表示月を決めるのに使う。
 * 開始月が無いとき（開始日が先でないメニュー・宿泊の泊プラン・取得範囲外）は、表示月を動かさない。
 *
 * @param {{year: number, month: number}}  month      表示月の候補。
 * @param {?{year: number, month: number}} startMonth 提供期間の開始月（なければ null）。
 * @return {{year: number, month: number}} 表示月。
 */
export const advanceToServicePeriodStart = ( month, startMonth ) =>
	startMonth &&
	month.year * 12 + month.month < startMonth.year * 12 + startMonth.month
		? startMonth
		: month;

/**
 * メニューの選択肢から、提供期間が終了したメニューを外す。
 *
 * 選択中のメニュー（selectedMenuId に一致するもの）だけは、終了済みでも残す（選択中のメニューが消えないように）。
 *
 * @param {Object[]}      menus          REST のサービスメニューの配列。
 * @param {string|number} selectedMenuId 選択中のメニュー ID（未選択なら空・0）。
 * @param {string}        today          今日の日付（YYYY-MM-DD。サイトのタイムゾーン）。
 * @return {Object[]} 選択肢に出すメニュー。
 */
export const filterSelectableMenus = ( menus, selectedMenuId, today ) =>
	menus.filter(
		( menu ) =>
			( selectedMenuId &&
				String( menu.id ) === String( selectedMenuId ) ) ||
			! isServicePeriodEnded( menu, today )
	);

/**
 * 要求の新旧を判定する小さな仕組みを作る（古い応答で新しい表示を上書きしないために使う）。
 *
 * begin() を呼ぶたびに識別子が進み、返った isLatest() は、その要求より後に begin() が呼ばれていなければ true を返す。
 *
 * @return {{begin: () => () => boolean}} begin() は、その要求が最新かを返す関数を返す。
 */
export const createLatestRequestGuard = () => {
	let currentId = 0;

	return {
		begin: () => {
			currentId += 1;
			const requestId = currentId;

			return () => requestId === currentId;
		},
	};
};

/**
 * 1日のみのメニューで初期選択する開催日を返す。
 *
 * @param {Object} menu メニュー。
 * @return {string} 開催日。対象外なら空文字。
 */
export const getSingleEventDate = ( menu ) =>
	menu?.meta?._vkbm_service_period_mode === 'single' && ! menu?.vkbm_lodging
		? getServicePeriod( menu ).start
		: '';

/**
 * 開催日に予約可能な時間枠が1つだけなら、その枠を返す。
 *
 * @param {Array} slots 時間枠。
 * @return {Object|null} 選択する枠。
 */
export const getOnlyBookableSlot = ( slots ) => {
	const bookable = slots.filter(
		( slot ) => slot.remaining > 0 && ! slot.exclusive_closed
	);
	return bookable.length === 1 ? bookable[ 0 ] : null;
};
