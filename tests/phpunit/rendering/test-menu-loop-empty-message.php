<?php
/**
 * メニュー一覧ブロックで、表示するメニューが無いときの案内文のテスト（#1129）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * Menu_Loop_Block::render_block() の、該当なしの案内文を検証する。
 *
 * ブロックエディタの中のプレビュー（ServerSideRender）も同じ render_block() で描くため、ここで両方を確かめる。
 *
 * @group rendering
 * @group resource-labels
 */
class Menu_Loop_Empty_Message_Test extends WP_UnitTestCase {

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
	 * 該当なしの案内文に「メニューの呼び方」（複数形）を差し込み、空欄・「メニュー」なら今までどおりの文にする。
	 *
	 * サービスメニューを1件も作らずに描画し、該当なしの案内を出させる。期待値は翻訳の読み込み状態によらないよう、本番と同じ翻訳関数から組み立てる。
	 *
	 * @return void
	 */
	public function test_render_block(): void {
		$default_message = esc_html( __( 'No service menus matching the criteria were found.', 'vk-booking-manager' ) );
		$plans_message   = esc_html( sprintf( __( 'No %s matching the criteria were found.', 'vk-booking-manager' ), 'Plans' ) );
		$plan_ja_message = esc_html( sprintf( __( 'No %s matching the criteria were found.', 'vk-booking-manager' ), 'プラン' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '呼び方が未保存の場合 => 今までどおりの案内文',
				'options'             => null,
				'attributes'          => array(),
				'expected'            => $default_message,
			),
			array(
				'test_condition_name' => '呼び方に メニュー と入力した場合 => 今までどおりの案内文',
				'options'             => array( 'menu_label' => 'メニュー' ),
				'attributes'          => array(),
				'expected'            => $default_message,
			),
			array(
				'test_condition_name' => '呼び方が プラン（複数形なし）の場合 => プラン を差し込んだ案内文',
				'options'             => array( 'menu_label' => 'プラン' ),
				'attributes'          => array(),
				'expected'            => $plan_ja_message,
			),
			array(
				'test_condition_name' => '呼び方が Plan・複数形が Plans の場合 => 複数形の Plans を差し込んだ案内文',
				'options'             => array(
					'menu_label'        => 'Plan',
					'menu_label_plural' => 'Plans',
				),
				'attributes'          => array(),
				'expected'            => $plans_message,
			),
			array(
				'test_condition_name' => 'ブロックで該当なしの文を指定した場合 => 呼び方を設定していても指定した文',
				'options'             => array( 'menu_label' => 'プラン' ),
				'attributes'          => array( 'emptyMessage' => '準備中です' ),
				'expected'            => '準備中です',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			// 保存値を用意する。
			delete_option( Settings_Repository::OPTION_KEY );
			if ( null !== $case['options'] ) {
				update_option( Settings_Repository::OPTION_KEY, $case['options'] );
			}

			// メニューが無い状態でブロックを描画する。
			$html = ( new Menu_Loop_Block() )->render_block(
				array_merge( array( 'loopId' => 'empty-message-test-' . $index ), $case['attributes'] )
			);

			$this->assertStringContainsString( 'vkbm-menu-loop--empty', $html, $case['test_condition_name'] . '（該当なしの表示）' );
			$this->assertStringContainsString( $case['expected'], $html, $case['test_condition_name'] );
		}
	}
}
