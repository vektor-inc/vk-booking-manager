<?php
/**
 * メニューループ（カード）の料金区分表示の分岐テスト。
 *
 * 料金区分（_vkbm_price_tiers）が設定されているサービスでは単一料金を出さず
 * 区分一覧を表示し、区分が無いサービスでは従来どおり単一料金を表示することを確認する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * カードレイアウトの料金区分一覧表示を検証するテスト。
 */
class Menu_Loop_Card_Price_Tiers_Test extends WP_UnitTestCase {
	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * 各テスト前にブロックインスタンスを用意する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block = new Menu_Loop_Block();
	}

	/**
	 * 料金区分が設定されたサービスのカードを生成するヘルパー。
	 *
	 * @param array<int, array{label: string, price: int}> $tiers      保存する料金区分。
	 * @param int                                          $base_price 基本料金（単一料金）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_tiers( array $tiers, int $base_price = 5000 ): int {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス',
			)
		);

		update_post_meta( $menu_id, '_vkbm_base_price', $base_price );
		if ( ! empty( $tiers ) ) {
			update_post_meta( $menu_id, '_vkbm_price_tiers', $tiers );
		}

		return (int) $menu_id;
	}

	/**
	 * 料金区分が設定されている場合、単一料金を出さずに区分一覧を表示することを確認する。
	 */
	public function test_card_renders_price_tiers_list_when_tiers_exist(): void {
		$menu_id = $this->create_menu_with_tiers(
			array(
				array(
					'label' => '大人',
					'price' => 5000,
				),
				array(
					'label' => '子供',
					'price' => 3000,
				),
			)
		);

		$output = $this->block->render_menu_card( $menu_id );

		// 区分一覧のラッパと各区分のラベル・料金が出力されている。
		$this->assertStringContainsString( 'vkbm-menu-loop__card-prices', $output );
		$this->assertStringContainsString( 'vkbm-menu-loop__card-prices-label', $output );
		$this->assertStringContainsString( '大人', $output );
		$this->assertStringContainsString( '子供', $output );

		// 区分があるときは単一料金のマークアップ（card-price）は出力されない（排他）。
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-price"', $output );

		// 件数が少ない（閾値未満）のためスクロール用クラスは付かない。
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-prices--scroll', $output );
	}

	/**
	 * 料金区分が無い場合、従来どおり単一料金を表示することを確認する。
	 */
	public function test_card_renders_single_price_when_no_tiers(): void {
		$menu_id = $this->create_menu_with_tiers( array(), 4800 );

		$output = $this->block->render_menu_card( $menu_id );

		// 単一料金のマークアップが出力され、区分一覧は出力されない。
		$this->assertStringContainsString( 'vkbm-menu-loop__card-price', $output );
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-prices', $output );
	}

	/**
	 * 区分数が多い場合でも全区分が漏れなく出力され、スクロール用クラスが付かないことを確認する。
	 *
	 * 縦スクロール表示は廃止し、件数によらず全件を縦に並べて表示する仕様のため、
	 * 6件でも全ラベルが出力され、`--scroll` クラスは一切付与されない。
	 */
	public function test_card_renders_all_tiers_without_scroll_class(): void {
		$tiers = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$tiers[] = array(
				'label' => '区分' . $i,
				'price' => 1000 * $i,
			);
		}

		$menu_id = $this->create_menu_with_tiers( $tiers );

		$output = $this->block->render_menu_card( $menu_id );

		// 6件すべての区分名が漏れなく出力されている。
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertStringContainsString( '区分' . $i, $output );
		}

		// スクロール用の修飾クラスは（仕様廃止により）一切付与されない。
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-prices--scroll', $output );
	}

	/**
	 * 税込みラベルが設定されている場合、各行ではなく一覧の末尾に1回だけ表示されることを確認する。
	 */
	public function test_tax_label_appears_once_at_the_end(): void {
		// 税込みラベルをプロバイダ設定に登録する。
		update_option(
			'vkbm_provider_settings',
			array( 'tax_label_text' => '税込' )
		);

		// assertion が失敗しても必ず後始末（delete_option）が走るよう try/finally で囲む。
		// これを怠ると失敗時にオプションが残り、後続テストの分離が崩れる。
		try {
			$menu_id = $this->create_menu_with_tiers(
				array(
					array(
						'label' => '大人',
						'price' => 5000,
					),
					array(
						'label' => '子供',
						'price' => 3000,
					),
				)
			);

			$output = $this->block->render_menu_card( $menu_id );

			// 末尾用の税込みラベル要素が出力されている。
			$this->assertStringContainsString( 'vkbm-menu-loop__card-prices-tax', $output );

			// 税込みラベル文言は一覧内で1回だけ出現する（各行には付かない）。
			$this->assertSame( 1, substr_count( $output, '税込' ) );
		} finally {
			delete_option( 'vkbm_provider_settings' );
		}
	}
}
