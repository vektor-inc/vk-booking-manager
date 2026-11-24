<?php
/**
 * 通知メールの予約内容に出す人数の行のテスト（#1126）。
 *
 * @package VK_Booking_Manager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Notifications;

use ReflectionMethod;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 人数の行を出す条件のテスト。
 *
 * @group notifications
 */
class Booking_Notification_Guests_Line_Test extends WP_UnitTestCase {

	/**
	 * 人数の行は、2 名以上の予約と、複数人予約を受け付けるメニューの予約（1 名でも）に出す（#1126）。
	 *
	 * 予約確定・仮予約・キャンセル・リマインダーの本文は build_booking_payload()、
	 * 確認メールの本文は build_draft_payload() の payload から get_reservation_information_lines() で
	 * 組み立てるため、両方の payload を通して確かめる。
	 * 料金区分の内訳がある予約でも、人数の行は 1 行だけ出ること（重複しないこと）も確かめる。
	 * 予約枠の定員機能が OFF のサイトは人数を入力できないため、メニューの設定に関わらず 1 名では出さない。
	 */
	public function test_get_reservation_information_lines(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人一括予約は Pro 版限定のため、無料版ではスキップする。' );
		}

		$service       = new Booking_Notification_Service( new Settings_Repository() );
		$booking_build = new ReflectionMethod( Booking_Notification_Service::class, 'build_booking_payload' );
		$booking_build->setAccessible( true );
		$draft_build = new ReflectionMethod( Booking_Notification_Service::class, 'build_draft_payload' );
		$draft_build->setAccessible( true );
		$lines_build = new ReflectionMethod( Booking_Notification_Service::class, 'get_reservation_information_lines' );
		$lines_build->setAccessible( true );

		$multi_menu_id  = $this->create_menu( '複数人で予約できるメニュー', true );
		$single_menu_id = $this->create_menu( '1人ずつ予約するメニュー', false );

		// 人数の行の頭（見出し + 区切り）。本番と同じ翻訳から組み立て、ロケールに依存しないようにする。
		$guests_line_prefix = sprintf( __( '%1$s: %2$s', 'vk-booking-manager' ), vkbm_get_guests_count_label(), '' );
		$tiers_two          = array(
			array(
				'label' => '大人',
				'price' => 3000,
				'count' => 1,
			),
			array(
				'label' => '子ども',
				'price' => 1500,
				'count' => 1,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '複数人予約が有効なメニュー・1名の予約 => 人数の行を 1 行出す',
				'source'              => 'booking',
				'menu_id'             => $multi_menu_id,
				'guests'              => 1,
				'guest_tiers'         => array(),
				'expected_multi_flag' => true,
				'expected_lines'      => array( $this->guests_line( 1 ) ),
				'expected_line_count' => 1,
			),
			array(
				'test_condition_name' => '複数人予約が有効なメニュー・2名の予約 => 人数の行を 1 行出す',
				'source'              => 'booking',
				'menu_id'             => $multi_menu_id,
				'guests'              => 2,
				'guest_tiers'         => array(),
				'expected_multi_flag' => true,
				'expected_lines'      => array( $this->guests_line( 2 ) ),
				'expected_line_count' => 1,
			),
			array(
				'test_condition_name' => '複数人予約が有効なメニュー・料金区分の内訳つき2名の予約 => 人数の行は 1 行だけで、区分の行も出す',
				'source'              => 'booking',
				'menu_id'             => $multi_menu_id,
				'guests'              => 2,
				'guest_tiers'         => $tiers_two,
				'expected_multi_flag' => true,
				'expected_lines'      => array(
					$this->guests_line( 2 ),
					sprintf( __( '%1$s: %2$s', 'vk-booking-manager' ), '大人', vkbm_format_guests_count( 1 ) ),
					sprintf( __( '%1$s: %2$s', 'vk-booking-manager' ), '子ども', vkbm_format_guests_count( 1 ) ),
				),
				'expected_line_count' => 1,
			),
			array(
				'test_condition_name' => '複数人予約が無効なメニュー・1名の予約 => 人数の行を出さない（従来どおり）',
				'source'              => 'booking',
				'menu_id'             => $single_menu_id,
				'guests'              => 1,
				'guest_tiers'         => array(),
				'expected_multi_flag' => false,
				'expected_lines'      => array(),
				'expected_line_count' => 0,
			),
			array(
				'test_condition_name' => '複数人予約が無効なメニュー・2名の予約 => 人数の行を出す（従来どおり）',
				'source'              => 'booking',
				'menu_id'             => $single_menu_id,
				'guests'              => 2,
				'guest_tiers'         => array(),
				'expected_multi_flag' => false,
				'expected_lines'      => array( $this->guests_line( 2 ) ),
				'expected_line_count' => 1,
			),
			array(
				'test_condition_name' => '予約枠の定員機能が OFF・複数人予約が有効なメニュー・1名の予約 => 人数の行を出さない',
				'source'              => 'booking',
				'settings'            => array( 'slot_capacity_enabled' => false ),
				'menu_id'             => $multi_menu_id,
				'guests'              => 1,
				'guest_tiers'         => array(),
				'expected_multi_flag' => false,
				'expected_lines'      => array(),
				'expected_line_count' => 0,
			),
			array(
				'test_condition_name' => 'メニューが存在しない予約・1名 => 人数の行を出さない',
				'source'              => 'booking',
				'menu_id'             => 99999999,
				'guests'              => 1,
				'guest_tiers'         => array(),
				'expected_multi_flag' => false,
				'expected_lines'      => array(),
				'expected_line_count' => 0,
			),
			array(
				'test_condition_name' => '確認メール・複数人予約が有効なメニュー・1名 => 人数の行を 1 行出す',
				'source'              => 'draft',
				'menu_id'             => $multi_menu_id,
				'guests'              => 1,
				'guest_tiers'         => array(),
				'expected_multi_flag' => true,
				'expected_lines'      => array( $this->guests_line( 1 ) ),
				'expected_line_count' => 1,
			),
			array(
				'test_condition_name' => '確認メール・複数人予約が無効なメニュー・1名 => 人数の行を出さない',
				'source'              => 'draft',
				'menu_id'             => $single_menu_id,
				'guests'              => 1,
				'guest_tiers'         => array(),
				'expected_multi_flag' => false,
				'expected_lines'      => array(),
				'expected_line_count' => 0,
			),
		);

