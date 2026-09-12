<?php
/**
 * メニューループ（カード）の料金区分・定員・最少催行人数表示と、
 * 指名機能の再ゲート（#412 C-1/C-2・#392）の回帰テスト。
 *
 * #412 A-1 以降、Service_Menu_Editor::save_post() は「このメニューで指名機能を使うか」に
 * 関わらず、予約枠の定員・複数人一括予約・料金区分・貸切設定のメタを削除せず保持する方針へ
 * 変わった。
 *
 * #392 で「指名を使うメニューを1枠1組（貸切）として扱い、定員は1組の最大人数として使う」
 * 仕様へ変更したことに伴い、表示側のゲートも変わった：
 * - 定員・料金区分は、指名を使うメニューでも意味のある値になったため、
 *   Staff_Editor::is_multi_guest_available_for_menu()（#392 で「指名OFF」条件を除去）のみで
 *   判定し、指名を使うメニューでも表示される
 * - ただし定員（Time slot capacity / Maximum group size）は、指名を使うメニューに限り
 *   複数人一括予約の許可フラグ（_vkbm_allow_multiple_guests）にも従属する（#392）。
 *   このメタがOFFだと resolve_guests() / get_max_guests() 経由で実際には1名しか申し込めないため、
 *   定員自体を表示しない。指名を使わないメニューは定員＝相乗り人数がこのメタと独立のため、
 *   この追加条件は課さない
 * - ラベルも指名の有無で出し分ける（#392）。同じ数値でも「相乗りできる人数」と
 *   「1組（貸切）の最大人数」は意味が違うため、指名を使うメニューは「Maximum group size」、
 *   使わないメニューは従来どおり「Time slot capacity」と表示する
 * - 最少催行人数（Minimum participants to confirm）は、複数の別々の予約が相乗りして
 *   「催行確定」に達するという概念が指名を使うメニュー（常に1枠1組）では成立しないため、
 *   引き続き「このメニューで指名機能を使っていないこと」を表示条件に含める
 *   （Menu_Loop_Block の $min_capacity_available）
 *
 * このテストは、指名を使うメニューで「定員・料金区分は表示される（ラベルは変わる）／
 * 複数人一括予約OFFなら定員自体が表示されない／最少催行人数は表示されない」という
 * 新仕様の出し分けを確認する。
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
 * カードレイアウトにおける指名機能の再ゲートを検証するテスト。
 *
 * @group blocks
 * @group nomination
 */
