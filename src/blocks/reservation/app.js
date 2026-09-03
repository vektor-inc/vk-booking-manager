import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import { CalendarGrid, DailySlotList, SelectedPlanSummary } from './booking-ui';
import {
	extractMenuBasePrice,
	formatCurrency,
	normalizePriceValue,
} from '../shared/pricing';
import { resolveLoginState } from '../shared/auth';
import { BookingConfirmApp } from './booking-confirm-app';
import { BookingSummaryItems } from './components/booking-summary-items';
import { ReservationHeader } from './components/reservation-header';
import { sanitizeDraftToken } from '../shared/draft-token';
const parseQueryParams = () => {
	if ( typeof window === 'undefined' ) {
		return {};
	}

	try {
		const params = new URLSearchParams( window.location.search );
		const menuId = Number( params.get( 'menu_id' ) ) || 0;
		return {
			menuId,
			// menu_id が明示指定されたリンク経由か（お気に入り・「同じ内容で予約する」・
			// 「予約に進むボタン」など、menu_id を URL に付与して開くすべての導線が該当）。
			// この場合は resource_id 未指定を「指名なし」として扱い、
			// ブロックのデフォルトスタッフ（data-default-resource-id）へフォールバックしない。
			hasExplicitMenuId: menuId > 0,
			staffId: Number( params.get( 'resource_id' ) ) || 0,
			date: params.get( 'date' ) || '',
			auth: params.get( 'vkbm_auth' ) || '',
			draft: sanitizeDraftToken( params.get( 'draft' ) || '' ),
		};
	} catch ( error ) {
		return {};
	}
};

const buildApiPath = ( base, query ) => {
	const searchParams = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			searchParams.append( key, value );
		}
	} );
	return `${ base }?${ searchParams.toString() }`;
};

const DRAFT_COOKIE = 'vkbm_draft_token';

const getDraftTokenFromCookie = () => {
	if ( typeof document === 'undefined' ) {
		return '';
	}
	const match = document.cookie.match(
		new RegExp( `(?:^|; )${ DRAFT_COOKIE }=([^;]*)` )
	);
	return sanitizeDraftToken( match ? decodeURIComponent( match[ 1 ] ) : '' );
};

const storeDraftToken = ( token, maxAge = 1800 ) => {
	if ( typeof document === 'undefined' ) {
		return;
	}
	const safeToken = sanitizeDraftToken( token );
	if ( ! safeToken ) {
		return;
	}
	let cookie = `${ DRAFT_COOKIE }=${ encodeURIComponent(
		safeToken
	) }; path=/; max-age=${ maxAge }; SameSite=Lax`;
	if (
		typeof window !== 'undefined' &&
		window.location?.protocol === 'https:'
	) {
		cookie += '; Secure';
	}
	document.cookie = cookie;
};

