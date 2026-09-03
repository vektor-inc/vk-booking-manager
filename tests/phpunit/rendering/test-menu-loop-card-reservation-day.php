<?php
/**
 * メニューループ（カード）の予約可能日ラベル表示の分岐テスト。
 *
 * 予約可能日種別（_vkbm_reservation_day_type）に応じて、カードに
 * 「予約可能日」項目と種別ラベル（土日限定 / 平日限定 / それ以外は値そのまま）を
 * 表示し、未指定（空）のサービスでは項目を出力しないことを検証する。
 * 集約リファクタ前後で種別→ラベルの写像が変わらないことを保証するための
 * 回帰（characterization）テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * カードレイアウトの予約可能日ラベル表示を検証するテスト。
 */
class Menu_Loop_Card_Reservation_Day_Test extends WP_UnitTestCase {
	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * 各テスト前にブロックインスタンスを用意する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block = new Menu_Loop_Block();
	}

	/**
	 * 予約可能日種別メタを設定したサービスメニューを生成するヘルパー。
	 *
	 * @param mixed $reservation_day_type 保存する予約可能日種別メタ（null の場合は未設定）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_reservation_day_type( $reservation_day_type ): int {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス',
			)
		);

		// 基本料金を設定しておく（カード自体が空にならないようにするため）。
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		// null の場合はメタ未設定の状態を表現するため update しない。
		if ( null !== $reservation_day_type ) {
			update_post_meta( $menu_id, '_vkbm_reservation_day_type', $reservation_day_type );
		}

		return (int) $menu_id;
	}

	/**
	 * 予約可能日種別に応じて、予約可能日項目とラベルの表示が切り替わることを確認する。
	 */
	public function test_render_menu_card(): void {
		// 「予約可能日」ラベルと各種別ラベル。実装側と同じ翻訳関数で組み立て、
		// ロケールが切り替わっても期待値が実装の出力文言と一致するようにする。
		$reservation_date_label = __( 'Reservation date', 'vk-booking-manager' );
		$weekend_label          = __( 'Saturdays and Sundays only', 'vk-booking-manager' );
		$weekday_label          = __( 'Weekdays only', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name'  => '土日限定（weekend） => 「予約可能日」項目と「土日限定」ラベルが表示される',
				'reservation_day_type' => 'weekend',
				'expect_item'          => true,
				'expect_contains'      => $weekend_label,
			),
			array(
				'test_condition_name'  => '平日限定（weekday） => 「予約可能日」項目と「平日限定」ラベルが表示される',
				'reservation_day_type' => 'weekday',
				'expect_item'          => true,
				'expect_contains'      => $weekday_label,
			),
			array(
				'test_condition_name'  => 'それ以外の値（未知の種別） => 値そのままがラベルとして表示される',
				'reservation_day_type' => 'custom-type',
				'expect_item'          => true,
				'expect_contains'      => 'custom-type',
			),
			array(
				'test_condition_name'  => '指定なし（空文字） => 予約可能日項目は出力されない',
				'reservation_day_type' => '',
				'expect_item'          => false,
				'expect_contains'      => '',
			),
			array(
				'test_condition_name'  => 'メタ未設定 => 予約可能日項目は出力されない',
				'reservation_day_type' => null,
				'expect_item'          => false,
				'expect_contains'      => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件に従ってサービスメニューを作成する。
			$menu_id = $this->create_menu_with_reservation_day_type( $case['reservation_day_type'] );

			// 公開境界（render_menu_card）経由でカードHTMLを生成する。
			$output = $this->block->render_menu_card( $menu_id );

			if ( $case['expect_item'] ) {
				// 予約可能日項目が表示される場合、ラベル見出しと種別ラベルが出力に含まれること。
				$this->assertStringContainsString( $reservation_date_label, $output, $case['test_condition_name'] );
				$this->assertStringContainsString( $case['expect_contains'], $output, $case['test_condition_name'] );
			} else {
				// 予約可能日項目が表示されない場合、見出しラベルが出力に含まれないこと。
				$this->assertStringNotContainsString( $reservation_date_label, $output, $case['test_condition_name'] );
			}

			// 後始末（次のケースに影響しないようメタを削除する）。
			delete_post_meta( $menu_id, '_vkbm_reservation_day_type' );
		}
	}
}
