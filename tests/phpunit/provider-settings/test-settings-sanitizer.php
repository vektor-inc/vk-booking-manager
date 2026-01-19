<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use WP_UnitTestCase;

/**
 * @group provider-settings
 */
class Settings_Sanitizer_Test extends WP_UnitTestCase {
	public function test_sanitize_normalizes_values(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		$raw = [
			'provider_name'           => '  Foo <script>alert(1)</script> ',
			'provider_address'        => " 東京都新宿区1-2-3 サンプルビル<script>\n別館",
			'provider_phone'          => '03-1234-5678<script>',
			'provider_business_hours' => "10:00-19:00\n<script>alert(1)</script>",
			'provider_website_url'    => 'https://example.com/<script>',
			'provider_email'          => 'info+alias@example.com ',
			'provider_logo_id'        => '42foo',
			'provider_regular_holidays' => [
				[
					'frequency' => 'nth-2',
					'weekday'   => 'tue',
				],
				[
					'frequency' => 'invalid',
					'weekday'   => 'fri',
				],
				[
					'frequency' => 'weekly',
					'weekday'   => 'sun',
				],
			],
			'provider_business_hours_basic' => [
				[
					'start_hour'   => '09',
					'start_minute' => '00',
					'end_hour'     => '12',
					'end_minute'   => '00',
				],
				[
					'start_hour'   => '13',
					'start_minute' => '00',
					'end_hour'     => '18',
					'end_minute'   => '00',
				],
			],
		'provider_business_hours_weekly' => [
			'mon' => [
				'use_custom' => '',
			],
			'sun' => [
				'use_custom' => '1',
				'time_slots' => [
						[
							'start_hour'   => '11',
							'start_minute' => '00',
							'end_hour'     => '16',
							'end_minute'   => '00',
						],
					],
				],
			'wed' => [
				'use_custom' => '1',
				'time_slots' => [
						[
							'start_hour'   => '10',
							'start_minute' => '00',
							'end_hour'     => '12',
							'end_minute'   => '30',
						],
						[
							'start_hour'   => '14',
							'start_minute' => '00',
							'end_hour'     => '19',
							'end_minute'   => '00',
						],
					],
				],
			],
		];

		$result = $sanitizer->sanitize( $raw, $defaults );

		$this->assertSame( sanitize_text_field( $raw['provider_name'] ), $result['provider_name'] );
		$this->assertSame(
			sanitize_textarea_field( $raw['provider_address'] ),
			$result['provider_address']
		);
		$this->assertSame( sanitize_text_field( $raw['provider_phone'] ), $result['provider_phone'] );
		$this->assertSame(
			sanitize_textarea_field( $raw['provider_business_hours'] ),
			$result['provider_business_hours']
		);
		$this->assertSame( esc_url_raw( $raw['provider_website_url'] ), $result['provider_website_url'] );
		$this->assertSame( sanitize_email( $raw['provider_email'] ), $result['provider_email'] );
		$this->assertSame( 42, $result['provider_logo_id'] );
		$this->assertSame(
			[
				[
					'frequency' => 'nth-2',
					'weekday'   => 'tue',
				],
				[
					'frequency' => 'weekly',
					'weekday'   => 'sun',
				],
			],
			$result['provider_regular_holidays']
		);
		$this->assertSame(
			[
				[
					'start' => '09:00',
					'end'   => '12:00',
				],
				[
					'start' => '13:00',
					'end'   => '18:00',
				],
			],
			$result['provider_business_hours_basic']
		);
		$this->assertSame(
			[
				'use_custom' => false,
				'time_slots' => [],
			],
			$result['provider_business_hours_weekly']['mon']
		);
		$this->assertSame(
			[
				'use_custom' => false,
				'time_slots' => [],
			],
			$result['provider_business_hours_weekly']['sun']
		);
		$this->assertSame(
			[
				'use_custom' => true,
				'time_slots' => [
					[
						'start' => '10:00',
						'end'   => '12:30',
					],
					[
						'start' => '14:00',
						'end'   => '19:00',
					],
				],
			],
			$result['provider_business_hours_weekly']['wed']
		);
		$this->assertSame( [], $sanitizer->get_errors() );
	}

	public function test_sanitize_handles_missing_values_with_defaults(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		$test_cases = [
			[
				'test_condition_name' => 'empty_payload_uses_expected_fallbacks',
				'conditions'          => [
					'options' => [],
				],
				'expected'            => [
					'provider_email'                          => (string) get_option( 'admin_email' ),
					'reservation_show_menu_list'              => false,
					'registration_email_verification_enabled' => false,
					'membership_redirect_wp_register'         => false,
					'auth_rate_limit_enabled'                 => false,
					'design_primary_color'                    => $defaults['design_primary_color'],
					'design_reservation_button_color'         => $defaults['design_reservation_button_color'],
					'design_radius_md'                        => $defaults['design_radius_md'],
				],
			],
		];

		foreach ( $test_cases as $case ) {
			$result = $sanitizer->sanitize( [], $defaults );

			foreach ( $case['expected'] as $key => $value ) {
				$this->assertSame( $value, $result[ $key ], $case['test_condition_name'] );
			}
		}
	}

	public function test_sanitize_discards_invalid_email(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		$result = $sanitizer->sanitize(
			[
				'provider_email' => 'invalid-email',
			],
			$defaults
		);

		$this->assertSame( (string) get_option( 'admin_email' ), $result['provider_email'] );
	}

	public function test_sanitize_records_errors_for_invalid_business_hours(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		$result = $sanitizer->sanitize(
			[
				'provider_business_hours_weekly' => [
					'mon' => [
						'use_custom' => '1',
						'time_slots' => [
							[
								'start_hour'   => '09',
								'start_minute' => '15', // invalid minute.
								'end_hour'     => '08',
								'end_minute'   => '50',
							],
						],
					],
				],
			],
			$defaults
		);

		$errors = $sanitizer->get_errors();

		$this->assertNotEmpty( $errors );
		$this->assertTrue( $result['provider_business_hours_weekly']['mon']['use_custom'] );
		$this->assertSame( [], $result['provider_business_hours_weekly']['mon']['time_slots'] );
	}
}
