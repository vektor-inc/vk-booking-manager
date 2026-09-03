<?php
/**
 * メニューループ（カード）の最少催行人数表示の分岐テスト。
 *
 * 1枠あたりの最大予約受付数（_vkbm_max_capacity）が 2 以上、かつ
 * 最少催行人数（_vkbm_min_capacity）が 2 以上のサービスでは、
 * カードのメタ情報末尾に「最少催行人数」項目と人数（数量＋単位）を表示し、
 * いずれかの条件を満たさない場合は項目を出力しないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * カードレイアウトの最少催行人数表示を検証するテスト。
 */
class Menu_Loop_Card_Min_Capacity_Test extends WP_UnitTestCase {
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
	 * 最大受付数・最少催行人数のメタを設定したサービスメニューを生成するヘルパー。
	 *
	 * @param mixed $max_capacity 保存する最大予約受付数メタ（null の場合は未設定）。
	 * @param mixed $min_capacity 保存する最少催行人数メタ（null の場合は未設定）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_capacities( $max_capacity, $min_capacity ): int {
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
		if ( null !== $max_capacity ) {
			update_post_meta( $menu_id, '_vkbm_max_capacity', $max_capacity );
		}
		if ( null !== $min_capacity ) {
			update_post_meta( $menu_id, '_vkbm_min_capacity', $min_capacity );
		}

		return (int) $menu_id;
	}

	/**
	 * 最大受付数・最少催行人数の組み合わせに応じて、最少催行人数項目の表示・非表示が切り替わることを確認する。
	 */
	public function test_render_menu_card(): void {
		// 「最少催行人数」ラベル。実装側と同じ翻訳関数で組み立て、ロケールが ja などに
		// 切り替わっても期待値が実装の出力文言と一致するようにする。
		$min_capacity_label = __( 'Minimum participants to confirm', 'vk-booking-manager' );

		// 人数（数量＋単位）の期待文字列。実装と同じ整形ヘルパーで組み立てる。
		$two_guests_markup   = vkbm_format_guests_count( 2 );
		$three_guests_markup = vkbm_format_guests_count( 3 );

		$test_cases = array(
			array(
				'test_condition_name' => '最大受付数=2 かつ 最少催行人数=2 の場合 => ラベルと「2名」相当が表示される',
				'max_capacity'        => 2,
				'min_capacity'        => 2,
				'expect_label'        => true,
				'expect_contains'     => array( $two_guests_markup ),
			),
			array(
				'test_condition_name' => '最大受付数=5 かつ 最少催行人数=3 の場合 => ラベルと「3名」相当が表示される',
				'max_capacity'        => 5,
				'min_capacity'        => 3,
				'expect_label'        => true,
				'expect_contains'     => array( $three_guests_markup ),
			),
			array(
				'test_condition_name' => '最大受付数=1 かつ 最少催行人数=2 の場合 => 単独枠なので最少催行人数項目は出力されない',
				'max_capacity'        => 1,
				'min_capacity'        => 2,
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
			array(
				'test_condition_name' => '最大受付数=3 かつ 最少催行人数=1 の場合 => min が1なので最少催行人数項目は出力されない',
				'max_capacity'        => 3,
				'min_capacity'        => 1,
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
			array(
				'test_condition_name' => '最大受付数=3 かつ 最少催行人数=0（制約なし）の場合 => 最少催行人数項目は出力されない',
				'max_capacity'        => 3,
				'min_capacity'        => 0,
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
			array(
				'test_condition_name' => '最大受付数・最少催行人数ともに未設定の場合 => 最少催行人数項目は出力されない',
				'max_capacity'        => null,
				'min_capacity'        => null,
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件に従ってサービスメニューを作成する。
			$menu_id = $this->create_menu_with_capacities( $case['max_capacity'], $case['min_capacity'] );

			// 公開境界（render_menu_card）経由でカードHTMLを生成する。
			$output = $this->block->render_menu_card( $menu_id );

			// 描画自体が壊れて空文字を返した場合に否定アサーションが空振り（vacuous pass）しないよう、
			// まずカードが想定どおり描画されている（メニュータイトルを含む非空の出力である）ことを確認する。
			// これにより render_menu_card が '' を返す回帰が起きても、否定ケースが見逃さない。
			$this->assertStringContainsString( 'テストサービス', $output, $case['test_condition_name'] );

			if ( $case['expect_label'] ) {
				// 最少催行人数項目が表示される場合、ラベルと人数が出力に含まれること。
				$this->assertStringContainsString( $min_capacity_label, $output, $case['test_condition_name'] );
				foreach ( $case['expect_contains'] as $expected_value ) {
					$this->assertStringContainsString( $expected_value, $output, $case['test_condition_name'] );
				}
			} else {
				// 最少催行人数項目が表示されない場合、ラベルが出力に含まれないこと。
				$this->assertStringNotContainsString( $min_capacity_label, $output, $case['test_condition_name'] );
			}

			// 後始末（次のケースに影響しないようメタを削除する）。
			delete_post_meta( $menu_id, '_vkbm_max_capacity' );
			delete_post_meta( $menu_id, '_vkbm_min_capacity' );
		}
	}
}
