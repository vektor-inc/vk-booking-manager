import { test, expect, type Page } from '@playwright/test';
import { wpCliArgs, wpEvalPhp, loginAsAdmin } from '../utils/helpers';

/**
 * 予約の個人情報の自動匿名化（issue #873 / Pro 版のみ）の管理画面 e2e テスト。
 *
 * 検証する範囲:
 * - 高度な設定タブに「予約の個人情報の自動匿名化」の行があり、初期値は「自動で匿名化しない」。
 * - 初期値では定期処理（vkbm_booking_auto_anonymize_daily）が何も匿名化しない。
 * - 期間を選んで保存すると確認ダイアログが出て、キャンセルすれば保存されない。
 * - 保存後の定期処理で、期限を過ぎた予約だけが匿名化される（編集画面に注記が出る）。
 * - 期間を短くしたときだけ確認ダイアログが出る（長くするときは出ない）。
 * - 「自動で匿名化しない」へ戻すと、定期処理は何もしない。
 * - 一括操作「個人情報を匿名化」の成功通知に、自動匿名化の設定へのリンクが出る（回帰）。
 * - 高度な設定の他の項目（シフトの自動登録・プラグイン削除時の片付け）が保存できる（回帰）。
 *
 * 実行例（検証用 wp-env を 9136 で起動した場合）:
 *   WP_ENV_TESTS_CONFIG=.wp-env.json WP_BASE_URL=http://localhost:9136 \
 *   npx playwright test tests/e2e/specs/issue-873-auto-anonymize.spec.ts
 * SHOT_DIR を指定すると、確認用のスクリーンショットをそのディレクトリへ保存する。
 */

const CRON_HOOK = 'vkbm_booking_auto_anonymize_daily';
const TITLE_PREFIX = 'E2E873 ダミー予約';
const SETTINGS_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=advanced';
const SELECT = '#vkbm-booking-auto-anonymize-months';

const ids: Record< 'A' | 'B' | 'C' | 'D', string > = {
	A: '',
	B: '',
	C: '',
	D: '',
};

/**
 * SHOT_DIR が指定されているときだけスクリーンショットを保存する。
 *
 * @param page     Playwright の Page。
 * @param name     保存するファイル名（拡張子なし）。
 * @param fullPage ページ全体を撮るか。
 */
async function shot( page: Page, name: string, fullPage = false ) {
	const dir = process.env.SHOT_DIR;
	if ( ! dir ) {
		return;
	}
	await page.screenshot( { path: `${ dir }/${ name }.png`, fullPage } );
}

/**
 * 自動匿名化の設定値を指定し、実行記録を消す。
 *
 * @param months 設定する期間（月数。0 は「しない」）。
 */
function resetSetting( months: number ) {
	wpEvalPhp( `
		$s = get_option( 'vkbm_provider_settings', array() );
		$s['booking_auto_anonymize_months'] = ${ Number( months ) };
		update_option( 'vkbm_provider_settings', $s );
		delete_option( 'vkbm_booking_auto_anonymize_state' );
	` );
}

/**
 * 定期処理を実行する。
 * 予定が登録されていない（自動匿名化が「しない」の）ときは `wp cron event run` が
 * 「Invalid cron event」で失敗するため、同じフックを直接実行して「何もしないこと」を確かめる。
 */
function runCron() {
	const scheduled = wpEvalPhp(
		`echo wp_next_scheduled( '${ CRON_HOOK }' ) ? 'yes' : 'no';`
	);
	if ( scheduled === 'yes' ) {
		wpCliArgs( [ 'cron', 'event', 'run', CRON_HOOK ] );
	} else {
		wpEvalPhp( `do_action( '${ CRON_HOOK }' );` );
	}
}

/**
 * 予約が匿名化済みかを返す（匿名化日時のメタの有無）。
 *
 * @param id 予約の投稿 ID（数値文字列）。
 */
function isAnonymized( id: string ): boolean {
	return (
		wpEvalPhp(
			`echo get_post_meta( ${ Number(
				id
			) }, '_vkbm_booking_anonymized_at', true ) ? 'yes' : 'no';`
		) === 'yes'
	);
}

/**
 * 設定画面で期間を選んで「変更を保存」を押す（確認ダイアログが出る場合はそのまま残す）。
 *
 * @param page   Playwright の Page。
 * @param months 選ぶ期間の option の値（月数）。
 */
async function chooseAndSave( page: Page, months: string ) {
	await page.goto( SETTINGS_URL );
	await page.locator( SELECT ).selectOption( months );
	await page.locator( '#submit' ).click();
}

