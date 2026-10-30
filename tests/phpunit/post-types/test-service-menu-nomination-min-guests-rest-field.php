<?php
/**
 * vkbm_nomination_min_guests REST フィールドのテスト（#393）。
 *
 * サービスメニュー投稿の REST レスポンスに、指名を使うメニューの最低申し込み人数
 * （受付制限）を読み取り専用フィールドとして公開する。フロント（app.js）は、この値を
 * 自前で再計算せずそのまま使うため、判定条件（指名可否・複数人一括予約・サイト全体の
 * 「予約枠の定員機能」スイッチ・予約枠の定員2以上）が Availability_Service 側の
 * get_menu_nomination_min_guests() の1箇所に集約されていることを担保する
 * （安藤レビュー指摘。以前はフロントが同じ条件をメタから再計算しており、サイト全体の
 * 予約枠の定員機能スイッチを見落としていた）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * vkbm_nomination_min_guests REST フィールドのテスト。
 *
 * @group post-types
 * @group nomination
 */
class Service_Menu_Nomination_Min_Guests_Rest_Field_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 最低申し込み人数は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '最低申し込み人数は Pro 版限定機能のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・静的キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		Industry_Presets::clear_cache();
		parent::tearDown();
	}

	/**
	 * 全体設定（指名機能・予約枠の定員機能）を更新する。
	 *
	 * @param bool   $nomination     指名機能を有効にする場合は true。
	 * @param bool   $slot_capacity  予約枠の定員機能を有効にする場合は true。
	 * @param string $industry_preset 業種プリセット。
	 */
	private function set_settings( bool $nomination, bool $slot_capacity, string $industry_preset = 'custom' ): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = $nomination;
		$settings['slot_capacity_enabled'] = $slot_capacity;
		$settings['industry_preset']       = $industry_preset;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
		Industry_Presets::clear_cache();
	}

	/**
	 * get_nomination_min_guests_rest_field() が Availability_Service に正しく委譲し、
	 * サイト全体の「予約枠の定員機能」スイッチOFF時は実効0を返すことを検証する。
	 *
	 * #393 安藤レビュー指摘の再現ケース: フロントがこのスイッチを見ずにメタから再計算すると、
	 * サイト全体でOFFにしても下限が残ってしまう不整合があった。この REST フィールドは
	 * Availability_Service::get_menu_nomination_min_guests() に委譲するため、
	 * このスイッチがOFFなら（get_menu_max_capacity 内部で実効定員1固定になり）常に0を返す。
	 */
	public function test_get_nomination_min_guests_rest_field(): void {
		$post_type = new Service_Menu_Post_Type();

		$test_cases = array(
			array(
				'test_condition_name'   => '指名ON・複数人一括予約ON・予約枠の定員機能ON・定員5・最低3 => 3（正常系）',
				'nomination_enabled'    => true,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'expected'              => 3,
			),
			array(
				'test_condition_name'   => '宿泊の1室1泊あたり・最低3 => 0（人数による受付制限なし）',
				'nomination_enabled'    => true,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'booking_unit'          => 'night',
				'night_price_unit'      => 'per_room',
				'industry_preset'       => 'lodging',
				'expected'              => 0,
			),
			array(
				'test_condition_name'   => '宿泊の1名1泊あたり・最低3 => 3（従来どおり受付制限あり）',
				'nomination_enabled'    => true,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'booking_unit'          => 'night',
				'night_price_unit'      => '',
				'industry_preset'       => 'lodging',
				'expected'              => 3,
			),
			array(
				'test_condition_name'   => '宿泊サイトの時間枠・保存済みper_room・最低3 => 3（時間枠の挙動は不変）',
				'nomination_enabled'    => true,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'booking_unit'          => '',
				'night_price_unit'      => 'per_room',
				'industry_preset'       => 'lodging',
				'expected'              => 3,
			),
			array(
				'test_condition_name'   => '指名ON・複数人一括予約ON・予約枠の定員機能OFF（サイト全体）・定員5・最低3 => 0（安藤レビュー指摘の回帰防止）',
				'nomination_enabled'    => true,
				'slot_capacity_enabled' => false,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'expected'              => 0,
			),
			array(
				'test_condition_name'   => '指名OFF・貸し切り予約OFF => 0（受付制限は指名を使うメニューか貸し切り予約ONのメニューのみ）',
				'nomination_enabled'    => false,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'expected'              => 0,
			),
			array(
				'test_condition_name'   => '指名OFF・貸し切り予約ON・予約枠の定員機能ON・定員5・最低3 => 3（#968：フロントが人数の下限として使う値）',
				'nomination_enabled'    => false,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'exclusive_when_booked' => true,
				'expected'              => 3,
			),
			array(
				'test_condition_name'   => '指名OFF・貸し切り予約ON・予約枠の定員機能OFF（サイト全体）・定員5・最低3 => 0（#968：定員機能OFFで実効0）',
				'nomination_enabled'    => false,
				'slot_capacity_enabled' => false,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'exclusive_when_booked' => true,
				'expected'              => 0,
			),
			array(
				'test_condition_name'   => '無効な post ID（0） => 0（境界値）',
				'nomination_enabled'    => true,
				'slot_capacity_enabled' => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 5,
				'min_capacity'          => 3,
				'expected'              => 0,
				'use_invalid_post_id'   => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_settings( $case['nomination_enabled'], $case['slot_capacity_enabled'], $case['industry_preset'] ?? 'custom' );

			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			update_post_meta( $menu_id, '_vkbm_min_capacity', $case['min_capacity'] );
			if ( ! empty( $case['booking_unit'] ) ) {
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, $case['booking_unit'] );
			}
			if ( ! empty( $case['night_price_unit'] ) ) {
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT, $case['night_price_unit'] );
			}
			if ( $case['allow_multiple_guests'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			}
			if ( ! empty( $case['exclusive_when_booked'] ) ) {
				update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );
			}

			$post_id = ! empty( $case['use_invalid_post_id'] ) ? 0 : $menu_id;
			$actual  = $post_type->get_nomination_min_guests_rest_field( array( 'id' => $post_id ) );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			Staff_Editor::clear_nomination_enabled_cache();
		}
	}
}
