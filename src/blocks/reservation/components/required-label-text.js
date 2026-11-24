import { _x, sprintf } from '@wordpress/i18n';

/**
 * 入力欄の見出し。必須なら項目名の後ろに「（必須）」を付ける。
 *
 * 狭い幅で「（必須）」だけが次の行に落ちないよう、項目名の最後の 1 文字と「（必須）」を折り返さないかたまりにする。
 *
 * @param {Object}  props          プロパティ。
 * @param {string}  props.label    項目名。
 * @param {boolean} props.required 必須か。
 * @return {JSX.Element} 見出しの中身。
 */
export const RequiredLabelText = ( { label, required } ) => {
	if ( ! required ) {
		return <>{ label }</>;
	}
	const characters = Array.from( label );
	const last = characters.pop() || '';
	return (
		<>
			{ characters.join( '' ) }
			<span className="text-nowrap">
				{ sprintf(
					/* translators: %s: Last character of the form field label. */
					_x(
						'%s (required)',
						'form field label with a required mark',
						'vk-booking-manager'
					),
					last
				) }
			</span>
		</>
	);
};
