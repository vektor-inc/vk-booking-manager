<?php

/**
 * REST controller for booking draft persistence.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Common\Rate_Limit_Trait;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Throwable;
use function __;
use function add_option;
use function apply_filters;
use function array_slice;
use function delete_option;
use function delete_transient;
use function get_current_user_id;
use function get_option;
use function get_transient;
use function mb_strlen;
use function mb_substr;
use function set_transient;
use function number_format_i18n;
use function get_post_meta;
use function is_user_logged_in;
use function is_ssl;
use function wp_unslash;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function wp_generate_password;
use function usleep;
use function hash_equals;
use function error_log;
use function substr;
use function sha1;
use function time;
use function maybe_serialize;
use function wp_cache_delete;

/**
 * Handles reservation draft persistence via REST API.
 */
class Booking_Draft_Controller {
	use Rate_Limit_Trait;

	private const REST_NAMESPACE      = 'vkbm/v1';
	private const TRANSIENT_PREFIX    = 'vkbm_draft_';
	private const OWNER_INDEX_PREFIX  = 'vkbm_draft_owner_idx_';
	private const TTL_SECONDS         = 1800; // 30 minutes.
	private const OWNER_COOKIE        = 'vkbm_draft_owner';

	// Per-owner soft lock for owner index read-modify-write critical section.
	// owner index 更新（read_owner_index → in-memory 変更 → write_owner_index）の
	// 並列リクエスト時の race condition を抑止するための per-owner ソフトロック。
	// add_option() の wp_options.option_name UNIQUE 制約をアトミック acquire として使い、
	// persistent object cache 非依存（共有レンタルサーバ含む全環境）で動作させる。
	private const OWNER_LOCK_PREFIX      = 'vkbm_draft_owner_lock_';
	private const OWNER_LOCK_TTL_SECONDS = 2;
	private const OWNER_LOCK_MAX_RETRIES = 5;

	// Default upper bound for drafts retained per owner.
	// 1 owner（user_id または owner_cookie）あたりに保持できる予約一時データ件数の既定上限。
	// 家族・グループ予約等で 4〜6 件並行する可能性を想定し 10 件としている。
	// 同一 owner が大量に draft を積むことによる transient 増殖（ゆっくり DoS）を緩和する目的。
	// Cookie を入れ替えながら別 owner として振る舞う攻撃は本クォータでは捕捉できないため、
	// IP 単位のレート制限（Rate_Limit_Trait）と併用して耐性を担保する。
	private const MAX_DRAFTS_PER_OWNER = 10;

	// Rate limiting thresholds for unauthenticated draft endpoints.
	// 認証不要の予約一時データ用エンドポイントに対するレート制限値。
	private const RATE_LIMIT_SAVE_MAX      = 30;
	private const RATE_LIMIT_SAVE_WINDOW   = 60;
	private const RATE_LIMIT_READ_MAX      = 60;
	private const RATE_LIMIT_READ_WINDOW   = 60;
	private const RATE_LIMIT_DELETE_MAX    = 30;
	private const RATE_LIMIT_DELETE_WINDOW = 60;

