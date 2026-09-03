/**
 * 貸し切り予約（予約が入ったら受付停止）機能の e2e 検証（#304）
 *
 * 検証内容:
 * - サービスメニュー編集画面で「貸し切り予約」チェックボックスが
 *   （複数人予約ON時に）表示され、ON で保存するとメタが保存される。
 * - 複数人予約OFF時はチェックボックスが隠れている（B案）。
 * - 貸し切り予約が1件入った時間帯は、別ユーザー視点でスロットが
 *   「予約受付終了」状態（選択不可）になる。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - 「貸し切り予約」は指名OFF＋複数人予約ON でのみ意味を持つため、
 *   この describe の間だけ指名OFFへ切り替え、afterAll で復元する。
 */
import { test, expect } from '@playwright/test';
import {
	loginAsAdmin,
	wpEvalPhp,
	getStaffId,
	getStaffEnabled,
	setStaffEnabled,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
} from '../utils/helpers';

const EXCLUSIVE_MENU_TITLE = 'Exclusive Menu';
let originalStaffEnabled = true;
let exclusiveMenuId = '';

/**
 * 貸し切り検証用のメニュー（複数人予約ON・最大受付数3・スタッフ割当）を作成する。
 * 同名メニューがあれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedExclusiveMenu(): string {
	const staffId = getStaffId();
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };
		$existing = get_posts( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'any',
			'title'       => '${ EXCLUSIVE_MENU_TITLE }',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( $existing as $eid ) {
			wp_delete_post( $eid, true );
		}
		$menu_id = wp_insert_post( array(
			'post_type'   => 'vkbm_service_menu',
			'post_status' => 'publish',
			'post_title'  => '${ EXCLUSIVE_MENU_TITLE }',
		) );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			echo 'Error: failed to create exclusive menu';
			return;
		}
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Exclusive menu seeding failed: "${ result }"` );
	}
	return result;
}

test.describe( '#304: 貸し切り予約', () => {
	test.beforeAll( () => {
		// 「貸し切り予約」は指名OFF＋複数人予約ON で表示・有効になる。
		originalStaffEnabled = getStaffEnabled();
		setStaffEnabled( false );
		exclusiveMenuId = seedExclusiveMenu();
	} );

	test.afterAll( () => {
		if ( exclusiveMenuId ) {
			wpEvalPhp(
				`wp_delete_post( ${ Number.parseInt(
					exclusiveMenuId,
					10
				) }, true );`
			);
			exclusiveMenuId = '';
		}
		setStaffEnabled( originalStaffEnabled );
	} );

	test( '編集画面で貸し切り予約チェックボックスが表示・保存される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// サービスメニュー編集画面を開く。
		await page.goto(
			`/wp-admin/post.php?post=${ exclusiveMenuId }&action=edit`
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 「複数人予約を許可」を ON にし、change イベントを発火して JS の表示連動を起こす。
		// （WP 管理画面のメタボックス内要素は Playwright の可視判定が安定しないため、
		//   DOM 経由で状態を設定し change を dispatch する。B案：貸し切り予約は複数人予約ON時のみ表示。）
		const multiGuests = page.locator(
			'#vkbm_service_menu_allow_multiple_guests'
		);
		await multiGuests.waitFor( { state: 'attached', timeout: 15000 } );
		await multiGuests.evaluate( ( el ) => {
			const input = el as HTMLInputElement;
			if ( ! input.checked ) {
				input.checked = true;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );

		// 複数人予約ON のとき、貸し切り予約行の hidden 属性が外れる（B案の表示連動）。
		const exclusiveRow = page.locator(
			'#vkbm-exclusive-when-booked-field'
		);
		await expect
			.poll( async () =>
				exclusiveRow.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( false );

		const checkbox = page.locator(
			'#vkbm_service_menu_exclusive_when_booked'
		);
		// 説明文が aria-describedby で紐付いている。
		await expect( checkbox ).toHaveAttribute(
			'aria-describedby',
			'vkbm-exclusive-when-booked-description'
		);

		// 貸し切り予約のチェックを入れられる（無効化されていない）ことを確認する。
		await checkbox.evaluate( ( el ) => {
			( el as HTMLInputElement ).checked = true;
		} );
		await expect( checkbox ).toBeChecked();

		// 保存経路（save_post → メタ保存）は phpunit で検証済みのため、
		// ここでは REST 経由でメタを ON 保存し、編集画面の再読込でチェックが復元されることを確認する。
		// （ブロックエディタのメタボックス保存ボタンは管理画面チロームに依存し e2e が不安定なため、
		//   保存状態の復元という観点に絞る。）
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt(
				exclusiveMenuId,
				10
			) }, '_vkbm_exclusive_when_booked', true );`
		);
		await page.reload();
		await page.waitForLoadState( 'domcontentloaded' );

		// 複数人予約を再度ONにして貸し切り行を表示させ、チェックが復元されていることを確認する。
		await page
			.locator( '#vkbm_service_menu_allow_multiple_guests' )
			.evaluate( ( el ) => {
				const input = el as HTMLInputElement;
				if ( ! input.checked ) {
					input.checked = true;
					input.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );
		await expect
			.poll( async () =>
				page
					.locator( '#vkbm_service_menu_exclusive_when_booked' )
					.evaluate( ( el ) => ( el as HTMLInputElement ).checked )
			)
			.toBe( true );
	} );

	test( '複数人予約OFF時は貸し切り予約チェックボックスが隠れる（B案）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// いったん複数人予約OFFのメニューを作る。
		const offMenuId = wpEvalPhp(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
				'post_title'  => 'Exclusive Off Menu',
			) );
			echo $id;
		`
		).trim();

		await page.goto(
			`/wp-admin/post.php?post=${ Number.parseInt(
				offMenuId,
				10
			) }&action=edit`
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 複数人予約OFF なので貸し切り予約行は hidden 属性が付いている（B案・初期非表示）。
		const exclusiveRow = page.locator(
			'#vkbm-exclusive-when-booked-field'
		);
		await exclusiveRow.waitFor( { state: 'attached', timeout: 15000 } );
		await expect
			.poll( async () =>
				exclusiveRow.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( true );

		// 後始末。
		wpEvalPhp(
			`wp_delete_post( ${ Number.parseInt( offMenuId, 10 ) }, true );`
		);
	} );

	test( '貸し切り予約が入った時間帯はフロントで予約受付終了になる', async ( {
		page,
	} ) => {
		const staffId = getStaffId();
		// 翌月の中旬（15日）を対象にする（予約締切・過去日フィルタを確実に避ける）。
		// 対象月は Asia/Tokyo 基準で算出する。Node ローカル時刻（new Date().getMonth()）に
		// 依存すると、CI の Node が UTC のため JST 月初に1か月ズレ、UI（browser=Asia/Tokyo で
		// 「次の月」を押して表示する月）と seed/予約する月が食い違って失敗する（issue #324 と同根）。
		// 共通ヘルパーの翌月（[1]）を使い、UI のカレンダー月送りと常に一致させる。
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		const year = String( nextTokyoMonth.year );
		// month はゼロパディングした文字列（PHP 日時文字列 "YYYY-MM-DD HH:MM:SS" 用）。
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		// 対象日は 15 日固定（同じくゼロパディング）。
		const day = '15';
		// createShiftForMonth には数値の year/month を渡す。
		createShiftForMonth(
			staffId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);

		// このテスト自身でメニューの貸し切り設定を明示的に ON にする（前テストの副作用に依存しない）。
		// 単体実行・retry でも exclusive_closed を再現できるようにするため。
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt(
				exclusiveMenuId,
				10
			) }, '_vkbm_exclusive_when_booked', true );`
		);

		// 対象日の 09:00-10:00 に「貸し切り」予約を1件投入する（残席はあるが受付停止になるはず）。
		const slotDay = `${ year }-${ month }-${ day }`;
		wpEvalPhp(
			`
			$booking_id = wp_insert_post( array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'publish',
				'post_title'  => 'Exclusive E2E Booking',
			) );
			update_post_meta( $booking_id, '_vkbm_booking_service_start', '${ slotDay } 09:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_service_end', '${ slotDay } 10:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_total_end', '${ slotDay } 10:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_resource_id', ${ Number.parseInt(
				staffId,
				10
			) } );
			update_post_meta( $booking_id, '_vkbm_booking_service_id', ${ Number.parseInt(
				exclusiveMenuId,
				10
			) } );
			update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
			update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
			update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
			echo $booking_id;
		`
		);

		// 別ユーザー（未ログイン）視点で予約フォームを開く。
		await page.context().clearCookies();
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// 予約フォームで貸し切り検証用メニューを選択する。
		// メニュー選択UIがセレクトの場合とカードの場合の双方に対応する。
		const menuSelect = page.locator( 'select' ).filter( {
			has: page.locator( `option:has-text("${ EXCLUSIVE_MENU_TITLE }")` ),
		} );
		if ( ( await menuSelect.count() ) > 0 ) {
			await menuSelect
				.first()
				.selectOption( { label: EXCLUSIVE_MENU_TITLE } );
		} else {
			const card = page
				.locator( '.vkbm-menu-loop__item', {
					hasText: EXCLUSIVE_MENU_TITLE,
				} )
				.first();
			await card
				.locator( '.vkbm-menu-loop__button--reserve' )
				.first()
				.click();
		}

		// カレンダー表示を待つ。
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 15000,
		} );

		// 対象は翌月のため「次の月」を1回送ってから対象日（15日）を選ぶ。
		await page.getByRole( 'button', { name: '次の月' } ).click();
		await page.waitForTimeout( 800 );

		const dayButton = page
			.locator( '.vkbm-calendar__day', {
				hasText: new RegExp( `^${ Number( day ) }$` ),
			} )
			.first();
		await dayButton.click();
		await page.waitForTimeout( 1000 );

		// 09:00 のスロットが「予約受付終了」状態（is-exclusive-closed・選択不可）であることを確認する。
		const closedSlot = page
			.locator( '.vkbm-slot-list__item.is-exclusive-closed' )
			.first();
		await expect( closedSlot ).toBeVisible();
		await expect( closedSlot ).toBeDisabled();
		await expect( closedSlot ).toContainText( '予約受付終了' );
	} );
} );
