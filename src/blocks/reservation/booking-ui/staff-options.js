// メニュー（REST の vkbm_service_menu 1件）から、指名可能なスタッフID配列を正規化して取り出す。
// メニュー選択・お気に入り適用・指名可能判定で共通利用する。
// #485: サーバー側で「すべてのスタッフが担当できる」を公開中の全スタッフへ展開した
// 読み取り専用フィールド vkbm_assignable_staff_ids を優先して使う（展開ロジックを
// フロントで二重実装しないため。判定は Service_Menu_Post_Type::get_assignable_staff_ids() に集約）。
// フィールドが無い（古いレスポンス等）場合のみ meta._vkbm_staff_ids へフォールバックする。
export const extractAssignableStaffIds = ( menu ) => {
	const meta = menu?.meta;
	const rawIds = Array.isArray( menu?.vkbm_assignable_staff_ids )
		? menu.vkbm_assignable_staff_ids
		: Array.isArray( meta?._vkbm_staff_ids ) && meta._vkbm_staff_ids.length
		? meta._vkbm_staff_ids
		: Array.isArray( meta?.vkbm_staff_ids ) && meta.vkbm_staff_ids.length
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
 * #761: 泊のプランを担当できる客室と、選択中の客室に選択肢を絞る。
 * 泊のプランの担当客室が選択肢に無い場合は、選択中の客室に関係なく元の一覧を返す。
 *
 * @param {Array}  staffOptions  客室の選択肢一覧。
 * @param {Array}  menus         予約ページが受け取ったメニュー一覧。
 * @param {number} staffFilterId 選択中の客室ID（0 はおまかせ）。
 * @return {Array} 元の順序を保った客室の選択肢一覧。
 */
export const filterLodgingStaffOptions = (
	staffOptions,
	menus,
	staffFilterId
) => {
	const lodgingMenus = menus.filter( ( menu ) =>
		Boolean( menu?.vkbm_lodging )
	);
	if ( lodgingMenus.length === 0 ) {
		return staffOptions;
	}

	// 泊のプランの担当客室をまとめる。
	const allowedIds = new Set(
		lodgingMenus.flatMap( extractAssignableStaffIds )
	);
	// 選択中の客室を加える前に判定し、担当客室が無いときに1室だけになるのを防ぐ。
	if ( ! staffOptions.some( ( staff ) => allowedIds.has( staff.id ) ) ) {
		return staffOptions;
	}

	// 担当客室が存在する場合は、選択済みの時間プラン専用客室も残す。
	if ( staffFilterId > 0 ) {
		allowedIds.add( staffFilterId );
	}
	return staffOptions.filter( ( staff ) => allowedIds.has( staff.id ) );
};
