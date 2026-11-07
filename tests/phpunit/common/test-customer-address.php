<?php
/**
 * 住所の入力検証。
 *
 * @package VKBookingManager
 */

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Customer_Address;
use WP_UnitTestCase;

/**
 * 住所の検証を確認する。
 *
 * @group customer-address
 */
class Customer_Address_Test extends WP_UnitTestCase {
	/** 文字種を制限せず、空欄と255文字の境界を検証する。 */
	public function test_sanitize_and_validate(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '日本の住所',
				'input'               => '東京都千代田区1-2',
				'required'            => true,
				'expected'            => '東京都千代田区1-2',
			),
			array(
				'test_condition_name' => '海外の住所',
				'input'               => '12 Rue de l’Église, Paris',
				'required'            => true,
				'expected'            => '12 Rue de l’Église, Paris',
			),
			array(
				'test_condition_name' => '255文字まで保存',
				'input'               => str_repeat( '住', 255 ),
				'required'            => true,
				'expected'            => str_repeat( '住', 255 ),
			),
			array(
				'test_condition_name' => '256文字はエラー',
				'input'               => str_repeat( '住', 256 ),
				'required'            => false,
				'expected'            => 'customer_address_too_long',
			),
			array(
				'test_condition_name' => '必須の空欄',
				'input'               => '',
				'required'            => true,
				'expected'            => 'customer_address_required',
			),
			array(
				'test_condition_name' => '全角スペース等だけは必須エラー',
				'input'               => " 　\t\n ",
				'required'            => true,
				'expected'            => 'customer_address_required',
			),
			array(
				'test_condition_name' => '任意は空欄可',
				'input'               => '　 ',
				'required'            => false,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '配列は拒否',
				'input'               => array( '住所' ),
				'required'            => false,
				'expected'            => 'customer_address_invalid',
			),
			array(
				'test_condition_name' => 'HTMLを除去',
				'input'               => '<b>東京</b>',
				'required'            => true,
				'expected'            => '東京',
			),
		);
		foreach ( $test_cases as $case ) {
			$actual = Customer_Address::sanitize_and_validate( $case['input'], $case['required'] );
			$this->assertSame( $case['expected'], is_wp_error( $actual ) ? $actual->get_error_code() : $actual, $case['test_condition_name'] );
		}
	}

	/** 訂正用住所は256文字の入力を保持し、巨大な値は制限する。 */
	public function test_sanitize_for_redisplay(): void {
		$test_cases = array(
			array( 'test_condition_name' => '256文字の訂正を保持', 'input' => str_repeat( '住', 256 ), 'expected' => str_repeat( '住', 256 ) ),
			array( 'test_condition_name' => '巨大入力は1024文字へ制限', 'input' => str_repeat( '住', 100000 ), 'expected' => str_repeat( '住', 1024 ) ),
			array( 'test_condition_name' => '配列は空欄へ', 'input' => array( '住所' ), 'expected' => '' ),
			array( 'test_condition_name' => 'HTMLは除去', 'input' => '<b>東京</b>', 'expected' => '東京' ),
		);
		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Customer_Address::sanitize_for_redisplay( $case['input'] ), $case['test_condition_name'] );
		}
	}
}
