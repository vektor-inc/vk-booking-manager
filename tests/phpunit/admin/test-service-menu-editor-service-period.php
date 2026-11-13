<?php
/**
 * サービスメニュー編集画面の「提供期間」欄の保存・通知・描画のテスト（#972）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use DateTimeImmutable;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\Common\Service_Period;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;
use function get_post;
use function get_post_meta;
use function get_transient;
use function delete_transient;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * 提供期間欄の保存と描画を検証するテストクラス。
 *
 * @group admin
 * @group service-period
 */
class Service_Menu_Editor_Service_Period_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/**
	 * テスト前の timezone_string。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * 管理者としてログインし、タイムゾーンを固定する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * ログイン状態・$_POST・通知・タイムゾーンを元に戻す。
	 */
	protected function tearDown(): void {
		delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
		wp_set_current_user( 0 );
		$_POST = array();
		update_option( 'timezone_string', $this->original_timezone_string );
		parent::tearDown();
	}

	/**
	 * サービスメニューを作る。
	 *
	 * @return int 投稿 ID。
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
	 * 編集画面の保存を、送信内容を指定して実行する。
	 *
	 * @param int                  $menu_id 投稿 ID。
	 * @param array<string, mixed> $fields  vkbm_service_menu として送る値。
	 */
	private function submit( int $menu_id, array $fields ): void {
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => $fields,
		);
		( new Service_Menu_Editor() )->save_post( $menu_id, get_post( $menu_id ) );
	}

	/**
	 * 今日から指定日数後の日付（サイトのタイムゾーン）を返す。
	 *
	 * @param int $days 日数。
	 * @return string Y-m-d。
	 */
	private function date_after( int $days ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * 保存: 両方・片側・空欄・過去の終了日・不正な値の扱い。
	 */
	public function test_save_post_saves_service_period(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '開始日・終了日とも入力 => 両方保存される',
				'fields'              => array(
					'service_period_start' => '2027-07-20',
					'service_period_end'   => '2027-08-31',
				),
				'expected'            => array(
					'start' => '2027-07-20',
					'end'   => '2027-08-31',
				),
			),
			array(
				'test_condition_name' => '開始日のみ => 終了日は保存されない（無期限）',
				'fields'              => array(
					'service_period_start' => '2027-07-20',
					'service_period_end'   => '',
				),
				'expected'            => array(
					'start' => '2027-07-20',
					'end'   => '',
				),
			),
			array(
				'test_condition_name' => '終了日のみ => 開始日は保存されない（無期限）',
				'fields'              => array(
					'service_period_start' => '',
					'service_period_end'   => '2027-08-31',
				),
				'expected'            => array(
					'start' => '',
					'end'   => '2027-08-31',
				),
			),
			array(
				'test_condition_name' => '両方空欄 => 何も保存されない',
				'fields'              => array(
					'service_period_start' => '',
					'service_period_end'   => '',
				),
				'expected'            => array(
					'start' => '',
					'end'   => '',
				),
			),
			array(
				'test_condition_name' => '過去の終了日 => そのまま保存できる（終了済みの状態を残すため）',
				'fields'              => array(
					'service_period_start' => '2020-01-01',
					'service_period_end'   => '2020-12-31',
				),
				'expected'            => array(
					'start' => '2020-01-01',
					'end'   => '2020-12-31',
				),
			),
			array(
				'test_condition_name' => '開始日と終了日が同じ => 保存できる（1日だけ）',
				'fields'              => array(
					'service_period_start' => '2027-08-01',
					'service_period_end'   => '2027-08-01',
				),
				'expected'            => array(
					'start' => '2027-08-01',
					'end'   => '2027-08-01',
				),
			),
			array(
				'test_condition_name' => '日付として読めない値 => 無期限として保存される',
				'fields'              => array(
					'service_period_start' => '2027-02-30',
					'service_period_end'   => 'abc',
				),
				'expected'            => array(
					'start' => '',
					'end'   => '',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();
			$this->submit( $menu_id, $case['fields'] );

			$this->assertSame( $case['expected'], Service_Period::get_period( $menu_id ), $case['test_condition_name'] );
			$this->assertFalse( get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() ), $case['test_condition_name'] . '（通知は出ない）' );
			if ( '' === $case['expected']['start'] ) {
				$this->assertFalse( metadata_exists( 'post', $menu_id, Service_Period::META_START ), $case['test_condition_name'] . '（空の開始日はメタを持たない）' );
			}
		}
	}

	/**
	 * 保存: 空欄にして保存すると、前に保存した期間が消えて無期限に戻る。
	 */
	public function test_save_post_clears_service_period_when_blank(): void {
		$menu_id = $this->create_menu();
		$this->submit(
			$menu_id,
			array(
				'service_period_start' => '2027-07-20',
				'service_period_end'   => '2027-08-31',
			)
		);
		$this->submit(
			$menu_id,
			array(
				'service_period_start' => '',
				'service_period_end'   => '',
			)
		);

		$this->assertSame(
			array(
				'start' => '',
				'end'   => '',
			),
			Service_Period::get_period( $menu_id )
		);
	}

	/**
	 * 保存: 開始日が終了日より後なら、前回の値を保ち、通知で知らせる。
	 */
	public function test_save_post_keeps_previous_values_when_reversed(): void {
		$menu_id = $this->create_menu();
		$this->submit(
			$menu_id,
			array(
				'service_period_start' => '2027-07-20',
				'service_period_end'   => '2027-08-31',
			)
		);
		$this->assertFalse( get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() ) );

		$this->submit(
			$menu_id,
			array(
				'service_period_start' => '2027-09-01',
				'service_period_end'   => '2027-08-31',
			)
		);

		$this->assertSame(
			array(
				'start' => '2027-07-20',
				'end'   => '2027-08-31',
			),
			Service_Period::get_period( $menu_id ),
			'逆転した入力は保存されず、前回の値が残る'
		);
		$this->assertSame(
			\VKBookingManager\Common\Nomination_Min_Guests_Message::join_sentences(
				__( 'The start of the service period was later than its end, so the service period was saved with its previous values.', 'vk-booking-manager' ),
				__( 'Check the dates and save again.', 'vk-booking-manager' )
			),
			get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() ),
			'前回の内容のまま保存した旨の通知が出る'
		);
	}

	/**
	 * 保存: フォームに提供期間の欄が含まれない送信では、保存済みの値に触れない。
	 */
	public function test_save_post_leaves_period_when_fields_are_not_sent(): void {
		$menu_id = $this->create_menu();
		$this->submit(
			$menu_id,
			array(
				'service_period_start' => '2027-07-20',
				'service_period_end'   => '2027-08-31',
			)
		);
		$this->submit( $menu_id, array( 'catch_copy' => 'キャッチ' ) );

		$this->assertSame(
			array(
				'start' => '2027-07-20',
				'end'   => '2027-08-31',
			),
			Service_Period::get_period( $menu_id )
		);
	}

	/**
	 * 描画: 「予約可能日」の直上に提供期間欄があり、値・min・終了済みの案内が反映される。
	 */
	public function test_render_conditions_meta_box_renders_service_period_field(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '終了日が昨日 => 終了済みの案内が表示される',
				'start'               => $this->date_after( -30 ),
				'end'                 => $this->date_after( -1 ),
				'ended_visible'       => true,
			),
			array(
				'test_condition_name' => '終了日が今日 => 終了済みの案内は隠れる',
				'start'               => '',
				'end'                 => $this->date_after( 0 ),
				'ended_visible'       => false,
			),
			array(
				'test_condition_name' => '期間なし => 終了済みの案内は隠れる',
				'start'               => '',
				'end'                 => '',
				'ended_visible'       => false,
			),
			array(
				'test_condition_name' => '終了した1日のみ => 開催日の終了案内が表示される',
				'start'               => $this->date_after( -1 ),
				'end'                 => $this->date_after( -1 ),
				'mode'                => 'single',
				'ended_visible'       => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();
			if ( '' !== $case['start'] ) {
				update_post_meta( $menu_id, Service_Period::META_START, $case['start'] );
			}
			if ( '' !== $case['end'] ) {
				update_post_meta( $menu_id, Service_Period::META_END, $case['end'] );
			}
			if ( isset( $case['mode'] ) ) {
				update_post_meta( $menu_id, Service_Period::META_MODE, $case['mode'] );
			}

			ob_start();
			( new Service_Menu_Editor() )->render_conditions_meta_box( get_post( $menu_id ) );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'name="vkbm_service_menu[service_period_start]"', $html, $case['test_condition_name'] );
			$this->assertStringContainsString( 'name="vkbm_service_menu[service_period_end]"', $html, $case['test_condition_name'] );
			$this->assertStringContainsString( '<label id="vkbm-service-period-label" for="vkbm_service_menu_service_period_start">' . __( 'Service period', 'vk-booking-manager' ) . '</label>', $html, $case['test_condition_name'] );
			$this->assertLessThan(
				strpos( $html, 'id="vkbm_service_menu_reservation_day_type"' ),
				strpos( $html, 'id="vkbm-service-period-field"' ),
				$case['test_condition_name'] . '（予約可能日の直上にある）'
			);
			// 「〜」は読み上げない。
			$this->assertStringContainsString( '<span class="vkbm-service-period-delimiter" aria-hidden="true">' . _x( '~', 'separator between the start date and the end date of the service period', 'vk-booking-manager' ) . '</span>', $html, $case['test_condition_name'] );
			if ( '' !== $case['start'] ) {
				$this->assertStringContainsString( 'value="' . $case['start'] . '"', $html, $case['test_condition_name'] );
				$this->assertStringContainsString( 'min="' . $case['start'] . '"', $html, $case['test_condition_name'] . '（終了日の min が開始日に連動）' );
			}

			$this->assertSame( 1, preg_match( '/<p\b[^>]*\bid="vkbm-service-period-ended"[^>]*>/', $html, $ended_match ), $case['test_condition_name'] );
			$this->assertSame( ! $case['ended_visible'], (bool) preg_match( '/\shidden(?:\s|=|>)/', $ended_match[0] ), $case['test_condition_name'] . '（終了済みの案内）' );
			if ( 'single' === ( $case['mode'] ?? 'range' ) ) {
				$this->assertStringContainsString( __( 'This event date has ended.', 'vk-booking-manager' ), $html );
			}
			$ended_label = 'single' === ( $case['mode'] ?? 'range' ) ? __( 'This event date has ended.', 'vk-booking-manager' ) : __( 'This period has ended.', 'vk-booking-manager' );
			$this->assertStringContainsString(
				'<span id="vkbm-service-period-ended-label">' . esc_html( $ended_label ) . '</span>' . substr( \VKBookingManager\Common\Nomination_Min_Guests_Message::join_sentences( $ended_label, __( 'The menu is not shown in the menu list on the reservation page.', 'vk-booking-manager' ) ), strlen( $ended_label ) ),
				$html,
				$case['test_condition_name'] . '（文の区切り）'
			);
		}
	}

	/**
	 * 一覧: 「提供期間／予約可能日」の統合列に、期間（上）と予約可能日（下）を出し、指定のない側は出さない。
	 */
	public function test_list_column_shows_period_and_ended_state(): void {
		$post_type = new Service_Menu_Post_Type();
		$render    = function ( int $post_id ) use ( $post_type ): string {
			ob_start();
			$post_type->render_admin_columns( 'vkbm_reservation_day_type', $post_id );
			return trim( (string) ob_get_clean() );
		};

		$none = $this->create_menu();
		$this->assertSame( '—', $render( $none ), '期間も予約可能日もなし => ダッシュ' );

		$day_only = $this->create_menu();
		update_post_meta( $day_only, '_vkbm_reservation_day_type', 'weekend' );
		$this->assertSame( esc_html( \VKBookingManager\Common\Reservation_Day::label( 'weekend' ) ), $render( $day_only ), '予約可能日だけ => その文言だけ' );

		$ended = $this->create_menu();
		update_post_meta( $ended, Service_Period::META_START, $this->date_after( -30 ) );
		update_post_meta( $ended, Service_Period::META_END, $this->date_after( -1 ) );
		$html = $render( $ended );
		$this->assertStringContainsString( '<br>', $html, '開始日と終了日は区切りの後で改行される' );
		$this->assertStringContainsString( __( 'Ended', 'vk-booking-manager' ), $html, '終了済みの表示が付く' );
		$this->assertStringNotContainsString( \VKBookingManager\Common\Reservation_Day::label( 'weekend' ), $html, '予約可能日の指定がなければ出さない' );

		// 日付を1行に収める指定（--nowrap）は、日付の言語が日本語のときだけ付く（英語は空白で折り返してはみ出しを防ぐ）。
		$ja_locale = determine_locale();
		if ( 0 === strpos( $ja_locale, 'ja' ) ) {
			$this->assertStringContainsString( 'vkbm-service-period-list--nowrap', $html, '日本語は1行に収める' );
		}
		switch_to_locale( 'en_US' );
		$english_html = $render( $ended );
		restore_previous_locale();
		$this->assertStringContainsString( 'vkbm-service-period-list', $english_html );
		$this->assertStringNotContainsString( 'vkbm-service-period-list--nowrap', $english_html, '英語は空白で折り返せるようにする' );

		$single = $this->create_menu();
		update_post_meta( $single, Service_Period::META_MODE, 'single' );
		update_post_meta( $single, Service_Period::META_START, '2027-12-05' );
		update_post_meta( $single, Service_Period::META_END, '2027-12-05' );
		$this->assertStringContainsString( esc_html__( 'Event date', 'vk-booking-manager' ) . ' <span class="vkbm-service-period__date">' . esc_html( Service_Period::format_single_date( '2027-12-05', true ) ) . '</span>', $render( $single ) );
		// メタを直接変更した場合も、日付が無効なら列はダッシュだけにする。
		foreach ( array( '', '2027-02-30' ) as $invalid_date ) {
			update_post_meta( $single, Service_Period::META_START, $invalid_date );
			$this->assertSame( '—', $render( $single ), '無効な開始日: ' . $invalid_date );
		}

		$both = $this->create_menu();
		update_post_meta( $both, Service_Period::META_END, $this->date_after( 0 ) );
		update_post_meta( $both, '_vkbm_reservation_day_type', 'weekday' );
		$html = $render( $both );
		$this->assertStringNotContainsString( __( 'Ended', 'vk-booking-manager' ), $html, '終了日が今日 => 終了済みの表示は付かない' );
		$label_pos = strpos( $html, esc_html( \VKBookingManager\Common\Reservation_Day::label( 'weekday' ) ) );
		$this->assertNotFalse( $label_pos, '予約可能日が出る' );
		$this->assertLessThan( $label_pos, (int) strpos( $html, 'vkbm-service-period__date' ), '期間が上、予約可能日が下' );
	}

	/**
	 * 一覧: 開始日だけ・終了日だけの期間には強制改行を入れず、両方あるときだけ終了日の直前で改行する。
	 */
	public function test_list_column_breaks_line_only_when_both_period_dates_are_set(): void {
		$post_type = new Service_Menu_Post_Type();
		$render    = function ( int $post_id ) use ( $post_type ): string {
			ob_start();
			$post_type->render_admin_columns( 'vkbm_reservation_day_type', $post_id );
			return trim( (string) ob_get_clean() );
		};

		$start_only = $this->create_menu();
		update_post_meta( $start_only, Service_Period::META_START, $this->date_after( 30 ) );
		$html = $render( $start_only );
		$this->assertStringContainsString( 'vkbm-service-period__date', $html, '開始日は出る' );
		$this->assertStringNotContainsString( '<br>', $html, '開始日だけなら強制改行を入れない（「から」は折り返しに任せる）' );

		$end_only = $this->create_menu();
		update_post_meta( $end_only, Service_Period::META_END, $this->date_after( 30 ) );
		$html = $render( $end_only );
		$this->assertStringContainsString( 'vkbm-service-period__date', $html, '終了日は出る' );
		$this->assertStringNotContainsString( '<br>', $html, '終了日だけなら強制改行を入れない（「まで」は折り返しに任せる）' );

		$both = $this->create_menu();
		update_post_meta( $both, Service_Period::META_START, $this->date_after( 10 ) );
		update_post_meta( $both, Service_Period::META_END, $this->date_after( 30 ) );
		$html = $render( $both );
		$this->assertSame( 1, substr_count( $html, '<br>' ), '両方あるときは1か所だけ改行する' );
		$this->assertMatchesRegularExpression( '#<br><span class="vkbm-service-period__date">[^<]*</span></span>$#u', $html, '改行は終了日の日付の直前に入る' );
	}

	/**
	 * 列見出し: 「提供期間」「予約可能日」の2行で、「提供期間」の単独列は無い。
	 */
	public function test_list_column_header_combines_period_and_reservation_date(): void {
		$post_type = new Service_Menu_Post_Type();
		$columns   = $post_type->filter_admin_columns(
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);
		$this->assertArrayNotHasKey( 'vkbm_service_period', $columns );
		$this->assertSame(
			esc_html__( 'Service period', 'vk-booking-manager' ) . ' <br>' . esc_html__( 'Reservation date', 'vk-booking-manager' ),
			$columns['vkbm_reservation_day_type']
		);
	}
	/** 1日のみは開始日を両端に保存し、無効な日付では前回値を保つ。 */
	public function test_save_post_single_day_preserves_previous_on_invalid_date(): void {
		$menu_id = $this->create_menu();
		$this->submit(
			$menu_id,
			array(
				'service_period_mode'  => 'single',
				'service_period_start' => '2027-12-05',
				'service_period_end'   => '2027-12-06',
			)
		);
		$this->assertSame( 'single', Service_Period::get_mode( $menu_id ) );
		$this->assertSame(
			array(
				'start' => '2027-12-05',
				'end'   => '2027-12-05',
			),
			Service_Period::get_period( $menu_id )
		);
		foreach ( array( '', '2027-02-30' ) as $invalid_date ) {
			$this->submit(
				$menu_id,
				array(
					'service_period_mode'  => 'single',
					'service_period_start' => $invalid_date,
					'service_period_end'   => '',
				)
			);
			$this->assertSame(
				array(
					'start' => '2027-12-05',
					'end'   => '2027-12-05',
				),
				Service_Period::get_period( $menu_id )
			);
			$this->assertNotFalse( get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() ) );
		}
	}
	/** 期間から単日への切り替えで日付が不正なら、モードも期間のまま残す。 */
	public function test_invalid_single_switch_keeps_range_mode(): void {
		$range_menu = $this->create_menu();
		$this->submit(
			$range_menu,
			array(
				'service_period_start' => '2027-12-01',
				'service_period_end'   => '2027-12-10',
			)
		);
		$this->submit(
			$range_menu,
			array(
				'service_period_mode'  => 'single',
				'service_period_start' => 'invalid',
				'service_period_end'   => '',
			)
		);
		$this->assertSame( 'range', Service_Period::get_mode( $range_menu ) );
		$this->assertSame(
			array(
				'start' => '2027-12-01',
				'end'   => '2027-12-10',
			),
			Service_Period::get_period( $range_menu )
		);
	}
}
