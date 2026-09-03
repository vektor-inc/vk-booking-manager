<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;
use function get_post_type_object;
use function post_type_exists;
use function unregister_post_type;
use function update_option;

/**
 * リソース投稿タイプのメニューアイコンが基本設定の値を反映することを検証するテスト。
 *
 * @group post-types
 */
class Resource_Post_Type_Menu_Icon_Test extends WP_UnitTestCase {
	/**
	 * 各テスト前にリソース投稿タイプの登録と設定値をリセットする。
	 */
	protected function setUp(): void {
		parent::setUp();

		// 既に登録済みの場合は一旦解除し、設定値に応じた再登録を検証できるようにする。
		if ( post_type_exists( Resource_Post_Type::POST_TYPE ) ) {
			unregister_post_type( Resource_Post_Type::POST_TYPE );
		}

		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * 各テスト後に状態を元に戻し、後続テストへの影響を防ぐ。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );

		// このテストで登録した投稿タイプのみを既定状態へ戻す。
		// init フック全体を再実行すると無関係なコールバックまで走り、
		// 後続テストのグローバル状態を汚染するため、リソース投稿タイプだけを再登録する。
		if ( post_type_exists( Resource_Post_Type::POST_TYPE ) ) {
			unregister_post_type( Resource_Post_Type::POST_TYPE );
		}
		( new Resource_Post_Type() )->register_post_type();

		parent::tearDown();
	}

	/**
	 * 設定値が未保存の場合はデフォルトアイコンで登録されることを確認する。
	 */
	public function test_menu_icon_defaults_to_groups(): void {
		( new Resource_Post_Type() )->register_post_type();

		$post_type = get_post_type_object( Resource_Post_Type::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'dashicons-groups', $post_type->menu_icon );
	}

	/**
	 * 正しい Dashicons クラス名が保存されている場合はその値で登録されることを確認する。
	 */
	public function test_menu_icon_reflects_saved_value(): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array( 'resource_menu_icon' => 'dashicons-building' )
		);

		( new Resource_Post_Type() )->register_post_type();

		$post_type = get_post_type_object( Resource_Post_Type::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'dashicons-building', $post_type->menu_icon );
	}

	/**
	 * 不正な値が保存されている場合はデフォルトアイコンへフォールバックして登録されることを確認する。
	 */
	public function test_menu_icon_falls_back_on_invalid_value(): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array( 'resource_menu_icon' => 'javascript:alert(1)' )
		);

		( new Resource_Post_Type() )->register_post_type();

		$post_type = get_post_type_object( Resource_Post_Type::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'dashicons-groups', $post_type->menu_icon );
	}
}
