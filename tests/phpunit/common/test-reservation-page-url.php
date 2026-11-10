<?php
/**
 * 予約ページURLが自サイトのURLかを判定する共通処理（issue #1049）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Reservation_Page_Url;
use WP_UnitTestCase;

/**
 * Reservation_Page_Url の判定を検証する。
 *
 * @group common
 */
class Reservation_Page_Url_Test extends WP_UnitTestCase {

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
}