	// Static maximum lengths for draft input fields. Applied after sanitize_*()
	// to cap each wp_options record size and mitigate DoS via oversized payloads.
	// 予約一時データ入力フィールドに対する静的な文字数上限。
	// sanitize_*() の後に適用し、wp_options レコードの肥大化による DoS を抑止する。
	public const MEMO_MAX_LENGTH                  = 1000;
	// memo の絶対上限。vkbm_draft_memo_max_length フィルタが極端に大きな値を返しても
	// この値を超えないようクランプし、DoS 経路の再オープンを防ぐ。
	// Absolute hard cap for memo length. Even if a third-party filter returns a huge value,
	// the effective limit is clamped to this constant to prevent reopening the DoS path.
	public const MEMO_HARD_MAX_LENGTH             = 10000;
	public const LABEL_MAX_LENGTH                 = 200;
	public const SLOT_FIELD_MAX_LENGTH            = 64;
	public const ASSIGNABLE_STAFF_IDS_MAX_COUNT   = 50;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings_repository;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null $settings_repository Provider settings repository.
	 */
	public function __construct( ?Settings_Repository $settings_repository = null ) {
		$this->settings_repository = $settings_repository ?? new Settings_Repository();
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
		// Drafts are intentionally accessible without authentication so that
		// anonymous visitors can prepare a reservation before logging in.
		// Authorization is enforced inside each callback via owner cookie /
		// user ID matching, and a per-IP rate limit is applied to mitigate abuse.
		// 予約一時データは未ログインユーザーでも作成できる必要があるため、
		// permission_callback は意図的に __return_true としている。
		// 認可はコールバック内で所有者Cookie/ユーザーIDの一致チェックを行い、
		// 加えてIP単位の簡易レート制限で乱用を抑止する。
		register_rest_route(
			self::REST_NAMESPACE,
			'/drafts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_draft' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/drafts/(?P<token>[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_draft' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_draft' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Persist reservation draft data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_draft( WP_REST_Request $request ) {
		// Apply per-IP rate limiting before doing any meaningful work to mitigate DoS via draft writes.
		// DoS緩和のため、処理に入る前にIP単位のレート制限を適用する。
		if ( ! $this->consume_rate_limit_token( 'draft_save', self::RATE_LIMIT_SAVE_MAX, self::RATE_LIMIT_SAVE_WINDOW ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please try again later.', 'vk-booking-manager' ),
				array( 'status' => 429 )
			);
		}

		$params      = $request->get_json_params();
		$menu_id     = isset( $params['menu_id'] ) ? (int) $params['menu_id'] : 0;
		$resource_id = isset( $params['resource_id'] ) ? max( 0, (int) $params['resource_id'] ) : 0;
		// date は他の日時系と同じく 64 文字でキャップ（DoS 経路防止）。
		// Cap top-level date to the same length as other datetime/slot identifier fields.
		$date        = isset( $params['date'] ) ? sanitize_text_field( (string) $params['date'] ) : '';
		$date        = $this->truncate_text( $date, self::SLOT_FIELD_MAX_LENGTH );

		if ( $menu_id <= 0 ) {
			return new WP_Error( 'invalid_menu_id', __( 'Menu ID is invalid.', 'vk-booking-manager' ) );
		}

		$tax_enabled = true;
		$tax_rate    = 0.0;

		// memo は sanitize 済み文字列を静的上限で切り詰める（フィルタで上書き可能）。
		// memo: sanitize then truncate using filterable static maximum length.
		$memo                = isset( $params['memo'] ) ? sanitize_textarea_field( (string) $params['memo'] ) : '';
		$memo                = $this->truncate_text( $memo, $this->get_memo_max_length() );
		$agreed              = ! empty( $params['agree_terms'] );
		$agreed_cancellation = array_key_exists( 'agree_cancellation_policy', $params )
			? ! empty( $params['agree_cancellation_policy'] )
			: $agreed;
		$agreed_tos          = array_key_exists( 'agree_terms_of_service', $params )
			? ! empty( $params['agree_terms_of_service'] )
			: $agreed;
		// menu_label / staff_label は sanitize 後にラベル用の静的上限で切り詰める。
		// Apply static label length cap after sanitize.
		$menu_label          = isset( $params['menu_label'] ) ? sanitize_text_field( (string) $params['menu_label'] ) : '';
		$menu_label          = $this->truncate_text( $menu_label, self::LABEL_MAX_LENGTH );
		$staff_label         = isset( $params['staff_label'] ) ? sanitize_text_field( (string) $params['staff_label'] ) : '';
		$staff_label         = $this->truncate_text( $staff_label, self::LABEL_MAX_LENGTH );
		$is_staff_preferred  = ! empty( $params['is_staff_preferred'] );

		// 指名機能が無効の場合、指名フラグを強制的に false にする。
		if ( ! Staff_Editor::is_nomination_enabled() ) {
			$is_staff_preferred = false;
		}

		$slot                = isset( $params['slot'] ) && is_array( $params['slot'] ) ? $params['slot'] : array();
		// slot 系の識別子・日時文字列は sanitize 後に SLOT_FIELD_MAX_LENGTH で切り詰める。
		// Slot identifier and timestamp strings: sanitize then truncate.
		$slot_id             = isset( $slot['slot_id'] ) ? sanitize_text_field( (string) $slot['slot_id'] ) : '';
		$slot_id             = $this->truncate_text( $slot_id, self::SLOT_FIELD_MAX_LENGTH );
		$start_at            = isset( $slot['start_at'] ) ? sanitize_text_field( (string) $slot['start_at'] ) : '';
		$start_at            = $this->truncate_text( $start_at, self::SLOT_FIELD_MAX_LENGTH );
		$end_at              = isset( $slot['end_at'] ) ? sanitize_text_field( (string) $slot['end_at'] ) : '';
		$end_at              = $this->truncate_text( $end_at, self::SLOT_FIELD_MAX_LENGTH );
		$service_end         = isset( $slot['service_end_at'] ) ? sanitize_text_field( (string) $slot['service_end_at'] ) : '';
		$service_end         = $this->truncate_text( $service_end, self::SLOT_FIELD_MAX_LENGTH );
		$duration            = isset( $slot['duration_minutes'] ) ? max( 0, (int) $slot['duration_minutes'] ) : 0;
		$slot_staff          = isset( $slot['staff'] ) && is_array( $slot['staff'] ) ? $slot['staff'] : null;
		// slot.staff.name もラベル系として 200 文字でキャップ（DoS 経路防止）。
		// Cap slot.staff.name with the label length limit to prevent oversize wp_options payloads.
		$slot_staff          = $slot_staff
			? array(
				'id'   => isset( $slot_staff['id'] ) ? (int) $slot_staff['id'] : 0,
				'name' => $this->truncate_text(
					sanitize_text_field( (string) ( $slot_staff['name'] ?? '' ) ),
					self::LABEL_MAX_LENGTH
				),
			)
			: null;
		// slot.staff_label はラベル用上限を適用。
		// slot.staff_label uses the label length cap.
		$slot_staff_label    = isset( $slot['staff_label'] ) ? sanitize_text_field( (string) $slot['staff_label'] ) : '';
		$slot_staff_label    = $this->truncate_text( $slot_staff_label, self::LABEL_MAX_LENGTH );
		// 巨大入力時の CPU 負荷を抑えるため、重複判定はハッシュセット（O(1)）で行い、
		// 上限件数に達した時点でループを打ち切る（同 ID の大量送信での回避も封じる）。
		// Use a hash set for O(1) deduplication and break out of the loop as soon as
		// the cap is reached, to keep per-request CPU cost bounded even for huge inputs.
		$assignable_staff = array();
		$seen_assignable  = array();

		if ( isset( $slot['assignable_staff_ids'] ) && is_array( $slot['assignable_staff_ids'] ) ) {
			foreach ( $slot['assignable_staff_ids'] as $candidate ) {
				$candidate = (int) $candidate;
				if ( $candidate <= 0 || isset( $seen_assignable[ $candidate ] ) ) {
					continue;
				}

				$seen_assignable[ $candidate ] = true;
				$assignable_staff[]            = $candidate;

				if ( count( $assignable_staff ) >= self::ASSIGNABLE_STAFF_IDS_MAX_COUNT ) {
					break;
				}
			}
		}

		$auto_assign = isset( $slot['auto_assign'] ) ? (bool) $slot['auto_assign'] : false;

		if ( '' === $slot_id || '' === $start_at ) {
			return new WP_Error( 'invalid_slot', __( 'Reservation slot information is incorrect.', 'vk-booking-manager' ) );
		}

		$meta     = isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : array();
		// meta.timezone も SLOT_FIELD_MAX_LENGTH（64 文字）でキャップ。
		// meta.timezone is also capped by the slot-field length limit.
		$timezone = isset( $meta['timezone'] ) ? sanitize_text_field( (string) $meta['timezone'] ) : '';
		$timezone = $this->truncate_text( $timezone, self::SLOT_FIELD_MAX_LENGTH );

		$token = $this->sanitize_token( isset( $params['token'] ) ? (string) $params['token'] : '' );
		if ( '' === $token ) {
			$token = $this->generate_token();
		}

		if ( '' === $staff_label && $resource_id <= 0 ) {
			$staff_label = vkbm_get_no_nomination_label();
		}

		$effective_slot_staff_label = '' !== $slot_staff_label ? $slot_staff_label : $staff_label;

			$nomination_fee         = 0;
			$disable_nomination_fee = (string) get_post_meta( $menu_id, '_vkbm_disable_nomination_fee', true );
		if ( '1' !== $disable_nomination_fee && $is_staff_preferred && $resource_id > 0 ) {
			$nomination_fee = $this->get_staff_nomination_fee( $resource_id );
		}

		$payload = array(
			'menu_id'                   => $menu_id,
			'resource_id'               => $resource_id,
			'date'                      => $date,
			'slot'                      => array(
				'slot_id'              => $slot_id,
				'start_at'             => $start_at,
				'end_at'               => $end_at,
				'service_end_at'       => $service_end,
				'duration_minutes'     => $duration,
				'staff'                => $slot_staff,
				'staff_label'          => $effective_slot_staff_label,
				'assignable_staff_ids' => $assignable_staff,
				'auto_assign'          => $auto_assign || ( $resource_id <= 0 ),
			),
			'meta'                      => array(
				'timezone' => $timezone,
			),
			'memo'                      => $memo,
			'agree_terms'               => ( $agreed_cancellation && $agreed_tos ),
			'agree_cancellation_policy' => $agreed_cancellation,
			'agree_terms_of_service'    => $agreed_tos,
			'menu_label'                => $menu_label,
			'staff_label'               => $staff_label,
			'is_staff_preferred'        => $is_staff_preferred,
			'nomination_fee'            => $nomination_fee,
			'owner_user_id'             => (int) get_current_user_id(),
		);

		if ( 0 === $payload['owner_user_id'] ) {
			$payload['owner_key'] = $this->ensure_owner_cookie();
		}

		// Resolve a stable owner identifier (user_id 優先、未ログイン時は owner_cookie)
		// and enforce a FIFO quota so that a single owner cannot accumulate
		// unbounded transients via repeated draft writes.
		// 同一 owner（user_id または owner_cookie）あたりの予約一時データ保持数を
		// FIFO で制限し、同一所有者による transient 増殖（ゆっくり DoS）を緩和する。
		// 別 owner に化ける攻撃には IP レート制限（Rate_Limit_Trait）と併せて対処する。
		$owner_id   = $this->resolve_owner_id( $payload );
		$created_at = time();
		if ( '' !== $owner_id ) {
			// 同一 token を別 owner で再保存する場合（匿名 → ログイン等で所有者が変わるケース）、
			// 旧 owner index に古いエントリが残り続けると、後で旧 owner 側の evict が走った時に
			// 現行 draft の transient が誤って削除される可能性がある。
			// 新規 enforce 前に旧 owner index 側から該当 token を除去して整合性を保つ。
			// When the same token is re-saved with a different owner (e.g. anonymous → logged-in),
			// remove the token from the previous owner's index to prevent stale entries from
			// later causing the active draft to be incorrectly evicted by the previous owner's quota.
			$existing_payload = get_transient( $this->build_transient_key( $token ) );
			if ( is_array( $existing_payload ) ) {
				$previous_owner_id = $this->resolve_owner_id( $existing_payload );
				if ( '' !== $previous_owner_id && $previous_owner_id !== $owner_id ) {
					$this->remove_token_from_owner_index( $previous_owner_id, $token );
				}
			}
			$this->enforce_draft_quota_per_owner( $owner_id, $token, $created_at );
		}

		set_transient( $this->build_transient_key( $token ), $payload, self::TTL_SECONDS );

		return new WP_REST_Response(
			array(
				'token'      => $token,
				'expires_in' => self::TTL_SECONDS,
			)
		);
	}

	/**
	 * Retrieve existing draft data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_draft( WP_REST_Request $request ) {
		// Apply per-IP rate limiting before token enumeration is possible.
		// トークン総当たり等の乱用を抑えるため、最初にレート制限を適用する。
		if ( ! $this->consume_rate_limit_token( 'draft_read', self::RATE_LIMIT_READ_MAX, self::RATE_LIMIT_READ_WINDOW ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please try again later.', 'vk-booking-manager' ),
				array( 'status' => 429 )
			);
		}

		$token = $this->sanitize_token( (string) $request['token'] );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', __( 'Token is invalid.', 'vk-booking-manager' ) );
		}

		$payload = get_transient( $this->build_transient_key( $token ) );
		if ( false === $payload ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		$payload = $this->backfill_draft_owner( $token, $payload );

		if ( ! $this->can_access_draft( $payload ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		unset( $payload['owner_user_id'], $payload['owner_key'] );

		$price_snapshot = $this->build_menu_price_snapshot( isset( $payload['menu_id'] ) ? (int) $payload['menu_id'] : 0 );
		$tax_enabled    = (bool) ( $price_snapshot['tax_enabled'] ?? false );

		if ( empty( $price_snapshot ) ) {
			$tax_enabled = true;
		}

		if ( ! empty( $price_snapshot ) ) {
			$payload['menu_price']              = $price_snapshot['display_price'];
			$payload['menu_price_base']         = $price_snapshot['base_price'];
			$payload['menu_price_formatted']    = $price_snapshot['formatted'];
			$payload['menu_price_tax_included'] = $price_snapshot['tax_enabled'];
			$payload['menu_price_tax_rate']     = $price_snapshot['tax_rate'];
			$payload['menu_price_currency']     = $price_snapshot['currency'];
		}

		if ( ! Staff_Editor::is_nomination_enabled() ) {
			$payload['nomination_fee'] = 0;
		}

		$payload['nomination_fee']           = (int) ( $payload['nomination_fee'] ?? 0 );
		$payload['nomination_fee_formatted'] = $this->format_currency_label(
			$payload['nomination_fee'],
			$tax_enabled
		);

		$base_display_price               = (int) ( $price_snapshot['display_price'] ?? 0 );
		$total_price                      = $base_display_price + $payload['nomination_fee'];
		$payload['total_price']           = $total_price;
		$payload['total_price_formatted'] = $this->format_currency_label( $total_price, $tax_enabled );

		return new WP_REST_Response( $payload );
	}

	/**
	 * Delete an existing draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_draft( WP_REST_Request $request ) {
		// Apply per-IP rate limiting to mitigate brute-force deletion attempts.
		// 削除APIへの総当たり攻撃を緩和するため、最初にレート制限を適用する。
		if ( ! $this->consume_rate_limit_token( 'draft_delete', self::RATE_LIMIT_DELETE_MAX, self::RATE_LIMIT_DELETE_WINDOW ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please try again later.', 'vk-booking-manager' ),
				array( 'status' => 429 )
			);
		}

		$token = $this->sanitize_token( (string) $request['token'] );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', __( 'Token is invalid.', 'vk-booking-manager' ) );
		}

		$payload = get_transient( $this->build_transient_key( $token ) );
		if ( false === $payload ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		$payload = $this->backfill_draft_owner( $token, $payload );

		if ( ! $this->can_access_draft( $payload ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		delete_transient( $this->build_transient_key( $token ) );

		// owner index 側からも該当 token を取り除き、整合性を保つ。
		// Remove the deleted token from the owner index to keep it in sync.
		$owner_id = $this->resolve_owner_id( $payload );
		if ( '' !== $owner_id ) {
			$this->remove_token_from_owner_index( $owner_id, $token );
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Build a price snapshot for the selected menu.
	 *
	 * @param int $menu_id Menu ID.
	 * @return array<string, mixed>
	 */
	private function build_menu_price_snapshot( int $menu_id ): array {
		if ( $menu_id <= 0 ) {
			return array();
		}

		$raw_price = get_post_meta( $menu_id, '_vkbm_base_price', true );

		if ( '' === $raw_price || ! is_numeric( $raw_price ) ) {
			return array();
		}

		$base_price = max( 0, (int) $raw_price );

		$tax_enabled = true;
		$tax_rate    = 0.0;

		$display_price = $base_price;

		$formatted = VKBM_Helper::format_currency( (int) $display_price );

		if ( $tax_enabled ) {
			$tax_label = VKBM_Helper::get_tax_included_label();
			if ( '' !== $tax_label ) {
				$formatted .= $tax_label;
			}
		}

		return array(
			'base_price'    => $base_price,
			'display_price' => $display_price,
			'formatted'     => $formatted,
			'tax_enabled'   => $tax_enabled,
			'tax_rate'      => $tax_rate,
			'currency'      => 'JPY',
		);
	}

	/**
	 * Retrieve nomination fee for a staff member.
	 *
	 * @param int $staff_id Staff post ID.
	 * @return int
	 */
	private function get_staff_nomination_fee( int $staff_id ): int {
		if ( $staff_id <= 0 ) {
			return 0;
		}

		if ( ! Staff_Editor::is_nomination_enabled() ) {
			return 0;
		}

		$raw = get_post_meta( $staff_id, Staff_Editor::META_NOMINATION_FEE, true );

		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		$fee = (int) $raw;

		return $fee > 0 ? $fee : 0;
	}

	/**
	 * Format a currency amount.
	 *
	 * @param int  $amount       Amount in yen.
	 * @param bool $tax_included Whether tax is included.
	 * @return string
	 */
	private function format_currency_label( int $amount, bool $tax_included ): string {
		$label = VKBM_Helper::format_currency( $amount );

		if ( $tax_included ) {
			$tax_label = VKBM_Helper::get_tax_included_label();
			if ( '' !== $tax_label ) {
				$label .= $tax_label;
			}
		}

		return $label;
	}

	/**
	 * Truncate a string to a static maximum length using multibyte-safe substring.
	 *
	 * 入力済み（sanitize 済み）の文字列を指定文字数で安全に切り詰めます。
	 * 上限値が 0 以下の場合は空文字を返します。
	 *
	 * mbstring 拡張が無効な環境でも UTF-8 のマルチバイト境界を壊さないよう、
	 * `mb_*` が利用できない場合は `preg_split('//u', ...)` でコードポイント単位に
	 * 分割してから切り詰める実装にフォールバックします。
	 *
	 * @param string $value     Sanitized string to truncate.
	 * @param int    $max_length Maximum allowed length in characters.
	 * @return string
	 */
	private function truncate_text( string $value, int $max_length ): string {
		if ( $max_length <= 0 ) {
			return '';
		}

		// mbstring 拡張が利用可能ならそれを優先（UTF-8 セーフかつ高速）。
		// Prefer mbstring when available: UTF-8 safe and faster.
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) <= $max_length ) {
				return $value;
			}

			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}

		// フォールバック: コードポイント単位に分割して切り詰める。
		// mbstring が無い環境でも UTF-8 のマルチバイト境界を破壊しないように
		// preg_split('//u', ...) を使用する（単純な substr() ではバイト境界で
		// 切ってしまい不正なマルチバイト列が残る可能性があるため避ける）。
		$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $characters ) ) {
			// preg_split が失敗した場合は安全側に倒し、空文字を返す。
			// If preg_split fails (e.g. malformed UTF-8), return an empty string to stay on the safe side.
			return '';
		}

