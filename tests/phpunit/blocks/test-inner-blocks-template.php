<?php
/**
 * #1092: InnerBlocks の template の出し分けに使う、WordPress のバージョン判定と JS へ渡す値のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Blocks\Inner_Blocks_Template;
use WP_Scripts;
use WP_UnitTestCase;

/**
 * Inner_Blocks_Template のテスト。
 *
 * @group blocks
 */
class Inner_Blocks_Template_Test extends WP_UnitTestCase {
	/**
	 * テスト開始前の WordPress のバージョン（$wp_version）。
	 *
	 * @var string
	 */
	private $original_wp_version;

	/**
	 * テスト開始前のスクリプト登録（$wp_scripts）。
	 *
	 * @var WP_Scripts|null
	 */
	private $original_wp_scripts;

	/**
	 * テストで書き換える $wp_version と $wp_scripts を退避する。
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_wp_version = $GLOBALS['wp_version'];
		$this->original_wp_scripts = isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null;
	}

	/**
	 * 退避した $wp_version と $wp_scripts を元に戻す。
	 *
	 * テストの途中で失敗しても、後続のテストへ持ち越さないためにここで戻す。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$GLOBALS['wp_version'] = $this->original_wp_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のバージョンへ戻すため。
		$GLOBALS['wp_scripts'] = $this->original_wp_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テスト前のスクリプトレジストリへ戻すため。
		parent::tear_down();
	}

	/**
	 * Inner_Blocks_Template::is_block_type_template_supported() のテスト。
	 *
	 * Gutenberg プラグインが無い環境で、本体の 7.2 以降（ベータ版・RC 版を含む）だけを、
	 * 設定の template を読むエディタと判定すること。
	 *
	 * @return void
	 */
	public function test_is_block_type_template_supported(): void {
		// Gutenberg プラグインが有効だと本体のバージョンでは判定しないため、このテストは成り立たない。
		if ( defined( 'GUTENBERG_VERSION' ) ) {
			$this->markTestSkipped( 'Gutenberg プラグインが有効な環境では、本体のバージョンの差し替えで判定を確認できないためスキップする。' );
		}

		// テストの配列（WordPress のバージョン => 期待値）。
		$test_cases = array(
			array(
				'test_condition_name' => '7.0 => 7.1 以前の扱い（false）',
				'wp_version'          => '7.0',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '7.1 => 7.1 以前の扱い（false）',
				'wp_version'          => '7.1',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '7.1.5（7.1 系のマイナー版） => 7.1 以前の扱い（false）',
				'wp_version'          => '7.1.5',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '7.1.99（7.2 の直前の境界） => 7.1 以前の扱い（false）',
				'wp_version'          => '7.1.99',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '7.2-alpha（判定の基準そのもの） => 7.2 以降の扱い（true）',
				'wp_version'          => '7.2-alpha',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '7.2-alpha-61234-src（開発版の表記） => 7.2 以降の扱い（true）',
				'wp_version'          => '7.2-alpha-61234-src',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '7.2-beta5（ベータ版） => 7.2 以降の扱い（true）',
				'wp_version'          => '7.2-beta5',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '7.2-RC1-65565（RC 版） => 7.2 以降の扱い（true）',
				'wp_version'          => '7.2-RC1-65565',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '7.2（正式版） => 7.2 以降の扱い（true）',
				'wp_version'          => '7.2',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '7.2.1（7.2 のマイナー版） => 7.2 以降の扱い（true）',
				'wp_version'          => '7.2.1',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '7.3 => 7.2 以降の扱い（true）',
				'wp_version'          => '7.3',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// get_bloginfo( 'version' ) が返すバージョンを差し替える。
			$GLOBALS['wp_version'] = $case['wp_version']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_bloginfo( 'version' ) が返すバージョンをケースごとに差し替えるため。

			$this->assertSame( $case['expected'], Inner_Blocks_Template::is_block_type_template_supported(), $case['test_condition_name'] );
		}
	}

	/**
	 * Inner_Blocks_Template::resolve_block_type_template_supported() のテスト。
	 *
	 * Gutenberg プラグインが有効なときはその版（23.8 以上。RC 版を含む）で、
	 * 無いときは本体のバージョン（7.2 以降。ベータ版・RC 版を含む）で判定すること。
	 *
	 * @return void
	 */
	public function test_resolve_block_type_template_supported(): void {
		// テストの配列（本体のバージョン・Gutenberg プラグインの版 => 期待値）。
		$test_cases = array(
			array(
				'test_condition_name' => '本体 7.2 で Gutenberg なし => 本体で判定して true',
				'wp_version'          => '7.2',
				'gutenberg_version'   => null,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.2-RC1-65565 で Gutenberg なし => 本体で判定して true',
				'wp_version'          => '7.2-RC1-65565',
				'gutenberg_version'   => null,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.1 で Gutenberg なし => 本体で判定して false',
				'wp_version'          => '7.1',
				'gutenberg_version'   => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '本体 7.2 で Gutenberg 23.7（設定の template を読まない版が残っている） => false',
				'wp_version'          => '7.2',
				'gutenberg_version'   => '23.7',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '本体 7.2 で Gutenberg 23.7.1 => false',
				'wp_version'          => '7.2',
				'gutenberg_version'   => '23.7.1',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '本体 7.2 で Gutenberg 17.6.0（さらに古い版が残っている） => false',
				'wp_version'          => '7.2',
				'gutenberg_version'   => '17.6.0',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '本体 7.1 で Gutenberg 23.8 => true',
				'wp_version'          => '7.1',
				'gutenberg_version'   => '23.8',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.1 で Gutenberg 23.8.0（定数の実際の表記） => true',
				'wp_version'          => '7.1',
				'gutenberg_version'   => '23.8.0',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.1 で Gutenberg 23.8.0-rc.1（RC 版） => true',
				'wp_version'          => '7.1',
				'gutenberg_version'   => '23.8.0-rc.1',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.1 で Gutenberg 23.10.0（23.8 より新しい版） => true',
				'wp_version'          => '7.1',
				'gutenberg_version'   => '23.10.0',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.2 で Gutenberg 24.0.0 => true',
				'wp_version'          => '7.2',
				'gutenberg_version'   => '24.0.0',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '本体 7.2 で Gutenberg の版が空文字（読めない） => 今までの書き方に倒して false',
				'wp_version'          => '7.2',
				'gutenberg_version'   => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Inner_Blocks_Template::resolve_block_type_template_supported( $case['wp_version'], $case['gutenberg_version'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * Inner_Blocks_Template::localize_editor_script() のテスト。
	 *
	 * 判定の結果を、wp_localize_script() の文字列（true は "1"、false は ""）で JS へ渡すこと。
	 * JS 側（src/blocks/shared/inner-blocks-template.js）はこの文字列を前提に判定しているため、文字列のまま検証する。
	 *
	 * @return void
	 */
	public function test_localize_editor_script(): void {
		// Gutenberg プラグインが有効だと本体のバージョンでは判定しないため、このテストは成り立たない。
		if ( defined( 'GUTENBERG_VERSION' ) ) {
			$this->markTestSkipped( 'Gutenberg プラグインが有効な環境では、本体のバージョンの差し替えで判定を確認できないためスキップする。' );
		}

		$handle = 'vkbm-test-inner-blocks-template';

		// テストの配列（WordPress のバージョン・スクリプトの登録有無 => JS に渡る値）。
		$test_cases = array(
			array(
				'test_condition_name' => '7.1.5 でスクリプトが登録済み => 空文字',
				'wp_version'          => '7.1.5',
				'register_script'     => true,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '7.2-RC1-65565 でスクリプトが登録済み => "1"',
				'wp_version'          => '7.2-RC1-65565',
				'register_script'     => true,
				'expected'            => '1',
			),
			array(
				'test_condition_name' => '7.2 でスクリプトが登録済み => "1"',
				'wp_version'          => '7.2',
				'register_script'     => true,
				'expected'            => '1',
			),
			array(
				'test_condition_name' => 'スクリプトが登録されていない => 何も渡さない（null）',
				'wp_version'          => '7.2',
				'register_script'     => false,
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$GLOBALS['wp_version'] = $case['wp_version']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_bloginfo( 'version' ) が返すバージョンをケースごとに差し替えるため。

			// ケースごとに読み込み状態を空にしてから、必要ならテスト用のスクリプトを登録する。
			$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ケースごとにスクリプトレジストリを初期化するため。
			if ( $case['register_script'] ) {
				wp_register_script( $handle, 'https://example.com/test.js', array(), '1.0.0', true );
			}

			Inner_Blocks_Template::localize_editor_script( $handle );

			$this->assertSame( $case['expected'], $this->get_localized_value( $handle ), $case['test_condition_name'] );
		}
	}

	/**
	 * wp_localize_script() が出力する `var vkbmInnerBlocksTemplate = {...};` から、判定の値を取り出す。
	 *
	 * @param string $handle スクリプトのハンドル。
	 * @return string|null 渡された値。何も渡されていない場合は null。
	 */
	private function get_localized_value( string $handle ): ?string {
		$localized = $GLOBALS['wp_scripts']->get_data( $handle, 'data' );
		if ( ! is_string( $localized ) || 1 !== preg_match( '/var vkbmInnerBlocksTemplate = (\{.*\});/s', $localized, $matches ) ) {
			return null;
		}

		$data = json_decode( $matches[1], true );

		return is_array( $data ) && array_key_exists( 'blockTypeTemplateSupported', $data ) ? $data['blockTypeTemplateSupported'] : null;
	}
}
