<?php
/**
 * 空き状況の計算で DB への問い合わせ回数を減らす変更（issue #705）のテスト。
 *
 * 結果は変えずに問い合わせだけを減らす変更のため、次の3点を確認する。
 * - 月次カレンダーの空き判定が、日ごとに予約を読む従来の経路と同じ結果になること
 *   （日をまたぐ予約・月をまたぐ予約・秒の無い日時・「T」区切りの日時・キャンセル済みの予約を含む）。
 * - 複数日の予約の世代番号をまとめて読む処理が、1日ずつ読む処理と同じ値を返すこと。
 * - 問い合わせ回数が「担当者 × 日数」に比例して増えないこと。
 *
 * 月次カレンダーが複数の担当者を扱うのは Pro 版の機能のため、担当者を複数使うテストは無料版ではスキップする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use ReflectionMethod;
use VKBookingManager\Availability\Availability_Booking_Cache_Generation;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 月次カレンダー・複数日の世代番号の問い合わせ削減を検証するテスト。
 *
 * @group availability
 * @group availability-query-reduction
 */
class Availability_Query_Reduction_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前のサイトのタイムゾーン設定。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 予約の検索（WP_Query）の実行回数。posts_request フィルターで数える。
	 *
	 * @var int
	 */
	private int $booking_search_count = 0;

	/**
	 * テスト前の状態を退避し、サイトのタイムゾーンを Asia/Tokyo に固定する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings        = get_option( Settings_Repository::OPTION_KEY, false );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * 全体設定・タイムゾーンをテスト前の状態へ戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		remove_filter( 'posts_request', array( $this, 'count_booking_search' ) );
		parent::tear_down();
	}

	/**
	 * 担当者が複数使える Pro 版でなければテストをスキップする。
	 */
	private function skip_unless_pro_edition(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '担当者を複数使う月次カレンダーは Pro 版の機能のため、無料版ビルドではスキップする。' );
		}
	}

	/**
	 * 予約の検索（WP_Query）だった場合に実行回数を数える（posts_request フィルター用）。
	 *
	 * @param string $request 実行する SQL。
	 * @return string 受け取った SQL（変更しない）。
	 */
	public function count_booking_search( $request ) {
		if ( false !== strpos( (string) $request, "'" . Booking_Post_Type::POST_TYPE . "'" ) ) {
			++$this->booking_search_count;
		}

		return $request;
	}

	/**
	 * 担当者・メニュー・シフトを作る（2099年3月・4月の毎日 10:00-13:00、60分刻みで1日3枠）。
	 *
	 * @param int $staff_count 担当者の数。
	 * @return array{menu_id:int, staff_ids:array<int, int>} メニュー ID と担当者 ID。
	 */
	private function build_scenario( int $staff_count ): array {
		// サイト全体の設定。スロットの刻みは 60 分、サイト全体の予約締切は 0 時間に固定する。
		$settings                                        = ( new Settings_Repository() )->get_settings();
		$settings['staff_enabled']                       = true;
		$settings['slot_capacity_enabled']               = true;
		$settings['provider_slot_step_minutes']          = 60;
		$settings['provider_reservation_deadline_hours'] = 0;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		// 担当者とシフト。
		$staff_ids = array();
		for ( $index = 1; $index <= $staff_count; $index++ ) {
			$staff_id    = (int) $this->factory()->post->create(
				array(
					'post_type'   => Resource_Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'staff_' . $index,
				)
			);
			$staff_ids[] = $staff_id;

			foreach ( array( 3, 4 ) as $month ) {
				$days = array();
				for ( $day = 1; $day <= 31; $day++ ) {
					$days[ $day ] = array(
						'status' => 'open',
						'slots'  => array(
							array(
								'start' => '10:00',
								'end'   => '13:00',
							),
						),
					);
				}

				$shift_id = (int) $this->factory()->post->create(
					array(
						'post_type'   => Shift_Post_Type::POST_TYPE,
						'post_status' => 'publish',
					)
				);
				update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
				update_post_meta( $shift_id, '_vkbm_shift_year', 2099 );
				update_post_meta( $shift_id, '_vkbm_shift_month', $month );
				update_post_meta( $shift_id, '_vkbm_shift_days', $days );
			}
		}

		// メニュー（所要時間 60 分・バッファなし・締切なし・受付上限なし）。
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 0 );
		update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', 0 );
		update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 0 );
		update_post_meta( $menu_id, '_vkbm_staff_ids', $staff_ids );

		return array(
			'menu_id'   => $menu_id,
			'staff_ids' => $staff_ids,
		);
	}

	/**
	 * 予約を1件作る。日時はテストごとの保存形式のまま（秒無し・T 区切りも）保存する。
	 *
	 * @param int                  $staff_id 担当者 ID。
	 * @param int                  $menu_id  メニュー ID。
	 * @param string               $start    サービス開始。
	 * @param string               $end      サービス終了（枠の終了にも同じ値を使う）。
	 * @param array<string, mixed> $options  status（予約ステータス）。
	 * @return int 予約の投稿 ID。
	 */
	private function create_booking( int $staff_id, int $menu_id, string $start, string $end, array $options = array() ): int {
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
		update_post_meta( $booking_id, '_vkbm_booking_status', $options['status'] ?? 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );

		return $booking_id;
	}

	/**
	 * 日をまたぐ・月をまたぐ・秒無し・T 区切り・キャンセル済みの予約を、担当者1名ぶん作る。
	 *
	 * @param int $staff_id 担当者 ID。
	 * @param int $menu_id  メニュー ID。
	 */
	private function create_edge_case_bookings( int $staff_id, int $menu_id ): void {
		// 通常の予約（3月5日 11:00-12:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-03-05 11:00:00', '2099-03-05 12:00:00' );
		// 日をまたぐ予約（3月10日 22:00 - 3月11日 11:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-03-10 22:00:00', '2099-03-11 11:00:00' );
		// 前月末から当月初へまたがる予約（2月28日 20:00 - 3月1日 11:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-02-28 20:00:00', '2099-03-01 11:00:00' );
		// 当月末から翌月初へまたがる予約（3月31日 12:00 - 4月1日 11:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-03-31 12:00:00', '2099-04-01 11:00:00' );
		// 秒の無い日時の予約（3月15日 10:00-11:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-03-15 10:00', '2099-03-15 11:00' );
		// 「T」区切りの日時の予約（3月20日 11:00-12:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-03-20T11:00:00', '2099-03-20T12:00:00' );
		// キャンセル済みの予約は枠を消費しない（3月25日 11:00-12:00）。
		$this->create_booking( $staff_id, $menu_id, '2099-03-25 11:00:00', '2099-03-25 12:00:00', array( 'status' => 'cancelled' ) );
	}

	/**
	 * 予約の配列を、順序に依存せず比較できる文字列の配列へ整える。
	 *
	 * @param array<int, array<string, mixed>> $bookings get_bookings_for_staff_date() の戻り値。
	 * @return array<int, string> 並べ替えた文字列の配列。
	 */
	private function summarize_bookings( array $bookings ): array {
		$lines = array();
		foreach ( $bookings as $booking ) {
			$lines[] = sprintf(
				'%s|%s|%d|%d|%d',
				$booking['start']->format( 'Y-m-d H:i:s' ),
				$booking['end']->format( 'Y-m-d H:i:s' ),
				$booking['guests'],
				(int) $booking['exclusive'],
				$booking['service_id']
			);
		}
		sort( $lines );

		return $lines;
	}

	/**
	 * 月次カレンダー用の保持を、リフレクションで直接有効にして月ぶんの予約を先に読む。
	 *
	 * @param Availability_Service $service  対象のサービス。
	 * @param int                  $staff_id 担当者 ID。
	 * @param int                  $year     年。
	 * @param int                  $month    月。
	 * @param DateTimeZone         $timezone タイムゾーン。
	 */
	private function prime_month( Availability_Service $service, int $staff_id, int $year, int $month, DateTimeZone $timezone ): void {
		$reflection = new ReflectionClass( Availability_Service::class );
		$scope      = $reflection->getProperty( 'month_scope' );
		$scope->setAccessible( true );
		$scope->setValue(
			$service,
			array(
				'bookings'  => array(),
				'snapshots' => array(),
			)
		);

		$prime = new ReflectionMethod( Availability_Service::class, 'prime_bookings_for_staff_month' );
		$prime->setAccessible( true );
		$prime->invoke( $service, $staff_id, $year, $month, $timezone );
	}

	/**
	 * 月ぶんをまとめて読んだ保持分から取り出した日ごとの予約が、日ごとに問い合わせた結果と同じことを検証する。
	 */
	public function test_get_bookings_for_staff_date(): void {
		$this->skip_unless_pro_edition();

		$scenario = $this->build_scenario( 1 );
		$staff_id = $scenario['staff_ids'][0];
		$this->create_edge_case_bookings( $staff_id, $scenario['menu_id'] );
		$timezone = new DateTimeZone( 'Asia/Tokyo' );

		$test_cases = array(
			array(
				'test_condition_name' => '2099年3月（日をまたぐ・月をまたぐ・秒無し・T 区切り・キャンセル済みを含む）の全日が、日ごとの問い合わせと同じ',
				'year'                => 2099,
				'month'               => 3,
				'days'                => 31,
				'expected_non_empty'  => array( 1, 5, 10, 11, 15, 20, 31 ),
			),
			array(
				'test_condition_name' => '2099年4月（3月末から続く予約が1日にかかる）の全日が、日ごとの問い合わせと同じ',
				'year'                => 2099,
				'month'               => 4,
				'days'                => 30,
				'expected_non_empty'  => array( 1 ),
			),
			array(
				'test_condition_name' => '2099年2月（2月末から3月へまたがる予約が28日にかかる）の全日が、日ごとの問い合わせと同じ',
				'year'                => 2099,
				'month'               => 2,
				'days'                => 28,
				'expected_non_empty'  => array( 28 ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 基準: 保持なしで、日ごとに問い合わせる従来の経路。
			$baseline_service = new Availability_Service();
			// 検証対象: 月ぶんを先に読んだ保持分から取り出す経路。
			$primed_service = new Availability_Service();
			$this->prime_month( $primed_service, $staff_id, $case['year'], $case['month'], $timezone );

			$non_empty_days = array();
			for ( $day = 1; $day <= $case['days']; $day++ ) {
				$date     = sprintf( '%04d-%02d-%02d', $case['year'], $case['month'], $day );
				$expected = $this->summarize_bookings( $baseline_service->get_bookings_for_staff_date( $staff_id, $date, $timezone ) );
				$actual   = $this->summarize_bookings( $primed_service->get_bookings_for_staff_date( $staff_id, $date, $timezone ) );

				$this->assertSame( $expected, $actual, $case['test_condition_name'] . ' / ' . $date );
				if ( array() !== $expected ) {
					$non_empty_days[] = $day;
				}
			}

			// 予約のある日が想定どおり（日をまたぐ予約などが漏れていない）ことも確認する。
			$this->assertSame( $case['expected_non_empty'], $non_empty_days, $case['test_condition_name'] . ' / 予約のある日' );
		}

		// 保持した月の外の日付は、保持分を使わず日ごとの問い合わせに任せても同じ結果になる。
		$primed_service = new Availability_Service();
		$this->prime_month( $primed_service, $staff_id, 2099, 3, $timezone );
		$baseline = new Availability_Service();
		foreach ( array( '2099-02-28', '2099-04-01' ) as $outside_date ) {
			$this->assertSame(
				$this->summarize_bookings( $baseline->get_bookings_for_staff_date( $staff_id, $outside_date, $timezone ) ),
				$this->summarize_bookings( $primed_service->get_bookings_for_staff_date( $staff_id, $outside_date, $timezone ) ),
				'保持した月の外の日付も同じ結果: ' . $outside_date
			);
		}
	}

	/**
	 * 月次カレンダーの空き枠の数が、予約のある日ごとに想定どおりであることを検証する（担当者指名あり）。
	 */
	public function test_get_calendar_meta(): void {
		$this->skip_unless_pro_edition();

		$scenario = $this->build_scenario( 2 );
		$staff_id = $scenario['staff_ids'][0];
		$this->create_edge_case_bookings( $staff_id, $scenario['menu_id'] );

		// 想定: 1日3枠（10・11・12時）。予約が重なる枠だけ減る。
		$test_cases = array(
			array(
				'test_condition_name' => '2099年3月: 通常・日またぎ・月またぎ・秒無し・T 区切りの予約の日は2枠、キャンセル済みの日は3枠',
				'year'                => 2099,
				'month'               => 3,
				'expected'            => array(
					1  => 2,
					2  => 3,
					5  => 2,
					10 => 3,
					11 => 2,
					15 => 2,
					20 => 2,
					25 => 3,
					30 => 3,
					31 => 2,
				),
			),
			array(
				'test_condition_name' => '2099年4月: 3月末から続く予約の1日だけ2枠',
				'year'                => 2099,
				'month'               => 4,
				'expected'            => array(
					1 => 2,
					2 => 3,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ( new Availability_Service() )->get_calendar_meta(
				array(
					'menu_id'     => $scenario['menu_id'],
					'year'        => $case['year'],
					'month'       => $case['month'],
					'resource_id' => $staff_id,
				)
			);
			$this->assertIsArray( $result, $case['test_condition_name'] );

			$actual = array();
			foreach ( $result['days'] as $day_entry ) {
				$actual[ (int) substr( $day_entry['date'], 8, 2 ) ] = $day_entry['available_slots'];
			}
			foreach ( $case['expected'] as $day => $slots ) {
				$this->assertSame( $slots, $actual[ $day ], $case['test_condition_name'] . ' / ' . $day . '日' );
			}
		}
	}

	/**
	 * 月次カレンダーの計算が終わったら、保持した予約・スタッフ情報が破棄されることを検証する。
	 *
	 * 破棄されないと、同じリクエスト内で予約を確定した後も古い保持分で空きを判定してしまう。
	 */
	public function test_get_calendar_meta_discards_month_scope(): void {
		$this->skip_unless_pro_edition();

		$scenario = $this->build_scenario( 1 );
		$staff_id = $scenario['staff_ids'][0];
		$timezone = new DateTimeZone( 'Asia/Tokyo' );
		$service  = new Availability_Service();

		$result = $service->get_calendar_meta(
			array(
				'menu_id' => $scenario['menu_id'],
				'year'    => 2099,
				'month'   => 3,
			)
		);
		$this->assertIsArray( $result, '月次カレンダーを計算できる' );

		// 計算後は保持が破棄されている。
		$scope = ( new ReflectionClass( Availability_Service::class ) )->getProperty( 'month_scope' );
		$scope->setAccessible( true );
		$this->assertNull( $scope->getValue( $service ), '計算の終わりで保持を破棄する' );

		// 計算の後に確定した予約を、同じインスタンスの日ごとの取得がすぐ読める。
		$this->create_booking( $staff_id, $scenario['menu_id'], '2099-03-12 11:00:00', '2099-03-12 12:00:00' );
		$bookings = $service->get_bookings_for_staff_date( $staff_id, '2099-03-12', $timezone );
		$this->assertCount( 1, $bookings, '計算後に確定した予約が古い保持分に隠れない' );
	}

	/**
	 * 複数日の世代番号をまとめて読んだ値が、1日ずつ読んだ値と同じことを検証する。
	 */
	public function test_get_daily_generations(): void {
		update_option( 'vkbm_avail_bgen_d_2099-03-02', 5 );
		// autoload しない option（実運用では世代番号はこちら）。
		update_option( 'vkbm_avail_bgen_d_2099-03-03', 7, false );
		// 壊れた値は0扱い。
		update_option( 'vkbm_avail_bgen_d_2099-03-04', 'abc', false );
		update_option( 'vkbm_avail_bgen_d_2099-03-05', -3, false );

		$dates = array(
			'2099-03-01',
			'2099-03-02',
			'2099-03-03',
			'2099-03-04',
			'2099-03-05',
			'2099-03-06',
			'2099-02-30',
			'abc',
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'option キャッシュが空の状態（初回の読み込み）でも、1日ずつ読んだ値と同じ',
				'flush_cache'         => true,
			),
			array(
				'test_condition_name' => 'option キャッシュが載った状態（2回目以降）でも、1日ずつ読んだ値と同じ',
				'flush_cache'         => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['flush_cache'] ) {
				wp_cache_flush();
			}

			$actual = Availability_Booking_Cache_Generation::get_daily_generations( $dates );

			$expected = array();
			foreach ( $dates as $date ) {
				$expected[ $date ] = Availability_Booking_Cache_Generation::get_daily_generation( $date );
			}

			$this->assertSame( $expected, $actual, $case['test_condition_name'] );
		}

		// 値そのものも確認する（未作成は0、壊れた値・負の値は0、形式不正・存在しない日付は0）。
		$this->assertSame(
			array(
				'2099-03-01' => 0,
				'2099-03-02' => 5,
				'2099-03-03' => 7,
				'2099-03-04' => 0,
				'2099-03-05' => 0,
				'2099-03-06' => 0,
				'2099-02-30' => 0,
				'abc'        => 0,
			),
			Availability_Booking_Cache_Generation::get_daily_generations( $dates ),
			'未作成・壊れた値・形式不正は0、作成済みはその値'
		);

		// まとめて読んだ後に世代番号を進めても、最新の値が読める（古い値がキャッシュに残らない）。
		Availability_Booking_Cache_Generation::get_daily_generations( $dates );
		update_option( 'vkbm_avail_bgen_d_2099-03-06', 1, false );
		update_option( 'vkbm_avail_bgen_d_2099-03-02', 6 );
		$after = Availability_Booking_Cache_Generation::get_daily_generations( $dates );
		$this->assertSame( 1, $after['2099-03-06'], '読み込み前は未作成だった日の更新が反映される' );
		$this->assertSame( 6, $after['2099-03-02'], '読み込み済みの日の更新が反映される' );
	}

	/**
	 * 複数日の世代番号を読む問い合わせが、日数に比例しないことを検証する。
	 */
	public function test_get_daily_generations_query_count(): void {
		global $wpdb;

		$dates = array();
		$day   = new DateTimeImmutable( '2099-03-01', new DateTimeZone( 'UTC' ) );
		for ( $index = 0; $index < 31; $index++ ) {
			$dates[] = $day->modify( sprintf( '+%d days', $index ) )->format( 'Y-m-d' );
		}
		update_option( 'vkbm_avail_bgen_d_2099-03-10', 3, false );

		// option キャッシュが空の状態で、31日ぶんをまとめて読む。
		wp_cache_flush();
		$before = $wpdb->num_queries;
		Availability_Booking_Cache_Generation::get_daily_generations( $dates );
		$queries = $wpdb->num_queries - $before;

		// 全 option の読み込み（1回）と、まとめて読む問い合わせ（1回）だけで済む。
		$this->assertLessThanOrEqual( 2, $queries, '31日ぶんの世代番号を読む問い合わせが2回以内' );

		// 2回目は option キャッシュから読めるため、問い合わせは発生しない。
		$before = $wpdb->num_queries;
		Availability_Booking_Cache_Generation::get_daily_generations( $dates );
		$this->assertSame( 0, $wpdb->num_queries - $before, '2回目は問い合わせなし' );
	}

	/**
	 * 月次カレンダーの予約の検索が、担当者の数だけ（日数に比例せず）になることを検証する。
	 */
	public function test_get_calendar_meta_query_count(): void {
		global $wpdb;
		$this->skip_unless_pro_edition();

		add_filter( 'posts_request', array( $this, 'count_booking_search' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '担当者2名・31日: 予約の検索は2回',
				'staff_count'         => 2,
				'expected_searches'   => 2,
			),
			array(
				'test_condition_name' => '担当者4名・31日: 予約の検索は4回',
				'staff_count'         => 4,
				'expected_searches'   => 4,
			),
		);

		foreach ( $test_cases as $case ) {
			$scenario = $this->build_scenario( $case['staff_count'] );
			foreach ( $scenario['staff_ids'] as $staff_id ) {
				$this->create_edge_case_bookings( $staff_id, $scenario['menu_id'] );
			}

			$this->booking_search_count = 0;
			$before                     = $wpdb->num_queries;
			$result                     = ( new Availability_Service() )->get_calendar_meta(
				array(
					'menu_id' => $scenario['menu_id'],
					'year'    => 2099,
					'month'   => 3,
				)
			);
			$total_queries              = $wpdb->num_queries - $before;

			$this->assertIsArray( $result, $case['test_condition_name'] );
			$this->assertCount( 31, $result['days'], $case['test_condition_name'] . ' / 日数' );
			$this->assertSame( $case['expected_searches'], $this->booking_search_count, $case['test_condition_name'] );
			// 担当者 × 日数（従来は予約の検索だけでこの回数）を下回る。
			$this->assertLessThan( $case['staff_count'] * 31, $total_queries, $case['test_condition_name'] . ' / 総問い合わせ数' );
		}
	}

	/**
	 * 泊の期間の空き表示のキャッシュ名を作る問い合わせが、泊数に比例しないことを検証する。
	 */
	public function test_build_lodging_cache_key(): void {
		global $wpdb;

		$service = new Availability_Service();
		$method  = new ReflectionMethod( Availability_Service::class, 'build_lodging_cache_key' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '7泊',
				'check_out_date'      => '2099-03-08',
			),
			array(
				'test_condition_name' => '31泊',
				'check_out_date'      => '2099-04-01',
			),
		);

		foreach ( $test_cases as $case ) {
			// 世代番号の option がある日・ない日が混ざった状態で、option キャッシュを空にしてから作る。
			update_option( 'vkbm_avail_bgen_d_2099-03-02', 4, false );
			wp_cache_flush();

			$before = $wpdb->num_queries;
			$key    = $method->invoke( $service, 5, array( 1, 2 ), '2099-03-01', $case['check_out_date'], 'Asia/Tokyo' );
			$count  = $wpdb->num_queries - $before;

			// 全 option の読み込み・全体の世代番号・日ごとの世代番号のまとめ読みの3回だけで済む（泊数に関係なく同じ）。
			$this->assertLessThanOrEqual( 3, $count, $case['test_condition_name'] . ' / 問い合わせが3回以内' );

			// 世代番号が変わればキャッシュ名も変わる（キャッシュの無効化が従来どおり効く）。
			update_option( 'vkbm_avail_bgen_d_2099-03-02', 5, false );
			$changed = $method->invoke( $service, 5, array( 1, 2 ), '2099-03-01', $case['check_out_date'], 'Asia/Tokyo' );
			$this->assertNotSame( $key, $changed, $case['test_condition_name'] . ' / 世代番号の変化でキャッシュ名が変わる' );
		}
	}
}
