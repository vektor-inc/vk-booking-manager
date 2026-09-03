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
use VKBookingManager\Common\Exclusive_Fee;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Rate_Limit_Trait;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_Query;
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
use function error_log; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational warning logging (privacy-safe, hashed identifiers only).
use function substr;
use function sha1;
use function time;
use function strtotime;
use function wp_date;
use function maybe_serialize;
use function wp_cache_delete;

/**
 * Handles reservation draft persistence via REST API.
 */
class Booking_Draft_Controller {
	use Rate_Limit_Trait;

	private const REST_NAMESPACE     = 'vkbm/v1';
	private const TRANSIENT_PREFIX   = 'vkbm_draft_';
	private const OWNER_INDEX_PREFIX = 'vkbm_draft_owner_idx_';
	private const TTL_SECONDS        = 1800; // 30 minutes.
	private const OWNER_COOKIE       = 'vkbm_draft_owner';

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
	public const MEMO_MAX_LENGTH = 1000;
	// memo の絶対上限。vkbm_draft_memo_max_length フィルタが極端に大きな値を返しても
	// この値を超えないようクランプし、DoS 経路の再オープンを防ぐ。
	// Absolute hard cap for memo length. Even if a third-party filter returns a huge value,
	// the effective limit is clamped to this constant to prevent reopening the DoS path.
	public const MEMO_HARD_MAX_LENGTH           = 10000;
	public const LABEL_MAX_LENGTH               = 200;
	public const SLOT_FIELD_MAX_LENGTH          = 64;
	public const ASSIGNABLE_STAFF_IDS_MAX_COUNT = 50;

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
		$date = isset( $params['date'] ) ? sanitize_text_field( (string) $params['date'] ) : '';
		$date = $this->truncate_text( $date, self::SLOT_FIELD_MAX_LENGTH );

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
		$menu_label         = isset( $params['menu_label'] ) ? sanitize_text_field( (string) $params['menu_label'] ) : '';
		$menu_label         = $this->truncate_text( $menu_label, self::LABEL_MAX_LENGTH );
		$staff_label        = isset( $params['staff_label'] ) ? sanitize_text_field( (string) $params['staff_label'] ) : '';
		$staff_label        = $this->truncate_text( $staff_label, self::LABEL_MAX_LENGTH );
		$is_staff_preferred = ! empty( $params['is_staff_preferred'] );

		// 指名機能が無効の場合、指名フラグを強制的に false にする。
		if ( ! Staff_Editor::is_nomination_enabled() ) {
			$is_staff_preferred = false;
		}

		$slot = isset( $params['slot'] ) && is_array( $params['slot'] ) ? $params['slot'] : array();
		// slot 系の識別子・日時文字列は sanitize 後に SLOT_FIELD_MAX_LENGTH で切り詰める。
		// Slot identifier and timestamp strings: sanitize then truncate.
		$slot_id     = isset( $slot['slot_id'] ) ? sanitize_text_field( (string) $slot['slot_id'] ) : '';
		$slot_id     = $this->truncate_text( $slot_id, self::SLOT_FIELD_MAX_LENGTH );
		$start_at    = isset( $slot['start_at'] ) ? sanitize_text_field( (string) $slot['start_at'] ) : '';
		$start_at    = $this->truncate_text( $start_at, self::SLOT_FIELD_MAX_LENGTH );
		$end_at      = isset( $slot['end_at'] ) ? sanitize_text_field( (string) $slot['end_at'] ) : '';
		$end_at      = $this->truncate_text( $end_at, self::SLOT_FIELD_MAX_LENGTH );
		$service_end = isset( $slot['service_end_at'] ) ? sanitize_text_field( (string) $slot['service_end_at'] ) : '';
		$service_end = $this->truncate_text( $service_end, self::SLOT_FIELD_MAX_LENGTH );
		$duration    = isset( $slot['duration_minutes'] ) ? max( 0, (int) $slot['duration_minutes'] ) : 0;
		$slot_staff  = isset( $slot['staff'] ) && is_array( $slot['staff'] ) ? $slot['staff'] : null;
		// slot.staff.name もラベル系として 200 文字でキャップ（DoS 経路防止）。
		// Cap slot.staff.name with the label length limit to prevent oversize wp_options payloads.
		$slot_staff = $slot_staff
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
		$slot_staff_label = isset( $slot['staff_label'] ) ? sanitize_text_field( (string) $slot['staff_label'] ) : '';
		$slot_staff_label = $this->truncate_text( $slot_staff_label, self::LABEL_MAX_LENGTH );
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

