<?php
/**
 * ブロック CSS における角丸カスタムプロパティ（--vkbm--radius--*）の扱いを検証するテスト。
 *
 * issue #420: 基本設定（プロバイダー設定）で「角丸の基本サイズ」を指定しても、
 * 予約ページの見た目（時間枠カード・選択内容パネルなど）に反映されない不具合があった。
 *
 * 実測した原因は次の2点。
 * 1. assets/scss/variables.scss の :root ブロック（--vkbm--radius--md 等の既定値定義）が
 *    assets/scss/index-blocks.scss 経由で各ブロックの style.scss にも @forward され、
 *    ビルド後の build/blocks/*\/style-index.css に :root{...} が重複出力されていた。
 *    CSS カスタムプロパティは同じ :root への宣言なら「後から読み込まれた方が勝つ」ため、
 *    設定値を差し込むインライン CSS より後にブロック CSS が読み込まれる状況になると、
 *    既定値（8px）で設定値が上書きされる構造だった。
 * 2. .vkbm-slot-list__item（時間枠カード）・.vkbm-plan-summary__status（選択内容パネル。
 *    日時・人数・予約に進むボタンを子に持つ）など、一部のセレクタが
 *    --vkbm--radius--md を参照せず border-radius の値を直接ハードコードしていた。
 *
 * ビルド成果物（build/ 配下、.gitignore 対象で CI ではビルドされない）ではなく、
 * 上記2点の原因そのものであるソースファイルの構成を直接検証する。CSS の読み込み順序に
 * 依存するカスケードの結果はユニットテストで直接検証しにくいため、「index-blocks.scss
 * から実際に読み込まれる全パーシャルに :root ブロックが存在しない（＝ :root の実体が
 * どこにも重複しない）こと」と「対象セレクタが共通のカスタムプロパティを参照している
 * こと」の2点に落として検証する。
 *
 * WordPress のフック・DB を使わないため WP_UnitTestCase ではなく通常の TestCase を使う。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * 角丸カスタムプロパティのソース構成を検証するテストクラス。
 *
 * @group assets
 */
class Block_Style_Tokens_Test extends TestCase {

	/**
	 * index-blocks.scss から @use / @forward で到達する全パーシャルに、
	 * トップレベルの :root ブロック宣言が存在しないことを検証する。
	 *
	 * 「index-blocks.scss が @forward "variables" を含まない」という条件だけを見ると、
	 * 誰かが別のパーシャル（例: common.scss）へ @use "variables" を書き足した場合に
	 * :root の重複が復活してもこのテストは気づけない。実際に到達するファイルを
	 * 再帰的に辿り、それぞれの中身を見て判定する。
	 */
	public function test_root_custom_properties_are_not_declared_in_partials_reachable_from_block_styles(): void {
		$entry_path = VKBM_PLUGIN_DIR_PATH . 'assets/scss/index-blocks.scss';
		$this->assertFileExists( $entry_path, 'assets/scss/index-blocks.scss が存在しません。' );

		$visited = array();
		$this->collect_reachable_scss_partials( $entry_path, $visited );

		// index-blocks.scss 自身が到達先に含まれていることを確認し、収集ロジックが
		// そもそも機能しているかを担保する（正常系の前提チェック）。
		$this->assertArrayHasKey(
			(string) realpath( $entry_path ),
			$visited,
			'到達ファイル収集の起点である index-blocks.scss 自体が結果に含まれていません。'
		);

		$this->assertNotEmpty(
			$visited,
			'index-blocks.scss から到達するファイルが1件も見つかりませんでした（@use / @forward の解析に失敗している可能性があります）。'
		);

		foreach ( $visited as $path => $content ) {
			$this->assertFalse(
				$this->has_top_level_root_block( $content ),
				sprintf(
					'%s に :root ブロックが見つかりました。ブロック CSS（webpack 経由で個別ビルドされる）へ ' .
					':root の実体を持ち込むと、build/blocks/*/style-index.css に既定値が重複出力され、' .
					'基本設定で指定した値が上書きされる不具合（issue #420）が復活します。',
					$path
				)
			);
		}
	}

