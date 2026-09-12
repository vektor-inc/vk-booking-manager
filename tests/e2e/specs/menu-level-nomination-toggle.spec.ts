/**
 * メニュー単位の指名機能設定（issue #391）の e2e 検証
 *
 * これまでは「指名機能を使うか」はサイト全体で1つのスイッチ
 * （基本設定 > システム > 指名機能）でしか切り替えられなかった。
 * 本機能はこれをサービスメニュー単位でも切り替えられるようにする
 * （メニュー投稿メタ `_vkbm_disable_nomination`、既定は「使う」）。
 *
 * issue #392（指名を使うメニューを「1枠1組・貸切」として扱う仕様変更）により、
 * 管理画面の表示・空き枠capacityの挙動が変わったため、それに合わせて更新している：
 * - 予約枠の定員フィールドは、指名を使うメニューでも常に表示される（#392より前は
 *   案内メッセージに差し替わり非表示だった。案内メッセージ要素自体を撤去済み）
 * - 指名を使うメニューの空き枠capacityは、指名OFFのメニューと同様にメニューの
 *   `_vkbm_max_capacity`（この例では3）を反映する（#392より前は常に1に固定されていた）
 *
 * 検証内容:
 * 1. 管理画面: サイト全体の指名機能ON時、サービスメニュー編集画面に
 *    「このメニューで指名を使う」チェックボックスが表示され、既定でチェックされている。
 *    予約枠の定員フィールドは指名を使うメニューでも表示される（#392）。
 * 2. 管理画面: サイト全体は指名ONのままメニュー単位で無効化すると、チェックが外れた状態で
 *    表示され、予約枠の定員フィールドも引き続き表示される。
 * 3. 管理画面: サイト全体の指名機能がOFFのサイトでは、このチェックボックス自体が現れない。
 * 4. REST: 同じ「予約枠の定員3・複数人一括予約許可」設定を持つ2つのメニューで、
 *    指名を使う・使わないのいずれも空き枠の capacity が3になること（#392：定員は
 *    指名の有無に関わらず「1組の最大人数」として使われる）。
 * 5. フロント: メニュー単位で指名を無効化したメニューは予約フォームにスタッフの指名欄が出ず、
 *    指名を使うメニューは引き続きスタッフの指名欄が表示されること。
 *
 * 指名ONメニューの1枠1組（貸切）としての実際の予約可否・排他制御（3名予約・4名拒否・
 * 予約後に他の予約者から選べなくなる等）は issue-392-nomination-slot-capacity.spec.ts で
 * 別途検証する。本ファイルは #391 のメニュー単位トグル自体の検証に主眼を置く。
 *
 * 管理画面の表示制御（1〜3）は、pr-311-min-capacity.spec.ts に倣い
 * Service_Menu_Editor::render_conditions_meta_box() の実際の出力を wpEvalPhp 経由で
 * 直接検証する（ブロックエディタのメタボックス折りたたみに依存せず安定させるため）。
 * 空き枠の capacity（4）は REST を直接叩いて検証し、指名UIの有無（5）のみ実ブラウザで確認する。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto / page.request には相対パスを渡す。
 */
import { test, expect } from '@playwright/test';
import {
	wpEvalPhp,
	getStaffId,
	getStaffEnabled,
	setStaffEnabled,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	extractOpeningTagById,
} from '../utils/helpers';

const NO_NOMINATION_MENU_TITLE = 'Menu Level No Nomination Menu';
const USES_NOMINATION_MENU_TITLE = 'Menu Level Uses Nomination Menu';

let originalStaffEnabled = true;
let noNominationMenuId = '';
let usesNominationMenuId = '';

/**
 * Service_Menu_Editor::render_basic_meta_box() + render_conditions_meta_box() の
 * HTML 出力を WP-CLI eval 経由で直接取得するヘルパー（pr-311-min-capacity.spec.ts と同じ手法）。
 *
 * render_vkbm_meta_box() が実際にこの2つを同じ順序で連結して描画するため、それを模す。
 * 「このメニューで指名を使う」チェックボックスは render_basic_meta_box() 側にある
 * （#412 B-2: 依存先の「Nomination fee」より前に配置するため）。
 *
 * @param menuId サービスメニューの投稿ID（数値文字列）
 * @return 連結したメタボックスの HTML 出力
 */
function getMenuMetaboxHtml( menuId: string ): string {
	const phpCode = `
		set_current_screen( 'post' );
		$post = get_post( ${ menuId } );
		if ( ! $post ) {
			echo 'ERROR: Post not found';
			return;
		}
		$editor = new \\VKBookingManager\\Admin\\Service_Menu_Editor();
		ob_start();
		$editor->render_basic_meta_box( $post );
		$editor->render_conditions_meta_box( $post );
		echo ob_get_clean();
	`;
	return wpEvalPhp( phpCode );
}

