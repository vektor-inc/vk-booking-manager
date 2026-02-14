import { test, expect } from '@playwright/test';
import { disableEmailVerification } from '../utils/setup';

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:1900';
const E2E_DEBUG = process.env.E2E_DEBUG === 'true' || process.env.E2E_DEBUG === '1';

const maskHeaders = (headers: Record<string, string>) => {
    const masked = { ...headers };
    ['cookie', 'authorization', 'set-cookie'].forEach(h => {
        Object.keys(masked).forEach(key => {
            if (key.toLowerCase() === h) masked[key] = '***REDACTED***';
        });
    });
    return masked;
};

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

const maskPostData = (postData: string | null, contentType: string = '') => {
    if (!postData) return null;
    try {
        if (contentType.includes('application/json') || (postData.trim().startsWith('{') && postData.trim().endsWith('}'))) {
             return JSON.stringify(maskSensitiveData(JSON.parse(postData)), null, 2);
        }
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

test.describe('Booking Flow with New User Registration (No Email Verification)', () => {
    // Delete transients before each test to prevent rate limiting
    test.beforeEach(async ({ page }) => {
        const { execSync } = await import('child_process');
        execSync('npx wp-env run cli wp transient delete --all', { stdio: 'ignore' });
        console.log('Deleted transients');
    });

    test.beforeAll(async () => {
        // Use disableEmailVerification which also creates the booking page and test data
        // disableEmailVerificationを使用して、予約ページとテストデータも作成
        await disableEmailVerification();
    });

    test.use({ storageState: { cookies: [], origins: [] } });

    const password = 'TestPassword123!';

    test('should allow a new user to register and complete a booking', async ({ page, context }) => {
        const username = `user-${Date.now()}-${Math.random().toString(36).substring(7)}`;
        const email = `${username}@example.com`;
        // Monitor console logs
        page.on('console', msg => console.log(`Browser Console: ${msg.text()}`));

        // Monitor requests
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

        // 1. Navigate to booking page
        console.log('Initial Page URL:', `${WP_BASE_URL}/booking/`);
        await page.goto(`${WP_BASE_URL}/booking/`);
        await page.waitForLoadState('networkidle');
        
        // 2. Select a service menu
        console.log('Waiting for service menu button...');
        
        // Try to find the service menu button with a reasonable timeout
        try {
            // First try the new button style
            await page.waitForSelector('.vkbm-menu-loop__button--reserve', { state: 'visible', timeout: 5000 });
            console.log('Service menu button found (.vkbm-menu-loop__button--reserve). Clicking...');
            await page.locator('.vkbm-menu-loop__button--reserve').first().click();
        } catch (e) {
            console.log('New style button not found, trying old style (.vkbm-service-menu-card)...');
            try {
                await page.waitForSelector('.vkbm-service-menu-card', { state: 'visible', timeout: 5000 });
                console.log('Found .vkbm-service-menu-card. Clicking...');
                await page.locator('.vkbm-service-menu-card').first().click();
            } catch (e2) {
                console.log('No service menu buttons found. Dumping page content...');
                console.log('--- Page Content Dump (Start) ---');
                const bodyContent = await page.locator('body').textContent();
                console.log(bodyContent);
                console.log('--- Page Content Dump (End) ---');
                throw new Error('Service menu button not found on the page');
            }
        }
        
        // 3. Wait for calendar view
        console.log('Waiting for calendar view...');
        await page.waitForSelector('.vkbm-calendar', { state: 'visible', timeout: 10000 });
        
        // 4. Select a date (first available date that is not disabled)
        console.log('Selecting an available date...');
        const availableDate = page.locator('.vkbm-calendar__day:not([disabled]):not(.vkbm-calendar__day--disabled)').first();
        await availableDate.waitFor({ state: 'visible', timeout: 5000 });
        await availableDate.click();
        await page.waitForTimeout(1000);
        
        // 5. Select a random time slot
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
        
        // 6. Click "Confirm Reservation Details" button
        const confirmButton = page.locator('.vkbm-plan-summary__action').first();
        await confirmButton.click();
        await page.waitForLoadState('networkidle', { timeout: 10000 });
        await page.waitForTimeout(2000);
        
        // Save draft token from URL (for debugging context if needed, but not for WP-CLI hack)
        const currentUrl = page.url();
        const urlParams = new URL(currentUrl).searchParams;
        const draftToken = urlParams.get('draft') || '';
        
        // 7. Scroll to top and check for auth selection or register button
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(1000);
        
        const authSelect = page.locator('.vkbm-confirm__auth');
        const authSelectVisible = await authSelect.isVisible().catch(() => false);
        console.log('Auth select visible:', authSelectVisible);
        
        // Click register button
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
            // If register button not found, navigate directly to register page
            console.log('Register button not found, navigating to register page...');
            await page.goto(`${WP_BASE_URL}/booking/?draft=${draftToken}&vkbm_auth=register`);
        }
        
        await page.waitForLoadState('networkidle', { timeout: 10000 });
        await page.waitForTimeout(2000);
        
        // 8. Fill in registration form if visible
        // Try various selectors as the implementation uses specific IDs/names
        const usernameInput = page.locator('#vkbm-register-username')
            .or(page.locator('input[name="vkbm_user_login"]'))
            .or(page.locator('input[name="username"]'));
        
        // Wait for username input to appear after clicking register button
        try {
            await usernameInput.waitFor({ state: 'visible', timeout: 5000 });
        } catch (e) {
            console.log('Username input not visible after waiting');
            
            // Debug: Print the content of auth panel
            const authPanel = page.locator('.vkbm-confirm__auth-panel');
            if (await authPanel.isVisible()) {
                console.log('Auth panel is visible');
                // console.log('Auth panel HTML:', await authPanel.innerHTML()); // Comment out to reduce noise
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
            
             // Confirm password if the field exists
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

            // Name fields
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

            // Kana/Phone might be required depending on settings
            // Kana Name input
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
            
            // Check for terms agreement in registration form
            const registerTerms = page.locator('#vkbm-register-terms')
               .or(page.locator('input[name="vkbm_agree_terms_of_service"]'));
            
            const termsVisible = await registerTerms.isVisible().catch(() => false);
            console.log('Terms checkbox visible:', termsVisible);
            
            if (termsVisible) {
                console.log('Checking registration terms...');
                await registerTerms.check();
                await page.waitForTimeout(500); // Wait for checkbox state to update
                
                // Verify checkbox is checked
                const isChecked = await registerTerms.isChecked().catch(() => false);
                console.log('Terms checkbox is checked:', isChecked);
            }
            
            // Gender and Birth Date
            const genderSelect = page.locator('select[name="gender"]');
            if (await genderSelect.isVisible().catch(() => false)) {
                await genderSelect.selectOption({ index: 1 });
                console.log('✓ Gender selected');
            }

            const birthYear = page.locator('select[name="birth_year"]');
             if (await birthYear.isVisible().catch(() => false)) {
                await birthYear.selectOption({ index: 20 }); // Select roughly 20 years ago
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

            // Check for privacy policy
            const privacyPolicy = page.locator('#vkbm-register-privacy-policy')

               .or(page.locator('input[name="vkbm_agree_privacy_policy"]'));
            
            const privacyVisible = await privacyPolicy.isVisible().catch(() => false);
            console.log('Privacy policy checkbox visible:', privacyVisible);
            
            if (privacyVisible) {
                console.log('Checking privacy policy...');
                await privacyPolicy.check();
                await page.waitForTimeout(500);
            }

            // Wait a bit before submitting to ensure all validations pass
            await page.waitForTimeout(1000);

            // Listen for network responses to debug registration
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

            // Submit registration
            // Use specific selector to avoid matching the tab button "新規登録"
            const submitButton = page.locator('#vkbm-provider-register-form button[type="submit"]')
                .or(page.locator('.vkbm-auth-button[type="submit"]'));
            
            // Check if submit button exists and is visible
            const submitButtonExists = await submitButton.count();
            console.log('Submit button count:', submitButtonExists);
            
            if (submitButtonExists > 0) {
                const submitButtonVisible = await submitButton.first().isVisible().catch(() => false);
                console.log('Submit button visible:', submitButtonVisible);
                
                // Check if submit button is enabled
                const isEnabled = await submitButton.first().isEnabled().catch(() => false);
                console.log('Submit button is enabled:', isEnabled);
                
                // Check for validation errors BEFORE clicking
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
                
                // Set up navigation listener BEFORE clicking
                const navigationPromise = page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 })
                    .catch(e => console.log('Navigation wait error or timeout:', e));
                
                await submitButton.first().click();
                console.log('Submit button clicked');
                
                await navigationPromise;
                console.log('Navigation completed');
            } else {
                console.log('ERROR: Submit button not found!');
            }
            
            // Wait for registration to complete
            await page.waitForLoadState('networkidle', { timeout: 10000 });
            await page.waitForTimeout(3000);
            
            console.log('User registration completed');
            
            // Debug: Check if registration actually succeeded or failed
            const currentUrlReg = page.url();
            console.log('URL after registration submit:', currentUrlReg);
            
            // Check if any error message is visible after registration
            const regError = page.locator('.vkbm-alert.vkbm-alert__danger, .vkbm-confirm__auth-error');
            if (await regError.isVisible()) {
                console.log('Registration error:', await regError.textContent());
            } else {
                console.log('No visible registration error found');
                // Dump HTML if we suspect failure but see no error
                if (E2E_DEBUG) {
                     console.log('--- Registration Page HTML Dump (Start) ---');
                     console.log(await page.locator('.vkbm-confirm__auth-panel').innerHTML());
                     console.log('--- Registration Page HTML Dump (End) ---');
                }
            }
            
            // Check login state immediately after registration
            const bodyClassesAfterReg = await page.locator('body').getAttribute('class');
            console.log('Body classes after registration:', bodyClassesAfterReg);
            
            // Now login with the newly created user
            // Navigate to the confirmation page to access login form
            console.log('Navigating to login...');
            if (draftToken) {
                await page.goto(`${WP_BASE_URL}/booking/?draft=${draftToken}`);
                await page.waitForLoadState('networkidle', { timeout: 10000 });
                await page.waitForTimeout(2000);
            }
            
            // Click login button
            const loginButton = page.getByRole('button', { name: 'ログイン', exact: false })
                .or(page.getByRole('button', { name: 'Log in', exact: false }));
            
            const loginButtonVisible = await loginButton.isVisible().catch(() => false);
            if (loginButtonVisible) {
                console.log('Clicking login button...');
                await loginButton.click();
                await page.waitForTimeout(2000);
                await page.waitForLoadState('networkidle', { timeout: 10000 });
            }
            
            // Fill in login form
            const loginUsernameInput = page.locator('input[name="log"]')
                .or(page.locator('input[name="username"]'))
                .or(page.locator('#vkbm-login-username')); // Add ID selector
            
            if (await loginUsernameInput.isVisible().catch(() => false)) {
                console.log('Filling in login form...');
                await loginUsernameInput.fill(username);
                
                const loginPasswordInput = page.locator('input[name="pwd"]')
                    .or(page.locator('input[name="password"]'))
                    .or(page.locator('#vkbm-login-password')); // Add ID selector
                await loginPasswordInput.fill(password);
                
                // Submit login
                const loginSubmitButton = page.locator('#vkbm-provider-login-form button[type="submit"]')
                    .or(page.locator('.vkbm-auth-button[type="submit"]'));
                await loginSubmitButton.click();
                
                // Wait for login to complete
                await page.waitForLoadState('networkidle', { timeout: 10000 });
                await page.waitForTimeout(3000); // Increased wait time
                
                console.log('Login request completed');
                console.log('Current URL after login:', page.url());

                // Check for login error
                const loginError = page.locator('.vkbm-alert.vkbm-alert__danger, .vkbm-confirm__auth-error');
                if (await loginError.isVisible()) {
                    console.log('Login error:', await loginError.textContent());
                }
                
                // Explicitly reload to ensure login state is reflected in body class
                await page.reload();
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(2000);
            }
        }
        
        // Debug: Check login state
        const bodyClasses = await page.locator('body').getAttribute('class');
        console.log('Body classes:', bodyClasses);
        const isLoggedIn = bodyClasses?.includes('logged-in');
        console.log('Is logged in:', isLoggedIn);
        
        // 9. Check agreements
        console.log('Checking for agreement checkboxes...');
        
        // Wait a bit for the page to fully load
        await page.waitForTimeout(1000);
        
        // Cancellation Policy
        try {
            const cancelPolicyCheckbox = page.locator('#vkbm-confirm-cancellation-policy');
            await cancelPolicyCheckbox.waitFor({ state: 'visible', timeout: 3000 });
            await cancelPolicyCheckbox.check();
            console.log('Checked cancellation policy checkbox');
        } catch (e) {
            console.log('Cancellation policy checkbox not found or not required');
        }

        // Terms of Use
        try {
            const termsCheckbox = page.locator('#vkbm-confirm-terms');
            await termsCheckbox.waitFor({ state: 'visible', timeout: 3000 });
            await termsCheckbox.check();
            console.log('Checked terms checkbox');
        } catch (e) {
            console.log('Terms checkbox not found or not required');
        }

        // Wait for button to become enabled after checking boxes
        await page.waitForTimeout(500);
        
        // Debug: Check button state
        const finalConfirmButton = page.locator('.vkbm-confirm__button');
        const isDisabled = await finalConfirmButton.getAttribute('disabled');
        console.log('Button disabled attribute:', isDisabled);

        // 10. Confirm reservation
        await expect(finalConfirmButton).toBeEnabled({ timeout: 10000 });
        await finalConfirmButton.click();

        // 11. Verify Completion
        // "Booking Completed" message
        // 「予約が完了しました」メッセージ
        await expect(page.getByText('予約が完了しました', { exact: false }).or(page.getByText('Booking completed', { exact: false }))).toBeVisible();
    });
});
