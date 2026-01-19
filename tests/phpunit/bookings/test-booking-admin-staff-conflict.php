<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;
use function get_user_by;
use function get_post;
use function get_post_meta;
use function update_post_meta;
use function update_option;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * @group bookings
 */
class Booking_Admin_Staff_Conflict_Test extends WP_UnitTestCase {
	private const META_DATE_START  = '_vkbm_booking_service_start';
	private const META_DATE_END    = '_vkbm_booking_service_end';
	private const META_TOTAL_END   = '_vkbm_booking_total_end';
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';
	private const META_STATUS      = '_vkbm_booking_status';

	protected function setUp(): void {
		parent::setUp();
		$this->set_current_user_with_caps();
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_has_staff_conflict_cases(): void {
		$test_cases = [
			[
				'name'               => 'blocks_conflicting_staff_change',
				'existing_start'     => '2024-01-01 10:00:00',
				'existing_end'       => '2024-01-01 11:00:00',
				'target_start'       => '2024-01-01 10:15:00',
				'target_end'         => '2024-01-01 11:00:00',
				'expected'           => true,
			],
			[
				'name'               => 'allows_non_conflicting_staff_change',
				'existing_start'     => '2024-01-02 10:00:00',
				'existing_end'       => '2024-01-02 11:00:00',
				'target_start'       => '2024-01-02 12:00:00',
				'target_end'         => '2024-01-02 12:30:00',
				'expected'           => false,
			],
		];

		$admin = new Booking_Admin_Test_Double();

		foreach ( $test_cases as $case ) {
			$staff_a = $this->create_staff( 'Staff A ' . $case['name'] );

			$this->create_booking(
				$staff_a,
				$case['existing_start'],
				$case['existing_end'],
				'confirmed'
			);

			$booking_id = $this->create_booking(
				$this->create_staff( 'Staff B ' . $case['name'] ),
				$case['target_start'],
				$case['target_end'],
				'confirmed'
			);

			$actual = $admin->has_staff_conflict_public(
				$booking_id,
				$staff_a,
				$case['target_start'],
				$case['target_end']
			);

			$this->assertSame( $case['expected'], $actual, $case['name'] );
		}
	}

	public function test_save_post_staff_conflict_setting_cases(): void {
		$test_cases = [
			[
				'name'               => 'disallow_conflict_on_admin_save',
				'allow_overlap'      => false,
				'existing_start'     => '2024-01-03 10:00:00',
				'existing_end'       => '2024-01-03 11:00:00',
				'booking_start'      => '2024-01-03 09:00:00',
				'booking_end'        => '2024-01-03 09:30:00',
				'requested_date'     => '2024-01-03',
				'requested_start'    => '10:15',
				'requested_end'      => '11:00',
				'expected_staff_key' => 'staff_b',
				'expected_start'     => '2024-01-03 09:00:00',
			],
			[
				'name'               => 'allow_conflict_on_admin_save',
				'allow_overlap'      => true,
				'existing_start'     => '2024-01-04 10:00:00',
				'existing_end'       => '2024-01-04 11:00:00',
				'booking_start'      => '2024-01-04 09:00:00',
				'booking_end'        => '2024-01-04 09:30:00',
				'requested_date'     => '2024-01-04',
				'requested_start'    => '10:15',
				'requested_end'      => '11:00',
				'expected_staff_key' => 'staff_a',
				'expected_start'     => '2024-01-04 10:15:00',
			],
		];

		foreach ( $test_cases as $case ) {
			$this->set_provider_settings(
				[
					'provider_allow_staff_overlap_admin' => $case['allow_overlap'],
				]
			);

			$staff_a = $this->create_staff( 'Staff A ' . $case['name'] );
			$staff_b = $this->create_staff( 'Staff B ' . $case['name'] );

			$this->create_booking(
				$staff_a,
				$case['existing_start'],
				$case['existing_end'],
				'confirmed'
			);

			$booking_id = $this->create_booking(
				$staff_b,
				$case['booking_start'],
				$case['booking_end'],
				'confirmed'
			);

			$this->set_booking_post_data(
				$case['requested_date'],
				$case['requested_start'],
				$case['requested_end'],
				$staff_a
			);

			$post  = get_post( $booking_id );
			$admin = new Booking_Admin();
			$admin->save_post( $booking_id, $post );

			$expected_staff_id = 'staff_a' === $case['expected_staff_key'] ? $staff_a : $staff_b;
			$this->assertSame( $expected_staff_id, (int) get_post_meta( $booking_id, self::META_RESOURCE_ID, true ), $case['name'] );
			$this->assertSame( $case['expected_start'], get_post_meta( $booking_id, self::META_DATE_START, true ), $case['name'] );
		}
	}

	private function set_current_user_with_caps(): void {
		$user_id = $this->factory()->user->create(
			[
				'role' => 'administrator',
			]
		);
		wp_set_current_user( $user_id );

		$user = get_user_by( 'id', $user_id );
		$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
	}

	private function create_staff( string $name ): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			]
		);
	}

	private function create_booking( int $staff_id, string $start_at, string $end_at, string $status ): int {
		$booking_id = (int) $this->factory()->post->create(
			[
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);

		update_post_meta( $booking_id, self::META_DATE_START, $start_at );
		update_post_meta( $booking_id, self::META_DATE_END, $end_at );
		update_post_meta( $booking_id, self::META_TOTAL_END, $end_at );
		update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );
		update_post_meta( $booking_id, self::META_STATUS, $status );

		return $booking_id;
	}

	private function set_booking_post_data( string $date, string $start_time, string $end_time, int $staff_id ): void {
		$_POST = [
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => [
				'date'        => $date,
				'start_time'  => $start_time,
				'end_time'    => $end_time,
				'resource_id' => $staff_id,
				'status'      => 'confirmed',
			],
		];
	}

	private function set_provider_settings( array $overrides ): void {
		$settings = ( new Settings_Repository() )->get_default_settings();
		$settings = array_merge( $settings, $overrides );
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}
}

class Booking_Admin_Test_Double extends Booking_Admin {
	public function has_staff_conflict_public( int $post_id, int $staff_id, string $start_at, string $end_at ): bool {
		return $this->has_staff_conflict( $post_id, $staff_id, $start_at, $end_at );
	}
}
