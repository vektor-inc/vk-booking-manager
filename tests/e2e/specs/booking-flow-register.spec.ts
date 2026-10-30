import { test, expect } from '@playwright/test';
import { wpCliArgs, getServiceMenuId } from '../utils/helpers';
import { checkConfirmConsents } from '../utils/consent-helpers';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';
const E2E_DEBUG =
	process.env.E2E_DEBUG === 'true' || process.env.E2E_DEBUG === '1';

// ヘッダー情報から機密情報をマスクする関数
// cookie, authorization, set-cookieなどの値を***REDACTED***に置き換える
const maskHeaders = ( headers: Record< string, string > ) => {
	const masked = { ...headers };
	[ 'cookie', 'authorization', 'set-cookie' ].forEach( ( h ) => {
		Object.keys( masked ).forEach( ( key ) => {
			if ( key.toLowerCase() === h ) {
				masked[ key ] = '***REDACTED***';
			}
		} );
	} );
	return masked;
};

// データオブジェクトから機密情報をマスクする関数
// email, phone, password, token, nonceなどのフィールドを***REDACTED***に置き換える
const maskSensitiveData = ( data: any ): any => {
	if ( ! data || typeof data !== 'object' ) {
		return data;
	}
	if ( Array.isArray( data ) ) {
		return data.map( maskSensitiveData );
	}
	const masked = { ...data };
	const sensitive = [
		'email',
		'phone',
		'password',
		'pass',
		'pwd',
		'token',
		'nonce',
	];
	Object.keys( masked ).forEach( ( key ) => {
		if ( sensitive.some( ( s ) => key.toLowerCase().includes( s ) ) ) {
			masked[ key ] = '***REDACTED***';
		} else if ( typeof masked[ key ] === 'object' ) {
			masked[ key ] = maskSensitiveData( masked[ key ] );
		}
	} );
	return masked;
};

// POSTデータから機密情報をマスクする関数
// JSON形式またはURLエンコード形式のデータから機密情報を除去してログ出力用に整形
const maskPostData = ( postData: string | null, contentType: string = '' ) => {
	if ( ! postData ) {
		return null;
	}
	try {
		// JSON形式の場合
		if (
			contentType.includes( 'application/json' ) ||
			( postData.trim().startsWith( '{' ) &&
				postData.trim().endsWith( '}' ) )
		) {
			return JSON.stringify(
				maskSensitiveData( JSON.parse( postData ) ),
				null,
				2
			);
		}
		// URLエンコード形式の場合
		if ( contentType.includes( 'application/x-www-form-urlencoded' ) ) {
			const params = new URLSearchParams( postData );
			const sensitive = [
				'email',
				'phone',
				'password',
				'pass',
				'pwd',
				'token',
				'nonce',
				'log',
			];
			const maskedParams = new URLSearchParams();
			params.forEach( ( val, key ) => {
				if (
					sensitive.some( ( s ) => key.toLowerCase().includes( s ) )
				) {
					maskedParams.append( key, '***REDACTED***' );
				} else {
					maskedParams.append( key, val );
				}
			} );
			return maskedParams.toString();
		}
	} catch {}
	return postData;
};

