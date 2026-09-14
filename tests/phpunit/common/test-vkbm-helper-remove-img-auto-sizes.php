<?php
/**
 * VKBM_Helper::remove_img_auto_sizes() のテスト。
 *
 * img タグの sizes 属性の先頭にある auto だけを取り除き、
 * それ以外の指定や、auto を含まない HTML は変えないことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\VKBM_Helper;
use WP_UnitTestCase;

/**
 * sizes 属性から auto を取り除く処理を検証するテスト。
 *
 * @group common
 */
class VKBM_Helper_Remove_Img_Auto_Sizes_Test extends WP_UnitTestCase {
	/**
	 * sizes 属性の内容に応じて、auto だけが取り除かれることを確認する。
	 */
	public function test_remove_img_auto_sizes(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '先頭に auto がある場合 => auto だけを取り除き、残りの指定を残す',
				'image'               => '<img src="a.jpg" sizes="auto, (max-width: 767px) 100vw, 480px" loading="lazy">',
				'expected'            => '<img src="a.jpg" sizes="(max-width: 767px) 100vw, 480px" loading="lazy">',
			),
			array(
				'test_condition_name' => 'auto が大文字で前後に空白がある場合 => auto だけを取り除き、残りの指定の前後の空白も除く',
				'image'               => '<img src="a.jpg" sizes=" AUTO ,  480px " loading="lazy">',
				'expected'            => '<img src="a.jpg" sizes="480px" loading="lazy">',
			),
			array(
				'test_condition_name' => 'sizes 属性が auto だけの場合 => sizes 属性ごと削除する',
				'image'               => '<img src="a.jpg" sizes="auto" loading="lazy">',
				'expected'            => '<img src="a.jpg"  loading="lazy">',
			),
			array(
				'test_condition_name' => '先頭が auto でない場合 => HTML を変えない',
				'image'               => '<img src="a.jpg" sizes="(max-width: 767px) 100vw, auto">',
				'expected'            => '<img src="a.jpg" sizes="(max-width: 767px) 100vw, auto">',
			),
			array(
				'test_condition_name' => 'auto で始まる別の語の場合 => auto とみなさず HTML を変えない',
				'image'               => '<img src="a.jpg" sizes="autox, 480px">',
				'expected'            => '<img src="a.jpg" sizes="autox, 480px">',
			),
			array(
				'test_condition_name' => 'sizes 属性が無い場合 => HTML を変えない',
				'image'               => '<img src="a.jpg" loading="lazy">',
				'expected'            => '<img src="a.jpg" loading="lazy">',
			),
			array(
				'test_condition_name' => 'img タグが無い場合 => HTML を変えない',
				'image'               => '<div sizes="auto, 480px"></div>',
				'expected'            => '<div sizes="auto, 480px"></div>',
			),
			array(
				'test_condition_name' => '空文字の場合 => 空文字を返す',
				'image'               => '',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			// 各ケースの入力を渡し、期待する HTML と一致するかを確認する。
			$actual = VKBM_Helper::remove_img_auto_sizes( $case['image'] );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
