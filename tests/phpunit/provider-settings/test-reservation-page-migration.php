<?php
/**
 * 予約ページ URL の設定を固定ページ ID へ一度だけ切り替える処理（issue #1050）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Reservation_Page_Migration;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Reservation_Page_Migration::maybe_migrate() の切り替え条件と、実行済みの目印を検証する。
 *
 * @group provider-settings
 */
class Reservation_Page_Migration_Test extends WP_UnitTestCase {

	/**
	 * 保存した設定・目印・パーマリンク構造・表示設定を掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Reservation_Page_Migration::OPTION_DONE );
		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * 固定ページを1件作る。
	 *
	 * @param array<string,mixed> $args 投稿の上書き項目。
	 * @return int 固定ページ ID。
	 */
	private function create_page( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Booking',
					'post_name'   => 'booking',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * maybe_migrate() が、このサイトの公開中の固定ページを指す URL だけを ID に切り替え、目印を必ず立てることを検証する。
	 */
	public function test_maybe_migrate(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$page_id  = $this->create_page();
		$draft_id = $this->create_page(
			array(
				'post_name'   => 'draft-booking',
				'post_status' => 'draft',
			)
		);
		$post_id  = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'news',
				'post_status' => 'publish',
			)
		);
		$front_id = $this->create_page( array( 'post_name' => 'front' ) );
		unset( $draft_id );

