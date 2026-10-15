import { __, _n, sprintf } from '@wordpress/i18n';
import { NightBreakdownItems } from './night-rates';
import './lodging.scss';

/**
 * 確認画面の 1 行（見出しと値）。
 *
 * @param {Object} props
 * @param {*}      props.label          見出し。
 * @param {*}      props.value          値。
 * @param {string} props.valueClassName 値に足す class。
 * @return {JSX.Element} 1 行。
 */
const SummaryRow = ( { label, value, valueClassName } ) => (
	<dl className="vkbm-confirm__summary-item">
		<dt className="vkbm-confirm__summary-item-title">{ label }</dt>
		<dd
			className={ [ 'vkbm-confirm__summary-item-value', valueClassName ]
				.filter( Boolean )
				.join( ' ' ) }
		>
			{ value || '—' }
		</dd>
	</dl>
);

/**
 * 日付と時刻を並べた値。時刻は途中で割れないようにする（日付と時刻の間では折り返してよい）。
 *
 * @param {Object} props
 * @param {Object} props.dateTime 整形済みの日付（date）と時刻（time）。
 * @return {JSX.Element} 日付と時刻。
 */
const DateTimeValue = ( { dateTime } ) => (
	<>
		{ dateTime.date }{ ' ' }
		<span className="vkbm-confirm__time">{ dateTime.time }</span>
	</>
);

/**
 * 宿泊予約の確認画面のサマリ。
 *
 * 先頭に「チェックイン」「チェックアウト」の 2 行（見出しと、日付・時刻の値）を他の行と同じ形で出し、続けて
 * プラン・客室・宿泊人数・泊数・1 泊あたりの料金・合計金額を並べる。日付・時刻・金額は
 * 呼び出し側（確認画面）がサイトの表示形式に整形した文字列を渡す（金額はサーバー計算値）。
 *
 * @param {Object}  props
 * @param {string}  props.menuName           プラン名。
 * @param {string}  props.roomLabel          客室の呼び方（見出し）。
 * @param {string}  props.roomName           客室名。
 * @param {Object}  props.checkIn            チェックイン（date / time を整形済みの文字列で持つ）。
 * @param {Object}  props.checkOut           チェックアウト（date / time を整形済みの文字列で持つ）。
 * @param {number}  props.nights             泊数。
 * @param {string}  props.guestsLabel        人数の見出し。
 * @param {string}  props.guestsText         人数（単位付き）。
 * @param {string}  props.pricePerNightText  1 泊あたりの料金（部屋全体）。空文字なら行を出さない。泊ごとの内訳を出すときは使わない。
 * @param {Array}   props.nightBreakdown     泊ごとの内訳（date / rate / plan_price / room_surcharge / price）。
 * @param {Array}   props.nightRates         泊ごとの区分（date / rate / special_label）。特定日の名称を添えるために使う。
 * @param {boolean} props.isRateTiersEnabled 休前日・特定日の料金を設定したプランか。true のとき「1 泊あたりの料金」の行を泊ごとの内訳（常に開いた状態）に置き換える。
 * @param {string}  props.locale             WordPress のロケール（泊の日付の曜日表記に使う）。
 * @param {?string} props.currencySymbol     通貨記号。
 * @param {string}  props.totalText          合計金額。
 * @return {JSX.Element} サマリ。
 */
const LodgingConfirmSummary = ( {
	menuName,
	roomLabel,
	roomName,
	checkIn,
	checkOut,
	nights,
	guestsLabel,
	guestsText,
	pricePerNightText,
	nightBreakdown,
	nightRates,
	isRateTiersEnabled,
	locale,
	currencySymbol,
	totalText,
} ) => (
	<>
		<SummaryRow
			label={ __( 'Check-in', 'vk-booking-manager' ) }
			value={ <DateTimeValue dateTime={ checkIn } /> }
		/>
		<SummaryRow
			label={ __( 'Check-out', 'vk-booking-manager' ) }
			value={ <DateTimeValue dateTime={ checkOut } /> }
		/>
		<SummaryRow
			label={ __( 'Menu', 'vk-booking-manager' ) }
			value={ menuName }
		/>
		<SummaryRow label={ roomLabel } value={ roomName } />
		<SummaryRow label={ guestsLabel } value={ guestsText } />
		<SummaryRow
			label={ __( 'Number of nights', 'vk-booking-manager' ) }
			value={ sprintf(
				/* translators: %d: number of nights. */
				_n( '%d night', '%d nights', nights, 'vk-booking-manager' ),
				nights
			) }
		/>
		{ isRateTiersEnabled &&
		Array.isArray( nightBreakdown ) &&
		nightBreakdown.length > 0 ? (
			<SummaryRow
				label={ __( 'Rate by night', 'vk-booking-manager' ) }
				value={
					<NightBreakdownItems
						nights={ nightBreakdown }
						nightRates={ nightRates }
						locale={ locale }
						currencySymbol={ currencySymbol }
					/>
				}
			/>
		) : (
			pricePerNightText && (
				<SummaryRow
					label={ __( 'Rate per night', 'vk-booking-manager' ) }
					value={ pricePerNightText }
				/>
			)
		) }
		<SummaryRow
			label={ __( 'Total amount', 'vk-booking-manager' ) }
			value={ totalText }
			valueClassName="vkbm-confirm__summary-item-value--price"
		/>
	</>
);

export default LodgingConfirmSummary;
