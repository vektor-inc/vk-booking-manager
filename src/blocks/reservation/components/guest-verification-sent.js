import { __, sprintf } from '@wordpress/i18n';
import { formatDate } from '../format-wp-date';

/**
 * 申し込み後に出す「確認メールをお送りしました」の画面。
 *
 * 予約はまだ作られていない。完了と誤認させないよう、緑（success）ではなく案内用の色（info）で出す。
 * 表示したときにフォーカスを移して読み上げさせるため、コンテナは tabIndex={ -1 } とし role="status" は付けない
 * （BookingCompleteNotice と同じ。フォーカス移動と live region の二重読み上げを避ける）。
 *
 * @param {Object}   props              プロパティ。
 * @param {string}   props.email        確認メールの宛先。
 * @param {string}   props.expiresAt    確認リンクの有効期限（ISO 8601）。
 * @param {Object}   props.containerRef フォーカス制御用に呼び出し側が保持する ref。
 * @param {Function} props.onResend     「メールアドレスを修正して送り直す」を押したときの処理。
 * @return {JSX.Element} 送信後の画面。
 */
export const GuestVerificationSent = ( {
	email,
	expiresAt,
	containerRef = null,
	onResend,
} ) => {
	const expiresLabel = expiresAt ? formatDate( expiresAt, '' ) : '';

	return (
		<div
			className="vkbm-alert vkbm-alert__info vkbm-confirm__complete vkbm-confirm__guest-sent"
			ref={ containerRef }
			tabIndex={ -1 }
		>
			<h3 className="vkbm-confirm__complete-title">
				{ __(
					'We have sent you a confirmation email',
					'vk-booking-manager'
				) }
			</h3>
			<p className="vkbm-confirm__complete-message">
				{ sprintf(
					/* translators: %s: email address the confirmation email was sent to. */
					__( 'Sent to: %s', 'vk-booking-manager' ),
					email
				) }
			</p>
			{ expiresLabel && (
				<p className="vkbm-confirm__complete-message">
					{ sprintf(
						/* translators: %s: date and time until which the confirmation link is valid. */
						__( 'Valid until: %s', 'vk-booking-manager' ),
						expiresLabel
					) }
				</p>
			) }
			<p className="vkbm-confirm__complete-message">
				{ __(
					'Your reservation is not confirmed until you open the link.',
					'vk-booking-manager'
				) }
			</p>
			<h4 className="vkbm-confirm__guest-sent-subtitle">
				{ __( 'If the email does not arrive', 'vk-booking-manager' ) }
			</h4>
			<p className="vkbm-confirm__complete-message">
				{ __( 'Please check your spam folder.', 'vk-booking-manager' ) }
			</p>
			<div className="vkbm-buttons vkbm-buttons__center">
				<button
					type="button"
					className="vkbm-button vkbm-button__md vkbm-button-outline vkbm-button-outline__primary"
					onClick={ onResend }
				>
					{ __(
						'Correct the email address and send again',
						'vk-booking-manager'
					) }
				</button>
			</div>
		</div>
	);
};
