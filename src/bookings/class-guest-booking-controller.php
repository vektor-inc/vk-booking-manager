<?php
/**
 * ゲスト予約（会員登録なしの予約）の申し込み受付と、確認リンクを開いたときの処理。
 *
 * 流れ: 申し込み（POST /vkbm/v1/guest-bookings）→ 確認メール → お客様が確認リンクを開く → 空きを確かめ直して予約を作る。
 * リンクを開くまで予約データは作らず、予約枠も押さえない。仕様は docs/specification-guest-booking.md。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use DateTimeImmutable;
use Exception;
use VKBookingManager\Common\Attendee_Name;
use VKBookingManager\Common\Rate_Limit_Trait;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function add_action;
use function add_option;
use function add_query_arg;
use function apply_filters;
use function delete_option;
use function delete_transient;
use function do_action;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function get_transient;
use function gmdate;
use function hash;
use function home_url;
use function is_array;
use function is_email;
use function is_user_logged_in;
use function mb_strlen;
use function mb_substr;
use function nocache_headers;
use function preg_match;
use function register_rest_route;
use function sanitize_email;
use function sanitize_key;
use function sanitize_textarea_field;
use function sanitize_text_field;
use function usleep;
use function wp_strip_all_tags;
use function wp_timezone;
use function wp_timezone_string;
use function get_option;
use function in_array;
use function set_transient;
use function time;
use function wp_date;
use function wp_generate_password;
use function wp_unslash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ゲスト予約の申し込み（REST）と確認リンクの処理。
 */
class Guest_Booking_Controller {
	use Rate_Limit_Trait;

	/**
	 * REST の名前空間。
	 */
	public const REST_NAMESPACE = 'vkbm/v1';

	/**
	 * 確認リンクのクエリ名。
	 */
	public const QUERY_VAR = 'vkbm_guest_verify';

	/**
	 * ロボット対策の隠し入力欄の名前（会員登録の隠し入力欄と同じ）。
	 */
	public const HONEYPOT_FIELD = 'vkbm_hp_email';

	/**
	 * 確認待ちデータ（transient）の名前の前置き。後ろにトークンの SHA-256 を付ける。
	 */
	public const PENDING_PREFIX = 'vkbm_guest_verify_';

	/**
	 * 使用済みトークンの結果（transient）の名前の前置き。後ろにトークンの SHA-256 を付ける。
	 */
	public const RESULT_PREFIX = 'vkbm_guest_verified_';

	/**
	 * 同じメールアドレス・同じ枠の確認待ちデータを置き換えるための索引（transient）の名前の前置き。
	 */
	public const SLOT_INDEX_PREFIX = 'vkbm_guest_verify_slot_';

	/**
	 * メールアドレスごとの送信回数（transient）の名前の前置き。
	 */
	public const EMAIL_LIMIT_PREFIX = 'vkbm_guest_verify_mail_';

	/**
	 * 確認リンクを処理している間のロック（オプション）の名前の前置き。
	 */
	public const LOCK_PREFIX = 'vkbm_guest_verify_lock_';

	/**
	 * 確認リンクの処理中ロックが残っていたとき、古いとみなして取り直す秒数。
	 */
	public const LOCK_STALE_SECONDS = 60;

	/**
	 * 1 つの予約下書きから確認メールを送れる宛先の数の既定値。
	 */
	public const DEFAULT_MAX_RECIPIENTS_PER_DRAFT = 3;

	/**
	 * 下書きごとの宛先（メールアドレスのハッシュ）を持つ transient の名前の前置き。
	 */
	public const DRAFT_RECIPIENTS_PREFIX = 'vkbm_guest_verify_rcpt_';

	/**
	 * 確認リンクの有効期限（秒）の既定値。60 分で固定し、設定画面には出さない。
	 */
	public const DEFAULT_TTL = 3600;

	/**
	 * 使用済みトークンの結果を残す期間（秒）の既定値。同じリンクを開き直したときに、その予約の今の状態を表示するため。
	 */
	public const DEFAULT_RESULT_TTL = 86400;

	/**
	 * 同じメールアドレスへの送信間隔（秒）。会員登録の確認メール再送と同じ数字。
	 */
	public const EMAIL_COOLDOWN_SECONDS = 60;

	/**
	 * 同じメールアドレスへの 24 時間あたりの送信上限。会員登録の確認メール再送と同じ数字。
	 */
	public const EMAIL_DAILY_MAX = 5;

	/**
	 * IP ごとの回数制限の期間（秒）。会員登録と同じ数字。
	 */
	public const IP_RATE_LIMIT_WINDOW = 1800;

	/**
	 * IP ごとの回数制限の既定の上限。基本設定 `auth_rate_limit_register_max` が無いときに使う（会員登録と同じ数字）。
	 */
	public const IP_RATE_LIMIT_DEFAULT_MAX = 5;

	/**
	 * 予約の作成結果の内部の区分: 一時的な失敗。結果を保存せず、確認待ちデータも残す。
	 */
	private const OUTCOME_RETRY = 'retry';

	/**
	 * 確認リンクを開いた結果: 予約が確定した（または確定済みの予約を開き直した）。
	 */
	public const RESULT_CONFIRMED = 'confirmed';

	/**
	 * 確認リンクを開いた結果: 仮予約を受け付けた（または仮予約のままの予約を開き直した）。
	 */
	public const RESULT_PENDING = 'pending';

	/**
	 * 確認リンクを開いた結果: 予約はキャンセル済みなど、確定でも仮予約でもない状態。
	 */
	public const RESULT_BOOKING_CLOSED = 'booking_closed';

	/**
	 * 確認リンクを開いた結果: 有効期限が切れている（または見つからない）。
	 */
	public const RESULT_EXPIRED = 'expired';

	/**
	 * 確認リンクを開いた結果: 枠が先に埋まっていて予約できなかった。
	 */
	public const RESULT_SLOT_UNAVAILABLE = 'slot_unavailable';

	/**
	 * 確認リンクを開いた結果: 顧客フラグや重複予約など、空き以外の理由で予約できなかった。
	 */
	public const RESULT_REJECTED = 'rejected';

	/**
	 * 確認リンクを開いた結果: 受付方法が「会員のみ」に戻っていて、ゲスト予約を受け付けられない。
	 */
	public const RESULT_UNAVAILABLE = 'unavailable';

