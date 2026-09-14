import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import { CalendarGrid, DailySlotList, SelectedPlanSummary } from './booking-ui';
import {
	extractMenuBasePrice,
	formatCurrency,
	normalizePriceValue,
} from '../shared/pricing';
import { resolveLoginState } from '../shared/auth';
import { BookingConfirmApp } from './booking-confirm-app';
import { BookingSummaryItems } from './components/booking-summary-items';
import { ReservationHeader } from './components/reservation-header';
import { sanitizeDraftToken } from '../shared/draft-token';
const parseQueryParams = () => {
	if ( typeof window === 'undefined' ) {
		return {};
	}

	try {
		const params = new URLSearchParams( window.location.search );
		const menuId = Number( params.get( 'menu_id' ) ) || 0;
		return {
			menuId,
			// menu_id が明示指定されたリンク経由か（お気に入り・「同じ内容で予約する」・
			// 「予約に進むボタン」など、menu_id を URL に付与して開くすべての導線が該当）。
			// この場合は resource_id 未指定を「指名なし」として扱い、
			// ブロックのデフォルトスタッフ（data-default-resource-id）へフォールバックしない。
			hasExplicitMenuId: menuId > 0,
			staffId: Number( params.get( 'resource_id' ) ) || 0,
			date: params.get( 'date' ) || '',
			auth: params.get( 'vkbm_auth' ) || '',
			draft: sanitizeDraftToken( params.get( 'draft' ) || '' ),
		};
	} catch ( error ) {
		return {};
	}
};

const buildApiPath = ( base, query ) => {
	const searchParams = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			searchParams.append( key, value );
		}
	} );
	return `${ base }?${ searchParams.toString() }`;
};

const DRAFT_COOKIE = 'vkbm_draft_token';

const getDraftTokenFromCookie = () => {
	if ( typeof document === 'undefined' ) {
		return '';
	}
	const match = document.cookie.match(
		new RegExp( `(?:^|; )${ DRAFT_COOKIE }=([^;]*)` )
	);
	return sanitizeDraftToken( match ? decodeURIComponent( match[ 1 ] ) : '' );
};

const storeDraftToken = ( token, maxAge = 1800 ) => {
	if ( typeof document === 'undefined' ) {
		return;
	}
	const safeToken = sanitizeDraftToken( token );
	if ( ! safeToken ) {
		return;
	}
	let cookie = `${ DRAFT_COOKIE }=${ encodeURIComponent(
		safeToken
	) }; path=/; max-age=${ maxAge }; SameSite=Lax`;
	if (
		typeof window !== 'undefined' &&
		window.location?.protocol === 'https:'
	) {
		cookie += '; Secure';
	}
	document.cookie = cookie;
};

