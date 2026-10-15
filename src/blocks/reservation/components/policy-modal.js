import { createPortal, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const FOCUSABLE_SELECTOR =
	'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

/**
 * 規約本文を表示するモーダル。
 * Esc で閉じる・開いたらモーダル内へフォーカス移動・Tab をモーダル内に留める・
 * 閉じたら開いたボタンへフォーカスを戻す・開いている間は背景のスクロールを止める。
 *
 * @param {Object}     props         プロパティ。
 * @param {string}     props.id      タイトル要素の id の元になる識別子。
 * @param {string}     props.title   モーダルの見出し。
 * @param {string}     props.body    本文（テキストとして表示する。HTML は解釈しない）。
 * @param {() => void} props.onClose 閉じるときに呼ぶ関数。
 * @return {Object} モーダルの React 要素。
 */
export const PolicyModal = ( { id, title, body, onClose } ) => {
	const dialogRef = useRef( null );
	const closeButtonRef = useRef( null );
	const onCloseRef = useRef( onClose );
	onCloseRef.current = onClose;
	const titleId = `vkbm-footer-modal-title-${ id }`;

	useEffect( () => {
		// 開く前にフォーカスのあった要素を覚え、閉じたときに戻す。
		const previouslyFocused = document.activeElement;
		const previousOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		if ( closeButtonRef.current ) {
			closeButtonRef.current.focus();
		}

		// キー操作はダイアログ内にフォーカスがあるときだけ処理し、ページ全体の Esc 処理などには干渉しない。
		const dialogElement = dialogRef.current;
		const handleKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				onCloseRef.current();
				return;
			}
			if ( event.key !== 'Tab' ) {
				return;
			}
			// Tab / Shift+Tab でモーダルの外へ出ないよう、先頭と末尾で循環させる。
			const focusable = Array.from(
				dialogRef.current.querySelectorAll( FOCUSABLE_SELECTOR )
			);
			if ( focusable.length === 0 ) {
				event.preventDefault();
				return;
			}
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const active = document.activeElement;
			const isOutside = active === dialogElement;
			if ( event.shiftKey && ( active === first || isOutside ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && ( active === last || isOutside ) ) {
				event.preventDefault();
				first.focus();
			}
		};
		dialogElement.addEventListener( 'keydown', handleKeyDown );

		return () => {
			dialogElement.removeEventListener( 'keydown', handleKeyDown );
			document.body.style.overflow = previousOverflow;
			if (
				previouslyFocused &&
				typeof previouslyFocused.focus === 'function'
			) {
				previouslyFocused.focus();
			}
		};
	}, [] );

	return createPortal(
		<div
			className="vkbm-footer-modal"
			role="presentation"
			onMouseDown={ ( event ) => {
				// 背景（オーバーレイ）を直接クリックしたときだけ閉じる。
				if ( event.target === event.currentTarget ) {
					onClose();
				}
			} }
		>
			<div
				className="vkbm-footer-modal__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				tabIndex={ -1 }
				ref={ dialogRef }
			>
				<div className="vkbm-footer-modal__header">
					<h2 className="vkbm-footer-modal__title" id={ titleId }>
						{ title }
					</h2>
					<button
						type="button"
						className="vkbm-footer-modal__close"
						onClick={ onClose }
						ref={ closeButtonRef }
						aria-label={ __( 'Close', 'vk-booking-manager' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				</div>
				<div className="vkbm-footer-modal__body">{ body }</div>
			</div>
		</div>,
		document.body
	);
};
