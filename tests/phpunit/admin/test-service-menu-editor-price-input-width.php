<?php
/**
 * サービスメニュー編集画面の金額入力欄の幅クラスのテスト。
 *
 * 基本料金は 14800 のような5桁以上になりやすく、WordPress コアの `.small-text`
 * （type="number" で 65px）だけでは数値が右端で見切れてしまう。
 * 金額欄用クラス `vkbm-price-input` が付与され続けることを保証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Service_Menu_Editor;
use WP_UnitTestCase;

/**
 * Service_Menu_Editor の金額入力欄に幅クラスが付くことを保証するテスト群。
 *
 * @group admin
 */
class Service_Menu_Editor_Price_Input_Width_Test extends WP_UnitTestCase {

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
	 * 金額の input には幅クラスが付き、金額以外の数値 input には付かないことを確認する。
	 */
	public function test_price_inputs_have_width_class(): void {
		$post   = get_post( $this->post_id );
		$editor = new Service_Menu_Editor();

		$test_cases = array(
			array(
				'test_condition_name' => '基本料金の input に vkbm-price-input が付く（金額なので幅を広げる）',
				'input_id'            => 'vkbm_service_menu_base_price',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '所要時間の input には vkbm-price-input が付かない（金額ではない）',
				'input_id'            => 'vkbm_service_menu_duration_minutes',
				'expected'            => false,
			),
		);

		// メタボックス全体の出力を取得する.
		ob_start();
		$editor->render_vkbm_meta_box( $post );
		$output = (string) ob_get_clean();

		foreach ( $test_cases as $case ) {
			// 対象の input タグ1つ分を切り出す（id 属性から直後の `>` まで）。
			$pos = strpos( $output, 'id="' . $case['input_id'] . '"' );
			$this->assertNotFalse( $pos, $case['test_condition_name'] . ': 対象フィールドが出力に含まれていない' );

			$end = strpos( $output, '>', $pos );
			$this->assertNotFalse( $end, $case['test_condition_name'] . ': 対象フィールドのタグが閉じられていない' );
			$tag = substr( $output, $pos, $end - $pos );

			// 幅クラスの有無を検証する.
			if ( $case['expected'] ) {
				$this->assertStringContainsString( 'vkbm-price-input', $tag, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( 'vkbm-price-input', $tag, $case['test_condition_name'] );
			}
		}
	}
}
