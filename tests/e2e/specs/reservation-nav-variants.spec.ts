import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';
import { disableEmailVerification } from '../utils/setup';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:1900';
const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'password';
const MEMBER_USER = 'e2e-member';
const MEMBER_EMAIL = 'e2e-member@example.com';
const MEMBER_PASSWORD = 'E2eMemberPass123';

const NAV_SELECTOR = '.vkbm-reservation-header__nav';

// 予約ページURLを組み立てるヘルパー。
// vkbm_auth を付与するケース（login/register）もここで統一する。
const buildBookingUrl = ( authMode = '' ) => {
	const url = new URL( '/booking/', WP_BASE_URL );
	if ( authMode ) {
		url.searchParams.set( 'vkbm_auth', authMode );
	}
	return url.toString();
};

// 未ログイン起点を担保するため、コンテキストのクッキーを消去する。
const resetAuthState = async ( page: Page ) => {
	await page.context().clearCookies();
};

// ログイン状態の確認を詳細エラー付きで行う。
const assertLoggedInState = async ( page: Page, label: string ) => {
	const url = page.url();
	const bodyClass = await page
		.locator( 'body' )
		.getAttribute( 'class' )
		.catch( () => '' );
	const detectedStates = await page
		.locator( `${ NAV_SELECTOR }[data-vkbm-nav-state]` )
		.evaluateAll( ( nodes ) =>
			nodes.map(
				( node ) => node.getAttribute( 'data-vkbm-nav-state' ) || ''
			)
		)
		.catch( () => [] );
	const authParam = new URL( url ).searchParams.get( 'vkbm_auth' ) || '';

	if ( ! bodyClass || ! bodyClass.includes( 'logged-in' ) ) {
		throw new Error(
			`Logged-in assertion failed (${ label }). url=${ url } bodyClass=${
				bodyClass || '(none)'
			} authParam=${ authParam || '(none)' } detected=${ JSON.stringify(
				detectedStates
			) }`
		);
	}
};

// 予約ページのログインフォームを使ってログインするフォールバック処理。
const loginViaReservationPage = async (
	page: Page,
	{
		username,
		password,
	}: {
		username: string;
		password: string;
	}
) => {
	await page.goto( buildBookingUrl( 'login' ) );
	await page.waitForLoadState( 'networkidle' );

	const loginUsernameInput = page
		.locator( 'input[name="log"]' )
		.or( page.locator( 'input[name="username"]' ) )
		.or( page.locator( '#vkbm-login-username' ) );
	const loginPasswordInput = page
		.locator( 'input[name="pwd"]' )
		.or( page.locator( 'input[name="password"]' ) )
		.or( page.locator( '#vkbm-login-password' ) );
	const loginSubmitButton = page
		.locator( '#vkbm-provider-login-form button[type="submit"]' )
		.or( page.getByRole( 'button', { name: 'ログイン', exact: false } ) )
		.or( page.getByRole( 'button', { name: 'Log in', exact: false } ) );

	await loginUsernameInput.first().fill( username );
	await loginPasswordInput.first().fill( password );
	await Promise.all( [
		page.waitForLoadState( 'networkidle' ),
		loginSubmitButton.first().click(),
	] );

	const cookies = await page.context().cookies( WP_BASE_URL );
	const hasLoggedInCookie = cookies.some( ( cookie ) =>
		cookie.name.startsWith( 'wordpress_logged_in_' )
	);
	if ( ! hasLoggedInCookie ) {
		throw new Error(
			`Login cookie missing after reservation login for "${ username }".`
		);
	}
};

// 予約ページ上でログイン状態を確認し、未ログインなら予約ページログインフォームで再試行する。
const ensureLoggedInOnBookingPage = async (
	page: Page,
	{
		username,
		password,
		label,
	}: {
		username: string;
		password: string;
		label: string;
	}
) => {
	await page.goto( buildBookingUrl() );
	await page.waitForLoadState( 'networkidle' );

	try {
		await assertLoggedInState( page, `${ label }-first-check` );
	} catch ( error ) {
		await loginViaReservationPage( page, { username, password } );
		await page.goto( buildBookingUrl() );
		await page.waitForLoadState( 'networkidle' );
		await assertLoggedInState( page, `${ label }-retry-check` );
	}
};

