import { useEffect, useRef } from '@wordpress/element';
import { restoreCalendarFocus } from './calendar-focus';

/**
 * 月送り後、無効になった操作元からカレンダーへフォーカスを戻す。
 *
 * @param {number}  year           表示年。
 * @param {number}  month          表示月。
 * @param {boolean} isPrevDisabled 前月へ移動できないか。
 * @param {boolean} isNextDisabled 翌月へ移動できないか。
 * @param {boolean} isLoading      空き状況の読み込み中か。
 * @return {Object} 操作元の参照。
 */
export const useCalendarNavigationFocus = (
	year,
	month,
	isPrevDisabled,
	isNextDisabled,
	isLoading
) => {
	const navigationRef = useRef( null );
	useEffect( () => {
		const button = navigationRef.current;
		// 応答前の仮の状態では、復帰先を決めない。
		if ( isLoading ) {
			return;
		}
		// 別の操作先へ移った利用者からフォーカスを奪わない。
		if (
			button?.disabled &&
			( document.activeElement === button ||
				document.activeElement === document.body )
		) {
			restoreCalendarFocus( button.closest( '.vkbm-calendar' ) );
		}
		// クリックでフォーカスされないブラウザでも、応答後の境界が確定するまで操作元を保持する。
		navigationRef.current = null;
	}, [ year, month, isPrevDisabled, isNextDisabled, isLoading ] );
	return navigationRef;
};
