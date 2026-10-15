/**
 * 翻訳済みの2文を、文末が非 ASCII（日本語の句点「。」等）のときは半角スペース無しで、
 * 文末が ASCII のとき（英語など）は半角スペース区切りで連結する（#393）。
 *
 * 個別の記号を列挙する方式（句点・感嘆符・閉じ括弧…）だと、想定していない記号終わりの文で
 * 一貫しない挙動になる（例: 半角の閉じ括弧を列挙に含めると `…(bar)Please set…` のように
 * スペース無しで連結されてしまう）ため、末尾1文字が ASCII かどうかだけで判定する
 * （安藤レビュー指摘）。
 *
 * 翻訳済み文字列そのものに前後の空白を含めると
 * `@wordpress/i18n-no-flanking-whitespace` の ESLint ルールに反するため、
 * 結合はコード側（翻訳を通さない箇所）で行う。PHP側
 * （src/common/class-nomination-min-guests-message.php の join_sentences()）と同型のロジック。
 *
 * @param {string} sentenceA 1文目（すでに翻訳・sprintf済み）。
 * @param {string} sentenceB 2文目（すでに翻訳・sprintf済み）。
 * @return {string} 連結後の文字列。
 */
export const joinSentences = ( sentenceA, sentenceB ) => {
	const endsWithAscii = /[\x00-\x7F]$/.test( sentenceA );
	return endsWithAscii ? sentenceA + ' ' + sentenceB : sentenceA + sentenceB;
};
