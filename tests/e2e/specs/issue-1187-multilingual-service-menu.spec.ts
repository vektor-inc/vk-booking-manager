/**
 * issue #1187: プラン（サービスメニュー）の編集画面に「多言語設定」の欄を追加し、
 * 言語ごとに登録したタイトル・抜粋・その他条件を、表示しているページの言語（Bogo の英語ページなど）に
 * 合わせて出し分ける変更の検証。
 *
 * 確かめること（PR #1190 の「確認手順」1〜13）:
 * - 編集画面: 言語を追加していないときは「多言語設定」の箱が出ないこと。言語を追加すると
 *   「VK Booking Manager」の箱の下に出て、言語の見出しと3つの欄が並び、薄い文字に基本の値が出ること。
 *   入力して更新した値が、読み込み直しても残ること
 * - 「多言語設定」タブ: プランの編集画面で入力できる案内と、プランの一覧画面へのリンクが出ること
 * - メニューループ: 英語ページでは登録した値、日本語ページでは基本の値が出ること。
 *   空欄の項目・値を登録していないプラン・削除した言語は基本の値に戻ること
 * - 予約ブロック: 英語ページのプランの選択欄・確認画面・予約履歴は登録した値、日本語ページは基本の値のまま。
 *   管理画面の予約編集画面は基本の値のままであること
 *
 * 実行環境: playwright.config の baseURL（テスト用 wp-env、サイトの言語は日本語）に対して実行する。
 * ページを言語ごとに作るため、WordPress.org から Bogo（多言語化プラグイン）を入れて有効化し、
 * 終了後に停止する（他の spec に影響させないため）。
 */
// @category 管理画面
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	loginAsAdmin,
	getStaffId,
} from '../utils/helpers';
import { checkConfirmConsents } from '../utils/consent-helpers';

// BM 設定の多言語設定タブの URL（baseURL に対する相対パス）。
const MULTILINGUAL_TAB_URL =
	'/wp-admin/admin.php?page=vkbm-provider-settings&tab=multilingual';

// 日本語の予約ページ（global-setup が作る）と、Bogo で作る英語版の予約ページ。
const JA_BOOKING_URL = '/booking/';
const EN_BOOKING_URL = '/en/booking/';

// このテストで作るメニューループのページ（日本語版と Bogo の英語版）。
const LOOP_SLUG = 'issue-1187-menu-loop';
const JA_LOOP_URL = `/${ LOOP_SLUG }/`;
const EN_LOOP_URL = `/en/${ LOOP_SLUG }/`;

// 言語ごとの呼び方（=追加した言語の一覧）を保存する option 名と、プランの言語ごとの値の post meta 名。
const MULTILINGUAL_OPTION = 'vkbm_multilingual_labels';
const TEXTS_META = '_vkbm_multilingual_texts';

// プランA・プランBの基本の値（日本語）。
const PLAN_A = {
	title: 'テストプランA',
	excerpt: 'プランAの日本語の抜粋です。',
	other: 'プランAの日本語のその他条件です。',
};
const PLAN_B = {
	title: 'テストプランB',
	excerpt: 'プランBの日本語の抜粋です。',
	other: 'プランBの日本語のその他条件です。',
};

// プランAの English の欄に入れる値。
const PLAN_A_EN = {
	title: 'Plan A',
	excerpt: 'Short description',
	other: 'Free cancellation',
};

// 予約に使う一般ユーザー（予約履歴は会員のみ表示されるため）。
const SUBSCRIBER = {
	name: 'e2e_1187_member',
	password: 'e2e-1187-Password!',
};

// このテストで作った投稿 ID（後片付けで消す）。
let planAId = 0;
let planBId = 0;
const createdPageIds: number[] = [];
let bookingIdsBefore: string[] = [];

/**
 * 言語ごとの呼び方の option を消し、「言語を追加していない」状態に戻す。
 */
function resetMultilingualLabels(): void {
	try {
		wpCliArgs( [ 'option', 'delete', MULTILINGUAL_OPTION ], {
			stdio: 'pipe',
		} );
	} catch ( e ) {
		// option が無いとき（初回）は WP-CLI がエラー終了するが、消えている状態なので問題ない。
	}
}

