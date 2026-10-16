import { useEffect, useRef } from '@wordpress/element';

/**
 * 人数の入力文字列から、先頭の余分な 0 を取り除く（#549）。
 *
 * 「0」の後ろに数字を打つと「03」になるが、React は数値入力の DOM の "03" と state の 3 を
 * 同じ値とみなして表示を書き直さないため、入力のたびにここで整える。
 * 空欄・小数（"0.5"）・入力途中の値はそのまま返し、範囲内への丸めは呼び出し側に任せる。
 *
 * @param {string} raw 入力欄の文字列。
 * @return {string} 先頭の 0 を取り除いた文字列。
 */
export const stripLeadingZeros = ( raw ) => {
	if ( typeof raw !== 'string' ) {
		return raw;
	}
	return raw.replace( /^(-?)0+(?=\d)/, '$1' );
};

/**
 * 入力欄の文字を全選択する。
 *
 * type="number" はブラウザにより select() が使えない・例外になることがあるため、
 * 失敗しても画面が止まらないよう例外を握りつぶして false を返す。
 *
 * @param {HTMLInputElement|null} input 入力欄。
 * @return {boolean} 全選択できたら true。
 */
export const selectInputText = ( input ) => {
	if ( ! input || typeof input.select !== 'function' ) {
		return false;
	}
	try {
		input.select();
		return true;
	} catch {
		return false;
	}
};

/**
 * 予約ブロックの人数欄（#549）。
 *
 * type="number" のまま、スマホで打ち替えやすくするため次を加える。
 * - フォーカス時に中の数字を全選択し、打った数字でそのまま置き換わるようにする
 * - 「03」のような先頭の 0 を入力のたびに取り除く
 * - inputMode="numeric" で数字だけのテンキーを出す
 *
 * それ以外の属性（id・className・min・max・aria-* など）はそのまま input に渡す。
 *
 * @param {Object}                 props               input に渡す属性。
 * @param {(event: Event) => void} props.onChange      値の変更時の処理。event.target.value は先頭の 0 を取り除いた値。
 * @param {(event: Event) => void} props.onFocus       フォーカス時の処理（任意）。
 * @param {(event: Event) => void} props.onPointerDown 押したときの処理（任意）。
 * @param {(event: Event) => void} props.onClick       クリック・タップ時の処理（任意）。
 * @return {JSX.Element} 人数欄。
 */
export const GuestCountInput = ( {
	onChange,
	onFocus,
	onPointerDown,
	onClick,
	...props
} ) => {
	const wasFocusedOnPressRef = useRef( false );
	const selectTimerRef = useRef( null );

	// 画面から外れた後に、予約した全選択が走らないよう止める。
	useEffect(
		() => () => {
			clearTimeout( selectTimerRef.current );
		},
		[]
	);

	const handleFocus = ( event ) => {
		// キーボード（Tab）で移ってきたときはここで全選択する。
		selectInputText( event.currentTarget );
		if ( onFocus ) {
			onFocus( event );
		}
	};

	const handlePointerDown = ( event ) => {
		// 押した時点で入力中だったかを控え、入力中の欄を押してカーソルを置き直す操作は邪魔しない。
		// 前のタップで予約した全選択が残っていれば、押し直しのカーソル配置を消さないよう取り消す。
		clearTimeout( selectTimerRef.current );
		selectTimerRef.current = null;
		const input = event.currentTarget;
		wasFocusedOnPressRef.current =
			input.ownerDocument?.activeElement === input;
		if ( onPointerDown ) {
			onPointerDown( event );
		}
	};

	const handleClick = ( event ) => {
		// タップ・クリックでフォーカスしたときは、click の後に全選択し直す。
		// WebKit（iOS Safari）は click の処理中に select() しても直後の既定動作で選択を外すため、
		// click の処理が終わってから選ぶ（#549）。mouseup の既定動作を止める方法は、
		// 上下の矢印の押し続け増減が止まらなくなるおそれがあるため使わない。
		if ( ! wasFocusedOnPressRef.current ) {
			const input = event.currentTarget;
			clearTimeout( selectTimerRef.current );
			selectTimerRef.current = setTimeout( () => {
				selectTimerRef.current = null;
				// 待つ間に別の欄へ移っていたら選ばない。
				if ( input.ownerDocument?.activeElement === input ) {
					selectInputText( input );
				}
			}, 0 );
		}
		wasFocusedOnPressRef.current = true;
		if ( onClick ) {
			onClick( event );
		}
	};

	const handleChange = ( event ) => {
		// React が書き直さない先頭の 0 を DOM 側で取り除き、呼び出し側にも整えた値を渡す。
		const input = event.target;
		const normalized = stripLeadingZeros( input.value );
		if ( normalized !== input.value ) {
			input.value = normalized;
		}
		if ( onChange ) {
			onChange( event );
		}
	};

	return (
		<input
			type="number"
			inputMode="numeric"
			{ ...props }
			onFocus={ handleFocus }
			onPointerDown={ handlePointerDown }
			onClick={ handleClick }
			onChange={ handleChange }
		/>
	);
};
