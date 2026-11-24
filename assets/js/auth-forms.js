( () => {
	if ( window.vkbmAuthPasswordToggleInitialized ) {
		return;
	}
	window.vkbmAuthPasswordToggleInitialized = true;

	// 予約ブロックのログインカードは REST 応答後に挿入されるため、見出しが現れるまで待つ。
	if ( window.location.hash === '#vkbm-registration-sent' ) {
		// 宿泊の予約内容など、上にある要素は見出しより遅れて読み込まれ、ページが伸びて見出しが画面外へ押し出される。
		// 読み込みが落ち着くまでの数秒だけ合わせ直す（長くすると、読み終えて待っている間に画面が動くおそれがある）。
		const RECENTER_DURATION_MS = 3000;
		// お客様が自分で画面を動かしたとみなす操作。scroll はプログラムによるスクロールでも起きるため含めない。
		const USER_SCROLL_EVENTS = [
			'wheel',
			'touchmove',
			'keydown',
			'pointerdown',
		];

		// ページの高さが変わるたびに見出しを画面の中ほどへ合わせ直す。期間が過ぎるか、お客様が操作したらやめる。
		const keepSentNoticeCentered = ( heading ) => {
			// ResizeObserver が無い環境では最初の1回のスクロールだけにする。
			if ( typeof window.ResizeObserver !== 'function' ) {
				return;
			}
			const resizeObserver = new window.ResizeObserver( () => {
				heading.scrollIntoView( { block: 'center' } );
			} );
			const stop = () => {
				resizeObserver.disconnect();
				window.clearTimeout( stopTimer );
				USER_SCROLL_EVENTS.forEach( ( type ) =>
					window.removeEventListener( type, stop, true )
				);
			};
			resizeObserver.observe( document.body );
			const stopTimer = window.setTimeout( stop, RECENTER_DURATION_MS );
			USER_SCROLL_EVENTS.forEach( ( type ) =>
				window.addEventListener( type, stop, {
					capture: true,
					passive: true,
				} )
			);
		};

		const focusSentNotice = () => {
			const heading = document.getElementById( 'vkbm-registration-sent' );
			if ( ! heading ) {
				return false;
			}
			// フォーカスは1回だけ（読み上げを1回にするため、合わせ直しでは呼ばない）。
			heading.focus( { preventScroll: true } );
			// 画面の上端に置くと、スクロール後に現れるテーマの固定ヘッダーに覆われるため、
			// 見出しは画面の中ほどに置く（block: 'start' に戻さないこと）。
			heading.scrollIntoView( { block: 'center' } );
			keepSentNoticeCentered( heading );
			return true;
		};
		if ( ! focusSentNotice() ) {
			const observer = new MutationObserver( () => {
				if ( focusSentNotice() ) {
					observer.disconnect();
					window.clearTimeout( timeout );
				}
			} );
			observer.observe( document.documentElement, {
				childList: true,
				subtree: true,
			} );
			const timeout = window.setTimeout(
				() => observer.disconnect(),
				30000
			);
		}
	}

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest(
			'.vkbm-auth-form__password-toggle'
		);
		if ( ! button ) {
			return;
		}

		const fieldId = button.getAttribute( 'aria-controls' );
		const field = fieldId ? document.getElementById( fieldId ) : null;
		if ( ! field ) {
			return;
		}

		const isVisible = field.type === 'text';
		field.type = isVisible ? 'password' : 'text';
		button.setAttribute( 'aria-pressed', ( ! isVisible ).toString() );

		const label = button.querySelector(
			'.vkbm-auth-form__password-toggle-label'
		);
		if ( label ) {
			label.textContent = isVisible
				? button.getAttribute( 'data-show-label' )
				: button.getAttribute( 'data-hide-label' );
		}
	} );

	// issue #507 植草さんレビュー指摘: 認証メール再送フォームの二重送信ガード。
	// 送信ボタンを無効化するだけで、フォーム自体の送信は妨げない（ブラウザは
	// そのまま遷移するため、無効化された見た目のまま次のページへ進む）。
	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( '.vkbm-auth-form__resend' );
		if ( ! form ) {
			return;
		}

		if ( form.dataset.vkbmSubmitting === '1' ) {
			event.preventDefault();
			return;
		}

		form.dataset.vkbmSubmitting = '1';

		const button = form.querySelector( 'button[type="submit"]' );
		if ( button ) {
			button.disabled = true;
		}
	} );
} )();
