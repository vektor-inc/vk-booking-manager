/**
 * メニューカードのサブ画像を <dialog> で拡大表示する（#623）。
 *
 * 予約ブロックは REST で取得したカードの HTML を後から差し込むため、
 * クリックは document への委譲で拾い、dialog は初回クリック時に1つだけ作る。
 */
( function () {
	'use strict';

	// 同じページで二重に読み込まれても、リスナーを重複させない。
	if ( window.vkbmMenuGalleryReady ) {
		return;
	}
	window.vkbmMenuGalleryReady = true;

	const BUTTON_SELECTOR = '.vkbm-menu-loop__gallery-button';
	const i18n =
		window.vkbmMenuGallery && window.vkbmMenuGallery.i18n
			? window.vkbmMenuGallery.i18n
			: {};

	let dialog = null;
	let closeButton = null;
	let previousButton = null;
	let nextButton = null;
	let image = null;
	let caption = null;
	let position = null;

	let buttons = [];
	let currentIndex = 0;
	let trigger = null;
	let previousOverflow = '';

	function formatPosition( current, total ) {
		return String( i18n.position || '%1$d / %2$d' )
			.replace( '%1$d', String( current ) )
			.replace( '%2$d', String( total ) );
	}

	function createButton( className, label, text ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = className;
		button.setAttribute( 'aria-label', label );
		const icon = document.createElement( 'span' );
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = text;
		button.appendChild( icon );
		return button;
	}

	// 表示している写真を切り替え、端のボタンを押せなくする。
	function show( index ) {
		const source = buttons[ index ];
		if ( ! source ) {
			return;
		}
		currentIndex = index;

		const srcset = source.dataset.vkbmGallerySrcset || '';
		image.alt = source.dataset.vkbmGalleryAlt || '';
		if ( srcset ) {
			image.sizes = '90vw';
			image.srcset = srcset;
		} else {
			image.removeAttribute( 'srcset' );
			image.removeAttribute( 'sizes' );
		}
		image.src = source.dataset.vkbmGallerySrc || '';

		const captionText = source.dataset.vkbmGalleryCaption || '';
		caption.textContent = captionText;
		caption.hidden = '' === captionText;

		position.textContent = formatPosition( index + 1, buttons.length );
		previousButton.disabled = 0 === index;
		nextButton.disabled = index === buttons.length - 1;
	}

	// 端に着いてボタンが押せなくなったときにフォーカスが失われないよう、反対側のボタンへ移す。
	function step( direction ) {
		const next = currentIndex + direction;
		if ( next < 0 || next >= buttons.length ) {
			return;
		}
		show( next );
		const active = document.activeElement;
		if ( active && active.disabled ) {
			( direction > 0 ? previousButton : nextButton ).focus();
		}
	}

	function createDialog() {
		dialog = document.createElement( 'dialog' );
		dialog.className = 'vkbm-gallery-dialog';

		const inner = document.createElement( 'div' );
		inner.className = 'vkbm-gallery-dialog__inner';

		closeButton = createButton(
			'vkbm-gallery-dialog__close',
			i18n.close || 'Close',
			'×'
		);

		const figure = document.createElement( 'figure' );
		figure.className = 'vkbm-gallery-dialog__figure';
		image = document.createElement( 'img' );
		image.className = 'vkbm-gallery-dialog__image';
		caption = document.createElement( 'figcaption' );
		caption.className = 'vkbm-gallery-dialog__caption';
		figure.appendChild( image );
		figure.appendChild( caption );

		const nav = document.createElement( 'div' );
		nav.className = 'vkbm-gallery-dialog__nav';
		previousButton = createButton(
			'vkbm-gallery-dialog__step vkbm-gallery-dialog__previous',
			i18n.previous || 'Previous photo',
			'‹'
		);
		nextButton = createButton(
			'vkbm-gallery-dialog__step vkbm-gallery-dialog__next',
			i18n.next || 'Next photo',
			'›'
		);
		position = document.createElement( 'span' );
		position.className = 'vkbm-gallery-dialog__position';
		position.setAttribute( 'aria-live', 'polite' );
		nav.appendChild( previousButton );
		nav.appendChild( position );
		nav.appendChild( nextButton );

		inner.appendChild( closeButton );
		inner.appendChild( figure );
		inner.appendChild( nav );
		dialog.appendChild( inner );
		document.body.appendChild( dialog );

		closeButton.addEventListener( 'click', function () {
			dialog.close();
		} );
		previousButton.addEventListener( 'click', function () {
			step( -1 );
		} );
		nextButton.addEventListener( 'click', function () {
			step( 1 );
		} );

		// 背景（::backdrop）のクリックは dialog 自身へのクリックとして届く。
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );

		dialog.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				step( -1 );
			} else if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				step( 1 );
			}
		} );

		// Esc・×・背景クリックのどれで閉じても、背景のスクロールを戻し、押したサムネイルへフォーカスを返す。
		dialog.addEventListener( 'close', function () {
			document.documentElement.style.overflow = previousOverflow;
			if ( trigger && document.contains( trigger ) ) {
				trigger.focus();
			}
			trigger = null;
		} );
	}

	function open( button ) {
		const group = button.closest( '.vkbm-menu-loop__gallery' );
		if ( ! group ) {
			return;
		}

		if ( ! dialog ) {
			createDialog();
		}

		buttons = Array.prototype.slice.call(
			group.querySelectorAll( BUTTON_SELECTOR )
		);
		trigger = button;
		dialog.setAttribute(
			'aria-label',
			group.dataset.vkbmGalleryLabel || i18n.dialogLabel || ''
		);
		show( buttons.indexOf( button ) );

		// 開けたことを確かめてから、背景のスクロールを止める。
		dialog.showModal();
		previousOverflow = document.documentElement.style.overflow;
		document.documentElement.style.overflow = 'hidden';
		closeButton.focus();
	}

	document.addEventListener( 'click', function ( event ) {
		const target = event.target;
		if ( ! target || 'function' !== typeof target.closest ) {
			return;
		}

		const button = target.closest( BUTTON_SELECTOR );
		if ( ! button ) {
			return;
		}

		// ブロックエディターのプレビュー内では開かない。
		if (
			button.closest(
				'.editor-styles-wrapper, .block-editor-block-list__layout'
			)
		) {
			return;
		}

		open( button );
	} );
} )();
