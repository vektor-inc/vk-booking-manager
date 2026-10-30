<?php
/**
 * ゲスト予約（会員登録なしの予約）の、メールのリンクからのキャンセル。
 *
 * 流れ: お知らせメールのリンク（?vkbm_guest_cancel=<トークン>&booking=<予約ID>）を開く → 予約内容と
 * 「この予約をキャンセルする」ボタンを出す → ボタンを押すと POST で実際にキャンセルする。
 * リンクを開いただけ（GET）では、キャンセルしない。仕様は docs/specification-guest-booking.md の「4. キャンセル用リンク」。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;
use function absint;
use function add_action;
use function add_post_meta;
use function add_query_arg;
use function delete_post_meta;
use function get_post;
use function get_post_meta;
use function hash;
use function hash_equals;
use function home_url;
use function is_admin;
use function in_array;
use function wp_print_inline_script_tag;
use function is_array;
use function is_string;
use function nocache_headers;
use function preg_match;
use function register_rest_route;
use function sanitize_text_field;
use function wp_generate_password;
use function wp_unslash;

/**
 * ゲスト予約のキャンセル用リンク。
 */
class Guest_Cancel_Controller {
	/**
	 * REST の名前空間。
	 */
	public const REST_NAMESPACE = 'vkbm/v1';

	/**
	 * キャンセル用リンクのトークンを入れるクエリ名。
	 */
	public const QUERY_VAR = 'vkbm_guest_cancel';

	/**
	 * キャンセル用リンクの予約 ID を入れるクエリ名。
	 */
	public const BOOKING_QUERY_VAR = 'booking';

	/**
	 * 1 つの予約に残すトークンのハッシュの上限。
	 *
	 * お知らせメール・リマインドメールのたびに新しいトークンを発行するため、古いメールのリンクも使えるよう複数残す。
	 * 上限を超えたら、古いものから消す。
	 */
	public const MAX_TOKEN_HASHES = 10;

	/**
	 * リンクが正しくない（トークンと予約 ID が合わない・ゲスト予約でない・匿名化済みなど）ときの状態。
	 */
	public const VIEW_INVALID = 'invalid';

	/**
	 * このリクエストで予約を作った直後に発行した、生のトークン（予約 ID => トークン）。
	 *
	 * 作成直後のお知らせメールの URL に入れるため、同じリクエストの間だけ覚える。保存はしない。
	 *
	 * @var array<int,string>
	 */
	private static $fresh_tokens = array();

	/**
	 * このリクエストでキャンセル用リンクを開いた結果。
	 *
	 * @var array<string,mixed>|null
	 */
	private static $current_view = null;

	/**
	 * キャンセルの可否の判定と実行。
	 *
	 * @var Booking_Customer_Cancellation
	 */
	private $cancellation;

	/**
	 * 基本設定。
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository           $settings_repository 基本設定。
	 * @param Booking_Customer_Cancellation $cancellation        キャンセルの可否の判定と実行。
	 */
	public function __construct( Settings_Repository $settings_repository, Booking_Customer_Cancellation $cancellation ) {
		$this->settings_repository = $settings_repository;
		$this->cancellation        = $cancellation;
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// キャンセル用リンクは予約ページに付く。テンプレートを決める前に処理し、結果を予約ブロックが読めるようにする。
		add_action( 'template_redirect', array( $this, 'handle_cancel_view_request' ), 1 );
		// リンクのトークンは、描画後ではなくページの先頭で URL から外す（アクセス解析タグに URL ごと渡さないため）。
		add_action( 'wp_head', array( self::class, 'print_link_scrubber' ), 0 );
	}

