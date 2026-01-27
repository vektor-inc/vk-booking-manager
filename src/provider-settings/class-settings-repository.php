<?php

/**
 * Persists provider settings to the WordPress options table.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function __;

/**
 * Persists provider settings to the WordPress options table.
 */
class Settings_Repository {
	public const OPTION_KEY = 'vkbm_provider_settings';

	/**
	 * Option key used for persistence.
	 *
	 * @var string
	 */
	private $option_key;

	/**
	 * Constructor.
	 *
	 * @param string $option_key Option key to use.
	 */
	public function __construct( string $option_key = self::OPTION_KEY ) {
		$this->option_key = $option_key;
	}

	/**
	 * Returns the default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		$is_japanese = $this->is_japanese_locale();

		return array(
			'provider_name'                              => '',
			'provider_address'                           => '',
			'provider_phone'                             => '',
			'provider_payment_method'                    => $is_japanese
				? __(
					'ご来店時に店舗にてお支払いください。
現金、クレジットカード、交通系ICカードがご利用いただけます。',
					'vk-booking-manager'
				)
				: __(
					'Please pay at the store when you visit.
You can use cash, credit cards, and transportation ICs.',
					'vk-booking-manager'
				),
			'resource_label_singular'                    => 'Staff',
			'resource_label_plural'                      => 'Staff',
			'resource_label_menu'                        => 'Staff available',
			'provider_business_hours'                    => '',
			'provider_reservation_deadline_hours'        => 3,
			'provider_slot_step_minutes'                 => 15,
			'provider_service_menu_buffer_after_minutes' => 0,
			'provider_booking_status_mode'               => 'confirmed',
			'provider_booking_cancel_mode'               => 'hours',
			'provider_booking_cancel_deadline_hours'     => 24,
			'provider_allow_staff_overlap_admin'         => false,
			'provider_website_url'                       => '',
			'provider_email'                             => '',
			'shift_alert_months'                         => 1,
			'booking_reminder_hours'                     => array(),
			'design_primary_color'                       => '',
			'design_reservation_button_color'            => '',
			'design_radius_md'                           => 8,
			'provider_logo_id'                           => 0,
			'provider_cancellation_policy'               => $is_japanese
				? $this->get_default_cancellation_policy_ja()
				: __(
					'For cancellations or changes, please contact us during business hours the day before your reservation date.
If you cancel on the day, 100% of the treatment fee will be charged as a cancellation fee.
In case of cancellation without notice, 100% of the treatment fee will be charged.
Please be sure to contact us if you will be late for your reservation time. If you are late, your treatment time may be shortened (the price will not change).',
					'vk-booking-manager'
				),
			'provider_terms_of_service'                  => $is_japanese
				? $this->get_default_terms_of_service_ja()
				: __(
					"[System Terms of Use]

These Terms set forth the terms of use of the reservation system (hereinafter referred to as the \"Service\") provided by our store. Customers who use this service (hereinafter referred to as \"users\") must agree to these terms before using the service.

1. Application
These Terms apply to all relationships related to the use of this service between users and our store.

2. Registration for use
When using this service, you may be required to register for use as necessary. If there are any falsehoods, errors, or omissions in the registered information, our store may cancel the approval of registration.

3. Account Management
Users shall manage their account information at their own risk. We are not responsible for any damage caused by unauthorized use of your account, unless it is intentional or grossly negligent on our part.

4. Reservations/Changes/Cancellations
Handling of reservations, changes, cancellations, late arrivals, etc. shall be in accordance with the \"Cancellation Policy\" separately established by our store.

5. Prohibited matters
Users must not engage in the following acts when using this service.
- Acts that violate laws and regulations or public order and morals
- Acts that make reservations using false information
- Acts that infringe on the rights and interests of our store or third parties
- Acts that interfere with the operation of this service (excessive access, unauthorized access to the system, etc.)
- Other acts that our store deems inappropriate

6.Disclaimer
Our store does not guarantee the accuracy, completeness, usefulness, etc. of the content of this service. This service may be unavailable due to communication line/equipment/system failures, maintenance, force majeure, etc., and we will not be responsible for any damage caused to users as a result, unless there is intentional or gross negligence on our part.

7. Handling of personal information
Our store handles users' personal information appropriately in accordance with our privacy policy.

8. Changes to Terms
Our store may change the contents of these Terms as necessary. The revised Terms will be made known by posting on the Service or any other method that the Company deems appropriate, and if the User uses the Service after being made aware, the User will be deemed to have agreed to the changes.

9. Governing law/jurisdiction
Japanese law shall be the governing law for the interpretation of these Terms, and in the event of any dispute regarding this service, the court with jurisdiction over the location of our store shall have exclusive jurisdiction.
",
					'vk-booking-manager'
				),
			'provider_privacy_policy_mode'               => 'none',
			'provider_privacy_policy_url'                => '',
			'provider_privacy_policy_content'            => $is_japanese
				? $this->get_default_privacy_policy_ja()
				: __(
					"[Privacy Policy]

Our store uses customers' personal information for the following purposes.
1. Management and communication of reservations
2. Information on service provision and improvement
3. Response based on laws and regulations

Information to be acquired: name, email address, telephone number, date of birth, etc.

Provision to third parties: Unless required by law, information will not be provided to third parties without the consent of the individual.

Storage and management: We will take necessary safety management measures to prevent unauthorized access, etc.

Disclosure/Correction/Deletion: If there is a request from the person in question, we will respond according to the prescribed method.

Contact us: Please contact our store.",
					'vk-booking-manager'
				),
			'reservation_page_url'                       => '',
			'reservation_show_menu_list'                 => true,
			'reservation_menu_list_display_mode'         => 'card',
			'reservation_show_provider_logo'             => false,
			'reservation_show_provider_name'             => false,
			'currency_symbol'                            => '',
			'tax_label_text'                             => __( '(tax included)', 'vk-booking-manager' ),
			'provider_regular_holidays'                  => array(),
			'provider_regular_holidays_disabled'         => false,
			'provider_business_hours_basic'              => array(),
			'provider_business_hours_weekly'             => $this->get_default_business_hours_weekly(),
			'registration_email_verification_enabled'    => true,
			'membership_redirect_wp_register'            => true,
			'membership_redirect_wp_login'               => true,
			'auth_rate_limit_enabled'                    => true,
			'auth_rate_limit_register_max'               => 5,
			'auth_rate_limit_login_max'                  => 10,
			'email_log_enabled'                          => false,
			'email_log_retention_days'                   => 1,
		);
	}

	/**
	 * Fetches persisted settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( $this->option_key, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = $this->get_default_settings();
		$settings = array_merge( $defaults, $stored );

		$settings['provider_business_hours_basic'] = $this->normalize_business_hours_basic(
			$settings['provider_business_hours_basic'] ?? array()
		);

		$settings['provider_business_hours_weekly'] = $this->normalize_business_hours_weekly(
			$settings['provider_business_hours_weekly'] ?? array()
		);

		return $settings;
	}

	/**
	 * Persists the provided settings array.
	 *
	 * @param array<string, mixed> $settings Sanitized settings array.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings( array $settings ): bool {
		return update_option( $this->option_key, $settings );
	}

	/**
	 * Check if the current locale is Japanese.
	 *
	 * @return bool
	 */
	private function is_japanese_locale(): bool {
		$locale = get_locale();
		return strpos( $locale, 'ja' ) === 0;
	}

