<?php
/**
 * 予約の重なり判定（Booking_Overlap）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Overlap;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_Query;
use WP_UnitTestCase;

/**
 * overlaps()（PHP）と build_meta_query()（SQL）が、同じ条件で重なりを判定することを検証する。
 *
 * @group bookings
 */
class Booking_Overlap_Test extends WP_UnitTestCase {

	/**
	 * 判定の条件と期待値の一覧。
	 *
	 * 期間は 2098-10-10 15:00:00 〜 2098-10-11 10:00:00 で固定する。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_cases(): array {
		return array(
			array(
				'test_condition_name' => '期間の中に収まる予約 => 重なる',
				'start'               => '2098-10-10 18:00:00',
				'end'                 => '2098-10-10 19:00:00',
				'total_end'           => '2098-10-10 19:30:00',
				'status'              => 'confirmed',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約の終了が期間の開始と同じ時刻（チェックアウトと次のチェックインが同時刻） => 重ならない',
				'start'               => '2098-10-09 15:00:00',
				'end'                 => '2098-10-10 15:00:00',
				'total_end'           => '2098-10-10 15:00:00',
				'status'              => 'confirmed',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約の終了が期間の開始の1秒後 => 重なる',
				'start'               => '2098-10-09 15:00:00',
				'end'                 => '2098-10-10 15:00:01',
				'total_end'           => '2098-10-10 15:00:01',
				'status'              => 'confirmed',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約の開始が期間の終了と同じ時刻 => 重ならない',
				'start'               => '2098-10-11 10:00:00',
				'end'                 => '2098-10-12 10:00:00',
				'total_end'           => '2098-10-12 10:00:00',
				'status'              => 'confirmed',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約の開始が期間の終了の1秒前 => 重なる',
				'start'               => '2098-10-11 09:59:59',
				'end'                 => '2098-10-12 10:00:00',
				'total_end'           => '2098-10-12 10:00:00',
				'status'              => 'confirmed',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'バッファ込みの終了だけが期間にかかる予約 => 重なる',
				'start'               => '2098-10-09 12:00:00',
				'end'                 => '2098-10-10 14:00:00',
				'total_end'           => '2098-10-10 16:00:00',
				'status'              => 'confirmed',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'キャンセルした予約 => 重ならない',
				'start'               => '2098-10-10 18:00:00',
				'end'                 => '2098-10-10 19:00:00',
				'total_end'           => '2098-10-10 19:30:00',
				'status'              => 'cancelled',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '無断キャンセルした予約 => 重ならない',
				'start'               => '2098-10-10 18:00:00',
				'end'                 => '2098-10-10 19:00:00',
				'total_end'           => '2098-10-10 19:30:00',
				'status'              => 'no_show',
				'expected'            => false,
			),
		);
	}

	/**
	 * 予約の投稿を作成する。
	 *
	 * @param array<string, mixed> $case 条件。
	 * @param int                  $resource_id リソース ID。
	 * @return int 予約の投稿 ID。
	 */
	private function create_booking( array $case, int $resource_id ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'meta_input'  => array(
					Booking_Overlap::META_START     => $case['start'],
					Booking_Overlap::META_END       => $case['end'],
					Booking_Overlap::META_TOTAL_END => $case['total_end'],
					Booking_Overlap::META_RESOURCE  => $resource_id,
					Booking_Overlap::META_STATUS    => $case['status'],
				),
			)
		);
	}

	/**
	 * overlaps: 境界・キャンセルを含む判定を検証する。
	 */
	public function test_overlaps(): void {
		foreach ( $this->get_cases() as $case ) {
			// 状態（キャンセルなど）は SQL の条件でだけ判定する。PHP の判定は呼び出し側で状態を除いた予約に使う。
			if ( 'confirmed' !== $case['status'] ) {
				continue;
			}

			$actual = Booking_Overlap::overlaps( $case['start'], $case['end'], $case['total_end'], '2098-10-10 15:00:00', '2098-10-11 10:00:00' );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		$edge_cases = array(
			array(
				'test_condition_name' => '予約の開始が空 => 重ならない',
				'args'                => array( '', '2098-10-10 18:00:00', '2098-10-10 18:00:00' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了の2つが空 => 重ならない',
				'args'                => array( '2098-10-10 18:00:00', '', '' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'バッファ込みの終了が無くても終了が期間にかかれば重なる',
				'args'                => array( '2098-10-10 18:00:00', '2098-10-10 19:00:00', '' ),
				'expected'            => true,
			),
		);
		foreach ( $edge_cases as $case ) {
			$actual = Booking_Overlap::overlaps( $case['args'][0], $case['args'][1], $case['args'][2], '2098-10-10 15:00:00', '2098-10-11 10:00:00' );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * build_meta_query: SQL の条件が overlaps() と同じ結果になることを検証する（公開済み・下書きを含む）。
	 */
	public function test_build_meta_query(): void {
		foreach ( $this->get_cases() as $case ) {
			$booking_id = $this->create_booking( $case, 4242 );

			foreach ( array( array( 4242 ), array( 4242, 4343 ) ) as $resource_ids ) {
				$query = new WP_Query(
					array(
						'post_type'      => Booking_Post_Type::POST_TYPE,
						'post_status'    => array( 'publish' ),
						'posts_per_page' => -1,
						'no_found_rows'  => true,
						'fields'         => 'ids',
						'meta_query'     => Booking_Overlap::build_meta_query( $resource_ids, '2098-10-10 15:00:00', '2098-10-11 10:00:00' ),
					)
				);
				$this->assertSame(
					$case['expected'],
					in_array( $booking_id, array_map( 'intval', $query->posts ), true ),
					$case['test_condition_name'] . '（対象客室 ' . count( $resource_ids ) . ' 件）'
				);
			}

			// 別の客室の予約は含まれない。
			$other = new WP_Query(
				array(
					'post_type'      => Booking_Post_Type::POST_TYPE,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'fields'         => 'ids',
					'meta_query'     => Booking_Overlap::build_meta_query( array( 9999 ), '2098-10-10 15:00:00', '2098-10-11 10:00:00' ),
				)
			);
			$this->assertNotContains( $booking_id, array_map( 'intval', $other->posts ), $case['test_condition_name'] . '（別の客室は含まない）' );
		}

		// 下書きの予約は、投稿の状態を publish に限る呼び出し側の条件で除外される。
		$draft_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'meta_input'  => array(
					Booking_Overlap::META_START     => '2098-10-10 18:00:00',
					Booking_Overlap::META_END       => '2098-10-10 19:00:00',
					Booking_Overlap::META_TOTAL_END => '2098-10-10 19:30:00',
					Booking_Overlap::META_RESOURCE  => 5151,
				),
			)
		);
		$query    = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => Booking_Overlap::build_meta_query( array( 5151 ), '2098-10-10 15:00:00', '2098-10-11 10:00:00' ),
			)
		);
		$this->assertNotContains( $draft_id, array_map( 'intval', $query->posts ), '下書きの予約は重ならない' );
	}
}
