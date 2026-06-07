import { test, expect } from '@playwright/test';
import {
	wpCli,
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
} from '../utils/helpers';
import {
	setResourceTags,
	clearResourceTags,
	setTagDisplayEnabled,
} from '../utils/resource-tag-helpers';

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
		// execFileSync 化に伴いシェルのシングルクォート展開が効かないため、
		// パーマリンク構造はクォートなしの引数として直接 wpCliArgs に渡す。
		// Since execFileSync no longer relies on a shell, pass the permalink
		// structure as an unquoted argument via wpCliArgs.
		try {
			wpCliArgs( [ 'rewrite', 'structure', '/%postname%/', '--hard' ] );
		} catch {
			// 既に設定済みの場合は無視 / Ignore if already set.
		}

		// スタッフIDと名前を取得
		// Retrieve staff ID and name.
		staffId = getStaffId();
		if ( ! staffId ) {
			throw new Error(
				'テスト用スタッフが存在しません。wp-env 上にスタッフ投稿を作成してください。'
			);
		}
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
		await page.goto( `/wp-admin/post.php?post=${ staffId }&action=edit` );
		// WordPress標準のタグUI（メタボックスタイトルに "Resource Tag" が含まれる）
		// WordPress default tag UI (metabox title contains "Resource Tag").
		const metabox = page.locator( '#tagsdiv-vkbm_resource_tag' );
		await expect( metabox ).toBeVisible( { timeout: 10000 } );
	} );

	test( '3. タグ付き表示名が正しく生成される（単一タグ）', async () => {
		setResourceTags( staffId, [ 'male' ] );
		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		const displayName = wpEvalPhp(
			`echo vkbm_get_resource_display_name( ${ staffId } );`
		);
		expect( displayName ).toContain( staffName );
		expect( displayName ).toContain( '(' );
		expect( displayName ).toContain( 'Male' );
	} );

	test( '4. タグ未設定時はスタッフ名のみ返す', async () => {
		clearResourceTags( staffId );
		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		const displayName = wpEvalPhp(
			`echo vkbm_get_resource_display_name( ${ staffId } );`
		);
		expect( displayName ).toBe( staffName );
		expect( displayName ).not.toContain( '(' );
	} );

	test( '5. 表示設定OFFの場合、タグがあってもスタッフ名のみ返す', async () => {
		setResourceTags( staffId, [ 'male' ] );
		setTagDisplayEnabled( false );
		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		const displayName = wpEvalPhp(
			`echo vkbm_get_resource_display_name( ${ staffId } );`
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
