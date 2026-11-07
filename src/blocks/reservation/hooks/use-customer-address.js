import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * 共有された本人情報から住所を初期入力し、取得待ちの手入力を保護する。
 *
 * @param {Object}  options             取得条件。
 * @param {boolean} options.enabled     ログイン中の宿泊予約か。
 * @param {boolean} options.isProxy     代理予約か。
 * @param {Object}  options.currentUser 共有された REST の本人情報。
 * @return {Object} 住所と利用者入力の更新関数。
 */
export const useCustomerAddress = ( { enabled, isProxy, currentUser } ) => {
	const [ address, setAddressValue ] = useState( '' );
	const edited = useRef( false );
	useEffect( () => {
		// 空欄への編集も保護し、代理予約には管理者自身の住所を使わない。
		if (
			enabled &&
			! isProxy &&
			currentUser &&
			! currentUser.can_manage_reservations &&
			! edited.current
		) {
			setAddressValue( currentUser.address_full || '' );
		}
	}, [ enabled, isProxy, currentUser ] );
	return {
		address,
		setAddress: ( value ) => {
			edited.current = true;
			setAddressValue( value );
		},
	};
};
