<?php
/**
 * 貸し切り予約（予約が入ったら受付停止）機能のテスト。
 *
 * 設定ONのメニューで予約が1件入ると、残り枠があってもその時間帯の受付を停止する（#304）。
 * 判定はすべて Availability_Service::slot_has_exclusive_booking() を通す。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function get_option;
use function update_option;
use function update_post_meta;
use function wp_date;

/**
 * 貸し切り予約機能のテスト。
 *
 * @group availability
 * @group exclusive
 */
class Exclusive_Booking_Test extends WP_UnitTestCase {

	/** @var string */
	private $original_timezone_string = '';

	protected function setUp(): void {
		parent::setUp();
		// ISO8601(+09:00) と保存形式（サイトTZの Y-m-d H:i:s）を一致させるため Asia/Tokyo に固定する。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 複数人予約を利用可能な状態（指名OFF・複数人予約ON）にする。
	 */
	private function enable_multiple_guests_context(): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * slot_has_exclusive_booking の重複判定（貸し切り予約があれば true）を検証する。
	 *
	 * 判定スロットは 10:00-11:00 固定。各ケースの予約リストに対し、
	 * 貸し切り（exclusive=true）かつ重複する予約があれば true を返すことを確認する。
	 */
	public function test_slot_has_exclusive_booking(): void {
		$tz      = new DateTimeZone( 'Asia/Tokyo' );
		$service = new Availability_Service();
		$start   = new DateTimeImmutable( '2026-04-01 10:00:00', $tz );
		$end     = new DateTimeImmutable( '2026-04-01 11:00:00', $tz );

		$test_cases = array(
			array(
				'test_condition_name' => '重複する貸し切り予約1件がある場合 => true（正常系）',
				'bookings'            => array(
					array(
						'start'     => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
						'end'       => new DateTimeImmutable( '2026-04-01 11:30:00', $tz ),
						'exclusive' => true,
					),
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '重複する予約はあるが貸し切りでない場合 => false（正常系・回帰防止）',
				'bookings'            => array(
					array(
						'start'     => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
						'end'       => new DateTimeImmutable( '2026-04-01 11:30:00', $tz ),
						'exclusive' => false,
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '貸し切り予約はあるが時間帯が重複しない場合 => false（境界値）',
				'bookings'            => array(
					array(
						'start'     => new DateTimeImmutable( '2026-04-01 11:00:00', $tz ),
						'end'       => new DateTimeImmutable( '2026-04-01 12:00:00', $tz ),
						'exclusive' => true,
					),
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約リストが空の場合 => false（境界値）',
				'bookings'            => array(),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'exclusive キー未設定の予約のみの場合 => false（後方互換・既存予約は専有しない）',
				'bookings'            => array(
					array(
						'start' => new DateTimeImmutable( '2026-04-01 10:30:00', $tz ),
						'end'   => new DateTimeImmutable( '2026-04-01 11:30:00', $tz ),
					),
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $service->slot_has_exclusive_booking( $start, $end, $case['bookings'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * get_bookings_for_staff_date が exclusive キーを返し、キャンセル/無断キャンセルを除外することを検証する。
	 *
	 * 貸し切り予約をキャンセルすると枠が再び空く（exclusive 予約が集計から消える）ことの根拠となる。
	 */
	public function test_get_bookings_for_staff_date(): void {
		$tz       = new DateTimeZone( 'Asia/Tokyo' );
		$service  = new Availability_Service();
		$staff_id = $this->create_staff();
		$menu_id  = $this->create_menu();

		$test_cases = array(
			array(
				'test_condition_name' => 'publish・confirmed の貸し切り予約 => exclusive=true で1件返る（正常系）',
				'status'              => 'confirmed',
				'exclusive_meta'      => true,
				'expected_count'      => 1,
				'expected_exclusive'  => true,
			),
			array(
				'test_condition_name' => 'publish・confirmed の通常予約 => exclusive=false で1件返る（正常系）',
				'status'              => 'confirmed',
				'exclusive_meta'      => false,
				'expected_count'      => 1,
				'expected_exclusive'  => false,
			),
			array(
				'test_condition_name' => 'キャンセルされた貸し切り予約 => 集計から除外され0件（境界値・再受付可能）',
				'status'              => 'cancelled',
				'exclusive_meta'      => true,
				'expected_count'      => 0,
				'expected_exclusive'  => null,
			),
			array(
				'test_condition_name' => '無断キャンセル（no_show）の貸し切り予約 => 集計から除外され0件（境界値・再受付可能）',
				'status'              => 'no_show',
				'exclusive_meta'      => true,
				'expected_count'      => 0,
				'expected_exclusive'  => null,
			),
		);

		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'get_bookings_for_staff_date' );
		$method->setAccessible( true );

		foreach ( $test_cases as $index => $case ) {
			// 各ケースで個別のスタッフ・日付を使い、予約キャッシュ・他ケースの干渉を避ける。
			$staff_id = $this->create_staff();
			$date     = sprintf( '2026-05-%02d', $index + 1 );

			$booking_id = $this->create_booking_post(
				$staff_id,
				$menu_id,
				$date . ' 10:00:00',
				$date . ' 11:00:00',
				$case['status']
			);
			if ( $case['exclusive_meta'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
			}

			$bookings = $method->invoke( $service, $staff_id, $date, $tz );

			$this->assertCount( $case['expected_count'], $bookings, $case['test_condition_name'] );
			if ( null !== $case['expected_exclusive'] ) {
				$this->assertArrayHasKey( 'exclusive', $bookings[0], $case['test_condition_name'] );
				$this->assertSame( $case['expected_exclusive'], $bookings[0]['exclusive'], $case['test_condition_name'] );
			}
		}
	}

	/**
	 * get_daily_slots で、貸し切り予約のある枠が remaining=0・exclusive_closed=true になることを検証する。
	 *
	 * - 貸し切り予約あり => 残席があっても remaining=0 / exclusive_closed=true（受付停止）。
	 * - 同条件で通常予約（貸し切りでない）=> exclusive_closed は立たず remaining>0（回帰防止）。
	 * - 貸し切り予約をキャンセル => 再び remaining>0・exclusive_closed=false（再受付可能）。
	 */
	public function test_get_daily_slots(): void {
		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_multiple_guests_context();

		$tz   = new DateTimeZone( 'Asia/Tokyo' );
		$date = '2026-06-10';

		$test_cases = array(
			array(
				'test_condition_name' => '貸し切り予約あり => 残席があっても remaining=0・exclusive_closed=true（正常系）',
				'exclusive_meta'      => true,
				'status'              => 'confirmed',
				'expect_closed'       => true,
			),
			array(
				'test_condition_name' => '通常予約（貸し切りでない）=> exclusive_closed は立たない（回帰防止）',
				'exclusive_meta'      => false,
				'status'              => 'confirmed',
				'expect_closed'       => false,
			),
			array(
				'test_condition_name' => '貸し切り予約だがキャンセル済み => exclusive_closed は立たない（再受付可能・境界値）',
				'exclusive_meta'      => true,
				'status'              => 'cancelled',
				'expect_closed'       => false,
			),
			array(
				'test_condition_name' => '貸し切り予約だが無断キャンセル（no_show）=> exclusive_closed は立たない（再受付可能・境界値）',
				'exclusive_meta'      => true,
				'status'              => 'no_show',
				'expect_closed'       => false,
			),
		);

		// 予約締切・過去日フィルタに掛からないよう、十分先の未来日（来年）を使う。
		$future_year = (int) wp_date( 'Y' ) + 1;

		foreach ( $test_cases as $index => $case ) {
			// ケースごとにメニュー・スタッフ・日付を分離する。
			$staff_id = $this->create_staff();
			$menu_id  = $this->create_menu();
			$day      = 10 + $index;
			$slot_day = sprintf( '%04d-12-%02d', $future_year, $day );

			// メニュー設定：複数人予約ON・最大受付数3・スタッフ割当（残席を作るため）。
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
			update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

			// スタッフのシフト（当日 10:00-12:00 営業）を作成する。
			$this->create_shift( $staff_id, $future_year, 12, $day, '10:00', '12:00' );

			// 10:00-11:00 に予約を1件作成する（残席2あり）。
			$booking_id = $this->create_booking_post(
				$staff_id,
				$menu_id,
				$slot_day . ' 10:00:00',
				$slot_day . ' 11:00:00',
				$case['status']
			);
			update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
			if ( $case['exclusive_meta'] ) {
				update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
			}

			$service = new Availability_Service();
			$result  = $service->get_daily_slots(
				array(
					'menu_id'  => $menu_id,
					'date'     => $slot_day,
					'timezone' => 'Asia/Tokyo',
				)
			);

			$this->assertIsArray( $result, $case['test_condition_name'] );
			$slots = $result['slots'] ?? array();
			$this->assertNotEmpty( $slots, $case['test_condition_name'] . ' / スロットが生成されるべき' );

			// 10:00 開始のスロットを探す。
			$target = null;
			foreach ( $slots as $slot ) {
				if ( isset( $slot['start_at'] ) && str_contains( (string) $slot['start_at'], $slot_day . 'T10:00:00' ) ) {
					$target = $slot;
					break;
				}
			}
			$this->assertNotNull( $target, $case['test_condition_name'] . ' / 10:00 のスロットが存在するべき' );

			if ( $case['expect_closed'] ) {
				$this->assertTrue( ! empty( $target['exclusive_closed'] ), $case['test_condition_name'] );
				$this->assertSame( 0, (int) $target['remaining'], $case['test_condition_name'] . ' / 受付停止時 remaining=0' );
			} else {
				$this->assertEmpty( $target['exclusive_closed'] ?? false, $case['test_condition_name'] );
				$this->assertGreaterThan( 0, (int) $target['remaining'], $case['test_condition_name'] . ' / 受付可能時 remaining>0' );
			}
		}
	}

	/**
	 * 貸し切り判定が「当該メニューの貸し切り予約のみ」を対象にすることを検証する（#7・実バグ回帰防止）。
	 *
	 * 同一スタッフが担当するメニューA・メニューBで、メニューAに貸し切り予約が入っているとき、
	 * - メニューA の同時間帯スロットは exclusive_closed=true（受付停止）。
	 * - メニューB の同時間帯スロットは exclusive_closed が立たず remaining>0（別メニューの貸し切りに影響されない）。
	 *
	 * service_id フィルタを外す（メニュー横断で閉じる）と、メニューB のアサートが FAIL する。
	 */
	public function test_slot_has_exclusive_booking_is_menu_scoped(): void {
		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->enable_multiple_guests_context();

		$future_year = (int) wp_date( 'Y' ) + 1;
		$day         = 20;
		$slot_day    = sprintf( '%04d-12-%02d', $future_year, $day );

		// 1名のスタッフが2つのメニュー（A・B）を担当する。
		$staff_id = $this->create_staff();
		$menu_a   = $this->create_menu();
		$menu_b   = $this->create_menu();

		foreach ( array( $menu_a, $menu_b ) as $menu_id ) {
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
			update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		}

		// スタッフのシフト（当日 10:00-12:00 営業）を作成する。
		$this->create_shift( $staff_id, $future_year, 12, $day, '10:00', '12:00' );

		// メニューA に貸し切り予約を1件投入する（10:00-11:00）。
		$booking_id = $this->create_booking_post(
			$staff_id,
			$menu_a,
			$slot_day . ' 10:00:00',
			$slot_day . ' 11:00:00',
			'confirmed'
		);
		update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
		update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );

		$service = new Availability_Service();

		// メニューA・B それぞれの 10:00 スロットの exclusive_closed を確認する。
		$cases = array(
			array(
				'menu_id'       => $menu_a,
				'expect_closed' => true,
				'label'         => 'メニューA（貸し切り予約のあるメニュー）=> 受付停止',
			),
			array(
				'menu_id'       => $menu_b,
				'expect_closed' => false,
				'label'         => 'メニューB（別メニュー）=> メニューAの貸し切りに影響されず受付可能',
			),
		);

		foreach ( $cases as $case ) {
			$result = $service->get_daily_slots(
				array(
					'menu_id'  => $case['menu_id'],
					'date'     => $slot_day,
					'timezone' => 'Asia/Tokyo',
				)
			);
			$slots  = $result['slots'] ?? array();
			$this->assertNotEmpty( $slots, $case['label'] . ' / スロットが生成されるべき' );

			$target = null;
			foreach ( $slots as $slot ) {
				if ( isset( $slot['start_at'] ) && str_contains( (string) $slot['start_at'], $slot_day . 'T10:00:00' ) ) {
					$target = $slot;
					break;
				}
			}
			$this->assertNotNull( $target, $case['label'] . ' / 10:00 のスロットが存在するべき' );

			if ( $case['expect_closed'] ) {
				$this->assertTrue( ! empty( $target['exclusive_closed'] ), $case['label'] );
				$this->assertSame( 0, (int) $target['remaining'], $case['label'] . ' / remaining=0' );
			} else {
				$this->assertEmpty( $target['exclusive_closed'] ?? false, $case['label'] );
				$this->assertGreaterThan( 0, (int) $target['remaining'], $case['label'] . ' / remaining>0' );
			}
		}
	}

	/**
	 * スタッフ（リソース）を作成する。
	 *
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * サービスメニューを作成する。
	 *
	 * @return int メニュー投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 予約投稿を作成する。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start    開始日時（Y-m-d H:i:s）。
	 * @param string $end      終了日時（Y-m-d H:i:s）。
	 * @param string $status   予約ステータス。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_post( int $staff_id, int $menu_id, string $start, string $end, string $status ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', $status );

		return $booking_id;
	}

	/**
	 * スタッフのシフト投稿を作成する（指定日に1スロット営業）。
	 *
	 * @param int    $staff_id 担当スタッフID。
	 * @param int    $year     年。
	 * @param int    $month    月。
	 * @param int    $day      日。
	 * @param string $start    営業開始（HH:MM）。
	 * @param string $end      営業終了（HH:MM）。
	 * @return int シフト投稿ID。
	 */
	private function create_shift( int $staff_id, int $year, int $month, int $day, string $start, string $end ): int {
		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta(
			$shift_id,
			'_vkbm_shift_days',
			array(
				$day => array(
					'status' => 'open',
					'slots'  => array(
						array(
							'start' => $start,
							'end'   => $end,
						),
					),
				),
			)
		);

		return $shift_id;
	}
}
