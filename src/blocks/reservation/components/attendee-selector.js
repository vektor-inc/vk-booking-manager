import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { MANUAL_ID } from '../attendee-selection';

/**
 * 予約確認画面で「ご利用になる方」を選ぶ欄。
 *
 * 名簿の人をラジオボタンで選び、名簿に無い人はその場で入力できる。
 *
 * @param {Object}  props
 * @param {Object}  props.options    サーバーが返した選択肢。
 * @param {Object}  props.state      useAttendeeSelection() が返す選択状態。
 * @param {string}  props.nameLabel  手入力する名前の見出し。
 * @param {boolean} props.isProxy    代理予約か。
 * @param {boolean} props.readOnly   予約の確定後など、変更できない状態か。
 * @param {string}  props.profileUrl ユーザー情報編集画面の URL。
 * @param {number}  props.guests     予約人数。
 * @return {JSX.Element} 選択欄。
 */
export const AttendeeSelector = ( {
	options,
	state,
	nameLabel,
	isProxy,
	readOnly,
	profileUrl,
	guests,
} ) => {
	const {
		selection,
		selectOption,
		manual,
		changeManual,
		save,
		setSave,
		problem,
		focusRequest,
		fieldsetRef,
		nameRef,
	} = state;

	// 確定時に不足があれば、不足している欄へフォーカスを移して見える位置までスクロールする。
	useEffect( () => {
		if ( ! focusRequest ) {
			return undefined;
		}
		const timer = window.setTimeout( () => {
			const target =
				problem === 'name'
					? nameRef.current
					: fieldsetRef.current?.querySelector(
							'input[type="radio"]'
					  );
			if ( target ) {
				target.focus();
			}
			// 動きを減らす設定のときは、滑らかなスクロールにしない。
			const reduceMotion =
				window.matchMedia?.( '(prefers-reduced-motion: reduce)' )
					?.matches ?? false;
			fieldsetRef.current?.scrollIntoView?.( {
				block: 'center',
				behavior: reduceMotion ? 'auto' : 'smooth',
			} );
		}, 0 );
		return () => window.clearTimeout( timer );
		// focusRequest が増えたときだけ実行する。
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ focusRequest ] );

	const items = Array.isArray( options.items ) ? options.items : [];
	const hasChoices = items.length > 0;
	const showManualFields = ! hasChoices || selection === MANUAL_ID;
	const required = ! isProxy;
	const errorId = 'vkbm-confirm-attendee-error';
	const helpId = 'vkbm-confirm-attendee-help';
	const helpTexts = [];
	if ( guests > 1 ) {
		helpTexts.push(
			__( 'Please choose the representative.', 'vk-booking-manager' )
		);
	}
	if ( isProxy && options.has_owner ) {
		helpTexts.push(
			sprintf(
				/* translators: %s: Name of the member whose phone number matched. */
				__( 'Matched member: %s', 'vk-booking-manager' ),
				options.owner_name
			)
		);
	}

	const renderItemLabel = ( item ) => {
		if ( item.id !== 'self' ) {
			return item.name;
		}
		if ( isProxy ) {
			return sprintf(
				/* translators: %s: Name of the member. */
				__( 'The member (%s)', 'vk-booking-manager' ),
				item.name
			);
		}
		return sprintf(
			/* translators: %s: Name of the person booking. */
			__( 'Myself (%s)', 'vk-booking-manager' ),
			item.name
		);
	};

	const renderSaveField = () => {
		if ( ! options.has_owner ) {
			return null;
		}
		if ( options.can_save ) {
			return (
				<div className="vkbm-confirm__attendee-save">
					<input
						type="checkbox"
						id="vkbm-confirm-attendee-save"
						checked={ save }
						onChange={ ( event ) =>
							setSave( event.target.checked )
						}
					/>
					<label htmlFor="vkbm-confirm-attendee-save">
						{ isProxy
							? __(
									"Save to this member's list",
									'vk-booking-manager'
							  )
							: __(
									'Save so I can select this next time',
									'vk-booking-manager'
							  ) }
					</label>
				</div>
			);
		}
		if ( ! options.limit_reached ) {
			return null;
		}
		// 上限に達しているときは保存できない理由を伝え、会員には整理できる場所を案内する。
		return (
			<p
				className="vkbm-confirm__attendee-help"
				id="vkbm-confirm-attendee-limit"
			>
				{ sprintf(
					/* translators: %d: Maximum number of entries that can be saved. */
					__(
						'Up to %d entries can be saved.',
						'vk-booking-manager'
					),
					options.max
				) }{ ' ' }
				{ ! isProxy && profileUrl && (
					<a href={ profileUrl }>
						{ __( 'Edit user information', 'vk-booking-manager' ) }
					</a>
				) }
			</p>
		);
	};

	return (
		<fieldset
			ref={ fieldsetRef }
			className="vkbm-confirm__form vkbm-confirm__attendee"
			disabled={ readOnly }
			aria-describedby={
				[ helpTexts.length > 0 ? helpId : '', problem ? errorId : '' ]
					.filter( Boolean )
					.join( ' ' ) || undefined
			}
		>
			<legend className="vkbm-confirm__attendee-legend">
				{ options.label }
				{ required && <span aria-hidden="true"> *</span> }
			</legend>
			{ helpTexts.length > 0 && (
				<p className="vkbm-confirm__attendee-help" id={ helpId }>
					{ helpTexts.join( ' ' ) }
				</p>
			) }
			{ hasChoices && (
				<div className="vkbm-confirm__attendee-options">
					{ items.map( ( item ) => (
						<label
							key={ item.id }
							htmlFor={ `vkbm-confirm-attendee-${ item.id }` }
						>
							<input
								type="radio"
								id={ `vkbm-confirm-attendee-${ item.id }` }
								name="vkbm-confirm-attendee"
								value={ item.id }
								checked={ selection === item.id }
								onChange={ () => selectOption( item.id ) }
								required={ required }
							/>
							<span className="vkbm-confirm__attendee-option-text">
								{ renderItemLabel( item ) }
								{ item.number && (
									<span className="vkbm-confirm__attendee-number">
										{ `${ options.number_label }: ${ item.number }` }
									</span>
								) }
							</span>
						</label>
					) ) }
					<label htmlFor="vkbm-confirm-attendee-manual">
						<input
							type="radio"
							id="vkbm-confirm-attendee-manual"
							name="vkbm-confirm-attendee"
							value={ MANUAL_ID }
							checked={ selection === MANUAL_ID }
							onChange={ () => selectOption( MANUAL_ID ) }
							required={ required }
						/>
						<span className="vkbm-confirm__attendee-option-text">
							{ __(
								'Someone else (enter a name)',
								'vk-booking-manager'
							) }
						</span>
					</label>
				</div>
			) }
			{ showManualFields && (
				<div className="vkbm-confirm__attendee-manual">
					<div className="vkbm-confirm__attendee-field">
						<label htmlFor="vkbm-confirm-attendee-name">
							{ nameLabel }
							{ required && <span aria-hidden="true"> *</span> }
						</label>
						<input
							ref={ nameRef }
							type="text"
							id="vkbm-confirm-attendee-name"
							value={ manual.name }
							onChange={ ( event ) =>
								changeManual( 'name', event.target.value )
							}
							maxLength={ 100 }
							autoComplete="off"
							required={ required }
							aria-required={ required }
							aria-invalid={ problem === 'name' }
							aria-describedby={
								problem === 'name' ? errorId : undefined
							}
						/>
					</div>
					{ options.kana_enabled && (
						<div className="vkbm-confirm__attendee-field">
							<label htmlFor="vkbm-confirm-attendee-kana">
								{ options.kana_label ||
									__( 'Furigana', 'vk-booking-manager' ) }
							</label>
							<input
								type="text"
								id="vkbm-confirm-attendee-kana"
								value={ manual.kana }
								onChange={ ( event ) =>
									changeManual( 'kana', event.target.value )
								}
								maxLength={ 100 }
								autoComplete="off"
							/>
						</div>
					) }
					{ isProxy && options.number_enabled && (
						<div className="vkbm-confirm__attendee-field">
							<label htmlFor="vkbm-confirm-attendee-number">
								{ options.number_label }
							</label>
							<input
								type="text"
								id="vkbm-confirm-attendee-number"
								value={ manual.number }
								onChange={ ( event ) =>
									changeManual( 'number', event.target.value )
								}
								maxLength={ 50 }
								autoComplete="off"
							/>
						</div>
					) }
					{ renderSaveField() }
				</div>
			) }
			{ problem && (
				<p
					id={ errorId }
					className="vkbm-alert vkbm-alert__danger vkbm-alert--compact"
					role="alert"
				>
					{ problem === 'name'
						? sprintf(
								/* translators: %s: Attendee name field label. */
								__( 'Please enter %s.', 'vk-booking-manager' ),
								nameLabel
						  )
						: sprintf(
								/* translators: %s: Label of the field for choosing who will use the service. */
								__( 'Please select %s.', 'vk-booking-manager' ),
								options.label
						  ) }
				</p>
			) }
		</fieldset>
	);
};
