<?php
/**
 * 表示中のサービス1件分のカードを表示するブロックを登録・描画する。
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
use WP_Block;
use WP_Post;
use function current_user_can;
use function generate_block_asset_handle;
use function get_post;
use function get_queried_object;
use function sanitize_key;
use function wp_enqueue_block_style;
use function wp_set_script_translations;

/**
 * 表示中のサービス1件分のカードを表示するブロック。
 *
 * 既存の menu-loop の単一カード描画ロジック（render_menu_card）を再利用し、
 * サービス詳細ページ上で「表示中のサービス」のカードを表示する。
 */
class Menu_Card_Block {
	/**
	 * ブロックメタデータのビルド成果物パス（プラグインルート相対）。
	 *
	 * @var string
	 */
	private const METADATA_PATH = 'build/blocks/menu-card';

	/**
	 * ブロックが登録済みかどうか。
	 *
	 * テスト環境などで init が複数回実行された際の二重登録を防ぐためのフラグ。
	 *
	 * @var bool
	 */
	private static bool $block_registered = false;

	/**
	 * 単一カード描画を担う menu-loop ブロックのインスタンス。
	 *
	 * @var Menu_Loop_Block
	 */
	private Menu_Loop_Block $menu_loop_block;

	/**
	 * コンストラクタ。
	 *
	 * @param Menu_Loop_Block $menu_loop_block 単一カード描画を再利用するための menu-loop ブロック。
	 */
	public function __construct( Menu_Loop_Block $menu_loop_block ) {
		$this->menu_loop_block = $menu_loop_block;
	}

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * ブロックメタデータを登録する。
	 *
	 * @return void
	 */
	public function register_block(): void {
		// テスト環境などで init が複数回走った場合の二重登録を防ぐ。
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

		// カードの見た目を司る menu-loop の style を、このブロック描画時に併せて読み込むよう登録する。
		$this->enqueue_shared_menu_loop_style();

		$this->register_script_translations( 'vk-booking-manager/menu-card', array( 'editorScript' ) );

		self::$block_registered = true;
	}

	/**
	 * カードの見た目を司る menu-loop の style を menu-card 描画時に読み込むよう登録する。
	 *
	 * menu-card は render_menu_card() を再利用して menu-loop と同じ vkbm-menu-loop__*
	 * クラスのマークアップを出力する。menu-card 自身の style には .vkbm-menu-card の
	 * ラッパー分しか含まれないため、menu-loop の style-index.css を読み込まないと
	 * フロントでカードのスタイルが当たらない。
	 *
	 * menu-card はサービス詳細ページ（vkbm_service_menu）のテンプレートに配置される
	 * こともあり、投稿コンテンツしか走査しない has_block() では取りこぼすため、
	 * ブロック描画を起点に読み込む wp_enqueue_block_style() を使う。
	 *
	 * @return void
	 */
	private function enqueue_shared_menu_loop_style(): void {
		if ( ! function_exists( 'wp_enqueue_block_style' ) ) {
			return;
		}

		wp_enqueue_block_style(
			'vk-booking-manager/menu-card',
			array(
				// 予約ブロックと同じハンドル名を使い、両方が読み込まれても重複登録・重複出力にならないようにする。
				'handle' => 'vkbm-shared-menu-card',
				'src'    => VKBM_PLUGIN_DIR_URL . 'build/blocks/menu-loop/style-index.css',
				'ver'    => defined( 'VKBM_VERSION' ) ? VKBM_VERSION : null,
				'path'   => VKBM_PLUGIN_DIR_PATH . 'build/blocks/menu-loop/style-index.css',
			)
		);
	}

	/**
	 * ブロックを描画する。
	 *
	 * 表示するサービスは、属性 selectedMenuId（>0）が指定されていればそれを優先し、
	 * 指定が無ければ表示中ページの queried object（フォールバックは get_post）から
	 * vkbm_service_menu の投稿IDを自動取得する。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @param string              $content    保存済みコンテンツ（未使用）。
	 * @param WP_Block|null       $block      ブロックインスタンス（未使用）。
	 * @return string
	 */
	public function render_block( array $attributes, string $content = '', ?WP_Block $block = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$menu_id = $this->resolve_menu_id( $attributes );

		// 表示対象のサービスIDが取得できない場合は、閲覧者には何も出さず、
		// 編集者・管理者にのみ注意文を表示する。
		if ( $menu_id <= 0 ) {
			return $this->render_editor_notice(
				__( 'No service is being displayed. Place this block on a service detail page, or specify a service manually.', 'vk-booking-manager' )
			);
		}

		$overrides = $this->build_overrides( $attributes );

		$html = $this->menu_loop_block->render_menu_card( $menu_id, $overrides );

		// render_menu_card は投稿タイプ不一致・不在・非公開かつ権限なしの場合に空文字を返す。
		// その場合も閲覧者には何も出さず、編集者・管理者にのみ注意文を表示する。
		if ( '' === $html ) {
			return $this->render_editor_notice(
				__( 'The service to display could not be found.', 'vk-booking-manager' )
			);
		}

		return sprintf(
			'<div class="vkbm-menu-card wp-block-vk-booking-manager-menu-card">%s</div>',
			$html
		);
	}

