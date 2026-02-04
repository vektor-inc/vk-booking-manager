import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import {
	CalendarGrid,
	DailySlotList,
	SelectedPlanSummary,
} from './booking-ui';
import {
	extractMenuBasePrice,
	formatCurrency,
	normalizePriceValue,
} from '../shared/pricing';
import { BookingConfirmApp } from './booking-confirm-app';
import { BookingSummaryItems } from './components/booking-summary-items';
import { sanitizeDraftToken } from '../shared/draft-token';
const parseQueryParams = () => {
	if (typeof window === 'undefined') {
		return {};
	}

	try {
		const params = new URLSearchParams(window.location.search);
		return {
			menuId: Number(params.get('menu_id')) || 0,
			staffId: Number(params.get('resource_id')) || 0,
			date: params.get('date') || '',
			auth: params.get('vkbm_auth') || '',
			draft: sanitizeDraftToken(params.get('draft') || ''),
		};
	} catch (error) {
		return {};
	}
};

const buildApiPath = (base, query) => {
	const searchParams = new URLSearchParams();
	Object.entries(query).forEach(([key, value]) => {
		if (value !== undefined && value !== null && value !== '') {
			searchParams.append(key, value);
		}
	});
	return `${base}?${searchParams.toString()}`;
};

const DRAFT_COOKIE = 'vkbm_draft_token';

const getDraftTokenFromCookie = () => {
	if (typeof document === 'undefined') {
		return '';
	}
	const match = document.cookie.match(
		new RegExp(`(?:^|; )${DRAFT_COOKIE}=([^;]*)`)
	);
	return sanitizeDraftToken(match ? decodeURIComponent(match[1]) : '');
};

const storeDraftToken = (token, maxAge = 1800) => {
	if (typeof document === 'undefined') {
		return;
	}
	const safeToken = sanitizeDraftToken(token);
	if (!safeToken) {
		return;
	}
	let cookie = `${DRAFT_COOKIE}=${encodeURIComponent(safeToken)}; path=/; max-age=${maxAge}; SameSite=Lax`;
	if (typeof window !== 'undefined' && window.location?.protocol === 'https:') {
		cookie += '; Secure';
	}
	document.cookie = cookie;
};

const useCollection = (path, setState) => {
	useEffect(() => {
		if (!path) {
			setState([]);
			return () => {};
		}

		let isMounted = true;
		apiFetch({ path })
			.then((result) => {
				if (isMounted) {
					setState(result || []);
				}
			})
			.catch(() => {
				if (isMounted) {
					setState([]);
				}
			});

		return () => {
			isMounted = false;
		};
	}, [path, setState]);
};

const useLoginState = () => {
	if (typeof document === 'undefined') {
		return false;
	}

	return document.body.classList.contains('logged-in');
};

const getCurrentUrl = () => {
	if (typeof window === 'undefined') {
		return '';
	}

	return window.location.href;
};

const buildLogoutFallbackUrl = (reservationPageUrl = '') => {
	if (typeof window === 'undefined') {
		return '/wp-login.php?action=logout';
	}
	const target =
		typeof reservationPageUrl === 'string' && reservationPageUrl.trim() !== ''
			? reservationPageUrl
			: window.location.href;
	const redirect = encodeURIComponent(target);
	return `/wp-login.php?action=logout&redirect_to=${redirect}`;
};

const buildModeUrl = (mode) => {
	if (typeof window === 'undefined') {
		return '';
	}

	const url = new URL(window.location.href);

	if (mode) {
		url.searchParams.set('vkbm_auth', mode);
	} else {
		url.searchParams.delete('vkbm_auth');
	}

	return url.toString();
};

const formatBookingDateTimeParts = (startAt, endAt) => {
	if (!startAt) {
		return { date: '', time: '' };
	}

	let settings;
	try {
		settings =
			typeof __experimentalGetSettings === 'function'
				? __experimentalGetSettings()
				: undefined;
	} catch (error) {
		settings = undefined;
	}

	const dateFormat = settings?.formats?.date || 'Y/m/d';
	const timeFormat = settings?.formats?.time || 'H:i';
	const wpTimezone = settings?.timezone?.string;

	const startDate = new Date(startAt);
	if (Number.isNaN(startDate.getTime())) {
		return { date: startAt, time: '' };
	}

	const dateLabel = dateI18n(dateFormat, startDate, wpTimezone);
	const weekdayLabel = dateI18n('D', startDate, wpTimezone);
	const startTimeLabel = dateI18n(timeFormat, startDate, wpTimezone);

	let timeLabel = startTimeLabel;
	if (endAt) {
		const endDate = new Date(endAt);
		if (!Number.isNaN(endDate.getTime())) {
			const endTimeLabel = dateI18n(timeFormat, endDate, wpTimezone);
			timeLabel = `${startTimeLabel} - ${endTimeLabel}`.trim();
		}
	}

	return {
		date: `${dateLabel}(${weekdayLabel})`,
		time: timeLabel,
	};
};