	/**
	 * Returns the default cancellation policy in Japanese.
	 *
	 * These are default values that users can edit in the admin panel.
	 * Using translation functions for WordPress.org plugin review compliance.
	 *
	 * @return string
	 */
	private function get_default_cancellation_policy_ja(): string {
		return __(
			'キャンセル・変更の場合は、予約日の前日までに営業時間内にご連絡ください。
当日キャンセルの場合、施術料金の100%をキャンセル料として頂戴いたします。
無断キャンセルの場合、施術料金の100%をキャンセル料として頂戴いたします。
予約時間に遅れる場合は必ずご連絡ください。遅刻された場合、施術時間が短縮となる場合がございます（料金の変更はございません）。',
			'vk-booking-manager'
		);
	}

	/**
	 * Returns the default terms of service in Japanese.
	 *
	 * These are default values that users can edit in the admin panel.
	 * Using translation functions for WordPress.org plugin review compliance.
	 *
	 * @return string
	 */
	private function get_default_terms_of_service_ja(): string {
		return __(
			'[システム利用規約]

本規約は、当店が提供する予約システム（以下「本サービス」といいます）の利用条件を定めるものです。本サービスをご利用のお客様（以下「ユーザー」といいます）は、本サービスを利用する前に、本規約に同意していただく必要があります。

第1条（適用）
本規約は、ユーザーと当店との間の本サービスの利用に関わる一切の関係に適用されるものとします。

第2条（利用登録）
本サービスの利用に際して、必要に応じて利用登録をしていただく場合があります。登録情報に虚偽、誤り、または不備があった場合、当店は登録の承認を取り消すことがあります。

第3条（アカウント管理）
ユーザーは、自己の責任において、アカウント情報を管理するものとします。当店の故意または重過失による場合を除き、アカウントの不正使用により生じた損害について、当店は一切の責任を負いません。

第4条（予約・変更・キャンセル）
予約、変更、キャンセル、遅刻等の取り扱いは、当店が別途定める「キャンセルポリシー」に従うものとします。

第5条（禁止事項）
ユーザーは、本サービスの利用にあたり、以下の行為を行ってはなりません。
- 法令または公序良俗に違反する行為
- 虚偽の情報を用いて予約を行う行為
- 当店または第三者の権利・利益を侵害する行為
- 本サービスの運営を妨害する行為（過度なアクセス、不正アクセス等）
- その他、当店が不適切と判断する行為

第6条（免責事項）
当店は、本サービスの内容の正確性、完全性、有用性等について一切保証するものではありません。本サービスは、通信回線・機器・システムの故障、メンテナンス、不可抗力等により利用できない場合があり、その結果ユーザーに生じた損害について、当店の故意または重過失による場合を除き、一切の責任を負いません。

第7条（個人情報の取り扱い）
当店は、ユーザーの個人情報を、当店のプライバシーポリシーに従い、適切に取り扱います。

第8条（規約の変更）
当店は、必要に応じて本規約の内容を変更することがあります。変更後の規約は、本サービス上への掲示その他当店が適切と判断する方法により通知し、ユーザーが通知後に本サービスを利用した場合、変更後の規約に同意したものとみなします。

第9条（準拠法・管轄裁判所）
本規約の解釈にあたっては、日本法を準拠法とし、本サービスに関する紛争については、当店所在地を管轄する裁判所を専属的合意管轄裁判所とします。',
			'vk-booking-manager'
		);
	}

