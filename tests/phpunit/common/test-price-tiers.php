<?php
/**
 * 料金区分ユーティリティ（Price_Tiers）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Price_Tiers;
use WP_UnitTestCase;

/**
 * 料金区分ユーティリティ（Price_Tiers）のテスト。
 *
 * @group common
 */
class Price_Tiers_Test extends WP_UnitTestCase {

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
}
