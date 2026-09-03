<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use ReflectionClass;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Bookings\Booking_Confirmation_Controller;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;
use function get_option;
use function update_option;
use function update_post_meta;

/**
 * スタッフ割り当て（分割なし・ベストフィット）と競合スタッフ判定を検証する。
 *
 * 1予約は分割せず単一スタッフに割り当てる。割り当て先は、人数を収容できるスタッフの中で
 * 「残りが最も少ない（きつきつの）」スタッフ（ベストフィット）。完全に空いているスタッフを温存し、
 * スロットの空き（最も空きの大きい単一スタッフの残り）を高く保つ。
 *
 * @group bookings
 */
class Staff_Distribution_Test extends WP_UnitTestCase {
	private const META_DATE_START = '_vkbm_booking_service_start';
	private const META_DATE_END   = '_vkbm_booking_service_end';
	private const META_TOTAL_END  = '_vkbm_booking_total_end';
	private const META_STATUS     = '_vkbm_booking_status';

	/** @var string */
	private $original_timezone_string = '';

	protected function setUp(): void {
		parent::setUp();
		// ISO8601(+09:00) と保存形式（サイトTZのY-m-d H:i:s）を一致させるため Asia/Tokyo に固定する。
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	protected function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );
		parent::tearDown();
	}

	/**
	 * select_best_fit_staff のベストフィット選択を検証する。
	 *
	 * 人数を収容できるスタッフの中で残りが最も少ないスタッフを選ぶ。
	 * 収容できる候補が無ければ 0（満枠＝分割しない）。残りが同じ場合は候補配列の順（登録順）で先頭。
	 */
	public function test_select_best_fit_staff(): void {
		$max_capacity = 5;
		$staff_a      = 101;
		$staff_b      = 102;

		$settings   = new Settings_Repository();
		$controller = new Booking_Confirmation_Controller( new Booking_Notification_Service( $settings ), $settings );
		$method     = ( new ReflectionClass( $controller ) )->getMethod( 'select_best_fit_staff' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'A:残2 / B:残5・4人 => 4人を収容できるのは B のみ（A は残2）（正常系）',
				'loads'               => array(
					$staff_a => 3,
					$staff_b => 0,
				),
				'guests'              => 4,
				'expected'            => $staff_b,
			),
			array(
				'test_condition_name' => 'A:残2 / B:残1・1人 => 残りが少ない B を埋め A の残2を温存（ベストフィット）（正常系）',
				'loads'               => array(
					$staff_a => 3,
					$staff_b => 4,
				),
				'guests'              => 1,
				'expected'            => $staff_b,
			),
			array(
				'test_condition_name' => 'A:残2 / B:残5・2人 => 残りが少ない A を満杯にし B の残5を温存（正常系）',
				'loads'               => array(
					$staff_a => 3,
					$staff_b => 0,
				),
				'guests'              => 2,
				'expected'            => $staff_a,
			),
			array(
				'test_condition_name' => 'A:残5 / B:残5・7人 => どのスタッフも単一上限5を超える7人を収容不可 => 0（満枠・分割なし）（境界値）',
				'loads'               => array(
					$staff_a => 0,
					$staff_b => 0,
				),
				'guests'              => 7,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '全員空き・5人 => 残りが同じため候補順（登録順）で先頭 A（境界値）',
				'loads'               => array(),
				'guests'              => 5,
				'expected'            => $staff_a,
			),
			array(
				'test_condition_name' => 'A 満杯（残0）・1人 => 満杯の A は除外され B（正常系）',
				'loads'               => array(
					$staff_a => 5,
				),
				'guests'              => 1,
				'expected'            => $staff_b,
			),
		);

		foreach ( $test_cases as $case ) {
			$result = $method->invoke( $controller, array( $staff_a, $staff_b ), $case['loads'], $case['guests'], $max_capacity );
			$this->assertSame( $case['expected'], $result, $case['test_condition_name'] );
		}

		// 候補なしの場合は 0。
		$this->assertSame(
			0,
			$method->invoke( $controller, array(), array(), 1, $max_capacity ),
			'候補スタッフが無い場合 => 0（割り当て不可）'
		);
	}

	/**
	 * 指定時間帯にすでに別予約で埋まっているスタッフID一覧（get_conflicting_staff_ids）を検証する。
	 *
	 * 【このメソッドは何をするか】
	 * 「判定対象の時間帯（開始〜終了の日時）」を引数で受け取り、その時間帯に重なる
	 * 確定・保留中の予約を担当しているスタッフのID配列を返す（true/false ではなく ID の配列）。
	 *
	 * 【何のために使うか】
	 * 予約編集画面の「担当スタッフ」プルダウンで、その時間帯にすでに埋まっているスタッフを
	 * 選べないように除外するために使う（＝ダブルブッキング防止）。
	 *
	 * 【判定ルール】
	 * - 1予約は単一スタッフに割り当てられるため、重なる予約の担当スタッフ（resource_id）を競合とみなす。
	 * - 時間帯が重ならない予約・キャンセル等の対象外ステータス・現在編集中の予約（自分自身）は除外する。
	 *
	 * 本テストでは判定対象スロットを 2026-07-01 10:00〜11:00 とし、各スタッフの予約が
	 * 正しく「競合 / 非競合」に振り分けられるか（時間重なりの境界も含めて）を確認する。
	 */
	public function test_get_conflicting_staff_ids(): void {
		// 各スタッフは「競合する / 除外される」パターンを1つずつ担当させる。
		$staff_a = $this->create_staff( 'Conflict A' );
		$staff_c = $this->create_staff( 'Conflict C' );
		$staff_d = $this->create_staff( 'Conflict D' );
		$staff_e = $this->create_staff( 'Conflict E' );

		// 判定対象スロット 10:00-11:00 の「現在編集中の予約」。自分自身なので結果から除外されるべき。
		// ※ B にあたるスタッフはここ（現在編集中予約の担当）が担うため別途作成しない。
		//    現在編集中予約は resource_id ではなく投稿ID（post__not_in）で除外されるため、担当は 0（未割り当て）で十分。
		$current = $this->create_slot_booking( '10:00:00', '11:00:00', 'confirmed', 0 );

		// (重なる) 担当 A の予約 10:30-11:30 → スロット後半に重なる → A は競合（除外対象）。
		$this->create_slot_booking( '10:30:00', '11:30:00', 'confirmed', $staff_a );
		// (重なる) 担当 C の予約 09:30-10:30 → スロット前半に重なる → C は競合（除外対象）。
		$this->create_slot_booking( '09:30:00', '10:30:00', 'confirmed', $staff_c );
		// (重ならない) スタッフ D の予約 12:00-13:00 → 時間帯が完全にずれている → 競合しない。
		$this->create_slot_booking( '12:00:00', '13:00:00', 'confirmed', $staff_d );
		// (重なるがキャンセル) スタッフ E の予約 10:00-11:00 → 時間は完全一致だが cancelled は対象外 → 競合しない。
		$this->create_slot_booking( '10:00:00', '11:00:00', 'cancelled', $staff_e );

		$settings   = new Settings_Repository();
		$controller = new Booking_Admin( new Booking_Notification_Service( $settings ) );
		$method     = ( new ReflectionClass( $controller ) )->getMethod( 'get_conflicting_staff_ids' );
		$method->setAccessible( true );

		// 第2・第3引数が「判定したい時間帯（日時）」。この 10:00〜11:00 に重なるスタッフを取得する。
		$result = $method->invoke( $controller, $current, '2026-07-01 10:00:00', '2026-07-01 11:00:00' );

		// 埋まっている（＝プルダウンから除外すべき）のは A と C のみ。
		// D=時間ずれ / E=キャンセル / current=自分自身 はいずれも除外される。
		$this->assertEqualsCanonicalizing(
			array( $staff_a, $staff_c ),
			$result,
			'重なる予約の担当スタッフ（A,C）のみ競合とすべき'
		);
	}

	/**
	 * 指定スロットの予約投稿を作成する（2026-07-01）。
	 *
	 * @param string $start_time  開始時刻（H:i:s）。
	 * @param string $end_time    終了時刻（H:i:s）。
	 * @param string $status      予約ステータス。
	 * @param int    $resource_id 担当スタッフID（0なら設定しない）。
	 * @return int 予約投稿ID。
	 */
	private function create_slot_booking( string $start_time, string $end_time, string $status, int $resource_id ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $booking_id, self::META_DATE_START, '2026-07-01 ' . $start_time );
		update_post_meta( $booking_id, self::META_DATE_END, '2026-07-01 ' . $end_time );
		update_post_meta( $booking_id, self::META_TOTAL_END, '2026-07-01 ' . $end_time );
		update_post_meta( $booking_id, self::META_STATUS, $status );

		if ( $resource_id > 0 ) {
			update_post_meta( $booking_id, '_vkbm_booking_resource_id', $resource_id );
		}

		return $booking_id;
	}

	/**
	 * スタッフ（リソース）を作成する。
	 *
	 * @param string $name スタッフ名。
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff( string $name ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
	}
}
