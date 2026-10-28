<?php
/**
 * サービスメニュー編集画面の「規定人数を超える場合は追加料金あり」（1室1泊あたりの追加料金）のテスト（#934）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 追加料金のチェック・基本料金に含まれる人数の保存・描画・読み取りヘルパーのテスト（#934）。
 *
 * @group admin
 * @group lodging
 */
class Service_Menu_Editor_Night_Extra_Guest_Test extends WP_UnitTestCase {

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
			$this->markTestSkipped( '追加料金は Pro 版の業種プリセット（宿泊）限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・キャッシュ・ログイン状態・$_POST・お知らせを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		remove_filter( 'vkbm_industry_presets', array( $this, 'unlock_slot_capacity_in_lodging' ) );
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
		wp_set_current_user( 0 );
		$_POST                     = array();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global.
		parent::tearDown();
	}

	/**
	 * 業種プリセットと予約枠の定員機能を設定する。
	 *
	 * @param string $preset 保存する業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => $preset,
					'slot_capacity_enabled' => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 宿泊プリセットのまま、予約枠の定員機能を OFF にできる状態にする。
	 *
	 * 宿泊プリセットは定員機能を ON に固定（非表示項目）しているため、設定値を false で保存しても
	 * 実効値は ON のままになる。フィルターで当該項目を表示項目（ユーザーが変更できる状態）にしたうえで OFF を保存する。
	 */
	private function set_site_with_slot_capacity_off(): void {
		add_filter( 'vkbm_industry_presets', array( $this, 'unlock_slot_capacity_in_lodging' ) );
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => 'lodging',
					'slot_capacity_enabled' => false,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$this->assertFalse( Staff_Editor::is_slot_capacity_enabled(), '前提: 定員機能が実効的に OFF になっている' );
	}

