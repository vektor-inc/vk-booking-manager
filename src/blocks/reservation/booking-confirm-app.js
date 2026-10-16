import { __ } from '@wordpress/i18n';
import {
	lazy,
	Suspense,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import { formatCurrency, normalizePriceValue } from '../shared/pricing';
import { resolveLoginState } from '../shared/auth';
import { resolveApiErrorMessage } from '../shared/rest-error-message';
import {
	isBookingTimeConflict,
	isDraftUnavailable,
	normalizeBookingError,
	notifyBookingFailed,
	parseJsonWithFallback,
} from './booking-confirm-error';
import { sanitizeDraftToken } from '../shared/draft-token';
import { formatGuestsCount } from '../shared/guests';
import { BookingSummaryItems } from './components/booking-summary-items';
import {
	BookingStayDatetime,
	isStayBooking,
} from './components/booking-stay-datetime';
import {
	buildPriceTierRows,
	buildServiceBasicFeeRow,
	calculateBaseFeeTotal,
	isMultiGuestFlatPricing,
	sumPriceTierSubtotals,
} from './pricing-breakdown';
import { ReservationHeader } from './components/reservation-header';
import { ReservationFooter } from './components/reservation-footer';
import {
	buildFooterModel,
	extractFooterSettings,
} from './components/footer-model';
import { PricingRowLabel } from './components/pricing-row-label';
import { BookingCompleteNotice } from './components/booking-complete-notice';

// 宿泊予約の確認画面のサマリは別チャンクに分け、宿泊の予約を確認するときだけ読み込む。
const LodgingConfirmSummary = lazy( () =>
	import(
		/* webpackChunkName: "lodging-confirm-summary" */ './lodging/lodging-confirm-summary'
	)
);

const getQueryParam = ( key ) => {
	if ( typeof window === 'undefined' ) {
		return '';
	}
	const params = new URLSearchParams( window.location.search );
	const value = params.get( key ) || '';
	return key === 'draft' ? sanitizeDraftToken( value ) : value;
};

const formatBookingDateTimeParts = ( startAt, endAt, timezone ) => {
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

const formatDate = (
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

const parseQueryParams = () => {
	if ( typeof window === 'undefined' ) {
		return {};
	}

	try {
		const params = new URLSearchParams( window.location.search );
		return {
			auth: params.get( 'vkbm_auth' ) || '',
			draft: sanitizeDraftToken( params.get( 'draft' ) || '' ),
		};
	} catch ( error ) {
		return {};
	}
};

const buildApiPath = ( base, query = {} ) => {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			params.append( key, value );
		}
	} );
	return `${ base }?${ params.toString() }`;
};

const buildLogoutFallbackUrl = () => {
	if ( typeof window === 'undefined' ) {
		return '/wp-login.php?action=logout';
	}
	const redirect = encodeURIComponent( window.location.href );
	return `/wp-login.php?action=logout&redirect_to=${ redirect }`;
};

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

