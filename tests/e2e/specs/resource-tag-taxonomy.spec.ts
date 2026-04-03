import { test, expect, type Page } from '@playwright/test';
import { execSync } from 'child_process';

// wp-env はプラグインルートの .wp-env.json で起動されている。
// cwd はそのままプラグインルートを使う。
const PLUGIN_ROOT = process.cwd();

/**
 * WP-CLIコマンドを実行するヘルパー。
 * Helper to run WP-CLI commands.
 */
const wpCli = ( command: string ): string => {
	return execSync( `npx wp-env run cli wp ${ command }`, {
		encoding: 'utf-8',
		cwd: PLUGIN_ROOT,
	} ).trim();
};

/**
 * wp_set_object_terms() でスタッフにリソースタグを設定する。
 * Set resource tags on a staff post via wp_set_object_terms().
 */
const setResourceTags = ( postId: string, slugs: string[] ): void => {
	const slugArray = slugs.map( ( s ) => `'${ s }'` ).join( ', ' );
	wpCli(
		`eval "wp_set_object_terms( ${ postId }, array( ${ slugArray } ), 'vkbm_resource_tag' );"`
	);
};

/**
 * スタッフのリソースタグをクリアする。
 * Clear resource tags from a staff post.
 */
const clearResourceTags = ( postId: string ): void => {
	wpCli(
		`eval "wp_set_object_terms( ${ postId }, array(), 'vkbm_resource_tag' );"`
	);
};

/**
 * プロバイダー設定で resource_tag_display_enabled を切り替える。
 * Toggle the resource_tag_display_enabled provider setting.
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
 * Log in to the WP admin.
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

test.describe( 'リソースタグタクソノミー機能', () => {
	let staffId: string;
	let staffName: string;

	test.beforeAll( () => {
		// プラグインが有効であることを確認
		// Ensure the plugin is active.
		try {
			wpCli( 'plugin is-active vk-booking-manager-pro' );
		} catch {
			wpCli( 'plugin activate vk-booking-manager-pro' );
		}

		// パーマリンク設定（REST API の /wp-json/ パスに必要）
		// Set permalink structure (required for REST API /wp-json/ path).
		try {
			wpCli( "rewrite structure '/%postname%/' --hard" );
		} catch {
			// 既に設定済みの場合は無視 / Ignore if already set.
		}

		// スタッフIDと名前を取得
		// Retrieve staff ID and name.
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
		if ( ! staffName ) {
			throw new Error(
				`スタッフID ${ staffId } のタイトル取得に失敗しました。テストデータを確認してください。`
			);
		}

		// テスト開始前にリソースタグをクリアし、表示を有効化
		// Clear resource tags and enable display before tests.
		clearResourceTags( staffId );
		setTagDisplayEnabled( true );
	} );

	test.afterAll( () => {
		if ( staffId ) {
			clearResourceTags( staffId );
		}
		setTagDisplayEnabled( false );
	} );

	test( '1. デフォルトのリソースタグタームが自動作成されている', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/edit-tags.php?taxonomy=vkbm_resource_tag&post_type=vkbm_resource'
		);
		const pageContent = await page.textContent( 'body' );
		expect( pageContent ).toContain( 'male' );
		expect( pageContent ).toContain( 'female' );
	} );

	test( '2. スタッフ編集画面にリソースタグメタボックスが表示される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ staffId }&action=edit`
		);
		// WordPress標準のタグUI（メタボックスタイトルに "Resource Tag" が含まれる）
		// WordPress default tag UI (metabox title contains "Resource Tag").
		const metabox = page.locator( '#tagsdiv-vkbm_resource_tag' );
		await expect( metabox ).toBeVisible( { timeout: 10000 } );
	} );

	test( '3. タグ付き表示名が正しく生成される（単一タグ）', async () => {
		setResourceTags( staffId, [ 'male' ] );
		const displayName = wpCli(
			`eval "echo vkbm_get_resource_display_name( ${ staffId } );"`
		);
		expect( displayName ).toContain( staffName );
		expect( displayName ).toContain( '(' );
		expect( displayName ).toContain( 'Male' );
	} );

	test( '4. タグ未設定時はスタッフ名のみ返す', async () => {
		clearResourceTags( staffId );
		const displayName = wpCli(
			`eval "echo vkbm_get_resource_display_name( ${ staffId } );"`
		);
		expect( displayName ).toBe( staffName );
		expect( displayName ).not.toContain( '(' );
	} );

	test( '5. 表示設定OFFの場合、タグがあってもスタッフ名のみ返す', async () => {
		setResourceTags( staffId, [ 'male' ] );
		setTagDisplayEnabled( false );
		const displayName = wpCli(
			`eval "echo vkbm_get_resource_display_name( ${ staffId } );"`
		);
		expect( displayName ).toBe( staffName );
		expect( displayName ).not.toContain( '(' );
		// 元に戻す / Restore.
		setTagDisplayEnabled( true );
	} );

	test( '6. REST API に resource_tags フィールドが含まれる', async ( {
		request,
	} ) => {
		setResourceTags( staffId, [ 'male' ] );
		const response = await request.get(
			`/wp-json/wp/v2/vkbm_resource/${ staffId }`
		);
		expect( response.ok() ).toBeTruthy();
		const data = await response.json();
		expect( data ).toHaveProperty( 'resource_tags' );
		expect( Array.isArray( data.resource_tags ) ).toBe( true );
		expect( data.resource_tags.length ).toBeGreaterThan( 0 );
		expect( data.resource_tags ).toContain( 'Male' );
	} );

	test( '7. REST API でタグ未設定時に resource_tags が空配列', async ( {
		request,
	} ) => {
		clearResourceTags( staffId );
		const response = await request.get(
			`/wp-json/wp/v2/vkbm_resource/${ staffId }`
		);
		expect( response.ok() ).toBeTruthy();
		const data = await response.json();
		expect( data.resource_tags ).toEqual( [] );
	} );

} );
