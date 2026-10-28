/**
 * 宿泊の「1名1泊あたり」プランの人数の下限の表示と受付（#935）
 *
 * 検証内容:
 * 1. 宿泊の「1名1泊あたり」プランの編集画面で、人数の下限の欄が「最低申し込み人数」と表示され、
 *    説明文の1行目が「1件の予約は、1組が1室を使う予約です。」になる（「最少催行人数」にならない）。
 *    予約枠の定員の欄の説明文も「1件の予約で、一度に申し込める最大人数です。」になる。
 * 2. 料金の単位を「1室1泊あたり」に切り替えると人数の下限の欄が隠れ、「1名1泊あたり」に戻すと同じ文言で再表示される。
 * 3. 人数の下限に 2 を入れて保存すると、1 名の予約（下書き保存）は断られ、2 名の予約は受け付けられる。
 *
 * テストデータと後片付け:
 * - 客室・プランは固有の接頭辞（E2E935）を付けて作り、afterAll で接頭辞を手がかりに削除する。
 * - BM 設定（vkbm_provider_settings）は beforeAll で控え、afterAll の finally で必ず元へ戻す
 *   （業種プリセットが宿泊のまま次の spec へ残ると、他の spec が壊れるため）。
 */
import { test, expect } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
} from '../utils/helpers';

// テストデータの識別用接頭辞（他 spec のデータと区別する）。
const PREFIX = 'E2E935';
const OPTION_NAME = 'vkbm_provider_settings';
const MAX_CAPACITY = 3;

let originalSettingsJson: string | null = null;
let roomId = '';
let menuId = '';
// チェックイン日・チェックアウト日（Asia/Tokyo。Y-m-d）。
let checkInIso = '';
let checkOutIso = '';

/**
 * PHP を実行し、出力が正の整数（post ID）であることを確かめて返す。
 *
 * @param php   実行する PHP コード（最後に ID を echo すること）
 * @param label エラーメッセージ用の名前
 * @return 作成した post ID（数値文字列）
 */
function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * このspecで作ったデータを接頭辞を手がかりに削除する（予約 → シフト → プラン → 客室の順）。
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
 * BM 設定を beforeAll で控えた値へ戻す。控えが無ければ（元から option が無かった）option を削除して戻す。
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
		// 元から無い（削除済み）なら何もしない。
	}
}

