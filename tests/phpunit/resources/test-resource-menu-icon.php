<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Resources;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use WP_UnitTestCase;

/**
 * リソースメニューアイコンの検証・取得・保存に関するテスト。
 *
 * @group resources
 */
class Resource_Menu_Icon_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();

		delete_option( Settings_Repository::OPTION_KEY );
	}

	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * デフォルト設定にリソースメニューアイコンの初期値が含まれることを確認する。
	 */
	public function test_default_settings_contain_resource_menu_icon(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();

		$this->assertSame( 'dashicons-groups', $defaults['resource_menu_icon'] );
	}

	/**
	 * vkbm_sanitize_resource_menu_icon() が様々な入力を正しく正規化することを確認する。
	 *
	 * @dataProvider provide_sanitize_cases
	 *
	 * @param mixed  $input    入力値。
	 * @param string $expected 期待値。
	 * @param string $message  アサーション失敗時のメッセージ。
	 */
	public function test_sanitize_resource_menu_icon( $input, string $expected, string $message ): void {
		$this->assertSame( $expected, vkbm_sanitize_resource_menu_icon( $input ), $message );
	}

	/**
	 * vkbm_sanitize_resource_menu_icon() のテストケースを提供する。
	 *
	 * @return array<int, array{0:mixed,1:string,2:string}>
	 */
	public function provide_sanitize_cases(): array {
		return array(
			array( 'dashicons-building', 'dashicons-building', '正しい Dashicons クラス名はそのまま返す' ),
			array( '  dashicons-store  ', 'dashicons-store', '前後の空白を除去して返す' ),
			array( '', 'dashicons-groups', '空文字はデフォルトへフォールバックする' ),
			array( 'building', 'dashicons-groups', 'プレフィックスがない値はデフォルトへフォールバックする' ),
			array( 'dashicons-<script>', 'dashicons-groups', '不正な文字を含む値はデフォルトへフォールバックする' ),
			array( 'DASHICONS-BUILDING', 'dashicons-groups', '大文字を含む値はデフォルトへフォールバックする' ),
			array( 'dashicons-foo bar', 'dashicons-groups', '空白を含む値はデフォルトへフォールバックする' ),
			array( null, 'dashicons-groups', 'null はデフォルトへフォールバックする' ),
			array( 123, 'dashicons-groups', '文字列以外はデフォルトへフォールバックする' ),
		);
	}

	/**
	 * vkbm_get_resource_menu_icon() が保存値・未設定・不正値を正しく扱うことを確認する。
	 */
	public function test_get_resource_menu_icon(): void {
		// 未設定の場合はデフォルトを返す。
		$this->assertSame( 'dashicons-groups', vkbm_get_resource_menu_icon() );

		// 正しい保存値はそのまま返す。
		update_option(
			Settings_Repository::OPTION_KEY,
			array( 'resource_menu_icon' => 'dashicons-location' )
		);
		$this->assertSame( 'dashicons-location', vkbm_get_resource_menu_icon() );

		// 不正な保存値はデフォルトへフォールバックする。
		update_option(
			Settings_Repository::OPTION_KEY,
			array( 'resource_menu_icon' => 'evil-value' )
		);
		$this->assertSame( 'dashicons-groups', vkbm_get_resource_menu_icon() );
	}

	/**
	 * Settings_Sanitizer がリソースメニューアイコンを検証して保存値に含めることを確認する。
	 */
	public function test_sanitizer_normalizes_resource_menu_icon(): void {
		$sanitizer = new Settings_Sanitizer();
		$defaults  = ( new Settings_Repository() )->get_default_settings();

		// 正しい値は保持される。
		$result = $sanitizer->sanitize( array( 'resource_menu_icon' => 'dashicons-car' ), $defaults );
		$this->assertSame( 'dashicons-car', $result['resource_menu_icon'] );

		// 不正な値はデフォルトへ強制される。
		$result = $sanitizer->sanitize( array( 'resource_menu_icon' => '../../etc/passwd' ), $defaults );
		$this->assertSame( 'dashicons-groups', $result['resource_menu_icon'] );
	}
}
