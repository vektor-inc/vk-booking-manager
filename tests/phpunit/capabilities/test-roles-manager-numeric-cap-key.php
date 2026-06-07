<?php

/**
 * Regression test for numeric capability keys breaking strpos() in Roles_Manager.
 *
 * 数値キーの capability が administrator ロールに混入した環境で、
 * sync_roles() / activate() が TypeError を出さずに動作することを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Capabilities;

use VKBookingManager\Capabilities\Roles_Manager;
use WP_UnitTestCase;
use function get_role;

/**
 * @group capabilities
 */
class Roles_Manager_Numeric_Cap_Key_Test extends WP_UnitTestCase {

	/**
	 * sync_roles() / activate() が、数値キーの capability 混入時にも TypeError を投げないことを検証する。
	 *
	 * PHP は配列キーが数値文字列（"0", "123" 等）の場合、自動的に int 型へキャストするため、
	 * `foreach ( $role->capabilities as $cap => $enabled )` の $cap が int になる。
	 * strict_types 下で strpos( $cap, ... ) に int を渡すと TypeError になるが、
	 * 修正後はこのケースでも例外を出さずに完走する必要がある。
	 */
	public function test_sync_roles(): void {

		// テストの配列。
		// 各ケースで administrator ロールに混入させる capability キーと、
		// sync_roles() を呼ぶエントリポイント（メソッド名）を指定する。
		$test_cases = array(
			array(
				'test_condition_name' => '数値文字列キー "123" の capability が混入し sync_roles() を呼ぶ場合 => 例外なく完走',
				'inject_caps'         => array(
					'123' => true,
				),
				'entry_method'        => 'sync_roles',
			),
			array(
				'test_condition_name' => '数値文字列キー "0" の capability が混入し activate() を呼ぶ場合 => 例外なく完走',
				'inject_caps'         => array(
					'0' => true,
				),
				'entry_method'        => 'activate',
			),
			array(
				'test_condition_name' => '数値キーと通常キーが混在し sync_roles() を呼ぶ場合 => 例外なく完走',
				'inject_caps'         => array(
					'456'                 => true,
					'custom_text_cap'     => true,
					'post_type_manage_xxx' => true,
				),
				'entry_method'        => 'sync_roles',
			),
		);

		foreach ( $test_cases as $case ) {

			// administrator ロールを取得し、テスト用の capability を混入させる。
			// 数値文字列キーは WP_Role::add_cap() を通すと PHP の仕様で int キーに変換される。
			$admin_role = get_role( 'administrator' );
			$this->assertNotNull( $admin_role, $case['test_condition_name'] . '（前提: administrator ロールが存在する）' );

			foreach ( $case['inject_caps'] as $cap => $granted ) {
				$admin_role->add_cap( (string) $cap, $granted );
			}

			// エントリポイントを実行する。修正前は strpos() に int が渡り TypeError で落ちる。
			$manager = new Roles_Manager();
			$manager->{$case['entry_method']}();

			// ここまで例外なく到達できれば成功（数値キーでも安全に処理されている）。
			$this->assertTrue( true, $case['test_condition_name'] );

			// クリーンアップ: 混入させた capability を administrator ロールから除去する。
			$admin_role = get_role( 'administrator' );
			foreach ( $case['inject_caps'] as $cap => $granted ) {
				$admin_role->remove_cap( (string) $cap );
			}
		}
	}
}
