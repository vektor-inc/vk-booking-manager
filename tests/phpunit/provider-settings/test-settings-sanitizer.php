<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\Admin\Pro_Upsell;
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

	/**
	 * closed_day_label がサニタイズされて保存されることを確認するテスト。
	 */
	public function test_sanitize_closed_day_label(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		// デフォルト値が空文字であることを確認する。
		$this->assertSame( '', $defaults['closed_day_label'] );

		// カスタムラベルが正しくサニタイズされることを確認する。
		$result = $sanitizer->sanitize(
			[ 'closed_day_label' => '  定休日 <script>alert(1)</script> ' ],
			$defaults
		);
		$this->assertSame( sanitize_text_field( '  定休日 <script>alert(1)</script> ' ), $result['closed_day_label'] );

		// 空文字のまま保存できることを確認する。
		$result_empty = $sanitizer->sanitize(
			[ 'closed_day_label' => '' ],
			$defaults
		);
		$this->assertSame( '', $result_empty['closed_day_label'] );

		// 未送信の場合はデフォルト値（空文字）になることを確認する。
		$result_missing = $sanitizer->sanitize(
			[],
			$defaults
		);
		$this->assertSame( '', $result_missing['closed_day_label'] );
	}

	/**
	 * staff_enabled の有効・無効がサニタイズで正しく処理されることを確認するテスト。
	 */
	public function test_sanitize_staff_enabled(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		// デフォルト設定で staff_enabled が true であることを確認する。
		$this->assertTrue( $defaults['staff_enabled'] );

		// staff_enabled を有効（truthy）で送信した場合。
		// 無料版では指名機能が常に無効のため、送信値に関わらず false へ強制される。
		// Pro 版では送信値どおり true が保存される。
		$result_enabled = $sanitizer->sanitize(
			[ 'staff_enabled' => '1' ],
			$defaults
		);
		if ( Pro_Upsell::is_free_edition() ) {
			$this->assertFalse( $result_enabled['staff_enabled'], '無料版では staff_enabled が強制的に無効になること' );
		} else {
			$this->assertTrue( $result_enabled['staff_enabled'], 'Pro 版では送信値どおり有効が保存されること' );
		}

		// staff_enabled を無効（空文字）で送信した場合。
		$result_disabled = $sanitizer->sanitize(
			[ 'staff_enabled' => '' ],
			$defaults
		);
		$this->assertFalse( $result_disabled['staff_enabled'] );

		// staff_enabled が送信されない場合（チェックボックス未チェック相当）。
		$result_missing = $sanitizer->sanitize(
			[],
			$defaults
		);
		$this->assertFalse( $result_missing['staff_enabled'] );
	}

	/**
	 * slot_capacity_enabled のサニタイズと後方互換フォールバックを検証するテスト（issue #281 / 呼称統一 #326）。
	 *
	 * 後方互換の核心：フォーム未送信時に false で焼き付けず、既定（true）を維持する事。
	 * staff_enabled と異なり、未送信時に無効化してはならない（既存サイトの挙動を変えないため）。
	 * 呼称統一（#326）でキー名を multiple_guests_enabled → slot_capacity_enabled に改名したため、
	 * 新キーでの送信・旧キー送信のフォールバック・未送信時の既定維持の3系統を検証する。
	 */
	public function test_sanitize_slot_capacity_enabled(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		// デフォルト設定で slot_capacity_enabled が true（有効）であることを確認する。
		$this->assertTrue( $defaults['slot_capacity_enabled'], '既定値は有効であること（未保存サイト相当）' );

		$is_free = Pro_Upsell::is_free_edition();

		// 条件と期待値の配列。無料版では常に false へ強制される。
		$test_cases = array(
			array(
				'test_condition_name' => '新キー 有効（"1"）を送信 → Pro:true / Free:false',
				'input'               => array( 'slot_capacity_enabled' => '1' ),
				'expected_pro'        => true,
			),
			array(
				'test_condition_name' => '新キー 無効（"0"）を送信 → 明示OFFで false',
				'input'               => array( 'slot_capacity_enabled' => '0' ),
				'expected_pro'        => false,
			),
			array(
				'test_condition_name' => '新キー 無効（空文字）を送信 → false',
				'input'               => array( 'slot_capacity_enabled' => '' ),
				'expected_pro'        => false,
			),
			array(
				// 旧キー送信のフォールバック（未リリース段階の開発DB互換）。
				'test_condition_name' => '旧キーのみ 無効（"0"）を送信 → false（旧キーへフォールバック）',
				'input'               => array( 'multiple_guests_enabled' => '0' ),
				'expected_pro'        => false,
			),
			array(
				'test_condition_name' => '旧キーのみ 有効（"1"）を送信 → true（旧キーへフォールバック）',
				'input'               => array( 'multiple_guests_enabled' => '1' ),
				'expected_pro'        => true,
			),
			array(
				// 後方互換の核心ケース：未送信時に false で焼き付けないこと。
				'test_condition_name' => 'キー未送信（指名ON時の disabled select 相当） → 既定の true を維持（後方互換）',
				'input'               => array(),
				'expected_pro'        => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$result   = $sanitizer->sanitize( $case['input'], $defaults );
			$expected = $is_free ? false : $case['expected_pro'];
			$this->assertSame(
				$expected,
				$result['slot_capacity_enabled'],
				$case['test_condition_name']
			);
			// 旧キーは新キーへ集約され、保存データには残らない事を確認する。
			$this->assertArrayNotHasKey(
				'multiple_guests_enabled',
				$result,
				'旧キー multiple_guests_enabled は保存データから除去されること: ' . $case['test_condition_name']
			);
		}
	}

	/**
	 * 指名機能を無効にして保存する際に、ラベルの保存値が維持されることを確認するテスト。
	 * Issue #174: 指名機能を無効にすると「指名なしラベル」「指名料ラベル」がリセットされる不具合の修正検証。
	 */
	public function test_sanitize_nomination_labels_preserved_when_disabled(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		$test_cases = [
			[
				'test_condition_name' => '指名無効時にラベルが hidden フィールドで送信された場合 => カスタムラベルが保持される',
				'conditions'          => [
					'input' => [
						'staff_enabled'       => '',
						'no_nomination_label'  => '指名なし',
						'nomination_fee_label' => '指名料',
					],
				],
				'expected'            => [
					'no_nomination_label'  => '指名なし',
					'nomination_fee_label' => '指名料',
					'staff_enabled'        => false,
				],
			],
			[
				'test_condition_name' => '指名有効時にラベルがテキストフィールドで送信された場合 => カスタムラベルが保持される',
				'conditions'          => [
					'input' => [
						'staff_enabled'       => '1',
						'no_nomination_label'  => 'お任せ',
						'nomination_fee_label' => '指名手数料',
					],
				],
				'expected'            => [
					'no_nomination_label'  => 'お任せ',
					'nomination_fee_label' => '指名手数料',
					// 無料版では指名機能が常に無効へ強制されるため false、Pro 版は送信値どおり true。
					'staff_enabled'        => ! Pro_Upsell::is_free_edition(),
				],
			],
			[
				'test_condition_name' => '指名無効時にラベルが送信されない場合 => デフォルト値になる（フォールバック）',
				'conditions'          => [
					'input' => [
						'staff_enabled' => '',
					],
				],
				'expected'            => [
					'no_nomination_label'  => $defaults['no_nomination_label'],
					'nomination_fee_label' => $defaults['nomination_fee_label'],
					'staff_enabled'        => false,
				],
			],
		];

		foreach ( $test_cases as $case ) {
			$result = $sanitizer->sanitize( $case['conditions']['input'], $defaults );

			foreach ( $case['expected'] as $key => $value ) {
				$this->assertSame( $value, $result[ $key ], $case['test_condition_name'] . " (key: {$key})" );
			}
		}
	}
}
