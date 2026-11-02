import { __, sprintf } from '@wordpress/i18n';
import { formatCurrency } from '../shared/pricing';
import { formatGuestsCount } from '../shared/guests';
import { joinSentences } from './booking-ui/join-sentences';

/**
 * 料金計算に使う人数を正規化する。
 *
 * 1未満・数値でない値は1名として扱い、小数は切り捨てる。
 *
 * @param {number} guests 申込人数。
 * @return {number} 1以上の整数の人数。
 */
const normalizeGuestCount = ( guests ) =>
	Math.max( 1, Math.floor( Number( guests ) || 1 ) );

/**
 * 料金欄に出す「基本料金合計」を計算する（#503）。
 *
 * 予約確定時・下書きの計算（Booking_Confirmation_Controller / Booking_Draft_Controller）と同じ式で求める。
 * 料金区分なしは「基本料金 × 人数 ＋ 指名料 ＋ 貸し切り料金」、料金区分あり（tiersSubtotal を渡す）は
 * 「区分小計の合計 ＋ 指名料 ＋ 貸し切り料金」。指名料は人数に関わらず1回分。
 * 複数人一括予約を使わないメニューは人数に1を渡すことで、従来の「基本料金 ＋ 指名料」になる。
 * ここで求めるのは表示用の概算で、実際に保存される金額はサーバー側で再計算される。
 *
 * @param {Object}      args
 * @param {number|null} args.basePrice          1名あたりのサービス基本料金。
 * @param {number}      args.guests             申込人数。
 * @param {number|null} args.nominationFee      指名料。未確定（null）は0円として扱う。
 * @param {number}      args.exclusiveFee       貸し切り料金。
 * @param {number|null} args.tiersSubtotal      料金区分ありのメニューの区分小計の合計。
 *                                              数値を渡すと「基本料金 × 人数」の代わりに使う（サーバーの下書き計算と同じ）。
 * @param {boolean}     args.isPerBooking       料金の単位が「1ツアーあたり」か（#1002）。true のときは人数も料金区分も使わず、
 *                                              基本料金そのままを基本部分にする（サーバーの Booking_Price と同じ）。
 * @param {number}      args.extraGuestSubtotal 1ツアーあたりで、基本料金に含まれる人数を超えた人数分の追加料金の合計（#1003）。
 *                                              isPerBooking のときだけ基本部分に足す。
 * @return {number|null} 基本料金合計。基本料金（区分ありは区分小計）が不明なら null。
 */
export const calculateBaseFeeTotal = ( {
	basePrice,
	guests = 1,
	nominationFee = 0,
	exclusiveFee = 0,
	tiersSubtotal = null,
	isPerBooking = false,
	extraGuestSubtotal = 0,
} ) => {
	// 指名料・貸し切り料金は未確定や不正な値を0円として足す。
	const nomination = Math.max( 0, Number( nominationFee ) || 0 );
	const exclusive = Math.max( 0, Number( exclusiveFee ) || 0 );

	// 1ツアーあたりは基本料金そのまま（人数も料金区分も掛けない）に、超過人数分の追加料金だけを足す。
	// 基本料金が分からないときは合計も出さない。
	if ( isPerBooking ) {
		if ( basePrice === null || basePrice === undefined ) {
			return null;
		}

		const extraGuests = Math.max( 0, Number( extraGuestSubtotal ) || 0 );

		return Math.max( 0, basePrice ) + extraGuests + nomination + exclusive;
	}

	// 料金区分ありのメニューは、メニューの基本料金を使わず区分小計の合計を基本部分にする。
	if (
		typeof tiersSubtotal === 'number' &&
		Number.isFinite( tiersSubtotal )
	) {
		return Math.max( 0, tiersSubtotal ) + nomination + exclusive;
	}

	// 基本料金が分からないときは合計も出さない（呼び出し側で「—」表示にする）。
	if ( basePrice === null || basePrice === undefined ) {
		return null;
	}

	return basePrice * normalizeGuestCount( guests ) + nomination + exclusive;
};

