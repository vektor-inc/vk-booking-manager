<?php
/**
 * 予約ページURLが自サイトのURLかを判定する共通処理（issue #1049）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Capabilities\Roles_Manager;
use VKBookingManager\Common\Page_Reference;
use VKBookingManager\Common\Reservation_Page_Url;
use WP_UnitTestCase;

/**
 * Reservation_Page_Url の判定を検証する。
 *
 * @group common
 */
class Reservation_Page_Url_Test extends WP_UnitTestCase {

	/**
	 * 文言の検証は英語（原文）で行う。
	 *
	 * サイトの言語が日本語で、先に動いた他のテストが日本語の翻訳を読み込んでいると、
	 * ページ名の「#ID (no title)」や警告文が日本語になり、実行する順番で結果が変わるため。
	 */
	protected function setUp(): void {
		parent::setUp();
		switch_to_locale( 'en_US' );
		unload_textdomain( 'vk-booking-manager', true );
	}

	/**
	 * 切り替えた言語を元に戻す。
	 */
	protected function tearDown(): void {
		restore_previous_locale();
		parent::tearDown();
	}

	/**
	 * テスト用のホスト構成（home / siteurl）を設定する。
	 *
	 * テスト環境は WP_HOME / WP_SITEURL 定数でURLが固定されているため、オプションではなくフィルターで差し替える。
	 *
	 * @param string $home    home の値。
	 * @param string $siteurl siteurl の値。空なら home と同じにする。
	 * @return void
	 */
	private function set_site_urls( string $home, string $siteurl = '' ): void {
		$siteurl = '' !== $siteurl ? $siteurl : $home;

		remove_all_filters( 'pre_option_home' );
		remove_all_filters( 'pre_option_siteurl' );
		add_filter(
			'pre_option_home',
			static function () use ( $home ) {
				return $home;
			},
			PHP_INT_MAX
		);
		add_filter(
			'pre_option_siteurl',
			static function () use ( $siteurl ) {
				return $siteurl;
			},
			PHP_INT_MAX
		);
	}

