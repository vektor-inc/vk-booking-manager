<?php
/**
 * サービスメニュー編集画面の「担当できるリソース」欄（#1144）のテスト。
 *
 * - 新規メニュー（自動下書き）では「すべてのリソースが担当できる」が初期値になること
 * - 「選ぶ」かつチェック0件で保存されたら、担当の設定を保存せずに案内を残すこと
 * を検証する。メタボックス自体が Pro 版にしか無いため、Pro 版限定のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function delete_transient;
use function get_post;
use function get_post_meta;
use function get_transient;
use function ob_get_clean;
use function ob_start;
use function update_post_meta;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * 「担当できるリソース」欄の初期値と保存時の確認を検証するテストクラス。
 *
 * @group admin
 * @group staff
 */
class Service_Menu_Editor_Staff_Selection_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/**
	 * 管理者としてログインする。Pro 版以外はスキップする。
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! Staff_Editor::is_enabled() ) {
			$this->markTestSkipped( '無料版には「担当できるリソース」欄が無いため、Pro版限定のテスト。' );
		}
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * お知らせ・$_POST・ログイン状態を戻す。
	 */
	protected function tearDown(): void {
		delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * テスト用のサービスメニューを作成する。
	 *
	 * @param string $status 投稿ステータス。
	 * @return int 投稿ID。
	 */
	private function create_menu( string $status = 'publish' ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Service_Menu_Editor::render_staff_meta_box() の「すべて」ラジオの初期状態のテスト。
	 */
	public function test_render_staff_meta_box(): void {
		$resource = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '新規メニュー（自動下書き） => 「すべて」が選ばれている（#1144 正常系）',
				'status'              => 'auto-draft',
				'staff_all'           => '',
				'staff_ids'           => array(),
				'expected_all'        => true,
			),
			array(
				'test_condition_name' => '保存済みで「すべて」 => 「すべて」が選ばれている（正常系）',
				'status'              => 'publish',
				'staff_all'           => '1',
				'staff_ids'           => array(),
				'expected_all'        => true,
			),
			array(
				'test_condition_name' => '保存済みで「選ぶ」かつチェック0件 => 自動では「すべて」にせず「選ぶ」のまま（#1144 境界値）',
				'status'              => 'publish',
				'staff_all'           => '',
				'staff_ids'           => array(),
				'expected_all'        => false,
			),
			array(
				'test_condition_name' => '保存済みで「選ぶ」かつ1件チェック => 「選ぶ」が選ばれている（正常系）',
				'status'              => 'draft',
				'staff_all'           => '',
				'staff_ids'           => array( $resource ),
				'expected_all'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu( $case['status'] );
			update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['staff_all'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', $case['staff_ids'] );

			ob_start();
			( new Service_Menu_Editor() )->render_staff_meta_box( get_post( $menu_id ) );
			$html = (string) ob_get_clean();

			// 「すべて」ラジオの開始タグに checked 属性が付いているかを見る。
			$is_all_checked = (bool) preg_match( '/id="vkbm-service-menu-staff-all"[^>]*checked/s', $html );
			$this->assertSame( $case['expected_all'], $is_all_checked, $case['test_condition_name'] );
		}
	}

	/**
	 * Service_Menu_Editor::save_post() で、「選ぶ」かつチェック0件の担当の設定を保存しないことのテスト。
	 */
	public function test_save_post(): void {
		$staff_a = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$staff_b = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '「選ぶ」でスタッフBに変更 => 保存され、案内は残さない（正常系）',
				'fields'              => array(
					'staff_all' => '0',
					'staff_ids' => array( (string) $staff_b ),
				),
				'expected_all'        => '',
				'expected_ids'        => array( $staff_b ),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '「すべて」を選んでチェック0件 => 保存され、案内は残さない（正常系）',
				'fields'              => array(
					'staff_all' => '1',
				),
				'expected_all'        => '1',
				'expected_ids'        => array(),
				'expected_notice'     => false,
			),
			array(
				'test_condition_name' => '「選ぶ」でチェック0件 => 担当の設定は保存前のまま、案内を残す（異常系）',
				'fields'              => array(
					'staff_all' => '0',
				),
				'expected_all'        => '',
				'expected_ids'        => array( $staff_a ),
				'expected_notice'     => true,
			),
			array(
				'test_condition_name' => 'ラジオを含まない送信 => 担当の設定は保存前のまま、案内は残さない（異常系）',
				'initial_all'         => '1',
				'fields'              => array(
					'staff_ids' => array( (string) $staff_b ),
				),
				'expected_all'        => '1',
				'expected_ids'        => array( $staff_a ),
				'expected_notice'     => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 前のケースの送信内容が、メニュー作成時の保存処理に使われないよう空にしておく。
			$_POST   = array();
			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a ) );
			if ( isset( $case['initial_all'] ) ) {
				update_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, $case['initial_all'] );
			}
			delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );

			$_POST = array(
				self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
				'vkbm_service_menu' => $case['fields'],
			);
			( new Service_Menu_Editor() )->save_post( $menu_id, get_post( $menu_id ) );

			$this->assertSame( $case['expected_all'], (string) get_post_meta( $menu_id, Service_Menu_Post_Type::META_STAFF_ALL, true ), $case['test_condition_name'] . '（すべて）' );
			$this->assertSame( $case['expected_ids'], Service_Menu_Post_Type::get_selected_staff_ids( $menu_id ), $case['test_condition_name'] . '（個別選択）' );

			$notice = get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
			$this->assertSame(
				$case['expected_notice'],
				is_string( $notice ) && false !== strpos( $notice, Service_Menu_Post_Type::get_staff_unselected_notice() ),
				$case['test_condition_name'] . '（案内）'
			);
		}
	}
}