class Menu_Loop_Card_Nomination_Gating_Test extends WP_UnitTestCase {
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
	 * 定員・最少催行人数・料金区分・複数人一括予約許可を一式そろえたサービスメニューを作成するヘルパー。
	 *
	 * @param bool $disable_nomination_for_menu true のときこのメニューでは指名を使わない（_vkbm_disable_nomination）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu_with_multi_guest_settings( bool $disable_nomination_for_menu ): int {
		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス（指名再ゲート）',
			)
		);

		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 4 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 2 );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
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

		if ( $disable_nomination_for_menu ) {
			update_post_meta( $menu_id, '_vkbm_disable_nomination', true );
		} else {
			delete_post_meta( $menu_id, '_vkbm_disable_nomination' );
		}

		return (int) $menu_id;
	}

	/**
	 * #392: 指名機能を使うメニュー（_vkbm_disable_nomination 未設定＝指名を使う）では、
	 * 定員（1組の最大人数として意味を持つ）・料金区分は一覧カードに表示されるが、
	 * 最少催行人数（複数の別々の予約が相乗りする前提の概念）だけは表示されないことを確認する。
	 *
	 * #392: 定員のラベルは、指名を使うメニューでは「予約枠の定員（Time slot capacity）」ではなく
	 * 「1組の最大人数（Maximum group size）」に出し分けられる（管理画面の言い回しと揃える）。
	 *
	 * Pro 版限定機能（Staff_Editor::is_multi_guest_available_for_menu()）のため、
	 * 無料版ビルドでは定員・料金区分自体が常に表示されない（このテストは無料版ではスキップする）。
	 */
	public function test_card_shows_capacity_and_price_tiers_but_hides_min_capacity_when_menu_uses_nomination(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '定員・料金区分の表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu_with_multi_guest_settings( false );

		$output = $this->block->render_menu_card( $menu_id );

		// カード自体は描画されている（空文字による否定アサーションの空振りを防ぐ）。
		$this->assertStringContainsString( 'テストサービス（指名再ゲート）', $output );

		// #392: 定員（1組の最大人数）は指名を使うメニューでも表示されるが、
		// ラベルは非指名メニュー用の「Time slot capacity」ではなく「Maximum group size」になる。
		$this->assertStringContainsString( esc_html__( 'Maximum group size', 'vk-booking-manager' ), $output );
		$this->assertStringNotContainsString( esc_html__( 'Time slot capacity', 'vk-booking-manager' ), $output );

		// 最少催行人数は、指名を使うメニュー（常に1枠1組）では概念自体が成立しないため表示されない。
		$this->assertStringNotContainsString( esc_html__( 'Minimum participants to confirm', 'vk-booking-manager' ), $output );

		// #392: 料金区分も指名を使うメニューで表示される（基本料金の単一表示にはフォールバックしない）。
		$this->assertStringContainsString( 'vkbm-menu-loop__card-prices', $output );
		$this->assertStringContainsString( '大人', $output );
		$this->assertStringContainsString( '子供', $output );
	}

	/**
	 * #392: 指名を使うメニューでも、複数人一括予約の許可フラグ
	 * （_vkbm_allow_multiple_guests）がOFFなら実際には1名しか申し込めない
	 * （resolve_guests() / get_max_guests() がこのメタに従属するため）。
	 * この場合はカードに定員自体を表示してはならない（表示すると「3名まで申し込める」と
	 * 誤解させてしまうため）。指名を使わないメニューでは定員＝相乗り人数がこのメタと独立のため、
	 * この制約は掛からない（対照群は test_card_shows_capacity_and_price_tiers_when_menu_does_not_use_nomination
	 * で確認済み）。
	 */
	public function test_card_hides_capacity_when_nomination_menu_disallows_multiple_guests(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '定員の表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'テストサービス（指名・複数人一括予約OFF）',
			)
		);
		update_post_meta( $menu_id, '_vkbm_base_price', 5000 );
		// 複数人一括予約は明示的にOFF（＝実際に申し込める人数は1名）。
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', false );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );
		// 指名を使う（既定＝使う。_vkbm_disable_nomination は保存しない）。
		delete_post_meta( $menu_id, '_vkbm_disable_nomination' );

		$output = $this->block->render_menu_card( (int) $menu_id );

		$this->assertStringContainsString( 'テストサービス（指名・複数人一括予約OFF）', $output );

		// #392: 定員（3名）は実際には申し込めないため、どちらのラベルでも表示しない。
		$this->assertStringNotContainsString( esc_html__( 'Maximum group size', 'vk-booking-manager' ), $output );
		$this->assertStringNotContainsString( esc_html__( 'Time slot capacity', 'vk-booking-manager' ), $output );
	}

	/**
	 * 対照群：同じメタ構成で、このメニューで指名機能を使わない
	 * （_vkbm_disable_nomination = true）場合は、従来どおり料金区分表・定員・
	 * 最少催行人数のすべてが表示されることを確認する（過剰にゲートしていないことの確認）。
	 */
	public function test_card_shows_capacity_and_price_tiers_when_menu_does_not_use_nomination(): void {
		// #412 F-2: 定員・料金区分の表示は Pro 版限定機能（Staff_Editor::is_multi_guest_available_for_menu()）
		// のため、無料版ビルドでは指名を使わないメニューでも常に表示されない。この「表示される」対照群は
		// 無料版では成立しないためスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '定員・料金区分の表示は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$menu_id = $this->create_menu_with_multi_guest_settings( true );

		$output = $this->block->render_menu_card( $menu_id );

		$this->assertStringContainsString( 'テストサービス（指名再ゲート）', $output );

		$this->assertStringContainsString( esc_html__( 'Time slot capacity', 'vk-booking-manager' ), $output );
		$this->assertStringContainsString( esc_html__( 'Minimum participants to confirm', 'vk-booking-manager' ), $output );

		$this->assertStringContainsString( 'vkbm-menu-loop__card-prices', $output );
		$this->assertStringContainsString( '大人', $output );
		$this->assertStringContainsString( '子供', $output );
	}
}
