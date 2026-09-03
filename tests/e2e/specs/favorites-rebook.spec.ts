import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	getStaffId,
	getTokyoDateParts,
	createShiftForMonth,
} from '../utils/helpers';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

/**
 * Issue #90: 予約履歴からの「同じ内容で予約する」とお気に入り（いつもの）機能の E2E。
 *
 * 検証内容:
 * - 予約確認画面に「次回のために、この内容をお気に入りに登録する」チェックボックスがあり、
 *   ON のまま確定すると予約完了画面に登録結果が表示される。
 * - 登録後、予約フォーム上部に「いつもの」クイック選択が表示される。
 * - マイページの予約一覧に「同じ内容で予約する」ボタンが表示され、クリックすると
 *   予約フォーム（カレンダー表示）が開く。
 * - 予約フォーム編集中には登録ボタン（旧仕様）が表示されない。
 */
test.describe( 'Issue #90: favorites (いつもの) and rebook flow', () => {
	const password = 'TestPassword123!';
	// グローバルセットアップの user_* クリーンアップ対象に合わせた接頭辞を使う。
	const username = `user_fav_${ Date.now() }`;

	// テスト用の一般ユーザー（subscriber）を作成する。お気に入りはログイン必須のため。
	test.beforeAll( async () => {
		wpCliArgs( [
			'user',
			'create',
			username,
			`${ username }@example.com`,
			'--role=subscriber',
			`--user_pass=${ password }`,
			'--porcelain',
		] );
	} );

	// 各テストは独自にログインするため、保存済みのログイン状態は使わない。
	test.use( { storageState: { cookies: [], origins: [] } } );

	/**
	 * wp-login.php 経由で作成済みユーザーとしてログインする。
	 *
	 * @param page Playwright ページ。
	 */
	const login = async ( page: Page ) => {
		// baseURL 未設定でも動くよう WP_BASE_URL を明示する（他の遷移と統一）。
		await page.goto( `${ WP_BASE_URL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );
	};

	/**
	 * 予約フォームでメニュー→日付→枠を選び、予約確認画面まで進める。
	 *
	 * @param page Playwright ページ。
	 */
	const proceedToConfirm = async ( page: Page ) => {
		await page.goto( `${ WP_BASE_URL }/booking/` );
		await page.waitForLoadState( 'networkidle' );

		// メニュー選択（新スタイル→旧スタイルの順でフォールバック）。
		const reserveButton = page.locator(
			'.vkbm-menu-loop__button--reserve'
		);
		if ( await reserveButton.count() ) {
			await reserveButton.first().click();
		} else {
			await page.locator( '.vkbm-service-menu-card' ).first().click();
		}

		// カレンダー表示を待つ。
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );
		// 空き枠ロード完了後にのみ付与される「空きあり」日（--available）を対象にする。
		// ロード前は全日が未 disabled になりレースで掴んでしまうため、available を待つ。
		const availableDays = page.locator(
			'.vkbm-calendar__day--available:not(:disabled)'
		);
		await expect
			.poll( async () => availableDays.count(), { timeout: 15000 } )
			.toBeGreaterThan( 0 );
		await availableDays.first().click();

		// 日付選択後、時間枠リストが表示される（非空になる）のを待つ。
		const slots = page.locator( '.vkbm-slot-list__item' );
		await expect
			.poll( async () => slots.count(), { timeout: 10000 } )
			.toBeGreaterThan( 0 );
		await slots.first().click();

		// この予約で選択中のメニュー／指名スタッフの値を控える（お気に入り復元の厳密照合に使う）。
		// 単一スタッフ時もスタッフ欄は disabled な <select>（value=staffId）でレンダリングされる。
		const selectors = page.locator(
			'.vkbm-plan-summary__selectors select'
		);
		const savedMenuId = await selectors.nth( 0 ).inputValue();
		const savedStaffId =
			( await selectors.count() ) > 1
				? await selectors.nth( 1 ).inputValue()
				: '';

		// 枠選択後、「予約内容を確認」ボタンが操作可能になるのを待つ。
		const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
		await expect( planAction ).toBeEnabled( { timeout: 10000 } );
		await planAction.click();

		// 予約確認画面（予約内容サマリー）が表示されるのを待つ。
		await expect(
			page.locator( '.vkbm-confirm__summary' ).first()
		).toBeVisible( { timeout: 15000 } );

		return { savedMenuId, savedStaffId };
	};

	test( 'confirm-screen favorite opt-in saves a favorite and enables rebook', async ( {
		page,
	} ) => {
		await login( page );
		// 予約時に選択したメニュー／指名スタッフの値を控える（復元検証で厳密照合する）。
		const { savedMenuId, savedStaffId } = await proceedToConfirm( page );
		expect( Number( savedMenuId ) ).toBeGreaterThan( 0 );

		// 同意チェック（キャンセルポリシー・利用規約）。
		const cancelPolicy = page.locator(
			'#vkbm-confirm-cancellation-policy'
		);
		if ( await cancelPolicy.isVisible().catch( () => false ) ) {
			await cancelPolicy.check();
		}
		const terms = page.locator( '#vkbm-confirm-terms' );
		if ( await terms.isVisible().catch( () => false ) ) {
			await terms.check();
		}

		// お気に入り登録のチェックボックス（本機能）が表示され、ON にできること。
		const favoriteOptIn = page.locator( '#vkbm-confirm-favorite-optin' );
		await expect( favoriteOptIn ).toBeVisible();
		await favoriteOptIn.check();

		// 予約を確定。
		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();

		// 完了メッセージと、お気に入り登録結果が表示されること。
		// 本確定（「予約が完了しました。」）と仮予約（「仮予約が完了しました。」）の
		// 双方に一致させる。英語ロケールでは "...reservation has been completed." で
		// 確定・仮予約のどちらも拾えるようにする。
		await expect(
			page.getByText( '予約が完了しました', { exact: false } ).or(
				page.getByText( 'reservation has been completed', {
					exact: false,
				} )
			)
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page
				.getByText( 'お気に入りに登録しました', { exact: false } )
				.or( page.getByText( 'Added to favorites', { exact: false } ) )
		).toBeVisible( { timeout: 10000 } );

		// 予約フォームを開き直すと「いつもの」クイック選択が表示されること。
		await page.goto( `${ WP_BASE_URL }/booking/` );
		await page.waitForLoadState( 'networkidle' );
		// テストユーザーは毎回新規作成で隔離されるため、お気に入りはこのテストで保存した
		// 1件だけになる。件数を厳密に確認することで .first() の順序依存を排除する。
		const favoriteChips = page.locator( '.vkbm-favorites__apply' );
		await expect( favoriteChips ).toHaveCount( 1, { timeout: 10000 } );
		const favoriteApply = favoriteChips.first();
		await expect( favoriteApply ).toBeVisible();

		// クリックするとメニュー・指名スタッフがフォームに反映されること（本機能の中核）。
		await favoriteApply.click();
		// メニュー選択済みビュー（プランサマリー＋カレンダー）に切り替わること。
		await expect(
			page.locator( '.vkbm-plan-summary' ).first()
		).toBeVisible( { timeout: 10000 } );
		await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
			timeout: 10000,
		} );
		// 保存したお気に入りと「同じメニュー」が同じコントロールに復元されること（厳密一致）。
		const applySelectors = page.locator(
			'.vkbm-plan-summary__selectors select'
		);
		await expect
			.poll( async () => applySelectors.nth( 0 ).inputValue(), {
				timeout: 10000,
			} )
			.toBe( savedMenuId );
		// 指名スタッフも同じ値で復元されること（指名ありの予約のみ）。
		if ( Number( savedStaffId ) > 0 ) {
			await expect
				.poll( async () => applySelectors.nth( 1 ).inputValue(), {
					timeout: 10000,
				} )
				.toBe( savedStaffId );
		}

		// マイページ（予約一覧）に「同じ内容で予約する」ボタンが表示されること。
		await page.goto( `${ WP_BASE_URL }/booking/?vkbm_auth=bookings` );
		await page.waitForLoadState( 'networkidle' );
		const rebookButton = page
			.locator( '.vkbm-confirm__rebook-button' )
			.first();
		await expect( rebookButton ).toBeVisible( { timeout: 10000 } );

		// 再予約リンクの href（同じメニュー・指名を復元するクエリ）を控えておく。
		const rebookHref = await rebookButton.getAttribute( 'href' );
		const rebookParams = new URL( rebookHref || '', WP_BASE_URL )
			.searchParams;

		// クリックすると予約フォーム（カレンダー＋プランサマリー）が開くこと。
		await rebookButton.click();
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( '.vkbm-calendar' ) ).toBeVisible( {
			timeout: 10000,
		} );
		// メニュー選択済みビューが表示され、メニュー一覧へ戻っていないこと。
		await expect(
			page.locator( '.vkbm-plan-summary' ).first()
		).toBeVisible( { timeout: 10000 } );

		// 遷移後 URL が再予約リンクと同じ初期選択（メニュー・指名）を保持していること。
		// menu_id は必須。resource_id は予約に指名があった場合のみ含まれるため、
		// リンク側の値（指名なしなら null）と一致することを確認する。
		const params = new URL( page.url() ).searchParams;
		expect( params.get( 'menu_id' ) ).toBeTruthy();
		expect( params.get( 'menu_id' ) ).toBe( rebookParams.get( 'menu_id' ) );
		expect( params.get( 'resource_id' ) ).toBe(
			rebookParams.get( 'resource_id' )
		);
	} );

	test( 'no favorite-register button is shown while editing the form', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( `${ WP_BASE_URL }/booking/` );
		await page.waitForLoadState( 'networkidle' );

		// メニューを選択してフォーム編集状態にする。
		const reserveButton = page.locator(
			'.vkbm-menu-loop__button--reserve'
		);
		if ( await reserveButton.count() ) {
			await reserveButton.first().click();
		} else {
			await page.locator( '.vkbm-service-menu-card' ).first().click();
		}
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );

		// 旧仕様のフォーム内「お気に入りに登録」ボタンが存在しないこと。
		await expect(
			page.locator( '.vkbm-favorites__add-button' )
		).toHaveCount( 0 );
	} );
} );

/**
 * Issue #90（レビュー指摘 #299）: 指名なしの引き継ぎ検証。
 *
 * 既存テストは URL パラメータの一致のみを見ており、フォーム上の実際のスタッフ
 * 選択状態までは検証していなかった。ここでは「指名なし」の意図が実際に
 * フォームへ反映されるかを、スタッフ選択 <select> の値で直接検証する。
 *
 * - 不具合1: 指名なしのお気に入りを適用しても、直前に選んでいたスタッフが
 *   残ってしまう（新メニューでも対応可能なスタッフだと handleMenuChange が維持するため）。
 *   → applyFavorite で指名なし時に必ず setStaffId(0) するのが期待動作。
 * - 不具合2: 「同じ内容で予約する」等の menu_id 付きリンク（resource_id なし）で開くと、
 *   ブロックのデフォルトスタッフにフォールバックしてしまう。
 *   → menu_id 明示時はデフォルトスタッフへフォールバックしないのが期待動作。
 *
 * どちらも「複数の対応スタッフを持つメニュー」で初めて再現するため、
 * この describe 専用に 2 人目のスタッフと複数スタッフメニューを用意する。
 */
test.describe( 'Issue #90: no-nomination is reflected on the form', () => {
	const password = 'TestPassword123!';
	// グローバルセットアップの user_* クリーンアップ対象に合わせた接頭辞を使う。
	const username = `user_fav_nonom_${ Date.now() }`;

	// このテストで生成したデータの ID を控えて、afterAll で確実に後片付けする。
	let staff1Id = '';
	let staff2Id = '';
	let multiStaffMenuId = '';
	let dedicatedPageId = '';
	// WP が実際に採番した slug を控える。同名 slug が既に存在すると WP が
	// 自動で連番サフィックス（booking-nonom-2 等）を付けるため、固定 slug で
	// 遷移すると別（古い）ページを開いてしまう。実 slug で遷移して取り違えを防ぐ。
	let dedicatedPageSlug = '';

	// ログインは各テストで個別に行うため、保存済みログイン状態は使わない。
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async () => {
		// 既存（グローバルセットアップ由来）のスタッフ 1 名を再利用する。
		staff1Id = getStaffId();

		// 2 人目のスタッフを作成し、当月シフトを付与する（空き枠が出るように）。
		staff2Id = wpCliArgs( [
			'post',
			'create',
			'--post_type=vkbm_resource',
			'--post_title=Staff Nonom 2',
			'--post_status=publish',
			'--porcelain',
		] );
		if ( ! /^\d+$/.test( staff2Id ) ) {
			throw new Error( `Invalid staff2Id: "${ staff2Id }"` );
		}
		const now = getTokyoDateParts( new Date() );
		createShiftForMonth(
			staff2Id,
			Number( now.year ),
			Number( now.month )
		);

		// 対応スタッフを 2 名持つメニューを作成する（＝「指名なし」が選べる状態）。
		multiStaffMenuId = wpCliArgs( [
			'post',
			'create',
			'--post_type=vkbm_service_menu',
			'--post_title=Nonom Multi-Staff Menu',
			'--post_status=publish',
			'--porcelain',
		] );
		if ( ! /^\d+$/.test( multiStaffMenuId ) ) {
			throw new Error(
				`Invalid multiStaffMenuId: "${ multiStaffMenuId }"`
			);
		}
		// メニューに 2 名の対応スタッフを割り当てる。
		wpEvalPhp(
			`update_post_meta(${ multiStaffMenuId }, '_vkbm_staff_ids', array((int)${ staff1Id }, (int)${ staff2Id }));`
		);

		// 不具合2 用: ブロックのデフォルトスタッフ（data-default-resource-id）に
		// staff1 を指定した専用の予約ページを作る。共有の /booking/ を汚さないよう別ページにする。
		const blockMarkup = `<!-- wp:vk-booking-manager/reservation {"defaultResourceId":"${ staff1Id }"} --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block" data-default-menu-id="" data-default-resource-id="${ staff1Id }" data-allow-menu-selection="1" data-allow-staff-selection="1"></div><!-- /wp:vk-booking-manager/reservation -->`;
		const base64Content = Buffer.from( blockMarkup ).toString( 'base64' );
		// 作成後、WP が実際に採番した slug（post_name）も一緒に返す。
		// 既存 slug との衝突時は WP が連番サフィックスを付けるため、固定文字列に頼らず
		// 実際の slug を控えて後続の遷移に使う。戻り値は "<id>|<slug>" 形式。
		const created = wpEvalPhp( `
			$content = base64_decode("${ base64Content }");
			$post_id = wp_insert_post(array(
				"post_type"    => "page",
				"post_title"   => "Booking Nonom",
				"post_name"    => "booking-nonom",
				"post_content" => $content,
				"post_status"  => "publish",
			));
			echo is_wp_error($post_id)
				? 'Error: ' . $post_id->get_error_message()
				: $post_id . '|' . get_post_field('post_name', $post_id);
		` );
		const [ createdId, createdSlug ] = created.split( '|' );
		if ( ! /^\d+$/.test( createdId ) || ! createdSlug ) {
			throw new Error( `Dedicated page creation failed: "${ created }"` );
		}
		dedicatedPageId = createdId;
		dedicatedPageSlug = createdSlug;
		// パーマリンクをフラッシュして専用ページにアクセス可能にする。
		wpCliArgs( [ 'rewrite', 'flush', '--hard' ] );

		// テスト用の一般ユーザー（subscriber）を作成する。お気に入りはログイン必須のため。
		wpCliArgs( [
			'user',
			'create',
			username,
			`${ username }@example.com`,
			'--role=subscriber',
			`--user_pass=${ password }`,
			'--porcelain',
		] );

		// お気に入りをユーザーメタ（_vkbm_favorites）へ直接シードする。
		// フル予約フローを経由せずに「指名あり」「指名なし」の 2 件を用意することで、
		// 不具合1（指名なし適用時にスタッフが残る）をピンポイントに検証できる。
		const seedResult = wpEvalPhp( `
			$user = get_user_by('login', '${ username }');
			if (!$user) { echo 'Error: user not found'; return; }
			$favs = array(
				array('id'=>wp_generate_uuid4(),'menu_id'=>(int)${ multiStaffMenuId },'resource_id'=>(int)${ staff1Id },'label'=>'NONOM-STAFF1'),
				array('id'=>wp_generate_uuid4(),'menu_id'=>(int)${ multiStaffMenuId },'resource_id'=>0,'label'=>'NONOM-FREE'),
			);
			update_user_meta($user->ID, '_vkbm_favorites', $favs);
			echo 'ok';
		` );
		if ( 'ok' !== seedResult ) {
			throw new Error( `Favorite seeding failed: "${ seedResult }"` );
		}
	} );

	test.afterAll( async () => {
		// 生成したデータを削除し、後続スペックのベースライン（スタッフ1名・メニュー1件）へ戻す。
		const ids = [ staff2Id, multiStaffMenuId, dedicatedPageId ].filter(
			( id ) => /^\d+$/.test( id )
		);
		if ( ids.length ) {
			wpCliArgs( [ 'post', 'delete', ...ids, '--force' ] );
		}
		// staff2 の当月シフトも削除する（resource_id メタで特定）。
		// beforeAll の途中失敗などで staff2Id が未設定・不正な場合はスキップする。
		// 0 へフォールバックすると resource_id=0 の無関係なシフトを誤削除しうるため、
		// posts 側の ids フィルタと同様に数値 ID を検証してから実行する。
		if ( /^\d+$/.test( staff2Id ) ) {
			wpEvalPhp( `
				$shifts = get_posts(array(
					'post_type' => 'vkbm_shift',
					'post_status' => 'any',
					'meta_key' => '_vkbm_shift_resource_id',
					'meta_value' => (int)${ staff2Id },
					'fields' => 'ids',
					'numberposts' => -1,
				));
				foreach ($shifts as $sid) { wp_delete_post($sid, true); }
				echo 'ok';
			` );
		}
		// テストユーザーを削除する。
		const userId = wpCliArgs( [
			'user',
			'get',
			username,
			'--field=ID',
		] ).trim();
		if ( /^\d+$/.test( userId ) ) {
			wpCliArgs( [ 'user', 'delete', userId, '--yes' ] );
		}
	} );

	/**
	 * wp-login.php 経由で作成済みユーザーとしてログインする。
	 *
	 * @param page Playwright ページ。
	 */
	const login = async ( page: Page ) => {
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForLoadState( 'networkidle' );
	};

	test( '不具合1: 指名なしのお気に入りを適用するとフォームのスタッフ選択が「指名なし」になる', async ( {
		page,
	} ) => {
		await login( page );

		// 予約フォームを開く（この共有ページはデフォルトスタッフ未指定＝指名なし始点）。
		await page.goto( '/booking/' );
		await page.waitForLoadState( 'networkidle' );

		// シード済みの 2 件のお気に入りチップが表示されることを確認する。
		await expect( page.locator( '.vkbm-favorites__apply' ) ).toHaveCount(
			2,
			{ timeout: 10000 }
		);

		// スタッフ選択 <select>（プランサマリー内の 2 番目の select）を取得するロケータ。
		const staffSelect = page
			.locator( '.vkbm-plan-summary__selectors select' )
			.nth( 1 );

		// まず「指名あり（staff1）」のお気に入りを適用し、スタッフが staff1 になることを確認する。
		await page
			.locator( '.vkbm-favorites__apply', { hasText: 'NONOM-STAFF1' } )
			.click();
		await expect(
			page.locator( '.vkbm-plan-summary' ).first()
		).toBeVisible( { timeout: 10000 } );
		await expect
			.poll( async () => staffSelect.inputValue(), { timeout: 10000 } )
			.toBe( staff1Id );

		// 続いて「指名なし」のお気に入りを適用する。staff1 は新メニューでも対応可能なため、
		// 修正前は staff1 が残ってしまう。修正後はスタッフ選択が「指名なし」(値="")になる。
		await page
			.locator( '.vkbm-favorites__apply', { hasText: 'NONOM-FREE' } )
			.click();
		await expect
			.poll( async () => staffSelect.inputValue(), { timeout: 10000 } )
			.toBe( '' );
	} );

	test( '不具合2: menu_id 付きリンク（指名なし）で開くとデフォルトスタッフにフォールバックしない', async ( {
		page,
	} ) => {
		// デフォルトスタッフ（staff1）が設定された専用ページを、menu_id のみ（resource_id なし）で開く。
		// これは「同じ内容で予約する」リンク（指名なし予約）で開くのと同じ状況。
		await page.goto(
			`/${ dedicatedPageSlug }/?menu_id=${ multiStaffMenuId }`
		);
		await page.waitForLoadState( 'networkidle' );

		// メニュー選択済みビュー（プランサマリー）が表示されること。
		await expect(
			page.locator( '.vkbm-plan-summary' ).first()
		).toBeVisible( { timeout: 10000 } );

		// スタッフ選択が「指名なし」(値="")であること。
		// 修正前は data-default-resource-id（staff1）にフォールバックして staff1 が選ばれていた。
		const staffSelect = page
			.locator( '.vkbm-plan-summary__selectors select' )
			.nth( 1 );
		await expect
			.poll( async () => staffSelect.inputValue(), { timeout: 10000 } )
			.toBe( '' );
	} );
} );
