<?php
/**
 * GET /vkbm/v1/menu-loop の staff パラメーター（#429）のテスト。
 *
 * issue #429「スタッフを選択しただけでも絞り込まれるようにしたい」で、
 * REST エンドポイント /vkbm/v1/menu-loop に追加した任意の整数パラメーター `staff` の
 * スキーマ定義（type/sanitize/validate）と、Menu_Loop_Block::render_menu_selection_list() への
 * 受け渡しを検証する。
 *
 * フィルタ判定基準そのもの（対応スタッフ・指名無効・スタッフ機能オフ等の組み合わせ）は
 * tests/phpunit/rendering/test-menu-loop-selection-list-staff-filter.php で検証済みのため、
 * ここでは REST 層の配線（args 定義・パラメーターの受け渡し）に絞って確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Menu_Preview_Controller;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * /vkbm/v1/menu-loop の staff パラメーターを検証するテストクラス。
 *
 * @group rest
 * @group staff
 */
class Menu_Preview_Controller_Menu_Loop_Staff_Filter_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 各テスト前に全体設定を退避する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * 全体設定・静的キャッシュをテスト前の状態へ戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		// #429: 匿名訪問者テストで wp_set_current_user( 0 ) を使うため、明示的に戻す。
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * サイト全体の指名機能スイッチ（staff_enabled）を更新する。
	 *
	 * @param bool $enabled 指名機能を有効にする場合は true。
	 */
	private function set_site_wide_nomination( bool $enabled ): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * /vkbm/v1/menu-loop の `staff` 引数が、type=integer・既定値0・
	 * sanitize_callback=absint・validate_callback 指定ありで登録されていることを確認する。
	 *
	 * このスキーマ定義が無いと、REST クライアントから文字列が渡された場合に
	 * 型が保証されず、実装が (int) キャストに暗黙で依存してしまう。
	 */
	public function test_menu_loop_route_registers_staff_arg_schema(): void {
		// プラグイン起動時に登録済みの REST サーバーからルート定義を取得する
		// （Menu_Preview_Controller::register() が rest_api_init に register_routes() を
		// フックしており、rest_get_server() の初回呼び出しで rest_api_init が発火する）。
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/vkbm/v1/menu-loop', $routes, '/vkbm/v1/menu-loop ルートが登録されていること。' );

		$route_handlers = $routes['/vkbm/v1/menu-loop'];
		$this->assertNotEmpty( $route_handlers );

		$args = $route_handlers[0]['args'] ?? array();

		$this->assertArrayHasKey( 'staff', $args, 'staff 引数が定義されていること。' );
		$this->assertSame( 'integer', $args['staff']['type'], 'staff 引数の type が integer であること。' );
		$this->assertSame( 0, $args['staff']['default'], 'staff 引数の既定値が 0（絞り込みなし）であること。' );
		$this->assertSame( 'absint', $args['staff']['sanitize_callback'], 'staff 引数の sanitize_callback が absint であること。' );
		$this->assertSame( 'rest_validate_request_arg', $args['staff']['validate_callback'], 'staff 引数の validate_callback が定義されていること。' );
		$this->assertFalse( $args['staff']['required'], 'staff 引数は必須ではない（未指定時は絞り込みなしにフォールバックする）こと。' );
	}

	/**
	 * dispatch() を通した実際のリクエストで、`staff` パラメーターの型検証が働くことを確認する。
	 *
	 * type=integer かつ minimum=0 のスキーマにより、負の値や数値化できない文字列は
	 * rest_invalid_param として拒否されることを固定する（境界値・異常系）。
	 */
	public function test_dispatch_rejects_invalid_staff_param(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'staff に数値化できない文字列を渡した場合 => rest_invalid_param で拒否される',
				'staff_param'         => 'not-a-number',
			),
			array(
				'test_condition_name' => 'staff に負の値を渡した場合 => minimum(0) 制約により rest_invalid_param で拒否される',
				'staff_param'         => '-5',
			),
		);

		foreach ( $test_cases as $case ) {
			$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
			$request->set_param( 'staff', $case['staff_param'] );

			$response = rest_get_server()->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 400, $response->get_status(), $case['test_condition_name'] );
			$this->assertSame( 'rest_invalid_param', $data['code'] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * dispatch() を通した実際のリクエストで、`staff` 未指定時は絞り込み無し（既定値0）として
	 * 全メニューを含む一覧HTMLが返り、`staff` 指定時は対応スタッフを含まないメニューが
	 * 除外されたHTMLが返ることを確認する（REST層からブロック描画までの結合テスト）。
	 */
	public function test_dispatch_passes_staff_param_to_render_menu_selection_list(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Free版ではスタッフ絞り込み自体が働かないため、REST経由の絞り込み確認は成立しない。' );
		}

		$this->set_site_wide_nomination( true );

		$target_staff_id = 9201;
		$other_staff_id  = 9202;

		$menu_assigned_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'メニューF（対応スタッフに含む）',
			)
		);
		update_post_meta( $menu_assigned_id, '_vkbm_staff_ids', array( $target_staff_id ) );

		$menu_other_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'メニューG（対応スタッフが別）',
			)
		);
		update_post_meta( $menu_other_id, '_vkbm_staff_ids', array( $other_staff_id ) );

		// staff 未指定 => 絞り込み無し（両方のメニューが含まれる）。
		$request_without_staff = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$html_without_staff    = rest_get_server()->dispatch( $request_without_staff )->get_data()['html'];

		$this->assertStringContainsString( 'メニューF（対応スタッフに含む）', $html_without_staff, 'staff 未指定時は全メニューが含まれること。' );
		$this->assertStringContainsString( 'メニューG（対応スタッフが別）', $html_without_staff, 'staff 未指定時は全メニューが含まれること。' );

		// staff=対象スタッフ => 対応スタッフに含まれないメニューが除外される。
		$request_with_staff = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request_with_staff->set_param( 'staff', $target_staff_id );
		$html_with_staff = rest_get_server()->dispatch( $request_with_staff )->get_data()['html'];

		$this->assertStringContainsString( 'メニューF（対応スタッフに含む）', $html_with_staff, 'staff 指定時は対応スタッフを含むメニューが表示されること。' );
		$this->assertStringNotContainsString( 'メニューG（対応スタッフが別）', $html_with_staff, 'staff 指定時は対応スタッフを含まないメニューが除外されること。' );
	}

	/**
	 * Menu_Preview_Controller::get_menu_loop() を直接呼び出す場合（schema のサニタイズを経由しない
	 * 呼び出し方。本リポジトリの REST コントローラーテストの慣例）でも、
	 * 負の staff 値が Menu_Loop_Block::is_menu_visible_for_staff_filter() の
	 * 「$staff_id <= 0 は絞り込みなし」判定によって安全側（全メニュー表示）に扱われることを確認する
	 * （境界値。REST スキーマの minimum 制約で弾かれる正規の入力経路とは別に、
	 * このメソッドを直接呼び出す経路でも安全であることを担保する）。
	 */
	public function test_get_menu_loop_treats_negative_staff_as_no_filter_when_called_directly(): void {
		$controller = new Menu_Preview_Controller( new Menu_Loop_Block() );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'メニューH（直接呼び出し確認用）',
			)
		);

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'staff', -5 );

		$response = $controller->get_menu_loop( $request );
		$html     = $response->get_data()['html'];

		$this->assertStringContainsString( get_the_title( $menu_id ), $html, '負の staff 値は絞り込み無し（0扱い）として扱われること。' );
	}

	/**
	 * #429 安藤レビュー指摘（LOW）: 未ログイン（current_user_can( vkbm_view_service_menus ) が
	 * false）の状態で staff パラメーターを付けて呼んでも、非公開（private）メニューが
	 * 結果に含まれないことを確認する。
	 *
	 * Menu_Loop_Block::get_menu_post_statuses() は権限が無い訪問者には publish のみを
	 * 対象にする既存のアクセス制御で、is_menu_visible_for_staff_filter() の絞り込みは
	 * この WP_Query の post_status 制限を経た後の投稿にのみ適用される。staff 絞り込みの
	 * 追加によってこの既存の非公開メニュー保護が回避されないことを固定する。
	 *
	 * #429 安藤レビュー指摘（LOW・再レビュー）: 元のテストは get_the_title() の戻り値で
	 * assertStringNotContainsString() していたため、応答 $html が何らかの理由で
	 * 空になっていた場合（一覧自体が空）でも「非公開メニューが含まれない」という
	 * 期待通りの結果になり、検出したい不具合（非公開メニューの混入）以外の原因で
	 * テストが通ってしまう恐れがあった。そのため、
	 * 1) 投稿作成時に指定したタイトル文字列そのもの（get_the_title() を介さない）で比較し、
	 * 2) 同じ対象スタッフに対応した「公開」メニューを併せて作成し、そのタイトルが
	 *    応答に含まれることも確認する（一覧自体が空ではないことの裏付け）よう修正した。
	 */
	public function test_dispatch_excludes_private_menu_for_anonymous_visitor_even_with_staff_param(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Free版ではスタッフ絞り込み自体が働かないため、staff指定時の非公開メニュー確認は成立しない。' );
		}

		$this->set_site_wide_nomination( true );

		// 明示的に未ログイン（匿名訪問者）へ固定する。
		wp_set_current_user( 0 );

		$target_staff_id = 9301;

		// 投稿作成時に指定したタイトル文字列そのもの。get_the_title() は非公開投稿に
		// "Private: " 接頭辞を付与するなど、比較対象を書き換えてしまう場合があるため、
		// ここではその変換を経由しない生のタイトル文字列で比較する。
		$private_menu_title = 'メニューI（非公開・対応スタッフに含む）';
		$public_menu_title  = 'メニューJ（公開・対応スタッフが同じ。一覧が空でないことの確認用）';

		// 対象スタッフに対応した非公開（private）メニュー。staff 絞り込みの基準だけ見れば
		// 表示対象になり得るが、非公開のため匿名訪問者には返してはならない。
		$private_menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => $private_menu_title,
			)
		);
		update_post_meta( $private_menu_id, '_vkbm_staff_ids', array( $target_staff_id ) );

		// 同じ対象スタッフに対応した公開メニュー。これが応答に含まれることを確認することで、
		// 非公開メニューが応答に無いのは「一覧自体が空（何らかの理由で全滅）」だからではなく、
		// 非公開メニューだけが正しく除外された結果であることを担保する。
		$public_menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $public_menu_title,
			)
		);
		update_post_meta( $public_menu_id, '_vkbm_staff_ids', array( $target_staff_id ) );

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/menu-loop' );
		$request->set_param( 'staff', $target_staff_id );

		$html = rest_get_server()->dispatch( $request )->get_data()['html'];

		$this->assertStringContainsString(
			$public_menu_title,
			$html,
			'同じスタッフに対応する公開メニューは応答に含まれること（一覧自体が空でないことの確認）。'
		);
		$this->assertStringNotContainsString(
			$private_menu_title,
			$html,
			'非公開メニューは、staff 指定時でも匿名訪問者には返してはならない。'
		);
	}
}
