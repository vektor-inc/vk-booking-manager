<?php
/**
 * #650: メニュー検索ブロックのフォームの送信先 URL が、リクエストヘッダー（HTTP_HOST）に依存しないことのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Blocks\Menu_Search_Block;
use WP_UnitTestCase;

/**
 * Menu_Search_Block::get_form_action_url() のテスト。
 *
 * @group blocks
 */
class Menu_Search_Block_Action_Url_Test extends WP_UnitTestCase {

	/**
	 * テスト前の $_SERVER。
	 *
	 * @var array<string, mixed>
	 */
	private array $server_backup = array();

	/**
	 * 各テストの前に $_SERVER を退避する。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->server_backup = $_SERVER;
	}

	/**
	 * 各テストの後に $_SERVER を戻す。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		parent::tearDown();
	}

	/**
	 * フォームの送信先 URL のホスト名がサイト設定（home_url）から決まり、パスとクエリだけがリクエストから引き継がれること。
	 *
	 * @return void
	 */
	public function test_get_form_action_url(): void {
		$home_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$test_cases = array(
			array(
				'test_condition_name' => 'HTTP_HOST が偽装されている => サイトのホスト名のまま、パスとクエリを引き継ぐ',
				'http_host'           => 'evil.example.com',
				'request_uri'         => '/menu/?foo=bar',
				'expected_path'       => '/menu/',
				'expected_query'      => 'foo=bar',
			),
			array(
				'test_condition_name' => '検索条件のクエリ（vkbm_menu_search） => 取り除く',
				'http_host'           => 'evil.example.com',
				'request_uri'         => '/menu/?vkbm_menu_search%5Bstaff%5D=1&foo=bar',
				'expected_path'       => '/menu/',
				'expected_query'      => 'foo=bar',
			),
			array(
				'test_condition_name' => 'REQUEST_URI が //evil.example.com/ で始まる => ホストはサイトのまま（パスの一部として扱う）',
				'http_host'           => 'evil.example.com',
				'request_uri'         => '//evil.example.com/menu/',
				'expected_path'       => '/evil.example.com/menu/',
				'expected_query'      => '',
			),
		);

		$block  = new Menu_Search_Block();
		$method = new \ReflectionMethod( Menu_Search_Block::class, 'get_form_action_url' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$_SERVER['HTTP_HOST']   = $case['http_host'];
			$_SERVER['REQUEST_URI'] = $case['request_uri'];

			$url = (string) $method->invoke( $block );

			$this->assertSame( $home_host, wp_parse_url( $url, PHP_URL_HOST ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_path'], wp_parse_url( $url, PHP_URL_PATH ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_query'], (string) wp_parse_url( $url, PHP_URL_QUERY ), $case['test_condition_name'] );
		}

		// REQUEST_URI が無い場合は、トップページの URL になる。
		unset( $_SERVER['REQUEST_URI'] );
		$this->assertSame( home_url( '/' ), $method->invoke( $block ) );
	}

	/**
	 * サイトをサブディレクトリに設置している場合（home が https://example.com/sub）に、パスの先頭のサブディレクトリが二重にならないこと。
	 *
	 * @return void
	 */
	public function test_get_form_action_url_does_not_duplicate_subdirectory(): void {
		// wp-env では WP_HOME 定数が option より優先されるため、フィルターで home_url() を差し替える。
		add_filter(
			'home_url',
			static function ( $url, $path ): string {
				return 'https://example.com/sub' . ( '' !== (string) $path ? '/' . ltrim( (string) $path, '/' ) : '' );
			},
			10,
			2
		);

		$_SERVER['REQUEST_URI'] = '/sub/menu/?foo=bar';

		$method = new \ReflectionMethod( Menu_Search_Block::class, 'get_form_action_url' );
		$method->setAccessible( true );

		$this->assertSame( 'https://example.com/sub/menu/?foo=bar', $method->invoke( new Menu_Search_Block() ) );
	}
}
