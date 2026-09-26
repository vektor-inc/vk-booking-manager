<?php
/**
 * サービスメニュー一覧の料金列（vkbm_price）が、料金区分で設定されているメニューでは
 * 基本料金の代わりに区分を全件表示することを検証するテスト（#515）。
 *
 * 判定は公開側メニューカード（Menu_Loop_Block）と共通の
 * Price_Tiers::is_menu_using_price_tiers() を使う。この一覧側の分岐テストでは
 * 主に出力（HTMLエスケープ・0円表示・フォールバック・クイック編集用 data 属性）を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;
use function update_post_meta;
use function wp_set_current_user;

/**
 * render_admin_columns()（vkbm_price 列）の表示分岐を検証するテスト。
 *
 * @group post-types
 */
class Service_Menu_List_Price_Tiers_Column_Test extends WP_UnitTestCase {

	/**
	 * テスト対象のインスタンス。
	 *
	 * @var Service_Menu_Post_Type
	 */
	private $post_type;

	/**
	 * テスト前に管理者としてログインし、テスト対象のインスタンスを用意する。
	 */
	public function set_up(): void {
		parent::set_up();

		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->post_type = new Service_Menu_Post_Type();
	}

	/**
	 * テスト後にログイン状態をリセットする。
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 料金区分で設定されているメニューを作成するヘルパー。
	 *
	 * @param array<int, array{label: string, price: int}> $tiers 保存する料金区分。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_using_price_tiers( array $tiers ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス（#515）',
			)
		);

		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );
		update_post_meta( $menu_id, '_vkbm_price_tiers', $tiers );

		return $menu_id;
	}

	/**
	 * render_admin_columns()（vkbm_price 列）の出力を取得するヘルパー。
	 *
	 * @param int $menu_id 対象メニューの投稿ID。
	 * @return string 出力されたHTML。
	 */
	private function render_price_column( int $menu_id ): string {
		ob_start();
		$this->post_type->render_admin_columns( 'vkbm_price', $menu_id );
		return (string) ob_get_clean();
	}

	/**
	 * 料金区分で設定されているメニューは、基本料金の代わりに区分を全件「区分名: 料金」で
	 * 縦（ul/li）に表示することを確認する（省略しない・0円は「0」表示・ラベルはエスケープ）。
	 */
	public function test_render_admin_columns_shows_all_tiers_when_menu_uses_price_tiers(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分の一覧表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu_using_price_tiers(
			array(
				array(
					'label' => '一般&特別',
					'price' => 4000,
				),
				array(
					'label' => '子供',
					'price' => 0,
				),
				array(
					'label' => '幼児',
					'price' => 3000,
				),
			)
		);

		$output = $this->render_price_column( $menu_id );

		// ul/li で区分一覧が出力される。
		$this->assertStringContainsString( '<ul class="vkbm-admin-price-tiers-list">', $output );
		$this->assertSame( 3, substr_count( $output, '<li>' ), '区分は省略されず3件すべて出力される' );

		// ラベルは esc_html でエスケープされる（"&" がそのまま出力されず "&amp;" になる）。
		$this->assertStringContainsString( '一般&amp;特別', $output );

		// 0円の区分は「—」ではなく「0」と表示される。
		$this->assertStringContainsString( '子供: <span class="vkbm-admin-price-tiers-price">0</span>', $output );
		$this->assertStringContainsString( '幼児: <span class="vkbm-admin-price-tiers-price">3,000</span>', $output );

		// 基本料金の単一表示（フォールバック）は出力されない。
		$this->assertStringNotContainsString( '—', $output );

		// スクリーンリーダー向けに「料金区分」ラベルが付与されている。
		$this->assertStringContainsString( '<span class="screen-reader-text">Price categories</span>', $output );

		// クイック編集用の data 属性（uses-price-tiers・edit URL）が付与されている。
		$this->assertStringContainsString( 'data-uses-price-tiers="1"', $output );
		$this->assertStringContainsString( 'data-price-tiers-edit-url="', $output );
		$this->assertStringContainsString( '#vkbm-price-tiers-field', $output );
	}

	/**
	 * 料金区分が無いメニューは、従来どおり基本料金の単一表示のままであることを確認する。
	 */
	public function test_render_admin_columns_shows_base_price_when_no_tiers(): void {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_base_price', 4800 );

		$output = $this->render_price_column( $menu_id );

		$this->assertStringContainsString( '4,800', $output );
		$this->assertStringNotContainsString( 'vkbm-admin-price-tiers-list', $output );
		$this->assertStringContainsString( 'data-uses-price-tiers=""', $output );
	}

	/**
	 * 基本料金・区分ともに未設定のメニューは、従来どおり「—」を表示することを確認する（フォールバック）。
	 */
	public function test_render_admin_columns_shows_placeholder_when_no_price_set(): void {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$output = $this->render_price_column( $menu_id );

		$this->assertStringContainsString( '—', $output );
		$this->assertStringNotContainsString( 'vkbm-admin-price-tiers-list', $output );
	}

	/**
	 * 料金区分で設定されているメニューでも、判定条件（複数人一括予約ON・定員2以上）を
	 * 満たさなくなった場合は基本料金の単一表示にフォールバックすることを確認する
	 * （区分メタ自体は残っていても、Price_Tiers::is_menu_using_price_tiers() が false を返すケース）。
	 */
	public function test_render_admin_columns_falls_back_to_base_price_when_gate_conditions_not_met(): void {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_base_price', 2500 );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
				),
			)
		);
		// 複数人一括予約はOFFのまま（既定）＝条件2未達のため、区分メタが残っていても基本料金を表示する。
		update_post_meta( $menu_id, '_vkbm_max_capacity', 2 );

		$output = $this->render_price_column( $menu_id );

		$this->assertStringContainsString( '2,500', $output );
		$this->assertStringNotContainsString( 'vkbm-admin-price-tiers-list', $output );
		$this->assertStringContainsString( 'data-uses-price-tiers=""', $output );
	}
}
