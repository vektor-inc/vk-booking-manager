<?php
/**
 * 販売日の判定（Shift_Sale_Days）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Shifts;

use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\Shifts\Shift_Sale_Days;
use WP_UnitTestCase;

/**
 * 公開済みのシフトの日ごとの状態から、販売日を正しく判定できることを検証する。
 *
 * @group shifts
 */
class Shift_Sale_Days_Test extends WP_UnitTestCase {

	/**
	 * シフトの投稿を作成する。
	 *
	 * @param int                              $resource_id 客室（リソース）ID。
	 * @param int                              $year        年。
	 * @param int                              $month       月。
	 * @param array<int, array<string, mixed>> $days        日ごとのデータ。
	 * @param string                           $status      投稿ステータス。
	 * @param string                           $post_date   投稿日（Y-m-d H:i:s）。
	 * @return int 投稿 ID。
	 */
	private function create_shift( int $resource_id, int $year, int $month, array $days, string $status = 'publish', string $post_date = '2026-01-01 00:00:00' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Shift_Post_Type::POST_TYPE,
				'post_status' => $status,
				'post_date'   => $post_date,
				'meta_input'  => array(
					'_vkbm_shift_resource_id' => $resource_id,
					'_vkbm_shift_year'        => $year,
					'_vkbm_shift_month'       => $month,
					'_vkbm_shift_days'        => $days,
				),
			)
		);
	}

	/**
	 * 日のデータ（時間帯なし）を作る。
	 *
	 * @param string $status 日の状態。
	 * @return array<string, mixed>
	 */
	private function day( string $status ): array {
		return array(
			'status' => $status,
			'slots'  => array(),
		);
	}

	/**
	 * 期間内の販売日の一覧を、客室ごとに返すことを検証する。
	 */
	public function test_get_sale_dates(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'シフト未登録の月 => 空',
				'shifts'              => array(),
				'resources'           => array( 10 ),
				'start'               => '2026-10-01',
				'end'                 => '2026-10-03',
				'expected'            => array( 10 => array() ),
			),
			array(
				'test_condition_name' => 'open と temporary_open は販売日、休み系の状態は販売しない、データに無い日は販売しない',
				'shifts'              => array(
					array(
						10,
						2026,
						10,
						array(
							1 => $this->day( 'open' ),
							2 => $this->day( 'temporary_open' ),
							3 => $this->day( 'unavailable' ),
							4 => $this->day( 'regular_holiday' ),
							5 => $this->day( 'temporary_closed' ),
						),
						'publish',
						'2026-01-01 00:00:00',
					),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-10-01',
				'end'                 => '2026-10-06',
				'expected'            => array( 10 => array( '2026-10-01', '2026-10-02' ) ),
			),
			array(
				'test_condition_name' => '下書きのシフトは無視する',
				'shifts'              => array(
					array( 10, 2026, 10, array( 1 => $this->day( 'open' ) ), 'draft', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-10-01',
				'end'                 => '2026-10-02',
				'expected'            => array( 10 => array() ),
			),
			array(
				'test_condition_name' => '月またぎ（2026-01-30 から 2026-02-02）で2つの月を読む。登録の無い日は販売しない',
				'shifts'              => array(
					array(
						10,
						2026,
						1,
						array(
							30 => $this->day( 'open' ),
							31 => $this->day( 'open' ),
						),
						'publish',
						'2026-01-01 00:00:00',
					),
					array( 10, 2026, 2, array( 1 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-01-30',
				'end'                 => '2026-02-02',
				'expected'            => array( 10 => array( '2026-01-30', '2026-01-31', '2026-02-01' ) ),
			),
			array(
				'test_condition_name' => '年またぎ（2026-12-30 から 2027-01-02）。翌年の月がシフト未登録なら翌年の日は販売しない',
				'shifts'              => array(
					array(
						10,
						2026,
						12,
						array(
							30 => $this->day( 'open' ),
							31 => $this->day( 'open' ),
						),
						'publish',
						'2026-01-01 00:00:00',
					),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-12-30',
				'end'                 => '2027-01-02',
				'expected'            => array( 10 => array( '2026-12-30', '2026-12-31' ) ),
			),
			array(
				'test_condition_name' => '年またぎで翌年の月も登録済み => 両方の年の販売日を返す',
				'shifts'              => array(
					array( 10, 2026, 12, array( 31 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
					array( 10, 2027, 1, array( 1 => $this->day( 'temporary_open' ) ), 'publish', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-12-30',
				'end'                 => '2027-01-02',
				'expected'            => array( 10 => array( '2026-12-31', '2027-01-01' ) ),
			),
			array(
				'test_condition_name' => '客室が複数でも、客室ごとに別々の結果を返す',
				'shifts'              => array(
					array( 10, 2026, 10, array( 1 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
					array( 20, 2026, 10, array( 2 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10, 20, 30 ),
				'start'               => '2026-10-01',
				'end'                 => '2026-10-02',
				'expected'            => array(
					10 => array( '2026-10-01' ),
					20 => array( '2026-10-02' ),
					30 => array(),
				),
			),
			array(
				'test_condition_name' => '同じ客室・月に公開済みが複数あるとき、投稿日の新しい方を使う（既存の空き状況の選び方と同じ）',
				'shifts'              => array(
					array( 10, 2026, 10, array( 1 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
					array( 10, 2026, 10, array( 2 => $this->day( 'open' ) ), 'publish', '2026-02-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-10-01',
				'end'                 => '2026-10-02',
				'expected'            => array( 10 => array( '2026-10-02' ) ),
			),
			array(
				'test_condition_name' => '投稿日が同時刻の公開シフトが複数 => 投稿 ID の大きい（後から作った）方を使う',
				'shifts'              => array(
					array( 10, 2026, 10, array( 1 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
					array( 10, 2026, 10, array( 2 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-10-01',
				'end'                 => '2026-10-02',
				'expected'            => array( 10 => array( '2026-10-02' ) ),
			),
			array(
				'test_condition_name' => '存在しない日付（2026-02-31）は受け付けず空を返す',
				'shifts'              => array(
					array( 10, 2026, 2, array( 28 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-02-28',
				'end'                 => '2026-02-31',
				'expected'            => array( 10 => array() ),
			),
			array(
				'test_condition_name' => '開始日が終了日より後なら空を返す',
				'shifts'              => array(
					array(
						10,
						2026,
						10,
						array(
							1 => $this->day( 'open' ),
							2 => $this->day( 'open' ),
						),
						'publish',
						'2026-01-01 00:00:00',
					),
				),
				'resources'           => array( 10 ),
				'start'               => '2026-10-02',
				'end'                 => '2026-10-01',
				'expected'            => array( 10 => array() ),
			),
			array(
				'test_condition_name' => 'うるう日（2028-02-29）は存在する日付として扱う',
				'shifts'              => array(
					array( 10, 2028, 2, array( 29 => $this->day( 'open' ) ), 'publish', '2026-01-01 00:00:00' ),
				),
				'resources'           => array( 10 ),
				'start'               => '2028-02-28',
				'end'                 => '2028-02-29',
				'expected'            => array( 10 => array( '2028-02-29' ) ),
			),
		);

		$sale_days = new Shift_Sale_Days();

		foreach ( $test_cases as $case ) {
			$created = array();
			foreach ( $case['shifts'] as $shift ) {
				$created[] = $this->create_shift( $shift[0], $shift[1], $shift[2], $shift[3], $shift[4], $shift[5] );
			}

			$actual = $sale_days->get_sale_dates( $case['resources'], $case['start'], $case['end'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			foreach ( $created as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * 客室・月が増えても、シフトを取得する問い合わせが1回で済むことを検証する。
	 */
	public function test_get_sale_dates_query_count(): void {
		$days = array( 1 => $this->day( 'open' ) );
		foreach ( array( 10, 20, 30 ) as $resource_id ) {
			foreach ( array( 10, 11, 12 ) as $month ) {
				$this->create_shift( $resource_id, 2026, $month, $days );
			}
		}

		// キャッシュを空にして、投稿の取得とメタの取得を含む全体の問い合わせ回数を数える。
		wp_cache_flush();
		// オプションの読み込み（キャッシュを空にした後に必ず1回走る）は対象外にするため、先に済ませる。
		wp_load_alloptions();
		global $wpdb;
		$before = $wpdb->num_queries;

		$actual = ( new Shift_Sale_Days() )->get_sale_dates( array( 10, 20, 30 ), '2026-10-01', '2026-12-31' );

		$this->assertSame( 2, $wpdb->num_queries - $before, '客室3件・シフト9件・3か月（92日）でも、問い合わせは投稿の取得1回とメタの取得1回の合計2回' );
		$this->assertSame( array( '2026-10-01', '2026-11-01', '2026-12-01' ), $actual[10], '各月の1日が販売日として返る' );
	}

	/**
	 * 年・月の条件に合う組み合わせでも、期間にかからない年月のシフトは使わないことを検証する。
	 */
	public function test_get_sale_dates_ignores_year_month_outside_period(): void {
		$days = array( 1 => $this->day( 'open' ) );
		$this->create_shift( 10, 2026, 12, $days );
		$this->create_shift( 10, 2027, 1, $days );
		// 年は 2026・2027、月は 12・1 に含まれるが、期間（2026-12〜2027-01）にかからない組み合わせ。
		$this->create_shift( 10, 2026, 1, $days );
		$this->create_shift( 10, 2027, 12, $days );

		$actual = ( new Shift_Sale_Days() )->get_sale_dates( array( 10 ), '2026-12-01', '2027-01-31' );

		$this->assertSame( array( '2026-12-01', '2027-01-01' ), $actual[10] );
	}

	/**
	 * 期間の日数の上限（366日）を超える指定は空を返すことを検証する。
	 */
	public function test_get_sale_dates_max_days(): void {
		$this->create_shift( 10, 2026, 1, array( 1 => $this->day( 'open' ) ) );
		$this->create_shift( 10, 2027, 1, array( 1 => $this->day( 'open' ) ) );

		$test_cases = array(
			array(
				'test_condition_name' => '上限ちょうど（366日: 2026-01-01 から 2027-01-01）=> 両端の販売日を返す',
				'start'               => '2026-01-01',
				'end'                 => '2027-01-01',
				'expected'            => array( 10 => array( '2026-01-01', '2027-01-01' ) ),
			),
			array(
				'test_condition_name' => '上限を超える（367日: 2026-01-01 から 2027-01-02）=> 空を返す',
				'start'               => '2026-01-01',
				'end'                 => '2027-01-02',
				'expected'            => array( 10 => array() ),
			),
			array(
				'test_condition_name' => '2年以上の期間 => 空を返す',
				'start'               => '2026-01-01',
				'end'                 => '2029-12-31',
				'expected'            => array( 10 => array() ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ( new Shift_Sale_Days() )->get_sale_dates( array( 10 ), $case['start'], $case['end'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 販売日の規則（状態が無ければ open とみなす）を検証する。
	 */
	public function test_is_sale_entry(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'open => 販売日',
				'entry'               => array( 'status' => 'open' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'temporary_open => 販売日',
				'entry'               => array( 'status' => 'temporary_open' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '状態が無い配列 => open とみなして販売日',
				'entry'               => array( 'slots' => array() ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'regular_holiday => 販売しない',
				'entry'               => array( 'status' => 'regular_holiday' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '配列でない値 => 販売しない',
				'entry'               => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Shift_Sale_Days::is_sale_entry( $case['entry'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * 1日だけを調べる窓口が、販売日・販売しない日・存在しない日付を正しく判定することを検証する。
	 */
	public function test_is_sale_date(): void {
		$this->create_shift(
			10,
			2026,
			10,
			array(
				1 => $this->day( 'open' ),
				2 => $this->day( 'temporary_open' ),
				3 => $this->day( 'unavailable' ),
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'open の日 => 販売日',
				'resource_id'         => 10,
				'date'                => '2026-10-01',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'temporary_open の日 => 販売日',
				'resource_id'         => 10,
				'date'                => '2026-10-02',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'unavailable の日 => 販売しない',
				'resource_id'         => 10,
				'date'                => '2026-10-03',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'データに無い日 => 販売しない',
				'resource_id'         => 10,
				'date'                => '2026-10-04',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '別の客室 => 販売しない',
				'resource_id'         => 99,
				'date'                => '2026-10-01',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '存在しない日付 => 販売しない',
				'resource_id'         => 10,
				'date'                => '2026-10-32',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '日付の形式が違う（スラッシュ区切り）=> 販売しない',
				'resource_id'         => 10,
				'date'                => '2026/10/01',
				'expected'            => false,
			),
		);

		$sale_days = new Shift_Sale_Days();

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $sale_days->is_sale_date( $case['resource_id'], $case['date'] ), $case['test_condition_name'] );
		}
	}
}
