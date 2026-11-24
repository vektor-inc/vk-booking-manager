<?php
/**
 * サービスメニュー編集画面の「最終チェックイン時刻」のテスト（#1125・#1136）。
 *
 * 予約画面のチェックイン予定時刻の選択肢の終わりになる時刻の保存・描画・読み取りと、
 * プラン側が未設定のときに基本設定の値を使う決め方（#1136）を検証する。
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
 * 最終チェックイン時刻の保存・描画・読み取りヘルパーのテスト（#1125・#1136）。
 *
 * @group admin
 * @group lodging
 */
class Service_Menu_Editor_Latest_Check_In_Time_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/**
	 * 管理画面のお知らせの一時保存のキーの接頭辞（Service_Menu_Editor::NOTICE_TRANSIENT_PREFIX と同じ値）。
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'vkbm_reservation_day_notice_';

	/**
	 * Setup で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避し、宿泊のサイトにする。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '予約の単位は Pro 版の業種プリセット（宿泊）限定のため、無料版ではスキップする。' );
		}

		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => 'lodging' ) )
		);
	}

	/**
	 * テスト後に全体設定・キャッシュ・ログイン状態・$_POST を元に戻す。
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
	 * 管理者でログインし、泊単位のサービスメニューを1件作成する。
	 *
	 * @param array<string, string> $meta 作成時に保存するメタ。
	 * @return \WP_Post 作成したメニュー。
	 */
	private function create_menu( array $meta = array() ): \WP_Post {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		foreach ( $meta as $meta_key => $meta_value ) {
			update_post_meta( $menu_id, $meta_key, $meta_value );
		}

		return get_post( $menu_id );
	}

	/**
	 * 基本設定の「最終チェックイン時刻（宿泊プラン）」を保存する（宿泊のサイトのまま）。
	 *
	 * @param string $time HH:MM。空文字なら未設定。
	 */
	private function set_default_latest_check_in_time( string $time ): void {
		$settings = get_option( Settings_Repository::OPTION_KEY, array() );
		$settings[ Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME ] = $time;
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * sanitize_callback を通さず、プランの最終チェックイン時刻を保存済みの値として書き込む（不正値を模すため）。
	 *
	 * @param int    $menu_id メニューの投稿 ID。
	 * @param string $time    書き込む値。
	 */
	private function insert_raw_latest_check_in_time( int $menu_id, string $time ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- sanitize_callback を通さず不正値を保存済みとして模すため。
			$wpdb->postmeta,
			array(
				'post_id'    => $menu_id,
				'meta_key'   => Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $time, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $menu_id, 'post_meta' );
	}

	/**
	 * 先頭の選択肢の期待値（英語の原文）を、翻訳後の文字にする。
	 *
	 * @param string $source_label 原文（'Do not set' または 'General Settings value (HH:MM)'）。
	 * @param string $default_time 基本設定の最終チェックイン時刻。
	 * @return string 翻訳後の文字。
	 */
	private function translate_unset_label( string $source_label, string $default_time ): string {
		if ( 'Do not set' === $source_label ) {
			return __( 'Do not set', 'vk-booking-manager' );
		}

		/* translators: %s: latest check-in time on the General Settings page (HH:MM). */
		return sprintf( __( 'General Settings value (%s)', 'vk-booking-manager' ), $default_time );
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
	 * 描画結果から、指定した name のセレクトの開始タグと選択肢を取り出す。
	 *
	 * @param string $output 描画結果。
	 * @param string $name   セレクトの name 属性。
	 * @return array{tag: string, values: array<int, string>, selected: array<int, string>} 開始タグ・選択肢の値・選ばれている値。
	 */
	private function parse_select( string $output, string $name ): array {
		$matched = preg_match( '/(<select[^>]*name="' . preg_quote( $name, '/' ) . '"[^>]*>)(.*?)<\/select>/s', $output, $matches );
		$this->assertSame( 1, $matched, $name . ' のセレクトが描画されること' );

		preg_match_all( '/<option value="([^"]*)"\s*([^>]*)>/', $matches[2], $options, PREG_SET_ORDER );
		$values   = array();
		$selected = array();
		foreach ( $options as $option ) {
			$values[] = $option[1];
			if ( str_contains( $option[2], 'selected' ) ) {
				$selected[] = $option[1];
			}
		}

		return array(
			'tag'      => $matches[1],
			'values'   => $values,
			'selected' => $selected,
		);
	}

	/**
	 * save_post: 最終チェックイン時刻の保存規則と、チェックイン時刻より前のときのお知らせを検証する。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'チェックイン時刻 15:00・最終 19:00 => 19:00 を保存し、お知らせは出さない',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'           => 'night',
					'check_in_hour'          => '15',
					'check_in_minute'        => '00',
					'latest_check_in_hour'   => '19',
					'latest_check_in_minute' => '00',
				),
				'expected'            => '19:00',
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'チェックイン時刻と同じ 15:00 => 有効として保存する',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'           => 'night',
					'check_in_hour'          => '15',
					'check_in_minute'        => '00',
					'latest_check_in_hour'   => '15',
					'latest_check_in_minute' => '00',
				),
				'expected'            => '15:00',
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '「設定しない」（時が空・分は無効で送られない） => 保存済みの値を削除する',
				'initial_meta'        => array( '_vkbm_latest_check_in_time' => '19:00' ),
				'submitted'           => array(
					'booking_unit'         => 'night',
					'check_in_hour'        => '15',
					'check_in_minute'      => '00',
					'latest_check_in_hour' => '',
				),
				'expected'            => null,
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'チェックイン時刻より前の 14:30 => 保存せず、保存済みの値も変えずにお知らせを出す',
				'initial_meta'        => array( '_vkbm_latest_check_in_time' => '19:00' ),
				'submitted'           => array(
					'booking_unit'           => 'night',
					'check_in_hour'          => '15',
					'check_in_minute'        => '00',
					'latest_check_in_hour'   => '14',
					'latest_check_in_minute' => '30',
				),
				'expected'            => '19:00',
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => '同じ保存でチェックイン時刻を 20:00 に遅らせ、最終 19:00 のまま => 最終だけ保存せずお知らせを出す',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'           => 'night',
					'check_in_hour'          => '20',
					'check_in_minute'        => '00',
					'latest_check_in_hour'   => '19',
					'latest_check_in_minute' => '00',
				),
				'expected'            => null,
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => '「分」が送られない（無効を解く処理が動かなかった） => 00 分として保存する',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'         => 'night',
					'check_in_hour'        => '15',
					'check_in_minute'      => '00',
					'latest_check_in_hour' => '21',
				),
				'expected'            => '21:00',
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '不正な時（25） => 保存済みの値を削除する',
				'initial_meta'        => array( '_vkbm_latest_check_in_time' => '19:00' ),
				'submitted'           => array(
					'booking_unit'           => 'night',
					'check_in_hour'          => '15',
					'check_in_minute'        => '00',
					'latest_check_in_hour'   => '25',
					'latest_check_in_minute' => '00',
				),
				'expected'            => null,
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '基本設定 20:00 のサイトで「基本設定の値（20:00）」のまま保存 => プランには書き込まない（#1136）',
				'default'             => '20:00',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'         => 'night',
					'check_in_hour'        => '15',
					'check_in_minute'      => '00',
					'latest_check_in_hour' => '',
				),
				'expected'            => null,
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'フォームに最終チェックイン時刻が無い保存 => 保存済みの値に触れない',
				'initial_meta'        => array( '_vkbm_latest_check_in_time' => '19:00' ),
				'submitted'           => array(
					'booking_unit'    => 'night',
					'check_in_hour'   => '15',
					'check_in_minute' => '00',
				),
				'expected'            => '19:00',
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_default_latest_check_in_time( $case['default'] ?? '' );
			$post = $this->create_menu( $case['initial_meta'] );
			delete_transient( self::NOTICE_TRANSIENT_PREFIX . get_current_user_id() );

			$this->submit( $post, $case['submitted'] );

			$actual = metadata_exists( 'post', $post->ID, '_vkbm_latest_check_in_time' ) ? get_post_meta( $post->ID, '_vkbm_latest_check_in_time', true ) : null;
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			// お知らせは、ほかのお知らせと同じ一時保存に追記される。
			$notice = (string) get_transient( self::NOTICE_TRANSIENT_PREFIX . get_current_user_id() );
			$this->assertSame(
				$case['expected_notice'],
				str_contains( $notice, __( 'The latest check-in time was not saved because it was earlier than the check-in time.', 'vk-booking-manager' ) ),
				$case['test_condition_name'] . '（お知らせ）'
			);
		}
	}

	/**
	 * get_latest_check_in_time: プランの値 → 基本設定の値 → 上限なし（空文字）の順で決める。
	 * どちらの値も、そのプランのチェックイン時刻より前なら使わない（#1125・#1136）。
	 */
	public function test_get_latest_check_in_time(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'プランも基本設定も未設定 => 空文字',
				'check_in'            => null,
				'latest'              => null,
				'default'             => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'プランがチェックイン時刻（既定 15:00）より後の 19:00・基本設定は未設定 => 19:00',
				'check_in'            => null,
				'latest'              => '19:00',
				'default'             => '',
				'expected'            => '19:00',
			),
			array(
				'test_condition_name' => 'プランがチェックイン時刻と同じ 16:00 => 16:00',
				'check_in'            => '16:00',
				'latest'              => '16:00',
				'default'             => '',
				'expected'            => '16:00',
			),
			array(
				'test_condition_name' => 'プランがチェックイン時刻 16:00 より前の 15:30（REST などで書き込まれた矛盾した値）・基本設定は未設定 => 空文字',
				'check_in'            => '16:00',
				'latest'              => '15:30',
				'default'             => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'プランが不正値 25:00・基本設定は未設定 => 空文字',
				'check_in'            => null,
				'latest'              => '25:00',
				'default'             => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'プランが未設定・基本設定 20:00 => 基本設定の 20:00',
				'check_in'            => null,
				'latest'              => null,
				'default'             => '20:00',
				'expected'            => '20:00',
			),
			array(
				'test_condition_name' => 'プラン 19:00・基本設定 20:00 => プランの 19:00 を優先する',
				'check_in'            => null,
				'latest'              => '19:00',
				'default'             => '20:00',
				'expected'            => '19:00',
			),
			array(
				'test_condition_name' => 'プランがチェックイン時刻より前の矛盾した値・基本設定 20:00 => 基本設定の 20:00',
				'check_in'            => '16:00',
				'latest'              => '15:30',
				'default'             => '20:00',
				'expected'            => '20:00',
			),
			array(
				'test_condition_name' => 'プランが未設定・基本設定 18:00 がチェックイン時刻 19:00 より前 => 使わず空文字（上限なし）',
				'check_in'            => '19:00',
				'latest'              => null,
				'default'             => '18:00',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'プランが未設定・基本設定がチェックイン時刻と同じ 19:00 => 19:00',
				'check_in'            => '19:00',
				'latest'              => null,
				'default'             => '19:00',
				'expected'            => '19:00',
			),
			array(
				'test_condition_name' => 'プランが未設定・基本設定が不正値 25:00 => 空文字',
				'check_in'            => null,
				'latest'              => null,
				'default'             => '25:00',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_default_latest_check_in_time( $case['default'] );
			$post = $this->create_menu();
			if ( null !== $case['check_in'] ) {
				update_post_meta( $post->ID, Service_Menu_Post_Type::META_CHECK_IN_TIME, $case['check_in'] );
			}
			if ( null !== $case['latest'] ) {
				$this->insert_raw_latest_check_in_time( $post->ID, $case['latest'] );
			}

			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_latest_check_in_time( $post->ID ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_plan_latest_check_in_time: プラン自身の値だけを返し、基本設定の値は返さない（#1136）。
	 */
	public function test_get_plan_latest_check_in_time(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'プランが未設定・基本設定 20:00 => 空文字（基本設定の値は返さない）',
				'check_in'            => null,
				'latest'              => null,
				'default'             => '20:00',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'プラン 19:00・基本設定 20:00 => 19:00',
				'check_in'            => null,
				'latest'              => '19:00',
				'default'             => '20:00',
				'expected'            => '19:00',
			),
			array(
				'test_condition_name' => 'プラン 21:30・基本設定は未設定 => 21:30',
				'check_in'            => null,
				'latest'              => '21:30',
				'default'             => '',
				'expected'            => '21:30',
			),
			array(
				'test_condition_name' => 'プランがチェックイン時刻 16:00 より前の 15:30 => 空文字',
				'check_in'            => '16:00',
				'latest'              => '15:30',
				'default'             => '20:00',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_default_latest_check_in_time( $case['default'] );
			$post = $this->create_menu();
			if ( null !== $case['check_in'] ) {
				update_post_meta( $post->ID, Service_Menu_Post_Type::META_CHECK_IN_TIME, $case['check_in'] );
			}
			if ( null !== $case['latest'] ) {
				$this->insert_raw_latest_check_in_time( $post->ID, $case['latest'] );
			}

			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_plan_latest_check_in_time( $post->ID ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_default_latest_check_in_time: 基本設定の値を返す。未保存・不正値は空文字（#1136）。
	 */
	public function test_get_default_latest_check_in_time(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '20:00 を保存 => 20:00',
				'default'             => '20:00',
				'expected'            => '20:00',
			),
			array(
				'test_condition_name' => '21:30 を保存 => 21:30',
				'default'             => '21:30',
				'expected'            => '21:30',
			),
			array(
				'test_condition_name' => '未設定（空文字） => 空文字',
				'default'             => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '不正値 24:00 => 空文字',
				'default'             => '24:00',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_default_latest_check_in_time( $case['default'] );

			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_default_latest_check_in_time(), $case['test_condition_name'] );
		}
	}

	/**
	 * is_default_latest_check_in_time_before_check_in: プランが未設定で、基本設定の値がチェックイン時刻より前のときだけ true（#1136）。
	 */
	public function test_is_default_latest_check_in_time_before_check_in(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'プランが未設定・基本設定 18:00・チェックイン時刻 19:00 => true',
				'check_in'            => '19:00',
				'latest'              => null,
				'default'             => '18:00',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'プランが未設定・基本設定 20:00・チェックイン時刻 15:00 => false',
				'check_in'            => '15:00',
				'latest'              => null,
				'default'             => '20:00',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'プラン 21:00 を設定・基本設定 18:00・チェックイン時刻 19:00 => false（基本設定の値をもともと使わない）',
				'check_in'            => '19:00',
				'latest'              => '21:00',
				'default'             => '18:00',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'プランも基本設定も未設定 => false',
				'check_in'            => '19:00',
				'latest'              => null,
				'default'             => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'プランが未設定・基本設定がチェックイン時刻と同じ 19:00 => false',
				'check_in'            => '19:00',
				'latest'              => null,
				'default'             => '19:00',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_default_latest_check_in_time( $case['default'] );
			$post = $this->create_menu( array( Service_Menu_Post_Type::META_CHECK_IN_TIME => $case['check_in'] ) );
			if ( null !== $case['latest'] ) {
				update_post_meta( $post->ID, Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME, $case['latest'] );
			}

			$this->assertSame( $case['expected'], Service_Menu_Post_Type::is_default_latest_check_in_time_before_check_in( $post->ID ), $case['test_condition_name'] );
		}
	}

	/**
	 * count_night_plans_ignoring_default_latest_check_in_time: 基本設定の値が使われない泊単位のプランだけを数える（#1136）。
	 */
	public function test_count_night_plans_ignoring_default_latest_check_in_time(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '基本設定 18:00・チェックイン時刻 19:00 の未設定プラン 2 件（公開・下書き）と 15:00 のプラン 1 件 => 2',
				'default'             => '18:00',
				'industry'            => 'lodging',
				'plans'               => array(
					array(
						'status'   => 'publish',
						'check_in' => '19:00',
						'latest'   => null,
						'unit'     => 'night',
					),
					array(
						'status'   => 'draft',
						'check_in' => '19:00',
						'latest'   => null,
						'unit'     => 'night',
					),
					array(
						'status'   => 'publish',
						'check_in' => '15:00',
						'latest'   => null,
						'unit'     => 'night',
					),
				),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => 'プラン側に値があるプラン・時間枠のプラン・ゴミ箱のプランは数えない => 0',
				'default'             => '18:00',
				'industry'            => 'lodging',
				'plans'               => array(
					array(
						'status'   => 'publish',
						'check_in' => '19:00',
						'latest'   => '21:00',
						'unit'     => 'night',
					),
					array(
						'status'   => 'publish',
						'check_in' => '19:00',
						'latest'   => null,
						'unit'     => 'slot',
					),
					array(
						'status'   => 'trash',
						'check_in' => '19:00',
						'latest'   => null,
						'unit'     => 'night',
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '基本設定が未設定 => 0',
				'default'             => '',
				'industry'            => 'lodging',
				'plans'               => array(
					array(
						'status'   => 'publish',
						'check_in' => '19:00',
						'latest'   => null,
						'unit'     => 'night',
					),
				),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '宿泊を使わない業種のサイト => 0',
				'default'             => '18:00',
				'industry'            => 'custom',
				'plans'               => array(
					array(
						'status'   => 'publish',
						'check_in' => '19:00',
						'latest'   => null,
						'unit'     => 'night',
					),
				),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			// ケースごとにプランを作り直すため、既存のプランを消す。
			$old_ids = get_posts(
				array(
					'post_type'      => Service_Menu_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $old_ids as $old_id ) {
				wp_delete_post( (int) $old_id, true );
			}
			foreach ( $case['plans'] as $plan ) {
				$menu_id = (int) $this->factory()->post->create(
					array(
						'post_type'   => Service_Menu_Post_Type::POST_TYPE,
						'post_status' => $plan['status'],
					)
				);
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, $plan['unit'] );
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_CHECK_IN_TIME, $plan['check_in'] );
				if ( null !== $plan['latest'] ) {
					update_post_meta( $menu_id, Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME, $plan['latest'] );
				}
			}
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge(
					( new Settings_Repository() )->get_default_settings(),
					array(
						'industry_preset' => $case['industry'],
						Settings_Repository::KEY_LODGING_LATEST_CHECK_IN_TIME => $case['default'],
					)
				)
			);
			Industry_Presets::clear_cache();

			$this->assertSame( $case['expected'], Service_Menu_Post_Type::count_night_plans_ignoring_default_latest_check_in_time(), $case['test_condition_name'] );
		}
	}

	/**
	 * count_night_plans_ignoring_default_latest_check_in_time: プランのメタをまとめて読み込み、クエリの数がプランの数に比例しない（#1136）。
	 */
	public function test_count_night_plans_ignoring_default_latest_check_in_time_queries(): void {
		// setUp() でもスキップしているが、宿泊プランの件数は Pro 版の業種プリセット（宿泊）が前提のため、テスト側でも明示する。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊のプランを数える処理は Pro 版の業種プリセット（宿泊）限定のため、無料版ではスキップする。' );
		}

		global $wpdb;

		$test_cases = array(
			array(
				'test_condition_name' => '未設定プラン 2 件（チェックイン時刻 19:00・基本設定 18:00） => 2 件',
				'plans'               => 2,
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '未設定プラン 8 件 => 8 件で、クエリの数は 2 件のときと同じ',
				'plans'               => 8,
				'expected'            => 8,
			),
			array(
				'test_condition_name' => '未設定プラン 0 件 => 0 件で、クエリの数は 2 件のときと同じ',
				'plans'               => 0,
				'expected'            => 0,
			),
		);

		$this->set_default_latest_check_in_time( '18:00' );
		$baseline_queries = null;
		foreach ( $test_cases as $case ) {
			// ケースごとに泊単位のプランを作り直す。
			$old_ids = get_posts(
				array(
					'post_type'      => Service_Menu_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $old_ids as $old_id ) {
				wp_delete_post( (int) $old_id, true );
			}
			$menu_ids = array();
			for ( $i = 0; $i < $case['plans']; $i++ ) {
				$menu_ids[] = $this->create_menu( array( Service_Menu_Post_Type::META_CHECK_IN_TIME => '19:00' ) )->ID;
			}

			// 基本設定・業種の判定を先に読み込み、プランのメタのキャッシュだけを空にしてから数える。
			Service_Menu_Post_Type::get_default_latest_check_in_time();
			Industry_Presets::supports( 'lodging' );
			foreach ( $menu_ids as $menu_id ) {
				wp_cache_delete( $menu_id, 'post_meta' );
			}
			$before = $wpdb->num_queries;
			$actual = Service_Menu_Post_Type::count_night_plans_ignoring_default_latest_check_in_time();
			$used   = $wpdb->num_queries - $before;

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			if ( null === $baseline_queries ) {
				$baseline_queries = $used;
				continue;
			}
			// プラン 0 件のときはメタの読み込み自体が無いため、2 件のとき以下になる。
			$this->assertLessThanOrEqual( $baseline_queries, $used, $case['test_condition_name'] . '（クエリの数）' );
		}
	}

	/**
	 * render_conditions_meta_box: 時刻の欄の選択肢（チェックイン時刻・最終チェックイン時刻は 00・30、チェックアウト時刻は 10 分刻み）と、
	 * 先頭の選択肢・説明文・基本設定の値が使われないときの注意（#1136）を検証する。
	 * 基本設定に値があるときは、描画された選択のまま保存しても、プランに基本設定の値が書き込まれないことも確かめる。
	 */
	public function test_render_conditions_meta_box(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '最終チェックイン時刻が未設定 => 「設定しない」が選ばれ、分は無効',
				'default'             => '',
				'meta'                => array( '_vkbm_check_in_time' => '15:00' ),
				'expected'            => array(
					'check_in_minutes'      => array( '00', '30' ),
					'check_in_selected'     => array( '15', '00' ),
					'latest_hour_head'      => '',
					'latest_selected'       => array( '', null ),
					'latest_minutes'        => array( '00', '30' ),
					'latest_minute_disable' => true,
					'check_out_minutes'     => array( '00', '10', '20', '30', '40', '50' ),
					'unset_label'           => 'Do not set',
					'notice'                => false,
				),
			),
			array(
				'test_condition_name' => '最終チェックイン時刻 19:30 => 19 時 30 分が選ばれ、分は有効',
				'default'             => '',
				'meta'                => array(
					'_vkbm_check_in_time'        => '15:00',
					'_vkbm_latest_check_in_time' => '19:30',
				),
				'expected'            => array(
					'check_in_minutes'      => array( '00', '30' ),
					'check_in_selected'     => array( '15', '00' ),
					'latest_hour_head'      => '',
					'latest_selected'       => array( '19', '30' ),
					'latest_minutes'        => array( '00', '30' ),
					'latest_minute_disable' => false,
					'check_out_minutes'     => array( '00', '10', '20', '30', '40', '50' ),
					'unset_label'           => 'Do not set',
					'notice'                => false,
				),
			),
			array(
				'test_condition_name' => '刻みを変える前に保存したチェックイン時刻 15:10 => 書き換えずに 10 分を選択肢に加えて表示する',
				'default'             => '',
				'meta'                => array( '_vkbm_check_in_time' => '15:10' ),
				'expected'            => array(
					'check_in_minutes'      => array( '00', '10', '30' ),
					'check_in_selected'     => array( '15', '10' ),
					'latest_hour_head'      => '',
					'latest_selected'       => array( '', null ),
					'latest_minutes'        => array( '00', '30' ),
					'latest_minute_disable' => true,
					'check_out_minutes'     => array( '00', '10', '20', '30', '40', '50' ),
					'unset_label'           => 'Do not set',
					'notice'                => false,
				),
			),
			array(
				'test_condition_name' => '基本設定 20:00・プランは未設定 => 先頭は「基本設定の値（20:00）」が選ばれ、説明に現在の値を出す。注意は出さない',
				'default'             => '20:00',
				'meta'                => array( '_vkbm_check_in_time' => '15:00' ),
				'expected'            => array(
					'check_in_minutes'      => array( '00', '30' ),
					'check_in_selected'     => array( '15', '00' ),
					'latest_hour_head'      => '',
					'latest_selected'       => array( '', null ),
					'latest_minutes'        => array( '00', '30' ),
					'latest_minute_disable' => true,
					'check_out_minutes'     => array( '00', '10', '20', '30', '40', '50' ),
					'unset_label'           => 'General Settings value (20:00)',
					'notice'                => false,
				),
			),
			array(
				'test_condition_name' => '基本設定 18:00・プランは未設定・チェックイン時刻 19:00 => 使われない理由と取る操作の注意を出す',
				'default'             => '18:00',
				'meta'                => array( '_vkbm_check_in_time' => '19:00' ),
				'expected'            => array(
					'check_in_minutes'      => array( '00', '30' ),
					'check_in_selected'     => array( '19', '00' ),
					'latest_hour_head'      => '',
					'latest_selected'       => array( '', null ),
					'latest_minutes'        => array( '00', '30' ),
					'latest_minute_disable' => true,
					'check_out_minutes'     => array( '00', '10', '20', '30', '40', '50' ),
					'unset_label'           => 'General Settings value (18:00)',
					'notice'                => true,
				),
			),
			array(
				'test_condition_name' => '基本設定 18:00・プラン 21:00・チェックイン時刻 19:00 => プランの値が選ばれ、注意は出さない',
				'default'             => '18:00',
				'meta'                => array(
					'_vkbm_check_in_time'        => '19:00',
					'_vkbm_latest_check_in_time' => '21:00',
				),
				'expected'            => array(
					'check_in_minutes'      => array( '00', '30' ),
					'check_in_selected'     => array( '19', '00' ),
					'latest_hour_head'      => '',
					'latest_selected'       => array( '21', '00' ),
					'latest_minutes'        => array( '00', '30' ),
					'latest_minute_disable' => false,
					'check_out_minutes'     => array( '00', '10', '20', '30', '40', '50' ),
					'unset_label'           => 'General Settings value (18:00)',
					'notice'                => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_default_latest_check_in_time( $case['default'] );
			$post   = $this->create_menu( $case['meta'] );
			$output = $this->render( $post );

			// 欄はチェックイン時刻の直下に置き、「泊」のときだけ表示する行として描画する。
			$this->assertMatchesRegularExpression(
				'/id="vkbm-check-in-time-field".*?<\/tr>\s*<tr id="vkbm-latest-check-in-time-field" class="vkbm-booking-unit-night-only"/s',
				$output,
				$case['test_condition_name'] . '（チェックイン時刻の直下）'
			);
			$this->assertStringContainsString( __( 'Latest check-in time', 'vk-booking-manager' ), $output, $case['test_condition_name'] );
			// 未設定のときに使われる値の説明。基本設定に値があれば現在の値を、無ければ 23:30 まで選べることを添える。
			$link_text            = __( 'Latest check-in time on the General Settings page', 'vk-booking-manager' );
			$expected_description = '' !== $case['default']
				/* translators: 1: link to the latest check-in time on the General Settings page, 2: its current value (HH:MM). */
				? sprintf( __( 'If not set, the %1$s (currently %2$s) is used.', 'vk-booking-manager' ), $link_text, $case['default'] )
				/* translators: %s: link to the latest check-in time on the General Settings page. */
				: sprintf( __( 'If not set, the %s is used.', 'vk-booking-manager' ), $link_text ) . ' ' . __( 'If it is not set on the General Settings page either, times up to 23:30 can be chosen.', 'vk-booking-manager' );
			// 基本設定の値がこのプランで使われないとき（注意を出すとき）は、注意と逆のことを言う「…が使われます」の文を出さない。
			$this->assertSame(
				! $case['expected']['notice'],
				str_contains( html_entity_decode( wp_strip_all_tags( $output ) ), $expected_description ),
				$case['test_condition_name'] . '（未設定のときに使われる値の説明）'
			);
			if ( $case['expected']['notice'] ) {
				$this->assertStringNotContainsString(
					/* translators: %s: link to the latest check-in time on the General Settings page. */
					sprintf( __( 'If not set, the %s is used.', 'vk-booking-manager' ), $link_text ),
					html_entity_decode( wp_strip_all_tags( $output ) ),
					$case['test_condition_name'] . '（基本設定が未設定のときの「…が使われます」の文も出さない）'
				);
				$this->assertDoesNotMatchRegularExpression(
					'/' . preg_quote( esc_html__( 'On the booking screen, the estimated check-in time can be chosen every 30 minutes from the check-in time up to this time.', 'vk-booking-manager' ), '/' ) . '\s*<br>/',
					$output,
					$case['test_condition_name'] . '（1 文目の後ろに改行を残さない）'
				);
			}
			// 「基本設定画面の最終チェックイン時刻」は、基本設定画面の該当の行へのリンクにする。
			$this->assertStringContainsString( 'tab=system#vkbm-default-latest-check-in-time', $output, $case['test_condition_name'] . '（基本設定画面へのリンク）' );
			$this->assertSame(
				$case['expected']['notice'],
				str_contains( $output, 'vkbm-latest-check-in-time-notice' ),
				$case['test_condition_name'] . '（基本設定の値が使われないときの注意）'
			);
			if ( $case['expected']['notice'] ) {
				$this->assertStringContainsString( esc_html__( 'Set the latest check-in time for this plan.', 'vk-booking-manager' ), $output, $case['test_condition_name'] . '（取る操作）' );
			}

			$check_in_hour     = $this->parse_select( $output, 'vkbm_service_menu[check_in_hour]' );
			$check_in_minute   = $this->parse_select( $output, 'vkbm_service_menu[check_in_minute]' );
			$latest_hour       = $this->parse_select( $output, 'vkbm_service_menu[latest_check_in_hour]' );
			$latest_minute     = $this->parse_select( $output, 'vkbm_service_menu[latest_check_in_minute]' );
			$check_out_minute  = $this->parse_select( $output, 'vkbm_service_menu[check_out_minute]' );
			$expected          = $case['expected'];
			$expected_selected = array_values( array_filter( $expected['latest_selected'], static fn( $value ) => null !== $value ) );

			$this->assertSame( $expected['check_in_minutes'], $check_in_minute['values'], $case['test_condition_name'] . '（チェックイン時刻の分）' );
			$this->assertSame( array( $expected['check_in_selected'][0] ), $check_in_hour['selected'], $case['test_condition_name'] . '（チェックイン時刻の時の選択）' );
			$this->assertSame( array( $expected['check_in_selected'][1] ), $check_in_minute['selected'], $case['test_condition_name'] . '（チェックイン時刻の分の選択）' );
			$this->assertSame( $expected['latest_hour_head'], $latest_hour['values'][0], $case['test_condition_name'] . '（時の先頭は「設定しない」）' );
			$this->assertStringContainsString( '>' . esc_html( $this->translate_unset_label( $case['expected']['unset_label'], $case['default'] ) ) . '</option>', $output, $case['test_condition_name'] . '（先頭の選択肢の文字）' );
			$this->assertSame( 25, count( $latest_hour['values'] ), $case['test_condition_name'] . '（「設定しない」+ 0〜23 時）' );
			$this->assertSame( array( $expected_selected[0] ), $latest_hour['selected'], $case['test_condition_name'] . '（最終チェックイン時刻の時の選択）' );
			$this->assertSame( array_slice( $expected_selected, 1 ), $latest_minute['selected'], $case['test_condition_name'] . '（最終チェックイン時刻の分の選択）' );
			$this->assertSame( $expected['latest_minutes'], $latest_minute['values'], $case['test_condition_name'] . '（最終チェックイン時刻の分）' );
			$this->assertSame( $expected['latest_minute_disable'], str_contains( $latest_minute['tag'], 'disabled' ), $case['test_condition_name'] . '（分の無効）' );
			$this->assertStringContainsString( 'data-vkbm-minute-target="#vkbm_service_menu_latest_check_in_minute"', $latest_hour['tag'], $case['test_condition_name'] . '（分の無効を切り替える対象）' );
			$this->assertSame( $expected['check_out_minutes'], $check_out_minute['values'], $case['test_condition_name'] . '（チェックアウト時刻の分）' );

			// 3 行とも「時 : 分」を 1 つの組（.vkbm-time-selects）にまとめ、時・分に幅をそろえるためのクラスを付ける。
			foreach ( array( 'check_in', 'latest_check_in', 'check_out' ) as $name_base ) {
				$this->assertMatchesRegularExpression(
					'/<div class="vkbm-time-selects">\s*<select id="vkbm_service_menu_' . $name_base . '_hour" class="vkbm-time-selects__hour[^"]*".*?<\/select>\s*<span>:<\/span>\s*<select id="vkbm_service_menu_' . $name_base . '_minute" class="vkbm-time-selects__minute".*?<\/select>\s*<\/div>/s',
					$output,
					$case['test_condition_name'] . '（' . $name_base . ' の時・分の組）'
				);
			}

			// 描画された選択のまま保存しても、プラン自身の値は変わらない（基本設定の値をプランへ書き込まない）。
			$before    = metadata_exists( 'post', $post->ID, Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME ) ? get_post_meta( $post->ID, Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME, true ) : null;
			$submitted = array(
				'booking_unit'         => 'night',
				'check_in_hour'        => $check_in_hour['selected'][0],
				'check_in_minute'      => $check_in_minute['selected'][0],
				'latest_check_in_hour' => $latest_hour['selected'][0],
			);
			if ( ! str_contains( $latest_minute['tag'], 'disabled' ) ) {
				$submitted['latest_check_in_minute'] = $latest_minute['selected'][0];
			}
			$this->submit( $post, $submitted );
			$after = metadata_exists( 'post', $post->ID, Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME ) ? get_post_meta( $post->ID, Service_Menu_Post_Type::META_LATEST_CHECK_IN_TIME, true ) : null;
			$this->assertSame( $before, $after, $case['test_condition_name'] . '（描画のまま保存してもプランの値は変わらない）' );
		}
	}

	/**
	 * register_meta / get_revisioned_meta_keys: REST の meta に公開し、リビジョンの対象にする。
	 */
	public function test_register_meta(): void {
		// テストケース間でメタ登録が解除されるため、登録を明示的にやり直す。
		( new Service_Menu_Post_Type() )->register_meta();
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。

		$test_cases = array(
			array(
				'test_condition_name' => '正しい時刻 20:30 => 保存される',
				'value'               => '20:30',
				'expected'            => '20:30',
			),
			array(
				'test_condition_name' => '不正な時刻 24:00 => 保存されない',
				'value'               => '24:00',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$post = $this->create_menu();

			$request = new \WP_REST_Request( 'POST', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $post->ID );
			$request->set_body_params( array( 'meta' => array( '_vkbm_latest_check_in_time' => $case['value'] ) ) );
			rest_get_server()->dispatch( $request );

			$this->assertSame( $case['expected'], (string) get_post_meta( $post->ID, '_vkbm_latest_check_in_time', true ), $case['test_condition_name'] );
		}

		$this->assertContains( '_vkbm_latest_check_in_time', ( new Service_Menu_Post_Type() )->get_revisioned_meta_keys(), 'リビジョン対象に最終チェックイン時刻が含まれること' );
	}
}
