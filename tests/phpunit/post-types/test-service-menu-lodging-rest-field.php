<?php
/**
 * メニューの REST フィールド vkbm_lodging（泊単位のプランの予約画面に必要な情報）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\Common\Lodging_Price_Display;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 泊単位のプランだけに情報が返り、それ以外（時間枠のメニュー・宿泊が無効なサイト）は null になることを検証する。
 *
 * @group post-types
 * @group lodging
 */
class Service_Menu_Lodging_Rest_Field_Test extends WP_UnitTestCase {

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

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * テスト後に設定・フィルターを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する。
	 *
	 * @param string $preset  保存する業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => $preset,
					'staff_enabled'   => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * メニューを作成する。
	 *
	 * @param bool                 $is_night 泊単位にするか。
	 * @param array<string, mixed> $meta     追加のメタ。
	 * @return int メニューの投稿 ID。
	 */
	private function create_menu( bool $is_night, array $meta = array() ): int {
		$room_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $room_id ) );
		if ( $is_night ) {
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * 泊単位のプランは情報を返し、時間枠のメニュー・宿泊が無効なサイトは null になる。
	 */
	public function test_get_lodging_rest_field(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊が有効・泊単位のメニュー => 人数の上限 1・区分なし・既定のチェックイン／チェックアウト時刻',
				'preset'              => 'lodging',
				'is_night'            => true,
				'meta'                => array(),
				'expected'            => array(
					'max_guests'        => 1,
					'guest_tiers'       => array(),
					'price_unit'        => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST,
					'base_price'        => 0,
					'has_variable_rate' => false,
					'lowest_price'      => 0,
					'check_in_time'     => Service_Menu_Post_Type::DEFAULT_CHECK_IN_TIME,
					'check_out_time'    => Service_Menu_Post_Type::DEFAULT_CHECK_OUT_TIME,
				),
			),
			array(
				'test_condition_name' => '宿泊が有効・泊単位・時刻を設定 => 設定した時刻',
				'preset'              => 'lodging',
				'is_night'            => true,
				'meta'                => array(
					Service_Menu_Post_Type::META_CHECK_IN_TIME  => '16:30',
					Service_Menu_Post_Type::META_CHECK_OUT_TIME => '11:00',
				),
				'expected'            => array(
					'max_guests'        => 1,
					'guest_tiers'       => array(),
					'price_unit'        => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST,
					'base_price'        => 0,
					'has_variable_rate' => false,
					'lowest_price'      => 0,
					'check_in_time'     => '16:30',
					'check_out_time'    => '11:00',
				),
			),
			array(
				'test_condition_name' => '宿泊が有効・泊単位・複数人一括予約（最大4名） => 人数の上限 4',
				'preset'              => 'lodging',
				'is_night'            => true,
				'meta'                => array(
					'_vkbm_allow_multiple_guests' => '1',
					'_vkbm_max_capacity'          => '4',
				),
				'expected'            => array(
					'max_guests'        => 4,
					'guest_tiers'       => array(),
					'price_unit'        => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST,
					'base_price'        => 0,
					'has_variable_rate' => false,
					'lowest_price'      => 0,
					'check_in_time'     => Service_Menu_Post_Type::DEFAULT_CHECK_IN_TIME,
					'check_out_time'    => Service_Menu_Post_Type::DEFAULT_CHECK_OUT_TIME,
				),
			),
			array(
				'test_condition_name' => '宿泊が有効・泊単位のメニューではない（時間枠） => null',
				'preset'              => 'lodging',
				'is_night'            => false,
				'meta'                => array(),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '宿泊以外の業種に切り替え済み・泊のメタが残っている => null',
				'preset'              => 'custom',
				'is_night'            => true,
				'meta'                => array(),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$menu_id = $this->create_menu( $case['is_night'], $case['meta'] );

			$actual = ( new Service_Menu_Post_Type() )->get_lodging_rest_field( array( 'id' => $menu_id ) );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 1名1泊あたりで区分があるメニューは区分を返し、1室1泊あたりは区分を返さない。
	 */
	public function test_get_lodging_rest_field_guest_tiers(): void {
		$this->set_site( 'lodging' );
		$tiers = array(
			array(
				'label' => '大人',
				'price' => 12000,
			),
			array(
				'label' => '子供',
				'price' => 6000,
			),
		);
		$multi = array(
			'_vkbm_allow_multiple_guests' => '1',
			'_vkbm_max_capacity'          => '3',
			'_vkbm_price_tiers'           => $tiers,
		);

		$per_guest = $this->create_menu( true, $multi );
		$per_room  = $this->create_menu( true, array_merge( $multi, array( Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM ) ) );
		$single    = $this->create_menu( true, array( '_vkbm_price_tiers' => $tiers ) );

		$field = new Service_Menu_Post_Type();

		$per_guest_info = $field->get_lodging_rest_field( array( 'id' => $per_guest ) );
		$this->assertSame( 3, $per_guest_info['max_guests'], '1名1泊あたり・区分あり => 人数の上限は 3' );
		$this->assertSame( array( '大人', '子供' ), array_column( $per_guest_info['guest_tiers'], 'label' ), '1名1泊あたり・区分あり => 区分を返す' );

		$per_room_info = $field->get_lodging_rest_field( array( 'id' => $per_room ) );
		$this->assertSame( array(), $per_room_info['guest_tiers'], '1室1泊あたり => 区分のデータが残っていても返さない' );
		$this->assertSame( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM, $per_room_info['price_unit'], '1室1泊あたり => 料金の単位を返す' );
		$this->assertSame( Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST, $per_guest_info['price_unit'], '1名1泊あたり => 料金の単位を返す' );

		$single_info = $field->get_lodging_rest_field( array( 'id' => $single ) );
		$this->assertSame( array(), $single_info['guest_tiers'], '複数人一括予約でない（上限 1 名） => 区分は使わない' );

		$this->assertSame( 3, Lodging_Booking::get_guest_limit( $per_guest ), '人数の上限は resolve_guests と同じ値' );
	}

	/**
	 * 休前日・特定日の料金が平日と違う金額のプランだけ has_variable_rate が true になり、lowest_price はカードと同じ最安になる。
	 */
	public function test_get_lodging_rest_field_variable_rate(): void {
		$this->set_site( 'lodging' );

		$pre_holiday = Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY;
		$special     = Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL;
		$per_room    = array( Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM );

		$test_cases = array(
			array(
				'test_condition_name' => '休前日・特定日の料金が空欄 => false・基本料金',
				'meta'                => array( '_vkbm_base_price' => 3500 ),
				'periods'             => false,
				'expected'            => array( false, 3500 ),
			),
			array(
				'test_condition_name' => '休前日の料金が平日より高い => true・平日の金額が最安',
				'meta'                => array(
					'_vkbm_base_price' => 3500,
					$pre_holiday       => '4000',
				),
				'periods'             => false,
				'expected'            => array( true, 3500 ),
			),
			array(
				'test_condition_name' => '休前日の料金が平日と同じ金額 => false',
				'meta'                => array(
					'_vkbm_base_price' => 3500,
					$pre_holiday       => '3500',
				),
				'periods'             => false,
				'expected'            => array( false, 3500 ),
			),
			array(
				'test_condition_name' => '特定日の料金が平日より安い（期間あり） => true・特定日の金額が最安',
				'meta'                => array(
					'_vkbm_base_price' => 3500,
					$special           => '3000',
				),
				'periods'             => true,
				'expected'            => array( true, 3000 ),
			),
			array(
				'test_condition_name' => '特定日の料金はあるが BM設定に特定日の期間が無い => false',
				'meta'                => array(
					'_vkbm_base_price' => 3500,
					$special           => '4500',
				),
				'periods'             => false,
				'expected'            => array( false, 3500 ),
			),
			array(
				'test_condition_name' => '1室1泊あたりで休前日の料金が安い => true・休前日の金額が最安',
				'meta'                => array_merge(
					$per_room,
					array(
						'_vkbm_base_price' => 30000,
						$pre_holiday       => '25000',
					)
				),
				'periods'             => false,
				'expected'            => array( true, 25000 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_special_periods( $case['periods'] );
			$menu_id = $this->create_menu( true, $case['meta'] );
			$actual  = ( new Service_Menu_Post_Type() )->get_lodging_rest_field( array( 'id' => $menu_id ) );

			$this->assertSame( $case['expected'][0], $actual['has_variable_rate'], $case['test_condition_name'] );
			$this->assertSame( $case['expected'][1], $actual['lowest_price'], $case['test_condition_name'] );

			// カードの料金行と同じ最安の金額になる。
			$rows = Lodging_Price_Display::get_menu_price_rows( $menu_id );
			if ( array() !== $rows ) {
				$this->assertSame( min( array_column( $rows, 'price' ) ), $actual['lowest_price'], $case['test_condition_name'] . '（カードと同じ金額）' );
			}
		}
	}

	/**
	 * BM設定の特定日の期間を設定する（false なら期間なし）。
	 *
	 * @param bool $has_periods 特定日の期間を1つ設定するか。
	 */
	private function set_special_periods( bool $has_periods ): void {
		$settings                          = get_option( Settings_Repository::OPTION_KEY, array() );
		$settings['lodging_special_dates'] = $has_periods
			? array(
				array(
					'label' => '年末年始',
					'start' => '2026-12-29',
					'end'   => '2027-01-03',
				),
			)
			: array();
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * メニューの REST レスポンスに vkbm_lodging が含まれる（登録されている）。
	 */
	public function test_rest_field_is_registered(): void {
		$this->set_site( 'lodging' );
		$menu_id = $this->create_menu( true, array( '_vkbm_base_price' => 10000 ) );

		wp_set_current_user( 0 );
		$request  = new \WP_REST_Request( 'GET', '/wp/v2/vkbm_service_menu/' . $menu_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'vkbm_lodging', $data, '泊単位のメニュー => vkbm_lodging が返る' );
		$this->assertIsArray( $data['vkbm_lodging'] );
		$this->assertSame( 1, $data['vkbm_lodging']['max_guests'] );
		$this->assertSame( 10000, $data['vkbm_lodging']['base_price'], 'プランの単価を返す' );
	}
}
