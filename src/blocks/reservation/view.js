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

		const props = {
			defaultMenuId: Number( defaultMenuId ) || 0,
			defaultStaffId: Number( defaultResourceId ) || 0,
			allowStaffSelection: allowStaffSelection !== '0',
		};

		render( <ReservationApp { ...props } />, node );
	} );
};

domReady( bootstrap );