	/**
	 * 表示対象のサービス投稿IDを決定する。
	 *
	 * 属性 selectedMenuId が 0 より大きければ手動指定として優先する。
	 * 指定が無い場合は表示中ページの queried object を確認し、
	 * vkbm_service_menu の投稿であればそのIDを返す。
	 * queried object で取得できない場合は get_post() にフォールバックする。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @return int 解決できなかった場合は 0。
	 */
	private function resolve_menu_id( array $attributes ): int {
		// 手動指定（selectedMenuId）が優先。
		$selected = isset( $attributes['selectedMenuId'] ) ? (int) $attributes['selectedMenuId'] : 0;
		if ( $selected > 0 ) {
			return $selected;
		}

		// 表示中ページの queried object から自動取得する。
		$queried = get_queried_object();
		if ( $queried instanceof WP_Post && Service_Menu_Post_Type::POST_TYPE === $queried->post_type ) {
			return (int) $queried->ID;
		}

		// フォールバック: ループ内などの現在の投稿から取得する。
		$post = get_post();
		if ( $post instanceof WP_Post && Service_Menu_Post_Type::POST_TYPE === $post->post_type ) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * 属性から render_menu_card へ渡す overrides を組み立てる。
	 *
	 * 表示項目トグルは menu-loop を踏襲しつつ、サービス詳細ページ用に
	 * 「詳細を見る」ボタンは常に非表示固定とし、予約ボタンは既定で表示とする。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @return array<string,mixed>
	 */
	private function build_overrides( array $attributes ): array {
		$overrides = array(
			'showImage'                => $this->attr_bool( $attributes, 'showImage', true ),
			'showExcerpt'              => $this->attr_bool( $attributes, 'showExcerpt', true ),
			'showMeta'                 => $this->attr_bool( $attributes, 'showMeta', true ),
			'showCategories'           => $this->attr_bool( $attributes, 'showCategories', true ),
			// サービス詳細ページに置くブロックのため、「詳細を見る」ボタンは常に非表示固定。
			'showDetailButton'         => false,
			'showReserveButton'        => $this->attr_bool( $attributes, 'showReserveButton', true ),
			// 単一カードは常にカード表示。
			'displayMode'              => 'card',
			// menu-card では予約ボタンを独立トグル(showReserveButton)で制御するため、
			// メタ情報(showMeta)を非表示にしても予約ボタンを残す。
			// menu-loop はこのフラグを渡さないため従来挙動のまま。
			'actionsIndependentOfMeta' => true,
		);

		// 予約ボタンのラベルは基本設定（menu_loop_reserve_button_label）で一元管理されるため、
		// ブロック側でラベルを override する経路は持たない。

		return $overrides;
	}

	/**
	 * 属性から真偽値を取り出す。未設定時は既定値を返す。
	 *
	 * @param array<string,mixed> $attributes ブロック属性。
	 * @param string              $key        属性キー。
	 * @param bool                $default    既定値。
	 * @return bool
	 */
	private function attr_bool( array $attributes, string $key, bool $default ): bool {
		if ( ! array_key_exists( $key, $attributes ) ) {
			return $default;
		}

		return (bool) $attributes[ $key ];
	}

	/**
	 * 編集者・管理者向けの注意文を描画する。
	 *
	 * サービスを表示できない場合に、編集できる権限を持つユーザーにのみ
	 * 設置ミスへの気付きを促すメッセージを表示する。閲覧者には何も表示しない。
	 *
	 * @param string $message 注意文。
	 * @return string
	 */
	private function render_editor_notice( string $message ): string {
		// 閲覧者には何も出さない。サービスメニューを編集できる権限を持つユーザーにのみ表示する。
		if ( ! current_user_can( Capabilities::VIEW_SERVICE_MENUS ) ) {
			return '';
		}

		return sprintf(
			'<div class="vkbm-menu-card wp-block-vk-booking-manager-menu-card vkbm-menu-card--notice"><p class="vkbm-alert vkbm-alert__warning text-center">%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * プラグインの languages ディレクトリからブロックスクリプトの翻訳を登録する。
	 *
	 * @param string            $block_name ブロック名。
	 * @param array<int,string> $fields     スクリプトフィールド。
	 * @return void
	 */
	private function register_script_translations( string $block_name, array $fields ): void {
		if ( ! function_exists( 'wp_set_script_translations' ) ) {
			return;
		}

		$translation_path = VKBM_PLUGIN_DIR_PATH . 'languages';

		foreach ( $fields as $field ) {
			$handle = $this->resolve_script_handle( $block_name, $field );
			if ( '' !== $handle ) {
				wp_set_script_translations( $handle, 'vk-booking-manager', $translation_path );
			}
		}
	}

	/**
	 * コアのヘルパーに依存せずブロックスクリプトのハンドルを解決する。
	 *
	 * @param string $block_name ブロック名。
	 * @param string $field      スクリプトフィールド。
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
