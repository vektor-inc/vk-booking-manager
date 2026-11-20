<?php
/**
 * #1092: サービスメニュー検索ブロックのエディタ用スクリプトへ、InnerBlocks の template の出し分けに使う値が渡ることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Blocks\Menu_Search_Block;
use WP_Scripts;
use WP_UnitTestCase;

/**
 * Menu_Search_Block のエディタ用スクリプトへの受け渡しのテスト。
 *
 * @group blocks
 */
class Menu_Search_Block_Editor_Script_Test extends WP_UnitTestCase {
	/**
	 * サービスメニュー検索ブロックのエディタ用スクリプトのハンドル（block.json の editorScript から WordPress が作る名前）。
	 */
	private const EDITOR_SCRIPT_HANDLE = 'vk-booking-manager-menu-search-editor-script';

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
	 * Menu_Search_Block::register() のテスト。
	 *
	 * ブロックエディタの読み込み時に、エディタ用スクリプトへ値を渡す処理が登録されること。
	 *
	 * @return void
	 */
	public function test_register(): void {
		$block = new Menu_Search_Block();
		$block->register();

		// テストの配列（フック名 => 登録される優先度）。
		$test_cases = array(
			array(
				'test_condition_name' => 'ブロックエディタのアセット読み込み時に localize_editor_script() が登録される',
				'hook'                => 'enqueue_block_editor_assets',
				'callback'            => 'localize_editor_script',
				'expected'            => 10,
			),
			array(
				'test_condition_name' => 'init でブロックの登録が登録される（今までどおり）',
				'hook'                => 'init',
				'callback'            => 'register_blocks',
				'expected'            => 10,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], has_action( $case['hook'], array( $block, $case['callback'] ) ), $case['test_condition_name'] );
		}
	}

	/**
	 * Menu_Search_Block::localize_editor_script() のテスト。
	 *
	 * サービスメニュー検索ブロックのエディタ用スクリプトへ、判定の値（true は "1"、false は ""）を渡すこと。
	 *
	 * @return void
	 */
	public function test_localize_editor_script(): void {
		// Gutenberg プラグインが有効だと本体のバージョンでは判定しないため、このテストは成り立たない。
		if ( defined( 'GUTENBERG_VERSION' ) ) {
			$this->markTestSkipped( 'Gutenberg プラグインが有効な環境では、本体のバージョンの差し替えで判定を確認できないためスキップする。' );
		}

		// テストの配列（WordPress のバージョン・スクリプトの登録有無 => JS に渡る値）。
		$test_cases = array(
			array(
				'test_condition_name' => '7.1.5 でエディタ用スクリプトが登録済み => 空文字',
				'wp_version'          => '7.1.5',
				'register_script'     => true,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '7.2 でエディタ用スクリプトが登録済み => "1"',
				'wp_version'          => '7.2',
				'register_script'     => true,
				'expected'            => '1',
			),
			array(
				'test_condition_name' => 'エディタ用スクリプトが登録されていない => 何も渡さない（null）',
				'wp_version'          => '7.2',
				'register_script'     => false,
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$GLOBALS['wp_version'] = $case['wp_version']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_bloginfo( 'version' ) が返すバージョンをケースごとに差し替えるため。

			// ケースごとに読み込み状態を空にしてから、必要ならエディタ用スクリプトと同じハンドルで登録する。
			$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ケースごとにスクリプトレジストリを初期化するため。
			if ( $case['register_script'] ) {
				wp_register_script( self::EDITOR_SCRIPT_HANDLE, 'https://example.com/index.js', array(), '1.0.0', true );
			}

			( new Menu_Search_Block() )->localize_editor_script();

			$this->assertSame( $case['expected'], $this->get_localized_value(), $case['test_condition_name'] );
		}
	}

	/**
	 * エディタ用スクリプトに出力される `var vkbmInnerBlocksTemplate = {...};` から、判定の値を取り出す。
	 *
	 * @return string|null 渡された値。何も渡されていない場合は null。
	 */
	private function get_localized_value(): ?string {
		$localized = $GLOBALS['wp_scripts']->get_data( self::EDITOR_SCRIPT_HANDLE, 'data' );
		if ( ! is_string( $localized ) || 1 !== preg_match( '/var vkbmInnerBlocksTemplate = (\{.*\});/s', $localized, $matches ) ) {
			return null;
		}

		$data = json_decode( $matches[1], true );

		return is_array( $data ) && array_key_exists( 'blockTypeTemplateSupported', $data ) ? $data['blockTypeTemplateSupported'] : null;
	}
}