// 新規ユーザー登録を含む予約フローのテスト（メール認証なし）
// @title 新規ユーザー登録を含む予約の流れ（メール認証なし）
test.describe( 'Booking Flow with New User Registration (No Email Verification)', () => {
	// 各テスト実行前にWordPressのトランジェントを削除してレート制限を回避
	// Delete WordPress transients before each test to avoid rate limits
	test.beforeEach( async () => {
		// shell パース経由のリスクを避けるため execFileSync ベースの wpCliArgs を使用する
		// Use execFileSync-based wpCliArgs to avoid shell-parse risks
		wpCliArgs( [ 'transient', 'delete', '--all' ], { stdio: 'ignore' } );
		console.log( 'Deleted transients' );
	} );

	// テストデータの存在を確認（グローバルセットアップで作成済み）
	// Verify test data exists (created by global setup)
	// 先頭カードではなく data-menu-id で「Service Menu 1」を指定する（#566）。
	// WP-CLI 経由の取得は 1 回数秒かかるため、テスト本体の制限時間（45 秒）を
	// 消費しないよう beforeAll で 1 度だけ取得する（#897）。
	// 見つからないエラーを握りつぶさないよう、取得失敗は beforeAll の失敗として表に出す。
	let menuId = '';

	test.beforeAll( async () => {
		// サービスメニューが存在するか確認
		// Verify service menus exist
		// execFileSync ベースの wpCliArgs に切り替え、shell パース経路を排除する
		// Switch to execFileSync-based wpCliArgs to eliminate the shell parse path
		const serviceMenus = wpCliArgs( [
			'post',
			'list',
			'--post_type=vkbm_service_menu',
			'--format=count',
		] );

		if ( serviceMenus === '0' ) {
			throw new Error(
				'No service menus found. Global setup may have failed.'
			);
		}

		// 予約ページが存在するか確認（slug ベースで安定的に検索）
		// Verify booking page exists (slug-based search for stability)
		const bookingPageId = wpCliArgs( [
			'post',
			'list',
			'--post_type=page',
			'--name=booking',
			'--field=ID',
			'--format=ids',
		] );

		if ( ! bookingPageId ) {
			throw new Error(
				'No booking page found. Global setup may have failed.'
			);
		}

		menuId = getServiceMenuId();
	} );

	test.use( { storageState: { cookies: [], origins: [] } } );

	const password = 'TestPassword123!';

	// @title 新規ユーザーが会員登録して予約を完了できる
	test( 'should allow a new user to register and complete a booking', async ( {
		page,
	} ) => {
		// 手順の固定待ちを条件待ちに置き換えた後（#897）も、画面遷移 8 回前後と
		// beforeEach の WP-CLI（約 5 秒）が残り、遅い環境では 30〜40 秒かかる。
		// 既定の 45 秒では余裕が薄いため、この 1 件だけ 60 秒にする。
		test.setTimeout( 60000 );

		const username = `user-${ Date.now() }-${ Math.random()
			.toString( 36 )
			.substring( 7 ) }`;
		const email = `${ username }@example.com`;
		// コンソールログを監視
		page.on( 'console', ( msg ) =>
			console.log( `Browser Console: ${ msg.text() }` )
		);

		// リクエストを監視
		page.on( 'request', ( request ) => {
			if (
				request.method() === 'POST' &&
				( request.url().includes( 'vkbm' ) ||
					request.url().includes( 'booking' ) )
			) {
				// 予約/認証系のPOSTだけを対象にログを出し、不要なノイズを抑える。
				console.log( `--- POST Request: ${ request.url() } ---` );

				if ( E2E_DEBUG ) {
					// デバッグモード時のみ、機密情報をマスクした詳細ログを出力する。
					console.log(
						'Headers:',
						JSON.stringify( maskHeaders( request.headers() ) )
					);
					const postData = request.postData();
					const contentType =
						request.headers()[ 'content-type' ] || '';
					console.log(
						'Post Data:',
						maskPostData( postData, contentType )
					);
					console.log( '--------------------' );
				}
			}
		} );

		// 1. 予約ページに移動
		// goto は load まで待つ。以降は「次に操作する要素が出るまで」を待つ（固定待ち・networkidle は使わない #897）。
		console.log( 'Initial Page URL:', `${ WP_BASE_URL }/booking/` );
		// @step ログインせずに予約ページを開く
		await page.goto( `${ WP_BASE_URL }/booking/` );

		// 2. サービスメニューを選択
		// （公開メニューの有無は beforeAll で確認・menuId 取得済み。WP-CLI の再実行は時間がかかるため行わない）
		console.log( 'Waiting for service menu button...' );

		// 適切なタイムアウトでサービスメニューボタンを探す
		// @step 「Service Menu 1」の「予約する」ボタンを押す
		try {
			// まず新しいボタンスタイルを試す
			const reserveSelector = `.vkbm-menu-loop__item[data-menu-id="${ menuId }"] .vkbm-menu-loop__button--reserve`;
			await page.waitForSelector( reserveSelector, {
				state: 'visible',
				timeout: 10000,
			} );
			console.log(
				'Service menu button found (.vkbm-menu-loop__button--reserve). Clicking...'
			);
			await page.locator( reserveSelector ).first().click();
		} catch ( e ) {
			console.log(
				'New style button not found, trying old style (.vkbm-service-menu-card)...'
			);
			try {
				await page.waitForSelector( '.vkbm-service-menu-card', {
					state: 'visible',
					timeout: 10000,
				} );
				console.log( 'Found .vkbm-service-menu-card. Clicking...' );
				await page.locator( '.vkbm-service-menu-card' ).first().click();
			} catch ( e2 ) {
				console.log(
					'No service menu buttons found. Dumping debug information...'
				);

				// Check if menu loop container exists
				const menuLoop = await page
					.locator( '.vkbm-menu-loop' )
					.count();
				console.log( `Menu loop containers found: ${ menuLoop }` );

				// Check if any menu items exist
				const menuItems = await page
					.locator( '.vkbm-menu-loop__item' )
					.count();
				console.log( `Menu items found: ${ menuItems }` );

				// Check page HTML structure
				console.log( '--- Page Content Dump (Start) ---' );
				const bodyContent = await page.locator( 'body' ).textContent();
				console.log( bodyContent );
				console.log( '--- Page Content Dump (End) ---' );

				throw new Error( 'Service menu button not found on the page' );
			}
		}

		// 3. カレンダービューの表示を待つ
		console.log( 'Waiting for calendar view...' );
		await page.waitForSelector( '.vkbm-calendar', {
			state: 'visible',
			timeout: 10000,
		} );

		// 4. 利用可能な日付を選択（無効化されていない最初の日付）
		console.log( 'Selecting an available date...' );
		// @step カレンダーで選べる最初の日を選ぶ
		const enabledCalendarDays = page.locator(
			'.vkbm-calendar__day:not(:disabled):not([aria-disabled="true"]):not(.vkbm-calendar__day--disabled)'
		);
		await expect
			.poll( async () => enabledCalendarDays.count(), { timeout: 10000 } )
			.toBeGreaterThan( 0 );

		let dateSelected = false;
		for ( let attempt = 0; attempt < 5; attempt++ ) {
			const availableDate = enabledCalendarDays.first();
			try {
				await expect( availableDate ).toBeVisible( { timeout: 2000 } );
				await expect( availableDate ).toBeEnabled( { timeout: 2000 } );
				await availableDate.click( { timeout: 3000 } );
				dateSelected = true;
				break;
			} catch ( error ) {
				console.warn(
					`Available date click attempt ${
						attempt + 1
					} failed. Retrying...`
				);
				await page.waitForTimeout( 300 );
			}
		}

		if ( ! dateSelected ) {
			throw new Error(
				'Failed to click an enabled calendar day after retries.'
			);
		}

		// 5. ランダムな時間枠を選択
		// 日付クリック後にスロット一覧が描画されるまで待つ（固定待ちの代わり）。
		// @step 表示された時間枠から1つ選ぶ
		const slots = page.locator( '.vkbm-slot-list__item' );
		await slots.first().waitFor( { state: 'visible', timeout: 10000 } );
		const count = await slots.count();
		if ( count > 0 ) {
			const randomIndex = Math.floor( Math.random() * count );
			console.log(
				`Selecting random slot index: ${ randomIndex } of ${ count }`
			);
			await slots.nth( randomIndex ).click();
		} else {
			console.log( 'No slots found!' );
			// スロットが見つからない場合は敢えてクリックして自然な失敗に委ねる。
			await slots.first().click();
		}

		// 6. 「予約内容を確認」ボタンをクリック
		// クリック後は /vkbm/v1/drafts の応答を受けて ?draft=<token> 付き URL へ遷移する。
		// 固定待ちの代わりに、その URL への遷移完了を待つ。
		// @step 「予約内容を確認」ボタンを押して確認画面へ進む
		const confirmButton = page
			.locator( '.vkbm-plan-summary__action' )
			.first();
		await confirmButton.click();
		await page.waitForURL( /[?&]draft=/, { timeout: 15000 } );

		// URLからドラフトトークンを保存（デバッグ用、WP-CLIハック用ではない）
		const currentUrl = page.url();
		const urlParams = new URL( currentUrl ).searchParams;
		const draftToken = urlParams.get( 'draft' ) || '';

		// 7. ページ最上部にスクロールし、認証選択または登録ボタンを確認
		await page.evaluate( () => window.scrollTo( 0, 0 ) );

		const authSelect = page.locator( '.vkbm-confirm__auth' );
		// 確認画面の描画（未ログイン時の認証欄）を待つ。出ない場合は下のフォールバックに任せる。
		await authSelect
			.waitFor( { state: 'visible', timeout: 10000 } )
			.catch( () => {} );
		const authSelectVisible = await authSelect
			.isVisible()
			.catch( () => false );
		console.log( 'Auth select visible:', authSelectVisible );

		// 登録ボタンをクリック
		// @step 「新規登録」ボタンを押す
		const registerButton = page
			.getByRole( 'button', { name: '新規登録', exact: false } )
			.or( page.getByRole( 'button', { name: 'Sign up', exact: false } ) )
			.or( page.getByRole( 'button', { name: '登録', exact: false } ) )
			.or(
				page.getByRole( 'button', { name: 'Register', exact: false } )
			);

		const registerButtonVisible = await registerButton
			.isVisible()
			.catch( () => false );
		console.log( 'Register button visible:', registerButtonVisible );

		if ( registerButtonVisible ) {
			console.log( 'Clicking register button...' );
			await registerButton.click();
		} else {
			// 登録ボタンが見つからない場合は、登録ページに直接移動
			console.log(
				'Register button not found, navigating to register page...'
			);
			await page.goto(
				`${ WP_BASE_URL }/booking/?draft=${ draftToken }&vkbm_auth=register`
			);
		}

		// 8. 登録フォームが表示されている場合は入力
		// 実装では特定のID/name属性を使用しているため、様々なセレクタを試行
		const usernameInput = page
			.locator( '#vkbm-register-username' )
			.or( page.locator( 'input[name="vkbm_user_login"]' ) )
			.or( page.locator( 'input[name="username"]' ) );

		// 登録ボタンクリック後、ユーザー名入力欄が表示されるまで待機
		try {
			await usernameInput.waitFor( { state: 'visible', timeout: 15000 } );
		} catch ( e ) {
			console.log( 'Username input not visible after waiting' );

			// デバッグ: 認証パネルの内容を出力
			const authPanel = page.locator( '.vkbm-confirm__auth-panel' );
			if ( await authPanel.isVisible() ) {
				console.log( 'Auth panel is visible' );
				// console.log('Auth panel HTML:', await authPanel.innerHTML()); // ノイズ削減のためコメントアウト
			} else {
				console.log( 'Auth panel is NOT visible' );
			}
		}

		const usernameVisible = await usernameInput
			.isVisible()
			.catch( () => false );
		console.log( 'Username input visible:', usernameVisible );

		if ( usernameVisible ) {
			// @step 登録フォームにユーザー名・メールアドレス・パスワード・姓名を入れ、表示されていればパスワード（確認）・フリガナ・電話番号・利用規約の同意・性別・生年月日・プライバシーポリシーの同意も入れる
			console.log( 'Filling in registration form...' );
			console.log( 'About to enter try block...' );

			try {
				console.log( 'Step 1: Filling username...' );
				await usernameInput.fill( username );
				console.log( '✓ Username filled:', username );

				console.log( 'Step 2: Filling email...' );
				const emailInput = page
					.locator( '#vkbm-register-email' )
					.or( page.locator( 'input[name="vkbm_user_email"]' ) )
					.or( page.locator( 'input[name="email"]' ) );
				await emailInput.fill( email );
				console.log( '✓ Email filled:', email );

				console.log( 'Step 3: Filling password...' );
				const passwordInput = page
					.locator( '#vkbm-register-password' )
					.or( page.locator( 'input[name="vkbm_user_pass"]' ) )
					.or( page.locator( 'input[name="password"]' ) );
				await passwordInput.fill( password );
				console.log( '✓ Password filled' );

				// パスワード確認フィールドが存在する場合は入力
				const confirmPass = page
					.locator( '#vkbm-register-password-confirm' )
					.or( page.locator( 'input[name="user_pass_confirm"]' ) )
					.or(
						page.locator( 'input[name="password_confirmation"]' )
					);

				if ( await confirmPass.isVisible().catch( () => false ) ) {
					console.log( 'Step 4: Filling password confirmation...' );
					await confirmPass.fill( password );
					console.log( '✓ Password confirmation filled' );
				} else {
					console.log(
						'Step 4: Password confirmation field not visible (Skipped)'
					);
				}

				// 名前フィールド
				console.log( 'Step 5: Filling name fields...' );
				const lastNameInput = page
					.locator( '#vkbm-register-last-name' )
					.or( page.locator( 'input[name="vkbm_last_name"]' ) )
					.or( page.locator( 'input[name="last_name"]' ) );

				const firstNameInput = page
					.locator( '#vkbm-register-first-name' )
					.or( page.locator( 'input[name="vkbm_first_name"]' ) )
					.or( page.locator( 'input[name="first_name"]' ) );

				await lastNameInput.fill( 'Test' );
				await firstNameInput.fill( 'User' );
				console.log( '✓ Name fields filled' );

				// 設定によってはカナ名/電話番号が必須の場合がある
				// カナ名入力（存在する環境のみ）
				const kanaInput = page
					.locator( 'input[name="kana_name"]' )
					.or( page.locator( '#vkbm-register-kana' ) );

				console.log( 'Checking for Kana input...' );
				if ( await kanaInput.isVisible().catch( () => false ) ) {
					await kanaInput.fill( 'テストユーザー' );
					console.log( '✓ Kana name filled' );
				} else {
					console.log( 'kana_name field not found!' );
				}

				const phoneInput = page
					.locator( '#vkbm-register-phone' )
					.or( page.locator( 'input[name="phone_number"]' ) )
					.or( page.locator( 'input[name="vkbm_phone"]' ) )
					.or( page.locator( 'input[name="phone"]' ) );

				if ( await phoneInput.isVisible().catch( () => false ) ) {
					console.log( 'Step 6: Filling phone...' );
					await phoneInput.fill( '09012345678' );
					console.log( '✓ Phone filled' );
				}

				console.log( 'All basic fields filled successfully' );
			} catch ( error ) {
				console.error( 'ERROR filling registration form:', error );
				throw error;
			}

			// 登録フォーム内の利用規約チェックボックスを確認
			const registerTerms = page
				.locator( '#vkbm-register-terms' )
				.or(
					page.locator( 'input[name="vkbm_agree_terms_of_service"]' )
				);

			const termsVisible = await registerTerms
				.isVisible()
				.catch( () => false );
			console.log( 'Terms checkbox visible:', termsVisible );

			if ( termsVisible ) {
				console.log( 'Checking registration terms...' );
				await registerTerms.check();

				// チェックボックスがチェックされたことを確認
				const isChecked = await registerTerms
					.isChecked()
					.catch( () => false );
				console.log( 'Terms checkbox is checked:', isChecked );
			}

			// 性別と生年月日
			const genderSelect = page.locator( 'select[name="gender"]' );
			if ( await genderSelect.isVisible().catch( () => false ) ) {
				await genderSelect.selectOption( { index: 1 } );
				console.log( '✓ Gender selected' );
			}

			const birthYear = page.locator( 'select[name="birth_year"]' );
			if ( await birthYear.isVisible().catch( () => false ) ) {
				await birthYear.selectOption( { index: 20 } ); // 約20年前を選択
				console.log( '✓ Birth year selected' );
			}
			const birthMonth = page.locator( 'select[name="birth_month"]' );
			if ( await birthMonth.isVisible().catch( () => false ) ) {
				await birthMonth.selectOption( { index: 1 } );
				console.log( '✓ Birth month selected' );
			}
			const birthDay = page.locator( 'select[name="birth_day"]' );
			if ( await birthDay.isVisible().catch( () => false ) ) {
				await birthDay.selectOption( { index: 1 } );
				console.log( '✓ Birth day selected' );
			}

			// プライバシーポリシーを確認
			const privacyPolicy = page
				.locator( '#vkbm-register-privacy-policy' )

				.or(
					page.locator( 'input[name="vkbm_agree_privacy_policy"]' )
				);

			const privacyVisible = await privacyPolicy
				.isVisible()
				.catch( () => false );
			console.log( 'Privacy policy checkbox visible:', privacyVisible );

			if ( privacyVisible ) {
				console.log( 'Checking privacy policy...' );
				await privacyPolicy.check();
			}

			// 登録のデバッグ用にネットワークレスポンスをリッスン
			let _registrationResponse: any = null;
			page.on( 'response', async ( response ) => {
				const url = response.url();
				if (
					url.includes( '/wp-json/vkbm/v1/' ) ||
					url.includes( 'vkbm' ) ||
					url.includes( 'register' )
				) {
					// 登録関連のレスポンスだけを収集し、失敗時に追跡しやすくする。
					console.log(
						`Network response: ${ url } Status: ${ response.status() }`
					);

					let body = '';
					try {
						body = await response.text();
						_registrationResponse = {
							url,
							status: response.status(),
							body,
						};
					} catch {}

					if ( E2E_DEBUG && body ) {
						const contentType =
							response.headers()[ 'content-type' ] || '';
						if (
							contentType.includes( 'json' ) ||
							( body.trim().startsWith( '{' ) &&
								body.trim().endsWith( '}' ) )
						) {
							console.log(
								'Response body:',
								maskPostData( body, 'application/json' )
							);
						} else if ( body.length < 1000 ) {
							console.log( 'Response body: [Non-JSON]' );
						}
					}
				}
			} );

			// 登録を送信
			// タブボタン「新規登録」と一致しないように特定のセレクタを使用
			// @step 登録ボタンを押す
			const submitButton = page
				.locator( '#vkbm-provider-register-form button[type="submit"]' )
				.or( page.locator( '.vkbm-auth-button[type="submit"]' ) );

			// 送信ボタンが存在し、表示されているかを確認
			const submitButtonExists = await submitButton.count();
			console.log( 'Submit button count:', submitButtonExists );

			if ( submitButtonExists > 0 ) {
				const submitButtonVisible = await submitButton
					.first()
					.isVisible()
					.catch( () => false );
				console.log( 'Submit button visible:', submitButtonVisible );

				// 送信ボタンが有効かどうかを確認
				const isEnabled = await submitButton
					.first()
					.isEnabled()
					.catch( () => false );
				console.log( 'Submit button is enabled:', isEnabled );

				// クリック前にバリデーションエラーを確認
				const invalidElements = page.locator( '.is-invalid' );
				const invalidCount = await invalidElements.count();
				if ( invalidCount > 0 ) {
					console.log( `Found ${ invalidCount } invalid fields!` );
					for ( let i = 0; i < invalidCount; i++ ) {
						const el = invalidElements.nth( i );
						const id = await el.getAttribute( 'id' );
						const name = await el.getAttribute( 'name' );
						console.log(
							`Invalid field ${ i }: id=${ id }, name=${ name }`
						);

						try {
							const parent = el.locator( '..' );
							const feedback = parent.locator(
								'.vkbm-invalid-feedback'
							);
							if ( ( await feedback.count() ) > 0 ) {
								console.log(
									`Error message: ${ await feedback.innerText() }`
								);
							}
						} catch ( e ) {}
					}
				} else {
					console.log( 'No invalid fields found before submit.' );
				}

				console.log( 'Attempting to click submit button...' );

				// クリック前にナビゲーションリスナーを設定
				// 送信後に遷移する場合/しない場合のどちらでもログを残せるようにする。
				// 登録成功後はログイン画面（vkbm_auth=login）へ遷移するため、その URL への遷移を待つ。
				// （networkidle や固定待ちは使わない #897）
				const navigationPromise = page
					.waitForURL( /vkbm_auth=login/, { timeout: 30000 } )
					.catch( ( e ) =>
						console.log( 'Navigation wait error or timeout:', e )
					);

				await submitButton.first().click();
				console.log( 'Submit button clicked' );

				await navigationPromise;
				console.log( 'Navigation completed' );
			} else {
				console.log( 'ERROR: Submit button not found!' );
			}

			// 登録完了（上の URL 遷移）は待機済み。
			console.log( 'User registration completed' );

			// デバッグ: 登録が実際に成功したか失敗したかを確認
			const currentUrlReg = page.url();
			console.log( 'URL after registration submit:', currentUrlReg );

			// 登録後にエラーメッセージが表示されているかを確認
			const regError = page.locator(
				'.vkbm-alert.vkbm-alert__danger, .vkbm-confirm__auth-error'
			);
			if ( await regError.isVisible() ) {
				console.log(
					'Registration error:',
					await regError.textContent()
				);
			} else {
				console.log( 'No visible registration error found' );
				// 失敗が疑われるがエラーが表示されない場合はHTMLをダンプ
				if ( E2E_DEBUG ) {
					console.log(
						'--- Registration Page HTML Dump (Start) ---'
					);
					console.log(
						await page
							.locator( '.vkbm-confirm__auth-panel' )
							.innerHTML()
					);
					console.log( '--- Registration Page HTML Dump (End) ---' );
				}
			}

			// 登録直後のログイン状態を確認
			const bodyClassesAfterReg = await page
				.locator( 'body' )
				.getAttribute( 'class' );
			console.log(
				'Body classes after registration:',
				bodyClassesAfterReg
			);

			// 新規作成したユーザーでログイン
			// 登録成功後はすでにログインフォームが表示されている。
			// 表示されていない場合だけ、確認ページを開き直してログインボタンから開く。
			// @step 表示されたログインフォームに登録したユーザー名とパスワードを入れてログインする
			const loginUsernameInput = page
				.locator( 'input[name="log"]' )
				.or( page.locator( 'input[name="username"]' ) )
				.or( page.locator( '#vkbm-login-username' ) ); // IDセレクタを追加

			const loginFormShown = await loginUsernameInput
				.waitFor( { state: 'visible', timeout: 15000 } )
				.then( () => true )
				.catch( () => false );

			if ( ! loginFormShown ) {
				console.log( 'Navigating to login...' );
				if ( draftToken ) {
					await page.goto(
						`${ WP_BASE_URL }/booking/?draft=${ draftToken }`
					);
				}

				// ログインボタンをクリック
				const loginButton = page
					.getByRole( 'button', { name: 'ログイン', exact: false } )
					.or(
						page.getByRole( 'button', { name: 'Log in', exact: false } )
					);

				// goto 直後は描画前のことがあるため、表示を待ってから判定する。
				const loginButtonVisible = await loginButton
					.waitFor( { state: 'visible', timeout: 10000 } )
					.then( () => true )
					.catch( () => false );
				if ( loginButtonVisible ) {
					console.log( 'Clicking login button...' );
					await loginButton.click();
				}
			}

			// ログインフォームに入力
			// （ボタンから開いた場合は表示まで待つ。表示されなければ入力をスキップする従来動作のまま）
			await loginUsernameInput
				.waitFor( { state: 'visible', timeout: 10000 } )
				.catch( () => {} );

			if ( await loginUsernameInput.isVisible().catch( () => false ) ) {
				console.log( 'Filling in login form...' );
				await loginUsernameInput.fill( username );

				const loginPasswordInput = page
					.locator( 'input[name="pwd"]' )
					.or( page.locator( 'input[name="password"]' ) )
					.or( page.locator( '#vkbm-login-password' ) ); // IDセレクタを追加
				await loginPasswordInput.fill( password );

				// ログインを送信
				const loginSubmitButton = page
					.locator(
						'#vkbm-provider-login-form button[type="submit"]'
					)
					.or( page.locator( '.vkbm-auth-button[type="submit"]' ) );
				// ログイン送信後のリダイレクト先の読み込み完了を待つため、クリック前に仕掛けておく。
				// Cookie は 302 応答で付くので、Cookie 確認だけでは遷移先がまだ読み込み中のことがあり、
				// 直後の reload と衝突する（Navigation interrupted）。
				const afterLoginLoad = page.waitForEvent( 'load', {
					timeout: 15000,
				} );
				await loginSubmitButton.click();

				// ログイン完了を待つ
				// ログイン成功の実体は WordPress のログイン Cookie の発行。
				// 固定待ち・networkidle の代わりに、この Cookie が付くまでポーリングする。
				await expect
					.poll(
						async () =>
							( await page.context().cookies() ).some( ( c ) =>
								c.name.startsWith( 'wordpress_logged_in_' )
							),
						{ timeout: 15000 }
					)
					.toBe( true );
				// リダイレクト先の読み込み完了まで待ってから、URL 取得・reload を行う。
				await afterLoginLoad;

				console.log( 'Login request completed' );
				console.log( 'Current URL after login:', page.url() );

				// ログインエラーを確認
				const loginError = page.locator(
					'.vkbm-alert.vkbm-alert__danger, .vkbm-confirm__auth-error'
				);
				if ( await loginError.isVisible() ) {
					console.log(
						'Login error:',
						await loginError.textContent()
					);
				}

				// ログイン状態がbodyクラスに反映されるように明示的にリロード
				// 一部環境でSPA描画の状態同期が遅れるため、再読込で安定化させる。
				await page.reload();
			}
		}

		// デバッグ: ログイン状態を確認
		const bodyClasses = await page
			.locator( 'body' )
			.getAttribute( 'class' );
		console.log( 'Body classes:', bodyClasses );
		const isLoggedIn = bodyClasses?.includes( 'logged-in' );
		console.log( 'Is logged in:', isLoggedIn );

		// 9. 同意事項をチェック
		console.log( 'Checking for agreement checkboxes...' );

		// 確認画面のまとめが描画されるのを待ってから同意欄を処理する（reload 後の固定待ちの代わり）。
		// @step 確認画面で、表示されている同意のチェックボックスにすべてチェックを入れる
		await page
			.locator( '.vkbm-confirm__summary' )
			.waitFor( { state: 'visible', timeout: 15000 } );

		// 文面の描画を待ってから、表示される同意欄だけチェックする（固定待ちは使わない）。
		await checkConfirmConsents( page );

		// デバッグ: ボタンの状態を確認
		const finalConfirmButton = page.locator( '.vkbm-confirm__button' );
		const isDisabled = await finalConfirmButton.getAttribute( 'disabled' );
		console.log( 'Button disabled attribute:', isDisabled );

		// 10. 予約を確定
		// @check 「予約を確定する」ボタンが押せる状態になる
		await expect( finalConfirmButton ).toBeEnabled( { timeout: 10000 } );
		// @step 「予約を確定する」ボタンを押す
		await finalConfirmButton.click();

		// 11. 完了を確認
		// 「予約が完了しました」メッセージ
		// 予約作成は REST（/vkbm/v1/bookings）の応答後に表示されるため、既定の 5 秒では足りない環境がある。
		// @check 「予約が完了しました」と表示される
		await expect(
			page
				.getByText( '予約が完了しました', { exact: false } )
				.or( page.getByText( 'Booking completed', { exact: false } ) )
		).toBeVisible( { timeout: 15000 } );
	} );
} );
