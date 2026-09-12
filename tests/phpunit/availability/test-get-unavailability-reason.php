<?php
/**
 * Availability_Service::get_unavailability_reason() のテスト（#411）。
 *
 * 表示中の月に予約可能な日が1件も無い場合の管理者向け診断理由が、
 * 植草さんの優先順位表（P1〜P9）どおりに1件だけ返ることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function current_datetime;
use function update_option;
use function update_post_meta;

/**
 * Availability_Service::get_unavailability_reason() のテストクラス。
 *
 * @group availability
 * @group unavailability-reason
 */
class Get_Unavailability_Reason_Test extends WP_UnitTestCase {

	/**
	 * 元のサイトタイムゾーン文字列（テスト後に復元する）。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 予約締切・上限日数の相対計算をブレさせないため、サイトTZをAsia/Tokyoに固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * サイトタイムゾーンを元に戻し、指名機能の静的キャッシュをクリアする。
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * P1〜P9の各原因が単独で発生した場合に、対応する理由コードが1件だけ返ることを検証する。
	 *
	 * 各ケースはメニュー・スタッフ・シフトの前提条件をそれぞれ用意してから
	 * get_unavailability_reason() を呼び出し、返る code を突き合わせる。
	 */
	public function test_get_unavailability_reason(): void {
		// 判定対象の月は「実行日から2ヶ月後」に固定する（P6/P8のような相対日数系の設定と
		// 混線しないよう、既定値（0=制限なし）のケースでは常に広く先の月を使う）。
		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$test_cases = array(
			array(
				'test_condition_name' => '担当スタッフが1人も設定されていない場合 => staff_not_configured（P1）',
				'setup'               => 'no_staff',
				'expected_code'       => 'staff_not_configured',
			),
			array(
				'test_condition_name' => '担当スタッフはいるが、指名したスタッフがメニューの担当に含まれない場合 => staff_not_assigned（P4）',
				'setup'               => 'staff_not_assigned',
				'expected_code'       => 'staff_not_assigned',
			),
			array(
				'test_condition_name' => 'メニューが下書き（非公開）の場合 => menu_not_bookable（P3）',
				'setup'               => 'menu_draft',
				'expected_code'       => 'menu_not_bookable',
			),
			array(
				'test_condition_name' => 'メニューがアーカイブ済みの場合 => menu_not_bookable（P3）',
				'setup'               => 'menu_archived',
				'expected_code'       => 'menu_not_bookable',
			),
			array(
				'test_condition_name' => 'メニューがオンライン予約対象外の場合 => menu_not_bookable（P3）',
				'setup'               => 'menu_offline',
				'expected_code'       => 'menu_not_bookable',
			),
			array(
				'test_condition_name' => '予約可能日設定（日付指定）が表示月と一致しない場合 => reservation_day_mismatch（P5）',
				'setup'               => 'day_mismatch',
				'expected_code'       => 'reservation_day_mismatch',
			),
			array(
				'test_condition_name' => '予約受付上限日数が短く表示月が範囲外の場合 => max_advance_days_exceeded（P6）',
				'setup'               => 'max_advance_exceeded',
				'expected_code'       => 'max_advance_days_exceeded',
			),
			array(
				'test_condition_name' => '定休日設定で表示月が全休の場合 => all_days_closed（P7）',
				'setup'               => 'all_days_closed',
				'expected_code'       => 'all_days_closed',
			),
			array(
				'test_condition_name' => '勤務時間は登録されているがメニューの所要時間に足りない場合 => shift_too_short_for_duration（P2）',
				'setup'               => 'shift_too_short',
				'expected_code'       => 'shift_too_short_for_duration',
			),
			array(
				'test_condition_name' => '予約受付締切が長すぎて近い日程の枠が全て受付終了している場合 => deadline_hours_too_long（P8）',
				'setup'               => 'deadline_too_long',
				'expected_code'       => 'deadline_hours_too_long',
			),
			array(
				'test_condition_name' => '予約可能な枠がある場合 => 診断理由なし（null。正常系）',
				'setup'               => 'bookable',
				'expected_code'       => null,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( 'staff_not_assigned' === $case['setup'] && Pro_Upsell::is_free_edition() ) {
				// 無料版ではスタッフ制限チェック自体がスキップされるため、この原因は発生しない。
				continue;
			}

			list( $menu_id, $preferred_staff_id ) = $this->build_scenario( $case['setup'], $year, $month );

			$service = new Availability_Service();
			$reason  = $service->get_unavailability_reason(
				array(
					'menu_id'     => $menu_id,
					'resource_id' => $preferred_staff_id,
					'year'        => $year,
					'month'       => $month,
					'timezone'    => 'Asia/Tokyo',
				)
			);

			if ( null === $case['expected_code'] ) {
				$this->assertNull( $reason, $case['test_condition_name'] );
			} else {
				$this->assertIsArray( $reason, $case['test_condition_name'] );
				$this->assertSame( $case['expected_code'], $reason['code'] ?? null, $case['test_condition_name'] );
				$this->assertNotEmpty( $reason['message'] ?? '', $case['test_condition_name'] . ' / message は空であってはならない' );
			}

			Staff_Editor::clear_nomination_enabled_cache();
		}
	}

	/**
	 * P1（担当スタッフ0件）と P3（メニューが非公開）が同時に成立する場合、
	 * 優先順位表どおり P1（staff_not_configured）が返ることを検証する（回帰防止）。
	 *
	 * validate_menu() はメニュー公開状態のチェックを先に行い早期returnするが、
	 * 診断（get_unavailability_reason）はより根本的な原因であるP1を優先しなければならない。
	 */
	public function test_priority_prefers_staff_not_configured_over_menu_not_public(): void {
		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		// メニューを下書き（非公開）にし、かつ担当スタッフも未設定にする。
		$menu_id = $this->create_menu( 'draft' );

		$service = new Availability_Service();
		$reason  = $service->get_unavailability_reason(
			array(
				'menu_id'  => $menu_id,
				'year'     => $year,
				'month'    => $month,
				'timezone' => 'Asia/Tokyo',
			)
		);

		$this->assertIsArray( $reason );
		$this->assertSame( 'staff_not_configured', $reason['code'], 'P1はP3より優先されるべき（根本原因を優先する仕様）' );
	}

	/**
	 * P9: 生成されたスロットはあるが、貸し切り予約により表示月の全日が受付停止になっている場合、
	 * exclusive_closed が返ることを検証する。
	 *
	 * 貸し切り予約は Pro 版限定機能のため、無料版ビルドではスキップする。
	 */
	public function test_get_unavailability_reason_exclusive_closed(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$staff_id = $this->create_staff();
		$menu_id  = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );

		// 勤務時間を10:00-11:00の1枠のみにし、候補スロットを1日1件に絞る。
		$this->create_full_month_shift(
			$staff_id,
			$year,
			$month,
			'open',
			array(
				array(
					'start' => '10:00',
					'end'   => '11:00',
				),
			)
		);

		// #411 PR #414 レビュー指摘: このPRが src 側で撤去したのと同じタイムゾーン依存パターン
		// （`wp_date( 't', gmmktime(...) )` はサイトTZがUTCより マイナス側の場合に前月の日数を
		// 返しうる）をテストへ再度持ち込まないよう、Availability_Service::days_in_month() と
		// 同じ DateTimeImmutable ベースの算出に揃える。
		$days_in_month = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );

