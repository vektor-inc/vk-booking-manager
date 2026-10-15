<?php
/**
 * #650: 予約ブロックの現在の URL が、サイトの設定（home_url）を基準に組み立てられることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * Reservation_Block::get_current_url() のテスト。
 *
 * @group blocks
 */
class Reservation_Block_Current_Url_Test extends WP_UnitTestCase {

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
	 * サブディレクトリ設置（home が https://example.com/sub）で、パスのサブディレクトリが二重にならず、
	 * REQUEST_URI がホストに見える形でもサイトのホストになること。
	 *
	 * @return void
	 */
	public function test_get_current_url(): void {
		// wp-env では WP_HOME 定数が option より優先されるため、フィルターで home_url() を差し替える。
		add_filter(
			'home_url',
			static function ( $url, $path ): string {
				return 'https://example.com/sub' . ( '' !== (string) $path ? '/' . ltrim( (string) $path, '/' ) : '' );
			},
			10,
			2
		);

		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );
		$block   = new Reservation_Block( new Auth_Shortcodes( $service ) );
		$post    = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		$method  = new \ReflectionMethod( Reservation_Block::class, 'get_current_url' );
		$method->setAccessible( true );

		$test_cases = array(
			array(
				'test_condition_name' => 'サブディレクトリ設置 => サブディレクトリが二重にならない',
				'request_uri'         => '/sub/booking/?foo=bar',
				'expected'            => 'https://example.com/sub/booking/?foo=bar',
			),
			array(
				'test_condition_name' => 'REQUEST_URI が //evil.example.com/ で始まる => ホストはサイトのまま',
				'request_uri'         => '//evil.example.com/booking/',
				'expected'            => 'https://example.com/evil.example.com/booking/',
			),
		);

		foreach ( $test_cases as $case ) {
			$_SERVER['REQUEST_URI'] = $case['request_uri'];

			$this->assertSame( $case['expected'], $method->invoke( $block, $post ), $case['test_condition_name'] );
		}
	}
}
