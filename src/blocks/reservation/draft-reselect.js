import { sanitizeDraftToken } from '../shared/draft-token';
import { normalizeCustomFieldValues } from './components/booking-custom-fields';

const FORM_KEY_PREFIX = 'vkbm-confirm-form:';

// 下書きの有効期限（サーバー側 Booking_Draft_Controller::TTL_SECONDS）と合わせる。
// 片方だけ変えると、期限切れの下書きに入力値が残る、または有効な下書きの入力値が消える。
export const CONFIRM_FIELDS_TTL_MS = 30 * 60 * 1000;

/**
 * 確認画面から日時選択へ戻る URL を作る。
 *
 * @param {string} currentUrl 現在の URL。
 * @param {Object} draft      所有者確認済みの下書き。
 * @param {string} token      下書きのトークン。
 * @return {string} 日時選択画面の URL。
 */
export const buildDatetimeReselectUrl = ( currentUrl, draft, token ) => {
	const safeToken = sanitizeDraftToken( token );
	if ( ! safeToken || ! Number( draft?.menu_id ) ) {
		return '';
	}
	const url = new URL( currentUrl );
	url.searchParams.delete( 'vkbm_auth' );
	url.searchParams.delete( 'vkbm_view' );
	url.searchParams.set( 'draft', safeToken );
	url.searchParams.set( 'vkbm_reselect', '1' );
	url.searchParams.set( 'menu_id', String( draft.menu_id ) );
	// 宿泊の resource_id は客室 ID で、URL の resource_id はスタッフ指名として読まれるため付けない。
	if ( ! draft.lodging && Number( draft.resource_id ) > 0 ) {
		url.searchParams.set( 'resource_id', String( draft.resource_id ) );
	} else {
		url.searchParams.delete( 'resource_id' );
	}
	const selectedDate = draft.lodging?.check_in_date || draft.date;
	if ( selectedDate ) {
		url.searchParams.set( 'date', selectedDate );
	} else {
		url.searchParams.delete( 'date' );
	}
	if ( draft.lodging?.check_out_date ) {
		url.searchParams.set( 'check_out_date', draft.lodging.check_out_date );
	} else {
		url.searchParams.delete( 'check_out_date' );
	}
	return url.toString();
};

/**
 * 読み込めない下書きから、選択内容を引き継がず予約画面へ戻る URL を作る。
 *
 * @param {string} reservationPageUrl 設定済みの予約ページ URL。
 * @param {string} currentUrl         現在の URL。予約ページ URL がない場合の代替。
 * @return {string} 予約を最初から選ぶ画面の URL。
 */
export const buildReservationStartUrl = ( reservationPageUrl, currentUrl ) => {
	const url = new URL( reservationPageUrl || currentUrl, currentUrl );
	// 下書きだけでなく選択済みのメニュー・日時も外し、最初の選択画面を開く。
	[
		'draft',
		'vkbm_auth',
		'vkbm_view',
		'vkbm_reselect',
		'menu_id',
		'resource_id',
		'date',
		'check_out_date',
	].forEach( ( key ) => url.searchParams.delete( key ) );
	return url.toString();
};

/**
 * 文字列だけを取り出す（それ以外は空文字）。
 *
 * @param {unknown} value 値。
 * @return {string} 文字列。
 */
const toText = ( value ) => ( typeof value === 'string' ? value : '' );

/**
 * 保存されていた入力値から、既知の項目だけを型をそろえて取り出す。
 *
 * @param {unknown} fields 保存されていた入力値。
 * @return {Object} 確認画面へ戻す入力値。
 */
