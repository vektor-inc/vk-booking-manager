import { __, sprintf } from '@wordpress/i18n';

export const normalizePriceValue = (value) => {
	if (value === null || value === undefined) {
		return null;
	}

	if (typeof value === 'number' && Number.isFinite(value) && value >= 0) {
		return value;
	}

	if (typeof value === 'string') {
		const trimmed = value.trim();

		if (trimmed === '') {
			return null;
		}

		const parsed = Number(trimmed);

		if (Number.isFinite(parsed) && parsed >= 0) {
			return parsed;
		}
	}

	return null;
};

const resolveDefaultCurrencySymbol = () => {
	if (typeof window === 'undefined') {
		return '$';
	}

	const locale = window?.vkbmCurrentUserBootstrap?.locale;
	if (typeof locale === 'string' && locale.trim() !== '' && locale.toLowerCase().startsWith('ja')) {
		return '¥';
	}

	return '$';
};

export const formatCurrency = (value, currencySymbol = null) => {
	const normalized = normalizePriceValue(value);

	if (normalized === null) {
		return '';
	}

	let formatter;
	try {
		formatter = new Intl.NumberFormat(resolveNumberFormatLocale());
	} catch (error) {
		formatter = new Intl.NumberFormat('ja-JP');
	}
	const formattedAmount = formatter.format(normalized);

	const trimmedSymbol = typeof currencySymbol === 'string' ? currencySymbol.trim() : '';
	const resolvedSymbol = trimmedSymbol !== '' ? trimmedSymbol : resolveDefaultCurrencySymbol();

	if (resolvedSymbol !== '') {
		return `${resolvedSymbol}${formattedAmount}`;
	}

	return sprintf(
		/* translators: %s: price amount */
		__('$%s', 'vk-booking-manager'),
		formattedAmount
	);
};

const resolveNumberFormatLocale = () => {
	if (typeof window === 'undefined') {
		return 'ja-JP';
	}

	const locale = window?.vkbmCurrentUserBootstrap?.locale;
	if (typeof locale === 'string' && locale.trim() !== '') {
		return locale.trim();
	}

	return 'ja-JP';
};

export const extractMenuBasePrice = (menu) => {
	if (!menu) {
		return null;
	}

	const candidates = [
		menu?.meta?.vkbm_base_price,
		menu?.meta?._vkbm_base_price,
		menu?._vkbm_base_price,
		menu?.price,
	];

	for (const candidate of candidates) {
		const normalized = normalizePriceValue(candidate);
		if (normalized !== null) {
			return normalized;
		}
	}

	return null;
};