/**
 * プランを作る（基本の値の抜粋・その他条件と、予約できるようにスタッフも割り当てる）。
 *
 * @param plan    基本の値。
 * @param staffId 割り当てるスタッフの投稿 ID。
 * @return 作ったプランの投稿 ID。
 */
function createPlan( plan: typeof PLAN_A, staffId: string ): number {
	const out = wpEvalPhp( `
		$id = wp_insert_post( array(
			'post_type'    => 'vkbm_service_menu',
			'post_status'  => 'publish',
			'post_title'   => ${ JSON.stringify( plan.title ) },
			'post_excerpt' => ${ JSON.stringify( plan.excerpt ) },
			'menu_order'   => -10,
		) );
		update_post_meta( $id, '_vkbm_other_conditions', ${ JSON.stringify(
			plan.other
		) } );
		update_post_meta( $id, '_vkbm_staff_ids', array( (int) ${ staffId } ) );
		echo (int) $id;
	` );
	return Number.parseInt( out.split( /\s+/ ).pop() || '0', 10 );
}

/**
 * プランの言語ごとの値（post meta）を JSON で読む。無ければ空のオブジェクトを返す。
 *
 * @param postId プランの投稿 ID。
 * @return ロケール => （項目 => 値）。
 */
function readPlanTexts(
	postId: number
): Record< string, Record< string, string > > {
	const out = wpEvalPhp(
		`echo wp_json_encode( get_post_meta( ${ postId }, '${ TEXTS_META }', true ) ?: new stdClass() );`
	);
	try {
		return JSON.parse( out.trim().split( /\n/ ).pop() || '{}' );
	} catch ( e ) {
		return {};
	}
}

/**
 * 予約の投稿 ID の一覧を返す。
 *
 * @return 予約の投稿 ID。
 */
function listBookingIds(): string[] {
	return wpCliArgs( [
		'post',
		'list',
		'--post_type=vkbm_booking',
		'--post_status=any',
		'--format=ids',
	] )
		.split( /\s+/ )
		.filter( ( id ) => /^\d+$/.test( id ) );
}

/**
 * プランの編集画面を開き、メタボックスの欄が読み込まれるまで待つ。
 *
 * @param page   Playwright の Page。
 * @param postId プランの投稿 ID。
 */
async function openPlanEditor( page: Page, postId: number ): Promise< void > {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page
		.locator( '#vkbm_service_menu_vkbm' )
		.waitFor( { state: 'attached', timeout: 30000 } );
}

/**
 * ブロックエディターの「更新」を押し、メタボックスの保存まで終わるのを待つ。
 *
 * @param page Playwright の Page。
 */
async function savePlan( page: Page ): Promise< void > {
	// メタボックスの欄は、投稿の保存のあとに post.php へ別に送られる。その応答まで待つ。
	const metaBoxSaved = page.waitForResponse(
		( res ) =>
			res.url().includes( 'post.php' ) &&
			res.url().includes( 'meta-box-loader' ) &&
			res.request().method() === 'POST',
		{ timeout: 30000 }
	);
	await page.locator( '.editor-post-publish-button' ).click();
	await metaBoxSaved;
}

/**
 * 多言語設定タブで「変更を保存」を押し、保存後の画面が出るまで待つ。
 *
 * @param page Playwright の Page。
 */
async function saveSettings( page: Page ): Promise< void > {
	await Promise.all( [
		page.waitForURL( /settings-updated=/, { timeout: 30000 } ),
		page.locator( '#submit' ).click(),
	] );
}

/**
 * メニューループのページを開き、指定したプランのカードを返す。
 *
 * @param page   Playwright の Page。
 * @param url    開くページの相対パス。
 * @param postId プランの投稿 ID。
 * @return カードの Locator。
 */
async function openLoopCard( page: Page, url: string, postId: number ) {
	await page.goto( url );
	const card = page
		.locator( `.vkbm-menu-loop__item[data-menu-id="${ postId }"]` )
		.first();
	await card.waitFor( { state: 'visible', timeout: 15000 } );
	return card;
}