export const BookingConfirmApp = ( {
	redirectUrl,
	termsLabel,
	policyText,
	successMessage,
	reservationPageUrl = '',
	isEditor = false,
	// issue #512: サーバー側（render_block フィルタ）が同一リクエスト内で判明した
	// ログイン失敗コードを wrapper の data-vkbm-login-error に埋め込み、view.js 経由で
	// ここまで渡す。初回の auth-form 取得時だけ REST の error パラメータとして送る。
	initialLoginError = '',
	// issue #512 植草指摘（ちらつき対策）: 上記コードに対応する、サーバー側フォール
	// バックが既に表示していたエラー文そのもの。REST 応答が届くまでの間、同じ文言を
	// 表示し続けるための初期値として使う。
	initialLoginErrorMessage = '',
	// issue #516: サーバー側（render_block フィルタ）が同一リクエスト内で発行した
	// 会員登録失敗トークンを wrapper の data-vkbm-registration-error-key に埋め込み、
	// view.js → app.js 経由でここまで渡す。初回の auth-form 取得時だけ REST の
	// registration_error_key パラメータとして送る。トークン自体に個人情報は含まれない。
	initialRegistrationErrorKey = '',
} ) => {
	const userBootstrap = useMemo( () => {
		if ( typeof window === 'undefined' ) {
			return null;
		}
		return window.vkbmCurrentUserBootstrap || null;
	}, [] );
	const draftToken = useMemo( () => getQueryParam( 'draft' ), [] );
	const [ draft, setDraft ] = useState( null );
	const [ menu, setMenu ] = useState( null );
	// #391: このメニューで指名機能を使うか。サーバ（Booking_Draft_Controller::get_draft）が
	// メニュー単位の判定結果を authoritative な値として返すため、フロント側でメニューメタから
	// 再計算せずそのまま使う（改竄防止・二重実装防止）。draft 読込前は既定で true として扱う
	// （読込前に指名料行等を一瞬非表示にするちらつきを避けるため。既存の staff_enabled 判定と同じ方針）。
	const nominationEnabledForMenu = draft?.nomination_enabled !== false;
	const [ staff, setStaff ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( '' );
	const [ memo, setMemo ] = useState( '' );
	const [ agreeCancellationPolicy, setAgreeCancellationPolicy ] =
		useState( false );
	const [ agreeTermsOfService, setAgreeTermsOfService ] = useState( false );
	const [ submitting, setSubmitting ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ success, setSuccess ] = useState( false );
	// お気に入り（いつもの）登録の状態。確認画面のチェックボックスで希望を受け取り、
	// 予約確定後に登録する。
	const [ favoriteOptIn, setFavoriteOptIn ] = useState( false );
	const [ favoriteSaved, setFavoriteSaved ] = useState( false );
	const [ favoriteError, setFavoriteError ] = useState( '' );
	const [ createdStatus, setCreatedStatus ] = useState( '' );
	const [ customerName, setCustomerName ] = useState( '' );
	const [ customerPhone, setCustomerPhone ] = useState( '' );
	const [ providerNote, setProviderNote ] = useState( '' );
	const [ paymentMethod, setPaymentMethod ] = useState( 'onsite' );
	const [ canManageReservations, setCanManageReservations ] = useState(
		Boolean( userBootstrap?.canManageReservations )
	);
	const [ logoutUrl, setLogoutUrl ] = useState(
		userBootstrap?.logoutUrl || ''
	);
	const [ shiftDashboardUrl, setShiftDashboardUrl ] = useState(
		userBootstrap?.shiftDashboardUrl || ''
	);
	const [ settingsUrl, setSettingsUrl ] = useState(
		userBootstrap?.settingsUrl || ''
	);
	const [ providerCancellationPolicy, setProviderCancellationPolicy ] =
		useState( '' );
	const [ providerTermsOfService, setProviderTermsOfService ] =
		useState( '' );
	const [ providerPaymentMethod, setProviderPaymentMethod ] = useState( '' );
	// 仮予約の完了画面で見出しの下に出す案内文（REST の実効値。空のままなら標準文を使う）。
	const [ pendingCompleteMessage, setPendingCompleteMessage ] =
		useState( '' );
	const [ providerName, setProviderName ] = useState( '' );
	// 予約画面と同じフッター（#796）。キャンセルポリシー・利用規約の本文は上の state と同じ値。
	const [ footerSettings, setFooterSettings ] = useState( null );
	const [ providerLogoUrl, setProviderLogoUrl ] = useState( '' );
	const [ providerLogoWidth, setProviderLogoWidth ] = useState( 0 );
	const [ showProviderLogo, setShowProviderLogo ] = useState( false );
	const [ showProviderName, setShowProviderName ] = useState( false );
	const [ resourceLabelSingular, setResourceLabelSingular ] = useState(
		__( 'Staff', 'vk-booking-manager' )
	);
	const [ noNominationLabel, setNoNominationLabel ] = useState(
		__( 'No preference', 'vk-booking-manager' )
	);
	const [ nominationFeeLabel, setNominationFeeLabel ] = useState(
		__( 'Nomination fee', 'vk-booking-manager' )
	);
	const [ otherConditionsLabel, setOtherConditionsLabel ] = useState(
		__( 'Other conditions', 'vk-booking-manager' )
	);
	// 数量の見出し（複数人一括予約）。REST が実効値を返す。
	const [ guestsCountLabel, setGuestsCountLabel ] = useState(
		__( 'Number of guests', 'vk-booking-manager' )
	);
	// 数量の単位。REST が実効値（null はロケール既定に解決済み）を返す。空文字は単位なし。
	const [ guestsUnitLabel, setGuestsUnitLabel ] = useState( '' );
	const [ resolvedReservationPageUrl, setResolvedReservationPageUrl ] =
		useState( reservationPageUrl || '' );
	const [ taxLabelText, setTaxLabelText ] = useState( '' );
	const [ currencySymbol, setCurrencySymbol ] = useState( '' );

	const isLoggedIn = resolveLoginState( { userBootstrap, isEditor } );

	const queryDefaults = useMemo( () => parseQueryParams(), [] );
	const [ authMode, setAuthMode ] = useState( queryDefaults.auth || '' );
	const [ authFormHtml, setAuthFormHtml ] = useState( '' );
	const [ authLoading, setAuthLoading ] = useState( false );
	// issue #512 植草指摘（ちらつき対策）: 初期値をサーバー側フォールバックの
	// エラー文で埋めておく。REST 応答が届くまでの間、空にせず表示し続ける。
	const [ authError, setAuthError ] = useState( initialLoginErrorMessage );
	// issue #512: 初回の auth-form 取得だけ initialLoginError を送るためのフラグ。
	const initialLoginErrorSentRef = useRef( false );
	// issue #516: 初回の auth-form 取得だけ initialRegistrationErrorKey を送るためのフラグ。
	const initialRegistrationErrorSentRef = useRef( false );
	// authFormHtml（dangerouslySetInnerHTML）の描画先。エラー要素への
	// フォーカス（下記 useEffect）に使う。
	const authFormContainerRef = useRef( null );
	// 予約完了ブロックのコンテナ。完了時にフォーカスを移すために使う。
	const completeNoticeRef = useRef( null );
	const [ bookingsLoading, setBookingsLoading ] = useState( false );
	const [ bookingsError, setBookingsError ] = useState( '' );
	const [ bookings, setBookings ] = useState( [] );
	const [ cancellingBookingId, setCancellingBookingId ] = useState( 0 );
	const timezone = draft?.meta?.timezone || '';

	const pricingSummary = useMemo( () => {
		const taxIncluded = Boolean( draft?.menu_price_tax_included );
		const taxLabel = taxLabelText.trim() !== '' ? taxLabelText : '';
		const ensureTaxLabel = ( label ) =>
			taxIncluded && taxLabel ? `${ label }${ taxLabel }` : label;

		const basePrice =
			normalizePriceValue( draft?.menu_price ) ??
			normalizePriceValue( draft?.menu_price_base ) ??
			normalizePriceValue(
				menu?.meta?.vkbm_base_price ??
					menu?.meta?._vkbm_base_price ??
					menu?._vkbm_base_price ??
					menu?.price
			);

		const currencySymbolValue =
			currencySymbol.trim() !== '' ? currencySymbol : null;

		// #503: 複数人一括予約を使うメニュー（料金区分なし）では、見出しに「単価 × 人数」、
		// 金額に小計を出し、内訳を足すと合計（サーバーが 基本料金 × 人数 ＋ 指名料 で計算）になるようにする。
		// 判定は予約画面（app.js の allowMultipleGuests）と同じメニューの設定値を使う。
		// メニュー情報の取得に失敗した場合でも、2名以上の予約なら複数人一括予約として扱う。
		const draftGuests = Math.max(
			1,
			Math.floor( Number( draft?.guests ) || 1 )
		);
		const hasDraftGuestTiers =
			Array.isArray( draft?.guest_tiers ) && draft.guest_tiers.length > 0;
		const isMultiGuest = isMultiGuestFlatPricing( {
			allowMultipleGuests: Boolean(
				menu?.meta?._vkbm_allow_multiple_guests
			),
			hasPriceTiers: hasDraftGuestTiers,
			guests: draftGuests,
		} );
		const baseRow = buildServiceBasicFeeRow( {
			unitPrice: basePrice,
			guests: draftGuests,
			isMultiGuest,
			currencySymbol: currencySymbolValue,
			guestsUnitLabel,
		} );

		let formattedBase = '';
		if ( isMultiGuest && baseRow.amount !== null ) {
			// 小計はサーバーの整形済み金額（1名分）が使えないため、ここで整形して税表記を付ける。
			formattedBase = ensureTaxLabel(
				formatCurrency( baseRow.amount, currencySymbolValue )
			);
		} else if (
			typeof draft?.menu_price_formatted === 'string' &&
			draft.menu_price_formatted.trim() !== ''
		) {
			formattedBase = draft.menu_price_formatted;
		} else if ( basePrice !== null ) {
			formattedBase = ensureTaxLabel(
				formatCurrency( basePrice, currencySymbolValue )
			);
		}

		const nominationFee = nominationEnabledForMenu
			? normalizePriceValue( draft?.nomination_fee ) ?? 0
			: 0;
		const formattedNomination =
			typeof draft?.nomination_fee_formatted === 'string' &&
			draft.nomination_fee_formatted.trim() !== ''
				? draft.nomination_fee_formatted
				: ensureTaxLabel(
						formatCurrency( nominationFee, currencySymbolValue )
				  );

		// 合計はサーバーの計算値を正とし、無いときだけ下書き計算と同じ式で補う
		// （区分なし: 基本料金 × 人数 ＋ 指名料 ＋ 貸し切り料金、区分あり: 区分小計の合計 ＋ 指名料 ＋ 貸し切り料金）。
		const totalPrice =
			normalizePriceValue( draft?.total_price ) ??
			calculateBaseFeeTotal( {
				basePrice,
				guests: isMultiGuest ? draftGuests : 1,
				nominationFee,
				exclusiveFee: draft?.user_exclusive
					? normalizePriceValue( draft?.exclusive_fee ) ?? 0
					: 0,
				tiersSubtotal: hasDraftGuestTiers
					? sumPriceTierSubtotals( draft.guest_tiers )
					: null,
			} );
		const formattedTotal =
			typeof draft?.total_price_formatted === 'string' &&
			draft.total_price_formatted.trim() !== ''
				? draft.total_price_formatted
				: totalPrice !== null
				? ensureTaxLabel(
						formatCurrency( totalPrice, currencySymbolValue )
				  )
				: '';

		// #503: 料金区分ありの予約は、予約画面の料金欄と同じ「区分名（単価 × 人数）」＋小計で表示する。
		// 0名の区分は省く。小計の金額はサーバーの整形済み値（税表記込み）を優先する。
		const tiersWithGuests = hasDraftGuestTiers
			? draft.guest_tiers.filter(
					( tier ) => Math.floor( Number( tier?.count ) || 0 ) > 0
			  )
			: [];
		const tierRows = buildPriceTierRows( {
			tiers: tiersWithGuests,
			currencySymbol: currencySymbolValue,
			guestsUnitLabel,
		} ).map( ( tierRow, index ) => {
			const subtotalFormatted =
				tiersWithGuests[ index ]?.subtotal_formatted;
			return {
				label: tierRow.label,
				labelParts: tierRow.labelParts,
				value:
					typeof subtotalFormatted === 'string' &&
					subtotalFormatted.trim() !== ''
						? subtotalFormatted
						: ensureTaxLabel(
								formatCurrency(
									tierRow.amount,
									currencySymbolValue
								)
						  ),
			};
		} );

		return {
			tierRows,
			baseRowLabel: baseRow.label,
			baseRowLabelParts: baseRow.labelParts,
			baseLabel: formattedBase,
			nominationLabel: formattedNomination,
			totalLabel: formattedTotal,
		};
	}, [
		draft,
		menu,
		nominationEnabledForMenu,
		taxLabelText,
		currencySymbol,
		guestsUnitLabel,
	] );

	useEffect( () => {
		if ( ! draftToken ) {
			setLoadError(
				__(
					'The URL does not include a temporary reservation data parameter. Please try again from the reservation page.',
					'vk-booking-manager'
				)
			);
			setLoading( false );
			return;
		}

		setLoading( true );
		setLoadError( '' );
		apiFetch( { path: `/vkbm/v1/drafts/${ draftToken }` } )
			.then( ( data ) => {
				setDraft( data );
				setMemo( data?.memo || '' );
				const agreedAll = Boolean( data?.agree_terms );
				setAgreeCancellationPolicy(
					data?.agree_cancellation_policy !== undefined
						? Boolean( data?.agree_cancellation_policy )
						: agreedAll
				);
				setAgreeTermsOfService(
					data?.agree_terms_of_service !== undefined
						? Boolean( data?.agree_terms_of_service )
						: agreedAll
				);
			} )
			.catch( ( error ) => {
				setLoadError(
					resolveApiErrorMessage(
						error,
						__(
							"Couldn't load temporary reservation data. Please try again from the reservation page.",
							'vk-booking-manager'
						),
						canManageReservations
					)
				);
			} )
			.finally( () => setLoading( false ) );
	}, [ draftToken, canManageReservations ] );

	useEffect( () => {
		let isMounted = true;

		apiFetch( { path: '/vkbm/v1/provider-settings' } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				if (
					typeof response?.resource_label_singular === 'string' &&
					response.resource_label_singular.trim() !== ''
				) {
					setResourceLabelSingular(
						response.resource_label_singular
					);
				} else {
					setResourceLabelSingular(
						__( 'Staff', 'vk-booking-manager' )
					);
				}

				setProviderCancellationPolicy(
					typeof response?.cancellation_policy === 'string'
						? response.cancellation_policy
						: ''
				);
				setProviderTermsOfService(
					typeof response?.terms_of_service === 'string'
						? response.terms_of_service
						: ''
				);
				setFooterSettings( extractFooterSettings( response ) );
				setProviderPaymentMethod(
					typeof response?.payment_method === 'string'
						? response.payment_method
						: ''
				);
				setPendingCompleteMessage(
					typeof response?.booking_complete_message_pending ===
						'string'
						? response.booking_complete_message_pending
						: ''
				);
				setProviderName(
					typeof response?.provider_name === 'string'
						? response.provider_name
						: ''
				);
				setProviderLogoUrl(
					typeof response?.provider_logo_url === 'string'
						? response.provider_logo_url
						: ''
				);
				setProviderLogoWidth(
					Number( response?.provider_logo_width ) > 0
						? Number( response.provider_logo_width )
						: 0
				);
				setShowProviderLogo(
					Boolean( response?.reservation_show_provider_logo )
				);
				setShowProviderName(
					Boolean( response?.reservation_show_provider_name )
				);
				// #391: 指名の可否そのものはメニュー単位の draft.nomination_enabled を使うため、
				// ここではサイト全体の staff_enabled を別 state に保持しない（二重管理を避ける）。

				// 指名関連ラベルを設定値から取得する。空の場合は翻訳デフォルト値を使用する。
				if (
					typeof response?.no_nomination_label === 'string' &&
					response.no_nomination_label.trim() !== ''
				) {
					setNoNominationLabel( response.no_nomination_label );
				}
				if (
					typeof response?.nomination_fee_label === 'string' &&
					response.nomination_fee_label.trim() !== ''
				) {
					setNominationFeeLabel( response.nomination_fee_label );
				}

				// Set the other conditions label from provider settings.
				// 基本設定のその他条件ラベルを設定する。
				if (
					typeof response?.other_conditions_label === 'string' &&
					response.other_conditions_label.trim() !== ''
				) {
					setOtherConditionsLabel( response.other_conditions_label );
				}

				// 数量の見出し。REST が実効値を返すが、念のため空ならデフォルトへフォールバックする。
				if (
					typeof response?.guests_count_label === 'string' &&
					response.guests_count_label.trim() !== ''
				) {
					setGuestsCountLabel( response.guests_count_label );
				}
				// 数量の単位。REST が実効値（null はロケール既定に解決済み）を返す。
				// 空文字は単位なしを意味するため、空判定でのフォールバックは行わない。
				if ( typeof response?.guests_unit_label === 'string' ) {
					setGuestsUnitLabel( response.guests_unit_label );
				}

				setTaxLabelText(
					typeof response?.tax_label_text === 'string'
						? response.tax_label_text
						: ''
				);
				setCurrencySymbol(
					typeof response?.currency_symbol === 'string'
						? response.currency_symbol
						: ''
				);
				if (
					! reservationPageUrl &&
					typeof response?.reservation_page_url === 'string'
				) {
					setResolvedReservationPageUrl(
						response.reservation_page_url
					);
				}
			} )
			.catch( () => {
				// Ignore provider settings fetch errors (fallback to defaults).
			} );

		return () => {
			isMounted = false;
		};
	}, [ reservationPageUrl ] );

	useEffect( () => {
		if ( ! draft?.menu_id ) {
			setMenu( null );
			return;
		}
		apiFetch( {
			path: `/wp/v2/vkbm_service_menu/${ draft.menu_id }?_fields=id,title,meta`,
		} )
			.then( ( response ) => setMenu( response ) )
			.catch( () => setMenu( null ) );
	}, [ draft?.menu_id ] );

	useEffect( () => {
		if ( ! draft?.resource_id || ! nominationEnabledForMenu ) {
			setStaff( null );
			return;
		}
		apiFetch( {
			path: `/wp/v2/vkbm_resource/${ draft.resource_id }?_fields=id,title,meta`,
		} )
			.then( ( response ) => setStaff( response ) )
			.catch( () => setStaff( null ) );
	}, [ draft?.resource_id, nominationEnabledForMenu ] );

	useEffect( () => {
		let isMounted = true;

		if ( ! isLoggedIn ) {
			setCanManageReservations( false );
			setLogoutUrl( '' );
			setShiftDashboardUrl( '' );
			setSettingsUrl( '' );
			return () => {
				isMounted = false;
			};
		}

		if (
			userBootstrap &&
			typeof userBootstrap === 'object' &&
			typeof userBootstrap.logoutUrl === 'string' &&
			typeof userBootstrap.shiftDashboardUrl === 'string'
		) {
			setCanManageReservations(
				Boolean( userBootstrap.canManageReservations )
			);
			setLogoutUrl( userBootstrap.logoutUrl || '' );
			setShiftDashboardUrl( userBootstrap.shiftDashboardUrl || '' );
			setSettingsUrl( userBootstrap.settingsUrl || '' );
			return () => {
				isMounted = false;
			};
		}

		const currentUrl = getCurrentUrl();
		const path = buildApiPath( '/vkbm/v1/current-user', {
			redirect: currentUrl || undefined,
		} );

		apiFetch( { path } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setCanManageReservations(
					Boolean( response?.can_manage_reservations )
				);
				setLogoutUrl( response?.logout_url || '' );
				setShiftDashboardUrl( response?.shift_dashboard_url || '' );
				setSettingsUrl( response?.settings_url || '' );
			} )
			.catch( () => {
				if ( isMounted ) {
					setCanManageReservations( false );
					setLogoutUrl( '' );
					setShiftDashboardUrl( '' );
					setSettingsUrl( '' );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ isLoggedIn ] );

	useEffect( () => {
		if ( ! canManageReservations ) {
			return;
		}

		if ( authMode === 'profile' || authMode === 'bookings' ) {
			setAuthMode( '' );
		}
	}, [ canManageReservations, authMode ] );

	useEffect( () => {
		if ( ! canManageReservations ) {
			setCustomerName( '' );
			setCustomerPhone( '' );
			setProviderNote( '' );
		}
	}, [ canManageReservations ] );

	const getCurrentUrl = () => {
		if ( typeof window === 'undefined' ) {
			return '';
		}
		return window.location.href;
	};

	const buildModeUrl = ( mode ) => {
		if ( typeof window === 'undefined' ) {
			return '';
		}

		const url = new URL( window.location.href );
		if ( mode ) {
			url.searchParams.set( 'vkbm_auth', mode );
		} else {
			url.searchParams.delete( 'vkbm_auth' );
		}
		return url.toString();
	};

	const buildReservationModeUrl = useCallback(
		( mode ) => {
			if ( typeof window === 'undefined' ) {
				return '';
			}

			const baseUrl =
				resolvedReservationPageUrl || reservationPageUrl || '';
			const url = baseUrl
				? new URL( baseUrl, window.location.origin )
				: new URL( window.location.href );

			if ( mode ) {
				url.searchParams.set( 'vkbm_auth', mode );
			} else {
				url.searchParams.delete( 'vkbm_auth' );
			}

			url.searchParams.delete( 'draft' );

			return url.toString();
		},
		[ resolvedReservationPageUrl, reservationPageUrl ]
	);

	const toggleAuthMode = useCallback(
		( mode ) => {
			const nextMode = mode === authMode ? '' : mode;
			setAuthMode( nextMode );
			if ( typeof window === 'undefined' ) {
				return;
			}

			const url = new URL( window.location.href );
			if ( nextMode ) {
				url.searchParams.set( 'vkbm_auth', nextMode );
			} else {
				url.searchParams.delete( 'vkbm_auth' );
			}
			window.history.replaceState( null, '', url.toString() );
		},
		[ authMode ]
	);

	const handleBookingsClick = useCallback( () => {
		const target = buildReservationModeUrl( 'bookings' );
		if ( target ) {
			window.location.href = target;
			return;
		}
		toggleAuthMode( 'bookings' );
	}, [ buildReservationModeUrl, toggleAuthMode ] );

	const handleProfileClick = useCallback( () => {
		const target = buildReservationModeUrl( 'profile' );
		if ( target ) {
			window.location.href = target;
			return;
		}
		toggleAuthMode( 'profile' );
	}, [ buildReservationModeUrl, toggleAuthMode ] );

	const handleBackClick = useCallback( () => {
		const target = buildReservationModeUrl( '' );
		if ( target ) {
			window.location.href = target;
			return;
		}
		toggleAuthMode( '' );
	}, [ buildReservationModeUrl, toggleAuthMode ] );

	useEffect( () => {
		if ( ! authMode ) {
			setAuthFormHtml( '' );
			return;
		}

		if ( authMode === 'bookings' ) {
			setAuthFormHtml( '' );
			return;
		}

		const requiresLogin = authMode === 'profile' || authMode === 'bookings';
		if ( requiresLogin && ! isLoggedIn ) {
			setAuthFormHtml( '' );
			return;
		}

		if ( ! requiresLogin && isLoggedIn ) {
			setAuthFormHtml( '' );
			return;
		}

		// issue #512 植草指摘（ちらつき対策）: サーバー側フォールバックのエラー文を
		// 初回の auth-form 取得中は消さない（=まだ error パラメータを送っておらず、
		// かつ引き継いだ初期エラー文がある場合だけ）。それ以外（モード切替の再取得等）
		// は従来どおり毎回クリアする。
		const includeInitialLoginError =
			authMode === 'login' &&
			!! initialLoginError &&
			! initialLoginErrorSentRef.current;

		// issue #516: 会員登録失敗トークンも、初回の auth-form 取得時だけ送る。
		const includeInitialRegistrationErrorKey =
			authMode === 'register' &&
			!! initialRegistrationErrorKey &&
			! initialRegistrationErrorSentRef.current;

		setAuthLoading( true );
		if ( ! includeInitialLoginError ) {
			setAuthError( '' );
		}
		setAuthFormHtml( '' );

		const params = new URLSearchParams();
		params.set( 'type', authMode );
		const redirectUrl = getCurrentUrl();
		if ( redirectUrl ) {
			params.set( 'redirect', redirectUrl );
		}
		if ( authMode === 'login' ) {
			params.set( 'register_url', buildModeUrl( 'register' ) );

			// issue #512: サーバーで判明したログイン失敗コードは、初回の auth-form
			// 取得時だけ渡す。モード切替等での再取得では渡さない。
			if ( includeInitialLoginError ) {
				params.set( 'error', initialLoginError );
			}
		} else if ( authMode === 'register' ) {
			params.set( 'login_url', buildModeUrl( 'login' ) );

			// issue #516: `vkbm_registration_errors` Cookie の代わりに、サーバー側
			// transient への鍵となるトークンを渡す。トークンだけでは中身（エラー文・
			// 入力値）は分からない。
			if ( includeInitialRegistrationErrorKey ) {
				params.set(
					'registration_error_key',
					initialRegistrationErrorKey
				);
			}
		}

		initialLoginErrorSentRef.current = true;
		initialRegistrationErrorSentRef.current = true;

		apiFetch( {
			path: `/vkbm/v1/auth-form?${ params.toString() }`,
			cache: 'no-store',
			credentials: 'same-origin',
		} )
			.then( ( response ) => {
				const html = response?.html || '';
				const message = response?.message || '';

				setAuthFormHtml( html );

				if ( html ) {
					// issue #512: 実フォーム（エラー欄込み）が取得できたら、初期表示用の
					// トップレベルのエラー文は不要になるため消す（二重表示防止）。
					setAuthError( '' );
				} else if ( authMode === 'register' && message ) {
					setAuthError( message );
				}
			} )
			.catch( ( error ) => {
				setAuthError(
					resolveApiErrorMessage(
						error,
						__(
							'The form could not be displayed.',
							'vk-booking-manager'
						),
						canManageReservations
					)
				);
			} )
			.finally( () => setAuthLoading( false ) );
	}, [
		authMode,
		isLoggedIn,
		canManageReservations,
		initialLoginError,
		initialRegistrationErrorKey,
	] );

	// issue #512: 植草提案。auth-form の HTML（ログイン失敗文を含む）が描画されたら、
	// その中のエラー要素（role="alert"。Auth_Shortcodes::render_error_list() が
	// tabindex="-1" 付きで出力）へ一度だけフォーカスし、スクリーンリーダー利用者にも
	// 気づけるようにする。REST 応答待ちの間は authFormHtml が空のままのため消えない。
	useEffect( () => {
		if ( ! authFormHtml || ! authFormContainerRef.current ) {
			return;
		}

		const errorEl =
			authFormContainerRef.current.querySelector( '[role="alert"]' );
		if ( errorEl ) {
			errorEl.focus();
		}
	}, [ authFormHtml ] );

	const handleCancelBooking = useCallback(
		( bookingId ) => {
			const id = Number( bookingId ) || 0;
			if ( ! id || cancellingBookingId ) {
				return;
			}

			if (
				typeof window !== 'undefined' &&
				! window.confirm(
					__(
						'Do you want to cancel this reservation?',
						'vk-booking-manager'
					)
				)
			) {
				return;
			}

			setCancellingBookingId( id );
			setBookingsError( '' );

			apiFetch( {
				path: `/vkbm/v1/my-bookings/${ id }/cancel`,
				method: 'POST',
			} )
				.then( () => {
					setBookings( ( current ) =>
						Array.isArray( current )
							? current.filter(
									( booking ) => Number( booking?.id ) !== id
							  )
							: []
					);
				} )
				.catch( ( error ) => {
					setBookingsError(
						resolveApiErrorMessage(
							error,
							__(
								'I was unable to cancel my reservation.',
								'vk-booking-manager'
							),
							canManageReservations
						)
					);
				} )
				.finally( () => {
					setCancellingBookingId( 0 );
				} );
		},
		[ cancellingBookingId, canManageReservations ]
	);

	useEffect( () => {
		if (
			isLoggedIn &&
			authMode &&
			authMode !== 'profile' &&
			authMode !== 'bookings'
		) {
			setAuthMode( '' );
		}

		if (
			! isLoggedIn &&
			( authMode === 'profile' || authMode === 'bookings' )
		) {
			setAuthMode( '' );
		}
	}, [ isLoggedIn, authMode ] );

	useEffect( () => {
		let isMounted = true;

		if ( ! isLoggedIn || authMode !== 'bookings' ) {
			return () => {
				isMounted = false;
			};
		}

		setBookingsLoading( true );
		setBookingsError( '' );

		apiFetch( { path: '/vkbm/v1/my-bookings' } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setBookings( Array.isArray( response ) ? response : [] );
			} )
			.catch( () => {
				if ( ! isMounted ) {
					return;
				}

				setBookings( [] );
				setBookingsError(
					__(
						'The reservation list could not be loaded.',
						'vk-booking-manager'
					)
				);
			} )
			.finally( () => {
				if ( isMounted ) {
					setBookingsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ authMode, isLoggedIn ] );

	// 今回の予約内容（メニュー＋指名スタッフ）をお気に入り（いつもの）に登録する。
	// 確認画面のチェックボックスがONのとき、予約確定後に呼び出す。
	// 完了後にリダイレクトする場合に待ち合わせできるよう、Promise を返す。
	const registerFavorite = () => {
		const favoriteMenuId = Number( draft?.menu_id ) || 0;
		if ( favoriteMenuId <= 0 ) {
			return Promise.resolve();
		}

		setFavoriteError( '' );

		return apiFetch( {
			path: '/vkbm/v1/favorites',
			method: 'POST',
			data: {
				menu_id: favoriteMenuId,
				// 実際に指名した予約（is_staff_preferred=true）のときだけスタッフIDを送る。
				// おまかせ・指名なし・無料版・メニュー単位で指名OFFのときは is_staff_preferred=false のため 0 を送る。
				// サーバ側（draft.is_staff_preferred）で既に確定済みの値なので、フロント側で
				// 指名可否（nominationEnabledForMenu）を重ねて判定する必要はない。
				resource_id: draft?.is_staff_preferred
					? Number( draft?.resource_id ) || 0
					: 0,
			},
		} )
			.then( () => {
				setFavoriteSaved( true );
			} )
			.catch( ( error ) => {
				// 重複登録（既にお気に入り済み）も登録済み扱いにする。
				if ( error?.code === 'duplicate_favorite' ) {
					setFavoriteSaved( true );
					return;
				}
				setFavoriteError(
					resolveApiErrorMessage(
						error,
						__(
							'Could not add to favorites.',
							'vk-booking-manager'
						),
						canManageReservations
					)
				);
			} );
	};

	const handleConfirm = async () => {
		if ( ! draftToken ) {
			return;
		}

		const cancellationText =
			typeof providerCancellationPolicy === 'string' &&
			providerCancellationPolicy.trim() !== ''
				? providerCancellationPolicy
				: '';
		const termsText =
			typeof providerTermsOfService === 'string' &&
			providerTermsOfService.trim() !== ''
				? providerTermsOfService
				: '';
		const requiresAgreements = ! canManageReservations;

		if (
			requiresAgreements &&
			cancellationText &&
			! agreeCancellationPolicy
		) {
			setSubmitError(
				__(
					'You must agree to the cancellation policy.',
					'vk-booking-manager'
				)
			);
			return;
		}

		if ( requiresAgreements && termsText && ! agreeTermsOfService ) {
			setSubmitError(
				__(
					'You must agree to the terms of use.',
					'vk-booking-manager'
				)
			);
			return;
		}

		const agreedAll = requiresAgreements
			? ( ! cancellationText || agreeCancellationPolicy ) &&
			  ( ! termsText || agreeTermsOfService )
			: true;

		setSubmitting( true );
		setSubmitError( '' );
		const payload = {
			token: draftToken,
			memo,
			agree_terms: agreedAll,
			agree_cancellation_policy: requiresAgreements
				? agreeCancellationPolicy
				: true,
			agree_terms_of_service: requiresAgreements
				? agreeTermsOfService
				: true,
		};

		if ( canManageReservations ) {
			payload.customer_name = customerName;
			payload.customer_phone = customerPhone;
			payload.internal_note = providerNote;
		}
		if ( window.vkbmPaymentConfig?.payment_enabled ) {
			payload.payment_method = paymentMethod;
		}

		// 決済プラグインが window.vkbmOnBeforeConfirm を設定している場合に呼び出す。
		// - false を返すと処理を中断（プラグイン側が決済エラーを表示）。
		// - オブジェクトを返すと payload にマージする（例: payment_intent_id を追加）。
		let paymentHookMerged = false;
		if ( typeof window.vkbmOnBeforeConfirm === 'function' ) {
			let hookResult;
			try {
				hookResult = await window.vkbmOnBeforeConfirm( payload, draft );
			} catch ( hookError ) {
				setSubmitError(
					hookError?.message ||
						__(
							'An error occurred during payment processing.',
							'vk-booking-manager'
						)
				);
				setSubmitting( false );
				return;
			}
			if ( hookResult === false ) {
				setSubmitting( false );
				return;
			}
			if ( hookResult && typeof hookResult === 'object' ) {
				Object.assign( payload, hookResult );
				paymentHookMerged = true;
			}
		}

		// 予約が作成されたか（予約 API が成功応答を返したか）。
		// 成功後の画面遷移などで例外が起きても、予約失敗の通知を送らないために使う。
		let bookingCreated = false;

		apiFetch( {
			path: '/vkbm/v1/bookings',
			method: 'POST',
			data: payload,
			parse: false,
		} )
			.then( async ( response ) => {
				// 成功応答（2xx）なら、この時点で予約は作成済み。本文の読み取りに失敗しても
				// 予約失敗の通知を送らないよう、本文を読む前に記録する。
				if ( response.ok ) {
					bookingCreated = true;
				}

				const raw = await response.text();
				let parsed;
				try {
					parsed = parseJsonWithFallback( raw );
				} catch ( parseError ) {
					parsed = null;
				}

				if ( ! response.ok ) {
					// apiFetch（parse: false）は HTTP エラー時に reject するため通常ここには来ないが、
					// 念のため catch 側と同じ形（status / code / message）の失敗として扱う。
					// Log error only in development mode
					if ( process.env.NODE_ENV === 'development' ) {
						// eslint-disable-next-line no-console
						console.error( 'vkbm booking confirm: request failed', {
							status: response?.status,
							parsed,
							raw,
						} );
					}
					const failureError = new Error(
						typeof parsed?.message === 'string'
							? parsed.message
							: ''
					);
					failureError.code =
						typeof parsed?.code === 'string' ? parsed.code : '';
					failureError.status = response.status;
					throw failureError;
				}

				setCreatedStatus( parsed?.status || '' );
				setSuccess( true );

				// 完了後のリダイレクト処理。
				const navigate = () => {
					if ( ! redirectUrl ) {
						return;
					}
					const target = new URL(
						redirectUrl,
						typeof window !== 'undefined'
							? window.location.origin
							: undefined
					);
					if ( parsed?.booking_id ) {
						target.searchParams.set(
							'booking_id',
							String( parsed.booking_id )
						);
					}
					window.location.href = target.toString();
				};

				// チェックが入っていれば、今回の内容をお気に入りに登録する。
				// リダイレクトする場合は、POST が中断されないよう登録の完了を待つ。
				if ( favoriteOptIn && isLoggedIn ) {
					registerFavorite().finally( navigate );
				} else {
					navigate();
				}
			} )
			.catch( async ( error ) => {
				// HTTP エラー（Response）・通信エラー・解析失敗のいずれでも、code / message を取り出す。
				const failure = await normalizeBookingError( error );

				// 決済プラグインの結果を payload にマージしていて、予約が作られなかった場合だけ、
				// 決済プラグインへ失敗を伝える（window.vkbmOnBookingFailed。仮押さえの取り消しに使われる）。
				// エラー表示を遅らせないよう、フックの完了は待たない（notifyBookingFailed() の説明を参照）。
				if ( paymentHookMerged && ! bookingCreated ) {
					notifyBookingFailed( payload, failure );
				}

				// 「同じ日時の予約があります」は、エラーコード booking_time_conflict のときだけ表示する。
				// 409 はほかの理由（空き枠の変化・リソースタグの不一致・決済の二重使用など）でも返るため、
				// それ以外は REST のエラーメッセージをそのまま表示する。
				if ( isBookingTimeConflict( failure ) ) {
					setSubmitError(
						__(
							'A reservation for the same date and time already exists. Please change the date and time.',
							'vk-booking-manager'
						)
					);
					return;
				}

				// 仮予約データの有効期限切れなどで使えない場合は、読み込み処理の予備の文言（同じ msgid）を使い、
				// 予約ページからやり直すよう案内する（サーバーの「予約一時データが見つかりません。」は
				// 次の行動が分からないため）。
				if ( isDraftUnavailable( failure ) ) {
					setSubmitError(
						__(
							"Couldn't load temporary reservation data. Please try again from the reservation page.",
							'vk-booking-manager'
						)
					);
					return;
				}

				setSubmitError(
					resolveApiErrorMessage(
						failure,
						__(
							'Confirmation of reservation failed. Please try again later.',
							'vk-booking-manager'
						),
						canManageReservations
					)
				);
			} )
			.finally( () => setSubmitting( false ) );
	};

	useEffect( () => {
		if ( ! success || redirectUrl ) {
			return;
		}

		if ( typeof window === 'undefined' ) {
			return;
		}

		const url = new URL( window.location.href );
		if ( ! url.searchParams.has( 'draft' ) ) {
			return;
		}

		url.searchParams.delete( 'draft' );
		window.history.replaceState( null, '', url.toString() );
	}, [ success, redirectUrl ] );

	// 予約完了時に完了ブロックへフォーカスを移し、支援技術に完了を伝える。
	useEffect( () => {
		if ( success && ! redirectUrl ) {
			completeNoticeRef.current?.focus();
		}
	}, [ success, redirectUrl ] );

	if ( loading ) {
		return (
			<div className="vkbm-confirm">
				<div className="vkbm-alert vkbm-alert__info" role="status">
					{ __(
						'Loading temporary reservation data…',
						'vk-booking-manager'
					) }
				</div>
			</div>
		);
	}

	if ( loadError ) {
		return (
			<div className="vkbm-confirm">
				<div className="vkbm-alert vkbm-alert__danger" role="alert">
					{ loadError }
				</div>
				{ /* 設定を取得済みのときだけ出す（未取得なら model が null で何も出ない）。 */ }
				<ReservationFooter
					model={ buildFooterModel( footerSettings ) }
				/>
			</div>
		);
	}

	if ( ! draft ) {
		return null;
	}

	// 仮予約は「見出し＋案内文」、即時確定は従来どおり見出しのみ（本文なし）。
	const isPendingComplete = createdStatus === 'pending';
	const completeTitle = isPendingComplete
		? __(
				'Your tentative reservation has been received.',
				'vk-booking-manager'
		  )
		: successMessage;
	const completeMessage = isPendingComplete
		? pendingCompleteMessage ||
		  __(
				'We will check the details and email you once your reservation is confirmed. Please wait for that email. You can close this page.',
				'vk-booking-manager'
		  )
		: '';

	const menuName =
		draft.menu_label ||
		menu?.title?.rendered ||
		menu?.title ||
		menu?.name ||
		String( draft.menu_id );

	const staffName =
		draft.staff_label ||
		draft.slot?.staff_label ||
		draft.slot?.staff?.name ||
		staff?.title?.rendered ||
		staff?.title ||
		staff?.name ||
		noNominationLabel;

	const logoutHref = logoutUrl || buildLogoutFallbackUrl();
	const showSuccessMessage = success && ! redirectUrl;

	const renderMemoField = () => {
		// バックエンド (Booking_Draft_Controller::resolve_memo_max_length) と
		// 揃えた上限値を `window.vkbmReservationConfig.memoMaxLength` から受け取る。
		// フィルタ vkbm_draft_memo_max_length を反映した値が入っている。
		// 設定が拾えない場面では maxLength を付けない（バックエンド側で確実に
		// 切り詰めるため UX 上の影響は許容範囲）。
		const memoMaxLength =
			typeof window !== 'undefined' &&
			window.vkbmReservationConfig &&
			Number.isInteger( window.vkbmReservationConfig.memoMaxLength ) &&
			window.vkbmReservationConfig.memoMaxLength > 0
				? window.vkbmReservationConfig.memoMaxLength
				: undefined;

		const commonTextarea = (
			<textarea
				id="vkbm-confirm-memo"
				value={ memo }
				onChange={ ( event ) => setMemo( event.target.value ) }
				maxLength={ memoMaxLength }
			/>
		);

		if ( ! showSuccessMessage ) {
			return commonTextarea;
		}

		return (
			<textarea
				id="vkbm-confirm-memo"
				value={ memo }
				readOnly
				placeholder={ __( 'No entry', 'vk-booking-manager' ) }
			/>
		);
	};

	const renderAgreements = () => {
		if ( showSuccessMessage || canManageReservations ) {
			return null;
		}

		const cancellationPolicyText =
			typeof providerCancellationPolicy === 'string' &&
			providerCancellationPolicy.trim() !== ''
				? providerCancellationPolicy
				: '';
		const termsOfServiceText =
			typeof providerTermsOfService === 'string' &&
			providerTermsOfService.trim() !== ''
				? providerTermsOfService
				: '';

		// 決済プラグインが有効なときは、案内文が空でも決済方法の選択肢を出すためブロックを表示する。
		const isPaymentEnabled = Boolean(
			window.vkbmPaymentConfig?.payment_enabled
		);

		const hasAgreements = Boolean(
			cancellationPolicyText ||
				termsOfServiceText ||
				paymentMethodText ||
				isPaymentEnabled
		);

		return (
			<>
				{ hasAgreements && (
					<div className="vkbm-agreements">
						{ cancellationPolicyText && (
							<div className="vkbm-agreement">
								<h3 className="vkbm-agreement__title">
									{ __(
										'Cancellation policy',
										'vk-booking-manager'
									) }
								</h3>
								<div className="vkbm-agreement__body vkbm-agreement__body--full">
									{ cancellationPolicyText }
								</div>
								<div className="vkbm-agreement__check">
									<input
										type="checkbox"
										checked={ agreeCancellationPolicy }
										onChange={ ( event ) =>
											setAgreeCancellationPolicy(
												event.target.checked
											)
										}
										id="vkbm-confirm-cancellation-policy"
									/>
									<label htmlFor="vkbm-confirm-cancellation-policy">
										{ __(
											'I agree to the cancellation policy',
											'vk-booking-manager'
										) }
									</label>
								</div>
							</div>
						) }

						{ termsOfServiceText && (
							<div className="vkbm-agreement">
								<h3 className="vkbm-agreement__title">
									{ __(
										'System Terms of Use',
										'vk-booking-manager'
									) }
								</h3>
								<div className="vkbm-agreement__body vkbm-agreement__body--scroll">
									{ termsOfServiceText }
								</div>
								<div className="vkbm-agreement__check">
									<input
										type="checkbox"
										checked={ agreeTermsOfService }
										onChange={ ( event ) =>
											setAgreeTermsOfService(
												event.target.checked
											)
										}
										id="vkbm-confirm-terms"
									/>
									<label htmlFor="vkbm-confirm-terms">
										{ termsLabel }
									</label>
								</div>
							</div>
						) }
						{ /* 決済方法: 見出し → 選択肢（決済プラグイン有効時のみ） → 事業者の案内文（入力時のみ）の順に並べる。 */ }
						{ ( paymentMethodText || isPaymentEnabled ) && (
							<div className="vkbm-agreement">
								<h3
									id="vkbm-payment-method-guest-label"
									className="vkbm-agreement__title"
								>
									{ __(
										'Payment method',
										'vk-booking-manager'
									) }
								</h3>
								{ isPaymentEnabled && (
									<div
										className="vkbm-confirm__payment-method-options"
										role="radiogroup"
										aria-labelledby="vkbm-payment-method-guest-label"
										// 案内文があるときだけ説明として関連付ける（空なら属性自体を出さない）。
										aria-describedby={
											paymentMethodText
												? 'vkbm-payment-method-guest-description'
												: undefined
										}
									>
										<label htmlFor="vkbm-payment-method-guest-onsite">
											<input
												type="radio"
												id="vkbm-payment-method-guest-onsite"
												name="vkbm-payment-method-guest"
												value="onsite"
												checked={
													paymentMethod === 'onsite'
												}
												onChange={ () =>
													setPaymentMethod( 'onsite' )
												}
											/>
											{ __(
												'On-site payment',
												'vk-booking-manager'
											) }
										</label>
										<label htmlFor="vkbm-payment-method-guest-card">
											<input
												type="radio"
												id="vkbm-payment-method-guest-card"
												name="vkbm-payment-method-guest"
												value="card"
												checked={
													paymentMethod === 'card'
												}
												onChange={ () =>
													setPaymentMethod( 'card' )
												}
											/>
											{ __(
												'Card payment (Stripe)',
												'vk-booking-manager'
											) }
										</label>
									</div>
								) }
								{ /* 案内文は選択中の決済方法にかかわらず常に表示する。 */ }
								{ paymentMethodText && (
									<div
										id="vkbm-payment-method-guest-description"
										className="vkbm-agreement__body"
									>
										{ paymentMethodText }
									</div>
								) }
							</div>
						) }
					</div>
				) }

				{ ! termsOfServiceText && policyText && (
					<p className="vkbm-confirm__policy">{ policyText }</p>
				) }

				{ /* ログインユーザーには、今回の内容を「いつもの」として登録するチェックを表示する。 */ }
				{ isLoggedIn && Number( draft?.menu_id ) > 0 && (
					<div className="vkbm-agreement__check vkbm-favorites__optin">
						<input
							type="checkbox"
							checked={ favoriteOptIn }
							onChange={ ( event ) =>
								setFavoriteOptIn( event.target.checked )
							}
							id="vkbm-confirm-favorite-optin"
						/>
						<label htmlFor="vkbm-confirm-favorite-optin">
							{ __(
								'Save these details so you can quickly book the same thing again next time (add to favorites)',
								'vk-booking-manager'
							) }
						</label>
					</div>
				) }
			</>
		);
	};

	const cancellationPolicyRequired =
		typeof providerCancellationPolicy === 'string' &&
		providerCancellationPolicy.trim() !== '';
	const termsOfServiceRequired =
		typeof providerTermsOfService === 'string' &&
		providerTermsOfService.trim() !== '';
	const paymentMethodText =
		typeof providerPaymentMethod === 'string' &&
		providerPaymentMethod.trim() !== ''
			? providerPaymentMethod
			: '';
	const canSubmit =
		isLoggedIn &&
		! submitting &&
		( canManageReservations ||
			! cancellationPolicyRequired ||
			agreeCancellationPolicy ) &&
		( canManageReservations ||
			! termsOfServiceRequired ||
			agreeTermsOfService );

	const datetimeParts = formatBookingDateTimeParts(
		draft?.slot?.start_at || '',
		draft?.slot?.service_end_at || draft?.slot?.end_at || '',
		timezone
	);

	const hasGuestTiers =
		Array.isArray( draft?.guest_tiers ) && draft.guest_tiers.length > 0;

	// 宿泊（泊単位のプラン）の下書きか。サーバーが検証した泊の情報（draft.lodging）の有無で判定する。
	// チェックイン／チェックアウトの日時・泊数・金額はサーバーの計算値をそのまま表示する。
	const isLodgingDraft = Boolean( draft?.lodging );
	const lodgingCheckIn = isLodgingDraft
		? formatBookingDateTimeParts(
				draft?.slot?.start_at || '',
				'',
				timezone
		  )
		: null;
	const lodgingCheckOut = isLodgingDraft
		? formatBookingDateTimeParts( draft?.slot?.end_at || '', '', timezone )
		: null;

	// 最小催行人数（グループ開催型）が設定された枠で、今回の予約を反映しても催行人数に満たない場合だけ
	// 「開催されない可能性がある」旨を注記する。催行確定済み（充足済み）の枠では表示しない。
	// 既存予約の合計人数（slot.booked_guests）に今回予約分（draft.guests）を加えて未達かを判定する。
	const minCapacityForSlot = Math.max(
		0,
		Number( draft?.slot?.min_capacity ) || 0
	);
	const bookedGuestsForSlot = Math.max(
		0,
		Number( draft?.slot?.booked_guests ) || 0
	);
	const draftGuestsForSlot = Math.max( 0, Number( draft?.guests ) || 0 );
	const showMinCapacityNote =
		minCapacityForSlot > 0 &&
		bookedGuestsForSlot + draftGuestsForSlot < minCapacityForSlot;

	const shouldShowLogo =
		showProviderLogo &&
		typeof providerLogoUrl === 'string' &&
		providerLogoUrl.trim() !== '';
	const shouldShowName =
		showProviderName &&
		typeof providerName === 'string' &&
		providerName.trim() !== '';
	const shouldShowBrand = shouldShowLogo || shouldShowName;
	const brandLinkHref =
		typeof resolvedReservationPageUrl === 'string'
			? resolvedReservationPageUrl.trim()
			: '';
	const brandLogoAlt = shouldShowName
		? providerName
		: __( 'Logo image', 'vk-booking-manager' );
	const navAriaLabel = __(
		'Reservation block navigation',
		'vk-booking-manager'
	);
	const navVariant =
		isLoggedIn && canManageReservations
			? 'staff'
			: isLoggedIn && ! canManageReservations && showSuccessMessage
			? 'return'
			: isLoggedIn && ! canManageReservations
			? 'member'
			: '';
	const navProps = navVariant
		? {
				screen: 'booking-confirm',
				variant: navVariant,
				ariaLabel: navAriaLabel,
				staffDashboardHref: shiftDashboardUrl || '',
				staffSettingsHref: settingsUrl || '',
				logoutHref,
				onReturn: handleBackClick,
				onBookings: handleBookingsClick,
				isBookingsActive: authMode === 'bookings',
				onProfile: handleProfileClick,
				isProfileActive: authMode === 'profile',
		  }
		: null;

	return (
		<div className="vkbm-confirm">
			<ReservationHeader
				showBrand={ shouldShowBrand }
				brandLinkHref={ brandLinkHref }
				showLogo={ shouldShowLogo }
				logoUrl={ providerLogoUrl }
				logoWidth={ providerLogoWidth }
				logoAlt={ brandLogoAlt }
				showName={ shouldShowName }
				brandName={ providerName }
				nav={ navProps }
			/>
			{ isLoggedIn && authMode === 'profile' && (
				<div className="vkbm-confirm__auth-panel">
					{ authLoading && (
						<p
							className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading"
							role="status"
						>
							{ __( 'Loading form…', 'vk-booking-manager' ) }
						</p>
					) }
					{ authError && (
						<p
							className="vkbm-alert vkbm-alert__danger vkbm-confirm__auth-error"
							role="alert"
						>
							{ authError }
						</p>
					) }
					{ authFormHtml && (
						<div
							ref={ authFormContainerRef }
							className="vkbm-confirm__auth-form"
							dangerouslySetInnerHTML={ {
								__html: authFormHtml,
							} }
						/>
					) }
				</div>
			) }
			{ isLoggedIn && authMode === 'bookings' && (
				<div className="vkbm-confirm__auth-panel">
					{ bookingsLoading && (
						<p
							className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading"
							role="status"
						>
							{ __( 'Loading…', 'vk-booking-manager' ) }
						</p>
					) }
					{ bookingsError && (
						<p
							className="vkbm-alert vkbm-alert__danger vkbm-confirm__auth-error"
							role="alert"
						>
							{ bookingsError }
						</p>
					) }
					{ ! bookingsLoading &&
						! bookingsError &&
						bookings.length === 0 && (
							<p
								className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading"
								role="status"
							>
								{ __(
									'There are no reservations to display.',
									'vk-booking-manager'
								) }
							</p>
						) }
					{ bookings.length > 0 && (
						<div className="vkbm-confirm">
							{ bookings.map( ( booking ) => {
								const bookingDatetimeParts =
									formatBookingDateTimeParts(
										booking?.start_at || '',
										booking?.end_at || '',
										timezone
									);
								// 泊の予約は、チェックイン／チェックアウトの日時を2行で表示する。
								const isStay = isStayBooking( booking );
								const checkInParts = isStay
									? formatBookingDateTimeParts(
											booking?.start_at || '',
											'',
											timezone
									  )
									: null;
								const checkOutParts = isStay
									? formatBookingDateTimeParts(
											booking?.end_at || '',
											'',
											timezone
									  )
									: null;
								const statusKey = String(
									booking?.status || ''
								).trim();
								const isCancelled =
									statusKey.toLowerCase() === 'cancelled';
								return (
									<div
										className={ [
											'vkbm-confirm__summary',
											isCancelled &&
												'vkbm-confirm__summary--cancelled',
										]
											.filter( Boolean )
											.join( ' ' ) }
										key={
											booking?.id ||
											bookingDatetimeParts.date
										}
									>
										{ ( bookingDatetimeParts.date ||
											bookingDatetimeParts.time ) && (
											<div className="vkbm-confirm__summary-title">
												<div
													className="vkbm-confirm__datetime"
													aria-label={ __(
														'Reservation date and time',
														'vk-booking-manager'
													) }
												>
													{ isCancelled && (
														<span className="vkbm-confirm__status vkbm-confirm__status--cancelled">
															{ __(
																'Cancelled',
																'vk-booking-manager'
															) }
														</span>
													) }
													{ isStay && (
														<BookingStayDatetime
															checkIn={ `${ checkInParts.date } ${ checkInParts.time }`.trim() }
															checkOut={ `${ checkOutParts.date } ${ checkOutParts.time }`.trim() }
														/>
													) }
													{ ! isStay &&
														bookingDatetimeParts.date && (
															<span className="vkbm-confirm__date">
																{
																	bookingDatetimeParts.date
																}
															</span>
														) }
													{ ! isStay &&
														bookingDatetimeParts.time && (
															<span className="vkbm-confirm__time">
																{ bookingDatetimeParts.date
																	? ' '
																	: '' }
																{
																	bookingDatetimeParts.time
																}
															</span>
														) }
												</div>
												{ booking?.can_cancel && (
													<button
														type="button"
														className="vkbm-button vkbm-button__sm vkbm-button__secondary vkbm-confirm__cancel-button"
														onClick={ () =>
															handleCancelBooking(
																booking?.id
															)
														}
														disabled={
															cancellingBookingId ===
															Number(
																booking?.id
															)
														}
													>
														{ cancellingBookingId ===
														Number( booking?.id )
															? __(
																	'Processing…',
																	'vk-booking-manager'
															  )
															: __(
																	'Cancel',
																	'vk-booking-manager'
															  ) }
													</button>
												) }
											</div>
										) }
										<BookingSummaryItems
											booking={ booking }
											resourceLabel={
												resourceLabelSingular
											}
											otherConditionsLabel={
												otherConditionsLabel
											}
											guestsCountLabel={
												guestsCountLabel
											}
											guestsUnitLabel={ guestsUnitLabel }
											emptyValue="—"
											currencySymbol={
												currencySymbol.trim() !== ''
													? currencySymbol
													: null
											}
										/>
									</div>
								);
							} ) }
						</div>
					) }
				</div>
			) }

			{ authMode !== 'bookings' && (
				<>
					<div className="vkbm-confirm__summary">
						{ isLodgingDraft ? (
							<Suspense
								fallback={
									<p
										className="vkbm-alert vkbm-alert__info"
										role="status"
									>
										{ __(
											'Loading…',
											'vk-booking-manager'
										) }
									</p>
								}
							>
								<LodgingConfirmSummary
									menuName={ menuName }
									roomLabel={ resourceLabelSingular }
									roomName={ staffName }
									checkIn={ lodgingCheckIn }
									checkOut={ lodgingCheckOut }
									nights={
										Number( draft.lodging.nights ) || 0
									}
									guestsLabel={ guestsCountLabel }
									guestsText={ formatGuestsCount(
										Number( draft.guests ),
										guestsUnitLabel
									) }
									pricePerNightText={
										draft.lodging.price_per_night ===
											undefined ||
										draft.lodging.price_per_night ===
											null ||
										draft.lodging.price_per_night === ''
											? ''
											: formatCurrency(
													Number(
														draft.lodging
															.price_per_night
													) || 0,
													currencySymbol.trim() !== ''
														? currencySymbol
														: null
											  )
									}
									nightBreakdown={
										draft.lodging.night_breakdown
									}
									nightRates={ draft.lodging.night_rates }
									isRateTiersEnabled={
										draft.lodging.rate_tiers_enabled ===
										true
									}
									locale={ userBootstrap?.locale }
									currencySymbol={
										currencySymbol.trim() !== ''
											? currencySymbol
											: null
									}
									totalText={
										pricingSummary.totalLabel ||
										pricingSummary.baseLabel
									}
								/>
							</Suspense>
						) : (
							<>
								{ ( datetimeParts.date ||
									datetimeParts.time ) && (
									<div className="vkbm-confirm__summary-title">
										<div
											className="vkbm-confirm__datetime"
											aria-label={ __(
												'Reservation date and time',
												'vk-booking-manager'
											) }
										>
											{ datetimeParts.date && (
												<span className="vkbm-confirm__date">
													{ datetimeParts.date }
												</span>
											) }
											{ datetimeParts.time && (
												<span className="vkbm-confirm__time">
													{ datetimeParts.date
														? ' '
														: '' }
													{ datetimeParts.time }
												</span>
											) }
										</div>
									</div>
								) }
								<SummaryRow
									label={ __( 'Menu', 'vk-booking-manager' ) }
									value={ menuName }
								/>
								{ /* 料金区分メニュー: 区分ごとに「区分名（単価 × 人数）」と小計を表示する（0名の区分は省略）。 */ }
								{ hasGuestTiers ? (
									<>
										{ pricingSummary.tierRows.map(
											( tierRow, index ) => (
												<SummaryRow
													key={ index }
													label={
														// #503: 「（単価 × 人数）」の括弧の途中で改行させない（予約画面と同じ）。
														<PricingRowLabel
															label={
																tierRow.label
															}
															labelParts={
																tierRow.labelParts
															}
														/>
													}
													value={ tierRow.value }
												/>
											)
										) }
										<SummaryRow
											label={ guestsCountLabel }
											value={ formatGuestsCount(
												Number( draft.guests ),
												guestsUnitLabel
											) }
										/>
									</>
								) : (
									Number( draft?.guests ) > 1 && (
										<SummaryRow
											label={ guestsCountLabel }
											value={ formatGuestsCount(
												Number( draft.guests ),
												guestsUnitLabel
											) }
										/>
									)
								) }
								{ nominationEnabledForMenu && (
									<SummaryRow
										label={ resourceLabelSingular }
										value={ staffName }
									/>
								) }
								{ /* 基本料金は指名機能の ON/OFF に関わらず常に表示する（#247）。ただし料金区分メニューでは区分ごとの小計が上に表示され、この行は常に0円で冗長になるため非表示にする（#338）。 */ }
								{ ! hasGuestTiers && (
									<SummaryRow
										label={
											// #503: 「（単価 × 人数）」の括弧の途中で改行させない（予約画面と同じ）。
											<PricingRowLabel
												label={
													pricingSummary.baseRowLabel
												}
												labelParts={
													pricingSummary.baseRowLabelParts
												}
											/>
										}
										value={ pricingSummary.baseLabel }
									/>
								) }
								{ /* 指名料はこのメニューで指名機能 ON のときのみ表示する（#391）。 */ }
								{ nominationEnabledForMenu && (
									<SummaryRow
										label={ nominationFeeLabel }
										value={
											pricingSummary.nominationLabel ||
											formatCurrency(
												0,
												currencySymbol.trim() !== ''
													? currencySymbol
													: null
											)
										}
									/>
								) }
								{ /* 貸し切り料金行（#305）。確認画面の draft は読み込み時に確定し以降変化しないため、他の行と同様に条件描画のみ（live region 不要）。 */ }
								{ Boolean( draft?.user_exclusive ) &&
									( Number( draft?.exclusive_fee ) > 0 ? (
										<SummaryRow
											label={ __(
												'Private booking fee',
												'vk-booking-manager'
											) }
											value={
												typeof draft?.exclusive_fee_formatted ===
													'string' &&
												draft.exclusive_fee_formatted.trim() !==
													''
													? draft.exclusive_fee_formatted
													: formatCurrency(
															Number(
																draft.exclusive_fee
															) || 0,
															currencySymbol.trim() !==
																''
																? currencySymbol
																: null
													  )
											}
										/>
									) : (
										<SummaryRow
											label={ __(
												'Private booking',
												'vk-booking-manager'
											) }
											value={ __(
												'Private (exclusive use of this slot)',
												'vk-booking-manager'
											) }
										/>
									) ) }
								{ /* 合計も指名機能の ON/OFF に関わらず常に表示する（#247）。 */ }
								<SummaryRow
									label={ __(
										'Total basic fee',
										'vk-booking-manager'
									) }
									value={
										pricingSummary.totalLabel ||
										pricingSummary.baseLabel
									}
									valueClassName="vkbm-confirm__summary-item-value--price"
								/>
							</>
						) }
					</div>

					{ ! isLoggedIn && (
						<div className="vkbm-confirm__auth">
							<p className="vkbm-confirm__auth-label">
								{ __(
									'You must log in to confirm your reservation.',
									'vk-booking-manager'
								) }
							</p>
							<div className="vkbm-buttons vkbm-buttons__center">
								<button
									type="button"
									className={ [
										'vkbm-button',
										'vkbm-button__primary',
										authMode === 'login' && 'is-active',
									]
										.filter( Boolean )
										.join( ' ' ) }
									onClick={ () => toggleAuthMode( 'login' ) }
								>
									{ __( 'Log in', 'vk-booking-manager' ) }
								</button>
								<button
									type="button"
									className={ [
										'vkbm-button',
										'vkbm-button-outline',
										'vkbm-button-outline__primary',
										authMode === 'register' && 'is-active',
									]
										.filter( Boolean )
										.join( ' ' ) }
									onClick={ () =>
										toggleAuthMode( 'register' )
									}
								>
									{ __( 'Sign up', 'vk-booking-manager' ) }
								</button>
							</div>
							{ authMode && (
								<div className="vkbm-confirm__auth-panel">
									{ authLoading && (
										<p
											className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading"
											role="status"
										>
											{ __(
												'Loading form…',
												'vk-booking-manager'
											) }
										</p>
									) }
									{ authError && (
										<p
											className="vkbm-alert vkbm-alert__danger vkbm-confirm__auth-error"
											role="alert"
										>
											{ authError }
										</p>
									) }
									{ authFormHtml && (
										<div
											ref={ authFormContainerRef }
											className="vkbm-confirm__auth-form"
											dangerouslySetInnerHTML={ {
												__html: authFormHtml,
											} }
										/>
									) }
								</div>
							) }
						</div>
					) }

					<div className="vkbm-confirm__form">
						<label htmlFor="vkbm-confirm-memo">
							{ __( 'Requests/Notes', 'vk-booking-manager' ) }
						</label>
						{ renderMemoField() }
					</div>

					{ canManageReservations && (
						<div className="vkbm-confirm__admin-fields">
							<div className="vkbm-confirm__admin-field">
								<label htmlFor="vkbm-confirm-customer-name">
									{ __(
										'Reserved name',
										'vk-booking-manager'
									) }
								</label>
								<input
									type="text"
									id="vkbm-confirm-customer-name"
									value={ customerName }
									onChange={ ( event ) =>
										setCustomerName( event.target.value )
									}
									placeholder={ __(
										'Please enter customer name.',
										'vk-booking-manager'
									) }
								/>
							</div>
							<div className="vkbm-confirm__admin-field">
								<label htmlFor="vkbm-confirm-customer-phone">
									{ __(
										'Telephone number',
										'vk-booking-manager'
									) }
								</label>
								<input
									type="tel"
									id="vkbm-confirm-customer-phone"
									value={ customerPhone }
									onChange={ ( event ) =>
										setCustomerPhone( event.target.value )
									}
									placeholder={ __(
										'Please enter your contact phone number.',
										'vk-booking-manager'
									) }
								/>
							</div>
							{ window.vkbmPaymentConfig?.payment_enabled && (
								<div className="vkbm-confirm__admin-field">
									<span id="vkbm-payment-method-label">
										{ __(
											'Payment method',
											'vk-booking-manager'
										) }
									</span>
									<div
										className="vkbm-confirm__payment-method-options"
										role="radiogroup"
										aria-labelledby="vkbm-payment-method-label"
									>
										<label htmlFor="vkbm-payment-method-onsite">
											<input
												type="radio"
												id="vkbm-payment-method-onsite"
												name="vkbm-payment-method"
												value="onsite"
												checked={
													paymentMethod === 'onsite'
												}
												onChange={ () =>
													setPaymentMethod( 'onsite' )
												}
											/>
											{ __(
												'On-site payment',
												'vk-booking-manager'
											) }
										</label>
										<label htmlFor="vkbm-payment-method-card">
											<input
												type="radio"
												id="vkbm-payment-method-card"
												name="vkbm-payment-method"
												value="card"
												checked={
													paymentMethod === 'card'
												}
												onChange={ () =>
													setPaymentMethod( 'card' )
												}
											/>
											{ __(
												'Card payment (Stripe)',
												'vk-booking-manager'
											) }
										</label>
									</div>
								</div>
							) }
							<div className="vkbm-confirm__admin-field">
								<label htmlFor="vkbm-confirm-provider-note">
									{ __(
										'Management memo',
										'vk-booking-manager'
									) }
								</label>
								<textarea
									id="vkbm-confirm-provider-note"
									value={ providerNote }
									onChange={ ( event ) =>
										setProviderNote( event.target.value )
									}
								/>
							</div>
						</div>
					) }

					{ renderAgreements() }

					<div className="vkbm-confirm__actions">
						{ ! success && showMinCapacityNote && (
							<p
								id="vkbm-confirm-min-capacity-note"
								className="vkbm-confirm__min-capacity-note description"
							>
								{ __(
									'If the minimum number of participants is not reached, the session may not take place.',
									'vk-booking-manager'
								) }
							</p>
						) }
						{ ! success && (
							<button
								type="button"
								className="vkbm-confirm__button vkbm-button vkbm-button__md vkbm-button__primary"
								onClick={ handleConfirm }
								disabled={ ! canSubmit }
								aria-describedby={
									showMinCapacityNote
										? 'vkbm-confirm-min-capacity-note'
										: undefined
								}
							>
								{ submitting
									? __( 'Processing…', 'vk-booking-manager' )
									: __(
											'Reserve with this content',
											'vk-booking-manager'
									  ) }
							</button>
						) }
						{ showSuccessMessage && (
							<BookingCompleteNotice
								title={ completeTitle }
								message={ completeMessage }
								containerRef={ completeNoticeRef }
							/>
						) }
						{ /* 予約確定時にチェックされていれば、お気に入り登録の結果を表示する。 */ }
						{ showSuccessMessage && favoriteSaved && (
							<p
								className="vkbm-alert vkbm-alert__success text-center"
								role="status"
							>
								{ __(
									'Added to favorites.',
									'vk-booking-manager'
								) }
							</p>
						) }
						{ showSuccessMessage && favoriteError && (
							<p
								className="vkbm-alert vkbm-alert__danger text-center"
								role="alert"
							>
								{ favoriteError }
							</p>
						) }
						{ submitError && (
							<p
								className="vkbm-alert vkbm-alert__danger"
								role="alert"
							>
								{ submitError }
							</p>
						) }
					</div>
				</>
			) }
			<ReservationFooter model={ buildFooterModel( footerSettings ) } />
		</div>
	);
};
