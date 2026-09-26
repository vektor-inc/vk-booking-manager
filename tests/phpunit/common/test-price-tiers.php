<?php
/**
 * 料金区分ユーティリティ（Price_Tiers）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function delete_option;
use function get_option;
use function update_option;
use function update_post_meta;

/**
 * 料金区分ユーティリティ（Price_Tiers）のテスト。
 *
 * @group common
 */
class Price_Tiers_Test extends WP_UnitTestCase {

	/**
	 * is_menu_using_price_tiers() 用テストで書き換えた予約枠の定員機能の設定を復元するため、
	 * setUp() 時点の設定を退避しておく。
	 *
	 * @var array<string, mixed>|null
	 */
	private $original_settings;

	/**
	 * 各テスト前に現在の予約枠の定員機能設定を退避する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * 各テスト後に予約枠の定員機能設定を元に戻し、キャッシュをクリアする。
	 */
	public function tear_down(): void {
		if ( null === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tear_down();
	}

	/**
	 * テスト用のサービスメニュー投稿を作成するヘルパー。
	 *
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス（#515）',
			)
		);
	}

	/**
	 * sanitize_tiers(): 生入力を保存用に正規化する。
	 */
	public function test_sanitize_tiers(): void {
		// 件数上限超過テスト用に MAX_TIERS + 5 件の入力を作る。
		$over_limit_input = array();
		for ( $i = 0; $i < Price_Tiers::MAX_TIERS + 5; $i++ ) {
			$over_limit_input[] = array(
				'label' => 'tier' . $i,
				'price' => 100,
			);
		}

		$test_cases = array(
			array(
				'test_condition_name' => '一般4000・子供3000の2区分 => そのまま正規化される（正常系）',
				'raw'                 => array(
					array(
						'label' => '一般',
						'price' => 4000,
					),
					array(
						'label' => '子供',
						'price' => 3000,
					),
				),
				'expected'            => array(
					array(
						'label' => '一般',
						'price' => 4000,
					),
					array(
						'label' => '子供',
						'price' => 3000,
					),
				),
			),
			array(
				'test_condition_name' => '料金が文字列「2500」とゼロ => 整数化され保持される（正常系）',
				'raw'                 => array(
					array(
						'label' => '大人',
						'price' => '2500',
					),
					array(
						'label' => '幼児',
						'price' => 0,
					),
				),
				'expected'            => array(
					array(
						'label' => '大人',
						'price' => 2500,
					),
					array(
						'label' => '幼児',
						'price' => 0,
					),
				),
			),
			array(
				'test_condition_name' => 'ラベル空行・負数料金 => 空行は除去され負数は0にクランプ（異常系）',
				'raw'                 => array(
					array(
						'label' => '',
						'price' => 1000,
					),
					array(
						'label' => '  ',
						'price' => 500,
					),
					array(
						'label' => '一般',
						'price' => -200,
					),
				),
				'expected'            => array(
					array(
						'label' => '一般',
						'price' => 0,
					),
				),
			),
			array(
				'test_condition_name' => '配列でない入力 => 空配列を返す（異常系）',
				'raw'                 => 'not-an-array',
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Price_Tiers::sanitize_tiers( $case['raw'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		// 件数上限のクランプは別アサーションで確認する（期待値配列が巨大になるのを避ける）。
		$clamped = Price_Tiers::sanitize_tiers( $over_limit_input );
		$this->assertCount( Price_Tiers::MAX_TIERS, $clamped, '件数上限超過 => MAX_TIERS 件にクランプされる（境界値）' );
	}

	/**
	 * resolve_guest_tiers(): サーバ保存メタを正としてクライアント人数を突き合わせる。
	 */
	public function test_resolve_guest_tiers(): void {
		$menu_tiers = array(
			array(
				'label' => '一般',
				'price' => 4000,
			),
			array(
				'label' => '子供',
				'price' => 3000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '一般3名・子供2名（index=>count）=> 保存メタの料金で内訳確定（正常系）',
				'menu_tiers'          => $menu_tiers,
				'requested'           => array(
					0 => 3,
					1 => 2,
				),
				'expected'            => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => 'クライアントが料金を改竄して送っても保存メタの料金が使われる（セキュリティ回帰）',
				'menu_tiers'          => $menu_tiers,
				'requested'           => array(
					array(
						'label' => '無料区分',
						'price' => 0,
						'count' => 3,
					),
					array(
						'label' => '無料区分',
						'price' => 0,
						'count' => 0,
					),
				),
				'expected'            => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 0,
					),
				),
			),
			array(
				'test_condition_name' => '人数に負数が来た区分 => 0 にクランプされる（異常系）',
				'menu_tiers'          => $menu_tiers,
				'requested'           => array(
					0 => -5,
					1 => 2,
				),
				'expected'            => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 0,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Price_Tiers::resolve_guest_tiers( $case['menu_tiers'], $case['requested'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * total_price(): 区分内訳から合計金額（Σ 料金 × 人数）を求める。
	 */
	public function test_total_price(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '一般4000×3 + 子供3000×2 => 18000（正常系）',
				'guest_tiers'         => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
				'expected'            => 18000,
			),
			array(
				'test_condition_name' => '一般4000×3 + 子供0名 => 12000（正常系・特定区分0名）',
				'guest_tiers'         => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 0,
					),
				),
				'expected'            => 12000,
			),
			array(
				'test_condition_name' => '全区分0名 => 0（境界値）',
				'guest_tiers'         => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 0,
					),
				),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Price_Tiers::total_price( $case['guest_tiers'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * total_count(): 区分内訳から合計人数を求める。
	 */
	public function test_total_count(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '一般3名 + 子供2名 => 5（正常系）',
				'guest_tiers'         => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
				'expected'            => 5,
			),
			array(
				'test_condition_name' => '一般3名 + 子供0名 => 3（正常系・特定区分0名）',
				'guest_tiers'         => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 0,
					),
				),
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '全区分0名 => 0（境界値）',
				'guest_tiers'         => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 0,
					),
				),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Price_Tiers::total_count( $case['guest_tiers'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * has_tiers(): 有効な区分が1件でもあるか判定する。
	 */
	public function test_has_tiers(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '区分が1件以上 => true（正常系）',
				'raw'                 => array(
					array(
						'label' => '一般',
						'price' => 4000,
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ラベル空のみ => false（異常系）',
				'raw'                 => array(
					array(
						'label' => '',
						'price' => 4000,
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空配列 => false（境界値）',
				'raw'                 => array(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Price_Tiers::has_tiers( $case['raw'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * normalize_guest_tiers(): 保存済みスナップショットの count を保持して正規化する（回帰防止）。
	 *
	 * normalize_tiers() は count を落とすため、人数を含むスナップショット（_vkbm_booking_guest_tiers）には
	 * 必ず normalize_guest_tiers() を使う。count が保持されることを明示的に検証する。
	 */
	public function test_normalize_guest_tiers(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '保存済み内訳（一般3名・子供2名）=> label/price/count を保持（正常系）',
				'raw'                 => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
				'expected'            => array(
					array(
						'label' => '一般',
						'price' => 4000,
						'count' => 3,
					),
					array(
						'label' => '子供',
						'price' => 3000,
						'count' => 2,
					),
				),
			),
			array(
				'test_condition_name' => 'count 欠落・負数 => 0 に補完／クランプ（異常系）',
				'raw'                 => array(
					array(
						'label' => '大人',
						'price' => 5000,
					),
					array(
						'label' => '幼児',
						'price' => 0,
						'count' => -3,
					),
				),
				'expected'            => array(
					array(
						'label' => '大人',
						'price' => 5000,
						'count' => 0,
					),
					array(
						'label' => '幼児',
						'price' => 0,
						'count' => 0,
					),
				),
			),
			array(
				'test_condition_name' => '配列でない入力 => 空配列（境界値）',
				'raw'                 => null,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Price_Tiers::normalize_guest_tiers( $case['raw'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		// 回帰の核心: normalize_tiers() は count を落とすが normalize_guest_tiers() は保持することを対比で確認する。
		$snapshot = array(
			array(
				'label' => '一般',
				'price' => 4000,
				'count' => 3,
			),
		);
		$this->assertArrayNotHasKey( 'count', Price_Tiers::normalize_tiers( $snapshot )[0], 'normalize_tiers は count を含まない' );
		$this->assertSame( 3, Price_Tiers::normalize_guest_tiers( $snapshot )[0]['count'], 'normalize_guest_tiers は count を保持する' );
		$this->assertSame( 12000, Price_Tiers::total_price( Price_Tiers::normalize_guest_tiers( $snapshot ) ), 'count 保持により合計が正しく再計算される' );
		$this->assertSame( 0, Price_Tiers::total_price( Price_Tiers::normalize_tiers( $snapshot ) ), 'normalize_tiers 経由は count=0 扱いで合計0（バグ再現）' );
	}

	/**
	 * is_menu_using_price_tiers(): 「料金区分で設定されているメニューか」を判定する（#515）。
	 *
	 * 公開側メニューカード（Menu_Loop_Block::render_meta_information()）と同じ4条件
	 * （Pro版・予約枠の定員機能ON・複数人一括予約ON・定員2以上・区分1件以上）のうち、
	 * Pro版・予約枠の定員機能ON以外の3条件の組み合わせを検証する。Pro/予約枠の定員機能の
	 * ゲート（Staff_Editor::is_multi_guest_available_for_menu()）自体は
	 * test_is_menu_using_price_tiers_returns_false_when_slot_capacity_feature_disabled() と
	 * test_is_menu_using_price_tiers_free_edition_equivalent() で別途検証する。
	 */
	public function test_is_menu_using_price_tiers(): void {
		$tiers = array(
			array(
				'label' => '一般',
				'price' => 4000,
			),
		);
		// ラベルにインラインタグ（<b> 等）を含む区分。update_post_meta() は register_post_meta() の
		// sanitize_callback（Price_Tiers::sanitize_tiers()）を経由するため、保存時点で
		// sanitize_text_field() -> wp_strip_all_tags() によりタグ自体は除去されるが、
		// <script>/<style> 以外のタグは中のテキストが残る（strip_tags() の挙動）。
		// 「一部にタグが混ざっていても、テキストが残る区分は有効な区分として扱われる」ことを確認する。
		$tiers_with_inline_tag_label = array(
			array(
				'label' => '<b>特別</b>',
				'price' => 4000,
			),
		);
		// <script>/<style> はタグに加えて要素の中身ごと除去される（wp_strip_all_tags() の仕様、
		// XSS対策）。ラベルが空になり sanitize_tiers() で行ごと除外されるため、この区分だけの
		// メニューは「有効な区分が無い」＝false になることを確認する（安全側に倒れることの確認）。
		$tiers_with_script_label = array(
			array(
				'label' => '<script>alert(1)</script>',
				'price' => 4000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name'   => '複数人一括予約ON・定員2・区分1件 => 予約枠の定員機能ON時のみ true（正常系）',
				'allow_multiple_guests' => true,
				'max_capacity'          => 2,
				'price_tiers'           => $tiers,
				'expected_when_pro'     => true,
			),
			array(
				'test_condition_name'   => 'ラベルにインラインタグを含む区分1件（保存時にタグ除去・テキストは残る）=> 予約枠の定員機能ON時のみ true（正常系）',
				'allow_multiple_guests' => true,
				'max_capacity'          => 2,
				'price_tiers'           => $tiers_with_inline_tag_label,
				'expected_when_pro'     => true,
			),
			array(
				'test_condition_name'   => 'ラベルが <script> タグのみ（保存時に中身ごと除去され空ラベルになる）=> 有効な区分が残らないため false（異常系・安全性の確認）',
				'allow_multiple_guests' => true,
				'max_capacity'          => 2,
				'price_tiers'           => $tiers_with_script_label,
				'expected_when_pro'     => false,
			),
			array(
				'test_condition_name'   => '区分なし => false（正常系・条件4未達）',
				'allow_multiple_guests' => true,
				'max_capacity'          => 2,
				'price_tiers'           => array(),
				'expected_when_pro'     => false,
			),
			array(
				'test_condition_name'   => '複数人一括予約OFF => false（異常系・条件2未達）',
				'allow_multiple_guests' => false,
				'max_capacity'          => 2,
				'price_tiers'           => $tiers,
				'expected_when_pro'     => false,
			),
			array(
				'test_condition_name'   => '定員1 => false（境界値・条件3未達）',
				'allow_multiple_guests' => true,
				'max_capacity'          => 1,
				'price_tiers'           => $tiers,
				'expected_when_pro'     => false,
			),
		);

		// 予約枠の定員機能はON（既定）にして、条件2〜4の組み合わせだけを検証する。
		$settings                          = (array) get_option( Settings_Repository::OPTION_KEY );
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		// 予約枠の定員機能ONの場合、Pro版なら「全条件を満たす」ケースのみ true。
		// 無料版（Staff_Editor::is_multi_guest_available_for_menu() が常に false）ではどのケースも false。
		$expect_true_when_all_conditions_met = ! Pro_Upsell::is_free_edition();

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', $case['allow_multiple_guests'] );
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			if ( ! empty( $case['price_tiers'] ) ) {
				update_post_meta( $menu_id, '_vkbm_price_tiers', $case['price_tiers'] );
			}

			$expected = $case['expected_when_pro'] ? $expect_true_when_all_conditions_met : false;

			$this->assertSame( $expected, Price_Tiers::is_menu_using_price_tiers( $menu_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * is_menu_using_price_tiers(): メニューIDが0以下（メニューという文脈が無い呼び出し）の場合、
	 * 他の条件を満たしていても false を返すことを確認する（境界値）。
	 */
	public function test_is_menu_using_price_tiers_returns_false_for_invalid_menu_id(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'menu_id が 0 => false（境界値）',
				'menu_id'             => 0,
			),
			array(
				'test_condition_name' => 'menu_id が負数 => false（異常系）',
				'menu_id'             => -1,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertFalse(
				Price_Tiers::is_menu_using_price_tiers( $case['menu_id'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * is_menu_using_price_tiers(): 予約枠の定員機能がOFFのときは、区分・複数人一括予約・定員の
	 * メタが残っていても false になることを確認する（条件1未達＝定員機能OFFで区分メタだけ残る）。
	 *
	 * 区分メタを削除せずに残したまま予約枠の定員機能だけをOFFにするのは、実運用で「一度ONにして
	 * 区分を設定した後に基本設定でOFFへ戻す」操作を想定しているため（#412 の「親スイッチOFF時も
	 * 子設定を残す」方針と同じ）。
	 */
	public function test_is_menu_using_price_tiers_returns_false_when_slot_capacity_feature_disabled(): void {
		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
				),
			)
		);

		$settings                          = (array) get_option( Settings_Repository::OPTION_KEY );
		$settings['slot_capacity_enabled'] = false;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$this->assertFalse(
			Price_Tiers::is_menu_using_price_tiers( $menu_id ),
			'予約枠の定員機能OFF => 区分・複数人一括予約・定員のメタが残っていても false（条件1未達）'
		);
	}

	/**
	 * is_menu_using_price_tiers(): 「Pro版であること」自体を満たさない（無料版相当）場合は、
	 * 他の3条件（複数人一括予約ON・定員2以上・区分1件以上）をすべて満たしていても false になることを
	 * 確認する（#515 の「Free 相当」ケース）。
	 *
	 * 無料版ビルドで実行した場合にこの意味を持つ。Pro版ビルドで実行した場合は
	 * Staff_Editor::is_multi_guest_available_for_menu() が true になるため、
	 * この境界（Pro版チェック単体の分岐）自体は Free 版ビルドでの実行時のみ検証される
	 * （Pro版ビルドでは true になることを test_is_menu_using_price_tiers() 側で確認している）。
	 */
	public function test_is_menu_using_price_tiers_free_edition_equivalent(): void {
		if ( ! Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'この境界（Pro版チェック単体）は無料版ビルドでのみ意味を持つため、Pro版ビルドではスキップする。' );
		}

		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
				),
			)
		);

		$settings                          = (array) get_option( Settings_Repository::OPTION_KEY );
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$this->assertFalse(
			Price_Tiers::is_menu_using_price_tiers( $menu_id ),
			'無料版相当（Pro版チェック不通過）は、他の条件をすべて満たしていても false'
		);
	}
}
