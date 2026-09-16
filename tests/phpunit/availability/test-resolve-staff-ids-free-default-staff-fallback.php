<?php
/**
 * Availability_Service::resolve_staff_ids() の無料版フォールバックのテスト（issue #465）。
 *
 * 無料版（Staff_Editor::is_enabled() === false）では、担当スタッフは常に基本スタッフ
 * （Default Staff）1名固定である。しかし resolve_staff_ids() は resource_id 未指定の
 * 通常フローで、サービスメニューの post meta `_vkbm_staff_ids` をそのまま担当スタッフとして
 * 使ってしまっていた。Pro版時代の古い／実在しないスタッフIDが `_vkbm_staff_ids` に残って
 * いると、そのスタッフのシフトは存在しないため全日程予約不可になり、
 * `shift_too_short_for_duration`（「勤務時間が確保されていません」）という誤った診断が
 * 出ていた（デモサイトで実際に確認された不具合）。
 *
 * このファイルでは、
 * 1. resolve_staff_ids() 自体（private のため ReflectionMethod 経由）が、無料版では
 *    メニュー側の担当設定に関わらず常に基本スタッフへフォールバックすることを検証する。
 *    無料版はスタッフ指名機能自体が常に無効なため、resource_id（$preferred_staff）が
 *    明示指定された場合もその値を信用せず、常に基本スタッフへ解決する（#465 レビュー指摘。
 *    URL クエリや「同じ内容で予約する」導線経由で古い resource_id が渡ってきても、
 *    非公開スタッフの情報が漏れたり、全日程予約不可になったりしないようにするため）。
 * 2. デモサイトの状態（基本スタッフのシフトは正しいが、メニューの担当設定が古いID）を
 *    再現し、get_unavailability_reason() / get_calendar_meta() の結果で予約枠が正しく
 *    解決されることを検証する（再現テスト。修正前は shift_too_short_for_duration が
 *    返り red、修正後は null（予約可能）が返り green になることを確認済み）。
 * 3. Pro版（Staff_Editor::is_enabled() === true）の既存の担当スタッフ限定挙動
 *    （staff_not_assigned エラー等）がこの修正で変わらないことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Availability;

use ReflectionMethod;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\PostTypes\Shift_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_UnitTestCase;
use function current_datetime;
use function get_post;
use function get_posts;
use function update_post_meta;
use function wp_update_post;

/**
 * Availability_Service の無料版デフォルトスタッフ・フォールバックを検証するテストクラス。
 *
 * @group availability
 * @group free-default-staff-fallback
 */
class Resolve_Staff_Ids_Free_Default_Staff_Fallback_Test extends WP_UnitTestCase {

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
	 * resolve_staff_ids() の無料版フォールバックを検証する。
	 *
	 * 「公開中の resource がちょうど1件ならそれを基本スタッフとして使う」という
	 * Resource_Post_Type::get_default_staff_id() の解決結果に依存するため、
	 * 事前に既存の公開済み resource を全て下書きへ落とし、状態を確定させてから
	 * 基本スタッフを1件だけ作成する。
	 */
	public function test_resolve_staff_ids_free_edition_ignores_stale_menu_staff_ids(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の挙動のため、有料版ビルドではスキップする（issue #465）。' );
		}

		$this->draft_all_published_resources();
		$default_staff_id = $this->create_staff();

		$menu_id = $this->create_menu();

