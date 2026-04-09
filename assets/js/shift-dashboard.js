( function () {
	'use strict';

	function toArray( nodeList ) {
		return Array.prototype.slice.call( nodeList );
	}

	function readQueryParam( name ) {
		const query = window.location.search.substring( 1 );
		if ( ! query ) {
			return null;
		}

		return query.split( '&' ).reduce( function ( found, pair ) {
			if ( found !== null ) {
				return found;
			}

			const parts = pair.split( '=' );
			if ( parts[ 0 ] !== name ) {
				return null;
			}

			return parts[ 1 ] ? decodeURIComponent( parts[ 1 ] ) : '';
		}, null );
	}

	function normalizeMonthValue( value ) {
		if ( ! value ) {
			return '';
		}

		const parts = value.split( '-' );
		if ( parts.length !== 2 ) {
			return '';
		}

		const year = parts[ 0 ];
		let month = parts[ 1 ];

		if ( month.length === 1 ) {
			month = '0' + month;
		}

		return year + '-' + month + '-01';
	}

	function getDateForView( root, view ) {
		if ( view === 'day' ) {
			const dayHeader = root.querySelector( '.vkbm-day-view__header' );
			return dayHeader ? dayHeader.getAttribute( 'data-vkbm-date' ) : '';
		}

		if ( view === 'month' ) {
			const monthHeader = root.querySelector(
				'.vkbm-month-view__header'
			);
			if ( ! monthHeader ) {
				return '';
			}

			const monthValue = monthHeader.getAttribute( 'data-vkbm-month' );
			return normalizeMonthValue( monthValue );
		}

		return '';
	}

	function toggleView( viewKey, buttons, panels ) {
		buttons.forEach( function ( button ) {
			const isActive =
				button.getAttribute( 'data-vkbm-view' ) === viewKey;
			button.classList.toggle( 'is-active', isActive );
			button.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
		} );

		panels.forEach( function ( panel ) {
			const shouldShow =
				panel.getAttribute( 'data-vkbm-view-panel' ) === viewKey;
			panel.classList.toggle( 'is-active', shouldShow );
		} );
	}

	function updateHistory( view, dateValue ) {
		if (
			typeof window.URL === 'undefined' ||
			! window.history ||
			! window.history.pushState
		) {
			return;
		}

		const url = new window.URL( window.location.href );

		if ( view ) {
			url.searchParams.set( 'vkbm_view', view );
		} else {
			url.searchParams.delete( 'vkbm_view' );
		}

		if ( dateValue ) {
			url.searchParams.set( 'vkbm_date', dateValue );
		} else {
			url.searchParams.delete( 'vkbm_date' );
		}

		window.history.pushState( {}, '', url.toString() );
	}

	/**
	 * モーダル開閉処理を登録する / Attach modal open/close handlers for "+N" badges.
	 *
	 * @param {Element} root - ダッシュボードのルート要素 / Dashboard root element.
	 */
	function attachModalHandlers( root ) {
		var badges = toArray(
			root.querySelectorAll( '.js-vkbm-more-badge' )
		);

		if ( ! badges.length ) {
			return;
		}

		var lastFocusedElement = null;

		/**
		 * モーダルを閉じる / Close the given modal.
		 *
		 * @param {Element} modal - モーダル要素 / Modal element.
		 */
		function closeModal( modal ) {
			if ( ! modal ) {
				return;
			}
			modal.setAttribute( 'hidden', '' );
			document.body.style.overflow = '';

			// トリガー要素にフォーカスを戻す / Restore focus to trigger element.
			if ( lastFocusedElement ) {
				lastFocusedElement.focus();
				lastFocusedElement = null;
			}
		}

		/**
		 * モーダルを開く / Open the given modal.
		 *
		 * @param {Element} modal - モーダル要素 / Modal element.
		 * @param {Element} trigger - トリガー要素 / Trigger element.
		 */
		function openModal( modal, trigger ) {
			if ( ! modal ) {
				return;
			}
			lastFocusedElement = trigger || document.activeElement;

			// 親要素のスタッキングコンテキストから脱出するため body 直下に移動する.
			// Move modal to body so it escapes any parent stacking context.
			if ( modal.parentNode !== document.body ) {
				document.body.appendChild( modal );
			}

			modal.removeAttribute( 'hidden' );
			document.body.style.overflow = 'hidden';

			// 閉じるボタンにフォーカスを移す / Focus the close button.
			var closeBtn = modal.querySelector( '.vkbm-booking-modal__close' );
			if ( closeBtn ) {
				closeBtn.focus();
			}
		}

		// 「+N件」バッジのクリックでモーダルを開く / Open modal on badge click.
		badges.forEach( function ( badge ) {
			badge.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				var targetId = badge.getAttribute( 'data-vkbm-modal-target' );
				if ( ! targetId ) {
					return;
				}

				var modal = document.getElementById( targetId );
				openModal( modal, badge );
			} );
		} );

		// オーバーレイ・閉じるボタンでモーダルを閉じる / Close modal on overlay/close button click.
		var closeButtons = toArray(
			root.querySelectorAll( '.js-vkbm-modal-close' )
		);

		closeButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var modal = btn.closest( '.vkbm-booking-modal' );
				closeModal( modal );
			} );
		} );

		// Escキーでモーダルを閉じる（重複登録防止） / Close modal on Escape key (prevent duplicate).
		if ( ! root._vkbmEscapeListenerAttached ) {
			document.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Escape' && event.key !== 'Esc' ) {
					return;
				}

				// body 直下に移動済みのモーダルも検索するため document から取得する.
				// Search from document since modals are moved to body when opened.
				var openModals = toArray(
					document.querySelectorAll(
						'.vkbm-booking-modal:not([hidden])'
					)
				);

				openModals.forEach( function ( modal ) {
					closeModal( modal );
				} );
			} );
			root._vkbmEscapeListenerAttached = true;
		}
	}

	function attachConfirmHandlers( root ) {
		if ( ! window.fetch || typeof window.FormData === 'undefined' ) {
			return;
		}

		const settings = window.vkbmShiftDashboard || {};
		if ( ! settings.ajaxUrl ) {
			return;
		}

		const buttons = toArray(
			root.querySelectorAll( '.js-vkbm-notification-confirm' )
		);
		if ( ! buttons.length ) {
			return;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( button.disabled ) {
					return;
				}

				const bookingId = button.getAttribute( 'data-booking-id' );
				if ( ! bookingId ) {
					return;
				}

				const defaultLabel = button.textContent;
				button.disabled = true;
				button.textContent =
					( settings.i18n && settings.i18n.confirming ) ||
					defaultLabel;

				const formData = new window.FormData();
				formData.append( 'action', 'vkbm_confirm_booking' );
				formData.append( 'booking_id', bookingId );
				formData.append( 'nonce', settings.confirmNonce || '' );

				window
					.fetch( settings.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData,
					} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( 'http_error' );
						}
						return response.json();
					} )
					.then( function ( payload ) {
						if ( payload && payload.success ) {
							window.location.reload();
							return;
						}

						const message =
							( payload &&
								payload.data &&
								payload.data.message ) ||
							( settings.i18n && settings.i18n.error ) ||
							'';
						throw new Error( message || 'error' );
					} )
					.catch( function ( error ) {
						button.disabled = false;
						button.textContent = defaultLabel;

						const fallback =
							( settings.i18n && settings.i18n.error ) ||
							'Failed to confirm booking.';
						window.alert(
							error && error.message ? error.message : fallback
						);
					} );
			} );
		} );
	}

	function init() {
		const root = document.querySelector( '.vkbm-shift-dashboard' );
		if ( ! root ) {
			return;
		}

		const buttons = toArray( root.querySelectorAll( '[data-vkbm-view]' ) );
		const panels = toArray(
			root.querySelectorAll( '[data-vkbm-view-panel]' )
		);

		if ( ! buttons.length || ! panels.length ) {
			return;
		}

		let currentView = null;
		const defaultView = ( function () {
			const activeButton = buttons.find( function ( button ) {
				return button.classList.contains( 'is-active' );
			} );

			if ( activeButton ) {
				return activeButton.getAttribute( 'data-vkbm-view' );
			}

			return buttons.length
				? buttons[ 0 ].getAttribute( 'data-vkbm-view' )
				: '';
		} )();

		function activateView( view, options ) {
			if ( ! view || ( view !== 'day' && view !== 'month' ) ) {
				return;
			}

			currentView = view;
			toggleView( view, buttons, panels );

			if ( ! options || options.syncHistory !== false ) {
				const dateValue = getDateForView( root, view );
				updateHistory( view, dateValue );
			}
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				const targetView = button.getAttribute( 'data-vkbm-view' );
				if ( ! targetView || targetView === currentView ) {
					return;
				}

				activateView( targetView, { syncHistory: true } );
			} );
		} );

		const viewFromUrl = readQueryParam( 'vkbm_view' ) || defaultView;

		activateView( viewFromUrl, { syncHistory: false } );

		window.addEventListener( 'popstate', function () {
			const paramView = readQueryParam( 'vkbm_view' ) || defaultView;
			if ( ! paramView || paramView === currentView ) {
				return;
			}

			activateView( paramView, { syncHistory: false } );
		} );

		attachConfirmHandlers( root );
		attachModalHandlers( root );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
