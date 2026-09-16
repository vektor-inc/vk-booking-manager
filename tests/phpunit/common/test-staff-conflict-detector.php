<?php
/**
 * Staff_Conflict_Detector のメモリ上の競合判定テスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Staff_Conflict_Detector;
use WP_UnitTestCase;

/**
 * 担当スタッフ競合判定を検証する。
 */
class Staff_Conflict_Detector_Test extends WP_UnitTestCase {
	/**
	 * 時間境界・自己除外・ステータス・メニュー・定員・担当未定をまとめて検証する。
	 */
	public function test_detect_reasons(): void {
		$base_target = array(
			'id'          => 1,
			'start'       => '2026-09-15 10:00:00',
			'service_end' => '2026-09-15 11:00:00',
			'total_end'   => '2026-09-15 11:00:00',
			'resource_id' => 10,
			'service_id'  => 100,
			'guests'      => 1,
		);
		$base_other  = array(
			'id'          => 2,
			'start'       => '2026-09-15 10:30:00',
			'service_end' => '2026-09-15 11:30:00',
			'total_end'   => '2026-09-15 11:30:00',
			'resource_id' => 10,
			'service_id'  => 100,
			'guests'      => 1,
			'status'      => 'confirmed',
			'post_status' => 'publish',
		);

		$cases = array(
			array(
				'name'       => '定員1の重なり',
				'target'     => array(),
				'other'      => array(),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array( Staff_Conflict_Detector::REASON_OVERLAP ),
			),
			array(
				'name'       => '終了と開始が同時刻なら非重複',
				'target'     => array(),
				'other'      => array( 'start' => '2026-09-15 11:00:00' ),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '自分自身は除外',
				'target'     => array(),
				'other'      => array( 'id' => 1 ),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '担当未定は対象外',
				'target'     => array( 'resource_id' => 0 ),
				'other'      => array(),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => 'キャンセルは単純重複対象外',
				'target'     => array(),
				'other'      => array( 'status' => 'cancelled' ),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '無断キャンセルは単純重複対象外',
				'target'     => array(),
				'other'      => array( 'status' => 'no_show' ),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '仮予約は単純重複対象',
				'target'     => array(),
				'other'      => array( 'status' => 'pending' ),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array( Staff_Conflict_Detector::REASON_OVERLAP ),
			),
			array(
				'name'       => '指名ありは同じメニューでも単純重複',
				'target'     => array(),
				'other'      => array(),
				'capacity'   => 3,
				'nomination' => true,
				'expected'   => array( Staff_Conflict_Detector::REASON_OVERLAP ),
			),
			array(
				'name'       => '同じメニューで残数あり',
				'target'     => array(),
				'other'      => array(),
				'capacity'   => 3,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '同じメニューで残数不足',
				'target'     => array( 'guests' => 2 ),
				'other'      => array(),
				'capacity'   => 2,
				'nomination' => false,
				'expected'   => array( Staff_Conflict_Detector::REASON_INSUFFICIENT_CAPACITY ),
			),
			array(
				'name'       => '別メニューは単純重複',
				'target'     => array(),
				'other'      => array( 'service_id' => 200 ),
				'capacity'   => 3,
				'nomination' => false,
				'expected'   => array( Staff_Conflict_Detector::REASON_OVERLAP ),
			),
			array(
				'name'       => '人数が定員を超えても同一メニュー負荷が0なら残数不足にしない',
				'target'     => array( 'guests' => 4 ),
				'other'      => array(
					'service_id' => 200,
					'status'     => 'cancelled',
				),
				'capacity'   => 3,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '秒なし日時もDATETIME比較と同じ境界で非重複',
				'target'     => array(
					'start'       => '2026-09-15 10:00',
					'service_end' => '2026-09-15 11:00',
					'total_end'   => '2026-09-15 11:00',
				),
				'other'      => array( 'start' => '2026-09-15 11:00:00' ),
				'capacity'   => 1,
				'nomination' => false,
				'expected'   => array(),
			),
			array(
				'name'       => '両理由は重複を先に返す',
				'target'     => array( 'guests' => 2 ),
				'other'      => array(),
				'extra'      => array(
					array_merge(
						$base_other,
						array(
							'id'         => 3,
							'service_id' => 200,
						)
					),
				),
				'capacity'   => 2,
				'nomination' => false,
				'expected'   => array( Staff_Conflict_Detector::REASON_OVERLAP, Staff_Conflict_Detector::REASON_INSUFFICIENT_CAPACITY ),
			),
		);

		foreach ( $cases as $case ) {
			$target   = array_merge( $base_target, $case['target'] );
			$other    = array_merge( $base_other, $case['other'] );
			$bookings = array_merge( array( $target, $other ), $case['extra'] ?? array() );
			$actual   = Staff_Conflict_Detector::detect_reasons( $target, $bookings, array( 100 => $case['capacity'] ), array( 100 => $case['nomination'] ) );
			$this->assertSame( $case['expected'], $actual, $case['name'] );
		}
	}

