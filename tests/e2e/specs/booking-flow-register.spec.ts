import { test, expect } from '@playwright/test';
import { disableEmailVerification } from '../utils/setup';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:1900';
const E2E_DEBUG = process.env.E2E_DEBUG === 'true' || process.env.E2E_DEBUG === '1';

// ヘッダー情報から機密情報をマスクする関数
// cookie, authorization, set-cookieなどの値を***REDACTED***に置き換える
const maskHeaders = (headers: Record<string, string>) => {
    const masked = { ...headers };
    ['cookie', 'authorization', 'set-cookie'].forEach(h => {
        Object.keys(masked).forEach(key => {
            if (key.toLowerCase() === h) masked[key] = '***REDACTED***';
        });
    });
    return masked;
};

// データオブジェクトから機密情報をマスクする関数
// email, phone, password, token, nonceなどのフィールドを***REDACTED***に置き換える
const maskSensitiveData = (data: any): any => {
    if (!data || typeof data !== 'object') return data;
    if (Array.isArray(data)) return data.map(maskSensitiveData);
    const masked = { ...data };
    const sensitive = ['email', 'phone', 'password', 'pass', 'pwd', 'token', 'nonce'];
    Object.keys(masked).forEach(key => {
        if (sensitive.some(s => key.toLowerCase().includes(s))) masked[key] = '***REDACTED***';
        else if (typeof masked[key] === 'object') masked[key] = maskSensitiveData(masked[key]);
    });
    return masked;
};

// POSTデータから機密情報をマスクする関数
// JSON形式またはURLエンコード形式のデータから機密情報を除去してログ出力用に整形
const maskPostData = (postData: string | null, contentType: string = '') => {
    if (!postData) return null;
    try {
        // JSON形式の場合
        if (contentType.includes('application/json') || (postData.trim().startsWith('{') && postData.trim().endsWith('}'))) {
             return JSON.stringify(maskSensitiveData(JSON.parse(postData)), null, 2);
        }
        // URLエンコード形式の場合
        if (contentType.includes('application/x-www-form-urlencoded')) {
            const params = new URLSearchParams(postData);
            const sensitive = ['email', 'phone', 'password', 'pass', 'pwd', 'token', 'nonce', 'log'];
            const maskedParams = new URLSearchParams();
            params.forEach((val, key) => {
                if (sensitive.some(s => key.toLowerCase().includes(s))) maskedParams.append(key, '***REDACTED***');
                else maskedParams.append(key, val);
            });
            return maskedParams.toString();
        }
    } catch { }
    return postData;
};

