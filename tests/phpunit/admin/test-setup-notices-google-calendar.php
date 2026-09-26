<?php
/**
 * Setup_Notices::render_google_calendar_sync_broken_notice() のテスト。
 *
 * issue #476。安藤レビュー指摘（既存の「未設定の項目があります」の枠に混ざっていた問題）への
 * 対応で、独立したお知らせに分けた。権限・理由別の文言・「連携」タブが出ていない間は
 * 出さないことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;
use function add_filter;
use function clean_post_cache;
use function delete_option;
use function get_user_by;
use function ob_get_clean;
use function ob_start;
use function remove_filter;
use function update_option;
use function update_post_meta;
use function wp_set_current_user;

/**
 * Setup_Notices の Google カレンダー連携お知らせのテスト。
 *
 * @group admin
 */
class Setup_Notices_Google_Calendar_Test extends WP_UnitTestCase {

	/**
	 * 中継サーバーの接続先を仮の値に差し替えるフィルターの参照（後片付け用）。
	 *
	 * @var callable|null
	 */
	private $relay_url_filter = null;

	/**
	 * 各テストの前に、中継サーバーの接続先を仮の値に差し替え、フラグを消しておく。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Google カレンダー連携は Pro 版限定機能のため、無料版ビルドでは通知が出ない。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Google カレンダー連携は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->relay_url_filter = static function (): string {
			return 'https://relay.test';
		};
		add_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );

		delete_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN );
	}

	/**
	 * 各テストの後に、差し替えと保存内容を元へ戻す。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->relay_url_filter ) {
			remove_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );
		}

		delete_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * フラグが立っていない・権限が無い・中継サーバー未接続のいずれでも出ないこと、
	 * 条件が揃えば独立したお知らせとして出る（「未設定の項目があります」の見出しに
	 * 混ざらない）ことを検証する（安藤レビュー指摘）。
	 */
	public function test_render_google_calendar_sync_broken_notice_conditions(): void {
		$admin_id = $this->create_user_with_provider_settings_access();

		$test_cases = array(
			array(
				'test_condition_name' => 'フラグが立っていない => 何も出さない（正常系）',
				'reason'              => '',
				'relay_configured'    => true,
				'user_id'             => $admin_id,
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '認可喪失（REASON_AUTH） => 出る。「未設定の項目」の見出しは含まない（正常系）',
				'reason'              => Google_Calendar_Event_Sync::REASON_AUTH,
				'relay_configured'    => true,
				'user_id'             => $admin_id,
				'expect_notice'       => true,
			),
			array(
				'test_condition_name' => '中継サーバー未接続（「連携」タブが無い） => 行き止まりを避けるため出さない（異常系）',
				'reason'              => Google_Calendar_Event_Sync::REASON_AUTH,
				'relay_configured'    => false,
				'user_id'             => $admin_id,
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '「連携」タブを開ける権限が無い => 出さない（異常系）',
				'reason'              => Google_Calendar_Event_Sync::REASON_AUTH,
				'relay_configured'    => true,
				'user_id'             => $this->create_user_without_provider_settings_access(),
				'expect_notice'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN );
			if ( '' !== $case['reason'] ) {
				update_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, $case['reason'], false );
			}

			$relay_filter = static function () use ( $case ): string {
				return $case['relay_configured'] ? 'https://relay.test' : '';
			};
			remove_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );
			add_filter( 'vkbm_google_calendar_relay_url', $relay_filter );

			wp_set_current_user( $case['user_id'] );

			$output = $this->capture(
				function () {
					( new Setup_Notices() )->render_google_calendar_sync_broken_notice();
				}
			);

			remove_filter( 'vkbm_google_calendar_relay_url', $relay_filter );
			add_filter( 'vkbm_google_calendar_relay_url', $this->relay_url_filter );

