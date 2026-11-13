<?php
/**
 * メニューループ（カード・一覧）の提供期間の表示と、終了済みメニューの除外のテスト（#972）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use DateTimeImmutable;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Common\Service_Period;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * 提供期間のカード表示と、終了済みメニューの一覧からの除外を検証するテスト。
 *
 * @group blocks
 * @group service-period
 */
class Menu_Loop_Service_Period_Test extends WP_UnitTestCase {

	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * テスト前の日付書式。
	 *
	 * @var mixed
	 */
	private $original_date_format;

	/**
	 * テスト前の timezone_string。
	 *
	 * @var string
	 */
	private $original_timezone_string = '';

	/**
	 * ブロックと日付書式・タイムゾーンを用意する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block                    = new Menu_Loop_Block();
		$this->original_date_format     = get_option( 'date_format' );
		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
		update_option( 'date_format', 'Y年n月j日' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	/**
	 * 日付書式・タイムゾーンを元に戻す。
	 */
	public function tear_down(): void {
		update_option( 'date_format', $this->original_date_format );
		update_option( 'timezone_string', $this->original_timezone_string );
		parent::tear_down();
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
	 * 提供期間を設定したメニューを作る。
	 *
	 * @param string $title タイトル。
	 * @param string $start 開始日（空は設定しない）。
	 * @param string $end   終了日（空は設定しない）。
	 * @return int メニューの投稿 ID。
	 */
	private function create_menu( string $title, string $start = '', string $end = '' ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		// 基本料金を設定しておく（カード自体が空にならないようにするため）。
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		if ( '' !== $start ) {
			update_post_meta( $menu_id, Service_Period::META_START, $start );
		}
		if ( '' !== $end ) {
			update_post_meta( $menu_id, Service_Period::META_END, $end );
		}

		return $menu_id;
	}

	/**
	 * カード: 提供期間があるときだけ「提供期間」の1行を、サイトの日付書式で出す。
	 */
	public function test_render_menu_card(): void {
		$label = __( 'Service period', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => '開始日と終了日 => 「提供期間」の行に両方の日付が出る',
				'start'               => '2027-07-20',
				'end'                 => '2027-08-31',
				/* translators: 1: service period start date, 2: service period end date. */
				'expected_text'       => sprintf( _x( '%1$s - %2$s', 'service period range', 'vk-booking-manager' ), '<span class="vkbm-service-period__date">2027年7月20日</span>', '<span class="vkbm-service-period__date">2027年8月31日</span>' ),
			),
			array(
				'test_condition_name' => '開始日のみ => 「◯◯から」',
				'start'               => '2027-07-20',
				'end'                 => '',
				/* translators: %s: service period start date. */
				'expected_text'       => $this->expected_one_sided( __( 'From %s', 'vk-booking-manager' ), '<span class="vkbm-service-period__date">2027年7月20日</span>' ),
			),
			array(
				'test_condition_name' => '終了日のみ => 「◯◯まで」',
				'start'               => '',
				'end'                 => '2027-08-31',
				/* translators: %s: service period end date. */
				'expected_text'       => $this->expected_one_sided( __( 'Until %s', 'vk-booking-manager' ), '<span class="vkbm-service-period__date">2027年8月31日</span>' ),
			),
			array(
				'test_condition_name' => '期間なし => 行を出さない',
				'start'               => '',
				'end'                 => '',
				'expected_text'       => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu( 'テストサービス', $case['start'], $case['end'] );
			$html    = $this->block->render_menu_card( $menu_id );

			if ( '' === $case['expected_text'] ) {
				$this->assertStringNotContainsString( '<dt>' . $label . '</dt>', $html, $case['test_condition_name'] );
				continue;
			}
			$this->assertStringContainsString( '<dt>' . $label . '</dt><dd>' . $case['expected_text'] . '</dd>', $html, $case['test_condition_name'] );
		}
	}

	/**
	 * 期待値用: 翻訳文言（%s を含む）のうち、日付以外の文字の部分を .vkbm-service-period__text の要素で包む。
	 *
	 * @param string $template 翻訳された文言（%s が日付の位置）。
	 * @param string $date_html 日付の要素の HTML。
	 * @return string 期待する HTML。
	 */
	private function expected_one_sided( string $template, string $date_html ): string {
		$wrap_text = static function ( string $text ): string {
			return (string) preg_replace( '/^(\s*)(.+?)(\s*)$/us', '$1<span class="vkbm-service-period__text">$2</span>$3', $text );
		};
		$parts     = explode( '%s', $template, 2 );
		return ( '' === $parts[0] ? '' : $wrap_text( $parts[0] ) ) . $date_html . ( '' === $parts[1] ? '' : $wrap_text( $parts[1] ) );
	}

	/**
	 * 予約ページのメニュー一覧: 終了済み（終了日 < 今日）だけが除外され、終了日が今日・開始前・期間なしは残る。
	 */
	public function test_selection_list_excludes_ended_menus(): void {
		$no_period  = $this->create_menu( '期間なし' );
		$ends_today = $this->create_menu( '終了日が今日', '', $this->date_after( 0 ) );
		$ongoing    = $this->create_menu( '期間中', $this->date_after( -10 ), $this->date_after( 10 ) );
		$upcoming   = $this->create_menu( '開始前', $this->date_after( 10 ), $this->date_after( 20 ) );
		$ended      = $this->create_menu( '終了済み', $this->date_after( -20 ), $this->date_after( -1 ) );

		$result = $this->block->get_selection_list( 0, array(), '', false );
		$ids    = $result['menu_ids'];

		foreach ( array( $no_period, $ends_today, $ongoing, $upcoming ) as $menu_id ) {
			$this->assertContains( $menu_id, $ids, get_the_title( $menu_id ) . ' は一覧に残る' );
		}
		$this->assertNotContains( $ended, $ids, '終了済みは一覧から外れる' );
	}

	/**
	 * メニューループ（ブロック）: 終了済みを出さず、それ以外は出す。
	 */
	public function test_loop_block_excludes_ended_menus(): void {
		$ongoing = $this->create_menu( '期間中のメニュー', $this->date_after( -10 ), $this->date_after( 10 ) );
		$ended   = $this->create_menu( '終了済みのメニュー', $this->date_after( -20 ), $this->date_after( -1 ) );

		$html = $this->block->render_block( array( 'loopId' => 'service-period-test' ) );

		$this->assertStringContainsString( 'data-menu-id="' . $ongoing . '"', $html, '期間中は出る' );
		$this->assertStringNotContainsString( 'data-menu-id="' . $ended . '"', $html, '終了済みは出ない' );
	}
	/** 1日のみは開催日を曜日付きで表示する。 */
	public function test_render_menu_card_single_day(): void {
		$menu_id = $this->create_menu( 'セミナー', '2027-12-05', '2027-12-05' );
		update_post_meta( $menu_id, Service_Period::META_MODE, 'single' );
		$html = $this->block->render_menu_card( $menu_id );
		$this->assertStringContainsString( '<dt>' . esc_html__( 'Event date', 'vk-booking-manager' ) . '</dt>', $html );
		$this->assertStringContainsString( '<span class="vkbm-service-period__date">' . esc_html( Service_Period::format_single_date( '2027-12-05' ) ) . '</span>', $html );

		// メタを直接変更した場合も、日付が無効なら開催日の行を出さない。
		foreach ( array( '', '2027-02-30' ) as $invalid_date ) {
			update_post_meta( $menu_id, Service_Period::META_START, $invalid_date );
			$html = $this->block->render_menu_card( $menu_id );
			$this->assertStringNotContainsString( '<dt>' . esc_html__( 'Event date', 'vk-booking-manager' ) . '</dt>', $html, '無効な開始日: ' . $invalid_date );
		}
	}
}