		$test_cases = array(
			array(
				'test_condition_name' => '自サイトの公開中の固定ページの URL => ID に切り替え（URL は残す）',
				'stored'              => array( 'reservation_page_url' => get_permalink( $page_id ) ),
				'expected_page_id'    => $page_id,
				'expected_url'        => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '相対パス => 自サイトの URL として ID に切り替え',
				'stored'              => array( 'reservation_page_url' => '/booking/' ),
				'expected_page_id'    => $page_id,
				'expected_url'        => '/booking/',
			),
			array(
				'test_condition_name' => '末尾スラッシュ無し => ID に切り替え',
				'stored'              => array( 'reservation_page_url' => untrailingslashit( get_permalink( $page_id ) ) ),
				'expected_page_id'    => $page_id,
				'expected_url'        => untrailingslashit( get_permalink( $page_id ) ),
			),
			array(
				'test_condition_name' => '旧ドメインの URL => 切り替えない（「その他」のまま警告で選び直してもらう）',
				'stored'              => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expected_page_id'    => null,
				'expected_url'        => 'https://old.example.com/booking/',
			),
			array(
				'test_condition_name' => 'クエリ付きの URL => 切り替えない',
				'stored'              => array( 'reservation_page_url' => get_permalink( $page_id ) . '?menu_id=1' ),
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $page_id ) . '?menu_id=1',
			),
			array(
				'test_condition_name' => 'フラグメント付きの URL => 切り替えない',
				'stored'              => array( 'reservation_page_url' => get_permalink( $page_id ) . '#form' ),
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $page_id ) . '#form',
			),
			array(
				'test_condition_name' => '下書きの固定ページの URL => 切り替えない',
				'stored'              => array( 'reservation_page_url' => home_url( '/draft-booking/' ) ),
				'expected_page_id'    => null,
				'expected_url'        => home_url( '/draft-booking/' ),
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿）の URL => 切り替えない',
				'stored'              => array( 'reservation_page_url' => get_permalink( $post_id ) ),
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $post_id ),
			),
			array(
				'test_condition_name' => 'トップページの URL（表示設定で固定ページをトップにしている） => トップのページの ID',
				'stored'              => array( 'reservation_page_url' => home_url( '/' ) ),
				'front_page'          => $front_id,
				'expected_page_id'    => $front_id,
				'expected_url'        => home_url( '/' ),
			),
			array(
				'test_condition_name' => 'トップページの URL（最新の投稿を表示） => 切り替えない',
				'stored'              => array( 'reservation_page_url' => home_url( '/' ) ),
				'expected_page_id'    => null,
				'expected_url'        => home_url( '/' ),
			),
			array(
				'test_condition_name' => 'URL が未設定 => 何も変えない（目印だけ立てる）',
				'stored'              => array( 'provider_name' => 'Shop' ),
				'expected_page_id'    => null,
				'expected_url'        => null,
			),
			array(
				'test_condition_name' => '既に ID が入っている => 変えない',
				'stored'              => array(
					'reservation_page_id'  => $front_id,
					'reservation_page_url' => get_permalink( $page_id ),
				),
				'expected_page_id'    => $front_id,
				'expected_url'        => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '目印が立っている => 自サイトの URL でも切り替えない（後から書かれた URL を勝手に変えない）',
				'stored'              => array( 'reservation_page_url' => get_permalink( $page_id ) ),
				'already_done'        => true,
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $page_id ),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['stored'] );
			delete_option( Reservation_Page_Migration::OPTION_DONE );
			if ( ! empty( $case['already_done'] ) ) {
				update_option( Reservation_Page_Migration::OPTION_DONE, '1' );
			}
			if ( isset( $case['front_page'] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $case['front_page'] );
			} else {
				update_option( 'show_on_front', 'posts' );
				delete_option( 'page_on_front' );
			}

			( new Reservation_Page_Migration() )->maybe_migrate();

			$stored = get_option( Settings_Repository::OPTION_KEY );
			// 切り替えないケースでは設定に触れないため、ID のキー自体が無い（null）ことを確かめる。
			$this->assertSame( $case['expected_page_id'], $stored['reservation_page_id'] ?? null, $case['test_condition_name'] . '（ID）' );
			$this->assertSame( $case['expected_url'], $stored['reservation_page_url'] ?? null, $case['test_condition_name'] . '（URL は変えない）' );
			$this->assertSame( '1', get_option( Reservation_Page_Migration::OPTION_DONE ), $case['test_condition_name'] . '（目印）' );
		}

		// 切り替えても他の設定キーには触れない（業種プリセットの反映などを通さない）。
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'provider_name'        => 'Shop',
				'industry_preset'      => 'salon',
				'reservation_page_url' => get_permalink( $page_id ),
			)
		);
		delete_option( Reservation_Page_Migration::OPTION_DONE );
		( new Reservation_Page_Migration() )->maybe_migrate();
		$stored = get_option( Settings_Repository::OPTION_KEY );
		$this->assertSame(
			array(
				'provider_name'        => 'Shop',
				'industry_preset'      => 'salon',
				'reservation_page_url' => get_permalink( $page_id ),
				'reservation_page_id'  => $page_id,
			),
			$stored,
			'切り替え先の ID を足すだけで、他のキーは値も順序も変えない'
		);

		// Ajax（ハートビート等）では何もしない（目印も立てない）。
		update_option( Settings_Repository::OPTION_KEY, array( 'reservation_page_url' => get_permalink( $page_id ) ) );
		delete_option( Reservation_Page_Migration::OPTION_DONE );
		add_filter( 'wp_doing_ajax', '__return_true' );
		( new Reservation_Page_Migration() )->maybe_migrate();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$stored = get_option( Settings_Repository::OPTION_KEY );
		$this->assertArrayNotHasKey( 'reservation_page_id', $stored, 'Ajax では切り替えない' );
		$this->assertFalse( get_option( Reservation_Page_Migration::OPTION_DONE ), 'Ajax では目印も立てない（次の通常の管理画面表示で実行する）' );

		// 設定が一度も保存されていないサイトでも、目印だけを立てて設定は作らない。
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Reservation_Page_Migration::OPTION_DONE );
		( new Reservation_Page_Migration() )->maybe_migrate();
		$this->assertFalse( get_option( Settings_Repository::OPTION_KEY ), '未保存のサイトでは設定を作らない' );
		$this->assertSame( '1', get_option( Reservation_Page_Migration::OPTION_DONE ), '未保存のサイトでも目印は立てる' );
	}
}