export const normalizeConfirmFields = ( fields ) => {
	const source = fields && typeof fields === 'object' ? fields : {};
	const guestContact =
		source.guestContact && typeof source.guestContact === 'object'
			? source.guestContact
			: {};
	const attendee =
		source.attendee && typeof source.attendee === 'object'
			? source.attendee
			: {};
	const manual =
		attendee.manual && typeof attendee.manual === 'object'
			? attendee.manual
			: {};
	return {
		memo: toText( source.memo ),
		attendeeName: toText( source.attendeeName ),
		customFields: normalizeCustomFieldValues( source.customFields ),
		customerAddress: toText( source.customerAddress ),
		customerAddressEdited: source.customerAddressEdited === true,
		guestContact: {
			name: toText( guestContact.name ),
			email: toText( guestContact.email ),
			phone: toText( guestContact.phone ),
		},
		customerName: toText( source.customerName ),
		customerPhone: toText( source.customerPhone ),
		providerNote: toText( source.providerNote ),
		paymentMethod: toText( source.paymentMethod ),
		favoriteOptIn: source.favoriteOptIn === true,
		attendee: {
			selection: toText( attendee.selection ),
			manual: {
				name: toText( manual.name ),
				kana: toText( manual.kana ),
				number: toText( manual.number ),
			},
			save: typeof attendee.save === 'boolean' ? attendee.save : null,
		},
	};
};

/**
 * 確認画面で入力した内容を、同じタブの下書きごとに保持する。
 *
 * @param {Storage} storage 保存先。
 * @param {string}  token   下書きのトークン。
 * @param {number}  menuId  下書きのメニュー ID。
 * @param {Object}  fields  入力値。
 * @param {number}  now     保存時刻（ミリ秒）。
 */
export const saveConfirmFields = (
	storage,
	token,
	menuId,
	fields,
	now = Date.now()
) => {
	const safeToken = sanitizeDraftToken( token );
	if ( safeToken ) {
		storage.setItem(
			`${ FORM_KEY_PREFIX }${ safeToken }`,
			JSON.stringify( {
				menu_id: Number( menuId ) || 0,
				savedAt: now,
				fields: normalizeConfirmFields( fields ),
			} )
		);
	}
};

/**
 * 同じタブで保存した確認画面の入力値を取り出す。
 * 別のメニューの下書きの値・期限切れの値は捨てる。
 *
 * @param {Storage} storage 保存先。
 * @param {string}  token   下書きのトークン。
 * @param {number}  menuId  いま開いている下書きのメニュー ID。
 * @param {number}  now     現在時刻（ミリ秒）。
 * @return {Object|null} 入力値。
 */
export const loadConfirmFields = (
	storage,
	token,
	menuId,
	now = Date.now()
) => {
	const safeToken = sanitizeDraftToken( token );
	if ( ! safeToken ) {
		return null;
	}
	const key = `${ FORM_KEY_PREFIX }${ safeToken }`;
	let saved = null;
	try {
		const value = storage.getItem( key );
		saved = value ? JSON.parse( value ) : null;
	} catch ( error ) {
		saved = null;
	}
	if ( ! saved || typeof saved !== 'object' ) {
		return null;
	}
	// メニューが違う、または期限を過ぎた値は、取り違えを防ぐため消してから捨てる。
	const savedAt = Number( saved.savedAt ) || 0;
	const isSameMenu =
		Number( menuId ) > 0 && Number( saved.menu_id ) === Number( menuId );
	if (
		! isSameMenu ||
		savedAt <= 0 ||
		now - savedAt > CONFIRM_FIELDS_TTL_MS
	) {
		storage.removeItem( key );
		return null;
	}
	return normalizeConfirmFields( saved.fields );
};

/**
 * 予約確定後に保持した入力値を削除する。
 *
 * @param {Storage} storage 保存先。
 * @param {string}  token   下書きのトークン。
 */
export const clearConfirmFields = ( storage, token ) => {
	const safeToken = sanitizeDraftToken( token );
	if ( safeToken ) {
		storage.removeItem( `${ FORM_KEY_PREFIX }${ safeToken }` );
	}
};

/**
 * 保持している確認画面の入力値を、下書きを問わずすべて削除する。
 * 確認画面からの戻りではない通常の予約画面を開いたときに使う。
 *
 * @param {Storage} storage 保存先。
 */
export const clearAllConfirmFields = ( storage ) => {
	const keys = [];
	for ( let index = 0; index < storage.length; index++ ) {
		const key = storage.key( index );
		if ( typeof key === 'string' && key.startsWith( FORM_KEY_PREFIX ) ) {
			keys.push( key );
		}
	}
	keys.forEach( ( key ) => storage.removeItem( key ) );
};

/**
 * ID の配列を、数値の昇順にそろえる。
 *
 * @param {unknown} ids ID の配列。
 * @return {Array<number>} そろえた ID の配列。
 */
