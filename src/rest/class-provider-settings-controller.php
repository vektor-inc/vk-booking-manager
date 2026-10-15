<?php
/**
 * REST controller for provider settings.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Response;
use WP_REST_Server;
use function __;
use function add_action;
use function current_datetime;
use function esc_url_raw;
use function home_url;
use function is_ssl;
use function str_starts_with;
use function wp_get_attachment_image_url;
use function wp_login_url;
use function wp_registration_url;

/**
 * Exposes provider settings required on the frontend.
 */
class Provider_Settings_Controller {
	private const NAMESPACE = 'vkbm/v1';

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings_repository Settings repository.
	 */
	public function __construct( Settings_Repository $settings_repository ) {
		$this->settings_repository = $settings_repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/provider-settings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				// Publicly readable: provider settings exposed here (labels, public URLs, currency, etc.)
				// are intentionally available to anonymous visitors of the reservation form.
				// 公開情報のため誰でも参照可能。予約フォーム表示に必要なラベル・通貨等のみを返す。
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return settings used on the frontend.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings = $this->settings_repository->get_settings();
		// 無料版では保存値ではなく既定値を返す。Pro 版は従来どおり保存値をそのまま返す。
		if ( vkbm_should_use_default_resource_labels() ) {
			$resource_label_singular = vkbm_get_resource_label_singular();
			$resource_label_plural   = vkbm_get_resource_label_plural();
		} else {
			$resource_label_singular = isset( $settings['resource_label_singular'] ) ? (string) $settings['resource_label_singular'] : __( 'Staff', 'vk-booking-manager' );
			$resource_label_plural   = isset( $settings['resource_label_plural'] ) ? (string) $settings['resource_label_plural'] : __( 'Staff', 'vk-booking-manager' );
		}

		// 指名関連ラベルを取得する（ヘルパー関数がフォールバックを処理）。
		$no_nomination_label  = vkbm_get_no_nomination_label();
		$nomination_fee_label = vkbm_get_nomination_fee_label();
		$provider_name        = isset( $settings['provider_name'] ) ? (string) $settings['provider_name'] : '';
		$provider_logo_id     = isset( $settings['provider_logo_id'] ) ? (int) $settings['provider_logo_id'] : 0;
		$reservation          = $this->normalize_reservation_page_url(
			isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : ''
		);
		$show_menu_search     = ! empty( $settings['reservation_show_menu_search'] );
		// #431: 「リソースタグ検索」。タクソノミー自体が Pro 版でしか登録されないため、
		// 万一 Free 版で設定値が残っていても taxonomy_exists() で実効的に無効化する。
		$resource_tag_search_enabled = ! empty( $settings['resource_tag_search_enabled'] )
			&& taxonomy_exists( \VKBookingManager\Resources\Resource_Tag_Taxonomy::TAXONOMY );
		$show_menu_list              = ! empty( $settings['reservation_show_menu_list'] );
		$menu_list_display_mode      = isset( $settings['reservation_menu_list_display_mode'] ) ? sanitize_key( (string) $settings['reservation_menu_list_display_mode'] ) : 'card';
		if ( ! in_array( $menu_list_display_mode, array( 'card', 'text' ), true ) ) {
			$menu_list_display_mode = 'card';
		}

		// 「日付で絞り込む」。宿泊のサイトはチェックイン日・チェックアウト日・泊数の3欄、それ以外は日付の1欄で絞り込む。
		$date_search_enabled = ! empty( $settings['reservation_date_search_enabled'] );
		$date_search_stay    = \VKBookingManager\Availability\Menu_Date_Filter::is_stay_mode();

		$show_provider_logo = ! empty( $settings['reservation_show_provider_logo'] );
		$show_provider_name = ! empty( $settings['reservation_show_provider_name'] );
		$show_footer        = ! empty( $settings['reservation_show_footer'] );
		$currency_symbol    = VKBM_Helper::get_currency_symbol();
		$tax_label_text     = isset( $settings['tax_label_text'] ) ? (string) $settings['tax_label_text'] : '';
		// Fetch provider logo URL for frontend display. / 予約画面表示用にロゴURLを取得します.
		// 横幅の指定がないときは従来どおり medium。指定があるときは拡大でぼやけないよう元画像を使う。
		$provider_logo_width = isset( $settings['design_header_logo_width'] ) ? absint( $settings['design_header_logo_width'] ) : 0;
		$provider_logo_width = min( Settings_Sanitizer::DESIGN_HEADER_LOGO_WIDTH_MAX, $provider_logo_width );
		$provider_logo_size  = $provider_logo_width > 0 ? 'full' : 'medium';
		$provider_logo_url   = $provider_logo_id > 0 ? wp_get_attachment_image_url( $provider_logo_id, $provider_logo_size ) : '';
		if ( ! is_string( $provider_logo_url ) ) {
			$provider_logo_url = '';
		}
		$provider_logo_url   = '' !== $provider_logo_url ? esc_url_raw( $provider_logo_url ) : '';
		$cancellation_policy = isset( $settings['provider_cancellation_policy'] ) ? (string) $settings['provider_cancellation_policy'] : '';
		$terms_of_service    = isset( $settings['provider_terms_of_service'] ) ? (string) $settings['provider_terms_of_service'] : '';
		$payment_method      = isset( $settings['provider_payment_method'] ) ? (string) $settings['provider_payment_method'] : '';
		$closed_day_label    = isset( $settings['closed_day_label'] ) ? (string) $settings['closed_day_label'] : '';
		// Use the shared helper to apply fallback when the setting is empty.
		// 設定が空の場合にデフォルト値を適用するため共通ヘルパーを使用する。
		$duration_label = vkbm_get_duration_label();
		// 数量の見出し・単位は実効値に解決して返す。
		// 単位ヘルパーは null（未設定）をロケール既定へ解決するため、フロントは受け取った値をそのまま使える。
		$guests_count_label = vkbm_get_guests_count_label();
		$guests_unit_label  = vkbm_get_guests_unit_label();
		// #391: このエンドポイントはサイト全体の設定を返すもので、特定のメニューという文脈を持たない
		// （フロントは複数のメニューを横断して同じレスポンスを参照する）。そのためここではメニュー単位判定
		// （is_nomination_enabled_for_menu）は使わず、従来どおりサイト全体の指名機能スイッチを返す。
		// メニュー単位の実際の判定は、選択中メニューの meta（_vkbm_disable_nomination）と
		// この staff_enabled を掛け合わせてフロント側（app.js）で行う。
		$nomination_enabled = Staff_Editor::is_nomination_enabled();

		// 無料版ではデフォルトスタッフのIDを取得する.
		// 解決ロジックは Resource_Post_Type::get_default_staff_id() に集約している（issue #465）。
		$default_staff_id = 0;
		if ( ! Staff_Editor::is_enabled() ) {
			$default_staff_id = Resource_Post_Type::get_default_staff_id();
		}

		$response_data = array(
			'tax_enabled'                        => true,
			'tax_rate'                           => 0.0,
			'staff_enabled'                      => $nomination_enabled,
			'default_staff_id'                   => $default_staff_id,
			'resource_label_singular'            => $resource_label_singular,
			'resource_label_plural'              => $resource_label_plural,
			'resource_tag_empty_message'         => vkbm_get_resource_tag_empty_message(),
			'provider_name'                      => $provider_name,
			'provider_logo_url'                  => $provider_logo_url,
			// ヘッダーのロゴ画像の横幅（px）。未指定は 0（現状の表示幅のまま）。
			'provider_logo_width'                => $provider_logo_width,
			// 絞り込み検索のデザイン（plain / framed）。
			'reservation_menu_search_style'      => self::resolve_menu_search_style( $settings ),
			'reservation_page_url'               => $reservation,
			'reservation_show_menu_search'       => $show_menu_search,
			'resource_tag_search_enabled'        => $resource_tag_search_enabled,
			'reservation_date_search_enabled'    => $date_search_enabled,
			// 日付で絞り込む欄が宿泊の日程（チェックイン日・チェックアウト日・泊数）か、日付1つか。
			'reservation_date_search_mode'       => $date_search_stay ? 'stay' : 'date',
			// 宿泊の日程で選べる最大の泊数（1回に予約できる上限）。
			'lodging_max_nights'                 => \VKBookingManager\Availability\Night_Strategy::MAX_NIGHTS,
			// 日付欄の「今日」をブラウザではなくサイトのタイムゾーンで決めるための日付（Y-m-d）。
			'site_today'                         => current_datetime()->format( 'Y-m-d' ),
			'reservation_show_menu_list'         => $show_menu_list,
			'reservation_menu_list_display_mode' => $menu_list_display_mode,
			'reservation_show_provider_logo'     => $show_provider_logo,
			'reservation_show_provider_name'     => $show_provider_name,
			'reservation_show_footer'            => $show_footer,
			// #633: フッター用の店舗情報・プライバシーポリシー。フッターが無効なときは公開しない。
			'reservation_footer'                 => $show_footer ? self::build_footer_data( $settings ) : null,
			'currency_symbol'                    => $currency_symbol,
			'tax_label_text'                     => $tax_label_text,
			'cancellation_policy'                => $cancellation_policy,
			'terms_of_service'                   => $terms_of_service,
			'payment_method'                     => $payment_method,
			// 仮予約の完了画面の案内文（実効値。設定が空なら標準文に解決済み）。
			'booking_complete_message_pending'   => $this->settings_repository->resolve_booking_complete_message_pending( $settings ),
			'no_nomination_label'                => $no_nomination_label,
			'nomination_fee_label'               => $nomination_fee_label,
			'closed_day_label'                   => $closed_day_label,
			'duration_label'                     => $duration_label,
			'other_conditions_label'             => vkbm_get_other_conditions_label(),
			// 数量の見出し（実効値）と単位（実効値。null はロケール既定に解決済み）。
			'guests_count_label'                 => $guests_count_label,
			'guests_unit_label'                  => $guests_unit_label,
		);

		/**
		 * プロバイダー設定 REST レスポンスにフィールドを追加する拡張ポイント。
		 * 決済プラグインなどがフロントエンド設定（Stripe 公開可能キー等）を公開するために使用する。
		 *
		 * このレスポンスは予約画面の表示に使うため、認証なしで誰でも取得できる。
		 * 秘密キー・Webhook シークレット・個人情報など、公開してはいけない値を追加しないこと。
		 *
		 * @param array $response_data 現在のレスポンスデータ。
		 * @param array $settings      リポジトリから取得した設定値そのもの。公開してはいけない値も含まれるため、
		 *                             レスポンスに載せる値は必要なものだけを選んで追加すること。
		 */
		$response_data = apply_filters( 'vkbm_provider_settings_response', $response_data, $settings );
		return new WP_REST_Response( $response_data );
	}

