<?php
/**
 * シフト・予約表の宿泊予約表示データのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use DateTimeImmutable;
use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\Bookings\Lodging_Booking;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 宿泊予約を日ごとの表示データへ展開する処理を検証する。
 *
 * @group admin
 * @group lodging
 */
class Shift_Dashboard_Lodging_Stays_Test extends WP_UnitTestCase {

	/**
	 * 元の設定。
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * 宿泊モードとサイトタイムゾーンを設定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		$repository              = new Settings_Repository();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_default_settings(), array( 'industry_preset' => 'lodging' ) ) );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * 設定を復元する。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		parent::tearDown();
	}

	/**
	 * 表示検証用の予約を作成する。
	 *
	 * @param string $start       開始日時。
	 * @param string $end         終了日時。
	 * @param int    $nights      泊数。
	 * @param int    $resource_id 客室ID。
	 * @param string $status      予約状態。
	 * @param string $total_end   バッファ込み終了日時。省略時は終了日時と同じ。
	 * @return int 予約ID。
	 */
	private function create_booking( string $start, string $end, int $nights, int $resource_id = 10, string $status = 'confirmed', ?string $total_end = null ): int {
		$booking_id = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_start', $start );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', $end );
		update_post_meta( $booking_id, '_vkbm_booking_total_end', $total_end ?? $end );
		update_post_meta( $booking_id, '_vkbm_booking_resource_id', $resource_id );
		update_post_meta( $booking_id, '_vkbm_booking_status', $status );
		update_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, $nights );
		return $booking_id;
	}

	/**
	 * 共通の表示データ組み立てを呼び出す。
	 *
	 * @param string $start 期間開始日。
	 * @param string $end   期間終了日。
	 * @return array<string, array<int, array<string, mixed>>> 表示データ。
	 */
	private function get_occurrences( string $start, string $end ): array {
		$method = new ReflectionMethod( Shift_Dashboard_Page::class, 'get_booking_occurrences_for_range' );
		$method->setAccessible( true );
		return $method->invoke( new Shift_Dashboard_Page(), new DateTimeImmutable( $start . ' 00:00:00', wp_timezone() ), new DateTimeImmutable( $end . ' 23:59:59', wp_timezone() ) );
	}

	/**
	 * 1泊・3泊・月またぎ・年またぎを IN／滞在中／OUT へ展開する。
	 */
	public function test_get_booking_occurrences_for_range(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1泊はINとOUTだけになる',
				'start'               => '2026-10-14',
				'end'                 => '2026-10-15',
				'nights'              => 1,
				'expected'            => array( 'in', 'out' ),
			),
			array(
				'test_condition_name' => '3泊は中日2日を含む',
				'start'               => '2026-10-14',
				'end'                 => '2026-10-17',
				'nights'              => 3,
				'expected'            => array( 'in', 'stay', 'stay', 'out' ),
			),
			array(
				'test_condition_name' => '月またぎでも全日を展開する',
				'start'               => '2026-10-30',
				'end'                 => '2026-11-02',
				'nights'              => 3,
				'expected'            => array( 'in', 'stay', 'stay', 'out' ),
			),
			array(
				'test_condition_name' => '年またぎでも全日を展開する',
				'start'               => '2026-12-30',
				'end'                 => '2027-01-02',
				'nights'              => 3,
				'expected'            => array( 'in', 'stay', 'stay', 'out' ),
			),
		);
		foreach ( $test_cases as $case ) {
			$booking_id = $this->create_booking( $case['start'] . ' 15:00:00', $case['end'] . ' 10:00:00', $case['nights'] );
			$actual     = array_map( static fn( array $rows ): string => (string) $rows[0]['stay_type'], $this->get_occurrences( $case['start'], $case['end'] ) );
			$this->assertSame( $case['expected'], array_values( $actual ), $case['test_condition_name'] );
			wp_delete_post( $booking_id, true );
		}
	}

	/**
	 * 同じ客室の OUT と次の予約の IN を別々に残し、予約状態も維持する。
	 */
	public function test_get_booking_occurrences_for_range_keeps_turnover_and_statuses(): void {
		$out_id = $this->create_booking( '2026-10-14 15:00:00', '2026-10-15 10:00:00', 1, 20, 'cancelled' );
		$in_id  = $this->create_booking( '2026-10-15 15:00:00', '2026-10-16 10:00:00', 1, 20, 'pending' );
		$rows   = $this->get_occurrences( '2026-10-15', '2026-10-15' )['2026-10-15'];
		$this->assertSame( array( $out_id, $in_id ), array_column( $rows, 'post_id' ), 'OUTとINが別予約として並ぶこと' );
		$this->assertSame( array( 'out', 'in' ), array_column( $rows, 'stay_type' ), 'OUTがINより先に並ぶこと' );
		$this->assertSame( array( 'cancelled', 'pending' ), array_column( $rows, 'status' ), 'キャンセル済み・仮予約の状態を変えないこと' );
	}

	/**
	 * 宿泊以外では日をまたぐ時間枠予約を翌日に展開しない。
	 */
	public function test_get_booking_occurrences_for_range_keeps_standard_behavior(): void {
		$repository = new Settings_Repository();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_default_settings(), array( 'industry_preset' => 'custom' ) ) );
		$this->create_booking( '2026-10-14 23:00:00', '2026-10-15 01:00:00', 0 );
		$this->assertArrayNotHasKey( '2026-10-15', $this->get_occurrences( '2026-10-15', '2026-10-15' ), '翌日には表示しないこと' );
	}

	/**
	 * 表示期間の端で宿泊を切り詰め、期間外の時間枠を除外する。
	 */
	public function test_get_booking_occurrences_for_range_clips_to_range(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '10月30日IN・11月2日OUTを11月だけ取得 => 11月1日stay・2日out',
				'start'               => '2026-10-30 15:00:00',
				'end'                 => '2026-11-02 10:00:00',
				'nights'              => 3,
				'expected'            => array(
					'2026-11-01' => 'stay',
					'2026-11-02' => 'out',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->create_booking( $case['start'], $case['end'], $case['nights'] );
			$actual = array_map( static fn( array $rows ): string => (string) $rows[0]['stay_type'], $this->get_occurrences( '2026-11-01', '2026-11-30' ) );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		$this->create_booking( '2026-10-31 23:00:00', '2026-11-01 01:00:00', 0 );
		$this->assertArrayNotHasKey( '2026-10-31', $this->get_occurrences( '2026-11-01', '2026-11-30' ), '期間外開始の時間枠は入らないこと' );
	}

	/**
	 * 日表示の帯はバッファ込み終了時刻を使い、表示時刻はサービス終了を使う。
	 */
	public function test_get_booking_occurrences_for_range_uses_total_end_for_block(): void {
		$repository = new Settings_Repository();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_default_settings(), array( 'industry_preset' => 'custom' ) ) );
		$this->create_booking( '2026-10-14 10:00:00', '2026-10-14 11:00:00', 0, 10, 'confirmed', '2026-10-14 11:30:00' );
		$row = $this->get_occurrences( '2026-10-14', '2026-10-14' )['2026-10-14'][0];
		$this->assertSame( 11.5, $row['end_decimal'], 'バッファ込み11:30まで帯を伸ばすこと' );
		$this->assertSame( '11:00', $row['end_label'], '文字はサービス終了11:00を示すこと' );
	}

	/**
	 * 深夜の最小表示幅を確保しても24時を超えない。
	 */
	public function test_get_booking_occurrences_for_range_caps_end_decimal(): void {
		$this->create_booking( '2026-10-14 23:50:00', '2026-10-15 10:00:00', 1 );
		$row = $this->get_occurrences( '2026-10-14', '2026-10-14' )['2026-10-14'][0];
		$this->assertSame( 24.0, $row['end_decimal'], '23:50開始でも24時を超えないこと' );
	}

	/**
	 * 範囲開始時刻ちょうどに終了した予約を取得しない。
	 */
	public function test_get_booking_occurrences_for_range_excludes_checkout_boundary(): void {
		$this->create_booking( '2026-10-31 15:00:00', '2026-11-01 00:00:00', 1 );
		$this->assertSame( array(), $this->get_occurrences( '2026-11-01', '2026-11-30' ), '範囲開始とチェックアウトが同時刻なら取得しないこと' );
	}

	/**
	 * 日表示カードへ宿泊種別・泊数・前日からの表示を渡す。
	 */
	public function test_map_booking_to_card_includes_stay_labels(): void {
		$this->create_booking( '2026-10-14 15:00:00', '2026-10-17 10:00:00', 3 );
		$occurrences = $this->get_occurrences( '2026-10-14', '2026-10-17' );
		$method      = new ReflectionMethod( Shift_Dashboard_Page::class, 'map_booking_to_card' );
		$method->setAccessible( true );
		$page = new Shift_Dashboard_Page();

		$test_cases = array(
			array(
				'test_condition_name' => 'IN日は種別と3泊を表示',
				'date'                => '2026-10-14',
				'expected_label'      => 'IN 15:00~',
				'expected_continuing' => '',
			),
			array(
				'test_condition_name' => '中日は2/3泊目と前日からを表示',
				'date'                => '2026-10-15',
				'expected_label'      => 'Staying (night 2 of 3)',
				'expected_continuing' => 'Continued from previous day',
			),
			array(
				'test_condition_name' => 'OUT日は種別と前日からを表示',
				'date'                => '2026-10-17',
				'expected_label'      => 'OUT ~10:00',
				'expected_continuing' => 'Continued from previous day',
			),
		);

		foreach ( $test_cases as $case ) {
			$card = $method->invoke( $page, $occurrences[ $case['date'] ][0] );
			$this->assertSame( $case['expected_label'], $card['stay_label'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_continuing'], $card['continuing'], $case['test_condition_name'] );
			$this->assertStringContainsString( '3 nights', $card['time'], $case['test_condition_name'] );
		}
	}
}
