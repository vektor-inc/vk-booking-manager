import { test, expect, type Page } from '@playwright/test';
import { execSync } from 'child_process';

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
 * wp_set_object_terms() でスタッフにリソースタグを設定する。
 */
const setResourceTags = ( postId: string, slugs: string[] ): void => {
	const slugArray = slugs.map( ( s ) => `'${ s }'` ).join( ', ' );
	wpCli(
		`eval "wp_set_object_terms( ${ postId }, array( ${ slugArray } ), 'vkbm_resource_tag' );"`
	);
};

/**
 * スタッフのリソースタグをクリアする。
 */
const clearResourceTags = ( postId: string ): void => {
	wpCli(
		`eval "wp_set_object_terms( ${ postId }, array(), 'vkbm_resource_tag' );"`
	);
};

/**
 * プロバイダー設定で resource_tag_display_enabled を切り替える。
 */
const setTagDisplayEnabled = ( enabled: boolean ): void => {
	const val = enabled ? 'true' : 'false';
	wpCli(
		`eval "
			\\\$s = get_option( 'vkbm_provider_settings', array() );
			\\\$s['resource_tag_display_enabled'] = ${ val };
			update_option( 'vkbm_provider_settings', \\\$s );
		"`
	);
};

/**
 * WP管理画面にログインする。
 */
const loginAsAdmin = async ( page: Page ) => {
	await page.goto( '/wp-login.php' );
	await page.waitForLoadState( 'domcontentloaded' );
	const userLogin = page.locator( '#user_login' );
	await userLogin.click();
	await userLogin.fill( 'admin' );
	const userPass = page.locator( '#user_pass' );
	await userPass.click();
	await userPass.fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/, { timeout: 30000 } );
};

test.describe( 'リソースタグ追加テスト（PR #119 追加確認項目）', () => {
	let staffId: string;
	let staffName: string;

	test.beforeAll( () => {
		// プラグインが有効であることを確認
		try {
			wpCli( 'plugin is-active vk-booking-manager-pro' );
		} catch {
			wpCli( 'plugin activate vk-booking-manager-pro' );
		}

		// スタッフIDと名前を取得
		const staffIdResult = wpCli(
			'post list --post_type=vkbm_resource --post_status=publish --field=ID --format=csv'
		).split( '\n' )[ 0 ];
		if ( ! staffIdResult ) {
			throw new Error(
				'テスト用スタッフが存在しません。wp-env 上にスタッフ投稿を作成してください。'
			);
		}
		staffId = staffIdResult;
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
		await page.goto( '/wp-admin/admin.php?page=vkbm-provider-settings&tab=system' );
		await page.waitForLoadState( 'domcontentloaded' );

		// 「Resource tag display」ラベルが表示されていることを確認
		// （旧ラベル「Resource pulldown」ではないこと）
		const pageContent = await page.textContent( 'body' );
		expect( pageContent ).toContain( 'Resource tag display' );

		// 旧ラベル「Resource pulldown」が残っていないことを確認
		expect( pageContent ).not.toContain( 'Resource pulldown' );
	} );

	test( '9. プロバイダー設定画面に表示例（Example）が表示される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// Systemタブへ直接遷移
		await page.goto( '/wp-admin/admin.php?page=vkbm-provider-settings&tab=system' );
		await page.waitForLoadState( 'domcontentloaded' );

		// 説明文にプレビュー例が表示されていることを確認
		const exampleCode = page.locator( 'code' ).filter( { hasText: /Hanako Yamada/ } );
		await expect( exampleCode ).toBeVisible( { timeout: 10000 } );
	} );

	test( '10. 複数タグが設定されたスタッフの表示名にカンマ区切りで表示される', async () => {
		// ベテランタームがなければ作成（既に存在する場合はスキップ）
		try {
			wpCli( "term create vkbm_resource_tag Veteran --slug=veteran" );
		} catch {
			// 既に存在する場合はエラーを無視
		}

		// 複数タグを設定
		setResourceTags( staffId, [ 'female', 'veteran' ] );
		setTagDisplayEnabled( true );

		const displayName = wpCli(
			`eval "echo vkbm_get_resource_display_name( ${ staffId } );"`
		);

		// スタッフ名が含まれる
		expect( displayName ).toContain( staffName );
		// 括弧がある
		expect( displayName ).toContain( '(' );
		// Female と Veteran の両方が含まれる
		expect( displayName ).toContain( 'Female' );
		expect( displayName ).toContain( 'Veteran' );
		// カンマ区切り
		expect( displayName ).toMatch( /Female.*,.*Veteran|Veteran.*,.*Female/ );
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

		// カラムヘッダーに「Resource Tag」または「リソースタグ」が表示されていることを確認
		const headerRow = page.locator( 'thead tr' );
		const headerText = await headerRow.textContent();
		// タクソノミーカラムは taxonomy-vkbm_resource_tag というIDで追加される
		const tagColumn = page.locator( 'th#taxonomy-vkbm_resource_tag, td.taxonomy-vkbm_resource_tag' );
		const tagColumnCount = await tagColumn.count();
		expect( tagColumnCount ).toBeGreaterThan( 0 );
	} );
} );
