import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { formatReservationDate } from '../date-format';
import {
	addDays,
	addMonths,
	buildMonthWeeks,
	formatStayMonth,
	getRangePosition,
	getWeekday,
} from './stay-dates';

const WEEKDAYS = [
	__( 'Sun', 'vk-booking-manager' ),
	__( 'Mon', 'vk-booking-manager' ),
	__( 'Tue', 'vk-booking-manager' ),
	__( 'Wed', 'vk-booking-manager' ),
	__( 'Thu', 'vk-booking-manager' ),
	__( 'Fri', 'vk-booking-manager' ),
	__( 'Sat', 'vk-booking-manager' ),
];

/**
 * 画面の読み上げ用に、日付のボタンの状態を説明する文を作る。
 *
 * @param {string}  position            選択範囲での位置（start / end / between / 空文字）。
 * @param {boolean} isPastDay           過去の日付か。
 * @param {string}  unavailableReason   空きなしの理由。
 * @param {boolean} isCheckoutCandidate チェックアウト日に選べるか。
 * @param {boolean} isStayBlocked       途中に空きなし日を含むか。
 * @param {boolean} isBeyondMaxNights   最大泊数を超えるか。
 * @return {string} 状態の説明。説明が要らなければ空文字。
 */
const describeDayState = (
	position,
	isPastDay,
	unavailableReason = '',
	isCheckoutCandidate = false,
	isStayBlocked = false,
	isBeyondMaxNights = false
) => {
	if ( isPastDay ) {
		return __( 'Not selectable (past date)', 'vk-booking-manager' );
	}
	if ( isCheckoutCandidate ) {
		return __( 'Available as check-out date', 'vk-booking-manager' );
	}
	if ( isBeyondMaxNights ) {
		return __(
			'Not selectable (exceeds the maximum number of nights)',
			'vk-booking-manager'
		);
	}
	if ( isStayBlocked ) {
		return __(
			'Not selectable (the stay includes an unavailable night)',
			'vk-booking-manager'
		);
	}
	if ( position === 'start' ) {
		return __( 'Check-in date', 'vk-booking-manager' );
	}
	if ( position === 'end' ) {
		return __( 'Check-out date', 'vk-booking-manager' );
	}
	if ( unavailableReason ) {
		return unavailableReason === 'fully_booked'
			? __( 'Not selectable (fully booked)', 'vk-booking-manager' )
			: __( 'Not selectable (unavailable date)', 'vk-booking-manager' );
	}
	switch ( position ) {
		case 'between':
			return __( 'Included in your stay', 'vk-booking-manager' );
		default:
			return '';
	}
};

/**
 * チェックイン日・チェックアウト日を同じカレンダーで 2 回押して選ぶ、宿泊用のカレンダー。
 *
 * キーボード操作は「Tab でカレンダーに入る（今フォーカスできる日は 1 つだけ）→ 矢印キーで日を移動
 * → Enter / Space で選択」。左右で前後の日、上下で前後の週、Home / End で週の頭・末、
 * PageUp / PageDown で前後の月へ動く。
 *
 * @param {Object}                              props
 * @param {number}                              props.year                 表示する年。
 * @param {number}                              props.month                表示する月（1〜12）。
 * @param {{checkIn: string, checkOut: string}} props.range                今の選択。
 * @param {string}                              props.todayIso             今日の日付（これより前は選べない）。
 * @param {Function}                            props.onSelectDate         日付を押したときに押した日を渡して呼ぶ。
 * @param {Function}                            props.onUnavailableDate    選べない日を押したときの処理。
 * @param {Function}                            props.onMonthChange        表示月を動かすときに月数を渡して呼ぶ。
 * @param {boolean}                             props.isPrevDisabled       前の月へ動かせないとき true（aria-disabled にする）。
 * @param {boolean}                             props.isNextDisabled       次の月へ動かせないとき true（aria-disabled にする）。
 * @param {string}                              props.guideId              ガイド文の要素 id（aria-describedby 用）。
 * @param {string}                              props.locale               WordPress のロケール。
 * @param {{status:string, days:Array}}         props.availability         空き状況。
 * @param {{status:string, days:Array}}         props.checkoutAvailability チェックアウト候補の空き状況。
 * @param {number}                              props.maxNights            最大泊数。
 * @return {JSX.Element} カレンダー。
 */
