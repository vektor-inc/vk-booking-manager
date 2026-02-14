import { execSync } from 'child_process';

/**
 * Configure provider settings via WP-CLI.
 * WP-CLI経由でプロバイダー設定を構成
 *
 * @param {Object} settings Settings to merge/update. / 更新する設定
 */
export const configureProviderSettings = async ( settings: any ) => {
	// Read current settings, merge with new settings, and write back
    // 現在の設定を読み取り、新しい設定とマージして書き戻します
    
    let currentSettings: any = {};
    
    // Try to get current settings
    // 現在の設定を取得しようとします
    try {
        const result = execSync( 'npx wp-env run cli wp option get vkbm_provider_settings --format=json', { encoding: 'utf-8' } );
        const parsed = JSON.parse(result.trim());
        // Ensure parsed result is a plain object before using it
        if (typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)) {
            currentSettings = parsed;
        } else {
            currentSettings = {};
        }
    } catch ( e ) {
        // Option doesn't exist or is empty, start with empty object
        // オプションが存在しないか空の場合、空のオブジェクトから開始
        console.log('vkbm_provider_settings does not exist yet, will create it');
        currentSettings = {};
    }
    
    // Merge settings
    // 設定をマージ
    const mergedSettings = { ...currentSettings, ...settings };
    
    // Write back as JSON using base64 to avoid shell escaping issues
    // シェルのエスケープ問題を回避するため、base64を使用してJSONを書き戻す
    const jsonSettings = JSON.stringify(mergedSettings);
    const base64Settings = Buffer.from(jsonSettings).toString('base64');
    
    const maxRetries = 3;
    let lastError;

    for (let i = 0; i < maxRetries; i++) {
        try {
            // Use base64 encoding to safely pass JSON through shell
            // base64エンコーディングを使用してシェル経由で安全にJSONを渡します
            // Add stdio: 'inherit' to see output
            execSync( `npx wp-env run cli bash -c "echo '${base64Settings}' | base64 -d | wp option update vkbm_provider_settings --format=json"`, { stdio: 'inherit' } );
            console.log('Updated vkbm_provider_settings:', Object.keys(settings).join(', '));
            return;
        } catch ( error ) {
            console.error( `Attempt ${i + 1} failed to update provider settings:`, (error as Error).message );
            lastError = error;
            // Wait a bit before retrying
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
    }
    
    console.error( `Failed to update provider settings after ${maxRetries} attempts` );
    throw lastError;
};

/**
 * Create a booking page with the reservation block.
 * 予約ブロックを含む予約ページを作成
 */