	/**
	 * 予約ブロックのフッターに表示する店舗情報とプライバシーポリシーを組み立てる。
	 *
	 * 事業者情報は基本設定の既存値をそのまま使う（前後の空白のみ除去）。
	 * プライバシーポリシーは会員登録画面と同じ判定で、URL 指定モードは URL が、
	 * 本文モードは本文が入力されているときだけ値を返す。
	 * ウェブサイトURLは、予約ページURLと異なるときだけ返す（同じ場合・未入力の場合は空文字）。
	 *
	 * @param array $settings Settings_Repository から取得した設定値。
	 * @return array{provider_name:string,address:string,phone:string,business_hours:string,website_url:string,privacy_policy:array{mode:string,url:string,content:string}}
	 */
	public static function build_footer_data( array $settings ): array {
		// 基本設定の店舗情報を文字列として取り出す。
		$read_text = static function ( string $key ) use ( $settings ): string {
			return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
		};

		// プライバシーポリシーの公開方法を判定する（不正値は「表示しない」）。
		$privacy_mode = sanitize_key( $read_text( 'provider_privacy_policy_mode' ) );
		if ( ! in_array( $privacy_mode, array( 'none', 'url', 'content' ), true ) ) {
			$privacy_mode = 'none';
		}
		$privacy_url     = esc_url_raw( $read_text( 'provider_privacy_policy_url' ) );
		$privacy_content = $read_text( 'provider_privacy_policy_content' );

		$website_url = self::resolve_footer_website_url(
			$read_text( 'provider_website_url' ),
			$read_text( 'reservation_page_url' )
		);

		return array(
			'provider_name'  => $read_text( 'provider_name' ),
			'address'        => $read_text( 'provider_address' ),
			'phone'          => $read_text( 'provider_phone' ),
			'business_hours' => $read_text( 'provider_business_hours' ),
			'website_url'    => $website_url,
			'privacy_policy' => array(
				'mode'    => $privacy_mode,
				'url'     => 'url' === $privacy_mode ? $privacy_url : '',
				'content' => 'content' === $privacy_mode ? $privacy_content : '',
			),
		);
	}

