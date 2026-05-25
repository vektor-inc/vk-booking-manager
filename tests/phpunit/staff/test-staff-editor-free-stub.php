<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Staff;

use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * Free 版 Staff_Editor スタブの公開 API テスト。
 *
 * 無料版では Staff_Editor は意図的に無効化されたスタブとなっており、
 * Pro 版と公開 API のシグネチャを揃えるためだけにメソッドが定義されている。
 * 本テストでは、Pro 版から build sync された Settings_Service からの呼び出しが
 * Fatal Error にならないことと、Free 版としての挙動（is_enabled / is_nomination_enabled が false）
 * を担保する。
 *
 * @group staff
 */
class Staff_Editor_Free_Stub_Test extends WP_UnitTestCase {

	/**
	 * Staff_Editor::clear_nomination_enabled_cache() を呼び出しても例外が発生しないこと、
	 * および is_enabled / is_nomination_enabled が Free 版仕様で false を返すことを確認する。
	 *
	 * テストケースを配列でまとめ、Free 版スタブの公開 API 仕様（メソッド名 / 期待値）を
	 * 一覧で把握できるようにしている。
	 *
	 * @return void
	 */
	public function test_clear_nomination_enabled_cache(): void {
		// テスト条件と期待値の組み合わせ。
		// test_condition_name は失敗時に出力される日本語説明。
		$test_cases = array(
			array(
				'test_condition_name' => 'clear_nomination_enabled_cache() を呼んでも例外が出ない（戻り値 null）',
				'callable'            => array( Staff_Editor::class, 'clear_nomination_enabled_cache' ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'is_enabled() は Free 版では常に false を返す',
				'callable'            => array( Staff_Editor::class, 'is_enabled' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'is_nomination_enabled() は Free 版では常に false を返す',
				'callable'            => array( Staff_Editor::class, 'is_nomination_enabled' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 静的メソッドを実行。clear_nomination_enabled_cache は void 戻りなので null となる。
			$actual = call_user_func( $case['callable'] );

			$this->assertSame(
				$case['expected'],
				$actual,
				$case['test_condition_name']
			);
		}

		// メソッドの存在を明示的に確認しておく（Pro→Free の build sync で消えた場合に検知するため）。
		$this->assertTrue(
			method_exists( Staff_Editor::class, 'clear_nomination_enabled_cache' ),
			'Staff_Editor::clear_nomination_enabled_cache() が定義されていること（Pro 版との API 互換性のため必須）'
		);
	}
}
