<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Assets;

use WP_UnitTestCase;

/**
 * ブロックエディターのキャンバス複製の再発防止テスト（issue #659）。
 *
 * ブロックエディターは、外側の文書のスタイルシートのうち「id が付いている」「id が `wp-` で始まらない」
 * 「セレクターに `.editor-styles-wrapper` か `.wp-block` を含む」ものを、キャンバス（iframe）の末尾へ
 * 複製する。複製されると各ブロック自身の CSS を上書きし、ブロックの見た目が変わる。
 * そのため VKBM のバンドルのセレクターに、これらの文字列を含めてはならない。
 *
 * ビルド成果物（build/assets/css/*.min.css）があればそれを検査し、無ければ
 * ソースの scss（コメントを除去したもの）を検査する。
 *
 * 対象に vkbm-frontend / vkbm-components も含める。この 2 つはキャンバス内にも block.json 経由で
 * 同じ id が先に入るため実害は出にくいが、含めても追加コストが無く、より厳しい側に倒しておく。
 *
 * @group assets
 */
class Editor_Canvas_Clone_Guard_Test extends WP_UnitTestCase {
	/**
	 * バンドル名 => ビルド後ファイルと元の scss（bin/build-css-bundles.js と揃える）。
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public function data_bundles(): array {
		return array(
			'vkbm-variables'    => array( 'vkbm-variables.min.css', array( 'variables.scss' ) ),
			'vkbm-admin-notice' => array( 'vkbm-admin-notice.min.css', array( 'admin-notice.scss' ) ),
			'vkbm-frontend'     => array( 'vkbm-frontend.min.css', array( 'common.scss' ) ),
			'vkbm-components'   => array( 'vkbm-components.min.css', array( 'alert.scss', 'utility.scss', 'buttons.scss' ) ),
			'vkbm-auth'         => array( 'vkbm-auth.min.css', array( 'auth-forms.scss' ) ),
			'vkbm-editor'       => array( 'vkbm-editor.min.css', array( 'admin-editor-fixes.scss' ) ),
			'vkbm-admin'        => array( 'vkbm-admin.min.css', array( 'common.scss', 'utility.scss', 'buttons.scss', 'alert.scss', 'auth-forms.scss', 'admin-core.scss', 'admin-table.scss' ) ),
		);
	}

	/**
	 * @dataProvider data_bundles
	 *
	 * @param string             $built   ビルド後の CSS ファイル名。
	 * @param array<int, string> $sources 元の scss ファイル名。
	 */
	public function test_bundle_has_no_canvas_cloned_selector( string $built, array $sources ): void {
		$root       = dirname( __DIR__, 3 );
		$built_path = $root . '/build/assets/css/' . $built;

		if ( is_readable( $built_path ) ) {
			$css = (string) file_get_contents( $built_path );
		} else {
			$css = '';
			foreach ( $sources as $source ) {
				$path = $root . '/assets/scss/' . $source;
				$this->assertFileExists( $path );
				$css .= (string) file_get_contents( $path );
			}
			// コメントは検査対象から除く（説明文で `.wp-block` に触れてよいようにする）。
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			$css = (string) preg_replace( '#^\s*//.*$#m', '', $css );
		}

		$this->assertDoesNotMatchRegularExpression(
			'/\.wp-block|\.editor-styles-wrapper/',
			$css,
			$built . ' に `.wp-block` / `.editor-styles-wrapper` を含むセレクターがあると、ブロックエディターのキャンバスへ複製され、ブロックの CSS を上書きします。'
		);
	}
}
