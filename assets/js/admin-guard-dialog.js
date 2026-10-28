/**
 * 管理画面の確認ダイアログ（アラートダイアログ）の共通部品。
 *
 * 削除ガード（resource-delete-guard.js）や予約の個人情報の匿名化（booking-anonymize-bulk-action.js）など、
 * ネイティブの confirm() の代わりに同じ見た目・同じ操作で確認を出したい画面から使う。
 * 読み込むと window.vkbmGuardDialog に部品を公開する。
 *
 * ネイティブの confirm() は使わない（リンクを含められず、スクリーンリーダーへの伝達も弱いため）。
 * role="alertdialog"・フォーカストラップ・Esc で閉じられるダイアログを自前で構築する。
 */
( function () {
	'use strict';

	/**
	 * フォーカス可能な要素を取得する。
	 *
	 * @param {Element} root 探索対象のルート要素。
	 * @return {Element[]} フォーカス可能な要素一覧。
	 */
	function getFocusableElements( root ) {
		const selector =
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
		return Array.prototype.slice.call( root.querySelectorAll( selector ) );
	}

	/**
	 * アクセシブルなアラートダイアログを開く。
	 *
	 * role="alertdialog"・フォーカストラップ・Esc クローズ・トリガーへのフォーカス復帰に対応する。
	 * 初期フォーカスは、本文中のリンクなど「最初に見つかったフォーカス可能要素」には任せず、
	 * 常にキャンセル・閉じるなど安全側のボタンへ明示的に当てる（開いた直後に Enter を押しても
	 * 予約一覧などへ意図せず遷移しないようにするため。植草レビュー指摘・issue #262）。
	 *
	 * @param {Object}    options              ダイアログ設定。
	 * @param {string}    options.title        タイトル文言。
	 * @param {Element[]} options.bodyElements 本文として並べる要素一覧（段落を分けて色以外でも伝わるようにする）。
	 * @param {Array}     options.actions      ボタン定義 [{label, isPrimary, isDestructive, onClick}]。isPrimary
	 *                                         が false のボタン（無ければ最後のボタン）に初期フォーカスを当てる。
	 *                                         isDestructive が true のボタンは削除系の赤い見た目にする。
	 * @param {Element}   options.triggerEl    ダイアログを開いた起点要素（閉じたときにフォーカスを戻す）。
	 * @return {{close: Function}} 呼び出し側から閉じるための制御オブジェクト。
	 */
	function openAlertDialog( options ) {
		const overlay = document.createElement( 'div' );
		overlay.className = 'vkbm-guard-dialog__overlay';

		const dialog = document.createElement( 'div' );
		dialog.className = 'vkbm-guard-dialog';
		dialog.setAttribute( 'role', 'alertdialog' );
		dialog.setAttribute( 'aria-modal', 'true' );

		const titleId = 'vkbm-guard-dialog-title-' + Date.now();
		const heading = document.createElement( 'h2' );
		heading.className = 'vkbm-guard-dialog__title';
		heading.id = titleId;
		heading.textContent = options.title || '';
		dialog.setAttribute( 'aria-labelledby', titleId );

		// 本文（件数・代替行動などを書いた段落群）に id を振り、aria-describedby で
		// ダイアログへ紐づける。既定ではタイトルしか自動で読み上げられないため
		// （植草レビュー指摘・issue #262）。
		const body = document.createElement( 'div' );
		body.className = 'vkbm-guard-dialog__body';
		body.id = 'vkbm-guard-dialog-desc-' + Date.now();
		( options.bodyElements || [] ).forEach( function ( el ) {
			body.appendChild( el );
		} );
		dialog.setAttribute( 'aria-describedby', body.id );

		const footer = document.createElement( 'div' );
		footer.className = 'vkbm-guard-dialog__footer';

		const lastFocused = document.activeElement;

		/**
		 * ダイアログを閉じ、DOM から取り除き、フォーカスを起点要素へ戻す。
		 */
		function close() {
			document.removeEventListener( 'keydown', onKeydown, true );
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			const restoreTo = options.triggerEl || lastFocused;
			if ( restoreTo && typeof restoreTo.focus === 'function' ) {
				restoreTo.focus();
			}
		}

		// 初期フォーカス先のボタンを決める: isPrimary でないボタン（キャンセル・閉じる）を
		// 優先し、無ければ最後のボタン（例: ブロックダイアログの「閉じる」1つだけの場合）にする。
		const actions = options.actions || [];
		let initialFocusButton = null;

		actions.forEach( function ( action, index ) {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = action.isPrimary
				? 'button button-primary'
				: 'button';
			if ( action.isDestructive ) {
				button.classList.add( 'vkbm-guard-dialog__button--danger' );
			}
			button.textContent = action.label;
			button.addEventListener( 'click', function () {
				close();
				if ( typeof action.onClick === 'function' ) {
					action.onClick();
				}
			} );
			footer.appendChild( button );

			if ( ! action.isPrimary && ! initialFocusButton ) {
				initialFocusButton = button;
			}
			if ( ! initialFocusButton && index === actions.length - 1 ) {
				initialFocusButton = button;
			}
		} );

		dialog.appendChild( heading );
		dialog.appendChild( body );
		dialog.appendChild( footer );

		/**
		 * Tab キーでダイアログ内だけをループさせる（フォーカストラップ）。Esc でも閉じる。
		 *
		 * @param {KeyboardEvent} event キーイベント。
		 */
		function onKeydown( event ) {
			if ( event.key === 'Escape' || event.key === 'Esc' ) {
				event.preventDefault();
				close();
				return;
			}

			if ( event.key !== 'Tab' ) {
				return;
			}

			const focusable = getFocusableElements( dialog );
			if ( ! focusable.length ) {
				event.preventDefault();
				return;
			}

			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		// オーバーレイクリックはキャンセル相当（プライマリ以外のボタンと同じ挙動）として閉じるだけにする。
		overlay.addEventListener( 'click', function () {
			close();
		} );

		document.body.appendChild( overlay );
		document.body.appendChild( dialog );
		document.addEventListener( 'keydown', onKeydown, true );

		( initialFocusButton || dialog ).focus();

		return { close };
	}

	/**
	 * 段落 <p> 要素を作る簡易ヘルパー。
	 *
	 * @param {string} text  表示テキスト。
	 * @param {string} [cls] 追加クラス名。
	 * @return {HTMLParagraphElement} 生成した段落要素。
	 */
	function paragraph( text, cls ) {
		const p = document.createElement( 'p' );
		if ( cls ) {
			p.className = cls;
		}
		p.textContent = text;
		return p;
	}

	/**
	 * アイコン付きの見出し段落を作る（色だけで危険度を伝えないため）。
	 *
	 * @param {string} text      見出しテキスト。
	 * @param {string} iconClass dashicons のクラス名。
	 * @return {HTMLParagraphElement} 生成した段落要素。
	 */
	function iconParagraph( text, iconClass ) {
		const p = document.createElement( 'p' );
		p.className = 'vkbm-guard-dialog__lead';
		const icon = document.createElement( 'span' );
		icon.className = 'dashicons ' + iconClass;
		icon.setAttribute( 'aria-hidden', 'true' );
		p.appendChild( icon );
		p.appendChild( document.createTextNode( ' ' + text ) );
		return p;
	}

	/**
	 * リンク（行動を書いた文言）を含む段落を作る。
	 *
	 * @param {string} href リンク先。
	 * @param {string} text リンク文言。
	 * @return {HTMLParagraphElement} 生成した段落要素。
	 */
	function linkParagraph( href, text ) {
		const p = document.createElement( 'p' );
		const a = document.createElement( 'a' );
		a.href = href;
		a.textContent = text;
		p.appendChild( a );
		return p;
	}

	window.vkbmGuardDialog = {
		openAlertDialog,
		paragraph,
		iconParagraph,
		linkParagraph,
	};
} )();
