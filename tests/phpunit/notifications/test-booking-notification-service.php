<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Notifications;

use ReflectionMethod;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * @group notifications
 */
class Booking_Notification_Service_Test extends WP_UnitTestCase {
	/** @var string */
	private $original_date_format = '';

	/** @var string */
	private $original_time_format = '';

	/** @var string */
	private $original_timezone_string = '';

	/** @var string */
	private $original_admin_email = '';

	protected function setUp(): void {
		parent::setUp();

		// テスト終了時に戻せるよう、現在の日時関連設定を保持する。
		$this->original_date_format     = (string) get_option( 'date_format' );
		$this->original_time_format     = (string) get_option( 'time_format' );
		$this->original_timezone_string = (string) get_option( 'timezone_string' );
		$this->original_admin_email     = (string) get_option( 'admin_email' );

		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		update_option( 'date_format', $this->original_date_format );
		update_option( 'time_format', $this->original_time_format );
		update_option( 'timezone_string', $this->original_timezone_string );
		update_option( 'admin_email', $this->original_admin_email );

		parent::tearDown();
	}

	public function test_format_datetime_with_weekday(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'format_datetime_with_weekday' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '未指定の時はデフォルトフォーマットで整形される',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => '',
					'time_format' => '',
					'input'       => '2026-02-26 12:30:00',
				),
				'expected'            => '2026-02-26 (木) 12:30',
			),
			array(
				'test_condition_name' => '年月日 時分 表記の時は設定フォーマットで整形される',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'input'       => '2026-02-26 12:30:00',
				),
				'expected'            => '2026年2月26日 (木) 12:30',
			),
			array(
				'test_condition_name' => 'en_US の時は英語の曜日短縮表記で整形される',
				'conditions'          => array(
					'locale'      => 'en_US',
					'date_format' => 'Y-m-d',
					'time_format' => 'H:i',
					'input'       => '2026-02-26 12:30:00',
				),
				'expected'            => '2026-02-26 (Thu) 12:30',
			),
		);

		foreach ( $test_cases as $case ) {
			$locale   = (string) ( $case['conditions']['locale'] ?? '' );
			$switched = false;
			if ( '' !== $locale ) {
				$current_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
				if ( $current_locale !== $locale ) {
					$switched = switch_to_locale( $locale );
					if ( ! $switched ) {
						$this->markTestSkipped( 'Required locale not available: ' . $locale . ' / ' . $case['test_condition_name'] );
					}
				}
			}

			try {
				update_option( 'date_format', $case['conditions']['date_format'] );
				update_option( 'time_format', $case['conditions']['time_format'] );

				$actual = (string) $method->invoke( $service, $case['conditions']['input'] );
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}
	}

	public function test_format_reservation_datetime_range(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'format_reservation_datetime_range' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '同日の場合は終了側を時刻のみで表示する',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'start'       => '2026-02-26 12:30:00',
					'end'         => '2026-02-26 14:40:00',
				),
				'expected'            => '2026年2月26日 (木) 12:30 - 14:40',
			),
			array(
				'test_condition_name' => '日付をまたぐ場合は終了側も年月日と曜日を表示する',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'start'       => '2026-02-26 23:30:00',
					'end'         => '2026-02-27 00:40:00',
				),
				'expected'            => '2026年2月26日 (木) 23:30 - 2026年2月27日 (金) 00:40',
			),
			array(
				'test_condition_name' => '開始または終了が未指定の場合は Not set を返す',
				'conditions'          => array(
					'locale'      => 'ja_JP',
					'date_format' => 'Y年n月j日',
					'time_format' => 'H:i',
					'start'       => '',
					'end'         => '2026-02-27 00:40:00',
				),
				'expected'            => __( 'Not set', 'vk-booking-manager' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$locale   = (string) ( $case['conditions']['locale'] ?? '' );
			$switched = false;
			if ( '' !== $locale ) {
				$current_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
				if ( $current_locale !== $locale ) {
					$switched = switch_to_locale( $locale );
					if ( ! $switched ) {
						$this->markTestSkipped( 'Required locale not available: ' . $locale . ' / ' . $case['test_condition_name'] );
					}
				}
			}

			try {
				update_option( 'date_format', $case['conditions']['date_format'] );
				update_option( 'time_format', $case['conditions']['time_format'] );

				$actual = (string) $method->invoke( $service, $case['conditions']['start'], $case['conditions']['end'] );
				$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
		}
	}

	/**
	 * 通知タイプごとの送信者ヘッダー情報が期待どおりに返ることを検証します。
	 */
	public function test_get_mail_header(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'get_mail_header' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'ユーザー向けメールは provider_name を優先し Reply-To に provider_email を設定する',
				'admin_email'         => 'admin@example.com',
				'type'                => 'pending_customer',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Provider',
					'mail'     => 'admin@example.com',
					'reply_to' => 'provider@example.com',
				),
			),
			array(
				'test_condition_name' => 'ユーザー向けメールで provider_name が空の場合はサイト名にフォールバックし provider_email が空なら Reply-To は空',
				'admin_email'         => 'admin@example.com',
				'type'                => 'pending_customer',
				'payload'             => array(
					'provider_name'       => '',
					'provider_email'      => '',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Site',
					'mail'     => 'admin@example.com',
					'reply_to' => '',
				),
			),
			array(
				'test_condition_name' => '施設向けメールは予約者名を From 名にし Reply-To に顧客メールを設定',
				'admin_email'         => 'admin@example.com',
				'type'                => 'pending_provider',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => '予約 太郎',
					'mail'     => 'admin@example.com',
					'reply_to' => 'customer@example.com',
				),
			),
			array(
				'test_condition_name' => '施設向けメールで予約者名が空の場合は provider_name を From 名にフォールバック',
				'admin_email'         => 'admin@example.com',
				'type'                => 'confirmed_provider',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Provider',
					'mail'     => 'admin@example.com',
					'reply_to' => 'customer@example.com',
				),
			),
			array(
				'test_condition_name' => '施設向けメールで顧客メールが不正な場合は Reply-To を空にする',
				'admin_email'         => 'admin@example.com',
				'type'                => 'cancelled_provider',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'not-an-email',
				),
				'expected'            => array(
					'name'     => '予約 太郎',
					'mail'     => 'admin@example.com',
					'reply_to' => '',
				),
			),
			array(
				'test_condition_name' => 'reminder_customer もユーザー向け経路として扱われ Reply-To に provider_email を設定する',
				'admin_email'         => 'admin@example.com',
				'type'                => 'reminder_customer',
				'payload'             => array(
					'provider_name'       => 'Sample Provider',
					'provider_email'      => 'provider@example.com',
					'site_name'           => 'Sample Site',
					'booking_author_name' => '予約 太郎',
					'customer_email'      => 'customer@example.com',
				),
				'expected'            => array(
					'name'     => 'Sample Provider',
					'mail'     => 'admin@example.com',
					'reply_to' => 'provider@example.com',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'admin_email', $case['admin_email'] );
			$actual = $method->invoke( $service, $case['type'], $case['payload'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * メールヘッダー表示名の CR/LF が除去されることを検証します。
	 */
	public function test_sanitize_mail_header_name(): void {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'sanitize_mail_header_name' );
		$method->setAccessible( true );

		$actual = (string) $method->invoke( $service, "Sample Name\r\nBcc: attacker@example.com" );
		$this->assertSame( 'Sample Name Bcc: attacker@example.com', $actual );

		$actual_with_tags = (string) $method->invoke( $service, "<b>店舗名</b>\t" );
		$this->assertSame( '店舗名', $actual_with_tags );
	}

}
