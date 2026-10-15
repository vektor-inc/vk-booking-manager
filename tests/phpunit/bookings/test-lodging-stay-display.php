<?php
/**
 * 泊の予約のチェックイン／チェックアウト・泊数の表示（通知メール・マイページ・メニュー一覧・管理画面・Google カレンダー）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\Bookings\My_Bookings_Controller;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Api_Client;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connect_Controller;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connection;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync_Settings;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Relay_Client;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 表示の分岐が「予約が泊の予約か（保存した泊数）」で決まること、時間枠の予約の表示が変わらないことを検証する。
 *
 * @group bookings
 * @group lodging
 */
class Lodging_Stay_Display_Test extends WP_UnitTestCase {

	/**
	 * 元の設定。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 元の日付・時刻・タイムゾーンの設定。
	 *
	 * @var array<string, string>
	 */
	private array $original_options = array();

	/**
	 * テスト前に設定を退避し、日付・時刻の書式を固定する。
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		foreach ( array( 'timezone_string', 'date_format', 'time_format' ) as $key ) {
			$this->original_options[ $key ] = (string) get_option( $key, '' );
		}
		// 文言の検証は英語（原文）で行う。サイトの言語が日本語でも、他のテストが日本語の翻訳を読み込んでいても結果が変わらないようにする。
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'Y年n月j日' );
		update_option( 'time_format', 'H:i' );
		$this->set_site( 'lodging' );
	}

	/**
	 * テスト後に設定を元に戻す。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		restore_previous_locale();
		foreach ( $this->original_options as $key => $value ) {
			update_option( $key, $value );
		}
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		parent::tearDown();
	}

	/**
	 * 業種プリセットを切り替える。
	 *
	 * @param string $preset 業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset' => $preset,
					'provider_email'  => 'owner@example.com',
				)
			)
		);
	}

	/**
	 * メニューを作る。
	 *
	 * @param string $title タイトル。
	 * @param array  $meta  メタ。
	 * @return int 投稿 ID。
	 */
	private function create_menu( string $title, array $meta = array() ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => $meta,
			)
		);
	}

	/**
	 * 泊の予約（泊数を保存した予約）を作る。チェックイン 2026-10-10 15:00、チェックアウト 2026-10-11 10:00。
	 *
	 * @param int $menu_id   メニュー ID。
	 * @param int $author_id 予約者のユーザー ID。
	 * @return int 予約の投稿 ID。
	 */
	private function create_stay_booking( int $menu_id, int $author_id = 0 ): int {
		$booking_id = $this->create_booking( $menu_id, '2026-10-10 15:00:00', '2026-10-11 10:00:00', $author_id );
		update_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, 1 );

		return $booking_id;
	}

	/**
	 * 予約を作る（泊数は保存しない）。
	 *
	 * @param int    $menu_id   メニュー ID。
	 * @param string $start     開始日時。
	 * @param string $end       終了日時。
	 * @param int    $author_id 予約者のユーザー ID。
	 * @return int 予約の投稿 ID。
	 */
	private function create_booking( int $menu_id, string $start, string $end, int $author_id = 0 ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_customer_name', '山田 太郎' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );

		return $booking_id;
	}

	/**
	 * 通知メールの本文の材料を取り出す。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return array<string, mixed>
	 */
	private function build_notification_payload( int $booking_id ): array {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$method->setAccessible( true );

		return $method->invoke( $service, $booking_id );
	}

	/**
	 * 通知メールの「予約情報」の行を取り出す。
	 *
	 * @param array<string, mixed> $payload 本文の材料。
	 * @return array<int, string>
	 */
	private function build_information_lines( array $payload ): array {
		$service = new Booking_Notification_Service( new Settings_Repository() );
		$method  = new ReflectionMethod( Booking_Notification_Service::class, 'get_reservation_information_lines' );
		$method->setAccessible( true );

		return $method->invoke( $service, $payload );
	}

	/**
	 * 予約に保存した泊数だけで、泊の予約かが決まる。
	 */
	public function test_is_stay_booking_depends_on_stored_nights_only(): void {
		$menu_id = $this->create_menu( '1泊プラン' );

		$stay = $this->create_stay_booking( $menu_id );
		$slot = $this->create_booking( $menu_id, '2026-10-10 10:00:00', '2026-10-10 11:00:00' );

		$this->assertTrue( Lodging_Booking::is_stay_booking( $stay ) );
		$this->assertSame( 1, Lodging_Booking::get_booking_nights( $stay ) );
		$this->assertFalse( Lodging_Booking::is_stay_booking( $slot ) );
		$this->assertSame( 0, Lodging_Booking::get_booking_nights( $slot ) );
		$this->assertFalse( Lodging_Booking::is_stay_booking( 0 ) );
	}

	/**
	 * 泊数のラベルは単数・複数で出し分ける。
	 */
	public function test_format_nights_label(): void {
		$this->assertSame( '1 night', Lodging_Booking::format_nights_label( 1 ) );
		$this->assertSame( '3 nights', Lodging_Booking::format_nights_label( 3 ) );
	}

	/**
	 * 泊の予約の通知メールは、日時の行がチェックイン／チェックアウトの1行になり、所要時間の行が泊数に置き換わる。
	 */
	public function test_notification_for_stay_booking_shows_check_in_out_and_nights(): void {
		$menu_id = $this->create_menu( '1泊プラン', array( '_vkbm_duration_minutes' => 530 ) );
		$payload = $this->build_notification_payload( $this->create_stay_booking( $menu_id ) );

		$this->assertSame( 'Check-in 2026年10月10日 (Sat) 15:00 / Check-out 2026年10月11日 (Sun) 10:00', $payload['reservation_datetime'] );
		$this->assertSame( '1 night', $payload['duration_label'] );

		$lines = $this->build_information_lines( $payload );
		$this->assertContains( 'Reservation date and time: Check-in 2026年10月10日 (Sat) 15:00 / Check-out 2026年10月11日 (Sun) 10:00', $lines );
		$this->assertContains( 'Number of nights: 1 night', $lines );
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( '530', $line, '泊の予約ではメニューの所要時間（分）を出さない' );
		}
	}

	/**
	 * 宿泊から別の業種へ切り替えた後も、受付済みの泊の予約は保存した日時のとおりに通知する。
	 */
	public function test_notification_for_stay_booking_after_switching_industry(): void {
		$menu_id    = $this->create_menu( '1泊プラン', array( '_vkbm_duration_minutes' => 530 ) );
		$booking_id = $this->create_stay_booking( $menu_id );

		$this->set_site( 'custom' );
		$payload = $this->build_notification_payload( $booking_id );

		$this->assertSame( 'Check-in 2026年10月10日 (Sat) 15:00 / Check-out 2026年10月11日 (Sun) 10:00', $payload['reservation_datetime'] );
		$this->assertSame( '1 night', $payload['duration_label'] );
	}

	/**
	 * 時間枠の予約の通知メールは今までと同じ（範囲の日時・所要時間（分））。宿泊のサイトでも変わらない。
	 */
	public function test_notification_for_time_slot_booking_is_unchanged(): void {
		$menu_id    = $this->create_menu( 'カット', array( '_vkbm_duration_minutes' => 60 ) );
		$booking_id = $this->create_booking( $menu_id, '2026-10-10 10:00:00', '2026-10-10 11:00:00' );

		foreach ( array( 'lodging', 'custom' ) as $preset ) {
			$this->set_site( $preset );
			$payload = $this->build_notification_payload( $booking_id );

			$this->assertSame( '2026年10月10日 (Sat) 10:00 - 11:00', $payload['reservation_datetime'] );
			$this->assertSame( '60 minutes', $payload['duration_label'] );

			$lines = $this->build_information_lines( $payload );
			$this->assertContains( 'Reservation date and time: 2026年10月10日 (Sat) 10:00 - 11:00', $lines );
			$this->assertNotContains( 'Number of nights: 1 night', $lines );
		}
	}

	/**
	 * マイページの予約一覧は、泊の予約にだけ泊数を返す（画面はこの値でチェックイン／チェックアウト表示にする）。
	 */
	public function test_my_bookings_returns_nights_for_stay_booking_only(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$menu_id = $this->create_menu( '1泊プラン' );

		$stay_id = $this->create_booking( $menu_id, '2099-10-10 15:00:00', '2099-10-11 10:00:00', $user_id );
		update_post_meta( $stay_id, Lodging_Booking::META_NIGHTS, 1 );
		$slot_id = $this->create_booking( $menu_id, '2099-10-12 10:00:00', '2099-10-12 11:00:00', $user_id );

		$request = new WP_REST_Request( 'GET', '/vkbm/v1/my-bookings' );
		$request->set_param( 'future_only', false );
		$items = ( new My_Bookings_Controller() )->get_bookings( $request )->get_data();

		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		$this->assertSame( 1, $by_id[ $stay_id ]['nights'] );
		$this->assertSame( '2099-10-10 15:00:00', $by_id[ $stay_id ]['start_at'] );
		$this->assertSame( '2099-10-11 10:00:00', $by_id[ $stay_id ]['end_at'] );
		$this->assertSame( 0, $by_id[ $slot_id ]['nights'] );
		$this->assertSame( '2099-10-12 11:00:00', $by_id[ $slot_id ]['end_at'] );
	}

	/**
	 * Google カレンダーの予定は、泊の予約ならチェックイン日時〜チェックアウト日時で登録する。
	 */
	public function test_google_calendar_event_input_uses_check_in_and_check_out_for_stay(): void {
		// Google カレンダー連携は Pro 版専用のファイルのため、無料版の配布物には無い。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu( '1泊プラン' );

		$stay_id = $this->create_stay_booking( $menu_id );
		// 後片付け込みの終了日時が別に保存されていても、泊の予約はチェックアウト日時を使う。
		update_post_meta( $stay_id, '_vkbm_booking_total_end', '2026-10-11 11:00:00' );
		$slot_id = $this->create_booking( $menu_id, '2026-10-10 10:00:00', '2026-10-10 10:30:00' );
		update_post_meta( $slot_id, '_vkbm_booking_total_end', '2026-10-10 10:45:00' );

		$stay_input = $this->build_event_input( $stay_id );
		$this->assertSame( '2026-10-10 15:00:00', $stay_input['start'] );
		$this->assertSame( '2026-10-11 10:00:00', $stay_input['end'] );

		// 時間枠の予約は今までどおり、後片付け込みの終了日時を使う。
		$slot_input = $this->build_event_input( $slot_id );
		$this->assertSame( '2026-10-10 10:00:00', $slot_input['start'] );
		$this->assertSame( '2026-10-10 10:45:00', $slot_input['end'] );
	}

	/**
	 * Google カレンダー連携の入力値を取り出す。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return array<string, mixed>
	 */
	private function build_event_input( int $booking_id ): array {
		$connection = new Google_Calendar_Connection();
		$relay      = new Google_Calendar_Relay_Client();
		$api_client = new Google_Calendar_Api_Client( $connection, $relay );
		$sync       = new Google_Calendar_Event_Sync(
			$connection,
			$api_client,
			new Google_Calendar_Connect_Controller( $connection, $relay, $api_client, 'manage_options' ),
			new Google_Calendar_Event_Sync_Settings(),
			Capabilities::MANAGE_RESERVATIONS
		);
		$method     = new ReflectionMethod( Google_Calendar_Event_Sync::class, 'build_event_input' );
		$method->setAccessible( true );

		return $method->invoke( $sync, $booking_id, get_post( $booking_id ) );
	}

	/**
	 * メニュー一覧のカードの、メタ情報の HTML を取り出す。
	 *
	 * @param int $menu_id メニュー ID。
	 * @return string
	 */
	private function render_menu_card_meta( int $menu_id ): string {
		$method = new ReflectionMethod( Menu_Loop_Block::class, 'render_meta_information' );
		$method->setAccessible( true );

		return (string) $method->invoke( new Menu_Loop_Block(), get_post( $menu_id ), array() );
	}

	/**
	 * 泊のプランのカードは、所要時間の代わりにチェックイン／チェックアウトの時刻を出す。
	 */
	public function test_menu_card_for_night_plan_shows_check_in_and_check_out(): void {
		$menu_id = $this->create_menu(
			'1泊プラン',
			array(
				'_vkbm_duration_minutes'                   => 530,
				Service_Menu_Post_Type::META_BOOKING_UNIT  => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
				Service_Menu_Post_Type::META_CHECK_IN_TIME => '15:00',
				Service_Menu_Post_Type::META_CHECK_OUT_TIME => '10:00',
			)
		);

		$html = $this->render_menu_card_meta( $menu_id );

		$this->assertStringContainsString( '<dt>Check-in</dt><dd>15:00</dd>', $html );
		$this->assertStringContainsString( '<dt>Check-out</dt><dd>Next day 10:00</dd>', $html );
		$this->assertStringNotContainsString( '530', $html );
	}

	/**
	 * 時間枠のプランのカードは今までどおり所要時間（分）を出し、宿泊を使わないサイトでは泊の設定が残っていても効かない。
	 */
	public function test_menu_card_for_time_slot_plan_is_unchanged(): void {
		$slot_menu_id = $this->create_menu( 'カット', array( '_vkbm_duration_minutes' => 60 ) );
		$html         = $this->render_menu_card_meta( $slot_menu_id );
		$this->assertStringContainsString( '60 minutes', $html );
		$this->assertStringNotContainsString( 'Check-in', $html );

		$night_menu_id = $this->create_menu(
			'1泊プラン',
			array(
				'_vkbm_duration_minutes'                  => 530,
				Service_Menu_Post_Type::META_BOOKING_UNIT => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
			)
		);
		$this->set_site( 'custom' );
		$html = $this->render_menu_card_meta( $night_menu_id );
		$this->assertStringContainsString( '530 minutes', $html );
		$this->assertStringNotContainsString( 'Check-in', $html );
	}

	/**
	 * 日によって料金が変わる泊のプランのカードは最安＋「〜」、年中同じ料金のプランは今までの表示のまま（#738）。
	 */
	public function test_menu_card_price_for_night_plan_shows_lowest_price_with_tilde(): void {
		$night_meta = array(
			Service_Menu_Post_Type::META_BOOKING_UNIT => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
			'_vkbm_base_price'                        => 7000,
		);

		$test_cases = array(
			array(
				'test_condition_name' => '休前日が高いプラン（1名あたり）: 最安＋「〜」と単位',
				'meta'                => array( Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => 9000 ),
				'contains'            => array( '7,000~ / ' ),
				'not_contains'        => array( '9,000' ),
			),
			array(
				'test_condition_name' => '1室あたり・特定日だけ違う: 1室の単位',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_UNIT => Service_Menu_Post_Type::NIGHT_PRICE_UNIT_PER_ROOM,
					Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL => 12000,
				),
				'contains'            => array( '7,000~ / 1 room, 1 night' ),
				'not_contains'        => array( '12,000' ),
			),
			array(
				'test_condition_name' => '休前日・特定日が空欄: 今までの表示（「〜」も単位も付けない）',
				'meta'                => array(),
				'contains'            => array( 'vkbm-menu-loop__card-price' ),
				'not_contains'        => array( '~', ' night' ),
			),
			array(
				'test_condition_name' => '休前日・特定日が平日と同じ金額: 今までの表示',
				'meta'                => array(
					Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => 7000,
					Service_Menu_Post_Type::META_NIGHT_PRICE_SPECIAL => 7000,
				),
				'contains'            => array( 'vkbm-menu-loop__card-price' ),
				'not_contains'        => array( '~', ' night' ),
			),
		);

		// 特定日の金額は、BM設定に特定日の期間があるときだけ最安の候補にする（期間が無い場合は別のテストで確かめる）。
		$settings                          = get_option( Settings_Repository::OPTION_KEY );
		$settings['lodging_special_dates'] = array(
			array(
				'start' => '2026-12-29',
				'end'   => '2027-01-03',
				'label' => '',
			),
		);
		update_option( Settings_Repository::OPTION_KEY, $settings );

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu( '宿泊プラン', array_merge( $night_meta, $case['meta'] ) );
			$html    = $this->render_menu_card_meta( $menu_id );
			foreach ( $case['contains'] as $expected ) {
				// 金額と単位は折り返し制御用の span で分かれているため、クラス名はマークアップ、表記はタグを除いた文字列で確かめる。
				$this->assertTrue( false !== strpos( $html, $expected ) || false !== strpos( wp_strip_all_tags( $html ), $expected ), $case['test_condition_name'] );
			}
			foreach ( $case['not_contains'] as $unexpected ) {
				$this->assertStringNotContainsString( $unexpected, $html, $case['test_condition_name'] );
			}
		}

		// 宿泊を使わなくなったサイトでは、泊の料金が残っていても「〜」を付けない。
		$menu_id = $this->create_menu( '宿泊プラン', array_merge( $night_meta, array( Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => 9000 ) ) );
		$this->set_site( 'custom' );
		$this->assertStringNotContainsString( '~', $this->render_menu_card_meta( $menu_id ), '宿泊を使わないサイトでは今までの表示' );
	}

	/**
	 * 管理画面のサービスメニュー一覧の料金列も、メニューカードと同じ最安＋「〜」の表記にする（#738）。
	 */
	public function test_admin_price_column_uses_same_notation_as_menu_card(): void {
		$night_meta = array(
			Service_Menu_Post_Type::META_BOOKING_UNIT => Service_Menu_Post_Type::BOOKING_UNIT_NIGHT,
			'_vkbm_base_price'                        => 7000,
		);
		$varying_id = $this->create_menu( '宿泊プラン', array_merge( $night_meta, array( Service_Menu_Post_Type::META_NIGHT_PRICE_PRE_HOLIDAY => 9000 ) ) );
		$plain_id   = $this->create_menu( '宿泊プラン（年中同じ）', $night_meta );

		ob_start();
		( new Service_Menu_Post_Type() )->render_admin_columns( 'vkbm_price', $varying_id );
		$varying_html = (string) ob_get_clean();
		ob_start();
		( new Service_Menu_Post_Type() )->render_admin_columns( 'vkbm_price', $plain_id );
		$plain_html = (string) ob_get_clean();

		$this->assertStringContainsString( '7,000~ / ', wp_strip_all_tags( $varying_html ), '日によって料金が変わるプランは最安＋「〜」' );
		$this->assertStringNotContainsString( '~', $plain_html, '年中同じ料金のプランは今までの表示' );
		$this->assertStringContainsString( '7,000', $plain_html, '年中同じ料金のプランは金額だけ' );
	}

	/**
	 * 管理画面の予約一覧の日時の列を取り出す。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return string
	 */
	private function render_admin_datetime_column( int $booking_id ): string {
		ob_start();
		( new Booking_Admin() )->render_column( 'vkbm_booking_datetime', $booking_id );

		return (string) ob_get_clean();
	}

	/**
	 * 管理画面の予約一覧は、泊の予約をチェックイン〜チェックアウトと泊数で表示し、時間枠の予約は今までと同じ。
	 */
	public function test_admin_list_column_shows_stay_range(): void {
		$menu_id = $this->create_menu( '1泊プラン' );

		$this->assertSame(
			'Check-in 2026.10.10 15:00 - Check-out 2026.10.11 10:00 (1 night)',
			$this->render_admin_datetime_column( $this->create_stay_booking( $menu_id ) )
		);

		$this->assertSame(
			'2026.10.10 10:00 - 11:00',
			$this->render_admin_datetime_column( $this->create_booking( $menu_id, '2026-10-10 10:00:00', '2026-10-10 11:00:00' ) )
		);
	}

	/**
	 * 宿泊を使わなくなったサイトでも、受付済みの泊の予約は管理画面の一覧でチェックイン〜チェックアウトのまま表示する。
	 */
	public function test_admin_list_column_keeps_stay_range_after_switching_industry(): void {
		$menu_id    = $this->create_menu( '1泊プラン' );
		$booking_id = $this->create_stay_booking( $menu_id );

		$this->set_site( 'custom' );

		$this->assertSame(
			'Check-in 2026.10.10 15:00 - Check-out 2026.10.11 10:00 (1 night)',
			$this->render_admin_datetime_column( $booking_id )
		);
	}

	/**
	 * 管理画面の予約の編集画面は、泊の予約をチェックイン〜チェックアウトで表示し、日付・時刻の入力欄は出さない。
	 */
	public function test_admin_edit_screen_shows_stay_range_without_time_inputs(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu_id = $this->create_menu( '1泊プラン' );

		$render = function ( int $booking_id ): string {
			ob_start();
			( new Booking_Admin() )->render_meta_box( get_post( $booking_id ) );

			return (string) ob_get_clean();
		};

		$html = $render( $this->create_stay_booking( $menu_id ) );
		$this->assertStringContainsString( 'Check-in 2026.10.10 15:00 - Check-out 2026.10.11 10:00 (1 night)', $html );
		$this->assertStringNotContainsString( 'name="vkbm_booking[start_time]"', $html );

		$html = $render( $this->create_booking( $menu_id, '2026-10-10 10:00:00', '2026-10-10 11:00:00' ) );
		$this->assertStringContainsString( 'name="vkbm_booking[start_time]"', $html );
		$this->assertStringNotContainsString( 'Check-in 2026', $html );
	}

	/**
	 * 泊の予約（2泊・合計 24000 円・客室の追加料金込み）を、料金区分なしで作る。
	 *
	 * @param int $menu_id メニュー ID。
	 * @param int $room_id 客室 ID。
	 * @return int 予約の投稿 ID。
	 */
	private function create_two_night_booking( int $menu_id, int $room_id ): int {
		$booking_id = $this->create_booking( $menu_id, '2026-10-10 15:00:00', '2026-10-12 10:00:00' );
		update_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, 2 );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $room_id );
		update_post_meta( $booking_id, '_vkbm_booking_guests', 2 );
		update_post_meta( $booking_id, '_vkbm_booking_service_base_price', 10000 );
		update_post_meta( $booking_id, '_vkbm_booking_base_total_price', 24000 );

		return $booking_id;
	}

	/**
	 * 管理画面の編集画面から保存する（$_POST を組み立てて save_post を呼ぶ）。
	 *
	 * @param int    $booking_id 予約の投稿 ID。
	 * @param int    $menu_id    メニュー ID。
	 * @param int    $room_id    客室 ID。
	 * @param string $status     送信するステータス。
	 */
	private function save_from_admin_screen( int $booking_id, int $menu_id, int $room_id, string $status ): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_RESERVATIONS );
		wp_set_current_user( $user_id );

		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => array(
				'date'        => '2026-10-10',
				'start_time'  => '15:00',
				'end_time'    => '16:00',
				'service_id'  => $menu_id,
				'resource_id' => $room_id,
				'guests'      => 1,
				'status'      => $status,
			),
		);
		( new Booking_Admin() )->save_post( $booking_id, get_post( $booking_id ) );
		$_POST = array();
	}

	/**
	 * 泊の予約を編集画面から保存しても、人数・基本料金合計・開始・終了は変わらない（ステータスだけ変えても同じ）。
	 */
	public function test_admin_save_keeps_stay_booking_values(): void {
		$menu_id    = $this->create_menu( '2泊プラン', array( '_vkbm_base_price' => 10000 ) );
		$room_id    = (int) $this->factory()->post->create(
			array(
				'post_type'   => \VKBookingManager\PostTypes\Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$booking_id = $this->create_two_night_booking( $menu_id, $room_id );

		$this->save_from_admin_screen( $booking_id, $menu_id, $room_id, 'pending' );

		$this->assertSame( '2026-10-10 15:00:00', get_post_meta( $booking_id, '_vkbm_booking_service_start', true ) );
		$this->assertSame( '2026-10-12 10:00:00', get_post_meta( $booking_id, '_vkbm_booking_service_end', true ) );
		$this->assertSame( '2026-10-12 10:00:00', get_post_meta( $booking_id, '_vkbm_booking_total_end', true ) );
		$this->assertSame( 24000, (int) get_post_meta( $booking_id, '_vkbm_booking_base_total_price', true ) );
		$this->assertSame( 2, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ) );
		$this->assertSame( 2, (int) get_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, true ) );
		$this->assertSame( 'pending', get_post_meta( $booking_id, '_vkbm_booking_status', true ) );
	}

	/**
	 * 泊の予約の編集画面は、人数を入力欄ではなく表示だけにする。
	 */
	public function test_admin_edit_screen_locks_guests_for_stay_booking(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu_id    = $this->create_menu( '2泊プラン' );
		$booking_id = $this->create_two_night_booking( $menu_id, 0 );

		ob_start();
		( new Booking_Admin() )->render_meta_box( get_post( $booking_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'name="vkbm_booking[guests]"', $html );
	}

	/**
	 * 料金区分なし・2泊の予約の通知メールは、保存済みの合計（基本料金 × 人数ではない）を料金に使う。
	 * 時間枠の予約は今までどおり 基本料金 × 人数。
	 */
	public function test_notification_price_uses_saved_total_for_stay_only(): void {
		$menu_id = $this->create_menu( '2泊プラン', array( '_vkbm_base_price' => 10000 ) );

		$stay_payload = $this->build_notification_payload( $this->create_two_night_booking( $menu_id, 0 ) );
		$this->assertStringContainsString( '24,000', (string) $stay_payload['price_label'] );

		$slot_id = $this->create_booking( $menu_id, '2026-10-10 10:00:00', '2026-10-10 11:00:00' );
		update_post_meta( $slot_id, '_vkbm_booking_service_base_price', 10000 );
		update_post_meta( $slot_id, '_vkbm_booking_guests', 2 );
		update_post_meta( $slot_id, '_vkbm_booking_base_total_price', 24000 );
		$slot_payload = $this->build_notification_payload( $slot_id );
		$this->assertStringContainsString( '20,000', (string) $slot_payload['price_label'] );
		$this->assertStringNotContainsString( '24,000', (string) $slot_payload['price_label'] );
	}
}
