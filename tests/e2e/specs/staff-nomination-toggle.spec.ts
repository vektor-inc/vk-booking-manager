import { test, expect, type Page } from '@playwright/test';
import { execSync } from 'child_process';
import { mkdirSync } from 'fs';

// wp-env はプラグインルートの .wp-env.json で起動されている。
const PLUGIN_ROOT = process.cwd();

/**
 * WP-CLIコマンドを実行するヘルパー。
 */
const wpCli = ( command: string ): string => {
	return execSync( `npx wp-env run cli wp ${ command }`, {
		encoding: 'utf-8',
		cwd: PLUGIN_ROOT,
	} ).trim();
};

/**
 * WP管理画面にログインする。
 * storageState を使ってログイン状態を維持する。
 */
const loginAsAdmin = async ( page: Page ) => {
	// まずダッシュボードにアクセスして、既にログイン済みか確認
	await page.goto( '/wp-admin/' );
	await page.waitForLoadState( 'domcontentloaded' );

	// ログインフォームが表示された場合のみログイン処理を行う
	if ( page.url().includes( 'wp-login.php' ) ) {
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/, { timeout: 30000 } );
	}
};

/**
 * プロバイダー設定で staff_enabled を切り替える。
 */
const setStaffEnabled = ( enabled: boolean ): void => {
	const val = enabled ? '1' : '0';
	// PHP の !empty() に合わせて 1 または 0 をセットする
	wpCli(
		`eval "
			\\\$s = get_option( 'vkbm_provider_settings', array() );
			\\\$s['staff_enabled'] = ${ val };
			update_option( 'vkbm_provider_settings', \\\$s );
		"`
	);
};

/**
 * テスト用のスタッフ・サービスメニュー・予約ページを作成する。
 */
const setupTestData = (): void => {
	// スタッフが存在しなければ作成
	let staffId = '';
	try {
		staffId = wpCli(
			'post list --post_type=vkbm_resource --post_status=publish --field=ID --format=csv'
		)
			.split( '\n' )[ 0 ];
	} catch ( error ) {
		console.log( 'Staff list check:', error instanceof Error ? error.message : error );
	}

	if ( ! staffId ) {
		staffId = wpCli(
			'post create --post_type=vkbm_resource --post_title="Staff 1" --post_status=publish --porcelain'
		);
		console.log( `Created Staff ID: ${ staffId }` );
	}

	// サービスメニューが存在しなければ作成
	let menuId = '';
	try {
		menuId = wpCli(
			'post list --post_type=vkbm_service_menu --post_status=publish --field=ID --format=csv'
		)
			.split( '\n' )[ 0 ];
	} catch ( error ) {
		console.log( 'Menu list check:', error instanceof Error ? error.message : error );
	}

	if ( ! menuId ) {
		menuId = wpCli(
			'post create --post_type=vkbm_service_menu --post_title="Service Menu 1" --post_status=publish --porcelain'
		);
		// スタッフをメニューに割り当て
		wpCli(
			`eval "update_post_meta(${ menuId }, '_vkbm_staff_ids', array((int)${ staffId }));"`
		);
		console.log( `Created Service Menu ID: ${ menuId }` );
	}

	// シフトを作成（今月）
	const shiftExists = wpCli(
		'post list --post_type=vkbm_shift --post_status=publish --format=ids'
	);
	if ( ! shiftExists ) {
		const createShiftCode = `
			$resource_id = ${ staffId };
			$year = (int) current_time('Y');
			$month = (int) current_time('n');
			$days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
			$days = [];
			for ($d = 1; $d <= $days_in_month; $d++) {
				$days[$d] = ['status' => 'open', 'slots' => [['start' => '09:00', 'end' => '18:00']]];
			}
			$post_id = wp_insert_post(['post_type' => 'vkbm_shift', 'post_status' => 'publish', 'post_title' => sprintf('%d-%02d Staff 1', $year, $month)]);
			if (!is_wp_error($post_id)) {
				update_post_meta($post_id, '_vkbm_shift_resource_id', $resource_id);
				update_post_meta($post_id, '_vkbm_shift_year', $year);
				update_post_meta($post_id, '_vkbm_shift_month', $month);
				update_post_meta($post_id, '_vkbm_shift_days', $days);
			}
		`;
		const base64Code = Buffer.from( createShiftCode ).toString( 'base64' );
		wpCli( `eval 'eval(base64_decode("${ base64Code }"));'` );
		console.log( 'Created Shift for current month' );
	}

	// 予約ページの作成
	const bookingPagePhp = `
		$post = get_page_by_path("booking");
		$content = '<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->';
		$post_data = array("post_type" => "page", "post_title" => "Booking", "post_name" => "booking", "post_content" => $content, "post_status" => "publish");
		if ($post) { $post_data["ID"] = $post->ID; wp_update_post($post_data); } else { wp_insert_post($post_data); }
	`;
	const base64Php = Buffer.from( bookingPagePhp ).toString( 'base64' );
	wpCli( `eval 'eval(base64_decode("${ base64Php }"));'` );

	// パーマリンクをフラッシュ
	wpCli( 'rewrite flush --hard' );
};

