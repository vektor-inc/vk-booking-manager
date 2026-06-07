import { wpCliArgs, wpEvalPhp } from './helpers';

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
		const result = wpCliArgs(
			[ 'option', 'get', 'vkbm_provider_settings', '--format=json' ],
			{ stdio: 'pipe' }
		);
		const parsed = JSON.parse( result );
		// Ensure parsed result is a plain object before using it
		if (
			typeof parsed === 'object' &&
			parsed !== null &&
			! Array.isArray( parsed )
		) {
			currentSettings = parsed;
		} else {
			currentSettings = {};
		}
	} catch ( e ) {
		// Option doesn't exist or is empty, start with empty object
		// オプションが存在しないか空の場合、空のオブジェクトから開始
		console.log(
			'vkbm_provider_settings does not exist yet, will create it'
		);
		currentSettings = {};
	}

	// Merge settings
	// 設定をマージ
	const mergedSettings = { ...currentSettings, ...settings };

	// JSON 文字列にして wp option update に渡す。
	// execFileSync を使っているため shell エスケープは不要で、
	// 任意の JSON 文字列をそのまま引数として安全に渡せる。
	// Pass JSON string directly to wp option update; execFileSync
	// removes the need for shell escaping or base64 wrapping.
	const jsonSettings = JSON.stringify( mergedSettings );

	const maxRetries = 3;
	let lastError;

	for ( let i = 0; i < maxRetries; i++ ) {
		try {
			// 引数として JSON 文字列を直接渡す。shell パースを経由しないため
			// 旧実装の bash -c | base64 -d パイプ構成は不要。
			// Pass JSON as argument; no shell pipe needed since execFileSync
			// bypasses shell entirely.
			wpCliArgs(
				[
					'option',
					'update',
					'vkbm_provider_settings',
					jsonSettings,
					'--format=json',
				],
				{ stdio: 'inherit' }
			);
			console.log(
				'Updated vkbm_provider_settings:',
				Object.keys( settings ).join( ', ' )
			);
			return;
		} catch ( error ) {
			console.error(
				`Attempt ${ i + 1 } failed to update provider settings:`,
				( error as Error ).message
			);
			lastError = error;
			// Wait a bit before retrying
			await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
		}
	}

	console.error(
		`Failed to update provider settings after ${ maxRetries } attempts`
	);
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
		wpCliArgs( [ 'plugin', 'activate', 'vk-booking-manager-pro' ] );

		// Install and switch to Japanese
		// 日本語のインストールと切り替え
		try {
			wpCliArgs( [ 'language', 'core', 'install', 'ja' ], {
				stdio: 'ignore',
			} );
			wpCliArgs( [ 'site', 'switch-language', 'ja' ] );
		} catch ( e: any ) {
			// Ignore if already installed or fails
			console.warn( 'Failed to switch language to ja:', e.message );
		}

		// Switch theme to twentytwentyone for stable testing
		// テスト安定化のためテーマをTwenty Twenty-Oneに切り替え
		try {
			wpCliArgs(
				[ 'theme', 'install', 'twentytwentyone', '--activate' ],
				{ stdio: 'ignore' }
			);
		} catch ( e: any ) {
			console.warn( 'Failed to switch theme:', e.message );
		}

		// Set Permalinks
		// パーマリンクを設定
		wpCliArgs( [ 'rewrite', 'structure', '/%postname%/', '--hard' ] );

		// Clean up existing test data
		// 既存のテストデータをクリーンアップ（蓄積回避）
		try {
			const cleanupPostTypes = [
				'vkbm_resource',
				'vkbm_service_menu',
				'vkbm_shift',
			];
			for ( const postType of cleanupPostTypes ) {
				// Get all IDs
				const ids = wpCliArgs(
					[
						'post',
						'list',
						`--post_type=${ postType }`,
						'--post_status=any',
						'--format=ids',
					],
					{ stdio: 'pipe' }
				);
				if ( ids ) {
					// Delete all
					// IDリストはスペース区切りで来るので配列に分解して渡す
					// Split IDs to pass each as a separate argument
					const idList = ids.split( /\s+/ ).filter( Boolean );
					wpCliArgs( [ 'post', 'delete', ...idList, '--force' ] );
					console.log( `Cleaned up ${ postType }: ${ ids }` );
				}
			}
		} catch ( e: any ) {
			console.warn( 'Cleanup failed (non-critical):', e.message );
		}

		// Clean up Test Users (user_*)
		// テストユーザーの削除
		try {
			const userIds = wpCliArgs(
				[ 'user', 'list', '--search=user_*', '--field=ID' ],
				{ stdio: 'pipe' }
			);
			if ( userIds ) {
				// IDs are whitespace-separated; pass each as its own argument
				// IDはスペース区切りで来るので配列に分解して渡す
				const idList = userIds.split( /\s+/ ).filter( Boolean );
				wpCliArgs( [ 'user', 'delete', ...idList, '--yes' ] );
				console.log( `Cleaned up test users: ${ userIds }` );
			}
		} catch ( e: any ) {
			console.warn( 'User cleanup failed (non-critical):', e.message );
		}

		// Create Staff
		// スタッフを作成
		// post create の戻り値を未検証のまま PHP に埋めると、空文字や警告混じりで
		// PHP 構文エラーになり原因が見えにくくなるため、数値 ID であることを検証する。
		// Validate the staff ID is a positive integer before interpolating into PHP,
		// otherwise an empty or warning-prefixed output causes opaque PHP parse errors.
		const staffId = wpCliArgs( [
			'post',
			'create',
			'--post_type=vkbm_resource',
			'--post_title=Staff 1',
			'--post_status=publish',
			'--porcelain',
		] );
		if (
			! staffId ||
			! /^\d+$/.test( staffId ) ||
			Number( staffId ) <= 0
		) {
			throw new Error(
				`Staff post creation returned invalid ID: "${ staffId }"`
			);
		}
		console.log( `Created Staff ID: ${ staffId }` );

		// Create Shift for the current month
		// 今月のシフトを作成（これがないと予約できない）
		const createShiftCode = `
            $resource_id = ${ staffId };
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

		// wpEvalPhp 経由で base64 ラップ＋execFileSync 実行に統一
		// Use wpEvalPhp to consolidate base64 wrap + execFileSync execution.
		// createShiftCode は失敗時に "Error: ..." を echo する実装なので、
		// 戻り値を見て失敗時はテスト全体を fail-fast させる。
		// createShiftCode echoes "Error: ..." on failure; surface that via the
		// return value so the test fails fast instead of silently continuing.
		const shiftResult = wpEvalPhp( createShiftCode );
		if (
			! shiftResult ||
			shiftResult.startsWith( 'Error' ) ||
			! /^\d+$/.test( shiftResult ) ||
			Number( shiftResult ) <= 0
		) {
			throw new Error(
				`Shift creation failed: ${ shiftResult || '(empty output)' }`
			);
		}
		console.log( `Created Shift for current month (ID: ${ shiftResult })` );

		// Create Service Menu
		// サービスメニューを作成
		// 同様に menuId も PHP 埋め込み前に数値検証する。
		// Validate menuId before interpolating into PHP, same rationale as staffId.
		const menuId = wpCliArgs( [
			'post',
			'create',
			'--post_type=vkbm_service_menu',
			'--post_title=Service Menu 1',
			'--post_status=publish',
			'--porcelain',
		] );
		if ( ! menuId || ! /^\d+$/.test( menuId ) || Number( menuId ) <= 0 ) {
			throw new Error(
				`Service menu creation returned invalid ID: "${ menuId }"`
			);
		}

		// Assign Staff to Menu (using wp eval)
		// スタッフをメニューに割り当て (wp evalを使用)
		// Ensure IDs are treated as integers in PHP array
		const assignStaffCode = `update_post_meta(${ menuId }, '_vkbm_staff_ids', array((int)${ staffId }));`;
		wpEvalPhp( assignStaffCode );

		// Create or Update Booking Page
		// 予約ページを作成または更新
		// Check if exists
		// 存在確認
		let existingId = '';
		try {
			existingId = wpCliArgs(
				[ 'post', 'list', '--name=booking', '--field=ID' ],
				{ stdio: 'pipe' }
			);
		} catch ( e ) {
			// ignore
		}

		// Create content with block and paragraph
		const rawContent =
			'<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->';
		const base64Content = Buffer.from( rawContent ).toString( 'base64' );

		// Create or Update Booking Page logic using wp eval for safe content handling.
		// 予約ページの作成・更新ロジックを wp eval で安全に行う。
		// Base64 でラップしておくことで PHP コード内の引用符や改行を気にしなくて済む。
		// Base64 wrapping avoids any concern about quotes or newlines in the PHP code.
		const phpCode = `
            $post = get_page_by_path("booking");
            $content = base64_decode("${ base64Content }");
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

		// wpEvalPhp 経由で base64 ラップ＋execFileSync 実行に統一
		// Use wpEvalPhp to consolidate base64 wrap + execFileSync execution
		wpEvalPhp( phpCode );

		// Flush permalinks to ensure the booking page is accessible
		// パーマリンクをフラッシュして予約ページがアクセス可能であることを保証
		wpCliArgs( [ 'rewrite', 'flush', '--hard' ] );
		console.log( 'Flushed permalinks after creating booking page' );
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
		registration_rate_limit_enabled: 0, // Disable rate limiting for E2E tests
		staff_enabled: 1,
		provider_terms_of_service: '利用規約に同意してください。',
		provider_cancellation_policy: 'キャンセルポリシーに同意してください。',
	} );
	// Update WP setting to allow user registration
	// ユーザー登録を許可する（これがないと新規登録ボタンを押してもエラーになる）
	wpCliArgs( [ 'option', 'update', 'users_can_register', '1' ] );
	console.log( 'Enabled users_can_register' );

	// Also ensure the booking page exists
	// 予約ページが存在することも確認
	createBookingPage();
};
