/**
 * 複数人予約系設定の表示制御（管理画面サービスメニュー編集画面） / issue #320
 *
 * 最大予約受付数が1以下、または複数人予約OFFのとき、複数人予約相乗りが前提の設定
 * （最少催行人数・貸し切り予約・予約者貸切指定・料金区分）を非表示にする挙動の回帰テスト。
 *
 * 検証内容（管理画面サービスメニュー編集画面の表示制御が主眼）:
 * 1. 「複数人予約」OFF → 最少催行人数・貸し切り予約・予約者貸切指定・料金区分 がすべて非表示
 *    （特に最少催行人数が非表示になること＝今回の修正の主眼）
 * 2. 「複数人予約」ON → 「最大予約受付数」入力欄が表示される
 * 3. 最大予約受付数=1 のまま → 上記4項目は非表示のまま
 * 4. 最大予約受付数を2以上に変更 → 4項目が即座に表示（保存・再読込なし）
 * 5. 2→1 に戻す → 4項目が即座に再非表示。入力値は DOM 上保持される
 *
 * 前提: Pro版有効・指名機能OFF・複数人予約機能（全体設定）ON。
 *
 * 実行環境:
 * - playwright.config の baseURL に対して実行する（WP_BASE_URL でローカル wp-env を指定）。
 *   page.goto には相対パスを渡し、絶対URLはハードコードしない。
 * - 指名OFF・複数人予約ON の設定とサービスメニューは beforeAll で用意し、
 *   afterAll で指名設定を元の状態へ復元する。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	setStaffEnabled,
	getStaffEnabled,
} from '../utils/helpers';

// 表示制御の対象となる4行の要素 ID（issue #320）。
// The four row IDs whose visibility is controlled (issue #320).
const MIN_CAPACITY_ROW = '#vkbm-min-capacity-field'; // 最少催行人数
const PRICE_TIERS_ROW = '#vkbm-price-tiers-field'; // 料金区分
const EXCLUSIVE_WHEN_BOOKED_ROW = '#vkbm-exclusive-when-booked-field'; // 貸し切り予約
const EXCLUSIVE_USER_SELECTABLE_ROW = '#vkbm-exclusive-user-selectable-field'; // 予約者による貸切指定

const DEPENDENT_ROWS = [
	MIN_CAPACITY_ROW,
	PRICE_TIERS_ROW,
	EXCLUSIVE_WHEN_BOOKED_ROW,
	EXCLUSIVE_USER_SELECTABLE_ROW,
];

// トリガー要素。
const ALLOW_MULTI = '#vkbm_service_menu_allow_multiple_guests'; // 複数人予約 チェックボックス
const MAX_CAPACITY = '#vkbm_service_menu_max_capacity'; // 最大予約受付数 入力

const MENU_TITLE = 'Multi Guest Fields Visibility Menu';
const SHOTS_DIR = '/tmp/vkbm-shots/multi-guest-fields-visibility';

let originalStaffEnabled = true;
let menuId = '';

/**
 * 検証用のサービスメニューを作成する。
 * 複数人予約ON・最大受付数3・従属設定（最少催行人数/貸切/予約者貸切指定/料金区分）に値を入れた
 * 状態で保存し、「2→1に戻したとき値が保持される」確認のために初期値を持たせる。
 * 同名メニューがあれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedMenu(): string {
	const phpCode = `
		// 既存の同名メニューを削除して決定的な状態にする。
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}

		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ MENU_TITLE }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 2 );
		update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
		update_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true );
		update_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', 1000 );
		update_post_meta( $menu_id, '_vkbm_price_tiers', array(
			array( 'label' => '一般', 'price' => 5000 ),
			array( 'label' => '子供', 'price' => 3000 ),
		) );
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Menu seeding failed: "${ result }"` );
	}
	return result;
}

// サービスメニュー編集画面を開く。
// vkbm_service_menu はブロックエディタ（Gutenberg）を使うため、
// クラシックメタボックスは画面下部の「メタボックス」パネルに折りたたまれて出る。
// ウェルカムモーダルを閉じ、メタボックス領域へスクロールして対象 input を可視化する。
//
// @param page   Playwright の Page
// @param postId 開くサービスメニューの post ID。省略時はモジュール変数 menuId を使う（#440）。
async function gotoMenuEditor( page: Page, postId: string = menuId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForLoadState( 'domcontentloaded' );

	// 「エディターへようこそ」モーダルが出たら閉じる（操作の妨げになるため）。
	//
	// モーダルは React が domcontentloaded の"後"に非同期で描画するため、
	// waitForLoadState 直後に count() で存在確認すると 0 件のまま素通りし、
	// この後の操作中に不意にモーダルが現れてクリックを遮ることがあった
	// （components-modal__screen-overlay が pointer events を intercept する）。
	// count() による即時判定ではなく、短いタイムアウト付きで「出るなら出る」のを
	// 待ってから閉じ、出なければ（タイムアウトしても）握りつぶして先に進む。
	//
	// The modal renders asynchronously via React *after* domcontentloaded, so
	// checking with count() right after waitForLoadState raced past it (0 at
	// that instant), only for the modal to appear later and intercept pointer
	// events (`components-modal__screen-overlay`). Wait briefly for it to
	// become visible if it's going to appear at all, and swallow the timeout
	// if it never does, rather than deciding based on an instantaneous count().
	const welcomeClose = page.locator(
		'.components-modal__frame .components-modal__header button[aria-label="閉じる"], .components-modal__frame .components-modal__header button[aria-label="Close"]'
	);
	try {
		await welcomeClose.first().waitFor( {
			state: 'visible',
			timeout: 3000,
		} );
		await welcomeClose.first().click();
		// モーダルのオーバーレイが完全に消えるまで（フェードアウト等）少し待つ。
		// Give the overlay a brief moment to fully disappear (fade-out, etc.).
		await page.waitForTimeout( 200 );
	} catch {
		// タイムアウト＝そもそもモーダルが出なかった。何もせず先に進む。
		// Timeout means the modal never appeared. Proceed without action.
	}

	// 最大受付数 input が DOM に存在するまで待つ（hidden でもよい＝メタボックス内）。
	await page
		.locator( MAX_CAPACITY )
		.waitFor( { state: 'attached', timeout: 15000 } );

	// ブロックエディタはクラシックメタボックス領域（.edit-post-layout__metaboxes）を
	// 画面下部に display:none で持ち、フッターの「メタボックス」トグルで開閉する。
	// トグルはリサイズ用セパレータと重なりポインタ操作が阻害されるため、
	// JS で直接クリックして展開する（aria-expanded=false のときのみ）。
	await page.evaluate( () => {
		const buttons = Array.from(
			document.querySelectorAll< HTMLButtonElement >(
				'button[aria-expanded]'
			)
		);
		const toggle = buttons.find(
			( b ) => ( b.textContent || '' ).trim() === 'メタボックス'
		);
		if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'false' ) {
			toggle.click();
		}
	} );
	await page.waitForTimeout( 400 );

	// メタボックス領域へスクロールして可視化する。
	await page.locator( MAX_CAPACITY ).scrollIntoViewIfNeeded();
	await page.waitForTimeout( 300 );
	await expect( page.locator( MAX_CAPACITY ) ).toBeVisible();
}

// 全従属行が非表示（hidden 属性付き）であることを検証する。
async function expectAllRowsHidden( page: Page ) {
	for ( const sel of DEPENDENT_ROWS ) {
		// hidden 属性で隠れているため not visible。
		await expect( page.locator( sel ) ).toBeHidden();
	}
}

// 全従属行が表示されていることを検証する。
async function expectAllRowsVisible( page: Page ) {
	for ( const sel of DEPENDENT_ROWS ) {
		await expect( page.locator( sel ) ).toBeVisible();
	}
}

test.describe( '複数人予約系設定の表示制御（管理画面） / issue #320', () => {
	test.beforeAll( () => {
		// 指名OFF・予約枠の定員ON が前提。グローバルセットアップは指名ON のため切り替える。
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		// 予約枠の定員機能（親スイッチ）を明示的に ON にする（#326 で新キー slot_capacity_enabled に改名）。
		wpEvalPhp( `
			$s = get_option( 'vkbm_provider_settings', array() );
			$s['slot_capacity_enabled'] = 1;
			unset( $s['multiple_guests_enabled'] );
			update_option( 'vkbm_provider_settings', $s );
		` );
		menuId = seedMenu();
	} );

	test.afterAll( () => {
		if ( menuId ) {
			wpCliArgs( [ 'post', 'delete', menuId, '--force' ], {
				stdio: 'ignore',
			} );
			menuId = '';
		}
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '複数人予約ON＋最大受付数2以上の初期表示で4項目が表示される（After基準）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 前提: 複数人予約 ON・最大受付数3 で保存済み → 初期表示で4項目が見える。
		await expect( page.locator( ALLOW_MULTI ) ).toBeChecked();
		await expect( page.locator( MAX_CAPACITY ) ).toHaveValue( '3' );
		await expectAllRowsVisible( page );

		// After スクリーンショット（4項目表示）。
		await page.locator( MAX_CAPACITY ).scrollIntoViewIfNeeded();
		await page.screenshot( {
			path: `${ SHOTS_DIR }/after-multi-on-capacity3.png`,
			fullPage: true,
		} );
	} );

	test( '複数人予約OFF → 4項目すべて非表示（最少催行人数含む / Before基準）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 複数人予約 OFF にする。
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 200 );

		// 4項目すべて非表示。特に最少催行人数（修正前は OFF でも表示されていた）。
		await expectAllRowsHidden( page );
		// 主眼の最少催行人数を個別にも明示確認。
		await expect( page.locator( MIN_CAPACITY_ROW ) ).toBeHidden();

		// Before スクリーンショット（複数人予約OFF＝4項目非表示）。
		await page.locator( ALLOW_MULTI ).scrollIntoViewIfNeeded();
		await page.screenshot( {
			path: `${ SHOTS_DIR }/before-multi-off.png`,
			fullPage: true,
		} );
	} );

	test( '複数人予約ON → 最大受付数欄が表示される', async ( { page } ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 一旦 OFF にして最大受付数欄の有無を見るが、最大受付数欄自体は常時表示の仕様。
		// ここでは「ON にすると最大受付数欄が見える」ことを確認する。
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 100 );
		await page.locator( ALLOW_MULTI ).check();
		await page.waitForTimeout( 100 );
		await expect( page.locator( MAX_CAPACITY ) ).toBeVisible();
	} );

	test( '最大受付数=1 のままなら4項目は非表示のまま', async ( { page } ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 複数人予約 ON のまま、最大受付数を 1 にする。
		await page.locator( ALLOW_MULTI ).check();
		await page.locator( MAX_CAPACITY ).fill( '1' );
		// input/change の双方を発火させる。
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'input' );
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'change' );
		await page.waitForTimeout( 200 );

		// 複数人予約 ON でも最大受付数=1 なら4項目は非表示。
		await expectAllRowsHidden( page );
	} );

	test( '最大受付数を2以上に変更 → 即座に4項目が表示（保存なし）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 複数人予約 ON・最大受付数を一旦 1 にして非表示にする。
		await page.locator( ALLOW_MULTI ).check();
		await page.locator( MAX_CAPACITY ).fill( '1' );
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'input' );
		await page.waitForTimeout( 200 );
		await expectAllRowsHidden( page );

		// 2 に変更 → 保存・再読込なしで即座に表示される。
		await page.locator( MAX_CAPACITY ).fill( '2' );
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'input' );
		await page.waitForTimeout( 200 );
		await expectAllRowsVisible( page );
	} );

	test( '2→1 に戻すと即座に再非表示・入力値は DOM 上保持される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 複数人予約 ON・最大受付数2で4項目を表示し、最少催行人数欄に値を入れる。
		await page.locator( ALLOW_MULTI ).check();
		await page.locator( MAX_CAPACITY ).fill( '2' );
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'input' );
		await page.waitForTimeout( 200 );
		await expectAllRowsVisible( page );

		// 最少催行人数欄に固有の値を入力（保持確認用）。
		await page.locator( '#vkbm_service_menu_min_capacity' ).fill( '2' );

		// 2 → 1 に戻す → 即座に再非表示。
		await page.locator( MAX_CAPACITY ).fill( '1' );
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'input' );
		await page.waitForTimeout( 200 );
		await expectAllRowsHidden( page );

		// 入力値は DOM 上保持されている（hidden でも value は消えない）。
		await expect(
			page.locator( '#vkbm_service_menu_min_capacity' )
		).toHaveValue( '2' );

		// 再度 2 に戻すと表示され、値も残っている。
		await page.locator( MAX_CAPACITY ).fill( '2' );
		await page.locator( MAX_CAPACITY ).dispatchEvent( 'input' );
		await page.waitForTimeout( 200 );
		await expectAllRowsVisible( page );
		await expect(
			page.locator( '#vkbm_service_menu_min_capacity' )
		).toHaveValue( '2' );
	} );

	// 完了条件2（issue #440）：複数人一括予約チェックの OFF→ON（未保存）で、
	// 料金区分欄の入力値（区分名・料金）が残ることを確認する。
	// 最少催行人数と同様、hidden で隠すだけで DOM から値を消さない実装のため、
	// OFF→ON を往復しても入力値は保持されるはずである。
	test( '複数人一括予約 OFF→ON（未保存）で料金区分の値（区分名・料金）が残る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoMenuEditor( page );

		// 前提: 料金区分は seedMenu() で「一般 5000」「子供 3000」を保存済み。
		await expectAllRowsVisible( page );
		const labelInputs = page.locator( '.vkbm-price-tier-label' );
		const priceInputs = page.locator( '.vkbm-price-tier-price' );
		await expect( labelInputs.nth( 0 ) ).toHaveValue( '一般' );
		await expect( priceInputs.nth( 0 ) ).toHaveValue( '5000' );
		await expect( labelInputs.nth( 1 ) ).toHaveValue( '子供' );
		await expect( priceInputs.nth( 1 ) ).toHaveValue( '3000' );

		// 複数人一括予約チェックを OFF にする（未保存）→ 料金区分欄は非表示になる。
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();

		// 再度 ON にする → 料金区分欄が再表示され、入力値は消えずに残っている。
		await page.locator( ALLOW_MULTI ).check();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();
		await expect( labelInputs.nth( 0 ) ).toHaveValue( '一般' );
		await expect( priceInputs.nth( 0 ) ).toHaveValue( '5000' );
		await expect( labelInputs.nth( 1 ) ).toHaveValue( '子供' );
		await expect( priceInputs.nth( 1 ) ).toHaveValue( '3000' );
	} );
} );

/**
 * issue #440：指名を使うメニューでも、編集画面の表示条件を予約画面の判定に揃える。
 *
 * 料金区分の欄は「指名を使わない」を表示条件から外し、最少催行人数と同じ
 * 「複数人一括予約ON かつ 予約枠の定員2以上」の2条件だけで出し入れする。
 *
 * #440（PR #447）：貸し切り予約・予約者による貸切指定の2欄も、
 * 指名を使うメニューでも表示・利用できるように仕様変更した（以前は「指名を使わない」も
 * 表示条件に含めており、指名を使うメニューでは常に非表示だった）。表示条件は最少催行人数・
 * 料金区分と同じ「複数人一括予約ON かつ 予約枠の定員2以上」の2条件のみ（指名の有無は問わない）。
 * 予約時の排他制御は「メニュー全体」ではなく「担当スタッフ単位」になる（PHPUnit側で検証。
 * `tests/phpunit/bookings/test-nomination-staff-scoped-exclusive-booking.php`）。
 *
 * 前提: Pro版有効・指名機能（サイト全体）ON・対象メニューは「このメニューで指名を使う」が
 * 既定（未設定＝使う）のまま。
 */