		$original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		try {
			foreach ( $test_cases as $case ) {
				// 設定を指定したケースだけ保存し、それ以外は未保存（予約枠の定員機能は既定で ON）にする。
				delete_option( Settings_Repository::OPTION_KEY );
				if ( isset( $case['settings'] ) ) {
					update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
				}
				Staff_Editor::clear_nomination_enabled_cache();

				$this->assert_guests_line( $case, $service, $booking_build, $draft_build, $lines_build, $guests_line_prefix );
			}
		} finally {
			if ( false === $original_settings ) {
				delete_option( Settings_Repository::OPTION_KEY );
			} else {
				update_option( Settings_Repository::OPTION_KEY, $original_settings );
			}
			Staff_Editor::clear_nomination_enabled_cache();
		}
	}

	/**
	 * 1 ケース分の payload を組み立て、人数の行を確かめる。
	 *
	 * @param array                        $case               テストケース。
	 * @param Booking_Notification_Service $service            通知サービス。
	 * @param ReflectionMethod             $booking_build      build_booking_payload()。
	 * @param ReflectionMethod             $draft_build        build_draft_payload()。
	 * @param ReflectionMethod             $lines_build        get_reservation_information_lines()。
	 * @param string                       $guests_line_prefix 人数の行の頭。
	 * @return void
	 */
	private function assert_guests_line( array $case, Booking_Notification_Service $service, ReflectionMethod $booking_build, ReflectionMethod $draft_build, ReflectionMethod $lines_build, string $guests_line_prefix ): void {
		if ( 'draft' === $case['source'] ) {
			$payload = $draft_build->invoke(
				$service,
				array(
					'menu_id' => $case['menu_id'],
					'guests'  => $case['guests'],
				),
				''
			);
		} else {
			$payload = $booking_build->invoke( $service, $this->create_booking( $case['menu_id'], $case['guests'], $case['guest_tiers'] ) );
		}
		$actual_lines = $lines_build->invoke( $service, $payload );

		$this->assertSame( $case['expected_multi_flag'], $payload['multi_guest_enabled'], $case['test_condition_name'] . '（payload の複数人予約フラグ）' );
		foreach ( $case['expected_lines'] as $expected_line ) {
			$this->assertContains( $expected_line, $actual_lines, $case['test_condition_name'] . " => '{$expected_line}' を含むべき" );
		}
		$guests_lines = array_filter(
			$actual_lines,
			static function ( string $line ) use ( $guests_line_prefix ): bool {
				return 0 === strpos( $line, $guests_line_prefix );
			}
		);
		$this->assertCount( $case['expected_line_count'], $guests_lines, $case['test_condition_name'] . '（人数の行の数）' );
	}

	/**
	 * 人数の行の文字列を、本番と同じ翻訳から組み立てる。
	 *
	 * @param int $guests 人数。
	 * @return string 人数の行。
	 */
	private function guests_line( int $guests ): string {
		return sprintf( __( '%1$s: %2$s', 'vk-booking-manager' ), vkbm_get_guests_count_label(), vkbm_format_guests_count( $guests ) );
	}

	/**
	 * 時間枠のサービスメニューを作る。
	 *
	 * @param string $title       タイトル。
	 * @param bool   $allow_multi 複数人予約を受け付けるか。
	 * @return int 投稿 ID。
	 */
	private function create_menu( string $title, bool $allow_multi ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		if ( $allow_multi ) {
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', '1' );
		}

		return $menu_id;
	}

	/**
	 * 予約を作る。
	 *
	 * @param int   $menu_id     サービスメニューの ID。
	 * @param int   $guests      人数。
	 * @param array $guest_tiers 料金区分ごとの人数（空なら保存しない）。
	 * @return int 予約の投稿 ID。
	 */
	private function create_booking( int $menu_id, int $guests, array $guest_tiers ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '人数の行のテスト用の予約',
			)
		);
		update_post_meta( $booking_id, '_vkbm_booking_service_id', $menu_id );
		update_post_meta( $booking_id, '_vkbm_booking_service_start', '2026-10-10 10:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_service_end', '2026-10-10 11:00:00' );
		update_post_meta( $booking_id, '_vkbm_booking_status', 'confirmed' );
		update_post_meta( $booking_id, '_vkbm_booking_guests', $guests );
		if ( array() !== $guest_tiers ) {
			update_post_meta( $booking_id, '_vkbm_booking_guest_tiers', $guest_tiers );
		}

		return $booking_id;
	}
}
