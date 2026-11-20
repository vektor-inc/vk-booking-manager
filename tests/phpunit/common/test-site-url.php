<?php
/**
 * Site_Url（リクエストの URI からサイトの URL を組み立てる共通処理）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Site_Url;
use WP_UnitTestCase;

/**
 * サブディレクトリ設置でもサイトのパスが二重にならないことを検証する。
 *
 * @group common
 */
class Site_Url_Test extends WP_UnitTestCase {

	/**
	 * サイトのパスは1回だけ入り、スキーム・ホスト・ポートは home_url() のものが使われること。
	 */
	public function test_from_request_uri(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'サブディレクトリ設置でサイトのパスを含むURI => パスは1回だけ',
				'home'                => 'https://example.com/sub',
				'request_uri'         => '/sub/?draft=1&vkbm_auth=login',
				'expected'            => 'https://example.com/sub/?draft=1&vkbm_auth=login',
			),
			array(
				'test_condition_name' => 'ドメイン直下設置 => home_url() に渡した場合と同じURL',
				'home'                => 'https://example.com',
				'request_uri'         => '/?draft=1&vkbm_auth=login',
				'expected'            => 'https://example.com/?draft=1&vkbm_auth=login',
			),
			array(
				'test_condition_name' => 'ポート番号付きのサイト => ポート番号を残す',
				'home'                => 'http://localhost:8888/sub',
				'request_uri'         => '/sub/reserve/',
				'expected'            => 'http://localhost:8888/sub/reserve/',
			),
			array(
				'test_condition_name' => '先頭が // のURI => 他ドメインとして解釈されず自サイトのURLになる',
				'home'                => 'https://example.com',
				'request_uri'         => '//evil.example/path',
				'expected'            => 'https://example.com/evil.example/path',
			),
			array(
				'test_condition_name' => 'home_url() にホストが無い => パスを二重にせずサイトのトップを返す',
				'home'                => '/sub',
				'request_uri'         => '/sub/?a=1',
				'expected'            => '/sub/',
			),
		);

		foreach ( $test_cases as $case ) {
			// サイトアドレスを差し替える。
			$home_filter = static function () use ( $case ) {
				return $case['home'];
			};
			add_filter( 'pre_option_home', $home_filter );

			$actual = Site_Url::from_request_uri( $case['request_uri'] );

			remove_filter( 'pre_option_home', $home_filter );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