// 新規ユーザー登録を含む予約フローのテスト（メール認証なし）
test.describe('Booking Flow with New User Registration (No Email Verification)', () => {
    // 各テスト実行前にWordPressのトランジェントを削除してレート制限を回避
    test.beforeEach(async ({ page }) => {
        const { execSync } = await import('child_process');
        execSync('npx wp-env run cli wp transient delete --all', { stdio: 'ignore' });
        console.log('Deleted transients');
    });

    // すべてのテスト実行前にメール認証を無効化し、予約ページとテストデータを作成
    test.beforeAll(async () => {
        console.log('=== beforeAll: Starting test data setup ===');
        const { execSync } = await import('child_process');
        
        try {
            // disableEmailVerificationを使用して、予約ページとテストデータも作成
            await disableEmailVerification();
            console.log('=== beforeAll: disableEmailVerification completed ===');
            
            // サービスメニューが作成されたか確認
            const serviceMenus = execSync('npx wp-env run cli wp post list --post_type=vkbm_service_menu --format=count', { encoding: 'utf-8' }).trim();
            console.log(`=== beforeAll: Service menus created: ${serviceMenus} ===`);
            
            // 予約ページが作成されたか確認（タイトルで検索）
            const bookingPages = execSync('npx wp-env run cli wp post list --post_type=page --s="Booking" --format=count', { encoding: 'utf-8' }).trim();
            console.log(`=== beforeAll: Booking pages created: ${bookingPages} ===`);
            
            if (serviceMenus === '0') {
                throw new Error('No service menus were created in beforeAll!');
            }
            
            if (bookingPages === '0') {
                throw new Error('No booking page was created in beforeAll!');
            }
        } catch (error) {
            console.error('=== beforeAll: Setup failed ===', error);
            throw error;
        }
    });

    test.use({ storageState: { cookies: [], origins: [] } });

    const password = 'TestPassword123!';

    test('should allow a new user to register and complete a booking', async ({ page, context }) => {
        const username = `user-${Date.now()}-${Math.random().toString(36).substring(7)}`;
        const email = `${username}@example.com`;
        // コンソールログを監視
        page.on('console', msg => console.log(`Browser Console: ${msg.text()}`));

        // リクエストを監視
        page.on('request', request => {
            if (request.method() === 'POST' && (request.url().includes('vkbm') || request.url().includes('booking'))) {
                console.log(`--- POST Request: ${request.url()} ---`);

                if (E2E_DEBUG) {
                    console.log('Headers:', JSON.stringify(maskHeaders(request.headers())));
                    const postData = request.postData();
                    const contentType = request.headers()['content-type'] || '';
                    console.log('Post Data:', maskPostData(postData, contentType));
                    console.log('--------------------');
                }
            }
        });

        // 1. 予約ページに移動
        console.log('Initial Page URL:', `${WP_BASE_URL}/booking/`);
        await page.goto(`${WP_BASE_URL}/booking/`);
        await page.waitForLoadState('networkidle');
        
        // ページが正しく読み込まれたか確認
        const pageTitle = await page.title();
        console.log('Page title:', pageTitle);
        
        const initialUrl = page.url();
        console.log('Current URL after navigation:', initialUrl);
        
        // 404エラーでないことを確認
        const is404 = await page.locator('body').evaluate(el => el.textContent?.includes('404') || false);
        if (is404) {
            console.error('ERROR: Booking page returned 404!');
            const { execSync } = await import('child_process');
            const pages = execSync('npx wp-env run cli wp post list --post_type=page --format=table', { encoding: 'utf-8' });
            console.log('Available pages:', pages);
        }
        
        // 2. サービスメニューを選択
        console.log('Waiting for service menu button...');
        
        // まずサービスメニューが実際に存在するか確認
        const { execSync } = await import('child_process');
        const serviceMenuCount = execSync('npx wp-env run cli wp post list --post_type=vkbm_service_menu --post_status=publish --format=count', { encoding: 'utf-8' }).trim();
        console.log(`Published service menus in database: ${serviceMenuCount}`);
        
        if (serviceMenuCount === '0') {
            console.error('CRITICAL: No published service menus found in database!');
            // すべてのサービスメニューを確認（下書きなども含む）
            const allMenus = execSync('npx wp-env run cli wp post list --post_type=vkbm_service_menu --post_status=any --format=table', { encoding: 'utf-8' });
            console.log('All service menus (any status):', allMenus);
        }
        
        // 適切なタイムアウトでサービスメニューボタンを探す
        try {
            // まず新しいボタンスタイルを試す
            await page.waitForSelector('.vkbm-menu-loop__button--reserve', { state: 'visible', timeout: 10000 });
            console.log('Service menu button found (.vkbm-menu-loop__button--reserve). Clicking...');
            await page.locator('.vkbm-menu-loop__button--reserve').first().click();
        } catch (e) {
            console.log('New style button not found, trying old style (.vkbm-service-menu-card)...');
            try {
                await page.waitForSelector('.vkbm-service-menu-card', { state: 'visible', timeout: 10000 });
                console.log('Found .vkbm-service-menu-card. Clicking...');
                await page.locator('.vkbm-service-menu-card').first().click();
            } catch (e2) {
                console.log('No service menu buttons found. Dumping debug information...');
                
                // Check if menu loop container exists
                const menuLoop = await page.locator('.vkbm-menu-loop').count();
                console.log(`Menu loop containers found: ${menuLoop}`);
                
                // Check if any menu items exist
                const menuItems = await page.locator('.vkbm-menu-loop__item').count();
                console.log(`Menu items found: ${menuItems}`);
                
                // Check page HTML structure
                console.log('--- Page Content Dump (Start) ---');
                const bodyContent = await page.locator('body').textContent();
                console.log(bodyContent);
                console.log('--- Page Content Dump (End) ---');
                
                throw new Error('Service menu button not found on the page');
            }
        }
        
        // 3. カレンダービューの表示を待つ
        console.log('Waiting for calendar view...');
        await page.waitForSelector('.vkbm-calendar', { state: 'visible', timeout: 10000 });
        
        // 4. 利用可能な日付を選択（無効化されていない最初の日付）
        console.log('Selecting an available date...');
        const availableDate = page.locator('.vkbm-calendar__day:not([disabled]):not(.vkbm-calendar__day--disabled)').first();
        await availableDate.waitFor({ state: 'visible', timeout: 5000 });
        await availableDate.click();
        await page.waitForTimeout(1000);
        
        // 5. ランダムな時間枠を選択
        const slots = page.locator('.vkbm-slot-list__item');
        const count = await slots.count();
        if (count > 0) {
            const randomIndex = Math.floor(Math.random() * count);
            console.log(`Selecting random slot index: ${randomIndex} of ${count}`);
            await slots.nth(randomIndex).click();
        } else {
            console.log('No slots found!');
            await slots.first().click(); // Fallback to fail naturally
        }
        await page.waitForTimeout(1000);
        
        // 6. 「予約内容を確認」ボタンをクリック
        const confirmButton = page.locator('.vkbm-plan-summary__action').first();
        await confirmButton.click();
        await page.waitForLoadState('networkidle', { timeout: 10000 });
        await page.waitForTimeout(2000);
        
        // URLからドラフトトークンを保存（デバッグ用、WP-CLIハック用ではない）
        const currentUrl = page.url();
        const urlParams = new URL(currentUrl).searchParams;
        const draftToken = urlParams.get('draft') || '';
        
        // 7. ページ最上部にスクロールし、認証選択または登録ボタンを確認
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(1000);
        
        const authSelect = page.locator('.vkbm-confirm__auth');
        const authSelectVisible = await authSelect.isVisible().catch(() => false);
        console.log('Auth select visible:', authSelectVisible);
        
        // 登録ボタンをクリック
        const registerButton = page.getByRole('button', { name: '新規登録', exact: false })
            .or(page.getByRole('button', { name: 'Sign up', exact: false }))
            .or(page.getByRole('button', { name: '登録', exact: false }))
            .or(page.getByRole('button', { name: 'Register', exact: false }));
        
        const registerButtonVisible = await registerButton.isVisible().catch(() => false);
        console.log('Register button visible:', registerButtonVisible);
        
        if (registerButtonVisible) {
            console.log('Clicking register button...');
            await registerButton.click();
            await page.waitForTimeout(2000);
        } else {
            // 登録ボタンが見つからない場合は、登録ページに直接移動
            console.log('Register button not found, navigating to register page...');
            await page.goto(`${WP_BASE_URL}/booking/?draft=${draftToken}&vkbm_auth=register`);
        }
        
        await page.waitForLoadState('networkidle', { timeout: 10000 });
        await page.waitForTimeout(2000);
        
        
        // 8. 登録フォームが表示されている場合は入力
        // 実装では特定のID/name属性を使用しているため、様々なセレクタを試行
        const usernameInput = page.locator('#vkbm-register-username')
            .or(page.locator('input[name="vkbm_user_login"]'))
            .or(page.locator('input[name="username"]'));
        
        // 登録ボタンクリック後、ユーザー名入力欄が表示されるまで待機
        try {
            await usernameInput.waitFor({ state: 'visible', timeout: 5000 });
        } catch (e) {
            console.log('Username input not visible after waiting');
            
            // デバッグ: 認証パネルの内容を出力
            const authPanel = page.locator('.vkbm-confirm__auth-panel');
            if (await authPanel.isVisible()) {
                console.log('Auth panel is visible');
                // console.log('Auth panel HTML:', await authPanel.innerHTML()); // ノイズ削減のためコメントアウト
            } else {
                console.log('Auth panel is NOT visible');
            }
        }
        
        const usernameVisible = await usernameInput.isVisible().catch(() => false);
        console.log('Username input visible:', usernameVisible);
        
        if (usernameVisible) {
            console.log('Filling in registration form...');
            console.log('About to enter try block...');
            
            try {
                console.log('Step 1: Filling username...');
                await usernameInput.fill(username);
                console.log('✓ Username filled:', username);
                
                console.log('Step 2: Filling email...');
                const emailInput = page.locator('#vkbm-register-email')
                    .or(page.locator('input[name="vkbm_user_email"]'))
                    .or(page.locator('input[name="email"]'));
                await emailInput.fill(email);
                console.log('✓ Email filled:', email);
                
                console.log('Step 3: Filling password...');
                const passwordInput = page.locator('#vkbm-register-password')
                    .or(page.locator('input[name="vkbm_user_pass"]'))
                    .or(page.locator('input[name="password"]'));
                await passwordInput.fill(password);
                console.log('✓ Password filled');
            
             // パスワード確認フィールドが存在する場合は入力
            const confirmPass = page.locator('#vkbm-register-password-confirm')
                .or(page.locator('input[name="user_pass_confirm"]'))
                .or(page.locator('input[name="password_confirmation"]'));
            
            if (await confirmPass.isVisible().catch(() => false)) {
                console.log('Step 4: Filling password confirmation...');
                await confirmPass.fill(password);
                console.log('✓ Password confirmation filled');
            } else {
                console.log('Step 4: Password confirmation field not visible (Skipped)');
            }

            // 名前フィールド
            console.log('Step 5: Filling name fields...');
            const lastNameInput = page.locator('#vkbm-register-last-name')
                .or(page.locator('input[name="vkbm_last_name"]'))
                .or(page.locator('input[name="last_name"]'));
            
            const firstNameInput = page.locator('#vkbm-register-first-name')
                .or(page.locator('input[name="vkbm_first_name"]'))
                .or(page.locator('input[name="first_name"]'));
                
            await lastNameInput.fill('Test');
            await firstNameInput.fill('User');
            console.log('✓ Name fields filled');

            // 設定によってはカナ名/電話番号が必須の場合がある
            // カナ名入力
            const kanaInput = page.locator('input[name="kana_name"]')
                 .or(page.locator('#vkbm-register-kana'));

            console.log('Checking for Kana input...');
            if (await kanaInput.isVisible().catch(() => false)) {
                 await kanaInput.fill('テストユーザー'); 
                 console.log('✓ Kana name filled');
            } else {
                 console.log('kana_name field not found!');
            }
            
             const phoneInput = page.locator('#vkbm-register-phone')
                .or(page.locator('input[name="phone_number"]'))
                .or(page.locator('input[name="vkbm_phone"]'))
                .or(page.locator('input[name="phone"]'));
                
             if (await phoneInput.isVisible().catch(() => false)) {
                 console.log('Step 6: Filling phone...');
                 await phoneInput.fill('09012345678');
                 console.log('✓ Phone filled');
             }
             
             console.log('All basic fields filled successfully');
             
            } catch (error) {
                console.error('ERROR filling registration form:', error);
                throw error;
            }
            
            // 登録フォーム内の利用規約チェックボックスを確認
            const registerTerms = page.locator('#vkbm-register-terms')
               .or(page.locator('input[name="vkbm_agree_terms_of_service"]'));
            
            const termsVisible = await registerTerms.isVisible().catch(() => false);
            console.log('Terms checkbox visible:', termsVisible);
            
            if (termsVisible) {
                console.log('Checking registration terms...');
                await registerTerms.check();
                await page.waitForTimeout(500); // チェックボックスの状態が更新されるまで待機
                
                // チェックボックスがチェックされたことを確認
                const isChecked = await registerTerms.isChecked().catch(() => false);
                console.log('Terms checkbox is checked:', isChecked);
            }
            
            // 性別と生年月日
            const genderSelect = page.locator('select[name="gender"]');
            if (await genderSelect.isVisible().catch(() => false)) {
                await genderSelect.selectOption({ index: 1 });
                console.log('✓ Gender selected');
            }

            const birthYear = page.locator('select[name="birth_year"]');
             if (await birthYear.isVisible().catch(() => false)) {
                await birthYear.selectOption({ index: 20 }); // 約20年前を選択
                console.log('✓ Birth year selected');
            }
            const birthMonth = page.locator('select[name="birth_month"]');
            if (await birthMonth.isVisible().catch(() => false)) {
                await birthMonth.selectOption({ index: 1 });
                 console.log('✓ Birth month selected');
            }
            const birthDay = page.locator('select[name="birth_day"]');
            if (await birthDay.isVisible().catch(() => false)) {
                 await birthDay.selectOption({ index: 1 });
                 console.log('✓ Birth day selected');
            }

            // プライバシーポリシーを確認
            const privacyPolicy = page.locator('#vkbm-register-privacy-policy')

               .or(page.locator('input[name="vkbm_agree_privacy_policy"]'));
            
            const privacyVisible = await privacyPolicy.isVisible().catch(() => false);
            console.log('Privacy policy checkbox visible:', privacyVisible);
            
            if (privacyVisible) {
                console.log('Checking privacy policy...');
                await privacyPolicy.check();
                await page.waitForTimeout(500);
            }

            // 送信前に少し待機して、すべてのバリデーションが完了するのを確認
            await page.waitForTimeout(1000);

            // 登録のデバッグ用にネットワークレスポンスをリッスン
            let registrationResponse: any = null;
            page.on('response', async (response) => {
                const url = response.url();
                if (url.includes('/wp-json/vkbm/v1/') || url.includes('vkbm') || url.includes('register')) {
                    console.log(`Network response: ${url} Status: ${response.status()}`);
                    
                    let body = '';
                    try {
                        body = await response.text();
                        registrationResponse = { url, status: response.status(), body };
                    } catch {}

                    if (E2E_DEBUG && body) {
                        const contentType = response.headers()['content-type'] || '';
                        if (contentType.includes('json') || (body.trim().startsWith('{') && body.trim().endsWith('}'))) {
                             console.log('Response body:', maskPostData(body, 'application/json'));
                        } else if (body.length < 1000) {
                             console.log('Response body: [Non-JSON]');
                        }
                    }
                }
            });

            // 登録を送信
            // タブボタン「新規登録」と一致しないように特定のセレクタを使用
            const submitButton = page.locator('#vkbm-provider-register-form button[type="submit"]')
                .or(page.locator('.vkbm-auth-button[type="submit"]'));
            
            // 送信ボタンが存在し、表示されているかを確認
            const submitButtonExists = await submitButton.count();
            console.log('Submit button count:', submitButtonExists);
            
            if (submitButtonExists > 0) {
                const submitButtonVisible = await submitButton.first().isVisible().catch(() => false);
                console.log('Submit button visible:', submitButtonVisible);
                
                // 送信ボタンが有効かどうかを確認
                const isEnabled = await submitButton.first().isEnabled().catch(() => false);
                console.log('Submit button is enabled:', isEnabled);
                
                // クリック前にバリデーションエラーを確認
                const invalidElements = page.locator('.is-invalid');
                const invalidCount = await invalidElements.count();
                if (invalidCount > 0) {
                    console.log(`Found ${invalidCount} invalid fields!`);
                    for (let i = 0; i < invalidCount; i++) {
                        const el = invalidElements.nth(i);
                        const id = await el.getAttribute('id');
                        const name = await el.getAttribute('name');
                        console.log(`Invalid field ${i}: id=${id}, name=${name}`);
                        
                        try {
                            const parent = el.locator('..');
                            const feedback = parent.locator('.vkbm-invalid-feedback');
                            if (await feedback.count() > 0) {
                                 console.log(`Error message: ${await feedback.innerText()}`);
                            }
                        } catch (e) {}
                    }
                } else {
                    console.log('No invalid fields found before submit.');
                }
                
                console.log('Attempting to click submit button...');
                
                // クリック前にナビゲーションリスナーを設定
                const navigationPromise = page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 })
                    .catch(e => console.log('Navigation wait error or timeout:', e));
                
                await submitButton.first().click();
                console.log('Submit button clicked');
                
                await navigationPromise;
                console.log('Navigation completed');
            } else {
                console.log('ERROR: Submit button not found!');
            }
            
            // 登録完了を待つ
            await page.waitForLoadState('networkidle', { timeout: 10000 });
            await page.waitForTimeout(3000);
            
            console.log('User registration completed');
            
            // デバッグ: 登録が実際に成功したか失敗したかを確認
            const currentUrlReg = page.url();
            console.log('URL after registration submit:', currentUrlReg);
            
            // 登録後にエラーメッセージが表示されているかを確認
            const regError = page.locator('.vkbm-alert.vkbm-alert__danger, .vkbm-confirm__auth-error');
            if (await regError.isVisible()) {
                console.log('Registration error:', await regError.textContent());
            } else {
                console.log('No visible registration error found');
                // 失敗が疑われるがエラーが表示されない場合はHTMLをダンプ
                if (E2E_DEBUG) {
                     console.log('--- Registration Page HTML Dump (Start) ---');
                     console.log(await page.locator('.vkbm-confirm__auth-panel').innerHTML());
                     console.log('--- Registration Page HTML Dump (End) ---');
                }
            }
            
            // 登録直後のログイン状態を確認
            const bodyClassesAfterReg = await page.locator('body').getAttribute('class');
            console.log('Body classes after registration:', bodyClassesAfterReg);
            
            // 新規作成したユーザーでログイン
            // 確認ページに移動してログインフォームにアクセス
            console.log('Navigating to login...');
            if (draftToken) {
                await page.goto(`${WP_BASE_URL}/booking/?draft=${draftToken}`);
                await page.waitForLoadState('networkidle', { timeout: 10000 });
                await page.waitForTimeout(2000);
            }
            
            // ログインボタンをクリック
            const loginButton = page.getByRole('button', { name: 'ログイン', exact: false })
                .or(page.getByRole('button', { name: 'Log in', exact: false }));
            
            const loginButtonVisible = await loginButton.isVisible().catch(() => false);
            if (loginButtonVisible) {
                console.log('Clicking login button...');
                await loginButton.click();
                await page.waitForTimeout(2000);
                await page.waitForLoadState('networkidle', { timeout: 10000 });
            }
            
            // ログインフォームに入力
            const loginUsernameInput = page.locator('input[name="log"]')
                .or(page.locator('input[name="username"]'))
                .or(page.locator('#vkbm-login-username')); // IDセレクタを追加
            
            if (await loginUsernameInput.isVisible().catch(() => false)) {
                console.log('Filling in login form...');
                await loginUsernameInput.fill(username);
                
                const loginPasswordInput = page.locator('input[name="pwd"]')
                    .or(page.locator('input[name="password"]'))
                    .or(page.locator('#vkbm-login-password')); // IDセレクタを追加
                await loginPasswordInput.fill(password);
                
                // ログインを送信
                const loginSubmitButton = page.locator('#vkbm-provider-login-form button[type="submit"]')
                    .or(page.locator('.vkbm-auth-button[type="submit"]'));
                await loginSubmitButton.click();
                
                // ログイン完了を待つ
                await page.waitForLoadState('networkidle', { timeout: 10000 });
                await page.waitForTimeout(3000); // 待機時間を増加
                
                console.log('Login request completed');
                console.log('Current URL after login:', page.url());

                // ログインエラーを確認
                const loginError = page.locator('.vkbm-alert.vkbm-alert__danger, .vkbm-confirm__auth-error');
                if (await loginError.isVisible()) {
                    console.log('Login error:', await loginError.textContent());
                }
                
                // ログイン状態がbodyクラスに反映されるように明示的にリロード
                await page.reload();
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(2000);
            }
        }
        
        // デバッグ: ログイン状態を確認
        const bodyClasses = await page.locator('body').getAttribute('class');
        console.log('Body classes:', bodyClasses);
        const isLoggedIn = bodyClasses?.includes('logged-in');
        console.log('Is logged in:', isLoggedIn);
        
        // 9. 同意事項をチェック
        console.log('Checking for agreement checkboxes...');
        
        // ページが完全に読み込まれるまで少し待機
        await page.waitForTimeout(1000);
        
        // キャンセルポリシー
        try {
            const cancelPolicyCheckbox = page.locator('#vkbm-confirm-cancellation-policy');
            await cancelPolicyCheckbox.waitFor({ state: 'visible', timeout: 3000 });
            await cancelPolicyCheckbox.check();
            console.log('Checked cancellation policy checkbox');
        } catch (e) {
            console.log('Cancellation policy checkbox not found or not required');
        }

        // 利用規約
        try {
            const termsCheckbox = page.locator('#vkbm-confirm-terms');
            await termsCheckbox.waitFor({ state: 'visible', timeout: 3000 });
            await termsCheckbox.check();
            console.log('Checked terms checkbox');
        } catch (e) {
            console.log('Terms checkbox not found or not required');
        }

        // チェックボックスをチェックした後、ボタンが有効になるまで待機
        await page.waitForTimeout(500);
        
        // デバッグ: ボタンの状態を確認
        const finalConfirmButton = page.locator('.vkbm-confirm__button');
        const isDisabled = await finalConfirmButton.getAttribute('disabled');
        console.log('Button disabled attribute:', isDisabled);

        // 10. 予約を確定
        await expect(finalConfirmButton).toBeEnabled({ timeout: 10000 });
        await finalConfirmButton.click();

        // 11. 完了を確認
        // 「予約が完了しました」メッセージ
        await expect(page.getByText('予約が完了しました', { exact: false }).or(page.getByText('Booking completed', { exact: false }))).toBeVisible();
    });
});
