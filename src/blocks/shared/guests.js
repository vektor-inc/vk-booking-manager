/**
 * 数量（数値＋単位）を表示用文字列に整形する。
 *
 * PHP 側の vkbm_format_guests_count() と同じスペーシング規則を JS でも再現する。
 * - 単位が空文字（単位なし）の場合は数値のみを返す。
 * - 単位が半角英字で始まる場合のみ、数値との間に半角スペースを入れる（例: 5 guests）。
 * - 日本語など全角の単位は数値と詰めて返す（例: 5名 / 5台）。
 *
 * @param {number} count 数量。
 * @param {string} unit  単位（実効値）。空文字は単位なしを意味する。
 * @return {string} 整形済みの数量文字列。
 */
export const formatGuestsCount = ( count, unit = '' ) => {
	const numeric = Number( count );

	// 単位なしの場合は数値のみを返す。
	if ( typeof unit !== 'string' || unit === '' ) {
		return String( numeric );
	}

	// 単位が半角英字で始まる場合のみ、数値との間に半角スペースを入れる。
	const separator = /^[A-Za-z]/.test( unit ) ? ' ' : '';

	return `${ numeric }${ separator }${ unit }`;
};
