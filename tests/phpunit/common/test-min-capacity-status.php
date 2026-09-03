<?php
/**
 * 最小催行人数（グループ開催型）の催行状態ヘルパー（VKBM_Helper::get_min_capacity_status）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\VKBM_Helper;
use WP_UnitTestCase;

/**
 * VKBM_Helper::get_min_capacity_status のテストクラス。
 *
 * @group min-capacity
 */
class Min_Capacity_Status_Test extends WP_UnitTestCase {

	/**
	 * 最小催行人数と合計予約人数の組み合わせから、催行状態（state）・不足人数（shortfall）が
	 * 正しく算出されることを検証する。
	 *
	 * - state は 'none'（制約なし）/'pending'（未達）/'fulfilled'（達成）のいずれか。
	 * - shortfall は未達時のみ「あと何名で開催か」を返し、達成・制約なし時は 0。
	 */
	public function test_get_min_capacity_status(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '最小催行人数0（未設定）の場合 => 制約なし state=none（後方互換）',
				'min_capacity'        => 0,
				'booked_guests'       => 3,
				'expected'            => array(
					'state'         => 'none',
					'min_capacity'  => 0,
					'booked_guests' => 3,
					'shortfall'     => 0,
				),
			),
			array(
				'test_condition_name' => '最小3・予約1の場合 => 未達 state=pending shortfall=2（正常系）',
				'min_capacity'        => 3,
				'booked_guests'       => 1,
				'expected'            => array(
					'state'         => 'pending',
					'min_capacity'  => 3,
					'booked_guests' => 1,
					'shortfall'     => 2,
				),
			),
			array(
				'test_condition_name' => '最小3・予約3の場合 => 達成 state=fulfilled shortfall=0（境界値：ちょうど達成）',
				'min_capacity'        => 3,
				'booked_guests'       => 3,
				'expected'            => array(
					'state'         => 'fulfilled',
					'min_capacity'  => 3,
					'booked_guests' => 3,
					'shortfall'     => 0,
				),
			),
			array(
				'test_condition_name' => '最小3・予約5の場合 => 達成 state=fulfilled shortfall=0（正常系：超過）',
				'min_capacity'        => 3,
				'booked_guests'       => 5,
				'expected'            => array(
					'state'         => 'fulfilled',
					'min_capacity'  => 3,
					'booked_guests' => 5,
					'shortfall'     => 0,
				),
			),
			array(
				'test_condition_name' => '最小2・予約0の場合 => 未達 state=pending shortfall=2（境界値：予約なし）',
				'min_capacity'        => 2,
				'booked_guests'       => 0,
				'expected'            => array(
					'state'         => 'pending',
					'min_capacity'  => 2,
					'booked_guests' => 0,
					'shortfall'     => 2,
				),
			),
			array(
				'test_condition_name' => '負値入力（最小-1・予約-5）の場合 => 0へ丸めて制約なし state=none（異常系：防御的）',
				'min_capacity'        => -1,
				'booked_guests'       => -5,
				'expected'            => array(
					'state'         => 'none',
					'min_capacity'  => 0,
					'booked_guests' => 0,
					'shortfall'     => 0,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = VKBM_Helper::get_min_capacity_status(
				$case['min_capacity'],
				$case['booked_guests']
			);
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
