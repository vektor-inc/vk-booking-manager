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
async function gotoMenuEditor( page: Page ) {
	await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
	await page.waitForLoadState( 'domcontentloaded' );

	// 「エディターへようこそ」モーダルが出たら閉じる（操作の妨げになるため）。
	const welcomeClose = page.locator(
		'.components-modal__frame .components-modal__header button[aria-label="閉じる"], .components-modal__frame .components-modal__header button[aria-label="Close"]'
	);
	if ( await welcomeClose.count() ) {
		await welcomeClose
			.first()
			.click()
			.catch( () => {} );
		await page.waitForTimeout( 200 );
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
} );