/**
 * 予約ページでプランAの予約ボタンを押し、空きのある日と時間枠を選んで確認画面まで進める。
 *
 * @param page Playwright の Page。
 * @param url  開く予約ページの相対パス。
 * @return プランの選択欄で選ばれている項目の文字。
 */
async function proceedToConfirm( page: Page, url: string ): Promise< string > {
	await page.goto( url );
	const reserveButton = page.locator(
		`.vkbm-menu-loop__item[data-menu-id="${ planAId }"] .vkbm-menu-loop__button--reserve`
	);
	await reserveButton.waitFor( { state: 'visible', timeout: 15000 } );
	await reserveButton.click();

	// 空き枠の読み込み後にだけ付く「空きあり」の日を選ぶ。
	const availableDays = page.locator(
		'.vkbm-calendar__day--available:not(:disabled)'
	);
	await expect
		.poll( async () => availableDays.count(), { timeout: 15000 } )
		.toBeGreaterThan( 0 );
	await availableDays.first().click();

	const slots = page.locator( '.vkbm-slot-list__item' );
	await expect
		.poll( async () => slots.count(), { timeout: 10000 } )
		.toBeGreaterThan( 0 );
	await slots.first().click();

	// プランの選択欄で選ばれている項目の文字を控える。
	const menuSelect = page
		.locator( '.vkbm-plan-summary__selectors select' )
		.first();
	const selectedText = (
		await menuSelect.locator( 'option:checked' ).textContent()
	)?.trim();

	const planAction = page.locator( '.vkbm-plan-summary__action' ).first();
	await expect( planAction ).toBeEnabled( { timeout: 10000 } );
	await planAction.click();
	await expect(
		page.locator( '.vkbm-confirm__summary' ).first()
	).toBeVisible( { timeout: 15000 } );

	return selectedText || '';
}

/**
 * 一般ユーザーでログインする。
 *
 * @param page Playwright の Page。
 */
async function loginAsSubscriber( page: Page ): Promise< void > {
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );
	await page.waitForLoadState( 'load' );
	await page.locator( '#user_login' ).fill( SUBSCRIBER.name );
	await page.locator( '#user_pass' ).fill( SUBSCRIBER.password );
	await expect( page.locator( '#user_pass' ) ).toHaveValue(
		SUBSCRIBER.password
	);
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( ( u ) => ! u.pathname.includes( 'wp-login.php' ), {
		timeout: 30000,
	} );
}