	/**
	 * vkbm_industry_presets フィルター: 宿泊プリセットの定員機能を表示項目にする。
	 *
	 * @param array<string, mixed> $presets プリセット定義。
	 * @return array<string, mixed>
	 */
	public function unlock_slot_capacity_in_lodging( $presets ) {
		$presets['lodging']['fields']['slot_capacity_enabled']['display'] = true;
		return $presets;
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
	 * 1室1泊あたり・追加料金ありの送信値を組み立てる。
	 *
	 * @param array<string, mixed> $override 上書きする値。
	 * @return array<string, mixed>
	 */
	private function build_submission( array $override = array() ): array {
		return array_merge(
			array(
				'booking_unit'              => 'night',
				'night_price_unit'          => 'per_room',
				'night_extra_guest_enabled' => '1',
				'night_included_guests'     => '4',
				'max_capacity'              => '6',
				'base_price'                => '10000',
				'price_tiers'               => array(
					'label' => array( '大人', '子供' ),
					'price' => array( '3000', '2000' ),
				),
			),
			$override
		);
	}

	/**
	 * save_post: 追加料金のチェック・基本料金に含まれる人数・料金区分の保存規則を検証する。
	 */
	public function test_save_post(): void {
		$saved_tiers = array(
			array(
				'label' => '大人',
				'price' => 3000,
			),
			array(
				'label' => '子供',
				'price' => 2000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり => チェック・人数・料金区分が保存される',
				'preset'              => 'lodging',
				'submitted'           => array(),
				'expected'            => array(
					'_vkbm_night_extra_guest_enabled' => '1',
					'_vkbm_night_included_guests'     => 4,
					'_vkbm_price_tiers'               => $saved_tiers,
				),
			),
			array(
				'test_condition_name' => '1室1泊あたり・チェックなし => チェックのメタは保存されず、料金区分は削除される（従来どおり）',
				'preset'              => 'lodging',
				'submitted'           => array( 'night_extra_guest_enabled' => null ),
				'expected'            => array(
					'_vkbm_night_extra_guest_enabled' => null,
					'_vkbm_price_tiers'               => null,
				),
			),
			array(
				'test_condition_name' => '基本料金に含まれる人数が空欄 => 1 で保存する',
				'preset'              => 'lodging',
				'submitted'           => array( 'night_included_guests' => '' ),
				'expected'            => array( '_vkbm_night_included_guests' => 1 ),
			),
			array(
				'test_condition_name' => '基本料金に含まれる人数が 0 => 1 で保存する',
				'preset'              => 'lodging',
				'submitted'           => array( 'night_included_guests' => '0' ),
				'expected'            => array( '_vkbm_night_included_guests' => 1 ),
			),
			array(
				'test_condition_name' => '基本料金に含まれる人数が最大人数（6）ちょうど => そのまま保存する',
				'preset'              => 'lodging',
				'submitted'           => array( 'night_included_guests' => '6' ),
				'expected'            => array( '_vkbm_night_included_guests' => 6 ),
			),
			array(
				'test_condition_name' => '基本料金に含まれる人数が最大人数（6）を超える（9） => 最大人数に丸めて保存する（保存は止めない）',
				'preset'              => 'lodging',
				'submitted'           => array( 'night_included_guests' => '9' ),
				'expected'            => array(
					'_vkbm_night_included_guests'     => 6,
					'_vkbm_night_extra_guest_enabled' => '1',
					'_vkbm_price_tiers'               => $saved_tiers,
				),
			),
			array(
				'test_condition_name' => '1名1泊あたりへ切り替えて保存 => チェックと人数は消さずに残し、料金区分は従来どおり保存する',
				'preset'              => 'lodging',
				'submitted'           => array(
					'night_price_unit'      => 'per_guest',
					'allow_multiple_guests' => '1',
				),
				'expected'            => array(
					'_vkbm_night_extra_guest_enabled' => '1',
					'_vkbm_night_included_guests'     => 4,
					'_vkbm_price_tiers'               => $saved_tiers,
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）=> 追加料金のメタを保存しない',
				'preset'              => 'custom',
				'submitted'           => array(),
				'expected'            => array(
					'_vkbm_night_extra_guest_enabled' => null,
					'_vkbm_night_included_guests'     => null,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );

			$submission = $this->build_submission( $case['submitted'] );
			// null を渡した項目は「送信されない」ものとして扱う（チェックを外したときのチェックボックスの動き）。
			$submission = array_filter(
				$submission,
				static function ( $value ) {
					return null !== $value;
				}
			);
			$this->submit( $post, $submission );

			foreach ( $case['expected'] as $meta_key => $expected ) {
				$actual = metadata_exists( 'post', $post->ID, $meta_key ) ? get_post_meta( $post->ID, $meta_key, true ) : null;
				$this->assertEquals( $expected, $actual, $case['test_condition_name'] . ': ' . $meta_key );
			}
		}
	}

	/**
	 * save_post: 基本料金に含まれる人数を最大人数に丸めたときは、管理画面の上部に知らせる。
	 */
	public function test_save_post_notice_when_included_guests_is_reduced(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '最大人数（6）を超える（9）=> お知らせを出す',
				'submitted'           => array( 'night_included_guests' => '9' ),
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => '最大人数以内（4）=> お知らせを出さない',
				'submitted'           => array( 'night_included_guests' => '4' ),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'チェックなしで最大人数を超える値が残っている => お知らせを出さない（使われない値のため）',
				'submitted'           => array(
					'night_included_guests'     => '9',
					'night_extra_guest_enabled' => null,
				),
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );
			delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );

			$submission = array_filter(
				$this->build_submission( $case['submitted'] ),
				static function ( $value ) {
					return null !== $value;
				}
			);
			$this->submit( $post, $submission );

			$notice = get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
			if ( $case['expected_notice'] ) {
				$this->assertIsString( $notice, $case['test_condition_name'] );
				$this->assertStringContainsString( '6', $notice, $case['test_condition_name'] );
			} else {
				$this->assertFalse( $notice, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * save_post: 追加料金を使うのに料金区分が1件も無いときは、追加料金が加算されないことを知らせる。
	 */
	public function test_save_post_notice_when_no_price_tier(): void {
		$empty_tiers = array(
			'label' => array( '' ),
			'price' => array( '' ),
		);
		$test_cases  = array(
			array(
				'test_condition_name' => '追加料金あり・1室1泊あたり・料金区分なし => お知らせを出す',
				'submitted'           => array( 'price_tiers' => $empty_tiers ),
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => '料金区分あり => お知らせを出さない',
				'submitted'           => array(),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => 'チェックなし・料金区分なし => お知らせを出さない',
				'submitted'           => array(
					'price_tiers'               => $empty_tiers,
					'night_extra_guest_enabled' => null,
				),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '1名1泊あたり・料金区分なし => お知らせを出さない',
				'submitted'           => array(
					'price_tiers'      => $empty_tiers,
					'night_price_unit' => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST,
				),
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );
			delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );

			$submission = array_filter(
				$this->build_submission( $case['submitted'] ),
				static function ( $value ) {
					return null !== $value;
				}
			);
			$this->submit( $post, $submission );

			$notice = get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
			if ( $case['expected_notice'] ) {
				$this->assertIsString( $notice, $case['test_condition_name'] );
				$this->assertStringContainsString( 'extra guest fee is not added', $notice, $case['test_condition_name'] );
			} else {
				$this->assertFalse( $notice, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * save_post: 予約枠の定員機能が OFF のとき、基本料金に含まれる人数は最大人数で丸めず入力値を保持し、料金区分なしのお知らせも出さない。
	 */
	public function test_save_post_when_slot_capacity_is_off(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '定員機能OFF・最大人数(6)を超える9 => 丸めず9を保持する',
				'submitted'           => array( 'night_included_guests' => '9' ),
				'expected_included'   => '9',
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '定員機能OFF・料金区分なし => お知らせを出さず、人数も保持する',
				'submitted'           => array(
					'night_included_guests' => '9',
					'price_tiers'           => array(
						'label' => array( '' ),
						'price' => array( '' ),
					),
				),
				'expected_included'   => '9',
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site_with_slot_capacity_off();
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );
			update_post_meta( $post->ID, '_vkbm_max_capacity', 6 );
			delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );

			$this->submit( $post, $this->build_submission( $case['submitted'] ) );

			$this->assertSame( $case['expected_included'], (string) get_post_meta( $post->ID, Service_Menu_Post_Type::META_NIGHT_INCLUDED_GUESTS, true ), $case['test_condition_name'] );
			$this->assertFalse( get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_reservation_day_notice: ブロックエディタ画面では表示も消費もせず、通常の画面では表示して消費する。
	 */
	public function test_render_reservation_day_notice_consumption(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ブロックエディタ画面 => 出力せず、消費しない',
				'is_block_editor'     => true,
				'expect_output'       => false,
				'expect_remaining'    => true,
			),
			array(
				'test_condition_name' => 'クラシックエディタ等の通常画面 => 出力して消費する',
				'is_block_editor'     => false,
				'expect_output'       => true,
				'expect_remaining'    => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->create_menu();
			$key = 'vkbm_reservation_day_notice_' . get_current_user_id();
			set_transient( $key, 'テストのお知らせ', MINUTE_IN_SECONDS );

			set_current_screen( Service_Menu_Post_Type::POST_TYPE );
			get_current_screen()->is_block_editor( $case['is_block_editor'] );

			ob_start();
			( new Service_Menu_Editor() )->render_reservation_day_notice();
			$output = (string) ob_get_clean();

			$has_output = str_contains( $output, 'テストのお知らせ' );
			$remaining  = is_string( get_transient( $key ) );
			$this->assertSame( $case['expect_output'], $has_output, $case['test_condition_name'] );
			$this->assertSame( $case['expect_remaining'], $remaining, $case['test_condition_name'] );
			delete_transient( $key );
		}
	}

	/**
	 * お知らせ取得 REST ルート: 権限のある利用者にだけ返し、返したら消費する（二重に出ない）。
	 */
	public function test_notice_rest_route(): void {
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global.
		add_action( 'rest_api_init', array( new Service_Menu_Editor(), 'register_notice_route' ) );
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.

		$post = $this->create_menu();
		$key  = 'vkbm_reservation_day_notice_' . get_current_user_id();
		set_transient( $key, 'テストのお知らせ', MINUTE_IN_SECONDS );

		$request = new \WP_REST_Request( 'GET', '/vkbm/v1/service-menu-notice' );
		$request->set_param( 'post_id', $post->ID );
		$first = rest_do_request( $request );
		$this->assertSame( 200, $first->get_status(), '管理者は取得できる' );
		$this->assertSame( 'テストのお知らせ', $first->get_data()['message'], '1回目はお知らせを返す' );
		$this->assertFalse( get_transient( $key ), '返したら消費する' );

		$second = rest_do_request( $request );
		$this->assertSame( '', $second->get_data()['message'], '2回目は空（二重に出ない）' );

		set_transient( $key, 'テストのお知らせ', MINUTE_IN_SECONDS );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = rest_do_request( $request );
		$this->assertContains( $denied->get_status(), array( 401, 403 ), '編集権限の無い利用者は取得できない' );
		wp_set_current_user( 0 );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ), '未ログインは取得できない' );

		// 管理者でも、サービスメニュー以外（通常の投稿）の ID では取得できない。
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$key = 'vkbm_reservation_day_notice_' . get_current_user_id();
		set_transient( $key, 'テストのお知らせ', MINUTE_IN_SECONDS );
		$other = new \WP_REST_Request( 'GET', '/vkbm/v1/service-menu-notice' );
		$other->set_param( 'post_id', $this->factory()->post->create() );
		$this->assertSame( 403, rest_do_request( $other )->get_status(), '通常の投稿の ID は管理者でも取得できない' );
		$this->assertSame( 'テストのお知らせ', get_transient( $key ), '拒否したときはお知らせを消費しない' );
	}

	/**
	 * is_night_extra_guest_fee_enabled / get_night_included_guests: 読み取りの規則を検証する。
	 */
	public function test_is_night_extra_guest_fee_enabled(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト・泊・1室あたり・チェックあり => true',
				'preset'              => 'lodging',
				'meta'                => array(
					'_vkbm_booking_unit'              => 'night',
					'_vkbm_night_price_unit'          => 'per_room',
					'_vkbm_night_extra_guest_enabled' => '1',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '1室あたり・チェックなし => false',
				'preset'              => 'lodging',
				'meta'                => array(
					'_vkbm_booking_unit'     => 'night',
					'_vkbm_night_price_unit' => 'per_room',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '1名あたり（メタが残っている）=> false（消さずに効かせない）',
				'preset'              => 'lodging',
				'meta'                => array(
					'_vkbm_booking_unit'              => 'night',
					'_vkbm_night_extra_guest_enabled' => '1',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '時間枠のメニュー（メタが残っている）=> false',
				'preset'              => 'lodging',
				'meta'                => array(
					'_vkbm_night_price_unit'          => 'per_room',
					'_vkbm_night_extra_guest_enabled' => '1',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（メタが残っている）=> false（#570 の方針）',
				'preset'              => 'custom',
				'meta'                => array(
					'_vkbm_booking_unit'              => 'night',
					'_vkbm_night_price_unit'          => 'per_room',
					'_vkbm_night_extra_guest_enabled' => '1',
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			foreach ( $case['meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::is_night_extra_guest_fee_enabled( $post->ID ), $case['test_condition_name'] );
		}

		$included_cases = array(
			array(
				'test_condition_name' => '保存値なし => 1',
				'stored'              => null,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '保存値 4 => 4',
				'stored'              => 4,
				'expected'            => 4,
			),
			array(
				'test_condition_name' => '保存値 0（不正）=> 1',
				'stored'              => 0,
				'expected'            => 1,
			),
		);
		foreach ( $included_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();
			if ( null !== $case['stored'] ) {
				update_post_meta( $post->ID, '_vkbm_night_included_guests', $case['stored'] );
			}
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_night_included_guests( $post->ID ), $case['test_condition_name'] );
		}
	}

	/**
	 * Price_Tiers::is_menu_using_price_tiers: 1室1泊あたりは追加料金を使っていても false のまま（一覧の料金表記は基本料金を正とする）。
	 */
	public function test_is_menu_using_price_tiers_is_false_for_extra_guest_fee(): void {
		$tiers      = array(
			array(
				'label' => '大人',
				'price' => 3000,
			),
		);
		$base_meta  = array(
			'_vkbm_booking_unit'          => 'night',
			'_vkbm_allow_multiple_guests' => true,
			'_vkbm_max_capacity'          => 6,
			'_vkbm_price_tiers'           => $tiers,
		);
		$test_cases = array(
			array(
				'test_condition_name' => '1名1泊あたり・区分あり => true（比較用。区分で設定されているメニュー）',
				'meta'                => array(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金あり・区分あり => false（一覧は基本料金のみ）',
				'meta'                => array(
					'_vkbm_night_price_unit'          => 'per_room',
					'_vkbm_night_extra_guest_enabled' => '1',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '1室1泊あたり・追加料金なし・区分のデータが残っている => false',
				'meta'                => array( '_vkbm_night_price_unit' => 'per_room' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();
			foreach ( array_merge( $base_meta, $case['meta'] ) as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}
			$this->assertSame( $case['expected'], Price_Tiers::is_menu_using_price_tiers( $post->ID ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_conditions_meta_box: 追加料金のチェック・人数の欄と、料金区分の行・見出しの出し分けを検証する。
	 */
	public function test_render_conditions_meta_box(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1室あたり・チェックあり: チェックが入り、人数の欄を表示、料金区分の行を表示し、追加料金の見出しを出す。基本料金は読み取り専用にしない',
				'meta'                => array(
					'_vkbm_night_price_unit'          => 'per_room',
					'_vkbm_night_extra_guest_enabled' => '1',
					'_vkbm_night_included_guests'     => 4,
				),
				'is_checked'          => true,
				'contains'            => array(
					'name="vkbm_service_menu[night_included_guests]"',
					'value="4"',
					'Additional fee per extra guest, per night',
					'The price does not change with the number of people up to the number included in the basic price.',
				),
				'not_contains'        => array( 'readonly', 'Price (per guest, per night)' ),
				'row_hidden'          => array(
					'vkbm-night-extra-guest-fields'    => false,
					'vkbm-night-included-guests-field' => false,
					'vkbm-price-tiers-field'           => false,
				),
			),
			array(
				'test_condition_name' => '1室あたり・チェックなし: チェックの欄は表示、人数の欄と料金区分の行は hidden',
				'meta'                => array( '_vkbm_night_price_unit' => 'per_room' ),
				'is_checked'          => false,
				'contains'            => array( 'name="vkbm_service_menu[night_extra_guest_enabled]"' ),
				'not_contains'        => array( 'Additional fee per extra guest, per night' ),
				'row_hidden'          => array(
					'vkbm-night-extra-guest-fields'    => false,
					'vkbm-night-included-guests-field' => true,
					'vkbm-price-tiers-field'           => true,
				),
			),
			array(
				'test_condition_name' => '1名あたり（チェックのメタが残っている）: 追加料金の欄は hidden、料金区分の行は表示し、従来の見出しのまま',
				'meta'                => array( '_vkbm_night_extra_guest_enabled' => '1' ),
				'is_checked'          => true,
				'contains'            => array( 'Price (per guest, per night)' ),
				'not_contains'        => array( 'Additional fee per extra guest, per night' ),
				'row_hidden'          => array(
					'vkbm-night-extra-guest-fields' => true,
					'vkbm-price-tiers-field'        => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_max_capacity', 6 );
			update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );
			update_post_meta( $post->ID, '_vkbm_allow_multiple_guests', true );
			update_post_meta(
				$post->ID,
				'_vkbm_price_tiers',
				array(
					array(
						'label' => '大人',
						'price' => 3000,
					),
				)
			);
			foreach ( $case['meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}
			$output = $this->render( $post );

			// 追加料金のチェックボックスが入っているか（保存済みの選択を、1名あたりへ戻したメニューでも表示する）。
			$is_checked = 1 === preg_match( '/<input[^>]*name="vkbm_service_menu\[night_extra_guest_enabled\]"[^>]*\schecked/', $output );
			$this->assertSame( $case['is_checked'], $is_checked, $case['test_condition_name'] . ' (checked)' );

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] . ' (not): ' . $needle );
			}
			foreach ( $case['row_hidden'] as $element_id => $expected_hidden ) {
				$is_hidden = 1 === preg_match( '/id="' . preg_quote( $element_id, '/' ) . '"[^>]*\shidden/', $output );
				$this->assertSame( $expected_hidden, $is_hidden, $case['test_condition_name'] . ' (hidden): ' . $element_id );
			}
		}
	}
}
