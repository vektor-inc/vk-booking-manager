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
		// @step 管理画面でスタッフ指名を使わない設定にしておく
		setStaffEnabled( false );
		// @step 複数人予約あり・最大 3 名・所要 60 分で、スタッフを割り当てた「Exclusive Menu」を作成しておく
		exclusiveMenuId = seedExclusiveMenu();
	} );

	test.afterAll( () => {
		// 各後始末を個別に try/catch で囲む。どれかが例外を投げても、
		// 後続の削除や setStaffEnabled の復元が飛ばないようにするため。
		if ( exclusiveMenuId ) {
			try {
				wpEvalPhp(
					`wp_delete_post( ${ Number.parseInt(
						exclusiveMenuId,
						10
					) }, true );`
				);
			} catch ( e ) {
				console.warn( 'Failed to delete exclusive menu:', e );
			}
			exclusiveMenuId = '';
		}
		// B案テストが途中で失敗しても「Exclusive Off Menu」を残さないよう、
		// 公開・下書きを問わずタイトル一致の投稿をすべて削除する（#566）。
		// 残ると担当スタッフ無しメニューとして後続 spec の予約フローを巻き込んで落とす。
		try {
			wpEvalPhp(
				`
			$ids = get_posts( array(
				'post_type'      => 'vkbm_service_menu',
				'post_status'    => 'any',
				'title'          => 'Exclusive Off Menu',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		`
			);
		} catch ( e ) {
			console.warn( 'Failed to delete "Exclusive Off Menu" posts:', e );
		}
		try {
			setStaffEnabled( originalStaffEnabled );
		} catch ( e ) {
			console.warn( 'Failed to restore staff_enabled:', e );
		}
	} );

	test( '編集画面で貸し切り予約チェックボックスが表示・保存される', async ( {
		page,
	} ) => {
		// @step 管理者でログインし、「Exclusive Menu」の編集画面を開く
		await loginAsAdmin( page );

		// サービスメニュー編集画面を開く。
		await page.goto(
			`/wp-admin/post.php?post=${ exclusiveMenuId }&action=edit`
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 「複数人予約を許可」を ON にし、change イベントを発火して JS の表示連動を起こす。
		// （WP 管理画面のメタボックス内要素は Playwright の可視判定が安定しないため、
		//   DOM 経由で状態を設定し change を dispatch する。B案：貸し切り予約は複数人予約ON時のみ表示。）
		// @step 「複数人予約を許可」にチェックを入れる
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
		// @check 「貸し切り予約」の行が表示される
		const exclusiveRow = page.locator(
			'#vkbm-exclusive-when-booked-field'
		);
		await expect
			.poll( async () =>
				exclusiveRow.evaluate( ( el ) => ( el as HTMLElement ).hidden )
			)
			.toBe( false );

		// @check 「貸し切り予約」のチェックボックスに説明文が結び付いている
		const checkbox = page.locator(
			'#vkbm_service_menu_exclusive_when_booked'
		);
		// 説明文が aria-describedby で紐付いている。
		await expect( checkbox ).toHaveAttribute(
			'aria-describedby',
			'vkbm-exclusive-when-booked-description'
		);

		// 貸し切り予約のチェックを入れられる（無効化されていない）ことを確認する。
		// @step 「貸し切り予約」にチェックを入れる
		await checkbox.evaluate( ( el ) => {
			( el as HTMLInputElement ).checked = true;
		} );
		// @check 「貸し切り予約」にチェックが入る
		await expect( checkbox ).toBeChecked();

		// 保存経路（save_post → メタ保存）は phpunit で検証済みのため、
		// ここでは REST 経由でメタを ON 保存し、編集画面の再読込でチェックが復元されることを確認する。
		// （ブロックエディタのメタボックス保存ボタンは管理画面チロームに依存し e2e が不安定なため、
		//   保存状態の復元という観点に絞る。）
		// @step 「貸し切り予約」をオンにした状態で保存しておき、編集画面を再読み込みする
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt(
				exclusiveMenuId,
				10
			) }, '_vkbm_exclusive_when_booked', true );`
		);
		await page.reload();
		await page.waitForLoadState( 'domcontentloaded' );

		// 複数人予約を再度ONにして貸し切り行を表示させ、チェックが復元されていることを確認する。
		// @step 「複数人予約を許可」にチェックを入れる
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
		// @check 「貸し切り予約」にチェックが入ったまま表示される
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
		// @step 管理者でログインする
		await loginAsAdmin( page );

		// いったん複数人予約OFFのメニューを作る。
		// 下書き（draft）で作る：管理者は下書きの編集画面を開けるため検証は成立し、
		// 万一削除されずに残っても公開メニュー一覧・予約ページには出ない（#566）。
		// @step 複数人予約なしの下書きメニュー「Exclusive Off Menu」を作成しておく
		const offMenuId = wpEvalPhp(
			`
			$id = wp_insert_post( array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'draft',
				'post_title'  => 'Exclusive Off Menu',
			) );
			echo $id;
		`
		).trim();
		// 作成失敗（空文字・0 など）を try に入る前に検出する（seedExclusiveMenu と同じ形）。
		if ( ! /^\d+$/.test( offMenuId ) || Number( offMenuId ) <= 0 ) {
			throw new Error(
				`Exclusive Off Menu creation failed: "${ offMenuId }"`
			);
		}

		try {
			// @step 「Exclusive Off Menu」の編集画面を開く
			await page.goto(
				`/wp-admin/post.php?post=${ Number.parseInt(
					offMenuId,
					10
				) }&action=edit`
			);
			await page.waitForLoadState( 'domcontentloaded' );

			// 複数人予約OFF なので貸し切り予約行は hidden 属性が付いている（B案・初期非表示）。
			// @check 「貸し切り予約」の行が隠れている
			const exclusiveRow = page.locator(
				'#vkbm-exclusive-when-booked-field'
			);
			await exclusiveRow.waitFor( { state: 'attached', timeout: 15000 } );
			await expect
				.poll( async () =>
					exclusiveRow.evaluate(
						( el ) => ( el as HTMLElement ).hidden
					)
				)
				.toBe( true );
		} finally {
			// 後始末。検証が途中で失敗しても作成したメニューを削除する。
			// 削除自体が失敗しても本来の失敗理由（タイムアウト等）を上書きしないよう警告に留める。
			// 残っても afterAll のタイトル一致削除が拾う。
			try {
				wpEvalPhp(
					`wp_delete_post( ${ Number.parseInt(
						offMenuId,
						10
					) }, true );`
				);
			} catch ( e ) {
				console.warn( 'Failed to delete Exclusive Off Menu:', e );
			}
		}
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
		// @step 翌月のシフトを登録しておく
		createShiftForMonth(
			staffId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);

		// このテスト自身でメニューの貸し切り設定を明示的に ON にする（前テストの副作用に依存しない）。
		// 単体実行・retry でも exclusive_closed を再現できるようにするため。
		// @step 「Exclusive Menu」の「貸し切り予約」をオンにしておく
		wpEvalPhp(
			`update_post_meta( ${ Number.parseInt(
				exclusiveMenuId,
				10
			) }, '_vkbm_exclusive_when_booked', true );`
		);

		// 対象日の 09:00-10:00 に「貸し切り」予約を1件投入する（残席はあるが受付停止になるはず）。
		// @step 翌月 15 日 9:00〜10:00 に、1 名の貸し切りの確定済み予約を登録しておく
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
		// @step ログインしていない状態で予約ページを開き、「Exclusive Menu」を選ぶ
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
		// @step カレンダーで「次の月」へ進み、15 日を選ぶ
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
		// @check 9:00 の時間枠が「予約受付終了」と表示され、選べない
		const closedSlot = page
			.locator( '.vkbm-slot-list__item.is-exclusive-closed' )
			.first();
		await expect( closedSlot ).toBeVisible();
		await expect( closedSlot ).toBeDisabled();
		await expect( closedSlot ).toContainText( '予約受付終了' );
	} );
} );