export const ReservationApp = ({
	defaultMenuId = 0,
	defaultStaffId = 0,
	allowStaffSelection = true,
	isEditor = false,
}) => {
	const userBootstrap = useMemo(() => {
		if (typeof window === 'undefined') {
			return null;
		}
		return window.vkbmCurrentUserBootstrap || null;
	}, []);
	const queryDefaults = useMemo(() => parseQueryParams(), []);
	const initialMenuId = queryDefaults.menuId || defaultMenuId || 0;
	const initialStaffId = queryDefaults.staffId || defaultStaffId || 0;
	const initialDate = queryDefaults.date || '';
	const initialMonthDate = initialDate
		? new Date(initialDate)
		: new Date();

	const [menuId, setMenuId] = useState(initialMenuId);
	const [staffId, setStaffId] = useState(initialStaffId);
	const [selectedDate, setSelectedDate] = useState(initialDate);
	const [selectedSlot, setSelectedSlot] = useState(null);
	const [monthCursor, setMonthCursor] = useState({
		year: initialMonthDate.getFullYear(),
		month: initialMonthDate.getMonth() + 1,
	});

	const [menus, setMenus] = useState([]);
	const [staffOptions, setStaffOptions] = useState([]);
	const [providerSettings, setProviderSettings] = useState({
		taxEnabled: false,
		taxRate: 0,
		taxLabelText: '',
		reservationPageUrl: '',
		showMenuList: true,
		staffEnabled: false,
		defaultStaffId: 0,
		resourceLabelSingular: __('Staff', 'vk-booking-manager'),
		resourceLabelPlural: __('Staff', 'vk-booking-manager'),
		showProviderLogo: false,
		showProviderName: false,
		providerName: '',
		providerLogoUrl: '',
	});
	const [menuList, setMenuList] = useState({
		html: '',
		isLoading: true,
		error: '',
	});
	const [authFormHtml, setAuthFormHtml] = useState('');
	const [authLoading, setAuthLoading] = useState(false);
	const [authError, setAuthError] = useState('');
	const [authMode, setAuthMode] = useState(queryDefaults.auth || '');
	const [logoutUrl, setLogoutUrl] = useState(userBootstrap?.logoutUrl || '');
	const [shiftDashboardUrl, setShiftDashboardUrl] = useState(
		userBootstrap?.shiftDashboardUrl || ''
	);
	const [canManageReservations, setCanManageReservations] = useState(
		Boolean(userBootstrap?.canManageReservations)
	);
	const canViewPrivateMenus = Boolean(userBootstrap?.canViewPrivateMenus);
	const [bookingsLoading, setBookingsLoading] = useState(false);
	const [bookingsError, setBookingsError] = useState('');
	const [bookings, setBookings] = useState([]);
	const [cancellingBookingId, setCancellingBookingId] = useState(0);
	const isLoggedIn = useLoginState();
	const confirmDraftToken = queryDefaults.draft || '';
	const handleAuthLink = (mode) => {
		if (typeof window === 'undefined') {
			return;
		}

		setAuthMode((current) => {
			const nextMode = current === mode ? '' : mode;
			const url = new URL(window.location.href);
			if (nextMode) {
				url.searchParams.set('vkbm_auth', nextMode);
			} else {
				url.searchParams.delete('vkbm_auth');
			}
			window.history.replaceState(null, '', url.toString());
			return nextMode;
		});
	};

	const [calendarData, setCalendarData] = useState(null);
	const [calendarLoading, setCalendarLoading] = useState(false);
	const [calendarError, setCalendarError] = useState(null);

	const [slotData, setSlotData] = useState([]);
	const [slotLoading, setSlotLoading] = useState(false);
	const [slotError, setSlotError] = useState(null);
	const [isSubmitting, setIsSubmitting] = useState(false);
	const [submitError, setSubmitError] = useState('');
	const [menuPreview, setMenuPreview] = useState({
		html: '',
		isLoading: false,
		error: '',
	});
	const layoutRef = useRef(null);
	const actionSectionRef = useRef(null);
	const [providerSettingsLoaded, setProviderSettingsLoaded] = useState(false);
	const staffSelectionEnabled =
		allowStaffSelection && providerSettings.staffEnabled;
	useEffect(() => {
		let isMounted = true;

		apiFetch({ path: '/vkbm/v1/provider-settings' })
			.then((settings) => {
				if (!isMounted) {
					return;
				}

				setProviderSettings({
					taxEnabled: Boolean(settings?.tax_enabled),
					taxRate: Number(settings?.tax_rate) || 0,
					taxLabelText:
						typeof settings?.tax_label_text === 'string'
							? settings.tax_label_text
							: '',
					currencySymbol:
						typeof settings?.currency_symbol === 'string'
							? settings.currency_symbol
							: '',
					reservationPageUrl: settings?.reservation_page_url || '',
					showMenuList: settings?.reservation_show_menu_list !== false,
					staffEnabled: Boolean(settings?.staff_enabled),
					defaultStaffId: Number(settings?.default_staff_id) || 0,
					showProviderLogo: Boolean(settings?.reservation_show_provider_logo),
					showProviderName: Boolean(settings?.reservation_show_provider_name),
					resourceLabelSingular:
						typeof settings?.resource_label_singular === 'string' &&
						settings.resource_label_singular.trim() !== ''
							? settings.resource_label_singular
							: __('Staff', 'vk-booking-manager'),
					resourceLabelPlural:
						typeof settings?.resource_label_plural === 'string' &&
						settings.resource_label_plural.trim() !== ''
							? settings.resource_label_plural
							: (typeof settings?.resource_label_singular === 'string' &&
									settings.resource_label_singular.trim() !== ''
									? settings.resource_label_singular
									: __('Staff', 'vk-booking-manager')),
					providerName:
						typeof settings?.provider_name === 'string'
							? settings.provider_name
							: '',
					providerLogoUrl:
						typeof settings?.provider_logo_url === 'string'
							? settings.provider_logo_url
							: '',
				});
			})
			.catch(() => {
				// Keep defaults.
			})
			.finally(() => {
				if (isMounted) {
					setProviderSettingsLoaded(true);
				}
			});

		return () => {
			isMounted = false;
		};
	}, []);

	useEffect(() => {
		// providerSettings が読み込まれるまで待つ
		if (!providerSettingsLoaded) {
			return;
		}

		if (!providerSettings.staffEnabled) {
			// 無料版ではデフォルトスタッフIDを設定
			if (providerSettings.defaultStaffId > 0 && staffId !== providerSettings.defaultStaffId) {
				setStaffId(providerSettings.defaultStaffId);
			} else if (providerSettings.defaultStaffId === 0 && staffId !== 0) {
				setStaffId(0);
			}
		}
	}, [providerSettingsLoaded, providerSettings.staffEnabled, providerSettings.defaultStaffId, staffId]);

	useEffect(() => {
		let isMounted = true;

		if (!providerSettingsLoaded) {
			return () => {
				isMounted = false;
			};
		}

		if (!providerSettings.showMenuList || menuId) {
			setMenuList({
				html: '',
				isLoading: false,
				error: '',
			});
			return () => {
				isMounted = false;
			};
		}

		setMenuList({
			html: '',
			isLoading: true,
			error: '',
		});

		apiFetch({ path: '/vkbm/v1/menu-loop' })
			.then((response) => {
				if (!isMounted) {
					return;
				}

				setMenuList({
					html: response?.html || '',
					isLoading: false,
					error: '',
				});
			})
			.catch((error) => {
				if (!isMounted) {
					return;
				}

				setMenuList({
					html: '',
					isLoading: false,
					error:
						error?.message ||
						__('Could not get menu list.', 'vk-booking-manager'),
				});
			});

		return () => {
			isMounted = false;
		};
	}, [providerSettingsLoaded, providerSettings.showMenuList, menuId]);

	useEffect(() => {
		let isMounted = true;

		if (!isLoggedIn) {
			setLogoutUrl('');
			setShiftDashboardUrl('');
			setCanManageReservations(false);
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
			setLogoutUrl(userBootstrap.logoutUrl || '');
			setShiftDashboardUrl(userBootstrap.shiftDashboardUrl || '');
			setCanManageReservations(Boolean(userBootstrap.canManageReservations));
			return () => {
				isMounted = false;
			};
		}

		const currentUrl = getCurrentUrl();
		const path = buildApiPath('/vkbm/v1/current-user', {
			redirect: currentUrl || undefined,
		});

		apiFetch({ path })
			.then((response) => {
				if (!isMounted) {
					return;
				}

				setLogoutUrl(response?.logout_url || '');
				setShiftDashboardUrl(response?.shift_dashboard_url || '');
				setCanManageReservations(Boolean(response?.can_manage_reservations));
			})
			.catch(() => {
				if (isMounted) {
					setLogoutUrl('');
					setShiftDashboardUrl('');
					setCanManageReservations(false);
				}
			});

		return () => {
			isMounted = false;
		};
	}, [isLoggedIn]);

	useEffect(() => {
		if (!canManageReservations) {
			return;
		}

		if (authMode === 'profile' || authMode === 'bookings') {
			setAuthMode('');
		}
	}, [canManageReservations, authMode]);

useEffect(() => {
	if (!authMode) {
		setAuthFormHtml('');
		return;
	}

	if (authMode === 'bookings') {
		setAuthFormHtml('');
		return;
	}

	const requiresLogin = authMode === 'profile' || authMode === 'bookings';
	if (requiresLogin && !isLoggedIn) {
		setAuthFormHtml('');
		return;
	}

	if (!requiresLogin && isLoggedIn) {
		setAuthFormHtml('');
		return;
	}

	setAuthLoading(true);
	setAuthError('');
	setAuthFormHtml('');

	const params = new URLSearchParams();
	params.set('type', authMode);

	const redirectUrl = getCurrentUrl();
	if (redirectUrl) {
		params.set('redirect', redirectUrl);
	}

	if (authMode === 'login') {
		params.set('register_url', buildModeUrl('register'));
	} else if (authMode === 'register') {
		params.set('login_url', buildModeUrl('login'));
	}

	apiFetch({
		path: `/vkbm/v1/auth-form?${params.toString()}`,
		cache: 'no-store',
		credentials: 'same-origin',
	})
		.then((response) => {
			const html = response?.html || '';
			const message = response?.message || '';

			setAuthFormHtml(html);

			if (!html && authMode === 'register' && message) {
				setAuthError(message);
			}
		})
		.catch((error) => {
			setAuthError(
				error?.message ||
					__('The form could not be displayed.', 'vk-booking-manager')
			);
		})
		.finally(() => {
			setAuthLoading(false);
		});
}, [authMode, isLoggedIn]);

	const handleCancelBooking = useCallback(
		(bookingId) => {
			const id = Number(bookingId) || 0;
			if (!id || cancellingBookingId) {
				return;
			}

			if (
				typeof window !== 'undefined' &&
				!window.confirm(__('Do you want to cancel this reservation?', 'vk-booking-manager'))
			) {
				return;
			}

			setCancellingBookingId(id);
			setBookingsError('');

			apiFetch({
				path: `/vkbm/v1/my-bookings/${id}/cancel`,
				method: 'POST',
			})
				.then(() => {
					setBookings((current) =>
						Array.isArray(current)
							? current.filter((booking) => Number(booking?.id) !== id)
							: []
					);
				})
				.catch((error) => {
					setBookingsError(
						error?.message ||
							__('I was unable to cancel my reservation.', 'vk-booking-manager')
					);
				})
				.finally(() => {
					setCancellingBookingId(0);
				});
		},
		[cancellingBookingId]
	);

useEffect(() => {
	if (isLoggedIn && authMode && authMode !== 'profile' && authMode !== 'bookings') {
		setAuthMode('');
	}

	if (!isLoggedIn && (authMode === 'profile' || authMode === 'bookings')) {
		setAuthMode('');
	}
}, [isLoggedIn, authMode]);

useEffect(() => {
	let isMounted = true;

	if (!isLoggedIn || authMode !== 'bookings') {
		return () => {
			isMounted = false;
		};
	}

	setBookingsLoading(true);
	setBookingsError('');

	apiFetch({ path: '/vkbm/v1/my-bookings' })
		.then((response) => {
			if (!isMounted) {
				return;
			}

			setBookings(Array.isArray(response) ? response : []);
		})
		.catch(() => {
			if (!isMounted) {
				return;
			}

			setBookings([]);
			setBookingsError(__('The reservation list could not be loaded.', 'vk-booking-manager'));
		})
		.finally(() => {
			if (isMounted) {
				setBookingsLoading(false);
			}
		});

	return () => {
		isMounted = false;
	};
}, [authMode, isLoggedIn]);
	const currentMenu = useMemo(
		() => menus.find((menu) => menu.id === menuId),
		[menus, menuId]
	);
	const currentStaff = useMemo(
		() => staffOptions.find((staff) => staff.id === staffId),
		[staffOptions, staffId]
	);

	const assignableStaffIds = useMemo(() => {
		const meta = currentMenu?.meta;
		if ( ! meta ) {
			return [];
		}

		const rawIds =
			Array.isArray( meta._vkbm_staff_ids ) && meta._vkbm_staff_ids.length
				? meta._vkbm_staff_ids
				: Array.isArray( meta.vkbm_staff_ids ) && meta.vkbm_staff_ids.length
					? meta.vkbm_staff_ids
					: [];

		const normalized = rawIds
			.map((value) => {
				if ( typeof value === 'number' ) {
					return value;
				}
				if ( typeof value === 'string' ) {
					return Number(value) || 0;
				}
				return 0;
			})
			.filter((id) => id > 0);

		return Array.from(new Set(normalized));
	}, [currentMenu]);

	const availableStaffOptions = useMemo(() => {
		// 無料版では選択可能スタッフの制限を解除
		if (!providerSettings.staffEnabled) {
			return staffOptions;
		}

		if ( assignableStaffIds.length === 0 ) {
			return staffOptions;
		}

		const allowedIds = new Set(assignableStaffIds);
		return staffOptions.filter((staff) => allowedIds.has(staff.id));
	}, [assignableStaffIds, staffOptions, providerSettings.staffEnabled]);
	const shouldLockStaffSelection = assignableStaffIds.length === 1;

	useEffect(() => {
		// 無料版では assignableStaffIds のチェックをスキップ
		if (!providerSettings.staffEnabled) {
			return;
		}

		if (!menuId || assignableStaffIds.length === 0) {
			return;
		}

		if (assignableStaffIds.length === 1) {
			const onlyStaffId = assignableStaffIds[0];
			if (staffId !== onlyStaffId) {
				setStaffId(onlyStaffId);
			}
			return;
		}

		if (staffId && !assignableStaffIds.includes(staffId)) {
			setStaffId(0);
		}
	}, [menuId, assignableStaffIds, staffId, providerSettings.staffEnabled]);
	const isNominationFeeDisabled = useMemo(() => {
		const meta = currentMenu?.meta;
		if (!meta) {
			return false;
		}

		const raw =
			meta._vkbm_disable_nomination_fee ??
			meta.vkbm_disable_nomination_fee ??
			currentMenu?._vkbm_disable_nomination_fee ??
			currentMenu?.vkbm_disable_nomination_fee;

		if (raw === undefined || raw === null) {
			return false;
		}

		if (typeof raw === 'boolean') {
			return raw;
		}

		const normalized = String(raw).toLowerCase().trim();
		return normalized === '1' || normalized === 'true';
	}, [currentMenu]);
	const isProfileMode = isLoggedIn && authMode === 'profile';
	const shouldShowUserSection =
		((authMode === 'profile' || authMode === 'bookings') && isLoggedIn) ||
		(!isLoggedIn && (authMode === 'login' || authMode === 'register'));
	const shouldShowReservation = !shouldShowUserSection;
		const hasMenuSelection = Boolean(menuId);
		const getNavLinkClass = (mode) =>
			[
				'vkbm-user-actions__link',
				'vkbm-button',
				'vkbm-button__sm',
				'vkbm-button__link',
				'vkbm-reservation-layout__nav-link',
				mode && authMode === mode && 'is-active',
			]
				.filter(Boolean)
				.join(' ');

		const applyTax = useCallback(
			(value) => {
				const normalized = normalizePriceValue(value);

				if (normalized === null) {
					return null;
				}

				return normalized;
			},
			[providerSettings]
		);

	const basePriceRaw = useMemo(
		() => extractMenuBasePrice(currentMenu),
		[currentMenu]
	);

	const basePrice = useMemo(() => {
		if (basePriceRaw === null) {
			return null;
		}

		return applyTax(basePriceRaw);
	}, [applyTax, basePriceRaw]);

	const staffNominationFeeRaw = useMemo(() => {
		if (!providerSettings.staffEnabled) {
			return 0;
		}
		if (!staffId) {
			return null;
		}
		if (isNominationFeeDisabled) {
			return 0;
		}

		const rawFee =
			currentStaff?.meta?.vkbm_nomination_fee ??
			currentStaff?.meta?._vkbm_nomination_fee ??
			currentStaff?._vkbm_nomination_fee ??
			currentStaff?.nomination_fee;

		const normalized = normalizePriceValue(rawFee);

		if (normalized === null) {
			return 0;
		}

		return normalized;
	}, [currentStaff, staffId, isNominationFeeDisabled, providerSettings.staffEnabled]);

	const staffNominationFee = useMemo(() => {
		if (staffNominationFeeRaw === null) {
			return null;
		}

		return applyTax(staffNominationFeeRaw);
	}, [applyTax, staffNominationFeeRaw]);

	const totalPrice = useMemo(() => {
		if (basePrice === null) {
			return null;
		}

		const extra = staffNominationFee === null ? 0 : staffNominationFee;
		return basePrice + extra;
	}, [basePrice, staffNominationFee]);

	const pricingRows = useMemo(() => {
		if (!providerSettingsLoaded) {
			return [];
		}

		const taxSuffix = providerSettings.taxEnabled
			? (providerSettings.taxLabelText &&
				providerSettings.taxLabelText.trim() !== ''
				? providerSettings.taxLabelText
				: '')
			: '';
		const withTaxLabel = (value) => ({
			value,
			taxLabel: value !== '—' ? taxSuffix : '',
		});

		const currencySymbol = providerSettings.currencySymbol || null;
		const rows = [
			{
				key: 'base',
				label: __('Service basic fee', 'vk-booking-manager'),
				...withTaxLabel(
					basePrice !== null ? formatCurrency(basePrice, currencySymbol) : '—'
				),
			},
			{
				key: 'total',
				label: __('Total basic fee', 'vk-booking-manager'),
				...withTaxLabel(
					totalPrice !== null ? formatCurrency(totalPrice, currencySymbol) : '—'
				),
				highlight: true,
			},
		];

		if (providerSettings.staffEnabled) {
			rows.splice(1, 0, {
				key: 'nomination',
				label: __('Nomination fee', 'vk-booking-manager'),
				value: staffNominationFee === null
					? staffId
						? formatCurrency(applyTax(0), currencySymbol)
						: '—'
					: formatCurrency(staffNominationFee, currencySymbol),
				taxLabel: '', // Nomination fee should not have tax label
			});
		}

		return rows;
	}, [
		basePrice,
		staffNominationFee,
		totalPrice,
		providerSettings,
		providerSettingsLoaded,
	]);

	const menuCollectionPath = useMemo(
		() =>
			buildApiPath('/wp/v2/vkbm_service_menu', {
				per_page: 100,
				_fields: 'id,title,meta,menu_order,vkbm_menu_group',
				status: canViewPrivateMenus ? 'publish,private' : undefined,
			}),
		[canViewPrivateMenus]
	);

	useCollection(menuCollectionPath, setMenus);

	useEffect(() => {
		let isMounted = true;

		if (!menuId) {
			setMenuPreview({
				html: '',
				isLoading: false,
				error: '',
			});
			return () => {
				isMounted = false;
			};
		}

		setMenuPreview({
			html: '',
			isLoading: true,
			error: '',
		});

		apiFetch({ path: `/vkbm/v1/menu-preview/${menuId}` })
			.then((response) => {
				if (!isMounted) {
					return;
				}

				setMenuPreview({
					html: response?.html || '',
					isLoading: false,
					error: '',
				});
			})
			.catch((error) => {
				if (!isMounted) {
					return;
				}

				setMenuPreview({
					html: '',
					isLoading: false,
					error:
						error?.message ||
						__('Failed to retrieve menu information.', 'vk-booking-manager'),
				});
			});

		return () => {
			isMounted = false;
		};
	}, [menuId]);
	const staffCollectionPath = useMemo(() => {
		if (!providerSettingsLoaded || !providerSettings.staffEnabled) {
			return '';
		}
		return '/wp/v2/vkbm_resource?per_page=100&_fields=id,title,meta,nomination_fee';
	}, [providerSettingsLoaded, providerSettings.staffEnabled]);
	useCollection(staffCollectionPath, setStaffOptions);

	const dayMetaMap = useMemo(() => {
		if (!calendarData?.days) {
			return {};
		}

		return calendarData.days.reduce((acc, day) => {
			acc[day.date] = day;
			return acc;
		}, {});
	}, [calendarData]);

	const fetchCalendar = useCallback(() => {
		if (!menuId || !monthCursor.year || !monthCursor.month) {
			setCalendarData(null);
			return;
		}

		setCalendarLoading(true);
		setCalendarError(null);

		const path = buildApiPath('/vkbm/v1/calendar-meta', {
			menu_id: menuId,
			resource_id: staffId || undefined,
			year: monthCursor.year,
			month: monthCursor.month,
		});

		apiFetch({ path })
			.then((response) => {
				setCalendarData(response);
			})
			.catch((error) => {
				setCalendarError(
					error?.message ||
						__('Failed to load calendar.', 'vk-booking-manager')
				);
			})
			.finally(() => {
				setCalendarLoading(false);
			});
	}, [menuId, staffId, monthCursor]);

	useEffect(() => {
		fetchCalendar();
	}, [fetchCalendar]);

	useEffect(() => {
		if (!menuId || !selectedDate) {
			setSlotData([]);
			return;
		}

		setSlotLoading(true);
		setSlotError(null);

	const path = buildApiPath('/vkbm/v1/availabilities', {
		menu_id: menuId,
		resource_id: staffId || undefined,
		date: selectedDate,
	});

	apiFetch({ path })
		.then((response) => {
			const slots = Array.isArray(response?.slots) ? response.slots : [];
			const preferredStaffLabel = staffId
				? currentStaff?.title?.rendered ??
				  currentStaff?.title ??
				  currentStaff?.name ??
				  ''
				: '';

			const decoratedSlots = slots.map((slot) => {
				const fallbackLabel =
					slot.staff_label ||
					slot.staff?.name ||
					(preferredStaffLabel || __('No preference', 'vk-booking-manager'));

				return {
					...slot,
					staff_label: preferredStaffLabel || fallbackLabel,
					staff:
						slot.staff ||
						(preferredStaffLabel
							? { id: staffId, name: preferredStaffLabel }
							: null),
				};
			});

			setSlotData(decoratedSlots);
		})
		.catch((error) => {
			setSlotError(
				error?.message ||
					__('Failed to read free space.', 'vk-booking-manager')
			);
		})
		.finally(() => {
			setSlotLoading(false);
		});
	}, [menuId, staffId, selectedDate, currentStaff]);

	useEffect(() => {
		setSubmitError('');
	}, [selectedSlot, menuId, staffId]);

	useEffect(() => {
		if (selectedSlot && actionSectionRef.current) {
			actionSectionRef.current.scrollIntoView({
				behavior: 'smooth',
				block: 'start',
			});
		}
	}, [selectedSlot]);

	const handleMonthChange = (delta) => {
		setMonthCursor((current) => {
			const newMonth = current.month + delta;
			const date = new Date(current.year, newMonth - 1, 1);
			return {
				year: date.getFullYear(),
				month: date.getMonth() + 1,
			};
		});
	};

	const handleSelectDate = (date) => {
		if (!menuId) {
			return;
		}
		setSelectedDate(date);
		setSelectedSlot(null);
	};

	const handleMenuChange = (nextMenuId) => {
		const nextMenu = menus.find((menu) => menu.id === nextMenuId);
		const nextMeta = nextMenu?.meta;
		
		// 無料版では staffEnabled が false なので、スタッフIDの処理をスキップ
		if (providerSettings.staffEnabled) {
			const rawNextStaffIds = Array.isArray(nextMeta?._vkbm_staff_ids) && nextMeta._vkbm_staff_ids.length
				? nextMeta._vkbm_staff_ids
				: Array.isArray(nextMeta?.vkbm_staff_ids) && nextMeta.vkbm_staff_ids.length
					? nextMeta.vkbm_staff_ids
					: [];
			const nextAssignableStaffIds = Array.from(
				new Set(
					rawNextStaffIds
						.map((value) => {
							if (typeof value === 'number') {
								return value;
							}
							if (typeof value === 'string') {
								return Number(value) || 0;
							}
							return 0;
						})
						.filter((id) => id > 0)
				)
			);

			if (nextAssignableStaffIds.length === 1) {
				setStaffId(nextAssignableStaffIds[0]);
			} else if (staffId && !nextAssignableStaffIds.includes(staffId)) {
				setStaffId(0);
			}
		} else {
			// 無料版では常にデフォルトスタッフIDを設定
			// providerSettings が読み込まれている場合のみ設定
			if (providerSettingsLoaded && providerSettings.defaultStaffId > 0) {
				setStaffId(providerSettings.defaultStaffId);
			} else if (providerSettingsLoaded) {
				setStaffId(0);
			}
		}

		setMenuId(nextMenuId);
		setSelectedDate('');
		setSelectedSlot(null);
		setMonthCursor({
			year: new Date().getFullYear(),
			month: new Date().getMonth() + 1,
		});
	};

	const handleStaffChange = (nextStaffId) => {
		setStaffId(nextStaffId);
		setSelectedSlot(null);
	};

	const handleMenuLoopClick = useCallback(
		(event) => {
			const target = event.target;
			if (!target || typeof target.closest !== 'function') {
				return;
			}

			const reserveButton = target.closest('a.vkbm-menu-loop__button--reserve');
			if (!reserveButton) {
				return;
			}

			event.preventDefault();

			const item = target.closest('.vkbm-menu-loop__item');
			if (!item) {
				return;
			}

			const rawId = item.getAttribute('data-menu-id') || '';
			const nextId = Number(rawId) || 0;

			if (!nextId) {
				return;
			}

			handleMenuChange(nextId);
			const blockRoot = layoutRef.current?.closest?.('.vkbm-reservation-block');
			const scrollTarget = blockRoot || layoutRef.current;
			if (scrollTarget) {
				scrollTarget.scrollIntoView({
					behavior: 'smooth',
					block: 'start',
				});
			}
		},
		[handleMenuChange]
	);

	const canProceed = Boolean(menuId && selectedSlot);

	const handleProceed = useCallback(() => {
		if (isEditor) {
			setSubmitError(
				__('The editor does not transition to the confirmation page.', 'vk-booking-manager')
			);
			return;
		}

		if (!selectedSlot || !menuId) {
			setSubmitError(
				__('Please select your menu and reservation slot before proceeding.', 'vk-booking-manager')
			);
			return;
		}

		setIsSubmitting(true);
		setSubmitError('');

		const payload = {
			token: getDraftTokenFromCookie(),
			menu_id: menuId,
			resource_id: staffId || 0,
			menu_label:
				currentMenu?.title?.rendered ??
				currentMenu?.title ??
				currentMenu?.name ??
				'',
			staff_label:
				staffId
					? currentStaff?.title?.rendered ??
					  currentStaff?.title ??
					  currentStaff?.name ??
					  ''
					: __('No preference', 'vk-booking-manager'),
			is_staff_preferred: Boolean(staffId),
			date: selectedDate,
			slot: {
				slot_id: selectedSlot.slot_id,
				start_at: selectedSlot.start_at,
				end_at: selectedSlot.end_at,
				service_end_at:
					selectedSlot.service_end_at || selectedSlot.end_at,
				duration_minutes: selectedSlot.duration_minutes || 0,
				staff_label:
					selectedSlot.staff_label ||
					(staffId
						? currentStaff?.title?.rendered ??
						  currentStaff?.title ??
						  currentStaff?.name ??
						  ''
						: __('No preference', 'vk-booking-manager')),
				staff: selectedSlot.staff
					? {
							id: selectedSlot.staff.id,
							name:
								selectedSlot.staff.name ||
								selectedSlot.staff.title ||
								'',
					  }
					: null,
				assignable_staff_ids: Array.isArray(selectedSlot.assignable_staff_ids)
					? selectedSlot.assignable_staff_ids.map((id) => Number(id) || 0).filter(Boolean)
					: [],
				auto_assign: Boolean(
					selectedSlot.auto_assign ||
						(!staffId &&
							!selectedSlot.staff &&
							(!selectedSlot.assignable_staff_ids ||
								selectedSlot.assignable_staff_ids.length > 0))
				),
			},
			meta: {
				timezone: calendarData?.meta?.timezone || '',
			},
		};

		apiFetch({
			path: '/vkbm/v1/drafts',
			method: 'POST',
			data: payload,
		})
			.then((response) => {
				const token = sanitizeDraftToken(response?.token);
				const expiresIn = response?.expires_in || 1800;
				if (!token) {
					throw new Error(
						__('Temporary reservation data save failed.', 'vk-booking-manager')
					);
				}

				storeDraftToken(token, expiresIn);

				if (typeof window !== 'undefined') {
					const url = new URL(window.location.href);
					url.searchParams.delete('vkbm_auth');
					url.searchParams.set('draft', token);
					window.location.href = url.toString();
				}
			})
			.catch((error) => {
				setSubmitError(
					error?.message ||
						__('Failed to save reservation details. Please try again later.', 'vk-booking-manager')
				);
			})
			.finally(() => {
				setIsSubmitting(false);
			});
	}, [
		calendarData?.meta?.timezone,
		currentMenu,
		currentStaff,
		isEditor,
		menuId,
		selectedDate,
		selectedSlot,
		staffId,
	]);

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
		: __('Logo image', 'vk-booking-manager');

	if (confirmDraftToken) {
		return (
			<div className="vkbm-reservation vkbm-reservation--confirm">
				<BookingConfirmApp
					redirectUrl=""
					termsLabel={__('I agree to the terms of use', 'vk-booking-manager')}
					policyText={__('Please check our cancellation policy before booking.', 'vk-booking-manager')}
					successMessage={__('Your reservation has been completed.', 'vk-booking-manager')}
				/>
			</div>
		);
	}

	return (
		<div className="vkbm-reservation-layout" ref={layoutRef}>
			{shouldShowBrand ? (
				<div className="vkbm-reservation-layout__header">
					{brandLinkHref ? (
						<a
							className="vkbm-reservation-layout__brand vkbm-reservation-layout__brand-link"
							href={brandLinkHref}
						>
							{shouldShowLogo && (
								<img
									className="vkbm-reservation-layout__brand-logo"
									src={providerSettings.providerLogoUrl}
									alt={brandLogoAlt}
									loading="lazy"
								/>
							)}
							{shouldShowName && (
								<span className="vkbm-reservation-layout__brand-name">
									{providerSettings.providerName}
								</span>
							)}
						</a>
					) : (
						<div className="vkbm-reservation-layout__brand">
							{shouldShowLogo && (
								<img
									className="vkbm-reservation-layout__brand-logo"
									src={providerSettings.providerLogoUrl}
									alt={brandLogoAlt}
									loading="lazy"
								/>
							)}
							{shouldShowName && (
								<span className="vkbm-reservation-layout__brand-name">
									{providerSettings.providerName}
								</span>
							)}
						</div>
					)}
					<div
						className="vkbm-user-actions vkbm-reservation-layout__nav"
						role="navigation"
						aria-label={__('Reservation block navigation', 'vk-booking-manager')}
					>
						<span
							className="vkbm-reservation-layout__nav-bracket"
							aria-hidden="true"
						>
							[
						</span>
							{shouldShowUserSection ? (
								<button
									type="button"
									className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
									onClick={() => handleAuthLink(authMode || '')}
								>
									{__('Return', 'vk-booking-manager')}
								</button>
						) : isLoggedIn && canManageReservations ? (
							<>
								<a
									className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
									href={shiftDashboardUrl || ''}
								>
									{__('Shift/reservation table', 'vk-booking-manager')}
								</a>
								<span
									className="vkbm-reservation-layout__nav-divider"
									aria-hidden="true"
								>
									|
								</span>
								<a
									className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
									href={
										logoutUrl ||
										buildLogoutFallbackUrl(providerSettings.reservationPageUrl)
									}
								>
									{__('Log out', 'vk-booking-manager')}
								</a>
							</>
						) : isLoggedIn ? (
							<>
								<button
									type="button"
									className={getNavLinkClass('bookings')}
									onClick={() => handleAuthLink('bookings')}
									aria-pressed={authMode === 'bookings'}
								>
									{__('Confirm reservation', 'vk-booking-manager')}
								</button>
								<span
									className="vkbm-reservation-layout__nav-divider"
									aria-hidden="true"
								>
									|
								</span>
								<button
									type="button"
									className={getNavLinkClass('profile')}
									onClick={() => handleAuthLink('profile')}
									aria-pressed={isProfileMode}
								>
									{__('Edit user information', 'vk-booking-manager')}
								</button>
								<span
									className="vkbm-reservation-layout__nav-divider"
									aria-hidden="true"
								>
									|
								</span>
									<a
										className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
										href={
											logoutUrl ||
											buildLogoutFallbackUrl(providerSettings.reservationPageUrl)
										}
								>
									{__('Log out', 'vk-booking-manager')}
								</a>
							</>
						) : (
							<>
								<button
									type="button"
									className={getNavLinkClass('login')}
									onClick={() => handleAuthLink('login')}
									aria-pressed={authMode === 'login'}
								>
									{__('Log in', 'vk-booking-manager')}
								</button>
								<span
									className="vkbm-reservation-layout__nav-divider"
									aria-hidden="true"
								>
									|
								</span>
								<button
									type="button"
									className={getNavLinkClass('register')}
									onClick={() => handleAuthLink('register')}
									aria-pressed={authMode === 'register'}
								>
									{__('Sign up', 'vk-booking-manager')}
								</button>
							</>
						)}
						<span
							className="vkbm-reservation-layout__nav-bracket"
							aria-hidden="true"
						>
							]
						</span>
					</div>
				</div>
			) : (
				<div
					className="vkbm-user-actions vkbm-reservation-layout__nav"
					role="navigation"
					aria-label={__('Reservation block navigation', 'vk-booking-manager')}
				>
					<span
						className="vkbm-reservation-layout__nav-bracket"
						aria-hidden="true"
					>
						[
					</span>
						{shouldShowUserSection ? (
							<button
								type="button"
								className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
								onClick={() => handleAuthLink(authMode || '')}
							>
								{__('return', 'vk-booking-manager')}
							</button>
					) : isLoggedIn && canManageReservations ? (
						<>
							<a
								className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
								href={shiftDashboardUrl || ''}
							>
								{__('Shift/reservation table', 'vk-booking-manager')}
							</a>
							<span
								className="vkbm-reservation-layout__nav-divider"
								aria-hidden="true"
							>
								|
							</span>
							<a
								className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
								href={
									logoutUrl ||
									buildLogoutFallbackUrl(providerSettings.reservationPageUrl)
								}
							>
								{__('Log out', 'vk-booking-manager')}
							</a>
						</>
					) : isLoggedIn ? (
						<>
							<button
								type="button"
								className={getNavLinkClass('bookings')}
								onClick={() => handleAuthLink('bookings')}
								aria-pressed={authMode === 'bookings'}
							>
								{__('Confirm reservation', 'vk-booking-manager')}
							</button>
							<span
								className="vkbm-reservation-layout__nav-divider"
								aria-hidden="true"
							>
								|
							</span>
							<button
								type="button"
								className={getNavLinkClass('profile')}
								onClick={() => handleAuthLink('profile')}
								aria-pressed={isProfileMode}
							>
								{__('Edit user information', 'vk-booking-manager')}
							</button>
							<span
								className="vkbm-reservation-layout__nav-divider"
								aria-hidden="true"
							>
								|
							</span>
								<a
									className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
									href={
										logoutUrl ||
										buildLogoutFallbackUrl(providerSettings.reservationPageUrl)
									}
							>
								{__('Log out', 'vk-booking-manager')}
							</a>
						</>
					) : (
						<>
							<button
								type="button"
								className={getNavLinkClass('login')}
								onClick={() => handleAuthLink('login')}
								aria-pressed={authMode === 'login'}
							>
								{__('Log in', 'vk-booking-manager')}
							</button>
							<span
								className="vkbm-reservation-layout__nav-divider"
								aria-hidden="true"
							>
								|
							</span>
							<button
								type="button"
								className={getNavLinkClass('register')}
								onClick={() => handleAuthLink('register')}
								aria-pressed={authMode === 'register'}
							>
								{__('Sign up', 'vk-booking-manager')}
							</button>
						</>
					)}
					<span
						className="vkbm-reservation-layout__nav-bracket"
						aria-hidden="true"
					>
						]
					</span>
				</div>
			)}

			{shouldShowUserSection && (
				<section className="vkbm-user-section" aria-live="polite">
					<div className="vkbm-user-section__panel vkbm-reservation__auth-panel">
						{authMode === 'bookings' && bookingsLoading && (
							<p className="vkbm-alert vkbm-alert__info vkbm-reservation__auth-loading" role="status">
								{__('Loading...', 'vk-booking-manager')}
							</p>
						)}
						{authMode === 'bookings' && bookingsError && (
							<p className="vkbm-alert vkbm-alert__danger vkbm-reservation__auth-error" role="alert">
								{bookingsError}
							</p>
						)}
						{authMode === 'bookings' &&
							!bookingsLoading &&
							!bookingsError &&
							bookings.length === 0 && (
								<p className="vkbm-alert vkbm-alert__info vkbm-reservation__auth-loading" role="status">
									{__('There are no reservations to display.', 'vk-booking-manager')}
								</p>
							)}
						{authMode === 'bookings' && bookings.length > 0 && (
							<div className="vkbm-confirm">
								{bookings.map((booking) => {
									const datetimeParts = formatBookingDateTimeParts(
										booking?.start_at,
										booking?.end_at
									);
									const statusKey = String(booking?.status || '').trim();
									const isPending = statusKey.toLowerCase() === 'pending';
									const isCancelled = statusKey.toLowerCase() === 'cancelled';

									return (
										<div
											className={[
												'vkbm-confirm__summary',
												isCancelled && 'vkbm-confirm__summary--cancelled',
											]
												.filter(Boolean)
												.join(' ')}
											key={booking?.id || datetimeLabel}
										>
											{(datetimeParts.date || datetimeParts.time) && (
												<div className="vkbm-confirm__summary-title">
													<div className="vkbm-confirm__datetime">
														{isPending && (
															<span className="vkbm-confirm__status vkbm-confirm__status--pending">
																{__('Pending', 'vk-booking-manager')}
															</span>
														)}
														{isCancelled && (
															<span className="vkbm-confirm__status vkbm-confirm__status--cancelled">
																{__('Cancelled', 'vk-booking-manager')}
															</span>
														)}
														{datetimeParts.date && (
															<span className="vkbm-confirm__date">
																{datetimeParts.date}
															</span>
														)}
														{datetimeParts.time && (
															<span className="vkbm-confirm__time">
																{datetimeParts.date ? ' ' : ''}
																{datetimeParts.time}
															</span>
														)}
													</div>
													{booking?.can_cancel && (
														<button
															type="button"
															className="vkbm-button vkbm-button__sm vkbm-button__secondary vkbm-confirm__cancel-button"
															onClick={() => handleCancelBooking(booking?.id)}
															disabled={cancellingBookingId === Number(booking?.id)}
														>
															{cancellingBookingId === Number(booking?.id)
																? __('Processing...', 'vk-booking-manager')
																: __('Cancelled', 'vk-booking-manager')}
														</button>
													)}
												</div>
											)}
											<BookingSummaryItems
												booking={booking}
												resourceLabel={providerSettings.resourceLabelSingular}
												currencySymbol={providerSettings.currencySymbol || null}
											/>
										</div>
									);
								})}
							</div>
						)}

						{authMode !== 'bookings' && authLoading && (
							<p className="vkbm-alert vkbm-alert__info vkbm-reservation__auth-loading" role="status">
								{__('Loading form...', 'vk-booking-manager')}
							</p>
						)}
						{authMode !== 'bookings' && authError && (
							<p className="vkbm-alert vkbm-alert__danger vkbm-reservation__auth-error" role="alert">
								{authError}
							</p>
						)}
						{authMode !== 'bookings' && authFormHtml && (
							<div
								className="vkbm-reservation__auth-form"
								dangerouslySetInnerHTML={{ __html: authFormHtml }}
							/>
						)}
					</div>
				</section>
			)}

				{shouldShowReservation && (
					<div
						className={[
							'vkbm-reservation',
							isEditor && 'vkbm-reservation--is-editor',
						]
							.filter(Boolean)
							.join(' ')}
					>
						{!providerSettingsLoaded ? (
							<p className="vkbm-alert vkbm-alert__info" role="status">
								{__('Loading...', 'vk-booking-manager')}
							</p>
						) : providerSettings.showMenuList && !menuId ? (
							<section className="vkbm-reservation__menu-list" aria-live="polite">
								{menuList.isLoading && (
									<p className="vkbm-alert vkbm-alert__info" role="status">
										{__('Loading service menu...', 'vk-booking-manager')}
									</p>
							)}
							{menuList.error && (
								<p className="vkbm-alert vkbm-alert__danger" role="alert">
									{menuList.error}
								</p>
							)}
							{providerSettingsLoaded && !menuList.isLoading && !menuList.error && !menuList.html && (
								<p className="vkbm-alert vkbm-alert__warning" role="status">
									{__('There are no service menus to display.', 'vk-booking-manager')}
								</p>
							)}
							{menuList.html && (
								<div
									className="vkbm-reservation__menu-loop"
									onClick={handleMenuLoopClick}
									dangerouslySetInnerHTML={{ __html: menuList.html }}
								/>
							)}
						</section>
					) : (
						<SelectedPlanSummary
							menuId={menuId}
							staffId={staffId}
							menus={menus}
							staffOptions={availableStaffOptions}
							onMenuChange={handleMenuChange}
							onStaffChange={handleStaffChange}
							allowStaffSelection={staffSelectionEnabled}
							showStaffField={providerSettings.staffEnabled}
							lockStaffSelection={shouldLockStaffSelection}
							pricingRows={pricingRows}
							menuPreviewLoading={menuPreview.isLoading}
							menuPreviewError={menuPreview.error}
							menuPreviewHtml={menuPreview.html}
							resourceLabelSingular={providerSettings.resourceLabelSingular}
						/>
					)}

					{hasMenuSelection && (
						<div className="vkbm-reservation__body">
							<CalendarGrid
								year={monthCursor.year}
								month={monthCursor.month}
								dayMetaMap={dayMetaMap}
								selectedDate={selectedDate}
								onSelectDate={handleSelectDate}
								onMonthChange={handleMonthChange}
								isLoading={calendarLoading}
								locale={userBootstrap?.locale}
							/>

							<div className="vkbm-reservation__slots">
								<DailySlotList
									slots={slotData}
									selectedDate={selectedDate}
									onSelectSlot={(slot) => setSelectedSlot(slot)}
									selectedSlotId={selectedSlot?.slot_id}
									isLoading={slotLoading}
									error={calendarError || slotError}
									showStaffLabel={providerSettings.staffEnabled}
									selectedStaffLabel={
										staffId
											? currentStaff?.title?.rendered ??
											  currentStaff?.title ??
											  currentStaff?.name ??
											  ''
											: ''
									}
								/>
							</div>
						</div>
					)}

					{hasMenuSelection && (
						<div
							className="vkbm-plan-summary__status vkbm-plan-summary__status--detached"
							ref={actionSectionRef}
						>
							<div>
								<span className="vkbm-plan-summary__status-label">
									{__('Selected frame', 'vk-booking-manager')}
								</span>
								{selectedSlot ? (
									<strong>
										{selectedSlot.start_at?.slice(0, 10)}{' '}
										{formatWeekdayLabel(selectedSlot.start_at)}{' '}
										{selectedSlot.start_at?.slice(11, 16)} -{' '}
										{(selectedSlot.service_end_at || selectedSlot.end_at)?.slice(
											11,
											16
										)}
									</strong>
								) : (
									<span>{__('Not selected', 'vk-booking-manager')}</span>
								)}
							</div>
							<div className="vkbm-plan-summary__actions">
									<button
										type="button"
										className="vkbm-plan-summary__action vkbm-button vkbm-button__md vkbm-button__primary"
										onClick={handleProceed}
										disabled={!canProceed || isSubmitting}
									>
									{isSubmitting
										? __('Processing...', 'vk-booking-manager')
										: __('Proceed to Reservation', 'vk-booking-manager')}
								</button>
								{submitError && (
									<p className="vkbm-plan-summary__error">{submitError}</p>
								)}
							</div>
						</div>
					)}
				</div>
			)}
		</div>
	);
};
const formatWeekdayLabel = (isoString) => {
	if (!isoString) {
		return '';
	}
	try {
		const date = new Date(isoString);
		if (Number.isNaN(date.getTime())) {
			return '';
		}
		const weekday = new Intl.DateTimeFormat('ja-JP', {
			weekday: 'short',
		}).format(date);
		return `(${weekday})`;
	} catch (error) {
		return '';
	}
};
