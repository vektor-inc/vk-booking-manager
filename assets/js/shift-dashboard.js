(function () {
	'use strict';

	function toArray(nodeList) {
		return Array.prototype.slice.call(nodeList);
	}

	function readQueryParam(name) {
		var query = window.location.search.substring(1);
		if (!query) {
			return null;
		}

		return query.split('&').reduce(function (found, pair) {
			if (found !== null) {
				return found;
			}

			var parts = pair.split('=');
			if (parts[0] !== name) {
				return null;
			}

			return parts[1] ? decodeURIComponent(parts[1]) : '';
		}, null);
	}

	function normalizeMonthValue(value) {
		if (!value) {
			return '';
		}

		var parts = value.split('-');
		if (parts.length !== 2) {
			return '';
		}

		var year = parts[0];
		var month = parts[1];

		if (month.length === 1) {
			month = '0' + month;
		}

		return year + '-' + month + '-01';
	}

	function getDateForView(root, view) {
		if (view === 'day') {
			var dayHeader = root.querySelector('.vkbm-day-view__header');
			return dayHeader ? dayHeader.getAttribute('data-vkbm-date') : '';
		}

		if (view === 'month') {
			var monthHeader = root.querySelector('.vkbm-month-view__header');
			if (!monthHeader) {
				return '';
			}

			var monthValue = monthHeader.getAttribute('data-vkbm-month');
			return normalizeMonthValue(monthValue);
		}

		return '';
	}

	function toggleView(viewKey, buttons, panels) {
		buttons.forEach(function (button) {
			var isActive = button.getAttribute('data-vkbm-view') === viewKey;
			button.classList.toggle('is-active', isActive);
			button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
		});

		panels.forEach(function (panel) {
			var shouldShow = panel.getAttribute('data-vkbm-view-panel') === viewKey;
			panel.classList.toggle('is-active', shouldShow);
		});
	}

	function updateHistory(view, dateValue) {
		if (typeof window.URL === 'undefined' || !window.history || !window.history.pushState) {
			return;
		}

		var url = new window.URL(window.location.href);

		if (view) {
			url.searchParams.set('vkbm_view', view);
		} else {
			url.searchParams.delete('vkbm_view');
		}

		if (dateValue) {
			url.searchParams.set('vkbm_date', dateValue);
		} else {
			url.searchParams.delete('vkbm_date');
		}

		window.history.pushState({}, '', url.toString());
	}

	function attachConfirmHandlers(root) {
		if (!window.fetch || typeof window.FormData === 'undefined') {
			return;
		}

		var settings = window.vkbmShiftDashboard || {};
		if (!settings.ajaxUrl) {
			return;
		}

		var buttons = toArray(root.querySelectorAll('.js-vkbm-notification-confirm'));
		if (!buttons.length) {
			return;
		}

		buttons.forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				if (button.disabled) {
					return;
				}

				var bookingId = button.getAttribute('data-booking-id');
				if (!bookingId) {
					return;
				}

				var defaultLabel = button.textContent;
				button.disabled = true;
				button.textContent =
					(settings.i18n && settings.i18n.confirming) || defaultLabel;

				var formData = new window.FormData();
				formData.append('action', 'vkbm_confirm_booking');
				formData.append('booking_id', bookingId);
				formData.append('nonce', settings.confirmNonce || '');

				window
					.fetch(settings.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData,
					})
					.then(function (response) {
						if (!response.ok) {
							throw new Error('http_error');
						}
						return response.json();
					})
					.then(function (payload) {
						if (payload && payload.success) {
							window.location.reload();
							return;
						}

						var message =
							(payload && payload.data && payload.data.message) ||
							(settings.i18n && settings.i18n.error) ||
							'';
						throw new Error(message || 'error');
					})
					.catch(function (error) {
						button.disabled = false;
						button.textContent = defaultLabel;

						var fallback =
							(settings.i18n && settings.i18n.error) ||
							'Failed to confirm booking.';
						window.alert(error && error.message ? error.message : fallback);
					});
			});
		});
	}

	function init() {
		var root = document.querySelector('.vkbm-shift-dashboard');
		if (!root) {
			return;
		}

		var buttons = toArray(root.querySelectorAll('[data-vkbm-view]'));
		var panels = toArray(root.querySelectorAll('[data-vkbm-view-panel]'));

		if (!buttons.length || !panels.length) {
			return;
		}

		var currentView = null;
		var defaultView = (function () {
			var activeButton = buttons.find(function (button) {
				return button.classList.contains('is-active');
			});

			if (activeButton) {
				return activeButton.getAttribute('data-vkbm-view');
			}

			return buttons.length ? buttons[0].getAttribute('data-vkbm-view') : '';
		})();

		function activateView(view, options) {
			if (!view || (view !== 'day' && view !== 'month')) {
				return;
			}

			currentView = view;
			toggleView(view, buttons, panels);

			if (!options || options.syncHistory !== false) {
				var dateValue = getDateForView(root, view);
				updateHistory(view, dateValue);
			}
		}

		buttons.forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();

				var targetView = button.getAttribute('data-vkbm-view');
				if (!targetView || targetView === currentView) {
					return;
				}

				activateView(targetView, { syncHistory: true });
			});
		});

		var viewFromUrl = readQueryParam('vkbm_view') || defaultView;

		activateView(viewFromUrl, { syncHistory: false });

		window.addEventListener('popstate', function () {
			var paramView = readQueryParam('vkbm_view') || defaultView;
			if (!paramView || paramView === currentView) {
				return;
			}

			activateView(paramView, { syncHistory: false });
		});

		attachConfirmHandlers(root);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
