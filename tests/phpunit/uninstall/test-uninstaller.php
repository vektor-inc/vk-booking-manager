<?php
/**
 * Uninstaller（プラグイン削除時の片付け）のテスト。
 *
 * Google カレンダー連携との通信（Google 側の連携許可の取り消し）は Pro 版にしかないクラスを使うため、
 * test-uninstaller-google-revoke.php に分けている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Uninstall;

use VKBookingManager\Uninstall\Uninstaller;
use WP_UnitTestCase;

/**
 * 「残す」「すべて削除する」それぞれの削除範囲と、もう一方のエディションが残っているときの扱いを検証する。
 *
 * @group uninstall
 */
class Uninstaller_Test extends WP_UnitTestCase {

	/**
	 * 片付けの対象外のデータ（残るべきもの）の目印。
	 */
	private const UNRELATED_OPTION = 'some_other_plugin_option';

	/**
	 * 各テストの前に、他のテストが作った予定処理を掃除する。
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_unschedule_hook( 'vkbm_send_booking_reminders' );
	}

	/**
	 * 各テストの後に、予定処理を掃除する。
	 */
	protected function tearDown(): void {
		wp_unschedule_hook( 'vkbm_send_booking_reminders' );
		wp_unschedule_hook( 'vkbm_google_calendar_sync_booking' );
		parent::tearDown();
	}

