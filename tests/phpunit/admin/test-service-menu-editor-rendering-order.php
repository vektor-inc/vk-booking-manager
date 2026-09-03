<?php
/**
 * render_booking_window_field の描画順序テスト。
 *
 * 「予約締切」「予約可能な最大日数」の input が
 * 内部メモの textarea より前に描画されることを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Service_Menu_Editor;
use WP_UnitTestCase;

/**
 * Service_Menu_Editor の描画順序を保証するテスト群。
 *
 * @group admin
 */
class Service_Menu_Editor_Rendering_Order_Test extends WP_UnitTestCase {

	/**
	 * テスト用投稿ID。
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * テスト前にサービスメニュー投稿を用意する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// 管理者としてログインした状態を作る.
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// テスト用のサービスメニュー投稿を作成する.
		$this->post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * テスト後にユーザー状態をリセットする。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * render_vkbm_meta_box の出力で「予約締切・最大日数 input」が「内部メモ textarea」より前に描画されることを確認する。
	 */
	public function test_render_vkbm_meta_box(): void {
		$post   = get_post( $this->post_id );
		$editor = new Service_Menu_Editor();

		$test_cases = array(
			array(
				'test_condition_name' => '予約締切 input が内部メモ textarea より前に出力される',
				'target_id'           => 'vkbm_service_menu_reservation_deadline',
				'memo_needle'         => 'name="vkbm_service_menu[internal_memo]"',
			),
			array(
				'test_condition_name' => '予約可能な最大日数 input が内部メモ textarea より前に出力される',
				'target_id'           => 'vkbm_service_menu_max_advance_booking_days',
				'memo_needle'         => 'name="vkbm_service_menu[internal_memo]"',
			),
		);

		// メタボックス全体の出力を取得する.
		ob_start();
		$editor->render_vkbm_meta_box( $post );
		$output = (string) ob_get_clean();

		foreach ( $test_cases as $case ) {
			// 対象フィールドと内部メモそれぞれの出現位置を取得する.
			$pos_target = strpos( $output, 'id="' . $case['target_id'] . '"' );
			$pos_memo   = strpos( $output, $case['memo_needle'] );

			// 両方が出力に含まれることを確認する.
			$this->assertNotFalse( $pos_target, $case['test_condition_name'] . ': 対象フィールドが出力に含まれていない' );
			$this->assertNotFalse( $pos_memo, $case['test_condition_name'] . ': 内部メモが出力に含まれていない' );

			// 対象フィールドが内部メモより前に出現することを確認する.
			$this->assertLessThan( $pos_memo, $pos_target, $case['test_condition_name'] );
		}
	}
}
