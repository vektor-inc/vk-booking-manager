<?php
/**
 * メニューの料金の単位（_vkbm_price_unit）の判定・REST 公開・書き込み認可のテスト（#1002）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 料金の単位が「1ツアーあたり」として効く条件、REST フィールド、メタの REST 書き込み認可を検証する。
 *
 * @group post-types
 * @group price-unit
 */
class Service_Menu_Price_Unit_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金の単位（1ツアーあたり）は Pro 版限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に設定・ログイン状態を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global.
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する（予約枠の定員機能は ON）。
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
	 * メニューを作成する。
	 *
	 * @param array<string, mixed> $meta 追加のメタ。
	 * @return int メニューの投稿 ID。
	 */
	private function create_menu( array $meta = array() ): int {
		$staff_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 6 );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * メタを登録し直した REST サーバーを用意する。
	 *
	 * WordPress のテスト基盤はテストごとに登録済みのメタを解除するため、REST にメタを出すテストでは先に登録し直す。
	 */
	private function prepare_rest_server(): void {
		( new Service_Menu_Post_Type() )->register_meta();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core global.
	}

	/**
	 * get_price_unit / is_price_per_booking: アクティビティのサイトの時間枠のメニューで per_booking が保存されているときだけ「1ツアーあたり」。
	 */
	public function test_get_price_unit(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクティビティ・per_booking 保存 => 1ツアーあたり',
				'preset'              => 'activity',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => 'per_booking',
			),
			array(
				'test_condition_name' => 'アクティビティ・保存なし => 1名あたり（既存メニューは変わらない）',
				'preset'              => 'activity',
				'meta'                => array(),
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => 'アクティビティ・不正な値 => 1名あたり',
				'preset'              => 'activity',
				'meta'                => array( '_vkbm_price_unit' => 'per_room' ),
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => 'サロンへ切り替えた後 => 保存値が残っていても1名あたり（効かせないだけ）',
				'preset'              => 'salon',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => 'カスタム => 1名あたり',
				'preset'              => 'custom',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => '宿泊のサイトの泊のプラン => 宿泊の料金の単位とは別のため1名あたり',
				'preset'              => 'lodging',
				'meta'                => array(
					'_vkbm_price_unit'       => 'per_booking',
					'_vkbm_booking_unit'     => 'night',
					'_vkbm_night_price_unit' => 'per_room',
				),
				'expected'            => 'per_guest',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu( $case['meta'] );

			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_price_unit( $menu_id ), $case['test_condition_name'] );
			$this->assertSame( 'per_booking' === $case['expected'], Service_Menu_Post_Type::is_price_per_booking( $menu_id ), $case['test_condition_name'] . ' / is_price_per_booking' );
		}

		$this->set_site( 'activity' );
		$this->assertSame( 'per_guest', Service_Menu_Post_Type::get_price_unit( 0 ), 'メニュー ID が 0 => 1名あたり' );
	}

	/**
	 * get_stored_price_unit: 業種に関わらず保存済みの選択を返す（編集画面のラジオの初期選択用）。
	 */
	public function test_get_stored_price_unit(): void {
		$this->set_site( 'salon' );
		$menu_id = $this->create_menu( array( '_vkbm_price_unit' => 'per_booking' ) );

		$this->assertSame( 'per_booking', Service_Menu_Post_Type::get_stored_price_unit( $menu_id ), '別の業種でも保存済みの選択を返す' );
		$this->assertSame( 'per_guest', Service_Menu_Post_Type::get_price_unit( $menu_id ), '実際に効く値は1名あたり' );
	}

	/**
	 * 1ツアーあたりのメニューは、複数人一括予約と貸し切り予約が保存値に関わらず有効として扱われる。
	 */
	public function test_per_booking_forces_multi_guest_and_exclusive(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクティビティ・1ツアーあたり・許可フラグも貸し切り予約も未保存 => どちらも有効扱い',
				'preset'              => 'activity',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expect_multi'        => true,
				'expect_exclusive'    => true,
			),
			array(
				'test_condition_name' => 'アクティビティ・1名あたり・未保存 => どちらも無効（既存メニューは変わらない）',
				'preset'              => 'activity',
				'meta'                => array(),
				'expect_multi'        => false,
				'expect_exclusive'    => false,
			),
			array(
				'test_condition_name' => 'アクティビティ・1名あたり・保存値が ON => 保存値に従う',
				'preset'              => 'activity',
				'meta'                => array(
					'_vkbm_allow_multiple_guests' => true,
					'_vkbm_exclusive_when_booked' => true,
				),
				'expect_multi'        => true,
				'expect_exclusive'    => true,
			),
			array(
				'test_condition_name' => 'サロンへ切り替えた後 => 1ツアーあたりの保存値は効かない',
				'preset'              => 'salon',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expect_multi'        => false,
				'expect_exclusive'    => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu( $case['meta'] );

			$this->assertSame( $case['expect_multi'], Service_Menu_Post_Type::is_multi_guest_booking_enabled( $menu_id ), $case['test_condition_name'] . ' / 複数人一括予約' );
			$this->assertSame( $case['expect_multi'], Service_Menu_Post_Type::is_allow_multiple_guests_setting_on( $menu_id ), $case['test_condition_name'] . ' / 複数人一括予約の許可' );
			$this->assertSame( $case['expect_exclusive'], Service_Menu_Post_Type::is_exclusive_when_booked_setting_on( $menu_id ), $case['test_condition_name'] . ' / 貸し切り予約の設定' );

			// 受付判定（Availability_Service）も、1ツアーあたりは貸し切り予約が有効になる（定員が2以上のとき）。
			$this->assertSame(
				$case['expect_exclusive'],
				( new Availability_Service() )->is_menu_exclusive_when_booked( get_post( $menu_id ) ),
				$case['test_condition_name'] . ' / 貸し切り予約の実効判定'
			);
		}
	}

	/**
	 * 1ツアーあたりのメニューは、保存された料金区分が残っていても「料金区分で設定されている」とは扱わない。
	 */
	public function test_per_booking_ignores_stored_price_tiers(): void {
		$tiers = array(
			array(
				'label' => '大人',
				'price' => 5000,
			),
		);

		$this->set_site( 'activity' );
		$per_guest_id   = $this->create_menu(
			array(
				'_vkbm_allow_multiple_guests' => true,
				'_vkbm_price_tiers'           => $tiers,
			)
		);
		$per_booking_id = $this->create_menu(
			array(
				'_vkbm_price_unit'  => 'per_booking',
				'_vkbm_price_tiers' => $tiers,
			)
		);

		$this->assertTrue( Price_Tiers::is_menu_using_price_tiers( $per_guest_id ), '前提: 1名あたりでは区分が使われる' );
		$this->assertFalse( Price_Tiers::is_menu_using_price_tiers( $per_booking_id ), '1ツアーあたりでは区分を使わない' );
	}

	/**
	 * REST フィールド vkbm_price_unit: 実際に効く料金の単位を返す。
	 */
	public function test_rest_field_vkbm_price_unit(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクティビティ・1ツアーあたり => per_booking',
				'preset'              => 'activity',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => 'per_booking',
			),
			array(
				'test_condition_name' => 'アクティビティ・保存なし => per_guest',
				'preset'              => 'activity',
				'meta'                => array(),
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => 'カスタムへ切り替え後 => per_guest',
				'preset'              => 'custom',
				'meta'                => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => 'per_guest',
			),
		);

		$this->prepare_rest_server();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu( $case['meta'] );

			$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $menu_id ) )->get_data();
			$this->assertSame( $case['expected'], $data['vkbm_price_unit'] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * メタ _vkbm_price_unit の auth_callback: 編集権限があり、アクティビティのサイトのときだけ書き込める。
	 */
	public function test_meta_auth_callback(): void {
		( new Service_Menu_Post_Type() )->register_meta();
		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );
		$meta       = $registered['_vkbm_price_unit'] ?? null;
		$this->assertIsArray( $meta, '_vkbm_price_unit が登録されている' );
		$this->assertNotEmpty( $meta['show_in_rest'], 'REST に公開されている' );
		$this->assertIsCallable( $meta['auth_callback'], 'auth_callback が登録されている' );

		$test_cases = array(
			array(
				'test_condition_name' => '管理者・アクティビティ => 許可',
				'role'                => 'administrator',
				'preset'              => 'activity',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者・カスタム => 拒否（アクティビティのサイト以外では書き込めない）',
				'role'                => 'administrator',
				'preset'              => 'custom',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '管理者・サロン => 拒否',
				'role'                => 'administrator',
				'preset'              => 'salon',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '管理者・宿泊 => 拒否',
				'role'                => 'administrator',
				'preset'              => 'lodging',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '購読者・アクティビティ => 拒否（編集権限が無い）',
				'role'                => 'subscriber',
				'preset'              => 'activity',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu();
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );

			$actual = (bool) call_user_func( $meta['auth_callback'], false, '_vkbm_price_unit', $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * REST でメタを書き込む: 許可される場合だけ保存され、許可されない場合は保存されない。値は per_booking 以外を空文字に丸める。
	 */
	public function test_meta_write_via_rest(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者・アクティビティ・per_booking => 保存される',
				'role'                => 'administrator',
				'preset'              => 'activity',
				'value'               => 'per_booking',
				'expected_saved'      => 'per_booking',
			),
			array(
				'test_condition_name' => '管理者・アクティビティ・不正な値 => 空文字に丸めて保存される（1名あたり）',
				'role'                => 'administrator',
				'preset'              => 'activity',
				'value'               => 'per_room',
				'expected_saved'      => '',
			),
			array(
				'test_condition_name' => '管理者・カスタム・per_booking => 保存されない',
				'role'                => 'administrator',
				'preset'              => 'custom',
				'value'               => 'per_booking',
				'expected_saved'      => '',
			),
			array(
				'test_condition_name' => '購読者・アクティビティ・per_booking => 保存されない',
				'role'                => 'subscriber',
				'preset'              => 'activity',
				'value'               => 'per_booking',
				'expected_saved'      => '',
			),
		);

		$this->prepare_rest_server();
		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu();
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );

			$request = new WP_REST_Request( 'POST', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $menu_id );
			$request->set_param( 'meta', array( '_vkbm_price_unit' => $case['value'] ) );
			rest_do_request( $request );

			$this->assertSame( $case['expected_saved'], (string) get_post_meta( $menu_id, '_vkbm_price_unit', true ), $case['test_condition_name'] );
		}
	}

	/**
	 * REST フィールド vkbm_extra_guest_fee: 1ツアーあたりで追加料金を使うメニューだけ有効で、含まれる人数を返す（#1003）。
	 */
	public function test_rest_field_vkbm_extra_guest_fee(): void {
		$tiers = array(
			array(
				'label' => '大人',
				'price' => 3000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'アクティビティ・1ツアーあたり・追加料金あり・料金区分あり => 有効で含まれる人数を返す',
				'preset'              => 'activity',
				'meta'                => array(
					'_vkbm_price_unit'          => 'per_booking',
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 4,
					'_vkbm_price_tiers'         => $tiers,
				),
				'expected'            => array(
					'enabled'         => true,
					'included_guests' => 4,
				),
			),
			array(
				'test_condition_name' => '追加料金あり・料金区分が0件 => 無効（追加料金が加算されないため）',
				'preset'              => 'activity',
				'meta'                => array(
					'_vkbm_price_unit'          => 'per_booking',
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 4,
				),
				'expected'            => array(
					'enabled'         => false,
					'included_guests' => 4,
				),
			),
			array(
				'test_condition_name' => '1名あたり（保存なし）でメタが残っている => 無効',
				'preset'              => 'activity',
				'meta'                => array(
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 4,
					'_vkbm_price_tiers'         => $tiers,
				),
				'expected'            => array(
					'enabled'         => false,
					'included_guests' => 4,
				),
			),
			array(
				'test_condition_name' => 'カスタムへ切り替え後 => 無効',
				'preset'              => 'custom',
				'meta'                => array(
					'_vkbm_price_unit'          => 'per_booking',
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 4,
					'_vkbm_price_tiers'         => $tiers,
				),
				'expected'            => array(
					'enabled'         => false,
					'included_guests' => 4,
				),
			),
			array(
				'test_condition_name' => '含まれる人数が未保存 => 1',
				'preset'              => 'activity',
				'meta'                => array(),
				'expected'            => array(
					'enabled'         => false,
					'included_guests' => 1,
				),
			),
		);

		$this->prepare_rest_server();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu( $case['meta'] );

			$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $menu_id ) )->get_data();
			$this->assertSame( $case['expected'], $data['vkbm_extra_guest_fee'] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * メタ _vkbm_extra_guest_enabled / _vkbm_included_guests の auth_callback: 編集権限があり、アクティビティのサイトのときだけ書き込める（#1003）。
	 */
	public function test_extra_guest_meta_auth_callback(): void {
		( new Service_Menu_Post_Type() )->register_meta();
		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );

		$test_cases = array(
			array(
				'test_condition_name' => '管理者・アクティビティ => 許可',
				'role'                => 'administrator',
				'preset'              => 'activity',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '管理者・カスタム => 拒否',
				'role'                => 'administrator',
				'preset'              => 'custom',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '管理者・宿泊 => 拒否（宿泊の追加料金とは別の項目）',
				'role'                => 'administrator',
				'preset'              => 'lodging',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '購読者・アクティビティ => 拒否（編集権限が無い）',
				'role'                => 'subscriber',
				'preset'              => 'activity',
				'expected'            => false,
			),
		);

		foreach ( array( '_vkbm_extra_guest_enabled', '_vkbm_included_guests' ) as $meta_key ) {
			$meta = $registered[ $meta_key ] ?? null;
			$this->assertIsArray( $meta, $meta_key . ' が登録されている' );
			$this->assertNotEmpty( $meta['show_in_rest'], $meta_key . ' は REST に公開されている' );

			foreach ( $test_cases as $case ) {
				$this->set_site( $case['preset'] );
				$menu_id = $this->create_menu();
				wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );

				$actual = (bool) call_user_func( $meta['auth_callback'], false, $meta_key, $menu_id );
				$this->assertSame( $case['expected'], $actual, $meta_key . ' / ' . $case['test_condition_name'] );
			}
		}
	}

	/**
	 * REST でメタを書き込む: 許可される場合だけ保存され、値は丸められる（有効フラグは '1' 以外を空文字、人数は 1 以上）（#1003）。
	 */
	public function test_extra_guest_meta_write_via_rest(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '管理者・アクティビティ => 保存される',
				'role'                => 'administrator',
				'preset'              => 'activity',
				'enabled'             => '1',
				'included'            => 6,
				'expected_enabled'    => '1',
				'expected_included'   => '6',
			),
			array(
				'test_condition_name' => '管理者・アクティビティ・不正な値 => 有効フラグは空文字、人数は 1 に丸める',
				'role'                => 'administrator',
				'preset'              => 'activity',
				'enabled'             => 'yes',
				'included'            => 0,
				'expected_enabled'    => '',
				'expected_included'   => '1',
			),
			array(
				'test_condition_name' => '管理者・カスタム => 保存されない',
				'role'                => 'administrator',
				'preset'              => 'custom',
				'enabled'             => '1',
				'included'            => 6,
				'expected_enabled'    => '',
				'expected_included'   => '',
			),
			array(
				'test_condition_name' => '購読者・アクティビティ => 保存されない',
				'role'                => 'subscriber',
				'preset'              => 'activity',
				'enabled'             => '1',
				'included'            => 6,
				'expected_enabled'    => '',
				'expected_included'   => '',
			),
		);

		$this->prepare_rest_server();
		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu();
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );

			$request = new WP_REST_Request( 'POST', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $menu_id );
			$request->set_param(
				'meta',
				array(
					'_vkbm_extra_guest_enabled' => $case['enabled'],
					'_vkbm_included_guests'     => $case['included'],
				)
			);
			rest_do_request( $request );

			$this->assertSame( $case['expected_enabled'], (string) get_post_meta( $menu_id, '_vkbm_extra_guest_enabled', true ), $case['test_condition_name'] . ' / 有効フラグ' );
			$this->assertSame( $case['expected_included'], (string) get_post_meta( $menu_id, '_vkbm_included_guests', true ), $case['test_condition_name'] . ' / 含まれる人数' );
		}
	}
}
