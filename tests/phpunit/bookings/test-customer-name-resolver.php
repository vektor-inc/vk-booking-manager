<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Customer_Name_Resolver;
use WP_UnitTestCase;
use function get_user_by;
use function update_user_meta;
use function wp_update_user;

/**
 * @group bookings
 */
class Customer_Name_Resolver_Test extends WP_UnitTestCase {
	public function test_resolve_for_user_cases(): void {
		$test_cases = [
			[
				'name'       => 'prefers_last_first_name',
				'first_name' => 'Taro',
				'last_name'  => 'Yamada',
				'kana_name'  => 'Kana Name',
				'display'    => 'Display User',
				'expected'   => 'Yamada Taro',
			],
			[
				'name'       => 'uses_kana_when_names_missing',
				'first_name' => '',
				'last_name'  => '',
				'kana_name'  => 'Kana Name',
				'display'    => 'Display User',
				'expected'   => 'Kana Name',
			],
			[
				'name'       => 'last_name_only',
				'first_name' => '',
				'last_name'  => 'Yamada',
				'kana_name'  => 'Kana Name',
				'display'    => 'Display User',
				'expected'   => 'Yamada',
			],
			[
				'name'       => 'falls_back_to_display_name',
				'first_name' => '',
				'last_name'  => '',
				'kana_name'  => '',
				'display'    => 'Display User',
				'expected'   => 'Display User',
			],
		];

		$resolver = new Customer_Name_Resolver();

		foreach ( $test_cases as $case ) {
			$user_id = $this->factory()->user->create(
				[
					'user_login' => $case['name'],
					'user_email' => $case['name'] . '@example.com',
				]
			);

			update_user_meta( $user_id, 'first_name', $case['first_name'] );
			update_user_meta( $user_id, 'last_name', $case['last_name'] );
			update_user_meta( $user_id, 'vkbm_kana_name', $case['kana_name'] );
			wp_update_user(
				[
					'ID' => $user_id,
					'display_name' => $case['display'],
				]
			);

			$user = get_user_by( 'id', $user_id );

			$this->assertSame( $case['expected'], $resolver->resolve_for_user( $user ), $case['name'] );
		}
	}
}
