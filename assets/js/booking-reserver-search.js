/**
 * 予約編集画面の「予約者」欄。
 *
 * 初期表示は現在の予約者だけ。「予約者を変更」でユーザー検索パネルを開き、
 * 候補から選ぶと hidden（vkbm_booking[author_id]）の値と表示を差し替える。
 * 値は保存ボタンを押すまで確定しない。
 */
( function () {
	'use strict';

	// 入力が止まってから自動検索するまでの待ち時間（ミリ秒）。
	const SEARCH_DELAY = 300;
	// 自動検索を始める最小文字数。1文字のときは Enter か「検索」ボタンで検索する。
	const AUTO_SEARCH_MIN_LENGTH = 2;
	// 「予約者の変更を取り消しました」を画面に出しておく時間（ミリ秒）。
	const NOTICE_DURATION = 5000;

	/**
	 * `%s` `%d` `%1$s` 形式のプレースホルダーを値で置き換える。
	 *
	 * @param {string}                 template 文言。
	 * @param {Array<string | number>} values   置き換える値（先頭から順に）。
	 * @return {string} 置き換え後の文言。
	 */
	function format( template, values ) {
		let index = 0;
		return template.replace(
			/%(?:(\d+)\$)?[sd]/g,
			function ( match, position ) {
				const value = position
					? values[ Number( position ) - 1 ]
					: values[ index++ ];
				return undefined === value ? '' : String( value );
			}
		);
	}

	/**
	 * 予約者欄ひとつ分の動作を組み立てる。
	 *
	 * @param {HTMLElement} root   data-vkbm-reserver を持つ要素。
	 * @param {Object}      config vkbmBookingAdmin.reserver（Ajax 設定と文言）。
	 */
	function setup( root, config ) {
		const i18n = config.i18n || {};
		const valueField = root.querySelector( '[data-vkbm-reserver-value]' );
		const nameEl = root.querySelector( '[data-vkbm-reserver-name]' );
		const emailEl = root.querySelector( '[data-vkbm-reserver-email]' );
		const changeButton = root.querySelector(
			'[data-vkbm-reserver-change]'
		);
		const restoreButton = root.querySelector(
			'[data-vkbm-reserver-restore]'
		);
		const noticeEl = root.querySelector( '[data-vkbm-reserver-notice]' );
		const panel = root.querySelector( '[data-vkbm-reserver-panel]' );
		const input = root.querySelector( '[data-vkbm-reserver-input]' );
		const searchButton = root.querySelector(
			'[data-vkbm-reserver-search]'
		);
		const closeButton = root.querySelector( '[data-vkbm-reserver-close]' );
		const statusText = root.querySelector(
			'[data-vkbm-reserver-status-text]'
		);
		const spinner = root.querySelector( '.spinner' );
		const resultsEl = root.querySelector( '[data-vkbm-reserver-results]' );
		const savedActions = root.querySelectorAll(
			'[data-vkbm-reserver-saved-action]'
		);

		// 検索権限が無いときはパネルが出力されないため、何もしない（予約者は据え置きで保存できる）。
		if (
			! valueField ||
			! nameEl ||
			! changeButton ||
			! panel ||
			! input ||
			! resultsEl ||
			! window.fetch
		) {
			return;
		}

		const original = {
			id: root.dataset.originalId || '0',
			label: root.dataset.originalLabel || '',
			email: root.dataset.originalEmail || '',
		};

		let searchTimer = null;
		let isComposing = false;
		let latestRequestId = 0;
		let abortController = null;
		let noticeTimer = null;

		// JavaScript が動くときだけ「変更」ボタンを出す。
		changeButton.hidden = false;

		/**
		 * 状態表示（検索中・件数・エラー）を更新する。
		 *
		 * @param {string}  message  表示する文言。空文字で消す。
		 * @param {boolean} isActive 検索中のスピナーを回すか。
		 */
		function setStatus( message, isActive ) {
			statusText.textContent = message;
			if ( spinner ) {
				spinner.classList.toggle( 'is-active', !! isActive );
			}
		}

		function clearResults() {
			while ( resultsEl.firstChild ) {
				resultsEl.removeChild( resultsEl.firstChild );
			}
		}

		function isPanelOpen() {
			return ! panel.hidden;
		}

		function openPanel() {
			panel.hidden = false;
			changeButton.setAttribute( 'aria-expanded', 'true' );
			input.focus();
		}

		/**
		 * パネルを閉じる。選択済みの内容は変えない。
		 *
		 * @param {boolean} shouldFocusButton 「変更」ボタンへフォーカスを戻すか。
		 */
		function closePanel( shouldFocusButton ) {
			panel.hidden = true;
			changeButton.setAttribute( 'aria-expanded', 'false' );
			cancelPendingSearch();
			if ( shouldFocusButton ) {
				changeButton.focus();
			}
		}

		function cancelPendingSearch() {
			window.clearTimeout( searchTimer );
			latestRequestId++;
			if ( abortController ) {
				abortController.abort();
				abortController = null;
			}
			setStatus( '', false );
		}

		/**
		 * 表示と hidden の値を指定のユーザーに差し替える。
		 *
		 * @param {{id: string, label: string, email: string}} user 表示するユーザー。
		 */
		function applyReserver( user ) {
			valueField.value = user.id;
			nameEl.textContent = user.label;
			if ( emailEl ) {
				emailEl.textContent = user.email;
				emailEl.hidden = '' === user.email;
			}
			changeButton.textContent =
				Number( user.id ) > 0
					? changeButton.dataset.changeLabel
					: changeButton.dataset.selectLabel;
		}

		/**
		 * 未保存の変更があるかに応じて、保存済みの人向けのボタンと「元に戻す」を切り替える。
		 *
		 * @param {boolean} hasChange 未保存の変更があるか。
		 */
		function setChangedState( hasChange ) {
			Array.prototype.forEach.call( savedActions, function ( element ) {
				element.hidden = hasChange;
			} );
			if ( restoreButton ) {
				restoreButton.hidden = ! hasChange;
			}
		}

		function selectUser( user ) {
			closePanel( false );

			// 選び終えたので、次に開いたときに前の検索が残らないようにする。
			input.value = '';
			clearResults();
			window.clearTimeout( noticeTimer );

			// 元の予約者を選び直したときは、変更なしの状態に戻す。
			if ( String( user.id ) === original.id ) {
				restore( false );
				changeButton.focus();
				return;
			}

			applyReserver( {
				id: String( user.id ),
				label: user.label,
				email: user.email,
			} );
			setChangedState( true );

			if ( Number( original.id ) > 0 ) {
				noticeEl.textContent = format( i18n.changeNotice || '', [
					original.label,
					user.label,
				] );
			} else {
				noticeEl.textContent = format( i18n.setNotice || '', [
					user.label,
				] );
			}
			changeButton.focus();
		}

		/**
		 * 元の予約者に戻す。
		 *
		 * @param {boolean} shouldAnnounce 「取り消しました」を知らせるか。
		 */
		function restore( shouldAnnounce ) {
			applyReserver( original );
			setChangedState( false );
			window.clearTimeout( noticeTimer );
			noticeEl.textContent = shouldAnnounce
				? i18n.restoreNotice || ''
				: '';

			// 「取り消しました」は読み上げた後も画面に残さないよう、数秒後に消す。
			if ( shouldAnnounce ) {
				noticeTimer = window.setTimeout( function () {
					noticeEl.textContent = '';
				}, NOTICE_DURATION );
			}
		}

		function renderResults( data ) {
			clearResults();
			const users = data && Array.isArray( data.users ) ? data.users : [];

			if ( 0 === users.length ) {
				setStatus( i18n.notFound || '', false );
				return;
			}

			users.forEach( function ( user ) {
				const item = document.createElement( 'li' );
				const button = document.createElement( 'button' );
				const nameLine = document.createElement( 'span' );
				const emailLine = document.createElement( 'span' );

				button.type = 'button';
				button.className = 'vkbm-reserver__option';
				nameLine.className = 'vkbm-reserver__option-name';
				nameLine.textContent = user.label;
				if ( String( user.id ) === valueField.value ) {
					const badge = document.createElement( 'span' );
					badge.className = 'vkbm-reserver__option-badge';
					// 読み上げで氏名とつながらないよう、空白と括弧で区切る。
					badge.textContent =
						' (' + ( i18n.currentReserver || '' ) + ')';
					nameLine.appendChild( badge );
				}
				emailLine.className = 'vkbm-reserver__option-email';
				emailLine.textContent = user.email;

				button.appendChild( nameLine );
				button.appendChild( emailLine );
				button.addEventListener( 'click', function () {
					selectUser( user );
				} );
				item.appendChild( button );
				resultsEl.appendChild( item );
			} );

			if ( data.has_more ) {
				setStatus(
					format( i18n.tooMany || '', [ config.limit ] ),
					false
				);
				return;
			}

			setStatus(
				format(
					1 === users.length
						? i18n.foundSingular || ''
						: i18n.foundPlural || '',
					[ users.length ]
				),
				false
			);
		}

		function runSearch() {
			window.clearTimeout( searchTimer );
			const keyword = input.value.trim();

			// 古い応答を採用しないよう、直近の検索だけを有効にする。
			const requestId = ++latestRequestId;
			if ( abortController ) {
				abortController.abort();
				abortController = null;
			}
			clearResults();

			if ( '' === keyword ) {
				setStatus( '', false );
				return;
			}

			setStatus( i18n.searching || '', true );

			const body = new URLSearchParams();
			body.set( 'action', config.action );
			body.set( 'nonce', config.nonce );
			body.set( 'keyword', keyword );

			abortController = window.AbortController
				? new window.AbortController()
				: null;

			window
				.fetch( window.vkbmBookingAdmin.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: body.toString(),
					signal: abortController
						? abortController.signal
						: undefined,
				} )
				.then( function ( response ) {
					if ( requestId !== latestRequestId ) {
						return null;
					}
					// 未ログイン時の admin-ajax は 400（本文 0）を返すため、400 も含める。
					if (
						400 === response.status ||
						401 === response.status ||
						403 === response.status
					) {
						setStatus( i18n.sessionExpired || '', false );
						return null;
					}
					if ( ! response.ok ) {
						throw new Error( 'HTTP error ' + response.status );
					}
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json || requestId !== latestRequestId ) {
						return;
					}
					if ( ! json.success ) {
						throw new Error( 'Search failed' );
					}
					renderResults( json.data );
				} )
				.catch( function ( error ) {
					// 取り消した検索（新しい検索に置き換えた）はエラー扱いにしない。
					if (
						requestId !== latestRequestId ||
						( error && 'AbortError' === error.name )
					) {
						return;
					}
					setStatus( i18n.error || '', false );
				} );
		}

		function scheduleSearch() {
			window.clearTimeout( searchTimer );
			const length = input.value.trim().length;

			if ( 0 === length ) {
				cancelPendingSearch();
				clearResults();
				return;
			}
			if ( length < AUTO_SEARCH_MIN_LENGTH ) {
				// 今の入力と合わない前の候補を選べないよう、候補と状態表示を消す。
				cancelPendingSearch();
				clearResults();
				return;
			}
			searchTimer = window.setTimeout( runSearch, SEARCH_DELAY );
		}

		function isImeKeydown( event ) {
			return event.isComposing || 229 === event.keyCode;
		}

		function getOptionButtons() {
			return resultsEl.querySelectorAll( '.vkbm-reserver__option' );
		}

		changeButton.addEventListener( 'click', function () {
			if ( isPanelOpen() ) {
				closePanel( true );
				return;
			}
			openPanel();
		} );

		closeButton.addEventListener( 'click', function () {
			closePanel( true );
		} );

		searchButton.addEventListener( 'click', runSearch );

		if ( restoreButton ) {
			restoreButton.addEventListener( 'click', function () {
				restore( true );
				changeButton.focus();
			} );
		}

		// IME で変換している間は検索せず、確定してから検索する。
		input.addEventListener( 'compositionstart', function () {
			isComposing = true;
			window.clearTimeout( searchTimer );
		} );
		input.addEventListener( 'compositionend', function () {
			isComposing = false;
			scheduleSearch();
		} );
		input.addEventListener( 'input', function () {
			if ( isComposing ) {
				return;
			}
			scheduleSearch();
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				// 投稿フォームの送信を防ぐ。変換確定の Enter では検索しない。
				event.preventDefault();
				if ( ! isImeKeydown( event ) ) {
					runSearch();
				}
				return;
			}
			if ( 'ArrowDown' === event.key ) {
				const first = getOptionButtons()[ 0 ];
				if ( first ) {
					event.preventDefault();
					first.focus();
				}
			}
		} );

		resultsEl.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key ) {
				return;
			}
			const buttons = Array.prototype.slice.call( getOptionButtons() );
			const index = buttons.indexOf( document.activeElement );
			if ( index < 0 ) {
				return;
			}
			event.preventDefault();
			if ( 'ArrowDown' === event.key ) {
				if ( buttons[ index + 1 ] ) {
					buttons[ index + 1 ].focus();
				}
				return;
			}
			if ( 0 === index ) {
				input.focus();
				return;
			}
			buttons[ index - 1 ].focus();
		} );

		// Esc でパネルを閉じて「変更」ボタンへ戻る。
		panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! isImeKeydown( event ) ) {
				event.preventDefault();
				event.stopPropagation();
				closePanel( true );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const adminConfig = window.vkbmBookingAdmin;
		if ( ! adminConfig || ! adminConfig.reserver ) {
			return;
		}
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-vkbm-reserver]' ),
			function ( root ) {
				setup( root, adminConfig.reserver );
			}
		);
	} );
} )();
