import { test, expect } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import {
	wpCli,
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
	setStaffEnabled,
	getStaffEnabled,
	resolvePluginSlug,
	createShiftForMonth,
	getCurrentAndNextTokyoMonths,
} from '../utils/helpers';
import {
	setResourceTags,
	clearResourceTags,
	setTagDisplayEnabled,
	setResourceTagSearchEnabled,
	getOrCreateResourceTagId,
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

/**
 * issue #431: リソースタグでの絞り込み → 指名なし予約 → 割り当てがタグ保持リソースであることの検証。
 *
 * 完了条件の核心（「そのリソースタグを持たない担当は絶対に割り当てられない」）は、
 * 空き枠計算（Availability_Service::resolve_staff_ids()）が候補リソースをタグで絞り込んだ
 * 結果（assignable_staff_ids）に現れる。指名なし（自動割当）の空き枠 REST
 * （/vkbm/v1/availabilities）にリソースタグIDを渡し、返る assignable_staff_ids に
 * タグを持たないリソースが一切含まれないことを検証する。
 *
 * ブラウザ経由の実予約確定（ログイン＋登録フロー）は、同種の検証を行う
 * issue-392-nomination-slot-capacity.spec.ts と同じ理由でこの spec のスコープ外とする
 * （認証済み REST 呼び出しの構築は本体機能とは別の複雑さを持ち込むため）。
 * 予約確定時の最終チェック（409）・予約メタ保存はサーバー側 PHPUnit
 * （tests/phpunit/bookings/test-booking-resource-tag-confirmation.php）で検証済み。
 */
test.describe( 'issue #431: リソースタグでの絞り込み（指名なし予約の自動割当候補）', () => {
	const MENU_TITLE = 'Resource Tag Filter E2E Menu';
	const STAFF_TAGGED_TITLE = 'Resource Tag Filter E2E Staff Tagged';
	const STAFF_UNTAGGED_TITLE = 'Resource Tag Filter E2E Staff Untagged';

	let originalStaffEnabled = true;
	let menuId = '';
	let staffTaggedId = '';
	let staffUntaggedId = '';
	let tagId = '';

	/**
	 * 検証用スタッフを1名作成する（同名スタッフがあれば作り直す。冪等）。
	 *
	 * @param title スタッフ投稿タイトル。
	 * @return 作成したスタッフの post ID（数値文字列）。
	 */
	function seedStaff( title: string ): string {
		const phpCode = `
			$existing = get_posts( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'any',
				'title'       => '${ title }',
				'fields'      => 'ids',
				'numberposts' => -1,
			) );
			foreach ( $existing as $eid ) {
				wp_delete_post( $eid, true );
			}
			$staff_id = wp_insert_post( array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
				'post_title'  => '${ title }',
			) );
			if ( is_wp_error( $staff_id ) || ! $staff_id ) {
				echo 'Error: failed to create staff';
				return;
			}
			echo $staff_id;
		`;
		const result = wpEvalPhp( phpCode ).trim();
		if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
			throw new Error(
				`Staff seeding failed ("${ title }"): "${ result }"`
			);
		}
		return result;
	}

	/**
	 * 検証用メニュー（指名なし＝自動割当。両スタッフを担当リソースに割り当て）を作成する。
	 *
	 * @return 作成したサービスメニューの post ID（数値文字列）。
	 */
	function seedMenu(): string {
		const phpCode = `
			$staff_tagged = ${ Number.parseInt( staffTaggedId, 10 ) };
			$staff_untagged = ${ Number.parseInt( staffUntaggedId, 10 ) };

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
				echo 'Error: failed to create menu';
				return;
			}
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( (int) $staff_tagged, (int) $staff_untagged ) );
			update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
			update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
			echo $menu_id;
		`;
		const result = wpEvalPhp( phpCode ).trim();
		if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
			throw new Error( `Menu seeding failed: "${ result }"` );
		}
		return result;
	}

	/**
	 * availability REST（/vkbm/v1/availabilities）を叩き、指定日・タグ条件の
	 * slots 配列を返すヘルパー（issue-392-nomination-slot-capacity.spec.ts の
	 * getDailySlots() と同じ手法。resource_id は指定せず「指名なし」で取得する）。
	 *
	 * @param request Playwright の APIRequestContext。
	 * @param dateStr 対象日（YYYY-MM-DD）。
	 * @param tagIds  絞り込むリソースタグのターム ID配列（数値文字列）。空配列は絞り込みなし。
	 */
	async function getDailySlotsFilteredByTag(
		request: APIRequestContext,
		dateStr: string,
		tagIds: string[]
	): Promise< Array< Record< string, unknown > > > {
		const tagParam = tagIds.length
			? `&resource_tag_ids=${ tagIds.join( ',' ) }`
			: '';
		const response = await request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ dateStr }${ tagParam }&timezone=Asia%2FTokyo&nocache=${ Date.now() }`
		);
		expect( response.status() ).toBe( 200 );
		const data = await response.json();
		return Array.isArray( data ) ? data : data.slots || [];
	}

	test.beforeAll( () => {
		const pluginSlug = resolvePluginSlug();
		try {
			wpCliArgs( [ 'plugin', 'is-active', pluginSlug ] );
		} catch {
			wpCliArgs( [ 'plugin', 'activate', pluginSlug ] );
		}

		originalStaffEnabled = getStaffEnabled();
		// 「指名なし予約」のシナリオを検証するため、サイト全体の指名機能をOFFにする
		// （issue #431 完了条件: タグ検索は指名機能の ON/OFF に関係なく機能する）。
		setStaffEnabled( false );

		staffTaggedId = seedStaff( STAFF_TAGGED_TITLE );
		staffUntaggedId = seedStaff( STAFF_UNTAGGED_TITLE );
		tagId = getOrCreateResourceTagId( 'e2e-431-female', 'E2E 431 Female' );
		setResourceTags( staffTaggedId, [ 'e2e-431-female' ] );
		clearResourceTags( staffUntaggedId );

		menuId = seedMenu();
		setResourceTagSearchEnabled( true );

		// 来月分のシフトを両スタッフぶん作成しておく（今月分だと実行日によっては
		// 予約締切・シフト日数の関係で確認できる日が無くなるため、既存 spec と同じ方針で来月を使う）。
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		createShiftForMonth(
			staffTaggedId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);
		createShiftForMonth(
			staffUntaggedId,
			nextTokyoMonth.year,
			nextTokyoMonth.month
		);
	} );

	test.afterAll( () => {
		if ( menuId ) {
			wpCliArgs( [ 'post', 'delete', menuId, '--force' ], {
				stdio: 'ignore',
			} );
			menuId = '';
		}
		if ( staffTaggedId ) {
			wpCliArgs( [ 'post', 'delete', staffTaggedId, '--force' ], {
				stdio: 'ignore',
			} );
			staffTaggedId = '';
		}
		if ( staffUntaggedId ) {
			wpCliArgs( [ 'post', 'delete', staffUntaggedId, '--force' ], {
				stdio: 'ignore',
			} );
			staffUntaggedId = '';
		}
		setResourceTagSearchEnabled( false );
		setStaffEnabled( originalStaffEnabled );
	} );

	test( 'REST: 指名なし予約の空き枠は、リソースタグで絞り込むとタグを持たないリソースが割当候補から外れる', async ( {
		request,
	} ) => {
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		const year = String( nextTokyoMonth.year );
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		const dateStr = `${ year }-${ month }-15`;

		// タグ未指定 => 両スタッフが自動割当の候補になる（従来どおり）。
		const slotsWithoutTag = await getDailySlotsFilteredByTag(
			request,
			dateStr,
			[]
		);
		const nineOClockWithoutTag = slotsWithoutTag.find( ( slot ) =>
			String( slot.start_at ).includes( 'T09:00:00' )
		);
		expect( nineOClockWithoutTag ).toBeDefined();
		const candidatesWithoutTag =
			( nineOClockWithoutTag?.assignable_staff_ids ?? [] ) as number[];
		expect( candidatesWithoutTag ).toEqual(
			expect.arrayContaining( [
				Number( staffTaggedId ),
				Number( staffUntaggedId ),
			] )
		);

		// タグを指定 => 「タグを持たないリソースは絶対に割り当てられない」ことの検証。
		// 完了条件どおり、割当候補（assignable_staff_ids）からタグ非保持スタッフが除外される。
		const slotsWithTag = await getDailySlotsFilteredByTag(
			request,
			dateStr,
			[ tagId ]
		);
		const nineOClockWithTag = slotsWithTag.find( ( slot ) =>
			String( slot.start_at ).includes( 'T09:00:00' )
		);
		expect( nineOClockWithTag ).toBeDefined();
		const candidatesWithTag = ( nineOClockWithTag?.assignable_staff_ids ??
			[] ) as number[];
		expect( candidatesWithTag ).toContain( Number( staffTaggedId ) );
		expect( candidatesWithTag ).not.toContain( Number( staffUntaggedId ) );
	} );

	test( 'REST: 存在しない条件でタグ絞り込みすると、その日の空き枠が0件になる（該当リソース0件・境界値）', async ( {
		request,
	} ) => {
		const nextTokyoMonth = getCurrentAndNextTokyoMonths()[ 1 ];
		const year = String( nextTokyoMonth.year );
		const month = String( nextTokyoMonth.month ).padStart( 2, '0' );
		// 前のテストと日付が競合しないよう別日（16日）を使う。
		const dateStr = `${ year }-${ month }-16`;

		// どのスタッフも持たないタームID（存在しないタームID）を指定する。
		const nonExistentTagId = '999999';
		const response = await request.get(
			`/wp-json/vkbm/v1/availabilities?menu_id=${ menuId }&date=${ dateStr }&resource_tag_ids=${ nonExistentTagId }&timezone=Asia%2FTokyo&nocache=${ Date.now() }`
		);

		// resolve_staff_ids() が resource_tag_no_match エラーを返すため、
		// 空き枠APIは（一般訪問者向けにマスクされた）エラー応答になる。
		expect( response.status() ).toBeGreaterThanOrEqual( 400 );
	} );
} );
