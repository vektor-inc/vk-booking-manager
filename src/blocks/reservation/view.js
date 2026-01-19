import domReady from '@wordpress/dom-ready';
import { render } from '@wordpress/element';
import { ReservationApp } from './app';

const bootstrap = () => {
	const nodes = document.querySelectorAll(
		'.wp-block-vk-booking-manager-reservation'
	);

		nodes.forEach((node) => {
			const {
				defaultMenuId = '0',
				defaultResourceId = '0',
				allowMenuSelection = '1',
				allowStaffSelection = '1',
			} = node.dataset;

			const props = {
				defaultMenuId: Number(defaultMenuId) || 0,
				defaultStaffId: Number(defaultResourceId) || 0,
				allowMenuSelection: allowMenuSelection !== '0',
				allowStaffSelection: allowStaffSelection !== '0',
			};

		render(<ReservationApp {...props} />, node);
	});
};

domReady(bootstrap);