test.describe( 'issue #873 予約の個人情報の自動匿名化', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async () => {
		// @step 前回の検証データを消し、終了日時が 4 か月前・1 か月前・明日・4 か月前（後で一括操作に使う）のダミー予約を用意する
		resetSetting( 0 );
		const out = wpEvalPhp( `
			foreach ( get_posts( array( 'post_type' => 'vkbm_booking', 'post_status' => 'any', 'numberposts' => -1, 's' => '${ TITLE_PREFIX }' ) ) as $p ) {
				wp_delete_post( $p->ID, true );
			}
			$defs = array( 'A' => '-4 months', 'B' => '-1 month', 'C' => '+1 day', 'D' => '-4 months' );
			foreach ( $defs as $k => $rel ) {
				$ts    = strtotime( $rel, current_time( 'timestamp' ) );
				$end   = wp_date( 'Y-m-d H:i:s', $ts );
				$start = wp_date( 'Y-m-d H:i:s', $ts - HOUR_IN_SECONDS );
				$id    = wp_insert_post( array( 'post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_title' => '${ TITLE_PREFIX }' . $k ) );
				update_post_meta( $id, '_vkbm_booking_service_start', $start );
				update_post_meta( $id, '_vkbm_booking_service_end', $end );
				update_post_meta( $id, '_vkbm_booking_total_end', $end );
				update_post_meta( $id, '_vkbm_booking_customer_name', 'ダミー太郎' . $k );
				update_post_meta( $id, '_vkbm_booking_customer_email', 'dummy' . strtolower( $k ) . '@example.com' );
				update_post_meta( $id, '_vkbm_booking_status', 'confirmed' );
				echo $k . '=' . $id . ';';
			}
		` );
		for ( const m of out.matchAll( /([ABCD])=(\d+);/g ) ) {
			ids[ m[ 1 ] as 'A' | 'B' | 'C' | 'D' ] = m[ 2 ];
		}
		expect( ids.A && ids.B && ids.C && ids.D ).toBeTruthy();
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	// @title 高度な設定に自動匿名化の行がある
	test( 'setting row exists with default value', async ( { page } ) => {
		// @step BM 設定の「高度な設定」タブを開く
		await page.goto( SETTINGS_URL );
		const row = page.locator( 'tr', { has: page.locator( SELECT ) } );
		// @check 「自動で匿名化しない」が選ばれた自動匿名化の行が、シフトの自動登録とプラグイン削除時の片付けの間にある
		await expect( row ).toBeVisible();
		await expect( page.locator( SELECT ) ).toHaveValue( '0' );
		const order = await page.evaluate( ( sel ) => {
			const rows = Array.from(
				document.querySelectorAll(
					'tr.vkbm-provider-settings__tab-advanced'
				)
			);
			const idx = rows.findIndex( ( r ) => r.querySelector( sel ) );
			return {
				idx,
				prev: rows[ idx - 1 ]?.querySelector( 'select' )?.id || '',
				next: rows[ idx + 1 ]?.querySelector( 'select' )?.id || '',
			};
		}, SELECT );
		expect( order.prev ).toContain( 'auto-register' );
		expect( order.next ).toBe( 'vkbm-uninstall-cleanup' );
		// @check 説明文のメール設定リンクを押すとメールログ保存期間の行へ移動する
		await shot( page, 'after-01-settings-default', true );
		await row.locator( 'a[href*="tab=email"]' ).click();
		await expect( page ).toHaveURL(
			/tab=email.*#vkbm-email-log-retention-days/
		);
	} );

	// @title 初期値では定期処理が何も匿名化しない
	test( 'cron does nothing with default setting', async () => {
		// @step 自動匿名化を「しない」のまま、定期処理を実行する
		runCron();
		// @check どの予約も匿名化済みにならない
		expect( isAnonymized( ids.A ) ).toBe( false );
		expect( isAnonymized( ids.B ) ).toBe( false );
		expect( isAnonymized( ids.C ) ).toBe( false );
	} );

	// @title 期間を選ぶと確認ダイアログが出てキャンセルできる
	test( 'enable shows confirm dialog and cancel does not save', async ( {
		page,
	} ) => {
		// @step 「終了から3か月後」を選んで「変更を保存」を押す
		await chooseAndSave( page, '3' );
		// @check 有効化の確認ダイアログが出て、初期フォーカスは「キャンセル」にある
		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( '3' );
		const focusedText = await page.evaluate(
			() => document.activeElement?.textContent?.trim() || ''
		);
		expect( focusedText ).toMatch( /キャンセル|Cancel/ );
		await shot( page, 'after-02-confirm-dialog-enable' );
		// @step 「キャンセル」を押す
		await dialog
			.getByRole( 'button', { name: /キャンセル|Cancel/ } )
			.click();
		// @check 保存されず、設定は「自動で匿名化しない」のまま
		await expect( dialog ).toBeHidden();
		await page.goto( SETTINGS_URL );
		await expect( page.locator( SELECT ) ).toHaveValue( '0' );
	} );

	// @title 確認して保存すると実行状況が表示される
	test( 'confirm saves and shows run status', async ( { page } ) => {
		// @step 「終了から3か月後」を選んで保存し、確認ダイアログで「この設定で保存」を押す
		await chooseAndSave( page, '3' );
		await page
			.getByRole( 'alertdialog' )
			.getByRole( 'button' )
			.last()
			.click();
		await page.waitForURL( /settings-updated|tab=advanced/ );
		// @check 設定が保存され、実行状況の行に「まだ実行していません」と次回の実行予定が出る
		await expect( page.locator( SELECT ) ).toHaveValue( '3' );
		const statusRow = page.locator( 'tr', { has: page.locator( SELECT ) } );
		await expect( statusRow ).toContainText(
			/まだ実行していません|Not run yet/
		);
		await shot( page, 'after-03-settings-saved-not-run', true );
		expect(
			wpEvalPhp(
				`echo wp_next_scheduled( '${ CRON_HOOK }' ) ? 'scheduled' : 'none';`
			)
		).toBe( 'scheduled' );
	} );

	// @title 期限を過ぎた予約だけが定期処理で匿名化される
	test( 'cron anonymizes only expired bookings', async ( { page } ) => {
		// @step 定期処理を実行する
		runCron();
		// @check 4 か月前に終了した予約だけが匿名化済みになり、1 か月前・明日の予約は残る
		expect( isAnonymized( ids.A ) ).toBe( true );
		expect( isAnonymized( ids.D ) ).toBe( true );
		expect( isAnonymized( ids.B ) ).toBe( false );
		expect( isAnonymized( ids.C ) ).toBe( false );
		// @step 匿名化された予約の編集画面を開く
		await page.goto( `/wp-admin/post.php?post=${ ids.A }&action=edit` );
		// @check 「自動匿名化の設定による」旨の注記が出て、予約の日時は残っている
		const notice = page.locator( 'body' );
		await expect( notice ).toContainText(
			/自動匿名化の設定による|automatic anonymization/i
		);
		await shot( page, 'after-04-booking-edit-auto-anonymized' );
		const kept = wpEvalPhp(
			`echo get_post_meta( ${ Number(
				ids.A
			) }, '_vkbm_booking_service_end', true ) ? 'kept' : 'lost';`
		);
		expect( kept ).toBe( 'kept' );
		// @step 高度な設定タブに戻る
		await page.goto( SETTINGS_URL );
		// @check 実行状況の行に前回の実行日時と「2件」の匿名化件数が出る
		const statusRow = page.locator( 'tr', { has: page.locator( SELECT ) } );
		await expect( statusRow ).toContainText( /前回の実行|Last run/ );
		// 件数の表示部分（「（2件を匿名化）」/ "(2 anonymized)"）だけを検証する。
		// 実行日時の年（2026 など）の数字には一致しない。
		await expect( statusRow ).toContainText(
			/（2件を匿名化）|\(2 anonymized\)/
		);
		await shot( page, 'after-05-settings-last-run', true );
	} );

	// @title 期間を短くしたときだけ確認ダイアログが出る
	test( 'shortening shows dialog, lengthening does not', async ( {
		page,
	} ) => {
		// @step 「終了から3か月後」から「終了から1年後」へ長くして保存する
		await chooseAndSave( page, '12' );
		// @check 確認ダイアログは出ずにそのまま保存される
		await page.waitForURL( /settings-updated|tab=advanced/ );
		await expect( page.getByRole( 'alertdialog' ) ).toHaveCount( 0 );
		await expect( page.locator( SELECT ) ).toHaveValue( '12' );
		// @step 「終了から1年後」から「終了から3か月後」へ短くして保存する
		await page.locator( SELECT ).selectOption( '3' );
		await page.locator( '#submit' ).click();
		// @check 「期間を短くしますか」の確認ダイアログが出る
		const dialog = page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( /短く|shorten/i );
		await shot( page, 'after-06-confirm-dialog-shorten' );
		await dialog
			.getByRole( 'button', { name: /キャンセル|Cancel/ } )
			.click();
	} );

	// @title 「自動で匿名化しない」に戻すと定期処理が何もしない
	test( 'cron does nothing after turning off', async ( { page } ) => {
		// @step 予約Bの終了日時を 4 か月前に変えてから、「自動で匿名化しない」にして保存する
		wpEvalPhp( `
			$end = wp_date( 'Y-m-d H:i:s', strtotime( '-4 months', current_time( 'timestamp' ) ) );
			update_post_meta( ${ Number( ids.B ) }, '_vkbm_booking_service_end', $end );
			update_post_meta( ${ Number( ids.B ) }, '_vkbm_booking_total_end', $end );
		` );
		await page.goto( SETTINGS_URL );
		await page.locator( SELECT ).selectOption( '0' );
		await page.locator( '#submit' ).click();
		await page.waitForURL( /settings-updated|tab=advanced/ );
		await expect( page.locator( SELECT ) ).toHaveValue( '0' );
		// @step 定期処理を実行する
		runCron();
		// @check 予約Bは 4 か月前でも匿名化されない
		expect( isAnonymized( ids.B ) ).toBe( false );
	} );

	// @title 一括操作の成功通知に自動匿名化の案内が出る
	test( 'bulk anonymize notice has link to setting', async ( { page } ) => {
		// @step 予約一覧で終了済みの予約Bを選び、一括操作「個人情報を匿名化」を実行する
		await page.goto(
			`/wp-admin/edit.php?post_type=vkbm_booking&s=${ encodeURIComponent(
				TITLE_PREFIX + 'B'
			) }`
		);
		await page.locator( `#cb-select-${ ids.B }` ).check();
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'vkbm_anonymize' );
		await page.locator( '#doaction' ).click();
		const dialog = page.getByRole( 'alertdialog' );
		if ( await dialog.isVisible().catch( () => false ) ) {
			await shot( page, 'after-07-bulk-confirm-dialog' );
			await dialog.getByRole( 'button' ).last().click();
		}
		// @check 予約Bが匿名化され、通知の末尾に自動匿名化の設定へのリンクが出る
		const notice = page
			.locator( '#message, .notice' )
			.filter( { hasText: /匿名化|anonymiz/i } )
			.first();
		await expect( notice ).toBeVisible();
		expect( isAnonymized( ids.B ) ).toBe( true );
		const link = notice.locator(
			'a[href*="tab=advanced"][href*="vkbm-booking-auto-anonymize-months"]'
		);
		await expect( link ).toBeVisible();
		await shot( page, 'after-08-bulk-notice' );
		// @step 通知のリンクを押す
		await link.click();
		// @check 高度な設定の自動匿名化の行へ移動する
		await expect( page.locator( SELECT ) ).toBeVisible();
	} );

	// @title 高度な設定の他の項目が保存できる
	test( 'other advanced settings still save', async ( { page } ) => {
		// @step 「プラグイン削除時の片付け」の値を変えて保存する
		await page.goto( SETTINGS_URL );
		const cleanup = page.locator( '#vkbm-uninstall-cleanup' );
		const options = await cleanup
			.locator( 'option' )
			.evaluateAll( ( els ) =>
				els.map( ( e ) => ( e as HTMLOptionElement ).value )
			);
		const before = await cleanup.inputValue();
		const target = options.find( ( v ) => v !== before ) as string;
		await cleanup.selectOption( target );
		const autoRegister = page
			.locator( 'select[id*="auto-register"]' )
			.first();
		const arOptions = await autoRegister
			.locator( 'option' )
			.evaluateAll( ( els ) =>
				els.map( ( e ) => ( e as HTMLOptionElement ).value )
			);
		const arBefore = await autoRegister.inputValue();
		const arTarget = arOptions.find( ( v ) => v !== arBefore ) as string;
		await autoRegister.selectOption( arTarget );
		await page.locator( '#submit' ).click();
		await page.waitForURL( /settings-updated|tab=advanced/ );
		// @check 変えた値が保存され、自動匿名化の設定は「自動で匿名化しない」のまま変わらない
		await expect( page.locator( '#vkbm-uninstall-cleanup' ) ).toHaveValue(
			target
		);
		await expect(
			page.locator( 'select[id*="auto-register"]' ).first()
		).toHaveValue( arTarget );
		await expect( page.locator( SELECT ) ).toHaveValue( '0' );
		// 元の値へ戻す
		await page.locator( '#vkbm-uninstall-cleanup' ).selectOption( before );
		await page
			.locator( 'select[id*="auto-register"]' )
			.first()
			.selectOption( arBefore );
		await page.locator( '#submit' ).click();
		await page.waitForURL( /settings-updated|tab=advanced/ );
	} );

	test.afterAll( async () => {
		// 後処理: 設定を初期値へ戻し、ダミー予約を削除する
		resetSetting( 0 );
		wpEvalPhp( `
			foreach ( get_posts( array( 'post_type' => 'vkbm_booking', 'post_status' => 'any', 'numberposts' => -1, 's' => '${ TITLE_PREFIX }' ) ) as $p ) {
				wp_delete_post( $p->ID, true );
			}
		` );
	} );
} );