test.describe( '複数人予約系設定の表示制御（指名を使うメニュー） / issue #440', () => {
	let originalStaffEnabledForNomination = true;
	let nominationMenuId = '';

	/**
	 * 指名を使う検証用メニューを作成する（_vkbm_disable_nomination は保存しない＝指名を使う）。
	 * 同名メニューがあれば作り直す（冪等）。
	 *
	 * @param allowMultipleGuests 複数人一括予約を許可するか
	 * @return 作成したサービスメニューの post ID（数値文字列）
	 */
	function seedNominationMenuForPriceTiers(
		allowMultipleGuests: boolean
	): string {
		const title = `Nomination Price Tiers Menu ${
			allowMultipleGuests ? 'On' : 'Off'
		}`;
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
			update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', ${
				allowMultipleGuests ? 'true' : 'false'
			} );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
			update_post_meta( $menu_id, '_vkbm_price_tiers', array(
				array( 'label' => '一般', 'price' => 5000 ),
				array( 'label' => '子供', 'price' => 3000 ),
			) );
			echo $menu_id;
		`;
		const result = wpEvalPhp( phpCode ).trim();
		if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
			throw new Error( `Nomination menu seeding failed: "${ result }"` );
		}
		return result;
	}

	test.afterEach( () => {
		if ( nominationMenuId ) {
			wpCliArgs( [ 'post', 'delete', nominationMenuId, '--force' ], {
				stdio: 'ignore',
			} );
			nominationMenuId = '';
		}
	} );

	test.beforeAll( () => {
		// 指名機能（サイト全体）ON・予約枠の定員機能ONが前提。
		originalStaffEnabledForNomination = getStaffEnabled();
		setStaffEnabled( true );
		wpEvalPhp( `
			$s = get_option( 'vkbm_provider_settings', array() );
			$s['slot_capacity_enabled'] = 1;
			unset( $s['multiple_guests_enabled'] );
			update_option( 'vkbm_provider_settings', $s );
		` );
	} );

	test.afterAll( () => {
		setStaffEnabled( originalStaffEnabledForNomination );
	} );

	// 完了条件1（issue #440）：指名を使うメニューでも、複数人一括予約チェックの有無で
	// 料金区分の入力欄の表示が切り替わる。
	test( '指名を使うメニューでも複数人一括予約ON＋定員2以上なら料金区分欄が表示される', async ( {
		page,
	} ) => {
		nominationMenuId = seedNominationMenuForPriceTiers( true );
		await loginAsAdmin( page );
		await gotoMenuEditor( page, nominationMenuId );

		// 「このメニューで指名を使う」チェックは既定でONのまま（メタ未設定）。
		await expect(
			page.locator( '#vkbm_service_menu_use_nomination' )
		).toBeChecked();

		// 修正前は「指名を使わない」が条件に含まれていたため、この状態でも
		// 料金区分欄は非表示のままだった（このテストは修正前は FAIL する）。
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();

		// 複数人一括予約チェックを OFF にすると非表示になる。
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();

		// 再度 ON にすると表示に戻る。
		await page.locator( ALLOW_MULTI ).check();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();
	} );

	// 完了条件4（issue #440）：指名を使うメニューでも、複数人一括予約が無効な場合は
	// 編集画面に料金区分の入力欄が表示されない。
	test( '指名を使うメニューでも複数人一括予約が無効なら料金区分欄が表示されない', async ( {
		page,
	} ) => {
		nominationMenuId = seedNominationMenuForPriceTiers( false );
		await loginAsAdmin( page );
		await gotoMenuEditor( page, nominationMenuId );

		await expect(
			page.locator( '#vkbm_service_menu_use_nomination' )
		).toBeChecked();
		await expect( page.locator( ALLOW_MULTI ) ).not.toBeChecked();

		// 料金区分は保存済み（_vkbm_price_tiers）だが、複数人一括予約が無効なため非表示。
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();
	} );

	// 完了条件3（issue #440）の e2e 側確認：編集画面から複数人一括予約を OFF にして保存する
	// 実際の操作フローを確認する。保存すると料金区分メタは save_post() の既存挙動
	// （#330 と同じ「明示的にOFFにした＝従属設定を破棄する意思表示」）で削除され、
	// 保存済みの区分値に関係なく基本料金だけで計算される状態になる。
	// 計算式そのもの（基本料金×1名になること）は PHPUnit 側
	// （test-max-capacity-disables-multi-guest-settings.php）で確認する。
	test( '編集画面で複数人一括予約を OFF にして保存すると、料金区分メタが破棄され再読込後も非表示のまま', async ( {
		page,
	} ) => {
		nominationMenuId = seedNominationMenuForPriceTiers( true );
		await loginAsAdmin( page );
		await gotoMenuEditor( page, nominationMenuId );

		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeVisible();
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 200 );
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();

		// ブロックエディタの更新ボタン（クラスはロケールに関わらず安定）をクリックすると、
		// (1) REST 保存リクエスト（api-fetch は更新でも POST /wp/v2/vkbm_service_menu/<id> を送る）と
		// (2) クラシックメタボックス（料金区分欄を含む）の保存リクエスト（POST .../post.php?…
		// meta-box-loader=1…）が順に飛ぶ。料金区分メタを実際に削除するのは (2) の save_post() 側の
		// ため、(1) だけを待つと (2) が終わる前にメタを読みに行ってしまう競合があった。
		// 固定の待機時間に頼らず、両方の応答完了を待つ。
		const saveResponse = page.waitForResponse(
			( res ) =>
				new RegExp(
					`/wp/v2/vkbm_service_menu/${ nominationMenuId }`
				).test( res.url() ) && res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		const metaBoxSaveResponse = page.waitForResponse(
			( res ) =>
				res.url().includes( 'meta-box-loader=1' ) &&
				res.request().method() === 'POST',
			{ timeout: 20000 }
		);
		await page.locator( '.editor-post-publish-button__button' ).click();
		await saveResponse;
		await metaBoxSaveResponse;

		// 保存済みの料金区分メタは、複数人一括予約OFFでの保存によって削除される。
		const savedTiers = wpEvalPhp( `
			$v = get_post_meta( ${ nominationMenuId }, '_vkbm_price_tiers', true );
			echo empty( $v ) ? 'empty' : 'not-empty';
		` ).trim();
		expect( savedTiers ).toBe( 'empty' );

		// 再読込しても複数人一括予約OFF・料金区分欄は非表示のまま。
		await gotoMenuEditor( page, nominationMenuId );
		await expect( page.locator( ALLOW_MULTI ) ).not.toBeChecked();
		await expect( page.locator( PRICE_TIERS_ROW ) ).toBeHidden();
	} );

	// #440：指名を使うメニューでも、複数人一括予約ON＋定員2以上なら
	// 貸し切り予約・予約者による貸切指定の2欄が表示される（修正前は指名を使うメニューでは
	// 常に非表示だったため、このテストは修正前は FAIL する）。
	test( '指名を使うメニューでも複数人一括予約ON＋定員2以上なら貸し切り予約・予約者による貸切指定の欄が表示される', async ( {
		page,
	} ) => {
		nominationMenuId = seedNominationMenuForPriceTiers( true );
		await loginAsAdmin( page );
		await gotoMenuEditor( page, nominationMenuId );

		await expect(
			page.locator( '#vkbm_service_menu_use_nomination' )
		).toBeChecked();

		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeVisible();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeVisible();

		// 複数人一括予約チェックを OFF にすると非表示になる。
		await page.locator( ALLOW_MULTI ).uncheck();
		await page.waitForTimeout( 200 );
		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeHidden();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeHidden();

		// 再度 ON にすると表示に戻る。
		await page.locator( ALLOW_MULTI ).check();
		await page.waitForTimeout( 200 );
		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeVisible();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeVisible();
	} );

	// #440：指名を使うメニューでも、複数人一括予約が無効なら貸し切り予約・
	// 予約者による貸切指定の欄は表示されない（他の3欄と同じ条件で揃っていることの確認）。
	test( '指名を使うメニューでも複数人一括予約が無効なら貸し切り予約・予約者による貸切指定の欄が表示されない', async ( {
		page,
	} ) => {
		nominationMenuId = seedNominationMenuForPriceTiers( false );
		await loginAsAdmin( page );
		await gotoMenuEditor( page, nominationMenuId );

		await expect(
			page.locator( '#vkbm_service_menu_use_nomination' )
		).toBeChecked();
		await expect( page.locator( ALLOW_MULTI ) ).not.toBeChecked();

		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeHidden();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeHidden();
	} );

	// #440：保存せず「このメニューで指名を使う」チェックを切り替えても、貸し切り予約・
	// 予約者による貸切指定の表示は変わらない（指名の有無を表示条件に含めなくなったため。
	// 修正前は、指名OFFへ切り替えた瞬間に貸切2欄が現れてしまっていた＝表示条件が指名依存だった名残）。
	test( '指名を使うメニューで保存せず「このメニューで指名を使う」を切り替えても貸し切り予約・予約者による貸切指定の表示は変わらない', async ( {
		page,
	} ) => {
		nominationMenuId = seedNominationMenuForPriceTiers( true );
		await loginAsAdmin( page );
		await gotoMenuEditor( page, nominationMenuId );

		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeVisible();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeVisible();

		// 「このメニューで指名を使う」を保存せずに OFF へ切り替える。
		await page.locator( '#vkbm_service_menu_use_nomination' ).uncheck();
		await page.waitForTimeout( 200 );
		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeVisible();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeVisible();

		// 再度 ON へ戻しても表示は変わらない。
		await page.locator( '#vkbm_service_menu_use_nomination' ).check();
		await page.waitForTimeout( 200 );
		await expect( page.locator( EXCLUSIVE_WHEN_BOOKED_ROW ) ).toBeVisible();
		await expect(
			page.locator( EXCLUSIVE_USER_SELECTABLE_ROW )
		).toBeVisible();
	} );
} );
