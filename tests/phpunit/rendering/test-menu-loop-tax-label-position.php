<?php
/**
 * メニューループの税込みラベルの表示位置のテスト（#858）。
 *
 * カード表示では税込みラベルを金額の外（金額の下の別要素）に出し、
 * テキスト表示では従来どおり金額の直後の span に出すことを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use ReflectionMethod;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * 税込みラベルの表示位置を検証するテスト。
 */
class Menu_Loop_Tax_Label_Position_Test extends WP_UnitTestCase {
	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * 各テスト前にブロックインスタンスと税込みラベルを用意する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block = new Menu_Loop_Block();
		update_option( 'vkbm_provider_settings', array( 'tax_label_text' => '(税込)' ) );
	}

	/**
	 * 各テスト後に税込みラベルの設定を戻す。
	 */
	public function tear_down(): void {
		delete_option( 'vkbm_provider_settings' );
		parent::tear_down();
	}

	/**
	 * 基本料金だけのサービスメニューを作る。
	 *
	 * @return int 投稿ID。
	 */
	private function create_menu(): int {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス',
			)
		);
		update_post_meta( $menu_id, '_vkbm_base_price', 3500 );

		return (int) $menu_id;
	}

	/**
	 * カード表示の単一料金では、税込みラベルが金額の外（別の p 要素）に出ることを確認する。
	 */
	public function test_card_single_price_puts_tax_label_outside_price(): void {
		$output = $this->block->render_menu_card( $this->create_menu() );

		$this->assertMatchesRegularExpression( '#<div class="vkbm-menu-loop__card-price">[^<]*3,500[^<]*</div><p class="vkbm-menu-loop__card-prices-tax">\(税込\)</p>#u', $output );
		$this->assertSame( 1, substr_count( $output, '(税込)' ) );
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-price-tax', $output );
	}

	/**
	 * 税込みラベルが空のとき、カード表示は余計な要素を出さないことを確認する。
	 */
	public function test_card_single_price_without_tax_label_has_no_tax_element(): void {
		update_option( 'vkbm_provider_settings', array( 'tax_label_text' => '' ) );

		$output = $this->block->render_menu_card( $this->create_menu() );

		$this->assertStringContainsString( 'vkbm-menu-loop__card-price', $output );
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-prices-tax', $output );
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-price-tax', $output );
	}

	/**
	 * 泊のプランで区分が無い 1 行だけのカード表示でも、税込みラベルが金額の外に出ることを確認する。
	 */
	public function test_card_lodging_single_row_puts_tax_label_outside_price(): void {
		$menu_id = $this->create_menu();
		$method  = new ReflectionMethod( Menu_Loop_Block::class, 'render_lodging_price_card' );
		$method->setAccessible( true );

		$output = $method->invoke(
			$this->block,
			$menu_id,
			array(
				array(
					'label'   => '',
					'price'   => 7000,
					'is_from' => true,
				),
			)
		);

		$this->assertMatchesRegularExpression( '#<div class="vkbm-menu-loop__card-price">.*</div><p class="vkbm-menu-loop__card-prices-tax">\(税込\)</p>$#us', $output );
		$this->assertSame( 1, substr_count( $output, '(税込)' ) );
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-price-tax', $output );
	}

	/**
	 * テキスト表示は従来どおり、税込みラベルが金額の直後の span に出ることを確認する。
	 */
	public function test_text_item_keeps_tax_label_inline(): void {
		$menu_id = $this->create_menu();
		$method  = new ReflectionMethod( Menu_Loop_Block::class, 'render_text_item' );
		$method->setAccessible( true );

		$output = $method->invoke( $this->block, get_post( $menu_id ), array() );

		$this->assertStringContainsString( 'vkbm-menu-loop__text-price', $output );
		$this->assertMatchesRegularExpression( '#3,500[^<]*<span class="vkbm-menu-loop__card-price-tax">\(税込\)</span>#u', $output );
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-prices-tax', $output );
	}
}