		$service    = new Availability_Service();
		$reflection = new ReflectionMethod( Availability_Service::class, 'resolve_staff_ids' );
		$reflection->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'メニューの _vkbm_staff_ids が実在しない古いIDを指す場合 => 基本スタッフへフォールバック（正常系）',
				'menu_staff_ids'      => array( 999999 ),
				'preferred_staff'     => 0,
				'expected'            => array( $default_staff_id ),
			),
			array(
				'test_condition_name' => 'メニューの _vkbm_staff_ids が空の場合 => 基本スタッフへフォールバック（正常系）',
				'menu_staff_ids'      => array(),
				'preferred_staff'     => 0,
				'expected'            => array( $default_staff_id ),
			),
			array(
				'test_condition_name' => 'resource_id に古い／実在しないIDが明示指定された場合 => 信用せず基本スタッフへフォールバック（異常系・#465レビュー指摘）',
				'menu_staff_ids'      => array( 999999 ),
				'preferred_staff'     => 424242,
				'expected'            => array( $default_staff_id ),
			),
		);

		foreach ( $test_cases as $case ) {
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['menu_staff_ids'] );
			$menu_post = get_post( $menu_id );

			$result = $reflection->invoke( $service, $menu_post, $case['preferred_staff'] );

			$this->assertIsArray( $result, $case['test_condition_name'] );
			$this->assertSame( $case['expected'], $result, $case['test_condition_name'] );
		}
	}

	/**
	 * デモサイトで実際に発生した状態（基本スタッフのシフトは正しく登録されているが、
	 * メニューの `_vkbm_staff_ids` が古い／実在しないIDを指している）を再現し、
	 * resource_id 未指定の通常フローでも正しく予約枠・シフトが解決されることを検証する。
	 *
	 * 修正前は resolve_staff_ids() が古いIDをそのまま使うため、そのIDのシフトが
	 * 存在せず全日程予約不可になり shift_too_short_for_duration が返っていた（red）。
	 * 修正後は基本スタッフへフォールバックし、null（予約可能日あり）が返る（green）。
	 */
	public function test_get_unavailability_reason_free_edition_stale_menu_staff_ids_still_bookable(): void {
		if ( Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版限定の再現テストのため、有料版ビルドではスキップする（issue #465）。' );
		}

		$this->draft_all_published_resources();
		$default_staff_id = $this->create_staff();

		// 判定対象の月は「実行日から2ヶ月後」に固定する（他のテストと同じ方針）。
		$now    = current_datetime();
		$target = $now->modify( '+2 months' );
		$year   = (int) $target->format( 'Y' );
		$month  = (int) $target->format( 'n' );

		$menu_id = $this->create_menu();
		// デモサイトで確認された状態を再現: 実在しない／基本スタッフとは異なる古いIDが残っている。
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( 999999 ) );
		// デモサイトと同じく、所要時間130分 + 前後作業10分でも十分収まる勤務時間を用意する。
		update_post_meta( $menu_id, '_vkbm_duration_minutes', 130 );
		update_post_meta( $menu_id, '_vkbm_buffer_after_minutes', 10 );

		// 基本スタッフの勤務時間はデモサイトと同じく10:00〜19:00で正しく登録されている。
		$this->create_full_month_shift(
			$default_staff_id,
			$year,
			$month,
			'open',
			array(
				array(
					'start' => '10:00',
					'end'   => '19:00',
				),
			)
		);

		$service = new Availability_Service();

		$reason = $service->get_unavailability_reason(
			array(
				'menu_id'  => $menu_id,
				'year'     => $year,
				'month'    => $month,
				'timezone' => 'Asia/Tokyo',
			)
		);

		$this->assertNull(
			$reason,
			'基本スタッフのシフトが正しく登録されていれば、メニューの古い担当設定に関わらず予約可能と判定されるべき'
		);

		$calendar = $service->get_calendar_meta(
			array(
				'menu_id'  => $menu_id,
				'year'     => $year,
				'month'    => $month,
				'timezone' => 'Asia/Tokyo',
			)
		);

		$this->assertIsArray( $calendar, 'get_calendar_meta() はエラーではなく配列を返すべき' );

		$has_available_day = false;
		foreach ( $calendar['days'] as $day ) {
			if ( $day['available_slots'] > 0 ) {
				$has_available_day = true;
				break;
			}
		}

		$this->assertTrue(
			$has_available_day,
			'基本スタッフの勤務時間（10:00〜19:00）から、予約可能な枠が1件も生成されないのはおかしい'
		);
	}

	/**
	 * Pro版（Staff_Editor::is_enabled() === true）の既存の担当スタッフ限定挙動が、
	 * 無料版フォールバックの追加によって変わらないことを検証する（回帰確認）。
	 *
	 * 安藤さんレビュー指摘: 「担当リストが空 + resource_id 明示指定」「担当リストに
	 * 含まれる場合」の真理値表が未カバーだったため、既存の2ケースに追加した。
	 */
	public function test_resolve_staff_ids_pro_edition_unaffected_by_free_fallback(): void {
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( 'Pro版限定の回帰確認のため、無料版ビルドではスキップする（issue #465）。' );
		}

		$assigned_staff = $this->create_staff();
		$other_staff    = $this->create_staff();

		$service    = new Availability_Service();
		$reflection = new ReflectionMethod( Availability_Service::class, 'resolve_staff_ids' );
		$reflection->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => '担当リストあり + resource_id 未指定 => メニューの担当設定どおり（正常系・従来どおり）',
				'menu_staff_ids'      => array( 'assigned' ),
				'preferred_staff'     => 0,
				'expected_is_error'   => false,
				'expected_ids'        => array( 'assigned' ),
			),
			array(
				'test_condition_name' => '担当リストが空 + resource_id 明示指定 => 指定IDを使用（正常系・従来どおり）',
				'menu_staff_ids'      => array(),
				'preferred_staff'     => 'other',
				'expected_is_error'   => false,
				'expected_ids'        => array( 'other' ),
			),
			array(
				'test_condition_name' => '担当リストに resource_id が含まれる場合 => そのIDを使用（正常系・従来どおり）',
				'menu_staff_ids'      => array( 'assigned', 'other' ),
				'preferred_staff'     => 'assigned',
				'expected_is_error'   => false,
				'expected_ids'        => array( 'assigned' ),
			),
			array(
				'test_condition_name' => '担当リストに resource_id が含まれない場合 => staff_not_assigned エラー（異常系・境界値・従来どおり）',
				'menu_staff_ids'      => array( 'assigned' ),
				'preferred_staff'     => 'other',
				'expected_is_error'   => true,
			),
		);

		// 'assigned' / 'other' というプレースホルダーを実際の投稿IDへ解決するヘルパー。
		$resolve = static function ( $value ) use ( $assigned_staff, $other_staff ) {
			if ( 'assigned' === $value ) {
				return $assigned_staff;
			}
			if ( 'other' === $value ) {
				return $other_staff;
			}
			return (int) $value;
		};

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_staff_ids', array_map( $resolve, $case['menu_staff_ids'] ) );
			$menu_post = get_post( $menu_id );

			$result = $reflection->invoke( $service, $menu_post, $resolve( $case['preferred_staff'] ) );

			if ( $case['expected_is_error'] ) {
				$this->assertInstanceOf( WP_Error::class, $result, $case['test_condition_name'] );
				$this->assertSame( 'staff_not_assigned', $result->get_error_code(), $case['test_condition_name'] );
			} else {
				$this->assertSame( array_map( $resolve, $case['expected_ids'] ), $result, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 既存の公開済みリソース（スタッフ）投稿を全て下書きに落とす。
	 *
	 * Resource_Post_Type::get_default_staff_id() は「公開中の resource がちょうど1件」を
	 * 優先して解決するため、テストシナリオを確定させるために事前に呼び出す。
	 */
	private function draft_all_published_resources(): void {
		$published_ids = get_posts(
			array(
				'post_type'      => Resource_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $published_ids as $post_id ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
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
	 * @return int メニュー投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
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
		// タイムゾーン依存のズレを避けるため、DateTimeImmutable ベースで月の日数を算出する
		// （test-get-unavailability-reason.php と同じ方針）。
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
