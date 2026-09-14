<?php
/**
 * Menu_Loop_Block::render_menu_selection_list() のスタッフ絞り込みテスト（#429）。
 *
 * issue #429「スタッフを選択しただけでも絞り込まれるようにしたい」への対応で、
 * 絞り込み検索でスタッフが選択されている（$staff_id > 0）とき、サービスメニュー一覧を
 * 「そのスタッフで予約できるメニュー」だけに絞り込む Menu_Loop_Block::render_menu_selection_list()
 * の判定基準（Menu_Loop_Block::is_menu_visible_for_staff_filter()）を検証する。
 *
 * 判定基準（issue #429 decision-record）:
 * - メニューの対応スタッフ（_vkbm_staff_ids）にそのスタッフが含まれる => 表示する
 * - 対応スタッフが未登録（空）のメニュー => 表示する
 *   （Availability_Service::resolve_staff_ids() が対応スタッフ未設定のメニューを
 *   指名スタッフでそのまま受け付ける仕様と一致させるため）
 * - メニュー単位で指名無効（_vkbm_disable_nomination）のメニュー => スタッフ選択中は非表示にする
 * - スタッフ機能自体が無効（無料版、または基本設定でOFF）のとき => staff パラメータを無視し、
 *   絞り込みを行わない（安全側のフォールバック）
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Frontend;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * render_menu_selection_list() のスタッフ絞り込みを検証するテストクラス。
 *
 * @group blocks
 * @group staff
 */
class Menu_Loop_Selection_List_Staff_Filter_Test extends WP_UnitTestCase {

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
	 * サイト全体の指名機能スイッチ（staff_enabled）を更新する。
	 *
	 * @param bool $enabled 指名機能を有効にする場合は true。
	 */
	private function set_site_wide_nomination( bool $enabled ): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * テスト用のサービスメニューを作成するヘルパー。
	 *
	 * @param string     $title               メニュータイトル（一覧HTML内での検出に使う）。
	 * @param array<int> $staff_ids           対応スタッフID配列（空配列は「対応スタッフ未登録」）。
	 * @param bool|null  $disable_nomination  true でこのメニューの指名を無効化。null はメタ未設定（既定＝指名を使う）。
	 * @return int 作成したサービスメニューの投稿ID。
	 */
	private function create_menu( string $title, array $staff_ids = array(), ?bool $disable_nomination = null ): int {
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

		if ( null !== $disable_nomination ) {
			update_post_meta( $menu_id, '_vkbm_disable_nomination', $disable_nomination );
		}

		return $menu_id;
	}

	/**
	 * render_menu_selection_list( $staff_id ) が、スタッフ絞り込みの各条件を
	 * 正しく組み合わせて一覧の表示・非表示を判定することを検証する。
	 *
	 * Free版では Staff_Editor::is_nomination_enabled() が常に false のため、
	 * サイト全体の設定に関わらずスタッフ絞り込みは常に無効（全メニュー表示）になる。
	 * そのため期待値は Pro/Free 版で分岐させる。
	 */
	public function test_render_menu_selection_list_filters_by_staff(): void {
		$is_free = Pro_Upsell::is_free_edition();

		// 対応スタッフID（テスト用に固定の投稿IDを使う。リソース投稿の実在は判定条件に含まれないため作成不要）。
		$target_staff_id = 9001;
		$other_staff_id  = 9002;

		// 対応スタッフに指定スタッフを含むメニュー（指名は使う）。
		$menu_assigned = $this->create_menu( 'メニューA（対応スタッフに含む）', array( $target_staff_id ) );
		// 対応スタッフが別スタッフのみのメニュー（指名は使う）。
		$menu_other = $this->create_menu( 'メニューB（対応スタッフが別）', array( $other_staff_id ) );
		// 対応スタッフ未登録のメニュー（指名は使う）。
		$menu_unset = $this->create_menu( 'メニューC（対応スタッフ未登録）', array() );
		// 対応スタッフに指定スタッフを含むが、メニュー単位で指名を無効化しているメニュー。
		$menu_disabled = $this->create_menu( 'メニューD（指名無効）', array( $target_staff_id ), true );
		// #429 安藤レビュー指摘（LOW）: 「指名無効」かつ「対応スタッフ未登録」の組み合わせ。
		// 対応スタッフが空でも、指名無効チェックが先に効いてスタッフ選択中は非表示になることを確認する
		// （is_menu_visible_for_staff_filter() 内で disable_nomination の判定が
		// 「対応スタッフ空なら表示」より先に評価される順序に依存するケース）。
		$menu_disabled_unassigned = $this->create_menu( 'メニューF（指名無効・対応スタッフ未登録）', array(), true );

		$test_cases = array(
			array(
				'test_condition_name' => 'サイト全体ONかつ staff_id=0（指名なし）の場合 => 絞り込みを行わず全メニュー表示',
				'site_wide'           => true,
				'staff_id'            => 0,
				'expected_visible'    => array( $menu_assigned, $menu_other, $menu_unset, $menu_disabled, $menu_disabled_unassigned ),
				'expected_hidden'     => array(),
			),
			array(
				'test_condition_name' => 'サイト全体ONかつ staff_id=対象スタッフの場合 => 対応スタッフに含む・未登録のメニューのみ表示（Free版は絞り込み自体が無効なため全件表示）',
				'site_wide'           => true,
				'staff_id'            => $target_staff_id,
				'expected_visible'    => $is_free
					? array( $menu_assigned, $menu_other, $menu_unset, $menu_disabled, $menu_disabled_unassigned )
					: array( $menu_assigned, $menu_unset ),
				'expected_hidden'     => $is_free
					? array()
					: array( $menu_other, $menu_disabled, $menu_disabled_unassigned ),
			),
			array(
				'test_condition_name' => 'サイト全体OFF（スタッフ機能オフ）の場合 => staff_id が指定されていても絞り込みを無視して全メニュー表示（安全側フォールバック）',
				'site_wide'           => false,
				'staff_id'            => $target_staff_id,
				'expected_visible'    => array( $menu_assigned, $menu_other, $menu_unset, $menu_disabled, $menu_disabled_unassigned ),
				'expected_hidden'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site_wide_nomination( $case['site_wide'] );

			$output = $this->block->render_menu_selection_list( $case['staff_id'] );

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
	 * 絞り込んだ結果0件になる場合、render_menu_selection_list() が空文字を返すことを確認する（境界値）。
	 *
	 * app.js 側はこの空文字を「表示するメニューが無い」の判定に使うため、
	 * 絞り込み後に該当メニューが1件も無くなったケースでも同じ扱いになることを固定する。
	 */
	public function test_render_menu_selection_list_returns_empty_string_when_no_menu_matches_staff_filter(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'Free版ではスタッフ絞り込み自体が働かないため、0件になるケースは成立しない。' );
		}

		$this->set_site_wide_nomination( true );

		$target_staff_id = 9101;
		$other_staff_id  = 9102;

		// 対象スタッフが対応スタッフに含まれないメニューのみを用意する。
		$this->create_menu( 'メニューE（対応スタッフが別）', array( $other_staff_id ) );

		$output = $this->block->render_menu_selection_list( $target_staff_id );

		$this->assertSame( '', $output );
	}
}
