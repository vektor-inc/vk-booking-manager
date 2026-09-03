<?php
/**
 * 「予約に進む」ボタンブロックを登録・描画する。
 *
 * プラン詳細ページなどに配置し、現在のプラン（または手動指定したプラン）の
 * 予約ページへのリンクボタンを出力する動的ブロック。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Block;
use WP_Post;
use function admin_url;
use function current_user_can;
use function esc_html;
use function esc_url;
use function generate_block_asset_handle;
use function sanitize_key;
use function wp_set_script_translations;

/**
 * 「予約に進む」ボタンブロックを登録・描画する。
 */
class Reservation_Button_Block {
	private const METADATA_PATH = 'build/blocks/reservation-button';

	/**
	 * 予約ボタンの共通描画ヘルパー。
	 *
	 * @var Reservation_Button_Renderer
	 */
	private $reservation_button_renderer;

	/**
	 * ブロックが登録済みかどうか。
	 *
	 * @var bool
	 */
	private static bool $block_registered = false;

	/**
	 * コンストラクタ。
	 *
	 * @param Reservation_Button_Renderer|null $reservation_button_renderer 予約ボタンの共通描画ヘルパー。
	 */
	public function __construct( ?Reservation_Button_Renderer $reservation_button_renderer = null ) {
		$this->reservation_button_renderer = $reservation_button_renderer ?? new Reservation_Button_Renderer( new Settings_Repository() );
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_editor_script' ) );
	}

