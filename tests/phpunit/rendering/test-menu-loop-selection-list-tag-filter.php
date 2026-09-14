<?php
/**
 * Menu_Loop_Block::render_menu_selection_list() のリソースタグ絞り込みテスト（issue #431）。
 *
 * 絞り込み検索でリソースタグが選択されているとき、サービスメニュー一覧を
 * 「そのタグを持つリソースが対応可能なメニュー」だけに絞り込む
 * Menu_Loop_Block::is_menu_visible_for_tag_filter() の判定基準を検証する。
 *
 * 判定基準（issue #431 完了条件・実装メモ）:
 * - 複数タグ選択時は AND 条件（選択したタグを「すべて」持つリソースだけが対象）
 * - 該当するリソースが1件も無い場合 => 対応リソース未登録のメニューを含め、
 *   どのメニューも表示しない（この条件が最優先。以下の「対応リソース未登録は表示する」
 *   扱いより先に評価される）
 * - 該当するリソースが1件以上あるとき、メニューの対応リソース（_vkbm_staff_ids）が
 *   未登録（空）のメニュー => 表示する（スタッフ絞り込み #429 と判定基準を揃える）
 * - 該当するリソースが1件以上あるとき、タグを持つリソースが対応リソースに1人でも
 *   含まれるメニュー => 表示する
 * - このタグ絞り込みは指名機能（staff_enabled）の ON/OFF に関係なく機能する
 *   （is_menu_visible_for_staff_filter() と異なり、Staff_Editor の判定を経由しない）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * render_menu_selection_list() のリソースタグ絞り込みを検証するテストクラス。
 *
 * @group blocks
 * @group resource-tag
 */
class Menu_Loop_Selection_List_Tag_Filter_Test extends WP_UnitTestCase {

	/**
	 * テスト対象のブロックインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private $block;

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 各テスト前にブロックインスタンスを用意し、全体設定を退避する。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block             = new Menu_Loop_Block();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * 全体設定・静的キャッシュをテスト前の状態へ戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tear_down();
	}

	/**
	 * テスト用のサービスメニューを作成するヘルパー。
	 *
	 * @param string     $title     メニュータイトル。
	 * @param array<int> $staff_ids 対応リソースID配列（空配列は「対応リソース未登録」）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu( string $title, array $staff_ids = array() ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( ! empty( $staff_ids ) ) {
			update_post_meta( $menu_id, '_vkbm_staff_ids', $staff_ids );
		}

		return $menu_id;
	}

	/**
	 * テスト用のリソース投稿を作成するヘルパー。
	 *
	 * @param string     $title   投稿タイトル。
	 * @param array<int> $tag_ids 割り当てるリソースタグのターム ID 配列。
	 * @return int 作成したリソース投稿ID。
	 */
	private function create_resource( string $title, array $tag_ids = array() ): int {
		$resource_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( ! empty( $tag_ids ) ) {
			wp_set_object_terms( $resource_id, $tag_ids, Resource_Tag_Taxonomy::TAXONOMY );
		}

		return $resource_id;
	}

	/**
	 * テスト用のリソースタグタームを作成するヘルパー。
	 *
	 * @param string $name タグ名。
	 * @return int タームID。
	 */
	private function create_tag( string $name ): int {
		$result = wp_insert_term( $name, Resource_Tag_Taxonomy::TAXONOMY );
		$this->assertIsArray( $result, 'wp_insert_term() はタームを作成できるべき: ' . $name );
		return (int) $result['term_id'];
	}

