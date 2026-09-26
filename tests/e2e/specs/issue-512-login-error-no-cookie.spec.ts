import { test, expect } from '@playwright/test';
import { wpCliArgs, wpEvalPhp } from '../utils/helpers';

/**
 * issue #512: ログインに失敗すると画面が真っ白になり、エラーも出ず管理画面にも
 * 入れなくなることがある不具合の修正確認。
 *
 * 検証観点:
 * - 予約ブロックのページでログインに失敗すると、統一文言（#194）が表示され、
 *   廃止した `vkbm_login_error` Cookie は発行されないこと。
 * - 予約ブロックの view.js が読み込めない（＝JS が全く実行できない）状況でも、
 *   render_block フィルタが同一リクエスト内で埋め込んだエラー文が最初から見え、
 *   約3秒後には「別のログイン画面」への案内リンクも表示されること
 *   （JS 未実行時でも画面が真っ白にならない保険）。
 */

const RESERVATION_PAGE_SLUG = 'issue-512-reservation-login-test';

const TEST_USER_LOGIN = 'issue512_login_user';
const TEST_USER_EMAIL = 'issue512_login_user@example.com';
const TEST_USER_PASSWORD = 'CorrectPassword123!';

const UNIFIED_MESSAGE_JA = 'ユーザー名またはパスワードが正しくありません。';
const UNIFIED_MESSAGE_EN = 'Username or password is incorrect.';

/**
 * 予約ブロックのみを含むテスト用ページを用意する（既存なら post_content を上書き）。
 * Ensure a dedicated page exists that renders only the reservation block.
 */
function ensureReservationPage(): void {
	// 予約ブロックは静的ブロック（save.js が wrapper div を保存する）。
	// 自己終了形式のコメントでは wrapper div が描画されず React もマウントできないため、
	// 実ページと同じ「開始コメント + wrapper div + 終了コメント」の形で保存する
	// （favorites-rebook.spec.ts と同じ組み立て方。base64 経由で PHP 文字列への
	// クォート混入を避ける）。
	const blockMarkup =
		'<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->';
	const base64Content = Buffer.from( blockMarkup ).toString( 'base64' );
	const phpCode = `
		$content = base64_decode( "${ base64Content }" );
		$post = get_page_by_path( "${ RESERVATION_PAGE_SLUG }" );
		$post_data = array(
			"post_type"    => "page",
			"post_title"   => "Issue 512 Reservation Login Test",
			"post_name"    => "${ RESERVATION_PAGE_SLUG }",
			"post_content" => $content,
			"post_status"  => "publish",
		);
		if ( $post ) {
			$post_data["ID"] = $post->ID;
			wp_update_post( $post_data );
		} else {
			wp_insert_post( $post_data );
		}
	`;
	wpEvalPhp( phpCode );
	wpCliArgs( [ 'rewrite', 'flush', '--hard' ] );
}

/**
 * テスト用ユーザーを確実に用意する（既存なら一度削除して作り直す）。
 * Ensure a known test user exists (delete first if present, then re-create).
 */
function ensureTestUser(): void {
	try {
		wpCliArgs( [ 'user', 'delete', TEST_USER_LOGIN, '--yes' ] );
	} catch ( _e ) {
		// 存在しなければ無視 / ignore when user does not exist
	}
	wpCliArgs( [
		'user',
		'create',
		TEST_USER_LOGIN,
		TEST_USER_EMAIL,
		`--user_pass=${ TEST_USER_PASSWORD }`,
		'--role=subscriber',
		'--porcelain',
	] );
}

/**
 * BM設定のレート制限を無効化する（連続テストでロックされないように）。
 * login-user-enumeration.spec.ts と同じ考え方。
 */
function disableRateLimitSetting(): void {
	try {
		let current: any = {};
		try {
			const raw = wpCliArgs( [
				'option',
				'get',
				'vkbm_provider_settings',
				'--format=json',
			] );
			const parsed = JSON.parse( raw );
			if (
				typeof parsed === 'object' &&
				parsed !== null &&
				! Array.isArray( parsed )
			) {
				current = parsed;
			}
		} catch ( _e ) {
			current = {};
		}

		const merged = {
			...current,
			auth_rate_limit_enabled: 0,
			registration_rate_limit_enabled: 0,
		};

		const b64 = Buffer.from( JSON.stringify( merged ) ).toString(
			'base64'
		);
		wpEvalPhp(
			`update_option( 'vkbm_provider_settings', json_decode( base64_decode( '${ b64 }' ), true ) );`
		);
	} catch ( _e ) {
		// ignore
	}
}

