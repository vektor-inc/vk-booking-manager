import { __, _n, sprintf } from '@wordpress/i18n';
import { formatCurrency } from '../../shared/pricing';

/**
 * 選んだ期間に空いている客室の候補一覧。
 *
 * 満室の客室はサーバーが返さないため、ここには空いている客室だけが並ぶ。
 * ボタンの中は 2 行（客室名、泊数と合計金額）で、「／」は使わない（画面の読み上げで「スラッシュ」と読まれるため）。
 * 合計金額はサーバーが計算した値をそのまま表示する。
 *
 * @param {Object}   props
 * @param {string}   props.status            問い合わせの状態（idle / loading / done / error）。
 * @param {Array}    props.rooms             空いている客室（id / name / total_price）。
 * @param {string}   props.error             取得に失敗したときのメッセージ。
 * @param {number}   props.nights            泊数。
 * @param {?string}  props.currencySymbol    通貨記号。
 * @param {number}   props.selectedRoomId    選択中の客室 ID。
 * @param {Function} props.onSelectRoom      客室を押したときに客室 ID を渡して呼ぶ。
 * @param {boolean}  props.isGuestCountValid 人数が受け付けられる範囲か。
 * @return {JSX.Element} 候補一覧。
 */
export const LodgingRoomList = ( {
	status,
	rooms,
	error,
	nights,
	currencySymbol,
	selectedRoomId,
	onSelectRoom,
	isGuestCountValid,
} ) => {
	let body = null;

	if ( ! isGuestCountValid ) {
		body = (
			<p className="vkbm-alert vkbm-alert__info vkbm-alert--compact">
				{ __(
					'Enter the number of guests to see available rooms.',
					'vk-booking-manager'
				) }
			</p>
		);
	} else if ( status === 'loading' ) {
		body = (
			<p className="vkbm-alert vkbm-alert__info vkbm-alert--compact">
				{ __( 'Checking available rooms…', 'vk-booking-manager' ) }
			</p>
		);
	} else if ( status === 'error' ) {
		body = (
			<p
				className="vkbm-alert vkbm-alert__danger vkbm-alert--compact"
				role="alert"
			>
				{ error }
			</p>
		);
	} else if ( status === 'done' && rooms.length === 0 ) {
		body = (
			<p className="vkbm-alert vkbm-alert__warning vkbm-alert--compact">
				{ __(
					'No rooms are available for the selected dates. Please change the dates and select again.',
					'vk-booking-manager'
				) }
			</p>
		);
	} else if ( status === 'done' ) {
		body = (
			<div className="vkbm-slot-list vkbm-lodging__rooms">
				{ rooms.map( ( room ) => (
					<button
						type="button"
						key={ room.id }
						className={ [
							'vkbm-slot-list__item',
							'vkbm-lodging__room',
							selectedRoomId === room.id && 'is-selected',
						]
							.filter( Boolean )
							.join( ' ' ) }
						aria-pressed={ selectedRoomId === room.id }
						onClick={ () => onSelectRoom( room.id ) }
					>
						<span className="vkbm-lodging__room-name">
							{ room.name }
						</span>
						<span className="vkbm-lodging__room-price">
							{ sprintf(
								/* translators: 1: number of nights, 2: total price for the stay. */
								_n(
									'%1$d night total %2$s',
									'%1$d nights total %2$s',
									nights,
									'vk-booking-manager'
								),
								nights,
								formatCurrency(
									room.total_price,
									currencySymbol
								)
							) }
						</span>
					</button>
				) ) }
			</div>
		);
	}

	return (
		<section className="vkbm-lodging__rooms-section">
			<h3 className="vkbm-lodging__heading">
				{ __( 'Available rooms', 'vk-booking-manager' ) }
			</h3>
			{ body }
		</section>
	);
};
