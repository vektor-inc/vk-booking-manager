/**
 * 予約完了画面の通知ブロック（見出し＋任意の本文）。
 *
 * 即時確定（本文なし）と仮予約（本文あり）で器を共通化している。
 * 本文は React のテキストとして描画する（HTML は解釈しない）。改行は CSS（white-space: pre-line）で保持する。
 * 完了時にフォーカスを移して読み上げさせるため、コンテナは tabIndex={ -1 } とし role="status" は付けない
 * （フォーカス移動と live region の二重読み上げを避ける）。
 *
 * @param {Object} props
 * @param {string} props.title        見出し。
 * @param {string} props.message      本文。空のときは本文の段落を出さない。
 * @param {Object} props.containerRef フォーカス制御用に呼び出し側が保持する ref。
 */
export const BookingCompleteNotice = ( {
	title,
	message = '',
	containerRef = null,
} ) => (
	<div
		className="vkbm-alert vkbm-alert__success vkbm-confirm__complete"
		ref={ containerRef }
		tabIndex={ -1 }
	>
		<h3 className="vkbm-confirm__complete-title">{ title }</h3>
		{ message && (
			<p className="vkbm-confirm__complete-message">{ message }</p>
		) }
	</div>
);
