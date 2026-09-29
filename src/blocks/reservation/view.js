import domReady from '@wordpress/dom-ready';
import { render } from '@wordpress/element';
import { ReservationApp } from './app';
import { registerRestFallbackMiddleware } from '../shared/rest-fallback';

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

		const props = {
			defaultMenuId: Number( defaultMenuId ) || 0,
			defaultStaffId: Number( defaultResourceId ) || 0,
			allowStaffSelection: allowStaffSelection !== '0',
			initialLoginError,
			initialLoginErrorMessage,
			initialRegistrationErrorKey,
		};

		render( <ReservationApp { ...props } />, node );
	} );
};

domReady( bootstrap );