	/**
	 * 確認リンクを開いた結果: 同じリンクを別のリクエストが処理している最中、または一時的な失敗で予約を作れなかった（少し待って開き直す）。
	 *
	 * 一時的な失敗のときは確認待ちデータを残し、結果も保存しないので、開き直せば予約の作成をやり直す。
	 */
	public const RESULT_PROCESSING = 'processing';

	/**
	 * 確認リンクを開いた結果: トークンの形が正しくない。
	 */
	public const RESULT_INVALID = 'invalid';

	/**
	 * このリクエストで確認リンクを処理した結果。処理していなければ null。
	 *
	 * @var array<string,mixed>|null
	 */
	private static $current_result = null;

	/**
	 * 予約を作る部品を持つコントローラー。
	 *
	 * @var Booking_Confirmation_Controller
	 */
	private $confirmation_controller;

	/**
	 * 通知サービス。
	 *
	 * @var Booking_Notification_Service
	 */
	private $notification_service;

	/**
	 * 基本設定。
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Booking_Confirmation_Controller $confirmation_controller 予約を作る部品を持つコントローラー。
	 * @param Booking_Notification_Service    $notification_service    通知サービス。
	 * @param Settings_Repository             $settings_repository     基本設定。
	 */
	public function __construct(
		Booking_Confirmation_Controller $confirmation_controller,
		Booking_Notification_Service $notification_service,
		Settings_Repository $settings_repository
	) {
		$this->confirmation_controller = $confirmation_controller;
		$this->notification_service    = $notification_service;
		$this->settings_repository     = $settings_repository;
	}

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// 確認リンクは予約ページに付く。テンプレートを決める前に処理し、結果を予約ブロックが読めるようにする。
		add_action( 'template_redirect', array( $this, 'handle_verification_request' ), 1 );
	}

	/**
	 * REST のルートを登録する。
	 */
	public function register_routes(): void {
		// ゲストは未ログインで申し込むため、permission_callback は意図的に __return_true とする。
		// 認可の代わりに、IP ごと・メールアドレスごとの回数制限、隠し入力欄、
		// 下書きの所有者の確認（下書きを作ったブラウザだけが使える）で乱用を抑える。
		register_rest_route(
			self::REST_NAMESPACE,
			'/guest-bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_guest_booking_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * このリクエストで処理した確認リンクの結果を返す。
	 *
	 * 予約ブロックが結果画面を出すために読む。キー: result（RESULT_* のいずれか）、booking_id、
	 * booking_status（予約の今のステータス）、repeat（同じリンクを開き直したか）、code・message（受け付けなかった理由）。
	 *
	 * @return array<string,mixed>|null 処理していなければ null。
	 */
	public static function get_current_result(): ?array {
		return self::$current_result;
	}

	/**
	 * 予約ブロックの画面へ渡す形に絞った、このリクエストの結果を返す。
	 *
	 * get_current_result() の code・message は渡さない。顧客フラグ・重複予約など、予約を受け付けなかった理由を画面に出すと、
	 * 他人のメールアドレス・電話番号を入れて、フラグの有無や予約の有無を探れてしまうため。
	 * 予約が確定・仮予約になった結果にだけ、画面に出す予約内容（booking）を付ける。
	 *
	 * @return array<string,mixed>|null 処理していなければ null。キー: result、bookingStatus、repeat、booking（予約内容。無ければ null）。
	 */
	public static function get_public_result(): ?array {
		$current = self::$current_result;
		if ( null === $current ) {
			return null;
		}

		$result     = (string) ( $current['result'] ?? '' );
		$booking_id = (int) ( $current['booking_id'] ?? 0 );

		// 予約内容は、確定・仮予約のときだけ読む（キャンセル済みなどでは出さない）。
		$booking = null;
		if ( $booking_id > 0 && in_array( $result, array( self::RESULT_CONFIRMED, self::RESULT_PENDING ), true ) ) {
			$booking = self::build_booking_summary( $booking_id );
		}

		return array(
			'result'        => $result,
			'bookingStatus' => (string) ( $current['booking_status'] ?? '' ),
			'repeat'        => ! empty( $current['repeat'] ),
			'booking'       => $booking,
		);
	}

	/**
	 * 結果画面に出す予約内容（日時・メニュー・担当・人数）を組み立てる。
	 *
	 * 連絡先・備考・金額など、本人以外に見せたくない値は含めない。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return array<string,mixed> startAt・endAt・timezone・menuName・resourceName（指名したときだけ）・guests。
	 */
	public static function build_booking_summary( int $booking_id ): array {
		$menu_id     = (int) get_post_meta( $booking_id, '_vkbm_booking_service_id', true );
		$resource_id = (int) get_post_meta( $booking_id, Booking_Overlap::META_RESOURCE, true );
		$preferred   = '1' === (string) get_post_meta( $booking_id, '_vkbm_booking_is_staff_preferred', true );

		return array(
			'startAt'      => self::to_iso8601( (string) get_post_meta( $booking_id, Booking_Overlap::META_START, true ) ),
			'endAt'        => self::to_iso8601( (string) get_post_meta( $booking_id, Booking_Overlap::META_END, true ) ),
			'timezone'     => wp_timezone_string(),
			'menuName'     => $menu_id > 0 ? wp_strip_all_tags( get_the_title( $menu_id ) ) : '',
			'resourceName' => ( $preferred && $resource_id > 0 ) ? wp_strip_all_tags( get_the_title( $resource_id ) ) : '',
			'guests'       => max( 1, (int) get_post_meta( $booking_id, '_vkbm_booking_guests', true ) ),
		);
	}

	/**
	 * 予約に保存した日時を、ブラウザがタイムゾーンを取り違えない ISO 8601（オフセット付き）にそろえる。
	 *
	 * 保存値にオフセットが無いときは、サイトのタイムゾーンの日時として扱う。
	 *
	 * @param string $value 予約に保存した日時。
	 * @return string ISO 8601 の日時。読み取れなければ空文字。
	 */
	private static function to_iso8601( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}

		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->format( 'c' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * テスト用に、このリクエストの結果を空に戻す。
	 */
	public static function reset_current_result(): void {
		self::$current_result = null;
	}

	/**
	 * 確認リンクの有効期限（秒）を返す。
	 *
	 * @return int 秒。開発者向けフィルター `vkbm_guest_booking_verification_ttl` で差し替えられる（60 秒未満は 60 秒にする）。
	 */
	public static function get_verification_ttl(): int {
		/**
		 * ゲスト予約の確認リンクの有効期限（秒）を差し替える。既定は 3600（60 分）。
		 *
		 * @param int $ttl 有効期限（秒）。
		 */
		$ttl = (int) apply_filters( 'vkbm_guest_booking_verification_ttl', self::DEFAULT_TTL );

		return max( 60, $ttl );
	}

	/**
	 * 使用済みトークンの結果を残す期間（秒）を返す。
	 *
	 * @return int 秒。開発者向けフィルター `vkbm_guest_booking_result_ttl` で差し替えられる（60 秒未満は 60 秒にする）。
	 */
	public static function get_result_ttl(): int {
		/**
		 * 使用済みの確認リンクの結果を残す期間（秒）を差し替える。既定は 86400（24 時間）。
		 *
		 * @param int $ttl 秒。
		 */
		$ttl = (int) apply_filters( 'vkbm_guest_booking_result_ttl', self::DEFAULT_RESULT_TTL );

		return max( 60, $ttl );
	}

	/**
	 * トークンから確認待ちデータの名前に使うハッシュを作る。
	 *
	 * @param string $token 生のトークン。
	 * @return string SHA-256（16 進数 64 文字）。
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * POST /guest-bookings: ゲスト予約の申し込みを受け付け、確認メールを送る。
	 *
	 * 予約データは作らない。確認待ちデータを一時保存に置き、リンク付きのメールを送るだけ。
	 *
	 * リクエストの値: token（予約の下書きのトークン。POST /drafts が返したもの）、customer_name、customer_email、customer_phone
	 * （すべて必須）、vkbm_hp_email（隠し入力欄。人は入力しない）。備考・同意・ご利用になる方は下書きの値を使う。
	 *
	 * @param WP_REST_Request $request リクエスト。
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_guest_booking_request( WP_REST_Request $request ) {
		$settings = $this->settings_repository->get_settings();

		// 受付方法が「会員のみ」なら受け付けない（初期値。今の動きを変えない）。
		if ( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER === Settings_Repository::normalize_booking_customer_mode( $settings[ Settings_Repository::KEY_BOOKING_CUSTOMER_MODE ] ?? null ) ) {
			return new WP_Error(
				'guest_booking_disabled',
				__( 'Reservations without registration are not accepted. Please log in or register to make a reservation.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		// ログイン中の会員はゲストとして受け付けない（顧客フラグで止められた会員が回り込めないようにするため）。
		if ( is_user_logged_in() ) {
			return new WP_Error(
				'guest_booking_logged_in',
				__( 'You are logged in. Please make the reservation as a member.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		// IP ごとの回数制限（会員登録と同じ設定値・同じ有効化設定）。処理に入る前に適用する。
		if ( ! empty( $settings['auth_rate_limit_enabled'] ) ) {
			$ip_limit = isset( $settings['auth_rate_limit_register_max'] ) ? (int) $settings['auth_rate_limit_register_max'] : self::IP_RATE_LIMIT_DEFAULT_MAX;
			$ip_limit = $ip_limit > 0 ? $ip_limit : self::IP_RATE_LIMIT_DEFAULT_MAX;
			if ( ! $this->consume_rate_limit_token( 'guest_booking_request', $ip_limit, self::IP_RATE_LIMIT_WINDOW ) ) {
				return $this->create_rate_limited_error();
			}
		}

		// ロボット対策の隠し入力欄。人には見えないので、入力があればロボットとみなす。
		$honeypot = sanitize_text_field( (string) $request->get_param( self::HONEYPOT_FIELD ) );
		if ( '' !== $honeypot ) {
			return new WP_Error(
				'guest_booking_honeypot',
				__( 'An invalid request was detected.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * ゲスト予約の申し込みを受け付ける前に、別のプラグインが止めるための差し込み口（CAPTCHA などを足すため）。
		 *
		 * WP_Error を返すと申し込みを受け付けない（エラーはそのまま REST の応答になる）。
		 *
		 * @param true|WP_Error   $result  判定結果。初期値は true（問題なし）。
		 * @param WP_REST_Request $request 申し込みのリクエスト。
		 */
		$check = apply_filters( 'vkbm_guest_booking_request_check', true, $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// 名前・メールアドレス・電話番号はすべて必須。
		$name  = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		$name  = mb_substr( $name, 0, 100 );
		$email = sanitize_email( (string) $request->get_param( 'customer_email' ) );
		$phone = VKBM_Helper::normalize_phone_number( sanitize_text_field( (string) $request->get_param( 'customer_phone' ) ) );
		if ( '' === $name || '' === $email || '' === $phone ) {
			return new WP_Error(
				'guest_contact_required',
				__( 'Please enter your name, email address and phone number.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_email( $email ) || mb_strlen( $email ) > 254 ) {
			return new WP_Error(
				'guest_email_invalid',
				__( 'Please enter a valid email address.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}
		$email = VKBM_Helper::normalize_email( $email );

		// 予約の下書き（下書きを作ったブラウザだけが使える）。
		$token = sanitize_key( (string) $request->get_param( 'token' ) );
		$draft = $this->confirmation_controller->load_accessible_draft( $token );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		// フォームの入力。リクエストに無ければ下書きの値を使う。
		$form = $this->collect_form_values( $request, $draft );

		// キャンセルポリシーと利用規約への同意（設定されているときだけ必須）。リンクを開いた後に失敗させないよう、申し込みの時点で確かめる。
		if ( '' !== trim( (string) ( $settings['provider_cancellation_policy'] ?? '' ) ) && ! $form['agree_cancellation_policy'] ) {
			return new WP_Error(
				'cancellation_policy_required',
				__( 'You must agree to the cancellation policy.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}
		if ( '' !== trim( (string) ( $settings['provider_terms_of_service'] ?? '' ) ) && ! $form['agree_terms_of_service'] ) {
			return new WP_Error(
				'terms_required',
				__( 'You must agree to the terms of use.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// ご利用になる方の名前が必須の設定でも、入力が無ければここで断る（リンクを開いた後に失敗させない）。
		$attendee = ( new \VKBookingManager\Attendees\Booking_Attendee_Resolver() )->resolve(
			$settings,
			0,
			array( 'attendee_name' => $form['attendee_name'] ),
			false
		);
		if ( is_wp_error( $attendee ) ) {
			return $attendee;
		}

		// 顧客フラグと重複予約の判定は、ここ（申し込みの時点）では行わない。
		// 確認前に断ると、他人のメールアドレス・電話番号を入れて、顧客フラグの有無や予約の有無を探れてしまうため。
		// メールの持ち主が確認リンクを開いた時点で判定する（create_booking_from_draft()）。

		// 宛先の数の上限（1 つの下書きにつき）と、メールアドレスごとの回数制限を確かめて数える。
		$reservation = array(
			'recipient_added' => false,
			'window_start'    => 0,
			'last'            => 0,
		);
		$limit_error = $this->reserve_send_slot( $token, $email, $reservation );
		if ( null !== $limit_error ) {
			return $limit_error;
		}

		// 確認待ちデータを一時保存に置く。保存するのはトークンの SHA-256 だけ。生のトークンはメールの URL にだけ入れる。
		$raw_token  = wp_generate_password( 32, false, false );
		$token_hash = self::hash_token( $raw_token );
		$ttl        = self::get_verification_ttl();
		$expires_at = time() + $ttl;

		$pending = array(
			'draft'       => $draft,
			'draft_token' => $token,
			'guest'       => array(
				'name'  => $name,
				'email' => $email,
				'phone' => $phone,
			),
			'form'        => $form,
			'created_at'  => time(),
			'expires_at'  => $expires_at,
		);

		// 同じメールアドレス・同じ枠への送り直しは、前の確認待ちデータを置き換える（前のリンクは使えなくなる）。
		$slot_index_key = self::SLOT_INDEX_PREFIX . hash( 'sha256', $email . '|' . $this->build_slot_key( $draft ) );
		$previous_hash  = get_transient( $slot_index_key );
		if ( is_string( $previous_hash ) && '' !== $previous_hash ) {
			delete_transient( self::PENDING_PREFIX . $previous_hash );
		}
		set_transient( self::PENDING_PREFIX . $token_hash, $pending, $ttl );
		set_transient( $slot_index_key, $token_hash, $ttl );

		$status_mode = sanitize_key( (string) ( $settings['provider_booking_status_mode'] ?? 'pending' ) );
		$sent        = $this->notification_service->send_guest_verification(
			array(
				'name'          => $name,
				'email'         => $email,
				'verify_url'    => add_query_arg( self::QUERY_VAR, $raw_token, $this->get_reservation_page_url() ),
				'expires_at'    => $expires_at,
				'draft'         => $draft,
				'attendee_name' => $form['attendee_name'],
				'is_pending'    => 'pending' === $status_mode,
			)
		);
		if ( ! $sent ) {
			// 送れなかったときは確認待ちデータを残さない（届かないリンクのデータを置いておかない）。
			delete_transient( self::PENDING_PREFIX . $token_hash );
			delete_transient( $slot_index_key );
			// 届いていないので、今回数えた宛先とメール送信回数を戻す（送れなかったのに枠を使い切らせないため）。
			$this->release_send_slot( $token, $email, $reservation );

			return new WP_Error(
				'guest_verification_mail_failed',
				__( 'Failed to send the confirmation email. Please check the email address and try again.', 'vk-booking-manager' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'status'     => 'verification_sent',
				'email'      => $email,
				'expires_in' => $ttl,
				'expires_at' => wp_date( 'c', $expires_at ),
			)
		);
	}

	/**
	 * 予約ページを開いたときに、確認リンクのトークンがあれば処理する。
	 *
	 * 結果は get_current_result() で読めるようにし、アクション `vkbm_guest_booking_verified` も発火する。
	 * 結果画面は、予約ブロックが get_public_result() 経由で結果を受け取って表示する。ここでは画面を作らない。
	 */
	public function handle_verification_request(): void {
		// 確認リンクは GET で開く。メールの安全確認の仕組みが先に開くことがあるため、HEAD では予約を作らない。
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}

		// リンクを開いただけの GET で、トークンを持つ本人だけが予約を作る（nonce の対象外）。
		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $raw ) {
			return;
		}

		// 結果は人ごとに違う。キャッシュに残さず、URL のトークンを外部へ送らない。
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// ページキャッシュ系プラグインが共通で参照する定数（WordPress エコシステムの規約）。プレフィックスは付けられない。
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		self::$current_result = $this->process_verification( $raw );

		/**
		 * 確認リンクを処理した結果を知らせる。
		 *
		 * @param array<string,mixed> $result get_current_result() と同じ形の結果。
		 */
		do_action( 'vkbm_guest_booking_verified', self::$current_result );
	}

	/**
	 * 確認リンクのトークンを処理し、予約を作る（または、すでに処理済みならその予約の今の状態を返す）。
	 *
	 * 空きの再確認・同時予約の防止（ロック）・予約の作成・お知らせメールは、会員の予約と同じ処理
	 * （Booking_Confirmation_Controller::create_booking_from_draft()）に任せる。
	 *
	 * @param string $raw_token 生のトークン（メールの URL に入っていた値）。
	 * @return array<string,mixed> 結果。キーは get_current_result() を参照。
	 */
	public function process_verification( string $raw_token ): array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $raw_token ) ) {
			return $this->build_result( self::RESULT_INVALID );
		}

		$token_hash = self::hash_token( $raw_token );

		// すでに使われたリンク: エラーにせず、その予約の今の状態を返す。
		$used = $this->read_used_record( $token_hash );
		if ( null !== $used ) {
			return $this->build_result_from_record( $used, true );
		}

		$pending = get_transient( self::PENDING_PREFIX . $token_hash );
		if ( ! is_array( $pending ) || ! isset( $pending['draft'], $pending['guest'] ) ) {
			return $this->build_result( self::RESULT_EXPIRED );
		}

		// リンクを開いた時点で受付方法が「会員のみ」に戻っていたら、予約を作らない。
		// 確認待ちデータは消さない（受付方法が戻れば、有効期限内はリンクが使える）。
		$settings = $this->settings_repository->get_settings();
		if ( Settings_Repository::BOOKING_CUSTOMER_MODE_MEMBER === Settings_Repository::normalize_booking_customer_mode( $settings[ Settings_Repository::KEY_BOOKING_CUSTOMER_MODE ] ?? null ) ) {
			return $this->build_result( self::RESULT_UNAVAILABLE, array( 'code' => 'guest_booking_disabled' ) );
		}

		// 同じリンクを同時に開かれても、予約を 2 件作らない（安全確認の仕組みと本人が同時に開く場合など）。
		$lock_name  = self::LOCK_PREFIX . $token_hash;
		$lock_value = $this->acquire_lock( $lock_name, self::LOCK_STALE_SECONDS );
		if ( '' === $lock_value ) {
			return $this->build_result( self::RESULT_PROCESSING );
		}

		try {
			// ロックを取る間に別のリクエストが処理を終えていないか確かめ直す。
			$used = $this->read_used_record( $token_hash );
			if ( null !== $used ) {
				return $this->build_result_from_record( $used, true );
			}

			$record = $this->create_booking_from_pending( $pending );

			// 一時的な失敗は、確認待ちデータを残し結果も保存しない（リンクを開き直せば、予約の作成をやり直せる）。
			if ( self::OUTCOME_RETRY === $record['outcome'] ) {
				return $this->build_result(
					self::RESULT_PROCESSING,
					array(
						'code'    => (string) ( $record['code'] ?? '' ),
						'message' => (string) ( $record['message'] ?? '' ),
					)
				);
			}

			// 使ったら確認待ちデータを消す。結果は残し、同じリンクを開き直したときに使う。
			delete_transient( self::PENDING_PREFIX . $token_hash );
			set_transient( self::RESULT_PREFIX . $token_hash, $record, self::get_result_ttl() );
		} finally {
			$this->release_lock( $lock_name, $lock_value );
		}

		return $this->build_result_from_record( $record, false );
	}

	/**
	 * 確認待ちデータから予約を作り、結果の記録を返す。
	 *
	 * @param array<string,mixed> $pending 確認待ちデータ。
	 * @return array<string,mixed> 結果の記録。outcome は created / slot_unavailable / rejected / retry のいずれか。retry は一時的な失敗で、保存しない。
	 */
	private function create_booking_from_pending( array $pending ): array {
		$draft = is_array( $pending['draft'] ) ? $pending['draft'] : array();
		$guest = is_array( $pending['guest'] ) ? $pending['guest'] : array();
		$form  = isset( $pending['form'] ) && is_array( $pending['form'] ) ? $pending['form'] : array();

		$request = new WP_REST_Request( 'POST', '/' . self::REST_NAMESPACE . '/guest-bookings' );
		foreach ( array( 'memo', 'agree_terms', 'agree_cancellation_policy', 'agree_terms_of_service', 'attendee_name' ) as $key ) {
			if ( array_key_exists( $key, $form ) ) {
				$request->set_param( $key, $form[ $key ] );
			}
		}

		$guest['verified_at'] = gmdate( 'Y-m-d H:i:s' );

		$result = $this->confirmation_controller->create_booking_from_draft(
			$draft,
			sanitize_key( (string) ( $pending['draft_token'] ?? '' ) ),
			0,
			false,
			$request,
			$guest
		);

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			// 枠が埋まっていた系（409）は「別の日時で予約する」へ案内する。同じメールアドレスの予約との重複は別の理由として扱う。
			$is_slot = 409 === $status && 'booking_time_conflict' !== $result->get_error_code();

			if ( self::is_temporary_failure( $result ) ) {
				return array(
					'outcome' => self::OUTCOME_RETRY,
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
			}

			return array(
				'outcome' => $is_slot ? self::RESULT_SLOT_UNAVAILABLE : self::RESULT_REJECTED,
				'code'    => (string) $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'outcome'    => 'created',
			'booking_id' => (int) $result['booking_id'],
		);
	}

	/**
	 * 予約を作れなかった理由が、時間を置けば変わりうる一時的な失敗かを返す。
	 *
	 * 一時的（やり直せる）: 予約枠のロックが取れない（booking_busy）、500 番台（投稿の作成失敗など）、
	 * 408・425・429、ステータスの付いていないエラー（決済の確認など外部の差し込み口から返る、種類の分からないもの）。
	 * 恒久的（やり直しても同じ）: 上記以外の 4xx（顧客フラグによる受付停止、重複予約、枠が埋まっていた 409 など）。
	 * 迷うものは一時側に倒す。誤って恒久にすると、確認待ちデータを消して予約できるリンクを失わせるため。
	 * 一時側に倒した誤りは、確認待ちデータの有効期限で止まる。
	 *
	 * @param WP_Error $error create_booking_from_draft() が返したエラー。
	 * @return bool 一時的な失敗なら true。
	 */
	private static function is_temporary_failure( WP_Error $error ): bool {
		if ( 'booking_busy' === $error->get_error_code() ) {
			return true;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		return 0 === $status || $status >= 500 || in_array( $status, array( 408, 425, 429 ), true );
	}

	/**
	 * 使用済みトークンの結果の記録を読む。
	 *
	 * @param string $token_hash トークンの SHA-256。
	 * @return array<string,mixed>|null 記録。無ければ null。
	 */
	private function read_used_record( string $token_hash ): ?array {
		$record = get_transient( self::RESULT_PREFIX . $token_hash );

		return is_array( $record ) && isset( $record['outcome'] ) ? $record : null;
	}

	/**
	 * 結果の記録から、予約ブロックへ渡す結果を作る。
	 *
	 * 予約を作った記録は、予約の今のステータスを読み直して結果にする（開き直したときに今の状態を出すため）。
	 *
	 * @param array<string,mixed> $record 結果の記録。
	 * @param bool                $repeat 同じリンクを開き直したか。
	 * @return array<string,mixed>
	 */
	private function build_result_from_record( array $record, bool $repeat ): array {
		$outcome = (string) $record['outcome'];

		if ( 'created' !== $outcome ) {
			return $this->build_result(
				self::RESULT_SLOT_UNAVAILABLE === $outcome ? self::RESULT_SLOT_UNAVAILABLE : self::RESULT_REJECTED,
				array(
					'code'    => (string) ( $record['code'] ?? '' ),
					'message' => (string) ( $record['message'] ?? '' ),
					'repeat'  => $repeat,
				)
			);
		}

		$booking_id = (int) ( $record['booking_id'] ?? 0 );
		$booking    = $booking_id > 0 ? get_post( $booking_id ) : null;
		$status     = $booking ? (string) get_post_meta( $booking_id, Booking_Overlap::META_STATUS, true ) : '';

		if ( 'confirmed' === $status ) {
			$result = self::RESULT_CONFIRMED;
		} elseif ( 'pending' === $status ) {
			$result = self::RESULT_PENDING;
		} else {
			// キャンセル済み・削除済みなど。
			$result = self::RESULT_BOOKING_CLOSED;
		}

		return $this->build_result(
			$result,
			array(
				'booking_id'     => $booking_id,
				'booking_status' => $status,
				'repeat'         => $repeat,
			)
		);
	}

	/**
	 * 結果の配列を組み立てる。
	 *
	 * @param string              $result 結果（RESULT_*）。
	 * @param array<string,mixed> $extra  追加の値（booking_id・booking_status・repeat・code・message）。
	 * @return array<string,mixed>
	 */
	private function build_result( string $result, array $extra = array() ): array {
		return array_merge(
			array(
				'result'         => $result,
				'booking_id'     => 0,
				'booking_status' => '',
				'repeat'         => false,
				'code'           => '',
				'message'        => '',
			),
			$extra
		);
	}

	/**
	 * メールアドレスごとの回数制限（60 秒に 1 回・24 時間に 5 回）を数える。
	 *
	 * 会員登録の確認メール再送（Email_Verification）と同じ数字。数えるだけで、上限を超えたら false を返す。
	 *
	 * @param string            $email   そろえたメールアドレス。
	 * @param array<string,int> $written 戻り値で、今回書き込んだ window_start と last を受け取る（送信失敗で戻すときの一致確認に使う）。
	 * @return bool 送ってよければ true（回数を 1 つ数えた）。上限を超えていれば false。
	 */
	private function consume_email_limit( string $email, array &$written ): bool {
		$key = self::EMAIL_LIMIT_PREFIX . hash( 'sha256', $email );

		// 同じメールアドレスへの同時リクエストで数え漏れが出ないよう、キー単位の短い排他の中で読み書きする。
		$lock_name  = self::LOCK_PREFIX . 'mail_' . hash( 'sha256', $email );
		$lock_value = $this->acquire_lock_with_retry( $lock_name );
		if ( '' === $lock_value ) {
			// 排他が取れないほど同時に来ている場合は、安全側（制限）に倒す。
			return false;
		}

		try {
			return $this->consume_email_limit_locked( $key, $written );
		} finally {
			$this->release_lock( $lock_name, $lock_value );
		}
	}

	/**
	 * 排他を取った状態で、メールアドレスごとの回数を数える。
	 *
	 * @param string            $key     回数を持つ transient の名前。
	 * @param array<string,int> $written 戻り値で、今回書き込んだ window_start と last を受け取る。
	 * @return bool 送ってよければ true。
	 */
	private function consume_email_limit_locked( string $key, array &$written ): bool {
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$window_start = (int) ( $state['window_start'] ?? 0 );
		$count        = (int) ( $state['count'] ?? 0 );
		$last         = (int) ( $state['last'] ?? 0 );

		if ( $window_start <= 0 || ( $now - $window_start ) >= DAY_IN_SECONDS ) {
			$window_start = $now;
			$count        = 0;
		}

		if ( $last > 0 && ( $now - $last ) < self::EMAIL_COOLDOWN_SECONDS ) {
			return false;
		}
		if ( $count >= self::EMAIL_DAILY_MAX ) {
			return false;
		}

		set_transient(
			$key,
			array(
				'window_start' => $window_start,
				'count'        => $count + 1,
				'last'         => $now,
			),
			max( 1, DAY_IN_SECONDS - ( $now - $window_start ) )
		);
		$written = array(
			'window_start' => $window_start,
			'last'         => $now,
		);

		return true;
	}

	/**
	 * 排他を取る（options テーブルへの INSERT IGNORE によるアトミックな取得）。
	 *
	 * 値は「ランダム文字列|取得時刻」。取れなかったとき、残っている排他が $stale_seconds より古ければ、
	 * 自分が読んだ値のときだけ置き換えて取り直す（致命的エラーやタイムアウトで残った排他が、リンクを永久に使えなくしないため）。
	 * 読み取りはオブジェクトキャッシュを通さず DB から行う。
	 *
	 * @param string $name          オプション名。
	 * @param int    $stale_seconds 古いとみなす秒数。
	 * @return string 取れたときは解放に使う値。取れなかったときは空文字。
	 */
	private function acquire_lock( string $name, int $stale_seconds ): string {
		global $wpdb;

		$value = wp_generate_password( 12, false, false ) . '|' . time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- アトミックな排他の取得のため、キャッシュを通さず直接クエリする。
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			)
		);
		if ( 1 === $inserted ) {
			$this->flush_lock_cache( $name );
			return $value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 排他の今の値を DB から読む。キャッシュを通すと排他にならない。
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $current ) {
			return '';
		}
		$parts  = explode( '|', (string) $current );
		$locked = (int) end( $parts );
		if ( $locked > 0 && ( time() - $locked ) < $stale_seconds ) {
			return '';
		}

		// 自分が見た値のときだけ置き換える。同時に取り直そうとしても、成功するのは一つだけ。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 古い排他の compare-and-swap による取り直し。
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $value ),
			array(
				'option_name'  => $name,
				'option_value' => (string) $current,
			)
		);
		if ( 1 === $updated ) {
			$this->flush_lock_cache( $name );
			return $value;
		}

		return '';
	}

	/**
	 * 取った排他を解放する。自分の値のときだけ消す（取り直された排他を消さないため）。
	 *
	 * @param string $name  オプション名。
	 * @param string $value acquire_lock() が返した値。
	 */
	private function release_lock( string $name, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自分の値のときだけ消す条件付きの削除。
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $value,
			)
		);
		$this->flush_lock_cache( $name );
	}

	/**
	 * 排他のオプションについて、オブジェクトキャッシュに残った値を消す（get_option で古い値を読まないため）。
	 *
	 * 消すのはこのオプションの分だけ。notoptions（存在しないオプションの一覧）は、このオプションの項目だけを外し、
	 * 他のオプションの項目は残す。
	 *
	 * @param string $name オプション名。
	 */
	private function flush_lock_cache( string $name ): void {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * 確認メールを送ってよいか確かめ、送るなら宛先と回数を数える。
	 *
	 * 宛先の数の確認と記録は、下書きのトークン単位の排他の中で行う（同じ下書きからの同時リクエストで、上限を超えないため）。
	 *
	 * @param string                 $draft_token 下書きのトークン。
	 * @param string                 $email       そろえたメールアドレス。
	 * @param array<string,bool|int> $reservation 戻り値で、今回数えた内容を受け取る（送信失敗で戻すときに使う）。recipient_added は宛先一覧に新しく加えたか、window_start と last は今回書き込んだ回数の記録。
	 * @return WP_Error|null 送れないときはエラー。送ってよければ null。
	 */
	private function reserve_send_slot( string $draft_token, string $email, array &$reservation ): ?WP_Error {
		$lock_name  = self::LOCK_PREFIX . 'draft_' . hash( 'sha256', $draft_token );
		$lock_value = $this->acquire_lock_with_retry( $lock_name );
		if ( '' === $lock_value ) {
			// 排他が取れないほど同時に来ている場合は、安全側（制限）に倒す。
			return $this->create_rate_limited_error();
		}

		try {
			// 1 つの下書きから確認メールを送れる宛先の数に上限を設ける（宛先を変えて何通でも送らせないため）。
			if ( ! $this->can_send_to_recipient( $draft_token, $email ) ) {
				return $this->create_rate_limited_error( 'guest_booking_recipient_limit' );
			}

			// メールアドレスごとの回数制限（60 秒に 1 回・24 時間に 5 回）。
			$written = array(
				'window_start' => 0,
				'last'         => 0,
			);
			if ( ! $this->consume_email_limit( $email, $written ) ) {
				return $this->create_rate_limited_error();
			}
			$reservation = array(
				'recipient_added' => $this->record_recipient( $draft_token, $email ),
				'window_start'    => $written['window_start'],
				'last'            => $written['last'],
			);
		} finally {
			$this->release_lock( $lock_name, $lock_value );
		}

		return null;
	}

	/**
	 * 確認メールを送れなかったときに、reserve_send_slot() で数えた宛先とメール送信回数を戻す。
	 *
	 * reserve_send_slot() と同じ順（下書きのトークン単位の排他の中で、メールアドレス単位の排他）で行う。
	 * 排他が取れなかったときは戻さない（回数を多めに数えたままにする、安全側）。
	 * 送信に時間がかかる間に、同じメールアドレスへの別の申し込みが成功して回数の記録が書き換わっていたら、何も戻さない
	 * （成功した分まで戻して、60 秒に 1 回の制限をすり抜けさせないため）。宛先も、回数を戻したときだけ外す。
	 *
	 * @param string                 $draft_token 下書きのトークン。
	 * @param string                 $email       そろえたメールアドレス。
	 * @param array<string,bool|int> $reservation reserve_send_slot() が受け取った内容。
	 */
	private function release_send_slot( string $draft_token, string $email, array $reservation ): void {
		$lock_name  = self::LOCK_PREFIX . 'draft_' . hash( 'sha256', $draft_token );
		$lock_value = $this->acquire_lock_with_retry( $lock_name );
		if ( '' === $lock_value ) {
			return;
		}

		try {
			$restored = $this->release_email_limit( $email, (int) $reservation['window_start'], (int) $reservation['last'] );
			if ( $restored && ! empty( $reservation['recipient_added'] ) ) {
				$this->remove_recipient( $draft_token, $email );
			}
		} finally {
			$this->release_lock( $lock_name, $lock_value );
		}
	}

	/**
	 * メールアドレスごとの回数を 1 つ戻す。直前の送信時刻も消す（失敗した送信を、60 秒の間隔の起点にしないため）。
	 *
	 * 今の記録の window_start と last が、今回書き込んだ値と両方一致するときだけ戻す。
	 *
	 * @param string $email        そろえたメールアドレス。
	 * @param int    $window_start 今回書き込んだ期間の開始時刻。
	 * @param int    $last         今回書き込んだ送信時刻。
	 * @return bool 戻したら true。排他が取れない・記録が書き換わっているときは false。
	 */
	private function release_email_limit( string $email, int $window_start, int $last ): bool {
		$key        = self::EMAIL_LIMIT_PREFIX . hash( 'sha256', $email );
		$lock_name  = self::LOCK_PREFIX . 'mail_' . hash( 'sha256', $email );
		$lock_value = $this->acquire_lock_with_retry( $lock_name );
		if ( '' === $lock_value ) {
			return false;
		}

		try {
			$state = get_transient( $key );
			if ( ! is_array( $state ) || (int) ( $state['window_start'] ?? 0 ) !== $window_start || (int) ( $state['last'] ?? 0 ) !== $last ) {
				return false;
			}

			set_transient(
				$key,
				array(
					'window_start' => $window_start,
					'count'        => max( 0, (int) ( $state['count'] ?? 0 ) - 1 ),
					'last'         => 0,
				),
				max( 1, DAY_IN_SECONDS - ( time() - $window_start ) )
			);

			return true;
		} finally {
			$this->release_lock( $lock_name, $lock_value );
		}
	}

	/**
	 * 排他が取れるまで、短い間隔で数回やり直して取る。
	 *
	 * @param string $name オプション名。
	 * @return string 取れたときは解放に使う値。取れなかったときは空文字。
	 */
	private function acquire_lock_with_retry( string $name ): string {
		$lock_value = '';
		for ( $i = 0; $i < 20 && '' === $lock_value; $i++ ) {
			$lock_value = $this->acquire_lock( $name, 5 );
			if ( '' === $lock_value ) {
				usleep( 50000 );
			}
		}

		return $lock_value;
	}

	/**
	 * 1 つの予約下書きから確認メールを送れる宛先の数の上限を返す。
	 *
	 * @return int 上限。フィルター `vkbm_guest_booking_max_recipients_per_draft` で変更できる（1 未満は 1）。
	 */
	public static function get_max_recipients_per_draft(): int {
		/**
		 * 1 つの予約下書きから確認メールを送れる宛先（メールアドレス）の数の上限。既定は 3。
		 *
		 * @param int $max 上限。
		 */
		return max( 1, (int) apply_filters( 'vkbm_guest_booking_max_recipients_per_draft', self::DEFAULT_MAX_RECIPIENTS_PER_DRAFT ) );
	}

	/**
	 * この下書きから、このメールアドレスへ確認メールを送ってよいかを返す。
	 *
	 * すでに送った宛先への送り直しは、宛先の数を増やさないので許可する。
	 *
	 * @param string $draft_token 下書きのトークン。
	 * @param string $email       そろえたメールアドレス。
	 * @return bool 送ってよければ true。
	 */
	private function can_send_to_recipient( string $draft_token, string $email ): bool {
		$recipients = get_transient( self::DRAFT_RECIPIENTS_PREFIX . hash( 'sha256', $draft_token ) );
		$recipients = is_array( $recipients ) ? $recipients : array();

		return in_array( hash( 'sha256', $email ), $recipients, true ) || count( $recipients ) < self::get_max_recipients_per_draft();
	}

	/**
	 * この下書きから確認メールを送った宛先を記録する。
	 *
	 * @param string $draft_token 下書きのトークン。
	 * @param string $email       そろえたメールアドレス。
	 * @return bool 宛先一覧に新しく加えたら true。すでに入っていたら false。
	 */
	private function record_recipient( string $draft_token, string $email ): bool {
		$key        = self::DRAFT_RECIPIENTS_PREFIX . hash( 'sha256', $draft_token );
		$recipients = get_transient( $key );
		$recipients = is_array( $recipients ) ? $recipients : array();
		$hash       = hash( 'sha256', $email );
		$added      = ! in_array( $hash, $recipients, true );
		if ( $added ) {
			$recipients[] = $hash;
		}
		set_transient( $key, $recipients, DAY_IN_SECONDS );

		return $added;
	}

	/**
	 * この下書きの宛先一覧から、メールアドレスを外す。
	 *
	 * @param string $draft_token 下書きのトークン。
	 * @param string $email       そろえたメールアドレス。
	 */
	private function remove_recipient( string $draft_token, string $email ): void {
		$key        = self::DRAFT_RECIPIENTS_PREFIX . hash( 'sha256', $draft_token );
		$recipients = get_transient( $key );
		if ( ! is_array( $recipients ) ) {
			return;
		}

		$recipients = array_values( array_diff( $recipients, array( hash( 'sha256', $email ) ) ) );
		set_transient( $key, $recipients, DAY_IN_SECONDS );
	}

	/**
	 * 回数制限のエラーを作る。
	 *
	 * @param string $code エラーコード。
	 * @return WP_Error 429 のエラー。
	 */
	private function create_rate_limited_error( string $code = 'rate_limited' ): WP_Error {
		$message = 'guest_booking_recipient_limit' === $code
			? __( 'Confirmation emails cannot be sent to any more email addresses for this reservation. Please reload the reservation page and start again.', 'vk-booking-manager' )
			: __( 'Too many requests. Please try again later.', 'vk-booking-manager' );

		return new WP_Error(
			$code,
			$message,
			array( 'status' => 429 )
		);
	}

	/**
	 * 同じ枠かどうかを比べるための文字列を作る（メニュー・リソース・開始・終了）。
	 *
	 * @param array<string,mixed> $draft 予約の下書き。
	 * @return string
	 */
	private function build_slot_key( array $draft ): string {
		$slot = isset( $draft['slot'] ) && is_array( $draft['slot'] ) ? $draft['slot'] : array();

		return implode(
			'|',
			array(
				(int) ( $draft['menu_id'] ?? 0 ),
				(int) ( $draft['resource_id'] ?? 0 ),
				(string) ( $slot['start_at'] ?? '' ),
				(string) ( $slot['end_at'] ?? '' ),
			)
		);
	}

	/**
	 * 予約を作るときに使うフォームの値を集める。リクエストに無い値は下書きの値を使う。
	 *
	 * @param WP_REST_Request     $request リクエスト。
	 * @param array<string,mixed> $draft   予約の下書き。
	 * @return array<string,mixed> memo・agree_*・attendee_name。
	 */
	private function collect_form_values( WP_REST_Request $request, array $draft ): array {
		$pick = static function ( string $key, $fallback ) use ( $request ) {
			return $request->has_param( $key ) ? $request->get_param( $key ) : $fallback;
		};

		// 配列やオブジェクトが送られても文字列として扱わない。
		$scalar = static function ( $value ): string {
			return is_scalar( $value ) ? (string) $value : '';
		};

		$agree_terms = ! empty( $pick( 'agree_terms', $draft['agree_terms'] ?? false ) );

		return array(
			'memo'                      => Booking_Draft_Controller::truncate_memo( sanitize_textarea_field( $scalar( $pick( 'memo', $draft['memo'] ?? '' ) ) ) ),
			'agree_terms'               => $agree_terms,
			'agree_cancellation_policy' => ! empty( $pick( 'agree_cancellation_policy', $draft['agree_cancellation_policy'] ?? $agree_terms ) ),
			'agree_terms_of_service'    => ! empty( $pick( 'agree_terms_of_service', $draft['agree_terms_of_service'] ?? $agree_terms ) ),
			'attendee_name'             => Attendee_Name::sanitize( $scalar( $pick( 'attendee_name', $draft['attendee_name'] ?? '' ) ) ),
		);
	}

	/**
	 * 確認リンクの土台になる予約ページの URL を返す。
	 *
	 * @return string 予約ページ URL。未設定ならサイトのトップページ。
	 */
	private function get_reservation_page_url(): string {
		$settings = $this->settings_repository->get_settings();
		$url      = isset( $settings['reservation_page_url'] ) ? (string) $settings['reservation_page_url'] : '';

		if ( function_exists( 'vkbm_normalize_reservation_page_url' ) ) {
			$url = vkbm_normalize_reservation_page_url( $url );
		}

		return '' !== $url ? $url : home_url( '/' );
	}
}
