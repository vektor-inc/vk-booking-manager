<?php
/**
 * Mail_From のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Mail_From;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 送信元メールアドレスの解決と警告判定を検証する。
 *
 * @group mail-from
 */
class Mail_From_Test extends WP_UnitTestCase {
	/**
	 * サイトURLを固定するテスト用フィルター。
	 *
	 * @var callable|null
	 */
	private $url_filter;

	/**
	 * 管理者メールを固定するテスト用フィルター。
	 *
	 * @var callable|null
	 */
	private $admin_email_filter;

	/**
	 * テスト後に設定とフィルターを掃除する。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		remove_all_filters( 'vkbm_mail_from_email' );
		remove_all_filters( 'vkbm_free_email_domains' );
		if ( is_callable( $this->url_filter ) ) {
			remove_filter( 'home_url', $this->url_filter );
			remove_filter( 'network_home_url', $this->url_filter );
		}
		if ( is_callable( $this->admin_email_filter ) ) {
			remove_filter( 'pre_option_admin_email', $this->admin_email_filter );
		}
		parent::tearDown();
	}

	/**
	 * 保存値、サイトドメイン、管理者メール、既存値の順に解決することを検証する。
	 */
	public function test_resolve(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '有効な保存値を使う',
				'settings'            => array( 'mail_from_email' => 'booking@example.com' ),
				'network_home_url'    => 'https://example.org/',
				'admin_email'         => 'admin@example.net',
				'fallback'            => 'existing@example.com',
				'expected'            => 'booking@example.com',
			),
			array(
				'test_condition_name' => '空欄はサイトドメインの no-reply を使う',
				'settings'            => array( 'mail_from_email' => '' ),
				'network_home_url'    => 'https://example.org/',
				'admin_email'         => 'admin@example.net',
				'fallback'            => 'existing@example.com',
				'expected'            => 'no-reply@example.org',
			),
			array(
				'test_condition_name' => 'www 付きホストは www を外す',
				'settings'            => null,
				'network_home_url'    => 'https://www.example.org/path',
				'admin_email'         => 'admin@example.net',
				'fallback'            => 'existing@example.com',
				'expected'            => 'no-reply@example.org',
			),
			array(
				'test_condition_name' => 'ドメインにピリオドが無い場合は管理者メールを使う',
				'settings'            => array( 'mail_from_email' => '' ),
				'network_home_url'    => 'http://localhost/',
				'admin_email'         => 'admin@example.net',
				'fallback'            => 'existing@example.com',
				'expected'            => 'admin@example.net',
			),
			array(
				'test_condition_name' => '管理者メールも不正な場合は既存値を使う',
				'settings'            => array( 'mail_from_email' => '' ),
				'network_home_url'    => 'http://localhost/',
				'admin_email'         => 'invalid',
				'fallback'            => 'existing@example.com',
				'expected'            => 'existing@example.com',
			),
		);

		foreach ( $test_cases as $case ) {
			delete_option( Settings_Repository::OPTION_KEY );
			if ( is_callable( $this->url_filter ) ) {
				remove_filter( 'home_url', $this->url_filter );
				remove_filter( 'network_home_url', $this->url_filter );
			}
			if ( is_callable( $this->admin_email_filter ) ) {
				remove_filter( 'pre_option_admin_email', $this->admin_email_filter );
			}
			if ( is_array( $case['settings'] ) ) {
				update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			}
			$this->url_filter = static function () use ( $case ): string {
				return $case['network_home_url'];
			};
			add_filter( 'home_url', $this->url_filter );
			add_filter( 'network_home_url', $this->url_filter );
			$this->admin_email_filter = static function () use ( $case ): string {
				return $case['admin_email'];
			};
			add_filter(
				'pre_option_admin_email',
				$this->admin_email_filter
			);
			$this->assertSame( $case['expected'], Mail_From::resolve( $case['fallback'] ), $case['test_condition_name'] );
		}
		$this->assertSame( 'no-reply@example.org', Mail_From::resolve_default_from_url( 'https://www.example.org/path' ) );
	}

	/**
	 * サイトドメイン、管理者メールの順に既定値を解決することを検証する。
	 */
	public function test_resolve_default(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'サイトドメインから no-reply を解決する',
				'network_home_url'    => 'https://www.example.org/',
				'admin_email'         => 'admin@example.net',
				'expected'            => 'no-reply@example.org',
			),
			array(
				'test_condition_name' => 'サイトドメインが不正なら管理者メールを使う',
				'network_home_url'    => 'http://localhost/',
				'admin_email'         => 'admin@example.net',
				'expected'            => 'admin@example.net',
			),
			array(
				'test_condition_name' => 'サイトドメインと管理者メールが不正なら空文字を返す',
				'network_home_url'    => 'http://localhost/',
				'admin_email'         => 'invalid',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			if ( is_callable( $this->url_filter ) ) {
				remove_filter( 'home_url', $this->url_filter );
				remove_filter( 'network_home_url', $this->url_filter );
			}
			if ( is_callable( $this->admin_email_filter ) ) {
				remove_filter( 'pre_option_admin_email', $this->admin_email_filter );
			}

			$this->url_filter = static function () use ( $case ): string {
				return $case['network_home_url'];
			};
			add_filter( 'home_url', $this->url_filter );
			add_filter( 'network_home_url', $this->url_filter );

			$this->admin_email_filter = static function () use ( $case ): string {
				return $case['admin_email'];
			};
			add_filter( 'pre_option_admin_email', $this->admin_email_filter );

			$this->assertSame( $case['expected'], Mail_From::resolve_default(), $case['test_condition_name'] );
		}
	}

	/**
	 * 不正なフィルター値を退け、メールヘッダーの From を安全に置換することを検証する。
	 */
	public function test_apply_to_email(): void {
		update_option( Settings_Repository::OPTION_KEY, array( 'mail_from_email' => 'sender@example.com' ) );
		add_filter(
			'vkbm_mail_from_email',
			static function (): string {
				return 'invalid';
			}
		);

		$email  = array( 'headers' => array( 'Content-Type: text/plain', 'From: Existing Name <old@example.com>' ) );
		$actual = Mail_From::apply_to_email( $email );

		$this->assertSame( 'sender@example.com', Mail_From::resolve() );
		$this->assertContains( 'From: Existing Name <sender@example.com>', $actual['headers'] );
		$this->assertNotContains( 'From: Existing Name <old@example.com>', $actual['headers'] );
	}

	/**
	 * フリーメール判定が完全一致かつ拡張可能であることを検証する。
	 */
	public function test_is_free_email(): void {
		$this->assertTrue( Mail_From::is_free_email( 'USER@GMAIL.COM' ) );
		$this->assertFalse( Mail_From::is_free_email( 'user@mail.gmail.com' ) );

		add_filter(
			'vkbm_free_email_domains',
			static function ( array $domains ): array {
				$domains[] = 'example.net';
				$domains[] = array();
				return $domains;
			}
		);
		$this->assertTrue( Mail_From::is_free_email( 'user@example.net' ) );

		remove_all_filters( 'vkbm_free_email_domains' );
		add_filter(
			'vkbm_free_email_domains',
			static function () {
				return 'invalid';
			}
		);
		$this->assertTrue( Mail_From::is_free_email( 'user@gmail.com' ) );
	}
}
