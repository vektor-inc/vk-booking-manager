<?php
/**
 * ユーザー貸し切り指定（#305）の貸し切り料金計算 Exclusive_Fee::calculate() のテスト。
 *
 * 確定仕様の計算式・境界（申込人数 < / = / > 適用外人数、適用外人数 空/0 で常に加算、per_person×人数）を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Exclusive_Fee;
use WP_UnitTestCase;

/**
 * 貸し切り料金計算のテスト。
 *
 * @group common
 * @group exclusive
 */
class Exclusive_Fee_Test extends WP_UnitTestCase {
	/**
	 * Exclusive_Fee::calculate() の計算式と境界を検証する。
	 */
	public function test_calculate(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価1000・適用外人数なし(0)・3名 => 3000（常に加算・人数分）',
				'user_selected'       => true,
				'per_person'          => 1000,
				'exempt_guests'       => 0,
				'guests'              => 3,
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価1000・適用外人数4・申込3名（< 適用外）=> 3000（加算）',
				'user_selected'       => true,
				'per_person'          => 1000,
				'exempt_guests'       => 4,
				'guests'              => 3,
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価1000・適用外人数4・申込4名（= 適用外＝以上）=> 0（加算しない）',
				'user_selected'       => true,
				'per_person'          => 1000,
				'exempt_guests'       => 4,
				'guests'              => 4,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価1000・適用外人数4・申込5名（> 適用外）=> 0（加算しない）',
				'user_selected'       => true,
				'per_person'          => 1000,
				'exempt_guests'       => 4,
				'guests'              => 5,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'ユーザー貸切OFF・単価1000・3名 => 0（選択していないので加算しない）',
				'user_selected'       => false,
				'per_person'          => 1000,
				'exempt_guests'       => 0,
				'guests'              => 3,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価0・3名 => 0（単価0は加算しない）',
				'user_selected'       => true,
				'per_person'          => 0,
				'exempt_guests'       => 0,
				'guests'              => 3,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価1500・適用外人数なし・1名 => 1500（境界: 最少人数1名）',
				'user_selected'       => true,
				'per_person'          => 1500,
				'exempt_guests'       => 0,
				'guests'              => 1,
				'expected'            => 1500,
			),
			array(
				'test_condition_name' => 'ユーザー貸切ON・単価1000・適用外人数2・申込1名（< 適用外）=> 1000（加算）',
				'user_selected'       => true,
				'per_person'          => 1000,
				'exempt_guests'       => 2,
				'guests'              => 1,
				'expected'            => 1000,
			),
			array(
				'test_condition_name' => '異常系: 申込0名 => 0（人数1未満は加算しない）',
				'user_selected'       => true,
				'per_person'          => 1000,
				'exempt_guests'       => 0,
				'guests'              => 0,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Exclusive_Fee::calculate(
				$case['user_selected'],
				$case['per_person'],
				$case['exempt_guests'],
				$case['guests']
			);

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 適用外人数が「空欄／未設定」のときの仕様（常に加算）を検証する。
	 *
	 * メニューメタ _vkbm_exclusive_fee_exempt_guests が空欄・未設定の場合、
	 * 確定／下書きコントローラは get_post_meta() の戻り値（'' や false）を (int) で 0 に正規化してから
	 * Exclusive_Fee::calculate() に渡す（= 上限なし＝常に加算）。
	 * Exclusive_Fee::calculate() の引数は int 型のため、コントローラと同じ正規化（(int) キャスト）を
	 * テスト側でも再現し、空欄入力が「常に加算」に倒れる仕様をリテラルなケースで担保する。
	 */
	public function test_calculate_with_empty_exempt(): void {
		$test_cases = array(
			array(
				// get_post_meta() が空文字を返す未設定メタを想定（'' を (int) で 0 に正規化）。
				'test_condition_name' => '適用外人数が空欄（""→0）・単価1000・大人数100名でも常に加算 => 100000',
				'raw_exempt'          => '',
				'per_person'          => 1000,
				'guests'              => 100,
				'expected'            => 100000,
			),
			array(
				// get_post_meta() が false を返す完全未設定メタを想定（false を (int) で 0 に正規化）。
				'test_condition_name' => '適用外人数が未設定（false→0）・単価500・2名 => 1000（常に加算）',
				'raw_exempt'          => false,
				'per_person'          => 500,
				'guests'              => 2,
				'expected'            => 1000,
			),
			array(
				// 念のため "0" 文字列の空相当値も 0 正規化で常に加算になることを確認。
				'test_condition_name' => '適用外人数が "0"（→0）・単価1000・1名 => 1000（常に加算）',
				'raw_exempt'          => '0',
				'per_person'          => 1000,
				'guests'              => 1,
				'expected'            => 1000,
			),
		);

		foreach ( $test_cases as $case ) {
			// コントローラと同じく get_post_meta() 戻り値を max(0, (int) ...) で正規化してから渡す。
			$exempt = max( 0, (int) $case['raw_exempt'] );
			$actual = Exclusive_Fee::calculate( true, $case['per_person'], $exempt, $case['guests'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
