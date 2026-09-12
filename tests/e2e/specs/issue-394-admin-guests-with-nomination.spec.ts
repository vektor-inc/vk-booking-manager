/**
 * issue #394: 管理画面の予約編集で、指名を使うメニューの予約でも人数を編集できるようにする e2e 検証。
 *
 * 親issue #251 で確定した仕様のうち、管理画面と通知まわりを指名あり＋複数人の予約に対応させる
 * サブ issue。フロント側の受付ロジックは PR #396（issue #392）で対応済みのため、ここでは
 * 予約編集メタボックス（`Booking_Admin::render_meta_box()` / `save_post()`）の
 * 人数編集・保存バリデーションのみを検証する。
 *
 * 検証内容:
 * 1. 指名を使うメニューの予約でも、人数入力欄が表示され（上限＝予約枠の定員）、
 *    定員内の人数へ変更して保存できる。人数欄と説明文は aria-describedby で紐付いている。
 * 2. 定員を超える人数を入力して保存しようとすると、サーバー側バリデーションで
 *    保存が中断され、エラー通知が表示される。保存済みの人数（既存値）は変更されない。
 *
 * 実行環境:
 * - playwright.config の baseURL（テスト用 wp-env）に対して実行する。
 *   絶対URLはハードコードせず page.goto には相対パスを渡す。
 * - 予約・メニューは issue-392-nomination-slot-capacity.spec.ts / exclusive-booking.spec.ts と
 *   同じ手法（wp_insert_post を wpEvalPhp 経由で直接シード）で作成する。
 *   ブラウザ経由の実予約確定（ログイン＋フロント予約フロー）は本 spec のスコープ外とする。
 */
import { test, expect } from '@playwright/test';
import {
	loginAsAdmin,
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	getStaffEnabled,
	setStaffEnabled,
} from '../utils/helpers';

const MENU_TITLE = 'Admin Guests Nomination Menu';
const MENU_MAX_CAPACITY = 3;
const MENU_BASE_PRICE = 1000;

let originalStaffEnabled = true;
let menuId = '';
let staffId = '';

/**
 * 指名を使う検証用メニュー（定員3・複数人一括予約許可・スタッフ割当）を作成する。
 * 同名メニューがあれば作り直す（冪等）。
 *
 * @return 作成したサービスメニューの post ID（数値文字列）
 */