	/**
	 * ブロックメタデータを登録する。
	 */
	public function register_block(): void {
		// テスト環境での二重登録を防ぐ。
		if ( self::$block_registered ) {
			return;
		}

		$metadata_path = VKBM_PLUGIN_DIR_PATH . self::METADATA_PATH;

		register_block_type_from_metadata(
			$metadata_path,
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
		$this->register_script_translations( 'vk-booking-manager/reservation-button', array( 'editorScript' ) );

		self::$block_registered = true;
	}

	/**
	 * 予約ページ未設定の警告リンク用に、基本設定画面のURLをエディタへ渡す。
	 *
	 * @return void
	 */
	public function localize_editor_script(): void {
		$handle = generate_block_asset_handle( 'vk-booking-manager/reservation-button', 'editorScript' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'vkbmReservationButtonBlock',
			array(
				// 基本設定（予約ページURL設定）画面のURL。
				'providerSettingsUrl'   => admin_url( 'admin.php?page=vkbm-provider-settings' ),
				// 予約ページが設定済みかどうか（エディタ側の警告表示に使う）。
				'hasReservationPageUrl' => '' !== $this->reservation_button_renderer->get_reservation_page_url(),
				// 既定のボタンラベル（基本設定の値、未設定なら標準ラベル）。
				'defaultReserveLabel'   => $this->reservation_button_renderer->get_default_reserve_label(),
			)
		);
	}

	/**
	 * ブロックを描画する。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @param string              $content    保存済みコンテンツ（未使用）。
	 * @param WP_Block|null       $block      ブロックインスタンス（未使用）。
	 *
	 * @return string ボタンHTML。出力しない場合は空文字。
	 */
	public function render_block( array $attributes, string $content = '', ?WP_Block $block = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// 予約ページが未設定ならフロントではボタンを出さない（死にリンク防止）。
		if ( '' === $this->reservation_button_renderer->get_reservation_page_url() ) {
			return '';
		}

		// リンク先プランを決定する。手動指定があればそれを優先し、無ければ現在の投稿を参照する。
		$post = $this->resolve_target_post( $attributes );

		// プランが特定できない場合は、menu_id なしで予約ページ（選択から始まる初期状態）へ送る。
		if ( ! $post instanceof WP_Post ) {
			return $this->render_fallback_button( $attributes );
		}

		// ボタンラベルはブロック属性で上書き可能。空なら共通ヘルパーの既定ラベルを使う。
		$label = trim( (string) ( $attributes['label'] ?? '' ) );

		$button = $this->reservation_button_renderer->render_button(
			$post,
			array(
				'label'             => $label,
				'extra_classes'     => 'vkbm-reservation-button__link',
				// アクセシブルネームに対象プラン名を含める。
				'accessible_suffix' => (string) get_the_title( $post ),
			)
		);

		if ( '' === $button ) {
			return '';
		}

		return $this->wrap_button( $button, $attributes );
	}

	/**
	 * リンク先のサービスメニュー投稿を解決する。
	 *
	 * 属性 menuId が指定されていればそれを、無ければ現在の投稿IDを使う。
	 * 対象がサービスメニュー投稿でなければ null を返す。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @return WP_Post|null
	 */
	private function resolve_target_post( array $attributes ): ?WP_Post {
		$manual_id = isset( $attributes['menuId'] ) ? (int) $attributes['menuId'] : 0;

		if ( $manual_id > 0 ) {
			return $this->get_visible_service_menu( $manual_id );
		}

		// 手動指定が無い場合は現在の投稿を自動参照する。
		$current_id = (int) get_the_ID();
		if ( $current_id <= 0 ) {
			$current_id = (int) get_queried_object_id();
		}

		if ( $current_id <= 0 ) {
			return null;
		}

		return $this->get_visible_service_menu( $current_id );
	}

	/**
	 * 指定IDがサービスメニュー投稿で、かつ閲覧可能なら投稿を返す。
	 *
	 * @param int $post_id 投稿ID。
	 * @return WP_Post|null
	 */
	private function get_visible_service_menu( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Service_Menu_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		// 非公開プランは閲覧権限がある場合のみ対象とする。
		if ( 'publish' !== $post->post_status && ! current_user_can( Capabilities::VIEW_SERVICE_MENUS ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * プランを特定できない場合のフォールバックボタンを描画する。
	 *
	 * menu_id なしで予約ページ（選択から始まる初期状態）へ送る。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @return string
	 */
	private function render_fallback_button( array $attributes ): string {
		$reservation_url = $this->reservation_button_renderer->get_reservation_page_url();
		if ( '' === $reservation_url ) {
			return '';
		}

		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $this->reservation_button_renderer->get_default_reserve_label();
		}

		// プランが特定できないフォールバックでは遷移先がプラン選択から始まる予約ページ
		// であることを、読み上げ用のアクセシブルネームで補足する。
		$aria_label = sprintf(
			/* translators: %s: button label */
			__( '%s (select a plan on the reservation page)', 'vk-booking-manager' ),
			$label
		);

		$button = sprintf(
			'<a class="vkbm-button vkbm-button__sm vkbm-reservation-button__link" href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $reservation_url ),
			esc_attr( $aria_label ),
			esc_html( $label )
		);

		return $this->wrap_button( $button, $attributes );
	}

	/**
	 * ボタンをラッパー要素で包む。
	 *
	 * コアの align / spacing / anchor などの supports を反映するため
	 * get_block_wrapper_attributes() を使う。
	 *
	 * @param string              $button     ボタンHTML。
	 * @param array<string,mixed> $attributes ブロック属性（未使用だが将来拡張用）。
	 * @return string
	 */
	private function wrap_button( string $button, array $attributes ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'vkbm-buttons vkbm-reservation-button' ) )
			: 'class="vkbm-buttons vkbm-reservation-button"';

		return sprintf( '<div %1$s>%2$s</div>', $wrapper_attributes, $button );
	}

	/**
	 * プラグインの languages ディレクトリからブロックスクリプトの翻訳を登録する。
	 *
	 * @param string            $block_name ブロック名。
	 * @param array<int,string> $fields     スクリプトのフィールド。
	 * @return void
	 */
	private function register_script_translations( string $block_name, array $fields ): void {
		if ( ! function_exists( 'wp_set_script_translations' ) ) {
			return;
		}

		$translation_path = VKBM_PLUGIN_DIR_PATH . 'languages';

		foreach ( $fields as $field ) {
			$handle = $this->resolve_script_handle( $block_name, $field );
			if ( $handle ) {
				wp_set_script_translations( $handle, 'vk-booking-manager', $translation_path );
			}
		}
	}

	/**
	 * コアのヘルパーに依存せずブロックスクリプトのハンドルを解決する。
	 *
	 * @param string $block_name ブロック名。
	 * @param string $field      スクリプトのフィールド。
	 * @return string
	 */
	private function resolve_script_handle( string $block_name, string $field ): string {
		if ( function_exists( 'generate_block_asset_handle' ) ) {
			return (string) generate_block_asset_handle( $block_name, $field );
		}

		$base = str_replace( '/', '-', $block_name );

		switch ( $field ) {
			case 'editorScript':
				return $base . '-editor-script';
			case 'viewScript':
				return $base . '-view-script';
			case 'script':
				return $base . '-script';
			default:
				return $base . '-' . sanitize_key( $field );
		}
	}
}
