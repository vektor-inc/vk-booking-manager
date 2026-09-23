import { test, expect, type Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	resolvePluginSlug,
} from '../utils/helpers';

/**
 * テストで使うスタッフ名（issue #262 差し戻し2の対応）。
 *
 * 半角ハイフンを挟んだ表記（例: "A - B"）は、WordPress 本体の `wptexturize()` が
 * 前後を半角スペースで挟まれたハイフンを en dash（–）へ自動変換するため、
 * 画面に描画された文字列を `toContainText()` で厳密一致させると失敗する
 * （サイト全体の投稿タイトルに一律でかかる処理のため、実装側では避けない）。
 * ハイフンを使わない表記にして、変換の影響を受けないようにする。
 */
const STAFF_ACTIVE_BOOKING = 'E2E Guard Active Booking';
const STAFF_CANCELLED_BOOKING_ONLY = 'E2E Guard Cancelled Booking Only';
const STAFF_NO_BOOKINGS = 'E2E Guard No Bookings';
const STAFF_BULK_DELETE_BLOCKED = 'E2E Guard Bulk Delete Blocked';
const STAFF_BULK_DELETE_ALLOWED = 'E2E Guard Bulk Delete Allowed';
const STAFF_EMPTY_TRASH_BLOCKED = 'E2E Guard Empty Trash Blocked';

/**
 * リソース一覧（ゴミ箱一覧も可）へ、対象スタッフ名で検索絞り込みした状態で遷移する。
 *
 * 繰り返し実行で他のテスト実行の残留データが増えても、検索語（スタッフ名）で
 * 1件に絞り込むことで、ページネーションや同名衝突の影響を受けずに対象行
 * （`#post-<ID>`）を確実に表示させる（issue #262 差し戻し3の対応）。
 *
 * @param page       Playwright の Page。
 * @param staffName  絞り込みに使うスタッフ名（部分一致・完全一致どちらでも可な一意な名前）。
 * @param postStatus 追加の post_status 絞り込み（省略時は「ゴミ箱以外」の既定ビュー）。
 */
async function gotoResourceList(
	page: Page,
	staffName: string,
	postStatus?: string
): Promise< void > {
	const params = new URLSearchParams( {
		post_type: 'vkbm_resource',
		s: staffName,
	} );
	if ( postStatus ) {
		params.set( 'post_status', postStatus );
	}
	await page.goto( `/wp-admin/edit.php?${ params.toString() }` );
}

/**
 * リソース（スタッフ）削除ガードの管理画面 e2e テスト（issue #262）。
 *
 * 検証する範囲:
 * - ゴミ箱へ移動: 対応中の予約があるスタッフを行アクションから「ゴミ箱へ移動」すると、
 *   警告ダイアログ（role="alertdialog"）が出て、キャンセルすればゴミ箱へ移動しないこと。
 *   「続行してゴミ箱へ移動」すれば実際にゴミ箱へ移動し、一覧に警告の管理通知が出ること。
 * - 完全に削除: 紐づく予約が残っているスタッフを、ゴミ箱一覧から「完全に削除」しようとすると、
 *   ブロックダイアログが出て「それでも削除する」ボタンが無く、実際には削除されないこと。
 * - 対応中の予約・紐づく予約が無いスタッフは、ダイアログを出さずにそのまま実行できること（回帰確認）。
 * - 一括の完全削除・「ゴミ箱を空にする」（いずれも事前ダイアログを出さずサーバー側でスキップする
 *   経路）: 紐づく予約が残っているスタッフだけスキップされ、実行後の管理通知に表示されること
 *   （司の指示・issue #262: 通知の組み立てを触る修正のため、実ブラウザで確認する）。
 */

/**
 * テスト用のスタッフ（リソース）投稿を作成する。
 *
 * @param title スタッフの表示名。
 * @return 作成した投稿ID（数値文字列）。
 */
