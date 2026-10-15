/* eslint-disable jsx-a11y/no-redundant-roles -- display: contents の li はブラウザによってリストの意味が失われるため、role を明示する（lodging.scss の .vkbm-lodging__night）。 */
import { useId } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { formatCurrency } from '../../shared/pricing';
import { parseIsoDate, getWeekday } from './stay-dates';
import './lodging.scss';

/**
 * 泊ごとの区分を要約に切り替える泊数（8 泊以上で要約にする）。
 */
export const NIGHT_SUMMARY_THRESHOLD = 8;

/**
 * 料金区分の表示順（要約の並び）。
 */
const RATE_ORDER = [ 'weekday', 'pre_holiday', 'special' ];

/**
 * 料金区分の利用者向けの名称を返す。
 *
 * 特定日に名称があれば「特定日（年末年始）」のように添える。
 *
 * @param {string} rate         料金区分（weekday / pre_holiday / special）。
 * @param {string} specialLabel 特定日の名称（任意）。
 * @return {string} 区分の名称。
 */
export const getRateLabel = ( rate, specialLabel = '' ) => {
	if ( rate === 'special' ) {
		return specialLabel
			? sprintf(
					/* translators: %s: name of the special date (e.g. New Year holidays). */
					__( 'Special date (%s)', 'vk-booking-manager' ),
					specialLabel
			  )
			: __( 'Special date', 'vk-booking-manager' );
	}

	if ( rate === 'pre_holiday' ) {
		return __( 'Pre-holiday', 'vk-booking-manager' );
	}

	return __( 'Regular', 'vk-booking-manager' );
};

/**
 * 泊の日付を「10/10（土）」の形にする。
 *
 * @param {string}           iso    泊の日付（YYYY-MM-DD）。
 * @param {string|undefined} locale WordPress のロケール。
 * @return {string} 表示用の日付。日付が不正なら入力をそのまま返す。
 */
export const formatNightDate = ( iso, locale ) => {
	const parts = parseIsoDate( iso );
	if ( ! parts ) {
		return iso || '';
	}

	const bcp47 =
		typeof locale === 'string' && locale !== ''
			? locale.replace( '_', '-' )
			: 'en';

	let weekday;
	try {
		weekday = new Intl.DateTimeFormat( bcp47, {
			timeZone: 'UTC',
			weekday: 'short',
		} ).format( new Date( Date.UTC( 2023, 0, 1 + getWeekday( iso ) ) ) );
	} catch ( error ) {
		return `${ parts.month }/${ parts.day }`;
	}

	return sprintf(
		/* translators: 1: month and day (e.g. 10/10), 2: weekday (e.g. Sat). */
		__( '%1$s (%2$s)', 'vk-booking-manager' ),
		`${ parts.month }/${ parts.day }`,
		weekday
	);
};

/**
 * 泊ごとの区分を数えて「平日 5泊・休前日 2泊・特定日 1泊」の形の要約にする。
 *
 * @param {Array} nights 泊の一覧（rate を持つ）。
 * @return {string} 要約。泊が無ければ空文字。
 */
export const summarizeRates = ( nights ) => {
	const parts = RATE_ORDER.map( ( rate ) => {
		const count = nights.filter( ( night ) => night.rate === rate ).length;
		if ( count < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: 1: number of nights, 2: rate name (e.g. Pre-holiday). */
			_n(
				'%2$s %1$d night',
				'%2$s %1$d nights',
				count,
				'vk-booking-manager'
			),
			count,
			getRateLabel( rate )
		);
	} ).filter( Boolean );

	// 区切りの前後の空白も翻訳に含める（日本語は「・」、英語は「, 」）。
	return parts.join(
		// eslint-disable-next-line @wordpress/i18n-no-flanking-whitespace
		_x( ', ', 'separator between nights per rate', 'vk-booking-manager' )
	);
};

