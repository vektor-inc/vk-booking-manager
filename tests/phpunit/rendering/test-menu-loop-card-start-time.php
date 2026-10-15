<?php
/**
 * メニューループ（カード）の開始時間表示の分岐テスト。
 *
 * 固定の開始時間（_vkbm_fixed_start_times）が設定されているサービスでは
 * カードに「開始時間」項目と時部分の先頭の 0 を外した各時刻を ` / ` 区切りで表示し、
 * 未設定（空配列・空文字のみ）のサービスでは開始時間項目を出力しないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
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
	 * テスト前の BM 設定（tear_down で復元する）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 各テスト前にブロックインスタンスを用意する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		$this->block = new Menu_Loop_Block();
	}

	/**
	 * 設定と業種プリセットのキャッシュを元に戻す（assert が途中で失敗しても必ず実行される）。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		parent::tear_down();
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
				'test_condition_name' => '0付きの時と2桁の時が設定されている場合 => 時の先頭の0だけを外して "&nbsp;/ " 区切りで表示される',
				'start_times'         => array( '09:00', '13:00' ),
				'expect_label'        => true,
				'expect_contains'     => array( '<dd>9:00&nbsp;/ 13:00</dd>' ),
			),
			array(
				'test_condition_name' => '時と分が0付きの場合 => 時の先頭の0だけを外して分の0は維持される',
				'start_times'         => array( '09:05', '00:30' ),
				'expect_label'        => true,
				'expect_contains'     => array( '<dd>9:05&nbsp;/ 0:30</dd>' ),
			),
			array(
				'test_condition_name' => 'HH:MM形式ではない値の場合 => 変換せずエスケープして表示される',
				'start_times'         => array( '9:05', 'invalid<&' ),
				'expect_label'        => true,
				'expect_contains'     => array( '9:05&nbsp;/ invalid&lt;&amp;' ),
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

	/**
	 * 宿泊が有効なサイトで、予約の単位ごとに開始時間項目の表示が切り替わることを確認する（#602）。
	 *
	 * 泊のプランには編集画面で隠れた固定開始時刻が残っていても、開始時間項目を出さない。
	 */
	public function test_render_menu_card_for_lodging_site(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$start_time_label = __( 'Start time', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊が有効で泊のプランに固定開始時刻が残っている場合 => 開始時間項目は出力されない',
				'industry_preset'     => 'lodging',
				'booking_unit'        => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
				'expect_label'        => false,
			),
			array(
				'test_condition_name' => '宿泊が有効で時間枠（予約の単位が未設定）のプランに固定開始時刻がある場合 => 開始時間項目が出力される',
				'industry_preset'     => 'lodging',
				'booking_unit'        => '',
				'expect_label'        => true,
			),
			array(
				'test_condition_name' => '宿泊が無効なサイトで泊の値が残っている場合 => 時間枠として扱い開始時間項目が出力される',
				'industry_preset'     => 'custom',
				'booking_unit'        => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
				'expect_label'        => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// 業種プリセットを切り替える。
			Industry_Presets::clear_cache();
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge(
					( new Settings_Repository() )->get_default_settings(),
					array( 'industry_preset' => $case['industry_preset'] )
				)
			);

			// 固定開始時刻を持つメニューを作り、予約の単位を設定する。
			$menu_id = $this->create_menu_with_start_times( array( '09:00', '13:00' ) );
			if ( '' !== $case['booking_unit'] ) {
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_BOOKING_UNIT, $case['booking_unit'] );
			}

			$output = $this->block->render_menu_card( $menu_id );

			if ( $case['expect_label'] ) {
				$this->assertStringContainsString( $start_time_label, $output, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( $start_time_label, $output, $case['test_condition_name'] );
			}
		}
	}
}
