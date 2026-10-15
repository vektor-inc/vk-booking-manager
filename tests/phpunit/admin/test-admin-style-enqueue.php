<?php
/**
 * 管理画面用 CSS（vkbm-admin / vkbm-admin-notice）を読み込む画面の対応テスト.
 *
 * issue #659: Common_Styles::enqueue_admin() が vkbm-admin を全管理画面で無条件に
 * 読み込むのをやめたため、これまで全画面読み込みに頼っていた VKBM の画面は、
 * 各画面のクラスが自分で vkbm-admin を enqueue する。読み込み漏れが再発しないことを固定する.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\Admin\Setup_Notices;
use VKBookingManager\Admin\User_Profile_Fields;
use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Resources\Resource_Delete_Guard;
use WP_UnitTestCase;

/**
 * @group admin
 */
class Admin_Style_Enqueue_Test extends WP_UnitTestCase {

	/**
	 * 管理者でログインし、スタイルの登録状態をリセットする。
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wp_styles'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Common_Styles() )->register_styles();
	}

	/**
	 * 画面・スタイル・ユーザーの状態を元に戻す。
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_styles'], $GLOBALS['current_screen'] );
		parent::tearDown();
	}

	/**
	 * 全管理画面に出る通知は、通知専用の小さなバンドルだけを読み込み、vkbm-admin は読み込まない。
	 */
	public function test_setup_notices_enqueue_only_the_notice_bundle(): void {
		( new Setup_Notices() )->enqueue_assets( 'index.php' );

		$this->assertTrue( wp_style_is( Common_Styles::NOTICE_HANDLE, 'enqueued' ), 'vkbm-admin-notice が読み込まれていません。' );
		$this->assertFalse( wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ), '通知のために vkbm-admin を読み込んではいけません。' );
	}

	/**
	 * ユーザー一覧・プロフィール画面（メール確認バッジ）でだけ vkbm-admin を読み込む。
	 */
	public function test_user_profile_fields_enqueue_admin_style_only_on_user_screens(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ユーザー一覧では読み込む',
				'hook_suffix'         => 'users.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '自分のプロフィールでは読み込む',
				'hook_suffix'         => 'profile.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ユーザー編集では読み込む',
				'hook_suffix'         => 'user-edit.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '投稿一覧では読み込まない',
				'hook_suffix'         => 'edit.php',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_dequeue_style( Common_Styles::ADMIN_HANDLE );
			( new User_Profile_Fields() )->enqueue_assets( $case['hook_suffix'] );

			$this->assertSame(
				$case['expected'],
				wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * サービスメニューの編集画面では vkbm-admin を読み込み、他の投稿タイプの編集画面では読み込まない。
	 */
	public function test_service_menu_editor_enqueues_admin_style_only_on_service_menu_edit_screen(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'サービスメニューの編集画面では読み込む',
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'hook_suffix'         => 'post.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'サービスメニューの一覧では、このクラスは読み込まない（一覧は Service_Menu_Post_Type 側）',
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'hook_suffix'         => 'edit.php',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '通常の投稿の編集画面では読み込まない',
				'post_type'           => 'post',
				'hook_suffix'         => 'post.php',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_dequeue_style( Common_Styles::ADMIN_HANDLE );
			set_current_screen( $case['post_type'] );
			get_current_screen()->post_type = $case['post_type'];

			( new Service_Menu_Editor() )->enqueue_assets( $case['hook_suffix'] );

			$this->assertSame(
				$case['expected'],
				wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * 予約の編集画面・一覧（クイック編集）では vkbm-admin を読み込み、通常の投稿では読み込まない。
	 */
	public function test_booking_admin_enqueues_admin_style_only_on_booking_screens(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約の編集画面では読み込む',
				'method'              => 'enqueue_assets',
				'post_type'           => Booking_Post_Type::POST_TYPE,
				'hook_suffix'         => 'post.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約の一覧画面では読み込む',
				'method'              => 'enqueue_quick_edit_assets',
				'post_type'           => Booking_Post_Type::POST_TYPE,
				'hook_suffix'         => 'edit.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '通常の投稿の編集画面では読み込まない',
				'method'              => 'enqueue_assets',
				'post_type'           => 'post',
				'hook_suffix'         => 'post.php',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '通常の投稿の一覧画面では読み込まない',
				'method'              => 'enqueue_quick_edit_assets',
				'post_type'           => 'post',
				'hook_suffix'         => 'edit.php',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_dequeue_style( Common_Styles::ADMIN_HANDLE );
			set_current_screen( $case['post_type'] );
			get_current_screen()->post_type = $case['post_type'];

			$booking_admin = new Booking_Admin();
			$booking_admin->{$case['method']}( $case['hook_suffix'] );

			$this->assertSame(
				$case['expected'],
				wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * リソース（スタッフ）の一覧・編集画面では vkbm-admin を読み込み、他の投稿タイプでは読み込まない。
	 */
	public function test_resource_delete_guard_enqueues_admin_style_only_on_resource_screens(): void {
		// スタッフ削除ガードは Pro 版専用のファイルのため、無料版の配布物には無い。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'スタッフ削除ガードは Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => 'リソースの一覧画面では読み込む',
				'post_type'           => Resource_Post_Type::POST_TYPE,
				'hook_suffix'         => 'edit.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'リソースの編集画面では読み込む',
				'post_type'           => Resource_Post_Type::POST_TYPE,
				'hook_suffix'         => 'post.php',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '通常の投稿の一覧画面では読み込まない',
				'post_type'           => 'post',
				'hook_suffix'         => 'edit.php',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_dequeue_style( Common_Styles::ADMIN_HANDLE );
			set_current_screen( $case['post_type'] );
			get_current_screen()->post_type = $case['post_type'];

			( new Resource_Delete_Guard() )->enqueue_assets( $case['hook_suffix'] );

			$this->assertSame(
				$case['expected'],
				wp_style_is( Common_Styles::ADMIN_HANDLE, 'enqueued' ),
				$case['test_condition_name']
			);
		}
	}
}
