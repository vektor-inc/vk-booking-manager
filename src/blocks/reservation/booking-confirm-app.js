import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import { formatCurrencyJPY, normalizePriceValue } from '../shared/pricing';
import { sanitizeDraftToken } from '../shared/draft-token';
import { BookingSummaryItems } from './components/booking-summary-items';

const getQueryParam = (key) => {
	if (typeof window === 'undefined') {
		return '';
	}
	const params = new URLSearchParams(window.location.search);
	const value = params.get(key) || '';
	return key === 'draft' ? sanitizeDraftToken(value) : value;
};

const formatDateTime = (iso, timezone) => {
	return formatDate(iso, timezone, {
		includeTime: true,
		includeDate: true,
	});
};

const formatBookingDateTimeParts = (startAt, endAt, timezone) => {
	if (!startAt) {
		return { date: '', time: '' };
	}

	const dateLabel = formatDate(startAt, timezone, {
		includeTime: false,
		includeDate: true,
		includeWeekday: true,
	});
	const startTimeLabel = formatDate(startAt, timezone, {
		includeTime: true,
		includeDate: false,
	});
	const endTimeLabel = endAt
		? formatDate(endAt, timezone, {
				includeTime: true,
				includeDate: false,
		  })
		: '';
	const timeLabel = endTimeLabel
		? `${startTimeLabel} - ${endTimeLabel}`.trim()
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
	if (!iso) {
		return '';
	}
	try {
		const date = new Date(iso);
		if (Number.isNaN(date.getTime())) {
			return iso;
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
		const wpTimezone = timezone || settings?.timezone?.string;

		const datePart = includeDate ? dateI18n(dateFormat, date, wpTimezone) : '';
		const timePart = includeTime ? dateI18n(timeFormat, date, wpTimezone) : '';
		const weekdayPart = includeWeekday ? dateI18n('D', date, wpTimezone) : '';

		if (includeDate && includeTime) {
			if (includeWeekday && weekdayPart) {
				return `${datePart}(${weekdayPart}) ${timePart}`.trim();
			}
			return `${datePart} ${timePart}`.trim();
		}

		if (includeDate) {
			if (includeWeekday && weekdayPart) {
				return `${datePart}(${weekdayPart})`.trim();
			}
			return datePart;
		}

		return timePart;
	} catch (error) {
		return iso;
	}
};

const useLoginState = () => {
	if (typeof document === 'undefined') {
		return false;
	}
	return document.body.classList.contains('logged-in');
};

const parseQueryParams = () => {
	if (typeof window === 'undefined') {
		return {};
	}

	try {
		const params = new URLSearchParams(window.location.search);
		return {
			auth: params.get('vkbm_auth') || '',
			draft: sanitizeDraftToken(params.get('draft') || ''),
		};
	} catch (error) {
		return {};
	}
};

const buildApiPath = (base, query = {}) => {
	const params = new URLSearchParams();
	Object.entries(query).forEach(([key, value]) => {
		if (value !== undefined && value !== null && value !== '') {
			params.append(key, value);
		}
	});
	return `${base}?${params.toString()}`;
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

const parseJsonWithFallback = (raw) => {
	const fallbackError = new Error(
		__('The response is not a valid JSON response.', 'vk-booking-manager')
	);

	if (typeof raw !== 'string') {
		throw fallbackError;
	}

	const trimmed = raw.trim();
	if (!trimmed) {
		throw fallbackError;
	}

	try {
		return JSON.parse(trimmed);
	} catch (error) {
		let start = trimmed.indexOf('{');
		let end = trimmed.lastIndexOf('}');

		if (start === -1 || end === -1 || end < start) {
			start = trimmed.indexOf('[');
			end = trimmed.lastIndexOf(']');
		}

		if (start === -1 || end === -1 || end < start) {
			throw fallbackError;
		}

		try {
			return JSON.parse(trimmed.slice(start, end + 1));
		} catch (innerError) {
			throw fallbackError;
		}
	}
};

const SummaryRow = ({ label, value, valueClassName }) => (
	<dl className="vkbm-confirm__summary-item">
		<dt className="vkbm-confirm__summary-item-title">{label}</dt>
		<dd
			className={[
				'vkbm-confirm__summary-item-value',
				valueClassName,
			]
				.filter(Boolean)
				.join(' ')}
		>
			{value || '—'}
		</dd>
	</dl>
);

export const BookingConfirmApp = ({
	redirectUrl,
	termsLabel,
	policyText,
	successMessage,
	reservationPageUrl = '',
}) => {
	const userBootstrap = useMemo(() => {
		if (typeof window === 'undefined') {
			return null;
		}
		return window.vkbmCurrentUserBootstrap || null;
	}, []);
	const draftToken = useMemo(() => getQueryParam('draft'), []);
	const [draft, setDraft] = useState(null);
	const [menu, setMenu] = useState(null);
	const [staff, setStaff] = useState(null);
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState('');
	const [memo, setMemo] = useState('');
	const [agreeCancellationPolicy, setAgreeCancellationPolicy] = useState(false);
	const [agreeTermsOfService, setAgreeTermsOfService] = useState(false);
	const [submitting, setSubmitting] = useState(false);
	const [submitError, setSubmitError] = useState('');
	const [success, setSuccess] = useState(false);
	const [createdStatus, setCreatedStatus] = useState('');
	const [customerName, setCustomerName] = useState('');
	const [customerPhone, setCustomerPhone] = useState('');
	const [providerNote, setProviderNote] = useState('');
	const [canManageReservations, setCanManageReservations] = useState(
		Boolean(userBootstrap?.canManageReservations)
	);
	const [logoutUrl, setLogoutUrl] = useState(userBootstrap?.logoutUrl || '');
	const [shiftDashboardUrl, setShiftDashboardUrl] = useState(
		userBootstrap?.shiftDashboardUrl || ''
	);
	const [providerCancellationPolicy, setProviderCancellationPolicy] = useState('');
	const [providerTermsOfService, setProviderTermsOfService] = useState('');
	const [providerPaymentMethod, setProviderPaymentMethod] = useState('');
	const [providerName, setProviderName] = useState('');
	const [providerLogoUrl, setProviderLogoUrl] = useState('');
	const [showProviderLogo, setShowProviderLogo] = useState(false);
	const [showProviderName, setShowProviderName] = useState(false);
	const [staffEnabled, setStaffEnabled] = useState(true);
	const [resourceLabelSingular, setResourceLabelSingular] = useState(__('Staff', 'vk-booking-manager'));
	const [resolvedReservationPageUrl, setResolvedReservationPageUrl] = useState(reservationPageUrl || '');

	const isLoggedIn = useLoginState();

	const queryDefaults = useMemo(() => parseQueryParams(), []);
	const [authMode, setAuthMode] = useState(queryDefaults.auth || '');
	const [authFormHtml, setAuthFormHtml] = useState('');
	const [authLoading, setAuthLoading] = useState(false);
	const [authError, setAuthError] = useState('');
	const [bookingsLoading, setBookingsLoading] = useState(false);
	const [bookingsError, setBookingsError] = useState('');
	const [bookings, setBookings] = useState([]);
	const [cancellingBookingId, setCancellingBookingId] = useState(0);
	const timezone = draft?.meta?.timezone || '';

	const pricingSummary = useMemo(() => {
		const taxIncluded = Boolean(draft?.menu_price_tax_included);
		const ensureTaxLabel = (label) =>
			taxIncluded ? `${label}${__('(tax included)', 'vk-booking-manager')}` : label;

		const basePrice =
			normalizePriceValue(draft?.menu_price) ??
			normalizePriceValue(draft?.menu_price_base) ??
			normalizePriceValue(
				menu?.meta?.vkbm_base_price ??
					menu?.meta?._vkbm_base_price ??
					menu?._vkbm_base_price ??
					menu?.price
			);

		const formattedBase =
			typeof draft?.menu_price_formatted === 'string' &&
			draft.menu_price_formatted.trim() !== ''
				? draft.menu_price_formatted
				: basePrice !== null
				? ensureTaxLabel(formatCurrencyJPY(basePrice))
				: '';

		const nominationFee = staffEnabled
			? normalizePriceValue(draft?.nomination_fee) ?? 0
			: 0;
		const formattedNomination =
			typeof draft?.nomination_fee_formatted === 'string' &&
			draft.nomination_fee_formatted.trim() !== ''
				? draft.nomination_fee_formatted
				: ensureTaxLabel(formatCurrencyJPY(nominationFee));

		const totalPrice =
			normalizePriceValue(draft?.total_price) ??
			(basePrice !== null ? basePrice + nominationFee : null);
		const formattedTotal =
			typeof draft?.total_price_formatted === 'string' &&
			draft.total_price_formatted.trim() !== ''
				? draft.total_price_formatted
				: totalPrice !== null
				? ensureTaxLabel(formatCurrencyJPY(totalPrice))
				: '';

		return {
			baseLabel: formattedBase,
			nominationLabel: formattedNomination,
			totalLabel: formattedTotal,
		};
	}, [draft, menu, staffEnabled]);

	useEffect(() => {
		if (!draftToken) {
			setLoadError(
				__(
					'The URL does not include a temporary reservation data parameter. Please try again from the reservation page.',
					'vk-booking-manager'
				)
			);
			setLoading(false);
			return;
		}

		setLoading(true);
		setLoadError('');
		apiFetch({ path: `/vkbm/v1/drafts/${draftToken}` })
			.then((data) => {
				setDraft(data);
				setMemo(data?.memo || '');
				const agreedAll = Boolean(data?.agree_terms);
				setAgreeCancellationPolicy(
					data?.agree_cancellation_policy !== undefined
						? Boolean(data?.agree_cancellation_policy)
						: agreedAll
				);
				setAgreeTermsOfService(
					data?.agree_terms_of_service !== undefined
						? Boolean(data?.agree_terms_of_service)
						: agreedAll
				);
			})
			.catch((error) => {
				setLoadError(
					error?.message ||
									__(
										"Couldn't load temporary reservation data. Please try again from the reservation page.",
										'vk-booking-manager'
									)
				);
			})
			.finally(() => setLoading(false));
	}, [draftToken]);

	useEffect(() => {
		let isMounted = true;

		apiFetch({ path: '/vkbm/v1/provider-settings' })
			.then((response) => {
				if (!isMounted) {
					return;
				}

				if (
					typeof response?.resource_label_singular === 'string' &&
					response.resource_label_singular.trim() !== ''
				) {
					setResourceLabelSingular(response.resource_label_singular);
				} else {
					setResourceLabelSingular(__('Staff', 'vk-booking-manager'));
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
				setProviderPaymentMethod(
					typeof response?.payment_method === 'string'
						? response.payment_method
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
				setShowProviderLogo(Boolean(response?.reservation_show_provider_logo));
				setShowProviderName(Boolean(response?.reservation_show_provider_name));
				setStaffEnabled(response?.staff_enabled !== false);
				if (!reservationPageUrl && typeof response?.reservation_page_url === 'string') {
					setResolvedReservationPageUrl(response.reservation_page_url);
				}
			})
			.catch(() => {
				// Ignore provider settings fetch errors (fallback to defaults).
			});

		return () => {
			isMounted = false;
		};
	}, [reservationPageUrl]);

	useEffect(() => {
		if (!draft?.menu_id) {
			setMenu(null);
			return;
		}
		apiFetch({ path: `/wp/v2/vkbm_service_menu/${draft.menu_id}?_fields=id,title,meta` })
			.then((response) => setMenu(response))
			.catch(() => setMenu(null));
	}, [draft?.menu_id]);

	useEffect(() => {
		if (!draft?.resource_id || !staffEnabled) {
			setStaff(null);
			return;
		}
		apiFetch({ path: `/wp/v2/vkbm_resource/${draft.resource_id}?_fields=id,title,meta` })
			.then((response) => setStaff(response))
			.catch(() => setStaff(null));
	}, [draft?.resource_id, staffEnabled]);

useEffect(() => {
	let isMounted = true;

	if (!isLoggedIn) {
		setCanManageReservations(false);
		setLogoutUrl('');
		setShiftDashboardUrl('');
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
		setCanManageReservations(Boolean(userBootstrap.canManageReservations));
		setLogoutUrl(userBootstrap.logoutUrl || '');
		setShiftDashboardUrl(userBootstrap.shiftDashboardUrl || '');
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

			setCanManageReservations(Boolean(response?.can_manage_reservations));
			setLogoutUrl(response?.logout_url || '');
			setShiftDashboardUrl(response?.shift_dashboard_url || '');
		})
		.catch(() => {
			if (isMounted) {
				setCanManageReservations(false);
				setLogoutUrl('');
				setShiftDashboardUrl('');
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
		if (!canManageReservations) {
			setCustomerName('');
			setCustomerPhone('');
			setProviderNote('');
		}
	}, [canManageReservations]);

	const getCurrentUrl = () => {
		if (typeof window === 'undefined') {
			return '';
		}
		return window.location.href;
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

	const buildReservationModeUrl = useCallback(
		(mode) => {
			if (typeof window === 'undefined') {
				return '';
			}

			const baseUrl = resolvedReservationPageUrl || reservationPageUrl || '';
			const url = baseUrl
				? new URL(baseUrl, window.location.origin)
				: new URL(window.location.href);

			if (mode) {
				url.searchParams.set('vkbm_auth', mode);
			} else {
				url.searchParams.delete('vkbm_auth');
			}

			url.searchParams.delete('draft');

			return url.toString();
		},
		[resolvedReservationPageUrl, reservationPageUrl]
	);

	const toggleAuthMode = useCallback(
		(mode) => {
			const nextMode = mode === authMode ? '' : mode;
			setAuthMode(nextMode);
			if (typeof window === 'undefined') {
				return;
			}

			const url = new URL(window.location.href);
			if (nextMode) {
				url.searchParams.set('vkbm_auth', nextMode);
			} else {
				url.searchParams.delete('vkbm_auth');
			}
			window.history.replaceState(null, '', url.toString());
		},
		[authMode]
	);

	const handleBookingsClick = useCallback(() => {
		const target = buildReservationModeUrl('bookings');
		if (target) {
			window.location.href = target;
			return;
		}
		toggleAuthMode('bookings');
	}, [buildReservationModeUrl, toggleAuthMode]);

	const handleProfileClick = useCallback(() => {
		const target = buildReservationModeUrl('profile');
		if (target) {
			window.location.href = target;
			return;
		}
		toggleAuthMode('profile');
	}, [buildReservationModeUrl, toggleAuthMode]);

	const handleBackClick = useCallback(() => {
		const target = buildReservationModeUrl('');
		if (target) {
			window.location.href = target;
			return;
		}
		toggleAuthMode('');
	}, [buildReservationModeUrl, toggleAuthMode]);

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
		.finally(() => setAuthLoading(false));
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

	const handleConfirm = () => {
		if (!draftToken) {
			return;
		}

		const cancellationText =
			typeof providerCancellationPolicy === 'string' && providerCancellationPolicy.trim() !== ''
				? providerCancellationPolicy
				: '';
		const termsText =
			typeof providerTermsOfService === 'string' && providerTermsOfService.trim() !== ''
				? providerTermsOfService
				: '';
		const requiresAgreements = !canManageReservations;

		if (requiresAgreements && cancellationText && !agreeCancellationPolicy) {
			setSubmitError(__('You must agree to the cancellation policy.', 'vk-booking-manager'));
			return;
		}

		if (requiresAgreements && termsText && !agreeTermsOfService) {
			setSubmitError(__('You must agree to the terms of use.', 'vk-booking-manager'));
			return;
		}

		const agreedAll = requiresAgreements
			? (!cancellationText || agreeCancellationPolicy) &&
			  (!termsText || agreeTermsOfService)
			: true;

		setSubmitting(true);
		setSubmitError('');
		const payload = {
			token: draftToken,
			memo,
			agree_terms: agreedAll,
			agree_cancellation_policy: requiresAgreements ? agreeCancellationPolicy : true,
			agree_terms_of_service: requiresAgreements ? agreeTermsOfService : true,
		};

		if (canManageReservations) {
			payload.customer_name = customerName;
			payload.customer_phone = customerPhone;
			payload.internal_note = providerNote;
		}

		apiFetch({
			path: '/vkbm/v1/bookings',
			method: 'POST',
			data: payload,
			parse: false,
		})
			.then(async (response) => {
				const raw = await response.text();
				let parsed;
				try {
					parsed = parseJsonWithFallback(raw);
				} catch (parseError) {
					parsed = null;
				}

				if (!response.ok) {
					const conflictMessage = __(
						'A reservation for the same date and time already exists. Please change the date and time.',
						'vk-booking-manager'
					);
					const rawConflict =
						typeof raw === 'string' &&
						(raw.includes('booking_time_conflict') ||
							raw.includes('A reservation for the same date and time already exists.'));
					const isConflict =
						response?.status === 409 ||
						parsed?.code === 'booking_time_conflict' ||
						rawConflict;
					const message =
						(isConflict ? conflictMessage : parsed?.message) ||
						(parsed?.code ? `${parsed.code} (HTTP ${response.status})` : `HTTP ${response.status}`) ||
						__('Confirmation of reservation failed. Please try again later.', 'vk-booking-manager');
					// eslint-disable-next-line no-console
					console.error('vkbm booking confirm: request failed', {
						status: response?.status,
						parsed,
						raw,
					});
					throw new Error(message);
				}

				setCreatedStatus(parsed?.status || '');
				setSuccess(true);
				if (redirectUrl) {
					const target = new URL(
						redirectUrl,
						typeof window !== 'undefined' ? window.location.origin : undefined
					);
					if (parsed?.booking_id) {
						target.searchParams.set('booking_id', String(parsed.booking_id));
					}
					window.location.href = target.toString();
				}
			})
			.catch((error) => {
				const conflictMessage = __(
					'A reservation for the same date and time already exists. Please change the date and time.',
					'vk-booking-manager'
				);
				const errorMessage = typeof error?.message === 'string' ? error.message : '';
				const errorStatus = error?.data?.status || error?.status;
				const isConflict =
					error?.code === 'booking_time_conflict' ||
					errorStatus === 409 ||
					errorMessage.includes('A reservation for the same date and time already exists.');
				setSubmitError(
					(isConflict ? conflictMessage : errorMessage) ||
						__('Confirmation of reservation failed. Please try again later.', 'vk-booking-manager')
				);
			})
			.finally(() => setSubmitting(false));
	};

	useEffect(() => {
		if (!success || redirectUrl) {
			return;
		}

		if (typeof window === 'undefined') {
			return;
		}

		const url = new URL(window.location.href);
		if (!url.searchParams.has('draft')) {
			return;
		}

		url.searchParams.delete('draft');
		window.history.replaceState(null, '', url.toString());
	}, [success, redirectUrl]);

	if (loading) {
		return (
			<div className="vkbm-confirm">
				<div className="vkbm-alert vkbm-alert__info" role="status">
					{__('Loading temporary reservation data...', 'vk-booking-manager')}
				</div>
			</div>
		);
	}

	if (loadError) {
		return (
			<div className="vkbm-confirm">
				<div className="vkbm-alert vkbm-alert__danger" role="alert">
					{loadError}
				</div>
			</div>
		);
	}

	if (!draft) {
		return null;
	}

	const finalSuccessMessage =
		createdStatus === 'pending'
			? __('Your tentative reservation has been completed.', 'vk-booking-manager')
			: successMessage;

	const menuName =
		draft.menu_label ||
		menu?.title?.rendered ||
		menu?.title ||
		menu?.name ||
		String(draft.menu_id);

	const staffName =
		draft.staff_label ||
		draft.slot?.staff_label ||
		draft.slot?.staff?.name ||
		staff?.title?.rendered ||
		staff?.title ||
		staff?.name ||
		__('No preference', 'vk-booking-manager');

	const logoutHref =
		logoutUrl || buildLogoutFallbackUrl(resolvedReservationPageUrl || reservationPageUrl);
	const showSuccessMessage = success && !redirectUrl;

	const renderMemoField = () => {
		const commonTextarea = (
			<textarea
				id="vkbm-confirm-memo"
				value={memo}
				onChange={(event) => setMemo(event.target.value)}
				placeholder={__('Please enter any contact information', 'vk-booking-manager')}
			/>
		);

		if (!showSuccessMessage) {
			return commonTextarea;
		}

		return (
			<textarea
				id="vkbm-confirm-memo"
				value={memo}
				readOnly
				placeholder={__('No entry', 'vk-booking-manager')}
			/>
		);
	};

	const renderAgreements = () => {
		if (showSuccessMessage || canManageReservations) {
			return null;
		}

		const cancellationPolicyText =
			typeof providerCancellationPolicy === 'string' && providerCancellationPolicy.trim() !== ''
				? providerCancellationPolicy
				: '';
		const termsOfServiceText =
			typeof providerTermsOfService === 'string' && providerTermsOfService.trim() !== ''
				? providerTermsOfService
				: '';

		const hasAgreements = Boolean(cancellationPolicyText || termsOfServiceText || paymentMethodText);

		return (
			<>
				{hasAgreements && (
					<div className="vkbm-agreements">
						{cancellationPolicyText && (
							<div className="vkbm-agreement">
								<h3 className="vkbm-agreement__title">
									{__('Cancellation policy', 'vk-booking-manager')}
								</h3>
								<div className="vkbm-agreement__body vkbm-agreement__body--full">
									{cancellationPolicyText}
								</div>
								<div className="vkbm-agreement__check">
									<input
										type="checkbox"
										checked={agreeCancellationPolicy}
										onChange={(event) => setAgreeCancellationPolicy(event.target.checked)}
										id="vkbm-confirm-cancellation-policy"
									/>
									<label htmlFor="vkbm-confirm-cancellation-policy">
										{__('I agree to the cancellation policy', 'vk-booking-manager')}
									</label>
								</div>
							</div>
						)}

						{termsOfServiceText && (
							<div className="vkbm-agreement">
								<h3 className="vkbm-agreement__title">
									{__('System Terms of Use', 'vk-booking-manager')}
								</h3>
								<div className="vkbm-agreement__body vkbm-agreement__body--scroll">
									{termsOfServiceText}
								</div>
								<div className="vkbm-agreement__check">
									<input
										type="checkbox"
										checked={agreeTermsOfService}
										onChange={(event) => setAgreeTermsOfService(event.target.checked)}
										id="vkbm-confirm-terms"
									/>
									<label htmlFor="vkbm-confirm-terms">{termsLabel}</label>
								</div>
							</div>
						)}
						{paymentMethodText && (
							<div className="vkbm-agreement">
								<h3 className="vkbm-agreement__title">
									{__('Payment method', 'vk-booking-manager')}
								</h3>
								<div className="vkbm-agreement__body">{paymentMethodText}</div>
							</div>
						)}
					</div>
				)}

				{!termsOfServiceText && policyText && (
					<p className="vkbm-confirm__policy">{policyText}</p>
				)}
			</>
		);
	};

	const cancellationPolicyRequired =
		typeof providerCancellationPolicy === 'string' && providerCancellationPolicy.trim() !== '';
	const termsOfServiceRequired =
		typeof providerTermsOfService === 'string' && providerTermsOfService.trim() !== '';
	const paymentMethodText =
		typeof providerPaymentMethod === 'string' && providerPaymentMethod.trim() !== ''
			? providerPaymentMethod
			: '';
	const canSubmit =
		isLoggedIn &&
		!submitting &&
		( canManageReservations ||
			( !cancellationPolicyRequired || agreeCancellationPolicy ) ) &&
		( canManageReservations || ( !termsOfServiceRequired || agreeTermsOfService ) );

	const datetimeParts = formatBookingDateTimeParts(
		draft?.slot?.start_at || '',
		draft?.slot?.service_end_at || draft?.slot?.end_at || '',
		timezone
	);

	const shouldShowLogo =
		showProviderLogo &&
		typeof providerLogoUrl === 'string' &&
		providerLogoUrl.trim() !== '';
	const shouldShowName =
		showProviderName &&
		typeof providerName === 'string' &&
		providerName.trim() !== '';
	const shouldShowBrand = shouldShowLogo || shouldShowName;
	const brandLinkHref = typeof resolvedReservationPageUrl === 'string'
		? resolvedReservationPageUrl.trim()
		: '';
	const brandLogoAlt = shouldShowName
		? providerName
		: __('logo image', 'vk-booking-manager');

	return (
		<div className="vkbm-confirm">
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
									src={providerLogoUrl}
									alt={brandLogoAlt}
									loading="lazy"
								/>
							)}
							{shouldShowName && (
								<span className="vkbm-reservation-layout__brand-name">
									{providerName}
								</span>
							)}
						</a>
					) : (
						<div className="vkbm-reservation-layout__brand">
							{shouldShowLogo && (
								<img
									className="vkbm-reservation-layout__brand-logo"
									src={providerLogoUrl}
									alt={brandLogoAlt}
									loading="lazy"
								/>
							)}
							{shouldShowName && (
								<span className="vkbm-reservation-layout__brand-name">
									{providerName}
								</span>
							)}
						</div>
					)}
					{isLoggedIn && canManageReservations && (
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
								href={logoutHref}
							>
								{__('Log out', 'vk-booking-manager')}
							</a>
							<span
								className="vkbm-reservation-layout__nav-bracket"
								aria-hidden="true"
							>
								]
							</span>
						</div>
					)}
					{isLoggedIn && !canManageReservations && showSuccessMessage && (
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
							<button
								type="button"
								className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
								onClick={handleBackClick}
							>
								{__('return', 'vk-booking-manager')}
							</button>
							<span
								className="vkbm-reservation-layout__nav-bracket"
								aria-hidden="true"
							>
								]
							</span>
						</div>
					)}
					{isLoggedIn && !canManageReservations && !showSuccessMessage && (
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
								<button
									type="button"
									className={[
										'vkbm-user-actions__link',
										'vkbm-button',
										'vkbm-button__sm',
										'vkbm-button__link',
										'vkbm-reservation-layout__nav-link',
										authMode === 'bookings' && 'is-active',
									]
										.filter(Boolean)
										.join(' ')}
									onClick={handleBookingsClick}
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
									className={[
										'vkbm-user-actions__link',
										'vkbm-button',
										'vkbm-button__sm',
										'vkbm-button__link',
										'vkbm-reservation-layout__nav-link',
										authMode === 'profile' && 'is-active',
									]
										.filter(Boolean)
										.join(' ')}
									onClick={handleProfileClick}
									aria-pressed={authMode === 'profile'}
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
									href={logoutHref}
								>
									{__('Log out', 'vk-booking-manager')}
								</a>
							<span
								className="vkbm-reservation-layout__nav-bracket"
								aria-hidden="true"
							>
								]
							</span>
						</div>
					)}
				</div>
			) : (
				<>
					{isLoggedIn && canManageReservations && (
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
								href={logoutHref}
							>
								{__('Log out', 'vk-booking-manager')}
							</a>
							<span
								className="vkbm-reservation-layout__nav-bracket"
								aria-hidden="true"
							>
								]
							</span>
						</div>
					)}
					{isLoggedIn && !canManageReservations && showSuccessMessage && (
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
							<button
								type="button"
								className="vkbm-user-actions__link vkbm-button vkbm-button__sm vkbm-button__link vkbm-reservation-layout__nav-link"
								onClick={handleBackClick}
							>
								{__('return', 'vk-booking-manager')}
							</button>
							<span
								className="vkbm-reservation-layout__nav-bracket"
								aria-hidden="true"
							>
								]
							</span>
						</div>
					)}
					{isLoggedIn && !canManageReservations && !showSuccessMessage && (
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
								<button
									type="button"
									className={[
										'vkbm-user-actions__link',
										'vkbm-button',
										'vkbm-button__sm',
										'vkbm-button__link',
										'vkbm-reservation-layout__nav-link',
										authMode === 'bookings' && 'is-active',
									]
										.filter(Boolean)
										.join(' ')}
									onClick={handleBookingsClick}
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
									className={[
										'vkbm-user-actions__link',
										'vkbm-button',
										'vkbm-button__sm',
										'vkbm-button__link',
										'vkbm-reservation-layout__nav-link',
										authMode === 'profile' && 'is-active',
									]
										.filter(Boolean)
										.join(' ')}
									onClick={handleProfileClick}
									aria-pressed={authMode === 'profile'}
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
									href={logoutHref}
								>
									{__('Log out', 'vk-booking-manager')}
								</a>
							<span
								className="vkbm-reservation-layout__nav-bracket"
								aria-hidden="true"
							>
								]
							</span>
						</div>
					)}
				</>
			)}
			{isLoggedIn && authMode === 'profile' && (
				<div className="vkbm-confirm__auth-panel">
					{authLoading && (
						<p className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading" role="status">
							{__('Loading form...', 'vk-booking-manager')}
						</p>
					)}
					{authError && (
						<p className="vkbm-alert vkbm-alert__danger vkbm-confirm__auth-error" role="alert">
							{authError}
						</p>
					)}
					{authFormHtml && (
						<div
							className="vkbm-confirm__auth-form"
							dangerouslySetInnerHTML={{
								__html: authFormHtml,
							}}
						/>
					)}
				</div>
			)}
			{isLoggedIn && authMode === 'bookings' && (
				<div className="vkbm-confirm__auth-panel">
					{bookingsLoading && (
						<p className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading" role="status">
							{__('Loading...', 'vk-booking-manager')}
						</p>
					)}
					{bookingsError && (
						<p className="vkbm-alert vkbm-alert__danger vkbm-confirm__auth-error" role="alert">
							{bookingsError}
						</p>
					)}
					{!bookingsLoading && !bookingsError && bookings.length === 0 && (
						<p className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading" role="status">
							{__('There are no reservations to display.', 'vk-booking-manager')}
						</p>
					)}
					{bookings.length > 0 && (
						<div className="vkbm-confirm">
							{bookings.map((booking) => {
								const bookingDatetimeParts = formatBookingDateTimeParts(
									booking?.start_at || '',
									booking?.end_at || '',
									timezone
								);
								const statusKey = String(booking?.status || '').trim();
								const isCancelled = statusKey.toLowerCase() === 'cancelled';
								return (
									<div
										className={[
											'vkbm-confirm__summary',
											isCancelled && 'vkbm-confirm__summary--cancelled',
										]
											.filter(Boolean)
											.join(' ')}
										key={booking?.id || bookingDatetimeParts.date}
									>
										{(bookingDatetimeParts.date || bookingDatetimeParts.time) && (
											<div className="vkbm-confirm__summary-title">
												<div className="vkbm-confirm__datetime" aria-label={__('Reservation date and time', 'vk-booking-manager')}>
													{isCancelled && (
														<span className="vkbm-confirm__status vkbm-confirm__status--cancelled">
															{__('Cancelled', 'vk-booking-manager')}
														</span>
													)}
													{bookingDatetimeParts.date && (
														<span className="vkbm-confirm__date">
															{bookingDatetimeParts.date}
														</span>
													)}
													{bookingDatetimeParts.time && (
														<span className="vkbm-confirm__time">
															{bookingDatetimeParts.date ? ' ' : ''}
															{bookingDatetimeParts.time}
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
											resourceLabel={resourceLabelSingular}
											emptyValue="—"
										/>
									</div>
								);
							})}
						</div>
					)}
				</div>
			)}

			{authMode !== 'bookings' && (
			<>
			<div className="vkbm-confirm__summary">
				{(datetimeParts.date || datetimeParts.time) && (
					<div className="vkbm-confirm__summary-title">
						<div className="vkbm-confirm__datetime" aria-label={__('Reservation date and time', 'vk-booking-manager')}>
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
					</div>
				)}
 				<SummaryRow label={__('Menu', 'vk-booking-manager')} value={menuName} />
				{staffEnabled && (
					<SummaryRow label={resourceLabelSingular} value={staffName} />
				)}
				<SummaryRow
					label={__('Service basic fee', 'vk-booking-manager')}
					value={pricingSummary.baseLabel}
				/>
				{staffEnabled && (
					<SummaryRow
						label={__('nomination fee', 'vk-booking-manager')}
						value={pricingSummary.nominationLabel || '¥0'}
					/>
				)}
				<SummaryRow
					label={__('Total basic fee', 'vk-booking-manager')}
					value={pricingSummary.totalLabel || pricingSummary.baseLabel}
					valueClassName="vkbm-confirm__summary-item-value--price"
				/>
			</div>

			{!canManageReservations && !providerCancellationPolicy && !providerTermsOfService && policyText && (
				<p className="vkbm-confirm__policy">{policyText}</p>
			)}

			{!isLoggedIn && (
				<div className="vkbm-confirm__auth">
					<p className="vkbm-confirm__auth-label">
						{__('You must log in to confirm your reservation.', 'vk-booking-manager')}
					</p>
					<div className="vkbm-buttons vkbm-buttons__center">
						<button
							type="button"
							className={[
								'vkbm-button',
								'vkbm-button__sm',
								'vkbm-button__primary',
								authMode === 'login' && 'is-active',
							]
								.filter(Boolean)
								.join(' ')}
							onClick={() => toggleAuthMode('login')}
						>
							{__('Log in', 'vk-booking-manager')}
						</button>
						<button
							type="button"
							className={[
								'vkbm-button',
								'vkbm-button__sm',
								'vkbm-button-outline',
								'vkbm-button-outline__primary',
								authMode === 'register' && 'is-active',
							]
								.filter(Boolean)
								.join(' ')}
							onClick={() => toggleAuthMode('register')}
						>
							{__('Sign up', 'vk-booking-manager')}
						</button>
					</div>
					{authMode && (
						<div className="vkbm-confirm__auth-panel">
							{authLoading && (
								<p className="vkbm-alert vkbm-alert__info vkbm-confirm__auth-loading" role="status">
									{__('Loading form...', 'vk-booking-manager')}
								</p>
							)}
							{authError && (
								<p className="vkbm-alert vkbm-alert__danger vkbm-confirm__auth-error" role="alert">
									{authError}
								</p>
							)}
						{authFormHtml && (
							<div
								className="vkbm-confirm__auth-form"
									dangerouslySetInnerHTML={{
										__html: authFormHtml,
									}}
								/>
							)}
						</div>
					)}
				</div>
			)}

			<div className="vkbm-confirm__form">
				<label htmlFor="vkbm-confirm-memo">
					{__('Requests/Notes', 'vk-booking-manager')}
				</label>
				{renderMemoField()}
			</div>

			{canManageReservations && (
				<div className="vkbm-confirm__admin-fields">
					<div className="vkbm-confirm__admin-field">
						<label htmlFor="vkbm-confirm-customer-name">
							{__('Reserved name', 'vk-booking-manager')}
						</label>
						<input
							type="text"
							id="vkbm-confirm-customer-name"
							value={customerName}
							onChange={(event) => setCustomerName(event.target.value)}
							placeholder={__('Please enter customer name.', 'vk-booking-manager')}
						/>
					</div>
					<div className="vkbm-confirm__admin-field">
						<label htmlFor="vkbm-confirm-customer-phone">
							{__('telephone number', 'vk-booking-manager')}
						</label>
						<input
							type="tel"
							id="vkbm-confirm-customer-phone"
							value={customerPhone}
							onChange={(event) => setCustomerPhone(event.target.value)}
							placeholder={__('Please enter your contact phone number.', 'vk-booking-manager')}
						/>
					</div>
					<div className="vkbm-confirm__admin-field">
						<label htmlFor="vkbm-confirm-provider-note">
							{__('Management memo', 'vk-booking-manager')}
						</label>
						<textarea
							id="vkbm-confirm-provider-note"
							value={providerNote}
							onChange={(event) => setProviderNote(event.target.value)}
							placeholder={__('Please enter any internal notes.', 'vk-booking-manager')}
						/>
					</div>
				</div>
			)}

			{renderAgreements()}

			<div className="vkbm-confirm__actions">
				{!success && (
						<button
							type="button"
							className="vkbm-confirm__button vkbm-button vkbm-button__md vkbm-button__primary"
							onClick={handleConfirm}
							disabled={!canSubmit}
						>
						{submitting ? __('Processing...', 'vk-booking-manager') : __('Reserve with this content', 'vk-booking-manager')}
					</button>
				)}
				{showSuccessMessage && (
					<p className="vkbm-alert vkbm-alert__success text-center" role="status">
						{finalSuccessMessage}
					</p>
				)}
				{submitError && (
					<p className="vkbm-alert vkbm-alert__danger" role="alert">
						{submitError}
					</p>
				)}
			</div>
			</>
			)}
		</div>
	);
};
