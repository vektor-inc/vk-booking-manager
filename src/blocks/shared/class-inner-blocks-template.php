<?php
/**
 * InnerBlocks（子ブロックを置く領域）の template の渡し先を出し分けるための、WordPress のバージョン判定。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function get_bloginfo;
use function wp_localize_script;
use function wp_script_is;

/**
 * InnerBlocks の template の渡し先を出し分けるための判定と、その結果のエディタ用スクリプトへの受け渡し。
 *
 * ブロックエディタの部品（Gutenberg 23.8 以降。WordPress 本体では 7.2 以降）から、InnerBlocks の template プロップが
 * 非推奨になり（渡すとコンソールに警告が出る）、代わりに registerBlockType() に渡すブロックの設定の template を読むようになった。
 * それより前のエディタは設定の template を読まないため、JS 側（src/blocks/shared/inner-blocks-template.js）で
 * この判定の結果を見て渡し先を切り替える。
 */
class Inner_Blocks_Template {
	/**
	 * エディタ用スクリプトへ渡す JS のグローバル変数名。
	 *
	 * JS 側（src/blocks/shared/inner-blocks-template.js）がこの名前で読むため、片方だけ変えないこと。
	 */
	public const SCRIPT_OBJECT_NAME = 'vkbmInnerBlocksTemplate';

	/**
	 * ブロックの設定（registerBlockType() に渡す設定）の template を、今のサイトのエディタが読むかを判定する。
	 *
	 * 本体のバージョンと、有効な Gutenberg プラグインの版（GUTENBERG_VERSION。無ければ null）を
	 * resolve_block_type_template_supported() に渡して判定する。
	 *
	 * @return bool 設定の template を読むエディタなら true、読まないエディタなら false。
	 */
	public static function is_block_type_template_supported(): bool {
		$gutenberg_version = defined( 'GUTENBERG_VERSION' ) ? (string) GUTENBERG_VERSION : null;

		return self::resolve_block_type_template_supported( (string) get_bloginfo( 'version' ), $gutenberg_version );
	}

	/**
	 * 本体のバージョンと Gutenberg プラグインの版から、設定の template をエディタが読むかを判定する。
	 *
	 * Gutenberg プラグインが有効なときは、エディタの部品がプラグイン側に置き換わるため、本体のバージョンではなくプラグインの版で判定する。
	 * 本体が 7.2 でも 23.7 以前のプラグインが残っていると設定の template を読まず、子ブロックが 1 つも並ばなくなるため。
	 * 版の比較は、ベータ版・RC 版も同じ版として扱えるよう、'-alpha' を付けた版と比べる（23.8.0-rc.1 は 23.8 以上、7.2-RC1 は 7.2 以上になる）。
	 * 版が読めないとき（空文字など）は false になり、今までの書き方に倒れる。
	 *
	 * @param string      $wp_version        WordPress 本体のバージョン（例: '7.2'、'7.2-RC1-65565'）。
	 * @param string|null $gutenberg_version 有効な Gutenberg プラグインの版（例: '23.8.0'）。プラグインが無ければ null。
	 * @return bool Gutenberg プラグインが有効なら 23.8 以上で true、無ければ本体が 7.2 以上で true。それ以外は false。
	 */
	public static function resolve_block_type_template_supported( string $wp_version, ?string $gutenberg_version ): bool {
		if ( null !== $gutenberg_version ) {
			return version_compare( $gutenberg_version, '23.8-alpha', '>=' );
		}

		return version_compare( $wp_version, '7.2-alpha', '>=' );
	}

	/**
	 * 判定の結果を、ブロックのエディタ用スクリプトへ渡す。
	 *
	 * wp_localize_script() で渡すため、スクリプトより前に出力され、registerBlockType() の実行前に JS から読める。
	 * スクリプトが登録されていないとき（ビルド前など）は何もしない。
	 *
	 * @param string $handle ブロックのエディタ用スクリプトのハンドル。
	 * @return void
	 */
	public static function localize_editor_script( string $handle ): void {
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			self::SCRIPT_OBJECT_NAME,
			array(
				// wp_localize_script() は真偽値を文字列にする（true は "1"、false は ""）。JS 側はこの文字列を前提に判定している。
				'blockTypeTemplateSupported' => self::is_block_type_template_supported(),
			)
		);
	}
}