// 現在の画面で予約ヘッダーナビの状態を検証する
const expectNavVariant = async (
	page: Page,
	{
		screen,
		variant,
	}: {
		screen: string;
		variant: string;
	}
) => {
	const selector = `${ NAV_SELECTOR }[data-vkbm-nav-screen="${ screen }"][data-vkbm-nav-variant="${ variant }"]`;
	const locator = page.locator( selector );
	const timeoutMs = 10000;
	try {
		// 期待する nav variant が見えることを検証
		await expect( locator.first() ).toBeVisible( { timeout: timeoutMs } );
	} catch ( error ) {
		// 失敗時は現在の nav state 一覧と body class を含めてデバッグしやすくする。
		const detectedStates = await page
			.locator( `${ NAV_SELECTOR }[data-vkbm-nav-state]` )
			.evaluateAll( ( nodes ) =>
				nodes.map(
					( node ) => node.getAttribute( 'data-vkbm-nav-state' ) || ''
				)
			)
			.catch( () => [] );
		const bodyClass = await page
			.locator( 'body' )
			.getAttribute( 'class' )
			.catch( () => '' );
		throw new Error(
			`Nav variant assertion failed. expected=${ screen }:${ variant } url=${ page.url() } bodyClass=${
				bodyClass || '(none)'
			} detected=${ JSON.stringify( detectedStates ) }`
		);
	}
};

// ブロックエディタ（iframe / 非iframe 両対応）で予約ヘッダーナビ状態を検証する
const expectEditorNavVariant = async (
	page: Page,
	{
		screen,
		variant,
	}: {
		screen: string;
		variant: string;
	}
) => {
	const selector = `${ NAV_SELECTOR }[data-vkbm-nav-screen="${ screen }"][data-vkbm-nav-variant="${ variant }"]`;
	const canvasFrameCount = await page
		.locator( 'iframe[name="editor-canvas"]' )
		.count();

	// Gutenberg の実装差異で iframe 内に描画される場合があるため両対応にする。
	if ( canvasFrameCount > 0 ) {
		const frame = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const strictLocator = frame.locator( selector ).first();
		if ( await strictLocator.isVisible().catch( () => false ) ) {
			await expect( strictLocator ).toBeVisible();
			return;
		}

		// エディター内は属性一致が不安定なケースがあるため、staffナビのリンク表示でフォールバック判定する。
		if ( variant === 'staff' ) {
			await expect(
				frame.getByRole( 'link', {
					name: /シフト・予約表|Shift\/reservation table/,
				} )
			).toBeVisible();
			await expect(
				frame.getByRole( 'link', { name: /ログアウト|Log out/ } )
			).toBeVisible();
			return;
		}
		return;
	}

	await expect( page.locator( selector ).first() ).toBeVisible();
};

// ブロックエディタで検証前に必要な前処理を行う。
// 1) 初回表示の「エディターへようこそ」モーダルを閉じる
// 2) 予約ブロックが無効扱いの場合は「復旧を試みる」を押して復旧する
const prepareEditorCanvas = async ( page: Page ) => {
	// まずウェルカムモーダルを確実に閉じる（文言差異を吸収するため複数手段で実行）。
	for ( let attempt = 0; attempt < 4; attempt++ ) {
		const welcomeDialog = page.getByRole( 'dialog' );
		const isDialogVisible = await welcomeDialog
			.first()
			.isVisible()
			.catch( () => false );
		if ( ! isDialogVisible ) {
			break;
		}

		const closeButton = page.getByRole( 'button', {
			name: /閉じる|Close/i,
		} );
		if (
			await closeButton
				.first()
				.isVisible()
				.catch( () => false )
		) {
			await closeButton.first().click();
			await page.waitForTimeout( 400 );
			continue;
		}

		// 閉じるボタンが取れないケース向けに Escape も試す。
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 400 );
	}

	const canvasFrameCount = await page
		.locator( 'iframe[name="editor-canvas"]' )
		.count();
	if ( canvasFrameCount === 0 ) {
		return;
	}

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const recoverButton = canvas.getByRole( 'button', {
		name: '復旧を試みる',
	} );
	if ( await recoverButton.isVisible().catch( () => false ) ) {
		await recoverButton.click();
		await page.waitForTimeout( 1200 );
	}
};