	/**
	 * 保存済みの設定から、予約ブロックへ渡す絞り込み検索のデザインを決める。
	 *
	 * 選択肢にない値（旧データなど）は初期値（plain）にする。
	 *
	 * @param array<string,mixed> $settings 保存済みの設定。
	 * @return string 絞り込み検索のデザイン。
	 */
	private static function resolve_menu_search_style( array $settings ): string {
		$style = isset( $settings['design_menu_search_style'] ) && is_string( $settings['design_menu_search_style'] )
			? $settings['design_menu_search_style']
			: '';

		return array_key_exists( $style, Settings_Sanitizer::get_design_menu_search_style_choices() )
			? $style
			: Settings_Sanitizer::DESIGN_MENU_SEARCH_STYLE_DEFAULT;
	}

	/**
	 * フッターに出すウェブサイトURLを決める。
	 *
	 * 予約ページURLと同じ場合（前後の空白・末尾スラッシュ・大文字小文字の違いは無視）は、
	 * 同じページへのリンクになるため空文字を返す。予約ページURLが未入力なら表示する。
	 *
	 * @param string $website_url          BM設定のウェブサイトURL。
	 * @param string $reservation_page_url BM設定の予約ページURL（未正規化）。
	 * @return string 表示するウェブサイトURL。表示しないときは空文字。
	 */
	private static function resolve_footer_website_url( string $website_url, string $reservation_page_url ): string {
		// ウェブサイトURLを安全な形に整える。
		$website_url = esc_url_raw( trim( $website_url ), array( 'http', 'https' ) );
		if ( '' === $website_url ) {
			return '';
		}

		// 予約ページURLが未入力なら比較せず表示する。
		$reservation_url = vkbm_normalize_reservation_page_url( $reservation_page_url );
		if ( '' === $reservation_url ) {
			return $website_url;
		}

		// 末尾スラッシュと大文字小文字を無視して比較する。
		$normalize = static function ( string $url ): string {
			return strtolower( untrailingslashit( trim( $url ) ) );
		};
		return $normalize( $website_url ) === $normalize( $reservation_url ) ? '' : $website_url;
	}

	/**
	 * Normalize reservation page URL to an absolute path.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_reservation_page_url( string $url ): string {
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
