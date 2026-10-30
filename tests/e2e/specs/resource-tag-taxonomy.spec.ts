import { test, expect } from '@playwright/test';
import {
	wpCli,
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
	resolvePluginSlug,
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
		// プラグインスラッグは wp-env のマウント元ディレクトリ名に一致するため、
		// CI（vk-booking-manager-pro）と worktree（agent-xxxx 等）で異なる場合がある。
		// 動的に解決することで両環境で確実に動作させる。
		// The plugin slug equals the wp-env mounted directory name, which differs
		// between CI (vk-booking-manager-pro) and worktrees (agent-xxxx).
		// Resolve it dynamically so both environments work correctly.
		// @step 予約プラグインを有効にし、パーマリンクを「投稿名」にしておく
		const pluginSlug = resolvePluginSlug();
		try {
			wpCliArgs( [ 'plugin', 'is-active', pluginSlug ] );
		} catch {
			wpCliArgs( [ 'plugin', 'activate', pluginSlug ] );
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
		// @step テスト用のスタッフを1人用意し、そのスタッフのリソースタグを外して、基本設定でタグの表示を有効にしておく
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
		// @step 管理者でログインし、リソースタグの一覧画面を開く
		await loginAsAdmin( page );
		await page.goto(
			'/wp-admin/edit-tags.php?taxonomy=vkbm_resource_tag&post_type=vkbm_resource'
		);
		// @check 一覧に「male」と「female」のタグが表示されている
		const pageContent = await page.textContent( 'body' );
		expect( pageContent ).toContain( 'male' );
		expect( pageContent ).toContain( 'female' );
	} );

	test( '2. スタッフ編集画面にリソースタグメタボックスが表示される', async ( {
		page,
	} ) => {
		// @step 管理者でログインし、スタッフの編集画面を開く
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ staffId }&action=edit` );
		// WordPress標準のタグUI（メタボックスタイトルに "Resource Tag" が含まれる）
		// WordPress default tag UI (metabox title contains "Resource Tag").
		// @check リソースタグを付けるボックスが表示されている
		const metabox = page.locator( '#tagsdiv-vkbm_resource_tag' );
		await expect( metabox ).toBeVisible( { timeout: 10000 } );
	} );

	test( '3. タグ付き表示名が正しく生成される（単一タグ）', async () => {
		// @step スタッフにリソースタグ「male」を付けておく
		setResourceTags( staffId, [ 'male' ] );
		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		// @check 予約画面などに出るスタッフの表示名が「スタッフ名 (Male)」の形になる
		const displayName = wpEvalPhp(
			`echo vkbm_get_resource_display_name( ${ staffId } );`
		);
		expect( displayName ).toContain( staffName );
		expect( displayName ).toContain( '(' );
		expect( displayName ).toContain( 'Male' );
	} );

	test( '4. タグ未設定時はスタッフ名のみ返す', async () => {
		// @step スタッフのリソースタグをすべて外しておく
		clearResourceTags( staffId );
		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		// @check スタッフの表示名がスタッフ名だけになり、括弧の付いたタグ名が付かない
		const displayName = wpEvalPhp(
			`echo vkbm_get_resource_display_name( ${ staffId } );`
		);
		expect( displayName ).toBe( staffName );
		expect( displayName ).not.toContain( '(' );
	} );

	test( '5. 表示設定OFFの場合、タグがあってもスタッフ名のみ返す', async () => {
		// @step スタッフにリソースタグ「male」を付け、基本設定でタグの表示を無効にしておく
		setResourceTags( staffId, [ 'male' ] );
		setTagDisplayEnabled( false );
		// execFileSync 化後は shell の引用符展開が効かないため wpEvalPhp 経由で PHP コードを渡す。
		// Pass PHP code via wpEvalPhp because shell-quoted `eval "..."` no longer works after the execFileSync migration.
		// @check スタッフの表示名がスタッフ名だけになり、括弧の付いたタグ名が付かない
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
		// @step スタッフにリソースタグ「male」を付けて、そのスタッフの公開データ（REST API）を開く
		setResourceTags( staffId, [ 'male' ] );
		const response = await request.get(
			`/wp-json/wp/v2/vkbm_resource/${ staffId }`
		);
		// @check 公開データにリソースタグの項目があり、「Male」が入っている
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
		// @step スタッフのリソースタグをすべて外して、そのスタッフの公開データ（REST API）を開く
		clearResourceTags( staffId );
		const response = await request.get(
			`/wp-json/wp/v2/vkbm_resource/${ staffId }`
		);
		// @check 公開データのリソースタグの項目が空になっている
		expect( response.ok() ).toBeTruthy();
		const data = await response.json();
		expect( data.resource_tags ).toEqual( [] );
	} );

	// #431: 絞り込みはタグ名ではなくターム ID で照合する仕様のため、
	// resource_tag_ids は表示設定（resource_tag_display_enabled）に関わらず常に返す必要がある。
	test( '13. REST API に resource_tag_ids フィールドが含まれ、表示設定に関わらずターム ID を返す', async ( {
		request,
	} ) => {
		// @step スタッフにリソースタグ「male」を付け、基本設定でタグの表示を無効にしておく
		setResourceTags( staffId, [ 'male' ] );
		// 「表示」設定をOFFにしても resource_tag_ids は絞り込み専用のため空にならないことを確認する。
		setTagDisplayEnabled( false );

		// @step そのスタッフの公開データ（REST API）を開く
		const response = await request.get(
			`/wp-json/wp/v2/vkbm_resource/${ staffId }`
		);
		// @check 表示を無効にしていても、公開データに絞り込み用のタグ ID の項目があり、数値の ID が入っている
		expect( response.ok() ).toBeTruthy();
		const data = await response.json();
		expect( data ).toHaveProperty( 'resource_tag_ids' );
		expect( Array.isArray( data.resource_tag_ids ) ).toBe( true );
		expect( data.resource_tag_ids.length ).toBeGreaterThan( 0 );
		expect(
			data.resource_tag_ids.every( ( id: unknown ) =>
				Number.isInteger( id )
			)
		).toBe( true );

		// 元に戻す（後続テストへの影響を避ける）。
		setTagDisplayEnabled( true );
	} );
} );
