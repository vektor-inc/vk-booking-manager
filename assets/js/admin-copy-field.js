/**
 * 管理画面の読み取り専用欄の値を、ボタンでクリップボードへコピーする（#1154）。
 *
 * ボタンに次の属性を付ける。
 * - data-vkbm-copy-target: コピーする欄（input）の id
 * - data-vkbm-copied-message: コピーできたときに読み上げる文言
 * - data-vkbm-copy-failed-message: コピーできなかったときに読み上げる文言
 * 結果の文言は、ボタンと同じまとまり（.vkbm-copy-field）の中の .vkbm-copy-field__status（aria-live）に書く。
 * ブラウザでは読み込み時に document へクリックの受け口を付け、Node（Jest）では module.exports へ公開する。
 *
 * @param {Window|Object} root グローバルオブジェクト。
 */
( function ( root ) {
	/**
	 * 欄の値をクリップボードへ書き込む。
	 *
	 * Clipboard API が使えない・拒否されたとき（http の管理画面など）は、欄を選択して execCommand( 'copy' ) を試す。
	 *
	 * @param {HTMLInputElement} input     コピーする欄。
	 * @param {Object}           clipboard navigator.clipboard（無ければ undefined）。
	 * @param {Document}         doc       欄がある document。
	 * @return {Promise<boolean>} コピーできたら true。
	 */
	function copyInputValue( input, clipboard, doc ) {
		const fallback = function () {
			input.focus();
			input.select();
			try {
				return Boolean( doc.execCommand && doc.execCommand( 'copy' ) );
			} catch ( error ) {
				return false;
			}
		};

		if ( clipboard && typeof clipboard.writeText === 'function' ) {
			return clipboard.writeText( input.value ).then(
				function () {
					return true;
				},
				function () {
					return fallback();
				}
			);
		}

		return Promise.resolve( fallback() );
	}

	/**
	 * コピーボタンの押下を受け、結果を読み上げ用の欄に書く。
	 *
	 * @param {HTMLElement} button    押されたボタン。
	 * @param {Object}      clipboard navigator.clipboard（無ければ undefined）。
	 * @param {Document}    doc       ボタンがある document。
	 * @return {Promise<boolean>} コピーできたら true（コピー先の欄が無いときは false）。
	 */
	function handleCopyButton( button, clipboard, doc ) {
		const input = doc.getElementById(
			button.getAttribute( 'data-vkbm-copy-target' ) || ''
		);
		const field = button.closest( '.vkbm-copy-field' );
		const status = field
			? field.querySelector( '.vkbm-copy-field__status' )
			: null;

		if ( ! input ) {
			return Promise.resolve( false );
		}

		// 続けて押したときも同じ文言を読み上げるよう、いったん空にしてから書き直す。
		if ( status ) {
			status.textContent = '';
		}

		return copyInputValue( input, clipboard, doc ).then( function ( ok ) {
			if ( status ) {
				status.textContent =
					button.getAttribute(
						ok
							? 'data-vkbm-copied-message'
							: 'data-vkbm-copy-failed-message'
					) || '';
			}
			return ok;
		} );
	}

	/**
	 * document にクリックの受け口を 1 つだけ付ける。
	 *
	 * @param {Document} doc 対象の document。
	 */
	function init( doc ) {
		doc.addEventListener( 'click', function ( event ) {
			const button =
				event.target && event.target.closest
					? event.target.closest( '[data-vkbm-copy-target]' )
					: null;
			if ( ! button ) {
				return;
			}

			event.preventDefault();
			handleCopyButton(
				button,
				root.navigator ? root.navigator.clipboard : undefined,
				doc
			);
		} );
	}

	const api = { copyInputValue, handleCopyButton, init };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	} else if ( root.document ) {
		init( root.document );
	}
} )( typeof window !== 'undefined' ? window : this ); // eslint-disable-line no-invalid-this