	/**
	 * render_menu_selection_list( 0, $tag_ids ) が、タグ絞り込みの各条件を
	 * 正しく組み合わせて一覧の表示・非表示を判定することを検証する。
	 *
	 * 指名機能（staff_enabled）を明示的に OFF にした状態でも同じ結果になることを確認し、
	 * タグ絞り込みが指名機能の状態に依存しないことも合わせて検証する。
	 */
	public function test_render_menu_selection_list_filters_by_resource_tags(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		// #431: 指名機能をサイト全体でOFFにしても、タグ絞り込みは機能し続けることを確認する。
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = false;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$tag_female  = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$tag_veteran = $this->create_tag( 'ベテラン_' . wp_generate_password( 6, false ) );

		$resource_both   = $this->create_resource( 'リソースA', array( $tag_female, $tag_veteran ) );
		$resource_female = $this->create_resource( 'リソースB', array( $tag_female ) );
		$resource_none   = $this->create_resource( 'リソースC' );

		$menu_matches_both   = $this->create_menu( 'メニューA（両方持つリソース対応）', array( $resource_both ) );
		$menu_matches_female = $this->create_menu( 'メニューB（女性のみ持つリソース対応）', array( $resource_female ) );
		$menu_no_match       = $this->create_menu( 'メニューC（タグ無しリソースのみ対応）', array( $resource_none ) );
		$menu_unassigned     = $this->create_menu( 'メニューD（対応リソース未登録）', array() );

		$test_cases = array(
			array(
				'test_condition_name' => 'タグ未選択（空配列） => 絞り込みを行わず全メニュー表示',
				'tag_ids'             => array(),
				'expected_visible'    => array( $menu_matches_both, $menu_matches_female, $menu_no_match, $menu_unassigned ),
				'expected_hidden'     => array(),
			),
			array(
				'test_condition_name' => '「女性」タグ選択 => 「女性」を持つリソースが対応するメニューと対応リソース未登録のメニューを表示',
				'tag_ids'             => array( $tag_female ),
				'expected_visible'    => array( $menu_matches_both, $menu_matches_female, $menu_unassigned ),
				'expected_hidden'     => array( $menu_no_match ),
			),
			array(
				'test_condition_name' => '「女性」「ベテラン」両方選択（AND） => 両方持つリソースが対応するメニューのみ表示',
				'tag_ids'             => array( $tag_female, $tag_veteran ),
				'expected_visible'    => array( $menu_matches_both, $menu_unassigned ),
				'expected_hidden'     => array( $menu_matches_female, $menu_no_match ),
			),
		);

		foreach ( $test_cases as $case ) {
			$output = $this->block->render_menu_selection_list( 0, $case['tag_ids'] );

			foreach ( $case['expected_visible'] as $menu_id ) {
				$this->assertStringContainsString(
					get_the_title( $menu_id ),
					$output,
					$case['test_condition_name'] . '（表示されるべきメニュー: ' . get_the_title( $menu_id ) . '）'
				);
			}

			foreach ( $case['expected_hidden'] as $menu_id ) {
				$this->assertStringNotContainsString(
					get_the_title( $menu_id ),
					$output,
					$case['test_condition_name'] . '（非表示になるべきメニュー: ' . get_the_title( $menu_id ) . '）'
				);
			}
		}
	}

	/**
	 * 選択したタグを「すべて」持つリソースが1件も無い場合、render_menu_selection_list() が
	 * 空文字を返す（＝どのメニューも表示しない）ことを確認する（境界値）。
	 */
	public function test_render_menu_selection_list_returns_empty_string_when_no_resource_matches_all_tags(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$tag_female  = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$tag_veteran = $this->create_tag( 'ベテラン_' . wp_generate_password( 6, false ) );

		// 「女性」のみ持つリソース（「ベテラン」は持たない）。
		$resource_id = $this->create_resource( 'リソースE', array( $tag_female ) );
		$this->create_menu( 'メニューE', array( $resource_id ) );

		$output = $this->block->render_menu_selection_list( 0, array( $tag_female, $tag_veteran ) );

		$this->assertSame( '', $output );
	}

	/**
	 * 該当リソースが0件のとき、対応リソース未登録（_vkbm_staff_ids が空）のメニューも
	 * 例外にせず非表示にすることを確認する（issue #431 完了条件「該当するリソースが0件に
	 * なった場合はメニュー一覧を空にする」）。
	 *
	 * #429（スタッフ絞り込み）では対応リソース未登録のメニューは常に表示するが、
	 * タグ絞り込みで該当0件のときは、この「対応リソース未登録は表示する」扱いを
	 * 適用しない（is_menu_visible_for_tag_filter() の判定順序による）。
	 */
	public function test_render_menu_selection_list_hides_unassigned_menu_when_no_resource_matches_all_tags(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースタグ機能は Pro 版限定のため、無料版ではスキップする。' );
		}

		$tag_female  = $this->create_tag( '女性_' . wp_generate_password( 6, false ) );
		$tag_veteran = $this->create_tag( 'ベテラン_' . wp_generate_password( 6, false ) );

		// 「女性」のみ持つリソース（「ベテラン」は持たないため、AND条件で該当0件になる）。
		$resource_id = $this->create_resource( 'リソースF', array( $tag_female ) );
		$this->create_menu( 'メニューF（対応リソースあり・タグ非該当）', array( $resource_id ) );
		// 対応リソースを一切登録していないメニュー。
		$menu_unassigned = $this->create_menu( 'メニューG（対応リソース未登録）', array() );

		$output = $this->block->render_menu_selection_list( 0, array( $tag_female, $tag_veteran ) );

		$this->assertSame(
			'',
			$output,
			'該当リソース0件のときは対応リソース未登録のメニューも含めて全て非表示になるべき'
		);
		$this->assertStringNotContainsString( get_the_title( $menu_unassigned ), $output );
	}
}
