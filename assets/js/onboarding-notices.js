(function () {
	function findClosest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches(selector)) return el;
			el = el.parentElement;
		}
		return null;
	}

	function init() {
		var root = document;
		root.addEventListener('click', function (e) {
			var target = e.target;
			if (!target) return;
			var dismissBtn = target.closest
				? target.closest('.vkbm-notice__dismiss')
				: findClosest(target, '.vkbm-notice__dismiss');
			if (!dismissBtn) return;

			var notice = dismissBtn.closest
				? dismissBtn.closest('.vkbm-notice')
				: findClosest(dismissBtn, '.vkbm-notice');
			if (!notice) return;

			var noticeId = notice.getAttribute('data-vkbm-notice-id') || '';
			if (!noticeId) {
				notice.remove();
				return;
			}

			var isGlobal = notice.classList.contains('is-dismissible-all');
			var scope = isGlobal ? 'global' : 'user';

			if (
				!window.vkbmOnboardingNotices ||
				!window.vkbmOnboardingNotices.ajaxUrl ||
				!window.vkbmOnboardingNotices.nonce
			) {
				notice.remove();
				return;
			}

			var body = new URLSearchParams();
			body.set('action', 'vkbm_dismiss_notice');
			body.set('nonce', window.vkbmOnboardingNotices.nonce);
			body.set('notice_id', noticeId);
			body.set('scope', scope);

			fetch(window.vkbmOnboardingNotices.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: body.toString(),
			})
				.then(function () {
					notice.remove();
				})
				.catch(function () {
					notice.remove();
				});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();