/**
 * 検証用メニューを作成する。両メニューとも「予約枠の定員3・複数人一括予約許可」を
 * 同一に揃え、`_vkbm_disable_nomination` の有無だけを差分にすることで、
 * この設定単体の効果を検証できるようにする。同名メニューがあれば作り直す（冪等）。
 *
 * @param title             メニュータイトル。
 * @param staffId           割り当てるスタッフID。
 * @param disableNomination true のときメニュー単位で指名を無効化する（_vkbm_disable_nomination）。
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedMenu(
	title: string,
	staffId: string,
	disableNomination: boolean
): string {
	const disableNominationLine = disableNomination
		? "update_post_meta( $menu_id, '_vkbm_disable_nomination', true );"
		: '';
	const phpCode = `
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ title }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( ${ Number.parseInt(
			staffId,
			10
		) } ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		${ disableNominationLine }
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Menu seeding failed ("${ title }"): "${ result }"` );
	}
	return result;
}

test.describe( 'メニュー単位の指名機能設定（#391）', () => {
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		// サイト全体の指名機能はONのまま検証する（メニュー単位トグルが意味を持つ前提）。
		setStaffEnabled( true );

		const staffId = getStaffId();
		noNominationMenuId = seedMenu(
			NO_NOMINATION_MENU_TITLE,
			staffId,
			true
		);
		usesNominationMenuId = seedMenu(
			USES_NOMINATION_MENU_TITLE,
			staffId,
			false
		);
	} );

	test.afterAll( () => {
		if ( noNominationMenuId ) {
			wpEvalPhp(
				`wp_delete_post( ${ Number.parseInt(
					noNominationMenuId,
					10
				) }, true );`
			);
			noNominationMenuId = '';
		}
		if ( usesNominationMenuId ) {
			wpEvalPhp(
				`wp_delete_post( ${ Number.parseInt(
					usesNominationMenuId,
					10
				) }, true );`
			);
			usesNominationMenuId = '';
		}
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '管理画面: サイト全体の指名機能ON・メニュー単位設定なし（既定） => 「このメニューで指名を使う」がチェック済みで表示され、予約枠の定員フィールドも表示される（#392）', () => {
		setStaffEnabled( true );
		const html = getMenuMetaboxHtml( usesNominationMenuId );

		expect( html ).toContain( 'vkbm_service_menu[use_nomination]' );
		// checked 属性が付いていること（既定＝使う）。
		expect( html ).toMatch(
			/name="vkbm_service_menu\[use_nomination\]"[^>]*checked/
		);
		// #392: 旧仕様（PR #163・#412）の案内メッセージ要素（vkbm-nomination-enabled-notice）は
		// 撤去済みで、DOM に存在しない。
		expect(
			extractOpeningTagById( html, 'vkbm-nomination-enabled-notice' )
		).toBe( '' );
		// #392: 予約枠の定員フィールドは、指名を使うメニューでも常に表示される
		// （1組の最大人数として使うため）。
		//
		// #412 F-3: extractOpeningTagById() は要素が見つからないと空文字を返し、
		// 空文字は `.not.toContain( 'hidden' )` を素通りしてしまう（空振り）。
		// 否定チェックの前に要素が実際に DOM に存在すること（空文字でないこと）を確認する。
		const maxCapacityTag = extractOpeningTagById(
			html,
			'vkbm-max-capacity-field'
		);
		expect( maxCapacityTag ).not.toBe( '' );
		expect( maxCapacityTag ).not.toContain( 'hidden' );
		// 「Nomination fee」欄（指名を使うメニューのみ表示。#392でも変更なし）は引き続き表示される。
		const feeFieldTag = extractOpeningTagById(
			html,
			'vkbm-disable-nomination-fee-field'
		);
		expect( feeFieldTag ).not.toBe( '' );
		expect( feeFieldTag ).not.toContain( 'hidden' );
	} );

	test( '管理画面: サイト全体は指名ONだがメニュー単位で指名を無効化 => チェックが外れた状態で表示され、予約枠の定員フィールドが表示される', () => {
		setStaffEnabled( true );
		const html = getMenuMetaboxHtml( noNominationMenuId );

		expect( html ).toContain( 'vkbm_service_menu[use_nomination]' );
		// checked 属性が付いていないこと（メニュー単位で無効化済み）。
		expect( html ).not.toMatch(
			/name="vkbm_service_menu\[use_nomination\]"[^>]*checked/
		);
		// #392: 旧仕様の案内メッセージ要素は撤去済みで、指名OFF時も DOM に存在しない。
		expect(
			extractOpeningTagById( html, 'vkbm-nomination-enabled-notice' )
		).toBe( '' );
		// #412 F-3: 否定チェックの前に要素が DOM に存在すること（空文字でないこと）を確認する
		// （extractOpeningTagById() は見つからないと空文字を返し、空文字は
		// `.not.toContain( 'hidden' )` を素通りしてしまうため）。
		const maxCapacityTag = extractOpeningTagById(
			html,
			'vkbm-max-capacity-field'
		);
		expect( maxCapacityTag ).not.toBe( '' );
		expect( maxCapacityTag ).not.toContain( 'hidden' );
		// 「Nomination fee」欄は指名を使わないメニューでは非表示のまま（#392でも変更なし）。
		expect(
			extractOpeningTagById( html, 'vkbm-disable-nomination-fee-field' )
		).toContain( 'hidden' );
	} );

	test( '管理画面: サイト全体の指名機能がOFFのサイトでは、メニュー単位トグル自体が現れない', () => {
		setStaffEnabled( false );
		const html = getMenuMetaboxHtml( usesNominationMenuId );

		expect( html ).not.toContain( 'vkbm_service_menu[use_nomination]' );
		// サイト全体OFFなので、このメニューも通常どおり予約枠の定員フィールドが表示される
		// （メニュー単位設定の有無に関わらず、サイト全体OFFの挙動は変わらない）。
		// #412 F-3: 否定チェックの前に要素が DOM に存在すること（空文字でないこと）を確認する。
		const maxCapacityTagWhenGloballyOff = extractOpeningTagById(
			html,
			'vkbm-max-capacity-field'
		);
		expect( maxCapacityTagWhenGloballyOff ).not.toBe( '' );
		expect( maxCapacityTagWhenGloballyOff ).not.toContain( 'hidden' );

		setStaffEnabled( true );
	} );

	test( 'REST: 予約枠の定員・複数人一括予約許可が同一なら、指名の有無に関わらず空き枠のcapacityは同じ（#392：定員は1組の最大人数）', async ( {
		page,
	} ) => {
		setStaffEnabled( true );
		const staffId = getStaffId();
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		createShiftForMonth(
			staffId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);

		const year = String( nextTokyoMonth.year );
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		const dateStr = `${ year }-${ month }-15`;

		// メニュー単位で指名を無効化したメニュー => 自動割り当てで相乗り可（capacity=3）。
		const noNominationResponse = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ noNominationMenuId }&date=${ dateStr }&timezone=Asia/Tokyo`
		);
		expect( noNominationResponse.status() ).toBe( 200 );
		const noNominationData = await noNominationResponse.json();
		const noNominationSlots = Array.isArray( noNominationData )
			? noNominationData
			: noNominationData.slots || [];
		expect( noNominationSlots.length ).toBeGreaterThan( 0 );
		expect( noNominationSlots[ 0 ].capacity ).toBe( 3 );

		// #392: 指名を使うメニュー => 定員（3）はそのスタッフを指名した1件の予約で
		// 申し込める最大人数として使われるため、指名を使わないメニューと同じ capacity=3 になる
		// （#392より前は常に1対1・capacity=1に固定されていた）。1枠1組（貸切）の排他制御は
		// capacity の値ではなく remaining/予約確定時のスタッフ競合判定で担保される
		// （issue-392-nomination-slot-capacity.spec.ts を参照）。
		const usesNominationResponse = await page.request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ usesNominationMenuId }&date=${ dateStr }&timezone=Asia/Tokyo`
		);
		expect( usesNominationResponse.status() ).toBe( 200 );
		const usesNominationData = await usesNominationResponse.json();
		const usesNominationSlots = Array.isArray( usesNominationData )
			? usesNominationData
			: usesNominationData.slots || [];
		expect( usesNominationSlots.length ).toBeGreaterThan( 0 );
		expect( usesNominationSlots[ 0 ].capacity ).toBe( 3 );
	} );

	test( 'フロント: メニュー単位で指名を無効化したメニューはスタッフの指名欄が出ない', async ( {
		page,
	} ) => {
		setStaffEnabled( true );

		await page.goto( `/booking/?menu_id=${ noNominationMenuId }` );
		await page.waitForLoadState( 'networkidle' );

		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );

		// メニュー選択済みの状態で表示される selector（select）は menu 分の1つだけで、
		// スタッフ選択欄は出ない。
		const selectors = page.locator(
			'.vkbm-plan-summary__selectors select'
		);
		await expect
			.poll( async () => selectors.count(), { timeout: 10000 } )
			.toBe( 1 );
	} );

	test( 'フロント（回帰）: 指名を使うメニューはこの変更後も引き続きスタッフの指名欄が表示される', async ( {
		page,
	} ) => {
		setStaffEnabled( true );

		await page.goto( `/booking/?menu_id=${ usesNominationMenuId }` );
		await page.waitForLoadState( 'networkidle' );

		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );

		// メニュー選択済みの状態で、メニュー用とスタッフ用の2つの selector が表示される。
		const selectors = page.locator(
			'.vkbm-plan-summary__selectors select'
		);
		await expect
			.poll( async () => selectors.count(), { timeout: 10000 } )
			.toBe( 2 );
	} );
} );