/**
 * 目印の前後に置く私用領域の文字（U+E000 / U+E001）。
 *
 * 目に見えない文字のため、ソース上は必ずエスケープで書き、目印の組み立てと
 * 目印を探す正規表現の両方をこの定数から作る（書き方を1か所にまとめる）。
 */
const PLACEHOLDER_OPEN = '\uE000';
const PLACEHOLDER_CLOSE = '\uE001';

/**
 * 見出しを組み立てる際に、差し込む値の位置を探すための目印（私用領域の文字で囲む）。
 *
 * @param {number} index 差し込む値の番号（0 始まり）。
 * @return {string} 目印の文字列。
 */
const placeholderMarker = ( index ) =>
	`${ PLACEHOLDER_OPEN }${ index }${ PLACEHOLDER_CLOSE }`;

/**
 * 目印（placeholderMarker の戻り値）を探す正規表現の元になる文字列。番号を1つ目の捕獲グループで取る。
 */
const PLACEHOLDER_PATTERN = `${ PLACEHOLDER_OPEN }(\\d+)${ PLACEHOLDER_CLOSE }`;

/**
 * 翻訳済みの見出しを組み立て、「（単価 × 人数）」の括弧部分を分けて返す（#503）。
 *
 * 狭い画面で「（¥15,000 × 2」と「名）」のように括弧の途中で改行されないよう、
 * 表示側で括弧部分を折り返さない要素に入れるために使う。訳文は変えずに、
 * 訳文中で単価（detailStartIndex 番目の値）より前にある最後の開き括弧（半角「(」・全角「（」）から、
 * 最後に差し込む値より後ろにある最初の閉じ括弧（半角「)」・全角「）」）までを括弧部分とする。
 * 訳文に括弧が無いときは、見出し全体を本体として返す（括弧部分は空文字）。
 *
 * @param {string}        format           翻訳済みの書式（sprintf 形式）。
 * @param {Array<string>} values           書式に差し込む値。
 * @param {number}        detailStartIndex 括弧部分の先頭になる値の番号（0 始まり）。
 * @return {{label: string, labelParts: {main: string, detail: string, suffix: string}}}
 *         見出し全体と、本体・括弧部分・括弧より後ろの文字に分けたもの。
 */
export const buildLabelWithDetail = (
	format,
	values,
	detailStartIndex = 0
) => {
	const label = sprintf( format, ...values );

	// 値の代わりに目印を差し込み、訳文の中で値がどこに入るかを調べる。
	const markers = values.map( ( _, index ) => placeholderMarker( index ) );
	const marked = sprintf( format, ...markers );
	const detailStart = marked.indexOf( markers[ detailStartIndex ] );
	const openIndex =
		detailStart === -1
			? -1
			: Math.max(
					marked.lastIndexOf( '(', detailStart ),
					marked.lastIndexOf( '（', detailStart )
			  );

	// 括弧が見つからない訳文は分けない。
	if ( openIndex === -1 ) {
		return { label, labelParts: { main: label, detail: '', suffix: '' } };
	}

	// 閉じ括弧は、括弧部分に入る値のうち最も後ろにあるものより後ろから探す。
	const lastValueEnd = markers
		.slice( detailStartIndex )
		.reduce( ( end, marker ) => {
			const position = marked.indexOf( marker );
			return position === -1
				? end
				: Math.max( end, position + marker.length );
		}, detailStart + markers[ detailStartIndex ].length );
	const closeCandidates = [ ')', '）' ]
		.map( ( bracket ) => marked.indexOf( bracket, lastValueEnd ) )
		.filter( ( position ) => position !== -1 );
	const closeIndex =
		closeCandidates.length > 0
			? Math.min( ...closeCandidates ) + 1
			: marked.length;

	// 目印を実際の値に戻す。目印ごとに順に置き換えると、先に戻した値（区分名など）に
	// 目印と同じ文字列が含まれていたとき後の置き換えで書き換わるため、1回の置き換えで戻す。
	// 値に「$&」などが含まれても置換パターンとして解釈されないよう、関数で置き換える。
	const restore = ( text ) =>
		text.replace(
			new RegExp( PLACEHOLDER_PATTERN, 'g' ),
			( match, index ) =>
				Number( index ) < values.length
					? String( values[ Number( index ) ] )
					: match
		);

	return {
		label,
		labelParts: {
			main: restore( marked.slice( 0, openIndex ) ),
			detail: restore( marked.slice( openIndex, closeIndex ) ),
			suffix: restore( marked.slice( closeIndex ) ),
		},
	};
};