	/**
	 * REST のルートを登録する。
	 */
	public function register_routes(): void {
		// ゲストは未ログインのため、permission_callback は意図的に __return_true とする。
		// 認可の代わりに、メールにだけ書かれたトークンと予約 ID の組を callback で確かめる。
		register_rest_route(
			self::REST_NAMESPACE,
			'/guest-bookings/(?P<id>\\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * 発行したトークンのハッシュを 1 件だけ取り消す。
	 *
	 * メールを送らなかった・送れなかったときに、そのメールのために発行した分を残さないために使う
	 * （残すと上限の件数を使い切り、先に送ったメールのリンクが使えなくなる）。
	 *
	 * @param int    $booking_id 予約の投稿 ID。
	 * @param string $raw_token  発行した生のトークン。
	 */
	public static function revoke_token( int $booking_id, string $raw_token ): void {
		if ( '' !== $raw_token ) {
			delete_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, self::hash_token( $raw_token ) );
		}
	}

	/**
	 * メールのリンクに付いたトークンを、ページの読み込み時にいちばん早く URL から外して window の変数へ退避する。
	 *
	 * キャンセル用リンク（vkbm_guest_cancel）と確認リンク（vkbm_guest_verify）のトークンが、
	 * ページ上のアクセス解析タグなどが URL ごと外部へ送る前に、アドレスから消えるようにする（wp_head 優先度 0）。
	 * 退避した値は window.vkbmGuestLinkParams に入り、画面（React）がそこから読む。サーバー側の GET 処理は変わらない。
	 * 出力するのは固定のスクリプトだけで、リクエストの値は埋め込まない。
	 */
	public static function print_link_scrubber(): void {
		if ( is_admin() ) {
			return;
		}

		// 存在の確認だけを行い、値は使わない（スクリプトがブラウザ側で読む）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) && ! isset( $_GET[ Guest_Booking_Controller::QUERY_VAR ] ) ) {
			return;
		}

		$script = '(function(){try{var u=new URL(window.location.href),p={},c=false;'
			. '["' . self::QUERY_VAR . '","' . Guest_Booking_Controller::QUERY_VAR . '"].forEach(function(n){'
			. 'var v=u.searchParams.get(n);if(v!==null){p[n]=v;u.searchParams.delete(n);c=true;'
			. 'if(n==="' . self::QUERY_VAR . '"){u.searchParams.delete("' . self::BOOKING_QUERY_VAR . '");}}});'
			. 'if(c){window.vkbmGuestLinkParams=p;window.history.replaceState(null,"",u.toString());}}catch(e){}})();';

		// CSP の nonce 等は、WordPress の wp_print_inline_script_tag() が付ける。
		wp_print_inline_script_tag( $script, array( 'id' => 'vkbm-guest-link-scrubber' ) );
	}

	/**
	 * 予約に、新しいキャンセル用トークンを発行する。
	 *
	 * 生のトークンは戻り値としてメールの URL に入れるためだけに返し、予約には SHA-256 のハッシュだけを保存する。
	 * 古いトークンのハッシュは残す（古いメールのリンクも使えるよう、上限まで）。
	 *
	 * @param int  $booking_id 予約の投稿 ID。
	 * @param bool $remember   true なら、同じリクエストの間だけ生のトークンを覚える（予約を作った直後のお知らせメール用。{@see self::take_fresh_token()}）。
	 * @return string 生のトークン（英数字 32 文字）。
	 */
	public static function issue_token( int $booking_id, bool $remember = false ): string {
		$raw_token = wp_generate_password( 32, false, false );

		add_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, self::hash_token( $raw_token ), false );