	/**
	 * variables.scss 自体には、既定値としての :root { --vkbm--radius--md: ... } 定義が
	 * 引き続き残っていることを検証する（既定値の実体そのものを消してはいけない）。
	 */
	public function test_variables_scss_still_declares_default_root_custom_properties(): void {
		$path = VKBM_PLUGIN_DIR_PATH . 'assets/scss/variables.scss';
		$this->assertFileExists( $path, 'assets/scss/variables.scss が存在しません。' );

		$content = $this->read_local_file( $path );

		$this->assertMatchesRegularExpression(
			'/:root\s*\{[^}]*--vkbm--radius--md\s*:/s',
			$content,
			'variables.scss の :root ブロックに --vkbm--radius--md の既定値定義が見つかりません。'
		);
	}

	/**
	 * bin/build-css-bundles.js の vkbm-variables バンドルが variables.scss のみを含み、
	 * 他のバンドル（frontend / auth / editor）には variables.scss が含まれないことを検証する。
	 *
	 * :root の実体を vkbm-variables バンドル1本に集約し、他バンドルには含めないことで、
	 * Common_Styles::register_styles() の $deps 経由でのみ読み込ませる設計（本文コミット
	 * メッセージ・class-common-styles.php の PHPDoc を参照）を、ビルド設定の側から担保する。
	 */
	public function test_css_bundle_config_isolates_variables_partial_to_its_own_bundle(): void {
		$path = VKBM_PLUGIN_DIR_PATH . 'bin/build-css-bundles.js';
		$this->assertFileExists( $path, 'bin/build-css-bundles.js が存在しません。' );

		$content = $this->read_local_file( $path );

		$test_cases = array(
			array(
				'test_condition_name' => 'vkbm-variables.min.css バンドルは variables.scss を含む',
				'bundle_key'          => "'vkbm-variables.min.css'",
				'should_contain'      => true,
			),
			array(
				'test_condition_name' => 'vkbm-frontend.min.css バンドルは variables.scss を含まない',
				'bundle_key'          => "'vkbm-frontend.min.css'",
				'should_contain'      => false,
			),
			array(
				'test_condition_name' => 'vkbm-auth.min.css バンドルは variables.scss を含まない',
				'bundle_key'          => "'vkbm-auth.min.css'",
				'should_contain'      => false,
			),
			array(
				'test_condition_name' => 'vkbm-editor.min.css バンドルは variables.scss を含まない',
				'bundle_key'          => "'vkbm-editor.min.css'",
				'should_contain'      => false,
			),
			array(
				// vkbm-admin.min.css は 'variables-admin.scss'（variables.scss とは別ファイル）を
				// 含む唯一のバンドル。'variables.scss' との文字列一致で誤検出しやすい箇所のため
				// 専用ケースとして固定する。
				'test_condition_name' => 'vkbm-admin.min.css バンドルは variables-admin.scss を含むが variables.scss は含まない',
				'bundle_key'          => "'vkbm-admin.min.css'",
				'should_contain'      => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$block = $this->extract_js_object_property_block( $content, $case['bundle_key'] );
			$this->assertNotSame( '', $block, $case['test_condition_name'] . '（バンドル定義が見つかりません）' );

			$contains_variables = false !== strpos( $block, "'variables.scss'" );
			$this->assertSame( $case['should_contain'], $contains_variables, $case['test_condition_name'] );
		}
	}

	/**
	 * 予約ブロックの特定セレクタが、border-radius の値をハードコードせず
	 * 共通のカスタムプロパティ（--vkbm--radius--md）を参照していることを検証する。
	 *
	 * 対象は issue #420 のスクリーンショットで指摘された「時間枠カード」
	 * （.vkbm-slot-list__item）と「選択内容パネル」（.vkbm-plan-summary__status。
	 * .vkbm-plan-summary__status--detached の直前コメントに「日時・人数・貸切・料金・
	 * 操作ボタンを子に持つ」と明記されている要素）に加え、同じ原因を持つ
	 * .vkbm-plan-summary__pricing（料金ボックス）・.vkbm-slot-list--placeholder
	 * （空き枠が無い場合のプレースホルダー）。
	 */
	public function test_reservation_block_radius_declarations_reference_shared_custom_property(): void {
		$path = VKBM_PLUGIN_DIR_PATH . 'src/blocks/reservation/style.scss';
		$this->assertFileExists( $path, 'src/blocks/reservation/style.scss が存在しません。' );

		$content = $this->read_local_file( $path );

		$test_cases = array(
			array(
				'test_condition_name' => '.vkbm-slot-list__item（時間枠カード）は --vkbm--radius--md を参照する',
				'selector'            => '.vkbm-slot-list__item',
			),
			array(
				'test_condition_name' => '.vkbm-plan-summary__status（選択内容パネル）は --vkbm--radius--md を参照する',
				'selector'            => '.vkbm-plan-summary__status',
			),
			array(
				'test_condition_name' => '.vkbm-plan-summary__pricing（料金ボックス）は --vkbm--radius--md を参照する',
				'selector'            => '.vkbm-plan-summary__pricing',
			),
			array(
				'test_condition_name' => '.vkbm-slot-list--placeholder（空き枠なしのプレースホルダー）は --vkbm--radius--md を参照する',
				'selector'            => '.vkbm-slot-list--placeholder',
			),
		);

		foreach ( $test_cases as $case ) {
			$block = $this->extract_top_level_rule_block( $content, $case['selector'] );
			$this->assertNotSame( '', $block, $case['test_condition_name'] . '（セレクタが見つかりません）' );
			$this->assertMatchesRegularExpression(
				'/border-radius:\s*var\(--vkbm--radius--md/',
				$block,
				$case['test_condition_name']
			);
		}
	}

	/**
	 * extract_top_level_rule_block() が、直前の文（@use "…"; 等）がセミコロン終端で
	 * 終わっている場合でも、その直後の最初のルールを取り出せることを検証する。
	 *
	 * 安藤さんの再レビューで判明した不具合。has_top_level_root_block() 側で先に直した
	 * 「直前のセミコロン終端の文ごとセレクタとして拾ってしまう」バグが、この兄弟
	 * ヘルパーには残っていた。実際 src/blocks/reservation/style.scss は1行目が
	 * `@use "…";` のため、2番目の宣言である先頭ルール
	 * `.wp-block-vk-booking-manager-reservation` はこのヘルパーでは永久に取り出せない
	 * 状態だった（test_reservation_block_radius_declarations_reference_shared_custom_property()
	 * の対象4セレクタがいずれもファイルの先頭ルールではないため、そちらのテストは
	 * 通ってしまい気づけていなかった）。
	 */
	public function test_extract_top_level_rule_block_finds_rule_immediately_after_semicolon_terminated_statement(): void {
		$scss = "@use \"../../../assets/scss/index-blocks.scss\" as *;\n\n" .
			".wp-block-vk-booking-manager-reservation {\n\tdisplay: block;\n}\n";

		$block = $this->invoke_extract_top_level_rule_block( $scss, '.wp-block-vk-booking-manager-reservation' );

		$this->assertNotSame( '', $block, '@use の直後にある先頭ルールを取り出せていません。' );
		$this->assertStringContainsString( 'display: block;', $block );
	}

	/**
	 * private メソッド extract_top_level_rule_block() をリフレクション経由で呼び出す。
	 *
	 * @param string $scss     テスト対象の SCSS 文字列。
	 * @param string $selector 完全一致させたいセレクタ。
	 * @return string
	 */
	private function invoke_extract_top_level_rule_block( string $scss, string $selector ): string {
		$method = new \ReflectionMethod( self::class, 'extract_top_level_rule_block' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this, $scss, $selector );
	}

	/**
	 * has_top_level_root_block() が、直前の文（@use / @forward / $変数宣言）を
	 * セレクタごと拾ってしまい完全一致に失敗するケースや、@media の中の :root を
	 * 見逃さないことを検証する。
	 *
	 * 安藤さんの再レビューで、`has_top_level_root_block()` と `strip_scss_comments()` を
	 * 切り出して実際に動かした結果、素の :root（A）・何かルールの後の :root（B）は
	 * 検出できていたが、`@use` の前置きの後（C）・変数宣言の前置きの後（D）・
	 * `@media` の中（E）・`@forward` の後（F）の :root を見逃すことが判明した
	 * （原因: セレクタ抽出が直前のセミコロン終端の文ごと拾ってしまい、完全一致に
	 * 失敗していたため）。特に F は index-blocks.scss 自身が全行 `@forward` のため、
	 * このテストが守りたい不変条件の最も近いところに開いていた穴だった。
	 *
	 * 3回目のレビューで安藤さんが追加で実測・指摘した3点も同じテストに含める。
	 * - G: `url(https://…)` のような値中の `//` に反応して行末まで削ってしまい、
	 *   直後の :root を巻き込んで見失うケース（strip_scss_comments() のコロン直前チェック）。
	 * - H: セレクタリストの先頭・中間に :root があるケース
	 *   （selector_list_contains_root() が末尾要素しか見ていなかった不備）。
	 * - I・J: `@each` ループ・`@mixin` 定義の本体にある :root
	 *   （should_recurse_into_at_rule() の対象拡張）。
	 */
	public function test_has_top_level_root_block(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'A: 素の :root が先頭 => 検出できる',
				'scss'                => ':root { --a: 1px; }',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'B: 何かルールの後の :root => 検出できる',
				'scss'                => ".foo { color: red; }\n:root { --a: 1px; }",
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'C: @use の前置きの後の :root => 検出できる',
				'scss'                => "@use \"sass:math\";\n:root { --a: 1px; }",
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'D: $変数宣言の前置きの後の :root => 検出できる',
				'scss'                => "\$gap: 4px;\n:root { --a: 1px; }",
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'E: @media の中の :root => 検出できる',
				'scss'                => '@media (min-width: 600px) { :root { --a: 1px; } }',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'F: @forward の後の :root => 検出できる',
				'scss'                => "@forward \"buttons\";\n@forward \"variables\";\n:root { --a: 1px; }",
				'expected'            => true,
			),
			array(
				// url() の中の `//` に反応してコメントとして削られると、`)` `;` `}` が
				// 失われ、後続の :root が見失われる（安藤さんが実測で再現）。
				'test_condition_name' => 'G: url(https://…) を含むルールの後の :root => 検出できる',
				'scss'                => ".foo { background: url(https://example.com/a.png); }\n:root { --a: 1px; }",
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'H: セレクタリストの先頭に :root があるカンマ並記 => 検出できる',
				'scss'                => ':root, .vkbm-scope { --a: 1px; }',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'H2: セレクタリストの中間に :root があるカンマ並記 => 検出できる',
				'scss'                => '.vkbm-scope, :root, .vkbm-other { --a: 1px; }',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'I: @each ループの本体にある :root => 検出できる',
				'scss'                => '@each $name, $value in $map { :root { --a: #{$value}; } }',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'J: @mixin 定義の本体にある :root => 検出できる',
				'scss'                => '@mixin vars { :root { --a: 1px; } }',
				'expected'            => true,
			),
			array(
				'test_condition_name' => ':root が1つも無い場合 => 検出しない',
				'scss'                => '.foo { color: red; }',
				'expected'            => false,
			),
			array(
				// `&:root` は通常のセレクタネストのため、コンパイル後は `.foo :root`（子孫結合子）
				// になり実際の :root にはならない。@media 等の条件付きグループ at-rule と違い
				// 再帰対象にしないことを確認する。
				'test_condition_name' => '通常セレクタのネスト内の &:root は子孫結合子になるため検出しない',
				'scss'                => '.foo { &:root { color: red; } }',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = $this->invoke_has_top_level_root_block( $case['scss'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * private メソッド has_top_level_root_block() をリフレクション経由で呼び出す。
	 *
	 * @param string $scss テスト対象の SCSS 文字列。
	 * @return bool
	 */
	private function invoke_has_top_level_root_block( string $scss ): bool {
		$method = new \ReflectionMethod( self::class, 'has_top_level_root_block' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $this, $scss );
	}

	/**
	 * ローカルの SCSS / JS ソースファイルを読み込む。
	 *
	 * @param string $path 絶対パス。
	 * @return string ファイル内容。
	 */
	private function read_local_file( string $path ): string {
		// プラグイン同梱のローカルソースファイルを読むため file_get_contents で問題ない。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled local source file.
		return (string) file_get_contents( $path );
	}

	/**
	 * SCSS からコメント（ブロックコメント /* ... *\/ と行コメント // ...）を除去する。
	 *
	 * 行コメントの正規表現は、直前の1文字がコロンでない `//` にだけマッチさせる。
	 * これが無いと `url(https://example.com/foo.png)` のような値の中の `//` にも
	 * マッチしてしまい、そこから行末までを丸ごと削ってしまう（`)` や直後の `;` `}` が
	 * 失われ、直後の :root を見逃す事故につながる）。現状の assets/scss/ には url( ) が
	 * 無いため無害だが、背景画像などを1つ足した時点でこの回帰防止テストが静かに
	 * 効かなくなるため、先回りして対応する。
	 *
	 * @param string $scss SCSS ソース文字列。
	 * @return string コメントを除いた文字列。
	 */
	private function strip_scss_comments( string $scss ): string {
		$without_block_comments = preg_replace( '#/\*.*?\*/#s', '', $scss );
		$without_block_comments = null === $without_block_comments ? $scss : $without_block_comments;

		$without_line_comments = preg_replace( '#(^|[^:])//[^\n]*#', '$1', $without_block_comments );
		return null === $without_line_comments ? $without_block_comments : $without_line_comments;
	}

	/**
	 * SCSS 文字列に、トップレベル（または @media 等の直下・@mixin/@each の本体）で
	 * :root { ... } 宣言が存在するかどうかを判定する。
	 *
	 * @param string $scss SCSS ソース文字列（コメントは呼び出し側で除去済みでなくてよい）。
	 * @return bool :root ブロックが見つかれば true。
	 */
	private function has_top_level_root_block( string $scss ): bool {
		return $this->has_root_block_in_group( $this->strip_scss_comments( $scss ) );
	}

	/**
	 * $group（トップレベル、または @media 等の直下・@mixin/@each の本体）を
	 * 波かっこの対応を追いながら走査し、:root ブロックを探す。
	 *
	 * 通常のセレクタネスト（例: `.foo { &:root { ... } }`）はコンパイル後
	 * `.foo :root {...}`（子孫結合子）になり実際の :root にはならないため再帰しないが、
	 * 以下の at-rule の直下だけは同じ深さ0として再帰的に見る（should_recurse_into_at_rule()
	 * 参照）。
	 * - `@media` / `@supports` / `@container` / `@layer` / `@scope`:
	 *   セレクタではなく条件付きグループ at-rule で、内側の :root はコンパイル後も
	 *   そのままトップレベルの :root として出力される。
	 * - `@each`（ループ）: ループ本体はコンパイル時にその場へ展開されるため、内側の
	 *   :root は実際にトップレベルの :root になりうる。
	 * - `@mixin`（定義）: 定義そのものは出力を生まないが、同じファイル内の @include で
	 *   呼び出されると内側の :root がその呼び出し位置にそのまま展開される。呼び出しの
	 *   有無まで追跡するのはこのテストの目的に対して過剰なため、定義に :root がある
	 *   時点で保守的に検出対象とする。
	 *
	 * @param string $group 走査対象の文字列（コメント除去済み）。
	 * @return bool :root ブロックが見つかれば true。
	 */
	private function has_root_block_in_group( string $group ): bool {
		$length = strlen( $group );
		$pos    = 0;

		while ( $pos < $length ) {
			$open = strpos( $group, '{', $pos );
			if ( false === $open ) {
				break;
			}

			$close = $this->find_matching_close_brace( $group, $open );
			if ( false === $close ) {
				break;
			}

			$selector = $this->extract_selector_before_brace( $group, $pos, $open );

			// selector_list_contains_root() はカンマ区切りの全要素を見るため、
			// 単独の ':root'（要素数1のリスト）もこの1行だけで判定できる。
			if ( $this->selector_list_contains_root( $selector ) ) {
				return true;
			}

			if ( $this->should_recurse_into_at_rule( $selector ) ) {
				$inner = substr( $group, $open + 1, $close - $open - 1 );
				if ( $this->has_root_block_in_group( $inner ) ) {
					return true;
				}
			}

			$pos = $close + 1;
		}

		return false;
	}

	/**
	 * `{` の直前テキストから、セレクタ（または at-rule 名）候補を取り出す。
	 *
	 * `$from` から `$open` までの間には、直前の文（`@use "…";` / `@forward "…";` /
	 * `$gap: 4px;` など、いずれも `;` 終端）がそのまま残っていることがある。
	 * これを含めたまま完全一致判定すると `':root' === $selector` に失敗し、
	 * その前置きを持つ :root 宣言を見逃す。直前の `;` より後ろだけを候補にすることで、
	 * 前置きの有無に関わらずセレクタ本体だけを取り出す。
	 *
	 * @param string $group $open を含む走査対象文字列。
	 * @param int    $from  走査開始位置（直前のブロックの `}` の次、または0）。
	 * @param int    $open  `{` の位置。
	 * @return string セレクタ（または at-rule 名）候補。
	 */
	private function extract_selector_before_brace( string $group, int $from, int $open ): string {
		$raw           = trim( substr( $group, $from, $open - $from ) );
		$semicolon_pos = strrpos( $raw, ';' );
		if ( false === $semicolon_pos ) {
			return $raw;
		}

		return trim( substr( $raw, $semicolon_pos + 1 ) );
	}

	/**
	 * セレクタリスト（カンマ区切り）の要素のいずれかが :root かどうかを判定する。
	 *
	 * 末尾要素だけを見る実装（`strrchr` によるカンマ区切りの最後の要素抽出）だと、
	 * `:root, .vkbm-scope { … }` のように :root が先頭・中間にあるケースを見逃す。
	 * カンマ区切りの全要素を見て判定する。
	 *
	 * @param string $selector セレクタ文字列。
	 * @return bool いずれかの要素が :root なら true。
	 */
	private function selector_list_contains_root( string $selector ): bool {
		$parts = array_map( 'trim', explode( ',', $selector ) );
		return in_array( ':root', $parts, true );
	}

	/**
	 * 本体を同じ深さ0として再帰的に見るべき at-rule（内側の :root が実際に
	 * トップレベルの :root になりうる at-rule）かどうかを判定する。
	 *
	 * `@media` / `@supports` / `@container` / `@layer` / `@scope` は条件付きグループ
	 * at-rule、`@each` はループ、`@mixin` は定義（`@include` 呼び出し先の展開まで
	 * 追跡はしないが、保守的に検出対象とする。has_root_block_in_group() の PHPDoc 参照）。
	 *
	 * @param string $selector セレクタ（または at-rule 名）候補。
	 * @return bool 該当する at-rule なら true。
	 */
	private function should_recurse_into_at_rule( string $selector ): bool {
		return 1 === preg_match( '/^@(media|supports|container|layer|scope|each|mixin)\b/i', $selector );
	}

	/**
	 * $open 位置の `{` に対応する `}` の位置を、ネストを数えて求める。
	 *
	 * @param string $css  検索対象文字列。
	 * @param int    $open `{` の位置。
	 * @return int|false 対応する `}` の位置。見つからなければ false。
	 */
	private function find_matching_close_brace( string $css, int $open ) {
		$depth  = 0;
		$length = strlen( $css );
		for ( $i = $open; $i < $length; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return false;
	}

	/**
	 * SCSS 文字列から @use / @forward が参照するパーシャル名（クォートの種類・
	 * コメントの有無に関わらず）を抽出する。
	 *
	 * @param string $scss SCSS ソース文字列。
	 * @return array<int, string> 参照されているパーシャル名（拡張子・先頭 _ は含まない）の配列。
	 */
	private function extract_used_or_forwarded_partial_names( string $scss ): array {
		$stripped = $this->strip_scss_comments( $scss );
		preg_match_all( '/@(?:use|forward)\s+["\']([^"\']+)["\']/', $stripped, $matches );
		return $matches[1] ?? array();
	}

	/**
	 * SCSS のパーシャル名（例: "variables"）から、assets/scss/ 配下の実ファイルパスを
	 * 解決する。相対パス表記（./ や ../）にはこのプロジェクトの構成上対応不要のため、
	 * 単純に assets/scss/ 直下の同名ファイルとして解決する。
	 *
	 * @param string $name パーシャル名。
	 * @return string 解決したファイルパス（存在しなくてもパス文字列は返す）。
	 */
	private function resolve_scss_partial_path( string $name ): string {
		$basename = basename( $name );
		return VKBM_PLUGIN_DIR_PATH . 'assets/scss/' . $basename . '.scss';
	}

	/**
	 * $entry_path から @use / @forward で到達する全ファイル（$entry_path 自身を含む）を
	 * 再帰的に収集し、$visited（実パス => ファイル内容）へ書き込む。
	 *
	 * @param string               $entry_path 起点となる SCSS ファイルの絶対パス。
	 * @param array<string,string> $visited    収集結果（参照渡し）。
	 */
	private function collect_reachable_scss_partials( string $entry_path, array &$visited ): void {
		if ( ! file_exists( $entry_path ) ) {
			return;
		}

		$real = realpath( $entry_path );
		if ( false === $real || isset( $visited[ $real ] ) ) {
			return;
		}

		$content          = $this->read_local_file( $entry_path );
		$visited[ $real ] = $content;
		$referenced_names = $this->extract_used_or_forwarded_partial_names( $content );

		foreach ( $referenced_names as $name ) {
			$this->collect_reachable_scss_partials( $this->resolve_scss_partial_path( $name ), $visited );
		}
	}

	/**
	 * JavaScript オブジェクトリテラル文字列から、指定キー（例: "'vkbm-frontend.min.css'"）の
	 * 値部分（`[` から対応する `]` まで）を抜き出す。
	 * bin/build-css-bundles.js の BUNDLES 定義（キー: 配列）の検証に使う。
	 *
	 * コメントを除去してから検索する。除去しないと、将来コメント中に
	 * `'vkbm-frontend.min.css'` 等のキー文字列が書かれた場合にその直後の `[` を
	 * 誤って拾い、無関係なブロックを検査してしまう。
	 *
	 * @param string $content JS ソース文字列。
	 * @param string $key     クォート込みのキー文字列（例: "'vkbm-frontend.min.css'"）。
	 * @return string 見つからなければ空文字列（コメント除去後の文字列から抜き出す）。
	 */
	private function extract_js_object_property_block( string $content, string $key ): string {
		$stripped = $this->strip_js_comments( $content );

		$key_pos = strpos( $stripped, $key );
		if ( false === $key_pos ) {
			return '';
		}

		$open = strpos( $stripped, '[', $key_pos );
		if ( false === $open ) {
			return '';
		}

		$depth  = 0;
		$length = strlen( $stripped );
		for ( $i = $open; $i < $length; $i++ ) {
			if ( '[' === $stripped[ $i ] ) {
				++$depth;
			} elseif ( ']' === $stripped[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $stripped, $open, $i - $open + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * JS ソース文字列からコメント（ブロックコメント /* ... *\/ と行コメント // ...）を除去する。
	 *
	 * コメントの記法（`//` 行コメント・`/* ... *\/` ブロックコメント）は SCSS と同じのため、
	 * strip_scss_comments() と同じ正規表現をそのまま使う。
	 *
	 * @param string $js JS ソース文字列。
	 * @return string コメントを除いた文字列。
	 */
	private function strip_js_comments( string $js ): string {
		return $this->strip_scss_comments( $js );
	}

	/**
	 * SCSS ソース文字列から、指定クラスセレクタのトップレベル（波かっこの深さ0）の
	 * ルールブロックを1つ抜き出す。
	 *
	 * strpos による単純一致だと (a) `.foo .vkbm-slot-list__item {` のような子孫セレクタの
	 * 末尾にも一致して別のブロックを拾う、(b) `{` の前に改行が入る・セレクタをカンマで
	 * 並べる整形で不一致になる、(c) コメント内の `{` `}` を数えてしまう、という弱点がある
	 * ため、コメントを除去したうえで「セレクタ全体（カンマ区切りの単位）が完全一致する
	 * トップレベルのルール」だけを対象にする。ネスト（`&:hover` 等）の中身は含めない
	 * （トップレベルの宣言だけを対象にすることで、ネスト内だけ正しい値になっている
	 * 見逃しを防ぐ）。
	 *
	 * `{` の直前テキストの取り出しは extract_selector_before_brace() と共用する。
	 * 直前の文が `@use "…";` などのセミコロン終端で終わっている場合（このファイルの
	 * 1行目が `@use "…";` のため、先頭ルールがまさにこのケースに当たる）、素の
	 * `substr()` のままだと前置き文ごとセレクタとして拾ってしまい、完全一致に
	 * 失敗して先頭ルールを永久に取り出せなくなる不具合があった。
	 *
	 * @param string $scss     SCSS ソース文字列。
	 * @param string $selector 完全一致させたいセレクタ（例: ".vkbm-slot-list__item"）。
	 * @return string 見つからなければ空文字列。ネストは含まないトップレベル宣言のみ。
	 */
	private function extract_top_level_rule_block( string $scss, string $selector ): string {
		$stripped = $this->strip_scss_comments( $scss );
		$length   = strlen( $stripped );
		$pos      = 0;

		while ( $pos < $length ) {
			$open = strpos( $stripped, '{', $pos );
			if ( false === $open ) {
				break;
			}

			$raw_selector_list = $this->extract_selector_before_brace( $stripped, $pos, $open );
			$selector_parts    = array_map( 'trim', explode( ',', $raw_selector_list ) );

			$close = $this->find_matching_close_brace( $stripped, $open );
			if ( false === $close ) {
				break;
			}

			if ( in_array( $selector, $selector_parts, true ) ) {
				// トップレベル宣言のみを対象にするため、直下のプロパティ宣言だけを
				// 対象にする（ネストされた `&...{ ... }` の中身は除外する）。
				return $this->strip_nested_rule_bodies( substr( $stripped, $open, $close - $open + 1 ) );
			}

			$pos = $close + 1;
		}

		return '';
	}

	/**
	 * ルールブロック文字列（`{...}` を含む）から、ネストされた子ルール（`{ ... }`）の
	 * 中身を取り除き、直下のプロパティ宣言だけを残す。
	 *
	 * @param string $block `{` から `}` までを含むルールブロック文字列。
	 * @return string ネストの中身を除いた文字列。
	 */
	private function strip_nested_rule_bodies( string $block ): string {
		$length = strlen( $block );
		$result = '';
		$depth  = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $block[ $i ];
			if ( '{' === $char ) {
				++$depth;
				if ( 1 === $depth ) {
					$result .= $char;
				}
				continue;
			}
			if ( '}' === $char ) {
				if ( 1 === $depth ) {
					$result .= $char;
				}
				--$depth;
				continue;
			}
			if ( 1 === $depth ) {
				$result .= $char;
			}
		}

		return $result;
	}
}
