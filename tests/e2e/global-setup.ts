import { configureProviderSettings } from './utils/setup';
import { wpCliArgs, wpEvalPhp } from './utils/helpers';

/**
 * Playwright グローバルセットアップ。
 * 全テスト実行前に1回だけ呼ばれ、テスト環境を初期化する。
 * Playwright global setup - runs once before all tests to initialize the test environment.
 *
 * 以下の処理を実行:
 * - プラグインの有効化
 * - 日本語ロケールのインストール・切り替え
 * - テーマの切り替え（Twenty Twenty-One）
 * - パーマリンク設定
 * - 既存テストデータのクリーンアップ
 * - スタッフ・シフト・サービスメニュー・予約ページの作成
 * - プロバイダー設定（メール認証無効化・レート制限無効化など）
 */
async function globalSetup() {
	console.log( '=== Global Setup: Starting ===' );

	// プラグインを有効化（失敗時は後続処理が全て壊れるため即時停止）
	// Activate the plugin (fail-fast: subsequent steps depend on the plugin)
	try {
		wpCliArgs( [ 'plugin', 'activate', 'vk-booking-manager-pro' ] );
		console.log( 'Plugin activated' );
	} catch ( e: any ) {
		throw new Error(
			`Plugin activation failed — aborting global setup: ${ e.message }`
		);
	}

	// 日本語のインストールと切り替え
	// Install and switch to Japanese locale
	try {
		wpCliArgs( [ 'language', 'core', 'install', 'ja' ], {
			stdio: 'ignore',
		} );
		wpCliArgs( [ 'site', 'switch-language', 'ja' ] );
		console.log( 'Language set to ja' );
	} catch ( e: any ) {
		console.warn( 'Language setup:', e.message );
	}

	// テスト安定化のためテーマを Twenty Twenty-One に切り替え（失敗時は即時停止）
	// Switch theme to Twenty Twenty-One for stable testing (fail-fast)
	try {
		wpCliArgs( [ 'theme', 'install', 'twentytwentyone', '--activate' ], {
			stdio: 'ignore',
		} );
		console.log( 'Theme set to twentytwentyone' );
	} catch ( e: any ) {
		throw new Error(
			`Theme installation failed — aborting global setup: ${ e.message }`
		);
	}

	// パーマリンクを設定
	// Set permalink structure
	wpCliArgs( [ 'rewrite', 'structure', '/%postname%/', '--hard' ] );
	console.log( 'Permalinks set' );

	// 既存テストデータのクリーンアップ（蓄積回避）
	// Clean up existing test data to prevent accumulation
	try {
		const cleanupPostTypes = [
			'vkbm_resource',
			'vkbm_service_menu',
			'vkbm_shift',
		];
		for ( const postType of cleanupPostTypes ) {
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
				// IDs are whitespace-separated; pass each as its own argument
				// IDはスペース区切りで来るので配列に分解して渡す
				const idList = ids.split( /\s+/ ).filter( Boolean );
				wpCliArgs( [ 'post', 'delete', ...idList, '--force' ] );
				console.log( `Cleaned up ${ postType }: ${ ids }` );
			}
		}
	} catch ( e: any ) {
		console.warn( 'Cleanup failed (non-critical):', e.message );
	}

	// テストユーザーの削除（user_* パターンに一致するユーザー）
	// Clean up test users matching user_* pattern
	try {
		const userIds = wpCliArgs(
			[ 'user', 'list', '--search=user_*', '--field=ID' ],
			{ stdio: 'pipe' }
		);
		if ( userIds ) {
			const idList = userIds.split( /\s+/ ).filter( Boolean );
			wpCliArgs( [ 'user', 'delete', ...idList, '--yes' ] );
			console.log( `Cleaned up test users: ${ userIds }` );
		}
	} catch ( e: any ) {
		console.warn( 'User cleanup failed (non-critical):', e.message );
	}

	// スタッフを作成
	// Create staff
	let staffId = '';
	try {
		staffId = wpCliArgs( [
			'post',
			'create',
			'--post_type=vkbm_resource',
			'--post_title=Staff 1',
			'--post_status=publish',
			'--porcelain',
		] );
	} catch ( e: any ) {
		throw new Error( `Failed to create staff post: ${ e.message }` );
	}
	if ( ! staffId || ! /^\d+$/.test( staffId ) || Number( staffId ) <= 0 ) {
		throw new Error(
			`Staff post creation returned invalid ID: "${ staffId }"`
		);
	}
	console.log( `Created Staff ID: ${ staffId }` );

	// 今月のシフトを作成（予約に必要）
	// Create shift for current month (required for bookings)
	const createShiftCode = `
		$resource_id = ${ staffId };
		$tz = new DateTimeZone('Asia/Tokyo');
		$year = (int) wp_date('Y', time(), $tz);
		$month = (int) wp_date('n', time(), $tz);
		$days_in_month = (int) wp_date('t', mktime(0, 0, 0, $month, 1, $year), $tz);
		$days = [];
		for ($d = 1; $d <= $days_in_month; $d++) {
			$days[$d] = [
				'status' => 'open',
				'slots' => [['start' => '09:00', 'end' => '18:00']]
			];
		}
		$post_id = wp_insert_post([
			'post_type'   => 'vkbm_shift',
			'post_status' => 'publish',
			'post_title'  => sprintf('%d year %02d month Staff 1', $year, $month),
		]);
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
	// Use wpEvalPhp to consolidate base64 wrap + execFileSync execution
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

	// サービスメニューを作成しスタッフを割り当て
	// Create service menu and assign staff
	let menuId = '';
	try {
		menuId = wpCliArgs( [
			'post',
			'create',
			'--post_type=vkbm_service_menu',
			'--post_title=Service Menu 1',
			'--post_status=publish',
			'--porcelain',
		] );
	} catch ( e: any ) {
		throw new Error( `Failed to create service menu: ${ e.message }` );
	}
	if ( ! menuId || ! /^\d+$/.test( menuId ) || Number( menuId ) <= 0 ) {
		throw new Error(
			`Service menu creation returned invalid ID: "${ menuId }"`
		);
	}
	try {
		const assignStaffCode = `update_post_meta(${ menuId }, '_vkbm_staff_ids', array((int)${ staffId }));`;
		// wpEvalPhp 経由で base64 ラップ＋execFileSync 実行に統一
		// Use wpEvalPhp to consolidate base64 wrap + execFileSync execution
		wpEvalPhp( assignStaffCode );
	} catch ( e: any ) {
		throw new Error(
			`Failed to assign staff to menu ${ menuId }: ${ e.message }`
		);
	}
	console.log( `Created Service Menu ID: ${ menuId }` );

	// 予約ページを作成または更新
	// Create or update the booking page
	const rawContent =
		'<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div><!-- /wp:vk-booking-manager/reservation -->';
	const base64Content = Buffer.from( rawContent ).toString( 'base64' );
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
	console.log( 'Booking page created' );

	// パーマリンクをフラッシュ
	// Flush permalinks
	wpCliArgs( [ 'rewrite', 'flush', '--hard' ] );

	// プロバイダー設定: メール認証無効化、レート制限無効化、スタッフ機能有効化、利用規約・キャンセルポリシー設定
	// Provider settings: disable email verification, disable rate limiting, enable staff, set terms/cancellation policy
	// configureProviderSettings はマージ更新なので既存設定を壊さない
	await configureProviderSettings( {
		registration_email_verification_enabled: 0,
		registration_rate_limit_enabled: 0,
		staff_enabled: 1,
		provider_terms_of_service: '利用規約に同意してください。',
		provider_cancellation_policy: 'キャンセルポリシーに同意してください。',
	} );
	console.log( 'Provider settings configured' );

	// ユーザー登録を許可
	// Allow user registration
	wpCliArgs( [ 'option', 'update', 'users_can_register', '1' ] );
	console.log( 'User registration enabled' );

	console.log( '=== Global Setup: Complete ===' );
}

export default globalSetup;
