<?php
/**
 * 最低申し込み人数の不足エラー文 Nomination_Min_Guests_Message のテスト（#1129）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Nomination_Min_Guests_Message のエラー文の組み立てを検証する。
 *
 * @group common
 * @group resource-labels
 */
class Nomination_Min_Guests_Message_Test extends WP_UnitTestCase {

	/**
	 * 各テスト後に基本設定を破棄する。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 1文目に「メニューの呼び方」を差し込み、空欄・「メニュー」なら今までどおりの文にする。
	 *
	 * 翻訳の読み込み状態はテストの実行順で変わるため、期待値は本番と同じ翻訳関数から組み立てる。
	 *
	 * @return void
	 */
	public function test_build_message(): void {
		// 呼び方を変えていないときの文（今までどおりの文）。
		$default_message = Nomination_Min_Guests_Message::join_sentences(
			sprintf( __( 'This menu accepts bookings from %d guests.', 'vk-booking-manager' ), 3 ),
			sprintf( __( 'Please set the number of guests to %d or more.', 'vk-booking-manager' ), 3 )
		);
		// 呼び方に「プラン」を設定したときの文。
		$plan_message = Nomination_Min_Guests_Message::join_sentences(
			sprintf( __( 'This %1$s accepts bookings from %2$d guests.', 'vk-booking-manager' ), 'プラン', 3 ),
			sprintf( __( 'Please set the number of guests to %d or more.', 'vk-booking-manager' ), 3 )
		);

		$test_cases = array(
			array(
				'test_condition_name' => '呼び方が未保存の場合 => 今までどおりの文',
				'options'             => null,
				'expected'            => $default_message,
			),
			array(
				'test_condition_name' => '呼び方に メニュー と入力した場合 => 今までどおりの文',
				'options'             => array( 'menu_label' => 'メニュー' ),
				'expected'            => $default_message,
			),
			array(
				'test_condition_name' => '呼び方が プラン の場合 => 1文目に プラン を差し込む',
				'options'             => array( 'menu_label' => 'プラン' ),
				'expected'            => $plan_message,
			),
		);

		foreach ( $test_cases as $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			$actual = Nomination_Min_Guests_Message::build_message( 3 );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