/**
 * 料金欄の「サービス基本料金」行の見出しと金額を組み立てる（#503）。
 *
 * 複数人一括予約を使うメニューでは、見出しに「単価 × 人数」を添え、金額には小計
 * （単価 × 人数）を出す。金額の欄を掛け算の式にしないのは、上から足して合計を
 * 確かめられるようにするため。1名のときも「× 1名」を出し、人数で金額が変わることを示す。
 * 複数人一括予約を使わないメニューは従来どおり見出しに単価も人数も出さない。
 *
 * @param {Object}      args
 * @param {number|null} args.unitPrice       1名あたりのサービス基本料金。
 * @param {number}      args.guests          申込人数。
 * @param {boolean}     args.isMultiGuest    複数人一括予約を使うメニュー（料金区分なし）か。
 * @param {boolean}     args.isPerBooking    料金の単位が「1ツアーあたり」か（#1002）。true のときは人数掛けの表記を出さず、
 *                                           見出しを「ツアー料金（1ツアーあたり）」にして金額は基本料金そのままにする。
 * @param {string|null} args.currencySymbol  通貨記号。
 * @param {string}      args.guestsUnitLabel 人数の単位（例: 名）。空文字は単位なし。
 * @return {{label: string, labelParts: {main: string, detail: string, suffix: string}, amount: number|null}}
 *         見出し・見出しを括弧部分で分けたもの（buildLabelWithDetail 参照）・金額（小計）。単価が不明なら金額は null。
 */
export const buildServiceBasicFeeRow = ( {
	unitPrice,
	guests = 1,
	isMultiGuest = false,
	isPerBooking = false,
	currencySymbol = null,
	guestsUnitLabel = '',
} ) => {
	// 1ツアーあたりは「× ◯名」を出さず、1ツアーの料金であることを見出しで示す。括弧部分は折り返さない塊として持たせる。
	if ( isPerBooking ) {
		const perBookingLabel = __(
			'Tour fee (per tour)',
			'vk-booking-manager'
		);
		// 訳文の括弧部分（半角・全角）を分けて持たせ、表示側で括弧の途中で改行させない。
		const parts = perBookingLabel.match( /^(.*?)([（(][^）)]*[）)])(.*)$/ );
		return {
			label: perBookingLabel,
			labelParts: parts
				? { main: parts[ 1 ], detail: parts[ 2 ], suffix: parts[ 3 ] }
				: { main: perBookingLabel, detail: '', suffix: '' },
			amount:
				unitPrice === null || unitPrice === undefined
					? null
					: unitPrice,
		};
	}

	const defaultLabel = __( 'Service basic fee', 'vk-booking-manager' );
	// 括弧部分が無い見出しは、全体を本体として持たせる。
	const defaultLabelParts = { main: defaultLabel, detail: '', suffix: '' };

	// 単価が分からないときは、見出しは従来の文言のまま金額を空にする。
	if ( unitPrice === null || unitPrice === undefined ) {
		return {
			label: defaultLabel,
			labelParts: defaultLabelParts,
			amount: null,
		};
	}

	// 複数人一括予約を使わないメニューは従来どおり。
	if ( ! isMultiGuest ) {
		return {
			label: defaultLabel,
			labelParts: defaultLabelParts,
			amount: unitPrice,
		};
	}

	const guestCount = normalizeGuestCount( guests );

	return {
		// 見出しは「（単価 × 人数）」の括弧部分を分けて持たせ、表示側で括弧の途中で改行させない。
		...buildLabelWithDetail(
			/* translators: 1: unit price per person, 2: number of guests with unit (e.g. 2 guests) */
			__( 'Service basic fee (%1$s × %2$s)', 'vk-booking-manager' ),
			[
				formatCurrency( unitPrice, currencySymbol ),
				formatGuestsCount( guestCount, guestsUnitLabel ),
			],
			0
		),
		amount: unitPrice * guestCount,
	};
};

