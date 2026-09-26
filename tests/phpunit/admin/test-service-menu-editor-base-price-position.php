<?php
/**
 * 基本料金の行の描画位置のテスト（#514）。
 *
 * 「基本料金（税込）」の行は、料金区分（price tiers）の行の直前に配置する。
 * 料金区分の行が表示されない構成（予約枠の定員機能OFFなど）でも、基本料金の行自体は
 * 必ず描画され、料金区分が入るはずの位置（その構成の末尾）に置かれることを確認する。
 *
 * 料金区分はPro版限定機能のため、このテストはPro版でのみ実行する
 * （無料版ビルドでは Pro_Upsell::is_free_edition() が true になりスキップする）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 基本料金の行の描画位置を保証するテスト群（#514）。
 *
 * @group admin
 */
class Service_Menu_Editor_Base_Price_Position_Test extends WP_UnitTestCase {

	/**
	 * テスト用投稿ID。
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * setUp で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避し、管理者ユーザー・テスト用投稿を用意する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 料金区分はPro版限定機能のため、無料版ビルドではこのテストの検証対象（料金区分の
		// 行の有無に応じた基本料金の配置）自体が成立しない。無料版で実行された場合はスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分はPro版限定機能のため、無料版ではスキップする。' );
		}

		// 管理者としてログインした状態を作る.
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// テスト用のサービスメニュー投稿を作成する.
		$this->post_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * テスト後に全体設定・静的キャッシュ・ユーザー状態を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 基本料金の行が常に描画され、料金区分の行が表示される構成では
	 * その直前（他の行を挟まない位置）に配置されることを確認する。
	 */
	public function test_render_conditions_meta_box(): void {
		$test_cases = array(
			array(
				'test_condition_name'      => '予約枠の定員機能ON・複数人一括予約ON・定員2以上 => 料金区分の行が表示され、その直前に基本料金の行が並ぶ',
				'slot_capacity_enabled'    => true,
				'allow_multiple_guests'    => '1',
				'max_capacity'             => '2',
				'expect_price_tiers_field' => true,
			),
			array(
				'test_condition_name'      => '予約枠の定員機能ON・複数人一括予約OFF（条件未達）=> 料金区分の行はhiddenで存在し、その直前に基本料金の行が並ぶ',
				'slot_capacity_enabled'    => true,
				'allow_multiple_guests'    => '',
				'max_capacity'             => '',
				'expect_price_tiers_field' => true,
			),
			array(
				'test_condition_name'      => '予約枠の定員機能OFF => 料金区分の行自体が描画されないが、基本料金の行は末尾側に必ず描画される',
				'slot_capacity_enabled'    => false,
				'allow_multiple_guests'    => '',
				'max_capacity'             => '',
				'expect_price_tiers_field' => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$repository                        = new Settings_Repository();
			$settings                          = $repository->get_settings();
			$settings['slot_capacity_enabled'] = $case['slot_capacity_enabled'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			if ( '' !== $case['allow_multiple_guests'] ) {
				update_post_meta( $this->post_id, '_vkbm_allow_multiple_guests', $case['allow_multiple_guests'] );
			} else {
				delete_post_meta( $this->post_id, '_vkbm_allow_multiple_guests' );
			}
			if ( '' !== $case['max_capacity'] ) {
				update_post_meta( $this->post_id, '_vkbm_max_capacity', $case['max_capacity'] );
			} else {
				delete_post_meta( $this->post_id, '_vkbm_max_capacity' );
			}

			$post   = get_post( $this->post_id );
			$editor = new Service_Menu_Editor();

			ob_start();
			$editor->render_conditions_meta_box( $post );
			$output = (string) ob_get_clean();

			// 基本料金の行は常に描画される.
			$pos_base_price = strpos( $output, 'id="vkbm-base-price-field"' );
			$this->assertNotFalse( $pos_base_price, $case['test_condition_name'] . ': 基本料金の行が出力に含まれていない' );

			$pos_price_tiers = strpos( $output, 'id="vkbm-price-tiers-field"' );

			if ( $case['expect_price_tiers_field'] ) {
				$this->assertNotFalse( $pos_price_tiers, $case['test_condition_name'] . ': 料金区分の行が出力に含まれていない' );
				$this->assertLessThan( $pos_price_tiers, $pos_base_price, $case['test_condition_name'] . ': 基本料金の行が料金区分の行より後ろにある' );

				// 基本料金の行の終わり（</tr>）から、料金区分の行を開く <tr> タグの開始位置までの
				// 間が空白のみ（＝他の行を挟まず直前に配置されている）ことを確認する.
				$pos_base_price_row_end = strpos( $output, '</tr>', $pos_base_price );
				$this->assertNotFalse( $pos_base_price_row_end, $case['test_condition_name'] . ': 基本料金の行が閉じられていない' );
				$pos_price_tiers_row_start = strrpos( substr( $output, 0, $pos_price_tiers ), '<tr' );
				$this->assertNotFalse( $pos_price_tiers_row_start, $case['test_condition_name'] . ': 料金区分の行の開始タグが見つからない' );
				$between = substr( $output, $pos_base_price_row_end + strlen( '</tr>' ), $pos_price_tiers_row_start - $pos_base_price_row_end - strlen( '</tr>' ) );
				$this->assertSame( '', trim( $between ), $case['test_condition_name'] . ': 基本料金の行と料金区分の行の間に他の行が挟まっている' );
			} else {
				$this->assertFalse( $pos_price_tiers, $case['test_condition_name'] . ': 料金区分の行が描画されないはずなのに出力に含まれている' );
			}
		}
	}

	/**
	 * どの構成（無料版／予約枠の定員機能OFF／ON）でも、基本料金の入力欄
	 * （name="vkbm_service_menu[base_price]"）がちょうど1つだけ出力されることを確認する
	 * （#514レビュー対応・安藤 LOW。二重描画の回帰防止）。
	 *
	 * 定員機能OFF・ONの2構成は、基本料金の元の出力場所であった render_basic_meta_box() を
	 * 含む render_vkbm_meta_box()（メタボックス全体の描画エントリーポイント）の出力で数える。
	 * render_conditions_meta_box() 単体の出力だけを数えると、render_basic_meta_box() 側に
	 * 基本料金の行が戻って二重描画になっても検出できないため（#514再レビュー対応・安藤 LOW）。
	 *
	 * 「無料版」はこのリポジトリの通常のPHPUnit実行では作れない（プラグインヘッダの
	 * Plugin Name が常に "VK Booking Manager Pro" のため Pro_Upsell::is_free_edition() を
	 * テストから偽装できない）。無料版の render_conditions_meta_box() は
	 * render_base_price_field( $post, false ) を1回だけ呼ぶのと等価なため、その呼び出しを
	 * ReflectionMethod で直接検証することで代替する。
	 */
	public function test_base_price_input_appears_exactly_once(): void {
		$post   = get_post( $this->post_id );
		$editor = new Service_Menu_Editor();

		$name_needle = 'name="vkbm_service_menu[base_price]"';

		// 1) 無料版相当：render_base_price_field() を直接1回呼び出す.
		$reflection = new ReflectionMethod( Service_Menu_Editor::class, 'render_base_price_field' );
		ob_start();
		$reflection->invoke( $editor, $post, false );
		$output_free_equivalent = (string) ob_get_clean();
		$this->assertSame(
			1,
			substr_count( $output_free_equivalent, $name_needle ),
			'無料版相当（render_base_price_field 単独呼び出し）: 基本料金の入力欄がちょうど1つ出力されていない'
		);

		// 2) 予約枠の定員機能OFF・3) ON の2構成は render_vkbm_meta_box() 経由で確認する
		// （render_basic_meta_box() と render_conditions_meta_box() の両方を含む描画エントリーポイント）.
		$test_cases = array(
			array(
				'test_condition_name'   => '予約枠の定員機能OFF => 基本料金の入力欄がちょうど1つ出力される',
				'slot_capacity_enabled' => false,
			),
			array(
				'test_condition_name'   => '予約枠の定員機能ON => 基本料金の入力欄がちょうど1つ出力される',
				'slot_capacity_enabled' => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$repository                        = new Settings_Repository();
			$settings                          = $repository->get_settings();
			$settings['slot_capacity_enabled'] = $case['slot_capacity_enabled'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			ob_start();
			$editor->render_vkbm_meta_box( $post );
			$output = (string) ob_get_clean();

			$this->assertSame(
				1,
				substr_count( $output, $name_needle ),
				$case['test_condition_name']
			);
		}
	}
}