		// 最小催行人数（グループ開催型）と当該枠の合計予約人数。確定画面の催行注記表示に使う表示専用値。
		// 表示のみで催行可否の強制はしないため、0以上の整数へサニタイズして保持する（負値・不正値は0）。
		$slot_min_capacity  = isset( $slot['min_capacity'] ) ? max( 0, (int) $slot['min_capacity'] ) : 0;
		$slot_booked_guests = isset( $slot['booked_guests'] ) ? max( 0, (int) $slot['booked_guests'] ) : 0;

		// ユーザーによる貸し切り指定（#305）。フロントのチェックボックスの選択状態。
		// フロント抑止は迂回可能なため、確定側と同じくサーバ側でフルゲートを再適用する（下記の guard 群）。
		$user_requested_exclusive = ! empty( $params['user_exclusive'] );

		if ( '' === $slot_id || '' === $start_at ) {
			return new WP_Error( 'invalid_slot', __( 'Reservation slot information is incorrect.', 'vk-booking-manager' ) );
		}

		// 貸し切り（枠を専有する）予約がこの時間帯に既に入っていれば、残席があっても受付停止する。
		// confirmation 側のロック保持下の再判定（必須の多層防御）とは別に、下書き保存の早い段階でも
		// 同じ判定を行い、利用者に無駄な入力を続けさせないようにする（ロジックは confirmation と同型）。
		if ( $this->slot_has_exclusive_booking_for_menu( $menu_id, $start_at, $end_at ) ) {
			return new WP_Error(
				'capacity_exceeded',
				__( 'Reservations are closed for this time slot because it has been reserved exclusively.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		$meta = isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : array();
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

		// 予約人数（複数人一括予約）を解決する。
		// メニューが複数人一括予約を許可している場合のみ受け付ける。
		$max_guests = $this->get_max_guests( $menu_id );

		// 料金区分が定義されているか（複数人一括予約が適用される場合のみ意味を持つ）。
		// 区分が1つでもあれば、人数は単一の guests ではなく区分ごとの内訳で受け取る。
		// 実効の最大受付数が1以下のメニューでは料金区分は無効（空配列扱い）にし、基本料金×人数へ倒す（#320）。
		// get_max_guests() は複数人一括予約適用時に max(1, max_capacity) を返すため、>= 2 で「実効max_capacity≥2」と等価。
		$menu_tiers      = ( $max_guests >= 2 ) ? Price_Tiers::normalize_tiers( get_post_meta( $menu_id, '_vkbm_price_tiers', true ) ) : array();
		$has_price_tiers = ! empty( $menu_tiers );
		$guest_tiers     = array();

		if ( $has_price_tiers ) {
			// 区分ごとの人数をサーバ保存メタ（ラベル・料金）に突き合わせて確定する。
			$requested_tiers  = isset( $params['guest_tiers'] ) ? $params['guest_tiers'] : array();
			$guest_tiers      = Price_Tiers::resolve_guest_tiers( $menu_tiers, $requested_tiers );
			$requested_guests = Price_Tiers::total_count( $guest_tiers );
		} else {
			$requested_guests = isset( $params['guests'] ) ? (int) $params['guests'] : 1;
		}

		// 入力人数が上限を超える場合は、黙ってクランプせずエラーを返す。
		// クランプして保存すると「5名で予約したのに2名分しか取れていない」という気付けないズレが生じるため、
		// 上限超過は明示的にエラーとして利用者へ知らせる。
		if ( $max_guests >= 1 && $requested_guests > $max_guests ) {
			return new WP_Error(
				'guests_exceeded',
				sprintf(
					/* translators: %d: maximum number of guests that can be booked. */
					__( 'The number of guests exceeds the maximum that can be booked (%d).', 'vk-booking-manager' ),
					$max_guests
				),
				array( 'status' => 400 )
			);
		}

		// 料金区分利用時は合計が最低1名いることを必須とする（特定区分が0名でも合計1名以上ならOK）。
		if ( $has_price_tiers && $requested_guests < 1 ) {
			return new WP_Error(
				'guests_required',
				__( 'Please select at least one guest.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// 安全網として 1〜最大人数の範囲にクランプする（上限超過は上で弾いている）。
		$guests = $this->resolve_guests( $menu_id, $requested_guests );

		// ユーザーによる貸し切り指定（#305）のサーバ側ガード。
		// フロントの表示抑止は迂回可能なため、確定側と同型の判定をここでも行い、無駄な入力の続行を防ぐ。
		$user_exclusive = false;
		if ( $user_requested_exclusive ) {
			if ( $this->is_user_exclusive_selectable( $menu_id ) ) {
				// 最小催行人数（>=）を満たさない場合は貸し切り指定を拒否する。
				// 最大受付数が1以下のメニューでは最小催行人数は意味を持たないため実効0（判定無効）にする（#320）。
				// Availability_Service::get_menu_min_capacity と同じく、max_capacity を上限にクランプする。
				$max_capacity = $this->get_menu_max_capacity( $menu_id );
				$min_capacity = $max_capacity <= 1
					? 0
					: max( 0, min( $max_capacity, (int) get_post_meta( $menu_id, '_vkbm_min_capacity', true ) ) );
				if ( $min_capacity > 0 && $guests < $min_capacity ) {
					return new WP_Error(
						'exclusive_min_capacity',
						__( 'The number of guests does not reach the minimum required for a private booking.', 'vk-booking-manager' ),
						array( 'status' => 400 )
					);
				}
				// 既にその枠に有効な予約があれば貸切にできない（最初の予約者のみ）。
				if ( $this->slot_has_any_active_booking_for_menu( $menu_id, $start_at, $end_at ) ) {
					return new WP_Error(
						'exclusive_unavailable',
						__( 'This slot cannot be reserved exclusively because it already has a booking.', 'vk-booking-manager' ),
						array( 'status' => 409 )
					);
				}
				$user_exclusive = true;
			}
			// メニューがユーザー貸し切り指定を受け付けない設定なら user_exclusive=false のまま（フラグは無視）。
		}

		$payload = array(
			'menu_id'                   => $menu_id,
			'resource_id'               => $resource_id,
			'date'                      => $date,
			'guests'                    => $guests,
			// ユーザーによる貸し切り指定（#305）。確定時にこのフラグを再判定して _vkbm_booking_exclusive を付与する。
			'user_exclusive'            => $user_exclusive,
			// 料金区分の人数内訳スナップショット（区分未定義なら空配列）。
			'guest_tiers'               => $guest_tiers,
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
				// 確定画面の催行注記（未達時のみ表示）に伝搬する表示専用の値。
				'min_capacity'         => $slot_min_capacity,
				'booked_guests'        => $slot_booked_guests,
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

		$menu_id_for_price = (int) ( $payload['menu_id'] ?? 0 );

		// 料金区分が定義されているメニューは、区分料金（Σ 料金 × 区分人数）で合計を計算する。
		// ラベル・料金は必ずサーバ保存メタを正とし、保存済みスナップショットの人数のみを使う（改竄防止）。
		// 実効の最大受付数が1以下のメニューでは料金区分は無効（空配列扱い）にし、基本料金×人数へ倒す（#320）。
		// get_max_guests() は複数人一括予約適用時に max(1, max_capacity) を返すため、>= 2 で「実効max_capacity≥2」と等価。
		$menu_tiers      = ( $menu_id_for_price > 0 && $this->get_max_guests( $menu_id_for_price ) >= 2 )
			? Price_Tiers::normalize_tiers( get_post_meta( $menu_id_for_price, '_vkbm_price_tiers', true ) )
			: array();
		$has_price_tiers = ! empty( $menu_tiers );

		if ( $has_price_tiers ) {
			// 保存済み内訳の人数を、現在のメニュー区分（ラベル・料金）に再突き合わせする。
			$stored_tiers = is_array( $payload['guest_tiers'] ?? null ) ? $payload['guest_tiers'] : array();
			$guest_tiers  = Price_Tiers::resolve_guest_tiers( $menu_tiers, $stored_tiers );

			$guests = Price_Tiers::total_count( $guest_tiers );

			// 区分構成の変更等で合計0名になった場合、guests=0／total=指名料のみで黙って返さず、
			// 明示的にエラーにする（save_draft 側の guests_required と対称）。利用者に再選択を促す。
			if ( $guests < 1 ) {
				return new WP_Error(
					'guests_required',
					__( 'Please select at least one guest.', 'vk-booking-manager' ),
					array( 'status' => 400 )
				);
			}

			$payload['guests']      = $guests;
			$payload['guest_tiers'] = $this->format_guest_tiers_for_response( $guest_tiers, $tax_enabled );

			$tiers_total = Price_Tiers::total_price( $guest_tiers );
			$total_price = $tiers_total + $payload['nomination_fee'];
		} else {
			// 区分未定義は従来どおり基本料金×人数（完全後方互換）。
			$guests                 = $this->resolve_guests( $menu_id_for_price, (int) ( $payload['guests'] ?? 1 ) );
			$payload['guests']      = $guests;
			$payload['guest_tiers'] = array();

			$base_display_price = (int) ( $price_snapshot['display_price'] ?? 0 );
			// 基本料金は人数分を掛ける。指名料は人数に関わらず1回分。
			$total_price = ( $base_display_price * $guests ) + $payload['nomination_fee'];
		}

		// 貸し切り料金（#305）を権威的に再計算して合計へ加算する（フロント送信額は信用しない）。
		// ユーザー貸切が選択され、メニューがユーザー貸し切り指定を受け付ける設定のときのみ加算される。
		$user_exclusive = ! empty( $payload['user_exclusive'] ) && $this->is_user_exclusive_selectable( $menu_id_for_price );
		$exclusive_fee  = $user_exclusive ? $this->calculate_exclusive_fee( $menu_id_for_price, $guests ) : 0;
		$total_price   += $exclusive_fee;

		// フロント（確定画面）の貸し切り料金行表示に使う値を返す。
		$payload['user_exclusive']          = $user_exclusive;
		$payload['exclusive_fee']           = $exclusive_fee;
		$payload['exclusive_fee_formatted'] = $this->format_currency_label( $exclusive_fee, $tax_enabled );

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
	 * ISO8601 日時をサイトのローカル時刻の Y-m-d H:i:s 文字列へ変換する。
	 *
	 * 予約メタ（_vkbm_booking_service_start 等）はサイトローカルの Y-m-d H:i:s で保存されるため、
	 * meta_query の DATETIME 比較に合わせて同じ形式へ変換する（confirmation 側と同型）。
	 *
	 * @param string $value ISO8601 日時。
	 * @return string 変換後の Y-m-d H:i:s。変換できない場合は空文字。
	 */
	private function format_datetime_for_storage( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '';
		}
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * 指定スロットに、枠を専有する貸し切り予約が既に1件以上あるかを判定する。
	 *
	 * 既存の空き判定と同じく、publish かつ status が cancelled/no_show 以外の予約のみを対象とし、
	 * その中で _vkbm_booking_exclusive が立っている予約がスロットに重複していれば true を返す。
	 *
	 * NOTE: 同型の判定を Booking_Confirmation_Controller::slot_has_exclusive_booking_for_menu にも
	 * 意図的に複製している。下書きと確定の2コントローラは独立して動くため、共有トレイト化せず
	 * 各クラスに閉じた実装とする。仕様変更時は両方を同じ条件で更新すること。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return bool 貸し切り予約が重複していれば true。
	 */
	private function slot_has_exclusive_booking_for_menu( int $menu_id, string $start_at, string $end_at ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return false;
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				// 1件でもヒットすれば貸し切り判定は確定するため取得は1件に絞る（軽微な最適化）。
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_vkbm_booking_service_id',
						'value'   => $menu_id,
						'compare' => '=',
					),
					// 貸し切りフラグが立っている予約のみを対象にする。
					array(
						'key'     => '_vkbm_booking_exclusive',
						'value'   => '1',
						'compare' => '=',
					),
					// キャンセル・無断キャンセルの予約は枠を消費しないためクエリ段階で除外する。
					// posts_per_page=1 で取得を1件に絞っても、対象外ステータスの予約が
					// 先頭に来て有効な貸し切り予約を見落とすことがないようにする。
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vkbm_booking_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_vkbm_booking_status',
							'value'   => array( 'cancelled', 'no_show' ),
							'compare' => 'NOT IN',
						),
					),
					array(
						'key'     => '_vkbm_booking_service_start',
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vkbm_booking_total_end',
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => '_vkbm_booking_service_end',
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, '_vkbm_booking_status', true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため除外する。
			if ( 'cancelled' === $status || 'no_show' === $status ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * メニューが「ユーザーによる貸し切り指定を受け付ける」設定として有効かどうかを返す（#305）。
	 *
	 * 確定コントローラ（Booking_Confirmation_Controller::is_user_exclusive_selectable）と同型の判定。
	 * 2コントローラは独立して動くため共有トレイト化せず、各クラスに閉じた実装とする。
	 * 仕様変更時は両方を同じ条件で更新すること。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return bool ユーザー貸し切り指定が有効なら true。
	 */
	private function is_user_exclusive_selectable( int $menu_id ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		// メタが立っていなければ対象外。
		if ( ! get_post_meta( $menu_id, '_vkbm_exclusive_user_selectable', true ) ) {
			return false;
		}

		// フルゲート（Pro版・予約枠の定員ON・指名OFF）を再適用する。
		$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( ! $is_pro || ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return false;
		}

		// メニュー単位の複数人一括予約許可も必須。
		if ( ! get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true ) ) {
			return false;
		}

		// 実効の最大予約受付数が1以下なら貸し切り指定は意味を持たないため無効化する（#320）。
		// 表示制御（管理画面の hidden）だけに頼らず、保存済みメタが残っていても実効0扱いにする。
		// 確定コントローラ（Booking_Confirmation_Controller::is_user_exclusive_selectable）と対称。
		if ( $this->get_menu_max_capacity( $menu_id ) <= 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * メニューの「1枠あたりの最大予約受付数」の実効値を返す（#320）。
	 *
	 * Availability_Service::get_menu_max_capacity と同じロジック（予約枠の定員機能OFF・指名ON時は1固定、
	 * それ以外は保存メタを 1 以上にクランプ）を、確定/下書きの2コントローラで対称に保つために複製する。
	 * 下書きコントローラは Availability_Service を保持しないため、ここに閉じた実装とする。
	 * 仕様変更時は確定コントローラ側（get_menu_max_capacity）と Availability_Service 側も合わせて更新すること。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return int 実効の最大予約受付数（1 以上）。
	 */
	private function get_menu_max_capacity( int $menu_id ): int {
		// 予約枠の定員機能が無効、または指名機能が有効な場合は1対1予約のため上限を1に固定する。
		if ( ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return 1;
		}

		$meta = get_post_meta( $menu_id, '_vkbm_max_capacity', true );
		return '' === $meta ? 1 : max( 1, (int) $meta );
	}

	/**
	 * メニュー設定とユーザー選択に基づき、貸し切り料金を権威的に再計算する（#305）。
	 *
	 * 単価・適用外人数は必ずサーバ保存メタを正とする（フロント送信額は信用しない）。
	 * 計算式は Exclusive_Fee::calculate() に委譲する（確定コントローラ・テストと同一ロジック）。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @param int $guests  申込人数。
	 * @return int 貸し切り料金（0 以上）。
	 */
	private function calculate_exclusive_fee( int $menu_id, int $guests ): int {
		if ( ! $this->is_user_exclusive_selectable( $menu_id ) ) {
			return 0;
		}

		$per_person    = max( 0, (int) get_post_meta( $menu_id, '_vkbm_exclusive_fee_per_person', true ) );
		$exempt_guests = max( 0, (int) get_post_meta( $menu_id, '_vkbm_exclusive_fee_exempt_guests', true ) );

		return Exclusive_Fee::calculate( true, $per_person, $exempt_guests, $guests );
	}

	/**
	 * 指定スロットに、ステータスが有効な予約（貸し切りか否かを問わない）が既に1件以上あるかを判定する（#305）。
	 *
	 * ユーザー貸し切り指定時に「最初の予約者のみ貸切にできる」を担保するための判定。
	 * cancelled / no_show は枠を消費しないため除外する。確定コントローラ側と同型。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return bool 有効な予約が重複していれば true。
	 */
	private function slot_has_any_active_booking_for_menu( int $menu_id, string $start_at, string $end_at ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return false;
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_vkbm_booking_service_id',
						'value'   => $menu_id,
						'compare' => '=',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vkbm_booking_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_vkbm_booking_status',
							'value'   => array( 'cancelled', 'no_show' ),
							'compare' => 'NOT IN',
						),
					),
					array(
						'key'     => '_vkbm_booking_service_start',
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vkbm_booking_total_end',
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => '_vkbm_booking_service_end',
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, '_vkbm_booking_status', true );
			if ( 'cancelled' === $status || 'no_show' === $status ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Resolve the number of guests for a booking against the menu's multi-guest settings.
	 *
	 * メニューの複数人一括予約設定に基づいて予約人数を確定する。
	 * 複数人一括予約が無効、または指名機能が有効な場合は常に1名を返す。
	 * 有効な場合は 1〜最大人数の範囲にクランプする。
	 *
	 * NOTE: 同等のロジックを Booking_Confirmation_Controller::resolve_guests にも意図的に複製している。
	 * 下書きと確定の2つの REST コントローラは独立しており、共有トレイト化すると両者を不要に結合させるため、
	 * あえて各クラスに閉じた実装としている。仕様変更時は必ず両方を同じ式（上限 = max_capacity）で更新すること。
	 *
	 * @param int $menu_id        Service menu ID.
	 * @param int $requested      Requested number of guests.
	 * @return int
	 */
	private function resolve_guests( int $menu_id, int $requested ): int {
		// 複数人一括予約が適用されない（最大人数が取得できない）場合は常に1名とする。
		$max = $this->get_max_guests( $menu_id );
		if ( $max < 1 ) {
			return 1;
		}

		// 安全網として 1〜最大人数の範囲にクランプする（入口の超過チェックを通った値のみ来る想定）。
		return max( 1, min( $max, $requested ) );
	}

	/**
	 * メニュー設定から「1予約あたりの最大予約人数」を求める。
	 * 複数人一括予約が適用されない場合（無料版・指名ON・未許可・スタッフ未割当）は 0 を返す。
	 *
	 * Resolve the per-booking maximum number of guests from the menu settings.
	 * Returns 0 when multi-guest booking does not apply (free edition, nomination on, not allowed, or no staff).
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return int 最大予約人数。複数人一括予約が適用されない場合は 0。
	 */
	private function get_max_guests( int $menu_id ): int {
		// 複数人一括予約は Pro 版 かつ 予約枠の定員機能ON かつ 指名機能OFF（自動割り当て）のときのみ有効。
		// Pro から無料版へダウングレードしてもメタが残る可能性があるため、Pro 版を再確認する。
		$is_pro = class_exists( 'Free_Version_Deactivator' ) && \Free_Version_Deactivator::is_pro_edition( VKBM_PLUGIN_FILE );
		if ( $menu_id <= 0 || ! $is_pro || ! Staff_Editor::is_slot_capacity_enabled() || Staff_Editor::is_nomination_enabled() ) {
			return 0;
		}

		$allow = (bool) get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		if ( ! $allow ) {
			return 0;
		}

		// 1予約は分割せず単一スタッフに割り当てるため、1予約あたりの最大人数の上限は max_capacity（1スタッフの上限）。
		// 実際のスロット別の空き（最も空きの大きい単一スタッフの残り）は確定時に厳密判定する。
		$max_raw      = get_post_meta( $menu_id, '_vkbm_max_capacity', true );
		$max_capacity = '' === $max_raw ? 1 : max( 1, (int) $max_raw );

		// 重複スタッフIDは1名として数える。
		$staff_ids   = get_post_meta( $menu_id, '_vkbm_staff_ids', true );
		$staff_count = is_array( $staff_ids )
			? count(
				array_unique(
					array_filter(
						array_map( 'intval', $staff_ids ),
						static function ( int $id ): bool {
							return $id > 0;
						}
					)
				)
			)
			: 0;

		// スタッフ未割当のメニューは割り当て先が無いため、複数人一括予約を許可しない（0 を返す）。
		if ( $staff_count < 1 ) {
			return 0;
		}

		return $max_capacity;
	}

	/**
	 * 料金区分の人数内訳に、フロント表示用の整形済み料金・小計を付与する。
	 *
	 * @param array<int, array{label: string, price: int, count: int}> $guest_tiers 確定済み区分内訳。
	 * @param bool                                                     $tax_enabled 税込ラベルを付けるか。
	 * @return array<int, array{label: string, price: int, count: int, price_formatted: string, subtotal: int, subtotal_formatted: string}>
	 */
	private function format_guest_tiers_for_response( array $guest_tiers, bool $tax_enabled ): array {
		$formatted = array();
		foreach ( $guest_tiers as $tier ) {
			$price    = isset( $tier['price'] ) ? max( 0, (int) $tier['price'] ) : 0;
			$count    = isset( $tier['count'] ) ? max( 0, (int) $tier['count'] ) : 0;
			$subtotal = $price * $count;

			$formatted[] = array(
				'label'              => (string) ( $tier['label'] ?? '' ),
				'price'              => $price,
				'count'              => $count,
				'price_formatted'    => $this->format_currency_label( $price, $tax_enabled ),
				'subtotal'           => $subtotal,
				'subtotal_formatted' => $this->format_currency_label( $subtotal, $tax_enabled ),
			);
		}

		return $formatted;
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
	 * @param string                                            $owner_id Owner identifier.
	 * @param array<int, array{token: string, created_at: int}> $index    Normalized index entries.
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
				// ループ条件内での count() を避けるため、件数を変数で保持して更新する。
				$index_count = count( $index );
				while ( $index_count >= $max ) {
					$oldest = array_shift( $index );
					--$index_count;
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

				// オーナーロックの CAS 更新のため options テーブルへ直接書き込む（アトミック性が必要）。
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic CAS update for owner lock; caching is not applicable.
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
		// オーナーロック解放のため options テーブルから直接削除する（アトミック性が必要）。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic delete for owner lock release; caching is not applicable.
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
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational warning logging (privacy-safe, hashed identifiers only).
		error_log( '[vkbm] owner lock acquisition failed for ' . substr( sha1( $owner_id ), 0, 8 ) . '...' );

		return null;
	}
}
