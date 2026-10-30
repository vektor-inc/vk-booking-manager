import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { GuestVerifyBookingSummary } from './guest-verify-result';

/** キャンセル用リンクを開いたときの状態（Booking_Customer_Cancellation の STATE_* と Guest_Cancel_Controller::VIEW_INVALID）。 */
export const GUEST_CANCEL_STATE = {
	CAN_CANCEL: 'can_cancel',
	CANCELLED: 'cancelled',
	CANCEL_DISABLED: 'cancel_disabled',
	DEADLINE_PASSED: 'cancel_deadline_passed',
	INVALID_BOOKING: 'invalid_booking',
	INVALID: 'invalid',
};

/** 画面の見た目の種類（既存のアラートの色だけを使い、新しい色は増やさない）。 */
const TONE = {
	INFO: 'info',
	SUCCESS: 'success',
	WARNING: 'warning',
};

/** キャンセルを実行した後の「キャンセルしました」の状態。サーバーの状態とは別に、画面だけで持つ。 */
const DONE = 'done';

/**
 * 状態から、画面に出す見出し・本文・色・ボタンの種類を決める。
 *
 * 文言（キャンセル不可・締め切り超過など）は、サーバーがマイページと同じものを返す。
 * キャンセルできないときは、電話での連絡を案内する。
 *
 * @param {string} state                 画面の状態（GUEST_CANCEL_STATE のいずれか、または DONE）。
 * @param {Object} options               追加の値。
 * @param {string} options.message       サーバーが返した文言（キャンセルできない理由）。
 * @param {string} options.providerPhone 店舗の電話番号（無ければ空）。
 * @return {{tone: string, title: string, messages: string[], phone?: string, showSummary: boolean, showCancelButton: boolean, showPageButton: boolean}} 画面の内容。
 */
export const getGuestCancelView = (
	state,
	{ message = '', providerPhone = '' } = {}
) => {
	switch ( state ) {
		case GUEST_CANCEL_STATE.CAN_CANCEL:
			return {
				tone: TONE.INFO,
				title: __( 'Cancel this reservation?', 'vk-booking-manager' ),
				messages: [
					__(
						'You are about to cancel the reservation below. Pressing the button cannot be undone.',
						'vk-booking-manager'
					),
					__(
						'If you do not want to cancel, close this page without pressing anything.',
						'vk-booking-manager'
					),
				],
				showSummary: true,
				showCancelButton: true,
				showPageButton: false,
			};

		case DONE:
			return {
				tone: TONE.SUCCESS,
				title: __(
					'Your reservation has been cancelled',
					'vk-booking-manager'
				),
				messages: [
					__(
						'We will email you to confirm the cancellation. You can close this page.',
						'vk-booking-manager'
					),
				],
				showSummary: true,
				showCancelButton: false,
				showPageButton: true,
			};

		case GUEST_CANCEL_STATE.CANCELLED:
			return {
				tone: TONE.WARNING,
				title: __(
					'This reservation has already been cancelled',
					'vk-booking-manager'
				),
				messages: [],
				showSummary: false,
				showCancelButton: false,
				showPageButton: true,
			};

		case GUEST_CANCEL_STATE.CANCEL_DISABLED:
		case GUEST_CANCEL_STATE.DEADLINE_PASSED:
		case GUEST_CANCEL_STATE.INVALID_BOOKING:
			return {
				tone: TONE.WARNING,
				title: __(
					'This reservation cannot be cancelled on this page',
					'vk-booking-manager'
				),
				messages: [
					message,
					providerPhone
						? __( 'Please call us.', 'vk-booking-manager' )
						: __(
								'Please contact us directly.',
								'vk-booking-manager'
						  ),
				].filter( Boolean ),
				phone: providerPhone,
				showSummary: true,
				showCancelButton: false,
				showPageButton: true,
			};

		case GUEST_CANCEL_STATE.INVALID:
		default:
			return {
				tone: TONE.WARNING,
				title: __(
					'This cancellation link cannot be used',
					'vk-booking-manager'
				),
				messages: [
					__(
						'A link for a reservation that has already been cancelled can no longer be used.',
						'vk-booking-manager'
					),
					__(
						'Otherwise, please open the link in the email exactly as it is written, without cutting it off.',
						'vk-booking-manager'
					),
					providerPhone
						? __(
								'If you cannot open it, please call us.',
								'vk-booking-manager'
						  )
						: '',
				].filter( Boolean ),
				phone: providerPhone,
				showSummary: false,
				showCancelButton: false,
				showPageButton: true,
			};
	}
};

/**
 * ゲスト予約のキャンセル用リンクを開いた後の画面（予約ブロックの中に表示する）。
 *
 * リンクを開いただけではキャンセルしない。「この予約をキャンセルする」ボタンを押したときに、
 * トークンと予約 ID を送ってキャンセルする。表示が変わったときにフォーカスを移して読み上げさせるため、
 * コンテナは tabIndex={ -1 } とし role="status" は付けない（確認リンクの結果画面と同じ）。
 *
 * @param {Object} props                    プロパティ。
 * @param {Object} props.view               サーバーから渡された内容（state・message・bookingId・booking・providerPhone）。
 * @param {string} props.token              リンクに入っていたトークン（URL から外す前に読んだ値）。
 * @param {string} props.reservationPageUrl 「予約ページへ」のリンク先。
 * @param {string} props.resourceLabel      担当の見出し。
 * @param {string} props.guestsLabel        人数の見出し。
 * @param {string} props.guestsUnitLabel    人数の単位。
 * @param {Object} props.containerRef       フォーカス制御用に呼び出し側が保持する ref。
 * @return {JSX.Element} キャンセル用リンクの画面。
 */
