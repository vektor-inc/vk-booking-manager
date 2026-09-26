<?php
/**
 * 基本料金のグレーアウト表示のテスト（#514）。
 *
 * 料金区分の行が表示中（hidden でない）かつ、区分名（trim後）が空でない行が1件以上
 * 登録されているときは、基本料金の入力欄を readonly にしてグレーアウト表示し、
 * 「料金区分が登録されているため基本料金は使われない」旨の説明文を表示する。
 * 条件を満たさないときは通常表示・説明文非表示になることを確認する。
 *
 * 料金区分はPro版限定機能のため、このテストはPro版でのみ実行する
 * （無料版ビルドでは Pro_Upsell::is_free_edition() が true になりスキップする）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 基本料金のグレーアウト表示（readonly・説明文）を保証するテスト群（#514）。
 *
 * @group admin
 */
class Service_Menu_Editor_Base_Price_Overridden_Test extends WP_UnitTestCase {

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
	 * テスト前に全体設定を退避し、Pro版・予約枠の定員機能ONへ揃える。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 料金区分はPro版限定機能のため、無料版ビルドではこのテストの検証対象自体が成立しない。
		// 無料版で実行された場合はスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分はPro版限定機能のため、無料版ではスキップする。' );
		}

		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

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
	 * 料金区分の登録状態・表示条件の組み合わせごとに、基本料金欄の readonly・
	 * グレーアウトクラス・説明文の表示が期待どおり切り替わることを確認する。
	 */
	public function test_render_base_price_field(): void {
		$test_cases = array(
			array(
				'test_condition_name'   => '複数人一括予約ON・定員2以上・区分名ありの区分が1件 => readonly でグレーアウトし説明文を表示する（正常系）',
				'allow_multiple_guests' => '1',
				'max_capacity'          => '2',
				'price_tiers'           => array(
					array(
						'label' => '大人',
						'price' => 5000,
					),
				),
				'expect_overridden'     => true,
			),
			array(
				'test_condition_name'   => '複数人一括予約ON・定員2以上・区分が未登録 => readonly にせず説明文も出さない（正常系）',
				'allow_multiple_guests' => '1',
				'max_capacity'          => '2',
				'price_tiers'           => array(),
				'expect_overridden'     => false,
			),
			array(
				'test_condition_name'   => '複数人一括予約OFF（料金区分の行自体がhidden）で区分名ありの区分が残っていても readonly にしない（境界値）',
				'allow_multiple_guests' => '',
				'max_capacity'          => '',
				'price_tiers'           => array(
					array(
						'label' => '大人',
						'price' => 5000,
					),
				),
				'expect_overridden'     => false,
			),
			array(
				'test_condition_name'   => '複数人一括予約ON・定員2以上・区分名が空白だけの行しか無い => Price_Tiers::normalize_tiers() で除外され readonly にしない（境界値・#514レビュー対応）',
				'allow_multiple_guests' => '1',
				'max_capacity'          => '2',
				'price_tiers'           => array(
					array(
						'label' => '   ',
						'price' => 5000,
					),
				),
				'expect_overridden'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
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
			if ( ! empty( $case['price_tiers'] ) ) {
				update_post_meta( $this->post_id, '_vkbm_price_tiers', $case['price_tiers'] );
			} else {
				delete_post_meta( $this->post_id, '_vkbm_price_tiers' );
			}

			$post   = get_post( $this->post_id );
			$editor = new Service_Menu_Editor();

			ob_start();
			$editor->render_conditions_meta_box( $post );
			$output = (string) ob_get_clean();

			// 基本料金の input タグ1つ分を切り出す（id 属性から直後の `/>` まで）。
			$pos = strpos( $output, 'id="vkbm_service_menu_base_price"' );
			$this->assertNotFalse( $pos, $case['test_condition_name'] . ': 基本料金の入力欄が出力に含まれていない' );
			$end = strpos( $output, '/>', $pos );
			$this->assertNotFalse( $end, $case['test_condition_name'] . ': 基本料金の入力欄のタグが閉じられていない' );
			$input_tag = substr( $output, $pos, $end - $pos );

			// 説明文（<p id="vkbm-base-price-overridden-description">...）1つ分を切り出す。
			$desc_pos = strpos( $output, 'id="vkbm-base-price-overridden-description"' );
			$this->assertNotFalse( $desc_pos, $case['test_condition_name'] . ': 基本料金の説明文が出力に含まれていない' );
			$desc_tag_start = strrpos( substr( $output, 0, $desc_pos ), '<p' );
			$desc_tag_end   = strpos( $output, '>', $desc_pos );
			$desc_tag       = substr( $output, $desc_tag_start, $desc_tag_end - $desc_tag_start );

			// クラス名の判定は末尾に `"` を含めて探す。`vkbm-base-price-overridden` は
			// 説明文の id・aria-describedby（`vkbm-base-price-overridden-description`）にも
			// 部分一致してしまうため、class 属性の終端（直後が `"`）まで含めて区別する。
			$overridden_class_needle = 'vkbm-base-price-overridden"';

			if ( $case['expect_overridden'] ) {
				$this->assertStringContainsString( 'readonly', $input_tag, $case['test_condition_name'] . ': readonly になっていない' );
				$this->assertStringContainsString( $overridden_class_needle, $input_tag, $case['test_condition_name'] . ': グレーアウトクラスが付いていない' );
				// #514レビュー対応（安藤 LOW）: aria-describedby はグレーアウト中だけ出力する。
				$this->assertStringContainsString( 'aria-describedby="vkbm-base-price-overridden-description"', $input_tag, $case['test_condition_name'] . ': aria-describedby が付いていない' );
				$this->assertStringNotContainsString( 'hidden', $desc_tag, $case['test_condition_name'] . ': 説明文が非表示のままになっている' );
			} else {
				$this->assertStringNotContainsString( 'readonly', $input_tag, $case['test_condition_name'] . ': readonly になっている' );
				$this->assertStringNotContainsString( $overridden_class_needle, $input_tag, $case['test_condition_name'] . ': グレーアウトクラスが付いている' );
				// #514レビュー対応（安藤 LOW）: グレーアウトしていないときは aria-describedby 自体を出力しない
				// （非表示の説明文を支援技術に関連付けないため）。
				$this->assertStringNotContainsString( 'aria-describedby', $input_tag, $case['test_condition_name'] . ': aria-describedby が付いている' );
				$this->assertStringContainsString( 'hidden', $desc_tag, $case['test_condition_name'] . ': 説明文が表示されてしまっている' );
			}
		}
	}
}
