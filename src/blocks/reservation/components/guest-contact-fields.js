import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';

/**
 * 「どちらでも」のときにだけ、ゲストの入力欄の上へ出す案内。
 * 会員登録済みのお客様が、ログイン・新規登録の欄へ切り替えるための導線。
 *
 * @param {Object}   props            プロパティ。
 * @param {Function} props.onLogin    [ログイン] を押したときの処理。
 * @param {Function} props.onRegister [新規登録] を押したときの処理。
 * @return {JSX.Element} 案内の段落。
 */
export const GuestLoginPrompt = ( { onLogin, onRegister } ) => (
	<p className="vkbm-confirm__guest-login-prompt">
		{ createInterpolateElement(
			__(
				'Registered members can skip entering these details by <login>logging in</login>.',
				'vk-booking-manager'
			),
			{
				login: (
					<button
						type="button"
						className="vkbm-confirm__link-button"
						onClick={ onLogin }
					/>
				),
			}
		) }{ ' ' }
		<button
			type="button"
			className="vkbm-confirm__link-button"
			onClick={ onRegister }
		>
			{ __( 'Sign up', 'vk-booking-manager' ) }
		</button>
	</p>
);

/**
 * 会員登録せずに予約するお客様の、ログイン・新規登録の欄から戻る導線。
 *
 * @param {Object}   props         プロパティ。
 * @param {Function} props.onClick 押したときの処理（入力途中のお客様情報は消さない）。
 * @return {JSX.Element} 戻るボタン。
 */
export const GuestBackButton = ( { onClick } ) => (
	<p className="vkbm-confirm__guest-back">
		<button
			type="button"
			className="vkbm-confirm__link-button"
			onClick={ onClick }
		>
			{ __(
				'Make a reservation without registering',
				'vk-booking-manager'
			) }
		</button>
	</p>
);

/**
 * 1 欄分（ラベル・入力欄・補足・エラー）。
 *
 * @param {Object}   props              プロパティ。
 * @param {string}   props.id           入力欄の id。
 * @param {string}   props.label        ラベル。
 * @param {string}   props.type         input の type。
 * @param {string}   props.autoComplete ブラウザの自動入力の指定。
 * @param {string}   props.value        入力値。
 * @param {string}   props.error        エラー文。空ならエラーなし。
 * @param {string}   props.help         補足。空なら出さない。
 * @param {Object}   props.inputRef     入力欄の ref（エラー時のフォーカスに使う）。
 * @param {number}   props.maxLength    最大文字数。
 * @param {Function} props.onChange     入力時の処理（値を受け取る）。
 * @param {string}   props.inputMode    input の inputMode。
 * @return {JSX.Element} 1 欄分の要素。
 */
const GuestField = ( {
	id,
	label,
	type,
	autoComplete,
	value,
	error,
	help = '',
	inputRef,
	maxLength,
	onChange,
	inputMode,
} ) => {
	const errorId = `${ id }-error`;
	const helpId = `${ id }-help`;
	const describedBy =
		[ error ? errorId : '', help ? helpId : '' ]
			.filter( Boolean )
			.join( ' ' ) || undefined;

	return (
		<div className="vkbm-confirm__guest-field">
			<label htmlFor={ id }>{ label }</label>
			<input
				ref={ inputRef }
				type={ type }
				id={ id }
				value={ value }
				required
				aria-required="true"
				aria-invalid={ error ? 'true' : undefined }
				aria-describedby={ describedBy }
				autoComplete={ autoComplete }
				inputMode={ inputMode }
				maxLength={ maxLength }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			{ error && (
				<div
					id={ errorId }
					className="vkbm-alert vkbm-alert__danger vkbm-alert--compact"
					role="alert"
				>
					{ error }
				</div>
			) }
			{ help && (
				<p id={ helpId } className="vkbm-confirm__guest-field-help">
					{ help }
				</p>
			) }
		</div>
	);
};

/**
 * 「お客様情報」欄（お名前・メールアドレス・電話番号。すべて必須）。
 *
 * @param {Object}   props                  プロパティ。
 * @param {Object}   props.values           name・email・phone の入力値。
 * @param {Object}   props.errors           name・email・phone のエラー文。
 * @param {Object}   props.inputRefs        name・email・phone の入力欄の ref。
 * @param {string}   props.honeypot         隠し入力欄の値。
 * @param {Function} props.onChange         入力時の処理（欄の名前と値を受け取る）。
 * @param {Function} props.onHoneypotChange 隠し入力欄の入力時の処理。
 * @return {JSX.Element} お客様情報欄。
 */
export const GuestContactFields = ( {
	values,
	errors,
	inputRefs,
	honeypot,
	onChange,
	onHoneypotChange,
} ) => (
	<div className="vkbm-confirm__guest-fields">
		<h3 className="vkbm-confirm__guest-title">
			{ __( 'Customer information', 'vk-booking-manager' ) }
		</h3>
		<GuestField
			id="vkbm-confirm-guest-name"
			label={ __(
				'Name of the person making the reservation (required)',
				'vk-booking-manager'
			) }
			type="text"
			autoComplete="name"
			value={ values.name }
			error={ errors.name }
			inputRef={ inputRefs.name }
			maxLength={ 100 }
			onChange={ ( value ) => onChange( 'name', value ) }
		/>
		<GuestField
			id="vkbm-confirm-guest-email"
			label={ __( 'Email address (required)', 'vk-booking-manager' ) }
			type="email"
			autoComplete="email"
			inputMode="email"
			value={ values.email }
			error={ errors.email }
			help={ __(
				'We will send the confirmation email and the cancellation link to this address.',
				'vk-booking-manager'
			) }
			inputRef={ inputRefs.email }
			maxLength={ 254 }
			onChange={ ( value ) => onChange( 'email', value ) }
		/>
		<GuestField
			id="vkbm-confirm-guest-phone"
			label={ __( 'Phone number (required)', 'vk-booking-manager' ) }
			type="tel"
			autoComplete="tel"
			inputMode="tel"
			value={ values.phone }
			error={ errors.phone }
			inputRef={ inputRefs.phone }
			maxLength={ 30 }
			onChange={ ( value ) => onChange( 'phone', value ) }
		/>
		{ /* ロボット対策の隠し入力欄。人には見えず、キー操作でも届かない。入力があればサーバーが断る。 */ }
		<div className="vkbm-confirm__guest-hp" aria-hidden="true">
			<input
				type="text"
				name="vkbm_hp_email"
				tabIndex={ -1 }
				autoComplete="off"
				value={ honeypot }
				onChange={ ( event ) => onHoneypotChange( event.target.value ) }
			/>
		</div>
	</div>
);
