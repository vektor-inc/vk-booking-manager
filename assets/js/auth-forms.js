( () => {
	if ( window.vkbmAuthPasswordToggleInitialized ) {
		return;
	}
	window.vkbmAuthPasswordToggleInitialized = true;

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
