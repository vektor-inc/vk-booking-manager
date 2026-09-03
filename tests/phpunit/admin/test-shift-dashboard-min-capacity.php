<?php
/**
 * Shift_Dashboard_Page::annotate_min_capacity_state() の催行状態集計テスト。
 *
 * 最小催行人数（グループ開催型）の集計が「1回 = 同じ日時（同じ開始・終了時刻）の
 * サービススロット」単位で行われ、時間帯が重なるだけの別開始時刻の予約を巻き込まないことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use ReflectionClass;
use VKBookingManager\Admin\Shift_Dashboard_Page;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 最小催行人数（グループ開催型）の催行状態集計のテストクラス。
 *
 * @group admin
 * @group min-capacity
 */
class Shift_Dashboard_Min_Capacity_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に指名・複数人予約の静的キャッシュをクリアする。
	 */
	protected function tearDown(): void {
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * 複数人予約が利用可能（指名OFF・複数人予約ON）な前提を整える。
	 *
	 * get_menu_min_capacity_for_service は最大受付数が2以上でないと0（制約なし）を返すため、
	 * 集計の挙動を検証するテストではこの前提を整える。
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
	 * 最小催行人数3・最大受付数5のサービスメニューを作成して投稿IDを返す。
	 *
	 * @return int サービスメニューの投稿ID。
	 */
	private function create_service_menu(): int {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 3 );
		return $menu_id;
	}

	/**
	 * 予約投稿を作成し、予約人数メタを設定して、$map 用の予約レコード配列を返す。
	 *
	 * @param int    $service_id    サービスメニューの投稿ID。
	 * @param float  $start_decimal 開始（十進時刻）。
	 * @param float  $end_decimal   終了（十進時刻）。
	 * @param int    $guests        予約人数。
	 * @param string $status        予約ステータス。
	 * @return array<string, mixed> 予約レコード。
	 */
	private function make_booking_row( int $service_id, float $start_decimal, float $end_decimal, int $guests, string $status = 'confirmed' ): array {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'vkbm_booking',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_vkbm_booking_guests', $guests );

		return array(
			'post_id'       => $post_id,
			'service_id'    => $service_id,
			'start_decimal' => $start_decimal,
			'end_decimal'   => $end_decimal,
			'status'        => $status,
		);
	}

	/**
	 * annotate_min_capacity_state を Reflection 経由で実行して $map を返す。
	 *
	 * @param array<int, array<int, array<string, mixed>>> $map 予約マップ。
	 * @return array<int, array<int, array<string, mixed>>> 付与後の予約マップ。
	 */
	private function call_annotate( array $map ): array {
		$page       = new Shift_Dashboard_Page();
		$reflection = new ReflectionClass( $page );
		$method     = $reflection->getMethod( 'annotate_min_capacity_state' );
		$method->setAccessible( true );
		$method->invokeArgs( $page, array( &$map ) );
		return $map;
	}

	/**
	 * 同一サービス・同一開始終了時刻のスロットだけが1セッションとして合算され、
	 * 時間帯が重なるだけの別開始時刻の予約は巻き込まれないことを検証する（#2 の回帰防止）。
	 */
	public function test_annotate_min_capacity_state(): void {
		$this->enable_multiple_guests_context();
		$service_id = $this->create_service_menu();

		$test_cases = array(
			array(
				'test_condition_name' => '同一スロット(10:00-11:00)にスタッフ横断で2名+1名 => 合計3名で fulfilled（最小3・正常系：スタッフ横断合算）',
				'bookings'            => array(
					// resource 1（10:00-11:00, 2名）
					1 => array(
						array(
							'start'  => 10.0,
							'end'    => 11.0,
							'guests' => 2,
						),
					),
					// resource 2（10:00-11:00, 1名）
					2 => array(
						array(
							'start'  => 10.0,
							'end'    => 11.0,
							'guests' => 1,
						),
					),
				),
				'expected_state'      => 'fulfilled',
				'expected_group'      => 3,
			),
			array(
				'test_condition_name' => '同一スロット(10:00-11:00)に1名のみ => 未達 pending・group=1（最小3・正常系：未達）',
				'bookings'            => array(
					1 => array(
						array(
							'start'  => 10.0,
							'end'    => 11.0,
							'guests' => 1,
						),
					),
				),
				'expected_state'      => 'pending',
				'expected_group'      => 1,
			),
			array(
				'test_condition_name' => '開始時刻が違い時間帯が重なるだけの別セッション(10:00-11:00 と 10:30-11:30)は合算しない => 各1名で pending（#2 回帰防止・境界値）',
				'bookings'            => array(
					1 => array(
						array(
							'start'  => 10.0,
							'end'    => 11.0,
							'guests' => 1,
						),
					),
					2 => array(
						array(
							'start'  => 10.5,
							'end'    => 11.5,
							'guests' => 1,
						),
					),
				),
				'expected_state'      => 'pending',
				'expected_group'      => 1,
			),
			array(
				'test_condition_name' => 'キャンセル済みは催行人数に数えない(10:00-11:00 で 2名confirmed + 2名cancelled) => group=2 で pending（異常系：キャンセル除外）',
				'bookings'            => array(
					1 => array(
						array(
							'start'  => 10.0,
							'end'    => 11.0,
							'guests' => 2,
							'status' => 'confirmed',
						),
						array(
							'start'  => 10.0,
							'end'    => 11.0,
							'guests' => 2,
							'status' => 'cancelled',
						),
					),
				),
				'expected_state'      => 'pending',
				'expected_group'      => 2,
			),
		);

		foreach ( $test_cases as $case ) {
			// $map を組み立てる。
			$map = array();
			foreach ( $case['bookings'] as $resource_id => $rows ) {
				$map[ $resource_id ] = array();
				foreach ( $rows as $row ) {
					$map[ $resource_id ][] = $this->make_booking_row(
						$service_id,
						$row['start'],
						$row['end'],
						$row['guests'],
						$row['status'] ?? 'confirmed'
					);
				}
			}

			$result = $this->call_annotate( $map );

			// 最初の resource の最初の予約（= 起点となる 10:00-11:00 の予約）の催行状態を検証する。
			$first = $result[1][0];
			$this->assertSame( $case['expected_group'], $first['group_guests'], $case['test_condition_name'] );
			$this->assertSame( $case['expected_state'], $first['min_capacity_state'], $case['test_condition_name'] );
		}
	}
}
