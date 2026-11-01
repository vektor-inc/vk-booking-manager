<?php
/**
 * カード背景の描画条件を検証する。
 *
 * @package VKBookingManager
 */

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/** カード・テキスト表示の背景クラスを検証する。 */
class Menu_Card_Background_Test extends WP_UnitTestCase {
	/** 白のときだけカードに背景クラスを付ける。 */
	public function test_card_background_class(): void {
		$id         = self::factory()->post->create(
			array(
				'post_type'   => 'vkbm_service_menu',
				'post_status' => 'publish',
			)
		);
		$test_cases = array(
			array(
				'test_condition_name' => '透明は背景なし',
				'conditions'          => 'transparent',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '白は背景あり',
				'conditions'          => 'white',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '不正値は背景なし',
				'conditions'          => 'invalid',
				'expected'            => false,
			),
		);
		foreach ( $test_cases as $case ) {
			$block = new Menu_Loop_Block();
			update_option( Settings_Repository::OPTION_KEY, array( 'design_menu_card_background' => $case['conditions'] ) );
			$html = $block->render_menu_card( $id );
			$this->assertStringContainsString( '<article', $html, $case['test_condition_name'] );
			$has_surface = false !== strpos( $html, 'vkbm-menu-loop__card-item--has-surface' );
			$this->assertSame( $case['expected'], $has_surface, $case['test_condition_name'] );
			$text = $block->render_menu_card( $id, array( 'displayMode' => 'text' ) );
			$this->assertStringNotContainsString( '--has-surface', $text, $case['test_condition_name'] );
		}
		delete_option( Settings_Repository::OPTION_KEY );
	}
}
