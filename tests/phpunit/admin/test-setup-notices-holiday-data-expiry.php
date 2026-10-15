<?php
/**
 * 祝日データ切れの案内 Setup_Notices::render_holiday_data_expiry_notice() のテスト（#733）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use DateTimeImmutable;
use DateTimeZone;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Holiday_Calendar;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 表示条件（しきい値の前後・各基準日）と権限による出し分けを検証する。
 *
 * @group admin
 * @group holiday
 */
class Setup_Notices_Holiday_Data_Expiry_Test extends WP_UnitTestCase {
	/**
	 * 各テスト後に設定・祝日データ・ユーザーを元に戻す。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		Industry_Presets::clear_cache();
		remove_all_filters( 'vkbm_holiday_calendar_data' );
		Holiday_Calendar::reset_cache();
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * get_holiday_data_required_until() が 3 つの基準日のうち最も先の日を返すことを検証する。
	 */
	public function test_get_holiday_data_required_until(): void {
		$today = new DateTimeImmutable( '2026-10-12 09:00:00', new DateTimeZone( 'Asia/Tokyo' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '予約受付期間なし・自動登録なし => 今日+3か月',
				'max_advance_days'    => 0,
				'auto_months'         => 0,
				'expected'            => '2027-01-12',
			),
			array(
				'test_condition_name' => '予約受付期間が 3か月より短い（30日） => 今日+3か月',
				'max_advance_days'    => 30,
				'auto_months'         => 0,
				'expected'            => '2027-01-12',
			),
			array(
				'test_condition_name' => '予約受付期間が長い（365日） => 今日+365日',
				'max_advance_days'    => 365,
				'auto_months'         => 0,
				'expected'            => '2027-10-12',
			),
			array(
				'test_condition_name' => '自動登録 3か月 => 3か月先の月の末日',
				'max_advance_days'    => 0,
				'auto_months'         => 3,
				'expected'            => '2027-01-31',
			),
			array(
				'test_condition_name' => '自動登録 1か月（今日+3か月のほうが先） => 今日+3か月',
				'max_advance_days'    => 0,
				'auto_months'         => 1,
				'expected'            => '2027-01-12',
			),
			array(
				'test_condition_name' => '自動登録の許可外の値（9） => 無効扱いで今日+3か月',
				'max_advance_days'    => 0,
				'auto_months'         => 9,
				'expected'            => '2027-01-12',
			),
			array(
				'test_condition_name' => '予約受付期間と自動登録の両方 => 先のほう（予約受付期間）',
				'max_advance_days'    => 400,
				'auto_months'         => 3,
				'expected'            => '2027-11-16',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Setup_Notices::get_holiday_data_required_until( $today, $case['max_advance_days'], $case['auto_months'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 同梱データの最終日と必要な最終日の大小で、案内の表示有無が変わることを検証する。
	 */
	public function test_render_holiday_data_expiry_notice_threshold(): void {
		wp_set_current_user( $this->create_administrator() );

		$required_year = (int) ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+3 months' )->format( 'Y' );

		$test_cases = array(
			array(
				'test_condition_name' => 'データ最終日が必要な最終日より前 => 表示される',
				'covered_until_year'  => $required_year - 1,
				'expect_notice'       => true,
			),
			array(
				'test_condition_name' => 'データ最終日が必要な最終日以後 => 表示されない',
				'covered_until_year'  => $required_year,
				'expect_notice'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_holiday_data_year( $case['covered_until_year'] );

			$output = $this->capture_notice();

			if ( $case['expect_notice'] ) {
				$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
			} else {
				$this->assertSame( '', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 必要な最終日がデータ最終日の 1 日後なら表示され、同日なら表示されないこと（境界）を検証する。
	 */
	public function test_render_holiday_data_expiry_notice_boundary(): void {
		wp_set_current_user( $this->create_administrator() );

		// 予約受付期間を「今日から翌年 1/1 まで」の日数にして、必要な最終日を翌年 1/1 にする。
		$timezone    = wp_timezone();
		$today       = new DateTimeImmutable( 'today', $timezone );
		$base_year   = (int) $today->modify( '+3 months' )->format( 'Y' );
		$new_year    = new DateTimeImmutable( sprintf( '%d-01-01', $base_year + 1 ), $timezone );
		$days_to_new = (int) $today->diff( $new_year )->days;

		update_option( Settings_Repository::OPTION_KEY, array( 'provider_max_advance_booking_days' => $days_to_new ) );

		$this->set_holiday_data_year( $base_year );
		$this->assertStringContainsString( 'vkbm-notice__warning', $this->capture_notice(), '必要な最終日が翌年 1/1・データが当年末まで => 表示される' );

		$this->set_holiday_data_year( $base_year + 1 );
		$this->assertSame( '', $this->capture_notice(), '必要な最終日が翌年 1/1・データが翌年末まで => 表示されない' );

		// 1 日短くすると必要な最終日は当年 12/31 になり、当年末までのデータで足りる。
		update_option( Settings_Repository::OPTION_KEY, array( 'provider_max_advance_booking_days' => $days_to_new - 1 ) );
		$this->set_holiday_data_year( $base_year );
		$this->assertSame( '', $this->capture_notice(), '必要な最終日が当年 12/31・データが当年末まで => 表示されない' );
	}

	/**
	 * 予約受付期間が必要な最終日を延ばして案内を出すこと（0 は無制限で対象外）を検証する。
	 */
	public function test_render_holiday_data_expiry_notice_settings(): void {
		wp_set_current_user( $this->create_administrator() );

		$required_year = (int) ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+3 months' )->format( 'Y' );
		$this->set_holiday_data_year( $required_year );

		$test_cases = array(
			array(
				'test_condition_name' => '設定なし => 表示されない',
				'settings'            => array(),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '予約受付期間が長い（約3年） => 表示される',
				'settings'            => array( 'provider_max_advance_booking_days' => 1100 ),
				'expect_notice'       => true,
			),
			array(
				'test_condition_name' => '予約受付期間が 0（無制限） => 対象外で表示されない',
				'settings'            => array( 'provider_max_advance_booking_days' => 0 ),
				'expect_notice'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );

			$output = $this->capture_notice();

			if ( $case['expect_notice'] ) {
				$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
			} else {
				$this->assertSame( '', $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 権限による出し分け（更新権限あり=ボタン、店舗設定権限のみ=依頼文、どちらも無し=非表示）を検証する。
	 */
	public function test_render_holiday_data_expiry_notice_capability(): void {
		$this->set_holiday_data_year( 2000 );

		// 管理者（update_plugins と店舗設定の権限あり）。
		wp_set_current_user( $this->create_administrator() );
		$output = $this->capture_notice();
		$this->assertStringContainsString( 'update-core.php', $output, '更新権限あり => 更新ボタンのリンク' );
		$this->assertStringContainsString( 'vkbm-buttons', $output, '更新権限あり => ボタン領域が出る' );
		$this->assertStringNotContainsString( 'notice-dismiss', $output, '閉じるボタンは付けない' );

		// 店舗設定の権限だけ持つユーザー。
		$manager_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $manager_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		wp_set_current_user( $manager_id );
		$output = $this->capture_notice();
		$this->assertStringContainsString( 'vkbm-notice__warning', $output, '店舗設定の権限のみ => 案内は出る' );
		$this->assertStringNotContainsString( 'update-core.php', $output, '店舗設定の権限のみ => 更新ボタンは出ない' );
		$this->assertStringNotContainsString( 'vkbm-buttons', $output, '店舗設定の権限のみ => ボタン領域は出ず、依頼文だけになる' );
		$this->assertSame( 2, substr_count( $output, '<p>' ), '店舗設定の権限のみ => 本文と依頼文の 2 段落' );

		// どちらの権限も無いユーザー。
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->capture_notice(), '権限なし => 表示されない' );
	}

	/**
	 * 宿泊のサイトでは、休前日の料金判定にも影響する旨を案内に添える（#737）。宿泊以外のサイトには添えない。
	 */
	public function test_render_holiday_data_expiry_notice_lodging_sentence(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '宿泊の業種プリセットは Pro 版限定のため、無料版ではスキップする。' );
		}

		wp_set_current_user( $this->create_administrator() );
		$this->set_holiday_data_year( (int) ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+3 months' )->format( 'Y' ) - 1 );
		$sentence = esc_html( __( ' This also affects the pre-holiday pricing of lodging plans.', 'vk-booking-manager' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト => 休前日の料金判定にも影響する一文を添える',
				'preset'              => 'lodging',
				'expect_sentence'     => true,
			),
			array(
				'test_condition_name' => '宿泊以外のサイト => 添えない',
				'preset'              => 'custom',
				'expect_sentence'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			Industry_Presets::clear_cache();
			update_option(
				Settings_Repository::OPTION_KEY,
				array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $case['preset'] ) )
			);

			$output = $this->capture_notice();

			$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] . ': 案内は出る' );
			if ( $case['expect_sentence'] ) {
				$this->assertStringContainsString( $sentence, $output, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( $sentence, $output, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 同梱データの最終年を指定した年にそろえる（その年の元日だけの祝日データへ差し替える）。
	 *
	 * @param int $year データに含める最終年.
	 */
	private function set_holiday_data_year( int $year ): void {
		remove_all_filters( 'vkbm_holiday_calendar_data' );
		add_filter(
			'vkbm_holiday_calendar_data',
			static function () use ( $year ) {
				return array( sprintf( '%d-01-01', $year ) => '元日' );
			}
		);
		Holiday_Calendar::reset_cache();
	}

	/**
	 * 案内の出力を文字列で受け取る。
	 *
	 * @return string 出力.
	 */
	private function capture_notice(): string {
		ob_start();
		( new Setup_Notices() )->render_holiday_data_expiry_notice();
		return (string) ob_get_clean();
	}

	/**
	 * 管理者（update_plugins と店舗設定の権限あり）を作成する。
	 *
	 * @return int ユーザーID.
	 */
	private function create_administrator(): int {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		if ( $user ) {
			$user->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
			$user->add_cap( 'update_plugins' );
		}

		return $user_id;
	}
}
