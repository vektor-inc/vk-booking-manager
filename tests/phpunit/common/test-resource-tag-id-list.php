<?php
/**
 * リソースタグ ID 正規化ユーティリティ（Resource_Tag_Id_List）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Common;

use VKBookingManager\Common\Resource_Tag_Id_List;
use WP_UnitTestCase;

/**
 * Resource_Tag_Id_List::normalize() のテスト。
 *
 * @group common
 * @group resource-tag
 */
class Resource_Tag_Id_List_Test extends WP_UnitTestCase {

	/**
	 * normalize(): 正の整数のみ・重複除去・上限件数の正規化ルールを検証する。
	 *
	 * 以前の実装（`absint()` を使うもの）は負値を絶対値化してしまい、
	 * `-5` が有効な ID `5` として紛れ込む不具合があった。`(int)` キャスト後に
	 * 0 以下を除外する現在の実装でその挙動が無いことを確認する。
	 *
	 * ここで検証したいのは無料版・Pro版に関わらない共通の正規化ルールのため、
	 * 第2引数に `false`（Pro版扱い）を明示し、実行環境が無料版構成
	 * （CIの `phpunit-free` ジョブ等）でも常に Pro版の正規化ルールを検証する。
	 */
	public function test_normalize(): void {
		// 上限件数超過テスト用に MAX_COUNT + 5 件の入力を作る（1始まりの連番）。
		$over_limit_input = range( 1, Resource_Tag_Id_List::MAX_COUNT + 5 );

		$test_cases = array(
			array(
				'test_condition_name' => '正の整数のみの配列 => そのまま正規化される（正常系）',
				'raw'                 => array( 1, 2, 3 ),
				'expected'            => array( 1, 2, 3 ),
			),
			array(
				'test_condition_name' => '重複を含む配列 => 重複除去される（正常系）',
				'raw'                 => array( 1, 2, 2, 3, 1 ),
				'expected'            => array( 1, 2, 3 ),
			),
			array(
				'test_condition_name' => '文字列の数値を含む配列 => 整数へキャストされる（正常系）',
				'raw'                 => array( '1', '2', '3' ),
				'expected'            => array( 1, 2, 3 ),
			),
			array(
				'test_condition_name' => '負値を含む配列 => absint() のように絶対値化されず除外される（異常系・回帰防止）',
				'raw'                 => array( -5, 1, -1, 2 ),
				'expected'            => array( 1, 2 ),
			),
			array(
				'test_condition_name' => '0 を含む配列 => 0 は除外される（境界値）',
				'raw'                 => array( 0, 1, 2 ),
				'expected'            => array( 1, 2 ),
			),
			array(
				'test_condition_name' => '配列以外（文字列） => 空配列になる（異常系）',
				'raw'                 => 'not-an-array',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '配列以外（null） => 空配列になる（異常系）',
				'raw'                 => null,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '空配列 => 空配列のまま（境界値）',
				'raw'                 => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '上限件数（MAX_COUNT）を超える配列 => 上限件数で打ち切られる（境界値）',
				'raw'                 => $over_limit_input,
				'expected'            => array_slice( $over_limit_input, 0, Resource_Tag_Id_List::MAX_COUNT ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				// 第2引数に false を明示し、実行環境の実際のエディションに関わらず
				// Pro版の正規化ルールを検証する（CIの無料版構成でも本テストの結果が変わらないようにする）。
				Resource_Tag_Id_List::normalize( $case['raw'], false ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * MAX_COUNT が正の整数として定義されていることを確認する（境界値・定義ミス防止）。
	 */
	public function test_max_count_is_positive_integer(): void {
		$this->assertIsInt( Resource_Tag_Id_List::MAX_COUNT );
		$this->assertGreaterThan( 0, Resource_Tag_Id_List::MAX_COUNT );
	}

	/**
	 * normalize(): 無料版では入力値に関わらず常に空配列を返すことを検証する（#431）。
	 *
	 * 無料版にはリソースタグ機能自体が無いため、resource_tag_ids を指定しても
	 * 「該当リソース0件」として絞り込まれるのではなく、絞り込み自体が行われない
	 * （＝空配列）扱いになるべき。実行時のエディション判定
	 * （`Pro_Upsell::is_free_edition()`）は、プラグイン本体ファイルのヘッダー情報から
	 * 判定する実装のため、無料版ビルド（CIの `phpunit-free` ジョブ）でこの判定は実際に
	 * true になる。このテストではその実行環境差に依存せず判定分岐そのものを狙って
	 * 検証したいため、`normalize()` の第2引数（テスト注入用の `$is_free_edition`）へ
	 * 明示的に true を渡す。
	 */
	public function test_normalize_forces_empty_array_when_free_edition(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '無料版でタグIDを複数指定 => 常に空配列になる（正常系）',
				'raw'                 => array( 1, 2, 3 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '無料版で負値・重複を含むタグIDを指定 => 常に空配列になる（正常系）',
				'raw'                 => array( -5, 1, 1 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '無料版でタグID未指定（空配列） => 空配列のまま（境界値）',
				'raw'                 => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Resource_Tag_Id_List::normalize( $case['raw'], true ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * normalize(): Pro版（無料版ではない）と明示した場合は、通常の正規化ルールが
	 * 適用されることを検証する（回帰防止・第2引数のデフォルト分岐と対になる異常系確認）。
	 */
	public function test_normalize_applies_normal_rules_when_not_free_edition(): void {
		$this->assertSame(
			array( 1, 2 ),
			Resource_Tag_Id_List::normalize( array( -5, 1, 1, 2 ), false ),
			'Pro版指定時は通常どおり正の整数のみ・重複除去された配列になること'
		);
	}

	/**
	 * normalize(): 第2引数を明示した呼び出しは、第2引数省略時のエディション判定
	 * メモ化キャッシュを参照・更新しないことを検証する（メモ化導入時の回帰防止）。
	 *
	 * 明示呼び出し（true/false）を挟んでも、直後の省略呼び出しの結果が
	 * その明示値に引きずられて変わらない（＝実行時の実際のエディション判定を
	 * 使い続ける）ことを確認する。この開発・テスト環境は常に Pro 版として動くため、
	 * 省略呼び出しは常に通常の正規化ルールが適用される前提で比較する。
	 */
	public function test_normalize_explicit_argument_does_not_affect_omitted_argument_result(): void {
		$raw = array( -5, 1, 1, 2 );

		// 省略呼び出しの基準結果（この環境では Pro版として動くため、通常の正規化結果）。
		$before = Resource_Tag_Id_List::normalize( $raw );

		// 明示的に true（無料版）を渡す呼び出しを挟む。キャッシュへ影響しなければ、
		// 直後の省略呼び出しの結果は $before と変わらないはず。
		$explicit_true = Resource_Tag_Id_List::normalize( $raw, true );
		$this->assertSame( array(), $explicit_true, '第2引数に true を渡した呼び出しは無料版扱いで空配列になること' );

		$after = Resource_Tag_Id_List::normalize( $raw );
		$this->assertSame(
			$before,
			$after,
			'明示的な第2引数を渡した呼び出しを挟んでも、省略呼び出しの結果（実行時のエディション判定）は変わらないこと'
		);
	}
}
