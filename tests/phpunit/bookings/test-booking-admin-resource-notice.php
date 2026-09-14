<?php
/**
 * 予約編集画面「担当スタッフと人数」欄の除外注記（vkbm-booking-resource-description）の
 * 表示判定（Booking_Admin::count_hidden_resource_options）を検証するテスト。
 *
 * 「除外された」は「プルダウンから実際に隠された」スタッフを指すため、除外一覧
 * （get_conflicting_staff_ids() の結果）に含まれていても、プルダウンの選択肢として
 * 存在しない（削除済み等の）IDや、現在選択中のIDは実際には隠れない。注記の表示・
 * 非表示はこの「実際に隠れる件数」だけで決まることを確認する（#446）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use WP_UnitTestCase;

/**
 * @group bookings
 */
class Booking_Admin_Resource_Notice_Test extends WP_UnitTestCase {

	/**
	 * count_hidden_resource_options() が、除外一覧・選択肢一覧・選択中IDの組み合わせに対して
	 * 正しい「実際に隠れる選択肢の数」を返すことを検証する。
	 *
	 * count_hidden_resource_options() は WordPress の関数に依存しない純粋な配列演算のため、
	 * インスタンス化やモックを介さず public static メソッドを直接呼び出す。
	 */
	public function test_count_hidden_resource_options(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '除外一覧が空 => 隠れる選択肢は0件（注記は非表示）',
				'conflict_staff_ids'   => array(),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'selected_resource_id' => 0,
				'expected'             => 0,
			),
			array(
				'test_condition_name'  => '除外一覧に1件、選択肢として存在し、選択中でない => 1件隠れる（注記は表示）',
				'conflict_staff_ids'   => array( 2 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'selected_resource_id' => 1,
				'expected'             => 1,
			),
			array(
				'test_condition_name'  => '除外一覧に含まれるスタッフが現在選択中 => 選択中は常に表示されるため0件（選択中は数えない）',
				'conflict_staff_ids'   => array( 1 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'selected_resource_id' => 1,
				'expected'             => 0,
			),
			array(
				'test_condition_name'  => '除外一覧に含まれるIDがプルダウンの選択肢として存在しない（削除済み等） => 0件',
				'conflict_staff_ids'   => array( 99 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'selected_resource_id' => 0,
				'expected'             => 0,
			),
			array(
				'test_condition_name'  => '除外一覧2件のうち1件が選択中 => 実際に隠れるのは選択中でない1件のみ',
				'conflict_staff_ids'   => array( 1, 2 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'selected_resource_id' => 1,
				'expected'             => 1,
			),
			array(
				'test_condition_name'  => '除外一覧に重複IDが含まれる => 重複を除いて1件として数える',
				'conflict_staff_ids'   => array( 2, 2, 2 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'selected_resource_id' => 0,
				'expected'             => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Booking_Admin::count_hidden_resource_options(
				$case['conflict_staff_ids'],
				$case['resource_option_ids'],
				$case['selected_resource_id']
			);

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * is_selected_resource_conflicting() が、選択中ID・除外一覧・選択肢一覧の組み合わせに対して
	 * 正しい真偽値を返すことを検証する（#446、安藤さんレビュー対応で $resource_option_ids 引数を追加）。
	 *
	 * is_selected_resource_conflicting() は WordPress の関数に依存しない純粋な配列演算のため、
	 * インスタンス化やモックを介さず public static メソッドを直接呼び出す。
	 */
	public function test_is_selected_resource_conflicting(): void {
		$test_cases = array(
			array(
				'test_condition_name'  => '未選択（0） => 除外一覧に0自身が含まれていても対象外なので false',
				'selected_resource_id' => 0,
				'conflict_staff_ids'   => array( 0, 1, 2 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '選択中IDが除外一覧・選択肢の両方に含まれる => true（担当できない状態）',
				'selected_resource_id' => 2,
				'conflict_staff_ids'   => array( 1, 2, 3 ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '選択中IDが除外一覧に含まれない => false',
				'selected_resource_id' => 5,
				'conflict_staff_ids'   => array( 1, 2, 3 ),
				'resource_option_ids'  => array( 1, 2, 3, 5 ),
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '除外一覧が空 => 常に false',
				'selected_resource_id' => 1,
				'conflict_staff_ids'   => array(),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'expected'             => false,
			),
			array(
				'test_condition_name'  => '除外一覧が文字列のID（\'2\'）でも intval 比較で true になる（型の揺れを吸収する）',
				'selected_resource_id' => 2,
				'conflict_staff_ids'   => array( '2' ),
				'resource_option_ids'  => array( 1, 2, 3 ),
				'expected'             => true,
			),
			array(
				'test_condition_name'  => '選択中IDが除外一覧に含まれても、選択肢として存在しない（削除・非公開等）なら false',
				'selected_resource_id' => 2,
				'conflict_staff_ids'   => array( 1, 2, 3 ),
				'resource_option_ids'  => array( 1, 3 ),
				'expected'             => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Booking_Admin::is_selected_resource_conflicting(
				$case['selected_resource_id'],
				$case['conflict_staff_ids'],
				$case['resource_option_ids']
			);

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * is_staff_check_target_status() が、ステータスごとに正しい真偽値を返すことを検証する
	 * （#446 安藤さんレビュー対応。save_post() の保存時チェックと同じ基準）。
	 *
	 * is_staff_check_target_status() は WordPress の関数に依存しない純粋な文字列比較のため、
	 * インスタンス化やモックを介さず public static メソッドを直接呼び出す。
	 */
	public function test_is_staff_check_target_status(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '確定（confirmed） => 担当チェック対象なので true',
				'status'              => 'confirmed',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '保留（pending） => 担当チェック対象なので true',
				'status'              => 'pending',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'キャンセル（cancelled） => 枠を消費しないため担当チェック対象外で false',
				'status'              => 'cancelled',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '無断キャンセル（no_show） => 枠を消費しないため担当チェック対象外で false',
				'status'              => 'no_show',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Booking_Admin::is_staff_check_target_status( $case['status'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