const useCollection = ( path, setState ) => {
	useEffect( () => {
		if ( ! path ) {
			setState( [] );
			return () => {};
		}

		let isMounted = true;
		apiFetch( { path } )
			.then( ( result ) => {
				if ( isMounted ) {
					setState( result || [] );
				}
			} )
			.catch( () => {
				if ( isMounted ) {
					setState( [] );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ path, setState ] );
};

const getCurrentUrl = () => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	return window.location.href;
};

const buildLogoutFallbackUrl = ( reservationPageUrl = '' ) => {
	if ( typeof window === 'undefined' ) {
		return '/wp-login.php?action=logout';
	}
	const target =
		typeof reservationPageUrl === 'string' &&
		reservationPageUrl.trim() !== ''
			? reservationPageUrl
			: window.location.href;
	const redirect = encodeURIComponent( target );
	return `/wp-login.php?action=logout&redirect_to=${ redirect }`;
};

const buildModeUrl = ( mode ) => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const url = new URL( window.location.href );

	if ( mode ) {
		url.searchParams.set( 'vkbm_auth', mode );
	} else {
		url.searchParams.delete( 'vkbm_auth' );
	}

	return url.toString();
};

// 「同じ内容で予約する」用の URL を組み立てる。予約フォームは menu_id / resource_id の
// クエリで初期値を復元できるため、過去の予約と同じメニュー・指名スタッフを引き継ぐ。
// 日付（date）は過去/将来いずれも再予約には不適切なため付与しない。
const buildRebookUrl = ( booking, reservationPageUrl = '' ) => {
	if ( typeof window === 'undefined' ) {
		return '';
	}

	const base =
		typeof reservationPageUrl === 'string' &&
		reservationPageUrl.trim() !== ''
			? reservationPageUrl
			: window.location.href;

	let url;
	try {
		url = new URL( base, window.location.href );
	} catch ( error ) {
		return '';
	}

	// マイページ表示モードの名残（vkbm_auth=bookings）や前回のドラフトは引き継がない。
	url.searchParams.delete( 'vkbm_auth' );
	url.searchParams.delete( 'draft' );
	url.searchParams.delete( 'date' );

	const menuId = Number( booking?.menu_id ) || 0;
	if ( menuId > 0 ) {
		url.searchParams.set( 'menu_id', String( menuId ) );
	}

	const resourceId = Number( booking?.resource_id ) || 0;
	if ( resourceId > 0 ) {
		url.searchParams.set( 'resource_id', String( resourceId ) );
	} else {
		url.searchParams.delete( 'resource_id' );
	}

	return url.toString();
};

// メニューのメタ情報から、指名可能なスタッフID配列を正規化して取り出す。
// メニュー選択・お気に入り適用・指名可能判定で共通利用する。
const extractAssignableStaffIds = ( meta ) => {
	const rawIds =
		Array.isArray( meta?._vkbm_staff_ids ) && meta._vkbm_staff_ids.length
			? meta._vkbm_staff_ids
			: Array.isArray( meta?.vkbm_staff_ids ) &&
			  meta.vkbm_staff_ids.length
			? meta.vkbm_staff_ids
			: [];

	return Array.from(
		new Set(
			rawIds
				.map( ( value ) => {
					if ( typeof value === 'number' ) {
						return value;
					}
					if ( typeof value === 'string' ) {
						return Number( value ) || 0;
					}
					return 0;
				} )
				.filter( ( id ) => id > 0 )
		)
	);
};

/**
 * 翻訳済みの2文を、文末が非 ASCII（日本語の句点「。」等）のときは半角スペース無しで、
 * 文末が ASCII のとき（英語など）は半角スペース区切りで連結する（#393）。
 *
 * 個別の記号を列挙する方式（句点・感嘆符・閉じ括弧…）だと、想定していない記号終わりの文で
 * 一貫しない挙動になる（例: 半角の閉じ括弧を列挙に含めると `…(bar)Please set…` のように
 * スペース無しで連結されてしまう）ため、末尾1文字が ASCII かどうかだけで判定する
 * （安藤レビュー指摘）。
 *
 * 翻訳済み文字列そのものに前後の空白を含めると
 * `@wordpress/i18n-no-flanking-whitespace` の ESLint ルールに反するため、
 * 結合はコード側（翻訳を通さない箇所）で行う。PHP側
 * （src/common/class-nomination-min-guests-message.php の join_sentences()）と同型のロジック。
 *
 * @param {string} sentenceA 1文目（すでに翻訳・sprintf済み）。
 * @param {string} sentenceB 2文目（すでに翻訳・sprintf済み）。
 * @return {string} 連結後の文字列。
 */
const joinSentences = ( sentenceA, sentenceB ) => {
	const endsWithAscii = /[\x00-\x7F]$/.test( sentenceA );
	return endsWithAscii ? sentenceA + ' ' + sentenceB : sentenceA + sentenceB;
};

/**
 * 指名を使うメニューの最低申し込み人数（受付制限。#393）の、実際のエラー・警告表示専用の
 * 文言（原因＋対処のセット）を組み立てる。
 *
 * PHP側（Booking_Draft_Controller / Booking_Confirmation_Controller が委譲する
 * src/common/class-nomination-min-guests-message.php の build_message()）と同じ2文を
 * 同じ結合方法で使う。同じ概念を指す英文が複数（警告・エラー）に分かれていたのを
 * 1種類へ統一し、料金区分側の警告にも「どうすればよいか」の文を必ず添える
 * （安藤レビュー指摘）。
 *
 * 人数入力欄の下の**常時表示**の案内文にはこの関数を使わない。常時表示は既に条件を
 * 満たしている状態でも表示されるため、命令形の「〜以上にしてください」が出続けるのは
 * 不自然（植草レビュー指摘）。常時案内には状態に依存しない buildNominationMinGuestsHint()
 * を使う。
 *
 * @param {number} minGuests 最低申し込み人数。
 * @return {string} エラー・警告文言。
 */
const buildNominationMinGuestsMessage = ( minGuests ) =>
	joinSentences(
		sprintf(
			/* translators: %d: minimum number of guests required to book this menu. */
			__(
				'This menu accepts bookings from %d guests.',
				'vk-booking-manager'
			),
			minGuests
		),
		sprintf(
			/* translators: %d: minimum number of guests required to book this menu. */
			__(
				'Please set the number of guests to %d or more.',
				'vk-booking-manager'
			),
			minGuests
		)
	);

/**
 * 指名を使うメニューの最低申し込み人数（受付制限。#393）の**常時表示**の案内文を組み立てる。
 *
 * 人数入力欄の初期値は既に最低申し込み人数へクランプされているため、条件を満たしている
 * 状態でも表示され続ける。buildNominationMinGuestsMessage()（原因＋対処のセット、命令形）を
 * 常時表示に使うと、満たしている状態でも「〜以上にしてください」という指示が出続けて
 * 不自然になるため、状態に依存しない中立の文言を別に用意する（植草レビュー指摘）。
 *
 * @param {number} minGuests 最低申し込み人数。
 * @return {string} 常時案内文。
 */
const buildNominationMinGuestsHint = ( minGuests ) =>
	sprintf(
		/* translators: %d: minimum number of guests required to book this menu. */
		__(
			'This menu requires a minimum of %d guests.',
			'vk-booking-manager'
		),
		minGuests
	);

/**
 * 選択中スロットの残り人数が指名を使うメニューの最低申し込み人数に満たない場合の
 * 案内文を組み立てる（#393）。
 *
 * 単一の人数入力欄・料金区分の両方で共有する（安藤/司レビュー指摘：料金区分側だけ
 * この案内が無いのは不揃い）。
 *
 * @param {number} minGuests 最低申し込み人数。
 * @return {string} 案内文。
 */
const buildSlotBelowNominationMinGuestsMessage = ( minGuests ) =>
	joinSentences(
		sprintf(
			/* translators: %d: minimum number of guests required to book this menu. */
			__(
				'This time slot cannot accept the minimum of %d guests required for this menu.',
				'vk-booking-manager'
			),
			minGuests
		),
		__( 'Please choose another time slot.', 'vk-booking-manager' )
	);

const formatBookingDateTimeParts = ( startAt, endAt ) => {
	if ( ! startAt ) {
		return { date: '', time: '' };
	}

	let settings;
	try {
		settings =
			typeof __experimentalGetSettings === 'function'
				? __experimentalGetSettings()
				: undefined;
	} catch ( error ) {
		settings = undefined;
	}

	const dateFormat = settings?.formats?.date || 'Y/m/d';
	const timeFormat = settings?.formats?.time || 'H:i';
	const wpTimezone = settings?.timezone?.string;

	const startDate = new Date( startAt );
	if ( Number.isNaN( startDate.getTime() ) ) {
		return { date: startAt, time: '' };
	}

	const dateLabel = dateI18n( dateFormat, startDate, wpTimezone );
	const weekdayLabel = dateI18n( 'D', startDate, wpTimezone );
	const startTimeLabel = dateI18n( timeFormat, startDate, wpTimezone );

	let timeLabel = startTimeLabel;
	if ( endAt ) {
		const endDate = new Date( endAt );
		if ( ! Number.isNaN( endDate.getTime() ) ) {
			const endTimeLabel = dateI18n( timeFormat, endDate, wpTimezone );
			timeLabel = `${ startTimeLabel } - ${ endTimeLabel }`.trim();
		}
	}

	return {
		date: `${ dateLabel }(${ weekdayLabel })`,
		time: timeLabel,
	};
};

export const ReservationApp = ( {
	defaultMenuId = 0,
	defaultStaffId = 0,
	allowStaffSelection = true,
	isEditor = false,
} ) => {
	const userBootstrap = useMemo( () => {
		if ( typeof window === 'undefined' ) {
			return null;
		}
		return window.vkbmCurrentUserBootstrap || null;
	}, [] );
	const queryDefaults = useMemo( () => parseQueryParams(), [] );
	const initialMenuId = queryDefaults.menuId || defaultMenuId || 0;
	// menu_id が明示指定されたリンク経由（お気に入り・再予約・予約に進むボタン等）の場合は、
	// resource_id 未指定を「指名なし」として尊重し、デフォルトスタッフへフォールバックしない。
	const initialStaffId = queryDefaults.hasExplicitMenuId
		? queryDefaults.staffId || 0
		: queryDefaults.staffId || defaultStaffId || 0;
	const initialDate = queryDefaults.date || '';
	const initialMonthDate = initialDate ? new Date( initialDate ) : new Date();

	const [ menuId, setMenuId ] = useState( initialMenuId );
	const [ staffId, setStaffId ] = useState( initialStaffId );
	// #431: リソースタグ検索で選択中のタグ（タームID配列）。複数選択時は AND 条件。
	const [ selectedTagIds, setSelectedTagIds ] = useState( [] );
	const [ selectedDate, setSelectedDate ] = useState( initialDate );
	const [ selectedSlot, setSelectedSlot ] = useState( null );
	// 予約人数（複数人一括予約）。複数人一括予約に対応したメニューでのみ使用する。既定は1。
	const [ guests, setGuests ] = useState( 1 );
	// 料金区分ごとの人数（区分インデックス => 人数）。料金区分が定義されたメニューでのみ使用する。
	const [ guestTierCounts, setGuestTierCounts ] = useState( [] );
	// ユーザーによる貸し切り指定（#305）。チェック状態。メニュー設定・人数・空き枠の条件を満たすときのみ有効。
	const [ userExclusive, setUserExclusive ] = useState( false );
	const [ monthCursor, setMonthCursor ] = useState( {
		year: initialMonthDate.getFullYear(),
		month: initialMonthDate.getMonth() + 1,
	} );

	const [ menus, setMenus ] = useState( [] );
	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ providerSettings, setProviderSettings ] = useState( {
		taxEnabled: false,
		taxRate: 0,
		taxLabelText: '',
		reservationPageUrl: '',
		showMenuList: true,
		showMenuSearch: false,
		// #431: 「リソースタグ検索」。指名機能（staffEnabled）の ON/OFF とは独立に判定する。
		resourceTagSearchEnabled: false,
		staffEnabled: false,
		defaultStaffId: 0,
		resourceLabelSingular: __( 'Staff', 'vk-booking-manager' ),
		resourceLabelPlural: __( 'Staff', 'vk-booking-manager' ),
		noNominationLabel: __( 'No preference', 'vk-booking-manager' ),
		nominationFeeLabel: __( 'Nomination fee', 'vk-booking-manager' ),
		showProviderLogo: false,
		showProviderName: false,
		providerName: '',
		providerLogoUrl: '',
		closedDayLabel: '',
		otherConditionsLabel: __( 'Other conditions', 'vk-booking-manager' ),
		// 数量の見出し（複数人一括予約）。REST が実効値を返す。
		guestsCountLabel: __( 'Number of guests', 'vk-booking-manager' ),
		// 数量の単位。REST が実効値（null はロケール既定に解決済み）を返す。空文字は単位なし。
		guestsUnitLabel: '',
	} );

	// 現在選択中のメニュー（メタ情報を含む）。以降の指名可否判定などに使うため早い段階で確定する。
	const currentMenu = useMemo(
		() => menus.find( ( menu ) => menu.id === menuId ),
		[ menus, menuId ]
	);
	// #391: このメニューで指名機能を使うか。
	// サイト全体の指名機能スイッチ（providerSettings.staffEnabled）がONで、かつ
	// メニュー単位の無効化メタ（_vkbm_disable_nomination）が立っていない場合のみ true。
	// メニュー未選択（currentMenu が無い）ときは、メタを読めないためサイト全体の設定にそのまま従う。
	const menuNominationEnabled = useMemo(
		() =>
			providerSettings.staffEnabled &&
			! Boolean( currentMenu?.meta?._vkbm_disable_nomination ),
		[ providerSettings.staffEnabled, currentMenu ]
	);
	// スタッフ選択UI（プルダウン等）自体を表示してよいか。
	const staffSelectionEnabled = allowStaffSelection && menuNominationEnabled;

	// #429 安藤レビュー指摘（低・再レビュー）: 絞り込み検索で「スタッフによるメニュー絞り込み」
	// （staffFilteredMenus・menuOptionsHiddenByStaffFilter・menuListEmptyMessage・
	// menuListStaffId）を行ってよいか。allowStaffSelection が false（古いブロックに残った
	// data-allow-staff-selection="0" 属性等で、スタッフ欄が ReadOnlyField 表示になっている場合を
	// 含む）のときは、利用者がスタッフ選択を変更できず「指名なしに戻せば全件表示に戻る」という
	// 案内も実行できないため、絞り込み自体を行わない。
	// staffSelectionEnabled はメニュー単位の指名無効（_vkbm_disable_nomination）も条件に含むが、
	// この4箇所はいずれもメニュー未選択時（currentMenu 未確定）にしか意味を持たないため、
	// 意味が混ざらないよう別の変数として定義する。
	const staffFilterAllowed =
		allowStaffSelection && providerSettings.staffEnabled;

	// #429 安藤レビュー指摘（低・2回目再レビュー）: URL の resource_id 等で staffId に
	// 非公開・削除済みスタッフの投稿IDが入っていると、staffOptions（読み込み済みのスタッフ
	// 選択肢）に該当が無いためスタッフ欄には選択中のスタッフ名が表示できず「指名なし」に
	// 見える。それにもかかわらず絞り込みだけは staffId を使って効いてしまうと、利用者からは
	// 「指名なしのはずなのに絞り込まれている／指名なしに戻しても変わらない」状態になり、
	// 案内文が実行不能になる。そのため絞り込みに使う id は、staffOptions に実在する場合の
	// みへ限定する。staffOptions は非同期取得のため、読み込み前（空配列）は一致せず絞り込みが
	// 一時的に掛からない状態になるが、読み込み完了後に staffOptions が更新されると
	// このメモも再計算され、正しく絞り込みが掛かる（許容範囲としてレビューで合意済み）。
	const staffFilterId = useMemo(
		() =>
			staffId > 0 &&
			staffOptions.some( ( staff ) => staff.id === staffId )
				? staffId
				: 0,
		[ staffId, staffOptions ]
	);

	// #431: リソースタグ検索が有効か（設定でON、かつ絞り込み検索自体を表示する構成）。
	// 指名機能（staffEnabled）の ON/OFF には依存しない仕様（issue #431 完了条件）。
	const resourceTagSearchAllowed = providerSettings.resourceTagSearchEnabled;
	// 実際にタグで絞り込み中か（1件以上選択されている）。
	const tagFilterActive =
		resourceTagSearchAllowed && selectedTagIds.length > 0;
	// 選択中のタグを「すべて」持つリソースの投稿ID配列（AND条件）。
	// サーバー側 Resource_Tag_Taxonomy::get_resource_ids_for_tags() と同じロジックを
	// クライアント側でも再現する（staffOptions が resource_tag_ids フィールドを持つ前提）。
	// タグ未選択（tagFilterActive が false）のときは null（絞り込みなし）にする。
	const resourceIdsForTagFilter = useMemo( () => {
		if ( ! tagFilterActive ) {
			return null;
		}

		return staffOptions
			.filter( ( staff ) => {
				const tagIds = Array.isArray( staff?.resource_tag_ids )
					? staff.resource_tag_ids
					: [];
				return selectedTagIds.every( ( tagId ) =>
					tagIds.includes( tagId )
				);
			} )
			.map( ( staff ) => staff.id );
	}, [ tagFilterActive, staffOptions, selectedTagIds ] );

	// #427: メニュー未選択時に「絞り込み検索」（メニュー／スタッフのプルダウン選択 UI。
	// SelectedPlanSummary を流用する）を表示するかどうか。
	// 「絞り込み検索」がチェックされている場合、または「サービスメニュー一覧」がチェックされて
	// いない場合（両方未チェックの場合を含む）に表示する。これにより、新設定の既定値 false の
	// まま「サービスメニュー一覧」だけ ON/OFF していた既存サイトの表示は変わらない
	// （一覧ON→一覧のみ、一覧OFF→絞り込み検索のみ、という従来どおりの2択が保たれる）。
	const shouldShowMenuSearch =
		providerSettings.showMenuSearch || ! providerSettings.showMenuList;

	// #429: 絞り込み検索でスタッフを選択している間（メニュー未選択時）、
	// 「メニュー」プルダウンの選択肢を、そのスタッフが対応できるメニューだけに絞り込む。
	// サーバ側（Menu_Loop_Block::is_menu_visible_for_staff_filter()）と判定基準を一致させる：
	// - スタッフ機能自体がOFF、staffId が指名なし（0）、既にメニュー選択済み、または
	//   絞り込み検索自体を表示しない構成（shouldShowMenuSearch が false）のときは絞り込まない
	//  （メニュー選択済みのときに絞り込むと、選択中のメニューが選択肢から消えてしまうため。
	//   絞り込み検索を表示しない構成では、URLのresource_idやブロック属性の既定スタッフだけで
	//   一覧が絞られてしまうと利用者が気づけないため。安藤レビュー指摘 LOW）。
	// - メニュー単位で指名を使わない設定（_vkbm_disable_nomination）のメニューは、
	//   スタッフ選択が有効なときのみ除外する（#431: タグのみでの絞り込みは指名の有無と無関係に
	//   自動割当へ効くため、この除外は適用しない）。
	// - 対応スタッフ（_vkbm_staff_ids）が未登録のメニューは残す（スタッフ絞り込みのみが有効なとき）。
	// - 対応スタッフが設定されている場合は、その中に staffId が含まれるメニューだけ残す（スタッフ絞り込み）。
	// - #431: タグ絞り込みが有効な場合、選択したタグを「すべて」持つリソースが1件も無いときは
	//   対応スタッフ未登録のメニューも含めて全て除外する（issue #431 完了条件）。1件以上あるときは
	//   対応スタッフの中にタグを持つリソースが1人でも含まれるメニューだけ残す（AND条件）。
	//   いずれもサーバー側 Menu_Loop_Block::is_menu_visible_for_tag_filter() と同じ判定に揃える。
	const staffActive = staffFilterAllowed && staffFilterId > 0;
	const staffFilteredMenus = useMemo( () => {
		if (
			menuId ||
			! shouldShowMenuSearch ||
			( ! staffActive && ! tagFilterActive )
		) {
			return menus;
		}

		return menus.filter( ( menu ) => {
			if ( staffActive && menu?.meta?._vkbm_disable_nomination ) {
				return false;
			}

			const assignableStaffIds = extractAssignableStaffIds( menu?.meta );

			if (
				staffActive &&
				assignableStaffIds.length &&
				! assignableStaffIds.includes( staffFilterId )
			) {
				return false;
			}

			if ( tagFilterActive ) {
				// 選択したタグを「すべて」持つリソースが1件も無いときは、対応スタッフ
				// 未登録のメニューも含めて全て除外する（サーバー側 is_menu_visible_for_tag_filter()
				// と同じ判定。issue #431 完了条件「該当するリソースが0件になった場合は
				// メニュー一覧を空にする」）。
				if ( resourceIdsForTagFilter.length === 0 ) {
					return false;
				}

				if (
					assignableStaffIds.length &&
					! assignableStaffIds.some( ( id ) =>
						resourceIdsForTagFilter.includes( id )
					)
				) {
					return false;
				}
			}

			return true;
		} );
	}, [
		menus,
		menuId,
		shouldShowMenuSearch,
		staffActive,
		staffFilterId,
		tagFilterActive,
		resourceIdsForTagFilter,
	] );

	// #429 植草レビュー指摘（低）: 「メニュー」プルダウンの選択肢が、絞り込み検索の
	// スタッフ選択が原因で0件になっているかどうか。メニュー自体が1件も無いサイト
	// （スタッフ選択と無関係）まで理由文言の対象にしないよう、menus.length > 0 も条件に含める。
	// #431: タグ絞り込みが原因で0件になっている場合も同様に扱う。
	const menuOptionsHiddenByStaffFilter =
		! menuId &&
		shouldShowMenuSearch &&
		( staffActive || tagFilterActive ) &&
		menus.length > 0 &&
		staffFilteredMenus.length === 0;

	// #429: 絞り込み検索でスタッフを選択している間にサービスメニュー一覧が0件になった場合は、
	// 「メニュー自体が存在しない」のではなく「そのスタッフで予約できるメニューが無い」ことが
	// 伝わるよう、通常の0件メッセージとは別の文言を表示する。
	// 絞り込み検索自体を表示しない構成（shouldShowMenuSearch が false）では絞り込みを行わない
	// ため、この専用メッセージも出さない（安藤レビュー指摘 LOW。上記 staffFilteredMenus と条件を揃える）。
	// 植草レビュー指摘（低）: 「指名なし」に戻せば全件表示に戻せることが伝わるよう、
	// 案内文を1文追加する。「指名なし」の表示名は基本設定
	// （providerSettings.noNominationLabel。未設定時は既定の "No preference"）で変わるため、
	// 固定文言にせずその設定値を使う。2文の結合は joinSentences()（#393）に揃える。
	const menuListEmptyMessage = useMemo( () => {
		// #431: 選択したタグを「すべて」持つリソースが1件も無い場合は、リソース名称の設定値を
		// 使った専用メッセージを優先する（issue #431 完了条件：「条件に該当する○○がいません」）。
		if (
			shouldShowMenuSearch &&
			tagFilterActive &&
			resourceIdsForTagFilter &&
			resourceIdsForTagFilter.length === 0
		) {
			return sprintf(
				/* translators: %s: resource label (plural), customizable via basic settings. */
				__(
					'There are no %s matching the selected conditions.',
					'vk-booking-manager'
				),
				providerSettings.resourceLabelPlural
			);
		}

		if ( shouldShowMenuSearch && staffFilterAllowed && staffFilterId > 0 ) {
			/* translators: %s: resource label (e.g. "Staff"), customizable via basic settings. */
			const message = __(
				'There are no service menus available for the selected %s.',
				'vk-booking-manager'
			);
			const baseMessage = message.includes( '%s' )
				? sprintf( message, providerSettings.resourceLabelSingular )
				: message;

			const hintMessage = sprintf(
				/* translators: %s: "No preference" label, customizable via basic settings. */
				__(
					'Choose %s to show all service menus.',
					'vk-booking-manager'
				),
				providerSettings.noNominationLabel
			);

			return joinSentences( baseMessage, hintMessage );
		}

		return __(
			'There are no service menus to display.',
			'vk-booking-manager'
		);
	}, [
		shouldShowMenuSearch,
		staffFilterAllowed,
		providerSettings.resourceLabelSingular,
		providerSettings.resourceLabelPlural,
		providerSettings.noNominationLabel,
		staffFilterId,
		tagFilterActive,
		resourceIdsForTagFilter,
	] );

	// #429 安藤・植草レビュー指摘（低・再レビュー）: 絞り込み検索とサービスメニュー一覧を
	// 両方表示する構成で0件になると、SelectedPlanSummary 側のヒントとこの一覧側の0件
	// メッセージ（下記 JSX の <p>）に同じ文言が2か所出て、どちらも role="status" で
	// 二重に読み上げられてしまう。一覧側の要素にこの一意な id を付与し、
	// SelectedPlanSummary の aria-describedby からこの id を参照させることで、
	// 一覧を表示する構成では SelectedPlanSummary 側の重複ヒントを出さずに済ませる
	// （同じページに予約ブロックが複数あっても衝突しないよう useId を使う）。
	// 安藤レビュー指摘（低・2回目再レビュー）: 以前は @wordpress/compose の useInstanceId を
	// 使っていたが、公開側の予約ページに wp-compose の追加読み込みが発生していたため、
	// 既に読み込み済みの @wordpress/element（React 18 の useId をそのまま再エクスポートした
	// もの。readme.txt の Requires at least 6.8 で利用可能）へ差し替えた。
	const menuListEmptyMessageIdSuffix = useId();
	const menuListEmptyMessageId = `vkbm-menu-list-empty-message-${ menuListEmptyMessageIdSuffix }`;

	const [ menuList, setMenuList ] = useState( {
		html: '',
		isLoading: true,
		error: '',
	} );
	const [ authFormHtml, setAuthFormHtml ] = useState( '' );
	const [ authLoading, setAuthLoading ] = useState( false );
	const [ authError, setAuthError ] = useState( '' );
	const [ authMode, setAuthMode ] = useState( queryDefaults.auth || '' );
	const [ logoutUrl, setLogoutUrl ] = useState(
		userBootstrap?.logoutUrl || ''
	);
	const [ shiftDashboardUrl, setShiftDashboardUrl ] = useState(
		userBootstrap?.shiftDashboardUrl || ''
	);
	const [ canManageReservations, setCanManageReservations ] = useState(
		Boolean( userBootstrap?.canManageReservations )
	);
	const canViewPrivateMenus = Boolean( userBootstrap?.canViewPrivateMenus );
	const [ bookingsLoading, setBookingsLoading ] = useState( false );
	const [ bookingsError, setBookingsError ] = useState( '' );
	const [ bookings, setBookings ] = useState( [] );
	const [ cancellingBookingId, setCancellingBookingId ] = useState( 0 );
	// お気に入り（いつもの）。ログインユーザーのよく使うメニュー＋スタッフの組み合わせ。
	const [ favorites, setFavorites ] = useState( [] );
	const [ favoritesError, setFavoritesError ] = useState( '' );
	const isLoggedIn = resolveLoginState( { userBootstrap, isEditor } );
	const confirmDraftToken = queryDefaults.draft || '';
	const handleAuthLink = ( mode ) => {
		if ( typeof window === 'undefined' ) {
			return;
		}

		setAuthMode( ( current ) => {
			const nextMode = current === mode ? '' : mode;
			const url = new URL( window.location.href );
			if ( nextMode ) {
				url.searchParams.set( 'vkbm_auth', nextMode );
			} else {
				url.searchParams.delete( 'vkbm_auth' );
			}
			window.history.replaceState( null, '', url.toString() );
			return nextMode;
		} );
	};

	const [ calendarData, setCalendarData ] = useState( null );
	const [ calendarLoading, setCalendarLoading ] = useState( false );
	const [ calendarError, setCalendarError ] = useState( null );
	// #411 植草さん・安藤さんレビュー指摘（PR #414 再差し戻し）: 診断理由は成功応答
	// （calendarData.unavailability_reason）とエラー応答（error.data.unavailability_reason）の
	// 2箇所から来うるが、以前は両方を別々の state に持ち calendarData 優先で OR 結合していたため、
	// 古い成功時の理由が新しいエラーの理由より優先されて表示され続ける不具合があった
	// （安藤さんMEDIUM指摘）。fetchCalendar() の .then / .catch のどちらで確定した結果でも
	// 必ずこの1つの state だけを更新する設計にし、二重管理・優先順位のバグ自体を無くす。
	// フェッチ開始時にはリセットしない（植草さん中〜高指摘: 月送りのたびに一旦 null を経由すると
	// 診断バナーが hidden 属性で消えてから再び現れることになり、同じ理由が続く月をまたぐ場合でも
	// aria-live のコンテナが一度非表示→表示を繰り返し、スクリーンリーダーに再アナウンスされてしまう。
	// 前の結果を「次の結果が確定するまで保持する」ことで、同じ理由が続く限り表示が途切れない）。
	const [ calendarUnavailabilityReason, setCalendarUnavailabilityReason ] =
		useState( null );

	const [ slotData, setSlotData ] = useState( [] );
	const [ slotLoading, setSlotLoading ] = useState( false );
	const [ slotError, setSlotError ] = useState( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ menuPreview, setMenuPreview ] = useState( {
		html: '',
		isLoading: false,
		error: '',
	} );
	const layoutRef = useRef( null );
	const actionSectionRef = useRef( null );
	const [ providerSettingsLoaded, setProviderSettingsLoaded ] =
		useState( false );
	// staffSelectionEnabled はメニュー選択後（currentMenu 確定後）に定義する。
	// #391 でメニュー単位の指名可否（menuNominationEnabled）に依存するようになったため。
	useEffect( () => {
		let isMounted = true;

		apiFetch( { path: '/vkbm/v1/provider-settings' } )
			.then( ( settings ) => {
				if ( ! isMounted ) {
					return;
				}

				setProviderSettings( {
					taxEnabled: Boolean( settings?.tax_enabled ),
					taxRate: Number( settings?.tax_rate ) || 0,
					taxLabelText:
						typeof settings?.tax_label_text === 'string'
							? settings.tax_label_text
							: '',
					currencySymbol:
						typeof settings?.currency_symbol === 'string'
							? settings.currency_symbol
							: '',
					reservationPageUrl: settings?.reservation_page_url || '',
					showMenuList:
						settings?.reservation_show_menu_list !== false,
					// #427: 「絞り込み検索」（メニュー／スタッフのプルダウン選択 UI）表示設定。
					// 未保存（レスポンスに含まれない・false）の場合は非表示。
					showMenuSearch: Boolean(
						settings?.reservation_show_menu_search
					),
					// #431: 指名機能（staff_enabled）の ON/OFF に関係なく機能させるため、
					// staffEnabled とは別のフラグとして保持する。
					resourceTagSearchEnabled: Boolean(
						settings?.resource_tag_search_enabled
					),
					staffEnabled: Boolean( settings?.staff_enabled ),
					defaultStaffId: Number( settings?.default_staff_id ) || 0,
					showProviderLogo: Boolean(
						settings?.reservation_show_provider_logo
					),
					showProviderName: Boolean(
						settings?.reservation_show_provider_name
					),
					resourceLabelSingular:
						typeof settings?.resource_label_singular === 'string' &&
						settings.resource_label_singular.trim() !== ''
							? settings.resource_label_singular
							: __( 'Staff', 'vk-booking-manager' ),
					resourceLabelPlural:
						typeof settings?.resource_label_plural === 'string' &&
						settings.resource_label_plural.trim() !== ''
							? settings.resource_label_plural
							: typeof settings?.resource_label_singular ===
									'string' &&
							  settings.resource_label_singular.trim() !== ''
							? settings.resource_label_singular
							: __( 'Staff', 'vk-booking-manager' ),
					noNominationLabel:
						typeof settings?.no_nomination_label === 'string' &&
						settings.no_nomination_label.trim() !== ''
							? settings.no_nomination_label
							: __( 'No preference', 'vk-booking-manager' ),
					nominationFeeLabel:
						typeof settings?.nomination_fee_label === 'string' &&
						settings.nomination_fee_label.trim() !== ''
							? settings.nomination_fee_label
							: __( 'Nomination fee', 'vk-booking-manager' ),
					providerName:
						typeof settings?.provider_name === 'string'
							? settings.provider_name
							: '',
					providerLogoUrl:
						typeof settings?.provider_logo_url === 'string'
							? settings.provider_logo_url
							: '',
					closedDayLabel:
						typeof settings?.closed_day_label === 'string'
							? settings.closed_day_label
							: '',
					otherConditionsLabel:
						typeof settings?.other_conditions_label === 'string' &&
						settings.other_conditions_label.trim() !== ''
							? settings.other_conditions_label
							: __( 'Other conditions', 'vk-booking-manager' ),
					// 数量の見出し。REST が実効値を返すが、念のため空ならデフォルトへフォールバックする。
					guestsCountLabel:
						typeof settings?.guests_count_label === 'string' &&
						settings.guests_count_label.trim() !== ''
							? settings.guests_count_label
							: __( 'Number of guests', 'vk-booking-manager' ),
					// 数量の単位。REST が実効値（null はロケール既定に解決済み）を返す。
					// 空文字は単位なしを意味するため、空判定でのフォールバックは行わない。
					guestsUnitLabel:
						typeof settings?.guests_unit_label === 'string'
							? settings.guests_unit_label
							: '',
				} );
			} )
			.catch( () => {
				// Keep defaults.
			} )
			.finally( () => {
				if ( isMounted ) {
					setProviderSettingsLoaded( true );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	useEffect( () => {
		// providerSettings が読み込まれるまで待つ
		if ( ! providerSettingsLoaded ) {
			return;
		}

		// #391: サイト全体ではなくこのメニューで指名を使わない場合（無料版、または
		// Pro版でメニュー単位に指名OFFにした場合）に自動割り当て向けの初期化を行う。
		if ( ! menuNominationEnabled ) {
			// 無料版ではデフォルトスタッフIDを設定
			if (
				providerSettings.defaultStaffId > 0 &&
				staffId !== providerSettings.defaultStaffId
			) {
				setStaffId( providerSettings.defaultStaffId );
			} else if (
				providerSettings.defaultStaffId === 0 &&
				staffId !== 0
			) {
				setStaffId( 0 );
			}
		}
	}, [
		providerSettingsLoaded,
		menuNominationEnabled,
		providerSettings.defaultStaffId,
		staffId,
	] );

	// #429: 絞り込み検索でスタッフが選択されているときだけ一覧取得へ反映する staffId。
	// スタッフ機能自体がサイト全体でOFF（無料版・基本設定でOFF）、絞り込み検索自体を
	// 表示しない構成（shouldShowMenuSearch が false。URLのresource_id等で初期スタッフが
	// 入っていても、利用者に見えない絞り込み検索の値だけで一覧が絞られるのを防ぐ。
	// 安藤レビュー指摘 LOW）、スタッフ選択欄自体が読み取り専用で変更できない構成
	// （staffFilterAllowed が false。安藤レビュー指摘 LOW・再レビュー）、または staffId が
	// staffOptions に存在しない（非公開・削除済み等）構成（staffFilterId が0。
	// 安藤レビュー指摘 LOW・2回目再レビュー）のときは常に0にする。
	// この場合は staffId が変化してもこの値が変わらないため、下記 useEffect も再実行されない。
	const menuListStaffId =
		shouldShowMenuSearch && staffFilterAllowed ? staffFilterId : 0;
	// #431: 一覧取得へ反映するリソースタグ選択（絞り込み検索を表示しない構成では反映しない。
	// 上記 menuListStaffId と同じ考え方）。useMemo で空配列の参照を安定させ、
	// 依存配列に使う下記 useEffect が毎レンダー再実行されないようにする。
	const menuListTagIds = useMemo(
		() => ( shouldShowMenuSearch && tagFilterActive ? selectedTagIds : [] ),
		[ shouldShowMenuSearch, tagFilterActive, selectedTagIds ]
	);

	useEffect( () => {
		let isMounted = true;

		if ( ! providerSettingsLoaded ) {
			return () => {
				isMounted = false;
			};
		}

		if ( ! providerSettings.showMenuList || menuId ) {
			setMenuList( {
				html: '',
				isLoading: false,
				error: '',
			} );
			return () => {
				isMounted = false;
			};
		}

		setMenuList( {
			html: '',
			isLoading: true,
			error: '',
		} );

		apiFetch( {
			path: buildApiPath( '/vkbm/v1/menu-loop', {
				// 0（指名なし）は絞り込み無しのため、パラメーター自体を付与しない。
				staff: menuListStaffId > 0 ? menuListStaffId : undefined,
				// #431: 配列はカンマ区切りへ自動変換される（buildApiPath → URLSearchParams）。
				// 未選択（空配列）時はパラメーター自体を付与しない。パラメーター名は他エンドポイント
				// （calendar-meta / availabilities）と揃えて resource_tag_ids に統一する。
				resource_tag_ids: menuListTagIds.length
					? menuListTagIds
					: undefined,
			} ),
		} )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuList( {
					html: response?.html || '',
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuList( {
					html: '',
					isLoading: false,
					error:
						error?.message ||
						__( 'Could not get menu list.', 'vk-booking-manager' ),
				} );
			} );

		return () => {
			isMounted = false;
		};
	}, [
		providerSettingsLoaded,
		providerSettings.showMenuList,
		menuId,
		menuListStaffId,
		menuListTagIds,
	] );

	useEffect( () => {
		let isMounted = true;

		if ( ! isLoggedIn ) {
			setLogoutUrl( '' );
			setShiftDashboardUrl( '' );
			setCanManageReservations( false );
			return () => {
				isMounted = false;
			};
		}

		if (
			userBootstrap &&
			typeof userBootstrap === 'object' &&
			typeof userBootstrap.logoutUrl === 'string' &&
			typeof userBootstrap.shiftDashboardUrl === 'string'
		) {
			setLogoutUrl( userBootstrap.logoutUrl || '' );
			setShiftDashboardUrl( userBootstrap.shiftDashboardUrl || '' );
			setCanManageReservations(
				Boolean( userBootstrap.canManageReservations )
			);
			return () => {
				isMounted = false;
			};
		}

		const currentUrl = getCurrentUrl();
		const path = buildApiPath( '/vkbm/v1/current-user', {
			redirect: currentUrl || undefined,
		} );

		apiFetch( { path } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setLogoutUrl( response?.logout_url || '' );
				setShiftDashboardUrl( response?.shift_dashboard_url || '' );
				setCanManageReservations(
					Boolean( response?.can_manage_reservations )
				);
			} )
			.catch( () => {
				if ( isMounted ) {
					setLogoutUrl( '' );
					setShiftDashboardUrl( '' );
					setCanManageReservations( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ isLoggedIn ] );

	useEffect( () => {
		if ( ! canManageReservations ) {
			return;
		}

		if ( authMode === 'profile' || authMode === 'bookings' ) {
			setAuthMode( '' );
		}
	}, [ canManageReservations, authMode ] );

	useEffect( () => {
		if ( ! authMode ) {
			setAuthFormHtml( '' );
			return;
		}

		if ( authMode === 'bookings' ) {
			setAuthFormHtml( '' );
			return;
		}

		const requiresLogin = authMode === 'profile' || authMode === 'bookings';
		if ( requiresLogin && ! isLoggedIn ) {
			setAuthFormHtml( '' );
			return;
		}

		if ( ! requiresLogin && isLoggedIn ) {
			setAuthFormHtml( '' );
			return;
		}

		setAuthLoading( true );
		setAuthError( '' );
		setAuthFormHtml( '' );

		const params = new URLSearchParams();
		params.set( 'type', authMode );

		const redirectUrl = getCurrentUrl();
		if ( redirectUrl ) {
			params.set( 'redirect', redirectUrl );
		}

		if ( authMode === 'login' ) {
			params.set( 'register_url', buildModeUrl( 'register' ) );
		} else if ( authMode === 'register' ) {
			params.set( 'login_url', buildModeUrl( 'login' ) );
		}

		apiFetch( {
			path: `/vkbm/v1/auth-form?${ params.toString() }`,
			cache: 'no-store',
			credentials: 'same-origin',
		} )
			.then( ( response ) => {
				const html = response?.html || '';
				const message = response?.message || '';

				setAuthFormHtml( html );

				if ( ! html && authMode === 'register' && message ) {
					setAuthError( message );
				}
			} )
			.catch( ( error ) => {
				setAuthError(
					error?.message ||
						__(
							'The form could not be displayed.',
							'vk-booking-manager'
						)
				);
			} )
			.finally( () => {
				setAuthLoading( false );
			} );
	}, [ authMode, isLoggedIn ] );

	const handleCancelBooking = useCallback(
		( bookingId ) => {
			const id = Number( bookingId ) || 0;
			if ( ! id || cancellingBookingId ) {
				return;
			}

			if (
				typeof window !== 'undefined' &&
				! window.confirm(
					__(
						'Do you want to cancel this reservation?',
						'vk-booking-manager'
					)
				)
			) {
				return;
			}

			setCancellingBookingId( id );
			setBookingsError( '' );

			apiFetch( {
				path: `/vkbm/v1/my-bookings/${ id }/cancel`,
				method: 'POST',
			} )
				.then( () => {
					setBookings( ( current ) =>
						Array.isArray( current )
							? current.filter(
									( booking ) => Number( booking?.id ) !== id
							  )
							: []
					);
				} )
				.catch( ( error ) => {
					setBookingsError(
						error?.message ||
							__(
								'I was unable to cancel my reservation.',
								'vk-booking-manager'
							)
					);
				} )
				.finally( () => {
					setCancellingBookingId( 0 );
				} );
		},
		[ cancellingBookingId ]
	);

	// お気に入り一覧を取得する。追加・削除後の再取得にも使う。
	const loadFavorites = useCallback( () => {
		if ( ! isLoggedIn || isEditor ) {
			setFavorites( [] );
			return;
		}

		apiFetch( { path: '/vkbm/v1/favorites' } )
			.then( ( response ) => {
				setFavorites( Array.isArray( response ) ? response : [] );
			} )
			.catch( () => {
				setFavorites( [] );
			} );
	}, [ isLoggedIn, isEditor ] );

	// ログイン状態が確定したらお気に入りを読み込む。
	useEffect( () => {
		loadFavorites();
	}, [ loadFavorites ] );

	useEffect( () => {
		if (
			isLoggedIn &&
			authMode &&
			authMode !== 'profile' &&
			authMode !== 'bookings'
		) {
			setAuthMode( '' );
		}

		if (
			! isLoggedIn &&
			( authMode === 'profile' || authMode === 'bookings' )
		) {
			setAuthMode( '' );
		}
	}, [ isLoggedIn, authMode ] );

	useEffect( () => {
		let isMounted = true;

		if ( ! isLoggedIn || authMode !== 'bookings' ) {
			return () => {
				isMounted = false;
			};
		}

		setBookingsLoading( true );
		setBookingsError( '' );

		// 「同じ内容で予約する」のため過去の予約も取得する（future_only=false）。
		apiFetch( { path: '/vkbm/v1/my-bookings?future_only=false' } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setBookings( Array.isArray( response ) ? response : [] );
			} )
			.catch( () => {
				if ( ! isMounted ) {
					return;
				}

				setBookings( [] );
				setBookingsError(
					__(
						'The reservation list could not be loaded.',
						'vk-booking-manager'
					)
				);
			} )
			.finally( () => {
				if ( isMounted ) {
					setBookingsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ authMode, isLoggedIn ] );
	const currentStaff = useMemo(
		() => staffOptions.find( ( staff ) => staff.id === staffId ),
		[ staffOptions, staffId ]
	);

	// メニューが複数人一括予約に対応しているかを判定する。
	// #392: 指名を使うメニュー（1枠1組＝貸切）でも、定員（1組の最大人数）までの人数を1件の予約で
	// 受け付けられるようにするため、以前あった「指名機能OFF（menuNominationEnabled=false）のときのみ
	// 有効」というゲートは外した。サーバ側（Staff_Editor::is_multi_guest_available_for_menu()）も
	// 同様に指名条件を外しているため、_vkbm_allow_multiple_guests の値だけで判定できる。
	const allowMultipleGuests = useMemo(
		() => Boolean( currentMenu?.meta?._vkbm_allow_multiple_guests ),
		[ currentMenu ]
	);
	// #393: 指名を使うメニューの最低申し込み人数（受付制限）。
	// 1件の予約が1組の貸切になる指名メニューで、1名の予約に定員2以上の枠を占有されるのを
	// 避けるための下限。入力欄の下限・常時案内文・送信抑止に使う。
	//
	// 適用条件（指名可否・複数人一括予約・サイト全体の「予約枠の定員機能」スイッチ・定員2以上）は
	// クライアントで再計算しない。以前はここでメニューのメタから再計算していたが、サイト全体の
	// 予約枠の定員機能スイッチ（Staff_Editor::is_slot_capacity_enabled()）をフロントが見ておらず、
	// その機能がOFFのサイトではサーバー側は実効0（制限なし）になるのにフロントだけ下限が残って
	// 予約できなくなる不整合があった（安藤レビュー指摘）。判定条件は
	// Availability_Service::get_menu_nomination_min_guests() の1箇所に集約し、その結果を
	// Service_Menu_Post_Type の読み取り専用RESTフィールド（vkbm_nomination_min_guests）経由で
	// 受け取るだけにする。
	const nominationMinGuests = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number( currentMenu?.vkbm_nomination_min_guests ) || 0
				)
			),
		[ currentMenu ]
	);
	// 選択可能な人数の上限は、選択中スロットの残り（= 最も空きの大きい単一スタッフの残り）。
	// 楽観的に最大人数を許可するとサーバー側で予約が失敗するため、不明時は安全側に倒す。
	const maxSelectableGuests = useMemo( () => {
		if ( ! allowMultipleGuests ) {
			return 1;
		}
		// 残り = 最も空きの大きい単一スタッフの残り。1予約は分割せず単一スタッフに割り当てるため、
		// その人数までしか指定できない。取得できない（不明な）場合は 0 とし、安全側へ倒す。
		const remaining = Number( selectedSlot?.remaining );
		if ( Number.isFinite( remaining ) ) {
			return Math.max( 0, Math.floor( remaining ) );
		}
		return 0;
	}, [ allowMultipleGuests, selectedSlot ] );

	// #393: 選択中スロットの残り人数が最低申し込み人数に満たないか（安藤/司レビュー指摘）。
	// 更新前から残っている予約などで、まれにこの状態になり得る。この状態のまま人数入力欄を
	// 出すと min 属性が max を超えた矛盾した入力欄になり、案内文と裏腹に人数を上げられず
	// 「予約へ進む」が無言で disabled になるため、入力欄自体を出さず理由を明示する。
	// maxSelectableGuests が 0（満枠）のときは別途「満枠」表示があるため対象外とする。
	const slotBelowNominationMinGuests = useMemo(
		() =>
			allowMultipleGuests &&
			nominationMinGuests > 0 &&
			maxSelectableGuests >= 1 &&
			maxSelectableGuests < nominationMinGuests,
		[ allowMultipleGuests, nominationMinGuests, maxSelectableGuests ]
	);

	// 選択可能上限・最低申し込み人数が変わった場合に人数を範囲内へ補正する（#393）。
	// 下限は最低申し込み人数（未適用なら1）、上限は選択可能上限。
	useEffect( () => {
		setGuests( ( current ) => {
			const lowerBound = Math.max( 1, nominationMinGuests );
			const next = Math.min(
				Math.max( lowerBound, current ),
				maxSelectableGuests
			);
			return next === current ? current : next;
		} );
	}, [ maxSelectableGuests, nominationMinGuests ] );

	// メニューに定義された料金区分（[ { label, price }, ... ]）。
	// 複数人一括予約ONかつ区分が1件以上ある場合のみ、人数を区分ごとに入力する。
	const priceTiers = useMemo( () => {
		if ( ! allowMultipleGuests ) {
			return [];
		}
		const rawTiers = currentMenu?.meta?._vkbm_price_tiers;
		if ( ! Array.isArray( rawTiers ) ) {
			return [];
		}
		return rawTiers
			.map( ( tier ) => ( {
				label: typeof tier?.label === 'string' ? tier.label.trim() : '',
				price: Math.max( 0, Math.floor( Number( tier?.price ) || 0 ) ),
			} ) )
			.filter( ( tier ) => tier.label !== '' );
	}, [ allowMultipleGuests, currentMenu ] );

	const hasPriceTiers = priceTiers.length > 0;

	// 料金区分の人数配列を区分数に合わせて初期化・サイズ調整する。
	useEffect( () => {
		if ( ! hasPriceTiers ) {
			if ( guestTierCounts.length > 0 ) {
				setGuestTierCounts( [] );
			}
			return;
		}
		setGuestTierCounts( ( current ) => {
			// 区分数に合わせて 0 埋めし、既存入力は保持する。
			const next = priceTiers.map( ( _, index ) =>
				Math.max( 0, Math.floor( Number( current[ index ] ) || 0 ) )
			);
			const sameLength = current.length === next.length;
			const sameValues =
				sameLength &&
				next.every( ( value, index ) => value === current[ index ] );
			return sameValues ? current : next;
		} );
	}, [ hasPriceTiers, priceTiers ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// 料金区分の合計人数（枠消費・上限判定に使う）。
	const guestTiersTotalCount = useMemo(
		() =>
			guestTierCounts.reduce(
				( sum, value ) =>
					sum + Math.max( 0, Math.floor( Number( value ) || 0 ) ),
				0
			),
		[ guestTierCounts ]
	);

	// 申込人数（実効値）。料金区分メニューは区分人数合計、複数人一括予約メニューは guests、それ以外は1。
	const currentGuestCount = useMemo( () => {
		if ( hasPriceTiers ) {
			return guestTiersTotalCount;
		}
		if ( allowMultipleGuests ) {
			return Math.max( 1, Math.floor( Number( guests ) || 1 ) );
		}
		return 1;
	}, [ hasPriceTiers, guestTiersTotalCount, allowMultipleGuests, guests ] );

	// メニューが「ユーザーによる貸し切り指定を受け付ける」設定か（#305）。複数人一括予約ON時のみ意味を持つ。
	// 「貸し切り予約」（_vkbm_exclusive_when_booked）がONのメニューでは、予約者の指定に関わらず
	// 既に枠全体が貸切扱いになるため、ユーザーによる貸し切り指定チェックボックス自体を出さない（#388）。
	// 両方ONで保存されていた過去のメニューでも、指定した予約者だけ貸し切り料金を負担する不整合を防ぐ。
	// #392 時点では、指名を使うメニューは常に1枠1組（貸切）とみなし、貸し切り予約・予約者による
	// 貸切指定・貸切料金の3設定自体を編集画面にも出さず（サーバ側も
	// Staff_Editor::is_exclusive_booking_available_for_menu() で指名OFFを要求していた）、ここでも
	// menuNominationEnabled で明示的に除外していた。#440: 指名を使うメニューでもこの3設定を
	// 編集画面で設定でき、予約時にも効くように変更した（貸切の排他はメニュー全体ではなく
	// 担当スタッフ単位。サーバ側の判定は Booking_Draft_Controller / Booking_Confirmation_Controller の
	// slot_has_exclusive_booking_for_menu() 等を参照）。そのため menuNominationEnabled による除外は撤廃する。
	const exclusiveSelectable = useMemo(
		() =>
			allowMultipleGuests &&
			! currentMenu?.meta?._vkbm_exclusive_when_booked &&
			Boolean( currentMenu?.meta?._vkbm_exclusive_user_selectable ),
		[ allowMultipleGuests, currentMenu ]
	);
	// 貸し切り料金（1人あたり単価）と適用外人数（0=上限なし＝常に加算）。
	const exclusiveFeePerPerson = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number(
						currentMenu?.meta?._vkbm_exclusive_fee_per_person
					) || 0
				)
			),
		[ currentMenu ]
	);
	const exclusiveFeeExempt = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number(
						currentMenu?.meta?._vkbm_exclusive_fee_exempt_guests
					) || 0
				)
			),
		[ currentMenu ]
	);
	// メニューの最小催行人数（0=制約なし）。貸し切りは申込人数 >= 最小催行人数のときのみ指定可能。
	const minCapacity = useMemo(
		() =>
			Math.max(
				0,
				Math.floor(
					Number( currentMenu?.meta?._vkbm_min_capacity ) || 0
				)
			),
		[ currentMenu ]
	);
	// 選択中スロットが既に予約済み（貸切受付終了 or 1件以上予約あり）か。貸切指定はこの場合 disabled。
	// 指名を使うメニューの「指名なし」枠は、booked_guests が全担当スタッフの合計になっており、
	// 空いている担当がいても booked_guests>0 になり得る。そのため remaining（最も空きの大きい
	// 単一担当の残り人数。担当ごとに exclusive_closed を反映済み）で「この枠へ新規に割り当てられる
	// 余地があるか」を判定する。指名を使わないメニューは、物理的に同じ枠を複数担当で共有する
	// 前提のため、従来どおり枠全体の合計人数（booked_guests）で判定する。
	const slotIsOccupied = useMemo( () => {
		if ( ! selectedSlot ) {
			return false;
		}
		if ( Boolean( selectedSlot.exclusive_closed ) ) {
			return true;
		}
		if ( menuNominationEnabled ) {
			const remaining = Number( selectedSlot.remaining );
			return Number.isFinite( remaining ) && remaining <= 0;
		}
		const booked = Math.max(
			0,
			Math.floor( Number( selectedSlot.booked_guests ) || 0 )
		);
		return booked > 0;
	}, [ selectedSlot, menuNominationEnabled ] );
	// 申込人数が最小催行人数を満たすか（minCapacity=0 のときは常に満たす）。
	const meetsMinCapacity = useMemo(
		() => minCapacity <= 0 || currentGuestCount >= minCapacity,
		[ minCapacity, currentGuestCount ]
	);
	// 貸切チェックを「有効なチェック」として出せるか（=空き枠 かつ 人数条件OK かつ メニュー設定ON）。
	const exclusiveCheckEnabled =
		exclusiveSelectable && ! slotIsOccupied && meetsMinCapacity;
	// 適用外人数に達しているか（適用外人数が正の値で、申込人数 >= 適用外人数）。
	const exclusiveFeeExempted =
		exclusiveFeeExempt > 0 && currentGuestCount >= exclusiveFeeExempt;
	// 貸切料金（フロント概算表示用。サーバ側で権威的に再計算される）。
	// ユーザー貸切ON かつ 単価 > 0 かつ 適用外人数未達のときのみ per_person × 人数。
	const exclusiveFee = useMemo( () => {
		if (
			! userExclusive ||
			! exclusiveSelectable ||
			exclusiveFeePerPerson <= 0 ||
			exclusiveFeeExempted
		) {
			return 0;
		}
		return exclusiveFeePerPerson * currentGuestCount;
	}, [
		userExclusive,
		exclusiveSelectable,
		exclusiveFeePerPerson,
		exclusiveFeeExempted,
		currentGuestCount,
	] );

	// 貸切チェックが無効化される条件（メニューOFF・枠が満席/締切・人数不足）になったらチェックを外す。
	useEffect( () => {
		if ( ! exclusiveCheckEnabled && userExclusive ) {
			setUserExclusive( false );
		}
	}, [ exclusiveCheckEnabled, userExclusive ] );

	const assignableStaffIds = useMemo( () => {
		return extractAssignableStaffIds( currentMenu?.meta );
	}, [ currentMenu ] );

	const availableStaffOptions = useMemo( () => {
		let options = staffOptions;

		// 無料版、またはこのメニューで指名機能OFFのときは選択可能スタッフの制限を解除する（#391）。
		if ( menuNominationEnabled && assignableStaffIds.length > 0 ) {
			const allowedIds = new Set( assignableStaffIds );
			options = options.filter( ( staff ) => allowedIds.has( staff.id ) );
		}

		// #431: リソースタグ検索は指名機能の ON/OFF と無関係に機能させる仕様のため、
		// 上記の指名可否分岐とは独立にタグ絞り込みを適用する。
		if ( tagFilterActive ) {
			options = options.filter( ( staff ) =>
				resourceIdsForTagFilter.includes( staff.id )
			);
		}

		return options;
	}, [
		assignableStaffIds,
		staffOptions,
		menuNominationEnabled,
		tagFilterActive,
		resourceIdsForTagFilter,
	] );
	const shouldLockStaffSelection = assignableStaffIds.length === 1;

	// このメニューで指名OFF かつ複数スタッフが対応可能なメニューは「おまかせ自動分配」とする（#391）。
	// この場合は resource_id=0 / is_staff_preferred=false で送信し、サーバー側で
	// 複数スタッフへ人数を配分させる（単一スタッフ・無料版・メニュー単位で指名ONのときは従来どおり staffId を使う）。
	const autoDistribute =
		! menuNominationEnabled && assignableStaffIds.length > 1;
	const effectiveResourceId = autoDistribute ? 0 : staffId;
	// #431: 空き枠計算・予約下書き・予約確定へ渡すリソースタグ（AND条件）。
	// 絞り込み検索の表示可否に関わらず、選択中のタグはそのまま候補の絞り込みに反映する
	// （menu_id をURL指定して開いた場合でもタグ検索欄は表示され続けるため、常時反映してよい。
	// docs/specification-resource-tag.md 参照）。
	// useMemo で空配列の参照を安定させ、これに依存する useCallback / useEffect の
	// 不要な再生成・再実行を防ぐ。
	const effectiveResourceTagIds = useMemo(
		() => ( tagFilterActive ? selectedTagIds : [] ),
		[ tagFilterActive, selectedTagIds ]
	);

	useEffect( () => {
		// 無料版、またはこのメニューで指名機能OFFのときは assignableStaffIds のチェックをスキップする（#391）。
		if ( ! menuNominationEnabled ) {
			return;
		}

		if ( ! menuId || assignableStaffIds.length === 0 ) {
			return;
		}

		if ( assignableStaffIds.length === 1 ) {
			const onlyStaffId = assignableStaffIds[ 0 ];
			if ( staffId !== onlyStaffId ) {
				setStaffId( onlyStaffId );
			}
			return;
		}

		if ( staffId && ! assignableStaffIds.includes( staffId ) ) {
			setStaffId( 0 );
		}
	}, [ menuId, assignableStaffIds, staffId, menuNominationEnabled ] );
	const isNominationFeeDisabled = useMemo( () => {
		const meta = currentMenu?.meta;
		if ( ! meta ) {
			return false;
		}

		const raw =
			meta._vkbm_disable_nomination_fee ??
			meta.vkbm_disable_nomination_fee ??
			currentMenu?._vkbm_disable_nomination_fee ??
			currentMenu?.vkbm_disable_nomination_fee;

		if ( raw === undefined || raw === null ) {
			return false;
		}

		if ( typeof raw === 'boolean' ) {
			return raw;
		}

		const normalized = String( raw ).toLowerCase().trim();
		return normalized === '1' || normalized === 'true';
	}, [ currentMenu ] );
	const isProfileMode = isLoggedIn && authMode === 'profile';
	const shouldShowUserSection =
		( ( authMode === 'profile' || authMode === 'bookings' ) &&
			isLoggedIn ) ||
		( ! isLoggedIn && ( authMode === 'login' || authMode === 'register' ) );
	const shouldShowReservation = ! shouldShowUserSection;
	const hasMenuSelection = Boolean( menuId );

	const applyTax = useCallback(
		( value ) => {
			const normalized = normalizePriceValue( value );

			if ( normalized === null ) {
				return null;
			}

			return normalized;
		},
		[ providerSettings ]
	);

	const basePriceRaw = useMemo(
		() => extractMenuBasePrice( currentMenu ),
		[ currentMenu ]
	);

	const basePrice = useMemo( () => {
		if ( basePriceRaw === null ) {
			return null;
		}

		return applyTax( basePriceRaw );
	}, [ applyTax, basePriceRaw ] );

	const staffNominationFeeRaw = useMemo( () => {
		// #391: サイト全体ではなくこのメニューの指名可否で判定する。
		if ( ! menuNominationEnabled ) {
			return 0;
		}
		if ( ! staffId ) {
			return null;
		}
		if ( isNominationFeeDisabled ) {
			return 0;
		}

		const rawFee =
			currentStaff?.meta?.vkbm_nomination_fee ??
			currentStaff?.meta?._vkbm_nomination_fee ??
			currentStaff?._vkbm_nomination_fee ??
			currentStaff?.nomination_fee;

		const normalized = normalizePriceValue( rawFee );

		if ( normalized === null ) {
			return 0;
		}

		return normalized;
	}, [
		currentStaff,
		staffId,
		isNominationFeeDisabled,
		menuNominationEnabled,
	] );

	const staffNominationFee = useMemo( () => {
		if ( staffNominationFeeRaw === null ) {
			return null;
		}

		return applyTax( staffNominationFeeRaw );
	}, [ applyTax, staffNominationFeeRaw ] );

	const totalPrice = useMemo( () => {
		if ( basePrice === null ) {
			return null;
		}

		const extra = staffNominationFee === null ? 0 : staffNominationFee;
		return basePrice + extra;
	}, [ basePrice, staffNominationFee ] );

	const pricingRows = useMemo( () => {
		if ( ! providerSettingsLoaded ) {
			return [];
		}

		// このメニューで指名機能が無効の場合は料金サマリーを表示しない
		// （指名料行がなく基本料金＝合計となり冗長なため）（#391）。
		if ( ! menuNominationEnabled ) {
			return [];
		}

		const taxSuffix = providerSettings.taxEnabled
			? providerSettings.taxLabelText &&
			  providerSettings.taxLabelText.trim() !== ''
				? providerSettings.taxLabelText
				: ''
			: '';
		const withTaxLabel = ( value ) => ( {
			value,
			taxLabel: value !== '—' ? taxSuffix : '',
		} );

		const currencySymbol = providerSettings.currencySymbol || null;
		const rows = [
			{
				key: 'base',
				label: __( 'Service basic fee', 'vk-booking-manager' ),
				...withTaxLabel(
					basePrice !== null
						? formatCurrency( basePrice, currencySymbol )
						: '—'
				),
			},
			{
				key: 'nomination',
				label: providerSettings.nominationFeeLabel,
				value:
					staffNominationFee === null
						? staffId
							? formatCurrency( applyTax( 0 ), currencySymbol )
							: '—'
						: formatCurrency( staffNominationFee, currencySymbol ),
				// staffNominationFee !== null : 指名料が設定されている場合は金額を表示するのでラベルを付ける
				// || staffId               : 指名料未設定（null）でもスタッフ選択済みなら ¥0 表示になるのでラベルを付ける
				// 両方 false = スタッフ未選択 = '—' 表示 のときはラベル不要
				taxLabel:
					staffNominationFee !== null || staffId ? taxSuffix : '',
			},
			{
				key: 'total',
				label: __( 'Total basic fee', 'vk-booking-manager' ),
				...withTaxLabel(
					totalPrice !== null
						? formatCurrency( totalPrice, currencySymbol )
						: '—'
				),
				highlight: true,
			},
		];

		return rows;
	}, [
		basePrice,
		staffNominationFee,
		totalPrice,
		providerSettings,
		providerSettingsLoaded,
		menuNominationEnabled,
	] );

	const menuCollectionPath = useMemo(
		() =>
			buildApiPath( '/wp/v2/vkbm_service_menu', {
				per_page: 100,
				_fields:
					'id,title,meta,menu_order,vkbm_menu_group,vkbm_nomination_min_guests',
				status: canViewPrivateMenus ? 'publish,private' : undefined,
			} ),
		[ canViewPrivateMenus ]
	);

	useCollection( menuCollectionPath, setMenus );

	useEffect( () => {
		let isMounted = true;

		if ( ! menuId ) {
			setMenuPreview( {
				html: '',
				isLoading: false,
				error: '',
			} );
			return () => {
				isMounted = false;
			};
		}

		setMenuPreview( {
			html: '',
			isLoading: true,
			error: '',
		} );

		apiFetch( { path: `/vkbm/v1/menu-preview/${ menuId }` } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuPreview( {
					html: response?.html || '',
					isLoading: false,
					error: '',
				} );
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}

				setMenuPreview( {
					html: '',
					isLoading: false,
					error:
						error?.message ||
						__(
							'Failed to retrieve menu information.',
							'vk-booking-manager'
						),
				} );
			} );

		return () => {
			isMounted = false;
		};
	}, [ menuId ] );
	// #391: このコレクションはサイト全体のスタッフ一覧であり、特定のメニューに紐づかない
	// （お気に入り・マイ予約タブなど、他メニューの過去の指名スタッフ名を表示する場面もある）。
	// そのためここは意図的にメニュー単位（menuNominationEnabled）ではなくサイト全体の
	// 指名機能スイッチのままにする。サイト全体でOFFなら、どのメニューも指名を使えないため
	// スタッフ一覧を取得する意味自体が無い。
	// #431: リソースタグ検索は指名機能（staffEnabled）と独立に機能させる仕様のため、
	// staffEnabled が OFF でも resourceTagSearchEnabled が ON なら取得する
	// （スタッフ選択プルダウンには使わず、タグによる絞り込み判定にのみ使う）。
	const staffCollectionPath = useMemo( () => {
		if (
			! providerSettingsLoaded ||
			( ! providerSettings.staffEnabled &&
				! providerSettings.resourceTagSearchEnabled )
		) {
			return '';
		}
		// resource_tag_ids: #431 の絞り込み判定に使うタームID配列（タグ名ではなくIDで照合する）。
		return '/wp/v2/vkbm_resource?per_page=100&_fields=id,title,meta,nomination_fee,resource_tags,resource_tag_ids';
	}, [
		providerSettingsLoaded,
		providerSettings.staffEnabled,
		providerSettings.resourceTagSearchEnabled,
	] );
	useCollection( staffCollectionPath, setStaffOptions );

	// #431: リソースタグ検索のチェックボックス一覧。リソースが1件も紐づいていないタグは
	// 表示しない（hide_empty=true を明示指定。WP REST の hide_empty は
	// タクソノミーの階層有無に関わらず既定値が false のため、指定しないと
	// 空タグまで一覧に出てしまう）。
	// なお count はリソース投稿が publish のときだけ数える（WP_Term_Query の既定挙動）ため、
	// 下書き・非公開状態のリソースにしか付いていないタグは hide_empty=true で
	// 除外される（docs/specification-resource-tag.md 参照）。
	const [ resourceTagOptions, setResourceTagOptions ] = useState( [] );
	const resourceTagCollectionPath = useMemo( () => {
		if (
			! providerSettingsLoaded ||
			! providerSettings.resourceTagSearchEnabled
		) {
			return '';
		}
		return '/wp/v2/vkbm_resource_tag?per_page=100&hide_empty=true&_fields=id,name,count';
	}, [ providerSettingsLoaded, providerSettings.resourceTagSearchEnabled ] );
	useCollection( resourceTagCollectionPath, setResourceTagOptions );

	const dayMetaMap = useMemo( () => {
		if ( ! calendarData?.days ) {
			return {};
		}

		return calendarData.days.reduce( ( acc, day ) => {
			acc[ day.date ] = day;
			return acc;
		}, {} );
	}, [ calendarData ] );

	// #411: 表示中の月に予約可能日が1件も無い場合の管理者向け診断メッセージ。
	// unavailability_reason は current_user_can( vkbm_manage_system_settings ) を満たすユーザーの
	// レスポンスにのみサーバー側で付与される（サーバー側で理由フィールドごと省略済み。CSSで隠しているのではない）。
	// calendarUnavailabilityReason は成功時・エラー時のどちらでも fetchCalendar() の確定時に
	// 一本化して更新される（上の state 宣言のコメント参照）ため、ここでは単純にその値だけを見る。
	// 同じ理由が続く月をまたいでも state が変化しない（≒同じ文字列が渡り続ける）限り React は
	// DOM を更新しないため、aria-live で再アナウンスされることはない（コンテナ自体は常時マウントし、
	// hidden 属性で表示切替する）。
	// プレフィックスと本文の間の区切り（スペース等）は翻訳者が %s 側で調整できるよう、
	// 文字列連結ではなく sprintf のプレースホルダーで結合する（安藤さん・植草さんレビュー指摘）。
	const adminDiagnosticMessage = calendarUnavailabilityReason?.message
		? sprintf(
				/* translators: %s: 診断理由の本文。 */
				__( '[Administrator diagnostic] %s', 'vk-booking-manager' ),
				calendarUnavailabilityReason.message
		  )
		: '';

	// #411 植草さんレビュー指摘（PR #414 再差し戻し・高）: 診断バナー（adminDiagnosticMessage、
	// 丁寧な対処法つき・role="status"）が出ているときに、DailySlotList 側の危険アラート
	// （calendarError の生の内部エラー文言・role="alert"）まで重ねて出すと、同じ原因を
	// 書き方も読み上げの緊急度も違う2枚の札で説明してしまう。診断バナーがあるときは
	// calendarError 由来の表示を抑制する（一般訪問者には adminDiagnosticMessage 自体が
	// 空文字のため、この抑制は効かず従来どおり calendarError が表示される＝非該当者への
	// 表示は変更なし）。slotError（選択日の空き枠取得エラー）は月単位の診断とは別要因のため
	// 抑制しない。
	const slotListError = adminDiagnosticMessage
		? slotError
		: calendarError || slotError;

	const fetchCalendar = useCallback( () => {
		if ( ! menuId || ! monthCursor.year || ! monthCursor.month ) {
			setCalendarData( null );
			setCalendarUnavailabilityReason( null );
			return;
		}

		setCalendarLoading( true );
		setCalendarError( null );
		// #411 植草さんレビュー指摘（PR #414 再差し戻し）: calendarUnavailabilityReason は
		// ここ（フェッチ開始時）ではリセットしない。.then / .catch の確定時にだけ更新することで、
		// 同じ理由が続く月への移動では診断バナーが一度も消えずに表示され続ける
		// （state 宣言のコメント参照）。

		const path = buildApiPath( '/vkbm/v1/calendar-meta', {
			menu_id: menuId,
			resource_id: effectiveResourceId || undefined,
			year: monthCursor.year,
			month: monthCursor.month,
			// #431: リソースタグ絞り込み（配列はカンマ区切りへ自動変換される）。
			resource_tag_ids: effectiveResourceTagIds.length
				? effectiveResourceTagIds
				: undefined,
		} );

		apiFetch( { path } )
			.then( ( response ) => {
				setCalendarData( response );
				// 成功応答でも、has_bookable_day() が false のときだけサーバー側で
				// unavailability_reason が付与される（無ければ null）。
				setCalendarUnavailabilityReason(
					response?.unavailability_reason ?? null
				);
			} )
			.catch( ( error ) => {
				setCalendarError(
					error?.message ||
						__( 'Failed to load calendar.', 'vk-booking-manager' )
				);
				// #411 麗美さん確認（PR #414 差し戻し）: エラー経路（担当スタッフ0件等）でも
				// 管理者向けには unavailability_reason が error.data に付与される
				// （src/rest/class-availability-controller.php handle_calendar_meta() 参照）。
				setCalendarUnavailabilityReason(
					error?.data?.unavailability_reason ?? null
				);
			} )
			.finally( () => {
				setCalendarLoading( false );
			} );
	}, [ menuId, effectiveResourceId, effectiveResourceTagIds, monthCursor ] );

	useEffect( () => {
		fetchCalendar();
	}, [ fetchCalendar ] );

	useEffect( () => {
		if ( ! menuId || ! selectedDate ) {
			setSlotData( [] );
			return;
		}

		setSlotLoading( true );
		setSlotError( null );

		const path = buildApiPath( '/vkbm/v1/availabilities', {
			menu_id: menuId,
			resource_id: effectiveResourceId || undefined,
			date: selectedDate,
			// #431: リソースタグ絞り込み（配列はカンマ区切りへ自動変換される）。
			resource_tag_ids: effectiveResourceTagIds.length
				? effectiveResourceTagIds
				: undefined,
		} );

		apiFetch( { path } )
			.then( ( response ) => {
				const slots = Array.isArray( response?.slots )
					? response.slots
					: [];
				const preferredStaffLabel = staffId
					? currentStaff?.title?.rendered ??
					  currentStaff?.title ??
					  currentStaff?.name ??
					  ''
					: '';

				const decoratedSlots = slots.map( ( slot ) => {
					const fallbackLabel =
						slot.staff_label ||
						slot.staff?.name ||
						preferredStaffLabel ||
						providerSettings.noNominationLabel;

					return {
						...slot,
						staff_label: preferredStaffLabel || fallbackLabel,
						staff:
							slot.staff ||
							( preferredStaffLabel
								? { id: staffId, name: preferredStaffLabel }
								: null ),
					};
				} );

				setSlotData( decoratedSlots );
			} )
			.catch( ( error ) => {
				setSlotError(
					error?.message ||
						__( 'Failed to read free space.', 'vk-booking-manager' )
				);
			} )
			.finally( () => {
				setSlotLoading( false );
			} );
	}, [
		menuId,
		staffId,
		effectiveResourceId,
		effectiveResourceTagIds,
		selectedDate,
		currentStaff,
	] );

	useEffect( () => {
		setSubmitError( '' );
	}, [ selectedSlot, menuId, staffId ] );

	useEffect( () => {
		if ( selectedSlot && actionSectionRef.current ) {
			actionSectionRef.current.scrollIntoView( {
				behavior: 'smooth',
				block: 'start',
			} );
		}
	}, [ selectedSlot ] );

	const handleMonthChange = ( delta ) => {
		setMonthCursor( ( current ) => {
			const newMonth = current.month + delta;
			const date = new Date( current.year, newMonth - 1, 1 );
			return {
				year: date.getFullYear(),
				month: date.getMonth() + 1,
			};
		} );
	};

	const handleSelectDate = ( date ) => {
		if ( ! menuId ) {
			return;
		}
		setSelectedDate( date );
		setSelectedSlot( null );
	};

	const handleMenuChange = ( nextMenuId ) => {
		const nextMenu = menus.find( ( menu ) => menu.id === nextMenuId );
		const nextMeta = nextMenu?.meta;
		// #391: これから切り替える「次のメニュー」自身の指名可否で判定する必要があるため、
		// （まだ state に反映されていない）nextMeta から個別に算出する。
		// menuNominationEnabled（現在のメニュー用）をここで使うと、切替前のメニューの
		// 指名設定のままスタッフIDの処理が行われてしまう。
		const nextMenuNominationEnabled =
			providerSettings.staffEnabled &&
			! Boolean( nextMeta?._vkbm_disable_nomination );

		// 無料版、または次のメニューで指名機能OFFのときはスタッフIDの処理をスキップ
		if ( nextMenuNominationEnabled ) {
			const nextAssignableStaffIds =
				extractAssignableStaffIds( nextMeta );

			if ( nextAssignableStaffIds.length === 1 ) {
				setStaffId( nextAssignableStaffIds[ 0 ] );
			} else if (
				// #429 安藤レビュー指摘（MEDIUM）: 対応スタッフ未登録
				// （nextAssignableStaffIds.length === 0）のメニューは、
				// applyFavorite・対応スタッフの useEffect（1339行目付近）・
				// PHP側 Availability_Service::resolve_staff_ids() と同じく
				// 「指名スタッフでそのまま受け付ける」ため、staffId を外してはいけない。
				staffId &&
				nextAssignableStaffIds.length > 0 &&
				! nextAssignableStaffIds.includes( staffId )
			) {
				setStaffId( 0 );
			}
			// 無料版では常にデフォルトスタッフIDを設定
			// providerSettings が読み込まれている場合のみ設定
		} else if (
			providerSettingsLoaded &&
			providerSettings.defaultStaffId > 0
		) {
			setStaffId( providerSettings.defaultStaffId );
		} else if ( providerSettingsLoaded ) {
			setStaffId( 0 );
		}

		setMenuId( nextMenuId );
		setSelectedDate( '' );
		setSelectedSlot( null );
		setGuests( 1 );
		// メニュー変更時は料金区分の人数もリセットする（区分構成が変わるため）。
		setGuestTierCounts( [] );
		// メニュー変更時は貸し切り指定もリセットする（メニューごとに設定が異なるため）。
		setUserExclusive( false );
		setMonthCursor( {
			year: new Date().getFullYear(),
			month: new Date().getMonth() + 1,
		} );
	};

	const handleStaffChange = ( nextStaffId ) => {
		setStaffId( nextStaffId );
		setSelectedSlot( null );
	};

	// #431: リソースタグ検索のチェックボックスを切り替える。
	// タグ選択でスタッフ・メニューの絞り込み対象外になった選択済み値は、下の useEffect で
	// リセットする（選んだ直後に見えなくなった選択肢を残さない）。
	const handleTagToggle = ( tagId, checked ) => {
		setSelectedTagIds( ( current ) => {
			if ( checked ) {
				return current.includes( tagId )
					? current
					: [ ...current, tagId ];
			}
			return current.filter( ( id ) => id !== tagId );
		} );
		setSelectedSlot( null );
	};

	// #431: タグ絞り込みで選択中のスタッフ・メニューが対象外になった場合、選択をリセットする
	// （issue #431 実装メモ「タグをチェックしたときの選択状態の整合」）。
	// 該当リソースが1件以上あるときに限り、対応スタッフ未登録のメニューは絞り込み対象外
	// （選択を維持）として扱う。該当リソースが0件になったときは対応スタッフ未登録の
	// メニューも含めて対象外にする（サーバー側 Menu_Loop_Block::is_menu_visible_for_tag_filter()、
	// および staffFilteredMenus の判定と揃える）。
	// staffOptions（リソース一覧）の非同期取得が完了する前は resourceIdsForTagFilter が
	// 常に空配列になり、「該当リソース無し」と誤判定して選択済みのスタッフ・メニューを
	// 毎回解除してしまう。staffOptions の読み込み完了（1件以上取得できている状態）までは
	// このリセット処理自体を動かさない。
	useEffect( () => {
		if ( ! tagFilterActive || staffOptions.length === 0 ) {
			return;
		}

		if ( staffId && ! resourceIdsForTagFilter.includes( staffId ) ) {
			setStaffId( 0 );
		}

		if ( menuId ) {
			const assignable = extractAssignableStaffIds( currentMenu?.meta );
			const menuStillMatches =
				resourceIdsForTagFilter.length > 0 &&
				( assignable.length === 0 ||
					assignable.some( ( id ) =>
						resourceIdsForTagFilter.includes( id )
					) );
			if ( ! menuStillMatches ) {
				setMenuId( 0 );
			}
		}
	}, [
		tagFilterActive,
		resourceIdsForTagFilter,
		staffOptions,
		staffId,
		menuId,
		currentMenu,
	] );

	// お気に入り（いつもの）を選んでフォームに反映する。
	// メニューを切り替え、指名スタッフはメニューに紐づく対応スタッフに含まれる場合のみ適用する。
	const applyFavorite = ( favorite ) => {
		const favoriteMenuId = Number( favorite?.menu_id ) || 0;
		if ( favoriteMenuId <= 0 ) {
			return;
		}

		// メニュー変更（指名・日時・人数のリセットを含む）。
		handleMenuChange( favoriteMenuId );

		// handleMenuChange が指名を再計算するため、お気に入りの指名はその後に上書きする。
		const favoriteResourceId = Number( favorite?.resource_id ) || 0;
		const favoriteMenu = menus.find(
			( menu ) => menu.id === favoriteMenuId
		);
		// #391: お気に入り先のメニュー自身の指名可否で判定する。
		const favoriteMenuNominationEnabled =
			providerSettings.staffEnabled &&
			! Boolean( favoriteMenu?.meta?._vkbm_disable_nomination );
		if ( favoriteMenuNominationEnabled ) {
			if ( favoriteResourceId > 0 ) {
				const assignable = extractAssignableStaffIds(
					favoriteMenu?.meta
				);
				// 対応スタッフ未設定のメニュー、または対応スタッフに含まれる場合のみ指名する。
				if (
					assignable.length === 0 ||
					assignable.includes( favoriteResourceId )
				) {
					setStaffId( favoriteResourceId );
				}
			} else {
				// 指名なしで登録したお気に入りは、直前に選んでいたスタッフが
				// 新メニューでも対応可能でも引き継がず、必ず「指名なし」を反映する。
				setStaffId( 0 );
			}
		}

		setFavoritesError( '' );
	};

	// お気に入りを削除する。
	const handleDeleteFavorite = ( favoriteId ) => {
		const id = String( favoriteId || '' );
		if ( '' === id ) {
			return;
		}

		setFavoritesError( '' );

		apiFetch( {
			path: `/vkbm/v1/favorites/${ encodeURIComponent( id ) }`,
			method: 'DELETE',
		} )
			.then( () => {
				// 楽観的に一覧から取り除く。
				setFavorites( ( current ) =>
					Array.isArray( current )
						? current.filter( ( favorite ) => favorite?.id !== id )
						: []
				);
			} )
			.catch( ( error ) => {
				setFavoritesError(
					error?.message ||
						__(
							'Could not delete the favorite.',
							'vk-booking-manager'
						)
				);
			} );
	};

	const handleMenuLoopClick = useCallback(
		( event ) => {
			const target = event.target;
			if ( ! target || typeof target.closest !== 'function' ) {
				return;
			}

			const reserveButton = target.closest(
				'a.vkbm-menu-loop__button--reserve'
			);
			if ( ! reserveButton ) {
				return;
			}

			event.preventDefault();

			const item = target.closest( '.vkbm-menu-loop__item' );
			if ( ! item ) {
				return;
			}

			const rawId = item.getAttribute( 'data-menu-id' ) || '';
			const nextId = Number( rawId ) || 0;

			if ( ! nextId ) {
				return;
			}

			handleMenuChange( nextId );
			const blockRoot = layoutRef.current?.closest?.(
				'.vkbm-reservation-block'
			);
			const scrollTarget = blockRoot || layoutRef.current;
			if ( scrollTarget ) {
				scrollTarget.scrollIntoView( {
					behavior: 'smooth',
					block: 'start',
				} );
			}
		},
		[ handleMenuChange ]
	);

	// 複数人一括予約メニューで満枠（選択可能人数が0）のスロットは予約へ進めない。
	// 料金区分メニューは合計人数が 1〜選択可能上限の範囲にある場合のみ進める。
	// #393: 指名を使うメニューの最低申し込み人数（受付制限）を満たさない場合も進めない。
	const canProceed = Boolean(
		menuId &&
			selectedSlot &&
			( ! allowMultipleGuests || maxSelectableGuests >= 1 ) &&
			( ! hasPriceTiers ||
				( guestTiersTotalCount >= 1 &&
					guestTiersTotalCount <= maxSelectableGuests ) ) &&
			currentGuestCount >= nominationMinGuests
	);

	const handleProceed = useCallback( () => {
		if ( isEditor ) {
			setSubmitError(
				__(
					'The editor does not transition to the confirmation page.',
					'vk-booking-manager'
				)
			);
			return;
		}

		if ( ! selectedSlot || ! menuId ) {
			setSubmitError(
				__(
					'Please select your menu and reservation slot before proceeding.',
					'vk-booking-manager'
				)
			);
			return;
		}

		// 防御的チェック: 複数人一括予約で人数が1未満（満枠で 0 にクランプされた等）の場合は送信しない。
		// canProceed 側のロジックにバグがあっても不正な人数をサーバーへ送らないための保険。
		if ( allowMultipleGuests && ! hasPriceTiers && guests < 1 ) {
			setSubmitError(
				__( 'Please select the quantity.', 'vk-booking-manager' )
			);
			return;
		}

		// 料金区分メニューは合計人数が最低1名必要（特定区分が0名でも合計1名以上ならOK）。
		if ( hasPriceTiers && guestTiersTotalCount < 1 ) {
			setSubmitError(
				__( 'Please select at least one guest.', 'vk-booking-manager' )
			);
			return;
		}

		// #393: 指名を使うメニューの最低申し込み人数（受付制限）を満たさない場合は送信しない。
		// canProceed 側のロジックにバグがあっても不正な人数をサーバーへ送らないための保険。
		if (
			nominationMinGuests > 0 &&
			currentGuestCount < nominationMinGuests
		) {
			setSubmitError(
				buildNominationMinGuestsMessage( nominationMinGuests )
			);
			return;
		}

		setIsSubmitting( true );
		setSubmitError( '' );

		const payload = {
			token: getDraftTokenFromCookie(),
			menu_id: menuId,
			// おまかせ自動分配時は resource_id=0 で送り、サーバーで複数スタッフへ配分する。
			// effectiveResourceId は常に数値（autoDistribute なら 0、それ以外は staffId）のため || 0 は不要。
			resource_id: effectiveResourceId,
			// #431: 予約時に指定したリソースタグ（ターム ID配列・AND条件）。予約確定時の
			// 再検証・最終チェック・予約メタ保存（希望タグ）まで同じ配列が使われる。
			resource_tag_ids: effectiveResourceTagIds,
			menu_label:
				currentMenu?.title?.rendered ??
				currentMenu?.title ??
				currentMenu?.name ??
				'',
			staff_label: effectiveResourceId
				? currentStaff?.title?.rendered ??
				  currentStaff?.title ??
				  currentStaff?.name ??
				  ''
				: providerSettings.noNominationLabel,
			// おまかせ自動分配のときは指名扱いにしない（複数スタッフへ配分させる）。
			// それ以外は従来どおりスタッフ選択時に指名扱いとする。
			is_staff_preferred: ! autoDistribute && Boolean( staffId ),
			date: selectedDate,
			// 予約人数（複数人一括予約対応メニューのみ。非対応時は1）。
			// 料金区分メニューは区分人数の合計、それ以外は単一の人数。
			guests: hasPriceTiers
				? guestTiersTotalCount
				: allowMultipleGuests
				? guests
				: 1,
			// 料金区分メニューのみ、区分ごとの人数（区分インデックス => 人数）を送る。
			// ラベル・料金はサーバ保存メタを正とするため送らない（送っても無視される）。
			...( hasPriceTiers
				? {
						guest_tiers: guestTierCounts.map( ( value ) =>
							Math.max( 0, Math.floor( Number( value ) || 0 ) )
						),
				  }
				: {} ),
			// ユーザーによる貸し切り指定（#305）。チェックが有効状態で選択されているときのみ true を送る。
			// 単価・適用外人数・最小催行人数・空き枠の最終判定はサーバ側で権威的に行う。
			user_exclusive: Boolean( userExclusive && exclusiveCheckEnabled ),
			slot: {
				slot_id: selectedSlot.slot_id,
				start_at: selectedSlot.start_at,
				end_at: selectedSlot.end_at,
				service_end_at:
					selectedSlot.service_end_at || selectedSlot.end_at,
				duration_minutes: selectedSlot.duration_minutes || 0,
				// 最小催行人数（グループ開催型）と当該枠の合計予約人数。確定画面の催行注記表示に使う。
				min_capacity: Math.max(
					0,
					Number( selectedSlot.min_capacity ) || 0
				),
				booked_guests: Math.max(
					0,
					Number( selectedSlot.booked_guests ) || 0
				),
				staff_label:
					selectedSlot.staff_label ||
					( staffId
						? currentStaff?.title?.rendered ??
						  currentStaff?.title ??
						  currentStaff?.name ??
						  ''
						: providerSettings.noNominationLabel ),
				staff: selectedSlot.staff
					? {
							id: selectedSlot.staff.id,
							name:
								selectedSlot.staff.name ||
								selectedSlot.staff.title ||
								'',
					  }
					: null,
				assignable_staff_ids: Array.isArray(
					selectedSlot.assignable_staff_ids
				)
					? selectedSlot.assignable_staff_ids
							.map( ( id ) => Number( id ) || 0 )
							.filter( Boolean )
					: [],
				auto_assign: Boolean(
					selectedSlot.auto_assign ||
						( ! staffId &&
							! selectedSlot.staff &&
							( ! selectedSlot.assignable_staff_ids ||
								selectedSlot.assignable_staff_ids.length > 0 ) )
				),
			},
			meta: {
				timezone: calendarData?.meta?.timezone || '',
			},
		};

		apiFetch( {
			path: '/vkbm/v1/drafts',
			method: 'POST',
			data: payload,
		} )
			.then( ( response ) => {
				const token = sanitizeDraftToken( response?.token );
				const expiresIn = response?.expires_in || 1800;
				if ( ! token ) {
					throw new Error(
						__(
							'Temporary reservation data save failed.',
							'vk-booking-manager'
						)
					);
				}

				storeDraftToken( token, expiresIn );

				if ( typeof window !== 'undefined' ) {
					const url = new URL( window.location.href );
					url.searchParams.delete( 'vkbm_auth' );
					url.searchParams.set( 'draft', token );
					window.location.href = url.toString();
				}
			} )
			.catch( ( error ) => {
				setSubmitError(
					error?.message ||
						__(
							'Failed to save reservation details. Please try again later.',
							'vk-booking-manager'
						)
				);
			} )
			.finally( () => {
				setIsSubmitting( false );
			} );
	}, [
		allowMultipleGuests,
		guests,
		hasPriceTiers,
		guestTierCounts,
		guestTiersTotalCount,
		userExclusive,
		exclusiveCheckEnabled,
		calendarData?.meta?.timezone,
		currentGuestCount,
		currentMenu,
		currentStaff,
		isEditor,
		menuId,
		nominationMinGuests,
		providerSettings,
		selectedDate,
		selectedSlot,
		staffId,
	] );

	const shouldShowLogo =
		providerSettings.showProviderLogo &&
		typeof providerSettings.providerLogoUrl === 'string' &&
		providerSettings.providerLogoUrl.trim() !== '';
	const shouldShowName =
		providerSettings.showProviderName &&
		typeof providerSettings.providerName === 'string' &&
		providerSettings.providerName.trim() !== '';
	const shouldShowBrand = shouldShowLogo || shouldShowName;
	const reservationPageUrl =
		typeof providerSettings.reservationPageUrl === 'string'
			? providerSettings.reservationPageUrl
			: '';
	const brandLinkHref = reservationPageUrl.trim();
	const brandLogoAlt = shouldShowName
		? providerSettings.providerName
		: __( 'Logo image', 'vk-booking-manager' );
	const navAriaLabel = __(
		'Reservation block navigation',
		'vk-booking-manager'
	);
	const returnLabel = shouldShowBrand
		? __( 'Return', 'vk-booking-manager' )
		: __( 'return', 'vk-booking-manager' );
	const navVariant = shouldShowUserSection
		? 'return'
		: isLoggedIn && canManageReservations
		? 'staff'
		: isLoggedIn
		? 'member'
		: 'guest';
	const navProps = {
		screen: 'reservation',
		variant: navVariant,
		ariaLabel: navAriaLabel,
		staffDashboardHref: shiftDashboardUrl || '',
		logoutHref:
			logoutUrl ||
			buildLogoutFallbackUrl( providerSettings.reservationPageUrl ),
		onReturn: () => handleAuthLink( authMode || '' ),
		returnLabel,
		onBookings: () => handleAuthLink( 'bookings' ),
		isBookingsActive: authMode === 'bookings',
		onProfile: () => handleAuthLink( 'profile' ),
		isProfileActive: isProfileMode,
		onLogin: () => handleAuthLink( 'login' ),
		isLoginActive: authMode === 'login',
		onRegister: () => handleAuthLink( 'register' ),
		isRegisterActive: authMode === 'register',
	};

	if ( confirmDraftToken ) {
		return (
			<div className="vkbm-reservation-content vkbm-reservation-content--confirm">
				<BookingConfirmApp
					redirectUrl=""
					termsLabel={ __(
						'I agree to the terms of use',
						'vk-booking-manager'
					) }
					policyText={ __(
						'Please check our cancellation policy before booking.',
						'vk-booking-manager'
					) }
					successMessage={ __(
						'Your reservation has been completed.',
						'vk-booking-manager'
					) }
					isEditor={ isEditor }
				/>
			</div>
		);
	}

	// 予約一覧を「今後の予約」と「過去の予約」に分割する。is_past はサーバ側で判定済み。
	const upcomingBookings = bookings.filter(
		( booking ) => ! booking?.is_past
	);
	// 過去の予約は新しい順（降順）に並べ替え、表示件数を上限まで絞る。
	const pastBookings = bookings
		.filter( ( booking ) => booking?.is_past )
		.slice()
		.reverse()
		.slice( 0, 20 );

	// 予約カード1件分を描画する。今後／過去どちらのセクションからも再利用する。
	const renderBookingCard = ( booking, index ) => {
		const datetimeParts = formatBookingDateTimeParts(
			booking?.start_at,
			booking?.end_at
		);
		const statusKey = String( booking?.status || '' ).trim();
		const isPending = statusKey.toLowerCase() === 'pending';
		const isCancelled = statusKey.toLowerCase() === 'cancelled';
		// メニューが削除済み等で参照できない場合は「同じ内容で予約する」を出さない。
		const canRebook =
			Number( booking?.menu_id ) > 0 &&
			String( booking?.menu_name || '' ).trim() !== '';
		const rebookUrl = canRebook
			? buildRebookUrl( booking, providerSettings.reservationPageUrl )
			: '';

		return (
			<div
				className={ [
					'vkbm-confirm__summary',
					isCancelled && 'vkbm-confirm__summary--cancelled',
				]
					.filter( Boolean )
					.join( ' ' ) }
				key={ booking?.id || index }
			>
				{ ( datetimeParts.date || datetimeParts.time ) && (
					<div className="vkbm-confirm__summary-title">
						<div className="vkbm-confirm__datetime">
							{ isPending && (
								<span className="vkbm-confirm__status vkbm-confirm__status--pending">
									{ __( 'Pending', 'vk-booking-manager' ) }
								</span>
							) }
							{ isCancelled && (
								<span className="vkbm-confirm__status vkbm-confirm__status--cancelled">
									{ __( 'Cancelled', 'vk-booking-manager' ) }
								</span>
							) }
							{ datetimeParts.date && (
								<span className="vkbm-confirm__date">
									{ datetimeParts.date }
								</span>
							) }
							{ datetimeParts.time && (
								<span className="vkbm-confirm__time">
									{ datetimeParts.date ? ' ' : '' }
									{ datetimeParts.time }
								</span>
							) }
						</div>
						<div className="vkbm-confirm__actions">
							{ booking?.can_cancel && (
								<button
									type="button"
									className="vkbm-button vkbm-button__sm vkbm-button__secondary vkbm-confirm__cancel-button"
									onClick={ () =>
										handleCancelBooking( booking?.id )
									}
									disabled={
										cancellingBookingId ===
										Number( booking?.id )
									}
								>
									{ cancellingBookingId ===
									Number( booking?.id )
										? __(
												'Processing…',
												'vk-booking-manager'
										  )
										: __( 'Cancel', 'vk-booking-manager' ) }
								</button>
							) }
							{ canRebook && rebookUrl && (
								<a
									className="vkbm-button vkbm-button__sm vkbm-button__primary vkbm-confirm__rebook-button"
									href={ rebookUrl }
								>
									{ __(
										'Book the same again',
										'vk-booking-manager'
									) }
								</a>
							) }
						</div>
					</div>
				) }
				<BookingSummaryItems
					booking={ booking }
					resourceLabel={ providerSettings.resourceLabelSingular }
					otherConditionsLabel={
						providerSettings.otherConditionsLabel
					}
					guestsCountLabel={ providerSettings.guestsCountLabel }
					guestsUnitLabel={ providerSettings.guestsUnitLabel }
					currencySymbol={ providerSettings.currencySymbol || null }
				/>
			</div>
		);
	};

	// #429 植草・安藤レビュー指摘（低・2回目再レビュー）: 一覧（サービスメニュー一覧）を
	// 表示する構成でも、一覧が読み込み中（menuList.isLoading）や取得失敗
	// （menuList.error）のときは、下記 JSX の一覧側0件メッセージ <p id={menuListEmptyMessageId}>
	// が実際には描画されない。この状態のまま SelectedPlanSummary へ menuListVisible=true を
	// 渡すと、プルダウン側の自前ヒントも一覧側のヒントも両方出ない（aria-describedby が
	// 存在しない id を指すだけになる）ため、一覧側0件メッセージが実際に描画される条件と
	// 完全に一致させる。この条件を満たさないときは SelectedPlanSummary 側の自前ヒントに戻す。
	const menuListEmptyMessageRendered =
		! menuId &&
		providerSettings.showMenuList &&
		providerSettingsLoaded &&
		! menuList.isLoading &&
		! menuList.error &&
		! menuList.html &&
		Boolean( menuListEmptyMessage );

	return (
		<div className="vkbm-reservation" ref={ layoutRef }>
			<ReservationHeader
				showBrand={ shouldShowBrand }
				brandLinkHref={ brandLinkHref }
				showLogo={ shouldShowLogo }
				logoUrl={ providerSettings.providerLogoUrl }
				logoAlt={ brandLogoAlt }
				showName={ shouldShowName }
				brandName={ providerSettings.providerName }
				nav={ navProps }
			/>

			{ shouldShowUserSection && (
				<section className="vkbm-user-section" aria-live="polite">
					<div className="vkbm-user-section__panel vkbm-reservation-content__auth-panel">
						{ authMode === 'bookings' && bookingsLoading && (
							<p
								className="vkbm-alert vkbm-alert__info vkbm-reservation-content__auth-loading"
								role="status"
							>
								{ __( 'Loading…', 'vk-booking-manager' ) }
							</p>
						) }
						{ authMode === 'bookings' && bookingsError && (
							<p
								className="vkbm-alert vkbm-alert__danger vkbm-reservation-content__auth-error"
								role="alert"
							>
								{ bookingsError }
							</p>
						) }
						{ authMode === 'bookings' &&
							! bookingsLoading &&
							! bookingsError &&
							bookings.length === 0 && (
								<p
									className="vkbm-alert vkbm-alert__info vkbm-reservation-content__auth-loading"
									role="status"
								>
									{ __(
										'There are no reservations to display.',
										'vk-booking-manager'
									) }
								</p>
							) }
						{ authMode === 'bookings' &&
							upcomingBookings.length > 0 && (
								<div className="vkbm-confirm vkbm-confirm--upcoming">
									<h3 className="vkbm-confirm__group-title">
										{ __(
											'Upcoming reservations',
											'vk-booking-manager'
										) }
									</h3>
									{ upcomingBookings.map(
										( booking, index ) =>
											renderBookingCard( booking, index )
									) }
								</div>
							) }
						{ authMode === 'bookings' &&
							pastBookings.length > 0 && (
								<div className="vkbm-confirm vkbm-confirm--past">
									<h3 className="vkbm-confirm__group-title">
										{ __(
											'Past reservations',
											'vk-booking-manager'
										) }
									</h3>
									{ pastBookings.map( ( booking, index ) =>
										renderBookingCard( booking, index )
									) }
								</div>
							) }

						{ authMode !== 'bookings' && authLoading && (
							<p
								className="vkbm-alert vkbm-alert__info vkbm-reservation-content__auth-loading"
								role="status"
							>
								{ __( 'Loading form…', 'vk-booking-manager' ) }
							</p>
						) }
						{ authMode !== 'bookings' && authError && (
							<p
								className="vkbm-alert vkbm-alert__danger vkbm-reservation-content__auth-error"
								role="alert"
							>
								{ authError }
							</p>
						) }
						{ authMode !== 'bookings' && authFormHtml && (
							<div
								className="vkbm-reservation-content__auth-form"
								dangerouslySetInnerHTML={ {
									__html: authFormHtml,
								} }
							/>
						) }
					</div>
				</section>
			) }

			{ shouldShowReservation && (
				<div
					className={ [
						'vkbm-reservation-content',
						isEditor && 'vkbm-reservation-content--is-editor',
					]
						.filter( Boolean )
						.join( ' ' ) }
				>
					{ providerSettingsLoaded &&
						isLoggedIn &&
						favoritesError && (
							<div className="vkbm-favorites__status">
								<p
									className="vkbm-alert vkbm-alert__danger"
									role="alert"
								>
									{ favoritesError }
								</p>
							</div>
						) }
					{ providerSettingsLoaded &&
						isLoggedIn &&
						favorites.length > 0 && (
							<section
								className="vkbm-favorites"
								aria-label={ __(
									'Favorites',
									'vk-booking-manager'
								) }
							>
								<h3 className="vkbm-favorites__title">
									{ __( 'Your usual', 'vk-booking-manager' ) }
								</h3>
								<ul className="vkbm-favorites__list">
									{ favorites.map( ( favorite ) => (
										<li
											className="vkbm-favorites__item"
											key={ favorite.id }
										>
											<button
												type="button"
												className="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__primary vkbm-favorites__apply"
												onClick={ () =>
													applyFavorite( favorite )
												}
												disabled={
													! favorite.available
												}
											>
												{ favorite.label ||
													favorite.menu_name }
											</button>
											<button
												type="button"
												className="vkbm-favorites__remove"
												aria-label={ __(
													'Delete favorite',
													'vk-booking-manager'
												) }
												onClick={ () =>
													handleDeleteFavorite(
														favorite.id
													)
												}
											>
												×
											</button>
										</li>
									) ) }
								</ul>
							</section>
						) }
					{ ! providerSettingsLoaded ? (
						<p
							className="vkbm-alert vkbm-alert__info"
							role="status"
						>
							{ __( 'Loading…', 'vk-booking-manager' ) }
						</p>
					) : (
						// #427: SelectedPlanSummary は「選択前」「選択後」で分岐を分けず、
						// 常に同じ位置（フラグメント内の1つ目）に1つだけ描画する。
						// menuId の有無で別ブロックに分けると React ツリー上の位置・親の型が
						// 変わり、プルダウンでメニューを選んだ瞬間に部品ごと作り直されて
						// フォーカスとスクリーンリーダーの読み上げ位置が失われるため（安藤レビュー指摘）。
						// 表示条件は「選択済み（menuId あり）」または「絞り込み検索が有効」。
						// 一覧全体は menuId が無い（未選択）ときだけ表示する。
						<>
							{ ( menuId || shouldShowMenuSearch ) && (
								<SelectedPlanSummary
									menuId={ menuId }
									staffId={ staffId }
									menus={ staffFilteredMenus }
									staffOptions={ availableStaffOptions }
									onMenuChange={ handleMenuChange }
									onStaffChange={ handleStaffChange }
									allowStaffSelection={
										staffSelectionEnabled
									}
									showStaffField={ menuNominationEnabled }
									lockStaffSelection={
										shouldLockStaffSelection
									}
									pricingRows={ pricingRows }
									menuPreviewLoading={ menuPreview.isLoading }
									menuPreviewError={ menuPreview.error }
									menuPreviewHtml={ menuPreview.html }
									resourceLabelSingular={
										providerSettings.resourceLabelSingular
									}
									noNominationLabel={
										providerSettings.noNominationLabel
									}
									menuUnavailableMessage={
										menuOptionsHiddenByStaffFilter
											? menuListEmptyMessage
											: ''
									}
									// #429 安藤・植草レビュー指摘（低・再レビュー、2回目再レビューで
									// 判定条件を修正）: 一覧側の0件メッセージが実際に描画されている
									// ときだけ、SelectedPlanSummary 側の自前ヒントを省略して
									// 一覧側の要素を aria-describedby で参照させる。一覧が読み込み中・
									// 取得失敗などで0件メッセージ自体が出ていないときは、従来どおり
									// SelectedPlanSummary 側の自前ヒントを出す。
									menuListVisible={
										menuListEmptyMessageRendered
									}
									menuListEmptyMessageId={
										menuListEmptyMessageId
									}
									// #431: リソースタグ検索。指名機能（staffEnabled）の
									// ON/OFF に関係なく表示・機能させる仕様のため、
									// showStaffField（menuNominationEnabled）とは
									// 独立した props として渡す。
									showResourceTagSearch={
										resourceTagSearchAllowed &&
										resourceTagOptions.length > 0
									}
									resourceTagOptions={ resourceTagOptions }
									selectedTagIds={ selectedTagIds }
									onTagToggle={ handleTagToggle }
									// #435: 「サービスメニュー一覧」を表示する設定のときは、
									// SelectedPlanSummary 側の「メニューを選択してください。」の
									// アラートを出さない（下に一覧のカードが並ぶため二重案内になる）。
									showMenuList={
										providerSettings.showMenuList
									}
								/>
							) }
							{ ! menuId &&
								providerSettings.showMenuList &&
								( menuList.html ? (
									<section
										className="vkbm-reservation-content__menu-list"
										aria-live="polite"
										onClick={ handleMenuLoopClick }
										dangerouslySetInnerHTML={ {
											__html: menuList.html,
										} }
									/>
								) : (
									<section
										className="vkbm-reservation-content__menu-list"
										aria-live="polite"
									>
										{ menuList.isLoading && (
											<p
												className="vkbm-alert vkbm-alert__info"
												role="status"
											>
												{ __(
													'Loading service menu…',
													'vk-booking-manager'
												) }
											</p>
										) }
										{ menuList.error && (
											<p
												className="vkbm-alert vkbm-alert__danger"
												role="alert"
											>
												{ menuList.error }
											</p>
										) }
										{ providerSettingsLoaded &&
											! menuList.isLoading &&
											! menuList.error &&
											! menuList.html && (
												<p
													id={
														menuListEmptyMessageId
													}
													className="vkbm-alert vkbm-alert__warning"
													role="status"
												>
													{ menuListEmptyMessage }
												</p>
											) }
									</section>
								) ) }
						</>
					) }

					{ hasMenuSelection && (
						<div className="vkbm-reservation-content__body">
							{ /* #411: 管理者・サイトオーナー・サロンオーナー向けの診断バナー。
							     一般訪問者にはサーバー側でフィールド自体が付与されないため、
							     常に空文字になり hidden のまま何も表示されない。
							     role="status" + aria-live="polite" は、利用者の操作結果ではなく
							     月表示時に受動的に現れる情報のため（role="alert" は使わない）。 */ }
							<div
								className="vkbm-alert vkbm-alert__warning vkbm-alert--compact vkbm-alert--admin-diagnostic"
								role="status"
								aria-live="polite"
								hidden={ ! adminDiagnosticMessage }
							>
								<svg
									className="vkbm-alert--admin-diagnostic__icon"
									viewBox="0 0 24 24"
									width="18"
									height="18"
									aria-hidden="true"
									focusable="false"
								>
									<path
										fill="currentColor"
										d="M12 2 1 21h22L12 2Zm0 5.5 7.53 12.5H4.47L12 7.5ZM11 10v5h2v-5h-2Zm0 6.5v2h2v-2h-2Z"
									/>
								</svg>
								<p>{ adminDiagnosticMessage }</p>
							</div>
							<CalendarGrid
								year={ monthCursor.year }
								month={ monthCursor.month }
								dayMetaMap={ dayMetaMap }
								selectedDate={ selectedDate }
								onSelectDate={ handleSelectDate }
								onMonthChange={ handleMonthChange }
								isLoading={ calendarLoading }
								locale={ userBootstrap?.locale }
								closedDayLabel={
									providerSettings.closedDayLabel
								}
							/>

							<div className="vkbm-reservation-content__slots">
								<DailySlotList
									slots={ slotData }
									selectedDate={ selectedDate }
									onSelectSlot={ ( slot ) =>
										setSelectedSlot( slot )
									}
									selectedSlotId={ selectedSlot?.slot_id }
									isLoading={ slotLoading }
									error={ slotListError }
									showStaffLabel={ menuNominationEnabled }
									isNominationMenu={ menuNominationEnabled }
									selectedStaffLabel={
										staffId
											? currentStaff?.title?.rendered ??
											  currentStaff?.title ??
											  currentStaff?.name ??
											  ''
											: ''
									}
									noNominationLabel={
										providerSettings.noNominationLabel
									}
								/>
							</div>
						</div>
					) }

					{ hasMenuSelection && (
						<div
							className="vkbm-plan-summary__status vkbm-plan-summary__status--detached"
							ref={ actionSectionRef }
						>
							<div className="vkbm-plan-summary__datetime">
								{ selectedSlot ? (
									<>
										{ /* 日付（年月日＋曜日）は途中で改行させない。 */ }
										<span className="text-nowrap">
											{ selectedSlot.start_at?.slice(
												0,
												10
											) }{ ' ' }
											{ formatWeekdayLabel(
												selectedSlot.start_at
											) }
										</span>{ ' ' }
										{ /* 時間帯（開始−終了）も途中で改行させない。 */ }
										<span className="text-nowrap">
											{ selectedSlot.start_at?.slice(
												11,
												16
											) }{ ' ' }
											-{ ' ' }
											{ (
												selectedSlot.service_end_at ||
												selectedSlot.end_at
											)?.slice( 11, 16 ) }
										</span>
									</>
								) : (
									<span>
										{ __(
											'Not selected',
											'vk-booking-manager'
										) }
									</span>
								) }
							</div>
							{ /* #393: 残り人数が最低申し込み人数に満たない枠は、料金区分側でも単一入力欄側と
								対称に「この時間帯は最低人数を受け付けられません」を明示する
								（安藤/司レビュー指摘）。区分ごとの入力欄と「もっと増やして」「もう十分」の
								矛盾した警告が同時に出る状態を避けるため、通常の区分入力欄は出さない。 */ }
							{ allowMultipleGuests &&
								selectedSlot &&
								hasPriceTiers &&
								slotBelowNominationMinGuests && (
									<div className="vkbm-plan-summary__guest-tiers">
										<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
											{ buildSlotBelowNominationMinGuestsMessage(
												nominationMinGuests
											) }
										</p>
									</div>
								) }
							{ /* 料金区分メニュー: 区分ごとに人数を入力する。 */ }
							{ allowMultipleGuests &&
								selectedSlot &&
								maxSelectableGuests >= 1 &&
								hasPriceTiers &&
								! slotBelowNominationMinGuests && (
									<div className="vkbm-plan-summary__guest-tiers">
										<span className="vkbm-plan-summary__guests-label">
											{
												providerSettings.guestsCountLabel
											}
										</span>
										{ priceTiers.map( ( tier, index ) => (
											<div
												key={ index }
												className="vkbm-plan-summary__guest-tier"
											>
												<label
													className="vkbm-plan-summary__guest-tier-label"
													htmlFor={ `vkbm-reservation-tier-${ index }` }
												>
													<span className="text-nowrap">
														{ tier.label }
													</span>
													<span className="vkbm-plan-summary__guest-tier-price">
														{ formatCurrency(
															tier.price,
															providerSettings.currencySymbol ||
																null
														) }
													</span>
												</label>
												<input
													type="number"
													id={ `vkbm-reservation-tier-${ index }` }
													className="vkbm-plan-summary__guests-select"
													min="0"
													max={ maxSelectableGuests }
													step="1"
													value={
														guestTierCounts[
															index
														] ?? 0
													}
													onChange={ ( event ) => {
														const value = Math.max(
															0,
															Math.floor(
																Number(
																	event.target
																		.value
																) || 0
															)
														);
														setGuestTierCounts(
															( current ) => {
																const next =
																	priceTiers.map(
																		(
																			_,
																			i
																		) =>
																			Math.max(
																				0,
																				Math.floor(
																					Number(
																						current[
																							i
																						]
																					) ||
																						0
																				)
																			)
																	);
																next[ index ] =
																	value;
																return next;
															}
														);
													} }
												/>
											</div>
										) ) }
										{ /* 人数未選択（合計0）のときは概算合計を表示しない。 */ }
										{ guestTiersTotalCount > 0 && (
											<div className="vkbm-plan-summary__guest-tiers-total">
												{ sprintf(
													/* translators: %s: total price. */
													__(
														'Total: %s',
														'vk-booking-manager'
													),
													formatCurrency(
														priceTiers.reduce(
															(
																sum,
																tier,
																index
															) =>
																sum +
																tier.price *
																	Math.max(
																		0,
																		Math.floor(
																			Number(
																				guestTierCounts[
																					index
																				]
																			) ||
																				0
																		)
																	),
															0
														),
														providerSettings.currencySymbol ||
															null
													)
												) }
											</div>
										) }
										{ /* 合計が残枠を超えると「予約へ進む」が無言で disabled になるのを避けるため、理由を明示する。 */ }
										{ guestTiersTotalCount >
											maxSelectableGuests && (
											<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
												{ sprintf(
													/* translators: %d: number of remaining bookable guests. */
													__(
														'You can book up to %d more.',
														'vk-booking-manager'
													),
													maxSelectableGuests
												) }
											</p>
										) }
										{ /* #393: 合計人数が指名メニューの最低申し込み人数に満たない場合の警告。 */ }
										{ /* どの区分に何名を入れるかは予約者が決めるため、区分ごとに下限は割り振らず、警告表示と送信抑止に留める。 */ }
										{ nominationMinGuests > 0 &&
											guestTiersTotalCount <
												nominationMinGuests && (
												<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
													{ buildNominationMinGuestsMessage(
														nominationMinGuests
													) }
												</p>
											) }
									</div>
								) }
							{ /* #393: 残り人数が最低申し込み人数に満たない枠は、下限だけを引き上げた矛盾した
								入力欄（min が max を超える）を出さず、その枠では申し込めないことと
								理由を明示する（安藤/司レビュー指摘）。 */ }
							{ allowMultipleGuests &&
								selectedSlot &&
								! hasPriceTiers &&
								slotBelowNominationMinGuests && (
									<div className="vkbm-plan-summary__guests">
										<p className="vkbm-plan-summary__hint vkbm-plan-summary__hint--warning">
											{ buildSlotBelowNominationMinGuestsMessage(
												nominationMinGuests
											) }
										</p>
									</div>
								) }
							{ /* 通常の複数人一括予約メニュー: 単一の人数入力。 */ }
							{ allowMultipleGuests &&
								selectedSlot &&
								! hasPriceTiers &&
								! slotBelowNominationMinGuests &&
								maxSelectableGuests > 1 && (
									<div className="vkbm-plan-summary__guests">
										<label
											className="vkbm-plan-summary__guests-label"
											htmlFor="vkbm-reservation-guests"
										>
											{
												providerSettings.guestsCountLabel
											}
										</label>
										{ /* 数値入力にして DOM 数を maxSelectableGuests に依存させない（大きな上限でのフリーズ防止）。 */ }
										{ /* Use a numeric input so the DOM count stays constant regardless of maxSelectableGuests. */ }
										<input
											type="number"
											id="vkbm-reservation-guests"
											className="vkbm-plan-summary__guests-select"
											min={ Math.max(
												1,
												nominationMinGuests
											) }
											max={ maxSelectableGuests }
											step="1"
											value={ guests }
											// #393: 説明文（案内文）と入力欄を関連付け、スクリーンリーダー利用者にも
											// フォーカス時に読み上げられるようにする（植草レビュー指摘）。
											// 案内文が出ない（最低申し込み人数0または1）のときは付けない。
											aria-describedby={
												nominationMinGuests > 1
													? 'vkbm-reservation-guests-hint'
													: undefined
											}
											onChange={ ( event ) =>
												// 手入力で小数が入る可能性があるため整数へ正規化してから範囲内にクランプする。
												// #393: 下限は最低申し込み人数（未適用なら1）。
												setGuests(
													Math.min(
														Math.max(
															Math.max(
																1,
																nominationMinGuests
															),
															Math.floor(
																Number(
																	event.target
																		.value
																) || 1
															)
														),
														maxSelectableGuests
													)
												)
											}
										/>
										{ /* #393: 指名を使うメニューの最低申し込み人数を常時案内する。状態に依存しない
											中立の文言（buildNominationMinGuestsHint()）を使う。既に条件を満たして
											いる状態でも表示され続けるため、命令形のエラー・警告文言
											（buildNominationMinGuestsMessage()）は使わない（植草レビュー指摘）。
											最低申し込み人数が1のときは実質何も制限していないため表示しない
											（安藤レビュー指摘。サーバー側の判定は `>0 && guests<min` のため、
											1では常に偽になり挙動は変わらない）。 */ }
										{ nominationMinGuests > 1 && (
											<p
												className="vkbm-plan-summary__hint"
												id="vkbm-reservation-guests-hint"
											>
												{ buildNominationMinGuestsHint(
													nominationMinGuests
												) }
											</p>
										) }
									</div>
								) }
							{ /* ユーザーによる貸し切り指定（#305）。人数入力の直下に表示する。 */ }
							{ exclusiveSelectable && selectedSlot && (
								<div className="vkbm-plan-summary__exclusive">
									<label className="vkbm-plan-summary__exclusive-label">
										<input
											type="checkbox"
											className="vkbm-plan-summary__exclusive-checkbox"
											checked={
												userExclusive &&
												exclusiveCheckEnabled
											}
											disabled={ ! exclusiveCheckEnabled }
											onChange={ ( event ) =>
												setUserExclusive(
													event.target.checked
												)
											}
										/>
										<span>
											{ /* 予約時点でメニューの指名の有無は既に確定しているため、フロントは
											menuNominationEnabled で文言を出し分けてよい（担当スタッフ単位で
											閉じることが伝わる文言にする）。 */ }
											{ menuNominationEnabled
												? __(
														"Make this staff member's time slot private (no other bookings for that staff member will be accepted)",
														'vk-booking-manager'
												  )
												: __(
														'Make this time slot private (no other bookings will be accepted)',
														'vk-booking-manager'
												  ) }
										</span>
									</label>
									{ /* 既に予約がある枠は非表示にせず disabled + 理由表示（色だけに頼らずテキスト併記）。 */ }
									{ slotIsOccupied ? (
										<p className="vkbm-plan-summary__exclusive-note vkbm-plan-summary__exclusive-note--disabled">
											{ __(
												'This slot already has a booking, so a private booking cannot be specified.',
												'vk-booking-manager'
											) }
										</p>
									) : (
										<>
											{ /* 人数が最小催行人数に満たない場合は理由を表示する。 */ }
											{ ! meetsMinCapacity && (
												<p className="vkbm-plan-summary__exclusive-note vkbm-plan-summary__exclusive-note--disabled">
													{ sprintf(
														/* translators: %d: minimum number of guests required for a private booking. */
														__(
															'A private booking requires at least %d guests.',
															'vk-booking-manager'
														),
														minCapacity
													) }
												</p>
											) }
											<p className="vkbm-plan-summary__exclusive-note">
												{ menuNominationEnabled
													? __(
															'Making it private means no one else can book this staff member for this time slot.',
															'vk-booking-manager'
													  )
													: __(
															'Making it private means no one else can book this time slot.',
															'vk-booking-manager'
													  ) }
											</p>
											{ exclusiveFeePerPerson > 0 &&
												( exclusiveFeeExempted ? (
													<p className="vkbm-plan-summary__exclusive-note vkbm-plan-summary__exclusive-note--muted">
														{ sprintf(
															/* translators: %d: number of guests at or above which the private booking fee is not charged. */
															__(
																'No private booking fee applies because there are %d or more guests.',
																'vk-booking-manager'
															),
															exclusiveFeeExempt
														) }
													</p>
												) : (
													<p className="vkbm-plan-summary__exclusive-note">
														{ sprintf(
															/* translators: %s: private booking fee per guest. */
															__(
																'A private booking fee of %s per guest will be added.',
																'vk-booking-manager'
															),
															formatCurrency(
																exclusiveFeePerPerson,
																providerSettings.currencySymbol ||
																	null
															)
														) }
													</p>
												) ) }
										</>
									) }
								</div>
							) }
							{ /* 料金サマリー（貸切料金行）。aria-live 領域は常設し、中身（金額・注記）を出し入れする。 */ }
							{ /* 後挿入の live region はスクリーンリーダーが初回読み上げしないことがあるため、 */ }
							{ /* 貸切料金が発生し得る場面（メニューがユーザー貸切ON＋単価あり）では領域自体を常に描画し、 */ }
							{ /* チェックの選択状態に応じて内側の金額行のみを出し入れする。 */ }
							{ exclusiveSelectable &&
								selectedSlot &&
								exclusiveFeePerPerson > 0 && (
									<div
										className="vkbm-plan-summary__exclusive-fee-live"
										aria-live="polite"
									>
										{ exclusiveCheckEnabled &&
											userExclusive && (
												<div className="vkbm-plan-summary__exclusive-fee">
													<span className="vkbm-plan-summary__exclusive-fee-label">
														{ __(
															'Private booking fee',
															'vk-booking-manager'
														) }
													</span>
													{ exclusiveFeeExempted ? (
														<span className="vkbm-plan-summary__exclusive-fee-value vkbm-plan-summary__exclusive-fee-value--muted">
															{ formatCurrency(
																0,
																providerSettings.currencySymbol ||
																	null
															) }
															<span className="vkbm-plan-summary__exclusive-fee-note">
																{ sprintf(
																	/* translators: %d: number of guests at or above which the private booking fee is not charged. */
																	__(
																		'No private booking fee applies because there are %d or more guests.',
																		'vk-booking-manager'
																	),
																	exclusiveFeeExempt
																) }
															</span>
														</span>
													) : (
														<span className="vkbm-plan-summary__exclusive-fee-value">
															{ '+ ' +
																formatCurrency(
																	exclusiveFee,
																	providerSettings.currencySymbol ||
																		null
																) }
														</span>
													) }
												</div>
											) }
									</div>
								) }
							<div className="vkbm-plan-summary__actions">
								<button
									type="button"
									className="vkbm-plan-summary__action vkbm-button vkbm-button__md vkbm-button__primary"
									onClick={ handleProceed }
									disabled={ ! canProceed || isSubmitting }
								>
									{ isSubmitting
										? __(
												'Processing…',
												'vk-booking-manager'
										  )
										: __(
												'Proceed to Reservation',
												'vk-booking-manager'
										  ) }
								</button>
								{ submitError && (
									<p className="vkbm-plan-summary__error">
										{ submitError }
									</p>
								) }
							</div>
						</div>
					) }
				</div>
			) }
		</div>
	);
};
const formatWeekdayLabel = ( isoString ) => {
	if ( ! isoString ) {
		return '';
	}
	try {
		const date = new Date( isoString );
		if ( Number.isNaN( date.getTime() ) ) {
			return '';
		}
		const weekday = new Intl.DateTimeFormat( 'ja-JP', {
			weekday: 'short',
		} ).format( date );
		return `(${ weekday })`;
	} catch ( error ) {
		return '';
	}
};
