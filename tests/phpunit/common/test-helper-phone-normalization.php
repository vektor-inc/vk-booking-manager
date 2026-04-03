<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\VKBM_Helper;
use WP_UnitTestCase;

/**
 * @group common
 */
class Helper_Phone_Normalization_Test extends WP_UnitTestCase {
	public function test_normalize_phone_number(): void {
		$test_cases = [
			[
				'test_condition_name' => '半角数字のみの場合 => そのまま返る',
				'input'               => '09012345678',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '半角数字ハイフン付きの場合 => ハイフンが除去される',
				'input'               => '090-1234-5678',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '全角数字のみの場合 => 半角数字に変換される',
				'input'               => '０９０１２３４５６７８',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '全角数字ハイフン付き（全角ハイフン）の場合 => 半角数字のみに変換される',
				'input'               => '０９０−１２３４−５６７８',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '全角数字ハイフン付き（半角ハイフン）の場合 => 半角数字のみに変換される',
				'input'               => '０９０-１２３４-５６７８',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '全角括弧付きの場合 => 半角数字のみに変換される',
				'input'               => '（０９０）１２３４−５６７８',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '半角括弧付きの場合 => 数字のみに変換される',
				'input'               => '(090)1234-5678',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '前後にスペースがある場合 => トリムされて数字のみになる',
				'input'               => ' 090 1234 5678 ',
				'expected'            => '09012345678',
			],
			[
				'test_condition_name' => '短い数字のみの場合 => そのまま返る',
				'input'               => '012345',
				'expected'            => '012345',
			],
			[
				'test_condition_name' => '空文字の場合 => 空文字が返る',
				'input'               => '',
				'expected'            => '',
			],
			[
				'test_condition_name' => 'スペースのみの場合 => 空文字が返る',
				'input'               => '   ',
				'expected'            => '',
			],
		];

		foreach ( $test_cases as $case ) {
			$actual = VKBM_Helper::normalize_phone_number( $case['input'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