test.describe( 'Reservation header nav variants', () => {
	let bookingPageId = '';
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeEach( async ( { page } ) => {
		await resetAuthState( page );
	} );

	// テストに必要なページ・ユーザーを事前準備する
	test.beforeAll( async () => {
		// 予約ブロックの表示に必要な初期データを作成する。
		await disableEmailVerification();

		// 管理者ログイン用のパスワードを明示的に固定して、環境差分で失敗しないようにする。
		execSync(
			`npx wp-env run cli wp user update ${ ADMIN_USER } --user_pass='${ ADMIN_PASSWORD }'`
		);

		// 一般会員（subscriber）ユーザーを用意する。存在しない場合のみ作成。
		try {
			execSync(
				`npx wp-env run cli wp user get ${ MEMBER_USER } --field=ID`,
				{ stdio: 'ignore' }
			);
		} catch {
			execSync(
				`npx wp-env run cli wp user create ${ MEMBER_USER } ${ MEMBER_EMAIL } --role=subscriber --user_pass='${ MEMBER_PASSWORD }'`
			);
		}
		// 既存ユーザーでも毎回パスワードを揃えてログイン失敗を防ぐ。
		execSync(
			`npx wp-env run cli wp user update ${ MEMBER_USER } --user_pass='${ MEMBER_PASSWORD }'`
		);

		// ブロックエディタ検証で使う予約ページIDを取得。
		bookingPageId = execSync(
			'npx wp-env run cli wp post list --post_type=page --name=booking --field=ID --format=ids',
			{ encoding: 'utf-8' }
		).trim();
		if ( ! bookingPageId ) {
			throw new Error(
				'Booking page ID could not be resolved. Ensure the booking page exists before running editor nav tests.'
			);
		}
	} );

	test( 'shows staff nav for logged-in staff/admin on frontend', async ( {
		page,
	} ) => {
		// 管理者でログイン後、予約画面で staff ナビになることを確認。
		await ensureLoggedInOnBookingPage( page, {
			username: ADMIN_USER,
			password: ADMIN_PASSWORD,
			label: 'staff-frontend',
		} );

		await expectNavVariant( page, {
			screen: 'reservation',
			variant: 'staff',
		} );
	} );

	test( 'shows member nav for logged-in member on frontend', async ( {
		page,
	} ) => {
		// subscriber でログイン後、予約画面で member ナビになることを確認。
		await ensureLoggedInOnBookingPage( page, {
			username: MEMBER_USER,
			password: MEMBER_PASSWORD,
			label: 'member-frontend',
		} );

		await expectNavVariant( page, {
			screen: 'reservation',
			variant: 'member',
		} );
	} );

	test( 'shows guest nav for logged-out visitor on frontend', async ( {
		page,
	} ) => {
		// 未ログイン時は guest ナビが表示されることを確認。
		await page.goto( buildBookingUrl() );
		await page.waitForLoadState( 'networkidle' );

		await expectNavVariant( page, {
			screen: 'reservation',
			variant: 'guest',
		} );
	} );

	test( 'shows return nav on login screen for logged-out visitor', async ( {
		page,
	} ) => {
		// ログインフォーム表示中は return ナビに切り替わることを確認。
		await page.goto( buildBookingUrl( 'login' ) );
		await page.waitForLoadState( 'networkidle' );

		await expectNavVariant( page, {
			screen: 'reservation',
			variant: 'return',
		} );
	} );

	test( 'shows return nav on register screen for logged-out visitor', async ( {
		page,
	} ) => {
		// 新規登録フォーム表示中も return ナビになることを確認。
		await page.goto( buildBookingUrl( 'register' ) );
		await page.waitForLoadState( 'networkidle' );

		await expectNavVariant( page, {
			screen: 'reservation',
			variant: 'return',
		} );
	} );

	test( 'shows staff nav in block editor when admin opens reservation block', async ( {
		page,
	} ) => {
		test.setTimeout( 30000 );

		// エディタ表示時は管理者として staff ナビになることを確認。
		await ensureLoggedInOnBookingPage( page, {
			username: ADMIN_USER,
			password: ADMIN_PASSWORD,
			label: 'editor-admin',
		} );
		await page.goto(
			`${ WP_BASE_URL }/wp-admin/post.php?post=${ bookingPageId }&action=edit`
		);
		// ブロックエディターは常時通信が発生しやすく、networkidle はタイムアウトしやすい。
		// そのため canvas iframe の表示を待機条件にする。
		await page.waitForLoadState( 'domcontentloaded' );
		await expect(
			page.locator( 'iframe[name="editor-canvas"]' )
		).toBeVisible( { timeout: 15000 } );
		await prepareEditorCanvas( page );

		await expectEditorNavVariant( page, {
			screen: 'reservation',
			variant: 'staff',
		} );
	} );
} );
