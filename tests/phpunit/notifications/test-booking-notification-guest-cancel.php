<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Notifications;

use VKBookingManager\Bookings\Booking_Source;
use VKBookingManager\Bookings\Guest_Cancel_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * ゲスト予約のお知らせメール・リマインドメールの「キャンセルについて」の節のテスト。
 *
 * @group notifications
 * @group guest-booking
 */
class Booking_Notification_Guest_Cancel_Test extends WP_UnitTestCase {
	private const PAGE_URL = 'https://example.com/reserve/';

	/**
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 送ろうとしたメール（pre_wp_mail で受け取る）。
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * @var \Closure|null
	 */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );

		$this->mails       = array();
		$this->mail_filter = function ( $pre, $atts ) {
			$this->mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		parent::tearDown();
	}

	/**
	 * 基本設定を上書き保存する。
	 *
	 * @param array<string, mixed> $overrides 上書きする値。
	 */
	private function save_settings( array $overrides ): void {
		$repository = new Settings_Repository();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				$repository->get_settings(),
				array(
					'reservation_page_url'                   => self::PAGE_URL,
					'provider_email'                         => 'shop@example.com',
					'provider_phone'                         => '03-1234-5678',
					'provider_booking_cancel_mode'           => 'hours',
					'provider_booking_cancel_deadline_hours' => 24,
					'booking_reminder_hours'                 => array( 24 ),
				),
				$overrides
			)
		);
	}

	/**
	 * 予約を 1 件作る。
	 *
	 * @param string|null          $source 予約の出どころの印。null なら印なし（会員の予約）。
	 * @param array<string, mixed> $meta   上書きする予約メタ。
	 * @return int 予約 ID。
	 */
	private function create_booking( ?string $source = Booking_Source::VALUE_GUEST, array $meta = array() ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => 0,
			)
		);
		$values     = array_merge(
			array(
				'_vkbm_booking_service_start'  => wp_date( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ),
				'_vkbm_booking_service_end'    => wp_date( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS + HOUR_IN_SECONDS ),
				'_vkbm_booking_status'         => 'confirmed',
				'_vkbm_booking_customer_name'  => 'ゲスト 太郎',
				'_vkbm_booking_customer_email' => 'guest@example.com',
			),
			$meta
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $booking_id, $key, $value );
		}
		if ( null !== $source ) {
			update_post_meta( $booking_id, Booking_Source::META_KEY, $source );
		}

		return $booking_id;
	}

	/**
	 * お客様宛てのメールの本文を返す。
	 *
	 * @return string 本文。送っていなければ空文字。
	 */
	private function customer_mail_body(): string {
		foreach ( $this->mails as $mail ) {
			if ( 'guest@example.com' === $mail['to'] ) {
				return (string) $mail['message'];
			}
		}

		return '';
	}

	/**
	 * 店舗宛てのメールの本文を返す。
	 *
	 * @return string 本文。送っていなければ空文字。
	 */
	private function provider_mail_body(): string {
		foreach ( $this->mails as $mail ) {
			if ( 'shop@example.com' === $mail['to'] ) {
				return (string) $mail['message'];
			}
		}

		return '';
	}

	/**
	 * お知らせメール（確定・仮予約）: ゲスト予約のお客様宛てにだけ、キャンセル用リンクの節を足す。
	 */
	public function test_handle_confirmed_creation(): void {
		$header = __( '--- About cancellation ---', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => 'キャンセルできるゲスト予約 => 節とリンクを載せる',
				'settings'            => array(),
				'source'              => Booking_Source::VALUE_GUEST,
				'meta'                => array(),
				'expected_header'     => true,
				'expected_link'       => true,
				'expected_tel_line'   => false,
			),
			array(
				'test_condition_name' => 'キャンセル不可の設定 => リンクを載せず、電話での連絡を案内する',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'source'              => Booking_Source::VALUE_GUEST,
				'meta'                => array(),
				'expected_header'     => true,
				'expected_link'       => false,
				'expected_tel_line'   => true,
			),
			array(
				'test_condition_name' => 'キャンセル不可で電話番号が未設定 => リンクを載せず、電話番号の行も付けない',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none', 'provider_phone' => '' ),
				'source'              => Booking_Source::VALUE_GUEST,
				'meta'                => array(),
				'expected_header'     => true,
				'expected_link'       => false,
				'expected_tel_line'   => false,
			),
			array(
				'test_condition_name' => 'すでに締め切りを過ぎた予約 => リンクを載せず、電話での連絡を案内する',
				'settings'            => array(),
				'source'              => Booking_Source::VALUE_GUEST,
				'meta'                => array(
					'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
					'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ),
				),
				'expected_header'     => true,
				'expected_link'       => false,
				'expected_tel_line'   => true,
			),
			array(
				'test_condition_name' => '会員の予約（ゲスト予約の印が無い） => 節を載せない',
				'settings'            => array(),
				'source'              => null,
				'meta'                => array(),
				'expected_header'     => false,
				'expected_link'       => false,
				'expected_tel_line'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			foreach ( array( 'confirmed', 'pending' ) as $kind ) {
				$this->mails = array();
				$this->save_settings( $case['settings'] );
				$booking_id = $this->create_booking( $case['source'], $case['meta'] );
				$service    = new Booking_Notification_Service( new Settings_Repository() );

				// 予約を作った直後と同じく、発行した生のトークンを同じリクエストの間だけ覚えさせてから送る。
				$raw = Guest_Cancel_Controller::issue_token( $booking_id, true );
				if ( 'confirmed' === $kind ) {
					$service->handle_confirmed_creation( $booking_id );
				} else {
					$service->handle_pending_creation( $booking_id );
				}

				$name = $case['test_condition_name'] . '（' . $kind . '）';
				$body = $this->customer_mail_body();
				$this->assertNotSame( '', $body, $name . '（お客様宛てのメールを送る）' );
				$this->assertSame( $case['expected_header'], false !== strpos( $body, $header ), $name . '（節の見出し）' );
				$expected_url = self::PAGE_URL . '?vkbm_guest_cancel=' . $raw . '&booking=' . $booking_id;
				$this->assertSame( $case['expected_link'], false !== strpos( $body, $expected_url ), $name . '（リンク）' );
				$this->assertSame( $case['expected_tel_line'], false !== strpos( $body, 'TEL: 03-1234-5678' ) && substr_count( $body, 'TEL: 03-1234-5678' ) >= 2, $name . '（案内の TEL 行。署名の分を除く）' );

				// 店舗宛てのメールには、リンクも節も載せない。
				$provider_body = $this->provider_mail_body();
				$this->assertNotSame( '', $provider_body, $name . '（店舗宛てのメールを送る）' );
				$this->assertStringNotContainsString( 'vkbm_guest_cancel', $provider_body, $name . '（店舗宛てにリンクを載せない）' );
				$this->assertStringNotContainsString( $header, $provider_body, $name . '（店舗宛てに節を載せない）' );
			}
		}
	}

	/**
	 * 理由と期限の案内: 締め切り超過のときだけ理由を添える。リンクありのときは、いつまでキャンセルできるかを 1 行添える。
	 */
	public function test_cancel_notice_wording(): void {
		$deadline_text = __( 'The deadline for cancellation requests has passed, so you cannot cancel this reservation on the web. Please call us.', 'vk-booking-manager' );
		$disabled_text = __( 'You cannot cancel this reservation on the web. Please call us.', 'vk-booking-manager' );
		$hours_text    = sprintf( __( 'You can cancel up to %d hours before your reservation starts.', 'vk-booking-manager' ), 24 );
		$soon          = array(
			'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
			'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '締め切りを過ぎた予約 => 期限を過ぎている理由を添える',
				'settings'            => array(),
				'meta'                => $soon,
				'expected_contains'   => array( $deadline_text ),
				'expected_missing'    => array( $disabled_text, $hours_text ),
			),
			array(
				'test_condition_name' => '設定が「キャンセル不可」 => 理由は添えず、従来の文面',
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'meta'                => array(),
				'expected_contains'   => array( $disabled_text ),
				'expected_missing'    => array( $deadline_text, $hours_text ),
			),
			array(
				'test_condition_name' => '開始の 24 時間前まで => リンクと一緒に期限を 1 行添える',
				'settings'            => array(),
				'meta'                => array(),
				'expected_contains'   => array( $hours_text, 'vkbm_guest_cancel' ),
				'expected_missing'    => array( $deadline_text, $disabled_text ),
			),
			array(
				'test_condition_name' => '締め切りが 0 時間 => 期限の行は添えない',
				'settings'            => array( 'provider_booking_cancel_deadline_hours' => 0 ),
				'meta'                => array(),
				'expected_contains'   => array( 'vkbm_guest_cancel' ),
				'expected_missing'    => array( sprintf( __( 'You can cancel up to %d hours before your reservation starts.', 'vk-booking-manager' ), 0 ) ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->mails = array();
			$this->save_settings( $case['settings'] );
			$booking_id = $this->create_booking( Booking_Source::VALUE_GUEST, $case['meta'] );

			( new Booking_Notification_Service( new Settings_Repository() ) )->handle_confirmed_creation( $booking_id );

			$body = $this->customer_mail_body();
			foreach ( $case['expected_contains'] as $text ) {
				$this->assertStringContainsString( $text, $body, $case['test_condition_name'] );
			}
			foreach ( $case['expected_missing'] as $text ) {
				$this->assertStringNotContainsString( $text, $body, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 日本語の翻訳でも、「リンクを開いただけではキャンセルされません」の注意書きはメール本文に 1 回だけ入る（翻訳ファイルで文が二重になる不具合の再発防止）。
	 */
	public function test_cancel_notice_appears_once_in_japanese(): void {
		$mo_file = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		$this->assertFileExists( $mo_file );
		$this->assertTrue( load_textdomain( 'vk-booking-manager', $mo_file ) );

		try {
			$notice = '開いたページの「この予約をキャンセルする」ボタンを押してください。';
			$this->save_settings( array() );
			$booking_id = $this->create_booking( Booking_Source::VALUE_GUEST );

			( new Booking_Notification_Service( new Settings_Repository() ) )->handle_confirmed_creation( $booking_id );

			$body = $this->customer_mail_body();
			$this->assertSame( 1, substr_count( $body, $notice ), '注意書きが 1 回だけ含まれる' );
			$this->assertSame( 1, substr_count( $body, 'リンクを開いただけではキャンセルされません。' ), '前半の文も 1 回だけ含まれる' );
		} finally {
			unload_textdomain( 'vk-booking-manager', true );
		}
	}

	/**
	 * 送らなかった・送れなかったメールのために発行したトークンは残さない（先に届いたリンクを上限で押し出さないため）。
	 */
	public function test_unsent_mail_does_not_keep_token(): void {
		$fail    = static function () {
			return false;
		};
		$test_cases = array(
			array(
				'test_condition_name' => '送信に失敗 => 作成直後に発行した分を残さない',
				'meta'                => array(),
				'fail_mail'           => true,
				'reminder'            => false,
			),
			array(
				'test_condition_name' => '宛先が無く送らない => 発行した分を残さない',
				'meta'                => array( '_vkbm_booking_customer_email' => '' ),
				'fail_mail'           => false,
				'reminder'            => false,
			),
			array(
				'test_condition_name' => 'リマインドの送信に失敗 => 発行した分を残さない',
				'meta'                => array(
					'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS - 60 ),
					'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + 25 * HOUR_IN_SECONDS ),
				),
				'fail_mail'           => true,
				'reminder'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( array( 'provider_booking_cancel_deadline_hours' => 12 ) );
			$booking_id = $this->create_booking( Booking_Source::VALUE_GUEST, $case['meta'] );
			$first      = Guest_Cancel_Controller::issue_token( $booking_id );
			$service    = new Booking_Notification_Service( new Settings_Repository() );

			if ( $case['fail_mail'] ) {
				// 横取り（優先度 10）の後で、失敗を返す。
				add_filter( 'pre_wp_mail', $fail, 20 );
			}
			if ( $case['reminder'] ) {
				$service->handle_reminders();
			} else {
				Guest_Cancel_Controller::issue_token( $booking_id, true );
				$service->handle_confirmed_creation( $booking_id );
			}
			remove_filter( 'pre_wp_mail', $fail, 20 );

			$rows = get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false );
			$this->assertSame( array( hash( 'sha256', $first ) ), $rows, $case['test_condition_name'] . '（先に届いたリンクの分だけが残る）' );
		}
	}

	/**
	 * 作成直後に渡された生のトークンを使う。渡されなかったとき（再送など）は、新しく発行して前のリンクも残す。
	 */
	public function test_dispatch_notification_token(): void {
		$this->save_settings( array() );
		$booking_id = $this->create_booking();
		$first      = Guest_Cancel_Controller::issue_token( $booking_id, true );
		$service    = new Booking_Notification_Service( new Settings_Repository() );

		$service->handle_confirmed_creation( $booking_id );
		$this->assertStringContainsString( 'vkbm_guest_cancel=' . $first . '&booking=' . $booking_id, $this->customer_mail_body(), '渡されたトークンを使う' );
		$this->assertCount( 1, get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), '発行し直さない' );

		$this->mails = array();
		$service->handle_retry( 'confirmed_customer', $booking_id, 2 );
		$this->assertSame( 1, preg_match( '/vkbm_guest_cancel=([A-Za-z0-9]{32})&booking=' . $booking_id . '/', $this->customer_mail_body(), $matches ), '再送のメールにもリンクが入る' );
		$this->assertNotSame( $first, $matches[1], '再送では新しいトークンを発行する' );
		$this->assertTrue( Guest_Cancel_Controller::verify_token( $booking_id, $matches[1] ), '新しいトークンが使える' );
		$this->assertTrue( Guest_Cancel_Controller::verify_token( $booking_id, $first ), '前のメールのリンクも使える' );
	}

	/**
	 * リマインドメール: 生のトークンを持っていないため、送信のたびにトークンを発行してリンクを載せる。
	 */
	public function test_handle_reminders(): void {
		$header = __( '--- About cancellation ---', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => 'ゲスト予約 => 新しいトークンを発行してリンクを載せる',
				'source'              => Booking_Source::VALUE_GUEST,
				'settings'            => array(),
				'expected_link'       => true,
				'expected_header'     => true,
			),
			array(
				'test_condition_name' => 'ゲスト予約でキャンセル不可 => リンクを載せず電話での連絡を案内する',
				'source'              => Booking_Source::VALUE_GUEST,
				'settings'            => array( 'provider_booking_cancel_mode' => 'none' ),
				'expected_link'       => false,
				'expected_header'     => true,
			),
			array(
				'test_condition_name' => '会員の予約 => 節を載せない',
				'source'              => null,
				'settings'            => array(),
				'expected_link'       => false,
				'expected_header'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->mails = array();
			// リマインド（24 時間前）の送信窓に入るよう、開始の 24 時間前ちょうどにする。締め切りが 24 時間前でもキャンセルできる（ちょうどは可）。
			$this->save_settings( array_merge( array( 'provider_booking_cancel_deadline_hours' => 12 ), $case['settings'] ) );
			$booking_id = $this->create_booking(
				$case['source'],
				array(
					'_vkbm_booking_service_start' => wp_date( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS - 60 ),
					'_vkbm_booking_service_end'   => wp_date( 'Y-m-d H:i:s', time() + 25 * HOUR_IN_SECONDS ),
				)
			);
			$before = Booking_Source::VALUE_GUEST === $case['source'] ? Guest_Cancel_Controller::issue_token( $booking_id ) : '';

			( new Booking_Notification_Service( new Settings_Repository() ) )->handle_reminders();

			$body = $this->customer_mail_body();
			$this->assertNotSame( '', $body, $case['test_condition_name'] . '（リマインドを送る）' );
			$this->assertSame( $case['expected_header'], false !== strpos( $body, $header ), $case['test_condition_name'] . '（節の見出し）' );
			$has_link = 1 === preg_match( '/vkbm_guest_cancel=([A-Za-z0-9]{32})&booking=' . $booking_id . '/', $body, $matches );
			$this->assertSame( $case['expected_link'], $has_link, $case['test_condition_name'] . '（リンク）' );
			if ( $case['expected_link'] ) {
				$this->assertTrue( Guest_Cancel_Controller::verify_token( $booking_id, $matches[1] ), $case['test_condition_name'] . '（載せたトークンで照合できる）' );
				$this->assertNotSame( $before, $matches[1], $case['test_condition_name'] . '（新しく発行している）' );
				$this->assertTrue( Guest_Cancel_Controller::verify_token( $booking_id, $before ), $case['test_condition_name'] . '（前のメールのリンクも使える）' );
			}
		}
	}

	/**
	 * キャンセル通知のメールには、キャンセル用リンクを載せない。
	 */
	public function test_handle_customer_cancellation(): void {
		$this->save_settings( array() );
		$booking_id = $this->create_booking();

		( new Booking_Notification_Service( new Settings_Repository() ) )->handle_customer_cancellation( $booking_id );

		$body = $this->customer_mail_body();
		$this->assertNotSame( '', $body, 'キャンセル通知を送る' );
		$this->assertStringNotContainsString( 'vkbm_guest_cancel', $body );
		$this->assertStringNotContainsString( __( '--- About cancellation ---', 'vk-booking-manager' ), $body );
		$this->assertSame( array(), get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false ), 'キャンセル通知ではトークンを発行しない' );
	}
}
