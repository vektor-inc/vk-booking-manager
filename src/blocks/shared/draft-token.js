/**
 * Sanitize a draft token string so it only contains lowercase alphanumerics.
 *
 * @param {string} token Raw token value.
 * @return {string} Sanitized token.
 */
export const sanitizeDraftToken = (token = '') => {
	if (typeof token !== 'string') {
		return '';
	}

	return token.toLowerCase().replace(/[^a-z0-9]/g, '');
};


