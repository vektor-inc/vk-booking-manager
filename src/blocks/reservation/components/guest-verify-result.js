import { __ } from '@wordpress/i18n';
import { formatBookingDateTimeParts } from '../format-wp-date';
import { formatGuestsCount } from '../../shared/guests';

/** 確認リンクを開いた結果（Guest_Booking_Controller の RESULT_*）。 */
export const GUEST_VERIFY_RESULT = {
	CONFIRMED: 'confirmed',
	PENDING: 'pending',
	BOOKING_CLOSED: 'booking_closed',
	EXPIRED: 'expired',
	SLOT_UNAVAILABLE: 'slot_unavailable',
	REJECTED: 'rejected',
	UNAVAILABLE: 'unavailable',
	PROCESSING: 'processing',
	INVALID: 'invalid',
};

/**
 * 結果の見た目の種類（既存のアラートの色だけを使い、新しい色は増やさない）。
 * 確定・仮予約は緑、期限切れ・枠が埋まっていた・処理中などは注意色、予約できなかったは赤系。
 */
const TONE = {
	SUCCESS: 'success',
	WARNING: 'warning',
	DANGER: 'danger',
};

/**
 * 結果の種類から、画面に出す見出し・本文・色・ボタンの文言を決める。
 *
 * rejected（顧客フラグ・重複予約など）は、サーバーが返す理由を使わず固定の文言にする。
 * 他人のメールアドレス・電話番号を入れて、フラグの有無や予約の有無を探れないようにするため。
 *
 * @param {Object} result                 サーバーから渡された結果（result・repeat）。
 * @param {Object} options                追加の値。
 * @param {string} options.pendingMessage 仮予約の完了画面の案内文（基本設定の実効値）。
 * @return {{tone: string, title: string, messages: string[], buttonLabel: string, showSummary: boolean}} 画面の内容。
 */
export const getGuestVerifyResultView = (
	result,
	{ pendingMessage = '' } = {}
) => {
	const repeatMessage = result?.repeat
		? __( 'This link has already been confirmed.', 'vk-booking-manager' )
		: '';
	const toPageLabel = __(
		'Go to the reservation page',
		'vk-booking-manager'
	);

	switch ( result?.result ) {
		case GUEST_VERIFY_RESULT.CONFIRMED:
			return {
				tone: TONE.SUCCESS,
				title: __(
					'Your reservation is confirmed',
					'vk-booking-manager'
				),
				messages: [ repeatMessage ].filter( Boolean ),
				buttonLabel: '',
				showSummary: true,
			};

		case GUEST_VERIFY_RESULT.PENDING:
			return {
				tone: TONE.SUCCESS,
				title: __(
					'Your tentative reservation has been received',
					'vk-booking-manager'
				),
				messages: [
					repeatMessage,
					pendingMessage ||
						__(
							'We will check the details and email you once your reservation is confirmed. Please wait for that email. You can close this page.',
							'vk-booking-manager'
						),
				].filter( Boolean ),
				buttonLabel: '',
				showSummary: true,
			};

		case GUEST_VERIFY_RESULT.EXPIRED:
			return {
				tone: TONE.WARNING,
				title: __(
					'The confirmation link has expired',
					'vk-booking-manager'
				),
				messages: [
					__(
						'Sorry for the trouble, but please start the reservation again from the beginning.',
						'vk-booking-manager'
					),
				],
				buttonLabel: __(
					'Start the reservation again',
					'vk-booking-manager'
				),
				showSummary: false,
			};

		case GUEST_VERIFY_RESULT.SLOT_UNAVAILABLE:
			return {
				tone: TONE.WARNING,
				title: __(
					'Another reservation was made for this date and time first',
					'vk-booking-manager'
				),
				messages: [
					__(
						'Sorry for the trouble, but please choose another date and time and make the reservation again.',
						'vk-booking-manager'
					),
				],
				buttonLabel: __(
					'Make a reservation for another date and time',
					'vk-booking-manager'
				),
				showSummary: false,
			};

		case GUEST_VERIFY_RESULT.PROCESSING:
			return {
				tone: TONE.WARNING,
				title: __(
					'Your reservation is being processed',
					'vk-booking-manager'
				),
				messages: [
					__(
						'Please wait a moment, then open the link in the email again.',
						'vk-booking-manager'
					),
				],
				buttonLabel: toPageLabel,
				showSummary: false,
			};

		case GUEST_VERIFY_RESULT.UNAVAILABLE:
			return {
				tone: TONE.WARNING,
				title: __(
					'Reservations without registration are not accepted right now',
					'vk-booking-manager'
				),
				messages: [
					__(
						'Please log in or sign up on the reservation page to make a reservation.',
						'vk-booking-manager'
					),
				],
				buttonLabel: toPageLabel,
				showSummary: false,
			};

		case GUEST_VERIFY_RESULT.BOOKING_CLOSED:
			return {
				tone: TONE.WARNING,
				title: __(
					'This reservation is not active',
					'vk-booking-manager'
				),
				messages: [
					repeatMessage,
					__(
						'It has already been cancelled or otherwise closed.',
						'vk-booking-manager'
					),
				].filter( Boolean ),
				buttonLabel: toPageLabel,
				showSummary: false,
			};

		case GUEST_VERIFY_RESULT.REJECTED:
			return {
				tone: TONE.DANGER,
				title: __(
					'We could not accept your reservation',
					'vk-booking-manager'
				),
				messages: [
					`${ __(
						'This reservation cannot be accepted online.',
						'vk-booking-manager'
					) } ${ __(
						'Please contact the store directly.',
						'vk-booking-manager'
					) }`,
				],
				buttonLabel: toPageLabel,
				showSummary: false,
			};

		case GUEST_VERIFY_RESULT.INVALID:
		default:
			return {
				tone: TONE.WARNING,
				title: __(
					'This confirmation link is not valid',
					'vk-booking-manager'
				),
				messages: [
					__(
						'Please open the link in the email exactly as it is written.',
						'vk-booking-manager'
					),
				],
				buttonLabel: toPageLabel,
				showSummary: false,
			};
	}
};