export const LodgingRangeCalendar = ( {
	year,
	month,
	range,
	todayIso,
	onSelectDate,
	onUnavailableDate,
	onMonthChange,
	guideId,
	locale,
	isPrevDisabled = false,
	isNextDisabled = false,
	availability = { status: 'loading', days: [] },
	checkoutAvailability = { status: 'idle', days: [] },
	maxNights = 30,
} ) => {
	const weeks = useMemo(
		() => buildMonthWeeks( year, month ),
		[ year, month ]
	);
	const monthPrefix = useMemo(
		() => `${ year }-${ String( month ).padStart( 2, '0' ) }-`,
		[ year, month ]
	);
	const availabilityByDate = useMemo(
		() =>
			Object.fromEntries(
				availability.days.map( ( day ) => [ day.date, day ] )
			),
		[ availability.days ]
	);
	const firstBlockedCheckout =
		range.checkIn && ! range.checkOut
			? checkoutAvailability.days.find( ( day ) => ! day.available )
					?.date || ''
			: '';
	// 今フォーカスできる日（roving tabindex）。月の外に出ていたら、その月の中の日へ寄せる。
	const [ focusIso, setFocusIso ] = useState( '' );
	const buttonRefs = useRef( {} );
	const shouldMoveFocusRef = useRef( false );

	const tabStopIso = useMemo( () => {
		if ( focusIso && focusIso.startsWith( monthPrefix ) ) {
			return focusIso;
		}
		const candidates = [ range.checkIn, todayIso ];
		const inMonth = candidates.find(
			( iso ) => iso && iso.startsWith( monthPrefix )
		);
		return inMonth || `${ monthPrefix }01`;
	}, [ focusIso, monthPrefix, range.checkIn, todayIso ] );

	// キーボードで日を動かした直後に、新しい日のボタンへフォーカスを移す。
	useEffect( () => {
		if ( shouldMoveFocusRef.current ) {
			shouldMoveFocusRef.current = false;
			buttonRefs.current[ focusIso ]?.focus();
		}
	}, [ focusIso, year, month ] );

	const moveFocus = ( nextIso ) => {
		shouldMoveFocusRef.current = true;
		setFocusIso( nextIso );

		// 表示中の月の外へ出たら、その月へ表示を動かす。
		if ( ! nextIso.startsWith( monthPrefix ) ) {
			const nextYear = Number( nextIso.slice( 0, 4 ) );
			const nextMonth = Number( nextIso.slice( 5, 7 ) );
			onMonthChange( ( nextYear - year ) * 12 + ( nextMonth - month ) );
		}
	};

	const handleKeyDown = ( event, iso ) => {
		let nextIso = '';
		switch ( event.key ) {
			case 'ArrowLeft':
				nextIso = addDays( iso, -1 );
				break;
			case 'ArrowRight':
				nextIso = addDays( iso, 1 );
				break;
			case 'ArrowUp':
				nextIso = addDays( iso, -7 );
				break;
			case 'ArrowDown':
				nextIso = addDays( iso, 7 );
				break;
			case 'Home':
				nextIso = addDays( iso, -getWeekday( iso ) );
				break;
			case 'End':
				nextIso = addDays( iso, 6 - getWeekday( iso ) );
				break;
			case 'PageUp':
				nextIso = addMonths( iso, -1 );
				break;
			case 'PageDown':
				nextIso = addMonths( iso, 1 );
				break;
			default:
				return;
		}

		event.preventDefault();
		if ( nextIso ) {
			moveFocus( nextIso );
		}
	};

	return (
		<div className="vkbm-calendar vkbm-stay-calendar">
			<header className="vkbm-calendar__header">
				<button
					type="button"
					className="vkbm-calendar__nav"
					onClick={ () => onMonthChange( -1 ) }
					aria-disabled={ isPrevDisabled }
					aria-label={ __( 'previous month', 'vk-booking-manager' ) }
				>
					‹
				</button>
				<div className="vkbm-calendar__current">
					{ formatStayMonth( year, month, locale ) }
					{ availability.status === 'loading' && (
						<span
							className="vkbm-stay-calendar__loading"
							aria-live="polite"
						>
							{ __( 'Loading…', 'vk-booking-manager' ) }
						</span>
					) }
				</div>
				<button
					type="button"
					className="vkbm-calendar__nav"
					onClick={ () => onMonthChange( 1 ) }
					aria-disabled={ isNextDisabled }
					aria-label={ __( 'next month', 'vk-booking-manager' ) }
				>
					›
				</button>
			</header>

			<div className="vkbm-calendar__weekdays">
				{ WEEKDAYS.map( ( weekday ) => (
					<span key={ weekday }>{ weekday }</span>
				) ) }
			</div>

			<div
				className="vkbm-calendar__grid"
				role="group"
				aria-label={ formatStayMonth( year, month, locale ) }
			>
				{ weeks.map( ( week ) => (
					<div className="vkbm-calendar__week" key={ week[ 0 ].iso }>
						{ week.map( ( day ) => {
							// 表示月の外の日は、空のマスにして押せないようにする。
							if ( ! day.inMonth ) {
								return (
									<span
										key={ day.iso }
										className="vkbm-calendar__day vkbm-calendar__day--muted"
										aria-hidden="true"
									/>
								);
							}

							const position = getRangePosition( range, day.iso );
							const isPastDay = day.iso < todayIso;
							const dayAvailability =
								availabilityByDate[ day.iso ];
							const isUnavailable =
								availability.status !== 'error' &&
								dayAvailability?.available === false;
							const isSelectingCheckout = Boolean(
								range.checkIn && ! range.checkOut
							);
							const isStayBlocked = Boolean(
								isSelectingCheckout &&
									firstBlockedCheckout &&
									day.iso >= firstBlockedCheckout
							);
							const isBeyondMaxNights = Boolean(
								isSelectingCheckout &&
									day.iso >
										addDays( range.checkIn, maxNights )
							);
							const isAfterCheckIn = Boolean(
								isSelectingCheckout && day.iso > range.checkIn
							);
							const isDisabled =
								isPastDay ||
								( isAfterCheckIn
									? isStayBlocked || isBeyondMaxNights
									: isUnavailable );
							const isCheckoutCandidate = Boolean(
								isAfterCheckIn &&
									checkoutAvailability.status === 'done' &&
									! isStayBlocked &&
									! isBeyondMaxNights
							);
							const shouldShowUnavailableMark = Boolean(
								isUnavailable &&
									! (
										isAfterCheckIn &&
										( isStayBlocked || isBeyondMaxNights )
									)
							);
							const isEdge =
								position === 'start' || position === 'end';
							const stateLabel = describeDayState(
								position,
								isPastDay,
								isUnavailable && ! isCheckoutCandidate
									? dayAvailability?.reason
									: '',
								isCheckoutCandidate,
								isStayBlocked,
								isBeyondMaxNights
							);

							return (
								<button
									type="button"
									key={ day.iso }
									ref={ ( node ) => {
										buttonRefs.current[ day.iso ] = node;
									} }
									className={ [
										'vkbm-calendar__day',
										position &&
											`vkbm-calendar__day--range-${ position }`,
										isPastDay && 'vkbm-calendar__day--past',
										isDisabled &&
											! isPastDay &&
											'vkbm-calendar__day--unavailable',
									]
										.filter( Boolean )
										.join( ' ' ) }
									tabIndex={ day.iso === tabStopIso ? 0 : -1 }
									aria-label={
										stateLabel
											? sprintf(
													/* translators: 1: date (e.g. October 10, 2026 (Sat)), 2: state of the date (e.g. Check-in date). */
													__(
														'%1$s, %2$s',
														'vk-booking-manager'
													),
													formatReservationDate(
														day.iso,
														locale
													),
													stateLabel
											  )
											: formatReservationDate(
													day.iso,
													locale
											  )
									}
									aria-pressed={ isEdge }
									aria-disabled={ isDisabled }
									aria-describedby={ guideId }
									onFocus={ () => setFocusIso( day.iso ) }
									onKeyDown={ ( event ) =>
										handleKeyDown( event, day.iso )
									}
									onClick={ () => {
										if ( isDisabled ) {
											onUnavailableDate( day.iso );
										} else {
											onSelectDate( day.iso );
										}
									} }
								>
									<span className="vkbm-calendar__day-label">
										{ day.label }
									</span>
									<span
										className={
											isEdge
												? 'vkbm-calendar__day-badge'
												: 'vkbm-calendar__day-unavailable-mark'
										}
										aria-hidden="true"
									>
										{ isEdge
											? position === 'start'
												? 'IN'
												: 'OUT'
											: shouldShowUnavailableMark
											? '×'
											: '' }
									</span>
								</button>
							);
						} ) }
					</div>
				) ) }
			</div>
		</div>
	);
};