function createStaff( title: string ): string {
	return wpEvalPhp(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_resource',
			'post_status' => 'publish',
			'post_title'  => '${ title }',
		) );
		echo $id;
		`
	).trim();
}

/**
 * テスト用の予約投稿を作成し、担当スタッフ・ステータスのメタを設定する。
 *
 * @param resourceId 担当スタッフ（リソース）投稿ID。
 * @param status     予約ステータス（pending・confirmed・cancelled 等）。
 * @return 作成した予約投稿ID（数値文字列）。
 */
function createBooking( resourceId: string, status: string ): string {
	const resourceIdNumber = Number.parseInt( resourceId, 10 );
	return wpEvalPhp(
		`
		$id = wp_insert_post( array(
			'post_type'   => 'vkbm_booking',
			'post_status' => 'publish',
			'post_title'  => 'E2E Guard Booking',
		) );
		update_post_meta( $id, '_vkbm_booking_resource_id', ${ resourceIdNumber } );
		update_post_meta( $id, '_vkbm_booking_status', '${ status }' );
		echo $id;
		`
	).trim();
}

test.describe( 'リソース削除ガード（issue #262）', () => {
	let staffWithActiveBooking: string;
	let staffWithLinkedBookingOnly: string;
	let staffWithoutBookings: string;

	test.beforeAll( () => {
		// プラグインが有効であることを確認する
		// Ensure the plugin is active.
		const pluginSlug = resolvePluginSlug();
		try {
			wpCliArgs( [ 'plugin', 'is-active', pluginSlug ] );
		} catch {
			wpCliArgs( [ 'plugin', 'activate', pluginSlug ] );
		}

		// global-setup.ts がサイト言語を日本語へ切り替えているが、本テストのダイアログ・
		// 通知の文言はまだ日本語訳（languages/vk-booking-manager-ja.po）を追加していないため
		// 未訳文字列として英語のまま表示される想定ではあるものの、判定を言語設定に依存させない
		// よう明示的に英語へ戻す（既存の他 spec への影響を避けるため afterAll で日本語へ戻す）。
		wpCliArgs( [ 'site', 'switch-language', 'en_US' ] );

		// 対応中（pending）の予約が1件あるスタッフ => ゴミ箱移動で警告が出る対象。
		staffWithActiveBooking = createStaff( STAFF_ACTIVE_BOOKING );
		createBooking( staffWithActiveBooking, 'pending' );

		// キャンセル済みの予約のみ紐づくスタッフ => 警告対象外だが、完全削除はブロックされる対象。
		staffWithLinkedBookingOnly = createStaff(
			STAFF_CANCELLED_BOOKING_ONLY
		);
		createBooking( staffWithLinkedBookingOnly, 'cancelled' );

		// 予約が1件も紐づかないスタッフ => ダイアログを出さず通常どおり操作できる対象（回帰確認）。
		staffWithoutBookings = createStaff( STAFF_NO_BOOKINGS );
	} );

	test.afterAll( () => {
		// テストデータを片付ける（残っていれば削除ガードを一時的に外して確実に消す）。
		// beforeAll が途中で失敗した場合、未設定の変数は undefined のままになり得るため、
		// PHP コードへ埋め込む前に数値化しておく（0 は if ($id > 0) で無視される）。
		const cleanupIds = [
			staffWithActiveBooking,
			staffWithLinkedBookingOnly,
			staffWithoutBookings,
		]
			.map( ( id ) => Number.parseInt( id, 10 ) || 0 )
			.join( ', ' );
		wpEvalPhp(
			`
			$vkbm_guard = \\VKBookingManager\\Resources\\Resource_Delete_Guard::get_instance();
			if ( null !== $vkbm_guard ) {
				remove_filter( 'pre_delete_post', array( $vkbm_guard, 'handle_pre_delete_post' ), 10 );
			}
			foreach ( array( ${ cleanupIds } ) as $id ) {
				if ( $id > 0 ) {
					wp_delete_post( $id, true );
				}
			}
			$bookings = get_posts( array(
				'post_type'      => 'vkbm_booking',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				's'              => 'E2E Guard Booking',
			) );
			foreach ( $bookings as $booking_id ) {
				wp_delete_post( $booking_id, true );
			}
			`
		);

		// 他の spec が日本語表示を前提にしているため、サイト言語を日本語へ戻す。
		wpCliArgs( [ 'site', 'switch-language', 'ja' ] );
	} );

	test( '対応中の予約があるスタッフをゴミ箱へ移動すると警告ダイアログが出て、キャンセルすればゴミ箱へ移動しない', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoResourceList( page, STAFF_ACTIVE_BOOKING );

		const row = page.locator( `#post-${ staffWithActiveBooking }` );
		await expect( row ).toBeVisible();

		// 行アクションはホバー/フォーカスで見えるようになるが、hover() で表示状態にしてから
		// トラッシュリンクをクリックする。
		await row.hover();
		await row.locator( '.row-actions .trash a' ).click();

		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		// アイコン付きで危険度を伝えていること（色だけに頼らない）を確認する。
		await expect( dialog.locator( '.dashicons-warning' ) ).toBeVisible();
		await expect(
			dialog.getByRole( 'link', { name: /active bookings/i } )
		).toBeVisible();

		// キャンセルするとダイアログが閉じ、行は一覧に残ったまま（ゴミ箱へ移動しない）。
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();
		await expect( row ).toBeVisible();

		const status = wpEvalPhp(
			`echo get_post_status( ${ staffWithActiveBooking } );`
		).trim();
		expect( status ).toBe( 'publish' );
	} );

	test( '警告ダイアログで「続行してゴミ箱へ移動」を選ぶと実際にゴミ箱へ移動し、一覧に警告の通知が表示される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoResourceList( page, STAFF_ACTIVE_BOOKING );

		const row = page.locator( `#post-${ staffWithActiveBooking }` );
		await row.hover();
		await row.locator( '.row-actions .trash a' ).click();

		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		await dialog
			.getByRole( 'button', { name: 'Move to Trash anyway' } )
			.click();

		// ゴミ箱への移動後に遷移する URL（?trashed=1&ids=X）は、WordPress 本体が
		// 履歴書き換え（history.replaceState 相当）で数百ミリ秒以内にクエリ引数を
		// アドレスバーから消してしまう一瞬しか存在しない状態のため、URL 待ちは
		// このタイミングとレースして不安定になる（issue #262 差し戻し1の対応）。
		// URL を待つのではなく、遷移後に描画される通知の可視化を待つ。
		const notice = page.locator( '.vkbm-resource-guard-notice' );
		await expect( notice ).toBeVisible();
		await expect(
			notice.locator( '.vkbm-resource-guard-notice__heading' )
		).toContainText( 'Moved' );

		const status = wpEvalPhp(
			`echo get_post_status( ${ staffWithActiveBooking } );`
		).trim();
		expect( status ).toBe( 'trash' );
	} );

	test( '紐づく予約が残っているスタッフを完全に削除しようとするとブロックされ、「それでも削除する」ボタンは無い', async ( {
		page,
	} ) => {
		// このスタッフはまだ公開状態のため、先にゴミ箱へ移動しておく
		// （ゴミ箱移動は警告のみで止まらないため、対象を汚さず素通りする）。
		await loginAsAdmin( page );
		await gotoResourceList( page, STAFF_CANCELLED_BOOKING_ONLY );
		const publishedRow = page.locator(
			`#post-${ staffWithLinkedBookingOnly }`
		);
		await publishedRow.hover();
		await publishedRow.locator( '.row-actions .trash a' ).click();
		// このスタッフはキャンセル済みの予約のみ（対応中の予約は無い）ため、ゴミ箱移動時の
		// 警告通知（.vkbm-resource-guard-notice）は出ない。代わりに WordPress 本体が出す
		// 標準の「ゴミ箱へ移動しました」通知（#message）の可視化を待つことで、URL の一瞬の
		// 存在に依存せずページ遷移の完了を検出する（issue #262 差し戻し1の対応）。
		await expect( page.locator( '#message' ) ).toBeVisible();

		// ゴミ箱一覧で「完全に削除」を試みる。
		await gotoResourceList( page, STAFF_CANCELLED_BOOKING_ONLY, 'trash' );
		const trashedRow = page.locator(
			`#post-${ staffWithLinkedBookingOnly }`
		);
		await expect( trashedRow ).toBeVisible();
		await trashedRow.hover();
		await trashedRow.locator( '.row-actions .delete a' ).click();

		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		await expect( dialog.locator( '.dashicons-dismiss' ) ).toBeVisible();
		// 「それでも削除する」に相当するボタンが無いことを確認する（Close のみ）。
		await expect(
			dialog.getByRole( 'button', { name: /anyway|force|delete/i } )
		).toHaveCount( 0 );
		await expect(
			dialog.getByRole( 'link', { name: /linked bookings/i } )
		).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Close' } ).click();
		await expect( dialog ).toBeHidden();

		// サーバー側でも実際にブロックされている（投稿がまだ存在する）ことを確認する。
		const status = wpEvalPhp(
			`echo get_post_status( ${ staffWithLinkedBookingOnly } );`
		).trim();
		expect( status ).toBe( 'trash' );
	} );

	test( '予約が1件も紐づかないスタッフは、ダイアログを出さずにそのままゴミ箱へ移動できる（回帰確認）', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await gotoResourceList( page, STAFF_NO_BOOKINGS );

		const row = page.locator( `#post-${ staffWithoutBookings }` );
		await expect( row ).toBeVisible();
		await row.hover();
		await row.locator( '.row-actions .trash a' ).click();

		// ダイアログを出さずに即座にゴミ箱へ移動する（一覧ページへ遷移する）。予約が無いため
		// 独自の警告通知は出ず、WordPress 標準の「ゴミ箱へ移動しました」通知（#message）で
		// 遷移完了を検出する（issue #262 差し戻し1の対応）。
		await expect( page.locator( '#message' ) ).toBeVisible();
		await expect( page.getByRole( 'alertdialog' ) ).toHaveCount( 0 );

		const status = wpEvalPhp(
			`echo get_post_status( ${ staffWithoutBookings } );`
		).trim();
		expect( status ).toBe( 'trash' );
	} );

	test( 'Esc キーでダイアログを閉じられる', async ( { page } ) => {
		await loginAsAdmin( page );
		await gotoResourceList( page, STAFF_CANCELLED_BOOKING_ONLY, 'trash' );

		const row = page.locator( `#post-${ staffWithLinkedBookingOnly }` );
		await expect( row ).toBeVisible();
		await row.hover();
		await row.locator( '.row-actions .delete a' ).click();

		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	} );

	test( '一括で「完全に削除」すると、紐づく予約が残っているスタッフだけスキップされ、通知が表示される', async ( {
		page,
	} ) => {
		// 一括の完全削除は事前ダイアログを出さずサーバー側でスキップする経路のため、
		// ダイアログではなく実行後の管理通知（.vkbm-resource-guard-notice）で確認する。
		const blockedStaffId = createStaff( STAFF_BULK_DELETE_BLOCKED );
		createBooking( blockedStaffId, 'cancelled' );
		const deletableStaffId = createStaff( STAFF_BULK_DELETE_ALLOWED );

		await loginAsAdmin( page );

		// 両方ともゴミ箱へ移動しておく（いずれも対応中の予約は無いため、独自の警告通知は
		// 出ない。WordPress 標準の「ゴミ箱へ移動しました」通知（#message）で遷移完了を
		// 検出する。URL 待ちをやめた理由は issue #262 差し戻し1を参照）。
		for ( const { id, name } of [
			{ id: blockedStaffId, name: STAFF_BULK_DELETE_BLOCKED },
			{ id: deletableStaffId, name: STAFF_BULK_DELETE_ALLOWED },
		] ) {
			await gotoResourceList( page, name );
			const row = page.locator( `#post-${ id }` );
			await expect( row ).toBeVisible();
			await row.hover();
			await row.locator( '.row-actions .trash a' ).click();
			await expect( page.locator( '#message' ) ).toBeVisible();
		}

		// ゴミ箱一覧で両方を選択し、一括操作「完全に削除」を実行する。
		// 両スタッフに共通する語（"E2E Guard Bulk Delete"）で絞り込み、他テストの
		// 残留データやページネーションに影響されず両方の行を確実に表示させる
		// （issue #262 差し戻し3の対応）。
		await gotoResourceList( page, 'E2E Guard Bulk Delete', 'trash' );
		await page
			.locator( `#post-${ blockedStaffId } input[type="checkbox"]` )
			.check();
		await page
			.locator( `#post-${ deletableStaffId } input[type="checkbox"]` )
			.check();
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'delete' );
		await page.locator( '#doaction' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const notice = page.locator( '.vkbm-resource-guard-notice' );
		await expect( notice ).toBeVisible();
		await expect(
			notice.locator( '.vkbm-resource-guard-notice__heading' )
		).toContainText( 'Skipped' );
		await expect(
			notice.locator( '.vkbm-resource-guard-notice__list' )
		).toContainText( STAFF_BULK_DELETE_BLOCKED );

		// 紐づく予約があるほうはスキップされゴミ箱に残り、無いほうは実際に完全削除されている。
		const blockedStatus = wpEvalPhp(
			`echo get_post_status( ${ blockedStaffId } );`
		).trim();
		expect( blockedStatus ).toBe( 'trash' );

		const deletableStatus = wpEvalPhp(
			`$status = get_post_status( ${ deletableStaffId } ); echo false === $status ? 'deleted' : $status;`
		).trim();
		expect( deletableStatus ).toBe( 'deleted' );

		// 後片付け（削除ガードを一時的に外して確実に消す。予約はタイトル一致で afterAll が掃除する）。
		wpEvalPhp(
			`
			$vkbm_guard = \\VKBookingManager\\Resources\\Resource_Delete_Guard::get_instance();
			if ( null !== $vkbm_guard ) {
				remove_filter( 'pre_delete_post', array( $vkbm_guard, 'handle_pre_delete_post' ), 10 );
			}
			wp_delete_post( ${ blockedStaffId }, true );
			`
		);
	} );

	test( '「ゴミ箱を空にする」を実行すると、紐づく予約が残っているスタッフはスキップされ、通知が表示される', async ( {
		page,
	} ) => {
		// 「ゴミ箱を空にする」は post_type=vkbm_resource のゴミ箱にある全件を対象にするため、
		// このテストは他のテストが残したゴミ箱内スタッフも巻き込む前提で書く（悪影響は無い:
		// 紐づく予約がある分はスキップされたままゴミ箱に残り、無い分は afterAll を待たず
		// 片付くだけ）。このテスト専用のブロック対象だけを個別に検証する。
		const staffId = createStaff( STAFF_EMPTY_TRASH_BLOCKED );
		createBooking( staffId, 'cancelled' );

		await loginAsAdmin( page );
		await gotoResourceList( page, STAFF_EMPTY_TRASH_BLOCKED );
		const row = page.locator( `#post-${ staffId }` );
		await expect( row ).toBeVisible();
		await row.hover();
		await row.locator( '.row-actions .trash a' ).click();
		// 対応中の予約は無い（キャンセル済みのみ）ため独自の警告通知は出ない。WordPress
		// 標準の「ゴミ箱へ移動しました」通知（#message）で遷移完了を検出する
		// （issue #262 差し戻し1の対応）。
		await expect( page.locator( '#message' ) ).toBeVisible();

		await gotoResourceList( page, STAFF_EMPTY_TRASH_BLOCKED, 'trash' );
		const trashedRow = page.locator( `#post-${ staffId }` );
		await expect( trashedRow ).toBeVisible();

		// 一覧の上下に同じ「ゴミ箱を空にする」ボタンが2つ存在するため（tablenav-top / tablenav-bottom）、
		// 最初の1つを使う。
		await page
			.getByRole( 'button', { name: 'Empty Trash' } )
			.first()
			.click();
		await page.waitForLoadState( 'domcontentloaded' );

		const notice = page.locator( '.vkbm-resource-guard-notice' );
		await expect( notice ).toBeVisible();
		await expect(
			notice.locator( '.vkbm-resource-guard-notice__heading' )
		).toContainText( 'Skipped' );
		await expect(
			notice.locator( '.vkbm-resource-guard-notice__list' )
		).toContainText( STAFF_EMPTY_TRASH_BLOCKED );

		// 紐づく予約が残っているため、完全削除はスキップされゴミ箱に残ったままである。
		const status = wpEvalPhp(
			`echo get_post_status( ${ staffId } );`
		).trim();
		expect( status ).toBe( 'trash' );

		// 後片付け（削除ガードを一時的に外して確実に消す。予約はタイトル一致で afterAll が掃除する）。
		wpEvalPhp(
			`
			$vkbm_guard = \\VKBookingManager\\Resources\\Resource_Delete_Guard::get_instance();
			if ( null !== $vkbm_guard ) {
				remove_filter( 'pre_delete_post', array( $vkbm_guard, 'handle_pre_delete_post' ), 10 );
			}
			wp_delete_post( ${ staffId }, true );
			`
		);
	} );
} );
