import { useEffect, useId, useRef } from '@wordpress/element';

/**
 * 予約ブロック内に表示するサービスメニューの詳細表示（#635）。
 *
 * 上から「メニュー名（h1）→ 本文 → 選択中サービスメニューのカード」の順に並べる。
 * 本文・カードは、サーバー側で組み立てた要素（readDetailSource で取り出したもの）を
 * そのまま差し込む。
 *
 * @param {Object}                  props
 * @param {string}                  props.title        サービスメニュー名。
 * @param {?Element}                props.contentNode  本文の要素。
 * @param {?Element}                props.editLinkNode 編集リンクの要素（権限がある場合のみ）。
 * @param {?Element}                props.cardNode     カードの要素。
 * @param {Object}                  props.headingRef   メニュー名見出しの ref（表示時のフォーカス先）。
 * @param {(event: Object) => void} props.onCardClick  カード内のクリックを受ける処理（予約に進むボタン用）。
 */
export const ServiceMenuDetail = ( {
	title,
	contentNode,
	cardNode,
	editLinkNode,
	headingRef,
	onCardClick,
} ) => {
	const headingId = useId();
	const editLinkHostRef = useRef( null );
	const contentHostRef = useRef( null );
	const cardHostRef = useRef( null );

	// 要素を差し込み、表示をやめるときは取り外す（再表示で同じ要素を戻せるようにする）。
	useEffect( () => {
		const host = contentHostRef.current;
		if ( ! host || ! contentNode ) {
			return undefined;
		}
		host.appendChild( contentNode );
		return () => {
			if ( contentNode.parentNode === host ) {
				host.removeChild( contentNode );
			}
		};
	}, [ contentNode ] );

	useEffect( () => {
		const host = cardHostRef.current;
		if ( ! host || ! cardNode ) {
			return undefined;
		}
		host.appendChild( cardNode );
		return () => {
			if ( cardNode.parentNode === host ) {
				host.removeChild( cardNode );
			}
		};
	}, [ cardNode ] );

	// 権限判定・翻訳済みのリンクを、見出しの読み上げに混ざらない位置へ差し込む。
	useEffect( () => {
		const host = editLinkHostRef.current;
		if ( ! host || ! editLinkNode ) {
			return undefined;
		}
		host.appendChild( editLinkNode );
		return () => {
			if ( editLinkNode.parentNode === host ) {
				host.removeChild( editLinkNode );
			}
		};
	}, [ editLinkNode ] );

	return (
		<section
			className="vkbm-reservation-detail"
			aria-labelledby={ headingId }
		>
			<div className="vkbm-reservation-detail__heading">
				<h1
					id={ headingId }
					className="vkbm-reservation-detail__title"
					tabIndex={ -1 }
					ref={ headingRef }
				>
					{ title }
				</h1>
				{ editLinkNode && (
					<div
						className="vkbm-reservation-detail__edit-link"
						ref={ editLinkHostRef }
					/>
				) }
			</div>
			<div
				className="vkbm-reservation-detail__content"
				ref={ contentHostRef }
			/>
			{ /* カード内のリンクのクリックを受けるだけ（キー操作は各リンク・ボタン自身が受ける）。 */ }
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events */ }
			<div
				className="vkbm-reservation-detail__card"
				ref={ cardHostRef }
				onClick={ onCardClick }
			/>
		</section>
	);
};