/**
 * `vkbm_rl_*` トランジェントを全削除してログイン試行カウンタをリセットする。
 */
function flushRateLimitTransients(): void {
	try {
		const phpCode = `
			global $wpdb;
			$rows = $wpdb->get_col(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_vkbm_rl_%'"
			);
			foreach ( $rows as $k ) {
				delete_transient( str_replace( '_transient_', '', $k ) );
			}
		`;
		wpEvalPhp( phpCode );
	} catch ( _e ) {
		// ignore
	}
}

/**
 * REST の auth-form エンドポイント（`/vkbm/v1/auth-form`）への GET リクエストの
 * URL が、期待する `error` クエリを持つかどうかを判定する。
 *
 * CodeRabbit 指摘対応（PR #513 レビュー）: 通常フロー（JS 正常）のテストは
 * `.vkbm-alert__danger` の文言だけを見ていたが、これは render_block フィルタの
 * サーバー側フォールバックも同じクラスで出すため、JS が初回リクエストで
 * `error=auth_failed` を渡し忘れても画面上は区別がつかずテストが見落として
 * しまう。REST リクエストの URL そのもの（クエリパラメータ）を直接検証する。
 *
 * パーマリンク設定済み環境では pathname に `/vkbm/v1/auth-form` を含む形
 * （例: `/wp-json/vkbm/v1/auth-form?...`）になるが、パーマリンク未設定環境
 * では `?rest_route=/vkbm/v1/auth-form&...` のクエリ形式になるため、
 * 両方に一致するようにする。
 *
 * @param requestUrl    リクエストの完全な URL 文字列。
 * @param expectedError 期待する `error` クエリの値。`null` の場合は
 *                      「クエリが存在しない、または空」であることを期待する。
 * @return auth-form エンドポイントへのリクエストで、かつ `error` クエリが
 *         期待どおりなら true。
 */
function isAuthFormRequestWithError(
	requestUrl: string,
	expectedError: string | null
): boolean {
	let url: URL;
	try {
		url = new URL( requestUrl );
	} catch ( _e ) {
		return false;
	}

	const isAuthFormEndpoint =
		url.pathname.includes( '/vkbm/v1/auth-form' ) ||
		'/vkbm/v1/auth-form' === url.searchParams.get( 'rest_route' );
	if ( ! isAuthFormEndpoint ) {
		return false;
	}

	const errorParam = url.searchParams.get( 'error' );
	if ( null === expectedError ) {
		return ! errorParam;
	}
	return errorParam === expectedError;
}

