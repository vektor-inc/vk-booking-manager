<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Staff;

use WP_UnitTestCase;

/**
 * Static source-level assertions for the Free build's Staff_Editor stub.
 *
 * Free 版ビルドソース (class-staff-editor-free.php) に対する静的解析的テスト。
 *
 * Pro リポでは src/staff/class-staff-editor.php は build スクリプト
 * (bin/switch-resource-config-dev.js / switch-resource-config.js) が
 * edition に応じて class-staff-editor-{free,pro}.php からコピーする
 * 生成物。Pro リポでのテスト実行時は class-staff-editor.php に pro 実装が
 * 入るため、Free スタブクラスを通常の require ベースで読み込むと
 * `VKBookingManager\Staff\Staff_Editor` の namespace 衝突が起きる。
 *
 * そのため Free スタブの「公開 API シグネチャ」をクラスロードせずに
 * ファイル文字列として読み込んで宣言の有無を assert する。これにより
 * Free 版ビルドに同梱されるソースが Pro 版と公開 API を揃えているかを
 * Pro リポ側でも回帰検知できるようにする。
 *
 * @group staff
 */
class Staff_Editor_Free_Source_Test extends WP_UnitTestCase {

	/**
	 * Path to the Free edition staff editor source file.
	 *
	 * Free 版スタッフエディタのソースファイルパス。
	 *
	 * @var string
	 */
	private string $source_path;

	/**
	 * Loaded source content (cached per test).
	 *
	 * 読み込んだソース文字列（テストごとにキャッシュ）。
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Set up the source path and contents before each test.
	 *
	 * 各テスト実行前にソースファイルを読み込んでおく。
	 */
	public function set_up(): void {
		parent::set_up();

		// プラグインルートからの相対で Free 版スタッフエディタソースを指す。
		// Resolve the Free edition staff editor source from the plugin root.
		$this->source_path = dirname( __DIR__, 2 ) . '/../src/staff/class-staff-editor-free.php';

		// class-staff-editor-free.php は Pro リポ専用のソースで、
		// publish-release.yml が Pro の dist/ を Free リポへ rsync する際に
		// このテストファイルだけ持ち込まれ、対象ソースが存在しないため失敗する。
		// Free 側ビルド環境ではソースが無いので、ここで skip して回避する。
		// The Free edition source file only exists in the Pro repo. When the
		// release workflow syncs dist/ to the Free repo, this test ends up
		// running in an environment where the file is absent, so skip it.
		if ( ! file_exists( $this->source_path ) ) {
			$this->markTestSkipped(
				'class-staff-editor-free.php is Pro repo only; skipped in Free build environment.'
			);
		}

		// テスト実行の前提として、ソースファイルが存在することを担保する。
		// Guard: the Free source must exist as a prerequisite of these tests.
		$this->assertFileExists(
			$this->source_path,
			'Free edition staff editor source file must exist for build sync.'
		);

		$contents = file_get_contents( $this->source_path );

		// file_get_contents が false を返した場合は読み込み失敗としてテストを止める。
		// Bail out if reading the source failed so we do not assert against false.
		$this->assertNotFalse(
			$contents,
			'Failed to read Free edition staff editor source file.'
		);

		$this->source = (string) $contents;
	}

	/**
	 * Test: Free スタブのソースが規約に沿った宣言を保持しているかを
	 * 条件と期待値の配列でまとめて検証する。
	 *
	 * これらはどれか1つでも失われると、build sync 後の Free 版で
	 * Fatal Error など実害が出る／Pro との API 互換性が崩れる項目のみを
	 * 列挙している（実装詳細ではなく公開シグネチャを対象とする）。
	 *
	 * - 正常系: 必須宣言が存在する（クラス宣言、no-op メソッド宣言など）
	 * - 異常系/境界値: 実体ロジックを持ち込んでいないこと（no-op であること）
	 */
	public function test_free_stub_source_has_required_declarations(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'Free版でも Staff_Editor クラスが宣言されている事',
				'needle'              => 'class Staff_Editor',
				'should_contain'      => true,
			),
			array(
				'test_condition_name' => 'Free版でも namespace VKBookingManager\\Staff が宣言されている事 (Pro と同一名前空間)',
				'needle'              => 'namespace VKBookingManager\\Staff',
				'should_contain'      => true,
			),
			array(
				'test_condition_name' => 'Free版でも clear_nomination_enabled_cache() が宣言されている事 (Pro との API 互換 / 無料版ビルドで Fatal Error にならない事を担保)',
				'needle'              => 'public static function clear_nomination_enabled_cache(',
				'should_contain'      => true,
			),
			array(
				'test_condition_name' => 'Free版でも is_nomination_enabled() が宣言されている事 (Pro との API 互換)',
				'needle'              => 'public static function is_nomination_enabled(',
				'should_contain'      => true,
			),
			array(
				'test_condition_name' => 'Free版でも is_enabled() が宣言されている事 (Pro との API 互換)',
				'needle'              => 'public static function is_enabled(',
				'should_contain'      => true,
			),
			// 境界値: Free スタブは実体ロジックを持たない事の確認。
			// Pro 実装で使われているキャッシュプロパティの代入文 (実体クリア処理) を
			// Free 側に紛れ込ませないようにする。
			array(
				'test_condition_name' => 'Free スタブには $nomination_enabled_cache 代入処理が含まれない事 (no-op を維持)',
				'needle'              => 'self::$nomination_enabled_cache',
				'should_contain'      => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['should_contain'] ) {
				$this->assertStringContainsString(
					$case['needle'],
					$this->source,
					$case['test_condition_name']
				);
			} else {
				$this->assertStringNotContainsString(
					$case['needle'],
					$this->source,
					$case['test_condition_name']
				);
			}
		}
	}

	/**
	 * Test: Free スタブの clear_nomination_enabled_cache() が
	 * 戻り値の型を void として宣言している事を検証する。
	 *
	 * Pro 版 (class-staff-editor-pro.php) の同名メソッドも void なので、
	 * シグネチャレベルで一致している事を保証する。
	 *
	 * 正規表現で「public static function clear_nomination_enabled_cache(): void」
	 * の宣言行を検出する。空白の揺らぎ（タブ/スペース）は許容する。
	 */
	public function test_clear_nomination_enabled_cache_signature_matches_pro(): void {
		// public static function clear_nomination_enabled_cache(): void を検出
		$pattern = '/public\s+static\s+function\s+clear_nomination_enabled_cache\s*\(\s*\)\s*:\s*void/';

		$this->assertMatchesRegularExpression(
			$pattern,
			$this->source,
			'Free build stub must declare clear_nomination_enabled_cache() with the same `(): void` signature as the Pro edition to keep API parity and avoid Fatal Error in the free build.'
		);
	}
}