/**
 * 泊ごとの内訳（金額）に、泊ごとの区分（特定日の名称を含む）を日付でひも付ける。
 *
 * 内訳（night_breakdown）は予約に保存する形のまま使うため、特定日の名称は別の配列（night_rates）で受け取る。
 *
 * @param {Array} breakdown  泊ごとの内訳（date / rate / plan_price / room_surcharge / price）。
 * @param {Array} nightRates 泊ごとの区分（date / rate / special_label）。
 * @return {Array} 特定日の名称（special_label）を足した内訳。
 */
export const attachSpecialLabels = ( breakdown, nightRates ) => {
	const labels = {};
	( Array.isArray( nightRates ) ? nightRates : [] ).forEach( ( night ) => {
		labels[ night.date ] = night.special_label || '';
	} );

	return ( Array.isArray( breakdown ) ? breakdown : [] ).map( ( night ) => ( {
		...night,
		special_label: labels[ night.date ] || '',
	} ) );
};

/**
 * 区分の表示。特定日は「特定日」と「（名称）」を折り返さない塊に分け、境目でだけ折り返す。
 *
 * 翻訳文字列（「特定日（%s）」）の形は変えず、完成した文から「特定日」の後ろを名称側の塊として切り出す。
 * 名称が列幅を超えるほど長い場合は、名称側の塊の中で折り返せる（lodging.scss の .vkbm-lodging__rate-name）。
 *
 * @param {Object} props
 * @param {string} props.rate         区分（regular / pre_holiday / special）。
 * @param {string} props.specialLabel 特定日の名称。
 * @return {JSX.Element|string} 区分の表示。
 */
export const RateLabel = ( { rate, specialLabel } ) => {
	const full = getRateLabel( rate, specialLabel );
	if ( rate !== 'special' || ! specialLabel ) {
		return full;
	}

	const base = getRateLabel( 'special' );
	if ( ! full.startsWith( base ) ) {
		return full;
	}

	const rest = full.slice( base.length );
	const name = rest.trimStart();
	return (
		<>
			<span className="vkbm-lodging__rate-chunk">{ base }</span>
			{ rest.length > name.length && ' ' }
			<span className="vkbm-lodging__rate-chunk vkbm-lodging__rate-name">
				{ name }
			</span>
		</>
	);
};

/**
 * 泊ごとの区分の一覧（日付と区分）。
 *
 * @param {Object}           props
 * @param {Array}            props.nights 泊の一覧（date / rate / special_label）。
 * @param {string|undefined} props.locale WordPress のロケール。
 * @return {JSX.Element} 一覧。
 */
const NightRateItems = ( { nights, locale } ) => (
	<ul className="vkbm-lodging__nights" role="list">
		{ nights.map( ( night ) => (
			<li
				key={ night.date }
				className="vkbm-lodging__night"
				role="listitem"
			>
				<span className="vkbm-lodging__night-date">
					{ formatNightDate( night.date, locale ) }
				</span>
				<span className="vkbm-lodging__night-rate">
					<RateLabel
						rate={ night.rate }
						specialLabel={ night.special_label }
					/>
				</span>
			</li>
		) ) }
	</ul>
);

/**
 * 日程を選んだ直後に、客室一覧の上へ出す泊ごとの区分。
 *
 * 8 泊以上は「平日 5泊・休前日 2泊・特定日 1泊」の要約にして、日ごとの一覧は開閉式にする。
 *
 * @param {Object}           props
 * @param {Array}            props.nights 泊の一覧（date / rate / special_label）。
 * @param {string|undefined} props.locale WordPress のロケール。
 * @return {JSX.Element|null} 泊ごとの区分。泊が無ければ null。
 */
export const NightRates = ( { nights, locale } ) => {
	if ( ! Array.isArray( nights ) || nights.length === 0 ) {
		return null;
	}

	const isSummarized = nights.length >= NIGHT_SUMMARY_THRESHOLD;

	return (
		<section className="vkbm-lodging__night-rates">
			<h3 className="vkbm-lodging__heading">
				{ __( 'Rate category for each night', 'vk-booking-manager' ) }
			</h3>
			{ isSummarized ? (
				<>
					<p className="vkbm-lodging__night-summary">
						{ summarizeRates( nights ) }
					</p>
					<details className="vkbm-lodging__details">
						<summary>
							{ __( 'See each night', 'vk-booking-manager' ) }
						</summary>
						<NightRateItems nights={ nights } locale={ locale } />
					</details>
				</>
			) : (
				<NightRateItems nights={ nights } locale={ locale } />
			) }
		</section>
	);
};