/**
 * 料金欄を「複数人一括予約（料金区分なし）」の形（単価 × 人数 と小計）で出すかを判定する（#503）。
 *
 * 料金区分ありのメニューは区分ごとの行で出すため対象外。
 * 確認画面でメニュー情報の取得に失敗した場合に備え、2名以上の予約は複数人一括予約として扱う。
 *
 * @param {Object}  args
 * @param {boolean} args.allowMultipleGuests メニューが複数人一括予約を使う設定か。
 * @param {boolean} args.hasPriceTiers       メニュー（予約）に料金区分があるか。
 * @param {number}  args.guests              申込人数。
 * @param {boolean} args.isPerBooking        料金の単位が「1ツアーあたり」か（#1002）。人数掛けの形では出さない。
 * @return {boolean} 複数人一括予約（料金区分なし）の形で出すなら true。
 */
export const isMultiGuestFlatPricing = ( {
	allowMultipleGuests = false,
	hasPriceTiers = false,
	guests = 1,
	isPerBooking = false,
} ) => {
	// 1ツアーあたりは人数にかかわらず基本料金そのままのため、人数掛けの形では出さない。
	if ( isPerBooking ) {
		return false;
	}

	// 料金区分ありは区分ごとの行で出す。
	if ( hasPriceTiers ) {
		return false;
	}

	return (
		Boolean( allowMultipleGuests ) ||
		Math.floor( Number( guests ) || 0 ) > 1
	);
};

/**
 * 料金区分の単価と人数を 0 以上の整数へ正規化する。
 *
 * @param {Object} tier 料金区分（{ label, price, count }）。
 * @return {{label: string, price: number, count: number}} 正規化した料金区分。
 */
const normalizePriceTier = ( tier ) => ( {
	label: typeof tier?.label === 'string' ? tier.label : '',
	price: Math.max( 0, Math.floor( Number( tier?.price ) || 0 ) ),
	count: Math.max( 0, Math.floor( Number( tier?.count ) || 0 ) ),
} );

/**
 * 料金区分ごとの小計（単価 × 人数）の合計を求める（#503）。
 *
 * サーバー側の Price_Tiers::total_price() と同じ式。
 *
 * @param {Array<Object>} tiers 料金区分の配列（[ { label, price, count }, ... ]）。
 * @return {number} 区分小計の合計。配列でなければ 0。
 */
export const sumPriceTierSubtotals = ( tiers ) => {
	if ( ! Array.isArray( tiers ) ) {
		return 0;
	}

	return tiers
		.map( normalizePriceTier )
		.reduce( ( sum, tier ) => sum + tier.price * tier.count, 0 );
};

/**
 * 1ツアーあたりで、基本料金に含まれる人数を超えた人数の内訳を求める（#1003）。
 *
 * サーバー側の Booking_Price::resolve_extra_guest_tiers()（Lodging_Price::distribute_excess_guests()）と同じ決め方で、
 * 単価の高い区分の人から順に基本料金に含め、残りの人を超過扱いにする。同額の区分は並びの先の区分から含める。
 * 表示用の概算で、実際に保存される金額はサーバー側で再計算される。
 *
 * @param {Array<Object>} tiers          料金区分の配列（[ { label, price, count }, ... ]）。price は超過1名あたりの追加料金。
 * @param {number}        includedGuests 基本料金に含まれる人数。
 * @return {Array<{label: string, price: number, count: number}>} 超過した人がいる区分と超過人数（元の並び順）。
 */