		// 表示月の全日、その1枠に貸し切り予約を入れて受付停止にする。
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$slot_day   = sprintf( '%04d-%02d-%02d', $year, $month, $day );
			$booking_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Booking_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $booking_id, '_vkbm_booking_service_start', $slot_day . ' 10:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_service_end', $slot_day . ' 11:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_total_end', $slot_day . ' 11:00:00' );
			update_post_meta( $booking_id, '_vkbm_booking_resource_id', $staff_id );
			update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
			update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
			update_post_meta( $booking_id, '_vkbm_booking_guests', 1 );
			update_post_meta( $booking_id, '_vkbm_booking_exclusive', true );
		}

		$service = new Availability_Service();
		$reason  = $service->get_unavailability_reason(
			array(
				'menu_id'  => $menu_id,
				'year'     => $year,
				'month'    => $month,
				'timezone' => 'Asia/Tokyo',
			)
		);

		$this->assertIsArray( $reason );
		$this->assertSame( 'exclusive_closed', $reason['code'] );
	}

	/**
	 * シナリオごとの前提条件を組み立て、[menu_id, preferred_staff_id] を返す。
	 *
	 * @param string $setup シナリオ識別子。
	 * @param int    $year  対象年。
	 * @param int    $month 対象月。
	 * @return array{0:int, 1:int} [menu_id, preferred_staff_id（0=指名なし）]
	 */
	private function build_scenario( string $setup, int $year, int $month ): array {
		switch ( $setup ) {
			case 'no_staff':
				$menu_id = $this->create_menu();
				return array( $menu_id, 0 );

			case 'staff_not_assigned':
				$assigned_staff = $this->create_staff();
				$other_staff    = $this->create_staff();
				$menu_id        = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $assigned_staff ) );
				$this->create_full_month_shift(
					$assigned_staff,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, $other_staff );

			case 'menu_draft':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu( 'draft' );
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'menu_archived':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				update_post_meta( $menu_id, '_vkbm_is_archived', '1' );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'menu_offline':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				update_post_meta( $menu_id, '_vkbm_online_unavailable', '1' );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'day_mismatch':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				update_post_meta( $menu_id, '_vkbm_reservation_day_type', 'custom_date' );
				// 表示月とは無関係な過去の1日だけを許可日にする → 表示月はどの日も一致しない。
				update_post_meta(
					$menu_id,
					'_vkbm_reservation_custom_weekdays',
					array()
				);
				update_post_meta(
					$menu_id,
					'_vkbm_reservation_custom_dates',
					array(
						array(
							'type' => 'single',
							'date' => '2000-01-01',
						),
					)
				);
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'max_advance_exceeded':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				// 3日先までしか予約を受け付けない設定にする。判定対象月は+2ヶ月後のため必ず範囲外になる。
				update_post_meta( $menu_id, '_vkbm_max_advance_booking_days', 3 );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'all_days_closed':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				// 表示月の全日を定休日にする（スロットなし）。
				$this->create_full_month_shift( $staff_id, $year, $month, 'regular_holiday', array() );
				return array( $menu_id, 0 );

			case 'shift_too_short':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				// メニューの所要時間60分に対し、勤務時間はわずか10分（前後作業なし）しかない。
				update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '10:00',
							'end'   => '10:10',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'deadline_too_long':
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
				// 締切を非常に長く（約11年）設定し、判定対象月（+2ヶ月後）の枠が全て受付終了扱いになるようにする。
				update_post_meta( $menu_id, '_vkbm_reservation_deadline_hours', 100000 );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );

			case 'bookable':
			default:
				$staff_id = $this->create_staff();
				$menu_id  = $this->create_menu();
				update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );
				update_post_meta( $menu_id, '_vkbm_duration_minutes', 60 );
				$this->create_full_month_shift(
					$staff_id,
					$year,
					$month,
					'open',
					array(
						array(
							'start' => '09:00',
							'end'   => '18:00',
						),
					)
				);
				return array( $menu_id, 0 );
		}
	}

	/**
	 * スタッフ（リソース）投稿を作成する。
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
	 * サービスメニュー投稿を作成する。
	 *
	 * @param string $post_status 投稿ステータス。
	 * @return int メニュー投稿ID。
	 */
	private function create_menu( string $post_status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => $post_status,
			)
		);
	}

	/**
	 * 対象月の全日について、同一ステータス・同一スロット構成のシフトを作成する。
	 *
	 * @param int                               $staff_id スタッフID。
	 * @param int                               $year     年。
	 * @param int                               $month    月。
	 * @param string                            $status   1日ごとのステータス（open / regular_holiday 等）。
	 * @param array<int, array<string, string>> $slots    1日ごとの勤務スロット（start/end のペア）。
	 * @return int シフト投稿ID。
	 */
	private function create_full_month_shift( int $staff_id, int $year, int $month, string $status, array $slots ): int {
		// #411 PR #414 レビュー指摘: このPRが src 側で撤去したのと同じタイムゾーン依存パターン
		// （`wp_date( 't', gmmktime(...) )` はサイトTZがUTCより マイナス側の場合に前月の日数を
		// 返しうる）をテストへ再度持ち込まないよう、Availability_Service::days_in_month() と
		// 同じ DateTimeImmutable ベースの算出に揃える。
		$days_in_month = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );

		$days = array();
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$days[ $day ] = array(
				'status' => $status,
				'slots'  => $slots,
			);
		}

		$shift_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $shift_id, '_vkbm_shift_resource_id', $staff_id );
		update_post_meta( $shift_id, '_vkbm_shift_year', $year );
		update_post_meta( $shift_id, '_vkbm_shift_month', $month );
		update_post_meta( $shift_id, '_vkbm_shift_days', $days );

		return $shift_id;
	}
}
