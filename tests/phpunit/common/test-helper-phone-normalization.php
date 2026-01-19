<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\VKBM_Helper;
use WP_UnitTestCase;

/**
 * @group common
 */
class Helper_Phone_Normalization_Test extends WP_UnitTestCase {
	public function test_normalize_phone_number_cases(): void {
		$test_cases = [
			[
				'name'     => 'removes_hyphens',
				'input'    => '090-1234-5678',
				'expected' => '09012345678',
			],
			[
				'name'     => 'converts_fullwidth_digits',
				'input'    => '０９０１２３４５６７８',
				'expected' => '09012345678',
			],
			[
				'name'     => 'removes_spaces_and_symbols',
				'input'    => ' 090 1234 5678 ',
				'expected' => '09012345678',
			],
			[
				'name'     => 'keeps_digits_only',
				'input'    => '012345',
				'expected' => '012345',
			],
		];

		foreach ( $test_cases as $case ) {
			$actual = VKBM_Helper::normalize_phone_number( $case['input'] );
			$this->assertSame( $case['expected'], $actual, $case['name'] );
		}
	}
}
