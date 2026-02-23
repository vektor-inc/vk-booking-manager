<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * @group provider-settings
 */
class Settings_Repository_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();

		delete_option( Settings_Repository::OPTION_KEY );
	}

	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );

		parent::tear_down();
	}

	public function test_get_settings(): void {
		$repository        = new Settings_Repository();
		$default_settings  = $repository->get_default_settings();
		$stored_policy     = "保存済みキャンセルポリシー\n当日キャンセルはご連絡ください。";
		$stored_terms      = "保存済み利用規約\n本サービスの利用には同意が必要です。";
		$default_policy    = $default_settings['provider_cancellation_policy'];
		$default_terms     = $default_settings['provider_terms_of_service'];

		$test_cases = [
			[
				'test_condition_name' => 'vkbm_provider_settings が存在しない場合はデフォルト値を返す',
				'conditions'          => [
					'stored_settings' => null,
				],
				'expected'            => [
					'provider_cancellation_policy' => $default_policy,
					'provider_terms_of_service'    => $default_terms,
				],
			],
			[
				'test_condition_name' => 'vkbm_provider_settings に保存値がある場合は保存値を返す',
				'conditions'          => [
					'stored_settings' => [
						'provider_cancellation_policy' => $stored_policy,
						'provider_terms_of_service'    => $stored_terms,
					],
				],
				'expected'            => [
					'provider_cancellation_policy' => $stored_policy,
					'provider_terms_of_service'    => $stored_terms,
				],
			],
		];

		foreach ( $test_cases as $case ) {
			if ( null === $case['conditions']['stored_settings'] ) {
				delete_option( Settings_Repository::OPTION_KEY );
			} else {
				update_option( Settings_Repository::OPTION_KEY, $case['conditions']['stored_settings'] );
			}

			$settings = $repository->get_settings();

			$this->assertSame(
				$case['expected']['provider_cancellation_policy'],
				$settings['provider_cancellation_policy'],
				$case['test_condition_name']
			);
			$this->assertSame(
				$case['expected']['provider_terms_of_service'],
				$settings['provider_terms_of_service'],
				$case['test_condition_name']
			);
		}
	}
}
