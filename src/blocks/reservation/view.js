import domReady from '@wordpress/dom-ready';
import { render } from '@wordpress/element';
import { ReservationApp } from './app';

const bootstrap = () => {
	const nodes = document.querySelectorAll(
		'.wp-block-vk-booking-manager-reservation'
	);

		nodes.forEach((node) => {
			// Reservation block settings are configured from BM basic settings; defaults for when no data attributes (e.g. block saved after attributes were removed).
			const dataset = node.dataset || {};
			const defaultMenuId = dataset.defaultMenuId ?? '0';
			const defaultResourceId = dataset.defaultResourceId ?? '0';
			const allowStaffSelection = dataset.allowStaffSelection ?? '1';

			const props = {
				defaultMenuId: Number(defaultMenuId) || 0,
				defaultStaffId: Number(defaultResourceId) || 0,
				allowStaffSelection: allowStaffSelection !== '0',
			};

			render(<ReservationApp {...props} />, node);
	});
};

domReady(bootstrap);
