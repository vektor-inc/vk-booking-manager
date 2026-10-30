/**
 * issue #831: 1室1泊あたりの泊のプランでは、複数人一括予約の行を出さず、定員を「1室の最大人数」として扱う。
 *
 * 検証内容（宿泊のサイト・Pro 版のみ）:
 *   1. 編集画面（1室1泊あたり）: 「複数人一括予約」の行は hidden、定員のラベルは「1室の最大人数」。
 *   2. 「1室の最大人数」に 4 を入れて保存すると、複数人一括予約が未保存（OFF）のままでも、
 *      予約画面の人数欄が上限 4 で出る。
 *   3. 料金の単位を「1名1泊あたり」へ切り替えると、複数人一括予約の行が出て、ラベルは「予約枠の定員」に戻る。
 *
 * 注: 文言は日本語で確かめる（e2e のサイトは日本語）。
 * テストデータは接頭辞（E2E831）を付けて作り、afterAll で消す。BM 設定は beforeAll で控えて必ず戻す。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
	getTokyoDateParts,
	loginAsAdmin,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E831';
const OPTION_NAME = 'vkbm_provider_settings';

let originalSettingsJson: string | null = null;
let roomId = '';
let nightMenuId = '';

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP（最後に ID を echo する）
 * @param label エラーメッセージ用の名前
 * @return post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * このスペックが作ったデータを接頭辞で探して消す（シフト → プラン → 客室の順）。
 */
function cleanupSeededData(): void {
	wpEvalPhp( `
		$prefix = '${ PREFIX }';
		$find = static function ( $post_type ) use ( $prefix ) {
			$ids = array();
			foreach ( get_posts( array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				's'           => $prefix,
			) ) as $p ) {
				if ( 0 === strpos( $p->post_title, $prefix ) ) { $ids[] = $p->ID; }
			}
			return $ids;
		};
		$rooms = $find( 'vkbm_resource' );
		$menus = $find( 'vkbm_service_menu' );
		if ( $rooms ) {
			foreach ( get_posts( array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_vkbm_shift_resource_id', 'value' => $rooms, 'compare' => 'IN' ) ),
			) ) as $sid ) { wp_delete_post( $sid, true ); }
		}
		foreach ( $menus as $mid ) { wp_delete_post( $mid, true ); }
		foreach ( $rooms as $rid ) { wp_delete_post( $rid, true ); }
		echo 'ok';
	` );
}

/**
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ option ごと消す。
 */
function restoreProviderSettings(): void {
	if ( originalSettingsJson ) {
		wpCliArgs(
			[
				'option',
				'update',
				OPTION_NAME,
				originalSettingsJson,
				'--format=json',
			],
			{ stdio: 'pipe' }
		);
		return;
	}
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ], { stdio: 'pipe' } );
	} catch {
		// 元から無いなら何もしない。
	}
}

/**
 * ブロックエディターの「ようこそ」案内を出さず、メタボックス欄を開いた状態にする。
 * 案内やメタボックス欄の折りたたみが、定員欄などの操作を妨げるため。
 */
function prepareBlockEditorPreferences(): void {
	const result = wpEvalPhp( `
		global $wpdb;
		$user = get_user_by( 'login', 'admin' );
		update_user_meta(
			$user->ID,
			$wpdb->get_blog_prefix() . 'persisted_preferences',
			array(
				'core/edit-post' => array(
					'welcomeGuide'        => false,
					'metaBoxesMainIsOpen' => true,
				),
				'core'           => array(
					'enableChoosePatternModal' => false,
				),
			)
		);
		echo 'OK';
	` );
	expect( result ).toContain( 'OK' );
}

/**
 * 今日（Asia/Tokyo）から offset 日後の年・月・日を返す。
 *
 * @param offset 日数
 * @return 年・月・日（数値）
 */
function tokyoDateAfter( offset: number ) {
	const date = new Date( Date.now() + offset * 24 * 60 * 60 * 1000 );
	const { year, month, day } = getTokyoDateParts( date );
	return {
		year: Number( year ),
		month: Number( month ),
		day: Number( day ),
	};
}

/**
 * 宿泊のカレンダーで、指定した日を押す（表示月が違えば翌月へ送る）。
 *
 * @param page       Playwright の Page
 * @param date       選ぶ日（Asia/Tokyo の年・月・日）
 * @param date.year  年
 * @param date.month 月
 * @param date.day   日
 */
