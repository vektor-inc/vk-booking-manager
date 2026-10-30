import domReady from '@wordpress/dom-ready';
import { render } from '@wordpress/element';
import { ReservationApp } from './app';
import { GuestVerifyResultScreen } from './guest-verify-result-screen';
import { GuestCancelScreen } from './guest-cancel-screen';
import { registerRestFallbackMiddleware } from '../shared/rest-fallback';
import { readDetailSource } from './components/service-menu-detail-source';

const bootstrap = () => {
	// パーマリンク設定はあるがサーバー側（.htaccess 等）に反映されていない環境向けの
	// REST フォールバック（issue #489）。window.vkbmReservationConfig は
	// Reservation_Block::maybe_enqueue_reservation_config() が他のインラインスクリプトと
	// 同じタイミングで出力しているため、domReady のこのタイミングでは読み込み済み。
	registerRestFallbackMiddleware();

	const nodes = document.querySelectorAll(
		'.wp-block-vk-booking-manager-reservation'
	);

	nodes.forEach( ( node ) => {
		// Reservation block settings are configured from BM basic settings; defaults for when no data attributes (e.g. block saved after attributes were removed).
		const dataset = node.dataset || {};
		const defaultMenuId = dataset.defaultMenuId ?? '0';
		const defaultResourceId = dataset.defaultResourceId ?? '0';
		const allowStaffSelection = dataset.allowStaffSelection ?? '1';
		// issue #512: render_block フィルタ（Reservation_Block）が同一リクエスト内で
		// 判明したログイン失敗時のみ data-vkbm-login-error を付与する（`data-vkbm-login-error`
		// → `dataset.vkbmLoginError`）。未指定時は undefined になるため空文字にフォールバックする。
		const initialLoginError = dataset.vkbmLoginError ?? '';

		// issue #512 植草指摘（ちらつき対策）: render() で置き換える前に、サーバーが
		// 埋め込んだフォールバックのエラー文（vkbm-alert__danger）をそのまま読み取って
		// 引き継ぐ。REST 応答が届くまでの間、React 側で同じ文言を表示し続けることで、
		// 「エラー文 → 読み込み中 → エラー文」という表示の消失・再表示を防ぐ。
		const initialLoginErrorMessage = initialLoginError
			? (
					node.querySelector( '.vkbm-alert__danger' )?.textContent ||
					''
			  ).trim()
			: '';

		// issue #516: render_block フィルタ（Reservation_Block）が同一リクエスト内で
		// 発行した会員登録失敗トークンのみを data-vkbm-registration-error-key に埋め込む
		// （`data-vkbm-registration-error-key` → `dataset.vkbmRegistrationErrorKey`）。
		// トークン自体に個人情報は含まれない（サーバー側 transient への鍵でしかない）。
		const initialRegistrationErrorKey =
			dataset.vkbmRegistrationErrorKey ?? '';

		// #635: サーバーが埋め込んだサービスメニューの詳細表示（本文・カード）。
		// render() で中身が置き換わる前に、ここで取り出しておく。
		const initialDetail = readDetailSource( node );

		const props = {
			defaultMenuId: Number( defaultMenuId ) || 0,
			defaultStaffId: Number( defaultResourceId ) || 0,
			allowStaffSelection: allowStaffSelection !== '0',
			initialLoginError,
			initialLoginErrorMessage,
			initialRegistrationErrorKey,
			initialDetail,
		};

		// 会員登録なしの予約のキャンセル用リンクを開いたリクエストでは、予約画面の代わりにキャンセルの確認画面を出す。
		// サーバーが確かめた内容だけを window.vkbmReservationConfig に載せる（トークンは載せず、画面が URL から読む）。
		const guestCancel = window.vkbmReservationConfig?.guestCancel;
		if ( guestCancel && typeof guestCancel.state === 'string' ) {
			render( <GuestCancelScreen view={ guestCancel } />, node );
			return;
		}

		// 会員登録なしの予約の確認リンクを開いたリクエストでは、予約画面の代わりに結果画面を出す。
		// サーバーが処理した結果だけを window.vkbmReservationConfig に載せる（code・message は載せない）。
		const guestVerifyResult =
			window.vkbmReservationConfig?.guestVerifyResult;
		if (
			guestVerifyResult &&
			typeof guestVerifyResult.result === 'string'
		) {
			render(
				<GuestVerifyResultScreen result={ guestVerifyResult } />,
				node
			);
			return;
		}

		render( <ReservationApp { ...props } />, node );
	} );
};

domReady( bootstrap );
