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

export const formatCurrencyJPY = (value, currencySymbol = null) => {
	const normalized = normalizePriceValue(value);

	if (normalized === null) {
		return '';
	}

	const formatter = new Intl.NumberFormat('ja-JP');
	const formattedAmount = formatter.format(normalized);

	if (currencySymbol !== null && currencySymbol !== undefined && currencySymbol.trim() !== '') {
		return `${currencySymbol}${formattedAmount}`;
	}

	return sprintf(
		/* translators: %s: price amount */
		__('$%s', 'vk-booking-manager'),
		formattedAmount
	);
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
