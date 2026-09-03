<?php
/**
 * 予約ボタンの共通描画ヘルパー。
 *
 * メニューループブロックの予約ボタンと、予約に進むボタンブロックで
 * 同じリンク生成・無効ボタン描画ロジックを共有するためのクラス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use function add_query_arg;
use function esc_attr;
use function esc_html;
use function esc_url;
use function esc_url_raw;
use function home_url;
use function is_ssl;
use function str_starts_with;

/**
 * 予約ボタンの共通描画ヘルパー。
 */
class Reservation_Button_Renderer {

	/**
	 * オンライン予約不可を表すメタキー。
	 */
	public const META_ONLINE_UNAVAILABLE = '_vkbm_online_unavailable';

	/**
	 * 予約ボタンの既定ラベルを参照する基本設定のキー。
	 */
	public const SETTING_RESERVE_LABEL = 'menu_loop_reserve_button_label';

	/**
	 * 予約ページURLを参照する基本設定のキー。
	 */
	public const SETTING_RESERVATION_PAGE_URL = 'reservation_page_url';

	/**
	 * 基本設定リポジトリ。
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * 基本設定のキャッシュ。
	 *
	 * @var array<string,mixed>|null
	 */
	private $provider_settings = null;

	/**
	 * コンストラクタ。
	 *
	 * @param Settings_Repository|null $settings_repository 基本設定リポジトリ。
	 */
	public function __construct( ?Settings_Repository $settings_repository = null ) {
		$this->settings_repository = $settings_repository ?? new Settings_Repository();
	}

	/**
	 * 予約ページのURL（クエリなし）を取得する。
	 *
	 * @return string 設定済みなら正規化済みURL、未設定なら空文字。
	 */
	public function get_reservation_page_url(): string {
		$settings = $this->get_provider_settings();
		$url      = isset( $settings[ self::SETTING_RESERVATION_PAGE_URL ] ) ? (string) $settings[ self::SETTING_RESERVATION_PAGE_URL ] : '';

		return $this->normalize_reservation_page_url( $url );
	}

	/**
	 * 指定プランの予約ページリンク（menu_id 付き）を組み立てる。
	 *
	 * @param WP_Post $post サービスメニュー投稿。
	 * @return string 予約ページURL。予約ページ未設定時は空文字。
	 */
	public function build_reservation_link( WP_Post $post ): string {
		$reservation_url = $this->get_reservation_page_url();

		if ( '' === $reservation_url ) {
			return '';
		}

		return add_query_arg(
			array(
				'menu_id' => (string) $post->ID,
			),
			$reservation_url
		);
	}

	/**
	 * 予約ボタンの既定ラベルを取得する。
	 *
	 * 基本設定にラベルが設定されていればそれを、無ければ標準ラベルを返す。
	 *
	 * @return string ボタンラベル。
	 */
	public function get_default_reserve_label(): string {
		$settings = $this->get_provider_settings();
		$label    = trim( (string) ( $settings[ self::SETTING_RESERVE_LABEL ] ?? '' ) );

		if ( '' === $label ) {
			$label = __( 'Proceed to Reservation', 'vk-booking-manager' );
		}

		return $label;
	}

	/**
	 * 指定プランがオンライン予約不可かどうかを返す。
	 *
	 * @param WP_Post $post サービスメニュー投稿。
	 * @return bool オンライン予約不可なら true。
	 */
	public function is_online_unavailable( WP_Post $post ): bool {
		return '1' === (string) get_post_meta( $post->ID, self::META_ONLINE_UNAVAILABLE, true );
	}

