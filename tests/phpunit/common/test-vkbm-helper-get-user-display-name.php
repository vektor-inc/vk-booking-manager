<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\VKBM_Helper;
use WP_UnitTestCase;
use function get_user_by;
use function update_user_meta;
use function wp_update_user;

/**
 * @group common
 */
class VKBM_Helper_Get_User_Display_Name_Test extends WP_UnitTestCase {
	public function test_get_user_display_name(): void {
		$test_cases = [
			[
				'test_condition_name' => '姓・名がある場合 => 姓 名 を返す',
				'login'               => 'prefers_last_first_name',
				'first_name'          => 'Taro',
				'last_name'           => 'Yamada',
				'kana_name'           => 'Kana Name',
				'display'             => 'Display User',
				'expected'            => 'Yamada Taro',
			],
			[
				'test_condition_name' => '姓・名がなくふりがなある場合 => ふりがな を返す',
				'login'               => 'uses_kana_when_names_missing',
				'first_name'          => '',
				'last_name'           => '',
				'kana_name'           => 'Kana Name',
				'display'             => 'Display User',
				'expected'            => 'Kana Name',
			],
			[
				'test_condition_name' => '姓のみある場合 => 姓 を返す',
				'login'               => 'last_name_only',
				'first_name'          => '',
				'last_name'           => 'Yamada',
				'kana_name'           => 'Kana Name',
				'display'             => 'Display User',
				'expected'            => 'Yamada',
			],
			[
				'test_condition_name' => '姓・名・ふりがながすべてない場合 => login を返す',
				'login'               => 'falls_back_to_login',
				'first_name'          => '',
				'last_name'           => '',
				'kana_name'           => '',
				'display'             => 'Display User',
				'expected'            => 'falls_back_to_login',
			],
		];

		foreach ( $test_cases as $case ) {
			$user_id = $this->factory()->user->create(
				[
					'user_login' => $case['login'],
					'user_email' => $case['login'] . '@example.com',
				]
			);

			update_user_meta( $user_id, 'first_name', $case['first_name'] );
			update_user_meta( $user_id, 'last_name', $case['last_name'] );
			update_user_meta( $user_id, 'vkbm_kana_name', $case['kana_name'] );
			wp_update_user(
				[
					'ID'           => $user_id,
					'display_name' => $case['display'],
				]
			);

			$user = get_user_by( 'id', $user_id );

			$this->assertSame( $case['expected'], VKBM_Helper::get_user_display_name( $user ), $case['test_condition_name'] );
		}
	}
}
