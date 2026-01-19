<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Assets;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * @group assets
 */
class Common_Styles_Test extends WP_UnitTestCase {
	public function test_get_custom_css_returns_expected_string(): void {
		$test_cases = [
			[
				'test_condition_name' => 'primary_color_only',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '#112233',
						'design_radius_md'     => '',
					],
				],
				'expected'            => ':root{--vkbm--color--primary: #112233;}',
			],
			[
				'test_condition_name' => 'radius_only',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '',
						'design_reservation_button_color' => '',
						'design_radius_md'     => 12,
					],
				],
				'expected'            => ':root{--vkbm--radius--md: 12px;}',
			],
			[
				'test_condition_name' => 'reservation_button_color_only',
				'conditions'          => [
					'options' => [
						'design_primary_color'           => '',
						'design_reservation_button_color' => '#445566',
						'design_radius_md'               => '',
					],
				],
				'expected'            => ':root{--vkbm--color--reservation-action: #445566;}',
			],
			[
				'test_condition_name' => 'empty_values',
				'conditions'          => [
					'options' => [
						'design_primary_color' => '',
						'design_reservation_button_color' => '',
						'design_radius_md'     => '',
					],
				],
				'expected'            => '',
			],
		];

		foreach ( $test_cases as $case ) {
			$options = $case['conditions']['options'] ?? [];
			update_option( Settings_Repository::OPTION_KEY, $options, false );

			$styles = new Common_Styles();
			$method = new \ReflectionMethod( $styles, 'get_custom_css' );
			$method->setAccessible( true );

			$result = $method->invoke( $styles );

			$this->assertSame( $case['expected'], $result, $case['test_condition_name'] );
		}
	}
}
