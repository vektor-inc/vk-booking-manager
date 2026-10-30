import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ReservationHeader } from './reservation-header';
import { ReservationFooter } from './reservation-footer';
import { buildFooterModel, extractFooterSettings } from './footer-model';

/** 確認リンクのクエリ名（Guest_Booking_Controller::QUERY_VAR）。 */
export const VERIFY_QUERY_VAR = 'vkbm_guest_verify';

/** キャンセル用リンクのクエリ名（Guest_Cancel_Controller::QUERY_VAR と BOOKING_QUERY_VAR）。 */
export const CANCEL_QUERY_VARS = [ 'vkbm_guest_cancel', 'booking' ];

/**
 * 「予約ページへ」のリンク先を作る。今のページから、メールのリンクに付いていたクエリだけを外す。
 *
 * 予約ページのアドレスが `?page_id=` の形でも壊れないよう、ほかのクエリは残す。
 *
 * @param {string[]} queryVars 外すクエリ名。
 * @return {string} 予約ページの URL。
 */
export const buildReservationPageUrl = ( queryVars = [ VERIFY_QUERY_VAR ] ) => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const url = new URL( window.location.href );
	queryVars.forEach( ( name ) => url.searchParams.delete( name ) );
	url.hash = '';

	return url.toString();
};

/**
 * メールのリンクを開いたときの画面の外枠（ブランド表示・フッター）。
 *
 * 確認リンクの結果画面とキャンセル用リンクの画面で共通に使う。基本設定（店名・ロゴ・フッターなど）は、
 * 予約確認画面と同じ REST から読み、読み込めた設定を子へ渡す。表示したら、リンクのトークンをアドレスバー・履歴から外す。
 *
 * @param {Object}                                 props                    プロパティ。
 * @param {string}                                 props.reservationPageUrl 「予約ページへ」のリンク先（トークンを外した URL）。
 * @param {(settings: Object|null) => JSX.Element} props.children           設定（読み込み前は null）を受け取り、本体を返す関数。
 * @return {JSX.Element} 画面。
 */
export const GuestResultFrame = ( { reservationPageUrl, children } ) => {
	const [ settings, setSettings ] = useState( null );

	// 基本設定を読む（失敗しても本体の表示は続ける）。
	useEffect( () => {
		let isMounted = true;

		apiFetch( { path: '/vkbm/v1/provider-settings' } )
			.then( ( response ) => {
				if ( isMounted ) {
					setSettings( response );
				}
			} )
			.catch( () => {
				// 取得できなくても、既定の見出しで表示する。
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	// 表示したら、リンクのトークンをアドレスバー・履歴から外す（「予約ページへ」のリンク先は呼び出し側で作成済み）。
	useEffect( () => {
		if ( typeof window !== 'undefined' ) {
			window.history.replaceState(
				null,
				'',
				reservationPageUrl || window.location.pathname
			);
		}
	}, [ reservationPageUrl ] );

	const providerName =
		typeof settings?.provider_name === 'string'
			? settings.provider_name
			: '';
	const providerLogoUrl =
		typeof settings?.provider_logo_url === 'string'
			? settings.provider_logo_url
			: '';
	const showLogo =
		Boolean( settings?.reservation_show_provider_logo ) &&
		providerLogoUrl.trim() !== '';
	const showName =
		Boolean( settings?.reservation_show_provider_name ) &&
		providerName.trim() !== '';

	return (
		<div className="vkbm-reservation-content vkbm-reservation-content--confirm vkbm-reservation-content--guest-result">
			<div className="vkbm-confirm">
				<ReservationHeader
					showBrand={ showLogo || showName }
					brandLinkHref={ settings?.reservation_page_url || '' }
					showLogo={ showLogo }
					logoUrl={ providerLogoUrl }
					logoWidth={
						Number( settings?.provider_logo_width ) > 0
							? Number( settings.provider_logo_width )
							: 0
					}
					logoAlt={
						showName
							? providerName
							: __( 'Logo image', 'vk-booking-manager' )
					}
					showName={ showName }
					brandName={ providerName }
					nav={ null }
				/>
				{ children( settings ) }
				<ReservationFooter
					model={
						settings
							? buildFooterModel(
									extractFooterSettings( settings )
							  )
							: null
					}
				/>
			</div>
		</div>
	);
};

/**
 * 画面に出す「担当」「人数」の見出しと人数の単位を、基本設定から決める。
 *
 * @param {Object|null} settings 基本設定（読み込み前は null）。
 * @return {{resourceLabel: string, guestsLabel: string, guestsUnitLabel: string}} 見出しと単位。
 */
export const getSummaryLabels = ( settings ) => ( {
	resourceLabel:
		typeof settings?.resource_label_singular === 'string' &&
		settings.resource_label_singular.trim() !== ''
			? settings.resource_label_singular
			: __( 'Staff', 'vk-booking-manager' ),
	guestsLabel:
		typeof settings?.guests_count_label === 'string' &&
		settings.guests_count_label.trim() !== ''
			? settings.guests_count_label
			: __( 'Number of guests', 'vk-booking-manager' ),
	guestsUnitLabel:
		typeof settings?.guests_unit_label === 'string'
			? settings.guests_unit_label
			: '',
} );

/**
 * 「予約ページへ」のリンク先を、画面の間は変わらない値として保つ。
 *
 * @param {string[]} queryVars 外すクエリ名。
 * @return {string} 予約ページの URL。
 */
export const useReservationPageUrl = ( queryVars ) =>
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useMemo( () => buildReservationPageUrl( queryVars ), [] );
