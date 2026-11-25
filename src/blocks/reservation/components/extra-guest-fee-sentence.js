import { Fragment } from '@wordpress/element';
import { joinSentences } from '../booking-ui/join-sentences';

/**
 * 差し込んだ値1つ（「3名」「¥8,500」「大人 ¥2,000」など）を、途中で改行させない要素に入れる。
 *
 * @param {Object} props
 * @param {string} props.children 値の文字。
 * @return {JSX.Element} 折り返さない要素。
 */
const Nowrap = ( { children } ) => (
	<span className="vkbm-plan-summary__nowrap">{ children }</span>
);

/**
 * splitSentenceByValues() で分けた文を出す（#1175）。
 *
 * 差し込んだ値は値ごとに折り返さない要素へ入れ、改行は書式の文字の中か値の境目で起きるようにする。
 *
 * @param {Object}                                                    props
 * @param {Array}                                                     props.parts       splitSentenceByValues() の parts。
 * @param {(part: {text: string, valueIndex: number}) => JSX.Element} props.renderValue 値の部分の出し方（部分を受け取る）。省略時は値全体を折り返さない要素に入れる。
 * @return {JSX.Element} 文。
 */
const SentenceParts = ( { parts, renderValue } ) => (
	<>
		{ parts.map( ( part, index ) => (
			<Fragment key={ index }>
				{ part.valueIndex === null && part.text }
				{ part.valueIndex !== null &&
					( renderValue ? (
						renderValue( part )
					) : (
						<Nowrap>{ part.text }</Nowrap>
					) ) }
			</Fragment>
		) ) }
	</>
);

/**
 * 追加料金の補足文の2文目（「3名を超えた分は、…追加料金がかかります（大人 ¥2,000、子供 ¥1,000）。」）を出す（#1175）。
 *
 * 「3名」と「大人 ¥2,000」の組をそれぞれ折り返さない要素へ入れ、狭い幅でも数字と単位・区分名と金額の間で改行させない。
 * 区分の一覧の中の改行は、組と組の境目（区切り文字の後ろ）で起きる。
 *
 * @param {Object} props
 * @param {Object} props.sentence buildExtraGuestFeeSentence() の戻り値。
 * @return {JSX.Element} 2文目。
 */
export const ExtraGuestFeeSentence = ( { sentence } ) => (
	<SentenceParts
		parts={ sentence.parts }
		renderValue={ ( part ) =>
			// 2つ目の値（区分の一覧）は、組ごとに折り返さない要素へ入れる。
			part.valueIndex === 1 ? (
				sentence.items.map( ( item, index ) => (
					<Fragment key={ index }>
						{ index > 0 && sentence.separator }
						<Nowrap>{ item }</Nowrap>
					</Fragment>
				) )
			) : (
				<Nowrap>{ part.text }</Nowrap>
			)
		}
	/>
);

/**
 * 追加料金を使うプランの料金の補足文（1文目と、区分ごとの追加料金を並べた2文目）を出す（#1175）。
 *
 * @param {Object} props
 * @param {Object} props.note buildPerBookingExtraGuestPriceNote() / buildPerRoomExtraGuestPriceNote() の戻り値（lead / extraFee）。
 * @return {JSX.Element} 補足文。
 */
export const ExtraGuestPriceNote = ( { note } ) => (
	<>
		<SentenceParts parts={ note.lead.parts } />
		{ /* 文と文の間の空白の有無は joinSentences と同じ規則にそろえる（英語は空白あり・日本語はなし）。joinSentences が足す区切りだけを出す。 */ }
		{ joinSentences( note.lead.text, '' ).slice( note.lead.text.length ) }
		<ExtraGuestFeeSentence sentence={ note.extraFee } />
	</>
);
