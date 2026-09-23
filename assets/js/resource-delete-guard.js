/**
 * リソース（スタッフ）削除ガードの管理画面用 JS（issue #262）。
 *
 * - ゴミ箱へ移動（個別・一括）: 対応中の予約があれば警告ダイアログを出す。実行自体は止めない
 *   （「続行してゴミ箱へ移動」で本来のリンク・フォーム送信をそのまま行う）。
 * - 完全に削除（個別）: 紐づく予約があればブロックダイアログを出し、そのリンクへは遷移させない
 *   （「それでも削除する」ボタンは置かない。サーバー側 pre_delete_post でも二重に防ぐ）。
 * - 一括の「ゴミ箱を空にする」・一括の完全削除は事前ダイアログでは介入しない
 *   （サーバー側で対象ごとにスキップし、実行後の通知でまとめて知らせる仕様のため）。
 *
 * ネイティブの confirm() は使わない（リンクを含められず、スクリーンリーダーへの伝達も弱いため）。
 * role="alertdialog"・フォーカストラップ・Esc で閉じられるダイアログを自前で構築する。
 */
( function () {
	'use strict';

	const config = window.vkbmResourceDeleteGuard || {};
	const i18n = config.i18n || {};

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
	 * post-95 のような tr[id] から投稿IDを取り出す。
	 *
	 * @param {Element} el 起点となる要素。
	 * @return {number} 投稿ID。見つからなければ0。
	 */
	function getPostIdFromElement( el ) {
		const row = el.closest( 'tr[id^="post-"]' );
		if ( ! row ) {
			return 0;
		}
		const match = /^post-(\d+)$/.exec( row.id );
		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	/**
	 * サーバーへ Ajax で対応中/紐づく予約の状況を問い合わせる。
	 *
	 * @param {number[]} ids 確認対象のリソース投稿ID一覧。
	 * @return {Promise<Array<Object>>} 各IDの判定結果
	 *   （id, name, activeCount, hasBookings, bookingsUrl（全ステータス）, activeBookingsUrl（対応中のみ））。
	 */
	function fetchCheck( ids ) {
		if ( ! config.ajaxUrl || ! window.fetch || ! ids.length ) {
			return Promise.resolve( [] );
		}

		const body = new URLSearchParams();
		body.set( 'action', config.action || 'vkbm_resource_delete_check' );
		body.set( 'nonce', config.nonce || '' );
		ids.forEach( function ( id ) {
			body.append( 'ids[]', String( id ) );
		} );

		return window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: body.toString(),
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP error ' + response.status );
				}
				return response.json();
			} )
			.then( function ( json ) {
				if (
					json &&
					json.success &&
					json.data &&
					Array.isArray( json.data.items )
				) {
					return json.data.items;
				}
				return [];
			} )
			.catch( function () {
				// 事前チェックに失敗した場合、ここで機能を止めない
				// （サーバー側 pre_trash_post / pre_delete_post が最終防衛線のため）。
				return [];
			} );
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
	 * @param {Array}     options.actions      ボタン定義 [{label, isPrimary, onClick}]。isPrimary
	 *                                         が false のボタン（無ければ最後のボタン）に初期フォーカスを当てる。
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

	/**
	 * 個別のゴミ箱移動: 警告ダイアログを表示し、続行なら元のリンクへ遷移する。
	 *
	 * @param {Element} triggerEl 起点要素。
	 * @param {Object}  item      Ajax 結果1件。
	 * @param {string}  href      元のリンク先。
	 */
	function showTrashWarningDialog( triggerEl, item, href ) {
		const body = [
			iconParagraph(
				( i18n.warningBodyCount || '' ).replace(
					'%d',
					String( item.activeCount )
				),
				'dashicons-warning'
			),
			paragraph( i18n.warningBodyDetail || '' ),
			paragraph( i18n.warningAlternative || '' ),
			paragraph( i18n.warningReassign || '' ),
			linkParagraph(
				item.activeBookingsUrl,
				i18n.checkBookingsLink || ''
			),
		];

		openAlertDialog( {
			title: i18n.warningTitle || '',
			bodyElements: body,
			triggerEl,
			actions: [
				{
					label: i18n.cancel || '',
					isPrimary: false,
					onClick: null,
				},
				{
					label: i18n.proceedTrash || '',
					isPrimary: true,
					onClick() {
						window.location.href = href;
					},
				},
			],
		} );
	}

	/**
	 * 個別の完全削除: ブロックダイアログを表示する（「それでも削除する」ボタンは置かない）。
	 *
	 * @param {Element} triggerEl 起点要素。
	 * @param {Object}  item      Ajax 結果1件。
	 */
	function showDeleteBlockedDialog( triggerEl, item ) {
		const body = [
			iconParagraph( i18n.blockBody || '', 'dashicons-dismiss' ),
			// 予約一覧の「予約ステータス」列はゴミ箱へ移しても書き換わらないため、リンク先に
			// ゴミ箱内の予約が元のステータス表示のまま混ざって並ぶことを別段落で明示する
			// （植草レビュー指摘・issue #262）。
			paragraph( i18n.blockBodyTrashNote || '' ),
			linkParagraph( item.bookingsUrl, i18n.checkLinkedLink || '' ),
		];

		openAlertDialog( {
			title: i18n.blockTitle || '',
			bodyElements: body,
			triggerEl,
			actions: [
				{
					label: i18n.close || '',
					isPrimary: true,
					onClick: null,
				},
			],
		} );
	}

	/**
	 * 一括のゴミ箱移動: 対象一覧をまとめた警告ダイアログを表示し、続行ならフォーム送信を継続する。
	 *
	 * @param {Element}  triggerEl 起点要素（Apply ボタン）。
	 * @param {Object[]} items     対応中の予約があるリソースの一覧。
	 * @param {Function} onProceed 「続行」時に呼ぶコールバック。
	 */
	function showBulkTrashWarningDialog( triggerEl, items, onProceed ) {
		const list = document.createElement( 'ul' );
		list.className = 'vkbm-guard-dialog__list';
		items.forEach( function ( item ) {
			const li = document.createElement( 'li' );
			li.textContent =
				item.name +
				' — ' +
				( i18n.warningBodyCount || '' ).replace(
					'%d',
					String( item.activeCount )
				);
			list.appendChild( li );
		} );

		const bodyWrap = document.createElement( 'div' );
		bodyWrap.appendChild( list );

		const title = ( i18n.bulkWarningTitleTemplate || '' ).replace(
			'%d',
			String( items.length )
		);

		const body = [
			iconParagraph( '', 'dashicons-warning' ),
			bodyWrap,
			paragraph( i18n.warningAlternative || '' ),
			paragraph( i18n.warningReassign || '' ),
		];

		openAlertDialog( {
			title,
			bodyElements: body,
			triggerEl,
			actions: [
				{
					label: i18n.cancel || '',
					isPrimary: false,
					onClick: null,
				},
				{
					label: i18n.proceedTrash || '',
					isPrimary: true,
					onClick: onProceed,
				},
			],
		} );
	}

	/**
	 * 一覧画面（trash/delete の行アクション）の初期化。
	 */
	function initListRowActions() {
		const list = document.getElementById( 'the-list' );
		if ( ! list ) {
			return;
		}

		list.addEventListener( 'click', function ( event ) {
			const trashLink = event.target.closest( '.row-actions .trash > a' );
			const deleteLink = event.target.closest(
				'.row-actions .delete > a'
			);

			if ( ! trashLink && ! deleteLink ) {
				return;
			}

			const link = trashLink || deleteLink;
			const postId = getPostIdFromElement( link );
			if ( ! postId ) {
				return;
			}

			event.preventDefault();
			const href = link.href;

			fetchCheck( [ postId ] ).then( function ( items ) {
				const item = items[ 0 ];
				if ( ! item ) {
					window.location.href = href;
					return;
				}

				if ( trashLink ) {
					if ( item.activeCount > 0 ) {
						showTrashWarningDialog( link, item, href );
					} else {
						window.location.href = href;
					}
					return;
				}

				if ( item.hasBookings ) {
					showDeleteBlockedDialog( link, item );
				} else {
					window.location.href = href;
				}
			} );
		} );
	}

	/**
	 * 単一編集画面（投稿ステータスボックスの「ゴミ箱へ移動」「完全に削除」）の初期化。
	 */
	function initSingleEditScreen() {
		const link = document.querySelector( '#submitdiv .submitdelete' );
		if ( ! link ) {
			return;
		}

		const postIdField = document.getElementById( 'post_ID' );
		const postId = postIdField ? parseInt( postIdField.value, 10 ) : 0;
		if ( ! postId ) {
			return;
		}

		const isDelete = /[?&]action=delete\b/.test( link.href );
		const isTrash = /[?&]action=trash\b/.test( link.href );
		if ( ! isDelete && ! isTrash ) {
			return;
		}

		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			const href = link.href;

			fetchCheck( [ postId ] ).then( function ( items ) {
				const item = items[ 0 ];
				if ( ! item ) {
					window.location.href = href;
					return;
				}

				if ( isTrash ) {
					if ( item.activeCount > 0 ) {
						showTrashWarningDialog( link, item, href );
					} else {
						window.location.href = href;
					}
					return;
				}

				if ( item.hasBookings ) {
					showDeleteBlockedDialog( link, item );
				} else {
					window.location.href = href;
				}
			} );
		} );
	}

	/**
	 * 一覧画面の一括「ゴミ箱へ移動」の初期化。
	 *
	 * 一括の完全削除・「ゴミ箱を空にする」は事前ダイアログでは介入しない
	 * （サーバー側の pre_delete_post がスキップし、実行後の通知でまとめて知らせる仕様のため）。
	 */
	function initBulkTrash() {
		const form = document.getElementById( 'posts-filter' );
		if ( ! form ) {
			return;
		}

		let bypassNextSubmit = false;

		form.addEventListener( 'submit', function ( event ) {
			if ( bypassNextSubmit ) {
				bypassNextSubmit = false;
				return;
			}

			// 「ゴミ箱を空にする」ボタン（name="delete_all2"）はサーバー側の事後通知に任せる。
			const submitter = event.submitter;
			if ( submitter && submitter.name === 'delete_all2' ) {
				return;
			}

			const top = document.getElementById( 'bulk-action-selector-top' );
			const bottom = document.getElementById(
				'bulk-action-selector-bottom'
			);
			const actionValue =
				top && top.value !== '-1'
					? top.value
					: bottom
					? bottom.value
					: '-1';

			if ( 'trash' !== actionValue ) {
				return;
			}

			const checked = Array.prototype.slice
				.call( form.querySelectorAll( 'input[name="post[]"]:checked' ) )
				.map( function ( input ) {
					return parseInt( input.value, 10 );
				} )
				.filter( Boolean );

			if ( ! checked.length ) {
				return;
			}

			event.preventDefault();

			fetchCheck( checked ).then( function ( items ) {
				const withBookings = items.filter( function ( item ) {
					return item.activeCount > 0;
				} );

				const resubmit = function () {
					bypassNextSubmit = true;
					if ( form.requestSubmit ) {
						form.requestSubmit( submitter || undefined );
					} else {
						form.submit();
					}
				};

				if ( ! withBookings.length ) {
					resubmit();
					return;
				}

				showBulkTrashWarningDialog(
					submitter || form,
					withBookings,
					resubmit
				);
			} );
		} );
	}

	function init() {
		initListRowActions();
		initSingleEditScreen();
		initBulkTrash();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