const useCollection = ( path, setState ) => {
	useEffect( () => {
		if ( ! path ) {
			setState( [] );
			return () => {};
		}

		let isMounted = true;
		apiFetch( { path } )
			.then( ( result ) => {
				if ( isMounted ) {
					setState( result || [] );
				}
			} )
			.catch( () => {
				if ( isMounted ) {
					setState( [] );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ path, setState ] );
};

const getCurrentUrl = () => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	return window.location.href;
};

const buildLogoutFallbackUrl = ( reservationPageUrl = '' ) => {
	if ( typeof window === 'undefined' ) {
		return '/wp-login.php?action=logout';
	}
	const target =
		typeof reservationPageUrl === 'string' &&
		reservationPageUrl.trim() !== ''
			? reservationPageUrl
			: window.location.href;
	const redirect = encodeURIComponent( target );
	return `/wp-login.php?action=logout&redirect_to=${ redirect }`;
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

// 「同じ内容で予約する」用の URL を組み立てる。予約フォームは menu_id / resource_id の
// クエリで初期値を復元できるため、過去の予約と同じメニュー・指名スタッフを引き継ぐ。
// 日付（date）は過去/将来いずれも再予約には不適切なため付与しない。
const buildRebookUrl = ( booking, reservationPageUrl = '' ) => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const base =
		typeof reservationPageUrl === 'string' &&
		reservationPageUrl.trim() !== ''
			? reservationPageUrl
			: window.location.href;

	let url;
	try {
		url = new URL( base, window.location.href );
	} catch ( error ) {
		return '';
	}

	// マイページ表示モードの名残（vkbm_auth=bookings）や前回のドラフトは引き継がない。
	url.searchParams.delete( 'vkbm_auth' );
	url.searchParams.delete( 'draft' );
	url.searchParams.delete( 'date' );

	const menuId = Number( booking?.menu_id ) || 0;
	if ( menuId > 0 ) {
		url.searchParams.set( 'menu_id', String( menuId ) );
	}

	const resourceId = Number( booking?.resource_id ) || 0;
	if ( resourceId > 0 ) {
		url.searchParams.set( 'resource_id', String( resourceId ) );
	} else {
		url.searchParams.delete( 'resource_id' );
	}

	return url.toString();
};

// メニューのメタ情報から、指名可能なスタッフID配列を正規化して取り出す。
// メニュー選択・お気に入り適用・指名可能判定で共通利用する。
const extractAssignableStaffIds = ( meta ) => {
	const rawIds =
		Array.isArray( meta?._vkbm_staff_ids ) && meta._vkbm_staff_ids.length
			? meta._vkbm_staff_ids
			: Array.isArray( meta?.vkbm_staff_ids ) &&
			  meta.vkbm_staff_ids.length
			? meta.vkbm_staff_ids
			: [];

	return Array.from(
		new Set(
			rawIds
				.map( ( value ) => {
					if ( typeof value === 'number' ) {
						return value;
					}
					if ( typeof value === 'string' ) {
						return Number( value ) || 0;
					}
					return 0;
				} )
				.filter( ( id ) => id > 0 )
		)
	);
};

const formatBookingDateTimeParts = ( startAt, endAt ) => {
	if ( ! startAt ) {
		return { date: '', time: '' };
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
	const wpTimezone = settings?.timezone?.string;

	const startDate = new Date( startAt );
	if ( Number.isNaN( startDate.getTime() ) ) {
		return { date: startAt, time: '' };
	}

	const dateLabel = dateI18n( dateFormat, startDate, wpTimezone );
	const weekdayLabel = dateI18n( 'D', startDate, wpTimezone );
	const startTimeLabel = dateI18n( timeFormat, startDate, wpTimezone );

	let timeLabel = startTimeLabel;
	if ( endAt ) {
		const endDate = new Date( endAt );
		if ( ! Number.isNaN( endDate.getTime() ) ) {
			const endTimeLabel = dateI18n( timeFormat, endDate, wpTimezone );
			timeLabel = `${ startTimeLabel } - ${ endTimeLabel }`.trim();
		}
	}

	return {
		date: `${ dateLabel }(${ weekdayLabel })`,
		time: timeLabel,
	};
};

export const ReservationApp = ( {
	defaultMenuId = 0,
	defaultStaffId = 0,
	allowStaffSelection = true,
	isEditor = false,
} ) => {
	const userBootstrap = useMemo( () => {
		if ( typeof window === 'undefined' ) {
			return null;
		}
		return window.vkbmCurrentUserBootstrap || null;
	}, [] );
	const queryDefaults = useMemo( () => parseQueryParams(), [] );
	const initialMenuId = queryDefaults.menuId || defaultMenuId || 0;
	// menu_id が明示指定されたリンク経由（お気に入り・再予約・予約に進むボタン等）の場合は、
	// resource_id 未指定を「指名なし」として尊重し、デフォルトスタッフへフォールバックしない。
	const initialStaffId = queryDefaults.hasExplicitMenuId
		? queryDefaults.staffId || 0
		: queryDefaults.staffId || defaultStaffId || 0;
	const initialDate = queryDefaults.date || '';
	const initialMonthDate = initialDate ? new Date( initialDate ) : new Date();

	const [ menuId, setMenuId ] = useState( initialMenuId );
	const [ staffId, setStaffId ] = useState( initialStaffId );
	const [ selectedDate, setSelectedDate ] = useState( initialDate );
	const [ selectedSlot, setSelectedSlot ] = useState( null );
	// 予約人数（複数人一括予約）。複数人一括予約に対応したメニューでのみ使用する。既定は1。
	const [ guests, setGuests ] = useState( 1 );
	// 料金区分ごとの人数（区分インデックス => 人数）。料金区分が定義されたメニューでのみ使用する。
	const [ guestTierCounts, setGuestTierCounts ] = useState( [] );
	// ユーザーによる貸し切り指定（#305）。チェック状態。メニュー設定・人数・空き枠の条件を満たすときのみ有効。
	const [ userExclusive, setUserExclusive ] = useState( false );
	const [ monthCursor, setMonthCursor ] = useState( {
		year: initialMonthDate.getFullYear(),
		month: initialMonthDate.getMonth() + 1,
	} );

	const [ menus, setMenus ] = useState( [] );
	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ providerSettings, setProviderSettings ] = useState( {
		taxEnabled: false,
		taxRate: 0,
		taxLabelText: '',
		reservationPageUrl: '',
		showMenuList: true,
		staffEnabled: false,
		defaultStaffId: 0,
		resourceLabelSingular: __( 'Staff', 'vk-booking-manager' ),
		resourceLabelPlural: __( 'Staff', 'vk-booking-manager' ),
		noNominationLabel: __( 'No preference', 'vk-booking-manager' ),
		nominationFeeLabel: __( 'Nomination fee', 'vk-booking-manager' ),
		showProviderLogo: false,
		showProviderName: false,
		providerName: '',
		providerLogoUrl: '',
		closedDayLabel: '',
		otherConditionsLabel: __( 'Other conditions', 'vk-booking-manager' ),
		// 数量の見出し（複数人一括予約）。REST が実効値を返す。
		guestsCountLabel: __( 'Number of guests', 'vk-booking-manager' ),
		// 数量の単位。REST が実効値（null はロケール既定に解決済み）を返す。空文字は単位なし。
		guestsUnitLabel: '',
	} );
	const [ menuList, setMenuList ] = useState( {
		html: '',
		isLoading: true,
		error: '',
	} );
	const [ authFormHtml, setAuthFormHtml ] = useState( '' );
	const [ authLoading, setAuthLoading ] = useState( false );
	const [ authError, setAuthError ] = useState( '' );
	const [ authMode, setAuthMode ] = useState( queryDefaults.auth || '' );
	const [ logoutUrl, setLogoutUrl ] = useState(
		userBootstrap?.logoutUrl || ''
	);
	const [ shiftDashboardUrl, setShiftDashboardUrl ] = useState(
		userBootstrap?.shiftDashboardUrl || ''
	);
	const [ canManageReservations, setCanManageReservations ] = useState(
		Boolean( userBootstrap?.canManageReservations )
	);
	const canViewPrivateMenus = Boolean( userBootstrap?.canViewPrivateMenus );
	const [ bookingsLoading, setBookingsLoading ] = useState( false );
	const [ bookingsError, setBookingsError ] = useState( '' );
	const [ bookings, setBookings ] = useState( [] );
	const [ cancellingBookingId, setCancellingBookingId ] = useState( 0 );
	// お気に入り（いつもの）。ログインユーザーのよく使うメニュー＋スタッフの組み合わせ。
	const [ favorites, setFavorites ] = useState( [] );
	const [ favoritesError, setFavoritesError ] = useState( '' );
	const isLoggedIn = resolveLoginState( { userBootstrap, isEditor } );
	const confirmDraftToken = queryDefaults.draft || '';
	const handleAuthLink = ( mode ) => {
		if ( typeof window === 'undefined' ) {
			return;
		}

		setAuthMode( ( current ) => {
			const nextMode = current === mode ? '' : mode;
			const url = new URL( window.location.href );
			if ( nextMode ) {
				url.searchParams.set( 'vkbm_auth', nextMode );
			} else {
				url.searchParams.delete( 'vkbm_auth' );
			}
			window.history.replaceState( null, '', url.toString() );
			return nextMode;
		} );
	};

	const [ calendarData, setCalendarData ] = useState( null );
	const [ calendarLoading, setCalendarLoading ] = useState( false );
	const [ calendarError, setCalendarError ] = useState( null );

	const [ slotData, setSlotData ] = useState( [] );
	const [ slotLoading, setSlotLoading ] = useState( false );
	const [ slotError, setSlotError ] = useState( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ menuPreview, setMenuPreview ] = useState( {
		html: '',
		isLoading: false,
		error: '',
	} );
	const layoutRef = useRef( null );
	const actionSectionRef = useRef( null );
	const [ providerSettingsLoaded, setProviderSettingsLoaded ] =
		useState( false );
	const staffSelectionEnabled =
		allowStaffSelection && providerSettings.staffEnabled;
	useEffect( () => {
		let isMounted = true;

		apiFetch( { path: '/vkbm/v1/provider-settings' } )
			.then( ( settings ) => {
				if ( ! isMounted ) {
					return;
				}

				setProviderSettings( {
					taxEnabled: Boolean( settings?.tax_enabled ),
					taxRate: Number( settings?.tax_rate ) || 0,
					taxLabelText:
						typeof settings?.tax_label_text === 'string'
							? settings.tax_label_text
							: '',
					currencySymbol:
						typeof settings?.currency_symbol === 'string'
							? settings.currency_symbol
							: '',
					reservationPageUrl: settings?.reservation_page_url || '',
					showMenuList:
						settings?.reservation_show_menu_list !== false,
					staffEnabled: Boolean( settings?.staff_enabled ),
					defaultStaffId: Number( settings?.default_staff_id ) || 0,
					showProviderLogo: Boolean(
						settings?.reservation_show_provider_logo
					),
					showProviderName: Boolean(
						settings?.reservation_show_provider_name
					),
					resourceLabelSingular:
						typeof settings?.resource_label_singular === 'string' &&
						settings.resource_label_singular.trim() !== ''
							? settings.resource_label_singular
							: __( 'Staff', 'vk-booking-manager' ),
					resourceLabelPlural:
						typeof settings?.resource_label_plural === 'string' &&
						settings.resource_label_plural.trim() !== ''
							? settings.resource_label_plural
							: typeof settings?.resource_label_singular ===
									'string' &&
							  settings.resource_label_singular.trim() !== ''
							? settings.resource_label_singular
							: __( 'Staff', 'vk-booking-manager' ),
					noNominationLabel:
						typeof settings?.no_nomination_label === 'string' &&
						settings.no_nomination_label.trim() !== ''
							? settings.no_nomination_label
							: __( 'No preference', 'vk-booking-manager' ),
					nominationFeeLabel:
						typeof settings?.nomination_fee_label === 'string' &&
						settings.nomination_fee_label.trim() !== ''
							? settings.nomination_fee_label
							: __( 'Nomination fee', 'vk-booking-manager' ),
					providerName:
						typeof settings?.provider_name === 'string'
							? settings.provider_name
							: '',
					providerLogoUrl:
						typeof settings?.provider_logo_url === 'string'
							? settings.provider_logo_url
							: '',
					closedDayLabel:
						typeof settings?.closed_day_label === 'string'
							? settings.closed_day_label
							: '',
					otherConditionsLabel:
						typeof settings?.other_conditions_label === 'string' &&
						settings.other_conditions_label.trim() !== ''
							? settings.other_conditions_label
							: __( 'Other conditions', 'vk-booking-manager' ),
					// 数量の見出し。REST が実効値を返すが、念のため空ならデフォルトへフォールバックする。
					guestsCountLabel:
						typeof settings?.guests_count_label === 'string' &&
						settings.guests_count_label.trim() !== ''
							? settings.guests_count_label
							: __( 'Number of guests', 'vk-booking-manager' ),
					// 数量の単位。REST が実効値（null はロケール既定に解決済み）を返す。
					// 空文字は単位なしを意味するため、空判定でのフォールバックは行わない。
					guestsUnitLabel:
						typeof settings?.guests_unit_label === 'string'
							? settings.guests_unit_label
							: '',
				} );
			} )
			.catch( () => {
				// Keep defaults.
			} )
			.finally( () => {
				if ( isMounted ) {
					setProviderSettingsLoaded( true );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	useEffect( () => {
		// providerSettings が読み込まれるまで待つ
		if ( ! providerSettingsLoaded ) {
			return;
		}

		if ( ! providerSettings.staffEnabled ) {
			// 無料版ではデフォルトスタッフIDを設定
			if (
				providerSettings.defaultStaffId > 0 &&
				staffId !== providerSettings.defaultStaffId
			) {
				setStaffId( providerSettings.defaultStaffId );
			} else if (
				providerSettings.defaultStaffId === 0 &&
				staffId !== 0
			) {
				setStaffId( 0 );
			}
		}
	}, [
		providerSettingsLoaded,
		providerSettings.staffEnabled,
		providerSettings.defaultStaffId,
		staffId,
	] );

	useEffect( () => {
		let isMounted = true;

		if ( ! providerSettingsLoaded ) {
			return () => {
				isMounted = false;
			};
		}

		if ( ! providerSettings.showMenuList || menuId ) {
			setMenuList( {
				html: '',
				isLoading: false,
				error: '',
			} );
			return () => {
				isMounted = false;
			};
		}

		setMenuList( {
			html: '',
			isLoading: true,
			error: '',
		} );

		apiFetch( { path: '/vkbm/v1/menu-loop' } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuList( {
					html: response?.html || '',
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuList( {
					html: '',
					isLoading: false,
					error:
						error?.message ||
						__( 'Could not get menu list.', 'vk-booking-manager' ),
				} );
			} );

		return () => {
			isMounted = false;
		};
	}, [ providerSettingsLoaded, providerSettings.showMenuList, menuId ] );

	useEffect( () => {
		let isMounted = true;

		if ( ! isLoggedIn ) {
			setLogoutUrl( '' );
			setShiftDashboardUrl( '' );
			setCanManageReservations( false );
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
			setLogoutUrl( userBootstrap.logoutUrl || '' );
			setShiftDashboardUrl( userBootstrap.shiftDashboardUrl || '' );
			setCanManageReservations(
				Boolean( userBootstrap.canManageReservations )
			);
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

				setLogoutUrl( response?.logout_url || '' );
				setShiftDashboardUrl( response?.shift_dashboard_url || '' );
				setCanManageReservations(
					Boolean( response?.can_manage_reservations )
				);
			} )
			.catch( () => {
				if ( isMounted ) {
					setLogoutUrl( '' );
					setShiftDashboardUrl( '' );
					setCanManageReservations( false );
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

		setAuthLoading( true );
		setAuthError( '' );
		setAuthFormHtml( '' );

		const params = new URLSearchParams();
		params.set( 'type', authMode );

		const redirectUrl = getCurrentUrl();
		if ( redirectUrl ) {
			params.set( 'redirect', redirectUrl );
		}

		if ( authMode === 'login' ) {
			params.set( 'register_url', buildModeUrl( 'register' ) );
		} else if ( authMode === 'register' ) {
			params.set( 'login_url', buildModeUrl( 'login' ) );
		}

		apiFetch( {
			path: `/vkbm/v1/auth-form?${ params.toString() }`,
			cache: 'no-store',
			credentials: 'same-origin',
		} )
			.then( ( response ) => {
				const html = response?.html || '';
				const message = response?.message || '';

				setAuthFormHtml( html );

				if ( ! html && authMode === 'register' && message ) {
					setAuthError( message );
				}
			} )
			.catch( ( error ) => {
				setAuthError(
					error?.message ||
						__(
							'The form could not be displayed.',
							'vk-booking-manager'
						)
				);
			} )
			.finally( () => {
				setAuthLoading( false );
			} );
	}, [ authMode, isLoggedIn ] );

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
						error?.message ||
							__(
								'I was unable to cancel my reservation.',
								'vk-booking-manager'
							)
					);
				} )
				.finally( () => {
					setCancellingBookingId( 0 );
				} );
		},
		[ cancellingBookingId ]
	);

	// お気に入り一覧を取得する。追加・削除後の再取得にも使う。
	const loadFavorites = useCallback( () => {
		if ( ! isLoggedIn || isEditor ) {
			setFavorites( [] );
			return;
		}

		apiFetch( { path: '/vkbm/v1/favorites' } )
			.then( ( response ) => {
				setFavorites( Array.isArray( response ) ? response : [] );
			} )
			.catch( () => {
				setFavorites( [] );
			} );
	}, [ isLoggedIn, isEditor ] );

	// ログイン状態が確定したらお気に入りを読み込む。
	useEffect( () => {
		loadFavorites();
	}, [ loadFavorites ] );

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

		// 「同じ内容で予約する」のため過去の予約も取得する（future_only=false）。
		apiFetch( { path: '/vkbm/v1/my-bookings?future_only=false' } )
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
	const currentMenu = useMemo(
		() => menus.find( ( menu ) => menu.id === menuId ),
		[ menus, menuId ]
	);
	const currentStaff = useMemo(
		() => staffOptions.find( ( staff ) => staff.id === staffId ),
		[ staffOptions, staffId ]
	);

	// メニューが複数人一括予約に対応しているかを判定する。
	// 複数人一括予約は指名機能OFF（staffEnabled=false）のときのみ有効。指名ONへ切り替えた場合に
	// 古いメタフラグで人数セレクタが残らないよう、現在の指名設定でもゲートする。
	const allowMultipleGuests = useMemo(
		() =>
			! providerSettings.staffEnabled &&
			Boolean( currentMenu?.meta?._vkbm_allow_multiple_guests ),
		[ currentMenu, providerSettings.staffEnabled ]
	);
	// 選択可能な人数の上限は、選択中スロットの残り（= 最も空きの大きい単一スタッフの残り）。
	// 楽観的に最大人数を許可するとサーバー側で予約が失敗するため、不明時は安全側に倒す。
	const maxSelectableGuests = useMemo( () => {
		if ( ! allowMultipleGuests ) {
			return 1;
		}
		// 残り = 最も空きの大きい単一スタッフの残り。1予約は分割せず単一スタッフに割り当てるため、
		// その人数までしか指定できない。取得できない（不明な）場合は 0 とし、安全側へ倒す。
		const remaining = Number( selectedSlot?.remaining );
		if ( Number.isFinite( remaining ) ) {
			return Math.max( 0, Math.floor( remaining ) );
		}
		return 0;
	}, [ allowMultipleGuests, selectedSlot ] );

	// 選択可能上限が変わった場合に人数を範囲内へ補正する。
	useEffect( () => {
		setGuests( ( current ) => {
			const next = Math.min(
				Math.max( 1, current ),
				maxSelectableGuests
			);
			return next === current ? current : next;
		} );
	}, [ maxSelectableGuests ] );

	// メニューに定義された料金区分（[ { label, price }, ... ]）。
	// 複数人一括予約ONかつ区分が1件以上ある場合のみ、人数を区分ごとに入力する。
	const priceTiers = useMemo( () => {
		if ( ! allowMultipleGuests ) {
			return [];
		}
		const rawTiers = currentMenu?.meta?._vkbm_price_tiers;
		if ( ! Array.isArray( rawTiers ) ) {
			return [];
		}
		return rawTiers
			.map( ( tier ) => ( {
				label: typeof tier?.label === 'string' ? tier.label.trim() : '',
				price: Math.max( 0, Math.floor( Number( tier?.price ) || 0 ) ),
			} ) )
			.filter( ( tier ) => tier.label !== '' );
	}, [ allowMultipleGuests, currentMenu ] );

	const hasPriceTiers = priceTiers.length > 0;

	// 料金区分の人数配列を区分数に合わせて初期化・サイズ調整する。
	useEffect( () => {
		if ( ! hasPriceTiers ) {
			if ( guestTierCounts.length > 0 ) {
				setGuestTierCounts( [] );
			}
			return;
		}
		setGuestTierCounts( ( current ) => {
			// 区分数に合わせて 0 埋めし、既存入力は保持する。
			const next = priceTiers.map( ( _, index ) =>
				Math.max( 0, Math.floor( Number( current[ index ] ) || 0 ) )
			);
			const sameLength = current.length === next.length;
			const sameValues =
				sameLength &&
				next.every( ( value, index ) => value === current[ index ] );
			return sameValues ? current : next;
		} );
	}, [ hasPriceTiers, priceTiers ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// 料金区分の合計人数（枠消費・上限判定に使う）。
	const guestTiersTotalCount = useMemo(
		() =>
			guestTierCounts.reduce(
				( sum, value ) =>
					sum + Math.max( 0, Math.floor( Number( value ) || 0 ) ),
				0
			),
		[ guestTierCounts ]
	);

	// 申込人数（実効値）。料金区分メニューは区分人数合計、複数人一括予約メニューは guests、それ以外は1。
	const currentGuestCount = useMemo( () => {
		if ( hasPriceTiers ) {
			return guestTiersTotalCount;
		}
		if ( allowMultipleGuests ) {
			return Math.max( 1, Math.floor( Number( guests ) || 1 ) );
		}
		return 1;
	}, [ hasPriceTiers, guestTiersTotalCount, allowMultipleGuests, guests ] );

	// メニューが「ユーザーによる貸し切り指定を受け付ける」設定か（#305）。複数人一括予約ON時のみ意味を持つ。
	const exclusiveSelectable = useMemo(
		() =>
			allowMultipleGuests &&
			Boolean( currentMenu?.meta?._vkbm_exclusive_user_selectable ),
		[ allowMultipleGuests, currentMenu ]
	);
	// 貸し切り料金（1人あたり単価）と適用外人数（0=上限なし＝常に加算）。
	const exclusiveFeePerPerson = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number(
						currentMenu?.meta?._vkbm_exclusive_fee_per_person
					) || 0
				)
			),
		[ currentMenu ]
	);
	const exclusiveFeeExempt = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number(
						currentMenu?.meta?._vkbm_exclusive_fee_exempt_guests
					) || 0
				)
			),
		[ currentMenu ]
	);
	// メニューの最小催行人数（0=制約なし）。貸し切りは申込人数 >= 最小催行人数のときのみ指定可能。
	const minCapacity = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number( currentMenu?.meta?._vkbm_min_capacity ) || 0
				)
			),
		[ currentMenu ]
	);
	// 選択中スロットが既に予約済み（貸切受付終了 or 1件以上予約あり）か。貸切指定はこの場合 disabled。
	const slotIsOccupied = useMemo( () => {
		if ( ! selectedSlot ) {
			return false;
		}
		const booked = Math.max(
			0,
			Math.floor( Number( selectedSlot.booked_guests ) || 0 )
		);
		return booked > 0 || Boolean( selectedSlot.exclusive_closed );
	}, [ selectedSlot ] );
	// 申込人数が最小催行人数を満たすか（minCapacity=0 のときは常に満たす）。
	const meetsMinCapacity = useMemo(
		() => minCapacity <= 0 || currentGuestCount >= minCapacity,
		[ minCapacity, currentGuestCount ]
	);
	// 貸切チェックを「有効なチェック」として出せるか（=空き枠 かつ 人数条件OK かつ メニュー設定ON）。
	const exclusiveCheckEnabled =
		exclusiveSelectable && ! slotIsOccupied && meetsMinCapacity;
	// 適用外人数に達しているか（適用外人数が正の値で、申込人数 >= 適用外人数）。
	const exclusiveFeeExempted =
		exclusiveFeeExempt > 0 && currentGuestCount >= exclusiveFeeExempt;
	// 貸切料金（フロント概算表示用。サーバ側で権威的に再計算される）。
	// ユーザー貸切ON かつ 単価 > 0 かつ 適用外人数未達のときのみ per_person × 人数。
	const exclusiveFee = useMemo( () => {
		if (
			! userExclusive ||
			! exclusiveSelectable ||
			exclusiveFeePerPerson <= 0 ||
			exclusiveFeeExempted
		) {
			return 0;
		}
		return exclusiveFeePerPerson * currentGuestCount;
	}, [
		userExclusive,
		exclusiveSelectable,
		exclusiveFeePerPerson,
		exclusiveFeeExempted,
		currentGuestCount,
	] );

	// 貸切チェックが無効化される条件（メニューOFF・枠が満席/締切・人数不足）になったらチェックを外す。
	useEffect( () => {
		if ( ! exclusiveCheckEnabled && userExclusive ) {
			setUserExclusive( false );
		}
	}, [ exclusiveCheckEnabled, userExclusive ] );

	const assignableStaffIds = useMemo( () => {
		return extractAssignableStaffIds( currentMenu?.meta );
	}, [ currentMenu ] );

	const availableStaffOptions = useMemo( () => {
		// 無料版では選択可能スタッフの制限を解除
		if ( ! providerSettings.staffEnabled ) {
			return staffOptions;
		}

		if ( assignableStaffIds.length === 0 ) {
			return staffOptions;
		}

		const allowedIds = new Set( assignableStaffIds );
		return staffOptions.filter( ( staff ) => allowedIds.has( staff.id ) );
	}, [ assignableStaffIds, staffOptions, providerSettings.staffEnabled ] );
	const shouldLockStaffSelection = assignableStaffIds.length === 1;

	// 指名OFF かつ複数スタッフが対応可能なメニューは「おまかせ自動分配」とする。
	// この場合は resource_id=0 / is_staff_preferred=false で送信し、サーバー側で
	// 複数スタッフへ人数を配分させる（単一スタッフ・無料版では従来どおり staffId を使う）。
	const autoDistribute =
		! providerSettings.staffEnabled && assignableStaffIds.length > 1;
	const effectiveResourceId = autoDistribute ? 0 : staffId;

	useEffect( () => {
		// 無料版では assignableStaffIds のチェックをスキップ
		if ( ! providerSettings.staffEnabled ) {
			return;
		}

		if ( ! menuId || assignableStaffIds.length === 0 ) {
			return;
		}

		if ( assignableStaffIds.length === 1 ) {
			const onlyStaffId = assignableStaffIds[ 0 ];
			if ( staffId !== onlyStaffId ) {
				setStaffId( onlyStaffId );
			}
			return;
		}

		if ( staffId && ! assignableStaffIds.includes( staffId ) ) {
			setStaffId( 0 );
		}
	}, [ menuId, assignableStaffIds, staffId, providerSettings.staffEnabled ] );
	const isNominationFeeDisabled = useMemo( () => {
		const meta = currentMenu?.meta;
		if ( ! meta ) {
			return false;
		}

		const raw =
			meta._vkbm_disable_nomination_fee ??
			meta.vkbm_disable_nomination_fee ??
			currentMenu?._vkbm_disable_nomination_fee ??
			currentMenu?.vkbm_disable_nomination_fee;

		if ( raw === undefined || raw === null ) {
			return false;
		}

		if ( typeof raw === 'boolean' ) {
			return raw;
		}

		const normalized = String( raw ).toLowerCase().trim();
		return normalized === '1' || normalized === 'true';
	}, [ currentMenu ] );
	const isProfileMode = isLoggedIn && authMode === 'profile';
	const shouldShowUserSection =
		( ( authMode === 'profile' || authMode === 'bookings' ) &&
			isLoggedIn ) ||
		( ! isLoggedIn && ( authMode === 'login' || authMode === 'register' ) );
	const shouldShowReservation = ! shouldShowUserSection;
	const hasMenuSelection = Boolean( menuId );

	const applyTax = useCallback(
		( value ) => {
			const normalized = normalizePriceValue( value );

			if ( normalized === null ) {
				return null;
			}

			return normalized;
		},
		[ providerSettings ]
	);

	const basePriceRaw = useMemo(
		() => extractMenuBasePrice( currentMenu ),
		[ currentMenu ]
	);

	const basePrice = useMemo( () => {
		if ( basePriceRaw === null ) {
			return null;
		}

		return applyTax( basePriceRaw );
	}, [ applyTax, basePriceRaw ] );

	const staffNominationFeeRaw = useMemo( () => {
		if ( ! providerSettings.staffEnabled ) {
			return 0;
		}
		if ( ! staffId ) {
			return null;
		}
		if ( isNominationFeeDisabled ) {
			return 0;
		}

		const rawFee =
			currentStaff?.meta?.vkbm_nomination_fee ??
			currentStaff?.meta?._vkbm_nomination_fee ??
			currentStaff?._vkbm_nomination_fee ??
			currentStaff?.nomination_fee;

		const normalized = normalizePriceValue( rawFee );

		if ( normalized === null ) {
			return 0;
		}

		return normalized;
	}, [
		currentStaff,
		staffId,
		isNominationFeeDisabled,
		providerSettings.staffEnabled,
	] );

	const staffNominationFee = useMemo( () => {
		if ( staffNominationFeeRaw === null ) {
			return null;
		}

		return applyTax( staffNominationFeeRaw );
	}, [ applyTax, staffNominationFeeRaw ] );

	const totalPrice = useMemo( () => {
		if ( basePrice === null ) {
			return null;
		}

		const extra = staffNominationFee === null ? 0 : staffNominationFee;
		return basePrice + extra;
	}, [ basePrice, staffNominationFee ] );

	const pricingRows = useMemo( () => {
		if ( ! providerSettingsLoaded ) {
			return [];
		}

		// 指名機能が無効の場合は料金サマリーを表示しない
		// （指名料行がなく基本料金＝合計となり冗長なため）。
		if ( ! providerSettings.staffEnabled ) {
			return [];
		}

		const taxSuffix = providerSettings.taxEnabled
			? providerSettings.taxLabelText &&
			  providerSettings.taxLabelText.trim() !== ''
				? providerSettings.taxLabelText
				: ''
			: '';
		const withTaxLabel = ( value ) => ( {
			value,
			taxLabel: value !== '—' ? taxSuffix : '',
		} );

		const currencySymbol = providerSettings.currencySymbol || null;
		const rows = [
			{
				key: 'base',
				label: __( 'Service basic fee', 'vk-booking-manager' ),
				...withTaxLabel(
					basePrice !== null
						? formatCurrency( basePrice, currencySymbol )
						: '—'
				),
			},
			{
				key: 'nomination',
				label: providerSettings.nominationFeeLabel,
				value:
					staffNominationFee === null
						? staffId
							? formatCurrency( applyTax( 0 ), currencySymbol )
							: '—'
						: formatCurrency( staffNominationFee, currencySymbol ),
				// staffNominationFee !== null : 指名料が設定されている場合は金額を表示するのでラベルを付ける
				// || staffId               : 指名料未設定（null）でもスタッフ選択済みなら ¥0 表示になるのでラベルを付ける
				// 両方 false = スタッフ未選択 = '—' 表示 のときはラベル不要
				taxLabel:
					staffNominationFee !== null || staffId ? taxSuffix : '',
			},
			{
				key: 'total',
				label: __( 'Total basic fee', 'vk-booking-manager' ),
				...withTaxLabel(
					totalPrice !== null
						? formatCurrency( totalPrice, currencySymbol )
						: '—'
				),
				highlight: true,
			},
		];

		return rows;
	}, [
		basePrice,
		staffNominationFee,
		totalPrice,
		providerSettings,
		providerSettingsLoaded,
	] );

	const menuCollectionPath = useMemo(
		() =>
			buildApiPath( '/wp/v2/vkbm_service_menu', {
				per_page: 100,
				_fields: 'id,title,meta,menu_order,vkbm_menu_group',
				status: canViewPrivateMenus ? 'publish,private' : undefined,
			} ),
		[ canViewPrivateMenus ]
	);

	useCollection( menuCollectionPath, setMenus );

	useEffect( () => {
		let isMounted = true;

		if ( ! menuId ) {
			setMenuPreview( {
				html: '',
				isLoading: false,
				error: '',
			} );
			return () => {
				isMounted = false;
			};
		}

		setMenuPreview( {
			html: '',
			isLoading: true,
			error: '',
		} );

		apiFetch( { path: `/vkbm/v1/menu-preview/${ menuId }` } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuPreview( {
					html: response?.html || '',
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuPreview( {
					html: '',
					isLoading: false,
					error:
						error?.message ||
						__(
							'Failed to retrieve menu information.',
							'vk-booking-manager'
						),
				} );
			} );

		return () => {
			isMounted = false;
		};
	}, [ menuId ] );
	const staffCollectionPath = useMemo( () => {
		if ( ! providerSettingsLoaded || ! providerSettings.staffEnabled ) {
			return '';
		}
		return '/wp/v2/vkbm_resource?per_page=100&_fields=id,title,meta,nomination_fee,resource_tags';
	}, [ providerSettingsLoaded, providerSettings.staffEnabled ] );
	useCollection( staffCollectionPath, setStaffOptions );

	const dayMetaMap = useMemo( () => {
		if ( ! calendarData?.days ) {
			return {};
		}

		return calendarData.days.reduce( ( acc, day ) => {
			acc[ day.date ] = day;
			return acc;
		}, {} );
	}, [ calendarData ] );

	const fetchCalendar = useCallback( () => {
		if ( ! menuId || ! monthCursor.year || ! monthCursor.month ) {
			setCalendarData( null );
			return;
		}

		setCalendarLoading( true );
		setCalendarError( null );

		const path = buildApiPath( '/vkbm/v1/calendar-meta', {
			menu_id: menuId,
			resource_id: effectiveResourceId || undefined,
			year: monthCursor.year,
			month: monthCursor.month,
		} );

		apiFetch( { path } )
			.then( ( response ) => {
				setCalendarData( response );
			} )
			.catch( ( error ) => {
				setCalendarError(
					error?.message ||
						__( 'Failed to load calendar.', 'vk-booking-manager' )
				);
			} )
			.finally( () => {
				setCalendarLoading( false );
			} );
	}, [ menuId, effectiveResourceId, monthCursor ] );

	useEffect( () => {
		fetchCalendar();
	}, [ fetchCalendar ] );

	useEffect( () => {
		if ( ! menuId || ! selectedDate ) {
			setSlotData( [] );
			return;
		}

		setSlotLoading( true );
		setSlotError( null );

		const path = buildApiPath( '/vkbm/v1/availabilities', {
			menu_id: menuId,
			resource_id: effectiveResourceId || undefined,
			date: selectedDate,
		} );

		apiFetch( { path } )
			.then( ( response ) => {
				const slots = Array.isArray( response?.slots )
					? response.slots
					: [];
				const preferredStaffLabel = staffId
					? currentStaff?.title?.rendered ??
					  currentStaff?.title ??
					  currentStaff?.name ??
					  ''
					: '';

				const decoratedSlots = slots.map( ( slot ) => {
					const fallbackLabel =
						slot.staff_label ||
						slot.staff?.name ||
						preferredStaffLabel ||
						providerSettings.noNominationLabel;

					return {
						...slot,
						staff_label: preferredStaffLabel || fallbackLabel,
						staff:
							slot.staff ||
							( preferredStaffLabel
								? { id: staffId, name: preferredStaffLabel }
								: null ),
					};
				} );

				setSlotData( decoratedSlots );
			} )
			.catch( ( error ) => {
				setSlotError(
					error?.message ||
						__( 'Failed to read free space.', 'vk-booking-manager' )
				);
			} )
			.finally( () => {
				setSlotLoading( false );
			} );
	}, [ menuId, staffId, effectiveResourceId, selectedDate, currentStaff ] );

	useEffect( () => {
		setSubmitError( '' );
	}, [ selectedSlot, menuId, staffId ] );

	useEffect( () => {
		if ( selectedSlot && actionSectionRef.current ) {
			actionSectionRef.current.scrollIntoView( {
				behavior: 'smooth',
				block: 'start',
			} );
		}
	}, [ selectedSlot ] );

	const handleMonthChange = ( delta ) => {
		setMonthCursor( ( current ) => {
			const newMonth = current.month + delta;
			const date = new Date( current.year, newMonth - 1, 1 );
			return {
				year: date.getFullYear(),
				month: date.getMonth() + 1,
			};
		} );
	};

	const handleSelectDate = ( date ) => {
		if ( ! menuId ) {
			return;
		}
		setSelectedDate( date );
		setSelectedSlot( null );
	};

	const handleMenuChange = ( nextMenuId ) => {
		const nextMenu = menus.find( ( menu ) => menu.id === nextMenuId );
		const nextMeta = nextMenu?.meta;

		// 無料版では staffEnabled が false なので、スタッフIDの処理をスキップ
		if ( providerSettings.staffEnabled ) {
			const nextAssignableStaffIds =
				extractAssignableStaffIds( nextMeta );

			if ( nextAssignableStaffIds.length === 1 ) {
				setStaffId( nextAssignableStaffIds[ 0 ] );
			} else if (
				staffId &&
				! nextAssignableStaffIds.includes( staffId )
			) {
				setStaffId( 0 );
			}
			// 無料版では常にデフォルトスタッフIDを設定
			// providerSettings が読み込まれている場合のみ設定
		} else if (
			providerSettingsLoaded &&
			providerSettings.defaultStaffId > 0
		) {
			setStaffId( providerSettings.defaultStaffId );
		} else if ( providerSettingsLoaded ) {
			setStaffId( 0 );
		}

		setMenuId( nextMenuId );
		setSelectedDate( '' );
		setSelectedSlot( null );
		setGuests( 1 );
		// メニュー変更時は料金区分の人数もリセットする（区分構成が変わるため）。
		setGuestTierCounts( [] );
		// メニュー変更時は貸し切り指定もリセットする（メニューごとに設定が異なるため）。
		setUserExclusive( false );
		setMonthCursor( {
			year: new Date().getFullYear(),
			month: new Date().getMonth() + 1,
		} );
	};

	const handleStaffChange = ( nextStaffId ) => {
		setStaffId( nextStaffId );
		setSelectedSlot( null );
	};

	// お気に入り（いつもの）を選んでフォームに反映する。
	// メニューを切り替え、指名スタッフはメニューに紐づく対応スタッフに含まれる場合のみ適用する。
	const applyFavorite = ( favorite ) => {
		const favoriteMenuId = Number( favorite?.menu_id ) || 0;
		if ( favoriteMenuId <= 0 ) {
			return;
		}

		// メニュー変更（指名・日時・人数のリセットを含む）。
		handleMenuChange( favoriteMenuId );

		// handleMenuChange が指名を再計算するため、お気に入りの指名はその後に上書きする。
		const favoriteResourceId = Number( favorite?.resource_id ) || 0;
		if ( providerSettings.staffEnabled ) {
			if ( favoriteResourceId > 0 ) {
				const favoriteMenu = menus.find(
					( menu ) => menu.id === favoriteMenuId
				);
				const assignable = extractAssignableStaffIds(
					favoriteMenu?.meta
				);
				// 対応スタッフ未設定のメニュー、または対応スタッフに含まれる場合のみ指名する。
				if (
					assignable.length === 0 ||
					assignable.includes( favoriteResourceId )
				) {
					setStaffId( favoriteResourceId );
				}
			} else {
				// 指名なしで登録したお気に入りは、直前に選んでいたスタッフが
				// 新メニューでも対応可能でも引き継がず、必ず「指名なし」を反映する。
				setStaffId( 0 );
			}
		}

		setFavoritesError( '' );
	};

	// お気に入りを削除する。
	const handleDeleteFavorite = ( favoriteId ) => {
		const id = String( favoriteId || '' );
		if ( '' === id ) {
			return;
		}

		setFavoritesError( '' );

		apiFetch( {
			path: `/vkbm/v1/favorites/${ encodeURIComponent( id ) }`,
			method: 'DELETE',
		} )
			.then( () => {
				// 楽観的に一覧から取り除く。
				setFavorites( ( current ) =>
					Array.isArray( current )
						? current.filter( ( favorite ) => favorite?.id !== id )
						: []
				);
			} )
			.catch( ( error ) => {
				setFavoritesError(
					error?.message ||
						__(
							'Could not delete the favorite.',
							'vk-booking-manager'
						)
				);
			} );
	};

	const handleMenuLoopClick = useCallback(
		( event ) => {
			const target = event.target;
			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}

			const reserveButton = target.closest(
				'a.vkbm-menu-loop__button--reserve'
			);
			if ( ! reserveButton ) {
				return;
			}

			event.preventDefault();

			const item = target.closest( '.vkbm-menu-loop__item' );
			if ( ! item ) {
				return;
			}

			const rawId = item.getAttribute( 'data-menu-id' ) || '';
			const nextId = Number( rawId ) || 0;

			if ( ! nextId ) {
				return;
			}

			handleMenuChange( nextId );
			const blockRoot = layoutRef.current?.closest?.(
				'.vkbm-reservation-block'
			);
			const scrollTarget = blockRoot || layoutRef.current;
			if ( scrollTarget ) {
				scrollTarget.scrollIntoView( {
					behavior: 'smooth',
					block: 'start',
				} );
			}
		},
		[ handleMenuChange ]
	);

	// 複数人一括予約メニューで満枠（選択可能人数が0）のスロットは予約へ進めない。
	// 料金区分メニューは合計人数が 1〜選択可能上限の範囲にある場合のみ進める。
	const canProceed = Boolean(
		menuId &&
			selectedSlot &&
			( ! allowMultipleGuests || maxSelectableGuests >= 1 ) &&
			( ! hasPriceTiers ||
				( guestTiersTotalCount >= 1 &&
					guestTiersTotalCount <= maxSelectableGuests ) )
	);

	const handleProceed = useCallback( () => {
		if ( isEditor ) {
			setSubmitError(
				__(
					'The editor does not transition to the confirmation page.',
					'vk-booking-manager'
				)
			);
			return;
		}

		if ( ! selectedSlot || ! menuId ) {
			setSubmitError(
				__(
					'Please select your menu and reservation slot before proceeding.',
					'vk-booking-manager'
				)
			);
			return;
		}

		// 防御的チェック: 複数人一括予約で人数が1未満（満枠で 0 にクランプされた等）の場合は送信しない。
		// canProceed 側のロジックにバグがあっても不正な人数をサーバーへ送らないための保険。
		if ( allowMultipleGuests && ! hasPriceTiers && guests < 1 ) {
			setSubmitError(
				__( 'Please select the quantity.', 'vk-booking-manager' )
			);
			return;
		}

		// 料金区分メニューは合計人数が最低1名必要（特定区分が0名でも合計1名以上ならOK）。
		if ( hasPriceTiers && guestTiersTotalCount < 1 ) {
			setSubmitError(
				__( 'Please select at least one guest.', 'vk-booking-manager' )
			);
			return;
		}

		setIsSubmitting( true );
		setSubmitError( '' );

		const payload = {
			token: getDraftTokenFromCookie(),
			menu_id: menuId,
			// おまかせ自動分配時は resource_id=0 で送り、サーバーで複数スタッフへ配分する。
			// effectiveResourceId は常に数値（autoDistribute なら 0、それ以外は staffId）のため || 0 は不要。
			resource_id: effectiveResourceId,
			menu_label:
				currentMenu?.title?.rendered ??
				currentMenu?.title ??
				currentMenu?.name ??
				'',
			staff_label: effectiveResourceId
				? currentStaff?.title?.rendered ??
				  currentStaff?.title ??
				  currentStaff?.name ??
				  ''
				: providerSettings.noNominationLabel,
			// おまかせ自動分配のときは指名扱いにしない（複数スタッフへ配分させる）。
			// それ以外は従来どおりスタッフ選択時に指名扱いとする。
			is_staff_preferred: ! autoDistribute && Boolean( staffId ),
			date: selectedDate,
			// 予約人数（複数人一括予約対応メニューのみ。非対応時は1）。
			// 料金区分メニューは区分人数の合計、それ以外は単一の人数。
			guests: hasPriceTiers
				? guestTiersTotalCount
				: allowMultipleGuests
				? guests
				: 1,
			// 料金区分メニューのみ、区分ごとの人数（区分インデックス => 人数）を送る。
			// ラベル・料金はサーバ保存メタを正とするため送らない（送っても無視される）。
			...( hasPriceTiers
				? {
						guest_tiers: guestTierCounts.map( ( value ) =>
							Math.max( 0, Math.floor( Number( value ) || 0 ) )
						),
				  }
				: {} ),
			// ユーザーによる貸し切り指定（#305）。チェックが有効状態で選択されているときのみ true を送る。
			// 単価・適用外人数・最小催行人数・空き枠の最終判定はサーバ側で権威的に行う。
			user_exclusive: Boolean( userExclusive && exclusiveCheckEnabled ),
			slot: {
				slot_id: selectedSlot.slot_id,
				start_at: selectedSlot.start_at,
				end_at: selectedSlot.end_at,
				service_end_at:
					selectedSlot.service_end_at || selectedSlot.end_at,
				duration_minutes: selectedSlot.duration_minutes || 0,
				// 最小催行人数（グループ開催型）と当該枠の合計予約人数。確定画面の催行注記表示に使う。
				min_capacity: Math.max(
					0,
					Number( selectedSlot.min_capacity ) || 0
				),
				booked_guests: Math.max(
					0,
					Number( selectedSlot.booked_guests ) || 0
				),
				staff_label:
					selectedSlot.staff_label ||
					( staffId
						? currentStaff?.title?.rendered ??
						  currentStaff?.title ??
						  currentStaff?.name ??
						  ''
						: providerSettings.noNominationLabel ),
				staff: selectedSlot.staff
					? {
							id: selectedSlot.staff.id,
							name:
								selectedSlot.staff.name ||
								selectedSlot.staff.title ||
								'',
					  }
					: null,
				assignable_staff_ids: Array.isArray(
					selectedSlot.assignable_staff_ids
				)
					? selectedSlot.assignable_staff_ids
							.map( ( id ) => Number( id ) || 0 )
							.filter( Boolean )
					: [],
				auto_assign: Boolean(
					selectedSlot.auto_assign ||
						( ! staffId &&
							! selectedSlot.staff &&
							( ! selectedSlot.assignable_staff_ids ||
								selectedSlot.assignable_staff_ids.length > 0 ) )
				),
			},
			meta: {
				timezone: calendarData?.meta?.timezone || '',
			},
		};

		apiFetch( {
			path: '/vkbm/v1/drafts',
			method: 'POST',
			data: payload,
		} )
			.then( ( response ) => {
				const token = sanitizeDraftToken( response?.token );
				const expiresIn = response?.expires_in || 1800;
				if ( ! token ) {
					throw new Error(
						__(
							'Temporary reservation data save failed.',
							'vk-booking-manager'
						)
					);
				}

				storeDraftToken( token, expiresIn );

				if ( typeof window !== 'undefined' ) {
					const url = new URL( window.location.href );
					url.searchParams.delete( 'vkbm_auth' );
					url.searchParams.set( 'draft', token );
					window.location.href = url.toString();
				}
			} )
			.catch( ( error ) => {
				setSubmitError(
					error?.message ||
						__(
							'Failed to save reservation details. Please try again later.',
							'vk-booking-manager'
						)
				);
			} )
			.finally( () => {
				setIsSubmitting( false );
			} );
	}, [
		allowMultipleGuests,
		guests,
		hasPriceTiers,
		guestTierCounts,
		guestTiersTotalCount,
		userExclusive,
		exclusiveCheckEnabled,
		calendarData?.meta?.timezone,
		currentMenu,
		currentStaff,
		isEditor,
		menuId,
		providerSettings,
		selectedDate,
		selectedSlot,
		staffId,
	] );

	const shouldShowLogo =
		providerSettings.showProviderLogo &&
		typeof providerSettings.providerLogoUrl === 'string' &&
		providerSettings.providerLogoUrl.trim() !== '';
	const shouldShowName =
		providerSettings.showProviderName &&
		typeof providerSettings.providerName === 'string' &&
		providerSettings.providerName.trim() !== '';
	const shouldShowBrand = shouldShowLogo || shouldShowName;
	const reservationPageUrl =
		typeof providerSettings.reservationPageUrl === 'string'
			? providerSettings.reservationPageUrl
			: '';
	const brandLinkHref = reservationPageUrl.trim();
	const brandLogoAlt = shouldShowName
		? providerSettings.providerName
		: __( 'Logo image', 'vk-booking-manager' );
	const navAriaLabel = __(
		'Reservation block navigation',
		'vk-booking-manager'
	);
	const returnLabel = shouldShowBrand
		? __( 'Return', 'vk-booking-manager' )
		: __( 'return', 'vk-booking-manager' );
	const navVariant = shouldShowUserSection
		? 'return'
		: isLoggedIn && canManageReservations
		? 'staff'
		: isLoggedIn
		? 'member'
		: 'guest';
	const navProps = {
		screen: 'reservation',
		variant: navVariant,
		ariaLabel: navAriaLabel,
		staffDashboardHref: shiftDashboardUrl || '',
		logoutHref:
			logoutUrl ||
			buildLogoutFallbackUrl( providerSettings.reservationPageUrl ),
		onReturn: () => handleAuthLink( authMode || '' ),
		returnLabel,
		onBookings: () => handleAuthLink( 'bookings' ),
		isBookingsActive: authMode === 'bookings',
		onProfile: () => handleAuthLink( 'profile' ),
		isProfileActive: isProfileMode,
		onLogin: () => handleAuthLink( 'login' ),
		isLoginActive: authMode === 'login',
		onRegister: () => handleAuthLink( 'register' ),
		isRegisterActive: authMode === 'register',
	};

	if ( confirmDraftToken ) {
		return (
			<div className="vkbm-reservation-content vkbm-reservation-content--confirm">
				<BookingConfirmApp
					redirectUrl=""
					termsLabel={ __(
						'I agree to the terms of use',
						'vk-booking-manager'
					) }
					policyText={ __(
						'Please check our cancellation policy before booking.',
						'vk-booking-manager'
					) }
					successMessage={ __(
						'Your reservation has been completed.',
						'vk-booking-manager'
					) }
					isEditor={ isEditor }
				/>
			</div>
		);
	}

	// 予約一覧を「今後の予約」と「過去の予約」に分割する。is_past はサーバ側で判定済み。
	const upcomingBookings = bookings.filter(
		( booking ) => ! booking?.is_past
	);
	// 過去の予約は新しい順（降順）に並べ替え、表示件数を上限まで絞る。
	const pastBookings = bookings
		.filter( ( booking ) => booking?.is_past )
		.slice()
		.reverse()
		.slice( 0, 20 );

	// 予約カード1件分を描画する。今後／過去どちらのセクションからも再利用する。
	const renderBookingCard = ( booking, index ) => {
		const datetimeParts = formatBookingDateTimeParts(
			booking?.start_at,
			booking?.end_at
		);
		const statusKey = String( booking?.status || '' ).trim();
		const isPending = statusKey.toLowerCase() === 'pending';
		const isCancelled = statusKey.toLowerCase() === 'cancelled';
		// メニューが削除済み等で参照できない場合は「同じ内容で予約する」を出さない。
		const canRebook =
			Number( booking?.menu_id ) > 0 &&
			String( booking?.menu_name || '' ).trim() !== '';
		const rebookUrl = canRebook
			? buildRebookUrl( booking, providerSettings.reservationPageUrl )
			: '';

		return (
			<div
				className={ [
					'vkbm-confirm__summary',
					isCancelled && 'vkbm-confirm__summary--cancelled',
				]
					.filter( Boolean )
					.join( ' ' ) }
				key={ booking?.id || index }
			>
				{ ( datetimeParts.date || datetimeParts.time ) && (
					<div className="vkbm-confirm__summary-title">
						<div className="vkbm-confirm__datetime">
							{ isPending && (
								<span className="vkbm-confirm__status vkbm-confirm__status--pending">
									{ __( 'Pending', 'vk-booking-manager' ) }
								</span>
							) }
							{ isCancelled && (
								<span className="vkbm-confirm__status vkbm-confirm__status--cancelled">
									{ __( 'Cancelled', 'vk-booking-manager' ) }
								</span>
							) }
							{ datetimeParts.date && (
								<span className="vkbm-confirm__date">
									{ datetimeParts.date }
								</span>
							) }
							{ datetimeParts.time && (
								<span className="vkbm-confirm__time">
									{ datetimeParts.date ? ' ' : '' }
									{ datetimeParts.time }
								</span>
							) }
						</div>
						<div className="vkbm-confirm__actions">
							{ booking?.can_cancel && (
								<button
									type="button"
									className="vkbm-button vkbm-button__sm vkbm-button__secondary vkbm-confirm__cancel-button"
									onClick={ () =>
										handleCancelBooking( booking?.id )
									}
									disabled={
										cancellingBookingId ===
										Number( booking?.id )
									}
								>
									{ cancellingBookingId ===
									Number( booking?.id )
										? __(
												'Processing…',
												'vk-booking-manager'
										  )
										: __( 'Cancel', 'vk-booking-manager' ) }
								</button>
							) }
							{ canRebook && rebookUrl && (
								<a
									className="vkbm-button vkbm-button__sm vkbm-button__primary vkbm-confirm__rebook-button"
									href={ rebookUrl }
								>
									{ __(
										'Book the same again',
										'vk-booking-manager'
									) }
								</a>
							) }
						</div>
					</div>
				) }
				<BookingSummaryItems
					booking={ booking }
					resourceLabel={ providerSettings.resourceLabelSingular }
					otherConditionsLabel={
						providerSettings.otherConditionsLabel
					}
					guestsCountLabel={ providerSettings.guestsCountLabel }
					guestsUnitLabel={ providerSettings.guestsUnitLabel }
					currencySymbol={ providerSettings.currencySymbol || null }
				/>
			</div>
		);
	};

	return (
		<div className="vkbm-reservation" ref={ layoutRef }>
			<ReservationHeader
				showBrand={ shouldShowBrand }
				brandLinkHref={ brandLinkHref }
				showLogo={ shouldShowLogo }
				logoUrl={ providerSettings.providerLogoUrl }
				logoAlt={ brandLogoAlt }
				showName={ shouldShowName }
				brandName={ providerSettings.providerName }
				nav={ navProps }
			/>

			{ shouldShowUserSection && (
				<section className="vkbm-user-section" aria-live="polite">
					<div className="vkbm-user-section__panel vkbm-reservation-content__auth-panel">
						{ authMode === 'bookings' && bookingsLoading && (
							<p
								className="vkbm-alert vkbm-alert__info vkbm-reservation-content__auth-loading"
								role="status"
							>
								{ __( 'Loading…', 'vk-booking-manager' ) }
							</p>
						) }
						{ authMode === 'bookings' && bookingsError && (
							<p
								className="vkbm-alert vkbm-alert__danger vkbm-reservation-content__auth-error"
								role="alert"
							>
								{ bookingsError }
							</p>
						) }
						{ authMode === 'bookings' &&
							! bookingsLoading &&
							! bookingsError &&
							bookings.length === 0 && (
								<p
									className="vkbm-alert vkbm-alert__info vkbm-reservation-content__auth-loading"
									role="status"
								>
									{ __(
										'There are no reservations to display.',
										'vk-booking-manager'
									) }
								</p>
							) }
						{ authMode === 'bookings' &&
							upcomingBookings.length > 0 && (
								<div className="vkbm-confirm vkbm-confirm--upcoming">
									<h3 className="vkbm-confirm__group-title">
										{ __(
											'Upcoming reservations',
											'vk-booking-manager'
										) }
									</h3>
									{ upcomingBookings.map(
										( booking, index ) =>
											renderBookingCard( booking, index )
									) }
								</div>
							) }
						{ authMode === 'bookings' &&
							pastBookings.length > 0 && (
								<div className="vkbm-confirm vkbm-confirm--past">
									<h3 className="vkbm-confirm__group-title">
										{ __(
											'Past reservations',
											'vk-booking-manager'
										) }
									</h3>
									{ pastBookings.map( ( booking, index ) =>
										renderBookingCard( booking, index )
									) }
								</div>
							) }

						{ authMode !== 'bookings' && authLoading && (
							<p
								className="vkbm-alert vkbm-alert__info vkbm-reservation-content__auth-loading"
								role="status"
							>
								{ __( 'Loading form…', 'vk-booking-manager' ) }
							</p>
						) }
						{ authMode !== 'bookings' && authError && (
							<p
								className="vkbm-alert vkbm-alert__danger vkbm-reservation-content__auth-error"
								role="alert"
							>
								{ authError }
							</p>
						) }
						{ authMode !== 'bookings' && authFormHtml && (
							<div
								className="vkbm-reservation-content__auth-form"
								dangerouslySetInnerHTML={ {
									__html: authFormHtml,
								} }
							/>
						) }
					</div>
				</section>
			) }

			{ shouldShowReservation && (
				<div
					className={ [
						'vkbm-reservation-content',
						isEditor && 'vkbm-reservation-content--is-editor',
					]
						.filter( Boolean )
						.join( ' ' ) }
				>
					{ providerSettingsLoaded &&
						isLoggedIn &&
						favoritesError && (
							<div className="vkbm-favorites__status">
								<p
									className="vkbm-alert vkbm-alert__danger"
									role="alert"
								>
									{ favoritesError }
								</p>
							</div>
						) }
					{ providerSettingsLoaded &&
						isLoggedIn &&
						favorites.length > 0 && (
							<section
								className="vkbm-favorites"
								aria-label={ __(
									'Favorites',
									'vk-booking-manager'
								) }
							>
								<h3 className="vkbm-favorites__title">
									{ __( 'Your usual', 'vk-booking-manager' ) }
								</h3>
								<ul className="vkbm-favorites__list">
									{ favorites.map( ( favorite ) => (
										<li
											className="vkbm-favorites__item"
											key={ favorite.id }
										>
											<button
												type="button"
												className="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__primary vkbm-favorites__apply"
												onClick={ () =>
													applyFavorite( favorite )
												}
												disabled={
													! favorite.available
												}
											>
												{ favorite.label ||
													favorite.menu_name }
											</button>
											<button
												type="button"
												className="vkbm-favorites__remove"
												aria-label={ __(
													'Delete favorite',
													'vk-booking-manager'
												) }
												onClick={ () =>
													handleDeleteFavorite(
														favorite.id
													)
												}
											>
												×
											</button>
										</li>
									) ) }
								</ul>
							</section>
						) }
					{ ! providerSettingsLoaded ? (
						<p
							className="vkbm-alert vkbm-alert__info"
							role="status"
						>
							{ __( 'Loading…', 'vk-booking-manager' ) }
						</p>
					) : providerSettings.showMenuList && ! menuId ? (
						menuList.html ? (
							<section
								className="vkbm-reservation-content__menu-list"
								aria-live="polite"
								onClick={ handleMenuLoopClick }
								dangerouslySetInnerHTML={ {
									__html: menuList.html,
								} }
							/>
						) : (
							<section
								className="vkbm-reservation-content__menu-list"
								aria-live="polite"
							>
								{ menuList.isLoading && (
									<p
										className="vkbm-alert vkbm-alert__info"
										role="status"
									>
										{ __(
											'Loading service menu…',
											'vk-booking-manager'
										) }
									</p>
								) }
								{ menuList.error && (
									<p
										className="vkbm-alert vkbm-alert__danger"
										role="alert"
									>
										{ menuList.error }
									</p>
								) }
								{ providerSettingsLoaded &&
									! menuList.isLoading &&
									! menuList.error &&
									! menuList.html && (
										<p
											className="vkbm-alert vkbm-alert__warning"
											role="status"
										>
											{ __(
												'There are no service menus to display.',
												'vk-booking-manager'
											) }
										</p>
									) }
							</section>
						)
					) : (
						<SelectedPlanSummary
							menuId={ menuId }
							staffId={ staffId }
							menus={ menus }
							staffOptions={ availableStaffOptions }
							onMenuChange={ handleMenuChange }
							onStaffChange={ handleStaffChange }
							allowStaffSelection={ staffSelectionEnabled }
							showStaffField={ providerSettings.staffEnabled }
							lockStaffSelection={ shouldLockStaffSelection }
							pricingRows={ pricingRows }
							menuPreviewLoading={ menuPreview.isLoading }
							menuPreviewError={ menuPreview.error }
							menuPreviewHtml={ menuPreview.html }
							resourceLabelSingular={
								providerSettings.resourceLabelSingular
							}
							noNominationLabel={
								providerSettings.noNominationLabel
							}
						/>
					) }

					{ hasMenuSelection && (
						<div className="vkbm-reservation-content__body">
							<CalendarGrid
								year={ monthCursor.year }
								month={ monthCursor.month }
								dayMetaMap={ dayMetaMap }
								selectedDate={ selectedDate }
								onSelectDate={ handleSelectDate }
								onMonthChange={ handleMonthChange }
								isLoading={ calendarLoading }
								locale={ userBootstrap?.locale }
								closedDayLabel={
									providerSettings.closedDayLabel
								}
							/>

							<div className="vkbm-reservation-content__slots">
								<DailySlotList
									slots={ slotData }
									selectedDate={ selectedDate }
									onSelectSlot={ ( slot ) =>
										setSelectedSlot( slot )
									}
									selectedSlotId={ selectedSlot?.slot_id }
									isLoading={ slotLoading }
									error={ calendarError || slotError }
									showStaffLabel={
										providerSettings.staffEnabled
									}
									selectedStaffLabel={
										staffId
											? currentStaff?.title?.rendered ??
											  currentStaff?.title ??
											  currentStaff?.name ??
											  ''
											: ''
									}
									noNominationLabel={
										providerSettings.noNominationLabel
									}
								/>
							</div>
						</div>
					) }

					{ hasMenuSelection && (
						<div
							className="vkbm-plan-summary__status vkbm-plan-summary__status--detached"
							ref={ actionSectionRef }
						>
							<div className="vkbm-plan-summary__datetime">
								{ selectedSlot ? (
									<>
										{ /* 日付（年月日＋曜日）は途中で改行させない。 */ }
										<span className="text-nowrap">
											{ selectedSlot.start_at?.slice(
												0,
												10
											) }{ ' ' }
											{ formatWeekdayLabel(
												selectedSlot.start_at
											) }
										</span>{ ' ' }
										{ /* 時間帯（開始−終了）も途中で改行させない。 */ }
										<span className="text-nowrap">
											{ selectedSlot.start_at?.slice(
												11,
												16
											) }{ ' ' }
											-{ ' ' }
											{ (
												selectedSlot.service_end_at ||
												selectedSlot.end_at
											)?.slice( 11, 16 ) }
										</span>
									</>
								) : (
									<span>
										{ __(
											'Not selected',
											'vk-booking-manager'
										) }
									</span>
								) }
							</div>
							{ /* 料金区分メニュー: 区分ごとに人数を入力する。 */ }
							{ allowMultipleGuests &&
								selectedSlot &&
								maxSelectableGuests >= 1 &&
								hasPriceTiers && (
									<div className="vkbm-plan-summary__guest-tiers">
										<span className="vkbm-plan-summary__guests-label">
											{
												providerSettings.guestsCountLabel
											}
										</span>
										{ priceTiers.map( ( tier, index ) => (
											<div
												key={ index }
												className="vkbm-plan-summary__guest-tier"
											>
												<label
													className="vkbm-plan-summary__guest-tier-label"
													htmlFor={ `vkbm-reservation-tier-${ index }` }
												>
													<span className="text-nowrap">
														{ tier.label }
													</span>
													<span className="vkbm-plan-summary__guest-tier-price">
														{ formatCurrency(
															tier.price,
															providerSettings.currencySymbol ||
																null
														) }
													</span>
												</label>
												<input
													type="number"
													id={ `vkbm-reservation-tier-${ index }` }
													className="vkbm-plan-summary__guests-select"
													min="0"
													max={ maxSelectableGuests }
													step="1"
													value={
														guestTierCounts[
															index
														] ?? 0
													}
													onChange={ ( event ) => {
														const value = Math.max(
															0,
															Math.floor(
																Number(
																	event.target
																		.value
																) || 0
															)
														);
														setGuestTierCounts(
															( current ) => {
																const next =
																	priceTiers.map(
																		(
																			_,
																			i
																		) =>
																			Math.max(
																				0,
																				Math.floor(
																					Number(
																						current[
																							i
																						]
																					) ||
																						0
																				)
																			)
																	);
																next[ index ] =
																	value;
																return next;
															}
														);
													} }
												/>
											</div>
										) ) }
										{ /* 人数未選択（合計0）のときは概算合計を表示しない。 */ }
										{ guestTiersTotalCount > 0 && (
											<div className="vkbm-plan-summary__guest-tiers-total">
												{ sprintf(
													/* translators: %s: total price. */
													__(
														'Total: %s',
														'vk-booking-manager'
													),
													formatCurrency(
														priceTiers.reduce(
															(
																sum,
																tier,
																index
															) =>
																sum +
																tier.price *
																	Math.max(
																		0,
																		Math.floor(
																			Number(
																				guestTierCounts[
																					index
																				]
																			) ||
																				0
																		)
																	),
															0
														),
														providerSettings.currencySymbol ||
															null
													)
												) }
											</div>
										) }
										{ /* 合計が残枠を超えると「予約へ進む」が無言で disabled になるのを避けるため、理由を明示する。 */ }
										{ guestTiersTotalCount >
											maxSelectableGuests && (
											<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
												{ sprintf(
													/* translators: %d: number of remaining bookable guests. */
													__(
														'You can book up to %d more.',
														'vk-booking-manager'
													),
													maxSelectableGuests
												) }
											</p>
										) }
									</div>
								) }
							{ /* 通常の複数人一括予約メニュー: 単一の人数入力。 */ }
							{ allowMultipleGuests &&
								selectedSlot &&
								! hasPriceTiers &&
								maxSelectableGuests > 1 && (
									<div className="vkbm-plan-summary__guests">
										<label
											className="vkbm-plan-summary__guests-label"
											htmlFor="vkbm-reservation-guests"
										>
											{
												providerSettings.guestsCountLabel
											}
										</label>
										{ /* 数値入力にして DOM 数を maxSelectableGuests に依存させない（大きな上限でのフリーズ防止）。 */ }
										{ /* Use a numeric input so the DOM count stays constant regardless of maxSelectableGuests. */ }
										<input
											type="number"
											id="vkbm-reservation-guests"
											className="vkbm-plan-summary__guests-select"
											min="1"
											max={ maxSelectableGuests }
											step="1"
											value={ guests }
											onChange={ ( event ) =>
												// 手入力で小数が入る可能性があるため整数へ正規化してから範囲内にクランプする。
												setGuests(
													Math.min(
														Math.max(
															1,
															Math.floor(
																Number(
																	event.target
																		.value
																) || 1
															)
														),
														maxSelectableGuests
													)
												)
											}
										/>
									</div>
								) }
							{ /* ユーザーによる貸し切り指定（#305）。人数入力の直下に表示する。 */ }
							{ exclusiveSelectable && selectedSlot && (
								<div className="vkbm-plan-summary__exclusive">
									<label className="vkbm-plan-summary__exclusive-label">
										<input
											type="checkbox"
											className="vkbm-plan-summary__exclusive-checkbox"
											checked={
												userExclusive &&
												exclusiveCheckEnabled
											}
											disabled={ ! exclusiveCheckEnabled }
											onChange={ ( event ) =>
												setUserExclusive(
													event.target.checked
												)
											}
										/>
										<span>
											{ __(
												'Make this time slot private (no other bookings will be accepted)',
												'vk-booking-manager'
											) }
										</span>
									</label>
									{ /* 既に予約がある枠は非表示にせず disabled + 理由表示（色だけに頼らずテキスト併記）。 */ }
									{ slotIsOccupied ? (
										<p className="vkbm-plan-summary__exclusive-note vkbm-plan-summary__exclusive-note--disabled">
											{ __(
												'This slot already has a booking, so a private booking cannot be specified.',
												'vk-booking-manager'
											) }
										</p>
									) : (
										<>
											{ /* 人数が最小催行人数に満たない場合は理由を表示する。 */ }
											{ ! meetsMinCapacity && (
												<p className="vkbm-plan-summary__exclusive-note vkbm-plan-summary__exclusive-note--disabled">
													{ sprintf(
														/* translators: %d: minimum number of guests required for a private booking. */
														__(
															'A private booking requires at least %d guests.',
															'vk-booking-manager'
														),
														minCapacity
													) }
												</p>
											) }
											<p className="vkbm-plan-summary__exclusive-note">
												{ __(
													'Making it private means no one else can book this time slot.',
													'vk-booking-manager'
												) }
											</p>
											{ exclusiveFeePerPerson > 0 &&
												( exclusiveFeeExempted ? (
													<p className="vkbm-plan-summary__exclusive-note vkbm-plan-summary__exclusive-note--muted">
														{ sprintf(
															/* translators: %d: number of guests at or above which the private booking fee is not charged. */
															__(
																'No private booking fee applies because there are %d or more guests.',
																'vk-booking-manager'
															),
															exclusiveFeeExempt
														) }
													</p>
												) : (
													<p className="vkbm-plan-summary__exclusive-note">
														{ sprintf(
															/* translators: %s: private booking fee per guest. */
															__(
																'A private booking fee of %s per guest will be added.',
																'vk-booking-manager'
															),
															formatCurrency(
																exclusiveFeePerPerson,
																providerSettings.currencySymbol ||
																	null
															)
														) }
													</p>
												) ) }
										</>
									) }
								</div>
							) }
							{ /* 料金サマリー（貸切料金行）。aria-live 領域は常設し、中身（金額・注記）を出し入れする。 */ }
							{ /* 後挿入の live region はスクリーンリーダーが初回読み上げしないことがあるため、 */ }
							{ /* 貸切料金が発生し得る場面（メニューがユーザー貸切ON＋単価あり）では領域自体を常に描画し、 */ }
							{ /* チェックの選択状態に応じて内側の金額行のみを出し入れする。 */ }
							{ exclusiveSelectable &&
								selectedSlot &&
								exclusiveFeePerPerson > 0 && (
									<div
										className="vkbm-plan-summary__exclusive-fee-live"
										aria-live="polite"
									>
										{ exclusiveCheckEnabled &&
											userExclusive && (
												<div className="vkbm-plan-summary__exclusive-fee">
													<span className="vkbm-plan-summary__exclusive-fee-label">
														{ __(
															'Private booking fee',
															'vk-booking-manager'
														) }
													</span>
													{ exclusiveFeeExempted ? (
														<span className="vkbm-plan-summary__exclusive-fee-value vkbm-plan-summary__exclusive-fee-value--muted">
															{ formatCurrency(
																0,
																providerSettings.currencySymbol ||
																	null
															) }
															<span className="vkbm-plan-summary__exclusive-fee-note">
																{ sprintf(
																	/* translators: %d: number of guests at or above which the private booking fee is not charged. */
																	__(
																		'No private booking fee applies because there are %d or more guests.',
																		'vk-booking-manager'
																	),
																	exclusiveFeeExempt
																) }
															</span>
														</span>
													) : (
														<span className="vkbm-plan-summary__exclusive-fee-value">
															{ '+ ' +
																formatCurrency(
																	exclusiveFee,
																	providerSettings.currencySymbol ||
																		null
																) }
														</span>
													) }
												</div>
											) }
									</div>
								) }
							<div className="vkbm-plan-summary__actions">
								<button
									type="button"
									className="vkbm-plan-summary__action vkbm-button vkbm-button__md vkbm-button__primary"
									onClick={ handleProceed }
									disabled={ ! canProceed || isSubmitting }
								>
									{ isSubmitting
										? __(
												'Processing…',
												'vk-booking-manager'
										  )
										: __(
												'Proceed to Reservation',
												'vk-booking-manager'
										  ) }
								</button>
								{ submitError && (
									<p className="vkbm-plan-summary__error">
										{ submitError }
									</p>
								) }
							</div>
						</div>
					) }
				</div>
			) }
		</div>
	);
};
const formatWeekdayLabel = ( isoString ) => {
	if ( ! isoString ) {
		return '';
	}
	try {
		const date = new Date( isoString );
		if ( Number.isNaN( date.getTime() ) ) {
			return '';
		}
		const weekday = new Intl.DateTimeFormat( 'ja-JP', {
			weekday: 'short',
		} ).format( date );
		return `(${ weekday })`;
	} catch ( error ) {
		return '';
	}
};
