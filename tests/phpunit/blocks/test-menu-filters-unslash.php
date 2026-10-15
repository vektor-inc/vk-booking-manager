<?php
/**
 * #691: メニュー検索・メニューループの検索語から、「\」が1文字消えないことのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use ReflectionClass;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Blocks\Menu_Search_Block;
use WP_UnitTestCase;

/**
 * get_filters_from_request() のテスト。
 *
 * @group blocks
 */
class Menu_Filters_Unslash_Test extends WP_UnitTestCase {

	/**
	 * テスト前の $_GET。
	 *
	 * @var array<string, mixed>
	 */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->get_backup = $_GET;
	}

	protected function tearDown(): void {
		$_GET = $this->get_backup;
		parent::tearDown();
	}

	/**
	 * WordPress は $_GET にスラッシュを追加済みの値を入れる（wp_magic_quotes）。
	 * 利用者が入力した「C:\path」は、$_GET では「C:\\path」になっている。
	 * 1回だけ unslash すれば入力どおりに戻ること。
	 */
	public function test_get_filters_from_request(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '「\」を1つ含む検索語が入力どおりに戻る',
				'slashed_keyword'     => 'C:\\\\path',
				'expected'            => 'C:\\path',
			),
			array(
				'test_condition_name' => '「\」を2つ連続で含む検索語が2つのまま戻る',
				'slashed_keyword'     => 'a\\\\\\\\b',
				'expected'            => 'a\\\\b',
			),
			array(
				'test_condition_name' => '「\」を含まない検索語は変わらない',
				'slashed_keyword'     => 'カット',
				'expected'            => 'カット',
			),
		);

		$targets = array(
			array( new Menu_Search_Block(), 'search-1' ),
			array( new Menu_Loop_Block(), 'loop-1' ),
		);

		foreach ( $targets as $target ) {
			list( $block, $id ) = $target;
			$method             = ( new ReflectionClass( $block ) )->getMethod( 'get_filters_from_request' );
			$method->setAccessible( true );

			foreach ( $test_cases as $case ) {
				$_GET   = array(
					Menu_Loop_Block::REQUEST_KEY => array(
						$id => array( 'keyword' => $case['slashed_keyword'] ),
					),
				);
				$result = $method->invoke( $block, $id );
				$this->assertSame(
					$case['expected'],
					$result['keyword'],
					get_class( $block ) . ': ' . $case['test_condition_name']
				);
			}
		}
	}
}