async function clickStayDay(
	page: Page,
	date: { year: number; month: number; day: number }
) {
	const calendar = page.locator( '.vkbm-stay-calendar' );
	await calendar.waitFor( { state: 'visible', timeout: 15000 } );
	const monthPattern = new RegExp( `${ date.year }\\D+0?${ date.month }\\D` );
	for ( let i = 0; i < 3; i++ ) {
		const heading =
			( await calendar
				.locator( '.vkbm-calendar__current' )
				.innerText() ) + ' ';
		if ( monthPattern.test( heading ) ) {
			break;
		}
		await calendar.locator( '.vkbm-calendar__nav' ).last().click();
	}
	await calendar
		.locator( 'button.vkbm-calendar__day' )
		.filter( {
			has: page.locator( '.vkbm-calendar__day-label', {
				hasText: new RegExp( `^${ date.day }$` ),
			} ),
		} )
		.click();
}

/**
 * 予約ページで泊のプランを開き、2 泊の日程を選んで客室の候補が出るまで進める。
 *
 * @param page Playwright の Page
 */
async function openAndPickDates( page: Page ) {
	await page.goto( `/booking/?menu_id=${ nightMenuId }` );
	await page.waitForLoadState( 'networkidle' );
	await expect( page.locator( '.vkbm-lodging' ) ).toBeVisible( {
		timeout: 15000,
	} );
	await clickStayDay( page, tokyoDateAfter( 2 ) );
	await clickStayDay( page, tokyoDateAfter( 4 ) );
	await expect( page.locator( '.vkbm-lodging__room' ) ).toHaveCount( 1, {
		timeout: 15000,
	} );
}

