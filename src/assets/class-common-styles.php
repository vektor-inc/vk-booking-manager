<?php
/**
 * Enqueues common CSS for the whole plugin.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;

/**
 * Enqueues common CSS for the whole plugin.
 */
class Common_Styles {
	public const FRONTEND_HANDLE   = 'vkbm-frontend';
	public const COMPONENTS_HANDLE = 'vkbm-components';
	public const AUTH_HANDLE       = 'vkbm-auth';
	public const ADMIN_HANDLE      = 'vkbm-admin';
	public const NOTICE_HANDLE     = 'vkbm-admin-notice';
	public const EDITOR_HANDLE     = 'vkbm-editor';
	public const VARIABLES_HANDLE  = 'vkbm-variables';

	/**
	 * フッターの文字色が未設定のとき、まず候補にする文字色（濃いグレー）。
	 */
	public const FOOTER_TEXT_COLOR_PREFERRED = '#333333';

	/**
	 * 文字色に求めるコントラスト比の下限（WCAG 2.1 AA の本文の基準）。
	 */
	public const MIN_TEXT_CONTRAST_RATIO = 4.5;

	/**
	 * カスタムプロパティのインライン CSS を既に付与したかどうか。
	 *
	 * ブロックエディター画面では `admin_enqueue_scripts`（本クラスの
	 * `enqueue_admin()`）と `enqueue_block_assets`（コアの
	 * `wp_common_block_scripts_and_styles()` 経由、および iframe 内アセット収集用の
	 * `_wp_get_iframed_editor_assets()` 経由）の両方から `apply_custom_properties()`
	 * が呼ばれ得る。`_wp_get_iframed_editor_assets()` は `$wp_styles->registered` の
	 * `_WP_Dependency` オブジェクト自体を共有し続けるため、ガードなしに複数回
	 * `wp_add_inline_style()` を呼ぶと同じ `:root{…}` が重複出力される。このフラグで
	 * 1リクエストにつき1回だけ付与する。
	 *
	 * @var bool
	 */
	private bool $custom_properties_applied = false;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// block.json の style が参照するハンドルは、ブロック登録（init の既定優先度）より前に登録しておく。
		add_action( 'init', array( $this, 'register_styles' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_variables' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * Enqueue common styles for frontend.
	 *
	 * `enqueue_variables()` を直接も呼ぶ（`enqueue_admin()` と対称にする）。
	 * 通常は `wp_enqueue_scripts` → コアの `wp_common_block_scripts_and_styles()`
	 * 経由で `enqueue_block_assets` が発火し `enqueue_variables()` が呼ばれるが、
	 * それが「コアの特定フックが常に発火する」という前提に依存している点は、今回の
	 * 管理画面の退行（`enqueue_block_assets` が発火しない画面がある）と同じ構造。
	 * `apply_custom_properties()` の冪等ガードにより、二重に呼ばれても実害はない。
	 */
	public function enqueue_frontend(): void {
		$this->register_styles();
		wp_enqueue_style( self::FRONTEND_HANDLE );
		$this->enqueue_variables();
	}

	/**
	 * :root カスタムプロパティ（既定値＋設定値）の実体を持つスタイルシートを読み込み、
	 * 設定値をインライン CSS として付与する。
	 *
	 * `enqueue_block_assets` は、`--vkbm--*` カスタムプロパティを必要とする
	 * すべての描画経路で発火する唯一のフック。フロント（`wp_enqueue_scripts` →
	 * コアの `wp_common_block_scripts_and_styles()` 経由）、管理画面
	 * （`admin_enqueue_scripts` → 同じコア関数経由）に加えて、ブロックエディターの
	 * キャンバス iframe（コアの `_wp_get_iframed_editor_assets()` がここで直接
	 * `do_action( 'enqueue_block_assets' )` を呼ぶ。`enqueue_block_editor_assets`
	 * では iframe 内には届かない）でも発火する。:root の宣言自体を
	 * bin/build-css-bundles.js のバンドル定義でこのハンドル1本に集約し、
	 * 設定値のインライン CSS もここへ一元化することで、他のスタイルシート
	 * （ブロック側の style-index.css、ショートコード描画時に後から enqueue される
	 * vkbm-auth 等）の読み込み順に関係なく、常に設定値が最終的に勝つ
	 * （issue #420）。
	 */
	public function enqueue_variables(): void {
		$this->register_styles();
		wp_enqueue_style( self::VARIABLES_HANDLE );
		$this->apply_custom_properties( self::VARIABLES_HANDLE );
	}

	/**
	 * Enqueue common styles for admin.
	 *
	 * 全管理画面で呼ばれるため、ここでは `:root` カスタムプロパティ（`enqueue_variables()`）
	 * だけを読み込む。管理画面用バンドル（`vkbm-admin`、約48KB）は VKBM の画面でのみ必要なので、
	 * 各画面のクラスが自分の `admin_enqueue_scripts` で `wp_enqueue_style( ADMIN_HANDLE )` する
	 * （issue #659）。全画面に出る通知用の CSS は `vkbm-admin-notice`（Setup_Notices が読み込む）。
	 *
	 * 通常の管理画面（VKBM の基本設定・シフト管理・スタイルガイド等）では
	 * コアの `wp_common_block_scripts_and_styles()` が
	 * `is_admin() && ! wp_should_load_block_editor_scripts_and_styles()` で
	 * 早期リターンし、`enqueue_block_assets` が発火しない
	 * （`wp_should_load_block_editor_scripts_and_styles()` は実質
	 * `$current_screen->is_block_editor()`）。そのため `enqueue_block_assets` だけに
	 * 任せると、設定値のインライン CSS（プライマリカラー・角丸）が付与されない
	 * （issue #420 差し戻し）。ブロックエディター画面では `enqueue_block_assets` も
	 * 発火するため二重に呼ばれるが、`apply_custom_properties()` 側のガードで無害化する。
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin( string $hook_suffix ): void {
		unset( $hook_suffix );

		$this->enqueue_variables();
	}

	/**
	 * Enqueue common styles for block editor.
	 */
	public function enqueue_editor(): void {
		$this->register_styles();
		wp_enqueue_style( self::EDITOR_HANDLE );
	}

	/**
	 * Register bundled stylesheets.
	 */
	public function register_styles(): void {
		$default_version = defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null;
		$base            = VKBM_PLUGIN_DIR_PATH . 'build/assets/css/';

		// vkbm-variables は :root カスタムプロパティの実体を持つ唯一のバンドル。
		// 他バンドルはこれに依存させ（$deps）、常にこれより後に読み込まれるようにする。
		$this->register_style( self::VARIABLES_HANDLE, 'vkbm-variables.min.css', array(), $base, $default_version );

		// 依存関係はバンドルごとに異なる。
		// - vkbm-frontend / vkbm-admin: :root（vkbm-variables）のみ。
		// - vkbm-components: ボタン・アラート・ユーティリティなど、ブロックと認証フォームで共有する部品。
		// box-sizing などの共通指定（vkbm-frontend）も一緒に読み込ませる。各ブロックの block.json の
		// style と vkbm-auth の $deps から参照され、ブロックが無いページには読み込まれない（issue #660）。
		// - vkbm-auth: 認証フォーム固有の部分のみ。部品は vkbm-components から取る。
		// - vkbm-admin-notice: 全管理画面に出る通知専用。vkbm-admin は VKBM の画面だけで読み込む（issue #659）。
		// - vkbm-editor: ブロックエディター用の補正のみ。部品の CSS は含めず、vkbm-auth 経由で vkbm-components に依存して
		// 各ブロックの CSS より前に1回だけ読み込ませる。vkbm-admin に依存させると、依存関係をたどってすべてのブロック
		// エディターに vkbm-admin が読み込まれ、VKBM の画面だけで読み込む方針（issue #659）に反するため依存させない。
		$variables_deps = array( self::VARIABLES_HANDLE );
		$map            = array(
			self::FRONTEND_HANDLE   => array( 'vkbm-frontend.min.css', $variables_deps ),
			self::COMPONENTS_HANDLE => array( 'vkbm-components.min.css', array( self::VARIABLES_HANDLE, self::FRONTEND_HANDLE ) ),
			self::AUTH_HANDLE       => array( 'vkbm-auth.min.css', array( self::VARIABLES_HANDLE, self::COMPONENTS_HANDLE ) ),
			self::ADMIN_HANDLE      => array( 'vkbm-admin.min.css', $variables_deps ),
			self::EDITOR_HANDLE     => array( 'vkbm-editor.min.css', array( self::VARIABLES_HANDLE, self::AUTH_HANDLE ) ),
			self::NOTICE_HANDLE     => array( 'vkbm-admin-notice.min.css', $variables_deps ),
		);

		foreach ( $map as $handle => $definition ) {
			$this->register_style( $handle, $definition[0], $definition[1], $base, $default_version );
		}
	}

	/**
	 * 未登録の場合のみ、バンドルされた1つのスタイルシートを登録する。
	 *
	 * @param string             $handle          スタイルハンドル。
	 * @param string             $file            build/assets/css/ 配下のファイル名。
	 * @param array<int, string> $deps            依存ハンドルの配列。
	 * @param string             $base            build/assets/css/ の絶対パス。
	 * @param string|null        $default_version ファイルの mtime が取得できない場合のバージョン。
	 */
	private function register_style( string $handle, string $file, array $deps, string $base, ?string $default_version ): void {
		if ( wp_style_is( $handle, 'registered' ) ) {
			return;
		}

		$path    = $base . $file;
		$version = file_exists( $path ) ? (string) filemtime( $path ) : $default_version;

		wp_register_style(
			$handle,
			VKBM_PLUGIN_DIR_URL . 'build/assets/css/' . $file,
			$deps,
			$version
		);

		// vkbm-components は小さい（約5KB）ため、ファイルがあるときは HTML への直接埋め込み
		// （インライン化）の対象にして、予約ページの CSS リクエストを減らす（issue #660）。
		// コアは path の指定されたスタイルを、サイズが styles_inline_size_limit 以下のときだけ埋め込む。
		if ( self::COMPONENTS_HANDLE === $handle && file_exists( $path ) ) {
			wp_style_add_data( $handle, 'path', $path );
		}
	}

	/**
	 * Attach custom CSS variables based on provider settings.
	 *
	 * @param string $handle Target style handle.
	 */
	private function apply_custom_properties( string $handle ): void {
		// 1リクエストにつき1回だけ付与する（クラス冒頭のプロパティ doc 参照）。
		if ( $this->custom_properties_applied ) {
			return;
		}

		$inline = $this->get_custom_css();
		if ( '' === $inline ) {
			return;
		}

		// wp_add_inline_style() はハンドル未登録時などに false を返す（第三者の
		// wp_deregister_style() 等）。戻り値を見ずにフラグを立てると、失敗した
		// リクエストでもガードが働いてしまい、そのリクエストでは二度と付与されない。
		if ( wp_add_inline_style( $handle, $inline ) ) {
			$this->custom_properties_applied = true;
		}
	}

	/**
	 * Get custom CSS properties.
	 *
	 * @return array<string, string>
	 */
	private function get_custom_properties(): array {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();

		$properties = array();

		// 色は保存時だけでなく出力直前にも sanitize_hex_color() で検証し、空や不正な値は出力しない
		// （保存済みの値が直接書き換えられても、インライン CSS に任意の文字列が入らないようにするため）。
		$color_properties = array(
			'design_primary_color'            => '--vkbm--color--primary',
			'design_menu_card_catch_color'    => '--vkbm--color--menu-card-catch',
			'design_menu_card_price_color'    => '--vkbm--color--menu-card-price',
			'design_reservation_button_color' => '--vkbm--color--reservation-action',
		);
		foreach ( $color_properties as $setting_key => $property_name ) {
			$color = isset( $settings[ $setting_key ] ) ? sanitize_hex_color( (string) $settings[ $setting_key ] ) : null;
			if ( is_string( $color ) && '' !== $color ) {
				$properties[ $property_name ] = $color;
			}
		}

		$properties = array_merge( $properties, self::get_footer_color_properties( $settings ) );
		$properties = array_merge( $properties, self::get_menu_heading_color_properties( $settings ) );

		$radius_raw = $settings['design_radius_md'] ?? null;
		if ( '' !== $radius_raw && null !== $radius_raw ) {
			// 上限クランプの参照元を Settings_Sanitizer::DESIGN_RADIUS_MD_MAX 一本にする。
			// 上限追加（issue #420）以前に保存された超過値（例: 50px）を持つサイトが
			// 再保存するまで超過値のまま描画され続けないよう、描画側でもクランプする。
			$radius_md                        = max( 0, (int) $radius_raw );
			$radius_md                        = min( Settings_Sanitizer::DESIGN_RADIUS_MD_MAX, $radius_md );
			$properties['--vkbm--radius--md'] = sprintf( '%dpx', $radius_md );
		}

		return $properties;
	}

	/**
	 * フッターの背景色・文字色・区切り線の色の CSS 変数を組み立てる（#825）。
	 *
	 * 文字色が未設定で背景色だけがあるときは、背景色から読みやすい文字色を選ぶ。
	 * 区切り線の色は、文字色（手動・自動のどちらでも）が決まったときだけ、その色の 20% の濃さで出力する。
	 * どちらの色も未設定・不正なら空の配列を返し、CSS 側の既定値のままにする。
	 *
	 * @param array<string, mixed> $settings 保存済みの設定。
	 * @return array<string, string> CSS 変数名 => 値。
	 */
	public static function get_footer_color_properties( array $settings ): array {
		// 出力する値は、必ず sanitize_hex_color() を通した色から作る。
		$background_color = self::sanitize_hex_color_or_empty( $settings['design_footer_background_color'] ?? '' );
		$text_color       = self::sanitize_hex_color_or_empty( $settings['design_footer_text_color'] ?? '' );

		// 文字色が未設定なら、背景色から自動で決める。
		if ( '' === $text_color && '' !== $background_color ) {
			$text_color = self::get_readable_text_color( $background_color );
		}

		$properties = array();
		if ( '' !== $background_color ) {
			$properties['--vkbm--color--footer-bg'] = $background_color;
		}
		if ( '' !== $text_color ) {
			$rgb                                        = self::hex_to_rgb( $text_color );
			$properties['--vkbm--color--footer-text']   = $text_color;
			$properties['--vkbm--color--footer-border'] = sprintf( 'rgb(%d %d %d / 20%%)', $rgb[0], $rgb[1], $rgb[2] );
		}

		return $properties;
	}

	/**
	 * メニューループブロックのグループ見出しと、予約ブロックのメニュー詳細の見出しの背景色・文字色の CSS 変数を組み立てる（#864）。
	 *
	 * 「見出しをフッターと同じ色にする」がオンのときだけ、フッターに出力する色と同じ値を返す。
	 * オフのとき、またはフッターの色が未設定のときは空の配列を返し、見出しは SCSS 側の既定の色のままにする。
	 *
	 * @param array<string, mixed> $settings 保存済みの設定。
	 * @return array<string, string> CSS 変数名 => 値。
	 */
	public static function get_menu_heading_color_properties( array $settings ): array {
		if ( empty( $settings['design_menu_heading_use_footer_color'] ) ) {
			return array();
		}

		// フッターの色（文字色の自動決定を含む）をそのまま見出しの色に割り当てる。
		$footer_properties = self::get_footer_color_properties( $settings );
		$property_map      = array(
			'--vkbm--color--footer-bg'   => '--vkbm--color--menu-heading-bg',
			'--vkbm--color--footer-text' => '--vkbm--color--menu-heading-text',
		);

		$properties = array();
		foreach ( $property_map as $footer_property => $heading_property ) {
			if ( isset( $footer_properties[ $footer_property ] ) ) {
				$properties[ $heading_property ] = $footer_properties[ $footer_property ];
			}
		}

		return $properties;
	}

	/**
	 * 背景色に対して読みやすい文字色を返す。
	 *
	 * 濃いグレー（#333333）で WCAG のコントラスト比 4.5:1 以上になればそれを使う。
	 * 届かなければ、黒（#000000）と白（#ffffff）のうちコントラスト比が高い方を使う。
	 *
	 * @param string $background_color 背景色（sanitize_hex_color() を通した #rgb / #rrggbb）。
	 * @return string 文字色（#rrggbb）。
	 */
	public static function get_readable_text_color( string $background_color ): string {
		if ( self::get_contrast_ratio( self::FOOTER_TEXT_COLOR_PREFERRED, $background_color ) >= self::MIN_TEXT_CONTRAST_RATIO ) {
			return self::FOOTER_TEXT_COLOR_PREFERRED;
		}

		$black_ratio = self::get_contrast_ratio( '#000000', $background_color );
		$white_ratio = self::get_contrast_ratio( '#ffffff', $background_color );

		return $black_ratio >= $white_ratio ? '#000000' : '#ffffff';
	}

	/**
	 * 2色の WCAG のコントラスト比（1〜21）を返す。
	 *
	 * @param string $color_a 色（#rgb / #rrggbb）。
	 * @param string $color_b 色（#rgb / #rrggbb）。
	 * @return float コントラスト比。
	 */
	public static function get_contrast_ratio( string $color_a, string $color_b ): float {
		$luminance_a = self::get_relative_luminance( $color_a );
		$luminance_b = self::get_relative_luminance( $color_b );

		return ( max( $luminance_a, $luminance_b ) + 0.05 ) / ( min( $luminance_a, $luminance_b ) + 0.05 );
	}

	/**
	 * WCAG の相対輝度（0〜1）を返す。
	 *
	 * @param string $color 色（#rgb / #rrggbb）。
	 * @return float 相対輝度。
	 */
	private static function get_relative_luminance( string $color ): float {
		// sRGB の各成分を線形の値に戻してから、人の目の感じ方に合わせた重みで足す。
		$linear = array_map(
			static function ( int $channel ): float {
				$value = $channel / 255;
				return $value <= 0.04045 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			self::hex_to_rgb( $color )
		);

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * 16進の色を RGB の各成分（0〜255）に分ける。3桁の色（#abc）は6桁に広げて扱う。
	 *
	 * @param string $color 色（#rgb / #rrggbb）。
	 * @return array{0: int, 1: int, 2: int} R・G・B。
	 */
	private static function hex_to_rgb( string $color ): array {
		$hex = ltrim( $color, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * sanitize_hex_color() を通した色を返す。空・不正な値は空文字にする。
	 *
	 * @param mixed $value 保存済みの値。
	 * @return string 色（#rgb / #rrggbb）または空文字。
	 */
	private static function sanitize_hex_color_or_empty( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$color = sanitize_hex_color( $value );

		return is_string( $color ) ? $color : '';
	}

	/**
	 * Build the inline CSS string for custom properties.
	 *
	 * @return string
	 */
	private function get_custom_css(): string {
		$custom_properties = $this->get_custom_properties();
		if ( array() === $custom_properties ) {
			return '';
		}

		$declarations = array();
		foreach ( $custom_properties as $name => $value ) {
			$declarations[] = sprintf( '%s: %s;', $name, $value );
		}

		return ':root{' . implode( '', $declarations ) . '}';
	}
}