			if ( $case['expect_notice'] ) {
				$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
				$this->assertStringNotContainsString( 'Please set the following items', $output, $case['test_condition_name'] . '（「未設定の項目があります」の枠に混ざっていないこと）' );
			} else {
				$this->assertSame( '', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 失敗の理由（REASON_AUTH / REASON_OTHER）によって文言が変わることを検証する
	 * （植草レビュー・安藤レビュー指摘: 認可喪失でない失敗にまで「アクセスが失われた
	 * 可能性」と出さない）。
	 */
	public function test_render_google_calendar_sync_broken_notice_message_by_reason(): void {
		wp_set_current_user( $this->create_user_with_provider_settings_access() );

		$test_cases = array(
			array(
				'test_condition_name' => 'REASON_AUTH => 「アクセスが失われた可能性」を示す文言を含む（正常系）',
				'reason'              => Google_Calendar_Event_Sync::REASON_AUTH,
				'expected_contains'   => 'access to Google was lost',
			),
			array(
				'test_condition_name' => 'REASON_OTHER => 認可喪失を示す文言は含まない（正常系）',
				'reason'              => Google_Calendar_Event_Sync::REASON_OTHER,
				'expected_contains'   => null,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, $case['reason'], false );

			$output = $this->capture(
				function () {
					( new Setup_Notices() )->render_google_calendar_sync_broken_notice();
				}
			);

			if ( null !== $case['expected_contains'] ) {
				$this->assertStringContainsString( $case['expected_contains'], $output, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( 'access to Google was lost', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 失敗理由ごとに、案内ボタンの行き先・文言が変わることを検証する（植草レビュー指摘:
	 * REASON_OTHER の文言「「連携」タブで詳細を確認してください。」は、実際には「連携」
	 * タブに失敗の詳細が無く、実際に取れる行動と文言・ボタンの行き先がずれていた）。
	 *
	 * - REASON_AUTH: 再接続が解決策のため、引き続き「連携」タブへ案内する。
	 * - REASON_OTHER: どの予約が失敗しているかは「連携」タブでは分からないため、
	 *   予約一覧へ案内し、文言も個別の予約編集画面での再試行を案内する内容に変える。
	 */
	public function test_render_google_calendar_sync_broken_notice_button_matches_reason(): void {
		wp_set_current_user( $this->create_user_with_provider_settings_access() );

		$test_cases = array(
			array(
				'test_condition_name'    => 'REASON_AUTH => 「連携」タブへのボタン（正常系）',
				'reason'                 => Google_Calendar_Event_Sync::REASON_AUTH,
				'expected_button_text'   => 'Open the Integration tab',
				'expected_button_href'   => 'tab=integration',
				'expected_message_hints' => array( 'Please check the connection on the Integration tab.' ),
			),
			array(
				'test_condition_name'    => 'REASON_OTHER => 予約一覧へのボタンと、個別の再試行を案内する文言（正常系）',
				'reason'                 => Google_Calendar_Event_Sync::REASON_OTHER,
				'expected_button_text'   => 'Open the bookings list',
				'expected_button_href'   => 'edit.php?post_type=vkbm_booking',
				'expected_message_hints' => array( 'bookings list', 'Retry now' ),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, $case['reason'], false );

			$output = $this->capture(
				function () {
					( new Setup_Notices() )->render_google_calendar_sync_broken_notice();
				}
			);

			$this->assertStringContainsString( $case['expected_button_text'], $output, $case['test_condition_name'] . '（ボタン文言）' );
			$this->assertStringContainsString( $case['expected_button_href'], $output, $case['test_condition_name'] . '（ボタンの行き先）' );

			foreach ( $case['expected_message_hints'] as $hint ) {
				$this->assertStringContainsString( $hint, $output, $case['test_condition_name'] . '（案内文）' );
			}
		}
	}

	/**
	 * REASON_OTHER のとき、反映に失敗している予約が最大5件、新しい順（編集画面へのリンク付き）で
	 * 一覧に出ることを検証する（植草レビュー指摘 中: 「連携」タブにはどの予約が失敗している
	 * かの手がかりが無いため、お知らせ自体に対象を並べる）。
	 */
	public function test_render_google_calendar_sync_broken_notice_lists_failed_bookings(): void {
		wp_set_current_user( $this->create_user_with_provider_settings_access() );
		update_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, Google_Calendar_Event_Sync::REASON_OTHER, false );

		// 「新しい順」（modified DESC）を検証できるよう、作成順とは逆になるよう明示的に
		// modified の日時をずらす。
		$oldest_id = $this->create_failed_booking( '一番古い予約', '2026-01-01 00:00:00' );
		$middle_id = $this->create_failed_booking( '真ん中の予約', '2026-01-02 00:00:00' );
		$newest_id = $this->create_failed_booking( '一番新しい予約', '2026-01-03 00:00:00' );

		$output = $this->capture(
			function () {
				( new Setup_Notices() )->render_google_calendar_sync_broken_notice();
			}
		);

		$this->assertStringContainsString( 'Open one of the bookings below', $output, '1件以上一覧に出せたときの案内文になること' );
		$this->assertStringContainsString( 'post.php?post=' . $newest_id . '&#038;action=edit', $output, '一番新しい予約の編集画面へのリンクが出ること' );
		$this->assertStringContainsString( '一番新しい予約', $output, '一番新しい予約のタイトルが出ること' );
		$this->assertStringContainsString( '一番古い予約', $output, '一番古い予約も（3件のみのため）出ること' );
		$this->assertStringNotContainsString( 'There are', $output, '5件以下のときは「ほかn件」を出さないこと' );

		// 新しい順（modified DESC）になっていること。
		$newest_pos = strpos( $output, '一番新しい予約' );
		$middle_pos = strpos( $output, '真ん中の予約' );
		$oldest_pos = strpos( $output, '一番古い予約' );
		$this->assertNotFalse( $newest_pos );
		$this->assertNotFalse( $middle_pos );
		$this->assertNotFalse( $oldest_pos );
		$this->assertLessThan( $middle_pos, $newest_pos, '新しい予約ほど先に出ること' );
		$this->assertLessThan( $oldest_pos, $middle_pos, '新しい予約ほど先に出ること' );

		unset( $oldest_id, $middle_id );
	}

	/**
	 * 失敗中の予約が5件を超える場合は、最大5件だけ出し「ほかn件」を添えることを検証する
	 * （植草レビュー指摘 中）。
	 */
	public function test_render_google_calendar_sync_broken_notice_shows_more_count_beyond_five(): void {
		wp_set_current_user( $this->create_user_with_provider_settings_access() );
		update_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, Google_Calendar_Event_Sync::REASON_OTHER, false );

		for ( $i = 1; $i <= 7; $i++ ) {
			$this->create_failed_booking( '予約' . $i, '2026-01-0' . $i . ' 00:00:00' );
		}

		$output = $this->capture(
			function () {
				( new Setup_Notices() )->render_google_calendar_sync_broken_notice();
			}
		);

		$this->assertStringContainsString( 'There are 2 more.', $output, '7件中5件だけ出し、残り2件を「ほか2件」で示すこと' );
	}

	/**
	 * REASON_AUTH のときは、失敗中の予約があっても一覧を出さないことを検証する（再接続で
	 * 全体が直る想定のため、個別の予約を並べる必要が無い）。
	 */
	public function test_render_google_calendar_sync_broken_notice_does_not_list_bookings_for_auth_reason(): void {
		wp_set_current_user( $this->create_user_with_provider_settings_access() );
		update_option( Google_Calendar_Event_Sync::OPTION_SYNC_BROKEN, Google_Calendar_Event_Sync::REASON_AUTH, false );

		$this->create_failed_booking( '認可喪失時の予約', '2026-01-01 00:00:00' );

		$output = $this->capture(
			function () {
				( new Setup_Notices() )->render_google_calendar_sync_broken_notice();
			}
		);

		$this->assertStringNotContainsString( '認可喪失時の予約', $output, 'REASON_AUTH では個別の予約一覧を出さないこと' );
		$this->assertStringNotContainsString( '<ul>', $output, 'REASON_AUTH では一覧の <ul> 自体を出さないこと' );
	}

	/**
	 * 反映に失敗している予約（`_vkbm_google_calendar_sync_failed` が立っている予約）を作る。
	 *
	 * @param string $title    予約のタイトル（`get_the_title()` の戻り値をテストで識別するため）。
	 * @param string $modified 「新しい順」の検証用に明示的に指定する post_modified（`Y-m-d H:i:s`）。
	 * @return int 予約投稿ID。
	 */
	private function create_failed_booking( string $title, string $modified ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'         => Booking_Post_Type::POST_TYPE,
				'post_status'       => 'publish',
				'post_title'        => $title,
				'post_modified'     => $modified,
				'post_modified_gmt' => $modified,
			)
		);

		update_post_meta( $booking_id, Google_Calendar_Event_Sync::META_SYNC_FAILED, '1' );

		// factory の post_modified 指定は wp_insert_post() 側で現在時刻に上書きされることがあるため、
		// 確実に反映させるため直接 $wpdb で更新し、キャッシュを消す。
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- テストの前提条件（modified 日時）を確実に反映させるための直接更新.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $modified,
				'post_modified_gmt' => $modified,
			),
			array( 'ID' => $booking_id )
		);
		clean_post_cache( $booking_id );

		return $booking_id;
	}

	/**
	 * コールバックの出力を取得する。
	 *
	 * @param callable $callback 出力する処理.
	 * @return string 出力内容.
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * BM設定への権限を持つ管理者ユーザーを作成する。
	 *
	 * @return int ユーザーID.
	 */
	private function create_user_with_provider_settings_access(): int {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		}

		return $user_id;
	}

	/**
	 * BM設定への権限を持たない購読者ユーザーを作成する。
	 *
	 * @return int ユーザーID.
	 */
	private function create_user_without_provider_settings_access(): int {
		return $this->factory()->user->create( array( 'role' => 'subscriber' ) );
	}
}
