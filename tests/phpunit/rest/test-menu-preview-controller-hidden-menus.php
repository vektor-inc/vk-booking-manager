<?php
/**
 * GET /vkbm/v1/menu-preview/{id} が、一覧で隠しているメニューを返さないことのテスト（#1017）。
 *
 * アーカイブ済み・提供期間終了・パスワード保護のメニューは、ID を直接指定されても
 * 下書き・非公開と同じ 404 にする。公開中・提供開始前・「詳細ページを使用する」が OFF のメニューは
 * 今までどおりカードを返す。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * /vkbm/v1/menu-preview/{id} の公開状態の扱いを検証するテストクラス。
 *
 * @group rest
 */
class Menu_Preview_Controller_Hidden_Menus_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に未ログイン状態へ戻す。
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * テスト用のサービスメニューを作る。
	 *
	 * @param array<string,mixed>  $post_args 投稿の追加引数。
	 * @param array<string,string> $meta      追加するメタ。
	 * @return int 投稿 ID。
	 */
	private function create_menu( array $post_args = array(), array $meta = array() ): int {
		$menu_id = (int) $this->factory()->post->create(
			array_merge(
				array(
					'post_type'    => Service_Menu_Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'プレビュー検証メニュー',
					'post_excerpt' => 'プレビュー検証の説明',
				),
				$post_args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		return $menu_id;
	}

	/**
	 * 未ログインで menu-preview を呼ぶ。
	 *
	 * @param int $menu_id メニューの投稿 ID。
	 * @return \WP_REST_Response レスポンス。
	 */
	private function dispatch_preview( int $menu_id ) {
		wp_set_current_user( 0 );

		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/vkbm/v1/menu-preview/' . $menu_id ) );
	}

	/**
	 * メニューの状態ごとに、menu-preview の HTTP ステータスが決まることを確認する。
	 */
	public function test_preview_status_by_menu_state(): void {
		$yesterday = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$tomorrow  = gmdate( 'Y-m-d', strtotime( '+2 days' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中のメニュー（対照） => 200',
				'post_args'           => array(),
				'meta'                => array(),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => 'アーカイブ済み => 404',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_is_archived' => '1' ),
				'expected_status'     => 404,
			),
			array(
				'test_condition_name' => 'アーカイブフラグが 0 => 200',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_is_archived' => '0' ),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '提供期間が終了済み（終了日が過去） => 404',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_service_period_end' => $yesterday ),
				'expected_status'     => 404,
			),
			array(
				'test_condition_name' => '提供期間の終了日が未来（提供中） => 200',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_service_period_end' => $tomorrow ),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '提供開始前（開始日が未来）=> 先行予約を維持するため 200',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_service_period_start' => $tomorrow ),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => 'パスワード保護 => 404（Cookie の解除状態は見ない）',
				'post_args'           => array( 'post_password' => 'secret123' ),
				'meta'                => array(),
				'expected_status'     => 404,
			),
			array(
				'test_condition_name' => '「詳細ページを使用する」が OFF のメニュー => 通常のカードとして 200',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '0' ),
				'expected_status'     => 200,
			),
			array(
				'test_condition_name' => '下書き => 404（従来どおり）',
				'post_args'           => array( 'post_status' => 'draft' ),
				'meta'                => array(),
				'expected_status'     => 404,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id  = $this->create_menu( $case['post_args'], $case['meta'] );
			$response = $this->dispatch_preview( $menu_id );

			$this->assertSame( $case['expected_status'], $response->get_status(), $case['test_condition_name'] );
		}
	}

	/**
	 * 隠すメニューの 404 に、タイトル・抜粋・価格が含まれないことを確認する。
	 */
	public function test_hidden_menu_response_does_not_contain_menu_data(): void {
		$menu_id = $this->create_menu(
			array( 'post_title' => '漏れてはいけないタイトル' ),
			array(
				'_vkbm_is_archived' => '1',
				'_vkbm_base_price'  => '5000',
			)
		);

		$response = $this->dispatch_preview( $menu_id );
		$data     = $response->get_data();
		$encoded  = (string) wp_json_encode( $data );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'vkbm_menu_not_found', $data['code'] ?? null );
		$this->assertStringNotContainsString( '漏れてはいけないタイトル', $encoded );
		$this->assertStringNotContainsString( '5,000', $encoded );
	}

	/**
	 * 公開中のメニューでは、タイトル・抜粋・価格が今までどおり返ることを確認する。
	 */
	public function test_public_menu_response_keeps_card_content(): void {
		$menu_id = $this->create_menu( array(), array( '_vkbm_base_price' => '5000' ) );

		$response = $this->dispatch_preview( $menu_id );
		$html     = (string) ( $response->get_data()['html'] ?? '' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'プレビュー検証メニュー', $html );
		$this->assertStringContainsString( 'プレビュー検証の説明', $html );
		$this->assertStringContainsString( '5,000', $html );
	}
}
