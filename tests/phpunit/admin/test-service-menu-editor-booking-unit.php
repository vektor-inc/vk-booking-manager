<?php
/**
 * サービスメニュー編集画面の「予約の単位」とチェックイン／チェックアウト時刻のテスト（#570）。
 *
 * 宿泊の機能を使うサイト（Pro 版で業種プリセットが宿泊のサイト）でだけ
 * 欄を描画・保存し、それ以外では画面も保存データも従来どおりであることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 予約の単位・チェックイン／チェックアウト時刻の保存・描画・読み取りヘルパーのテスト（#570）。
 *
 * @group admin
 * @group lodging
 */
class Service_Menu_Editor_Booking_Unit_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/**
	 * Setup で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '予約の単位は Pro 版の業種プリセット（宿泊）限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・フィルター・キャッシュ・ログイン状態・$_POST を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		wp_set_current_user( 0 );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する。
	 *
	 * @param string $preset         保存する業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $preset ) )
		);
	}

	/**
	 * 管理者でログインし、サービスメニューを1件作成する。
	 *
	 * @return \WP_Post 作成したメニュー。
	 */
	private function create_menu(): \WP_Post {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		return get_post( $menu_id );
	}

	/**
	 * 編集画面の保存を模す。
	 *
	 * @param \WP_Post             $post         対象メニュー。
	 * @param array<string, mixed> $service_menu 送信された vkbm_service_menu。
	 */
	private function submit( \WP_Post $post, array $service_menu ): void {
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => $service_menu,
		);
		( new Service_Menu_Editor() )->save_post( $post->ID, $post );
	}

	/**
	 * 条件メタボックスの描画結果を返す。
	 *
	 * @param \WP_Post $post 対象メニュー。
	 * @return string
	 */
	private function render( \WP_Post $post ): string {
		ob_start();
		( new Service_Menu_Editor() )->render_conditions_meta_box( $post );
		return (string) ob_get_clean();
	}

	/**
	 * save_post: 予約の単位・時刻の保存規則を検証する。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトで「泊」と時刻を送ると保存される',
				'preset'              => 'lodging',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'     => 'night',
					'check_in_hour'    => '16',
					'check_in_minute'  => '30',
					'check_out_hour'   => '11',
					'check_out_minute' => '00',
				),
				'expected'            => array(
					'_vkbm_booking_unit'   => 'night',
					'_vkbm_check_in_time'  => '16:30',
					'_vkbm_check_out_time' => '11:00',
				),
			),
			array(
				'test_condition_name' => '「時間枠」へ戻すと単位メタは削除され、時刻は残る',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_booking_unit'   => 'night',
					'_vkbm_check_in_time'  => '14:00',
					'_vkbm_check_out_time' => '12:00',
				),
				'submitted'           => array(
					'booking_unit'     => 'slot',
					'check_in_hour'    => '14',
					'check_in_minute'  => '00',
					'check_out_hour'   => '12',
					'check_out_minute' => '00',
				),
				'expected'            => array(
					'_vkbm_booking_unit'   => null,
					'_vkbm_check_in_time'  => '14:00',
					'_vkbm_check_out_time' => '12:00',
				),
			),
			array(
				'test_condition_name' => '不正な時刻（時が25・分が75）は保存されずメタが削除される',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_check_in_time'  => '14:00',
					'_vkbm_check_out_time' => '12:00',
				),
				'submitted'           => array(
					'booking_unit'     => 'night',
					'check_in_hour'    => '25',
					'check_in_minute'  => '00',
					'check_out_hour'   => '11',
					'check_out_minute' => '75',
				),
				'expected'            => array(
					'_vkbm_booking_unit'   => 'night',
					'_vkbm_check_in_time'  => null,
					'_vkbm_check_out_time' => null,
				),
			),
			array(
				'test_condition_name' => 'フォームに「予約の単位」が無い保存では既存の値に触れない',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_booking_unit'  => 'night',
					'_vkbm_check_in_time' => '14:00',
				),
				'submitted'           => array( 'base_price' => '3000' ),
				'expected'            => array(
					'_vkbm_booking_unit'  => 'night',
					'_vkbm_check_in_time' => '14:00',
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）では値が送られても保存せず、既存の値にも触れない',
				'preset'              => 'custom',
				'initial_meta'        => array( '_vkbm_booking_unit' => 'night' ),
				'submitted'           => array(
					'booking_unit'    => 'slot',
					'check_in_hour'   => '16',
					'check_in_minute' => '00',
				),
				'expected'            => array(
					'_vkbm_booking_unit'  => 'night',
					'_vkbm_check_in_time' => null,
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（salon）でも「泊」を送っても保存されない',
				'preset'              => 'salon',
				'initial_meta'        => array(),
				'submitted'           => array( 'booking_unit' => 'night' ),
				'expected'            => array( '_vkbm_booking_unit' => null ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			foreach ( $case['initial_meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}

			$this->submit( $post, $case['submitted'] );

			foreach ( $case['expected'] as $meta_key => $expected ) {
				$actual = metadata_exists( 'post', $post->ID, $meta_key ) ? get_post_meta( $post->ID, $meta_key, true ) : null;
				$this->assertSame( $expected, $actual, $case['test_condition_name'] . ': ' . $meta_key );
			}
		}
	}

	/**
	 * is_night_unit / get_check_in_time / get_check_out_time: 読み取りの規則を検証する。
	 */
	public function test_is_night_unit(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトで night なら true',
				'preset'              => 'lodging',
				'meta'                => 'night',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊のサイトでメタなしなら false（時間枠）',
				'preset'              => 'lodging',
				'meta'                => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊のサイトでも night 以外の値は false',
				'preset'              => 'lodging',
				'meta'                => 'week',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊以外（salon）へ切り替えると、night が残っていても false',
				'preset'              => 'salon',
				'meta'                => 'night',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '業種プリセットが custom（宿泊以外）なら、night が残っていても false',
				'preset'              => 'custom',
				'meta'                => 'night',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			if ( '' !== $case['meta'] ) {
				update_post_meta( $post->ID, '_vkbm_booking_unit', $case['meta'] );
			}
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::is_night_unit( $post->ID ), $case['test_condition_name'] );
		}
		$this->assertFalse( Service_Menu_Post_Type::is_night_unit( 0 ), 'メニューIDが0なら false' );
	}

	/**
	 * get_check_in_time / get_check_out_time: 未設定・不正値は既定値になる。
	 */
	public function test_get_check_in_time(): void {
		$this->set_site( 'lodging' );
		$post = $this->create_menu();

		$test_cases = array(
			array(
				'test_condition_name' => '未設定はチェックイン15:00・チェックアウト10:00',
				'check_in'            => null,
				'check_out'           => null,
				'expected'            => array( '15:00', '10:00' ),
			),
			array(
				'test_condition_name' => '保存済みの値を返す',
				'check_in'            => '16:30',
				'check_out'           => '11:00',
				'expected'            => array( '16:30', '11:00' ),
			),
			array(
				'test_condition_name' => '不正値（REST 等で保存された値）は既定値へ戻す',
				'check_in'            => '25:00',
				'check_out'           => 'abc',
				'expected'            => array( '15:00', '10:00' ),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_post_meta( $post->ID, '_vkbm_check_in_time' );
			delete_post_meta( $post->ID, '_vkbm_check_out_time' );
			if ( null !== $case['check_in'] ) {
				// sanitize_callback を通さず、保存済みの不正値を模す。
				global $wpdb;
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- sanitize_callback を通さず不正値を保存済みとして模すため。
					$wpdb->postmeta,
					array(
						'post_id'    => $post->ID,
						'meta_key'   => '_vkbm_check_in_time', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $case['check_in'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- sanitize_callback を通さず不正値を保存済みとして模すため。
					$wpdb->postmeta,
					array(
						'post_id'    => $post->ID,
						'meta_key'   => '_vkbm_check_out_time', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $case['check_out'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				wp_cache_delete( $post->ID, 'post_meta' );
			}

			$this->assertSame(
				$case['expected'],
				array(
					Service_Menu_Post_Type::get_check_in_time( $post->ID ),
					Service_Menu_Post_Type::get_check_out_time( $post->ID ),
				),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * sanitize_time_value: HH:MM の検証を行う。
	 */
	public function test_sanitize_time_value(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '正しい時刻はそのまま',
				'value'               => '15:00',
				'expected'            => '15:00',
			),
			array(
				'test_condition_name' => '境界値 00:00 と 23:59 は有効',
				'value'               => '23:59',
				'expected'            => '23:59',
			),
			array(
				'test_condition_name' => '24:00 は無効',
				'value'               => '24:00',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '1桁表記は無効',
				'value'               => '9:00',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '文字列以外は無効',
				'value'               => 1500,
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::sanitize_time_value( $case['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_conditions_meta_box: 宿泊のサイトでだけ欄を描画し、泊のときは時間枠用の3行を hidden にする。
	 */
	public function test_render_conditions_meta_box(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト・時間枠: 単位ラジオと時刻の行は出るが時刻の行は hidden、時間枠用の行は表示',
				'preset'              => 'lodging',
				'meta'                => '',
				'contains'            => array( 'name="vkbm_service_menu[booking_unit]"', 'id="vkbm-check-in-time-field"' ),
				'row_hidden'          => array(
					'vkbm-check-in-time-field'     => true,
					'vkbm-duration-field'          => false,
					'vkbm-buffer-after-field'      => false,
					'vkbm-fixed-start-times-field' => false,
				),
				'not_contains'        => array( 'vkbm-booking-unit-inactive-notice' ),
			),
			array(
				'test_condition_name' => '宿泊のサイト・泊: 所要時間・バッファ・固定開始時刻が hidden、時刻の行は表示',
				'preset'              => 'lodging',
				'meta'                => 'night',
				'contains'            => array( 'name="vkbm_service_menu[booking_unit]"', 'name="vkbm_service_menu[duration_minutes]"' ),
				'row_hidden'          => array(
					'vkbm-check-in-time-field'     => false,
					'vkbm-check-out-time-field'    => false,
					'vkbm-duration-field'          => true,
					'vkbm-buffer-after-field'      => true,
					'vkbm-fixed-start-times-field' => true,
				),
				'not_contains'        => array( 'vkbm-booking-unit-inactive-notice' ),
			),
			array(
				'test_condition_name' => '宿泊以外のサイトで泊のメニューを開く: 案内とリンクを表示し、ラジオは出さない',
				'preset'              => 'salon',
				'meta'                => 'night',
				'contains'            => array( 'id="vkbm-booking-unit-inactive-notice"', 'vkbm-provider-settings&#038;tab=system#vkbm-industry-preset' ),
				'row_hidden'          => array(
					'vkbm-duration-field' => false,
				),
				'not_contains'        => array( 'name="vkbm_service_menu[booking_unit]"', 'vkbm-check-in-time-field' ),
			),
			array(
				'test_condition_name' => '宿泊以外のサイトで時間枠のメニュー: 欄も案内も出さない（従来どおり）',
				'preset'              => 'custom',
				'meta'                => '',
				'contains'            => array( 'name="vkbm_service_menu[duration_minutes]"' ),
				'row_hidden'          => array(
					'vkbm-duration-field' => false,
				),
				'not_contains'        => array( 'name="vkbm_service_menu[booking_unit]"', 'vkbm-booking-unit-inactive-notice', 'vkbm-check-in-time-field' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			if ( '' !== $case['meta'] ) {
				update_post_meta( $post->ID, '_vkbm_booking_unit', $case['meta'] );
			}
			$output = $this->render( $post );

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}
			foreach ( $case['row_hidden'] as $row_id => $expected_hidden ) {
				$matched = preg_match( '/<tr id="' . preg_quote( $row_id, '/' ) . '"[^>]*>/', $output, $matches );
				$this->assertSame( 1, $matched, $case['test_condition_name'] . ': ' . $row_id . ' の行が描画されること' );
				$this->assertSame( $expected_hidden, str_contains( $matches[0], 'hidden' ), $case['test_condition_name'] . ': ' . $row_id . ' の hidden' );
			}
		}
	}

	/**
	 * メタの書き込み権限: 宿泊の機能を使うサイトで、編集権限のあるユーザーだけが書き込める。
	 */
	public function test_register_meta(): void {
		// テストケース間でメタ登録が解除されるため、登録を明示的にやり直す。
		( new Service_Menu_Post_Type() )->register_meta();
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトでは編集権限があれば書き込める',
				'preset'              => 'lodging',
				'role'                => 'administrator',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊以外のサイトでは編集権限があっても書き込めない',
				'preset'              => 'salon',
				'role'                => 'administrator',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊のサイトでも、編集権限の無い購読者は書き込めない',
				'preset'              => 'lodging',
				'role'                => 'subscriber',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '業種プリセットが custom のサイトでは書き込めない',
				'preset'              => 'custom',
				'role'                => 'administrator',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			// 投稿の作成者は管理者のまま、操作するユーザーだけを差し替える。
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );
			foreach ( array( '_vkbm_booking_unit', '_vkbm_check_in_time', '_vkbm_check_out_time' ) as $meta_key ) {
				$this->assertSame(
					$case['expected'],
					current_user_can( 'edit_post_meta', $post->ID, $meta_key ),
					$case['test_condition_name'] . ': ' . $meta_key
				);
				// auth_callback 単体の結果（core の edit_post 判定を介さず、callback 自身が権限を確認していること）。
				$this->assertSame(
					$case['expected'],
					(bool) apply_filters( 'auth_post_meta_' . $meta_key . '_for_' . Service_Menu_Post_Type::POST_TYPE, false, $meta_key, $post->ID, get_current_user_id(), 'edit_post_meta', array() ),  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core のフィルター名。
					$case['test_condition_name'] . '（auth_callback）: ' . $meta_key
				);
			}
		}

		$this->assertContains( '_vkbm_booking_unit', ( new Service_Menu_Post_Type() )->get_revisioned_meta_keys(), 'リビジョン対象に予約の単位が含まれること' );
	}

	/**
	 * REST 経由の書き込み: 不正な値は保存されない。
	 */
	public function test_register_meta_rest_sanitize(): void {
		// メタ登録は他のテストの後始末で解除されるため、登録をやり直したうえで REST サーバーを作り直す。
		( new Service_Menu_Post_Type() )->register_meta();
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。
		$test_cases                = array(
			array(
				'test_condition_name' => '予約の単位に night 以外（week）を書いても night にはならない',
				'meta'                => array( '_vkbm_booking_unit' => 'week' ),
				'meta_key'            => '_vkbm_booking_unit',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'night は保存される',
				'meta'                => array( '_vkbm_booking_unit' => 'night' ),
				'meta_key'            => '_vkbm_booking_unit',
				'expected'            => 'night',
			),
			array(
				'test_condition_name' => '不正な時刻 25:00 は保存されない',
				'meta'                => array( '_vkbm_check_in_time' => '25:00' ),
				'meta_key'            => '_vkbm_check_in_time',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '正しい時刻は保存される',
				'meta'                => array( '_vkbm_check_out_time' => '11:30' ),
				'meta_key'            => '_vkbm_check_out_time',
				'expected'            => '11:30',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();

			$request = new \WP_REST_Request( 'POST', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $post->ID );
			$request->set_body_params( array( 'meta' => $case['meta'] ) );
			rest_get_server()->dispatch( $request );

			$this->assertSame( $case['expected'], (string) get_post_meta( $post->ID, $case['meta_key'], true ), $case['test_condition_name'] );
		}
	}
}
