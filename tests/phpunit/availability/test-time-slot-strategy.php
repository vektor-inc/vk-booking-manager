<?php
/**
 * 時間枠戦略（Time_Slot_Strategy）と、Availability_Service からの委譲のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Availability\Slot_Strategy;
use VKBookingManager\Availability\Time_Slot_Strategy;
use WP_Post;
use WP_UnitTestCase;

/**
 * 呼び出されたことを記録するだけの戦略。
 */
class Recording_Slot_Strategy implements Slot_Strategy {

	/**
	 * 受け取った引数の記録。
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = array();

	/**
	 * 引数を記録して固定の空き枠を返す。
	 *
	 * @param WP_Post      $menu_post          メニュー投稿。
	 * @param array<int>   $staff_ids          候補スタッフ ID。
	 * @param string       $date               日付（Y-m-d）。
	 * @param DateTimeZone $timezone           タイムゾーン。
	 * @param bool         $is_staff_preferred スタッフ指名ありか。
	 * @return array<int, array<string, mixed>>
	 */
	public function generate_slots_for_date( WP_Post $menu_post, array $staff_ids, string $date, DateTimeZone $timezone, bool $is_staff_preferred ): array {
		$this->calls[] = array(
			'menu_id'            => $menu_post->ID,
			'staff_ids'          => $staff_ids,
			'date'               => $date,
			'is_staff_preferred' => $is_staff_preferred,
		);

		return array( array( 'slot_id' => 'recorded' ) );
	}
}

/**
 * 戦略の選択点（resolve_slot_strategy）を差し替えるテスト用の Availability_Service。
 */
class Strategy_Injected_Availability_Service extends Availability_Service {

	/**
	 * 差し替える戦略。
	 *
	 * @var Recording_Slot_Strategy
	 */
	public Recording_Slot_Strategy $strategy;

	/**
	 * 差し替える戦略を返す。
	 *
	 * @param WP_Post $menu_post メニュー投稿。
	 * @return Slot_Strategy 戦略。
	 */
	protected function resolve_slot_strategy( WP_Post $menu_post ): Slot_Strategy {
		return $this->strategy;
	}
}

/**
 * Time_Slot_Strategy と委譲の振る舞いのテストクラス。
 *
 * @group availability
 */
class Time_Slot_Strategy_Test extends WP_UnitTestCase {

	/**
	 * generate_slots_for_date() が選ばれた戦略へ引数をそのまま委譲することを検証する。
	 */
	public function test_generate_slots_for_date_delegates_to_selected_strategy(): void {
		// 戦略を差し替えた Availability_Service と、メニュー投稿を用意する。
		$service           = new Strategy_Injected_Availability_Service();
		$service->strategy = new Recording_Slot_Strategy();
		$menu_id           = self::factory()->post->create();
		$menu_post         = get_post( $menu_id );

		// private の generate_slots_for_date() を呼ぶ。
		$method = new ReflectionMethod( Availability_Service::class, 'generate_slots_for_date' );
		$method->setAccessible( true );
		$result = $method->invoke( $service, $menu_post, array( 3, 5 ), '2026-04-01', new DateTimeZone( 'Asia/Tokyo' ), true );

		// 差し替えた戦略の戻り値と、受け取った引数を確認する。
		$this->assertSame( array( array( 'slot_id' => 'recorded' ) ), $result );
		$this->assertCount( 1, $service->strategy->calls );
		$this->assertSame(
			array(
				'menu_id'            => $menu_id,
				'staff_ids'          => array( 3, 5 ),
				'date'               => '2026-04-01',
				'is_staff_preferred' => true,
			),
			$service->strategy->calls[0]
		);
	}

	/**
	 * 時間枠戦略が Slot_Strategy を実装していることを検証する。
	 */
	public function test_time_slot_strategy_implements_slot_strategy(): void {
		$this->assertInstanceOf( Slot_Strategy::class, new Time_Slot_Strategy( new Availability_Service() ) );
	}

	/**
	 * 時間枠戦略の build_slots_from_entry() と Availability_Service 経由の結果が一致することを検証する。
	 */
	public function test_build_slots_from_entry_matches_between_strategy_and_service(): void {
		$service  = new Availability_Service();
		$strategy = new Time_Slot_Strategy( $service );
		$args     = array(
			array(
				array(
					'start' => '09:00',
					'end'   => '12:00',
				),
			),
			'2026-04-01',
			new DateTimeZone( 'Asia/Tokyo' ),
			60,
			60,
			null,
			array(),
			30,
		);

		// 戦略を直接呼んだ結果と、Availability_Service（private）経由の結果を比べる。
		$via_strategy = $strategy->build_slots_from_entry( ...$args );

		$method = new ReflectionMethod( Availability_Service::class, 'build_slots_from_entry' );
		$method->setAccessible( true );
		$via_service = $method->invoke( $service, ...$args );

		$this->assertEquals( $via_service, $via_strategy );
		$this->assertSame(
			array( '09:00', '09:30', '10:00', '10:30', '11:00' ),
			array_map(
				static function ( array $slot ): string {
					return $slot['start']->format( 'H:i' );
				},
				$via_strategy
			)
		);
		$this->assertInstanceOf( DateTimeImmutable::class, $via_strategy[0]['end'] );
	}
}
