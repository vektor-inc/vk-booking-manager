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

use VKBookingManager\Admin\Pro_Upsell;
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
	 * @param array<int, array{label: string, price: int}> $tiers        保存する料金区分。
	 * @param int                                          $base_price   基本料金（単一料金）。
	 * @param int                                          $max_capacity 予約枠の定員（_vkbm_max_capacity）。
	 *                                                                   $tiers が空でないときのみ設定される。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_tiers( array $tiers, int $base_price = 5000, int $max_capacity = 2 ): int {
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
			// #412 C-1: 料金区分の表示は「このメニューで指名機能を使っていない」かつ
			// 「複数人一括予約を許可する」がONであることが前提（resolve_menu_price_tiers() と
			// 同じ実効ゲート、Staff_Editor::is_multi_guest_available_for_menu() を使用）になったため、
			// このテストで検証したい「区分の有無による表示分岐」をゲートの影響から切り離すために
			// 明示的に指名を無効化し、複数人一括予約を許可する。
			update_post_meta( $menu_id, '_vkbm_disable_nomination', true );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			// #412 F-1: resolve_menu_price_tiers() は「実効定員が2以上」も必須にしており、
			// 表示側のゲートもこれに揃えたため、区分ありのケースでは既定で定員2以上を設定する
			// （デフォルト未設定＝実質1のままだと、区分の有無による表示分岐を検証したいこのテストが
			// 定員ゲートで弾かれて意図しない失敗になるため）。
			update_post_meta( $menu_id, '_vkbm_max_capacity', $max_capacity );
		}

		return (int) $menu_id;
	}

	/**
	 * 料金区分が設定されている場合、単一料金を出さずに区分一覧を表示することを確認する。
	 */
	public function test_card_renders_price_tiers_list_when_tiers_exist(): void {
		// #412 F-2: 料金区分表示は Pro 版限定機能（Staff_Editor::is_multi_guest_available_for_menu()）
		// のため、無料版ビルドでは常に単一料金へフォールバックし、この「表示される」ケースは成立しない。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分の一覧表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

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
	 * #412 F-1: 料金区分が設定されていても、実効定員（_vkbm_max_capacity）が1
	 * （未設定を含む）の場合は区分一覧を表示せず、基本料金の単一表示にフォールバック
	 * することを確認する。
	 *
	 * resolve_menu_price_tiers()（Booking_Confirmation_Controller）は「実効定員が2以上」
	 * も区分適用の条件にしており、これが表示側のゲートに抜けていると、
	 * 「定員2以上・複数人一括予約ON・区分あり」で保存した後に定員だけ1へ戻しても、
	 * カードには区分表が残り続ける一方、実際の課金は基本料金×1名になる、という
	 * C-1 と同種の不一致が再発する。このテストはその不一致が無いことを固定化する。
	 *
	 * Pro/Free いずれでも「実効定員1（未設定含む）では区分を表示しない」という
	 * 結論は変わらないため、この負側の検証は Free 版でもスキップしない。
	 */
	public function test_card_does_not_render_price_tiers_when_max_capacity_is_one(): void {
		// 定員を明示的に1に設定する（未設定＝実質1と同じ状態を明示的に固定する）。
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
			),
			5000,
			1
		);

		$output = $this->block->render_menu_card( $menu_id );

		// 区分一覧は出力されず、基本料金の単一表示にフォールバックする。
		$this->assertStringNotContainsString( 'vkbm-menu-loop__card-prices', $output );
		$this->assertStringNotContainsString( '大人', $output );
		$this->assertStringNotContainsString( '子供', $output );
		$this->assertStringContainsString( 'vkbm-menu-loop__card-price', $output );
	}

	/**
	 * 区分数が多い場合でも全区分が漏れなく出力され、スクロール用クラスが付かないことを確認する。
	 *
	 * 縦スクロール表示は廃止し、件数によらず全件を縦に並べて表示する仕様のため、
	 * 6件でも全ラベルが出力され、`--scroll` クラスは一切付与されない。
	 */
	public function test_card_renders_all_tiers_without_scroll_class(): void {
		// #412 F-2: 料金区分表示は Pro 版限定機能のため、無料版ではスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分の一覧表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

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
		// #412 F-2: 料金区分表示は Pro 版限定機能のため、無料版ではスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分の一覧表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

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