test.describe.serial( 'issue #831: 1室1泊あたりの定員と複数人一括予約', () => {
	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		// 客室1室と、1室1泊あたり・複数人一括予約が未保存・定員が未設定の泊のプランを作る。
		// @step 客室「E2E831 Room」を作成し、今月と来月のシフトを登録しておく
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Room',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			echo $id;
		`,
			'room'
		);
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomId, year, month );
		}
		// @step 予約の単位「泊」・料金の単位「1室1泊あたり」・基本料金 10,000円で、複数人一括予約と定員は未設定のプラン「E2E831 Night Plan」を作成しておく
		nightMenuId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Night Plan',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( roomId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', 10000 );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			update_post_meta( $id, '_vkbm_night_price_unit', 'per_room' );
			echo $id;
		`,
			'night menu'
		);
		// 宿泊のサイトにする（予約枠の定員機能も有効になる）。
		// @step 管理画面で業種を宿泊・スタッフの呼び名を「客室」にし、予約締切と予約可能日数の制限を外しておく
		await configureProviderSettings( {
			industry_preset: 'lodging',
			resource_label_singular: '客室',
			resource_label_plural: '客室',
			// 既存の設定値（予約締切・予約可能日数）に左右されず、
			// 2 日後・4 日後のチェックイン／アウトを常に受付内にする。
			provider_reservation_deadline_hours: 0,
			provider_max_advance_booking_days: 0,
		} );
	} );

	test.afterAll( () => {
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test( '1室1泊あたりでは複数人一括予約の行を隠し、定員を「1室の最大人数」として保存できる', async ( {
		page,
	} ) => {
		// @step 管理者でログインし、プランの編集画面を開く
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ nightMenuId }&action=edit`
		);

		const multiGuestsRow = page.locator(
			'#vkbm-allow-multiple-guests-field'
		);
		const exclusiveRow = page.locator(
			'#vkbm-exclusive-when-booked-field'
		);
		const capacityLabel = page.locator( '#vkbm-max-capacity-label' );
		const capacityInput = page.locator( '#vkbm_service_menu_max_capacity' );
		const resourceSelectionRow = page.locator(
			'#vkbm-use-nomination-field'
		);
		await capacityInput.waitFor( { state: 'attached', timeout: 15000 } );

		// 1室1泊あたり: 複数人一括予約の行は出さず、ラベルは「1室の最大人数」。
		// @check 「複数人一括予約」の行と客室指定の行が隠れ、定員の欄の名前が「1室の最大人数」で、説明文に「予約画面の人数欄では、この人数まで選べます。」が出る
		await expect( multiGuestsRow ).toBeHidden();
		await expect( resourceSelectionRow ).toBeHidden();
		await expect( capacityLabel ).toHaveText( '1室の最大人数' );
		await expect(
			page.locator( '#vkbm-max-capacity-description' )
		).toContainText( '予約画面の人数欄では、この人数まで選べます。' );

		// 4 を入れても、複数人一括予約に依存する貸し切り予約の行は出ない。
		// @step 「1室の最大人数」に 4 を入れる
		await capacityInput.fill( '4' );
		// @check 貸し切り予約の行は出ない
		await expect( exclusiveRow ).toBeHidden();

		// 保存する。複数人一括予約のチェックは触らない（隠れたまま OFF で保存される）。
		// ブロックエディターの保存ボタンで、メタボックスの内容もあわせて保存される。
		// @step 保存する
		await page.locator( '.editor-post-publish-button' ).click();
		// @check 1室の最大人数が 4 で保存され、複数人一括予約と客室指定の設定は空のまま保存される
		await expect
			.poll(
				() =>
					wpCliArgs(
						[
							'post',
							'meta',
							'get',
							nightMenuId,
							'_vkbm_max_capacity',
						],
						{ stdio: 'pipe' }
					),
				{ timeout: 20000 }
			)
			.toBe( '4' );
		expect(
			wpCliArgs(
				[
					'post',
					'meta',
					'get',
					nightMenuId,
					'_vkbm_allow_multiple_guests',
				],
				{ stdio: 'pipe' }
			)
		).toBe( '' );
		// 指定欄が hidden のまま保存されても、メニュー単位の有効値は OFF に落ちない。
		expect(
			wpCliArgs(
				[
					'post',
					'meta',
					'get',
					nightMenuId,
					'_vkbm_disable_nomination',
				],
				{ stdio: 'pipe' }
			)
		).toBe( '' );

		// 時間枠へ戻すと「客室指定」が表示され、チェック切り替え後も宿泊向け説明文を維持する。
		// @step 予約の単位を時間枠に切り替える
		await page.evaluate( () => {
			const radio = document.querySelector< HTMLInputElement >(
				'input[name="vkbm_service_menu[booking_unit]"][value="slot"]'
			);
			if ( ! radio ) {
				throw new Error( '時間枠のラジオボタンが見つかりません。' );
			}
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		// @check 「客室指定」の行が表示される
		await expect( resourceSelectionRow ).toBeVisible();
		await expect( resourceSelectionRow.locator( 'th' ) ).toHaveText(
			'客室指定'
		);
		// @step 「客室指定」のチェックを外す
		await resourceSelectionRow
			.locator( 'input[type="checkbox"]' )
			.uncheck();
		// @check 客室が自動で割り当てられる旨の説明が出て、「スタッフ指名」の語は出ず、人数の下限の欄の名前は「最少催行人数」になる
		await expect( resourceSelectionRow ).toContainText(
			'このサービスメニューでは客室が自動で割り当てられ'
		);
		await expect(
			page.getByText( 'スタッフ指名', { exact: true } )
		).toHaveCount( 0 );
		await expect( page.locator( '#vkbm-min-capacity-label' ) ).toHaveText(
			'最少催行人数'
		);

		// 泊単位へ戻すと指定欄は隠れ、隠れたチェックが OFF でも定員文言は指定なし側に固定される。
		// @step 予約の単位を「泊」に戻す
		await page.evaluate( () => {
			const radio = document.querySelector< HTMLInputElement >(
				'input[name="vkbm_service_menu[booking_unit]"][value="night"]'
			);
			if ( ! radio ) {
				throw new Error( '泊単位のラジオボタンが見つかりません。' );
			}
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		// @check 「客室指定」の行が隠れる
		await expect( resourceSelectionRow ).toBeHidden();

		// 料金の単位を 1名1泊あたりへ切り替えると、行とラベルが元に戻る。
		// @step 料金の単位を「1名1泊あたり」に切り替える
		await page.evaluate( () => {
			const radio = document.querySelector< HTMLInputElement >(
				'input[name="vkbm_service_menu[night_price_unit]"][value="per_guest"]'
			);
			if ( ! radio ) {
				throw new Error(
					'1名1泊あたりのラジオボタンが見つかりません。'
				);
			}
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		// @check 「複数人一括予約」の行が表示され、定員の欄の名前が「予約枠の定員」に戻り、説明文に「客室が自動で割り当てられる場合」が出る
		await expect( multiGuestsRow ).toBeVisible();
		await expect( capacityLabel ).toHaveText( '予約枠の定員' );
		await expect(
			page.locator( '#vkbm-max-capacity-description' )
		).toContainText( '客室が自動で割り当てられる場合' );
	} );

	test( '複数人一括予約が OFF のままでも、予約画面の人数欄が「1室の最大人数」を上限に出る', async ( {
		page,
	} ) => {
		// @step プランの予約ページを開き、2 日後をチェックイン日、4 日後をチェックアウト日に選ぶ
		await openAndPickDates( page );
		// @step 表示された客室を選ぶ
		await page.locator( '.vkbm-lodging__room' ).first().click();

		// @check 人数の入力欄が表示され、上限が 4 になっている
		const guestsInput = page.locator(
			'input.vkbm-plan-summary__guests-select'
		);
		await expect( guestsInput ).toBeVisible( { timeout: 15000 } );
		await expect( guestsInput ).toHaveAttribute( 'max', '4' );
	} );
} );
