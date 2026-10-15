import { __, _n, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { joinSentences } from '../booking-ui/join-sentences';
import { formatCurrency } from '../../shared/pricing';
import { formatGuestsCount } from '../../shared/guests';
import { resolveApiErrorMessage } from '../../shared/rest-error-message';
import { LodgingRangeCalendar } from './lodging-range-calendar';
import { LodgingRoomList } from './lodging-room-list';
import { NightBreakdown, NightRates } from './night-rates';
import {
	applyDateClick,
	countNights,
	formatStayDate,
	getTodayIso,
	parseIsoDate,
	shiftMonth,
} from './stay-dates';
import './lodging.scss';

/**
 * 人数の入力値を、1 以上の整数にそろえる。
 *
 * @param {*}      value 入力値。
 * @param {number} max   上限。
 * @return {number} 1 以上 max 以下の整数。
 */
const clampGuests = ( value, max ) =>
	Math.min( Math.max( 1, Math.floor( Number( value ) || 1 ) ), max );

/**
 * 選択中の表示の 1 行（見出し dt と値 dd）。
 *
 * 見出し列を固定幅にして、値の左端を行をまたいでそろえる（lodging.scss の .vkbm-lodging__summary-list）。
 *
 * @param {Object} props
 * @param {string} props.label 見出し。
 * @param {*}      props.value 値。
 * @return {JSX.Element} 1 行（dt と dd）。
 */
const SummaryLine = ( { label, value } ) => (
	<>
		<dt className="vkbm-lodging__summary-label">{ label }</dt>
		<dd className="vkbm-lodging__summary-value">{ value }</dd>
	</>
);

/**
 * 日付と時刻を、それぞれ途中で折り返さない塊にして並べる。
 *
 * @param {string} date 日付の表示。
 * @param {string} time 時刻の表示。
 * @return {JSX.Element} 日付と時刻。
 */
const DateTimeValue = ( date, time ) => (
	<>
		<span className="text-nowrap">{ date }</span>{ ' ' }
		<span className="text-nowrap">{ time }</span>
	</>
);

/**
 * 宿泊プラン（泊単位のプラン）の予約画面。
 *
 * 流れは「宿泊人数 → カレンダーでチェックイン日・チェックアウト日 → 空いている客室 →
 * 選択中の表示 → 予約へ進む」。料金は人数で変わるため人数を先に入力する。
 * 空室と料金はサーバー（/vkbm/v1/lodging-availabilities）が計算した値をそのまま表示する。
 *
 * @param {Object}   props
 * @param {number}   props.menuId                選択中のメニュー（泊単位のプラン）の ID。
 * @param {string}   props.menuTitle             メニュー名。
 * @param {Object}   props.lodging               メニューの REST フィールド vkbm_lodging（人数の上限・料金区分・料金の単位・時刻）。
 * @param {string}   props.locale                WordPress のロケール。
 * @param {string}   props.guestsCountLabel      人数の見出し（業種プリセットの呼び方）。
 * @param {string}   props.guestsUnitLabel       人数の単位。
 * @param {?string}  props.currencySymbol        通貨記号。
 * @param {string}   props.resourceLabelSingular 客室の呼び方。
 * @param {boolean}  props.isEditor              ブロックエディターの中か。
 * @param {string}   props.initialCheckIn        最初から選んでおくチェックイン日（YYYY-MM-DD。絞り込み検索で選んだ日程の引き継ぎ。空文字は引き継ぎなし）。
 * @param {string}   props.initialCheckOut       最初から選んでおくチェックアウト日（YYYY-MM-DD。空文字は引き継ぎなし）。
 * @param {boolean}  props.isCarriedDatePast     URL で受け取った日程が過ぎていて引き継げなかったか（日程選択の直上で案内する）。
 * @param {Function} props.onSubmitDraft         下書きの内容を渡して保存・確認画面へ移動する関数（Promise を返す）。
 * @return {JSX.Element} 宿泊用の予約画面。
 */
const LodgingBookingPanel = ( {
	menuId,
	menuTitle,
	lodging,
	locale,
	guestsCountLabel,
	guestsUnitLabel,
	currencySymbol,
	resourceLabelSingular,
	isEditor,
	initialCheckIn = '',
	initialCheckOut = '',
	isCarriedDatePast = false,
	onSubmitDraft,
} ) => {
	const idPrefix = useId();
	const guideId = `${ idPrefix }-guide`;
	const guestsHintId = `${ idPrefix }-guests-hint`;
	const guestsLabelId = `${ idPrefix }-guests-label`;
	const maxGuests = Math.max( 1, Number( lodging?.max_guests ) || 1 );
	const tiers = useMemo(
		() =>
			Array.isArray( lodging?.guest_tiers ) ? lodging.guest_tiers : [],
		[ lodging ]
	);
	const hasTiers = tiers.length > 0;
	const basePrice = Number( lodging?.base_price ) || 0;

	// 今日はブラウザの日付（サーバー側でも過去日・受付期間は検証される）。
	const [ todayIso ] = useState( () => getTodayIso() );
	// 絞り込み検索で選んだ日程の引き継ぎは、チェックイン日・チェックアウト日の両方がそろっているときだけ使う。
	const hasInitialRange = Boolean(
		parseIsoDate( initialCheckIn ) && parseIsoDate( initialCheckOut )
	);
	// 引き継いだ日程の月から表示する（引き継ぎが無ければ今月）。
	const [ monthCursor, setMonthCursor ] = useState( () => {
		const start = parseIsoDate(
			hasInitialRange ? initialCheckIn : getTodayIso()
		);
		return { year: start.year, month: start.month };
	} );
	const [ range, setRange ] = useState( () =>
		hasInitialRange
			? { checkIn: initialCheckIn, checkOut: initialCheckOut }
			: { checkIn: '', checkOut: '' }
	);
	// 引き継いだ日程が、このプランで選べるか確かめ終わるまで覚えておく（利用者が日程を触ったら捨てる）。
	const carriedRangeRef = useRef( hasInitialRange ? { ...range } : null );
	// 引き継げなかった日程の案内。kind は 'past'（過ぎていた）か 'unavailable'（選べなかった）、range は選べなかった日程。
	// 利用者が日程を選び直したら消す。
	const [ carryNotice, setCarryNotice ] = useState(
		isCarriedDatePast ? { kind: 'past', range: null } : null
	);
	// 入力中は空文字も許す。範囲への丸めは欄を離れた時に行う。
	const [ guestsInput, setGuestsInput ] = useState( '1' );
	const guests = Math.floor( Number( guestsInput ) ) || 0;
	const [ tierCounts, setTierCounts ] = useState( () =>
		tiers.map( ( _, index ) => ( index === 0 ? 1 : 0 ) )
	);
	const [ availability, setAvailability ] = useState( {
		status: 'idle',
		data: null,
		error: '',
	} );
	const [ selectedRoomId, setSelectedRoomId ] = useState( 0 );
	const [ isRoomLost, setIsRoomLost ] = useState( false );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const requestIdRef = useRef( 0 );

	const guestCount = hasTiers
		? tierCounts.reduce( ( total, count ) => total + count, 0 )
		: guests;
	const isGuestCountValid = guestCount >= 1 && guestCount <= maxGuests;
	const tierCountsKey = tierCounts.join( ',' );
	const isRangeComplete = Boolean( range.checkIn && range.checkOut );
	const nights = countNights( range.checkIn, range.checkOut );

	// 日程・人数が変わったら、空いている客室を問い合わせ直す。
	useEffect( () => {
		if ( ! isRangeComplete || ! isGuestCountValid ) {
			requestIdRef.current += 1;
			setAvailability( { status: 'idle', data: null, error: '' } );
			return;
		}

		requestIdRef.current += 1;
		const requestId = requestIdRef.current;
		setAvailability( { status: 'loading', data: null, error: '' } );

		apiFetch( {
			path: addQueryArgs( '/vkbm/v1/lodging-availabilities', {
				menu_id: menuId,
				check_in_date: range.checkIn,
				check_out_date: range.checkOut,
				guests: guestCount,
				guest_tiers: hasTiers ? tierCounts : undefined,
			} ),
		} )
			.then( ( response ) => {
				// 古い問い合わせの結果は捨てる（日程を変えた直後に前の結果が出るのを防ぐ）。
				if ( requestId !== requestIdRef.current ) {
					return;
				}
				setAvailability( {
					status: 'done',
					data: response,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( requestId !== requestIdRef.current ) {
					return;
				}
				setAvailability( {
					status: 'error',
					data: null,
					error: resolveApiErrorMessage(
						error,
						__(
							'Failed to read free space.',
							'vk-booking-manager'
						),
						false
					),
				} );
			} );
		// tierCounts は内容が同じ間は再取得しないよう、文字列化した tierCountsKey で見る。
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		menuId,
		range.checkIn,
		range.checkOut,
		guestCount,
		tierCountsKey,
		isRangeComplete,
		isGuestCountValid,
		hasTiers,
	] );

	const rooms = useMemo(
		() =>
			availability.status === 'done' &&
			Array.isArray( availability.data?.rooms )
				? availability.data.rooms
				: [],
		[ availability ]
	);
	const selectedRoom = rooms.find( ( room ) => room.id === selectedRoomId );
	// 休前日・特定日の料金を設定したプランだけ、泊ごとの区分と内訳を出す（サーバーが判定する）。
	const isRateTiersEnabled =
		availability.status === 'done' &&
		availability.data?.rate_tiers_enabled === true;
	const nightRates = isRateTiersEnabled
		? availability.data?.night_rates ?? []
		: [];
	const checkInTime =
		availability.data?.check_in_time || lodging?.check_in_time || '';
	const checkOutTime =
		availability.data?.check_out_time || lodging?.check_out_time || '';

	// 引き継いだ日程がこのプランで選べないとき（受付期間・締切の外、または空室なし）は、別の日へ差し替えず空に戻す。
	// 空室が届いて選べると分かったら、引き継ぎの確認は終わり。
	useEffect( () => {
		const carried = carriedRangeRef.current;
		if ( ! carried ) {
			return;
		}

		if (
			range.checkIn !== carried.checkIn ||
			range.checkOut !== carried.checkOut
		) {
			carriedRangeRef.current = null;
			return;
		}

		if (
			availability.status === 'error' ||
			( availability.status === 'done' && rooms.length === 0 )
		) {
			carriedRangeRef.current = null;
			setSelectedRoomId( 0 );
			setIsRoomLost( false );
			setRange( { checkIn: '', checkOut: '' } );
			setCarryNotice( { kind: 'unavailable', range: carried } );
		} else if ( availability.status === 'done' ) {
			carriedRangeRef.current = null;
		}
	}, [ availability.status, rooms, range ] );

	// 結果が届いたとき、選んでいた客室が無ければ選択を外して知らせる（読み込み中は外さない）。
	useEffect( () => {
		if (
			availability.status === 'done' &&
			selectedRoomId &&
			! selectedRoom
		) {
			setSelectedRoomId( 0 );
			setIsRoomLost( true );
		}
	}, [ availability.status, selectedRoomId, selectedRoom ] );

	useEffect( () => {
		setSubmitError( '' );
	}, [ range, selectedRoomId, guestCount ] );

	const handleSelectDate = useCallback( ( iso ) => {
		carriedRangeRef.current = null;
		setCarryNotice( null );
		setSelectedRoomId( 0 );
		setIsRoomLost( false );
		setRange( ( current ) => applyDateClick( current, iso ) );
	}, [] );

	const handleSelectRoom = useCallback( ( roomId ) => {
		setIsRoomLost( false );
		setSelectedRoomId( roomId );
	}, [] );

	const handleResetRange = () => {
		carriedRangeRef.current = null;
		setCarryNotice( null );
		setSelectedRoomId( 0 );
		setIsRoomLost( false );
		setRange( { checkIn: '', checkOut: '' } );
	};

	// 今月より前へは動かさない。
	const isCurrentMonth = useMemo( () => {
		const today = parseIsoDate( todayIso );
		return (
			monthCursor.year * 12 + monthCursor.month <=
			today.year * 12 + today.month
		);
	}, [ todayIso, monthCursor ] );

	const handleMonthChange = useCallback(
		( delta ) => {
			setMonthCursor( ( current ) => {
				const today = parseIsoDate( todayIso );
				const next = shiftMonth( current, delta );
				return next.year * 12 + next.month <
					today.year * 12 + today.month
					? current
					: next;
			} );
		},
		[ todayIso ]
	);

	const handleProceed = () => {
		if ( isEditor ) {
			setSubmitError(
				__(
					'The editor does not transition to the confirmation page.',
					'vk-booking-manager'
				)
			);
			return;
		}
		if ( ! selectedRoom || ! isRangeComplete ) {
			return;
		}

		setIsSubmitting( true );
		setSubmitError( '' );

		// 金額・泊数・チェックイン／チェックアウトの日時はサーバーが計算し直すため、送るのは選んだ内容だけ。
		onSubmitDraft( {
			menu_id: menuId,
			resource_id: selectedRoom.id,
			menu_label: menuTitle,
			staff_label: selectedRoom.name,
			is_staff_preferred: false,
			check_in_date: range.checkIn,
			check_out_date: range.checkOut,
			guests: guestCount,
			...( hasTiers ? { guest_tiers: tierCounts } : {} ),
		} ).catch( ( error ) => {
			setSubmitError(
				resolveApiErrorMessage(
					error,
					__(
						'Failed to save reservation details. Please try again later.',
						'vk-booking-manager'
					),
					false
				)
			);
			setIsSubmitting( false );
		} );
	};

	// カレンダーの下のガイド行（今の状態と次にすることを伝える）。
	let guideText = __( 'Select the check-in date.', 'vk-booking-manager' );
	if ( range.checkIn && ! range.checkOut ) {
		guideText = sprintf(
			/* translators: %s: check-in date (e.g. October 10, 2026 (Sat)). */
			__(
				'Check-in: %s. Next, select the check-out date.',
				'vk-booking-manager'
			),
			formatStayDate( range.checkIn, locale )
		);
	} else if ( isRangeComplete ) {
		guideText = sprintf(
			/* translators: 1: check-in date, 2: check-out date. */
			__(
				'Check-in: %1$s, check-out: %2$s. Select a date to choose again.',
				'vk-booking-manager'
			),
			formatStayDate( range.checkIn, locale ),
			formatStayDate( range.checkOut, locale )
		);
	}

	// 画面の読み上げ用に、空室の問い合わせ結果を知らせる文（見た目には出さない）。
	let roomsStatusText = '';
	if ( availability.status === 'loading' ) {
		roomsStatusText = __(
			'Checking available rooms…',
			'vk-booking-manager'
		);
	} else if ( availability.status === 'done' ) {
		roomsStatusText = sprintf(
			/* translators: %d: number of available rooms. */
			_n(
				'%d room is available.',
				'%d rooms are available.',
				rooms.length,
				'vk-booking-manager'
			),
			rooms.length
		);
	}

	const renderGuestsInput = () => {
		if ( maxGuests < 2 ) {
			return null;
		}

		const hint = (
			<p className="vkbm-plan-summary__hint" id={ guestsHintId }>
				{ sprintf(
					/* translators: 1: minimum number of guests with unit (e.g. 1), 2: maximum number of guests with unit (e.g. 4). */
					__(
						'The number of guests can be from %1$s to %2$s.',
						'vk-booking-manager'
					),
					formatGuestsCount( 1, guestsUnitLabel ),
					formatGuestsCount( maxGuests, guestsUnitLabel )
				) }
			</p>
		);

		if ( hasTiers ) {
			return (
				<div
					className="vkbm-plan-summary__guest-tiers"
					role="group"
					aria-labelledby={ guestsLabelId }
					aria-describedby={ guestsHintId }
				>
					<span
						className="vkbm-plan-summary__guests-label"
						id={ guestsLabelId }
					>
						{ guestsCountLabel }
					</span>
					{ tiers.map( ( tier, index ) => (
						<div
							key={ `${ index }-${ tier.label }` }
							className="vkbm-plan-summary__guest-tier"
						>
							<label
								className="vkbm-plan-summary__guest-tier-label"
								htmlFor={ `${ idPrefix }-tier-${ index }` }
							>
								<span className="text-nowrap">
									{ tier.label }
								</span>
								<span className="vkbm-plan-summary__guest-tier-price">
									{ formatCurrency(
										tier.price,
										currencySymbol
									) }
								</span>
							</label>
							<input
								type="number"
								id={ `${ idPrefix }-tier-${ index }` }
								className="vkbm-plan-summary__guests-select"
								min="0"
								max={ maxGuests }
								step="1"
								value={ tierCounts[ index ] ?? 0 }
								onChange={ ( event ) => {
									const value = Math.max(
										0,
										Math.floor(
											Number( event.target.value ) || 0
										)
									);
									setTierCounts( ( current ) =>
										current.map( ( count, i ) =>
											i === index ? value : count
										)
									);
								} }
							/>
						</div>
					) ) }
					{ hint }
					{ guestCount > maxGuests && (
						<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
							{ sprintf(
								/* translators: %s: maximum number of guests with unit (e.g. 4). */
								__(
									'The number of guests can be up to %s.',
									'vk-booking-manager'
								),
								formatGuestsCount( maxGuests, guestsUnitLabel )
							) }
						</p>
					) }
					{ guestCount < 1 && (
						<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
							{ __(
								'Please select at least one guest.',
								'vk-booking-manager'
							) }
						</p>
					) }
				</div>
			);
		}

		return (
			<div className="vkbm-plan-summary__guests">
				<label
					className="vkbm-plan-summary__guests-label"
					htmlFor={ `${ idPrefix }-guests` }
				>
					{ guestsCountLabel }
				</label>
				<input
					type="number"
					id={ `${ idPrefix }-guests` }
					className="vkbm-plan-summary__guests-select"
					min="1"
					max={ maxGuests }
					step="1"
					value={ guestsInput }
					aria-describedby={ guestsHintId }
					onChange={ ( event ) =>
						setGuestsInput( event.target.value )
					}
					onBlur={ () =>
						setGuestsInput(
							String( clampGuests( guestsInput, maxGuests ) )
						)
					}
				/>
				{ hint }
			</div>
		);
	};

	// 料金の単位の補足。区分のあるメニューは区分ごとの単価が人数欄に出ているため出さない。
	// 日によって料金が変わるプランは、最安の金額を「〜」付きにして、日付と客室を選ぶと泊ごとの料金が出ることを添える。
	const renderPriceUnitNote = () => {
		const hasVariableRate = Boolean( lodging?.has_variable_rate );
		// 平日が0円でも、休前日・特定日に金額があればカードは「¥0〜」を出すため、補足も出す。
		if ( hasTiers || ( basePrice <= 0 && ! hasVariableRate ) ) {
			return null;
		}

		const lowestPrice = Number( lodging?.lowest_price ?? NaN );
		const formattedPrice = formatCurrency(
			hasVariableRate && Number.isFinite( lowestPrice )
				? lowestPrice
				: basePrice,
			currencySymbol
		);
		const price = hasVariableRate
			? sprintf(
					/* translators: %s: the lowest price (e.g. ¥7,000). The price differs by day, so it is shown as "from". */
					__( '%s~', 'vk-booking-manager' ),
					formattedPrice
			  )
			: formattedPrice;
		const text =
			lodging?.price_unit === 'per_room'
				? sprintf(
						/* translators: %s: plan price per room per night. */
						__(
							'The rate is %s per room per night. It does not change with the number of guests.',
							'vk-booking-manager'
						),
						price
				  )
				: sprintf(
						/* translators: %s: plan price per guest per night. */
						__(
							'The rate is %s per guest per night.',
							'vk-booking-manager'
						),
						price
				  );

		if ( ! hasVariableRate ) {
			return <p className="vkbm-plan-summary__hint">{ text }</p>;
		}

		return (
			<p className="vkbm-plan-summary__hint">
				{ text }{ ' ' }
				{ __(
					'However, the rate varies by day, such as pre-holidays and special dates.',
					'vk-booking-manager'
				) }{ ' ' }
				{ __(
					'Select your dates and a room to see the rate for each night.',
					'vk-booking-manager'
				) }
			</p>
		);
	};

	const renderCarryNotice = () => {
		if ( ! carryNotice ) {
			return null;
		}

		let message;
		if ( carryNotice.kind === 'past' ) {
			message = joinSentences(
				__(
					'The dates you had selected have passed.',
					'vk-booking-manager'
				),
				__( 'Please select your dates again.', 'vk-booking-manager' )
			);
		} else {
			const carriedNights = countNights(
				carryNotice.range.checkIn,
				carryNotice.range.checkOut
			);
			message = joinSentences(
				sprintf(
					/* translators: 1: check-in date, 2: number of nights. */
					__(
						'The dates you selected (%1$s, %2$s) cannot be booked for this plan because no rooms are available or they are outside the booking period.',
						'vk-booking-manager'
					),
					formatStayDate( carryNotice.range.checkIn, locale ),
					sprintf(
						/* translators: %d: number of nights. */
						_n(
							'%d night',
							'%d nights',
							carriedNights,
							'vk-booking-manager'
						),
						carriedNights
					)
				),
				__( 'Please select your dates again.', 'vk-booking-manager' )
			);
		}

		return (
			<p className="vkbm-alert vkbm-alert__warning vkbm-alert--compact">
				{ message }
			</p>
		);
	};

	// 泊ごとの内訳を出すか。出すときだけ、内訳と合計金額の間に区切り線を挟む。
	const hasBreakdown =
		!! selectedRoom &&
		isRateTiersEnabled &&
		Array.isArray( selectedRoom.night_breakdown ) &&
		selectedRoom.night_breakdown.length > 0;

	return (
		<div className="vkbm-lodging">
			{ renderGuestsInput() }
			{ renderPriceUnitNote() }

			{ /* 引き継いだ日程で予約できなかったことの案内。領域は常設し、中身だけを入れ替える（フォーカスは移さない）。 */ }
			<div className="vkbm-lodging__carry-notice" role="status">
				{ renderCarryNotice() }
			</div>

			<LodgingRangeCalendar
				year={ monthCursor.year }
				month={ monthCursor.month }
				range={ range }
				todayIso={ todayIso }
				onSelectDate={ handleSelectDate }
				onMonthChange={ handleMonthChange }
				isPrevDisabled={ isCurrentMonth }
				guideId={ guideId }
				locale={ locale }
			/>

			<div className="vkbm-lodging__guide-row">
				<p id={ guideId } className="vkbm-lodging__guide" role="status">
					{ guideText }
				</p>
				{ range.checkIn && (
					<button
						type="button"
						className="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__primary"
						onClick={ handleResetRange }
					>
						{ __( 'Reselect dates', 'vk-booking-manager' ) }
					</button>
				) }
			</div>

			{ /* 結果の件数・読み込み中・選べなくなった客室の案内。領域は常設し、中身だけを入れ替える。 */ }
			<div role="status" className="vkbm-lodging__live">
				<span className="vkbm-lodging__sr-only">
					{ roomsStatusText }
				</span>
				{ isRoomLost && (
					<p className="vkbm-alert vkbm-alert__warning vkbm-alert--compact">
						{ __(
							'The room you selected is no longer available. Please select again.',
							'vk-booking-manager'
						) }
					</p>
				) }
			</div>

			{ isRangeComplete && isRateTiersEnabled && (
				<NightRates nights={ nightRates } locale={ locale } />
			) }

			{ isRangeComplete && (
				<LodgingRoomList
					status={ availability.status }
					rooms={ rooms }
					error={ availability.error }
					nights={ nights }
					currencySymbol={ currencySymbol }
					selectedRoomId={ selectedRoomId }
					onSelectRoom={ handleSelectRoom }
					isGuestCountValid={ isGuestCountValid }
				/>
			) }

			<div className="vkbm-lodging__summary-container">
				<div className="vkbm-plan-summary__status vkbm-plan-summary__status--detached vkbm-lodging__summary">
					<div className="vkbm-lodging__summary-body">
						<div className="vkbm-lodging__summary-column vkbm-lodging__summary-column--stay">
							{ isRangeComplete ? (
								<>
									<dl className="vkbm-lodging__summary-list">
										<SummaryLine
											label={ __(
												'Check-in',
												'vk-booking-manager'
											) }
											value={ DateTimeValue(
												formatStayDate(
													range.checkIn,
													locale
												),
												checkInTime
											) }
										/>
										<SummaryLine
											label={ __(
												'Check-out',
												'vk-booking-manager'
											) }
											value={ DateTimeValue(
												formatStayDate(
													range.checkOut,
													locale
												),
												checkOutTime
											) }
										/>
										<SummaryLine
											label={ __(
												'Number of nights',
												'vk-booking-manager'
											) }
											value={ sprintf(
												/* translators: %d: number of nights. */
												_n(
													'%d night',
													'%d nights',
													nights,
													'vk-booking-manager'
												),
												nights
											) }
										/>
									</dl>
									{ selectedRoom && (
										<dl className="vkbm-lodging__summary-list">
											<SummaryLine
												label={ resourceLabelSingular }
												value={ selectedRoom.name }
											/>
											<SummaryLine
												label={ guestsCountLabel }
												value={ formatGuestsCount(
													guestCount,
													guestsUnitLabel
												) }
											/>
										</dl>
									) }
								</>
							) : (
								<p className="vkbm-lodging__summary-empty">
									{ __(
										'Not selected',
										'vk-booking-manager'
									) }
								</p>
							) }
						</div>
						{ selectedRoom && (
							<div className="vkbm-lodging__summary-column vkbm-lodging__summary-column--price">
								{ isRateTiersEnabled && (
									<NightBreakdown
										nights={ selectedRoom.night_breakdown }
										nightRates={ nightRates }
										locale={ locale }
										currencySymbol={ currencySymbol }
									/>
								) }
								{ hasBreakdown && (
									<div
										className="vkbm-lodging__summary-rule"
										aria-hidden="true"
									/>
								) }
								<dl className="vkbm-lodging__summary-list vkbm-lodging__summary-list--total">
									<SummaryLine
										label={ __(
											'Total amount',
											'vk-booking-manager'
										) }
										value={ formatCurrency(
											selectedRoom.total_price,
											currencySymbol
										) }
									/>
								</dl>
							</div>
						) }
					</div>
					<div className="vkbm-plan-summary__actions">
						<button
							type="button"
							className="vkbm-plan-summary__action vkbm-button vkbm-button__md vkbm-button__primary"
							onClick={ handleProceed }
							disabled={ ! selectedRoom || isSubmitting }
						>
							{ isSubmitting
								? __( 'Processing…', 'vk-booking-manager' )
								: __(
										'Proceed to Reservation',
										'vk-booking-manager'
								  ) }
						</button>
						{ submitError && (
							<p className="vkbm-plan-summary__error">
								{ submitError }
							</p>
						) }
					</div>
				</div>
			</div>
		</div>
	);
};

export default LodgingBookingPanel;
