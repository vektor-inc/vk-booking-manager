/**
 * 会員登録なしの予約（ゲスト予約）の、予約画面（お客様側）で使う共通部品。
 *
 * 仕様は docs/specification-guest-booking.md。お客様の目に触れる文言では「ゲスト」と言わず、
 * 「会員登録せずに予約する」と書く。
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/** 予約の受付方法（基本設定 booking_customer_mode）。 */
export const BOOKING_CUSTOMER_MODE = {
	MEMBER: 'member',
	GUEST: 'guest',
	BOTH: 'both',
};

/**
 * 受付方法を検査して返す。許可外の値は「会員のみ」に倒す（今までの動きを変えないため）。
 *
 * @param {*} value provider-settings の booking_customer_mode。
 * @return {string} 'member' / 'guest' / 'both' のいずれか。
 */
export const normalizeBookingCustomerMode = ( value ) =>
	value === BOOKING_CUSTOMER_MODE.GUEST ||
	value === BOOKING_CUSTOMER_MODE.BOTH
		? value
		: BOOKING_CUSTOMER_MODE.MEMBER;

/**
 * メールアドレスの形として明らかに誤っていないかを調べる（厳密な検証はサーバーが行う）。
 *
 * @param {string} email メールアドレス。
 * @return {boolean} 形が正しければ true。
 */
export const isEmailFormat = ( email ) =>
	/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( email || '' ).trim() );

/**
 * お客様情報（お名前・メールアドレス・電話番号）の入力を検査する。すべて必須。
 *
 * @param {Object} values       入力値。
 * @param {string} values.name  予約者のお名前。
 * @param {string} values.email メールアドレス。
 * @param {string} values.phone 電話番号。
 * @return {{name: string, email: string, phone: string}} 欄ごとのエラー文。問題がない欄は空文字。
 */
export const validateGuestContact = ( { name, email, phone } ) => {
	const errors = { name: '', email: '', phone: '' };

	// お名前（空白だけは未入力として扱う）。
	if ( String( name || '' ).trim() === '' ) {
		errors.name = __( 'Please enter your name.', 'vk-booking-manager' );
	}

	// メールアドレス（未入力と形の誤りで文言を分ける）。
	const trimmedEmail = String( email || '' ).trim();
	if ( trimmedEmail === '' ) {
		errors.email = __(
			'Please enter your email address.',
			'vk-booking-manager'
		);
	} else if ( ! isEmailFormat( trimmedEmail ) ) {
		errors.email = __(
			'Please enter a valid email address.',
			'vk-booking-manager'
		);
	}

	// 電話番号（数字を 1 文字も含まないものは未入力と同じ扱い。全角数字も数字として数える）。
	if ( ! /[0-9０-９]/.test( String( phone || '' ) ) ) {
		errors.phone = __(
			'Please enter your phone number.',
			'vk-booking-manager'
		);
	}

	return errors;
};

/**
 * 入力欄の検査結果に、エラーがあるかを返す。
 *
 * @param {{name: string, email: string, phone: string}} errors validateGuestContact() の戻り値。
 * @return {boolean} 1 つでもエラーがあれば true。
 */
export const hasGuestContactErrors = ( errors ) =>
	Boolean( errors.name || errors.email || errors.phone );

/**
 * 申し込み（POST /vkbm/v1/guest-bookings）のリクエスト本文を作る。
 *
 * 備考・同意・ご利用になる方は、画面の値を送る（送らなければ下書きの値が使われる）。
 *
 * @param {Object}  params                         引数。
 * @param {string}  params.draftToken              予約の下書きのトークン。
 * @param {Object}  params.contact                 name・email・phone。
 * @param {string}  params.honeypot                隠し入力欄の値（人は入力しない）。
 * @param {string}  params.memo                    ご要望／備考。
 * @param {string}  params.attendeeName            ご利用になる方のお名前。
 * @param {boolean} params.agreeCancellationPolicy キャンセルポリシーへの同意。
 * @param {boolean} params.agreeTermsOfService     利用規約への同意。
 * @param {boolean} params.agreeTerms              両方に同意したか。
 * @return {Object} リクエスト本文。
 */
export const buildGuestBookingPayload = ( {
	draftToken,
	contact,
	honeypot = '',
	memo = '',
	attendeeName = '',
	agreeCancellationPolicy = false,
	agreeTermsOfService = false,
	agreeTerms = false,
} ) => ( {
	token: draftToken,
	customer_name: String( contact.name || '' ).trim(),
	customer_email: String( contact.email || '' ).trim(),
	customer_phone: String( contact.phone || '' ).trim(),
	vkbm_hp_email: honeypot,
	memo,
	attendee_name: attendeeName,
	agree_terms: agreeTerms,
	agree_cancellation_policy: agreeCancellationPolicy,
	agree_terms_of_service: agreeTermsOfService,
} );

/**
 * ゲスト予約の申し込みを送る。成功すると確認メールが送られる（予約はまだ作られない）。
 *
 * @param {Object} payload buildGuestBookingPayload() の戻り値。
 * @return {Promise<{status: string, email: string, expires_in: number, expires_at: string}>} 送信結果。
 */
export const postGuestBooking = ( payload ) =>
	apiFetch( {
		path: '/vkbm/v1/guest-bookings',
		method: 'POST',
		data: payload,
	} );