/**
 * 結果画面に出す予約内容（日時・メニュー・担当・人数）。確認画面のまとめと同じ見た目にする。
 *
 * @param {Object} props                 プロパティ。
 * @param {Object} props.booking         サーバーから渡された予約内容（startAt・endAt・timezone・menuName・resourceName・guests）。
 * @param {string} props.resourceLabel   担当の見出し（基本設定の実効値）。
 * @param {string} props.guestsLabel     人数の見出し（基本設定の実効値）。
 * @param {string} props.guestsUnitLabel 人数の単位。
 * @return {JSX.Element|null} 予約内容。予約内容が無ければ null。
 */
export const GuestVerifyBookingSummary = ( {
	booking,
	resourceLabel,
	guestsLabel,
	guestsUnitLabel,
} ) => {
	if ( ! booking ) {
		return null;
	}

	const datetime = formatBookingDateTimeParts(
		booking.startAt || '',
		booking.endAt || '',
		booking.timezone || ''
	);
	const rows = [
		{ label: __( 'Menu', 'vk-booking-manager' ), value: booking.menuName },
		{ label: resourceLabel, value: booking.resourceName },
		{
			label: guestsLabel,
			value:
				Number( booking.guests ) > 1
					? formatGuestsCount(
							Number( booking.guests ),
							guestsUnitLabel
					  )
					: '',
		},
	].filter( ( row ) => row.value );

	return (
		<div className="vkbm-confirm__summary">
			{ ( datetime.date || datetime.time ) && (
				<div className="vkbm-confirm__summary-title">
					<div
						className="vkbm-confirm__datetime"
						aria-label={ __(
							'Reservation date and time',
							'vk-booking-manager'
						) }
					>
						{ datetime.date && (
							<span className="vkbm-confirm__date">
								{ datetime.date }
							</span>
						) }
						{ datetime.time && (
							<span className="vkbm-confirm__time">
								{ datetime.date ? ' ' : '' }
								{ datetime.time }
							</span>
						) }
					</div>
				</div>
			) }
			{ rows.map( ( row ) => (
				<dl key={ row.label } className="vkbm-confirm__summary-item">
					<dt className="vkbm-confirm__summary-item-title">
						{ row.label }
					</dt>
					<dd className="vkbm-confirm__summary-item-value">
						{ row.value }
					</dd>
				</dl>
			) ) }
		</div>
	);
};

/**
 * 確認リンクを開いた後の結果画面（予約ブロックの中に表示する）。
 *
 * 表示したときにフォーカスを移して読み上げさせるため、コンテナは tabIndex={ -1 } とし role="status" は付けない
 * （BookingCompleteNotice と同じ）。
 *
 * @param {Object} props                    プロパティ。
 * @param {Object} props.result             サーバーから渡された結果。
 * @param {string} props.pendingMessage     仮予約の完了画面の案内文。
 * @param {string} props.reservationPageUrl 「予約ページへ」のリンク先。
 * @param {string} props.resourceLabel      担当の見出し。
 * @param {string} props.guestsLabel        人数の見出し。
 * @param {string} props.guestsUnitLabel    人数の単位。
 * @param {Object} props.containerRef       フォーカス制御用に呼び出し側が保持する ref。
 * @return {JSX.Element} 結果画面。
 */
export const GuestVerifyResult = ( {
	result,
	pendingMessage = '',
	reservationPageUrl = '',
	resourceLabel = '',
	guestsLabel = '',
	guestsUnitLabel = '',
	containerRef = null,
} ) => {
	const view = getGuestVerifyResultView( result, { pendingMessage } );

	return (
		<>
			<div
				className={ `vkbm-alert vkbm-alert__${ view.tone } vkbm-confirm__complete vkbm-confirm__guest-result` }
				ref={ containerRef }
				tabIndex={ -1 }
				data-vkbm-guest-result={ result?.result || '' }
			>
				<h3 className="vkbm-confirm__complete-title">{ view.title }</h3>
				{ view.messages.map( ( message ) => (
					<p
						key={ message }
						className="vkbm-confirm__complete-message"
					>
						{ message }
					</p>
				) ) }
			</div>
			{ view.showSummary && (
				<GuestVerifyBookingSummary
					booking={ result?.booking }
					resourceLabel={ resourceLabel }
					guestsLabel={ guestsLabel }
					guestsUnitLabel={ guestsUnitLabel }
				/>
			) }
			{ view.buttonLabel && reservationPageUrl && (
				<div className="vkbm-buttons vkbm-buttons__center">
					<a
						className="vkbm-button vkbm-button__md vkbm-button__primary"
						href={ reservationPageUrl }
					>
						{ view.buttonLabel }
					</a>
				</div>
			) }
		</>
	);
};
