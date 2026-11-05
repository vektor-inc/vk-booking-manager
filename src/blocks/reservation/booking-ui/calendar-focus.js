/**
 * 操作できる日付、月送り、月見出しの順でフォーカスを戻す。
 *
 * @param {Element|null} container カレンダーを含む要素。
 */
export const restoreCalendarFocus = ( container ) => {
	// ボタンが全て無効でも、月見出しに操作位置を残す。
	const target =
		container?.querySelector(
			'button.vkbm-calendar__day:not(:disabled):not([aria-disabled="true"])'
		) ||
		container?.querySelector( '.vkbm-calendar__nav:not(:disabled)' ) ||
		container?.querySelector( '.vkbm-calendar__current' );
	target?.focus();
};
