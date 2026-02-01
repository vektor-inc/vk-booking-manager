<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * @group common
 */
class Helper_Currency_Symbol_Test extends WP_UnitTestCase {
	public function test_get_currency_symbol_cases(): void {
		$test_cases = [
			[
				'name'       => 'uses_setting_symbol',
				'conditions' => [
					'options' => [
						'currency_symbol' => '€',
					],
					'locale'  => 'en_US',
				],
				'expected'   => '€',
			],
			[
				'name'       => 'uses_japanese_locale_default',
				'conditions' => [
					'options' => [
						'currency_symbol' => '',
					],
					'locale'  => 'ja_JP',
				],
				'expected'   => '¥',
			],
			[
				'name'       => 'uses_default_dollar_when_empty',
				'conditions' => [
					'options' => [
						'currency_symbol' => '',
					],
					'locale'  => 'en_US',
				],
				'expected'   => '$',
			],
			[
				'name'       => 'trims_setting_symbol',
				'conditions' => [
					'options' => [
						'currency_symbol' => '  ¥ ',
					],
					'locale'  => 'en_US',
				],
				'expected'   => '¥',
			],
		];

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );

			$options = $case['conditions']['options'] ?? [];
			if ( $options ) {
				update_option( Settings_Repository::OPTION_KEY, $options );
			}

			$locale   = $case['conditions']['locale'] ?? '';
			$switched = false;
			if ( is_string( $locale ) && '' !== $locale ) {
				$switched = switch_to_locale( $locale );
			}

			try {
				$actual = VKBM_Helper::get_currency_symbol();
				$this->assertSame( $case['expected'], $actual, $case['name'] );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}
	}
}