export const createBookingPage = () => {
    try {
        // Activate Plugin just in case
        // 念のためプラグインを有効化
        execSync( 'npx wp-env run cli wp plugin activate vk-booking-manager-pro' );

        // Install and switch to Japanese
        // 日本語のインストールと切り替え
        try {
            execSync( 'npx wp-env run cli wp language core install ja', { stdio: 'ignore' } );
            execSync( 'npx wp-env run cli wp site switch-language ja' );
        } catch ( e: any ) {
            // Ignore if already installed or fails
            console.warn( 'Failed to switch language to ja:', e.message );
        }

        // Switch theme to twentytwentyone for stable testing
        // テスト安定化のためテーマをTwenty Twenty-Oneに切り替え
        try {
            execSync( 'npx wp-env run cli wp theme install twentytwentyone --activate', { stdio: 'ignore' } );
        } catch (e: any) {
             console.warn( 'Failed to switch theme:', e.message );
        }

        // Set Permalinks
        // パーマリンクを設定
        execSync( 'npx wp-env run cli wp rewrite structure "/%postname%/" --hard' );

        // Clean up existing test data
        // 既存のテストデータをクリーンアップ（蓄積回避）
        try {
            const cleanupPostTypes = ['vkbm_resource', 'vkbm_service_menu', 'vkbm_shift'];
            for (const postType of cleanupPostTypes) {
                // Get all IDs
                const ids = execSync( `npx wp-env run cli wp post list --post_type=${postType} --post_status=any --format=ids`, { stdio: 'pipe' } ).toString().trim();
                if (ids) {
                    // Delete all
                    // IDリストはスペース区切りで渡す
                    execSync( `npx wp-env run cli wp post delete ${ids.replace(/\s+/g, ' ')} --force` );
                    console.log( `Cleaned up ${postType}: ${ids}` );
                }
            }
        } catch (e: any) {
            console.warn( 'Cleanup failed (non-critical):', e.message );
        }

        // Clean up Test Users (user_*)
        // テストユーザーの削除
        try {
            const userIds = execSync( 'npx wp-env run cli wp user list --search="user_*" --field=ID', { stdio: 'pipe' } ).toString().trim();
            if ( userIds ) {
                // Ensure IDs are space-separated
                execSync( `npx wp-env run cli wp user delete ${userIds.replace(/\s+/g, ' ')} --yes` );
                console.log( `Cleaned up test users: ${userIds}` );
            }
        } catch (e: any) {
            console.warn( 'User cleanup failed (non-critical):', e.message );
        }

        // Create Staff
        // スタッフを作成
        const staffId = execSync( 'npx wp-env run cli wp post create --post_type=vkbm_resource --post_title="Staff 1" --post_status=publish --porcelain' ).toString().trim();
        console.log( `Created Staff ID: ${staffId}` );

        // Create Shift for the current month
        // 今月のシフトを作成（これがないと予約できない）
        const createShiftCode = `
            $resource_id = ${staffId};
            $year = (int) current_time('Y');
            $month = (int) current_time('n');
            $days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
            
            $days = [];
            for ($d = 1; $d <= $days_in_month; $d++) {
                $days[$d] = [
                    'status' => 'open',
                    'slots' => [
                        ['start' => '09:00', 'end' => '18:00']
                    ]
                ];
            }
            
            $post_data = [
                'post_type'   => 'vkbm_shift',
                'post_status' => 'publish',
                'post_title'  => sprintf('%d year %02d month Staff 1', $year, $month),
            ];
            
            $post_id = wp_insert_post($post_data);
            
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, '_vkbm_shift_resource_id', $resource_id);
                update_post_meta($post_id, '_vkbm_shift_year', $year);
                update_post_meta($post_id, '_vkbm_shift_month', $month);
                update_post_meta($post_id, '_vkbm_shift_days', $days);
                echo $post_id;
            } else {
                echo 'Error: ' . $post_id->get_error_message();
            }
        `;
        
        // Use Base64 to avoid shell escaping issues
        const base64ShiftCode = Buffer.from( createShiftCode ).toString( 'base64' );
        execSync( `npx wp-env run cli wp eval 'eval(base64_decode("${base64ShiftCode}"));'` );
        console.log( 'Created Shift for current month' );

        // Create Service Menu
        // サービスメニューを作成
        const menuId = execSync( 'npx wp-env run cli wp post create --post_type=vkbm_service_menu --post_title="Service Menu 1" --post_status=publish --porcelain' ).toString().trim();

        // Assign Staff to Menu (using wp eval)
        // スタッフをメニューに割り当て (wp evalを使用)
        // Ensure IDs are treated as integers in PHP array
        const assignStaffCode = `update_post_meta(${menuId}, '_vkbm_staff_ids', array((int)${staffId}));`;
        execSync( `npx wp-env run cli wp eval "${assignStaffCode}"` );

        // Create or Update Booking Page
        // 予約ページを作成または更新
        // Check if exists
        // 存在確認
        let existingId = '';
        try {
             existingId = execSync( 'npx wp-env run cli wp post list --name=booking --field=ID', { stdio: 'pipe' } ).toString().trim();
        } catch (e) {
            // ignore
        }

        // Create content with block and paragraph
        const rawContent = '<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation"></div><!-- /wp:vk-booking-manager/reservation -->';
        const base64Content = Buffer.from( rawContent ).toString( 'base64' );

        // Create or Update Booking Page logic using wp eval for safe content handling
        // Use single quotes for the php code wrapper, and double quotes inside.
        // Base64 string is safe to embed.
        const phpCode = `
            $post = get_page_by_path("booking");
            $content = base64_decode("${base64Content}");
            $post_data = array(
                "post_type"    => "page",
                "post_title"   => "Booking",
                "post_name"    => "booking",
                "post_content" => $content,
                "post_status"  => "publish",
            );
            
            if ($post) {
                $post_data["ID"] = $post->ID;
                wp_update_post($post_data);
            } else {
                wp_insert_post($post_data);
            }
        `;

        // Remove newlines to avoid shell issues, though wp-env might handle it.
        // But safer to keep it one line or carefully quoted.
        // Actually, with single quotes around the whole arg, newlines might be risky depending on the shell.
        // Let's flatten it.
        const flatPhpCode = phpCode.replace(/\s+/g, ' ').trim();

        execSync( `npx wp-env run cli wp eval '${flatPhpCode}'` );
    } catch ( error: any ) {
        console.error( 'Failed to setup booking data:', error.message );
        throw error;
    }
};

/**
 * Disable email verification specifically for E2E tests.
 * E2Eテスト用にメール認証を無効化
 * 
 * @param {Object} requestContext 
 */
export const disableEmailVerification = async () => {
    // メール認証を無効化（＝登録即ログイン状態）
    // スタッフ機能を有効化（今回はスタッフ1名でテストするため）
    // 明示的に利用規約とキャンセルポリシーを設定し、チェックボックスが表示されるようにする
    // レート制限を無効化（E2Eテストで複数回試行するため）
    await configureProviderSettings( { 
        registration_email_verification_enabled: 0,
        registration_rate_limit_enabled: 0,  // Disable rate limiting for E2E tests
        staff_enabled: 1,
        provider_terms_of_service: "利用規約に同意してください。",
        provider_cancellation_policy: "キャンセルポリシーに同意してください。"
    } );
    // Update WP setting to allow user registration
    // ユーザー登録を許可する（これがないと新規登録ボタンを押してもエラーになる）
    execSync( 'npx wp-env run cli wp option update users_can_register 1' );
    console.log( 'Enabled users_can_register' );
    
    // Also ensure the booking page exists
    // 予約ページが存在することも確認
    createBookingPage();
};