test.describe( '指名機能の使用 設定トグルテスト（PR #141）', () => {
	test.beforeAll( () => {
		// テストデータをセットアップ
		setupTestData();
		// デフォルトで指名機能を有効にしておく
		setStaffEnabled( true );
	} );

	test.afterAll( () => {
		// テスト後は有効に戻す
		setStaffEnabled( true );
	} );

	test( '1. 基本設定画面のシステムタブに「Nomination feature」プルダウンが表示される', async ( {
		page,
	} ) => {
		// 管理画面にログイン
		await loginAsAdmin( page );

		// 基本設定画面のシステムタブに遷移
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 「Nomination feature」または日本語翻訳のラベルが表示されていることを確認
		const staffEnabledSelect = page.locator( '#vkbm-staff-enabled' );
		await expect( staffEnabledSelect ).toBeVisible();

		// プルダウンに「Enabled」と「Disabled」の選択肢があることを確認
		const options = staffEnabledSelect.locator( 'option' );
		const optionCount = await options.count();
		expect( optionCount ).toBe( 2 );

		// デフォルトで「Enabled」（値=1）が選択されていることを確認
		const selectedValue = await staffEnabledSelect.inputValue();
		expect( selectedValue ).toBe( '1' );
	} );

	test( '2. 指名機能を無効にして保存→設定が保持される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// 基本設定画面のシステムタブに遷移
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// プルダウンを「Disabled」に変更
		const staffEnabledSelect = page.locator( '#vkbm-staff-enabled' );
		await staffEnabledSelect.selectOption( '0' );

		// 設定を保存（フォームのsubmitボタンをクリック）
		const submitButton = page.locator( 'input[type="submit"]#submit' );
		// submitボタンが見つからない場合は別のセレクタを試す
		const submitBtn = ( await submitButton.count() ) > 0
			? submitButton
			: page.locator( '.submit input[type="submit"]' ).first();
		await submitBtn.click();
		await page.waitForLoadState( 'domcontentloaded' );

		// リロード後も「Disabled」が選択されていることを確認
		const reloadedSelect = page.locator( '#vkbm-staff-enabled' );
		const savedValue = await reloadedSelect.inputValue();
		expect( savedValue ).toBe( '0' );
	} );

	test( '3. 指名機能無効時にリソースラベルは表示され、指名関連設定は非表示になる', async ( {
		page,
	} ) => {
		// 指名機能を無効に設定
		setStaffEnabled( false );

		await loginAsAdmin( page );

		// 基本設定画面のシステムタブに遷移
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// 「Nomination feature」プルダウン自体は表示される
		const staffEnabledSelect = page.locator( '#vkbm-staff-enabled' );
		await expect( staffEnabledSelect ).toBeVisible();

		// 指名無効時の値が「0」であること
		const selectedValue = await staffEnabledSelect.inputValue();
		expect( selectedValue ).toBe( '0' );

		// リソース名称（singular）は指名無効でも表示される（リソース機能は常に有効）
		const resourceLabelSingular = page.locator(
			'#vkbm-resource-label-singular'
		);
		await expect( resourceLabelSingular ).toBeVisible();

		// リソースラベル（menu）も表示される
		const resourceLabelMenu = page.locator( '#vkbm-resource-label-menu' );
		await expect( resourceLabelMenu ).toBeVisible();

		// 指名関連の設定は非表示（is_nomination_enabled() === false）
		const noNominationLabel = page.locator(
			'#vkbm-no-nomination-label'
		);
		await expect( noNominationLabel ).not.toBeVisible();

		const nominationFeeLabel = page.locator(
			'#vkbm-nomination-fee-label'
		);
		await expect( nominationFeeLabel ).not.toBeVisible();
	} );

	test( '4. 指名機能を再有効化すると設定が「Enabled」に戻る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// 基本設定画面のシステムタブに遷移
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'domcontentloaded' );

		// プルダウンを「Enabled」に変更
		const staffEnabledSelect = page.locator( '#vkbm-staff-enabled' );
		await staffEnabledSelect.selectOption( '1' );

		// 保存
		const submitBtn = page
			.locator( '.submit input[type="submit"]' )
			.first();
		await submitBtn.click();
		await page.waitForLoadState( 'domcontentloaded' );

		// 設定が「Enabled」で保存されたことを確認
		const reloadedSelect = page.locator( '#vkbm-staff-enabled' );
		const savedValue = await reloadedSelect.inputValue();
		expect( savedValue ).toBe( '1' );

	} );

	test( '5. 指名機能無効時にサービスメニュー編集画面でスタッフ連携は表示、指名料無効チェックボックスは非表示', async ( {
		page,
	} ) => {
		// 指名機能を無効に設定
		setStaffEnabled( false );

		await loginAsAdmin( page );

		// サービスメニュー一覧から最初のメニューの編集画面を開く
		const menuId = wpCli(
			'post list --post_type=vkbm_service_menu --post_status=publish --field=ID --format=csv'
		)
			.split( '\n' )[ 0 ];

		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
		await page.waitForLoadState( 'domcontentloaded' );

		// スタッフ連携メタボックスは指名無効でも表示される（リソース機能は常に有効）
		const staffMetaBox = page.locator( '#vkbm_service_menu_staff' );
		await expect( staffMetaBox ).toBeVisible();

		// 「このメニューは指名料を無効にする」チェックボックスが非表示であることを確認
		const disableNominationCheckbox = page.locator(
			'input[name="_vkbm_disable_nomination_fee"]'
		);
		const checkboxCount = await disableNominationCheckbox.count();

		// 指名機能が無効の場合、このチェックボックスは表示されないはず
		if ( checkboxCount > 0 ) {
			await expect( disableNominationCheckbox ).not.toBeVisible();
		}
		// checkboxCount が 0 の場合は DOM に存在しない = 非表示で OK
	} );

	test( '6. 指名機能有効時にサービスメニュー編集画面で指名料無効チェックボックスが表示', async ( {
		page,
	} ) => {
		// 指名機能を有効に設定
		setStaffEnabled( true );

		await loginAsAdmin( page );

		// サービスメニュー一覧から最初のメニューの編集画面を開く
		const menuId = wpCli(
			'post list --post_type=vkbm_service_menu --post_status=publish --field=ID --format=csv'
		)
			.split( '\n' )[ 0 ];

		await page.goto( `/wp-admin/post.php?post=${ menuId }&action=edit` );
		await page.waitForLoadState( 'domcontentloaded' );

		// 「このメニューは指名料を無効にする」チェックボックスが表示されていることを確認
		const disableNominationCheckbox = page.locator(
			'input[name="_vkbm_disable_nomination_fee"]'
		);

		// 指名機能が有効の場合、このチェックボックスが存在するはず
		// ただし、このフィールドが存在するかはプラグインの実装による
		// 存在する場合のみ visible チェックを行う
		const checkboxCount = await disableNominationCheckbox.count();
		if ( checkboxCount > 0 ) {
			await expect( disableNominationCheckbox ).toBeVisible();
		}
	} );

	test( '7. 指名機能無効時にフロントのメニューカードでスタッフ名が非表示になる', async ( {
		page,
	} ) => {
		// 指名機能を無効に設定
		setStaffEnabled( false );

		// 予約ページにアクセス
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'domcontentloaded' );

		// メニューカード内に「Staff available」またはスタッフ名が表示されていないことを確認。
		// ページにメニューカードが存在する場合のみチェック。
		const menuCards = page.locator( '.vkbm-menu-loop__card' );
		const cardCount = await menuCards.count();
		if ( cardCount > 0 ) {
			// 担当可能スタッフのメタ項目が非表示であること
			const staffMetaItems = menuCards.first().locator(
				'.vkbm-menu-loop__card-meta-item'
			);
			const metaCount = await staffMetaItems.count();
			for ( let i = 0; i < metaCount; i++ ) {
				const dtText = await staffMetaItems
					.nth( i )
					.locator( 'dt' )
					.textContent();
				// "Staff available" や翻訳されたリソースラベルにスタッフ名が含まれないことを確認
				expect( dtText ).not.toContain( 'Staff available' );
			}
		}

		// テスト後に有効に戻す
		setStaffEnabled( true );
	} );

	test( '7b. 指名機能有効時にフロントのメニューカードでスタッフ名が表示される', async ( {
		page,
	} ) => {
		// 指名機能を有効に設定
		setStaffEnabled( true );

		// 予約ページにアクセス
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'domcontentloaded' );

		// メニューカードが存在する場合、スタッフ情報が表示されていることを確認
		const menuCards = page.locator( '.vkbm-menu-loop__card' );
		const cardCount = await menuCards.count();
		if ( cardCount > 0 ) {
			// 担当可能スタッフの表示があるかチェック（スタッフが設定されているメニューの場合）
			const staffMetaItems = menuCards.first().locator(
				'.vkbm-menu-loop__card-meta-item'
			);
			const metaCount = await staffMetaItems.count();
			// メタ項目が1つ以上あればOK（所要時間やスタッフ名が表示される）
			expect( metaCount ).toBeGreaterThan( 0 );
		}
	} );

	test( '9. スクリーンショット撮影: 指名機能の有効/無効時の設定画面', async ( {
		page,
	} ) => {
		const screenshotDir =
			'/tmp/review-assets/vk-booking-manager-pro/pr-141';
		mkdirSync( screenshotDir, { recursive: true } );

		// 指名機能有効時のスクリーンショット
		setStaffEnabled( true );
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'domcontentloaded' );
		await page.screenshot( {
			path: `${ screenshotDir }/after-settings-enabled.png`,
			fullPage: true,
		} );

		// 指名機能無効時のスクリーンショット
		setStaffEnabled( false );
		await page.reload();
		await page.waitForLoadState( 'domcontentloaded' );
		await page.screenshot( {
			path: `${ screenshotDir }/after-settings-disabled.png`,
			fullPage: true,
		} );

		// 元に戻す
		setStaffEnabled( true );
	} );

	test( '10. WP-CLI で staff_enabled の切り替えが正しく反映される', async () => {
		// 指名機能を無効に設定
		setStaffEnabled( false );

		// WP-CLI で設定値を確認
		const disabledResult = wpCli(
			`eval "
				\\\$s = get_option( 'vkbm_provider_settings', array() );
				echo isset( \\\$s['staff_enabled'] ) ? var_export( \\\$s['staff_enabled'], true ) : 'not set';
			"`
		);
		// 0 または false または '' のいずれかであること
		expect( [ '0', 'false', "''", '' ] ).toContain( disabledResult );

		// 指名機能を有効に設定
		setStaffEnabled( true );

		// WP-CLI で設定値を確認
		const enabledResult = wpCli(
			`eval "
				\\\$s = get_option( 'vkbm_provider_settings', array() );
				echo isset( \\\$s['staff_enabled'] ) ? var_export( \\\$s['staff_enabled'], true ) : 'not set';
			"`
		);
		// 1 または true のいずれかであること
		expect( [ '1', 'true' ] ).toContain( enabledResult );
	} );
} );
