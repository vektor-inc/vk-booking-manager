<?php
/**
 * 公開側料金の表示用整形テスト。
 *
 * @package VKBookingManager\Tests\Common
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Public_Price_Display;
use VKBookingManager\Common\VKBM_Helper;
use WP_UnitTestCase;

/**
 * 公開側料金の表示を確認する。
 *
 * @group common
 */
class Public_Price_Display_Test extends WP_UnitTestCase {
	/**
	 * 公開側料金の0円表示と正額表示を確認する。
	 *
	 * @return void
	 */
	public function test_format_currency(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '0円は翻訳可能な無料表示',
				'amount'              => 0,
				'expected'            => __( 'Free', 'vk-booking-manager' ),
			),
			array(
				'test_condition_name' => '正額は通貨記号付き',
				'amount'              => 1250,
				'expected'            => VKBM_Helper::format_currency( 1250 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Public_Price_Display::format_currency( $case['amount'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 単位付き料金の表示と折り返し構造を確認する。
	 *
	 * @return void
	 */
	public function test_format_price_with_unit_html(): void {
		$unit = '1 tour';
		$formatted_price = VKBM_Helper::format_currency( 1250 );
		$this->assertSame(
			'<span class="vkbm-price-chunk">Free / 1 tour</span>',
			Public_Price_Display::format_price_with_unit_html( 0, false, $unit )
		);
		$this->assertSame(
			'<span class="vkbm-price-chunk">' . $formatted_price . '~ / 1 tour</span>',
			Public_Price_Display::format_price_with_unit_html( 1250, true, $unit )
		);
		$this->assertSame(
			'<span class="vkbm-price-chunk">' . $formatted_price . ' / 1 tour</span>',
			Public_Price_Display::format_price_with_unit_html( 1250, false, $unit )
		);

		$lodging_unit = '1 guest, 1 night';
		$this->assertSame(
			'<span class="vkbm-price-chunk">Free</span> / <span class="vkbm-price-chunk">1 guest, 1 night</span>',
			Public_Price_Display::format_lodging_price_with_unit_html( 0, false, $lodging_unit )
		);
		$this->assertSame(
			'<span class="vkbm-price-chunk">' . $formatted_price . '~</span> / <span class="vkbm-price-chunk">1 guest, 1 night</span>',
			Public_Price_Display::format_lodging_price_with_unit_html( 1250, true, $lodging_unit )
		);
		$this->assertSame(
			'<span class="vkbm-price-chunk">' . $formatted_price . '</span> / <span class="vkbm-price-chunk">1 guest, 1 night</span>',
			Public_Price_Display::format_lodging_price_with_unit_html( 1250, false, $lodging_unit )
		);
	}
}
