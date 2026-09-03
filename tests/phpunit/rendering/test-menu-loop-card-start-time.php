<?php
/**
 * メニューループ（カード）の開始時間表示の分岐テスト。
 *
 * 固定の開始時間（_vkbm_fixed_start_times）が設定されているサービスでは
 * カードに「開始時間」項目と各時刻を ` / ` 区切りで表示し、
 * 未設定（空配列・空文字のみ）のサービスでは開始時間項目を出力しないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * カードレイアウトの開始時間表示を検証するテスト。
 */
class Menu_Loop_Card_Start_Time_Test extends WP_UnitTestCase {
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
	 * 開始時間メタを設定したサービスメニューを生成するヘルパー。
	 *
	 * @param mixed $start_times 保存する開始時間メタ（配列・空配列・非配列を許容）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_start_times( $start_times ): int {
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
		if ( null !== $start_times ) {
			update_post_meta( $menu_id, '_vkbm_fixed_start_times', $start_times );
		}

		return (int) $menu_id;
	}

	/**
	 * 開始時間メタの内容に応じて、開始時間項目の表示・非表示が切り替わることを確認する。
	 */
	public function test_render_menu_card(): void {
		// 「開始時間」ラベル。実装側と同じ翻訳関数で組み立て、ロケールが ja などに
		// 切り替わっても期待値が実装の出力文言と一致するようにする。
		$start_time_label = __( 'Start time', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => '開始時間が2件設定されている場合 => ラベルと各時刻が "&nbsp;/ " 区切りで表示される',
				'start_times'         => array( '09:00', '13:00' ),
				'expect_label'        => true,
				'expect_contains'     => array( '09:00&nbsp;/ 13:00' ),
			),
			array(
				'test_condition_name' => '開始時間が1件設定されている場合 => ラベルと単一の時刻が表示される',
				'start_times'         => array( '10:30' ),
				'expect_label'        => true,
				'expect_contains'     => array( '10:30' ),
			),
			array(
				'test_condition_name' => '空文字や空白のみの要素が混在する場合 => それらは除外され有効な時刻のみ表示される',
				'start_times'         => array( '', '11:00', '   ', '15:45' ),
				'expect_label'        => true,
				'expect_contains'     => array( '11:00&nbsp;/ 15:45' ),
			),
			array(
				'test_condition_name' => '開始時間メタが空配列の場合 => 開始時間項目は出力されない',
				'start_times'         => array(),
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
			array(
				'test_condition_name' => '開始時間メタが空文字や空白のみの要素だけの場合 => 開始時間項目は出力されない',
				'start_times'         => array( '', '   ' ),
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
			array(
				'test_condition_name' => '開始時間メタが未設定の場合 => 開始時間項目は出力されない',
				'start_times'         => null,
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
			array(
				'test_condition_name' => '開始時間メタに非配列値（文字列）が保存されている場合 => is_array ガードで弾かれ開始時間項目は出力されない',
				'start_times'         => 'invalid',
				'expect_label'        => false,
				'expect_contains'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			// 条件に従ってサービスメニューを作成する。
			$menu_id = $this->create_menu_with_start_times( $case['start_times'] );

			// 公開境界（render_menu_card）経由でカードHTMLを生成する。
			$output = $this->block->render_menu_card( $menu_id );

			if ( $case['expect_label'] ) {
				// 開始時間項目が表示される場合、ラベルと各時刻が出力に含まれること。
				$this->assertStringContainsString( $start_time_label, $output, $case['test_condition_name'] );
				foreach ( $case['expect_contains'] as $expected_value ) {
					$this->assertStringContainsString( $expected_value, $output, $case['test_condition_name'] );
				}
			} else {
				// 開始時間項目が表示されない場合、ラベルが出力に含まれないこと。
				$this->assertStringNotContainsString( $start_time_label, $output, $case['test_condition_name'] );
			}

			// 後始末（次のケースに影響しないようメタを削除する）。
			delete_post_meta( $menu_id, '_vkbm_fixed_start_times' );
		}
	}
}