test.describe
	.serial( 'issue #1187: プランの編集画面で言語ごとのタイトル・抜粋・その他条件を登録し、ページの言語に合わせて表示する', () => {
	test.beforeAll( async () => {
		// @step Bogo（多言語化プラグイン）を入れて有効にする
		try {
			wpCliArgs( [ 'plugin', 'is-installed', 'bogo' ], {
				stdio: 'pipe',
			} );
		} catch ( e ) {
			wpCliArgs( [ 'plugin', 'install', 'bogo' ], { stdio: 'pipe' } );
		}
		wpCliArgs( [ 'plugin', 'activate', 'bogo' ], { stdio: 'pipe' } );

		// @step 基本の値（日本語）のタイトル・抜粋・その他条件を入れたプランA・プランBを公開しておく
		const staffId = getStaffId();
		planAId = createPlan( PLAN_A, staffId );
		planBId = createPlan( PLAN_B, staffId );
		expect( planAId ).toBeGreaterThan( 0 );
		expect( planBId ).toBeGreaterThan( 0 );

		// @step メニューループブロックを置いた日本語のページと、日本語の予約ページの英語版を Bogo で作り、公開する
		const out = wpEvalPhp( `
			$old = get_posts( array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_locale',
				'meta_value'     => 'en_US',
			) );
			foreach ( $old as $old_id ) { wp_delete_post( $old_id, true ); }
			$old_loop = get_page_by_path( '${ LOOP_SLUG }', OBJECT, 'page' );
			if ( $old_loop ) { wp_delete_post( $old_loop->ID, true ); }

			$ids = array();
			$loop = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Issue 1187 menu loop',
				'post_name'    => '${ LOOP_SLUG }',
				'post_content' => '<!-- wp:vk-booking-manager/menu-loop {"loopId":"issue1187"} /-->',
			) );
			$ids[] = $loop;
			foreach ( array( get_post( $loop ), get_page_by_path( 'booking', OBJECT, 'page' ) ) as $ja ) {
				$en = bogo_duplicate_post( $ja, 'en_US' );
				wp_update_post( array( 'ID' => $en, 'post_status' => 'publish', 'post_name' => $ja->post_name ) );
				$ids[] = $en;
			}
			echo implode( ',', $ids );
		` );
		createdPageIds.push(
			...( out.trim().split( /\s+/ ).pop() || '' )
				.split( ',' )
				.map( ( id ) => Number.parseInt( id, 10 ) )
				.filter( ( id ) => id > 0 )
		);
		expect( createdPageIds ).toHaveLength( 3 );

		// @step 予約に使う一般ユーザー（購読者）のアカウントを作っておく
		try {
			wpCliArgs( [ 'user', 'delete', SUBSCRIBER.name, '--yes' ], {
				stdio: 'pipe',
			} );
		} catch ( e ) {
			// まだ無いときは消すものが無い。
		}
		wpCliArgs( [
			'user',
			'create',
			SUBSCRIBER.name,
			`${ SUBSCRIBER.name }@example.com`,
			'--role=subscriber',
			`--user_pass=${ SUBSCRIBER.password }`,
			'--porcelain',
		] );
		bookingIdsBefore = listBookingIds();

		// 言語を何も追加していない状態から始める。
		resetMultilingualLabels();
		wpCliArgs( [ 'rewrite', 'flush' ], { stdio: 'pipe' } );
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test.afterAll( async () => {
		// 他の spec へ持ち越さないよう、作った予約・ページ・プラン・ユーザー・言語の一覧を戻し、Bogo を止める。
		const created = listBookingIds().filter(
			( id ) => ! bookingIdsBefore.includes( id )
		);
		if ( created.length ) {
			wpCliArgs( [ 'post', 'delete', ...created, '--force' ], {
				stdio: 'pipe',
			} );
		}
		const ids = [ ...createdPageIds, planAId, planBId ].filter(
			( id ) => id > 0
		);
		if ( ids.length ) {
			wpCliArgs( [ 'post', 'delete', ...ids.map( String ), '--force' ], {
				stdio: 'pipe',
			} );
		}
		try {
			wpCliArgs( [ 'user', 'delete', SUBSCRIBER.name, '--yes' ], {
				stdio: 'pipe',
			} );
		} catch ( e ) {
			// 作る前に失敗したときは消すものが無い。
		}
		resetMultilingualLabels();
		wpCliArgs( [ 'plugin', 'deactivate', 'bogo' ], { stdio: 'pipe' } );
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
	} );

	test( '言語を追加していないサイトでは、プランの編集画面に「多言語設定」の箱が出ない', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step 「多言語設定」タブで言語を追加していない状態で、プランAの編集画面を開く
		await openPlanEditor( page, planAId );

		// @check 「VK Booking Manager」の箱はあり、「多言語設定」の箱は表示されない
		await expect( page.locator( '#vkbm_service_menu_vkbm' ) ).toHaveCount(
			1
		);
		await expect(
			page.locator( '#vkbm_service_menu_multilingual' )
		).toHaveCount( 0 );
	} );

	test( '「多言語設定」タブで英語を追加すると、プランの編集画面で入力できる案内と一覧画面へのリンクが出る', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step BM 設定の「多言語設定」タブで「English (United States)」を選んで「+ 言語を追加」を押し、「変更を保存」を押す
		await page.goto( MULTILINGUAL_TAB_URL );
		await page
			.locator( '#vkbm-multilingual-add-locale' )
			.selectOption( 'en_US' );
		await page.locator( '#vkbm-multilingual-add' ).click();
		await expect(
			page.locator( '.vkbm-multilingual__language[data-locale="en_US"]' )
		).toBeVisible();
		await saveSettings( page );

		// @check タブの説明に「タイトル・抜粋・その他条件は、各プランの編集画面で言語ごとに入力できます。」の案内が出る
		const guide = page
			.locator( '.vkbm-multilingual__intro' )
			.filter( { hasText: '編集画面で言語ごとに入力できます' } );
		await expect( guide ).toHaveCount( 1 );
		await expect( guide ).toContainText( 'タイトル・抜粋・その他条件' );

		// @check 案内の「プラン」の部分が、プランの一覧画面へのリンクになっている
		const link = guide.locator( 'a' );
		await expect( link ).toHaveCount( 1 );
		await expect( link ).toHaveAttribute(
			'href',
			/edit\.php\?post_type=vkbm_service_menu$/
		);
		expect( ( await link.textContent() )?.trim() ).not.toBe( '' );
	} );

	test( 'プランの編集画面に「多言語設定」の箱が出て、English の欄に入力した値を保存できる', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step プランAの編集画面を開く
		await openPlanEditor( page, planAId );

		// @check 「VK Booking Manager」の箱の下に「多言語設定」の箱があり、「English (United States)（en_US）」の見出しが出る
		const box = page.locator( '#vkbm_service_menu_multilingual' );
		await expect( box ).toHaveCount( 1 );
		const boxIds = await page
			.locator( '.postbox' )
			.evaluateAll( ( els ) => els.map( ( el ) => el.id ) );
		expect( boxIds.indexOf( 'vkbm_service_menu_multilingual' ) ).toBe(
			boxIds.indexOf( 'vkbm_service_menu_vkbm' ) + 1
		);
		await expect(
			box.locator( '.vkbm-service-menu-multilingual__language-title' )
		).toHaveText( 'English (United States)（en_US）' );

		// @check タイトル・抜粋・その他条件の欄が並び、薄い文字に今のタイトル・抜粋・その他条件が出ている
		const title = box.locator(
			'#vkbm-service-menu-multilingual-en_US-title'
		);
		const excerpt = box.locator(
			'#vkbm-service-menu-multilingual-en_US-excerpt'
		);
		const other = box.locator(
			'#vkbm-service-menu-multilingual-en_US-other_conditions'
		);
		await expect( title ).toHaveAttribute( 'placeholder', PLAN_A.title );
		await expect( excerpt ).toHaveAttribute(
			'placeholder',
			PLAN_A.excerpt
		);
		await expect( other ).toHaveAttribute( 'placeholder', PLAN_A.other );
		await expect( title ).toHaveAttribute( 'lang', 'en-US' );

		// @step English の欄に、タイトル「Plan A」・抜粋「Short description」・その他条件「Free cancellation」を入力して「更新」を押す
		await title.fill( PLAN_A_EN.title );
		await excerpt.fill( PLAN_A_EN.excerpt );
		await other.fill( PLAN_A_EN.other );
		await savePlan( page );
		expect( readPlanTexts( planAId ).en_US ).toEqual( {
			title: PLAN_A_EN.title,
			excerpt: PLAN_A_EN.excerpt,
			other_conditions: PLAN_A_EN.other,
		} );

		// @step プランAの編集画面を読み込み直す
		await openPlanEditor( page, planAId );

		// @check 入力した値が残っている
		await expect(
			page.locator( '#vkbm-service-menu-multilingual-en_US-title' )
		).toHaveValue( PLAN_A_EN.title );
		await expect(
			page.locator( '#vkbm-service-menu-multilingual-en_US-excerpt' )
		).toHaveValue( PLAN_A_EN.excerpt );
		await expect(
			page.locator(
				'#vkbm-service-menu-multilingual-en_US-other_conditions'
			)
		).toHaveValue( PLAN_A_EN.other );
	} );

	test( 'メニューループは、英語ページでは English の値、日本語ページでは基本の値で表示される', async ( {
		page,
	} ) => {
		await page.context().clearCookies();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		// @step 英語ページのメニューループを開く
		const enCard = await openLoopCard( page, EN_LOOP_URL, planAId );

		// @check プランAのカードに「Plan A」「Short description」「Free cancellation」が表示される
		await expect(
			enCard.locator( '.vkbm-menu-loop__card-title' )
		).toHaveText( PLAN_A_EN.title );
		await expect( enCard ).toContainText( PLAN_A_EN.excerpt );
		await expect( enCard ).toContainText( PLAN_A_EN.other );
		await expect( enCard ).not.toContainText( PLAN_A.excerpt );
		await expect( enCard ).not.toContainText( PLAN_A.other );
		// 予約ボタンの読み上げ名にも English のタイトルが入る。
		await expect(
			enCard.locator( '.vkbm-menu-loop__button--reserve' )
		).toHaveAccessibleName( new RegExp( PLAN_A_EN.title ) );

		// @step 日本語ページのメニューループを開く
		const jaCard = await openLoopCard( page, JA_LOOP_URL, planAId );

		// @check プランAのカードは今までどおり日本語のタイトル・抜粋・その他条件で表示される
		await expect(
			jaCard.locator( '.vkbm-menu-loop__card-title' )
		).toHaveText( PLAN_A.title );
		await expect( jaCard ).toContainText( PLAN_A.excerpt );
		await expect( jaCard ).toContainText( PLAN_A.other );
		await expect( jaCard ).not.toContainText( PLAN_A_EN.other );
	} );

	test( '予約ブロックは、英語ページではプラン名・確認画面・予約履歴が English の値、日本語ページと管理画面では基本の値', async ( {
		page,
	} ) => {
		await loginAsSubscriber( page );
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );

		// @step 一般ユーザーでログインし、英語ページの予約ブロックでプランAを選び、空きのある日と時間枠を選んで確認画面まで進む
		const enSelected = await proceedToConfirm( page, EN_BOOKING_URL );

		// @check プランの選択欄のプラン名が「Plan A」になっている
		expect( enSelected ).toBe( PLAN_A_EN.title );

		// @check 確認画面のプラン名が「Plan A」で表示され、日本語のプラン名は出ない
		const enConfirm = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( enConfirm ).toContainText( PLAN_A_EN.title );
		await expect( enConfirm ).not.toContainText( PLAN_A.title );

		// @step 同意欄にチェックを入れて予約を確定する
		await checkConfirmConsents( page );
		const confirmButton = page.locator( '.vkbm-confirm__button' );
		await expect( confirmButton ).toBeEnabled( { timeout: 10000 } );
		await confirmButton.click();
		await expect( page.locator( '.vkbm-confirm__button' ) ).toHaveCount(
			0,
			{ timeout: 20000 }
		);

		// @step 英語ページの予約履歴を開く
		await page.goto( `${ EN_BOOKING_URL }?vkbm_auth=bookings` );

		// @check 予約履歴のプラン名が「Plan A」、その他条件が「Free cancellation」で表示される
		const history = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( history ).toContainText( PLAN_A_EN.title, {
			timeout: 15000,
		} );
		await expect( history ).toContainText( PLAN_A_EN.other );

		// @step 日本語ページの予約ブロックで同じ操作をして確認画面まで進む
		const jaSelected = await proceedToConfirm( page, JA_BOOKING_URL );

		// @check プランの選択欄・確認画面のプラン名は日本語のまま
		expect( jaSelected ).toBe( PLAN_A.title );
		const jaConfirm = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( jaConfirm ).toContainText( PLAN_A.title );
		await expect( jaConfirm ).not.toContainText( PLAN_A_EN.title );

		// @step 日本語ページの予約履歴を開く
		await page.goto( `${ JA_BOOKING_URL }?vkbm_auth=bookings` );

		// @check 予約履歴のプラン名・その他条件は日本語のまま
		const jaHistory = page.locator( '.vkbm-confirm__summary' ).first();
		await expect( jaHistory ).toContainText( PLAN_A.title, {
			timeout: 15000,
		} );
		await expect( jaHistory ).toContainText( PLAN_A.other );

		// @step 管理者でログインし、英語ページから作った予約の編集画面を開く
		const created = listBookingIds().filter(
			( id ) => ! bookingIdsBefore.includes( id )
		);
		expect( created.length ).toBeGreaterThan( 0 );
		await page.context().clearCookies();
		await loginAsAdmin( page );
		await page.goto(
			`/wp-admin/post.php?post=${ created[ 0 ] }&action=edit`
		);

		// @check プラン名は基本の値（日本語）のままで、English のタイトルは出ない
		const body = page.locator( '#wpbody-content' );
		await expect( body ).toContainText( PLAN_A.title );
		await expect( body ).not.toContainText( PLAN_A_EN.title );
	} );

	test( 'English の抜粋を空にしたプランと、English を入れていないプランは、英語ページでも基本の値で表示される', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step プランAの English の抜粋だけを空にして「更新」を押す
		await openPlanEditor( page, planAId );
		await page
			.locator( '#vkbm-service-menu-multilingual-en_US-excerpt' )
			.fill( '' );
		await savePlan( page );
		expect( readPlanTexts( planAId ).en_US ).toEqual( {
			title: PLAN_A_EN.title,
			other_conditions: PLAN_A_EN.other,
		} );

		// @step ログアウトして英語ページのメニューループを開く
		await page.context().clearCookies();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
		const cardA = await openLoopCard( page, EN_LOOP_URL, planAId );

		// @check プランAのタイトルは「Plan A」、抜粋は基本の値（日本語の抜粋）で表示される
		await expect(
			cardA.locator( '.vkbm-menu-loop__card-title' )
		).toHaveText( PLAN_A_EN.title );
		await expect( cardA ).toContainText( PLAN_A.excerpt );
		await expect( cardA ).not.toContainText( PLAN_A_EN.excerpt );

		// @check English の欄に何も入れていないプランBは、基本の値（日本語）で表示される
		const cardB = page
			.locator( `.vkbm-menu-loop__item[data-menu-id="${ planBId }"]` )
			.first();
		await expect(
			cardB.locator( '.vkbm-menu-loop__card-title' )
		).toHaveText( PLAN_B.title );
		await expect( cardB ).toContainText( PLAN_B.excerpt );
		await expect( cardB ).toContainText( PLAN_B.other );
	} );

	test( '「多言語設定」タブで English を削除すると、英語ページでも基本の値に戻り、編集画面から箱が消える', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );

		// @step 「多言語設定」タブで English の「削除」を押し、「変更を保存」を押す
		await page.goto( MULTILINGUAL_TAB_URL );
		const section = page.locator(
			'.vkbm-multilingual__language[data-locale="en_US"]'
		);
		await section.locator( '.vkbm-multilingual__remove' ).click();
		// 呼び方に入力がある言語だけ確認ダイアログが出る。出たら「削除する」を押す。
		const dialog = page.locator( '.vkbm-guard-dialog' );
		if ( await dialog.isVisible() ) {
			await dialog.getByRole( 'button', { name: '削除する' } ).click();
		}
		await expect( section ).toHaveCount( 0 );
		await saveSettings( page );

		// @check プランの編集画面から「多言語設定」の箱が消える
		await openPlanEditor( page, planAId );
		await expect(
			page.locator( '#vkbm_service_menu_multilingual' )
		).toHaveCount( 0 );

		// 言語を削除しても、プランの言語ごとの値は消さずに残る。
		expect( readPlanTexts( planAId ).en_US?.title ).toBe( PLAN_A_EN.title );

		// @step ログアウトして英語ページのメニューループを開く
		await page.context().clearCookies();
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'pipe' } );
		const card = await openLoopCard( page, EN_LOOP_URL, planAId );

		// @check プランAは基本の値（日本語）で表示される
		await expect(
			card.locator( '.vkbm-menu-loop__card-title' )
		).toHaveText( PLAN_A.title );
		await expect( card ).toContainText( PLAN_A.other );
		await expect( card ).not.toContainText( PLAN_A_EN.other );
	} );
} );