		// 上限を超えた分は、古いものから消す（メタは追加した順に読める）。
		$hashes = get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false );
		if ( is_array( $hashes ) && count( $hashes ) > self::MAX_TOKEN_HASHES ) {
			foreach ( array_slice( $hashes, 0, count( $hashes ) - self::MAX_TOKEN_HASHES ) as $old_hash ) {
				delete_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, $old_hash );
			}
		}

		if ( $remember ) {
			self::$fresh_tokens[ $booking_id ] = $raw_token;
		}

		return $raw_token;
	}

	/**
	 * 予約を作った直後に発行した生のトークンを、一度だけ受け取る。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return string 生のトークン。覚えていなければ空文字。
	 */
	public static function take_fresh_token( int $booking_id ): string {
		$raw_token = self::$fresh_tokens[ $booking_id ] ?? '';
		unset( self::$fresh_tokens[ $booking_id ] );

		return $raw_token;
	}

	/**
	 * トークンから、予約に保存するハッシュを作る。
	 *
	 * @param string $raw_token 生のトークン。
	 * @return string SHA-256（16 進数 64 文字）。
	 */
	public static function hash_token( string $raw_token ): string {
		return hash( 'sha256', $raw_token );
	}

	/**
	 * トークンと予約 ID の組が正しいかを返す。
	 *
	 * ゲスト予約で、匿名化されておらず、保存したハッシュのどれかと一致するときだけ true。
	 * ハッシュの比較は hash_equals で行う。
	 *
	 * @param int    $booking_id 予約の投稿 ID。
	 * @param string $raw_token  メールのリンクに入っていた生のトークン。
	 * @return bool 正しければ true。
	 */
	public static function verify_token( int $booking_id, string $raw_token ): bool {
		if ( $booking_id <= 0 || 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $raw_token ) ) {
			return false;
		}

		$booking = get_post( $booking_id );
		if ( ! $booking instanceof \WP_Post || Booking_Post_Type::POST_TYPE !== $booking->post_type ) {
			return false;
		}

		// ゴミ箱などに入れた予約は、リンクから見せない・キャンセルさせない。
		if ( in_array( $booking->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return false;
		}

		if ( Booking_Source::VALUE_GUEST !== (string) get_post_meta( $booking_id, Booking_Source::META_KEY, true ) ) {
			return false;
		}

		if ( Booking_Anonymizer::is_anonymized( $booking_id ) ) {
			return false;
		}

		$hashes = get_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH, false );
		if ( ! is_array( $hashes ) ) {
			return false;
		}

		$given = self::hash_token( $raw_token );
		$match = false;
		foreach ( $hashes as $stored ) {
			// 匿名化で空にした行は対象外。見つかっても止めず、全件を比べる。
			if ( is_string( $stored ) && '' !== $stored && hash_equals( $stored, $given ) ) {
				$match = true;
			}
		}

		return $match;
	}

	/**
	 * 予約のキャンセル用トークンのハッシュをすべて消す。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 */
	public static function clear_tokens( int $booking_id ): void {
		delete_post_meta( $booking_id, Booking_Source::META_GUEST_CANCEL_TOKEN_HASH );
	}

	/**
	 * メールに載せるキャンセル用リンクを作る。
	 *
	 * @param int                 $booking_id 予約の投稿 ID。
	 * @param string              $raw_token  生のトークン。
	 * @param array<string,mixed> $settings   基本設定（予約ページの URL を読む）。
	 * @return string `{予約ページ URL}?vkbm_guest_cancel=<トークン>&booking=<予約ID>`。
	 */
	public static function build_cancel_url( int $booking_id, string $raw_token, array $settings ): string {
		$base = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';
		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			$base = vkbm_normalize_reservation_page_url( $base );
		}
		if ( '' === $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg(
			array(
				self::QUERY_VAR         => $raw_token,
				self::BOOKING_QUERY_VAR => $booking_id,
			),
			$base
		);
	}

	/**
	 * このリクエストで開いたキャンセル用リンクの結果を返す。
	 *
	 * キー: state（Booking_Customer_Cancellation の STATE_* か VIEW_INVALID）、message、booking_id。
	 *
	 * @return array<string,mixed>|null リンクを開いていなければ null。
	 */
	public static function get_current_view(): ?array {
		return self::$current_view;
	}

	/**
	 * 予約ブロックの画面へ渡す形に絞った、このリクエストの結果を返す。
	 *
	 * トークンは含めない（画面は、開いた時点の URL から読む）。予約内容（booking）は、トークンが正しいときだけ付ける。
	 *
	 * @return array<string,mixed>|null リンクを開いていなければ null。キー: state、message、bookingId、booking（予約内容。無ければ null）。
	 */
	public static function get_public_view(): ?array {
		$current = self::$current_view;
		if ( null === $current ) {
			return null;
		}

		$state      = (string) ( $current['state'] ?? self::VIEW_INVALID );
		$booking_id = (int) ( $current['booking_id'] ?? 0 );

		$is_valid = self::VIEW_INVALID !== $state && $booking_id > 0;
		$settings = ( new Settings_Repository() )->get_settings();

		return array(
			'state'         => $state,
			'message'       => (string) ( $current['message'] ?? '' ),
			'bookingId'     => $is_valid ? $booking_id : 0,
			'booking'       => $is_valid ? Guest_Booking_Controller::build_booking_summary( $booking_id ) : null,
			// キャンセルできないときの「お電話でご連絡ください」に添える店舗の電話番号（メールのフッターにも載せている公開情報）。
			// 予約の有無に関係なく出る公開情報だけなので、正しくないリンクにも渡す。
			'providerPhone' => sanitize_text_field( (string) ( $settings['provider_phone'] ?? '' ) ),
		);
	}

	/**
	 * テスト用に、このリクエストの結果を空に戻す。
	 */
	public static function reset_current_view(): void {
		self::$current_view = null;
	}

	/**
	 * 予約ページを開いたときに、キャンセル用リンクのトークンがあれば確かめる。
	 *
	 * ここでは予約を変更しない（確かめて、予約ブロックに見せる内容を用意するだけ）。キャンセルはボタンを押した後の POST で行う。
	 * 結果は get_current_view() で読めるようにする。画面は、予約ブロックが get_public_view() 経由で受け取って表示する。
	 */
	public function handle_cancel_view_request(): void {
		// メールの安全確認の仕組みが先にリンクを開くことがある。GET 以外（HEAD など）では何もしない。
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}

		// リンクを開いただけの GET。トークンと予約 ID の組で確かめ、状態は変えない（nonce の対象外）。
		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $raw ) {
			return;
		}
		$booking_id = isset( $_GET[ self::BOOKING_QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::BOOKING_QUERY_VAR ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 結果は人ごとに違う。キャッシュに残さず、URL のトークンを外部へ送らない。
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// ページキャッシュ系プラグインが共通で参照する定数（WordPress エコシステムの規約）。プレフィックスは付けられない。
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		self::$current_view = $this->resolve_view( $booking_id, $raw );
	}

	/**
	 * リンクのトークンと予約 ID から、画面に出す状態を決める。
	 *
	 * @param int    $booking_id 予約の投稿 ID（リンクの booking）。
	 * @param string $raw_token  生のトークン。
	 * @return array<string,mixed> キー: state、message、booking_id。
	 */
	private function resolve_view( int $booking_id, string $raw_token ): array {
		// 予約が無い・ゲスト予約でない・匿名化済み・キャンセル済みでトークンを消した、のどれも同じ「正しくないリンク」として返す。
		// 区別して返すと、予約 ID の存在などを探れてしまうため。
		if ( ! self::verify_token( $booking_id, $raw_token ) ) {
			return array(
				'state'      => self::VIEW_INVALID,
				'message'    => '',
				'booking_id' => 0,
			);
		}

		$state = $this->cancellation->get_state( $booking_id );

		return array(
			'state'      => $state,
			'message'    => Booking_Customer_Cancellation::get_state_message( $state ),
			'booking_id' => $booking_id,
		);
	}

	/**
	 * POST /guest-bookings/{id}/cancel: キャンセル用トークンで本人を確かめ、予約をキャンセルする。
	 *
	 * リクエストの値: token（メールのリンクに入っていた生のトークン）。
	 * キャンセルの可否の判定と、キャンセル後の処理（ステータス変更・通知メール・外部連携への通知）は、マイページと共通。
	 *
	 * @param WP_REST_Request $request リクエスト。
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_booking( WP_REST_Request $request ) {
		$booking_id = absint( $request['id'] ?? 0 );
		$raw_token  = sanitize_text_field( (string) $request->get_param( 'token' ) );

		if ( ! self::verify_token( $booking_id, $raw_token ) ) {
			// 予約が無い場合も同じ応答にする（予約 ID の存在を探れないように）。
			return new WP_Error(
				'guest_cancel_invalid',
				__( 'This cancellation link is not valid.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		$result = $this->cancellation->cancel( $booking_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// キャンセルしたら、リンクはもう使えない。
		self::clear_tokens( $booking_id );

		return new WP_REST_Response( array( 'cancelled' => true ) );
	}
}