test.describe( 'issue #512 ログイン失敗時の画面真っ白/Cookie廃止', () => {
	test.beforeAll( () => {
		ensureReservationPage();
		ensureTestUser();
		disableRateLimitSetting();
		flushRateLimitTransients();
	} );

	test.beforeEach( () => {
		flushRateLimitTransients();
	} );

	test.afterAll( () => {
		try {
			wpCliArgs( [ 'user', 'delete', TEST_USER_LOGIN, '--yes' ] );
		} catch ( _e ) {
			// ignore
		}
	} );

	test( '予約ブロックでログイン失敗→統一文言が表示され、vkbm_login_error Cookie は発行されない', async ( {
		page,
		context,
	} ) => {
		await context.clearCookies();

		// 通常どおり JS を読み込ませ、予約ブロックが REST 経由でログインフォームを描画する。
		await page.goto( `/${ RESERVATION_PAGE_SLUG }/?vkbm_auth=login` );
		await page.waitForLoadState( 'networkidle' );

		const usernameInput = page.locator( '#vkbm-login-username' );
		await expect( usernameInput ).toBeVisible( { timeout: 15000 } );

		await usernameInput.fill( TEST_USER_LOGIN );
		await page.locator( '#vkbm-login-password' ).fill( 'WrongPassword!!' );

		// CodeRabbit 指摘対応: フォーム送信（POST）→ページ再読み込み→JS が
		// auth-form を再取得、という順になる。ページ遷移をまたいでも同じ page
		// オブジェクト上でリクエストイベントは発火し続けるため、送信前に
		// 仕掛けておけば遷移後のリクエストも捕まえられる。
		// 安藤レビュー指摘（LOW・PR #513）: この待ち受け Promise を Promise.all の
		// 外で個別に await すると、Promise.all の完了を待っている間に reject した
		// 場合に未処理の rejection として残ってしまうため、Promise.all にまとめて
		// 含める。
		const initialAuthFormRequestPromise = page.waitForRequest(
			( request ) =>
				'GET' === request.method() &&
				isAuthFormRequestWithError( request.url(), 'auth_failed' ),
			{ timeout: 20000 }
		);

		// クライアントが初回の auth-form 取得で error=auth_failed を渡していること。
		// （サーバー側フォールバックが同じ .vkbm-alert__danger を出すため、これを
		// 怠っても下の文言チェックだけでは見落としてしまう。CodeRabbit 指摘対応）
		await Promise.all( [
			initialAuthFormRequestPromise,
			page.waitForLoadState( 'networkidle' ),
			page.locator( 'form.vkbm-auth-form button[type="submit"]' ).click(),
		] );

		// ログイン失敗後、統一文言（#194）がどこかに表示される
		// （通常フローでは REST が返した authFormHtml 内のエラー欄に出る）。
		const errorAlert = page.locator( '.vkbm-alert__danger' ).first();
		await expect( errorAlert ).toBeVisible( { timeout: 15000 } );
		const text = ( ( await errorAlert.textContent() ) || '' ).replace(
			/\s+/g,
			' '
		);
		expect( text ).toMatch(
			new RegExp( `${ UNIFIED_MESSAGE_JA }|${ UNIFIED_MESSAGE_EN }` )
		);

		// issue #512: 廃止した一時 Cookie が発行されていないこと。
		const cookies = await context.cookies();
		expect(
			cookies.some( ( cookie ) => cookie.name === 'vkbm_login_error' )
		).toBe( false );

		// 仕様: error は初回の auth-form 取得時だけ渡す。モード切替（新規登録⇄
		// ログイン）での再取得では渡さない（確認手順の「フォーム上部の切り替えで
		// 新規登録を開き、もう一度ログインに戻る→手順1のエラー文は残っていない」
		// に対応。CodeRabbit 指摘対応）。
		//
		// 安藤レビュー・CodeRabbit 指摘対応（PR #513）: 以前はここを page.goto で
		// ページごと開き直していたが、それは誤り。予約ページ上部の「ログイン」
		// 「新規登録」はヘッダーナビ（reservation-header-nav.js）の
		// type="button" 要素で、押しても app.js の handleAuthLink が
		// history.replaceState と state 切り替えのみを行い、ページは読み直され
		// ない。page.goto で開き直すと React が新規マウントされ、初回取得だけ
		// error を送るためのフラグ（initialLoginErrorSentRef）もリセットされて
		// しまうため、そのガードを消してもこのテストは通ってしまっていた。
		// SPA のまま（同じマウントのまま）モードを切り替えて検証する。
		//
		// 実際の DOM で確認した挙動: ログイン失敗直後はヘッダーナビが「戻る」
		// 単体（return バリアント）になっており、「ログイン」「新規登録」が
		// 同時に並ぶ guest バリアントではない。「戻る」を押すと未ログイン状態の
		// トップ（guest バリアント）に戻り、そこで初めて「ログイン」
		// 「新規登録」ボタンが現れる。
		const nav = page.locator( '.vkbm-reservation-header__nav' );
		const returnButton = nav.getByRole( 'button', {
			name: /戻る|Return|return/,
		} );
		const registerButton = nav.getByRole( 'button', {
			name: /新規登録|Sign up/,
		} );
		const loginButton = nav.getByRole( 'button', {
			name: /ログイン|Log in/,
		} );

		// 「戻る」→ guest バリアントのトップに戻る（この遷移は auth-form の
		// 再取得を伴わないため、待ち受けは不要）。
		await returnButton.click();

		// 「新規登録」を押す（GET に error クエリが付かないこと）。
		const registerAuthFormRequestPromise = page.waitForRequest(
			( request ) =>
				'GET' === request.method() &&
				isAuthFormRequestWithError( request.url(), null ) &&
				'register' ===
					new URL( request.url() ).searchParams.get( 'type' ),
			{ timeout: 15000 }
		);
		await Promise.all( [
			registerAuthFormRequestPromise,
			registerButton.click(),
		] );

		// 再び「戻る」→ guest バリアントのトップに戻る。
		await returnButton.click();

		// 「ログイン」を押す（同一マウントを保ったままの2回目の login モードへの
		// 切り替え。initialLoginErrorSentRef は初回取得で既に true になっている
		// ため、この GET にも error クエリが付かないはず＝ガードが効いている
		// ことの直接的な検証）。
		const loginAuthFormRequestPromise = page.waitForRequest(
			( request ) =>
				'GET' === request.method() &&
				isAuthFormRequestWithError( request.url(), null ) &&
				'login' === new URL( request.url() ).searchParams.get( 'type' ),
			{ timeout: 15000 }
		);
		await Promise.all( [
			loginAuthFormRequestPromise,
			loginButton.click(),
		] );

		// 安藤レビュー・CodeRabbit 指摘対応（PR #513）: waitForRequest は GET
		// リクエストの送信を捕まえるだけで、REST 応答を受けてのフォーム再描画
		// までは保証しない。「ログイン」クリック直後の読み込み中（フォームも
		// エラー文もまだ空）のタイミングで下の .vkbm-alert__danger の0件を
		// 確認すると素通りしてしまい、その後の描画で古いエラーが出ても
		// 検出できない。再取得されたログインフォームであることが確実な要素
		// （#vkbm-login-username。register フォームには存在しない）が表示され、
		// 描画が完了してから確認する。
		await expect( page.locator( '#vkbm-login-username' ) ).toBeVisible( {
			timeout: 15000,
		} );

		// ログインに戻ったあと、直前のエラー文（.vkbm-alert__danger）が
		// 残っていないこと（PR確認手順「切り替えで新規登録を開き、もう一度
		// ログインに戻る→エラー文は残っていない」に対応）。
		await expect( page.locator( '.vkbm-alert__danger' ) ).toHaveCount( 0 );
	} );

	test( '予約ブロックの view.js が読み込めなくても、エラー文が最初から見え約3秒後に代替ログイン導線が現れる', async ( {
		page,
		context,
	} ) => {
		await context.clearCookies();

		// 1回目は通常どおり読み込ませ、React が REST からログインフォームを取得して
		// 実際の（有効な nonce を含む）フォームを描画するのを待つ。
		await page.goto( `/${ RESERVATION_PAGE_SLUG }/?vkbm_auth=login` );
		await page.waitForLoadState( 'networkidle' );

		const usernameInput = page.locator( '#vkbm-login-username' );
		await expect( usernameInput ).toBeVisible( { timeout: 15000 } );

		// ここから先（フォーム送信によるページ再読み込み）で view.js を止める。
		// これにより、送信結果のページでは React が一切マウントできない
		// （＝ JS 未実行時のフォールバック確認になる）。
		await page.route( '**/blocks/reservation/view.js*', ( route ) =>
			route.abort()
		);

		await usernameInput.fill( TEST_USER_LOGIN );
		await page.locator( '#vkbm-login-password' ).fill( 'WrongPassword!!' );

		await Promise.all( [
			page.waitForLoadState( 'networkidle' ),
			page.locator( 'form.vkbm-auth-form button[type="submit"]' ).click(),
		] );

		// render_block フィルタが同一リクエスト内で埋め込んだエラー文は、
		// REST 応答を待たず最初から見えている（tabindex="-1" 付きの role="alert"）。
		const wrapper = page.locator(
			'.wp-block-vk-booking-manager-reservation'
		);
		// `data-vkbm-login-error` は wrapper 要素自身に付与される属性であり、子要素
		// ではない（class-reservation-block.php の set_login_error_attribute() が
		// useBlockProps.save() のベース div そのものへ WP_HTML_Tag_Processor で
		// 設定する）。`wrapper.locator( '[data-vkbm-login-error]' )` は wrapper の
		// "子孫" しか探さないため常にヒットしない誤ったアサーションだった。
		// wrapper 自身の属性値を確認する `toHaveAttribute` に修正する。
		await expect( wrapper ).toHaveAttribute(
			'data-vkbm-login-error',
			'auth_failed'
		);

		const fallbackError = wrapper.locator( '.vkbm-alert__danger' ).first();
		await expect( fallbackError ).toBeVisible( { timeout: 5000 } );
		const fallbackText = (
			( await fallbackError.textContent() ) || ''
		).replace( /\s+/g, ' ' );
		expect( fallbackText ).toMatch(
			new RegExp( `${ UNIFIED_MESSAGE_JA }|${ UNIFIED_MESSAGE_EN }` )
		);

		// 「読み込み中」表示も残っている（React が置き換えていない証拠）。
		await expect(
			wrapper.locator( '.vkbm-alert__info' ).first()
		).toBeVisible();

		// 案内リンクは最初は隠れている。
		const fallbackHint = wrapper.locator(
			'a[href*="vkbm_native_login=1"]'
		);
		await expect( fallbackHint ).toBeHidden();

		// 約3秒後、ブロックHTML内のインラインスクリプトが hidden を外す。
		await expect( fallbackHint ).toBeVisible( { timeout: 4000 } );
		const href = await fallbackHint.getAttribute( 'href' );
		expect( href ).toContain( 'wp-login.php' );
		expect( href ).toContain( 'vkbm_native_login=1' );
	} );
} );
