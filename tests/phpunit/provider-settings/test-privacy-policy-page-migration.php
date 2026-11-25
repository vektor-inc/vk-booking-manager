<?php
/**
 * プライバシーポリシーのページの URL 設定を固定ページ ID へ一度だけ切り替える処理（issue #1168）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Privacy_Policy_Page_Migration;
use VKBookingManager\ProviderSettings\Reservation_Page_Migration;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Privacy_Policy_Page_Migration::maybe_migrate() の切り替え条件と、実行済みの目印を検証する。
 *
 * @group provider-settings
 */
class Privacy_Policy_Page_Migration_Test extends WP_UnitTestCase {

	/**
	 * 保存した設定・目印・パーマリンク構造を掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Privacy_Policy_Page_Migration::OPTION_DONE );
		delete_option( Reservation_Page_Migration::OPTION_DONE );
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
					'post_title'  => 'Privacy',
					'post_name'   => 'privacy',
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
		$other_id = $this->create_page( array( 'post_name' => 'other-privacy' ) );
		$this->create_page(
			array(
				'post_name'   => 'draft-privacy',
				'post_status' => 'draft',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'news',
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '自サイトの公開中の固定ページの URL => ID に切り替え（URL は残す）',
				'stored'              => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => get_permalink( $page_id ),
				),
				'expected_page_id'    => $page_id,
				'expected_url'        => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '本文を入力する方式でも、残っている自サイトの固定ページの URL => ID に切り替え（後でページ指定に戻したときも同じページ）',
				'stored'              => array(
					'provider_privacy_policy_mode' => 'content',
					'provider_privacy_policy_url'  => get_permalink( $page_id ),
				),
				'expected_page_id'    => $page_id,
				'expected_url'        => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '相対パス => 自サイトの URL として ID に切り替え',
				'stored'              => array( 'provider_privacy_policy_url' => '/privacy/' ),
				'expected_page_id'    => $page_id,
				'expected_url'        => '/privacy/',
			),
			array(
				'test_condition_name' => '外部サイトの URL => 切り替えない（「その他」の URL として従来どおり使う）',
				'stored'              => array( 'provider_privacy_policy_url' => 'https://external.example.com/privacy/' ),
				'expected_page_id'    => null,
				'expected_url'        => 'https://external.example.com/privacy/',
			),
			array(
				'test_condition_name' => '旧ドメインの URL => 切り替えない（外部サイトと区別できないため）',
				'stored'              => array( 'provider_privacy_policy_url' => 'https://old.example.com/privacy/' ),
				'expected_page_id'    => null,
				'expected_url'        => 'https://old.example.com/privacy/',
			),
			array(
				'test_condition_name' => 'フラグメント付きの URL => 切り替えない',
				'stored'              => array( 'provider_privacy_policy_url' => get_permalink( $page_id ) . '#cookie' ),
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $page_id ) . '#cookie',
			),
			array(
				'test_condition_name' => '下書きの固定ページの URL => 切り替えない',
				'stored'              => array( 'provider_privacy_policy_url' => home_url( '/draft-privacy/' ) ),
				'expected_page_id'    => null,
				'expected_url'        => home_url( '/draft-privacy/' ),
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿）の URL => 切り替えない',
				'stored'              => array( 'provider_privacy_policy_url' => get_permalink( $post_id ) ),
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $post_id ),
			),
			array(
				'test_condition_name' => 'URL が空 => 何も変えない（目印だけ立てる）',
				'stored'              => array(
					'provider_privacy_policy_mode' => 'url',
					'provider_privacy_policy_url'  => '',
				),
				'expected_page_id'    => null,
				'expected_url'        => '',
			),
			array(
				'test_condition_name' => 'URL のキーが無い => 何も変えない（目印だけ立てる）',
				'stored'              => array( 'provider_name' => 'Shop' ),
				'expected_page_id'    => null,
				'expected_url'        => null,
			),
			array(
				'test_condition_name' => '既に ID が入っている => 変えない',
				'stored'              => array(
					'provider_privacy_policy_page_id' => $other_id,
					'provider_privacy_policy_url'     => get_permalink( $page_id ),
				),
				'expected_page_id'    => $other_id,
				'expected_url'        => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '目印が立っている => 自サイトの URL でも切り替えない（後から書かれた URL を勝手に変えない）',
				'stored'              => array( 'provider_privacy_policy_url' => get_permalink( $page_id ) ),
				'already_done'        => true,
				'expected_page_id'    => null,
				'expected_url'        => get_permalink( $page_id ),
			),
			array(
				'test_condition_name' => '予約ページの切り替えを済ませたサイト => プライバシーポリシーは別に切り替える（目印は別）',
				'stored'              => array( 'provider_privacy_policy_url' => get_permalink( $page_id ) ),
				'reservation_done'    => true,
				'expected_page_id'    => $page_id,
				'expected_url'        => get_permalink( $page_id ),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['stored'] );
			delete_option( Privacy_Policy_Page_Migration::OPTION_DONE );
			delete_option( Reservation_Page_Migration::OPTION_DONE );
			if ( ! empty( $case['already_done'] ) ) {
				update_option( Privacy_Policy_Page_Migration::OPTION_DONE, '1' );
			}
			if ( ! empty( $case['reservation_done'] ) ) {
				update_option( Reservation_Page_Migration::OPTION_DONE, '1' );
			}

			( new Privacy_Policy_Page_Migration() )->maybe_migrate();

			$stored = get_option( Settings_Repository::OPTION_KEY );
			// 切り替えないケースでは設定に触れないため、ID のキー自体が無い（null）ことを確かめる。
			$this->assertSame( $case['expected_page_id'], $stored['provider_privacy_policy_page_id'] ?? null, $case['test_condition_name'] . '（ID）' );
			$this->assertSame( $case['expected_url'], $stored['provider_privacy_policy_url'] ?? null, $case['test_condition_name'] . '（URL は変えない）' );
			$this->assertSame( '1', get_option( Privacy_Policy_Page_Migration::OPTION_DONE ), $case['test_condition_name'] . '（目印）' );
		}

		// 切り替えても他の設定キーには触れない（業種プリセットの反映などを通さない）。2回目は何もしない（冪等）。
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'provider_name'                => 'Shop',
				'industry_preset'              => 'salon',
				'provider_privacy_policy_mode' => 'url',
				'provider_privacy_policy_url'  => get_permalink( $page_id ),
			)
		);
		delete_option( Privacy_Policy_Page_Migration::OPTION_DONE );
		( new Privacy_Policy_Page_Migration() )->maybe_migrate();
		$expected_after_migration = array(
			'provider_name'                   => 'Shop',
			'industry_preset'                 => 'salon',
			'provider_privacy_policy_mode'    => 'url',
			'provider_privacy_policy_url'     => get_permalink( $page_id ),
			'provider_privacy_policy_page_id' => $page_id,
		);
		$this->assertSame( $expected_after_migration, get_option( Settings_Repository::OPTION_KEY ), '切り替え先の ID を足すだけで、他のキーは値も順序も変えない' );
		( new Privacy_Policy_Page_Migration() )->maybe_migrate();
		$this->assertSame( $expected_after_migration, get_option( Settings_Repository::OPTION_KEY ), '2回目の実行では何も変えない' );

		// Ajax（ハートビート等）では何もしない（目印も立てない）。
		update_option( Settings_Repository::OPTION_KEY, array( 'provider_privacy_policy_url' => get_permalink( $page_id ) ) );
		delete_option( Privacy_Policy_Page_Migration::OPTION_DONE );
		add_filter( 'wp_doing_ajax', '__return_true' );
		( new Privacy_Policy_Page_Migration() )->maybe_migrate();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$stored = get_option( Settings_Repository::OPTION_KEY );
		$this->assertArrayNotHasKey( 'provider_privacy_policy_page_id', $stored, 'Ajax では切り替えない' );
		$this->assertFalse( get_option( Privacy_Policy_Page_Migration::OPTION_DONE ), 'Ajax では目印も立てない（次の通常の管理画面表示で実行する）' );

		// 設定が一度も保存されていないサイトでも、目印だけを立てて設定は作らない。
		delete_option( Settings_Repository::OPTION_KEY );
		delete_option( Privacy_Policy_Page_Migration::OPTION_DONE );
		( new Privacy_Policy_Page_Migration() )->maybe_migrate();
		$this->assertFalse( get_option( Settings_Repository::OPTION_KEY ), '未保存のサイトでは設定を作らない' );
		$this->assertSame( '1', get_option( Privacy_Policy_Page_Migration::OPTION_DONE ), '未保存のサイトでも目印は立てる' );
	}
}
