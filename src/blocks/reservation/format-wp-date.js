import { dateI18n, __experimentalGetSettings } from '@wordpress/date';

/**
 * 開始・終了の日時を、サイトの日付・時刻の書式で「日付」「時間帯」に分けて文字列にする。
 *
 * @param {string} startAt  開始日時（ISO 8601）。
 * @param {string} endAt    終了日時（ISO 8601）。空なら時間帯は開始時刻だけ。
 * @param {string} timezone WordPress のタイムゾーン名。
 * @return {{date: string, time: string}} 表示用の日付と時間帯。
 */
export const formatBookingDateTimeParts = ( startAt, endAt, timezone ) => {
	if ( ! startAt ) {
		return { date: '', time: '' };
	}

	const dateLabel = formatDate( startAt, timezone, {
		includeTime: false,
		includeDate: true,
		includeWeekday: true,
	} );
	const startTimeLabel = formatDate( startAt, timezone, {
		includeTime: true,
		includeDate: false,
	} );
	const endTimeLabel = endAt
		? formatDate( endAt, timezone, {
				includeTime: true,
				includeDate: false,
		  } )
		: '';
	const timeLabel = endTimeLabel
		? `${ startTimeLabel } - ${ endTimeLabel }`.trim()
		: startTimeLabel;

	return {
		date: dateLabel,
		time: timeLabel,
	};
};

/**
 * 日時を、サイトの日付・時刻の書式で文字列にする。
 *
 * @param {string}  iso                    日時（ISO 8601）。
 * @param {string}  timezone               WordPress のタイムゾーン名。
 * @param {Object}  options                表示する部分の指定。
 * @param {boolean} options.includeTime    時刻を含めるか（既定 true）。
 * @param {boolean} options.includeDate    日付を含めるか（既定 true）。
 * @param {boolean} options.includeWeekday 曜日を含めるか（既定 false）。
 * @return {string} 表示用の文字列。変換できなければ入力をそのまま返す。
 */
export const formatDate = (
	iso,
	timezone,
	{ includeTime = true, includeDate = true, includeWeekday = false } = {}
) => {
	if ( ! iso ) {
		return '';
	}
	try {
		const date = new Date( iso );
		if ( Number.isNaN( date.getTime() ) ) {
			return iso;
		}

		let settings;
		try {
			settings =
				typeof __experimentalGetSettings === 'function'
					? __experimentalGetSettings()
					: undefined;
		} catch ( error ) {
			settings = undefined;
		}

		const dateFormat = settings?.formats?.date || 'Y/m/d';
		const timeFormat = settings?.formats?.time || 'H:i';
		const wpTimezone = timezone || settings?.timezone?.string;

		const datePart = includeDate
			? dateI18n( dateFormat, date, wpTimezone )
			: '';
		const timePart = includeTime
			? dateI18n( timeFormat, date, wpTimezone )
			: '';
		const weekdayPart = includeWeekday
			? dateI18n( 'D', date, wpTimezone )
			: '';

		if ( includeDate && includeTime ) {
			if ( includeWeekday && weekdayPart ) {
				return `${ datePart }(${ weekdayPart }) ${ timePart }`.trim();
			}
			return `${ datePart } ${ timePart }`.trim();
		}

		if ( includeDate ) {
			if ( includeWeekday && weekdayPart ) {
				return `${ datePart }(${ weekdayPart })`.trim();
			}
			return datePart;
		}

		return timePart;
	} catch ( error ) {
		return iso;
	}
};
