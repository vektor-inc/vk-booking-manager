import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

// 名簿に無い人を手入力する選択肢の値。サーバー側の ID_MANUAL と同じ値にそろえる。
export const MANUAL_ID = 'manual';

// 代理予約で、電話番号の照合を始める最小の桁数。入力の途中で名簿が出たり消えたりしないようにする。
const MIN_PHONE_DIGITS = 10;

// 電話番号の入力が止まってから名簿を引くまでの待ち時間（ミリ秒）。
const PHONE_LOOKUP_DELAY = 500;

/**
 * v4 の UUID を作る。
 *
 * @return {string} UUID。
 */
export const generateUuid = () => {
	const cryptoApi = typeof window !== 'undefined' ? window.crypto : null;
	if ( cryptoApi && typeof cryptoApi.randomUUID === 'function' ) {
		return cryptoApi.randomUUID();
	}

	const bytes = new Uint8Array( 16 );
	if ( cryptoApi && typeof cryptoApi.getRandomValues === 'function' ) {
		cryptoApi.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < bytes.length; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	// v4 のバージョンとバリアントのビットを立てる。
	bytes[ 6 ] = ( bytes[ 6 ] % 16 ) + 64;
	bytes[ 8 ] = ( bytes[ 8 ] % 64 ) + 128;
	const hex = Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	return `${ hex.slice( 0, 8 ) }-${ hex.slice( 8, 12 ) }-${ hex.slice(
		12,
		16
	) }-${ hex.slice( 16, 20 ) }-${ hex.slice( 20 ) }`;
};

/**
 * 画面を開いたときに選んでおく選択肢を返す。
 *
 * 選択肢が名簿の人だけで1つなら、それを選んだ状態で始める。名簿が空なら手入力だけにする。
 *
 * @param {Object} options サーバーが返した選択肢。
 * @return {string} 選んでおく ID。未選択なら空文字。
 */
export const resolveDefaultSelection = ( options ) => {
	const items = Array.isArray( options?.items ) ? options.items : [];
	if ( items.length === 0 ) {
		return MANUAL_ID;
	}
	return items.length === 1 ? items[ 0 ].id : '';
};

/**
 * 選択内容の不足を返す。
 *
 * 代理予約は、ご利用になる方を空のまま確定できる。
 *
 * @param {Object}  args
 * @param {string}  args.selection 選んだ ID。
 * @param {string}  args.name      手入力した名前。
 * @param {boolean} args.isProxy   代理予約か。
 * @return {string} 'select'（未選択）、'name'（手入力の名前が空）、または空文字（問題なし）。
 */
export const findSelectionProblem = ( { selection, name, isProxy } ) => {
	if ( isProxy ) {
		return '';
	}
	if ( ! selection ) {
		return 'select';
	}
	if ( selection === MANUAL_ID && String( name ).trim() === '' ) {
		return 'name';
	}
	return '';
};

/**
 * 予約確定の API に送る、ご利用になる方の値を組み立てる。
 *
 * 名簿の人は ID だけを送り、名前と番号はサーバーが名簿から写す。
 *
 * @param {Object}  args
 * @param {Object}  args.options   サーバーが返した選択肢。
 * @param {string}  args.selection 選んだ ID。
 * @param {Object}  args.manual    手入力した値（name / kana / number）。
 * @param {boolean} args.save      名簿に保存するか。
 * @param {string}  args.newId     新しい人に付ける UUID。
 * @param {boolean} args.isProxy   代理予約か。
 * @return {Object} API に送る値。
 */
export const buildAttendeePayload = ( {
	options,
	selection,
	manual,
	save,
	newId,
	isProxy,
} ) => {
	const payload = { attendee_id: selection };
	if ( selection !== MANUAL_ID ) {
		return payload;
	}

	payload.attendee_name = manual.name;
	if ( options?.kana_enabled ) {
		payload.attendee_kana = manual.kana;
	}
	if ( isProxy && options?.number_enabled ) {
		payload.attendee_number = manual.number;
	}
	if ( options?.can_save && save && manual.name.trim() !== '' ) {
		payload.attendee_save_to_roster = true;
		payload.attendee_new_id = newId;
	}
	return payload;
};

/**
 * 照合用の電話番号の比較キーを作る。数字だけを残し、全角数字は半角にそろえる。
 *
 * ハイフンや全角数字の違いだけで取り直しが走らないように、比較にはこのキーを使う。
 *
 * @param {string} phone 入力された電話番号。
 * @return {string} 数字だけのキー。
 */
export const toPhoneKey = ( phone ) =>
	String( phone || '' )
		.replace( /[^0-9０-９]/g, '' )
		.replace( /[０-９]/g, ( digit ) =>
			String.fromCharCode( digit.charCodeAt( 0 ) - 0xfee0 )
		);

/**
 * 選択肢が古い（取り直しが必要、または取り直し中）か。
 * 照合電話番号のキーが、取得済みのものと違えば古いものとして扱う。
 *
 * @param {Object}      args
 * @param {boolean}     args.enabled   名簿を使う設定か。
 * @param {string|null} args.loadedKey 取得が終わった照合キー（未取得は null）。
 * @param {string}      args.lookupKey いま照合に使うキー。
 * @return {boolean} 古い（確定を止めるべき）なら true。
 */
export const isAttendeeOptionsLoading = ( { enabled, loadedKey, lookupKey } ) =>
	enabled && loadedKey !== lookupKey;

/**
 * 名簿の選択肢をサーバーから取得する。
 *
 * 代理予約では、電話番号が一致した会員の名簿を取得する。
 *
 * @param {Object}  args
 * @param {boolean} args.enabled       取得してよいか（ログイン済みか）。
 * @param {boolean} args.isProxy       代理予約か。
 * @param {string}  args.customerPhone 代理予約で入力された電話番号。
 * @return {{options: Object|null, loading: boolean, stale: boolean, refetch: () => void}} 選択肢（取得前・取得失敗時は null）、最初の取得中か（表示用）、選択肢が古いか（最初の取得中と、照合電話番号が変わってから取り直しが終わるまでの間は true。確定を止める用）、取り直す関数。
 */
export const useAttendeeOptions = ( { enabled, isProxy, customerPhone } ) => {
	const [ options, setOptions ] = useState( null );
	// 取得が終わった照合キー。null は未取得。
	const [ loadedKey, setLoadedKey ] = useState( null );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const refetch = useCallback( () => setReloadKey( ( key ) => key + 1 ), [] );
	const phoneRef = useRef( customerPhone );
	phoneRef.current = customerPhone;
	const digitsKey = toPhoneKey( customerPhone );
	const lookupKey =
		isProxy && digitsKey.length >= MIN_PHONE_DIGITS ? digitsKey : '';

	useEffect( () => {
		if ( ! enabled ) {
			setOptions( null );
			setLoadedKey( null );
			return undefined;
		}

		let cancelled = false;
		const timer = window.setTimeout(
			() => {
				apiFetch( {
					path: addQueryArgs( '/vkbm/v1/booking-attendees', {
						customer_phone: lookupKey
							? phoneRef.current
							: undefined,
					} ),
				} )
					.then( ( response ) => {
						if ( ! cancelled ) {
							setOptions( response );
							setLoadedKey( lookupKey );
						}
					} )
					.catch( () => {
						// 取得できない場合は名簿を使わず、従来の入力欄にする。
						if ( ! cancelled ) {
							setOptions( null );
							setLoadedKey( lookupKey );
						}
					} );
			},
			isProxy ? PHONE_LOOKUP_DELAY : 0
		);

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ enabled, isProxy, lookupKey, reloadKey ] );

	return {
		options,
		// 画面上部の読み込み表示用。最初の取得中だけ true。
		loading: enabled && loadedKey === null,
		// 確定を止める用。電話番号が変わってから取り直しが終わるまでも true（前の会員の選択肢を使わせない）。
		stale: isAttendeeOptionsLoading( { enabled, loadedKey, lookupKey } ),
		refetch,
	};
};

/**
 * ご利用になる方の選択状態を管理する。
 *
 * @param {Object}      args
 * @param {Object|null} args.options サーバーが返した選択肢。
 * @param {boolean}     args.isProxy 代理予約か。
 * @return {Object} 選択状態と操作。
 */
export const useAttendeeSelection = ( { options, isProxy } ) => {
	const [ selection, setSelection ] = useState( '' );
	const [ manual, setManual ] = useState( {
		name: '',
		kana: '',
		number: '',
	} );
	// 会員自身の予約は保存を既定でオンにし、代理予約は他人の名簿を変えるためオフにする。
	const [ save, setSave ] = useState( ! isProxy );
	const [ newId ] = useState( generateUuid );
	const [ problem, setProblem ] = useState( '' );
	const [ focusRequest, setFocusRequest ] = useState( 0 );
	// 名簿へ保存する送信が失敗したあと、取り直した選択肢で選択済みにする ID。
	const pendingSelectionRef = useRef( null );
	const fieldsetRef = useRef( null );
	const nameRef = useRef( null );

	// 選択肢が変わったとき（代理予約で会員が変わったとき）は、選び直しにする。
	useEffect( () => {
		const pending = pendingSelectionRef.current;
		pendingSelectionRef.current = null;
		const items = Array.isArray( options?.items ) ? options.items : [];
		if ( pending !== null ) {
			// 名簿に入っていれば選択済みにし、入っていなければ今の選択をそのまま残す。
			if ( items.some( ( item ) => item.id === pending ) ) {
				setSelection( pending );
			}
			return;
		}
		setSelection( resolveDefaultSelection( options ) );
		setProblem( '' );
	}, [ options ] );

	const selectAfterRefresh = useCallback( ( id ) => {
		pendingSelectionRef.current = id;
	}, [] );

	// 権限が後から確定した場合も、既定値を権限に合わせる。
	useEffect( () => {
		setSave( ! isProxy );
	}, [ isProxy ] );

	const selectOption = useCallback( ( id ) => {
		setSelection( id );
		setProblem( '' );
	}, [] );

	const changeManual = useCallback( ( key, value ) => {
		setManual( ( current ) => ( { ...current, [ key ]: value } ) );
		setProblem( '' );
	}, [] );

	const validate = () => {
		const found = findSelectionProblem( {
			selection,
			name: manual.name,
			isProxy,
		} );
		setProblem( found );
		if ( found ) {
			setFocusRequest( ( request ) => request + 1 );
		}
		return found === '';
	};

	const buildPayload = () =>
		buildAttendeePayload( {
			options,
			selection,
			manual,
			save,
			newId,
			isProxy,
		} );

	return {
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
		selectAfterRefresh,
		validate,
		buildPayload,
	};
};
