import { useRef } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';
import { RequiredLabelText } from './required-label-text';

/**
 * 1 行のテキストの上限文字数（サーバーの Booking_Custom_Fields::TEXT_MAX_LENGTH と揃える）。
 */
export const CUSTOM_FIELD_TEXT_MAX_LENGTH = 200;

/**
 * 予約時の入力項目（#1147）の定義を、provider-settings の REST 応答から型をそろえて取り出す。
 *
 * @param {unknown} raw 応答の booking_custom_fields。
 * @return {Array<{id: string, label: string, type: string, required: boolean, maxLength: number}>} 項目の定義。
 */
export const normalizeCustomFieldDefinitions = ( raw ) => {
	if ( ! Array.isArray( raw ) ) {
		return [];
	}
	return raw
		.filter(
			( item ) =>
				item &&
				typeof item.id === 'string' &&
				item.id !== '' &&
				typeof item.label === 'string' &&
				item.label !== ''
		)
		.map( ( item ) => {
			const type = [ 'textarea', 'select', 'radio', 'number' ].includes(
				item.type
			)
				? item.type
				: 'text';
			const maxLength =
				Number.isInteger( item.max_length ) && item.max_length > 0
					? item.max_length
					: CUSTOM_FIELD_TEXT_MAX_LENGTH;
			return {
				id: item.id,
				label: item.label,
				type,
				choices: Array.isArray( item.choices )
					? item.choices.filter(
							( choice ) => typeof choice === 'string'
					  )
					: [],
				defaultValue:
					typeof item.default_value === 'string'
						? item.default_value
						: '',
				required: Boolean( item.required ),
				maxLength,
			};
		} );
};

/**
 * 入力値を検証し、項目ごとのエラーを返す（サーバーの Booking_Custom_Fields::validate_values() と同じ規則）。
 *
 * @param {Array}   definitions     項目の定義。
 * @param {Object}  values          項目 ID => 入力値。
 * @param {boolean} enforceRequired 必須を確かめるか（代理予約では false）。
 * @return {Object} 項目 ID => エラーメッセージ。エラーが無ければ空のオブジェクト。
 */
export const validateCustomFieldValues = (
	definitions,
	values,
	enforceRequired
) => {
	const errors = {};
	definitions.forEach( ( definition ) => {
		const rawValue =
			typeof values?.[ definition.id ] === 'string'
				? values[ definition.id ]
				: definition.type === 'number'
				? definition.defaultValue
				: '';
		const value = rawValue.trim();
		if ( enforceRequired && definition.required && value === '' ) {
			const message = [ 'select', 'radio' ].includes( definition.type )
				? /* translators: %s: Booking input field label. */
				  _x(
						'Please select %s.',
						'booking input field',
						'vk-booking-manager'
				  )
				: /* translators: %s: Booking input field label. */
				  __( 'Please enter %s.', 'vk-booking-manager' );
			errors[ definition.id ] = sprintf( message, definition.label );
			return;
		}
		if ( Array.from( value ).length > definition.maxLength ) {
			errors[ definition.id ] = sprintf(
				/* translators: 1: Booking input field label, 2: Maximum number of characters. */
				__(
					'%1$s must be %2$d characters or fewer.',
					'vk-booking-manager'
				),
				definition.label,
				definition.maxLength
			);
			return;
		}
		if (
			rawValue !== '' &&
			[ 'select', 'radio' ].includes( definition.type ) &&
			! definition.choices.includes( rawValue )
		) {
			errors[ definition.id ] = __(
				'Please select a valid choice.',
				'vk-booking-manager'
			);
		}
		if (
			rawValue !== '' &&
			definition.type === 'number' &&
			! (
				/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?$/.test(
					rawValue
				) && Number.isFinite( Number( rawValue ) )
			)
		) {
			errors[ definition.id ] = __(
				'Please enter a valid number.',
				'vk-booking-manager'
			);
		}
	} );
	return errors;
};

/**
 * 送信する回答を、定義にある項目だけに絞って作る。
 *
 * @param {Array}  definitions 項目の定義。
 * @param {Object} values      項目 ID => 入力値。
 * @return {Object} 項目 ID => 入力値。
 */
export const buildCustomFieldsPayload = ( definitions, values ) => {
	const payload = {};
	definitions.forEach( ( definition ) => {
		payload[ definition.id ] =
			typeof values?.[ definition.id ] === 'string'
				? values[ definition.id ]
				: definition.type === 'number'
				? definition.defaultValue
				: '';
	} );
	return payload;
};

/**
 * 下書き・退避データの回答から、文字列の値だけを取り出す。
 *
 * サーバーは回答が無いとき空配列（JSON の []）を返すため、配列もオブジェクトでないものとして扱う。
 *
 * @param {unknown} raw 回答（項目 ID => 値）。
 * @return {Object<string, string>} 項目 ID => 入力値。
 */
export const normalizeCustomFieldValues = ( raw ) => {
	const values = {};
	if ( ! raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return values;
	}
	Object.keys( raw ).forEach( ( key ) => {
		if ( typeof raw[ key ] === 'string' ) {
			values[ key ] = raw[ key ];
		}
	} );
	return values;
};

/**
 * 入力欄の id 属性に使う値を返す。
 *
 * @param {string} id 項目 ID。
 * @return {string} id 属性の値。
 */