	/**
	 * 秒なしの対象と逆順の一覧（正規化する経路）と、正規化済みの対象と開始日時順の一覧（正規化済みの経路）で判定結果が一致することを検証する。
	 */
	public function test_detect_reasons_sorted_by_start_matches_unsorted(): void {
		$target   = array(
			'id'          => 1,
			'start'       => '2026-09-15 10:00',
			'service_end' => '2026-09-15 11:00',
			'total_end'   => '2026-09-15 11:00',
			'resource_id' => 10,
			'service_id'  => 100,
			'guests'      => 2,
		);
		$bookings = array(
			array_merge(
				$target,
				array(
					'start'       => '2026-09-15 10:00:00',
					'service_end' => '2026-09-15 11:00:00',
					'total_end'   => '2026-09-15 11:00:00',
				)
			),
			array(
				'id'          => 2,
				'start'       => '2026-09-15 10:30:00',
				'service_end' => '2026-09-15 11:30:00',
				'total_end'   => '2026-09-15 11:30:00',
				'resource_id' => 10,
				'service_id'  => 100,
				'guests'      => 1,
				'status'      => 'confirmed',
				'post_status' => 'publish',
			),
		);

		$unsorted_result = Staff_Conflict_Detector::detect_reasons( $target, array_reverse( $bookings ), array( 100 => 2 ), array( 100 => false ) );
		$sorted_result   = Staff_Conflict_Detector::detect_reasons( array_merge( $target, $bookings[0] ), $bookings, array( 100 => 2 ), array( 100 => false ), true );

		$this->assertSame( $unsorted_result, $sorted_result, '秒なしの対象と逆順の一覧、および正規化済みの対象と開始日時順の一覧で判定結果が一致する' );
	}

	/**
	 * 開始日時順の一覧の打ち切り境界に、対象予約の total_end が使われることを検証する。
	 */
	public function test_detect_reasons_sorted_by_start_uses_total_end_for_break_boundary(): void {
		$target   = array(
			'id'          => 1,
			'start'       => '2026-09-15 10:00:00',
			'service_end' => '2026-09-15 11:00:00',
			'total_end'   => '2026-09-15 11:30:00',
			'resource_id' => 10,
			'service_id'  => 100,
			'guests'      => 1,
		);
		$bookings = array(
			$target,
			array(
				'id'          => 2,
				'start'       => '2026-09-15 11:15:00',
				'service_end' => '2026-09-15 12:15:00',
				'total_end'   => '2026-09-15 12:15:00',
				'resource_id' => 10,
				'service_id'  => 200,
				'guests'      => 1,
				'status'      => 'confirmed',
				'post_status' => 'publish',
			),
		);

		$actual = Staff_Conflict_Detector::detect_reasons( $target, $bookings, array( 100 => 1 ), array( 100 => false ), true );

		$this->assertSame( array( Staff_Conflict_Detector::REASON_OVERLAP ), $actual, 'service_end 後かつ total_end 前に始まる別メニュー予約を重複として検出する' );
	}

	/**
	 * 開始日時順の一覧で長い重複予約を検出し、終了境界の隣接予約を除外することを検証する。
	 */
	public function test_detect_reasons_sorted_by_start_checks_long_booking_before_adjacent_booking(): void {
		$target   = array(
			'id'          => 1,
			'start'       => '2026-09-15 10:00:00',
			'service_end' => '2026-09-15 11:00:00',
			'total_end'   => '2026-09-15 11:00:00',
			'resource_id' => 10,
			'service_id'  => 100,
			'guests'      => 1,
		);
		$bookings = array(
			$target,
			array(
				'id'          => 2,
				'start'       => '2026-09-15 10:30:00',
				'service_end' => '2026-09-15 13:00:00',
				'total_end'   => '2026-09-15 13:00:00',
				'resource_id' => 10,
				'service_id'  => 200,
				'guests'      => 1,
				'status'      => 'confirmed',
				'post_status' => 'publish',
			),
			array(
				'id'          => 3,
				'start'       => '2026-09-15 11:00:00',
				'service_end' => '2026-09-15 12:00:00',
				'total_end'   => '2026-09-15 12:00:00',
				'resource_id' => 10,
				'service_id'  => 200,
				'guests'      => 1,
				'status'      => 'confirmed',
				'post_status' => 'publish',
			),
		);

		$actual        = Staff_Conflict_Detector::detect_reasons( $target, $bookings, array( 100 => 3 ), array( 100 => false ), true );
		$adjacent_only = Staff_Conflict_Detector::detect_reasons(
			$target,
			array( $target, $bookings[2] ),
			array( 100 => 3 ),
			array( 100 => false ),
			true
		);

		$this->assertSame( array( Staff_Conflict_Detector::REASON_OVERLAP ), $actual, '後ろ側の長い予約は重複として検出する' );
		$this->assertSame( array(), $adjacent_only, '終了時刻から始まる隣接予約は重複として検出しない' );
	}
}
