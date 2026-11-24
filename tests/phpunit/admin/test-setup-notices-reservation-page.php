<?php
/**
 * Setup_Notices の予約ページ関連（issue #1049・#1050）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 予約ページが使えない状態（旧ドメインの URL・未公開や削除された固定ページ）のとき、
 * セットアップ案内で「未設定」と同じ扱いになり、専用の警告が1件だけ出ることを検証する。
 *
 * @group admin
 */
class Setup_Notices_Reservation_Page_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		$_GET = array();
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 固定ページを1件作る。
	 *
	 * @param string $status 公開状態。
	 * @param string $title  タイトル。
	 * @return int 固定ページ ID。
	 */
	private function create_page( string $status = 'publish', string $title = 'Booking' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	/**
	 * has_reservation_page() が、使える予約ページ（自サイトの URL か公開中の固定ページ）のときだけ true を返すことを検証する。
	 */
	public function test_has_reservation_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '未設定 => false',
				'settings'            => array( 'reservation_page_url' => '' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空白のみ => false',
				'settings'            => array( 'reservation_page_url' => '   ' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '自サイトの絶対URL => true',
				'settings'            => array( 'reservation_page_url' => home_url( '/booking/' ) ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '相対パス => true',
				'settings'            => array( 'reservation_page_url' => '/booking/' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '旧ドメインのURL => 未設定と同じ扱い（false）',
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '公開中の固定ページを選択 => true（#1050）',
				'settings'            => array( 'reservation_page_id' => $this->create_page() ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページを選択（URL は自サイト） => 未設定と同じ扱い（false。URL へフォールバックしない）',
				'settings'            => array(
					'reservation_page_id'  => $this->create_page( 'trash' ),
					'reservation_page_url' => home_url( '/booking/' ),
				),
				'expected'            => false,
			),
		);

		$method = new \ReflectionMethod( Setup_Notices::class, 'has_reservation_page' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );

			$this->assertSame( $case['expected'], $method->invoke( new Setup_Notices() ), $case['test_condition_name'] );
		}
	}

	/**
	 * 専用の警告が出る状態では初期設定通知の「予約ページ」の行を重ねて出さず、未設定のときだけ出すことを検証する。
	 */
	public function test_get_missing_setup_items_for_user(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		wp_set_current_user( $admin_id );

		$test_cases = array(
			array(
				'test_condition_name' => '未設定 => 初期設定通知に出す',
				'settings'            => array( 'reservation_page_url' => '' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '旧ドメイン => 専用の通知と重複するので出さない',
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '自サイトのURL => 出さない',
				'settings'            => array( 'reservation_page_url' => home_url( '/booking/' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '公開中の固定ページを選択 => 出さない',
				'settings'            => array( 'reservation_page_id' => $this->create_page() ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択 => 専用の通知と重複するので出さない（予約ページの通知は常に1件）',
				'settings'            => array( 'reservation_page_id' => $this->create_page( 'draft' ) ),
				'expected'            => false,
			),
		);

		$method = new \ReflectionMethod( Setup_Notices::class, 'get_missing_setup_items_for_user' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );

			$ids = array_column( $method->invoke( new Setup_Notices() ), 'id' );

			$this->assertSame( $case['expected'], in_array( 'reservation_page', $ids, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_reservation_page_problem_notice() が、権限のある利用者に、予約ページが使えないときだけ
	 * （基本設定のシステム設定タブを除く）警告を出すことを検証する。
	 */
	public function test_render_reservation_page_problem_notice(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin_id )->add_cap( Capabilities::MANAGE_PROVIDER_SETTINGS );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$deleted_id    = $this->create_page();
		wp_delete_post( $deleted_id, true );
		$draft_id = $this->create_page( 'draft', 'Draft Booking Page' );
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）。
		( new Roles_Manager() )->sync_roles();
		$owner_id = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );

		$test_cases = array(
			array(
				'test_condition_name' => '旧ドメイン・権限あり => 入力中のドメインとサイトのドメインを並べた警告',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expect_notice'       => true,
				'expect_text'         => 'old.example.com',
			),
			array(
				'test_condition_name' => '旧ドメイン・基本設定のシステム設定タブ => 入力欄下の警告と重なるので出さない',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'screen'              => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'system',
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '旧ドメイン・基本設定の他タブ（email） => 出す',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'screen'              => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'email',
				),
				'expect_notice'       => true,
				'expect_text'         => 'old.example.com',
			),
			array(
				'test_condition_name' => '旧ドメイン・基本設定でタブ省略（店舗情報タブが開く） => 出す',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'screen'              => array( 'page' => 'vkbm-provider-settings' ),
				'expect_notice'       => true,
				'expect_text'         => 'old.example.com',
			),
			array(
				'test_condition_name' => '旧ドメイン・他の画面で tab=system => 出す',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'screen'              => array(
					'page' => 'vkbm-shift-dashboard',
					'tab'  => 'system',
				),
				'expect_notice'       => true,
				'expect_text'         => 'old.example.com',
			),
			array(
				'test_condition_name' => '自サイトのURL・システム設定タブ => 出さない',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => home_url( '/booking/' ) ),
				'screen'              => array(
					'page' => 'vkbm-provider-settings',
					'tab'  => 'system',
				),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '旧ドメイン・権限なし => 出さない',
				'user_id'             => $subscriber_id,
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '自サイトのURL・権限あり => 出さない',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => home_url( '/booking/' ) ),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '未設定・権限あり => 出さない（未設定の案内は別の通知が担当）',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_url' => '' ),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '公開中の固定ページを選択 => 出さない（#1050）',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_id' => $this->create_page() ),
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択 => ページ名入りの警告。ボタンはそのページの編集画面へ',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_id' => $draft_id ),
				'expect_notice'       => true,
				'expect_text'         => 'Draft Booking Page',
				'expect_href'         => 'post.php?post=' . $draft_id,
			),
			array(
				'test_condition_name' => '下書きの固定ページを選択・業務オーナー（固定ページの権限なし） => 公開ではなく選び直しを勧める警告。ボタンは予約ページの設定欄へ',
				'user_id'             => $owner_id,
				'settings'            => array( 'reservation_page_id' => $draft_id ),
				'expect_notice'       => true,
				'expect_text'         => array( 'Draft Booking Page', 'Please select the reservation page again.' ),
				'unexpect_text'       => 'publish the page',
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページを選択・業務オーナー（固定ページの権限なし） => ゴミ箱から戻すのではなく選び直しを勧める警告。ボタンは予約ページの設定欄へ',
				'user_id'             => $owner_id,
				'settings'            => array( 'reservation_page_id' => $this->create_page( 'trash', 'Trashed Booking Page' ) ),
				'expect_notice'       => true,
				'expect_text'         => array( 'Trashed Booking Page', 'Please select the reservation page again.' ),
				'unexpect_text'       => 'from the trash',
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページを選択（URL は自サイト） => ページ名入りの警告（URL へフォールバックしない）。ボタンはゴミ箱一覧へ',
				'user_id'             => $admin_id,
				'settings'            => array(
					'reservation_page_id'  => $this->create_page( 'trash', 'Trashed Booking Page' ),
					'reservation_page_url' => home_url( '/booking/' ),
				),
				'expect_notice'       => true,
				'expect_text'         => array( 'Trashed Booking Page', 'Please restore the page from the trash and publish it.' ),
				'expect_href'         => 'edit.php?post_status=trash&#038;post_type=page',
			),
			array(
				'test_condition_name' => '削除された固定ページを選択 => 選び直しを促す警告',
				'user_id'             => $admin_id,
				'settings'            => array( 'reservation_page_id' => $deleted_id ),
				'expect_notice'       => true,
				'expect_text'         => 'vkbm-reservation-page-url',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			$_GET = $case['screen'] ?? array();

			ob_start();
			( new Setup_Notices() )->render_reservation_page_problem_notice();
			$output = (string) ob_get_clean();

			if ( $case['expect_notice'] ) {
				$this->assertStringContainsString( 'vkbm-notice__warning', $output, $case['test_condition_name'] );
				foreach ( (array) $case['expect_text'] as $expect_text ) {
					$this->assertStringContainsString( $expect_text, $output, $case['test_condition_name'] );
				}
				if ( isset( $case['unexpect_text'] ) ) {
					$this->assertStringNotContainsString( $case['unexpect_text'], $output, $case['test_condition_name'] . '（できない行動を勧めない）' );
				}
				// 管理メニューの「予約ページ」の行き先は、指す先が分かるよう画面名と欄名で書く。
				$this->assertStringContainsString( 'the reservation page field in BM settings', $output, $case['test_condition_name'] . '（管理メニューの行き先）' );
				// ボタンの行き先は状態ごとに 1 つ（既定は予約ページの設定欄）。
				$this->assertStringContainsString( $case['expect_href'] ?? 'admin.php?page=vkbm-provider-settings&#038;tab=system#vkbm-reservation-page-url', $output, $case['test_condition_name'] . '（ボタンの行き先）' );
			} else {
				$this->assertSame( '', $output, $case['test_condition_name'] );
			}
		}
	}
}