export const getCustomFieldInputId = ( id ) =>
	`vkbm-confirm-custom-field-${ id }`;

/**
 * 予約時の入力項目の入力欄を、設定した順に並べる。
 *
 * @param {Object}                              props                 プロパティ。
 * @param {Array}                               props.definitions     項目の定義。
 * @param {Object}                              props.values          項目 ID => 入力値。
 * @param {Object}                              props.errors          項目 ID => エラーメッセージ。
 * @param {boolean}                             props.enforceRequired 必須を示すか（代理予約では false）。
 * @param {boolean}                             props.readOnly        読み取り専用にするか。
 * @param {{id: string}|null}                   props.focusRequest    描画後にフォーカスする項目。
 * @param {(id: string, value: string) => void} props.onChange        入力したときの処理。
 * @return {JSX.Element|null} 入力欄。項目が無ければ null。
 */
const BookingCustomFields = ( {
	definitions,
	values,
	errors,
	enforceRequired,
	readOnly,
	focusRequest,
	onChange,
} ) => {
	const handledFocusRequest = useRef( null );
	// 実際の入力要素が DOM に接続された時点で、各送信の要求を一度だけ処理する。
	const focusRequestedInput = ( definition, node ) => {
		if (
			node &&
			focusRequest?.id === definition.id &&
			handledFocusRequest.current !== focusRequest
		) {
			node.focus();
			handledFocusRequest.current = focusRequest;
		}
	};
	if ( ! definitions.length ) {
		return null;
	}
	return definitions.map( ( definition ) => {
		const inputId = getCustomFieldInputId( definition.id );
		const errorId = `${ inputId }-error`;
		const error = errors?.[ definition.id ] || '';
		const required = enforceRequired && definition.required;
		const value =
			typeof values?.[ definition.id ] === 'string'
				? values[ definition.id ]
				: definition.type === 'number'
				? definition.defaultValue
				: '';
		const firstRadioHasError = Boolean( error );
		const inputProps = {
			id: inputId,
			ref: ( node ) => focusRequestedInput( definition, node ),
			value,
			onChange: ( event ) =>
				onChange( definition.id, event.target.value ),
			maxLength: definition.maxLength,
			required,
			'aria-required': required,
			'aria-invalid': Boolean( error ),
			'aria-describedby': error ? errorId : undefined,
			readOnly,
			// 読み取り専用のときは「ご要望・備考」と同じく、未入力であることを示す。
			placeholder:
				readOnly && definition.type !== 'select'
					? __( 'No entry', 'vk-booking-manager' )
					: undefined,
		};
		return (
			<div className="vkbm-confirm__form" key={ definition.id }>
				{ definition.type === 'radio' ? (
					<span id={ inputId + '-label' }>
						<RequiredLabelText
							label={ definition.label }
							required={ required }
						/>
					</span>
				) : (
					<label htmlFor={ inputId }>
						<RequiredLabelText
							label={ definition.label }
							required={ required }
						/>
					</label>
				) }
				{ definition.type === 'textarea' ? (
					<textarea { ...inputProps } />
				) : definition.type === 'select' ? (
					<select { ...inputProps } disabled={ readOnly }>
						<option value="">
							{ readOnly
								? __( 'Not selected', 'vk-booking-manager' )
								: __( 'Please select', 'vk-booking-manager' ) }
						</option>
						{ definition.choices.map( ( choice ) => (
							<option key={ choice } value={ choice }>
								{ choice }
							</option>
						) ) }
					</select>
				) : definition.type === 'radio' ? (
					readOnly && value === '' ? (
						<p>{ __( 'Not selected', 'vk-booking-manager' ) }</p>
					) : (
						<div
							className="vkbm-confirm__custom-radio"
							role="radiogroup"
							aria-labelledby={ inputId + '-label' }
							aria-invalid={ Boolean( error ) }
							aria-describedby={ error ? errorId : undefined }
						>
							{ definition.choices.map( ( choice, index ) => (
								<label
									key={ choice }
									htmlFor={
										index === 0
											? inputId
											: `${ inputId }-${ index }`
									}
								>
									<input
										ref={
											index === 0
												? ( node ) =>
														focusRequestedInput(
															definition,
															node
														)
												: undefined
										}
										id={
											index === 0
												? inputId
												: `${ inputId }-${ index }`
										}
										type="radio"
										name={ inputId }
										value={ choice }
										checked={ value === choice }
										onChange={ () =>
											onChange( definition.id, choice )
										}
										disabled={ readOnly }
										required={ required && index === 0 }
										aria-invalid={
											firstRadioHasError && index === 0
												? true
												: undefined
										}
										aria-describedby={
											firstRadioHasError && index === 0
												? errorId
												: undefined
										}
									/>
									{ choice }
								</label>
							) ) }
						</div>
					)
				) : (
					<input
						type={
							definition.type === 'number' ? 'number' : 'text'
						}
						step={
							definition.type === 'number' ? 'any' : undefined
						}
						autoComplete="off"
						{ ...inputProps }
					/>
				) }
				{ error && (
					<p
						id={ errorId }
						className="vkbm-alert vkbm-alert__danger"
						role="alert"
					>
						{ error }
					</p>
				) }
			</div>
		);
	} );
};

export default BookingCustomFields;