	/**
	 * 予約ボタン1つ分のHTMLを生成する。
	 *
	 * オンライン予約不可の場合は無効状態の span を、可能な場合はリンクを返す。
	 * 予約ページが未設定でリンク先が決められない場合は空文字を返す。
	 *
	 * @param WP_Post              $post  サービスメニュー投稿。
	 * @param array<string,string> $args {
	 *     描画オプション。
	 *
	 *     @type string $label             ボタンラベル。空なら既定ラベルを使う。
	 *     @type string $extra_classes     追加するクラス名（スペース区切り）。
	 *     @type string $fallback_url      予約ページ未設定時に使う代替URL。
	 *     @type string $accessible_suffix アクセシブルネームに追記するプラン名等。
	 * }
	 * @return string ボタンHTML。生成できない場合は空文字。
	 */
	public function render_button( WP_Post $post, array $args = array() ): string {
		$label = trim( (string) ( $args['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $this->get_default_reserve_label();
		}

		$extra_classes = trim( (string) ( $args['extra_classes'] ?? '' ) );
		$base_classes  = 'vkbm-button vkbm-button__sm';
		$class_attr    = '' !== $extra_classes ? $base_classes . ' ' . $extra_classes : $base_classes;

		// アクセシブルネーム（読み上げ用ラベル）にプラン名等を含める。
		$accessible_suffix = trim( (string) ( $args['accessible_suffix'] ?? '' ) );
		$aria_label        = '' !== $accessible_suffix
			? sprintf( '%1$s: %2$s', $label, $accessible_suffix )
			: '';

		// 先に予約リンク（menu_id 付き）を解決する。未設定時は代替URLにフォールバック。
		// リンク先が決められない場合は、オンライン予約可否にかかわらずボタンを出さない
		// （予約ページ未設定時はボタンを隠すという要件・docblock に合わせる）。
		$reserve_url = $this->build_reservation_link( $post );
		if ( '' === $reserve_url ) {
			$reserve_url = trim( (string) ( $args['fallback_url'] ?? '' ) );
		}

		if ( '' === $reserve_url ) {
			return '';
		}

		// オンライン予約が無効の場合はグレーアウトした非活性ボタンを表示する。
		if ( $this->is_online_unavailable( $post ) ) {
			// 無効理由（オンライン予約不可）。
			$disabled_reason = __( 'This menu does not accept online reservations.', 'vk-booking-manager' );

			// 無効理由とプラン名を、視覚的に隠したテキスト（screen-reader-text）として
			// 要素内に含める。これにより role/aria-label に頼らずとも、要素のアクセシブル
			// ネーム（＝可視ラベル＋隠しテキスト）にプラン名・無効理由が含まれ、
			// SR 利用者へ確実に届く。title はホバー時の補助として残す。
			$hidden_parts = array_filter(
				array( $accessible_suffix, $disabled_reason ),
				static function ( string $part ): bool {
					return '' !== trim( $part );
				}
			);
			$hidden_text  = '' !== implode( '', $hidden_parts )
				? sprintf( '<span class="screen-reader-text"> %s</span>', esc_html( implode( ' ', $hidden_parts ) ) )
				: '';

			// role="link" は「たどれるリンク」を意味し aria-disabled と衝突するため付けない。
			// 非活性であることは aria-disabled="true" のみで表現する。
			return sprintf(
				'<span class="%1$s is-disabled" aria-disabled="true" title="%2$s">%3$s%4$s</span>',
				esc_attr( $class_attr ),
				esc_attr( $disabled_reason ),
				esc_html( $label ),
				$hidden_text
			);
		}

		$aria_label_attr = '' !== $aria_label ? sprintf( ' aria-label="%s"', esc_attr( $aria_label ) ) : '';

		return sprintf(
			'<a class="%1$s" href="%2$s"%4$s>%3$s</a>',
			esc_attr( $class_attr ),
			esc_url( $reserve_url ),
			esc_html( $label ),
			$aria_label_attr
		);
	}

	/**
	 * 基本設定をリポジトリから取得する（リクエスト単位でキャッシュ）。
	 *
	 * @return array<string,mixed>
	 */
	private function get_provider_settings(): array {
		if ( null === $this->provider_settings ) {
			$settings                = $this->settings_repository->get_settings();
			$this->provider_settings = is_array( $settings ) ? $settings : array();
		}

		return $this->provider_settings;
	}

	/**
	 * 予約ページURLをサイトに対して正規化する。
	 *
	 * グローバルヘルパー vkbm_normalize_reservation_page_url があればそれを使い、
	 * 無い場合（単体テスト等）は同等のロジックでフォールバックする。
	 *
	 * @param string $url 生のURL。
	 * @return string 正規化済みURL。空なら空文字。
	 */
	private function normalize_reservation_page_url( string $url ): string {
		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			return vkbm_normalize_reservation_page_url( $url );
		}

		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' ) ) {
			return esc_url_raw( $url );
		}

		if ( str_starts_with( $url, '//' ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			return esc_url_raw( $scheme . $url );
		}

		if ( ! str_starts_with( $url, '/' ) ) {
			$url = '/' . $url;
		}

		return esc_url_raw( home_url( $url ) );
	}
}