	/**
	 * 保存された BM 設定から、削除時の片付けの選択肢が決まること。
	 */
	public function test_resolve_mode(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「すべて削除する」が保存されている => delete_all',
				'conditions'          => array( 'settings' => array( 'uninstall_cleanup' => 'delete_all' ) ),
				'expected'            => 'delete_all',
			),
			array(
				'test_condition_name' => '「残す」が保存されている => keep',
				'conditions'          => array( 'settings' => array( 'uninstall_cleanup' => 'keep' ) ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '未設定（項目が無い） => データを守る側の keep',
				'conditions'          => array( 'settings' => array( 'shift_alert_months' => 1 ) ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '想定外の値 => データを守る側の keep',
				'conditions'          => array( 'settings' => array( 'uninstall_cleanup' => 'DELETE_ALL' ) ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '設定そのものが配列でない => データを守る側の keep',
				'conditions'          => array( 'settings' => false ),
				'expected'            => 'keep',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Uninstaller::resolve_mode( $case['conditions']['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 保存・表示する値が、許可された 2 つのどちらかに揃うこと。
	 */
	public function test_normalize_mode(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'delete_all => delete_all',
				'conditions'          => array( 'value' => 'delete_all' ),
				'expected'            => 'delete_all',
			),
			array(
				'test_condition_name' => 'keep => keep',
				'conditions'          => array( 'value' => 'keep' ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '空文字 => keep',
				'conditions'          => array( 'value' => '' ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '配列 => keep',
				'conditions'          => array( 'value' => array( 'delete_all' ) ),
				'expected'            => 'keep',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Uninstaller::normalize_mode( $case['conditions']['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Uninstaller が使う option 名・投稿タイプ名などが、プラグイン本体の定義と一致していること。
	 *
	 * 本体側だけ名前を変えると、削除時に消えずに残るため。
	 */
	public function test_constants_match_plugin_definitions(): void {
		$this->assertSame( \VKBookingManager\ProviderSettings\Settings_Repository::OPTION_KEY, Uninstaller::SETTINGS_OPTION );
		$this->assertSame( \VKBookingManager\PostTypes\Booking_Post_Type::POST_TYPE, Uninstaller::POST_TYPES[0] );
		$this->assertSame( \VKBookingManager\PostTypes\Resource_Post_Type::POST_TYPE, Uninstaller::POST_TYPES[1] );
		$this->assertSame( \VKBookingManager\PostTypes\Shift_Post_Type::POST_TYPE, Uninstaller::POST_TYPES[2] );
		$this->assertSame( \VKBookingManager\PostTypes\Service_Menu_Post_Type::POST_TYPE, Uninstaller::POST_TYPES[3] );
		$this->assertContains( \VKBookingManager\PostTypes\Service_Menu_Post_Type::TAXONOMY, Uninstaller::TAXONOMIES );
		$this->assertContains( \VKBookingManager\PostTypes\Service_Menu_Post_Type::TAXONOMY_GROUP, Uninstaller::TAXONOMIES );
		$this->assertContains( \VKBookingManager\Resources\Resource_Tag_Taxonomy::TAXONOMY, Uninstaller::TAXONOMIES );
		$this->assertSame( \VKBookingManager\Capabilities\Roles_Manager::ROLE_PROVIDER_SITE_OWNER, Uninstaller::ROLES[0] );
		$this->assertSame( \VKBookingManager\Capabilities\Roles_Manager::ROLE_PROVIDER_OWNER, Uninstaller::ROLES[1] );
		$this->assertSame( 'vk-booking-manager', Uninstaller::TEXT_DOMAIN );
	}

	/**
	 * インストール済みのプラグインから、削除する側以外の無料版・Pro 版だけが見つかること。
	 */
	public function test_find_other_edition_plugins(): void {
		$free = array(
			'Name'       => 'VK Booking Manager',
			'TextDomain' => 'vk-booking-manager',
		);
		$pro  = array(
			'Name'       => 'VK Booking Manager Pro',
			'TextDomain' => 'vk-booking-manager',
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'Pro 版を削除し、無料版が残っている => 無料版が見つかる',
				'conditions'          => array(
					'own'     => 'vk-booking-manager-pro/vk-booking-manager.php',
					'plugins' => array(
						'vk-booking-manager-pro/vk-booking-manager.php' => $pro,
						'vk-booking-manager/vk-booking-manager.php' => $free,
						'akismet/akismet.php' => array(
							'Name'       => 'Akismet',
							'TextDomain' => 'akismet',
						),
					),
				),
				'expected'            => array( 'vk-booking-manager/vk-booking-manager.php' ),
			),
			array(
				'test_condition_name' => '無料版を削除し、Pro 版が残っている => Pro 版が見つかる',
				'conditions'          => array(
					'own'     => 'vk-booking-manager/vk-booking-manager.php',
					'plugins' => array(
						'vk-booking-manager-pro/vk-booking-manager.php' => $pro,
						'vk-booking-manager/vk-booking-manager.php' => $free,
					),
				),
				'expected'            => array( 'vk-booking-manager-pro/vk-booking-manager.php' ),
			),
			array(
				'test_condition_name' => 'フォルダ名が違っても、テキストドメインが同じなら見つかる',
				'conditions'          => array(
					'own'     => 'vk-booking-manager-pro/vk-booking-manager.php',
					'plugins' => array(
						'vk-booking-manager-pro/vk-booking-manager.php' => $pro,
						'bm-free-copy/vk-booking-manager.php'        => $free,
					),
				),
				'expected'            => array( 'bm-free-copy/vk-booking-manager.php' ),
			),
			array(
				'test_condition_name' => '削除する側しか入っていない => 見つからない',
				'conditions'          => array(
					'own'     => 'vk-booking-manager-pro/vk-booking-manager.php',
					'plugins' => array(
						'vk-booking-manager-pro/vk-booking-manager.php' => $pro,
						'hello.php' => array( 'TextDomain' => '' ),
					),
				),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Uninstaller::find_other_edition_plugins( $case['conditions']['own'], $case['conditions']['plugins'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 実際にインストールされているプラグインの一覧から、もう一方のエディションの有無を判定できること。
	 */
	public function test_has_other_edition_installed(): void {
		$own_basename = plugin_basename( VKBM_PLUGIN_FILE );

		$test_cases = array(
			array(
				'test_condition_name' => 'インストール済みのこのプラグイン自身を削除する側とする => 他に無い',
				'conditions'          => array( 'own' => $own_basename ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '別の場所にあるプラグインを削除する側とする => このプラグインがもう一方として見つかる',
				'conditions'          => array( 'own' => 'another-edition/vk-booking-manager.php' ),
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Uninstaller::has_other_edition_installed( $case['conditions']['own'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 現在のサイトの片付けで、選択肢ごとに消えるものと残るものが期待どおりになること。
	 */
	public function test_cleanup_current_site(): void {
		$kept_after_keep = array(
			'settings_option'              => true,
			'license_key_option'           => true,
			'email_logs_option'            => true,
			'roles_sync_signature'         => true,
			'avail_generation'             => true,
			'update_checker_option'        => true,
			'unrelated_option'             => true,
			'google_connection'            => false,
			'plugin_transient'             => false,
			'unrelated_transient'          => true,
			'free_notice_option'           => false,
			'booking_post'                 => true,
			'resource_post'                => true,
			'shift_post'                   => true,
			'service_menu_post'            => true,
			'uploaded_attachment'          => true,
			'plain_attachment'             => true,
			'service_menu_term'            => true,
			'plugin_meta_on_post'          => true,
			'unrelated_post_meta'          => true,
			'plugin_user_meta'             => true,
			'plugin_hidden_usermeta'       => true,
			'attendee_booking_id_meta'     => true,
			'attendee_booking_name_meta'   => true,
			'attendee_booking_number_meta' => true,
			'attendee_roster_meta'         => true,
			'attendee_roster_site_meta'    => true,
			'attendee_number_meta'         => true,
			'attendee_number_site_meta'    => true,
			'generic_user_meta'            => true,
			'owner_role_exists'            => true,
			'owner_user_role'              => 'provider_owner',
			'admin_capability'             => true,
			'reminder_schedule'            => false,
			'mail_retry_schedule'          => false,
		);

		$test_cases = array(
			array(
				'test_condition_name' => '「残す」 => 設定・予約データ・ライセンスキーは残り、Google 連携の認証情報・一時的なデータ・予定処理だけ消える',
				'conditions'          => array( 'mode' => Uninstaller::MODE_KEEP ),
				'expected'            => $kept_after_keep,
			),
			array(
				'test_condition_name' => '「すべて削除する」 => プラグインが作ったデータがすべて消え、他のプラグインのデータは残る',
				'conditions'          => array( 'mode' => Uninstaller::MODE_DELETE_ALL ),
				'expected'            => array(
					'settings_option'              => false,
					'license_key_option'           => false,
					'email_logs_option'            => false,
					'roles_sync_signature'         => false,
					'avail_generation'             => false,
					'update_checker_option'        => false,
					'unrelated_option'             => true,
					'google_connection'            => false,
					'plugin_transient'             => false,
					'unrelated_transient'          => true,
					'free_notice_option'           => false,
					'booking_post'                 => false,
					'resource_post'                => false,
					'shift_post'                   => false,
					'service_menu_post'            => false,
					'uploaded_attachment'          => false,
					'plain_attachment'             => true,
					'service_menu_term'            => false,
					'plugin_meta_on_post'          => false,
					'unrelated_post_meta'          => true,
					'plugin_user_meta'             => true,
					'plugin_hidden_usermeta'       => true,
					'attendee_booking_id_meta'     => false,
					'attendee_booking_name_meta'   => false,
					'attendee_booking_number_meta' => false,
					'attendee_roster_meta'         => true,
					'attendee_roster_site_meta'    => true,
					'attendee_number_meta'         => true,
					'attendee_number_site_meta'    => true,
					'generic_user_meta'            => true,
					'owner_role_exists'            => false,
					'owner_user_role'              => 'subscriber',
					'admin_capability'             => false,
					'reminder_schedule'            => false,
					'mail_retry_schedule'          => false,
				),
			),
			array(
				'test_condition_name' => '選択肢が未設定 => 「残す」と同じ（データを消さない）',
				'conditions'          => array( 'mode' => null ),
				'expected'            => $kept_after_keep,
			),
		);

		foreach ( $test_cases as $case ) {
			$seeded = $this->seed_data( $case['conditions']['mode'] );

			$actual_mode = Uninstaller::cleanup_current_site();

			$this->assertSame(
				$case['conditions']['mode'] ?? Uninstaller::MODE_KEEP,
				$actual_mode,
				$case['test_condition_name'] . '（採用された選択肢）'
			);
			$this->assertSame( $case['expected'], $this->take_snapshot( $seeded ), $case['test_condition_name'] );

			// 次のケースに前のケースのデータを持ち越さない。
			$this->purge_seeded_data( $seeded );
		}
	}

	/**
	 * run() は、もう一方のエディションが残っていれば何も消さず、残っていなければ片付けること。
	 *
	 * 削除する側のプラグインを実在しない場所に見立てると、いま読み込まれているこのプラグインが
	 * 「もう一方のエディション」として見つかる。
	 */
	public function test_run(): void {
		$expected_all_gone   = array(
			'settings_option'              => false,
			'license_key_option'           => false,
			'booking_post'                 => false,
			'plugin_user_meta'             => false,
			'attendee_booking_id_meta'     => false,
			'attendee_booking_name_meta'   => false,
			'attendee_booking_number_meta' => false,
			'attendee_roster_meta'         => false,
			'attendee_roster_site_meta'    => false,
			'attendee_number_meta'         => false,
			'attendee_number_site_meta'    => false,
			'google_connection'            => false,
			'reminder_schedule'            => false,
			'unrelated_option'             => true,
			'plain_attachment'             => true,
			'generic_user_meta'            => true,
		);
		$expected_other_kept = array(
			'settings_option'              => true,
			'license_key_option'           => true,
			'booking_post'                 => true,
			'plugin_user_meta'             => true,
			'attendee_booking_id_meta'     => true,
			'attendee_booking_name_meta'   => true,
			'attendee_booking_number_meta' => true,
			'attendee_roster_meta'         => true,
			'attendee_roster_site_meta'    => true,
			'attendee_number_meta'         => true,
			'attendee_number_site_meta'    => true,
			'google_connection'            => true,
			'reminder_schedule'            => true,
			'unrelated_option'             => true,
			'plain_attachment'             => true,
			'generic_user_meta'            => true,
		);

		$test_cases = array(
			array(
				'test_condition_name' => '「すべて削除する」で、もう一方のエディションが残っている => 共有データも予定処理も Google 連携の認証情報も消さない',
				'conditions'          => array(
					'mode' => Uninstaller::MODE_DELETE_ALL,
					'own'  => 'another-edition/vk-booking-manager.php',
				),
				'expected'            => $expected_other_kept,
			),
			array(
				'test_condition_name' => '「すべて削除する」で、もう一方のエディションが無い => 片付ける（ユーザーメタも消える）',
				'conditions'          => array(
					'mode' => Uninstaller::MODE_DELETE_ALL,
					'own'  => plugin_basename( VKBM_PLUGIN_FILE ),
				),
				'expected'            => $expected_all_gone,
			),
			array(
				'test_condition_name' => '「残す」で、もう一方のエディションが無い => 設定・予約データ・ユーザーメタは残り、Google 連携の認証情報・予定処理は消える',
				'conditions'          => array(
					'mode' => Uninstaller::MODE_KEEP,
					'own'  => plugin_basename( VKBM_PLUGIN_FILE ),
				),
				'expected'            => array(
					'settings_option'              => true,
					'license_key_option'           => true,
					'booking_post'                 => true,
					'plugin_user_meta'             => true,
					'attendee_booking_id_meta'     => true,
					'attendee_booking_name_meta'   => true,
					'attendee_booking_number_meta' => true,
					'attendee_roster_meta'         => true,
					'attendee_roster_site_meta'    => true,
					'attendee_number_meta'         => true,
					'attendee_number_site_meta'    => true,
					'google_connection'            => false,
					'reminder_schedule'            => false,
					'unrelated_option'             => true,
					'plain_attachment'             => true,
					'generic_user_meta'            => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$seeded = $this->seed_data( $case['conditions']['mode'] );

			Uninstaller::run( $case['conditions']['own'] );

			$snapshot = $this->take_snapshot( $seeded );
			$actual   = array();
			foreach ( array_keys( $case['expected'] ) as $key ) {
				$actual[ $key ] = $snapshot[ $key ];
			}

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			$this->purge_seeded_data( $seeded );
		}
	}

	/**
	 * プラグイン削除の時点で既定のロールがプラグインのロールや存在しないロールでも、付け替え先が有効なロールになること。
	 */
	public function test_cleanup_current_site_default_role(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '既定のロールが editor => editor に付け替える',
				'conditions'          => array( 'default_role' => 'editor' ),
				'expected'            => 'editor',
			),
			array(
				'test_condition_name' => '既定のロールがプラグインのロール（provider_owner） => subscriber に付け替える',
				'conditions'          => array( 'default_role' => 'provider_owner' ),
				'expected'            => 'subscriber',
			),
			array(
				'test_condition_name' => '既定のロールが存在しないロール => subscriber に付け替える',
				'conditions'          => array( 'default_role' => 'no_such_role' ),
				'expected'            => 'subscriber',
			),
		);

		$original_default_role = get_option( 'default_role' );

		foreach ( $test_cases as $case ) {
			update_option( 'default_role', $case['conditions']['default_role'] );
			$seeded = $this->seed_data( Uninstaller::MODE_DELETE_ALL );

			Uninstaller::cleanup_current_site();

			$user = get_userdata( $seeded['owner_user_id'] );
			$this->assertSame( $case['expected'], $user ? (string) reset( $user->roles ) : '', $case['test_condition_name'] );

			$this->purge_seeded_data( $seeded );
		}

		update_option( 'default_role', $original_default_role );
	}

	/**
	 * 削除するプラグインのフォルダに Google 連携のクラスが無くても（一括削除で先に消えた場合など）、Fatal エラーにならず片付けが進むこと。
	 */
	public function test_run_with_missing_plugin_dir(): void {
		update_option( Uninstaller::SETTINGS_OPTION, array( 'uninstall_cleanup' => Uninstaller::MODE_KEEP ) );
		update_option( Uninstaller::GOOGLE_CONNECTION_OPTION, array( 'status' => 'connected' ) );

		Uninstaller::run( plugin_basename( VKBM_PLUGIN_FILE ), '/nonexistent/already-deleted-plugin' );

		$this->assertFalse( get_option( Uninstaller::GOOGLE_CONNECTION_OPTION, false ), 'Google 連携の認証情報は削除される' );
		$this->assertNotFalse( get_option( Uninstaller::SETTINGS_OPTION, false ), '設定は残る' );
		delete_option( Uninstaller::SETTINGS_OPTION );
	}

	/**
	 * もう一方のエディションの本体が読み込み済みのとき、uninstall.php を別フォルダから読み込んでも
	 * 同名クラスの二重宣言（Fatal エラー）にならず、何も消さないこと。
	 *
	 * 別フォルダへコピーした uninstall.php を読み込んで再現する。修正前は Cannot declare class で停止していた。
	 */
	public function test_uninstall_file_does_not_fatal_when_other_edition_is_loaded(): void {
		$copy_dir = trailingslashit( get_temp_dir() ) . 'vkbm-uninstall-copy-' . uniqid();
		wp_mkdir_p( $copy_dir . '/src/common' );
		wp_mkdir_p( $copy_dir . '/src/uninstall' );
		$plugin_root = dirname( VKBM_PLUGIN_FILE );
		copy( $plugin_root . '/uninstall.php', $copy_dir . '/uninstall.php' );
		copy( $plugin_root . '/src/common/class-scheduled-events.php', $copy_dir . '/src/common/class-scheduled-events.php' );
		copy( $plugin_root . '/src/uninstall/class-uninstaller.php', $copy_dir . '/src/uninstall/class-uninstaller.php' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'copy-of-edition/vk-booking-manager.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core の定数を再現する。
		}

		$this->assertTrue( defined( 'VKBM_VERSION' ), '前提: 本体が読み込み済み' );
		update_option( Uninstaller::SETTINGS_OPTION, array( 'uninstall_cleanup' => Uninstaller::MODE_DELETE_ALL ) );
		update_option( Uninstaller::GOOGLE_CONNECTION_OPTION, array( 'status' => 'connected' ) );

		include $copy_dir . '/uninstall.php';

		$this->assertNotFalse( get_option( Uninstaller::SETTINGS_OPTION, false ), '設定は消えない' );
		$this->assertNotFalse( get_option( Uninstaller::GOOGLE_CONNECTION_OPTION, false ), 'Google 連携の認証情報は消えない' );

		delete_option( Uninstaller::SETTINGS_OPTION );
		delete_option( Uninstaller::GOOGLE_CONNECTION_OPTION );
		unlink( $copy_dir . '/uninstall.php' );
		unlink( $copy_dir . '/src/common/class-scheduled-events.php' );
		unlink( $copy_dir . '/src/uninstall/class-uninstaller.php' );
		rmdir( $copy_dir . '/src/common' );
		rmdir( $copy_dir . '/src/uninstall' );
		rmdir( $copy_dir . '/src' );
		rmdir( $copy_dir );
	}

	/**
	 * 削除するプラグイン自身が読み込み済みのとき（WP-CLI の `wp plugin uninstall --deactivate` など）は、
	 * uninstall.php が片付けを飛ばさず、Google 連携の認証情報を削除すること。
	 *
	 * 修正前は VKBM_VERSION が定義済みというだけで return しており、認証情報が残っていた。
	 */
	public function test_uninstall_file_cleans_up_when_own_plugin_is_loaded(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( VKBM_PLUGIN_FILE ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core の定数を再現する。
		}

		$this->assertTrue( defined( 'VKBM_PLUGIN_DIR_PATH' ), '前提: 本体が読み込み済み' );
		update_option( Uninstaller::SETTINGS_OPTION, array( 'uninstall_cleanup' => Uninstaller::MODE_KEEP ) );
		update_option( Uninstaller::GOOGLE_CONNECTION_OPTION, array( 'status' => 'connected' ) );

		include dirname( VKBM_PLUGIN_FILE ) . '/uninstall.php';

		$this->assertFalse( get_option( Uninstaller::GOOGLE_CONNECTION_OPTION, false ), 'Google 連携の認証情報は削除される' );
		$this->assertNotFalse( get_option( Uninstaller::SETTINGS_OPTION, false ), '「残す」なので設定は残る' );
		delete_option( Uninstaller::SETTINGS_OPTION );
	}

	/**
	 * 「すべて削除」で投稿を削除するとき、削除に反応して予定処理が登録し直されても、最後に残らないこと。
	 */
	public function test_cleanup_clears_events_rescheduled_during_post_deletion(): void {
		update_option( Uninstaller::SETTINGS_OPTION, array( 'uninstall_cleanup' => Uninstaller::MODE_DELETE_ALL ) );
		$booking_id = self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'publish',
			)
		);

		$reschedule = static function ( $post_id ) {
			wp_schedule_single_event( time() + 60, 'vkbm_google_calendar_sync_booking', array( (int) $post_id ) );
		};
		add_action( 'before_delete_post', $reschedule );

		Uninstaller::cleanup_current_site();

		remove_action( 'before_delete_post', $reschedule );
		$this->assertFalse( wp_next_scheduled( 'vkbm_google_calendar_sync_booking', array( $booking_id ) ), '削除中に登録し直された予定処理が残っていない' );
		$this->assertNull( get_post( $booking_id ) );
	}

	/**
	 * 片付けの対象になるデータと、対象外のデータを作る。
	 *
	 * @param string|null $mode BM 設定に保存する削除時の片付けの選択肢。null なら項目を保存しない。
	 * @return array<string, int> 後で状態を確かめるための ID の一覧。
	 */
	private function seed_data( ?string $mode ): array {
		// 設定・ライセンスキー・その他の option。
		$settings = array( 'shift_alert_months' => 1 );
		if ( null !== $mode ) {
			$settings['uninstall_cleanup'] = $mode;
		}
		update_option( Uninstaller::SETTINGS_OPTION, $settings );
		update_option( Uninstaller::LICENSE_KEY_OPTION, 'license-key-value' );
		update_option( 'vkbm_email_logs', array( array( 'to' => 'a@example.com' ) ) );
		update_option( 'vkbm_roles_sync_signature', 'signature', true );
		update_option( 'vkbm_availability_cache_generation', 5 );
		update_option( Uninstaller::UPDATE_CHECKER_OPTION, array( 'checkedAt' => 1 ) );
		update_option( self::UNRELATED_OPTION, 'keep-me' );
		update_option( Uninstaller::GOOGLE_CONNECTION_OPTION, array( 'status' => 'connected' ) );
		update_option( Uninstaller::FREE_VERSION_NOTICE_OPTION, '1' );

		// transient。
		set_transient( 'vkbm_draft_token123', array( 'a' => 1 ), HOUR_IN_SECONDS );
		set_transient( 'some_other_plugin_cache', 'keep-me', HOUR_IN_SECONDS );

		// 投稿（4 種類のカスタム投稿タイプ）と投稿メタ。
		$booking_id  = self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'publish',
			)
		);
		$resource_id = self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_resource',
				'post_status' => 'publish',
			)
		);
		$shift_id    = self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_shift',
				'post_status' => 'draft',
			)
		);
		$menu_id     = self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'trash',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		// ご利用になる方の ID・名前・番号の写し（予約の投稿メタ）。
		update_post_meta( $booking_id, '_vkbm_booking_attendee_id', 'self' );
		update_post_meta( $booking_id, '_vkbm_booking_attendee_name', 'テスト太郎' );
		update_post_meta( $booking_id, '_vkbm_booking_attendee_number', 'A-001' );

		// プラグインの投稿タイプ以外の投稿に付いたプラグインのメタ・他のメタ。
		$plain_post_id = self::factory()->post->create();
		update_post_meta( $plain_post_id, '_vkbm_use_detail_page', '1' );
		update_post_meta( $plain_post_id, '_other_plugin_meta', 'keep-me' );

		// 添付ファイル（予約者がアップロードしたものと、通常のメディア）。
		$uploaded_attachment_id = self::factory()->attachment->create();
		update_post_meta( $uploaded_attachment_id, '_vkbm_booking_uploaded_attachment', '1' );
		$plain_attachment_id = self::factory()->attachment->create();

		// ターム。
		$term = wp_insert_term( 'テストのタグ', 'vkbm_service_menu_tag' );
		$this->assertIsArray( $term );

		// ユーザーとユーザーメタ・ロール・権限。
		if ( null === get_role( 'provider_owner' ) ) {
			add_role( 'provider_owner', 'Business Owner', array( 'read' => true ) );
		}
		$owner_user_id = self::factory()->user->create( array( 'role' => 'provider_owner' ) );
		update_user_meta( $owner_user_id, 'vkbm_customer_flag', array( 'type' => 'vip' ) );
		update_user_meta( $owner_user_id, '_vkbm_favorites', array( 1, 2 ) );
		// ご利用になる方の名簿と番号の検索用メタ（通常サイトとマルチサイトのキー）。
		$roster_rows = array(
			array(
				'id'     => 'self',
				'name'   => 'テスト太郎',
				'kana'   => '',
				'number' => 'A-001',
			),
		);
		update_user_meta( $owner_user_id, 'vkbm_attendees', $roster_rows );
		update_user_meta( $owner_user_id, 'vkbm_attendee_number', 'a-001' );
		update_user_meta( $owner_user_id, 'vkbm_attendees_site_2', array() );
		update_user_meta( $owner_user_id, 'vkbm_attendee_number_site_2', 'a-001' );
		update_user_meta( $owner_user_id, 'phone_number', '0312345678' );
		get_role( 'administrator' )->add_cap( 'vkbm_manage_reservations' );

		// 予定処理。
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'vkbm_send_booking_reminders' );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'vkbm_retry_booking_email', array( 'customer', $booking_id, 2 ) );

		return array(
			'booking_id'             => (int) $booking_id,
			'resource_id'            => (int) $resource_id,
			'shift_id'               => (int) $shift_id,
			'menu_id'                => (int) $menu_id,
			'plain_post_id'          => (int) $plain_post_id,
			'uploaded_attachment_id' => (int) $uploaded_attachment_id,
			'plain_attachment_id'    => (int) $plain_attachment_id,
			'term_id'                => (int) $term['term_id'],
			'owner_user_id'          => (int) $owner_user_id,
		);
	}

	/**
	 * seed_data() で作ったものの今の状態を返す。
	 *
	 * @param array<string, int> $seeded seed_data() の戻り値。
	 * @return array<string, bool|string> 項目名 => 残っているか（ロールのみ文字列）。
	 */
	private function take_snapshot( array $seeded ): array {
		$user = get_userdata( $seeded['owner_user_id'] );

		return array(
			'settings_option'              => false !== get_option( Uninstaller::SETTINGS_OPTION, false ),
			'license_key_option'           => false !== get_option( Uninstaller::LICENSE_KEY_OPTION, false ),
			'email_logs_option'            => false !== get_option( 'vkbm_email_logs', false ),
			'roles_sync_signature'         => false !== get_option( 'vkbm_roles_sync_signature', false ),
			'avail_generation'             => false !== get_option( 'vkbm_availability_cache_generation', false ),
			'update_checker_option'        => false !== get_option( Uninstaller::UPDATE_CHECKER_OPTION, false ),
			'unrelated_option'             => false !== get_option( self::UNRELATED_OPTION, false ),
			'google_connection'            => false !== get_option( Uninstaller::GOOGLE_CONNECTION_OPTION, false ),
			'plugin_transient'             => false !== get_transient( 'vkbm_draft_token123' ),
			'unrelated_transient'          => false !== get_transient( 'some_other_plugin_cache' ),
			'free_notice_option'           => false !== get_option( Uninstaller::FREE_VERSION_NOTICE_OPTION, false ),
			'booking_post'                 => null !== get_post( $seeded['booking_id'] ),
			'resource_post'                => null !== get_post( $seeded['resource_id'] ),
			'shift_post'                   => null !== get_post( $seeded['shift_id'] ),
			'service_menu_post'            => null !== get_post( $seeded['menu_id'] ),
			'uploaded_attachment'          => null !== get_post( $seeded['uploaded_attachment_id'] ),
			'plain_attachment'             => null !== get_post( $seeded['plain_attachment_id'] ),
			'service_menu_term'            => $this->term_exists_in( $seeded['term_id'], 'vkbm_service_menu_tag' ),
			'plugin_meta_on_post'          => '' !== get_post_meta( $seeded['plain_post_id'], '_vkbm_use_detail_page', true ),
			'unrelated_post_meta'          => '' !== get_post_meta( $seeded['plain_post_id'], '_other_plugin_meta', true ),
			'plugin_user_meta'             => array() !== get_user_meta( $seeded['owner_user_id'], 'vkbm_customer_flag' ),
			'plugin_hidden_usermeta'       => array() !== get_user_meta( $seeded['owner_user_id'], '_vkbm_favorites' ),
			'attendee_booking_id_meta'     => '' !== get_post_meta( $seeded['booking_id'], '_vkbm_booking_attendee_id', true ),
			'attendee_booking_name_meta'   => '' !== get_post_meta( $seeded['booking_id'], '_vkbm_booking_attendee_name', true ),
			'attendee_booking_number_meta' => '' !== get_post_meta( $seeded['booking_id'], '_vkbm_booking_attendee_number', true ),
			'attendee_roster_meta'         => array() !== get_user_meta( $seeded['owner_user_id'], 'vkbm_attendees' ),
			'attendee_roster_site_meta'    => array() !== get_user_meta( $seeded['owner_user_id'], 'vkbm_attendees_site_2' ),
			'attendee_number_meta'         => array() !== get_user_meta( $seeded['owner_user_id'], 'vkbm_attendee_number' ),
			'attendee_number_site_meta'    => array() !== get_user_meta( $seeded['owner_user_id'], 'vkbm_attendee_number_site_2' ),
			'generic_user_meta'            => array() !== get_user_meta( $seeded['owner_user_id'], 'phone_number' ),
			'owner_role_exists'            => null !== get_role( 'provider_owner' ),
			'owner_user_role'              => $user ? (string) reset( $user->roles ) : '',
			'admin_capability'             => (bool) get_role( 'administrator' )->has_cap( 'vkbm_manage_reservations' ),
			'reminder_schedule'            => false !== wp_next_scheduled( 'vkbm_send_booking_reminders' ),
			'mail_retry_schedule'          => false !== wp_next_scheduled( 'vkbm_retry_booking_email', array( 'customer', $seeded['booking_id'], 2 ) ),
		);
	}

	/**
	 * 指定したタームが、そのタクソノミーに残っているかを返す。
	 *
	 * @param int    $term_id  ターム ID。
	 * @param string $taxonomy タクソノミー名。
	 * @return bool 残っていれば true。
	 */
	private function term_exists_in( int $term_id, string $taxonomy ): bool {
		$term = get_term( $term_id, $taxonomy );

		return $term instanceof \WP_Term;
	}

	/**
	 * seed_data() で作ったものを、次のケースに持ち越さないよう片付ける。
	 *
	 * @param array<string, int> $seeded seed_data() の戻り値。
	 * @return void
	 */
	private function purge_seeded_data( array $seeded ): void {
		foreach ( array( 'booking_id', 'resource_id', 'shift_id', 'menu_id', 'plain_post_id', 'uploaded_attachment_id', 'plain_attachment_id' ) as $key ) {
			wp_delete_post( $seeded[ $key ], true );
		}
		wp_delete_term( $seeded['term_id'], 'vkbm_service_menu_tag' );
		wp_delete_user( $seeded['owner_user_id'] );

		foreach ( array( Uninstaller::SETTINGS_OPTION, Uninstaller::LICENSE_KEY_OPTION, 'vkbm_email_logs', 'vkbm_availability_cache_generation', Uninstaller::UPDATE_CHECKER_OPTION, self::UNRELATED_OPTION, Uninstaller::GOOGLE_CONNECTION_OPTION, Uninstaller::FREE_VERSION_NOTICE_OPTION ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'vkbm_draft_token123' );
		delete_transient( 'some_other_plugin_cache' );
		wp_unschedule_hook( 'vkbm_send_booking_reminders' );
		wp_unschedule_hook( 'vkbm_retry_booking_email' );
		get_role( 'administrator' )->remove_cap( 'vkbm_manage_reservations' );
	}
}
