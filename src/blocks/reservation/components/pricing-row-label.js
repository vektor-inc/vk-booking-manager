/**
 * 料金の行の見出しを、「（単価 × 人数）」の括弧部分で折り返さない形で出す（#503）。
 *
 * 狭い画面で「サービス基本料金（¥15,000 × 2」と「名）」のように括弧の途中で改行されないよう、
 * 括弧部分だけを折り返さない要素に入れる。改行は括弧の手前で起きる。
 * 括弧部分を持たない見出し（labelParts が無い、または detail が空）は、見出しをそのまま出す。
 *
 * @param {Object} props
 * @param {string} props.label      見出し全体。
 * @param {Object} props.labelParts 見出しを本体・括弧部分・括弧より後ろの文字に分けたもの（buildLabelWithDetail の戻り値）。
 * @return {JSX.Element|string} 見出し。
 */
export const PricingRowLabel = ( { label, labelParts } ) => {
	// 括弧部分が無ければ分ける必要が無い。
	if ( ! labelParts?.detail ) {
		return label;
	}

	return (
		<>
			{ labelParts.main }
			<span className="vkbm-pricing-row-label__detail">
				{ labelParts.detail }
			</span>
			{ labelParts.suffix }
		</>
	);
};