export const GuestCancel = ( {
	view,
	token = '',
	reservationPageUrl = '',
	resourceLabel = '',
	guestsLabel = '',
	guestsUnitLabel = '',
	containerRef = null,
} ) => {
	const [ state, setState ] = useState( view?.state || '' );
	const [ message, setMessage ] = useState( view?.message || '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	const content = getGuestCancelView( state, {
		message,
		providerPhone: view?.providerPhone || '',
	} );

	// 初回表示は呼び出し側がフォーカスを移す。ボタンを押した後に状態が変わったときは、押したボタンが消えて
	// フォーカスを失うため、状態の種類に関わらず見出しの枠へフォーカスを移して読み上げさせる。
	const previousState = useRef( state );
	useEffect( () => {
		if ( previousState.current !== state ) {
			previousState.current = state;
			containerRef?.current?.focus();
		}
	}, [ state, containerRef ] );

	const handleCancel = () => {
		if ( isSubmitting || ! token || ! view?.bookingId ) {
			return;
		}

		setIsSubmitting( true );
		setErrorMessage( '' );

		apiFetch( {
			path: `/vkbm/v1/guest-bookings/${ Number(
				view.bookingId
			) }/cancel`,
			method: 'POST',
			data: { token },
		} )
			.then( () => {
				setState( DONE );
			} )
			.catch( ( error ) => {
				// 画面を開いた後に締め切りを過ぎた・リンクが使えなくなった場合は、その状態の画面へ切り替える。
				if (
					error?.code === 'cancel_disabled' ||
					error?.code === 'cancel_deadline_passed'
				) {
					setMessage( error.message || '' );
					setState(
						error.code === 'cancel_disabled'
							? GUEST_CANCEL_STATE.CANCEL_DISABLED
							: GUEST_CANCEL_STATE.DEADLINE_PASSED
					);
				} else if ( error?.code === 'guest_cancel_invalid' ) {
					setState( GUEST_CANCEL_STATE.INVALID );
				} else {
					setErrorMessage(
						__(
							'We could not cancel the reservation. Please wait a moment and try again.',
							'vk-booking-manager'
						)
					);
				}
			} )
			.finally( () => {
				setIsSubmitting( false );
			} );
	};

	return (
		<>
			<div
				className={ `vkbm-alert vkbm-alert__${ content.tone } vkbm-confirm__complete vkbm-confirm__guest-result vkbm-confirm__guest-cancel` }
				ref={ containerRef }
				tabIndex={ -1 }
				data-vkbm-guest-cancel={ state }
			>
				<h3 className="vkbm-confirm__complete-title">
					{ content.title }
				</h3>
				{ content.messages.map( ( text ) => (
					<p key={ text } className="vkbm-confirm__complete-message">
						{ text }
					</p>
				) ) }
				{ content.phone && (
					<p className="vkbm-confirm__complete-message vkbm-confirm__guest-cancel-tel">
						<a
							href={ `tel:${ content.phone.replace(
								/[^0-9+]/g,
								''
							) }` }
						>
							{ sprintf(
								/* translators: %s: Provider phone number. */
								__( 'TEL: %s', 'vk-booking-manager' ),
								content.phone
							) }
						</a>
					</p>
				) }
			</div>
			{ content.showSummary && (
				<GuestVerifyBookingSummary
					booking={ view?.booking }
					resourceLabel={ resourceLabel }
					guestsLabel={ guestsLabel }
					guestsUnitLabel={ guestsUnitLabel }
				/>
			) }
			{ errorMessage && (
				<div
					className="vkbm-alert vkbm-alert__danger vkbm-confirm__guest-cancel-error"
					role="alert"
				>
					{ errorMessage }
				</div>
			) }
			{ ( content.showCancelButton ||
				( content.showPageButton && reservationPageUrl ) ) && (
				<div className="vkbm-buttons vkbm-buttons__center vkbm-confirm__guest-actions">
					{ content.showCancelButton && (
						<button
							type="button"
							className="vkbm-button vkbm-button__md vkbm-button__danger"
							onClick={ handleCancel }
							aria-disabled={ isSubmitting }
						>
							{ isSubmitting
								? __( 'Cancelling…', 'vk-booking-manager' )
								: __(
										'Cancel this reservation',
										'vk-booking-manager'
								  ) }
						</button>
					) }
					{ content.showPageButton && reservationPageUrl && (
						<a
							className="vkbm-button vkbm-button__md vkbm-button__primary"
							href={ reservationPageUrl }
						>
							{ __(
								'Go to the reservation page',
								'vk-booking-manager'
							) }
						</a>
					) }
				</div>
			) }
		</>
	);
};
