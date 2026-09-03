import { test, expect } from '@playwright/test';
import {
	wpCli,
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
	setStaffEnabled,
	resolvePluginSlug,
} from '../utils/helpers';
import {
	setResourceTags,
	clearResourceTags,
	setTagDisplayEnabled,
} from '../utils/resource-tag-helpers';

test.describe( 'リソースタグ追加テスト（PR #119 追加確認項目）', () => {
	let staffId: string;
	let staffName: string;

	test.beforeAll( () => {
		// プラグインが有効であることを確認
		// Ensure the plugin is active.
		// プラグインスラッグは wp-env のマウント元ディレクトリ名に一致するため、
		// CI（vk-booking-manager-pro）と worktree（agent-xxxx 等）で異なる場合がある。
		// 動的に解決することで両環境で確実に動作させる。
		// The plugin slug equals the wp-env mounted directory name, which differs
		// between CI (vk-booking-manager-pro) and worktrees (agent-xxxx).
		// Resolve it dynamically so both environments work correctly.
		const pluginSlug = resolvePluginSlug();
		try {
			wpCliArgs( [ 'plugin', 'is-active', pluginSlug ] );
		} catch {
			wpCliArgs( [ 'plugin', 'activate', pluginSlug ] );
		}

		// 前のテスト（staff-nomination-toggle 等）で staff_enabled が無効にされている可能性があるため、
		// 明示的に有効化する（リソースタグ表示設定は Staff_Editor::is_enabled() が true の場合のみ表示）
		// Ensure staff_enabled is true — the resource tag settings section requires Staff_Editor::is_enabled()
		setStaffEnabled( true );

		// スタッフIDと名前を取得
		// Retrieve staff ID and name.
		staffId = getStaffId();
		if ( ! staffId ) {
			throw new Error(
				'テスト用スタッフが存在しません。wp-env 上にスタッフ投稿を作成してください。'
			);
		}
		staffName = wpCli( `post get ${ staffId } --field=post_title` );
	} );

	test.afterAll( () => {
		if ( staffId ) {
			clearResourceTags( staffId );
		}
		setTagDisplayEnabled( false );
	} );

	test( '8. プロバイダー設定画面に「Resource tag display」ラベルが表示される', async ( {
		page,
	} ) => {
		// 管理画面にログイン
		await loginAsAdmin( page );

		// プロバイダー設定画面のSystemタブへ直接遷移（URLパラメータで tab=system を指定）
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'networkidle' );

		// ロケール非依存: チェックボックスの ID セレクタでリソースタグ表示設定が存在することを確認
		// Locale-independent: verify by checkbox ID that the resource tag display setting exists
		const resourceTagCheckbox = page.locator(
			'#vkbm-resource-tag-display-enabled'
		);
		await expect( resourceTagCheckbox ).toBeVisible( { timeout: 10000 } );

		// 「Resource tag display」または日本語翻訳「リソースタグ表示」ラベルが表示されていることを確認
		// （CI環境では wp site switch-language ja により日本語ロケールで動作するため両方を許容する）
		const pageContent = await page.textContent( 'body' );
		const hasEnglishLabel = pageContent?.includes( 'Resource tag display' );
		const hasJapaneseLabel = pageContent?.includes( 'リソースタグ表示' );
		expect( hasEnglishLabel || hasJapaneseLabel ).toBeTruthy();

		// 旧ラベル「Resource pulldown」が残っていないことを確認
		expect( pageContent ).not.toContain( 'Resource pulldown' );
	} );

	test( '9. プロバイダー設定画面に表示例（Example）が表示される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// Systemタブへ直接遷移
		await page.goto(
			'/wp-admin/admin.php?page=vkbm-provider-settings&tab=system'
		);
		await page.waitForLoadState( 'networkidle' );

		// リソースタグ表示設定のチェックボックスが読み込まれていることを確認
		// Wait for the resource tag checkbox to ensure the section is loaded
		await expect(
			page.locator( '#vkbm-resource-tag-display-enabled' )
		).toBeVisible( { timeout: 10000 } );

		// 説明文にプレビュー例が表示されていることを確認
		// 英語: "Hanako Yamada ( Female, Veteran )" / 日本語: "山田花子 ( 女性, ベテラン )"
		// CI環境では日本語ロケールで動作するため両方を許容する
		const exampleCodeEn = page
			.locator( 'code' )
			.filter( { hasText: /Hanako Yamada/ } );
		const exampleCodeJa = page
			.locator( 'code' )
			.filter( { hasText: /山田花子/ } );
		const enCount = await exampleCodeEn.count();
		const jaCount = await exampleCodeJa.count();
		expect( enCount + jaCount ).toBeGreaterThan( 0 );
	} );

	test( '10. 複数タグが設定されたスタッフの表示名にカンマ区切りで表示される', async () => {
		// ベテランタームがなければ作成（既に存在する場合はスキップ）
		// Create "Veteran" term if it doesn't exist
		try {
			wpCli( 'term create vkbm_resource_tag Veteran --slug=veteran' );
		} catch {
			// 既に存在する場合はエラーを無視
		}

		// 複数タグを設定
		// Set multiple tags
		setResourceTags( staffId, [ 'female', 'veteran' ] );
		setTagDisplayEnabled( true );

		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		const displayName = wpEvalPhp(
			`echo vkbm_get_resource_display_name( ${ staffId } );`
		);

		// スタッフ名が含まれる
		expect( displayName ).toContain( staffName );
		// 括弧がある
		expect( displayName ).toContain( '(' );
		// Female と Veteran の両方が含まれる
		expect( displayName ).toContain( 'Female' );
		expect( displayName ).toContain( 'Veteran' );
		// カンマ区切り
		expect( displayName ).toMatch(
			/Female.*,.*Veteran|Veteran.*,.*Female/
		);
	} );

	test( '11. REST API で表示設定OFFの場合に resource_tags が空配列になる', async ( {
		request,
	} ) => {
		// タグを設定しておく
		setResourceTags( staffId, [ 'male' ] );
		// 表示をOFFにする
		setTagDisplayEnabled( false );

		const response = await request.get(
			`/wp-json/wp/v2/vkbm_resource/${ staffId }`
		);
		expect( response.ok() ).toBeTruthy();
		const data = await response.json();
		// 表示OFFの場合、resource_tags は空配列であるべき
		expect( data.resource_tags ).toEqual( [] );
	} );

	test( '12. スタッフ一覧画面に「リソースタグ」列が表示される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/edit.php?post_type=vkbm_resource' );
		await page.waitForLoadState( 'domcontentloaded' );

		// タクソノミーカラムは taxonomy-vkbm_resource_tag というIDで追加される
		// The taxonomy column has ID taxonomy-vkbm_resource_tag
		const tagColumn = page.locator(
			'th#taxonomy-vkbm_resource_tag, td.taxonomy-vkbm_resource_tag'
		);
		const tagColumnCount = await tagColumn.count();
		expect( tagColumnCount ).toBeGreaterThan( 0 );
	} );
} );