function seedNominationMenu(): string {
	const phpCode = `
		$staff_id = ${ Number.parseInt( staffId, 10 ) };

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
			echo 'Error: failed to create nomination menu';
			return;
		}
		// このメニューでは指名を使う（既定＝使う。_vkbm_disable_nomination は保存しない）。
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', 1 );
		update_post_meta( $menu_id, '_vkbm_max_capacity', ${ MENU_MAX_CAPACITY } );
		update_post_meta( $menu_id, '_vkbm_base_price', ${ MENU_BASE_PRICE } );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		echo $menu_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Nomination menu seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * 指名を使うメニューの予約を1件シードする（人数1・確定ステータス）。
 * 過去・他specと衝突しないよう遠い未来日を使う。
 *
 * @param dateStr 予約日（YYYY-MM-DD）
 * @return 作成した予約の post ID（数値文字列）
 */
function seedBooking( dateStr: string ): string {
	const phpCode = `
		$booking_id = wp_insert_post( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'publish',
			'post_title'  => 'Admin Guests Nomination E2E Booking',
		) );
		if ( is_wp_error( $booking_id ) || ! $booking_id ) {
			echo 'Error: failed to create booking';
			return;
		}
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '${ dateStr } 09:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', '${ dateStr } 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', '${ dateStr } 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', ${ Number.parseInt(
			staffId,
			10
		) } );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', ${ Number.parseInt(
			menuId,
			10
		) } );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
		echo $booking_id;
	`;
	const result = wpEvalPhp( phpCode ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `Booking seeding failed: "${ result }"` );
	}
	return result;
}

/**
 * シード済みの予約投稿を削除する（テスト後始末）。
 *
 * @param bookingId 削除する予約の post ID（数値文字列）
 */
function deleteBooking( bookingId: string ): void {
	if ( bookingId ) {
		wpCliArgs( [ 'post', 'delete', bookingId, '--force' ], {
			stdio: 'ignore',
		} );
	}
}

test.describe( 'issue #394: 管理画面の予約編集で指名あり・複数人の予約の人数を編集できる', () => {
	test.beforeAll( () => {
		originalStaffEnabled = getStaffEnabled();
		// サイト全体の指名機能をONにする（メニューは既定で指名を使う）。
		setStaffEnabled( true );

		staffId = getStaffId();
		menuId = seedNominationMenu();
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

	test( '指名を使うメニューの予約の人数を変更して保存できる（人数欄はaria-describedbyで説明文と紐付く）', async ( {
		page,
	} ) => {
		const bookingId = seedBooking( '2999-01-15' );

		try {
			await loginAsAdmin( page );
			await page.goto(
				`/wp-admin/post.php?post=${ bookingId }&action=edit`
			);
			await page.waitForLoadState( 'domcontentloaded' );

			const guestsInput = page.locator(
				'input[name="vkbm_booking[guests]"]'
			);
			await guestsInput.waitFor( { state: 'visible', timeout: 15000 } );

			// #394: 指名を使うメニューでも人数入力欄が表示され、上限（予約枠の定員=3）が反映されている。
			await expect( guestsInput ).toHaveAttribute(
				'max',
				String( MENU_MAX_CAPACITY )
			);

			// 人数欄と直下の説明文が aria-describedby で紐付いている（#394）。
			const describedBy =
				await guestsInput.getAttribute( 'aria-describedby' );
			expect( describedBy ).toBeTruthy();
			if ( describedBy ) {
				const description = page.locator( `#${ describedBy }` );
				await expect( description ).toBeVisible();

				// #394: ベースの説明文（1予約あたりの上限・保存時に基本料金を再計算する旨）が
				// 表示されている。翻訳漏れ（msgstr が空 → 空文字にフォールバックし、文が
				// まるごと消える）を自動テストで検出できるよう、日本語訳の一部をアサートする。
				await expect( description ).toContainText(
					'保存時に人数から基本料金を再計算します'
				);

				// #394: 指名を使うメニューだけに追加した「1枠1組の貸切になる」旨の説明文。
				// テストサイトは日本語ロケールで動作するため、実際にレンダリングされる訳文の
				// 一部（この説明文だけに含まれる語）をアサートする（他の既存 spec と同じ作法。
				// 例: exclusive-booking.spec.ts の '予約受付終了' 等）。ベースの説明文
				// （上記）と区別するため、貸切に関する語のみを含む部分を選ぶ。
				await expect( description ).toContainText(
					'貸切（1枠1組）になります'
				);
			}

			// 定員内（3のうち2）へ変更して保存する。
			await guestsInput.fill( '2' );
			await page.locator( '#publish' ).click();
			await page.waitForLoadState( 'domcontentloaded' );

			// 保存後、再読み込みされた画面の人数欄に変更後の値（2）が反映されている。
			const guestsInputAfterSave = page.locator(
				'input[name="vkbm_booking[guests]"]'
			);
			await guestsInputAfterSave.waitFor( {
				state: 'visible',
				timeout: 15000,
			} );
			await expect( guestsInputAfterSave ).toHaveValue( '2' );
		} finally {
			deleteBooking( bookingId );
		}
	} );

	test( '定員を超える人数を入力して保存しようとすると保存が中断され、既存の人数が保持される', async ( {
		page,
	} ) => {
		const bookingId = seedBooking( '2999-01-16' );

		try {
			await loginAsAdmin( page );
			await page.goto(
				`/wp-admin/post.php?post=${ bookingId }&action=edit`
			);
			await page.waitForLoadState( 'domcontentloaded' );

			const guestsInput = page.locator(
				'input[name="vkbm_booking[guests]"]'
			);
			await guestsInput.waitFor( { state: 'visible', timeout: 15000 } );

			// 定員（3）を超える人数（99）を入力する。number 入力の max 属性による
			// ブラウザ側の暗黙バリデーションで送信自体がブロックされないよう、
			// フォームの novalidate を有効にしてからサーバー側バリデーションを検証する
			// （ユーザーはスピナーではなく直接キー入力で max を超える値を入力し得るため、
			//  この迂回は実利用シナリオの近似として妥当）。
			await guestsInput.evaluate( ( el ) => {
				const input = el as HTMLInputElement;
				input.value = '99';
				if ( input.form ) {
					input.form.noValidate = true;
				}
			} );
			await page.locator( '#publish' ).click();
			await page.waitForLoadState( 'domcontentloaded' );

			// サーバー側バリデーションでエラー通知が表示される（管理者向けの保存中断案内）。
			await expect( page.locator( '.notice-error' ).first() ).toBeVisible(
				{ timeout: 15000 }
			);

			// 保存済みの人数（既存値1）は変更されず保持されている。
			const guestsInputAfterSave = page.locator(
				'input[name="vkbm_booking[guests]"]'
			);
			await guestsInputAfterSave.waitFor( {
				state: 'visible',
				timeout: 15000,
			} );
			await expect( guestsInputAfterSave ).toHaveValue( '1' );
		} finally {
			deleteBooking( bookingId );
		}
	} );
} );