	/**
	 * Returns the default privacy policy in Japanese.
	 *
	 * These are default values that users can edit in the admin panel.
	 * Using translation functions for WordPress.org plugin review compliance.
	 *
	 * @return string
	 */
	private function get_default_privacy_policy_ja(): string {
		return __(
			'[プライバシーポリシー]

当店は、お客様の個人情報を以下の目的で利用いたします。
1. 予約の管理および連絡
2. サービス提供および改善のための情報提供
3. 法令に基づく対応

取得する情報：氏名、メールアドレス、電話番号、生年月日等

第三者への提供：法令に基づく場合を除き、本人の同意なく第三者に提供することはありません。

保管および管理：不正アクセス等を防ぐため、必要な安全管理措置を講じます。

開示・訂正・削除：本人からの請求があった場合、所定の方法に従い対応いたします。

お問い合わせ：当店までご連絡ください。',
			'vk-booking-manager'
		);
	}

	/**
	 * Returns the default weekly business hours structure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_default_business_hours_weekly(): array {
		$keys = array(
			'mon',
			'tue',
			'wed',
			'thu',
			'fri',
			'sat',
			'sun',
			'holiday',
			'holiday_eve',
		);

		$defaults = array();

		foreach ( $keys as $key ) {
			$defaults[ $key ] = array(
				'use_custom' => false,
				'time_slots' => array(),
			);
		}

		return $defaults;
	}

	/**
	 * Normalize basic business hour payloads.
	 *
	 * @param mixed $raw Raw value from the options table.
	 * @return array<int, array{start:string,end:string}>
	 */
	private function normalize_business_hours_basic( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $raw as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = isset( $slot['start'] ) ? (string) $slot['start'] : '';
			$end   = isset( $slot['end'] ) ? (string) $slot['end'] : '';

			if ( '' === $start || '' === $end ) {
				continue;
			}

			$normalized[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize weekly business hour payloads into the current structure.
	 *
	 * @param mixed $raw Raw value from the options table.
	 * @return array<string, array{use_custom:bool,time_slots:array<int, array{start:string,end:string}>}>
	 */
	private function normalize_business_hours_weekly( $raw ): array {
		$normalized = $this->get_default_business_hours_weekly();

		if ( ! is_array( $raw ) ) {
			return $normalized;
		}

		foreach ( $normalized as $day_key => $default_value ) {
			$day_value = $raw[ $day_key ] ?? null;

			if ( ! is_array( $day_value ) ) {
				// Try to map the legacy format directly from merged array.
				$day_value = $this->convert_legacy_day_value( $day_value );
			}

			if ( ! is_array( $day_value ) ) {
				continue;
			}

			$use_custom = isset( $day_value['use_custom'] ) ? (bool) $day_value['use_custom'] : false;
			$time_slots = array();

			if ( isset( $day_value['time_slots'] ) && is_array( $day_value['time_slots'] ) ) {
				foreach ( $day_value['time_slots'] as $slot ) {
					if ( ! is_array( $slot ) ) {
						continue;
					}

					$start = isset( $slot['start'] ) ? (string) $slot['start'] : '';
					$end   = isset( $slot['end'] ) ? (string) $slot['end'] : '';

					if ( '' === $start || '' === $end ) {
						continue;
					}

					$time_slots[] = array(
						'start' => $start,
						'end'   => $end,
					);
				}
			} elseif ( isset( $day_value['start'], $day_value['end'] ) ) {
				// Legacy single slot format.
				$start = (string) $day_value['start'];
				$end   = (string) $day_value['end'];

				if ( '' !== $start && '' !== $end ) {
					$time_slots[] = array(
						'start' => $start,
						'end'   => $end,
					);
				}
			}

			$normalized[ $day_key ] = array(
				'use_custom' => $use_custom,
				'time_slots' => $time_slots,
			);
		}

		return $normalized;
	}

	/**
	 * Attempt to convert legacy day values into the new structure.
	 *
	 * @param mixed $value Raw value for a day.
	 * @return array<string, mixed>|null
	 */
	private function convert_legacy_day_value( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( array_key_exists( 'use_custom', $value ) || array_key_exists( 'time_slots', $value ) ) {
			if ( array_key_exists( 'use_basic', $value ) && ! array_key_exists( 'use_custom', $value ) ) {
				$value['use_custom'] = ! empty( $value['use_basic'] );
				unset( $value['use_basic'] );
			}

			return $value;
		}

		if ( array_key_exists( 'use_basic', $value ) ) {
			$use_basic  = ! empty( $value['use_basic'] );
			$time_slots = isset( $value['time_slots'] ) && is_array( $value['time_slots'] ) ? $value['time_slots'] : array();

			return array(
				'use_custom' => ! $use_basic,
				'time_slots' => $time_slots,
			);
		}

		$enabled = ! empty( $value['enabled'] );
		$start   = isset( $value['start'] ) ? (string) $value['start'] : '';
		$end     = isset( $value['end'] ) ? (string) $value['end'] : '';

		if ( ! $enabled || '' === $start || '' === $end ) {
			return array(
				'use_custom' => false,
				'time_slots' => array(),
			);
		}

		return array(
			'use_custom' => true,
			'time_slots' => array(
				array(
					'start' => $start,
					'end'   => $end,
				),
			),
		);
	}
}
