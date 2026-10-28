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
 * 日付を「2026年10月10日(土)」のような表示用の文字列にする。
 *
 * @param {string}           iso    日付文字列。
 * @param {string|undefined} locale WordPress のロケール（'ja'、'en_US' など）。
 * @return {string} 表示用の日付。日付が不正なら入力をそのまま返す。
 */
export const formatReservationDate = ( iso, locale ) => {
	const parts = parseIsoDate( iso );
	if ( ! parts ) {
		return iso || '';
	}

	const bcp47 =
		typeof locale === 'string' && locale !== ''
			? locale.replace( '_', '-' )
			: 'en';
	const date = new Date( Date.UTC( parts.year, parts.month - 1, parts.day ) );

	try {
		return new Intl.DateTimeFormat( bcp47, {
			timeZone: 'UTC',
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			weekday: 'short',
		} ).format( date );
	} catch ( error ) {
		return iso;
	}
};
