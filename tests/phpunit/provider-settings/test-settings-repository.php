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

	/**
	 * お支払いの案内（provider_payment_method）の初期値が、サイトの言語ごとに
	 * カード決済と併用しても矛盾しない文章になっていることを検証する（#540）。
	 */
	public function test_get_default_settings(): void {
		$test_cases = [
			[
				'test_condition_name' => '日本語サイトの場合 => 日本語の初期値',
				'locale'              => 'ja',
				'expected'            => 'ご来店時のお支払いには、現金・クレジットカード・交通系ICカードがご利用いただけます。',
			],
			[
				'test_condition_name' => '英語サイトの場合 => 英語の初期値',
				'locale'              => 'en_US',
				'expected'            => 'For payment at the store, we accept cash, credit cards, and transit IC cards.',
			],
		];

		foreach ( $test_cases as $case ) {
			// サイトの言語を locale フィルターで差し替えてから初期値を取得する。
			// （テスト環境に言語パックが無くても判定できるよう、switch_to_locale() は使わない）
			$locale        = $case['locale'];
			$locale_filter = static function () use ( $locale ): string {
				return $locale;
			};
			add_filter( 'locale', $locale_filter );

			$default_settings = ( new Settings_Repository() )->get_default_settings();

			// 次のケースへ影響を残さないよう、フィルターを取り除く。
			remove_filter( 'locale', $locale_filter );

			$this->assertSame(
				$case['expected'],
				$default_settings['provider_payment_method'],
				$case['test_condition_name']
			);
		}
	}

	/**
	 * provider_max_advance_booking_days のデフォルト値と保存値の取得テスト。
	 */
	public function test_get_settings_max_advance_booking_days(): void {
		$repository = new Settings_Repository();

		$test_cases = [
			[
				'test_condition_name' => '保存値がない場合はデフォルトの 0（無制限）を返す',
				'conditions'          => [
					'stored_settings' => null,
				],
				'expected'            => 0,
			],
			[
				'test_condition_name' => '保存値が 14 の場合は 14 を返す',
				'conditions'          => [
					'stored_settings' => [
						'provider_max_advance_booking_days' => 14,
					],
				],
				'expected'            => 14,
			],
			[
				'test_condition_name' => '保存値が 0 の場合は 0（無制限）を返す',
				'conditions'          => [
					'stored_settings' => [
						'provider_max_advance_booking_days' => 0,
					],
				],
				'expected'            => 0,
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
				$case['expected'],
				$settings['provider_max_advance_booking_days'],
				$case['test_condition_name']
			);
		}
	}
}
