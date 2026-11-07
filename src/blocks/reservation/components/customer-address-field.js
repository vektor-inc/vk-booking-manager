import { __ } from '@wordpress/i18n';

/**
 * 住所の必須・文字数を検証する。文字数は Unicode のコードポイントで数える。
 *
 * @param {string}  value    入力住所。
 * @param {boolean} required 必須かどうか。
 * @return {string} エラー文、または空文字。
 */
export const validateCustomerAddress = ( value, required ) => {
	if ( Array.from( value ).length > 255 ) {
		return __(
			'Please enter an address of 255 characters or fewer.',
			'vk-booking-manager'
		);
	}
	if ( required && ! value.trim() ) {
		return __( 'Please enter your address.', 'vk-booking-manager' );
	}
	return '';
};

/**
 * 予約時点の住所を入力する共通欄。
 *
 * @param {Object}                  props              プロパティ。
 * @param {string}                  props.value        入力住所。
 * @param {boolean}                 props.required     必須か。
 * @param {boolean}                 props.readOnly     読み取り専用か。既定は false。
 * @param {string}                  props.error        エラー文。
 * @param {Object}                  props.inputRef     入力欄への参照。
 * @param {boolean}                 props.inGroup      顧客情報の箱に入れるか。
 * @param {boolean}                 props.isOwnBooking 本人予約か。
 * @param {(value: string) => void} props.onChange     入力更新関数。
 * @return {JSX.Element} 住所欄。
 */
export const CustomerAddressField = ( {
	value,
	required,
	readOnly = false,
	error,
	inputRef,
	inGroup = false,
	isOwnBooking = false,
	onChange,
} ) => (
	<div
		className={
			inGroup
				? 'vkbm-confirm__address-group-item'
				: 'vkbm-confirm__address'
		}
	>
		<div className="vkbm-confirm__address-field">
			<label htmlFor="vkbm-confirm-address">
				{ required
					? __( 'Address (required)', 'vk-booking-manager' )
					: __( 'Address (optional)', 'vk-booking-manager' ) }
			</label>
			<input
				type="text"
				id="vkbm-confirm-address"
				ref={ inputRef }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
				autoComplete="street-address"
				required={ required && ! readOnly }
				readOnly={ readOnly }
				aria-invalid={ error ? 'true' : undefined }
				aria-describedby={
					error
						? 'vkbm-confirm-address-error vkbm-confirm-address-help'
						: 'vkbm-confirm-address-help'
				}
			/>
			{ error && (
				<div
					id="vkbm-confirm-address-error"
					className="vkbm-alert vkbm-alert__danger vkbm-alert--compact"
					role="alert"
				>
					{ error }
				</div>
			) }
			<p
				className="vkbm-confirm__address-help"
				id="vkbm-confirm-address-help"
			>
				{ __(
					'Please enter your domestic or international address in 255 characters or fewer.',
					'vk-booking-manager'
				) }
				{ isOwnBooking && (
					<>
						{ ' ' }
						<span className="vkbm-confirm__address-booking-note">
							{ __(
								'Address changes apply only to this booking.',
								'vk-booking-manager'
							) }
						</span>
					</>
				) }
			</p>
		</div>
	</div>
);