const normalizeIdList = ( ids ) =>
	( Array.isArray( ids ) ? ids : [] )
		.map( ( id ) => Number( id ) || 0 )
		.filter( Boolean )
		.sort( ( a, b ) => a - b );

/**
 * 空き枠の問い合わせ条件を、比較できる1つの文字列にする。
 *
 * @param {Object}        args
 * @param {number}        args.menuId     メニュー ID。
 * @param {string}        args.date       日付（YYYY-MM-DD）。
 * @param {number}        args.resourceId スタッフ ID（指名なしは 0）。
 * @param {Array<number>} args.tagIds     リソースタグ ID。
 * @return {string} 問い合わせ条件のキー。
 */
export const buildSlotRequestKey = ( { menuId, date, resourceId, tagIds } ) =>
	JSON.stringify( [
		Number( menuId ) || 0,
		date || '',
		Number( resourceId ) || 0,
		normalizeIdList( tagIds ),
	] );

/**
 * 2つの ID の配列が、順序を問わず同じ ID の組か判定する。
 *
 * @param {unknown} first  ID の配列。
 * @param {unknown} second ID の配列。
 * @return {boolean} 同じ組なら true。
 */
export const isSameIdSet = ( first, second ) =>
	JSON.stringify( normalizeIdList( first ) ) ===
	JSON.stringify( normalizeIdList( second ) );

/**
 * 下書きで選んでいた枠を、この問い合わせの結果から戻す（または埋まっていたと伝える）途中か判定する。
 * 途中のあいだは、枠が1つしかないときの自動選択で、選んでいた枠と別の枠を選ばないようにする。
 *
 * @param {Object|null} draft       所有者確認済みの下書き（戻し終えたら null）。
 * @param {Object}      args        問い合わせ条件。
 * @param {number}      args.menuId メニュー ID。
 * @param {string}      args.date   日付（YYYY-MM-DD）。
 * @return {boolean} 枠を戻す途中なら true。
 */
export const isSlotRestorePending = ( draft, { menuId, date } ) =>
	Boolean( draft?.slot?.slot_id ) &&
	Number( draft.menu_id ) === Number( menuId ) &&
	draft.date === date;

/**
 * 枠が1つしかないときの自動選択を見送るか判定する。
 * 下書きの枠を戻す途中と、選んでいた枠が埋まっていた案内（slotLost）を出しているあいだは、
 * 案内と食い違う別の枠を選ばない。利用者が予約条件や枠を選び直して案内が消えれば、通常の自動選択に戻る。
 *
 * @param {Object}      args                 判定に使う状態。
 * @param {Object|null} args.draft           所有者確認済みの下書き（戻し終えたら null）。
 * @param {string}      args.carryNoticeKind 表示中の引き継ぎの案内の種類（無ければ空）。
 * @param {number}      args.menuId          メニュー ID。
 * @param {string}      args.date            日付（YYYY-MM-DD）。
 * @return {boolean} 自動選択を見送るなら true。
 */
export const shouldSkipOnlySlotAutoSelect = ( {
	draft,
	carryNoticeKind,
	menuId,
	date,
} ) =>
	carryNoticeKind === 'slotLost' ||
	isSlotRestorePending( draft, { menuId, date } );

/**
 * 利用者が予約条件や枠を選び直したとき、埋まっていた枠の案内だけを消す。
 *
 * @param {Object|null} notice 表示中の引き継ぎの案内。
 * @return {Object|null} 選び直した後の案内。
 */
export const clearSlotLostNotice = ( notice ) =>
	notice?.kind === 'slotLost' ? null : notice;

/**
 * 最新の空き枠から、下書きで選んでいた枠を探す（残りが無い枠は選ばない）。
 *
 * @param {Array<Object>} slots 最新の空き枠。
 * @param {Object|null}   draft 所有者確認済みの下書き。
 * @return {Object|null} 選び直せる枠。
 */
export const findRestorableSlot = ( slots, draft ) => {
	const slotId = draft?.slot?.slot_id;
	if ( ! slotId || ! Array.isArray( slots ) ) {
		return null;
	}
	return (
		slots.find(
			( slot ) => slot.slot_id === slotId && Number( slot.remaining ) > 0
		) || null
	);
};