export const resolveExtraGuestTiers = ( tiers, includedGuests ) => {
	if ( ! Array.isArray( tiers ) ) {
		return [];
	}

	const normalized = tiers.map( normalizePriceTier );
	// 単価の高い順。同額は元の並びの順を保つ。
	const order = normalized
		.map( ( _, index ) => index )
		.sort( ( a, b ) =>
			normalized[ a ].price === normalized[ b ].price
				? a - b
				: normalized[ b ].price - normalized[ a ].price
		);

	let remainingIncluded = Math.max(
		0,
		Math.floor( Number( includedGuests ) || 0 )
	);
	const excessCounts = [];
	order.forEach( ( index ) => {
		const includedCount = Math.min(
			normalized[ index ].count,
			remainingIncluded
		);
		remainingIncluded -= includedCount;
		excessCounts[ index ] = normalized[ index ].count - includedCount;
	} );

	return normalized
		.map( ( tier, index ) => ( { ...tier, count: excessCounts[ index ] } ) )
		.filter( ( tier ) => tier.count > 0 );
};

/**
 * 料金欄に出す追加料金の行（見出し「追加料金（区分名 単価 × 超過人数）」と小計）を組み立てる（#1003）。
 *
 * 超過人数が0名の区分は行を出さない。見出しの括弧部分は、狭い画面で途中で改行されないよう分けて返す。
 *
 * @param {Object}        args
 * @param {Array<Object>} args.extraTiers      超過した人がいる区分と超過人数（resolveExtraGuestTiers の戻り値）。
 * @param {string|null}   args.currencySymbol  通貨記号。
 * @param {string}        args.guestsUnitLabel 人数の単位（例: 名）。空文字は単位なし。
 * @return {Array<{label: string, labelParts: {main: string, detail: string, suffix: string}, amount: number}>}
 *         区分ごとの見出し・見出しを括弧部分で分けたもの（buildLabelWithDetail 参照）・小計。
 */
export const buildExtraGuestFeeRows = ( {
	extraTiers,
	currencySymbol = null,
	guestsUnitLabel = '',
} ) => {
	if ( ! Array.isArray( extraTiers ) ) {
		return [];
	}

	return extraTiers
		.map( normalizePriceTier )
		.filter( ( tier ) => tier.count > 0 )
		.map( ( tier ) => ( {
			...buildLabelWithDetail(
				/* translators: 1: price category name (e.g. Child), 2: extra fee per guest, 3: number of extra guests with unit (e.g. 1 guest) */
				__(
					'Extra guest fee (%1$s %2$s × %3$s)',
					'vk-booking-manager'
				),
				[
					tier.label,
					formatCurrency( tier.price, currencySymbol ),
					formatGuestsCount( tier.count, guestsUnitLabel ),
				],
				0
			),
			amount: tier.price * tier.count,
		} ) );
};

/**
 * 区分ごとの人数欄に添える、追加料金の金額表記（「+¥3,000」）と読み上げ用の文言を組み立てる（#1003）。
 *
 * 「+」と金額を1つの文字列で返し、表示側で折り返さない塊に入れて、狭い幅で「+」だけが次の行に落ちないようにする。
 * 画面には記号つきの表記だけを見せ、読み上げには「追加料金 ¥3,000」を使う。
 *
 * @param {Object}      args
 * @param {number}      args.price          超過1名あたりの追加料金。
 * @param {string|null} args.currencySymbol 通貨記号。
 * @return {{visible: string, spoken: string}} 画面に出す表記と、読み上げ用の文言。
 */
export const buildExtraGuestTierPriceText = ( {
	price,
	currencySymbol = null,
} ) => {
	const amount = formatCurrency( price, currencySymbol );

	return {
		visible: `+${ amount }`,
		spoken: sprintf(
			/* translators: %s: extra fee per guest (e.g. ¥3,000). */
			__( 'Extra fee %s', 'vk-booking-manager' ),
			amount
		),
	};
};

/**
 * 追加料金があるときに、合計の上に添える1行を、折り返さない2つの塊に分けて組み立てる（#1003）。
 *
 * 「ツアー料金 ¥30,000」と「＋ 追加料金 ¥1,500」を別々の塊として返す。表示側で塊ごとに折り返さない要素に入れ、
 * 狭い画面でも折り返しが塊の境界だけで起こるようにする（金額や「＋」だけが次の行に落ちない）。
 *
 * @param {Object}      args
 * @param {number}      args.tourFee        ツアー料金（1ツアーあたりの基本料金）。
 * @param {number}      args.extraGuestFee  追加料金の合計。
 * @param {string|null} args.currencySymbol 通貨記号。
 * @return {{tourFee: string, extraGuestFee: string}} ツアー料金の塊と、追加料金の塊。
 */