/**
 * ブロックエディターの「ようこそ」案内を出さず、メタボックス欄を開いた状態にする。
 * 案内やメタボックス欄の折りたたみが、下限の欄などの操作を妨げるため。
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

test.describe
	.serial( '宿泊の「1名1泊あたり」プランの人数の下限（#935）', () => {
	test.beforeAll( () => {
		// BM 設定を控える（afterAll で必ず戻す）。
		try {
			originalSettingsJson = wpCliArgs(
				[ 'option', 'get', OPTION_NAME, '--format=json' ],
				{ stdio: 'pipe' }
			);
		} catch {
			originalSettingsJson = null;
		}

		// 宿泊のサイトで、指名（客室の指定）と予約枠の定員の機能を有効にする（他の設定は残す）。
		wpEvalPhp( `
			$settings = get_option( '${ OPTION_NAME }', array() );
			$settings = is_array( $settings ) ? $settings : array();
			$settings['industry_preset']       = 'lodging';
			$settings['staff_enabled']         = true;
			$settings['slot_capacity_enabled'] = true;
			update_option( '${ OPTION_NAME }', $settings );
			echo 'ok';
		` );

		// 今日から 3 日後をチェックイン日、その翌日をチェックアウト日にする（1 泊）。
		const dates = wpEvalPhp( `
			$tz  = new DateTimeZone( 'Asia/Tokyo' );
			$day = ( new DateTimeImmutable( 'today', $tz ) )->modify( '+3 days' );
			echo $day->format( 'Y-m-d' ) . ',' . $day->modify( '+1 day' )->format( 'Y-m-d' );
		` )
			.trim()
			.split( ',' );
		[ checkInIso, checkOutIso ] = dates;

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
		// 当月＋翌月のシフト（泊の販売日。月境界でも残るよう両方）。
		for ( const { year, month } of getCurrentAndNextTokyoMonths() ) {
			createShiftForMonth( roomId, year, month );
		}

		// 人数の下限（_vkbm_min_capacity）は未設定のまま作り、画面から 2 を入れて保存する。
		menuId = insertAndGetId(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'post_title'  => '${ PREFIX } Per Guest Plan',
			) );
			if ( is_wp_error( $id ) || ! $id ) { echo 'Error'; return; }
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( roomId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', 10000 );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			update_post_meta( $id, '_vkbm_night_price_unit', 'per_guest' );
			update_post_meta( $id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $id, '_vkbm_max_capacity', ${ MAX_CAPACITY } );
			echo $id;
		`,
			'plan'
		);
	} );

	test.afterAll( () => {
		// 片付けが例外で止まっても、BM 設定は必ず元へ戻す（次の spec への状態漏れ防止）。
		try {
			cleanupSeededData();
		} finally {
			restoreProviderSettings();
		}
	} );

	test( '編集画面で「最低申し込み人数」と新しい説明文が表示され、1室1泊あたりでは隠れる', async ( {
		page,
	} ) => {
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );

		const minRow = page.locator( '#vkbm-min-capacity-field' );
		const minLabel = page.locator( '#vkbm-min-capacity-label' );
		const minDescription = page.locator( '#vkbm-min-capacity-description' );
		const maxDescription = page.locator( '#vkbm-max-capacity-description' );
		await minRow.waitFor( { state: 'attached', timeout: 15000 } );

		// 1名1泊あたり: 人数の下限の欄は「最低申し込み人数」。「最少催行人数」にはならない。
		await expect( minRow ).toBeVisible();
		await expect( minLabel ).toHaveText( '最低申し込み人数' );
		await expect( minDescription ).toContainText(
			'1件の予約は、1組が1室を使う予約です。'
		);
		await expect( minDescription ).toContainText(
			'申込人数がこの人数に満たない場合、その予約は受け付けられません'
		);
		await expect( minDescription ).not.toContainText( '催行' );
		await expect( minDescription ).not.toContainText( '時間枠' );

		// 予約枠の定員の欄の説明文も、時間枠を共有する前提の文言にならない。
		await expect( maxDescription ).toContainText(
			'1件の予約で、一度に申し込める最大人数です。'
		);
		await expect( maxDescription ).not.toContainText( '時間枠' );

		// 1室1泊あたりへ切り替えると人数の下限の欄は隠れ、1名1泊あたりへ戻すと同じ文言で再表示される。
		await page
			.locator(
				'input[name="vkbm_service_menu[night_price_unit]"][value="per_room"]'
			)
			.check();
		await expect( minRow ).toBeHidden();
		await page
			.locator(
				'input[name="vkbm_service_menu[night_price_unit]"][value="per_guest"]'
			)
			.check();
		await expect( minRow ).toBeVisible();
		await expect( minLabel ).toHaveText( '最低申し込み人数' );
		await expect( minDescription ).toContainText(
			'1件の予約は、1組が1室を使う予約です。'
		);
		await expect( maxDescription ).toContainText(
			'1件の予約で、一度に申し込める最大人数です。'
		);

		// 予約の単位を時間枠へ戻すと、1組1室の説明文は消え、予約枠の定員の欄は指名向けの文言に戻る。
		await page
			.locator(
				'input[name="vkbm_service_menu[booking_unit]"][value="slot"]'
			)
			.check();
		await expect( minDescription ).not.toContainText(
			'1件の予約は、1組が1室を使う予約です。'
		);
		await expect( maxDescription ).toContainText(
			/を(指定|指名)した1件の予約で/
		);
	} );

	test( '下限に 2 を入れて保存すると、1 名の予約は断られ、2 名の予約は受け付けられる', async ( {
		page,
		request,
	} ) => {
		/**
		 * 宿泊の下書き保存（POST /vkbm/v1/drafts）を、人数を指定して呼ぶ。
		 *
		 * @param guests 申込人数
		 * @return ステータスコードとレスポンスボディ
		 */
		const postDraft = async ( guests: number ) => {
			const response = await request.post( '/wp-json/vkbm/v1/drafts', {
				data: {
					menu_id: Number( menuId ),
					resource_id: Number( roomId ),
					check_in_date: checkInIso,
					check_out_date: checkOutIso,
					guests,
					meta: { timezone: 'Asia/Tokyo' },
				},
			} );
			const body = ( await response.json() ) as Record< string, unknown >;
			return { status: response.status(), body };
		};

		// 下限を入れる前は、1 名でも受け付けられる（比較の基準）。
		const before = await postDraft( 1 );
		expect( before.status, JSON.stringify( before.body ) ).toBe( 200 );

		// 画面から下限に 2 を入れて保存する。
		prepareBlockEditorPreferences();
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
		const minInput = page.locator( '#vkbm_service_menu_min_capacity' );
		await minInput.waitFor( { state: 'visible', timeout: 15000 } );
		await minInput.fill( '2' );
		// ブロックエディターの保存ボタンで、メタボックスの内容もあわせて保存される。
		await page.locator( '.editor-post-publish-button' ).click();
		await expect
			.poll(
				() =>
					wpCliArgs(
						[ 'post', 'meta', 'get', menuId, '_vkbm_min_capacity' ],
						{ stdio: 'pipe' }
					),
				{ timeout: 20000 }
			)
			.toBe( '2' );

		// 1 名は断られ、2 名は受け付けられる。
		const oneGuest = await postDraft( 1 );
		expect( oneGuest.status ).toBe( 400 );
		expect( oneGuest.body.code ).toBe( 'nomination_min_guests' );

		const twoGuests = await postDraft( 2 );
		expect( twoGuests.status, JSON.stringify( twoGuests.body ) ).toBe(
			200
		);
	} );
} );
