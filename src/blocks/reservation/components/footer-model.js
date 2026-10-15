import { __, sprintf } from '@wordpress/i18n';

/**
 * 文字列でなければ空文字、文字列なら前後の空白を除いて返す。
 *
 * @param {unknown} value 任意の値。
 * @return {string} 前後の空白を除いた文字列。
 */
const toTrimmedString = ( value ) =>
	typeof value === 'string' ? value.trim() : '';

/**
 * URL が http / https のときだけ、そのまま返す。
 *
 * @param {string} url 検証する URL。
 * @return {string} 安全な URL。それ以外は空文字。
 */
const toSafeHttpUrl = ( url ) => {
	const value = toTrimmedString( url );
	return /^https?:\/\//i.test( value ) ? value : '';
};

/**
 * フッターに表示する電話番号の文字列を組み立てる（番号の前に「TEL」を付ける）。
 *
 * @param {string} phone 電話番号。
 * @return {string} 表示用の文字列。電話番号が空なら空文字。
 */
export const formatFooterPhone = ( phone ) => {
	const value = toTrimmedString( phone );
	if ( ! value ) {
		return '';
	}
	return sprintf(
		/* translators: %s: phone number (e.g. 000-000-0000) */
		__( 'TEL %s', 'vk-booking-manager' ),
		value
	);
};

/**
 * REST（/vkbm/v1/provider-settings）の応答から、buildFooterModel() に渡す設定を取り出す。
 * 予約画面と予約確認・予約完了画面で、同じ取り出し方を使うための共通関数。
 *
 * @param {?Object} response REST の応答。
 * @return {Object} buildFooterModel() に渡す設定。
 */
export const extractFooterSettings = ( response ) => ( {
	showFooter: Boolean( response?.reservation_show_footer ),
	footer: response?.reservation_footer || null,
	cancellationPolicy:
		typeof response?.cancellation_policy === 'string'
			? response.cancellation_policy
			: '',
	termsOfService:
		typeof response?.terms_of_service === 'string'
			? response.terms_of_service
			: '',
} );

/**
 * REST の設定値から、フッターの表示内容を組み立てる。
 * 未入力の項目・本文の無い規約は含めない。
 *
 * @param {Object}  settings                    予約画面の設定。
 * @param {boolean} settings.showFooter         フッターを表示する設定か。
 * @param {Object}  settings.footer             店舗情報・ウェブサイトURL・プライバシーポリシー（REST の reservation_footer）。
 * @param {string}  settings.cancellationPolicy キャンセルポリシー本文。
 * @param {string}  settings.termsOfService     システム利用規約本文。
 * @return {?Object} 表示内容。表示するものが無い場合は null。
 */
export const buildFooterModel = ( settings ) => {
	if ( ! settings || ! settings.showFooter ) {
		return null;
	}

	const footer = settings.footer || {};
	const privacy = footer.privacy_policy || {};
	const phone = toTrimmedString( footer.phone );
	const info = {
		providerName: toTrimmedString( footer.provider_name ),
		address: toTrimmedString( footer.address ),
		phone,
		businessHours: toTrimmedString( footer.business_hours ),
	};

	const links = [];
	// ウェブサイトは、サーバー側で予約ページと別の URL と判定されたときだけ先頭に入れる（別タブで開く）。
	const websiteUrl = toSafeHttpUrl( footer.website_url );
	if ( websiteUrl ) {
		links.push( {
			key: 'website',
			label: __( 'Website', 'vk-booking-manager' ),
			body: '',
			url: websiteUrl,
		} );
	}
	// 本文のある規約だけリンクにする。
	const cancellationPolicy = toTrimmedString( settings.cancellationPolicy );
	if ( cancellationPolicy ) {
		links.push( {
			key: 'cancellation-policy',
			label: __( 'Cancellation policy', 'vk-booking-manager' ),
			body: cancellationPolicy,
			url: '',
		} );
	}
	const termsOfService = toTrimmedString( settings.termsOfService );
	if ( termsOfService ) {
		links.push( {
			key: 'terms-of-service',
			label: __( 'System Terms of Use', 'vk-booking-manager' ),
			body: termsOfService,
			url: '',
		} );
	}
	// プライバシーポリシーは会員登録画面と同じく、URL 指定なら別タブ、本文入力ならモーダルで開く。
	const privacyLabel = __( 'Privacy policy', 'vk-booking-manager' );
	const privacyUrl = toSafeHttpUrl( privacy.url );
	const privacyContent = toTrimmedString( privacy.content );
	if ( privacy.mode === 'url' && privacyUrl ) {
		links.push( {
			key: 'privacy-policy',
			label: privacyLabel,
			body: '',
			url: privacyUrl,
		} );
	} else if ( privacy.mode === 'content' && privacyContent ) {
		links.push( {
			key: 'privacy-policy',
			label: privacyLabel,
			body: privacyContent,
			url: '',
		} );
	}

	const hasInfo = Boolean(
		info.providerName || info.address || info.phone || info.businessHours
	);
	if ( ! hasInfo && links.length === 0 ) {
		return null;
	}

	return { ...info, links };
};
