<?php
/**
 * 公開空き状況の回数制限・受付範囲の回帰テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Availability_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 受付範囲と共通カウンターを検証する。
 *
 * @group availability
 */
class Availability_Request_Limits_Test extends WP_UnitTestCase {
	/**
	 * 元の送信元。
	 *
	 * @var string
	 */
	private string $original_ip = '';

	/** テストの送信元と設定を用意する。 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.118';
		delete_option( Settings_Repository::OPTION_KEY );
		delete_transient( $this->counter_key() );
	}

	/** フィルターと送信元を元に戻す。 */
	protected function tearDown(): void {
		remove_filter( 'vkbm_rate_limit_enabled', '__return_true', 99 );
		delete_transient( $this->counter_key() );
		$_SERVER['REMOTE_ADDR'] = $this->original_ip;
		Industry_Presets::clear_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 共通カウンターの名前を返す。
	 *
	 * @return string カウンター名。
	 */
	private function counter_key(): string {
		return 'vkbm_rl_' . substr( sha1( 'availability_read|192.0.2.118' ), 0, 20 );
	}

	/** 4 URL が同じカウンターを使い、回復後はサービスに到達する。 */
	public function test_four_handlers_share_limit_and_recover(): void {
		add_filter( 'vkbm_rate_limit_enabled', '__return_true', 99 );
		$controller = new Availability_Controller( new Availability_Service() );
		$handlers   = array( 'handle_calendar_meta', 'handle_daily_slots', 'handle_lodging_calendar_meta', 'handle_lodging_availabilities' );
		// 上限まで使った IP は、どの窓口でもマスクされない 429 になる。
		set_transient(
			$this->counter_key(),
			array(
				'count' => 120,
				'reset' => time() + 60,
			),
			60
		);
		foreach ( $handlers as $handler ) {
			$result = $controller->$handler( new WP_REST_Request( 'GET' ) );
			$this->assertWPError( $result );
			$this->assertSame( 'rate_limited', $result->get_error_code() );
			$this->assertSame( 429, $result->get_error_data()['status'] );
		}
		// 時計の経過をカウンターの期限に反映する（実時間の sleep はしない）。
		set_transient(
			$this->counter_key(),
			array(
				'count' => 120,
				'reset' => time() - 1,
			),
			60
		);
		$result = $controller->handle_daily_slots( new WP_REST_Request( 'GET' ) );
		$this->assertNotSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 1, get_transient( $this->counter_key() )['count'] );
	}

	/** キャッシュに当たる120要求も数え、121回目を拒否する。 */
	public function test_cached_requests_consume_tokens(): void {
		add_filter( 'vkbm_rate_limit_enabled', '__return_true', 99 );
		$staff_id = self::factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array( '_vkbm_staff_ids' => array( $staff_id ) ),
			)
		);
		$request  = new WP_REST_Request( 'GET' );
		$request->set_param( 'menu_id', $menu_id );
		$request->set_param( 'date', current_datetime()->modify( '+1 day' )->format( 'Y-m-d' ) );
		$controller = new Availability_Controller( new Availability_Service() );
		for ( $i = 0; $i < 120; ++$i ) {
			$this->assertInstanceOf( \WP_REST_Response::class, $controller->handle_daily_slots( $request ) );
		}
		$this->assertSame( 'rate_limited', $controller->handle_calendar_meta( $request )->get_error_code() );
	}

	/** 代理予約の担当者を制限せず、一般ログイン利用者は制限する。 */
	public function test_only_reservation_managers_bypass_limit(): void {
		add_filter( 'vkbm_rate_limit_enabled', '__return_true', 99 );
		set_transient(
			$this->counter_key(),
			array(
				'count' => 120,
				'reset' => time() + 60,
			),
			60
		);
		$controller = new Availability_Controller( new Availability_Service() );
		$user_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->assertSame( 'rate_limited', $controller->handle_daily_slots( new WP_REST_Request( 'GET' ) )->get_error_code() );
		wp_get_current_user()->add_cap( Capabilities::MANAGE_RESERVATIONS );
		$this->assertNotSame( 'rate_limited', $controller->handle_daily_slots( new WP_REST_Request( 'GET' ) )->get_error_code() );
	}

	/** 未保存だけ365日とし、明示した0は無制限のままにする。 */
	public function test_unsaved_default_and_explicit_zero(): void {
		$repository = new Settings_Repository();
		$this->assertSame( 365, $repository->get_settings()['provider_max_advance_booking_days'] );
		update_option( Settings_Repository::OPTION_KEY, array( 'provider_max_advance_booking_days' => 0 ) );
		$this->assertSame( 0, $repository->get_settings()['provider_max_advance_booking_days'] );
	}

	/** 範囲外・過去の月と日では空の成功応答となり、キャッシュを読み書きしない。 */
	public function test_outside_range_never_accesses_availability_transients(): void {
		$staff_id = self::factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$menu_id  = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array( '_vkbm_staff_ids' => array( $staff_id ) ),
			)
		);
		$service  = new Availability_Service();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- 保存件数の増加をキャッシュなしで検証する。
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_vkbm_calendar_%' OR option_name LIKE '_transient_vkbm_daily_%'" );
		foreach ( array( '-2 months', '+2 years' ) as $offset ) {
			$date  = current_datetime()->modify( $offset );
			$month = $service->get_calendar_meta(
				array(
					'menu_id' => $menu_id,
					'year'    => (int) $date->format( 'Y' ),
					'month'   => (int) $date->format( 'n' ),
				)
			);
			$this->assertIsArray( $month );
			$this->assertSame( array(), $month['days'] );
			$this->assertFalse( $month['booking_range']['in_range'] );
			$daily = $service->get_daily_slots(
				array(
					'menu_id' => $menu_id,
					'date'    => $date->format( 'Y-m-d' ),
				)
			);
			$this->assertSame( array(), $daily['slots'] );
			$this->assertFalse( $daily['booking_range']['in_range'] );
		}
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_vkbm_calendar_%' OR option_name LIKE '_transient_vkbm_daily_%'" ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		// メニューに保存した0は、全体の365日より優先する。
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', '0' );
		$this->assertSame( 0, $service->get_menu_settings( get_post( $menu_id ) )['max_advance_days'] );
		$month = $service->get_calendar_meta(
			array(
				'menu_id' => $menu_id,
				'year'    => (int) $date->format( 'Y' ),
				'month'   => (int) $date->format( 'n' ),
			)
		);
		$this->assertTrue( $month['booking_range']['in_range'] );
		$this->assertNotEmpty( $month['days'] );
	}
}