export const buildTourFeeSummaryParts = ( {
	tourFee,
	extraGuestFee,
	currencySymbol = null,
} ) => ( {
	tourFee: sprintf(
		/* translators: %s: tour fee (e.g. ¥30,000). */
		__( 'Tour fee %s', 'vk-booking-manager' ),
		formatCurrency( tourFee, currencySymbol )
	),
	extraGuestFee: sprintf(
		/* translators: %s: extra guest fee (e.g. ¥1,500). */
		__( '+ Extra guest fee %s', 'vk-booking-manager' ),
		formatCurrency( extraGuestFee, currencySymbol )
	),
} );

/**
 * 人数の合計に区分ごとの構成を添えた表記（「5名（大人4名・子供1名）」）を組み立てる（#1003）。
 *
 * 1ツアーあたりでは区分の単価が追加料金のため、区分ごとの小計の行を出さない。人数の構成は人数の行で伝える。
 * 人数が0名の区分は構成に含めず、構成にする区分が無いときは人数の合計だけを返す。
 * サーバー側の Booking_Price_Display::format_guest_composition() と同じ形。
 *
 * @param {Object}        args
 * @param {number}        args.guests          人数の合計。
 * @param {Array<Object>} args.tiers           区分ごとの人数内訳（[ { label, count }, ... ]）。
 * @param {string}        args.guestsUnitLabel 人数の単位（例: 名）。空文字は単位なし。
 * @return {string} 表記。
 */
export const buildGuestCompositionText = ( {
	guests,
	tiers,
	guestsUnitLabel = '',
} ) => {
	const total = formatGuestsCount(
		Math.max( 0, Number( guests ) || 0 ),
		guestsUnitLabel
	);
	const parts = ( Array.isArray( tiers ) ? tiers : [] )
		.map( normalizePriceTier )
		.filter( ( tier ) => tier.count > 0 )
		.map( ( tier ) =>
			sprintf(
				/* translators: 1: price category name (e.g. Adult), 2: number of guests with unit (e.g. 4 guests). */
				__( '%1$s %2$s', 'vk-booking-manager' ),
				tier.label,
				formatGuestsCount( tier.count, guestsUnitLabel )
			)
		);

	if ( parts.length === 0 ) {
		return total;
	}

	const composition = parts.reduce( ( list, part ) =>
		sprintf(
			/* translators: 1: price categories listed so far, 2: next price category. */
			__( '%1$s, %2$s', 'vk-booking-manager' ),
			list,
			part
		)
	);

	return sprintf(
		/* translators: 1: total number of guests with unit (e.g. 5 guests), 2: breakdown by price category (e.g. Adult 4 guests, Child 1 guest). */
		__( '%1$s (%2$s)', 'vk-booking-manager' ),
		total,
		composition
	);
};

/**
 * 料金欄に出す料金区分ごとの行（見出し「区分名（単価 × 人数）」と小計）を組み立てる（#503）。
 *
 * 0名の区分は行を出さない（確認画面 #338 と同じ）。
 *
 * @param {Object}        args
 * @param {Array<Object>} args.tiers           料金区分の配列（[ { label, price, count }, ... ]）。
 * @param {string|null}   args.currencySymbol  通貨記号。
 * @param {string}        args.guestsUnitLabel 人数の単位（例: 名）。空文字は単位なし。
 * @return {Array<{label: string, labelParts: {main: string, detail: string, suffix: string}, amount: number}>}
 *         区分ごとの見出し・見出しを括弧部分で分けたもの（buildLabelWithDetail 参照）・小計。
 */
