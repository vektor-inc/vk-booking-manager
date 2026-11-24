<?php
/**
 * Setup_Notices::render_unassigned_menu_notice() のテスト（#1144）。
 *
 * 担当できるリソースがいないサービスメニューがあるとき、管理画面のお知らせに
 * 該当メニュー名を編集画面へのリンク付きで並べること、呼び方（リソース名・メニュー名）が
 * 表記ラベルの設定に従うこと、権限・無料版では出さないことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function delete_option;
use function get_edit_post_link;
use function get_option;
use function ob_get_clean;
use function ob_start;
use function update_option;
use function update_post_meta;
use function wp_set_current_user;

/**
 * 担当できるリソースがいないメニューのお知らせを検証するテストクラス。
 *
 * @group admin
 * @group staff
 */
class Setup_Notices_Unassigned_Menu_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * 全体設定を退避し、キャッシュを初期化する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
	}

	/**
	 * 全体設定・キャッシュ・ログイン状態を戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Service_Menu_Post_Type::clear_published_resource_ids_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * テスト用のサービスメニューを、担当の設定付きで作成する。
	 *
	 * @param string     $title     メニュー名。
	 * @param array<int> $staff_ids 個別に選んだリソースID（「選ぶ」のメニューとして作る）。
	 * @param string     $status    投稿ステータス。
	 * @param bool       $archived  アーカイブ済みなら true。
	 * @return int 投稿ID。
	 */
	private function create_menu( string $title, array $staff_ids, string $status = 'publish', bool $archived = false ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
		update_post_meta( $menu_id, '_vkbm_staff_ids', $staff_ids );
		if ( $archived ) {
			update_post_meta( $menu_id, '_vkbm_is_archived', '1' );
		}

		return $menu_id;
	}

	/**
	 * お知らせの出力を取得する。
	 *
	 * @return string 出力された HTML。
	 */
	private function render(): string {
		ob_start();
		( new Setup_Notices() )->render_unassigned_menu_notice();
		return (string) ob_get_clean();
	}

	/**
	 * Setup_Notices::render_unassigned_menu_notice() のテスト。
	 */
	public function test_render_unassigned_menu_notice(): void {
		$is_pro   = Staff_Editor::is_enabled();
		$resource = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$draft    = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		$menu_ok       = $this->create_menu( '担当ありメニュー', array( $resource ) );
		$menu_empty    = $this->create_menu( 'チェックなしメニュー', array() );
		$menu_retired  = $this->create_menu( '担当が下書きだけのメニュー', array( $draft ) );
		$menu_draft    = $this->create_menu( '下書きのチェックなしメニュー', array(), 'draft' );
		$menu_archived = $this->create_menu( 'アーカイブ済みのチェックなしメニュー', array(), 'publish', true );

		$test_cases = array(
			array(
				'test_condition_name' => '管理者・既定の呼び方 => Pro は該当メニューを編集画面へのリンク付きで並べる（正常系）',
				'user_role'           => 'administrator',
				'settings'            => array(),
				'expected_shown'      => $is_pro,
				/* translators: %s: resource label (plural), e.g. "Staff". */
				'expected_texts'      => array( sprintf( __( 'There are service menus with no %s set as in charge.', 'vk-booking-manager' ), __( 'Staff', 'vk-booking-manager' ) ) ),
			),
			array(
				'test_condition_name' => '管理者・呼び方を変えている => Pro は見出しに設定した呼び方を使う（正常系）',
				'user_role'           => 'administrator',
				'settings'            => array(
					'menu_label'              => 'プラン',
					'resource_label_singular' => 'ルーム',
					'resource_label_plural'   => 'ルーム',
				),
				'expected_shown'      => $is_pro,
				/* translators: 1: menu term set in the settings, plural (e.g. Plans). 2: resource label (plural), e.g. "Staff". */
				'expected_texts'      => array( sprintf( __( 'There are %1$s with no %2$s set as in charge.', 'vk-booking-manager' ), 'プラン', 'ルーム' ) ),
			),
			array(
				'test_condition_name' => 'メニューを編集できない利用者（購読者） => 出さない（異常系）',
				'user_role'           => 'subscriber',
				'settings'            => array(),
				'expected_shown'      => false,
				'expected_texts'      => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( Settings_Repository::OPTION_KEY, $case['settings'] );
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['user_role'] ) ) );

			$output = $this->render();

			if ( ! $case['expected_shown'] ) {
				$this->assertSame( '', trim( $output ), $case['test_condition_name'] );
				continue;
			}

			foreach ( $case['expected_texts'] as $text ) {
				$this->assertStringContainsString( esc_html( $text ), $output, $case['test_condition_name'] . '（見出し）' );
			}
			foreach ( array( $menu_empty, $menu_retired ) as $menu_id ) {
				$this->assertStringContainsString( esc_url( (string) get_edit_post_link( $menu_id, 'raw' ) ), $output, $case['test_condition_name'] . '（編集画面へのリンク）' );
				$this->assertStringContainsString( get_the_title( $menu_id ), $output, $case['test_condition_name'] . '（メニュー名）' );
			}
			foreach ( array( $menu_ok, $menu_draft, $menu_archived ) as $menu_id ) {
				$this->assertStringNotContainsString( get_the_title( $menu_id ), $output, $case['test_condition_name'] . '（担当あり・下書き・アーカイブ済みは並べない）' );
			}
		}
	}

	/**
	 * 担当できるリソースがいないメニューが無いときはお知らせを出さないことのテスト（境界値）。
	 */
	public function test_render_unassigned_menu_notice_without_target(): void {
		$resource = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->create_menu( '担当ありメニュー', array( $resource ) );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array(), Setup_Notices::get_unassigned_listed_menu_ids(), '担当のいないメニューが無い => 対象なし' );
		$this->assertSame( '', trim( $this->render() ), '担当のいないメニューが無い => 出さない' );
	}
}