	/**
	 * resolve_usable() が、自サイトのURLだけを返し、旧ドメインは空文字にすることを検証する。
	 */
	public function test_resolve_usable(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '空文字 => 空文字',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '相対パス => 自サイトの絶対URLに正規化',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => '/booking/',
				'expected'            => 'https://example.test/booking/',
			),
			array(
				'test_condition_name' => '先頭スラッシュの無い相対パス => 自サイトの絶対URLに正規化',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'booking/',
				'expected'            => 'https://example.test/booking/',
			),
			array(
				'test_condition_name' => '自サイトの絶対URL => そのまま',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://example.test/booking/',
				'expected'            => 'https://example.test/booking/',
			),
			array(
				'test_condition_name' => '旧ドメインのURL => 空文字（変更前は旧ドメインのURLを返していた）',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://old.example.com/booking/',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '自サイトのホストを前方一致で含む別ドメイン => 空文字',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://example.test.evil.example.com/booking/',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'クエリに自サイトのホストを含む別ドメイン => 空文字',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://old.example.com/?r=example.test',
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'http と https の違い => 自サイトとして扱う',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'http://example.test/booking/',
				'expected'            => 'http://example.test/booking/',
			),
			array(
				'test_condition_name' => '保存値だけ www 付き => 自サイトとして扱う',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://www.example.test/booking/',
				'expected'            => 'https://www.example.test/booking/',
			),
			array(
				'test_condition_name' => 'サイトだけ www 付き（保存値は www 無し） => 自サイトとして扱う',
				'home'                => 'https://www.example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://example.test/booking/',
				'expected'            => 'https://example.test/booking/',
			),
			array(
				'test_condition_name' => 'ポート違い => 自サイトとして扱う',
				'home'                => 'http://example.test:8080',
				'siteurl'             => '',
				'raw_url'             => 'http://example.test/booking/',
				'expected'            => 'http://example.test/booking/',
			),
			array(
				'test_condition_name' => 'サブディレクトリ設置のサイト => 自サイトとして扱う',
				'home'                => 'https://example.test/shop',
				'siteurl'             => '',
				'raw_url'             => 'https://example.test/shop/booking',
				'expected'            => 'https://example.test/shop/booking',
			),
			array(
				'test_condition_name' => 'ホストの大文字小文字の違い => 自サイトとして扱う',
				'home'                => 'https://example.test',
				'siteurl'             => '',
				'raw_url'             => 'https://EXAMPLE.test/booking/',
				'expected'            => 'https://EXAMPLE.test/booking/',
			),
			array(
				'test_condition_name' => 'サイトが Unicode ドメインで、保存値が punycode 表記 => 自サイトとして扱う',
				'home'                => 'https://例え.test',
				'siteurl'             => '',
				'raw_url'             => 'https://xn--r8jz45g.test/booking/',
				'expected'            => 'https://xn--r8jz45g.test/booking/',
			),
			array(
				'test_condition_name' => 'スキーム省略（//）の自サイトURL => 自サイトとして扱う',
				'home'                => 'http://example.test',
				'siteurl'             => '',
				'raw_url'             => '//example.test/booking/',
				'expected'            => 'http://example.test/booking/',
			),
			array(
				'test_condition_name' => 'home と site_url のホストが違う構成で site_url 側のホスト => 自サイトとして扱う',
				'home'                => 'https://example.test',
				'siteurl'             => 'https://wp.example.test',
				'raw_url'             => 'https://wp.example.test/booking/',
				'expected'            => 'https://wp.example.test/booking/',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site_urls( $case['home'], $case['siteurl'] );

			$this->assertSame( $case['expected'], Reservation_Page_Url::resolve_usable( $case['raw_url'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * `allowed_redirect_hosts` で許可されたホスト（言語別ドメイン等）が自サイトとして扱われることを検証する。
	 */
	public function test_is_own_site_url(): void {
		$this->set_site_urls( 'https://example.test' );

		$allow_other_language_domain = static function ( array $hosts ): array {
			$hosts[] = 'en.example.test';
			return $hosts;
		};

		$test_cases = array(
			array(
				'test_condition_name' => '自サイトのホスト => true',
				'allow_filter'        => false,
				'url'                 => 'https://example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '旧ドメイン => false',
				'allow_filter'        => false,
				'url'                 => 'https://old.example.com/booking/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '言語別ドメインがフィルターで許可されていない => false',
				'allow_filter'        => false,
				'url'                 => 'https://en.example.test/booking/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '言語別ドメインがフィルターで許可されている => true',
				'allow_filter'        => true,
				'url'                 => 'https://en.example.test/booking/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ホストが取れない文字列 => false',
				'allow_filter'        => false,
				'url'                 => 'https:///booking/',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['allow_filter'] ) {
				add_filter( 'allowed_redirect_hosts', $allow_other_language_domain );
			}

			$this->assertSame( $case['expected'], Reservation_Page_Url::is_own_site_url( $case['url'] ), $case['test_condition_name'] );

			remove_filter( 'allowed_redirect_hosts', $allow_other_language_domain );
		}
	}

	/**
	 * get_mismatch() が、入力済みで自サイトのURLでないときだけ警告用の情報を返すことを検証する。
	 */
	public function test_get_mismatch(): void {
		$this->set_site_urls( 'https://example.test' );

		$test_cases = array(
			array(
				'test_condition_name' => '未設定 => null',
				'raw_url'             => '',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '自サイトのURL => null',
				'raw_url'             => 'https://example.test/booking/',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '相対パス => null',
				'raw_url'             => '/booking/',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '旧ドメイン => 入力のドメインとサイトのドメインを返す',
				'raw_url'             => 'https://old.example.com/booking/',
				'expected'            => array(
					'entered_host' => 'old.example.com',
					'site_host'    => 'example.test',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Reservation_Page_Url::get_mismatch( $case['raw_url'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * build_mismatch_message() が、入力中のドメインとサイトのドメインを並べた文面を返すことを検証する。
	 */
	public function test_build_mismatch_message(): void {
		$message = Reservation_Page_Url::build_mismatch_message(
			array(
				'entered_host' => 'old.example.com',
				'site_host'    => 'example.test',
			)
		);

		$this->assertStringContainsString( '<code>old.example.com</code>', $message, '入力中のドメインだけを code で強調する' );
		$this->assertSame( 1, substr_count( $message, '<code>' ), '強調は1か所' );
		$this->assertStringContainsString( 'example.test', $message, 'サイトのドメインを含む' );
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
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * get_url() が、固定ページ ID があればそのページ（公開中のときだけ）、無ければ URL の設定を使うことを検証する（issue #1050）。
	 */
	public function test_get_url(): void {
		$published_id = $this->create_page();
		$password_id  = $this->create_page( array( 'post_password' => 'secret' ) );
		$deleted_id   = $this->create_page();
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページの ID => そのページのパーマリンク',
				'settings'            => array( 'reservation_page_id' => $published_id ),
				'expected'            => get_permalink( $published_id ),
			),
			array(
				'test_condition_name' => 'パスワード保護の固定ページの ID => パーマリンク（パスワード保護は使う）',
				'settings'            => array( 'reservation_page_id' => $password_id ),
				'expected'            => get_permalink( $password_id ),
			),
			array(
				'test_condition_name' => '固定ページの ID と旧ドメインの URL の両方 => ID を優先してパーマリンク',
				'settings'            => array(
					'reservation_page_id'  => $published_id,
					'reservation_page_url' => 'https://old.example.com/booking/',
				),
				'expected'            => get_permalink( $published_id ),
			),
			array(
				'test_condition_name' => '下書きの固定ページの ID => 空文字（URL が残っていてもフォールバックしない）',
				'settings'            => array(
					'reservation_page_id'  => $this->create_page( array( 'post_status' => 'draft' ) ),
					'reservation_page_url' => home_url( '/booking/' ),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページの ID => 空文字',
				'settings'            => array(
					'reservation_page_id'  => $this->create_page( array( 'post_status' => 'trash' ) ),
					'reservation_page_url' => home_url( '/booking/' ),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '完全に削除された固定ページの ID => 空文字',
				'settings'            => array(
					'reservation_page_id'  => $deleted_id,
					'reservation_page_url' => home_url( '/booking/' ),
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '固定ページ以外（投稿）の ID => 空文字',
				'settings'            => array( 'reservation_page_id' => self::factory()->post->create( array( 'post_type' => 'post' ) ) ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ID が 0 で自サイトの URL => その URL（従来どおり）',
				'settings'            => array(
					'reservation_page_id'  => 0,
					'reservation_page_url' => home_url( '/booking/' ),
				),
				'expected'            => home_url( '/booking/' ),
			),
			array(
				'test_condition_name' => 'ID のキーが無く相対パスの URL => 自サイトの絶対 URL（既存の保存データ）',
				'settings'            => array( 'reservation_page_url' => '/booking/' ),
				'expected'            => home_url( '/booking/' ),
			),
			array(
				'test_condition_name' => 'ID が 0 で旧ドメインの URL => 空文字',
				'settings'            => array(
					'reservation_page_id'  => 0,
					'reservation_page_url' => 'https://old.example.com/booking/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'どちらも未設定 => 空文字',
				'settings'            => array(),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Reservation_Page_Url::get_url( $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_configured_url() が、使えるかどうかを問わず設定上の URL を返すことを検証する（フッターの重複判定用）。
	 */
	public function test_get_configured_url(): void {
		$published_id = $this->create_page();
		$trash_id     = $this->create_page( array( 'post_status' => 'trash' ) );
		$deleted_id   = $this->create_page();
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => '公開中の固定ページの ID => パーマリンク',
				'settings'            => array( 'reservation_page_id' => $published_id ),
				'expected'            => get_permalink( $published_id ),
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページの ID => パーマリンク（存在するページは URL を返す）',
				'settings'            => array( 'reservation_page_id' => $trash_id ),
				'expected'            => get_permalink( $trash_id ),
			),
			array(
				'test_condition_name' => '削除済みの ID と URL => URL 側',
				'settings'            => array(
					'reservation_page_id'  => $deleted_id,
					'reservation_page_url' => 'https://old.example.com/booking/',
				),
				'expected'            => 'https://old.example.com/booking/',
			),
			array(
				'test_condition_name' => 'ID が 0 で旧ドメインの URL => 旧ドメインの URL のまま（自サイト判定はしない）',
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expected'            => 'https://old.example.com/booking/',
			),
			array(
				'test_condition_name' => 'ID が 0 で相対パス => 自サイトの絶対 URL',
				'settings'            => array( 'reservation_page_url' => '/booking/' ),
				'expected'            => home_url( '/booking/' ),
			),
			array(
				'test_condition_name' => 'どちらも未設定 => 空文字',
				'settings'            => array(),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Reservation_Page_Url::get_configured_url( $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_problem() が、警告が要る状態（旧ドメイン・未公開・ゴミ箱・削除済み）だけを種類付きで返すことを検証する。
	 */
	public function test_get_problem(): void {
		$site_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$deleted_id = $this->create_page();
		wp_delete_post( $deleted_id, true );

		$test_cases = array(
			array(
				'test_condition_name' => 'どちらも未設定 => null（未設定の案内は別の通知が担当）',
				'settings'            => array(),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'ID が 0 で自サイトの URL => null',
				'settings'            => array( 'reservation_page_url' => home_url( '/booking/' ) ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'ID が 0 で旧ドメインの URL => mismatch（入力のドメインとサイトのドメイン付き）',
				'settings'            => array( 'reservation_page_url' => 'https://old.example.com/booking/' ),
				'expected'            => array(
					'type'         => Reservation_Page_Url::PROBLEM_MISMATCH,
					'entered_host' => 'old.example.com',
					'site_host'    => $site_host,
				),
			),
			array(
				'test_condition_name' => '公開中の固定ページの ID => null',
				'settings'            => array( 'reservation_page_id' => $this->create_page() ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '公開中の固定ページの ID と旧ドメインの URL => null（URL 側は見ない）',
				'settings'            => array(
					'reservation_page_id'  => $this->create_page(),
					'reservation_page_url' => 'https://old.example.com/booking/',
				),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '下書きの固定ページの ID => unpublished（ページ名付き）',
				'settings'            => array(
					'reservation_page_id' => $this->create_page(
						array(
							'post_title'  => 'Draft Booking',
							'post_status' => 'draft',
						)
					),
				),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => 'draft',
					'page_title' => 'Draft Booking',
				),
			),
			array(
				'test_condition_name' => 'ゴミ箱の固定ページの ID => trash（ページ名付き）',
				'settings'            => array(
					'reservation_page_id' => $this->create_page(
						array(
							'post_title'  => 'Trashed Booking',
							'post_status' => 'trash',
						)
					),
				),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_TRASH,
					'page_id'    => 'trash',
					'page_title' => 'Trashed Booking',
				),
			),
			array(
				'test_condition_name' => '完全に削除された固定ページの ID => not_found',
				'settings'            => array( 'reservation_page_id' => $deleted_id ),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_NOT_FOUND,
					'page_id'    => $deleted_id,
					'page_title' => '',
				),
			),
			array(
				'test_condition_name' => 'タイトルの無い下書きの ID => ページ名は「#ID (no title)」の形',
				'settings'            => array(
					'reservation_page_id' => $this->create_page(
						array(
							'post_title'  => '',
							'post_status' => 'draft',
						)
					),
				),
				'expected'            => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => 'untitled',
					'page_title' => 'untitled',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$expected = $case['expected'];
			// ページを配列の中で作るケースは、作った ID をここで期待値に入れる。
			if ( isset( $expected['page_id'] ) && is_string( $expected['page_id'] ) ) {
				$expected['page_id'] = $case['settings']['reservation_page_id'];
			}
			if ( isset( $expected['page_title'] ) && 'untitled' === $expected['page_title'] ) {
				$expected['page_title'] = '#' . $expected['page_id'] . ' (no title)';
			}
			$this->assertSame( $expected, Reservation_Page_Url::get_problem( $case['settings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_problem_action() が、状態ごとに「次にすること」の行き先を 1 つ返すことを検証する。
	 */
	public function test_get_problem_action(): void {
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）のロールを用意する。
		( new Roles_Manager() )->sync_roles();
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$owner_id      = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$no_publish_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$no_delete_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$draft_id      = $this->create_page( array( 'post_status' => 'draft' ) );
		$trash_id      = $this->create_page( array( 'post_status' => 'trash' ) );
		$others_trash  = $this->create_page(
			array(
				'post_status' => 'trash',
				'post_author' => $admin_id,
			)
		);
		$settings_url  = admin_url( 'admin.php?page=vkbm-provider-settings&tab=system#vkbm-reservation-page-url' );
		// 固定ページを編集できても公開できない利用者（独自ロールなど）にする。
		get_user_by( 'id', $no_publish_id )->add_cap( 'publish_pages', false );
		// 固定ページの公開・編集はできるが、他人のページを削除（ゴミ箱から戻す）できない利用者（独自ロールなど）にする。
		get_user_by( 'id', $no_delete_id )->add_cap( 'delete_others_pages', false );

		$test_cases = array(
			array(
				'test_condition_name' => '未公開・編集権限あり => そのページの編集画面',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id' => $draft_id,
				),
				'expected_url_part'   => 'post.php?post=' . $draft_id,
				'expected_settings'   => false,
			),
			array(
				'test_condition_name' => 'ゴミ箱・編集権限あり => 固定ページのゴミ箱一覧',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_TRASH,
					'page_id' => $trash_id,
				),
				'expected_url_part'   => 'edit.php?post_status=trash&post_type=page',
				'expected_settings'   => false,
			),
			array(
				'test_condition_name' => 'ゴミ箱・編集者 => 固定ページのゴミ箱一覧',
				'user_id'             => $editor_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_TRASH,
					'page_id' => $trash_id,
				),
				'expected_url_part'   => 'edit.php?post_status=trash&post_type=page',
				'expected_settings'   => false,
			),
			array(
				'test_condition_name' => '未公開・編集権限なし => 予約ページの設定欄（選び直してもらう）',
				'user_id'             => $subscriber_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id' => $draft_id,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => '未公開・業務オーナー（固定ページの権限なし） => 予約ページの設定欄（選び直してもらう）',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id' => $draft_id,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => 'ゴミ箱・業務オーナー（固定ページの権限なし） => 予約ページの設定欄（選び直してもらう）',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_TRASH,
					'page_id' => $trash_id,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => '未公開・固定ページを編集できるが公開できない => 予約ページの設定欄',
				'user_id'             => $no_publish_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id' => $draft_id,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => 'ゴミ箱・公開と固定ページの編集はできるが、他人のページを戻せない（delete_others_pages なし）利用者が、他人のページを見る => 予約ページの設定欄',
				'user_id'             => $no_delete_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_TRASH,
					'page_id' => $others_trash,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => 'ゴミ箱・固定ページを編集できるが公開できない => 予約ページの設定欄',
				'user_id'             => $no_publish_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_TRASH,
					'page_id' => $trash_id,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => '削除済み => 予約ページの設定欄',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'    => Page_Reference::PROBLEM_NOT_FOUND,
					'page_id' => 99999,
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
			array(
				'test_condition_name' => '旧ドメインの URL => 予約ページの設定欄',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'         => Reservation_Page_Url::PROBLEM_MISMATCH,
					'entered_host' => 'old.example.com',
					'site_host'    => 'example.test',
				),
				'expected_url_part'   => $settings_url,
				'expected_settings'   => true,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );

			$action = Reservation_Page_Url::get_problem_action( $case['problem'] );

			$this->assertStringContainsString( $case['expected_url_part'], $action['url'], $case['test_condition_name'] . '（行き先）' );
			$this->assertNotSame( '', $action['label'], $case['test_condition_name'] . '（ラベル）' );
			$this->assertSame( $case['expected_settings'], $action['is_settings'], $case['test_condition_name'] . '（設定欄への案内か）' );
		}

		wp_set_current_user( 0 );
	}

	/**
	 * build_problem_message() が、状態ごとに利用者へ次の行動が分かる文面を返し、ページ名をエスケープすることを検証する。
	 *
	 * 「次にすること」は get_problem_action() の行き先と一致させる（ページを直せない利用者には、設定欄で選び直すことを勧める）。
	 */
	public function test_build_problem_message(): void {
		// 業務オーナー（BM 設定はできるが、固定ページの権限を外されている）のロールを用意する。
		( new Roles_Manager() )->sync_roles();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner_id = self::factory()->user->create( array( 'role' => Roles_Manager::ROLE_PROVIDER_OWNER ) );
		$draft_id = $this->create_page( array( 'post_status' => 'draft' ) );
		$trash_id = $this->create_page( array( 'post_status' => 'trash' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '旧ドメイン => 入力中のドメインを code で強調した文面',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'         => Reservation_Page_Url::PROBLEM_MISMATCH,
					'entered_host' => 'old.example.com',
					'site_host'    => 'example.test',
				),
				'expected_contains'   => '<code>old.example.com</code>',
			),
			array(
				'test_condition_name' => '未公開・ページを直せる（管理者） => ページ名をエスケープして含み、公開を勧める',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => $draft_id,
					'page_title' => 'Booking <b>Draft</b>',
				),
				'expected_contains'   => 'Booking &lt;b&gt;Draft&lt;/b&gt;',
				'expected_action'     => 'Please publish the page.',
			),
			array(
				'test_condition_name' => '未公開・ページを直せない（業務オーナー） => ページ名を含み、公開ではなく選び直しを勧める',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_UNPUBLISHED,
					'page_id'    => $draft_id,
					'page_title' => 'Draft Booking',
				),
				'expected_contains'   => 'Draft Booking',
				'expected_action'     => 'Please select the reservation page again.',
				'unexpected_action'   => 'publish the page',
			),
			array(
				'test_condition_name' => 'ゴミ箱・ページを直せない（業務オーナー） => ページ名を含み、ゴミ箱から戻すのではなく選び直しを勧める',
				'user_id'             => $owner_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_TRASH,
					'page_id'    => $trash_id,
					'page_title' => 'Trashed Booking',
				),
				'expected_contains'   => 'Trashed Booking',
				'expected_action'     => 'Please select the reservation page again.',
				'unexpected_action'   => 'from the trash',
			),
			array(
				'test_condition_name' => 'ゴミ箱・ページを直せる（管理者） => ページ名を含み、ゴミ箱から戻して公開することを勧める（戻すと下書きになるため）',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_TRASH,
					'page_id'    => $trash_id,
					'page_title' => 'Trashed Booking',
				),
				'expected_contains'   => 'Trashed Booking',
				'expected_action'     => 'Please restore the page from the trash and publish it.',
			),
			array(
				'test_condition_name' => '削除済み => 選び直しを促す文面（ページ名は無い）',
				'user_id'             => $admin_id,
				'problem'             => array(
					'type'       => Page_Reference::PROBLEM_NOT_FOUND,
					'page_id'    => 99999,
					'page_title' => '',
				),
				'expected_contains'   => 'select',
				'expected_action'     => 'Please select the reservation page again.',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );

			$message = Reservation_Page_Url::build_problem_message( $case['problem'] );

			$this->assertNotSame( '', $message, $case['test_condition_name'] );
			$this->assertStringContainsString( $case['expected_contains'], $message, $case['test_condition_name'] );
			if ( isset( $case['expected_action'] ) ) {
				// 1 状態につき「次にすること」は 1 つ（公開する／ゴミ箱から戻して公開する／選び直す）。
				$this->assertStringContainsString( $case['expected_action'], $message, $case['test_condition_name'] . '（次にすること）' );
				$this->assertStringNotContainsString( 'or select', $message, $case['test_condition_name'] . '（2 択にしない）' );
			}
			if ( isset( $case['unexpected_action'] ) ) {
				$this->assertStringNotContainsString( $case['unexpected_action'], $message, $case['test_condition_name'] . '（できない行動を勧めない）' );
			}

			// 文面と行き先を一致させる: 選び直しを勧めるのは、行き先が予約ページの設定欄のときだけ。
			$action               = Reservation_Page_Url::get_problem_action( $case['problem'] );
			$asks_to_select_again = false !== strpos( $message, 'select the reservation page again' );
			$this->assertSame( $action['is_settings'], $asks_to_select_again, $case['test_condition_name'] . '（文面と行き先の一致）' );
		}

		wp_set_current_user( 0 );
	}
}
