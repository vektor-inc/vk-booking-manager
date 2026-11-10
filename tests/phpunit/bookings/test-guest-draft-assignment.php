<?php
/**
 * 確認メール送信前と予約確定時で共有する担当・貸し切り判定のテスト。
 *
 * @package VKBookingManager
 */

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 下書きの担当条件と貸し切り設定の共通判定を検証する。
 */
class Guest_Draft_Assignment_Test extends WP_UnitTestCase {
	/**
	 * 指名の有無・担当未定・候補不一致の各条件を確定時と同じ条件で解決する。
	 */
	public function test_resolve_draft_staff_assignment(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '指名は Pro 版限定のため、無料版ではスキップする。' );
		}

		$repository = new Settings_Repository();
		$original   = get_option( Settings_Repository::OPTION_KEY );
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				$repository->get_settings(),
				array(
					'industry_preset' => 'custom',
					'staff_enabled'   => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$controller = new Booking_Confirmation_Controller( new Booking_Notification_Service( $repository ), $repository );
		$method     = new ReflectionMethod( $controller, 'resolve_draft_staff_assignment' );
		$method->setAccessible( true );
		// 確定時に使う担当IDと指名フラグを期待値として並べる。
		$cases = array(
			array(
				'test_condition_name' => '指名フラグなしでも担当IDあり => 担当IDを維持し指名として扱う（多層防御）',
				'preferred'           => false,
				'id'                  => 10,
				'slot'                => array(),
				'expected'            => array( 10, true ),
			),
			array(
				'test_condition_name' => '指名ありで担当なし => 拒否',
				'preferred'           => true,
				'id'                  => 0,
				'slot'                => array(),
				'expected'            => 'staff_assignment_failed',
			),
			array(
				'test_condition_name' => '枠の担当あり => 指名担当',
				'preferred'           => true,
				'id'                  => 0,
				'slot'                => array( 'staff' => array( 'id' => 10 ) ),
				'expected'            => array( 10, true ),
			),
			array(
				'test_condition_name' => '自動割当 => 担当未定',
				'preferred'           => true,
				'id'                  => 0,
				'slot'                => array(
					'assignable_staff_ids' => array( 10 ),
					'auto_assign'          => true,
				),
				'expected'            => array( 0, false ),
			),
			array(
				'test_condition_name' => '候補外の担当 => 拒否',
				'preferred'           => true,
				'id'                  => 20,
				'slot'                => array( 'assignable_staff_ids' => array( 10 ) ),
				'expected'            => 'staff_unavailable',
			),
		);
		try {
			foreach ( $cases as $case ) {
				$test_condition_name = $case['test_condition_name'];
				$result              = $method->invoke( $controller, array( 'is_staff_preferred' => $case['preferred'] ), $case['slot'], $case['id'] );
				if ( is_string( $case['expected'] ) ) {
					$this->assertWPError( $result, $test_condition_name );
					$this->assertSame( $case['expected'], $result->get_error_code(), $test_condition_name );
				} else {
					$this->assertNotWPError( $result, $test_condition_name );
					$this->assertSame( $case['expected'], array( $result['staff_id'], $result['is_staff_preferred'] ), $test_condition_name );
				}
			}
		} finally {
			update_option( Settings_Repository::OPTION_KEY, $original );
			Staff_Editor::clear_nomination_enabled_cache();
		}
	}

	/**
	 * 貸し切りの最低人数判定はメールアドレス・電話番号に依存しない。
	 */
	public function test_resolve_user_exclusive(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '定員と貸し切りは Pro 版限定のため、無料版ではスキップする。' );
		}

		$repository = new Settings_Repository();
		$original   = get_option( Settings_Repository::OPTION_KEY );
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				$repository->get_settings(),
				array(
					'industry_preset'       => 'custom',
					'staff_enabled'         => false,
					'slot_capacity_enabled' => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
		$menu_id = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		foreach ( array(
			'_vkbm_max_capacity'              => 3,
			'_vkbm_min_capacity'              => 2,
			'_vkbm_allow_multiple_guests'     => true,
			'_vkbm_exclusive_user_selectable' => true,
		) as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}
		$controller = new Booking_Confirmation_Controller( new Booking_Notification_Service( $repository ), $repository );
		$method     = new ReflectionMethod( $controller, 'resolve_user_exclusive' );
		$method->setAccessible( true );
		try {
			foreach ( array( 'first@example.com', 'second@example.com' ) as $email ) {
				foreach ( array(
					array(
						'test_condition_name' => '最低2名の貸し切りに1名 => 人数不足で拒否',
						'guests'              => 1,
						'expected'            => 'exclusive_min_capacity',
					),
					array(
						'test_condition_name' => '最低2名の貸し切りに2名 => 貸し切りを許可',
						'guests'              => 2,
						'expected'            => true,
					),
				) as $case ) {
					$test_condition_name = $case['test_condition_name'];
					$expected            = $case['expected'];
					$guests              = $case['guests'];
					$result              = $method->invoke(
						$controller,
						array(
							'user_exclusive' => true,
							'guest'          => array(
								'email' => $email,
								'phone' => $email,
							),
						),
						$menu_id,
						$guests
					);
					if ( true === $expected ) {
						$this->assertNotWPError( $result, $test_condition_name );
						$this->assertTrue( $result['user_exclusive'], $test_condition_name );
					} else {
						$this->assertWPError( $result, $test_condition_name );
						$this->assertSame( $expected, $result->get_error_code(), $test_condition_name );
					}
				}
			}
		} finally {
			update_option( Settings_Repository::OPTION_KEY, $original );
			Staff_Editor::clear_nomination_enabled_cache();
		}
	}
}
