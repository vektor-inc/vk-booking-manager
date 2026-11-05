import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { restoreCalendarFocus } from './calendar-focus';

/**
 * 回数制限と受付期間の案内を一か所に表示する。
 *
 * @param {Object}     props           表示状態。
 * @param {boolean}    props.busy      回数制限中。
 * @param {boolean}    props.outside   受付範囲外。
 * @param {boolean}    props.lastMonth 最後の月。
 * @param {Object}     props.range     受付境界。
 * @param {Object}     props.cursor    表示年月。
 * @param {boolean}    props.loading   再取得中。
 * @param {() => void} props.onRetry   手動の再取得。
 * @return {JSX.Element|null} 案内。
 */
export const AvailabilityNotice = ( {
	busy,
	outside,
	lastMonth,
	range,
	cursor,
	loading = false,
	onRetry,
} ) => {
	const noticeRef = useRef( null );
	const recoveryTargetRef = useRef( null );
	const retryButtonRef = useRef( null );
	useEffect( () => {
		if ( ! loading && recoveryTargetRef.current ) {
			// 通信中に別の入力へ移った場合は、その操作を優先する。
			if (
				! busy &&
				( document.activeElement === retryButtonRef.current ||
					document.activeElement === document.body )
			) {
				restoreCalendarFocus( recoveryTargetRef.current );
			}
			recoveryTargetRef.current = null;
		}
	}, [ busy, loading ] );
	// 関連する取得が全て完了するまで、再試行ボタンの DOM を保持する。
	const showRetry =
		busy || ( loading && Boolean( recoveryTargetRef.current ) );
	const hasRange =
		range?.first_date &&
		range?.last_date &&
		range.first_date <= range.last_date;
	const beforeRange =
		hasRange &&
		cursor &&
		`${ cursor.year }-${ String( cursor.month ).padStart( 2, '0' ) }` <
			range.first_date.slice( 0, 7 );
	if ( ! showRetry && ! outside && ! lastMonth ) {
		return null;
	}
	return (
		<div
			className="vkbm-alert vkbm-alert__warning vkbm-availability-notice"
			ref={ noticeRef }
			role={ ! outside && showRetry ? 'alert' : 'status' }
		>
			<p>
				{ ! outside && showRetry ? (
					<>
						{ __(
							'Availability is temporarily busy.',
							'vk-booking-manager'
						) }{ ' ' }
						{ __(
							'Your selections are kept.',
							'vk-booking-manager'
						) }{ ' ' }
						{ __(
							'Please wait a moment and try again.',
							'vk-booking-manager'
						) }
					</>
				) : outside ? (
					<>
						{ __(
							'This month is outside the booking period.',
							'vk-booking-manager'
						) }{ ' ' }
						{ hasRange &&
							( beforeRange
								? __(
										'Please choose a later month.',
										'vk-booking-manager'
								  )
								: __(
										'Please choose an earlier month.',
										'vk-booking-manager'
								  ) ) }
					</>
				) : (
					__(
						'This is the last month available for booking.',
						'vk-booking-manager'
					)
				) }
			</p>
			{ showRetry && ! outside && (
				<button
					ref={ retryButtonRef }
					type="button"
					className="vkbm-button vkbm-button__md vkbm-button-outline vkbm-button-outline__primary"
					aria-disabled={ loading }
					aria-busy={ loading }
					onClick={ ( event ) => {
						if ( loading ) {
							return;
						}
						retryButtonRef.current = event.currentTarget;
						recoveryTargetRef.current =
							noticeRef.current?.parentElement;
						onRetry();
					} }
				>
					{ loading
						? __( 'Loading…', 'vk-booking-manager' )
						: __( 'Try again', 'vk-booking-manager' ) }
				</button>
			) }
		</div>
	);
};

/**
 * サーバーの境界日と表示月を比較する。
 *
 * @param {Object} range  サーバーの受付範囲。
 * @param {Object} cursor 表示年月。
 * @return {boolean} 最後の月以降か。
 */
export const isLastBookingMonth = ( range, cursor ) =>
	Boolean(
		range?.last_date &&
			`${ cursor.year }-${ String( cursor.month ).padStart( 2, '0' ) }` >=
				range.last_date.slice( 0, 7 )
	);

/**
 * 受付開始日の月以前では前月へ移動させない。
 *
 * @param {Object} range  受付範囲。
 * @param {Object} cursor 表示年月。
 * @return {boolean} 最初の月以前か。
 */
export const isFirstBookingMonth = ( range, cursor ) =>
	Boolean(
		range?.first_date &&
			`${ cursor.year }-${ String( cursor.month ).padStart( 2, '0' ) }` <=
				range.first_date.slice( 0, 7 )
	);
