/*
 * サービスメニュー編集画面のサブ画像欄（#623）。
 *
 * 画像の追加（メディア選択画面）・削除・並び替え（ドラッグ／前後ボタン）・すべて削除と元に戻すを扱い、
 * 並び順どおりの添付IDを hidden 欄に「12,34,56」の形式で保持する。
 */
( function ( $ ) {
	'use strict';

	const i18n =
		window.vkbmServiceMenuGallery && window.vkbmServiceMenuGallery.i18n
			? window.vkbmServiceMenuGallery.i18n
			: {};

	/**
	 * "%d" や "%1$d" を値に置き換える。
	 *
	 * @param {string}    template 置換前の文言。
	 * @param {...number} values   差し込む値（先頭から順に %1$d, %2$d, ...）。
	 * @return {string} 置換後の文言。
	 */
	function format( template, ...values ) {
		let sequential = 0;
		return String( template || '' ).replace(
			/%(?:(\d+)\$)?d/g,
			function ( match, index ) {
				const value = index
					? values[ Number( index ) - 1 ]
					: values[ sequential++ ];
				return String( value );
			}
		);
	}

	function speak( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, 'polite' );
		}
	}

	function init( root ) {
		const list = root.querySelector( '.vkbm-gallery__list' );
		const input = root.querySelector( '.vkbm-gallery__input' );
		const addButton = root.querySelector( '.vkbm-gallery__add' );
		const count = root.querySelector( '.vkbm-gallery__count' );
		const clearButton = root.querySelector( '.vkbm-gallery__clear' );
		const undoNotice = root.querySelector( '.vkbm-gallery__undo-notice' );
		const undoButton = root.querySelector( '.vkbm-gallery__undo' );
		const status = root.querySelector( '.vkbm-gallery__status' );
		if ( ! list || ! input ) {
			return;
		}

		// 状態は { id, src } の配列。初期値はサーバーが描画した項目から読む。
		let items = Array.prototype.map.call(
			list.querySelectorAll( '.vkbm-gallery__item' ),
			function ( node ) {
				const image = node.querySelector( 'img' );
				return {
					id: Number( node.dataset.id ),
					src: image ? image.getAttribute( 'src' ) : '',
				};
			}
		);
		let undoItems = null;
		let frame = null;

		function setStatus( message ) {
			status.textContent = message;
		}

		function hideUndo() {
			undoItems = null;
			undoNotice.hidden = true;
		}

		function createMoveButton( kind, position, disabled ) {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className =
				'vkbm-gallery__move-button vkbm-gallery__move-' + kind;
			button.title =
				kind === 'earlier' ? i18n.moveEarlier : i18n.moveLater;
			button.setAttribute(
				'aria-label',
				format(
					kind === 'earlier'
						? i18n.moveEarlierAria
						: i18n.moveLaterAria,
					position
				)
			);
			button.disabled = disabled;
			const arrow = document.createElement( 'span' );
			arrow.setAttribute( 'aria-hidden', 'true' );
			arrow.textContent = kind === 'earlier' ? '←' : '→';
			button.appendChild( arrow );
			return button;
		}

		function createItem( item, index ) {
			const position = index + 1;
			const li = document.createElement( 'li' );
			li.className = 'vkbm-gallery__item';
			li.dataset.id = String( item.id );

			const thumb = document.createElement( 'div' );
			thumb.className = 'vkbm-gallery__thumb';
			const image = document.createElement( 'img' );
			image.className = 'vkbm-gallery__image';
			image.alt = '';
			image.src = item.src;
			image.width = 96;
			image.height = 96;
			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'vkbm-gallery__remove';
			remove.setAttribute(
				'aria-label',
				format( i18n.removeAria, position )
			);
			remove.textContent = '×';
			thumb.appendChild( image );
			thumb.appendChild( remove );

			const move = document.createElement( 'div' );
			move.className = 'vkbm-gallery__move';
			move.appendChild(
				createMoveButton( 'earlier', position, index === 0 )
			);
			move.appendChild(
				createMoveButton(
					'later',
					position,
					index === items.length - 1
				)
			);

			li.appendChild( thumb );
			li.appendChild( move );
			return li;
		}

		// 状態から一覧・件数・hidden 欄・「すべて削除」の表示を作り直す。
		function render() {
			list.textContent = '';
			items.forEach( function ( item, index ) {
				list.appendChild( createItem( item, index ) );
			} );
			input.value = items
				.map( function ( item ) {
					return item.id;
				} )
				.join( ',' );
			count.textContent = format(
				items.length === 1 ? i18n.countSingular : i18n.countPlural,
				items.length
			);
			clearButton.hidden = items.length < 2;
		}

		function indexOfItemNode( node ) {
			const li = node.closest( '.vkbm-gallery__item' );
			return li ? Array.prototype.indexOf.call( list.children, li ) : -1;
		}

		function moveItem( from, to ) {
			const moved = items.splice( from, 1 )[ 0 ];
			items.splice( to, 0, moved );
		}

		// 移動後は移動先の同じボタンへフォーカスを残す（端で押せなくなったら反対側のボタンへ）。
		function focusMoveButton( index, kind ) {
			const li = list.children[ index ];
			if ( ! li ) {
				return;
			}
			let button = li.querySelector( '.vkbm-gallery__move-' + kind );
			if ( button.disabled ) {
				button = li.querySelector(
					'.vkbm-gallery__move-' +
						( kind === 'earlier' ? 'later' : 'earlier' )
				);
			}
			button.focus();
		}

		list.addEventListener( 'click', function ( event ) {
			const target = event.target.closest( 'button' );
			if ( ! target ) {
				return;
			}
			const index = indexOfItemNode( target );
			if ( index < 0 ) {
				return;
			}

			hideUndo();
			setStatus( '' );

			if ( target.classList.contains( 'vkbm-gallery__remove' ) ) {
				items.splice( index, 1 );
				render();
				speak( i18n.removedAnnounce );
				// 削除後は次の画像、無ければ追加ボタンへフォーカスを移す。
				const next =
					list.children[ Math.min( index, items.length - 1 ) ];
				const nextRemove = next
					? next.querySelector( '.vkbm-gallery__remove' )
					: null;
				( nextRemove || addButton ).focus();
				return;
			}

			const isEarlier = target.classList.contains(
				'vkbm-gallery__move-earlier'
			);
			const isLater = target.classList.contains(
				'vkbm-gallery__move-later'
			);
			if ( ! isEarlier && ! isLater ) {
				return;
			}
			const destination = isEarlier ? index - 1 : index + 1;
			if ( destination < 0 || destination >= items.length ) {
				return;
			}
			moveItem( index, destination );
			render();
			focusMoveButton( destination, isEarlier ? 'earlier' : 'later' );
			speak(
				format( i18n.movedAnnounce, destination + 1, items.length )
			);
		} );

		// ドラッグでの並び替え。終了後に DOM の並びを状態へ反映して作り直す。
		let dragStartIndex = -1;
		$( list ).sortable( {
			items: '> .vkbm-gallery__item',
			cancel: 'button',
			tolerance: 'pointer',
			start( event, ui ) {
				dragStartIndex = ui.item.index();
			},
			stop( event, ui ) {
				const dragEndIndex = ui.item.index();
				const orderedIds = Array.prototype.map.call(
					list.children,
					function ( node ) {
						return Number( node.dataset.id );
					}
				);
				items = orderedIds.map( function ( id ) {
					return items.filter( function ( item ) {
						return item.id === id;
					} )[ 0 ];
				} );
				hideUndo();
				setStatus( '' );
				render();
				if ( dragEndIndex !== dragStartIndex ) {
					speak(
						format(
							i18n.movedAnnounce,
							dragEndIndex + 1,
							items.length
						)
					);
				}
			},
		} );

		clearButton.addEventListener( 'click', function () {
			undoItems = items;
			items = [];
			setStatus( '' );
			render();
			undoNotice.hidden = false;
			speak( i18n.cleared );
			undoButton.focus();
		} );

		undoButton.addEventListener( 'click', function () {
			if ( ! undoItems ) {
				return;
			}
			items = undoItems;
			hideUndo();
			render();
			speak( i18n.restoredAnnounce );
			addButton.focus();
		} );

		function addAttachments( attachments ) {
			let skipped = 0;
			attachments.forEach( function ( attachment ) {
				const id = Number( attachment.id );
				const exists = items.some( function ( item ) {
					return item.id === id;
				} );
				if ( exists ) {
					skipped++;
					return;
				}
				const sizes = attachment.sizes || {};
				items.push( {
					id,
					src: sizes.thumbnail ? sizes.thumbnail.url : attachment.url,
				} );
			} );
			hideUndo();
			render();
			setStatus(
				skipped > 0
					? format(
							skipped === 1
								? i18n.skippedSingular
								: i18n.skippedPlural,
							skipped
					  )
					: ''
			);
		}

		addButton.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: i18n.mediaTitle,
					button: { text: i18n.mediaButton },
					library: { type: 'image' },
					multiple: 'add',
				} );
				// multiple: 'add' は、追加せずに閉じた（Esc など）選択が次に開いたときも残るため、開くたびに空にする。
				frame.on( 'open', function () {
					frame.state().get( 'selection' ).reset();
				} );
				frame.on( 'select', function () {
					addAttachments( frame.state().get( 'selection' ).toJSON() );
				} );
			}
			frame.open();
		} );

		render();
	}

	$( function () {
		document
			.querySelectorAll( '[data-vkbm-gallery]' )
			.forEach( function ( root ) {
				init( root );
			} );
	} );
} )( jQuery );
