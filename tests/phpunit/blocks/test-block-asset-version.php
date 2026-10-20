<?php
/**
 * issue #854: ブロックの CSS の ?ver= がプラグインのバージョンと連動すること、JS の ?ver= が *.asset.php のハッシュのままであることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Blocks\Block_Asset_Version;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * ブロックのアセットのバージョンのテスト。
 *
 * @group blocks
 */
class Block_Asset_Version_Test extends WP_UnitTestCase {
	/**
	 * Block_Asset_Version::filter_block_type_metadata() のテスト。
	 */
	public function test_filter_block_type_metadata(): void {
		$filter = new Block_Asset_Version();

		$test_cases = array(
			array(
				'test_condition_name' => 'このプラグインのブロックで version が無い場合 => プラグインのバージョンが入る',
				'metadata'            => array( 'name' => 'vk-booking-manager/reservation' ),
				'expected'            => array(
					'name'    => 'vk-booking-manager/reservation',
					'version' => VKBM_VERSION,
				),
			),
			array(
				'test_condition_name' => 'このプラグインのブロックで version が古い値の場合 => プラグインのバージョンに置き換わる',
				'metadata'            => array(
					'name'    => 'vk-booking-manager/menu-loop',
					'version' => '0.0.1',
				),
				'expected'            => array(
					'name'    => 'vk-booking-manager/menu-loop',
					'version' => VKBM_VERSION,
				),
			),
			array(
				'test_condition_name' => '他プラグインのブロックの場合 => 変更されない',
				'metadata'            => array( 'name' => 'other-plugin/block' ),
				'expected'            => array( 'name' => 'other-plugin/block' ),
			),
			array(
				'test_condition_name' => '名前が似ているだけの他プラグインのブロックの場合 => 変更されない',
				'metadata'            => array( 'name' => 'vk-booking-manager-extra/block' ),
				'expected'            => array( 'name' => 'vk-booking-manager-extra/block' ),
			),
			array(
				'test_condition_name' => 'コアのブロックの場合 => 変更されない',
				'metadata'            => array( 'name' => 'core/paragraph' ),
				'expected'            => array( 'name' => 'core/paragraph' ),
			),
			array(
				'test_condition_name' => 'name が無い場合 => 変更されない',
				'metadata'            => array( 'title' => 'No name' ),
				'expected'            => array( 'title' => 'No name' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$filter->filter_block_type_metadata( $case['metadata'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * src/blocks/ 配下の全 block.json が、block_type_metadata フィルターを通るとプラグインのバージョンになること。
	 *
	 * SCRIPT_DEBUG が有効な環境では WordPress が CSS の ver にファイルの更新時刻を使うため、
	 * 登録済みのハンドルの ver では検証できない。そのため登録前のメタデータで検証する。
	 */
	public function test_block_json_version_via_filter(): void {
		$block_json_files = glob( VKBM_PLUGIN_DIR_PATH . 'src/blocks/*/block.json' );
		$this->assertNotEmpty( $block_json_files, 'src/blocks/ 配下に block.json が見つからない' );

		// ブロックを追加したときにこのテストの対象から漏れないよう、登録済みのブロック数と突き合わせる。
		$this->assertCount(
			count( $this->get_plugin_block_types() ),
			$block_json_files,
			'src/blocks/ 配下の block.json の数と、登録済みのこのプラグインのブロック数が一致する'
		);

		foreach ( $block_json_files as $block_json_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルの block.json の読み込み。
			$metadata = json_decode( (string) file_get_contents( $block_json_file ), true );
			$this->assertIsArray( $metadata, $block_json_file . ' を読み込める' );

			// 登録時（register_block_type_from_metadata）と同じく、フィルターチェーン全体を通す。
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress コアのフィルター。
			$filtered = apply_filters( 'block_type_metadata', $metadata );

			$this->assertSame( VKBM_VERSION, $filtered['version'] ?? null, $metadata['name'] . ' の version がプラグインのバージョンと一致する' );
		}
	}

	/**
	 * 登録済みのこのプラグインの全ブロックで、ファイルから登録された CSS の ver が期待どおりになること。
	 *
	 * 通常はプラグインのバージョン（更新すると ver が変わり、CDN・ブラウザのキャッシュが取り直される）。
	 * SCRIPT_DEBUG が有効な場合は、WordPress がファイルの更新時刻を使う。
	 * 共通 CSS（vkbm-components など）は PHP 側で登録しており、このテストの対象外。
	 */
	public function test_registered_block_style_versions(): void {
		$blocks = $this->get_plugin_block_types();
		$this->assertNotEmpty( $blocks, 'このプラグインのブロックが 1 つも登録されていない（build/blocks が無い可能性）' );

		$checked_handles = 0;
		foreach ( $blocks as $block_name => $block_type ) {
			$handles = array_merge( (array) $block_type->style_handles, (array) $block_type->editor_style_handles );
			foreach ( $handles as $handle ) {
				// block.json の file: 指定から自動生成されたハンドルのみを対象にする。
				if ( 0 !== strpos( $handle, 'vk-booking-manager-' ) ) {
					continue;
				}

				$this->assertTrue( wp_style_is( $handle, 'registered' ), $block_name . ' の ' . $handle . ' が登録されている' );
				$registered = wp_styles()->registered[ $handle ];

				// SCRIPT_DEBUG が有効なときは、WordPress が ver にファイルの更新時刻を使う。
				$expected = VKBM_VERSION;
				if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
					$expected = filemtime( str_replace( VKBM_PLUGIN_DIR_URL, VKBM_PLUGIN_DIR_PATH, $registered->src ) );
				}

				$this->assertSame(
					$expected,
					$registered->ver,
					$block_name . ' の ' . $handle . ' の ver が期待値と一致する'
				);
				++$checked_handles;
			}
		}

		$this->assertGreaterThan( 0, $checked_handles, 'file: 指定の CSS ハンドルが 1 つも確認できていない' );
	}

	/**
	 * 登録済みのこのプラグインの全ブロックで、JS の ver が WordPress 本体のバージョンではないこと。
	 *
	 * これは回帰確認用のテストで、issue #854 の修正前から通る。
	 * JS の ver は *.asset.php のハッシュ（ファイルの中身が変わると変わる）が使われるため、
	 * もともと本体のバージョンにならず、プラグインのバージョンとも一致しない。
	 * 将来 *.asset.php が使われなくなって本体のバージョンに戻ってしまう事故を防ぐため、
	 * 本体のバージョンや空になっていないことだけを確認する。
	 */
	public function test_registered_block_script_versions(): void {
		$blocks = $this->get_plugin_block_types();
		$this->assertNotEmpty( $blocks, 'このプラグインのブロックが 1 つも登録されていない（build/blocks が無い可能性）' );

		$wp_version      = get_bloginfo( 'version' );
		$checked_handles = 0;
		foreach ( $blocks as $block_name => $block_type ) {
			$handles = array_merge(
				(array) $block_type->script_handles,
				(array) $block_type->editor_script_handles,
				(array) $block_type->view_script_handles
			);
			foreach ( $handles as $handle ) {
				if ( 0 !== strpos( $handle, 'vk-booking-manager-' ) ) {
					continue;
				}

				$version = wp_scripts()->registered[ $handle ]->ver;
				$this->assertNotEmpty( $version, $block_name . ' の ' . $handle . ' の ver が空でない' );
				$this->assertNotSame( $wp_version, $version, $block_name . ' の ' . $handle . ' の ver が WordPress 本体のバージョンではない' );
				++$checked_handles;
			}
		}

		$this->assertGreaterThan( 0, $checked_handles, 'file: 指定の JS ハンドルが 1 つも確認できていない' );
	}

	/**
	 * このプラグインのブロックの登録情報を返す。
	 *
	 * @return array<string, \WP_Block_Type> ブロック名をキーにした登録情報。
	 */
	private function get_plugin_block_types(): array {
		$block_types = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block_type ) {
			if ( 0 === strpos( $name, Block_Asset_Version::BLOCK_NAME_PREFIX ) ) {
				$block_types[ $name ] = $block_type;
			}
		}

		return $block_types;
	}
}