/**
 * 泊ごとの内訳（日付・区分・単価）。
 *
 * 単価は部屋全体の 1 泊分（プラン料金＋客室の追加料金）。客室の追加料金があるときは内訳も添える。
 * 宿泊のサイトでは客室の追加料金は常に 0 円（#736）のため、通常は内訳に追加料金は出ない。
 *
 * @param {Object}           props
 * @param {Array}            props.nights         泊の内訳（date / rate / plan_price / room_surcharge / price）。
 * @param {Array}            props.nightRates     泊ごとの区分（date / rate / special_label）。特定日の名称を添えるために使う。
 * @param {string|undefined} props.locale         WordPress のロケール。
 * @param {?string}          props.currencySymbol 通貨記号。
 * @return {JSX.Element|null} 内訳。泊が無ければ null。
 */
export const NightBreakdownItems = ( {
	nights: breakdown,
	nightRates,
	locale,
	currencySymbol,
} ) => {
	const nights = attachSpecialLabels( breakdown, nightRates );
	if ( nights.length === 0 ) {
		return null;
	}

	return (
		<ul
			className="vkbm-lodging__nights vkbm-lodging__nights--priced"
			role="list"
		>
			{ nights.map( ( night ) => (
				<li
					key={ night.date }
					className="vkbm-lodging__night"
					role="listitem"
				>
					<span className="vkbm-lodging__night-date">
						{ formatNightDate( night.date, locale ) }
					</span>
					<span className="vkbm-lodging__night-rate">
						<RateLabel
							rate={ night.rate }
							specialLabel={ night.special_label }
						/>
					</span>
					<span className="vkbm-lodging__night-price">
						{ formatCurrency( night.price, currencySymbol ) }
					</span>
					{ Number( night.room_surcharge ) > 0 && (
						<span className="vkbm-lodging__night-note">
							{ sprintf(
								/* translators: 1: plan price per night, 2: room surcharge per night. */
								__(
									'Plan price %1$s + room surcharge %2$s',
									'vk-booking-manager'
								),
								formatCurrency(
									night.plan_price,
									currencySymbol
								),
								formatCurrency(
									night.room_surcharge,
									currencySymbol
								)
							) }
						</span>
					) }
				</li>
			) ) }
		</ul>
	);
};

/**
 * 客室を選んだ後のまとめ欄に出す、泊ごとの内訳。畳まずに常に表示する（確認画面の内訳と同じ）。
 *
 * @param {Object}           props
 * @param {Array}            props.nights         泊の内訳。
 * @param {Array}            props.nightRates     泊ごとの区分（特定日の名称を添えるために使う）。
 * @param {string|undefined} props.locale         WordPress のロケール。
 * @param {?string}          props.currencySymbol 通貨記号。
 * @return {JSX.Element|null} 内訳。泊が無ければ null。
 */
export const NightBreakdown = ( {
	nights,
	nightRates,
	locale,
	currencySymbol,
} ) => {
	// 1 ページに予約ブロックが複数あっても id が重ならないようにする。
	const labelId = useId();
	if ( ! Array.isArray( nights ) || nights.length === 0 ) {
		return null;
	}

	return (
		<div
			className="vkbm-lodging__breakdown"
			role="group"
			aria-labelledby={ labelId }
		>
			<p id={ labelId } className="vkbm-lodging__breakdown-label">
				{ __( 'Breakdown by night', 'vk-booking-manager' ) }
			</p>
			<NightBreakdownItems
				nights={ nights }
				nightRates={ nightRates }
				locale={ locale }
				currencySymbol={ currencySymbol }
			/>
		</div>
	);
};
