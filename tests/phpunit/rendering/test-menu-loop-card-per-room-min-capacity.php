<?php
/**
 * 「1室1泊あたり」のプランでは最少催行人数を表示しないことのテスト（#1109）。
 *
 * 1室1泊あたりのプランは人数で受付を制限しない（#830）。保存済みの最少催行人数（_vkbm_min_capacity）が
 * 残っていても、メニューカードに「最少催行人数」を出さず、Availability_Service::get_menu_min_capacity() も
 * 0（制約なし）を返すことを確認する。料金の単位を「1名1泊あたり」へ戻せば保存値が元どおり効くことも確かめる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 1室1泊あたりのプランの最少催行人数の表示・取得を検証するテスト。
 *
 * @group lodging
 */
class Menu_Loop_Card_Per_Room_Min_Capacity_Test extends WP_UnitTestCase {
	/**
	 * テスト前の基本設定（元に戻すために保持する）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 業種を宿泊にし、予約枠の定員機能を有効にする。
	 */
	public function set_up(): void {
		parent::set_up();

		// 宿泊・予約枠の定員は Pro 版限定のため、無料版ではスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊・予約枠の定員は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => 'lodging',
					'slot_capacity_enabled' => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 基本設定とキャッシュを元に戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tear_down();
	}

	/**
	 * 指名を使わない・定員 10 名・最少人数 4 名の泊単位のプランを作る。
	 *
	 * @param string $price_unit 料金の単位（NIGHT_PRICE_UNIT_PER_ROOM / NIGHT_PRICE_UNIT_PER_GUEST）。
	 * @return int 作成したサービスメニューの投稿 ID。
	 */
	private function create_night_menu( string $price_unit ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テスト宿泊プラン',
			)
		);
		update_post_meta( $menu_id, '_vkbm_base_price', 20000 );
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, Service_Menu_Post_Type::BOOKING_UNIT_NIGHT );
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT, $price_unit );
		update_post_meta( $menu_id, '_vkbm_disable_nomination', true );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 4 );

		return $menu_id;
	}

	/**
	 * 1室1泊あたり・指名 OFF・最少 4 名なら、カードに最少催行人数を出さない。
	 * 同じ設定で1名1泊あたりなら出す（保存値は消さず、料金の単位を戻せば元どおり効く）。
	 */
	public function test_render_menu_card_hides_min_capacity_for_per_room_plan(): void {
		$label = __( 'Minimum participants to confirm', 'vk-booking-manager' );
		$block = new Menu_Loop_Block();

		$test_cases = array(
			array(
				'test_condition_name' => '1室1泊あたり・指名OFF・定員10・最少4 => 最少催行人数を表示しない',
				'price_unit'          => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
				'expect_label'        => false,
			),
			array(
				'test_condition_name' => '1名1泊あたり・指名OFF・定員10・最少4 => 最少催行人数「4名」を表示する',
				'price_unit'          => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST,
				'expect_label'        => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件どおりのプランを作り、公開境界（render_menu_card）でカードを描く。
			$menu_id = $this->create_night_menu( $case['price_unit'] );
			$output  = $block->render_menu_card( $menu_id );

			// カードが空で否定のアサーションが空振りしないよう、先に描画されていることを確かめる。
			$this->assertStringContainsString( 'テスト宿泊プラン', $output, $case['test_condition_name'] );

			if ( $case['expect_label'] ) {
				$this->assertStringContainsString( $label, $output, $case['test_condition_name'] );
				$this->assertStringContainsString( vkbm_format_guests_count( 4 ), $output, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( $label, $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * get_menu_min_capacity() は1室1泊あたりなら 0、1名1泊あたりなら保存値を返す。
	 */
	public function test_get_menu_min_capacity_for_per_room_plan(): void {
		$service = new Availability_Service();

		$test_cases = array(
			array(
				'test_condition_name' => '1室1泊あたり・指名OFF・定員10・最少4 => 0（人数で受付を制限しない）',
				'price_unit'          => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '1名1泊あたり・指名OFF・定員10・最少4 => 4（保存値どおり）',
				'price_unit'          => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_GUEST,
				'expected'            => 4,
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件どおりのプランを作り、最少催行人数を取得する。
			$menu_post = get_post( $this->create_night_menu( $case['price_unit'] ) );
			$this->assertInstanceOf( \WP_Post::class, $menu_post, $case['test_condition_name'] );

			$this->assertSame(
				$case['expected'],
				$service->get_menu_min_capacity( $menu_post ),
				$case['test_condition_name']
			);
		}
	}
}