		if ( count( $characters ) <= $max_length ) {
			return $value;
		}

		return implode( '', array_slice( $characters, 0, $max_length ) );
	}

	/**
	 * Resolve the maximum length applied to the memo field.
	 *
	 * `vkbm_draft_memo_max_length` フィルタで上書き可能。
	 * フィルタ値が int 化後 0 以下のときは既定値にフォールバックし、
	 * 極端に大きな値が返された場合は MEMO_HARD_MAX_LENGTH でクランプする。
	 *
	 * フロント側（予約ブロックの textarea maxlength）からも同じ解決結果を
	 * 参照できるよう public static として公開する。
	 *
	 * @return int
	 */
	public static function resolve_memo_max_length(): int {
		/**
		 * Filter the maximum length applied to the draft memo field.
		 *
		 * @param int $max_length Default memo maximum length in characters.
		 */
		$filtered = (int) apply_filters( 'vkbm_draft_memo_max_length', self::MEMO_MAX_LENGTH );

		if ( $filtered <= 0 ) {
			return self::MEMO_MAX_LENGTH;
		}

		// 第三者フィルタが巨大値を返しても DoS 経路を再オープンしないよう絶対上限でクランプ。
		// Clamp to the hard cap so a third-party filter cannot reopen the DoS path.
		return min( $filtered, self::MEMO_HARD_MAX_LENGTH );
	}

	/**
	 * Internal accessor that delegates to the public resolver.
	 *
	 * @return int
	 */
	private function get_memo_max_length(): int {
		return self::resolve_memo_max_length();
	}

	/**
	 * Sanitize token string.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function sanitize_token( string $token ): string {
		$token = sanitize_key( $token );
		return ( '' !== $token ) ? $token : '';
	}

	/**
	 * Generate new token.
	 *
	 * @return string
	 */
	private function generate_token(): string {
		return strtolower( wp_generate_password( 16, false, false ) );
	}

	/**
	 * Build transient key.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function build_transient_key( string $token ): string {
		return self::TRANSIENT_PREFIX . $token;
	}

	/**
	 * Ensure draft owner cookie exists for anonymous users.
	 *
	 * 未ログインユーザー向けに予約一時データ所有者Cookieを設定します。
	 *
	 * @return string
	 */
	private function ensure_owner_cookie(): string {
		$owner_key = $this->get_owner_cookie_value();
		if ( '' !== $owner_key ) {
			return $owner_key;
		}

		$owner_key = strtolower( wp_generate_password( 20, false, false ) );
		$this->set_owner_cookie( $owner_key );

		return $owner_key;
	}

	/**
	 * Read the current draft owner cookie value.
	 *
	 * 現在の予約一時データ所有者Cookieの値を取得します。
	 *
	 * @return string
	 */
	private function get_owner_cookie_value(): string {
		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return '';
		}

		$value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );

		return '' !== $value ? $value : '';
	}

	/**
	 * Set the draft owner cookie.
	 *
	 * 予約一時データ所有者Cookieを設定します。
	 *
	 * @param string $value Cookie value.
	 * @return void
	 */
	private function set_owner_cookie( string $value ): void {
		if ( headers_sent() ) {
			return;
		}

		$cookie_path   = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$cookie_domain = defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
		setcookie(
			self::OWNER_COOKIE,
			$value,
			time() + self::TTL_SECONDS,
			$cookie_path,
			$cookie_domain,
			is_ssl(),
			true
		);
	}

	/**
	 * Backfill draft ownership data for older drafts.
	 *
	 * 既存予約一時データに所有者情報を補完します。
	 *
	 * @param string $token Temporary reservation data token.
	 * @param mixed  $payload Temporary reservation data payload.
	 * @return array<string, mixed>
	 */
	private function backfill_draft_owner( string $token, $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		$owner_key     = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';

		if ( $owner_user_id > 0 || '' !== $owner_key ) {
			return $payload;
		}

		$payload['owner_user_id'] = (int) get_current_user_id();
		if ( 0 === $payload['owner_user_id'] ) {
			$payload['owner_key'] = $this->ensure_owner_cookie();
		}

		set_transient( $this->build_transient_key( $token ), $payload, self::TTL_SECONDS );

		return $payload;
	}

	/**
	 * Check whether the current requester can access the draft.
	 *
	 * 現在のリクエストが予約一時データにアクセス可能か検証します。
	 *
	 * @param mixed $payload Temporary reservation data payload.
	 * @return bool
	 */
	private function can_access_draft( $payload ): bool {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		if ( $owner_user_id > 0 ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			return (int) get_current_user_id() === $owner_user_id;
		}

		$owner_key = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';
		if ( '' === $owner_key ) {
			return false;
		}

		$cookie_value = $this->get_owner_cookie_value();
		if ( '' === $cookie_value ) {
			return false;
		}

		// タイミング攻撃対策のため hash_equals を使用する。
		// Use hash_equals to mitigate timing attacks when comparing the owner key against the cookie value.
		return hash_equals( $owner_key, $cookie_value );
	}

	/**
	 * Resolve a stable owner identifier from a draft payload.
	 *
	 * 予約一時データの所有者を一意に表す識別子文字列を生成します。
	 * ログインユーザーは `user:<id>`、未ログインユーザーは `cookie:<owner_key>` の形式で返します。
	 * 名前空間を分離することで「ユーザーIDが偶然 cookie キーと一致する」ケースを防ぎます。
	 *
	 * @param array<string, mixed> $payload Draft payload.
	 * @return string Owner identifier (empty string when not resolvable).
	 */
	private function resolve_owner_id( array $payload ): string {
		$user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		if ( $user_id > 0 ) {
			return 'user:' . $user_id;
		}

		$owner_key = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';
		if ( '' !== $owner_key ) {
			return 'cookie:' . $owner_key;
		}

		return '';
	}

	/**
	 * Build the transient key for an owner index.
	 *
	 * owner index 用 transient のキーを組み立てます。owner_id を sha1 でハッシュ化することで、
	 * 長さやキャラクタ制約の影響を受けないキー長に正規化します。
	 *
	 * @param string $owner_id Owner identifier returned by resolve_owner_id().
	 * @return string Transient key for the owner index.
	 */
	private function build_owner_index_key( string $owner_id ): string {
		return self::OWNER_INDEX_PREFIX . sha1( $owner_id );
	}

	/**
	 * Get the configured per-owner draft quota.
	 *
	 * フィルタ `vkbm_draft_max_per_owner` で上書きできる owner 単位の保持上限を取得します。
	 * 異常値（0 以下や非数値）が返された場合は既定値（10）にフォールバックします。
	 *
	 * @return int Maximum drafts retained per owner (>= 1).
	 */
	private function get_draft_quota_per_owner(): int {
		/**
		 * Filter the maximum number of drafts retained per owner.
		 *
		 * 1 owner（user_id or owner_cookie）あたりに保持する予約一時データ件数の上限。
		 *
		 * @param int $max Default maximum (10).
		 */
		$max = apply_filters( 'vkbm_draft_max_per_owner', self::MAX_DRAFTS_PER_OWNER );

		$max = is_numeric( $max ) ? (int) $max : self::MAX_DRAFTS_PER_OWNER;

		if ( $max < 1 ) {
			$max = self::MAX_DRAFTS_PER_OWNER;
		}

		return $max;
	}

	/**
	 * Read the owner index transient as a normalized array.
	 *
	 * owner index transient を読み込み、配列形式（{ token, created_at } の FIFO）に正規化します。
	 * 既存値が想定形式でない場合は空配列として扱います。
	 *
	 * @param string $owner_id Owner identifier.
	 * @return array<int, array{token: string, created_at: int}>
	 */
	private function read_owner_index( string $owner_id ): array {
		$raw = get_transient( $this->build_owner_index_key( $owner_id ) );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$token = isset( $entry['token'] ) ? $this->sanitize_token( (string) $entry['token'] ) : '';
			if ( '' === $token ) {
				continue;
			}

			$created_at = isset( $entry['created_at'] ) ? (int) $entry['created_at'] : 0;

			$normalized[] = array(
				'token'      => $token,
				'created_at' => $created_at,
			);
		}

		return $normalized;
	}

	/**
	 * Persist the owner index transient with the existing TTL.
	 *
	 * owner index transient を書き込み、空の場合は明示的に削除します。
	 * TTL は draft 本体と同じ {@see self::TTL_SECONDS} を使い、自然に有効期限が揃うようにします。
	 *
	 * @param string                                              $owner_id Owner identifier.
	 * @param array<int, array{token: string, created_at: int}>   $index    Normalized index entries.
	 * @return void
	 */
	private function write_owner_index( string $owner_id, array $index ): void {
		$key = $this->build_owner_index_key( $owner_id );

		if ( empty( $index ) ) {
			delete_transient( $key );
			return;
		}

		// 配列が連番インデックスになるよう詰め直す。
		// Re-index numerically so that subsequent FIFO operations behave predictably.
		set_transient( $key, array_values( $index ), self::TTL_SECONDS );
	}

	/**
	 * Enforce the per-owner draft quota by FIFO eviction.
	 *
	 * 新規 draft を登録する前に呼び出し、上限超過時は最古の draft（先頭エントリ）から
	 * `delete_transient()` で静かに削除します。エラーは返しません。
	 * 削除後、新しい token を末尾に追加し owner index を更新します。
	 *
	 * @param string $owner_id   Owner identifier (must be non-empty).
	 * @param string $new_token  Token of the draft being created.
	 * @param int    $created_at UNIX timestamp at which the new draft is created.
	 * @return void
	 */
	private function enforce_draft_quota_per_owner( string $owner_id, string $new_token, int $created_at ): void {
		if ( '' === $owner_id || '' === $new_token ) {
			return;
		}

		// owner index の read-modify-write を per-owner ソフトロックで直列化し、
		// 同一 owner からの並列 POST /vkbm/v1/drafts による index エントリの消失・重複を抑止する。
		// Serialize the read-modify-write on the owner index with a per-owner soft lock
		// so that concurrent POST /vkbm/v1/drafts from the same owner can no longer
		// lose or duplicate index entries.
		$this->with_owner_lock(
			$owner_id,
			function () use ( $owner_id, $new_token, $created_at ): void {
				$max   = $this->get_draft_quota_per_owner();
				$index = $this->read_owner_index( $owner_id );

				// 同一 token の更新（上書き保存）は重複登録を避けるため一旦除外する。
				// Drop any existing entry for the same token so updates don't double-count.
				$index = array_values(
					array_filter(
						$index,
						static function ( array $entry ) use ( $new_token ): bool {
							return $entry['token'] !== $new_token;
						}
					)
				);

				// 既存件数が上限以上の場合は、上限に収まるまで先頭（最古）から削除する。
				// 上限 N に対して、これから 1 件追加するため (N - 1) 件以下になるまで FIFO で evict する。
				// While we are about to append one entry, evict from the head until we leave room for it.
				while ( count( $index ) >= $max ) {
					$oldest = array_shift( $index );
					if ( is_array( $oldest ) && isset( $oldest['token'] ) && '' !== $oldest['token'] ) {
						delete_transient( $this->build_transient_key( (string) $oldest['token'] ) );
					}
				}

				$index[] = array(
					'token'      => $new_token,
					'created_at' => $created_at,
				);

				$this->write_owner_index( $owner_id, $index );
			}
		);
	}

	/**
	 * Remove a specific token from the owner index.
	 *
	 * delete_draft 実行時など、特定 token を owner index から取り除きます。
	 * 結果として空になった場合は transient ごと削除し、不要なゴミを残しません。
	 *
	 * @param string $owner_id Owner identifier.
	 * @param string $token    Token to remove.
	 * @return void
	 */
	private function remove_token_from_owner_index( string $owner_id, string $token ): void {
		if ( '' === $owner_id || '' === $token ) {
			return;
		}

		// enforce_draft_quota_per_owner と同じ理由で per-owner ソフトロックで直列化する。
		// 取得失敗時はログのみ出して何もしない（次回 enforce_draft_quota_per_owner で自然整合）。
		// Serialize with the same per-owner soft lock as enforce_draft_quota_per_owner.
		// If the lock cannot be acquired, log a warning and skip; the next enforce_draft_quota_per_owner
		// call will reconcile the index naturally.
		$this->with_owner_lock(
			$owner_id,
			function () use ( $owner_id, $token ): void {
				$index = $this->read_owner_index( $owner_id );
				if ( empty( $index ) ) {
					return;
				}

				$filtered = array_values(
					array_filter(
						$index,
						static function ( array $entry ) use ( $token ): bool {
							return $entry['token'] !== $token;
						}
					)
				);

				// 件数に変更がなければ書き戻し不要（不要な書き込みを避ける）。
				// Avoid unnecessary writes when the index did not change.
				if ( count( $filtered ) === count( $index ) ) {
					return;
				}

				$this->write_owner_index( $owner_id, $filtered );
			}
		);
	}

	/**
	 * Build the option key for the per-owner soft lock.
	 *
	 * owner index 更新クリティカルセクション用のロックキーを組み立てます。
	 * owner_id を sha1 でハッシュ化することで、長さやキャラクタ制約の影響を受けないキー長に正規化します。
	 *
	 * @param string $owner_id Owner identifier returned by resolve_owner_id().
	 * @return string Option key for the owner lock.
	 */
	private function build_owner_lock_key( string $owner_id ): string {
		return self::OWNER_LOCK_PREFIX . sha1( $owner_id );
	}

	/**
	 * Atomically acquire a per-owner soft lock for owner index updates.
	 *
	 * 並列リクエスト時の race condition 対策として、`add_option()` の wp_options.option_name
	 * UNIQUE 制約をアトミック acquire に流用する per-owner ソフトロックを取得します。
	 * persistent object cache 非依存（共有レンタルサーバ含む全環境）で動作する点が採用理由です。
	 *
	 * 既にロックが取得済みでも、`OWNER_LOCK_TTL_SECONDS` を超えた stale lock であれば破棄して
	 * 再取得を試みます（プロセス異常終了などで解放されなかった場合の自己回復）。
	 *
	 * @param string $owner_id Owner identifier returned by resolve_owner_id().
	 * @return string|null Lock token on success, or null when the lock is held by another request.
	 */
	private function acquire_owner_lock( string $owner_id ): ?string {
		$key   = $this->build_owner_lock_key( $owner_id );
		$token = wp_generate_password( 12, false );
		$now   = time();

		// add_option は同名オプションが既に存在する場合 false を返すため、
		// アトミックな compare-and-set として利用できる（autoload は 'no' で wp_options 全件読み込みに乗せない）。
		// add_option returns false when the option already exists, so it works as an atomic
		// compare-and-set primitive. We pass autoload='no' so the lock row does not pollute the
		// autoloaded options cache.
		$added = add_option(
			$key,
			array(
				'token'      => $token,
				'created_at' => $now,
			),
			'',
			'no'
		);

		if ( $added ) {
			return $token;
		}

		// 既存ロックがある場合、TTL 超過しているなら stale として破棄し再取得を試みる。
		// If the lock already exists, check whether it has exceeded the TTL and reclaim it.
		$existing = get_option( $key, null );
		if ( is_array( $existing ) && isset( $existing['created_at'] ) ) {
			$created_at = (int) $existing['created_at'];
			if ( $now - $created_at > self::OWNER_LOCK_TTL_SECONDS ) {
				// `delete_option` → `add_option` の 2 段階で stale 回収すると、
				// その隙に別リクエストが先に stale を回収して新ロックを取得した場合に
				// 自分の `delete_option` が相手の新ロックを誤って消し、両者が
				// クリティカルセクションに同時侵入する ABA 競合が起こりうる。
				// これを避けるため、stale 回収パスは 1 クエリの条件付き UPDATE
				// （WHERE option_value = <自分が見た stale 値>）で原子的に置き換える。
				// 1 行更新できた場合のみ stale-and-claim 成功、それ以外（0 行 / false）は
				// 別リクエストに先を越されたかDBエラーとみなしてリトライへ流す。
				//
				// To avoid an ABA race where another request reclaims the stale lock between
				// our `delete_option` and `add_option`, perform stale-and-claim atomically
				// with a single conditional UPDATE that requires the option_value to still
				// match what we observed. Anything other than "1 row updated" is treated as
				// "someone else won" and the caller's retry loop will pick it up.
				global $wpdb;
				$new_lock      = array(
					'token'      => $token,
					'created_at' => $now,
				);
				$existing_blob = maybe_serialize( $existing );
				$new_blob      = maybe_serialize( $new_lock );

				$updated = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => $new_blob ),
					array(
						'option_name'  => $key,
						'option_value' => $existing_blob,
					)
				);

				// $updated は成功時の更新行数（0 or 1）。それ以外（false）は DB エラー。
				// $updated is the affected row count (0 or 1) on success, or false on DB error.
				if ( 1 === (int) $updated ) {
					// option cache を必ず無効化（永続 object cache 環境で stale な値が
					// 残ると、後続の get_option が DB の最新値ではなく古い値を返す）。
					// Invalidate the option cache so persistent object cache backends do not
					// keep returning the previous value after our direct DB update.
					wp_cache_delete( $key, 'options' );
					return $token;
				}
			}
		}

		return null;
	}

	/**
	 * Release a previously acquired per-owner soft lock.
	 *
	 * 取得時に発行したトークンと照合し、一致する場合のみ削除します。
	 * 他リクエストがリトライ中に取得した別ロックを誤って削除しないための照合です。
	 *
	 * acquire_owner_lock の stale 回収パスが `$wpdb->update` の条件付き CAS で原子化されているのと
	 * 対称に、release 側も `$wpdb->delete` の条件付き DELETE で原子化します。
	 * `get_option` で値を見てから `delete_option` するまでの隙間で別リクエストが TTL 経過を判定して
	 * reclaim する（=自分のロックが別ロックに置き換わる）と、無条件 `delete_option` では
	 * 他リクエストのロックを誤って消し去る ABA 競合が起こりうるため、
	 * 「option_value が自分のロックそのものである場合にのみ DELETE」する 1 クエリで対称化します。
	 *
	 * To mirror the conditional-UPDATE CAS used by the stale-reclaim path of acquire_owner_lock(),
	 * release_owner_lock() performs a conditional DELETE that matches both option_name AND the
	 * serialized lock value we observed. Without this, a TOCTOU window between get_option() and
	 * delete_option() lets another request reclaim the slot and we would erase its lock by mistake.
	 *
	 * @param string $owner_id   Owner identifier returned by resolve_owner_id().
	 * @param string $lock_token Lock token previously returned by acquire_owner_lock().
	 * @return void
	 */
	private function release_owner_lock( string $owner_id, string $lock_token ): void {
		if ( '' === $lock_token ) {
			return;
		}

		$key      = $this->build_owner_lock_key( $owner_id );
		$existing = get_option( $key, null );

		if ( ! is_array( $existing ) || ! isset( $existing['token'] ) ) {
			return;
		}

		// トークン照合にはタイミング攻撃対策の hash_equals を使う（必須ではないが安全側）。
		// Compare with hash_equals as a defensive measure.
		if ( ! hash_equals( (string) $existing['token'], $lock_token ) ) {
			return;
		}

		// option_value をそのまま WHERE 条件に入れて条件付き DELETE を 1 クエリで実行する。
		// get_option → delete_option の隙間で別リクエストが reclaim していた場合は
		// WHERE option_value 不一致で 0 行となり、相手の新ロックは破壊されない。
		// 削除に失敗（0 行 / false）しても TTL（OWNER_LOCK_TTL_SECONDS）経過で
		// 次の acquire_owner_lock 側の stale 回収パスが自然に拾うため運用上の問題はない。
		//
		// Perform a single conditional DELETE that also matches the serialized lock value.
		// If a concurrent request has reclaimed the slot between our get_option() and this query,
		// the WHERE clause will not match (0 rows) and we will not erase the foreign lock.
		// Even if the delete does not happen, the next acquire_owner_lock() call will reclaim
		// the slot via its stale-reclaim CAS once OWNER_LOCK_TTL_SECONDS elapses.
		global $wpdb;
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $key,
				'option_value' => maybe_serialize( $existing ),
			),
			array( '%s', '%s' )
		);

		// option cache を無効化して、永続オブジェクトキャッシュ環境で stale な値が
		// 後続 get_option に残らないようにする（acquire 側 CAS と同じ理由）。
		// Invalidate the option cache for parity with the stale-reclaim path in acquire_owner_lock().
		wp_cache_delete( $key, 'options' );
	}

	/**
	 * Run a callback while holding a per-owner soft lock.
	 *
	 * 並列リクエスト時の race condition 対策として、callback 実行中に owner index 用のソフトロックを
	 * 確実に保持し、try-finally で必ず解放する高階ラッパです。
	 * 取得失敗時は指数バックオフ（10ms→20ms→40ms→80ms→160ms、合計上限 約310ms）で再試行し、
	 * 最終的に取得できなかった場合は warning ログを残して callback を実行せず null を返します。
	 *
	 * 呼び出し側（save_draft / delete_draft）は draft 本体の transient 操作を行うため、
	 * owner index 更新だけスキップされても TTL（30 分）で自然整合されます。
	 *
	 * @param string   $owner_id Owner identifier returned by resolve_owner_id().
	 * @param callable $callback Critical section to execute while holding the lock.
	 * @return mixed Callback return value on success, null when the lock could not be acquired.
	 */
	private function with_owner_lock( string $owner_id, callable $callback ) {
		// 指数バックオフ用のスリープ時間（マイクロ秒）。10ms→20ms→40ms→80ms→160ms。
		// Exponential backoff in microseconds.
		$backoff_us = 10000;

		for ( $attempt = 0; $attempt < self::OWNER_LOCK_MAX_RETRIES; $attempt++ ) {
			$lock_token = $this->acquire_owner_lock( $owner_id );
			if ( null !== $lock_token ) {
				try {
					return $callback();
				} finally {
					// 例外発生時もロックを確実に解放する。
					// Release the lock even when the callback throws.
					$this->release_owner_lock( $owner_id, $lock_token );
				}
			}

			// 最後の試行で失敗した場合は sleep せずループを抜ける。
			// Skip sleep on the final failed attempt.
			if ( $attempt < self::OWNER_LOCK_MAX_RETRIES - 1 ) {
				usleep( $backoff_us );
				$backoff_us *= 2;
			}
		}

		// 取得最終失敗時は warning ログのみ残し callback を実行しない。
		// owner_id のハッシュ先頭 8 文字のみログに出し、ユーザーID等の生値はログに残さない。
		// On final failure, log a warning without leaking raw owner identifiers.
		error_log( '[vkbm] owner lock acquisition failed for ' . substr( sha1( $owner_id ), 0, 8 ) . '...' );

		return null;
	}
}
