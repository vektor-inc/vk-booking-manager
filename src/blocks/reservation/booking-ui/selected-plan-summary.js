import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { PricingRowLabel } from '../components/pricing-row-label';
import { formatStayDate } from '../lodging/stay-dates';
import {
	applyCheckInChange,
	applyCheckOutChange,
	applyNightsChange,
	classifyDateInput,
	FILTER_DATE_MAX,
	getCheckOutRange,
	isValidIsoDate,
	STAY_ERROR_CHECK_OUT_BEFORE,
	STAY_ERROR_TOO_MANY_NIGHTS,
	STAY_MAX_NIGHTS,
	validateStay,
} from './date-filter';
import { joinSentences } from './join-sentences';

const SelectField = ( {
	label,
	options,
	value,
	onChange,
	disabled,
	placeholder,
	// #429 植草レビュー指摘（低）: 絞り込みで選択肢が0件になり disabled になったとき、
	// 理由をスクリーンリーダーへ伝えるため、説明テキストの id を aria-describedby で紐づける。
	// 説明テキストが無いとき（通常時）は aria-describedby 自体を付けない。
	describedBy,
} ) => (
	<label className="vkbm-plan-summary__field">
		<span className="vkbm-plan-summary__label">{ label }</span>
		<select
			value={ value || '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			disabled={ disabled }
			aria-describedby={ describedBy || undefined }
		>
			<option value="">{ placeholder }</option>
			{ options.map( ( option ) => (
				<option key={ option.id } value={ option.id }>
					{ option.name }
				</option>
			) ) }
		</select>
	</label>
);

/**
 * 絞り込み検索の日付欄（ブラウザ標準の日付入力）。
 *
 * 欄に出す値（入力途中を含む）と、絞り込みに使う値を分けて持つ。絞り込みに使うのは
 * 実在する日付で今日以降のときだけで、過去日は欄の値を消さずにエラーを欄の直下に出す
 * （年を打っている途中の 0002 年などでエラーが鳴り続けないよう、年が 4 桁そろったときか欄を離れたときに出す）。
 * 欄の下に、説明文と日付の指定を外すボタンを同じ行に置く（選んだ日付は読み上げ用にだけ残す）。
 *
 * @param {Object}                  props
 * @param {string}                  props.value    絞り込みに使っている日付（YYYY-MM-DD。未指定は空文字）。
 * @param {string}                  props.min      選べる最初の日付（今日）。
 * @param {(value: string) => void} props.onChange 絞り込みに使う日付が変わったときのコールバック（YYYY-MM-DD。外すときは空文字）。
 * @param {string}                  props.locale   WordPress のロケール（日付表示用）。
 */
const DateFilterField = ( { value, min, onChange, locale } ) => {
	const idSuffix = useId();
	const inputId = `vkbm-plan-summary-date-${ idSuffix }`;
	const helpId = `${ inputId }-help`;
	const errorId = `${ inputId }-error`;
	// 日付の指定を外したあと、フォーカスを入力欄へ戻すため（ボタンは外した時点で消える）。
	const inputRef = useRef( null );
	// 日付の指定を外したことを、読み上げ専用の領域で伝えるか。
	const [ cleared, setCleared ] = useState( false );
	// 欄に出している値。入力途中でも消さない。
	const [ inputValue, setInputValue ] = useState( value || '' );
	// 今日より前の日付の入力に対するエラーを出しているか。
	const [ showPastError, setShowPastError ] = useState( false );

	// 絞り込みに使う日付が外から変わったとき（解除ボタンなど）は、欄の値とエラーも合わせる。
	useEffect( () => {
		setInputValue( value || '' );
		setShowPastError( false );
		if ( value ) {
			setCleared( false );
		}
	}, [ value ] );

	const describedBy = showPastError ? `${ helpId } ${ errorId }` : helpId;

	return (
		<div className="vkbm-plan-summary__field vkbm-plan-summary__field--date">
			<label className="vkbm-plan-summary__label" htmlFor={ inputId }>
				{ __( 'Date', 'vk-booking-manager' ) }
			</label>
			<input
				id={ inputId }
				ref={ inputRef }
				className="vkbm-plan-summary__date-input"
				type="date"
				value={ inputValue }
				min={ min || undefined }
				max={ FILTER_DATE_MAX }
				onChange={ ( event ) => {
					const nextValue = event.target.value;
					const result = classifyDateInput(
						nextValue,
						event.target.validity?.badInput === true,
						min
					);
					setInputValue( nextValue );

					if ( result.action === 'ignore' ) {
						// 過去日は年が 4 桁そろったときにエラーを出す。入力途中・範囲外では出さない。
						setShowPastError(
							result.isPast && result.isYearComplete
						);
						return;
					}

					// 有効な日付に変わった・空になった時点でエラーを外す。
					setShowPastError( false );
					onChange( nextValue );
				} }
				onBlur={ () => {
					const result = classifyDateInput( inputValue, false, min );
					setShowPastError( result.isPast );
				} }
				aria-describedby={ describedBy }
				aria-invalid={ showPastError ? 'true' : undefined }
			/>
			{ showPastError && (
				<p
					id={ errorId }
					className="vkbm-plan-summary__date-error"
					role="alert"
				>
					{ __(
						'Please select today or a later date.',
						'vk-booking-manager'
					) }
				</p>
			) }
			<div className="vkbm-plan-summary__date-note">
				<p id={ helpId } className="vkbm-plan-summary__date-help">
					{ __(
						'Only the service menus that can be booked on the selected date are shown.',
						'vk-booking-manager'
					) }
				</p>
				{ value && (
					<button
						type="button"
						className="vkbm-plan-summary__date-clear"
						onClick={ () => {
							setInputValue( '' );
							setShowPastError( false );
							setCleared( true );
							onChange( '' );
							inputRef.current?.focus();
						} }
					>
						{ __( 'Clear the date', 'vk-booking-manager' ) }
					</button>
				) }
			</div>
			{ /* 読み上げ領域は常に描画し、中身だけ出し入れする（後から現れた領域は読み上げられないことがあるため）。画面には出さず、選んだ日付を読み上げ用に残す。 */ }
			<div
				className="vkbm-plan-summary__date-selected"
				aria-live="polite"
			>
				{ value && <span>{ formatStayDate( value, locale ) }</span> }
				{ ! value && cleared && (
					<span>
						{ __(
							'The date has been cleared.',
							'vk-booking-manager'
						) }
					</span>
				) }
			</div>
		</div>
	);
};

/**
 * 宿泊のサイトの絞り込み検索に出す、チェックイン日・チェックアウト日・泊数の欄。
 *
 * メニュー・客室のプルダウンより上の独立した 1 行に置く。日付入力はブラウザ標準で、欄に出す値（入力途中を含む）と
 * 絞り込みに使う値を分けて持つ（DateFilterField と同じ考え方）。日付の組み立て（翌日の自動入力・泊数を保った移動・矛盾の判定）は
 * date-filter.js の純関数に任せる。矛盾しているときは、欄の直下にエラーを出し、絞り込みは呼び出し側（app.js）が実行しない。
 *
 * @param {Object}                                              props
 * @param {string}                                              props.checkIn   絞り込みに使うチェックイン日（YYYY-MM-DD。未指定は空文字）。
 * @param {string}                                              props.checkOut  絞り込みに使うチェックアウト日（YYYY-MM-DD。未指定は空文字）。
 * @param {string}                                              props.min       チェックイン日で選べる最初の日付（今日）。
 * @param {number}                                              props.maxNights 1 回に予約できる最大泊数。
 * @param {(stay: {checkIn: string, checkOut: string}) => void} props.onChange  日程が変わったときのコールバック。
 * @param {string}                                              props.locale    WordPress のロケール（日付表示用）。
 */
const StayFilterField = ( {
	checkIn,
	checkOut,
	min,
	maxNights,
	onChange,
	locale,
} ) => {
	const idSuffix = useId();
	const baseId = `vkbm-plan-summary-stay-${ idSuffix }`;
	const checkInId = `${ baseId }-check-in`;
	const checkOutId = `${ baseId }-check-out`;
	const nightsId = `${ baseId }-nights`;
	const helpId = `${ baseId }-help`;
	const pastErrorId = `${ baseId }-past-error`;
	const stayErrorId = `${ baseId }-stay-error`;
	// 日付の指定を外したあと、フォーカスをチェックイン日の欄へ戻すため（ボタンは外した時点で消える）。
	const checkInRef = useRef( null );
	// 日付の指定を外したことを、読み上げ専用の領域で伝えるか。
	const [ cleared, setCleared ] = useState( false );
	const stay = { checkIn, checkOut };
	// 欄に出している値。入力途中でも消さない。
	const [ checkInInput, setCheckInInput ] = useState( checkIn );
	const [ checkOutInput, setCheckOutInput ] = useState( checkOut );
	// 今日より前のチェックイン日の入力に対するエラーを出しているか。
	const [ showPastError, setShowPastError ] = useState( false );

	// 絞り込みに使う日程が外から変わったとき（解除ボタン・チェックイン日の変更に伴うチェックアウト日の移動）は、欄の値も合わせる。
	useEffect( () => {
		setCheckInInput( checkIn );
	}, [ checkIn ] );
	useEffect( () => {
		if ( checkIn || checkOut ) {
			setCleared( false );
		}
	}, [ checkIn, checkOut ] );
	useEffect( () => {
		setCheckOutInput( checkOut );
	}, [ checkOut ] );

	const validation = validateStay( stay, maxNights );
	const checkOutRange = getCheckOutRange( checkIn, maxNights );
	const nightsValue =
		validation.nights >= 1 && validation.nights <= maxNights
			? String( validation.nights )
			: '';

	let stayErrorText = '';
	if ( validation.error === STAY_ERROR_CHECK_OUT_BEFORE ) {
		stayErrorText = __(
			'Please select a check-out date that is after the check-in date.',
			'vk-booking-manager'
		);
	} else if ( validation.error === STAY_ERROR_TOO_MANY_NIGHTS ) {
		// 上限を伝える1文と、次にすることを伝える1文を続ける（翻訳は1文ずつ）。
		stayErrorText = joinSentences(
			sprintf(
				/* translators: %d: maximum number of nights per booking. */
				__(
					'You can book up to %d nights in a single booking.',
					'vk-booking-manager'
				),
				maxNights
			),
			sprintf(
				/* translators: %d: maximum number of nights per booking. */
				__(
					'Please select a check-out date within %d nights.',
					'vk-booking-manager'
				),
				maxNights
			)
		);
	}

	const helpText = __(
		'Select dates to see only the service menus that can be booked for those dates.',
		'vk-booking-manager'
	);
	const checkInDescribedBy = showPastError
		? `${ helpId } ${ pastErrorId }`
		: helpId;
	const checkOutDescribedBy = stayErrorText
		? `${ helpId } ${ stayErrorId }`
		: helpId;

	return (
		<div className="vkbm-plan-summary__stay">
			<div className="vkbm-plan-summary__stay-fields">
				<div className="vkbm-plan-summary__field">
					<label
						className="vkbm-plan-summary__label"
						htmlFor={ checkInId }
					>
						{ __( 'Check-in date', 'vk-booking-manager' ) }
					</label>
					<input
						id={ checkInId }
						ref={ checkInRef }
						className="vkbm-plan-summary__date-input"
						type="date"
						value={ checkInInput }
						min={ min || undefined }
						max={ FILTER_DATE_MAX }
						onChange={ ( event ) => {
							const nextValue = event.target.value;
							const result = classifyDateInput(
								nextValue,
								event.target.validity?.badInput === true,
								min
							);
							setCheckInInput( nextValue );

							if ( result.action === 'ignore' ) {
								// 過去日は年が 4 桁そろったときにエラーを出す。入力途中・範囲外では出さない。
								setShowPastError(
									result.isPast && result.isYearComplete
								);
								return;
							}

							// 有効な日付に変わった・空になった時点でエラーを外す。
							setShowPastError( false );
							onChange(
								applyCheckInChange( stay, nextValue, maxNights )
							);
						} }
						onBlur={ () => {
							const result = classifyDateInput(
								checkInInput,
								false,
								min
							);
							setShowPastError( result.isPast );
						} }
						aria-describedby={ checkInDescribedBy }
						aria-invalid={ showPastError ? 'true' : undefined }
					/>
				</div>
				<div className="vkbm-plan-summary__field">
					<label
						className="vkbm-plan-summary__label"
						htmlFor={ checkOutId }
					>
						{ __( 'Check-out date', 'vk-booking-manager' ) }
					</label>
					<input
						id={ checkOutId }
						className="vkbm-plan-summary__date-input"
						type="date"
						value={ checkOutInput }
						min={ checkOutRange.min || undefined }
						max={ checkOutRange.max || undefined }
						onChange={ ( event ) => {
							const nextValue = event.target.value;
							// チェックアウト日の矛盾は欄の直下のエラーで伝えるため、今日との比較はしない。
							const result = classifyDateInput(
								nextValue,
								event.target.validity?.badInput === true,
								''
							);
							setCheckOutInput( nextValue );

							if ( result.action === 'ignore' ) {
								return;
							}

							onChange( applyCheckOutChange( stay, nextValue ) );
						} }
						aria-describedby={ checkOutDescribedBy }
						aria-invalid={ stayErrorText ? 'true' : undefined }
					/>
				</div>
				<div className="vkbm-plan-summary__field vkbm-plan-summary__field--nights">
					<label
						className="vkbm-plan-summary__label"
						htmlFor={ nightsId }
					>
						{ __( 'Number of nights', 'vk-booking-manager' ) }
					</label>
					<select
						id={ nightsId }
						value={ nightsValue }
						disabled={ ! isValidIsoDate( checkIn ) }
						// 泊数が選べない理由（チェックイン日が未選択）は、欄の下の説明文（helpId）が伝える。
						aria-describedby={
							isValidIsoDate( checkIn ) ? undefined : helpId
						}
						onChange={ ( event ) =>
							onChange(
								applyNightsChange(
									stay,
									Number( event.target.value )
								)
							)
						}
					>
						<option value="">
							{ __( 'Select', 'vk-booking-manager' ) }
						</option>
						{ Array.from(
							{ length: maxNights },
							( _, index ) => index + 1
						).map( ( nights ) => (
							<option key={ nights } value={ nights }>
								{ sprintf(
									/* translators: %d: number of nights. */
									_n(
										'%d night',
										'%d nights',
										nights,
										'vk-booking-manager'
									),
									nights
								) }
							</option>
						) ) }
					</select>
				</div>
			</div>
			{ showPastError && (
				<p
					id={ pastErrorId }
					className="vkbm-plan-summary__date-error"
					role="alert"
				>
					{ __(
						'Please select today or a later date.',
						'vk-booking-manager'
					) }
				</p>
			) }
			{ stayErrorText && (
				<p
					id={ stayErrorId }
					className="vkbm-plan-summary__date-error"
					role="alert"
				>
					{ stayErrorText }
				</p>
			) }
			<div className="vkbm-plan-summary__date-note">
				<p id={ helpId } className="vkbm-plan-summary__date-help">
					{ helpText }
				</p>
				{ ( checkIn || checkOut ) && (
					<button
						type="button"
						className="vkbm-plan-summary__date-clear"
						onClick={ () => {
							setCheckInInput( '' );
							setCheckOutInput( '' );
							setShowPastError( false );
							setCleared( true );
							onChange( { checkIn: '', checkOut: '' } );
							checkInRef.current?.focus();
						} }
					>
						{ __( 'Clear the dates', 'vk-booking-manager' ) }
					</button>
				) }
			</div>
			{ /* 読み上げ領域は常に描画し、中身だけ出し入れする（後から現れた領域は読み上げられないことがあるため）。画面には出さず、日程の確定を読み上げ用に残す。 */ }
			<div
				className="vkbm-plan-summary__date-selected"
				aria-live="polite"
			>
				{ validation.isComplete && (
					<span>
						{ sprintf(
							/* translators: 1: Check-in date, 2: Check-out date, 3: Number of nights (e.g. 1 night). */
							__(
								'Check-in %1$s - Check-out %2$s (%3$s)',
								'vk-booking-manager'
							),
							formatStayDate( checkIn, locale ),
							formatStayDate( checkOut, locale ),
							sprintf(
								/* translators: %d: number of nights. */
								_n(
									'%d night',
									'%d nights',
									validation.nights,
									'vk-booking-manager'
								),
								validation.nights
							)
						) }
					</span>
				) }
				{ ! validation.isComplete && cleared && (
					<span>
						{ __(
							'The dates have been cleared.',
							'vk-booking-manager'
						) }
					</span>
				) }
			</div>
		</div>
	);
};

const ReadOnlyField = ( { label, value, placeholder } ) => (
	<div className="vkbm-plan-summary__field">
		<span className="vkbm-plan-summary__label">{ label }</span>
		<span className="vkbm-plan-summary__value">
			{ value || placeholder }
		</span>
	</div>
);

export const SelectedPlanSummary = ( {
	// 絞り込み検索のデザイン（plain / framed など）。plain 以外は修飾クラスを付ける。
	searchStyle = 'plain',
	menuId,
	staffId,
	menus,
	staffOptions = [],
	onMenuChange,
	onStaffChange,
	allowStaffSelection,
	showStaffField = true,
	pricingRows = [],
	menuPreviewLoading = false,
	menuPreviewError = '',
	menuPreviewHtml = '',
	resourceLabelSingular = __( 'Staff', 'vk-booking-manager' ),
	lockStaffSelection = false,
	noNominationLabel = '',
	// #429 植草レビュー指摘（低）: 絞り込み検索でスタッフを選んだ結果「メニュー」の選択肢が
	// 0件になり disabled になったときの理由文言。呼び出し側（app.js）が
	// カード一覧の0件メッセージと同じ文言を渡す想定。空文字なら何も表示しない。
	menuUnavailableMessage = '',
	// #429 安藤レビュー指摘（低・再レビュー、#435 で再々レビュー修正）: 一覧側（app.js）の
	// 0件メッセージ要素が実際に描画されているかどうか（app.js の menuListEmptyMessageRendered）。
	// showMenuList（「サービスメニュー一覧」を表示する設定かどうか）とは別物で、一覧を表示する
	// 設定でも、読み込み中・取得失敗などで0件メッセージ自体が出ていなければ false になる。
	// true のときはこのコンポーネント自身のヒント表示を省略し、下記 menuListEmptyMessageId が
	// 指す一覧側の要素だけを aria-describedby で参照させる
	// （同じ文言を role="status" で二重に読み上げさせないため）。
	menuListVisible = false,
	// 一覧側（app.js 側で描画される0件メッセージ要素）の id。menuListVisible が true で
	// この値がある場合のみ、そちらを aria-describedby の参照先として使う。
	menuListEmptyMessageId = '',
	// #431: リソースタグ検索。指名機能（staffEnabled）の ON/OFF に関係なく表示・機能させる
	// 仕様のため、showStaffField（指名機能の可否）とは独立したフラグで表示可否を判定する。
	showResourceTagSearch = false,
	// チェックボックスとして表示するリソースタグ一覧（{id, name, count} の配列）。
	// リソースが1件も紐づいていないタグは呼び出し側（app.js）で既に除外済み（hide_empty）。
	resourceTagOptions = [],
	// 選択中のリソースタグのターム ID 配列（複数選択時は呼び出し側で AND 条件として扱う）。
	selectedTagIds = [],
	// チェックボックスの ON/OFF を切り替えたときのコールバック（tagId, checked）。
	onTagToggle = () => {},
	// #435: 「予約ページの表示要素」の「サービスメニュー一覧」（呼び出し側の
	// providerSettings.showMenuList）を表示する設定かどうか。一覧を表示する設定では、
	// 一覧のカードが並ぶので「メニューを選択してください。」のアラートは不要（二重案内になる）。
	// 「サービスメニュー一覧」を表示しない設定（絞り込み検索のみ）のときは従来どおり表示する。
	// 一覧の読み込み中・取得失敗のときも判定は設定値だけに揃え、表示しない。
	// menuListVisible（一覧側の0件メッセージが実際に描画されているか）とは意味が異なるため、
	// 流用せずこのフラグを別途受け取る。
	showMenuList = false,
	// 絞り込み検索の日付欄。メニュー未選択のときだけ呼び出し側（app.js）が true を渡す。
	// 宿泊のチェックイン・チェックアウト欄を足すときは、この日付欄とは別の props で渡す。
	showDateFilter = false,
	// 絞り込みで選んだ日付（YYYY-MM-DD。未指定は空文字）。
	filterDate = '',
	// 日付欄で選べる最初の日付（今日。YYYY-MM-DD）。
	filterDateMin = '',
	// 日付が変わったときのコールバック（YYYY-MM-DD。指定を外すときは空文字）。
	onFilterDateChange = () => {},
	// 日付表示用の WordPress ロケール。
	locale = '',
	// 宿泊のサイトのチェックイン日・チェックアウト日・泊数の欄。メニュー未選択のときだけ呼び出し側（app.js）が true を渡す。
	// 時間枠のサイトの日付欄（showDateFilter）とは同時に true にしない。
	showStayFilter = false,
	// 絞り込みで選んだ日程（それぞれ YYYY-MM-DD。未指定は空文字）。
	stayCheckIn = '',
	stayCheckOut = '',
	// 日程が変わったときのコールバック（{ checkIn, checkOut }。指定を外すときは両方空文字）。
	onStayChange = () => {},
	// 1 回に予約できる最大泊数。
	stayMaxNights = STAY_MAX_NIGHTS,
} ) => {
	// #429 安藤レビュー指摘（低・再レビュー）: 同じページに予約ブロックが複数あっても
	// id が衝突しないよう、固定文字列ではなくコンポーネントの描画ごとに一意な id を作る
	// （一覧を表示しない構成で自前のヒントを出す場合に使う）。
	// 安藤レビュー指摘（低・2回目再レビュー）: 以前は @wordpress/compose の useInstanceId を
	// 使っていたが、公開側の予約ページに wp-compose の追加読み込みが発生していたため、
	// 既に読み込み済みの @wordpress/element（React 18 の useId をそのまま再エクスポートした
	// もの。readme.txt の Requires at least 6.8 で利用可能）へ差し替えた。
	const menuUnavailableMessageIdSuffix = useId();
	const menuUnavailableMessageId = `vkbm-plan-summary-menu-unavailable-${ menuUnavailableMessageIdSuffix }`;
	const menuSelectionMessage = ( () => {
		const message = __( 'Please select a menu.', 'vk-booking-manager' );
		if ( message.includes( '%s' ) ) {
			return sprintf( message, __( 'Menu', 'vk-booking-manager' ) );
		}
		return message;
	} )();
	const menuOptions = [ ...menus ]
		.sort( ( a, b ) => {
			const groupA = a?.vkbm_menu_group || null;
			const groupB = b?.vkbm_menu_group || null;
			const orderA = Number.isFinite( groupA?.order )
				? groupA.order
				: Number.MAX_SAFE_INTEGER;
			const orderB = Number.isFinite( groupB?.order )
				? groupB.order
				: Number.MAX_SAFE_INTEGER;

			if ( orderA !== orderB ) {
				return orderA - orderB;
			}

			const hasGroupA = Boolean( groupA );
			const hasGroupB = Boolean( groupB );
			if ( hasGroupA !== hasGroupB ) {
				return hasGroupA ? -1 : 1;
			}

			const nameA = String( groupA?.name ?? '' );
			const nameB = String( groupB?.name ?? '' );
			const groupNameCompare = nameA.localeCompare( nameB );
			if ( groupNameCompare !== 0 ) {
				return groupNameCompare;
			}

			const menuOrderA = Number.isFinite( a?.menu_order )
				? a.menu_order
				: 0;
			const menuOrderB = Number.isFinite( b?.menu_order )
				? b.menu_order
				: 0;
			if ( menuOrderA !== menuOrderB ) {
				return menuOrderA - menuOrderB;
			}

			const titleA = String( a?.title?.rendered ?? a?.title ?? '' );
			const titleB = String( b?.title?.rendered ?? b?.title ?? '' );
			return titleA.localeCompare( titleB );
		} )
		.map( ( menu ) => ( {
			id: menu.id,
			name: menu.title?.rendered ?? menu.title,
		} ) );

	const staffItems = staffOptions.map( ( staff ) => {
		const staffName = staff.title?.rendered ?? staff.name ?? staff.title;
		const tags = staff.resource_tags;
		return {
			id: staff.id,
			name:
				Array.isArray( tags ) && tags.length > 0
					? `${ staffName } ( ${ tags.join( ', ' ) } )`
					: staffName,
		};
	} );

	// #429 安藤レビュー指摘（低・再レビュー）: 「メニュー」の選択肢が0件で理由文言がある状態か。
	const hasMenuUnavailableMessage =
		! menuOptions.length && Boolean( menuUnavailableMessage );
	// 一覧も表示している構成では、一覧側の0件メッセージと重複するため、
	// このコンポーネント自身のヒント（<p>）は描画せず、一覧側の要素を aria-describedby で参照する。
	// 一覧を表示しない構成、または一覧側の id が渡っていない場合は、従来どおり自前のヒントを出す。
	const useExternalMenuListMessage =
		hasMenuUnavailableMessage &&
		menuListVisible &&
		Boolean( menuListEmptyMessageId );
	const menuSelectDescribedBy = hasMenuUnavailableMessage
		? useExternalMenuListMessage
			? menuListEmptyMessageId
			: menuUnavailableMessageId
		: undefined;

	return (
		<div className="vkbm-plan-summary">
			{ /* 絞り込み検索の欄（日程・プルダウン・タグ・案内文）だけを 1 つのまとまりにする。
			    デザイン設定（searchStyle）の「枠で囲う」の枠はこのまとまりにだけ付け、下のメニューのプレビューと料金欄は枠の外に置く（#778）。
			    plain 以外は --{searchStyle} の修飾クラスを付ける（#779）。 */ }
			<div
				className={
					'plain' === searchStyle
						? 'vkbm-plan-summary__search'
						: `vkbm-plan-summary__search vkbm-plan-summary__search--${ searchStyle }`
				}
			>
				{ /* 宿泊のサイトは、チェックイン日・チェックアウト日・泊数を、メニュー・客室のプルダウンより上の独立した 1 行に置く。 */ }
				{ showStayFilter && (
					<StayFilterField
						checkIn={ stayCheckIn }
						checkOut={ stayCheckOut }
						min={ filterDateMin }
						maxNights={ stayMaxNights }
						onChange={ onStayChange }
						locale={ locale }
					/>
				) }
				{ /* 日付欄は、プルダウンと横並びにせず、プルダウンの行より上の独立した行に置く（宿泊のサイトと同じ）。 */ }
				{ showDateFilter && (
					<DateFilterField
						value={ filterDate }
						min={ filterDateMin }
						onChange={ onFilterDateChange }
						locale={ locale }
					/>
				) }
				<div className="vkbm-plan-summary__selectors">
					<SelectField
						label={ __( 'Menu', 'vk-booking-manager' ) }
						options={ menuOptions }
						value={ menuId }
						onChange={ ( value ) =>
							onMenuChange( Number( value ) || 0 )
						}
						placeholder={ __(
							'Select menu',
							'vk-booking-manager'
						) }
						disabled={ ! menuOptions.length }
						describedBy={ menuSelectDescribedBy }
					/>
					{ showStaffField &&
						( allowStaffSelection ? (
							<SelectField
								label={ resourceLabelSingular }
								options={ staffItems }
								value={ staffId }
								onChange={ ( value ) =>
									onStaffChange( Number( value ) || 0 )
								}
								placeholder={
									noNominationLabel ||
									__( 'No preference', 'vk-booking-manager' )
								}
								disabled={
									! staffItems.length || lockStaffSelection
								}
							/>
						) : (
							<ReadOnlyField
								label={ resourceLabelSingular }
								value={
									staffItems.find(
										( staff ) => staff.id === staffId
									)?.name
								}
								placeholder={
									noNominationLabel ||
									__( 'No preference', 'vk-booking-manager' )
								}
							/>
						) ) }
				</div>

				{ /* #431: リソースタグ検索。サービスメニュー・リソースの絞り込みプルダウンの直下に、
				    リソースが1件でも紐づいているタグだけをチェックボックスとして並べる。
				    複数選択時は呼び出し側（app.js）で AND 条件として扱われる。 */ }
				{ showResourceTagSearch && (
					<fieldset className="vkbm-plan-summary__tag-filter">
						{ /* #431: 固定文言「Resource tag」ではなく、
						    受け取り済みの resourceLabelSingular（基本設定の「リソース名称」）を使って
						    動的化する（例:「スタッフタグ」）。管理画面のタクソノミーラベル
						    （Resource_Tag_Taxonomy::register_taxonomy() の "%s Resource Tag"）と
						    同じ原文（同じ msgid）を使うことで訳語・表記を揃える。 */ }
						<legend className="vkbm-plan-summary__label">
							{ sprintf(
								/* translators: %s: resource label (singular), e.g. "Staff". */
								__( '%s Resource Tag', 'vk-booking-manager' ),
								resourceLabelSingular
							) }
						</legend>
						<div className="vkbm-plan-summary__tag-filter-options">
							{ resourceTagOptions.map( ( tag ) => (
								<label
									key={ tag.id }
									className="vkbm-plan-summary__tag-filter-option"
								>
									<input
										type="checkbox"
										checked={ selectedTagIds.includes(
											tag.id
										) }
										onChange={ ( event ) =>
											onTagToggle(
												tag.id,
												event.target.checked
											)
										}
									/>
									{ /* リソースタグ名は文字の途中で改行しない（css側で white-space: nowrap）。*/ }
									{ /* #431: REST の term.name は HTMLエンティティ
									    エンコード済みのため、そのまま表示すると「&」等が実体参照のまま
									    見えてしまう。decodeEntities() でデコードしてから表示する。 */ }
									<span className="vkbm-plan-summary__tag-filter-label">
										{ decodeEntities( tag.name ) }
									</span>
								</label>
							) ) }
						</div>
					</fieldset>
				) }

				{ hasMenuUnavailableMessage && ! useExternalMenuListMessage && (
					<p
						id={ menuUnavailableMessageId }
						className="vkbm-plan-summary__field-hint vkbm-alert vkbm-alert__warning vkbm-alert--compact"
						role="status"
					>
						{ menuUnavailableMessage }
					</p>
				) }
			</div>

			{ menuId ? (
				<div className="vkbm-plan-summary__menu-preview vkbm-reservation__menu-preview">
					{ menuPreviewLoading && (
						<p className="vkbm-reservation__menu-preview-notice">
							{ __(
								'Loading menu information…',
								'vk-booking-manager'
							) }
						</p>
					) }
					{ ! menuPreviewLoading && menuPreviewError && (
						<div className="vkbm-reservation__error">
							{ menuPreviewError }
						</div>
					) }
					{ ! menuPreviewLoading &&
						! menuPreviewError &&
						menuPreviewHtml && (
							<div
								className="vkbm-reservation__menu-preview-card"
								dangerouslySetInnerHTML={ {
									__html: menuPreviewHtml,
								} }
							/>
						) }
				</div>
			) : null }

			{ menuId ? (
				pricingRows?.length > 0 ? (
					<div className="vkbm-plan-summary__pricing">
						{ pricingRows.map( ( row ) => (
							<div
								key={ row.key }
								className="vkbm-plan-summary__pricing-row"
								// #503: live 指定の行（基本料金合計）は、人数の変更などで金額が変わったことを
								// 読み上げソフトにも伝える。見出し・金額・税表記をまとめて読ませるため、
								// 行全体を live 領域にして aria-atomic も付ける。小計の行には付けない（二重読み上げ防止）。
								aria-live={ row.live ? 'polite' : undefined }
								aria-atomic={ row.live ? 'true' : undefined }
							>
								<span className="vkbm-plan-summary__pricing-label">
									{ /* #503: 「（単価 × 人数）」の括弧の途中で改行させない。 */ }
									<PricingRowLabel
										label={ row.label }
										labelParts={ row.labelParts }
									/>
								</span>
								<strong
									className={ [
										'vkbm-plan-summary__pricing-value',
										row.highlight &&
											'vkbm-plan-summary__pricing-value--accent',
									]
										.filter( Boolean )
										.join( ' ' ) }
								>
									<span className="vkbm-plan-summary__pricing-amount">
										{ row.value ?? '—' }
									</span>
									{ row.taxLabel ? (
										<span className="vkbm-plan-summary__pricing-tax">
											{ row.taxLabel }
										</span>
									) : null }
								</strong>
							</div>
						) ) }
					</div>
				) : null
			) : (
				// #435: 「サービスメニュー一覧」を表示する設定のときは、下に一覧のカードが
				// 並ぶため「メニューを選択してください。」のアラートは出さない。
				! showMenuList && (
					<div className="vkbm-plan-summary__pricing vkbm-plan-summary__pricing--alert">
						<p
							className="vkbm-alert vkbm-alert__info vkbm-alert--compact"
							role="status"
						>
							{ menuSelectionMessage }
						</p>
					</div>
				)
			) }
		</div>
	);
};