export const buildPriceTierRows = ( {
	tiers,
	currencySymbol = null,
	guestsUnitLabel = '',
} ) => {
	if ( ! Array.isArray( tiers ) ) {
		return [];
	}

	return tiers
		.map( normalizePriceTier )
		.filter( ( tier ) => tier.count > 0 )
		.map( ( tier ) => ( {
			// 括弧部分は2番目の値（単価）から始まる。区分名の中の括弧は括弧部分に含めない。
			...buildLabelWithDetail(
				/* translators: 1: price tier name (e.g. Adult), 2: unit price per person, 3: number of guests with unit (e.g. 2 guests) */
				__( '%1$s (%2$s × %3$s)', 'vk-booking-manager' ),
				[
					tier.label,
					formatCurrency( tier.price, currencySymbol ),
					formatGuestsCount( tier.count, guestsUnitLabel ),
				],
				1
			),
			amount: tier.price * tier.count,
		} ) );
};

/**
 * プランカードに出す料金の内訳の行を決める。
 *
 * 泊単位のプラン（宿泊用の画面）では、人数・料金は宿泊用の画面が持つ人数で
 * 泊数分を計算して出す。プランカードの内訳は時間枠の人数（既定 1 名）で計算されて
 * 食い違うため、行を出さない。時間枠のプランは行をそのまま返す。
 *
 * @param {Array}   rows          時間枠の人数で組み立てた内訳の行。
 * @param {boolean} isLodgingMenu 泊単位のプランかどうか。
 * @return {Array} プランカードに渡す行。
 */
export const resolvePlanPricingRows = ( rows, isLodgingMenu ) =>
	isLodgingMenu ? [] : rows;

/**
 * 料金の単位が「1ツアーあたり」のメニューで、人数欄の下に出す補足文を組み立てる（#1002）。
 *
 * 人数を入力しても料金が変わらないことを、1ツアーの料金と合わせて伝える。
 * 例: 「料金は 1ツアーあたり ¥30,000 です。人数を入力しても料金は変わりません。」
 *
 * @param {Object}      args
 * @param {number}      args.price          1ツアーあたりの基本料金。
 * @param {string|null} args.currencySymbol 通貨記号。
 * @return {string} 補足文。
 */
export const buildPerBookingPriceNote = ( { price, currencySymbol = null } ) =>
	joinSentences(
		sprintf(
			/* translators: %s: price per tour (e.g. ¥30,000). */
			__( 'The price is %s per tour.', 'vk-booking-manager' ),
			formatCurrency( price, currencySymbol )
		),
		__(
			'The price does not change even if the number of guests is entered.',
			'vk-booking-manager'
		)
	);

/**
 * 「1ツアーあたり」で規定人数を超えた分の追加料金を使うメニューの、人数欄の下に出す補足文を組み立てる（#1003）。
 *
 * 1ツアーの料金に何名までの参加が含まれるかと、超えた人数に区分ごとの追加料金が加算されることを伝える。
 * 例: 「料金は 1ツアーあたり ¥30,000 で、4名までの参加を含みます。これを超える人数には、区分ごとに表示した追加料金が、超えた1名あたりで加算されます。」
 *
 * @param {Object}      args
 * @param {number}      args.price           1ツアーあたりの基本料金。
 * @param {number}      args.includedGuests  基本料金に含まれる人数。
 * @param {string|null} args.currencySymbol  通貨記号。
 * @param {string}      args.guestsUnitLabel 人数の単位（例: 名）。空文字は単位なし。
 * @return {string} 補足文。
 */
export const buildPerBookingExtraGuestPriceNote = ( {
	price,
	includedGuests,
	currencySymbol = null,
	guestsUnitLabel = '',
} ) =>
	joinSentences(
		sprintf(
			/* translators: 1: price per tour (e.g. ¥30,000), 2: number of guests included in the basic price with unit (e.g. 4 guests). */
			__(
				'The price is %1$s per tour and includes up to %2$s.',
				'vk-booking-manager'
			),
			formatCurrency( price, currencySymbol ),
			formatGuestsCount( includedGuests, guestsUnitLabel )
		),
		__(
			'For guests over this number, the extra fee shown for each category is added per extra guest.',
			'vk-booking-manager'
		)
	);
