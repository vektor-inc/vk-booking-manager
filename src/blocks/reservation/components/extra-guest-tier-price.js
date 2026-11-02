import { buildExtraGuestTierPriceText } from '../pricing-breakdown';

/**
 * 区分ごとの人数欄に添える、追加料金の金額（「+¥3,000」）を出す（#1003）。
 *
 * 「+」と金額を同じ要素の1つの文字列にして、親の折り返さない指定（white-space: nowrap）の中で
 * 「+」だけが次の行に落ちないようにする。画面の表記は読み上げ対象から外し、
 * 読み上げには「追加料金 ¥3,000」の文言を視覚的にのみ隠した要素で添える。
 *
 * @param {Object}      props
 * @param {number}      props.price          超過1名あたりの追加料金。
 * @param {string|null} props.currencySymbol 通貨記号。
 * @return {JSX.Element} 金額の表記。
 */
export const ExtraGuestTierPrice = ( { price, currencySymbol = null } ) => {
	const { visible, spoken } = buildExtraGuestTierPriceText( {
		price,
		currencySymbol,
	} );

	return (
		<>
			<span aria-hidden="true">{ visible }</span>
			<span className="vkbm-plan-summary__visually-hidden">
				{ spoken }
			</span>
		</>
	);
};
