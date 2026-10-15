import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	addDays,
	addMonths,
	buildMonthWeeks,
	formatStayDate,
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
 * @param {string}  position  選択範囲での位置（start / end / between / 空文字）。
 * @param {boolean} isPastDay 過去の日付か。
 * @return {string} 状態の説明。説明が要らなければ空文字。
 */
const describeDayState = ( position, isPastDay ) => {
	if ( isPastDay ) {
		return __( 'Not selectable (past date)', 'vk-booking-manager' );
	}
	switch ( position ) {
		case 'start':
			return __( 'Check-in date', 'vk-booking-manager' );
		case 'end':
			return __( 'Check-out date', 'vk-booking-manager' );
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
 * @param {number}                              props.year           表示する年。
 * @param {number}                              props.month          表示する月（1〜12）。
 * @param {{checkIn: string, checkOut: string}} props.range          今の選択。
 * @param {string}                              props.todayIso       今日の日付（これより前は選べない）。
 * @param {Function}                            props.onSelectDate   日付を押したときに押した日を渡して呼ぶ。
 * @param {Function}                            props.onMonthChange  表示月を動かすときに月数を渡して呼ぶ。
 * @param {boolean}                             props.isPrevDisabled 前の月へ動かせないとき true（aria-disabled にする）。
 * @param {string}                              props.guideId        ガイド文の要素 id（aria-describedby 用）。
 * @param {string}                              props.locale         WordPress のロケール。
 * @return {JSX.Element} カレンダー。
 */
export const LodgingRangeCalendar = ( {
	year,
	month,
	range,
	todayIso,
	onSelectDate,
	onMonthChange,
	guideId,
	locale,
	isPrevDisabled = false,
} ) => {
	const weeks = useMemo(
		() => buildMonthWeeks( year, month ),
		[ year, month ]
	);
	const monthPrefix = useMemo(
		() => `${ year }-${ String( month ).padStart( 2, '0' ) }-`,
		[ year, month ]
	);

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
				</div>
				<button
					type="button"
					className="vkbm-calendar__nav"
					onClick={ () => onMonthChange( 1 ) }
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
							const isEdge =
								position === 'start' || position === 'end';
							const stateLabel = describeDayState(
								position,
								isPastDay
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
													formatStayDate(
														day.iso,
														locale
													),
													stateLabel
											  )
											: formatStayDate( day.iso, locale )
									}
									aria-pressed={ isEdge }
									aria-disabled={ isPastDay }
									aria-describedby={ guideId }
									onFocus={ () => setFocusIso( day.iso ) }
									onKeyDown={ ( event ) =>
										handleKeyDown( event, day.iso )
									}
									onClick={ () => {
										if ( ! isPastDay ) {
											onSelectDate( day.iso );
										}
									} }
								>
									<span className="vkbm-calendar__day-label">
										{ day.label }
									</span>
									{ isEdge && (
										<span
											className="vkbm-calendar__day-badge"
											aria-hidden="true"
										>
											{ position === 'start'
												? 'IN'
												: 'OUT' }
										</span>
									) }
								</button>
							);
						} ) }
					</div>
				) ) }
			</div>
		</div>
	);
};
