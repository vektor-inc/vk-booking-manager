<?php
/**
 * REST controller that finalizes reservation drafts into confirmed bookings.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Attendees\Booking_Attendee_Resolver;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Exclusive_Fee;
use VKBookingManager\Common\Lodging_Price;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\Common\Reservation_Day;
use VKBookingManager\Common\Resource_Tag_Id_List;
use VKBookingManager\Common\Staff_Load_Calculator;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\Common\Attendee_Name;
use VKBookingManager\Integrations\Booking_Event_Dispatcher;
use VKBookingManager\Notifications\Booking_Notification_Service;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use VKBookingManager\Staff\Staff_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Query;
use WP_User;
use function __;
use function add_filter;
use function apply_filters;
use function remove_filter;
use function delete_post_meta;
use function is_wp_error;
use function delete_transient;
use function get_post;
use function get_post_meta;
use function get_transient;
use function get_user_by;
use function get_userdata;
use function get_users;
use function is_user_logged_in;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function current_user_can;
use function get_current_user_id;
use function vkbm_get_resource_label_plural;
use function vkbm_get_resource_label_singular;
use function vkbm_get_resource_tag_empty_message;
use function wp_get_current_user;
use function wp_insert_post;
use function update_post_meta;
use function wp_date;
use function strtotime;
use function wp_unslash;

/**
 * REST controller that finalizes reservation drafts into confirmed bookings.
 */
class Booking_Confirmation_Controller {
	private const REST_NAMESPACE = 'vkbm/v1';
	private const DRAFT_PREFIX   = 'vkbm_draft_';

	// #431: 予約時にユーザーが指定したリソースタグ（希望タグ）のターム ID 配列。タグ未指定なら保存しない。
	private const META_RESOURCE_TAG_IDS          = '_vkbm_booking_resource_tag_ids';
	private const META_SERVICE_ID                = '_vkbm_booking_service_id';
	private const META_CUSTOMER                  = '_vkbm_booking_customer_name';
	private const META_CUSTOMER_TEL              = '_vkbm_booking_customer_tel';
	private const META_CUSTOMER_MAIL             = '_vkbm_booking_customer_email';
	private const META_NOTE                      = '_vkbm_booking_note';
	private const META_INTERNAL_NOTE             = '_vkbm_booking_internal_note';
	private const META_IS_PREFERRED              = '_vkbm_booking_is_staff_preferred';
	private const META_NOMINATION_FEE            = '_vkbm_booking_nomination_fee';
	private const META_SERVICE_BASE_PRICE        = '_vkbm_booking_service_base_price';
	private const META_BASE_TOTAL_PRICE          = '_vkbm_booking_base_total_price';
	private const META_GUESTS                    = '_vkbm_booking_guests';
	private const META_GUEST_TIERS               = '_vkbm_booking_guest_tiers';
	private const META_EXCLUSIVE                 = '_vkbm_booking_exclusive';
	private const META_EXCLUSIVE_FEE             = '_vkbm_booking_exclusive_fee';
	private const MENU_META_PRICE_TIERS          = '_vkbm_price_tiers';
	private const MENU_META_RESERVATION_DAY_TYPE = '_vkbm_reservation_day_type';
	// メタキーの定義元は Reservation_Day（single source of truth）。
	private const MENU_META_RESERVATION_CUSTOM_WEEKDAYS = Reservation_Day::META_CUSTOM_WEEKDAYS;
	private const MENU_META_RESERVATION_CUSTOM_DATES    = Reservation_Day::META_CUSTOM_DATES;
	private const MENU_META_MAX_CAPACITY                = '_vkbm_max_capacity';
	private const MENU_META_MIN_CAPACITY                = '_vkbm_min_capacity';
	private const MENU_META_ALLOW_GUESTS                = '_vkbm_allow_multiple_guests';
	private const MENU_META_EXCLUSIVE_WHEN_BOOKED       = '_vkbm_exclusive_when_booked';
	private const MENU_META_EXCLUSIVE_USER_SELECTABLE   = '_vkbm_exclusive_user_selectable';
	private const MENU_META_EXCLUSIVE_FEE_PER_PERSON    = '_vkbm_exclusive_fee_per_person';
	private const MENU_META_EXCLUSIVE_FEE_EXEMPT        = '_vkbm_exclusive_fee_exempt_guests';
	private const BOOKING_STATUS_CONFIRMED              = 'confirmed';
	private const BOOKING_STATUS_PENDING                = 'pending';
	private const BOOKING_STATUS_CANCELLED              = 'cancelled';
	private const BOOKING_STATUS_NO_SHOW                = 'no_show';
	private const OWNER_COOKIE                          = 'vkbm_draft_owner';

	/**
	 * Transient-based mutex lock timeout in seconds.
	 *
	 * ロック保持区間（wp_insert_post＋予約メタ保存）が万一遅延しても、その途中で
	 * 別リクエストに stale ロックとして奪取され二重に capacity チェックを通過する
	 * （＝オーバーブッキングする）ことのないよう、PHP の既定 max_execution_time（30秒）に
	 * 合わせて設定する。これにより元リクエストが生存し得る間はロックを奪われない。
	 *
	 * @var int
	 */
	private const MUTEX_TIMEOUT = 30;

	/**
	 * Availability service.
	 *
	 * @var Availability_Service
	 */
	private $availability_service;

	/**
	 * Notification handler.
	 *
	 * @var Booking_Notification_Service
	 */
	private $notification_service;

	/**
	 * Provider settings repository.
	 *
	 * @var Settings_Repository
	 */
	private $settings_repository;

	/**
	 * 予約の状態変化を外部連携へ橋渡しするディスパッチャー（#474）。
	 *
	 * @var Booking_Event_Dispatcher
	 */
	private $event_dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Booking_Notification_Service  $notification_service Notification handler.
	 * @param Settings_Repository           $settings_repository  Provider settings repository.
	 * @param Availability_Service|null     $availability_service Availability service.
	 * @param Booking_Event_Dispatcher|null $event_dispatcher     予約の状態変化を外部連携へ橋渡しするディスパッチャー。
	 */
	public function __construct(
		Booking_Notification_Service $notification_service,
		Settings_Repository $settings_repository,
		?Availability_Service $availability_service = null,
		?Booking_Event_Dispatcher $event_dispatcher = null
	) {
		$this->notification_service = $notification_service;
		$this->settings_repository  = $settings_repository;
		$this->availability_service = null !== $availability_service ? $availability_service : new Availability_Service( $settings_repository );
		$this->event_dispatcher     = $event_dispatcher ?? new Booking_Event_Dispatcher();
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
			self::REST_NAMESPACE,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'args'                => array(
					'attendee_name'           => array(
						'type'      => 'string',
						'maxLength' => Attendee_Name::MAX_LENGTH,
					),
					'attendee_id'             => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
					'attendee_new_id'         => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
					'attendee_kana'           => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'attendee_number'         => array(
						'type'      => 'string',
						'maxLength' => 50,
					),
					'attendee_save_to_roster' => array(
						'type' => 'boolean',
					),
				),
				// Booking creation requires an authenticated user.
				// 予約作成はログインユーザーのみ許可する。
				'permission_callback' => array( $this, 'check_create_booking_permission' ),
			)
		);

		// 予約確認画面で「ご利用になる方」を選ぶための名簿の選択肢を返す。
		register_rest_route(
			self::REST_NAMESPACE,
			'/booking-attendees',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_booking_attendee_options' ),
				'args'                => array(
					'customer_phone' => array(
						'type'      => 'string',
						'maxLength' => 50,
					),
				),
				'permission_callback' => array( $this, 'check_create_booking_permission' ),
			)
		);
	}

	/**
	 * 予約確認画面に出す「ご利用になる方」の選択肢を返す。
	 *
	 * 会員自身の予約では自分の名簿を、代理予約では電話番号が一致した会員の名簿を返す。
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_booking_attendee_options( WP_REST_Request $request ): WP_REST_Response {
		$is_proxy = current_user_can( Capabilities::MANAGE_RESERVATIONS );
		$owner    = wp_get_current_user();
		if ( $is_proxy ) {
			$phone = VKBM_Helper::normalize_phone_number( sanitize_text_field( (string) ( $request['customer_phone'] ?? '' ) ) );
			$owner = $this->get_user_by_phone_number( $phone );
		}

		$options = ( new Booking_Attendee_Resolver() )->get_options( $this->settings_repository->get_settings(), $owner, $is_proxy );
		return new WP_REST_Response( $options );
	}

	/**
	 * Permission callback for POST /bookings.
	 *
	 * 予約作成エンドポイントの認可コールバック。ログイン済みのユーザーのみ許可する。
	 *
	 * @return bool|WP_Error
	 */
	public function check_create_booking_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'not_logged_in',
				__( 'Login required.', 'vk-booking-manager' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Load a temporary reservation data payload the current requester may use.
	 *
	 * トークンから仮予約データを読み、現在の操作者が使ってよいもの（所有者の確認を通したもの）だけを返す。
	 * 会員の予約作成（create_booking）と、ゲスト予約の申し込み受付が共有する。
	 *
	 * @param string $token Sanitized temporary reservation data token.
	 * @return array<string, mixed>|WP_Error 仮予約データ。トークンが空・データが無い・壊れている・他人のデータのときは WP_Error。
	 */
	public function load_accessible_draft( string $token ) {
		$token = $this->sanitize_token( $token );
		if ( '' === $token ) {
			return new WP_Error(
				'missing_token',
				__( 'Temporary reservation data token not found.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$draft = get_transient( $this->build_transient_key( $token ) );
		if ( false === $draft ) {
			return new WP_Error( 'draft_not_found', __( 'Temporary reservation data not found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		if ( ! is_array( $draft ) ) {
			return new WP_Error(
				'invalid_draft',
				__( 'Temporary reservation data contents are incomplete.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$draft = $this->backfill_draft_owner( $token, $draft );
		if ( ! $this->can_access_draft( $draft ) ) {
			return new WP_Error(
				'forbidden_draft',
				__( 'You do not have permission to access this temporary reservation data.', 'vk-booking-manager' ),
				array( 'status' => 403 )
			);
		}

		return $draft;
	}

	/**
	 * Finalize a reservation temporary data into a booking post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_booking( WP_REST_Request $request ) {
		// Defense in depth: the permission_callback already enforces login,
		// but we re-check here so direct callers (e.g. tests) still get a 401.
		// 多重防御: permission_callback で既にチェック済みだが、
		// 直接呼び出された場合（テスト等）にも 401 を返すため再チェックする。
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$token = $this->sanitize_token( $request['token'] ?? '' );
		$draft = $this->load_accessible_draft( $token );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$can_override_contact = current_user_can( Capabilities::MANAGE_RESERVATIONS );
		$result               = $this->create_booking_from_draft( $draft, $token, (int) get_current_user_id(), $can_override_contact, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Create a booking post from a reservation draft (entry-point independent).
	 *
	 * 予約の下書きと予約者を受け取り、空きの再確認・同時予約の防止（ロック）・予約投稿の作成・通知までを行う。
	 * REST 以外の入口（ゲスト予約の受付など）からも呼べるよう、ログイン確認・トークン検証・下書きの取得・
	 * 下書きの権限確認は呼び出し側で済ませてから渡す。
	 * フォーム入力（備考・同意・予約者名・電話番号・ご利用になる方など）は、拡張フックへ渡す互換性のため $request から読む。
	 *
	 * 呼び出し側の責務（この関数では確認しない）:
	 * - $draft は、操作者が利用してよいもの（can_access_draft() 相当の確認を通したもの）に限る。
	 * - $can_override_contact は、呼び出し側が current_user_can( Capabilities::MANAGE_RESERVATIONS ) で判定した値だけを渡す。
	 *   リクエストの値や下書きの値から決めてはならない。true にすると、顧客フラグのブロック判定の省略・規約同意の省略・
	 *   確定ステータスでの作成・連絡先の上書き・電話番号が一致する会員への投稿者の付け替えが有効になる。
	 *
	 * 空き枠エラーの詳細を出すかどうか（revalidate_draft_slot() 内の MANAGE_SYSTEM_SETTINGS 判定）だけは、
	 * 引数ではなく現在ログイン中のユーザーの権限で決まる。
	 *
	 * @param array           $draft                仮予約データ（権限確認済みのもの）。
	 * @param string          $token                仮予約データのトークン。作成後に仮予約データを削除する。空文字なら削除しない。
	 * @param int             $acting_user_id       操作者のユーザー ID。本人予約では予約者本人、代理予約では操作者（管理者・オーナー）。予約投稿の投稿者・連絡先・顧客フラグ判定に使う。
	 * @param bool            $can_override_contact 操作者が代理予約の権限（MANAGE_RESERVATIONS）を持つか。
	 * @param WP_REST_Request $request              フォーム入力を持つリクエスト。
	 * @param array|null      $guest                ゲスト予約のお客様情報（name・email・phone・verified_at）。渡すとゲスト予約として作る（docs/specification-guest-booking.md）。
	 *                                              ゲスト予約では $acting_user_id を 0、$can_override_contact を false で渡す。予約したユーザーは無し（投稿者 0）、
	 *                                              予約者の名前・メール・電話番号は $guest の値、ご利用になる方は手入力だけ、重複予約はメールアドレスで判定する。
	 *                                              呼び出し側は、$guest の値をメールの持ち主が確認した後（確認リンクを開いた後）にだけ渡す。
	 * @return array{booking_id:int,status:string}|WP_Error 作成した予約 ID と初期ステータス。失敗時は WP_Error。
	 */
	public function create_booking_from_draft( array $draft, string $token, int $acting_user_id, bool $can_override_contact, WP_REST_Request $request, ?array $guest = null ) {
		$is_guest = null !== $guest;
		if ( $is_guest ) {
			// ゲスト予約は代理予約の権限と両立しない（顧客フラグの判定・規約同意を省略させないため）。
			$can_override_contact = false;
			$acting_user_id       = 0;
			$guest_name           = sanitize_text_field( (string) ( $guest['name'] ?? '' ) );
			$guest_email          = VKBM_Helper::normalize_email( (string) ( $guest['email'] ?? '' ) );
			$guest_phone          = VKBM_Helper::normalize_phone_number( (string) ( $guest['phone'] ?? '' ) );
			$guest_verified_at    = (string) ( $guest['verified_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
			if ( '' === $guest_name || '' === $guest_email || '' === $guest_phone ) {
				return new WP_Error( 'guest_contact_required', __( 'Please enter your name, email address and phone number.', 'vk-booking-manager' ), array( 'status' => 400 ) );
			}
			$user = null;
		} else {
			// 操作者が実在することを最初に確かめる。
			$user = get_userdata( $acting_user_id );
			if ( ! $user instanceof WP_User ) {
				return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
			}
			$guest_name  = '';
			$guest_email = '';
			$guest_phone = '';
		}

		$menu_id = isset( $draft['menu_id'] ) ? (int) $draft['menu_id'] : 0;
		// #465 レビュー指摘: 無料版はスタッフ指名機能自体が常に無効なため、下書きに
		// 保存された resource_id の値によらず常に 0（自動割当）へ正規化する。
		// resolve_staff_ids() の無料版分岐と同じ「無料版では resource_id を信用しない」
		// 方針をここでも揃えることで、下書き生成経路が将来変わっても後段（定員判定・
		// META_RESOURCE_ID 保存）が無料版で古い／改竄された resource_id を受け取らない。
		$staff_id = Staff_Editor::is_enabled() ? (int) ( $draft['resource_id'] ?? 0 ) : 0;
		// 泊の下書き（チェックイン日・チェックアウト日・客室）かどうか。客室は常に下書きの resource_id を使う。
		$is_lodging_draft = isset( $draft['lodging'] ) && is_array( $draft['lodging'] );
		if ( $is_lodging_draft ) {
			$staff_id = (int) ( $draft['resource_id'] ?? 0 );
		}
		// #431: 予約時に指定されたリソースタグ（ターム ID配列・AND条件）。下書きに保存済みの値をそのまま使う
		// （利用者からの改竄を受け付けない。空配列はタグ絞り込みなしを意味する）。
		// 正規化ルールは Resource_Tag_Id_List::normalize() に一元化している（同じ処理を複数箇所へ重複させないため）。
		$tag_ids  = $is_lodging_draft ? array() : Resource_Tag_Id_List::normalize( $draft['resource_tag_ids'] ?? array() );
		$slot     = isset( $draft['slot'] ) && is_array( $draft['slot'] ) ? $draft['slot'] : array();
		$start_at = isset( $slot['start_at'] ) ? (string) $slot['start_at'] : '';
		$end_at   = isset( $slot['end_at'] ) ? (string) $slot['end_at'] : '';

		if ( $menu_id <= 0 || '' === $start_at ) {
			return new WP_Error(
				'invalid_draft',
				__( 'Temporary reservation data contents are incomplete.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// 顧客フラグによる予約ブロック判定。
		// フロントからの予約（顧客本人）のみを対象とし、管理者・オーナーによる代理作成はブロックしない。
		// 空き状況・capacity の再検証より前に判定し、ブロック対象の顧客を早期に弾く（設定メッセージを確実に返す）。
		// 連携処理（顧客フラグ機能）が vkbm_booking_block_check フィルターで WP_Error を返した場合は確定を中止する。
		if ( ! $can_override_contact ) {
			// 第3引数は予約者の連絡先（メールアドレス・電話番号をそろえた値）。ゲスト予約ではユーザーがいないため、これで会員のフラグと照合する。
			$block_contact = $is_guest ? array(
				'email' => $guest_email,
				'phone' => $guest_phone,
			) : array();
			$block_check   = apply_filters( 'vkbm_booking_block_check', null, $acting_user_id, $block_contact );
			if ( is_wp_error( $block_check ) ) {
				return $block_check;
			}
		}

		// 泊単位のプランを時間枠の下書きで確定させない（1枠ぶんの料金で泊の予約が確定してしまうのを防ぐ）。
		if ( ! $is_lodging_draft && Lodging_Price::is_applicable( $menu_id ) ) {
			return Lodging_Booking::dates_required_error();
		}

		// 料金区分が定義されているメニューは、人数を区分ごとの内訳から確定する。
		// ラベル・料金は必ずサーバ保存メタを正とし、下書きに保存済みの人数のみ突き合わせる（改竄防止）。
		$menu_price_tiers = $this->resolve_menu_price_tiers( $menu_id );
		$guest_tiers      = array();
		if ( $is_lodging_draft ) {
			// 泊の下書き: 人数と区分の内訳は Lodging_Booking::resolve_guests()（空き状況・下書きと同じ決め方）で、
			// 現在のメニュー設定に対して決め直す。時間枠の決め方（resolve_guests）を通すと、設定が揃わない
			// 泊のプランで人数が 1 名に丸められ、空室一覧で表示した料金より安い金額で確定してしまう。
			$lodging_guests = Lodging_Booking::resolve_guests(
				$menu_id,
				isset( $draft['guests'] ) ? (int) $draft['guests'] : 1,
				isset( $draft['guest_tiers'] ) && is_array( $draft['guest_tiers'] ) ? $draft['guest_tiers'] : array()
			);
			if ( is_wp_error( $lodging_guests ) ) {
				return $lodging_guests;
			}
			$guests      = $lodging_guests['guests'];
			$guest_tiers = $lodging_guests['guest_tiers'];
		} elseif ( ! empty( $menu_price_tiers ) ) {
			$stored_tiers = isset( $draft['guest_tiers'] ) && is_array( $draft['guest_tiers'] ) ? $draft['guest_tiers'] : array();
			$guest_tiers  = Price_Tiers::resolve_guest_tiers( $menu_price_tiers, $stored_tiers );
			// 区分人数の合計を予約人数とする（枠消費・後方互換集計に使う）。
			// 全区分0名（合計0）の予約は成立させない。max() で1名に丸めると0名予約が確定してしまうため、
			// 合計1名未満は明示的にエラーで拒否する（下書き保存時のバリデーションと対称）。
			$guests = Price_Tiers::total_count( $guest_tiers );
			if ( $guests < 1 ) {
				return new WP_Error(
					'guests_required',
					__( 'Please select at least one guest.', 'vk-booking-manager' ),
					array( 'status' => 400 )
				);
			}
			// #392: 区分内訳の合計人数は resolve_guests() のクランプ（1〜max_capacity）を
			// 通らない経路のため、ここで確定時点の定員（get_menu_max_capacity()）を明示的に
			// 再検証する。下書き作成後に管理者が予約枠の定員を引き下げた場合、区分内訳のままだと
			// 定員超過の人数で確定できてしまう（#392より前は指名予約＝必ず1名だったため
			// 存在しなかった穴）。
			if ( $guests > $this->get_menu_max_capacity( $menu_id ) ) {
				return new WP_Error(
					'guests_exceeded',
					__( 'The number of guests exceeds the maximum that can be booked.', 'vk-booking-manager' ),
					array( 'status' => 400 )
				);
			}
		} else {
			// 予約人数（複数人一括予約）を確定する。メニュー設定に応じて 1〜最大人数にクランプする。
			$guests = $this->resolve_guests( $menu_id, isset( $draft['guests'] ) ? (int) $draft['guests'] : 1 );
		}

		// 指名を使うメニュー、または貸し切り予約ONのメニューの最低申し込み人数（受付制限。#393・#968）。
		// フロントの入力欄下限・警告表示は迂回可能なため、確定時にサーバ側でも権威的に再判定する。
		// get_menu_min_capacity()（催行状態の表示専用、指名メニュー・貸し切り予約ONのメニューで常に0）とは別の取得経路。
		$nomination_min_guests = $this->get_menu_nomination_min_guests( $menu_id );
		if ( $nomination_min_guests > 0 && $guests < $nomination_min_guests ) {
			return new WP_Error(
				'nomination_min_guests',
				Nomination_Min_Guests_Message::build_message( $nomination_min_guests ),
				array( 'status' => 400 )
			);
		}

		// ユーザーによる貸し切り指定（#305）。下書きに保存されたユーザー選択フラグを取得する。
		// フロントの抑止は迂回可能なため、サーバ側でフルゲート（メニュー設定・最小催行人数・空き枠）を再判定する。
		$user_requested_exclusive = ! empty( $draft['user_exclusive'] ) && ! $is_lodging_draft;
		$user_exclusive           = false;
		$exclusive_fee            = 0;
		if ( $user_requested_exclusive ) {
			if ( ! $this->is_user_exclusive_selectable( $menu_id ) ) {
				// 利用者が下書きで明示的に貸し切りを指定したのに、確定時点でメニュー設定が
				// 貸し切り不可（受付OFF・予約枠の定員機能の無効化・指名ON等）に変わっている場合は、
				// 黙って通常予約に変換せず 409 で拒否し、再選択を促す（明示的な意思を失わせない）。
				return new WP_Error(
					'exclusive_unavailable',
					__( 'This slot can no longer be reserved exclusively. Please review your selection.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			} else {
				// 最小催行人数（>=）を満たさない場合は貸し切り指定を拒否する。
				// 正規ヘルパ経由で取得し、最大受付数1以下のメニューでは実効0（判定無効）になるようにする（#320）。
				$min_capacity = $this->get_menu_min_capacity( $menu_id );
				if ( $min_capacity > 0 && $guests < $min_capacity ) {
					return new WP_Error(
						'exclusive_min_capacity',
						__( 'The number of guests does not reach the minimum required for a private booking.', 'vk-booking-manager' ),
						array( 'status' => 400 )
					);
				}
				$user_exclusive = true;
				// 貸し切り料金をサーバ保存メタから権威的に再計算する（フロント送信額は信用しない）。
				$exclusive_fee = $this->calculate_exclusive_fee( $menu_id, $guests );
			}
		}

		// 予約できる日（曜日・日付の指定）の判定は、従来どおり空き枠の再検証より前に行う。
		// 後段の再検証で開始が空き枠と時刻として一致することを確かめるため、ここでの判定結果は
		// 取り直した空き枠の開始で判定した場合と同じになる（#528）。
		$reservation_day_type = (string) get_post_meta( $menu_id, self::MENU_META_RESERVATION_DAY_TYPE, true );
		if ( ! $is_lodging_draft && '' !== $reservation_day_type && ! $this->is_reservation_day_allowed( $reservation_day_type, $start_at, $menu_id ) ) {
			return new WP_Error(
				'invalid_reservation_day',
				__( 'The selected date cannot be reserved. Please choose another date.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// 改竄された下書きでシフト時刻の解釈を変えられないよう、サイト設定で上書きする。
		$timezone = wp_timezone()->getName();

		$lodging_stay = null;
		if ( $is_lodging_draft ) {
			// 泊の予約: 下書きに保存された日付・客室をサーバー側で検証し直し、日時・泊数・金額を計算し直す。
			// 下書きに保存されたスロットの日時・金額は使わない。
			$lodging_stay = Lodging_Booking::prepare(
				$this->availability_service,
				array(
					'menu_id'        => $menu_id,
					'resource_id'    => $staff_id,
					'check_in_date'  => (string) ( $draft['lodging']['check_in_date'] ?? '' ),
					'check_out_date' => (string) ( $draft['lodging']['check_out_date'] ?? '' ),
					'guests'         => $guests,
					'guest_tiers'    => $guest_tiers,
				)
			);
			if ( is_wp_error( $lodging_stay ) ) {
				return $lodging_stay;
			}

			$available_slot = array(
				'start_at'             => $lodging_stay['check_in_at'],
				'end_at'               => $lodging_stay['check_out_at'],
				'service_end_at'       => $lodging_stay['check_out_at'],
				'staff'                => array( 'id' => $lodging_stay['room_id'] ),
				'assignable_staff_ids' => array( $lodging_stay['room_id'] ),
				'auto_assign'          => false,
			);
		} else {
			// Re-check availability for the selected slot before confirming. / 予約確定前に空きを再検証します.
			$available_slot = $this->revalidate_draft_slot( $menu_id, $staff_id, $slot, $timezone, $tag_ids );
			if ( is_wp_error( $available_slot ) ) {
				return $available_slot;
			}
		}

		// 以降の判定・ロック・保存・予約タイトル・通知には、再検証で取り直した空き枠の日時を使う（#528）。
		// 再検証で下書きの日時と時刻として一致することを確かめているため、通常の予約では値は変わらない。
		$start_at               = isset( $available_slot['start_at'] ) ? (string) $available_slot['start_at'] : '';
		$end_at                 = isset( $available_slot['end_at'] ) ? (string) $available_slot['end_at'] : '';
		$slot['start_at']       = $start_at;
		$slot['end_at']         = $end_at;
		$slot['service_end_at'] = isset( $available_slot['service_end_at'] ) ? (string) $available_slot['service_end_at'] : '';

		// Use the latest slot snapshot for staff assignment checks. / 最新の空き情報に基づいて指名判定を行います.
		if ( isset( $available_slot['staff'] ) && is_array( $available_slot['staff'] ) ) {
			$slot['staff'] = $available_slot['staff'];
		}

		$assignable_staff = $this->normalize_assignable_staff_ids( $available_slot['assignable_staff_ids'] ?? array() );

		// #466 差し戻し（安藤さんレビュー指摘）: 無料版はスタッフ指名機能自体が常に無効なため、
		// 下書きの is_staff_preferred フラグも $staff_id 同様に信用しない。改竄された指名フラグで
		// 自動割当（ベストフィット判定）をバイパスされることを防ぐ。
		$is_staff_preferred = Staff_Editor::is_enabled() && ! empty( $draft['is_staff_preferred'] );

		// 自動割当スロットでは、確定直前のロック保持下で単一スタッフへベストフィット割り当てする（分割しない）。
		$is_auto_assign = ! empty( $available_slot['auto_assign'] );

		if ( $is_staff_preferred && $staff_id > 0 ) {
			// 指名予約：選択された1名のスタッフへ割り当てる。
			$is_staff_preferred = true;
		} elseif ( ! empty( $assignable_staff ) ) {
			// 自動割当：スタッフの確定はロック保持下のベストフィット判定（check_capacity_with_mutex）で行う。
			$is_staff_preferred = false;
		}

		// 自動割当でスタッフがまだ未確定（$staff_id <= 0）の場合は、直前の空き枠再検証が返した
		// スタッフ（$slot['staff']['id']）を単一候補として確定処理へ渡す。
		// 無料版で resource_id を 0 へ正規化したケースもここを通るため、上の elseif chain を独立させて
		// おかないと候補ゼロのまま check_capacity_with_mutex() へ渡り、capacity_exceeded を誤って
		// 返してしまう（無料版の予約確定が失敗する回帰）。
		if ( $staff_id <= 0 && isset( $slot['staff']['id'] ) ) {
			$staff_id = (int) $slot['staff']['id'];
		}

		// 無料版では選択可能スタッフの制限を解除.
		// 指名予約で選択スタッフが候補外なら拒否する（自動割当はロック保持下で確定するため対象外）。
		if ( Staff_Editor::is_enabled() && $is_staff_preferred && $staff_id > 0 && ! empty( $assignable_staff ) && ! in_array( $staff_id, $assignable_staff, true ) ) {
			return new WP_Error(
				'staff_unavailable',
				__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		// 指名予約はこの時点でスタッフが確定している必要がある。
		// 自動割当はロック保持下のベストフィット判定でスタッフを確定するため、ここではチェックしない。
		if ( $is_staff_preferred && $staff_id <= 0 ) {
			$singular = vkbm_get_resource_label_singular();
			return new WP_Error(
				'staff_assignment_failed',
				sprintf(
					/* translators: %s: Resource label (singular). */
					__( 'Could not assign person %s. Please try a different frame.', 'vk-booking-manager' ),
					$singular
				),
				array( 'status' => 409 )
			);
		}

		// 備考は下書きと同じ上限（vkbm_draft_memo_max_length）で切り詰める。
		// Truncate memo with the same limit as the draft controller.
		$memo                   = Booking_Draft_Controller::truncate_memo( sanitize_textarea_field( (string) ( $request['memo'] ?? '' ) ) );
		$agree                  = ! empty( $request['agree_terms'] );
		$agree_cancellation     = $request->has_param( 'agree_cancellation_policy' )
			? ! empty( $request['agree_cancellation_policy'] )
			: $agree;
		$agree_tos              = $request->has_param( 'agree_terms_of_service' )
			? ! empty( $request['agree_terms_of_service'] )
			: $agree;
		$customer_name_override = $can_override_contact
			? sanitize_text_field( (string) ( $request['customer_name'] ?? '' ) )
			: '';
		// 代理予約は、枠のロックや決済の確認より前に予約者名を検証する。
		if ( $can_override_contact && '' === preg_replace( '/[\s\p{Z}]+/u', '', $customer_name_override ) ) {
			return new WP_Error(
				'customer_name_required',
				__( 'Please enter the customer name.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}
		// 電話番号を正規化する：全角数字を半角に変換し、数字以外の文字（ハイフン・スペース・括弧など）を除去する.
		$customer_phone = $is_guest
			? $guest_phone
			: ( $can_override_contact
				? VKBM_Helper::normalize_phone_number( sanitize_text_field( (string) ( $request['customer_phone'] ?? '' ) ) )
				: VKBM_Helper::normalize_phone_number( $this->get_user_phone_number( $user->ID ) ) );
		$internal_note  = $can_override_contact
			? sanitize_textarea_field( (string) ( $request['internal_note'] ?? '' ) )
			: '';
		if ( $is_guest ) {
			// ゲスト予約: 予約したユーザーは無し（投稿者 0）。名前・メールアドレスは確認済みの入力値。
			$customer_name_value = $guest_name;
			$booking_author_id   = 0;
			$customer_email      = $guest_email;
		} else {
			$customer_name_value = '' !== $customer_name_override ? $customer_name_override : VKBM_Helper::get_user_display_name( $user );
			$booking_author_id   = (int) $user->ID;
			$customer_email      = (string) $user->user_email;
		}
		$matched_user_id = 0;
		$acting_id       = $is_guest ? 0 : (int) $user->ID;

		if ( $can_override_contact ) {
			// $customer_phone は既に正規化済みのためそのままユーザー検索に使用する.
			if ( '' !== $customer_phone ) {
				// Assign booking author by matching phone number when possible.
				// 電話番号が一致するユーザーがいれば予約投稿者を割り当てる.
				$matched_user = $this->get_user_by_phone_number( $customer_phone );
				if ( $matched_user instanceof WP_User ) {
					$booking_author_id = (int) $matched_user->ID;
					$customer_email    = (string) $matched_user->user_email;
					$matched_user_id   = (int) $matched_user->ID;
				}
			}

			if ( 0 === $matched_user_id ) {
				$customer_email = '';
			}
		}

		$settings              = $this->settings_repository->get_settings();
		$requires_cancellation = '' !== trim( (string) ( $settings['provider_cancellation_policy'] ?? '' ) );
		$requires_tos          = '' !== trim( (string) ( $settings['provider_terms_of_service'] ?? '' ) );
		$status_mode           = sanitize_key( (string) ( $settings['provider_booking_status_mode'] ?? self::BOOKING_STATUS_PENDING ) );
		$initial_status        = self::BOOKING_STATUS_PENDING === $status_mode ? self::BOOKING_STATUS_PENDING : self::BOOKING_STATUS_CONFIRMED;

		if ( $can_override_contact ) {
			$requires_cancellation = false;
			$requires_tos          = false;
			$initial_status        = self::BOOKING_STATUS_CONFIRMED;
		}

		if ( $requires_cancellation && ! $agree_cancellation ) {
			return new WP_Error(
				'cancellation_policy_required',
				__( 'You must agree to the cancellation policy.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( $requires_tos && ! $agree_tos ) {
			return new WP_Error(
				'terms_required',
				__( 'You must agree to the terms of use.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// ご利用になる方を、確定時点の設定と名簿に照らして決める。名簿への保存は予約の成否にかかわらず残す。
		// 名簿の持ち主は、会員自身の予約では予約者、代理予約では電話番号で一致した会員。
		// ゲスト予約は名簿を持たないため、名前の手入力だけを受け付ける（名簿の選択・保存・番号は無視する）。
		$attendee_input = $is_guest
			? array( 'attendee_name' => $request['attendee_name'] ?? ( $draft['attendee_name'] ?? '' ) )
			: array(
				'attendee_id'             => $request['attendee_id'] ?? '',
				'attendee_name'           => $request['attendee_name'] ?? ( $draft['attendee_name'] ?? '' ),
				'attendee_kana'           => $request['attendee_kana'] ?? '',
				'attendee_number'         => $request['attendee_number'] ?? '',
				'attendee_new_id'         => $request['attendee_new_id'] ?? '',
				'attendee_save_to_roster' => ! empty( $request['attendee_save_to_roster'] ),
			);
		$attendee       = ( new Booking_Attendee_Resolver() )->resolve(
			$settings,
			$is_guest ? 0 : ( $can_override_contact ? $matched_user_id : $acting_id ),
			$attendee_input,
			$can_override_contact
		);
		if ( is_wp_error( $attendee ) ) {
			return $attendee;
		}

		// ロック取得前の早期拒否。同時リクエスト対策の本判定は、ロック取得後に行う（下記）。
		if ( $this->has_booking_user_conflict( $can_override_contact, $acting_id, $matched_user_id, $start_at, $end_at, $guest_email ) ) {
			return $this->create_user_conflict_error( $is_guest );
		}

		// 予約確定直前に capacity 上限を再チェック（簡易排他制御付き）。
		// 自動割当時はロック保持下で、人数を収容できる単一スタッフをベストフィットで確定する（分割しない）。
		// Re-check capacity just before confirming, with transient-based mutex.
		// For auto-assignment, pick a single best-fit staff under the lock (no splitting).
		if ( null !== $lodging_stay ) {
			// 泊の予約: 客室 ID 単位のロックを取り、ロック中に期間全体の重なりと販売日を確かめる。
			$capacity_check = $this->check_lodging_room_with_mutex( $lodging_stay );
		} else {
			$capacity_check = $this->check_capacity_with_mutex( $menu_id, $staff_id, $start_at, $end_at, $guests, $is_staff_preferred, $is_auto_assign ? $assignable_staff : array(), $user_exclusive );
		}
		if ( is_wp_error( $capacity_check ) ) {
			return $capacity_check;
		}

		// 取得したロック（ロック名＋所有者トークン）は予約投稿の作成・メタ保存まで保持し、後段で必ず解放する。
		$slot_lock_name  = (string) $capacity_check['name'];
		$slot_lock_token = (string) $capacity_check['token'];

		// ロック取得後の再確認・スタッフ確定・料金計算・決済の確認・予約投稿の作成・メタ保存の途中で
		// 例外が発生してもロックが残らないよう、ロック取得直後から全メタ保存までを try/finally で囲み、finally で必ず解放する。
		// 決済の確認（vkbm_booking_payment_check）のコールバックが例外を投げた場合も、ここで解放される。
		// 解放はこの finally の1か所だけで行う（途中の return でも1回だけ解放される）。
		try {
			// 同じ利用者の同時リクエストが先に予約を確定している場合があるため、ロック取得後に重複予約をもう一度確認する（#692）。
			// 上のロック前の確認だけでは、2件のリクエストが同時に確認を通り抜けて両方確定してしまう。
			if ( $this->has_booking_user_conflict( $can_override_contact, $acting_id, $matched_user_id, $start_at, $end_at, $guest_email ) ) {
				return $this->create_user_conflict_error( $is_guest );
			}

			// 自動割当時はベストフィットで確定したスタッフIDを採用する。指名予約は渡した staff_id がそのまま返る。
			$staff_id = (int) $capacity_check['staff_id'];
			if ( $staff_id <= 0 ) {
				$singular = vkbm_get_resource_label_singular();
				return new WP_Error(
					'staff_assignment_failed',
					sprintf(
						/* translators: %s: Resource label (singular). */
						__( 'Could not assign person %s. Please try a different frame.', 'vk-booking-manager' ),
						$singular
					),
					array( 'status' => 409 )
				);
			}

			// #431: 念のための最終チェック。空き枠計算（resolve_staff_ids()）の時点でタグを持つ
			// リソースだけに候補を絞っているため通常はここで弾かれることは無いが、多層防御として
			// 「割り当て結果のリソースが指定タグを『すべて』持っているか」をここでも確認する。
			// 外れていれば 409 で予約を拒否する（取得済みロックは finally で解放される）。
			if ( ! Resource_Tag_Taxonomy::resource_has_all_tags( $staff_id, $tag_ids ) ) {
				return new WP_Error(
					'resource_tag_unavailable',
					vkbm_get_resource_tag_empty_message(),
					array( 'status' => 409 )
				);
			}

			// 決済プラグインの payment_check フィルター用に wp_insert_post() より前に料金を確定する。
			$service_base_price = max( 0, (int) get_post_meta( $menu_id, '_vkbm_base_price', true ) );
			$nomination_fee     = isset( $draft['nomination_fee'] ) ? (int) $draft['nomination_fee'] : 0;
			// #391: サイト全体の判定からメニュー単位の判定へ置き換え。既存メニュー（新メタ未設定＝指名を使う）は
			// サイト全体の設定と等価な結果になるため、この置き換えで既存メニューの挙動は変わらない。
			if ( ! Staff_Editor::is_nomination_enabled_for_menu( $menu_id ) ) {
				$nomination_fee = 0;
			}
			$disable_nomination_fee = (string) get_post_meta( $menu_id, '_vkbm_disable_nomination_fee', true );
			// 宿泊のサイトは時間枠のプランでも指名料を 0 円にする（下書きに残った金額も使わない）。
			if ( '1' === $disable_nomination_fee || Lodging_Price::is_nomination_fee_disabled_for_site() ) {
				$nomination_fee = 0;
			} elseif ( $nomination_fee <= 0 && $is_staff_preferred && $staff_id > 0 ) {
				$nomination_fee = $this->get_staff_nomination_fee( $menu_id, $staff_id );
			}
			// 料金区分が定義されている場合は Σ(区分料金 × 区分人数) + 指名料 + 貸し切り料金。
			// 区分未定義は従来どおり サービス基本料金 × 人数 + 指名料 + 貸し切り料金（完全後方互換）。
			// 料金はクライアントから送られた金額を信用せず、サーバ保存メタを正として再計算する。
			if ( ! empty( $guest_tiers ) ) {
				$base_total_price = max( 0, Price_Tiers::total_price( $guest_tiers ) + max( 0, $nomination_fee ) + max( 0, $exclusive_fee ) );
			} else {
				$base_total_price = max( 0, ( $service_base_price * $guests ) + max( 0, $nomination_fee ) + max( 0, $exclusive_fee ) );
			}
			if ( null !== $lodging_stay ) {
				// 泊の予約は、サーバーの設定から計算した合計（（プランの料金＋客室の追加料金）× 泊数）を使う。
				// 客室の追加料金は合計に含めるため、指名料としては保存しない。
				$nomination_fee   = 0;
				$base_total_price = (int) $lodging_stay['price']['total'];
			}

			/**
			 * 決済プラグインが payment_intent_id を検証する拡張ポイント。
			 * WP_Error を返すと予約の作成を中断し、そのまま REST レスポンスとして返す。
			 * 予約枠のロックを保持したまま呼ばれる。例外を投げた場合もロックは finally で解放される。
			 *
			 * @param true|WP_Error   $result           判定結果。初期値は true（問題なし）。
			 * @param WP_REST_Request $request          現在のリクエスト（payment_intent_id を含む場合がある）。
			 * @param array           $draft            仮予約データ。
			 * @param int             $base_total_price 指名料・貸し切り料金を含む基本料金合計（円）。
			 * @param array           $booking_context  予約の種類。is_guest（ゲスト予約か）、ゲスト予約では guest（name・email・phone）。
			 */
			$payment_check = apply_filters(
				'vkbm_booking_payment_check',
				true,
				$request,
				$draft,
				$base_total_price,
				$this->build_booking_context( $is_guest, $guest_name, $guest_email, $guest_phone )
			);
			if ( is_wp_error( $payment_check ) ) {
				// ロックは finally で解放される。
				return $payment_check;
			}

			// WordPress は投稿者 0 を空とみなして、保存した人（確認リンクを開いたログイン中のユーザーなど）に置き換える。
			// ゲスト予約は予約したユーザーを持たないため、保存する直前に投稿者を 0 へ戻す。
			$keep_guest_author = static function ( $data ) {
				$data['post_author'] = 0;
				return $data;
			};
			if ( $is_guest ) {
				add_filter( 'wp_insert_post_data', $keep_guest_author, PHP_INT_MAX );
			}
			try {
				$booking_id = wp_insert_post(
					array(
						'post_type'   => Booking_Post_Type::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $this->generate_booking_title( $customer_name_value, $start_at ),
						'post_author' => $booking_author_id,
					)
				);
			} finally {
				if ( $is_guest ) {
					remove_filter( 'wp_insert_post_data', $keep_guest_author, PHP_INT_MAX );
				}
			}

			if ( is_wp_error( $booking_id ) ) {
				return $booking_id;
			}
			// wp_insert_post() は失敗時に WP_Error だけでなく 0 を返す場合がある。
			// 0 も失敗として扱い、無効な投稿IDでメタ保存に進んだり成功レスポンスを返したりしないようにする。
			if ( 0 === $booking_id ) {
				return new WP_Error(
					'booking_creation_failed',
					__( 'Failed to create the booking. Please try again.', 'vk-booking-manager' ),
					array( 'status' => 500 )
				);
			}

			$start_for_storage     = $this->format_datetime_for_storage( $start_at );
			$end_for_storage       = $this->format_datetime_for_storage( $end_at );
			$service_end_at        = isset( $slot['service_end_at'] ) ? (string) $slot['service_end_at'] : '';
			$service_end_for_store = $this->format_datetime_for_storage( $service_end_at );

			$start_meta_value = '' !== $start_for_storage ? $start_for_storage : $start_at;
			update_post_meta( $booking_id, Booking_Overlap::META_START, $start_meta_value );

			$service_end_value = '' !== $service_end_for_store ? $service_end_for_store : ( '' !== $service_end_at ? $service_end_at : ( '' !== $end_for_storage ? $end_for_storage : $end_at ) );
			if ( $service_end_value ) {
				update_post_meta( $booking_id, Booking_Overlap::META_END, $service_end_value );
			}
			if ( '' !== $end_for_storage || '' !== $end_at ) {
				$total_end_value = '' !== $end_for_storage ? $end_for_storage : $end_at;
				update_post_meta( $booking_id, Booking_Overlap::META_TOTAL_END, $total_end_value );
			}
			if ( $staff_id > 0 ) {
				update_post_meta( $booking_id, Booking_Overlap::META_RESOURCE, $staff_id );
			}
			// #431: 予約時に指定したリソースタグ（希望タグ）のターム ID 配列を保存する。
			// タグ未指定の予約はメタを保存しない（完了条件どおり）。
			if ( ! empty( $tag_ids ) ) {
				update_post_meta( $booking_id, self::META_RESOURCE_TAG_IDS, $tag_ids );
			} else {
				delete_post_meta( $booking_id, self::META_RESOURCE_TAG_IDS );
			}
			update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
			// 予約人数を保存する（複数人一括予約）。単価×人数または区分料金合計の計算に用いる。
			update_post_meta( $booking_id, self::META_GUESTS, $guests );
			if ( null !== $lodging_stay ) {
				// 泊数と1泊あたりの合計（部屋全体）を保存する。チェックイン・チェックアウトの日時は開始・終了のメタに保存済み。
				update_post_meta( $booking_id, Lodging_Booking::META_NIGHTS, (int) $lodging_stay['nights'] );
				// 泊ごとの内訳を固定で保存する。1泊あたりの合計は、すべての泊が同じ金額のときだけ保存する。
				update_post_meta( $booking_id, Lodging_Booking::META_NIGHT_BREAKDOWN, $lodging_stay['price']['night_breakdown'] );
				if ( null !== $lodging_stay['price']['price_per_night'] ) {
					update_post_meta( $booking_id, Lodging_Booking::META_PRICE_PER_NIGHT, (int) $lodging_stay['price']['price_per_night'] );
				} else {
					delete_post_meta( $booking_id, Lodging_Booking::META_PRICE_PER_NIGHT );
				}
			}
			// 貸し切り予約フラグを保存する。
			// (1) 設定ON（_vkbm_exclusive_when_booked）のメニューでは予約確定時に自動で true を付与する（#304）。
			// (2) ユーザーによる貸し切り指定（#305）でユーザーが貸切を選択し、フルゲートを通過した場合も true を付与する。
			// いずれかが成立すればこの予約が枠を専有する（以降その枠は他のユーザーが予約できなくなる）。
			if ( $this->is_menu_exclusive_when_booked( $menu_id ) || $user_exclusive ) {
				update_post_meta( $booking_id, self::META_EXCLUSIVE, true );
			} else {
				delete_post_meta( $booking_id, self::META_EXCLUSIVE );
			}
			// 貸し切り料金（#305）のスナップショットを保存する。メニュー側の単価を後で変えても
			// 過去予約の金額が動かないよう、確定時の金額を予約レコードに保存する（base_price スナップショットと同思想）。
			// 0（ユーザー貸切なし／適用外人数到達等）の場合はメタを削除する。
			if ( $exclusive_fee > 0 ) {
				update_post_meta( $booking_id, self::META_EXCLUSIVE_FEE, $exclusive_fee );
			} else {
				delete_post_meta( $booking_id, self::META_EXCLUSIVE_FEE );
			}
			// 料金区分の人数内訳スナップショットを保存する（区分未定義なら削除する＝従来表示）。
			if ( ! empty( $guest_tiers ) ) {
				update_post_meta( $booking_id, self::META_GUEST_TIERS, $guest_tiers );
			} else {
				delete_post_meta( $booking_id, self::META_GUEST_TIERS );
			}
			update_post_meta( $booking_id, self::META_SERVICE_BASE_PRICE, $service_base_price );
			update_post_meta( $booking_id, self::META_CUSTOMER, $customer_name_value );
			if ( '' !== $attendee['id'] ) {
				update_post_meta( $booking_id, Booking_Attendee_Resolver::META_ID, $attendee['id'] );
			}
			if ( '' !== $attendee['name'] ) {
				update_post_meta( $booking_id, Attendee_Name::META_KEY, $attendee['name'] );
			}
			if ( '' !== $attendee['number'] ) {
				update_post_meta( $booking_id, Booking_Attendee_Resolver::META_NUMBER, $attendee['number'] );
			}
			update_post_meta( $booking_id, self::META_CUSTOMER_MAIL, $customer_email );
			if ( '' !== $customer_phone ) {
				update_post_meta( $booking_id, self::META_CUSTOMER_TEL, $customer_phone );
			} else {
				delete_post_meta( $booking_id, self::META_CUSTOMER_TEL );
			}
			update_post_meta( $booking_id, Booking_Overlap::META_STATUS, $initial_status );
			update_post_meta( $booking_id, self::META_IS_PREFERRED, $is_staff_preferred ? '1' : '' );
			update_post_meta( $booking_id, '_vkbm_booking_agreed_cancellation_policy', $agree_cancellation ? '1' : '' );
			update_post_meta( $booking_id, '_vkbm_booking_agreed_terms_of_service', $agree_tos ? '1' : '' );
			if ( $nomination_fee > 0 ) {
				update_post_meta( $booking_id, self::META_NOMINATION_FEE, $nomination_fee );
			} else {
				delete_post_meta( $booking_id, self::META_NOMINATION_FEE );
			}

			// 基本料金合計を予約確定時に数値で確定保存する（wp_insert_post 前に計算済みの値を使用）。
			update_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, $base_total_price );
			if ( '' !== $memo ) {
				update_post_meta( $booking_id, self::META_NOTE, wp_slash( $memo ) );
			}
			if ( $is_guest ) {
				// ゲスト予約の印と、メールの持ち主が確認した日時（UTC）。
				update_post_meta( $booking_id, Booking_Source::META_KEY, Booking_Source::VALUE_GUEST );
				update_post_meta( $booking_id, Booking_Source::META_GUEST_VERIFIED_AT, $guest_verified_at );
				// キャンセル用リンクのトークン。ハッシュだけを予約に保存し、生の値は作成直後のお知らせメールの URL にだけ入れる
				// （同じリクエストの間だけ覚えておき、お知らせメールを組み立てるときに受け取る）。
				Guest_Cancel_Controller::issue_token( (int) $booking_id, true );
			}
			if ( '' !== $internal_note ) {
				update_post_meta( $booking_id, self::META_INTERNAL_NOTE, wp_slash( $internal_note ) );
			} else {
				delete_post_meta( $booking_id, self::META_INTERNAL_NOTE );
			}
		} finally {
			// すべての予約メタを保存し終えた（または途中で離脱した）のでロックを解放する。
			// 以降の通知処理はロック不要。
			$this->release_slot_mutex( $slot_lock_name, $slot_lock_token );
		}

		/**
		 * 予約の作成が完了したときに発火する拡張ポイント。
		 * 予約枠のロック解放後・仮予約データ削除前に発火する（予約の全メタは保存済み）。
		 * 予約投稿の作成やメタ保存の途中で処理を中断した場合（早期 return・例外）は発火しない。
		 * 決済プラグインはこのフックで決済情報を予約に保存する。
		 *
		 * 予約はこの時点で成立しているため、コールバックが例外を投げても予約は成功として扱い、
		 * エラーログに記録したうえで仮予約データの削除・通知・変更の通知を続ける。
		 * 例外を投げたコールバックより後に登録されたコールバックは実行されない。
		 *
		 * @param int             $booking_id 作成した予約投稿の ID。
		 * @param array           $draft      仮予約データ（メニュー・金額など）。
		 * @param WP_REST_Request $request    元の REST リクエスト（payment_intent_id を含む）。
		 * @param array           $booking_context 予約の種類。is_guest（ゲスト予約か）、ゲスト予約では guest（name・email・phone）。
		 */
		try {
			do_action( 'vkbm_booking_post_created', $booking_id, $draft, $request, $this->build_booking_context( $is_guest, $guest_name, $guest_email, $guest_phone ) );
		} catch ( \Throwable $e ) {
			// 予約は作成済みのため、拡張側の例外で結果を覆さず、管理者が追跡できるようログだけ残す。
			// 仮予約データ・リクエスト（個人情報を含む）とスタックトレース（引数の値を含むことがある）は記録しない。
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 拡張プラグインの不具合を管理者が追跡できるよう記録する（個人情報は含めない）。
			error_log(
				sprintf(
					'[vkbm] vkbm_booking_post_created callback failed. booking_id=%d exception=%s message=%s at=%s:%d',
					(int) $booking_id,
					get_class( $e ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
		}

		if ( '' !== $token ) {
			delete_transient( $this->build_transient_key( $token ) );
		}

		if ( self::BOOKING_STATUS_CONFIRMED === $initial_status ) {
			$this->notification_service->handle_confirmed_creation( (int) $booking_id );
		} else {
			$this->notification_service->handle_pending_creation( (int) $booking_id );
		}

		// #474: 新規作成のため「変更前」は無い（null）。種別（created）の判定はディスパッチャー側で行う。
		$this->event_dispatcher->dispatch_change( (int) $booking_id, null );

		return array(
			'booking_id' => $booking_id,
			'status'     => $initial_status,
		);
	}

	/**
	 * 人数 N を収容できるスタッフの中から「残りが最も少ない（きつきつの）」スタッフを選ぶ（ベストフィット）。
	 *
	 * 1予約は分割せず単一スタッフへ割り当てる。完全に空いているスタッフを温存し、
	 * スロットの空き（= 最も空きの大きい単一スタッフの残り）を高く保つことで、
	 * 後から来る大人数予約の機会を守る。残りが同じ場合は候補配列の順（スタッフ登録順）で先頭を選ぶ。
	 *
	 * @param array<int>     $candidates   候補スタッフID。
	 * @param array<int,int> $loads        staff_id => 既存予約人数。
	 * @param int            $guests       割り当てる人数。
	 * @param int            $max_capacity 1スタッフあたりの最大受付数。
	 * @return int 選ばれたスタッフID。収容できる候補が無ければ 0。
	 */
	private function select_best_fit_staff( array $candidates, array $loads, int $guests, int $max_capacity ): int {
		$guests       = max( 1, $guests );
		$max_capacity = max( 1, $max_capacity );

		$best_id        = 0;
		$best_remaining = PHP_INT_MAX;
		foreach ( $candidates as $sid ) {
			$sid = (int) $sid;
			if ( $sid <= 0 ) {
				continue;
			}
			$load      = isset( $loads[ $sid ] ) ? (int) $loads[ $sid ] : 0;
			$remaining = $max_capacity - $load;
			// このスタッフだけでは全員を収容できない場合は対象外（分割はしない）。
			if ( $remaining < $guests ) {
				continue;
			}
			// 残りが最も少ない（きつきつの）スタッフを選ぶ。同点は先頭（登録順）を保持する。
			if ( $remaining < $best_remaining ) {
				$best_remaining = $remaining;
				$best_id        = $sid;
			}
		}

		return $best_id;
	}

	/**
	 * スロット内の各スタッフについて、同一メニューの担当人数と別メニューの予約の有無を要約する。
	 *
	 * #394 / #656: クエリ本体と判定は Staff_Load_Calculator（空き枠の計算・管理画面と共通）へ集約し、
	 * ここでは ISO8601 → サイトのタイムゾーンの 'Y-m-d H:i:s' への変換のみを担う薄いラッパーにする
	 * （同じ判定ロジックを複数箇所に写経しない）。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @return array<int, array{same_menu_guests:int, has_other_menu:bool}> staff_id => 要約。予約が無いスタッフは含まれない。
	 */
	private function get_staff_summaries_for_slot( int $menu_id, string $start_at, string $end_at ): array {
		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return array();
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		return Staff_Load_Calculator::get_staff_summaries_for_slot( $menu_id, $start_for_storage, $end_for_storage );
	}

	/**
	 * Determine if the staff already has a booking overlapping the slot.
	 *
	 * @param int    $staff_id Staff ID.
	 * @param string $start_at Slot start (ISO8601).
	 * @param string $end_at   Slot end (ISO8601).
	 * @return bool
	 */
	private function has_staff_conflict( int $staff_id, string $start_at, string $end_at ): bool {
		if ( $staff_id <= 0 ) {
			return true;
		}

		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );

		if ( '' === $start_for_storage ) {
			return true;
		}

		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		// 重なりの条件は泊の空室判定と共有する（Booking_Overlap）。
		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => Booking_Overlap::build_meta_query( array( $staff_id ), $start_for_storage, $end_for_storage ),
			)
		);

		return $query->have_posts();
	}

	/**
	 * 予約者本人（代理入力では電話番号で一致した会員）が、同じ時間帯に予約を持っているかを判定する。
	 *
	 * @param bool   $can_override_contact 予約管理権限による代理入力か。
	 * @param int    $acting_user_id       操作者のユーザーID。
	 * @param int    $matched_user_id      代理入力で電話番号が一致した会員のユーザーID（一致なしは 0）。
	 * @param string $start_at             枠の開始日時（ISO8601）。
	 * @param string $end_at               枠の終了日時（ISO8601）。
	 * @param string $guest_email          ゲスト予約のそろえたメールアドレス（会員の予約では空文字）。
	 * @return bool 重複する予約があれば true。
	 */
	private function has_booking_user_conflict( bool $can_override_contact, int $acting_user_id, int $matched_user_id, string $start_at, string $end_at, string $guest_email = '' ): bool {
		// ゲスト予約は予約したユーザーがいないため、そろえたメールアドレスで重複を確認する。
		if ( '' !== $guest_email ) {
			return $this->has_guest_email_conflict( $guest_email, $start_at, $end_at );
		}

		// 代理入力では、一致した会員がいる場合だけ重複を確認する。
		$target_user_id = $can_override_contact ? $matched_user_id : $acting_user_id;

		return $target_user_id > 0 && $this->has_user_conflict( $target_user_id, $start_at, $end_at );
	}

	/**
	 * 同じ日時に予約が既にある場合のエラーを作る。
	 *
	 * @param bool $is_guest ゲスト予約か（メールアドレス基準の文言にする）。
	 * @return WP_Error 409 の booking_time_conflict エラー。
	 */
	private function create_user_conflict_error( bool $is_guest = false ): WP_Error {
		return new WP_Error(
			'booking_time_conflict',
			$is_guest
				? __( 'You already have a reservation at the same date and time with this email address. Please change the date and time.', 'vk-booking-manager' )
				: __( 'A reservation for the same date and time already exists. Please change the date and time.', 'vk-booking-manager' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Determine if the user already has a booking overlapping the slot.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $start_at Slot start (ISO8601).
	 * @param string $end_at   Slot end (ISO8601).
	 * @return bool
	 */
	private function has_user_conflict( int $user_id, string $start_at, string $end_at ): bool {
		if ( $user_id <= 0 ) {
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
				'post_type'              => Booking_Post_Type::POST_TYPE,
				'post_status'            => array( 'publish' ),
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				// 別リクエストが保存した予約をロック取得後の再確認で見落とさないよう、検索結果をキャッシュさせない（#692）。
				// このプロセスのキャッシュの更新日時は別プロセスの保存では変わらず、0件の結果が残り続けるため。
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => Booking_Overlap::META_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => Booking_Overlap::META_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => Booking_Overlap::META_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		return $query->have_posts();
	}

	/**
	 * 予約の種類を、拡張フック（決済の確認・予約作成後の通知）へ渡す配列にまとめる。
	 *
	 * @param bool   $is_guest ゲスト予約か。
	 * @param string $name     ゲスト予約のお名前。
	 * @param string $email    ゲスト予約のメールアドレス（そろえた値）。
	 * @param string $phone    ゲスト予約の電話番号（そろえた値）。
	 * @return array<string, mixed> is_guest と、ゲスト予約のときだけ guest（name・email・phone）。
	 */
	private function build_booking_context( bool $is_guest, string $name, string $email, string $phone ): array {
		if ( ! $is_guest ) {
			return array( 'is_guest' => false );
		}

		return array(
			'is_guest' => true,
			'guest'    => array(
				'name'  => $name,
				'email' => $email,
				'phone' => $phone,
			),
		);
	}

	/**
	 * ゲスト予約のメールアドレスと同じメールアドレスの予約（会員の予約を含む）が、同じ時間帯にあるかを判定する。
	 *
	 * 予約に保存したメールアドレスで探す。あわせて、そのメールアドレスを今持つ会員がいれば、その会員の予約も確かめる
	 * （会員が予約したあとにメールアドレスを変えた場合に備える）。キャンセル・無断キャンセルの予約は重複に含めない。
	 *
	 * @param string $email    そろえたメールアドレス（前後の空白を除いた小文字）。
	 * @param string $start_at 枠の開始日時（ISO8601）。
	 * @param string $end_at   枠の終了日時（ISO8601）。
	 * @return bool 重複する予約があれば true。
	 */
	private function has_guest_email_conflict( string $email, string $start_at, string $end_at ): bool {
		if ( '' === $email ) {
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
				'post_type'              => Booking_Post_Type::POST_TYPE,
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				// 別リクエストが保存した予約をロック取得後の再確認で見落とさないよう、検索結果をキャッシュさせない（#692）。
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_CUSTOMER_MAIL,
						'value' => $email,
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => Booking_Overlap::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => Booking_Overlap::META_STATUS,
							'value'   => array( Booking_Overlap::STATUS_CANCELLED, Booking_Overlap::STATUS_NO_SHOW ),
							'compare' => 'NOT IN',
						),
					),
					array(
						'key'     => Booking_Overlap::META_START,
						'value'   => $end_for_storage,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => Booking_Overlap::META_TOTAL_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => Booking_Overlap::META_END,
							'value'   => $start_for_storage,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
				),
			)
		);

		if ( $query->have_posts() ) {
			return true;
		}

		// 予約のあとにメールアドレスを変えた会員の予約も確かめる。
		$member = get_user_by( 'email', $email );

		return $member instanceof WP_User && $this->has_user_conflict( (int) $member->ID, $start_at, $end_at );
	}

	/**
	 * Retrieve nomination fee for staff.
	 *
	 * #391: このメニューで指名機能が無効なら常に0を返す（メニュー単位の判定）。
	 *
	 * @param int $menu_id  Service menu post ID.
	 * @param int $staff_id Staff ID.
	 * @return int
	 */
	private function get_staff_nomination_fee( int $menu_id, int $staff_id ): int {
		if ( $staff_id <= 0 || Lodging_Price::is_nomination_fee_disabled_for_site() ) {
			return 0;
		}

		if ( ! Staff_Editor::is_nomination_enabled_for_menu( $menu_id ) ) {
			return 0;
		}

		$raw = get_post_meta( $staff_id, Staff_Editor::META_NOMINATION_FEE, true );

		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

			$fee = (int) $raw;

		if ( $fee <= 0 ) {
			return 0;
		}
			return $fee;
	}

	/**
	 * Build transient key name.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function build_transient_key( string $token ): string {
		return self::DRAFT_PREFIX . $token;
	}

	/**
	 * Sanitize token input.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function sanitize_token( string $token ): string {
		$token = sanitize_key( $token );
		return $token ? $token : '';
	}

	/**
	 * Check whether the current requester can access the temporary reservation data payload.
	 *
	 * @param array<string, mixed> $payload Temporary reservation data payload.
	 * @return bool
	 */
	private function can_access_draft( array $payload ): bool {
		if ( current_user_can( Capabilities::MANAGE_RESERVATIONS ) ) {
			return true;
		}

		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		if ( $owner_user_id > 0 ) {
			return (int) get_current_user_id() === $owner_user_id;
		}

		$owner_key = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';
		if ( '' === $owner_key ) {
			return false;
		}

		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return false;
		}

		$cookie_value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );
		if ( '' === $cookie_value ) {
			return false;
		}

		return hash_equals( $owner_key, $cookie_value );
	}

	/**
	 * Backfill ownership data for older temporary reservation data.
	 *
	 * @param string              $token Temporary reservation data token.
	 * @param array<string,mixed> $payload Temporary reservation data payload.
	 * @return array<string, mixed>
	 */
	private function backfill_draft_owner( string $token, array $payload ): array {
		$owner_user_id = isset( $payload['owner_user_id'] ) ? (int) $payload['owner_user_id'] : 0;
		$owner_key     = isset( $payload['owner_key'] ) ? sanitize_key( (string) $payload['owner_key'] ) : '';

		if ( $owner_user_id > 0 || '' !== $owner_key ) {
			return $payload;
		}

		if ( empty( $_COOKIE[ self::OWNER_COOKIE ] ) ) {
			return $payload;
		}

		$cookie_value = sanitize_key( (string) wp_unslash( $_COOKIE[ self::OWNER_COOKIE ] ) );
		if ( '' === $cookie_value ) {
			return $payload;
		}

		$payload['owner_key'] = $cookie_value;
		return $payload;
	}

	/**
	 * Generate readable booking title.
	 *
	 * @param string $customer Customer name.
	 * @param string $start_at Start datetime.
	 * @return string
	 */
	private function generate_booking_title( string $customer, string $start_at ): string {
		$label           = $customer ? $customer : __( 'Reservation', 'vk-booking-manager' );
		$formatted_start = $this->format_datetime_for_title( $start_at );
		return sprintf( '%s / %s', $label, $formatted_start );
	}

	/**
	 * Convert ISO8601 datetime to a compact booking title format.
	 *
	 * @param string $value ISO8601 datetime.
	 * @return string
	 */
	private function format_datetime_for_title( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( 'Y.n.j H:i', $timestamp, wp_timezone() );
	}

	/**
	 * Convert ISO8601 datetime to site-local Y-m-d H:i:s string.
	 *
	 * @param string $value ISO8601 datetime.
	 * @return string
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
	 * Retrieve stored phone number for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_user_phone_number( int $user_id ): string {
		$phone = trim( (string) get_user_meta( $user_id, 'phone_number', true ) );
		return $phone;
	}

	/**
	 * Retrieve a user by normalized phone number.
	 *
	 * @param string $phone Normalized phone number.
	 * @return WP_User|null
	 */
	private function get_user_by_phone_number( string $phone ): ?WP_User {
		if ( '' === $phone ) {
			return null;
		}

		// Match on normalized phone number to avoid formatting differences.
		// 表記揺れを避けるため正規化済みの電話番号で検索する.
		$users = get_users(
			array(
				'meta_key'    => 'phone_number',
				'meta_value'  => $phone,
				'number'      => 1,
				'count_total' => false,
			)
		);

		if ( empty( $users ) ) {
			return null;
		}

		$user = $users[0];
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Determine if booking date matches menu restriction.
	 *
	 * @param string $reservation_day_type Restriction type (weekend|weekday|custom_weekday|custom_date).
	 * @param string $start_at             Slot start (ISO8601).
	 * @param int    $menu_id              Menu post ID（custom 種別の詳細設定を読み込むために使用）。
	 * @return bool
	 */
	private function is_reservation_day_allowed( string $reservation_day_type, string $start_at, int $menu_id ): bool {
		if ( '' === $reservation_day_type ) {
			return true;
		}

		// 予約確定時は ISO8601 文字列を strtotime で解釈し、wp_timezone() で日付を組み立てる。
		// パース経路は従来どおりこの箇所で保持し、失敗時は許可（true）へフォールバックする。
		$timestamp = strtotime( $start_at );
		if ( false === $timestamp ) {
			return true;
		}

		// タイムスタンプからサイトのタイムゾーンで日付を組み立て、日付ベース判定へ渡す。
		$datetime = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );

		// 曜日指定・日付指定の詳細設定を取得する（該当種別のときだけ参照される）。
		$custom_weekdays = get_post_meta( $menu_id, self::MENU_META_RESERVATION_CUSTOM_WEEKDAYS, true );
		$custom_dates    = get_post_meta( $menu_id, self::MENU_META_RESERVATION_CUSTOM_DATES, true );

		// 共有ヘルパーの日付ベース判定へ委譲する。
		// '' / weekend / weekday は is_date_allowed() 内で従来どおり曜日番号ベースの判定になる。
		return Reservation_Day::is_date_allowed(
			$reservation_day_type,
			$datetime,
			array(
				'weekdays' => is_array( $custom_weekdays ) ? $custom_weekdays : array(),
				'dates'    => is_array( $custom_dates ) ? $custom_dates : array(),
			)
		);
	}

	/**
	 * Revalidate temporary reservation data slot availability before booking confirmation.
	 *
	 * 予約確定前に予約一時データの枠がまだ空いているか再検証します。
	 * エラーの詳細を出すかどうかは、操作者の引数ではなく現在ログイン中のユーザーの権限（MANAGE_SYSTEM_SETTINGS）で決まります。
	 *
	 * @param int                  $menu_id            Menu post ID.
	 * @param int                  $preferred_staff_id Preferred staff ID (0 for auto).
	 * @param array<string, mixed> $slot               Temporary reservation data slot payload.
	 * @param string               $timezone           Timezone string (optional).
	 * @param array<int>           $tag_ids            #431: リソースタグ絞り込み（ターム ID配列・AND条件）。
	 * @return array<string, mixed>|WP_Error
	 */
	private function revalidate_draft_slot(
		int $menu_id,
		int $preferred_staff_id,
		array $slot,
		string $timezone,
		array $tag_ids = array()
	) {
		$slot_id  = isset( $slot['slot_id'] ) ? sanitize_text_field( (string) $slot['slot_id'] ) : '';
		$start_at = isset( $slot['start_at'] ) ? sanitize_text_field( (string) $slot['start_at'] ) : '';
		$end_at   = isset( $slot['end_at'] ) ? sanitize_text_field( (string) $slot['end_at'] ) : '';

		if ( '' === $slot_id || '' === $start_at ) {
			return new WP_Error(
				'invalid_slot',
				__( 'Reservation slot information is incorrect.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		// Resolve timezone from draft or slot data. / 予約一時データまたは枠情報からタイムゾーンを補正します.
		$timezone = sanitize_text_field( $timezone );
		if ( '' === $timezone ) {
			$timezone = $this->extract_timezone_from_datetime( $start_at );
		}

		$date = $this->extract_slot_date( $start_at, $timezone );
		if ( '' === $date ) {
			return new WP_Error(
				'invalid_slot',
				__( 'Reservation slot information is incorrect.', 'vk-booking-manager' ),
				array( 'status' => 400 )
			);
		}

		$availability = $this->availability_service->get_daily_slots(
			array(
				'menu_id'          => $menu_id,
				'resource_id'      => $preferred_staff_id,
				'date'             => $date,
				'timezone'         => $timezone,
				// #431: リソースタグ絞り込み（ターム ID配列・AND条件）を空き枠の再検証にも反映する。
				'resource_tag_ids' => $tag_ids,
			)
		);

		if ( is_wp_error( $availability ) ) {
			// #411 安藤さんレビュー指摘: get_daily_slots() のエラー識別子・メッセージ（担当スタッフ
			// 未設定等の内部設定を示す）をそのまま返すと、/bookings はログイン必須とはいえ
			// 一般の予約者にまで内部設定の詳細が伝わってしまう。/calendar-meta /availabilities で
			// 塞いだのと同じ原因のため、権限が無いユーザーには既存の slot_unavailable 相当の
			// 汎用エラーへ寄せる（新規の翻訳文字列を増やさないよう、下記の既存メッセージを再利用する）。
			if ( current_user_can( Capabilities::MANAGE_SYSTEM_SETTINGS ) ) {
				return new WP_Error(
					$availability->get_error_code(),
					$availability->get_error_message(),
					array( 'status' => 409 )
				);
			}

			return new WP_Error(
				'slot_unavailable',
				__( 'The selected slot is no longer available. Please choose another slot.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		$slots = isset( $availability['slots'] ) && is_array( $availability['slots'] ) ? $availability['slots'] : array();
		$match = $this->find_matching_available_slot( $slots, $slot_id, $start_at, $end_at );
		if ( null === $match ) {
			return new WP_Error(
				'slot_unavailable',
				__( 'The selected slot is no longer available. Please choose another slot.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		// #655: 空き枠はキャッシュ（最大1分）を通っているため、一致した枠でも受付下限（現在時刻 + 締切）より
		// 前になっていることがある。キャッシュを通さず直接比べて、過ぎた枠での確定を防ぐ。
		if ( $this->availability_service->is_before_booking_cutoff( $menu_id, $start_at ) ) {
			return new WP_Error(
				'slot_unavailable',
				__( 'The selected slot is no longer available. Please choose another slot.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( $preferred_staff_id > 0 ) {
			$matched_staff_id = isset( $match['staff']['id'] ) ? (int) $match['staff']['id'] : 0;
			if ( 0 === $matched_staff_id || $matched_staff_id !== $preferred_staff_id ) {
				return new WP_Error(
					'staff_unavailable',
					__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		return $match;
	}

	/**
	 * Extract slot date string from ISO datetime.
	 *
	 * ISO日時から営業日の文字列を取得します。
	 *
	 * @param string $start_at Slot start datetime.
	 * @param string $timezone Timezone string (optional).
	 * @return string
	 */
	private function extract_slot_date( string $start_at, string $timezone ): string {
		try {
			$datetime = new DateTimeImmutable( $start_at );
		} catch ( Exception $e ) {
			return '';
		}

		if ( '' !== $timezone ) {
			try {
				$datetime = $datetime->setTimezone( new DateTimeZone( $timezone ) );
			} catch ( Exception $e ) {
				// Ignore invalid timezone and use original. / タイムゾーンが不正な場合は元の値を使います.
				unset( $e );
			}
		}

		return $datetime->format( 'Y-m-d' );
	}

	/**
	 * Extract timezone name from ISO datetime if possible.
	 *
	 * ISO日時からタイムゾーン名を抽出します。
	 *
	 * @param string $value ISO datetime.
	 * @return string
	 */
	private function extract_timezone_from_datetime( string $value ): string {
		try {
			$datetime = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return '';
		}

		return $datetime->getTimezone()->getName();
	}

	/**
	 * Normalize staff ID list to unique positive integers.
	 *
	 * 指名候補IDを正の整数に正規化します。
	 *
	 * @param mixed $raw_ids Raw staff IDs.
	 * @return array<int>
	 */
	private function normalize_assignable_staff_ids( $raw_ids ): array {
		if ( ! is_array( $raw_ids ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw_ids as $candidate ) {
			$candidate = (int) $candidate;
			if ( $candidate > 0 && ! in_array( $candidate, $ids, true ) ) {
				$ids[] = $candidate;
			}
		}

		return $ids;
	}

	/**
	 * Find matching slot from the availability snapshot.
	 *
	 * 空き一覧から一致する枠を取得します。
	 * 枠の ID が一致した場合も、開始・終了が時刻として一致するときだけ一致とみなします（#528）。
	 * 枠の ID が一致する枠が1件でもあれば、開始・終了だけで別の枠を探すことはしません。
	 *
	 * @param array<int, array<string, mixed>> $slots   Available slots.
	 * @param string                           $slot_id Temporary reservation data slot ID.
	 * @param string                           $start_at Temporary reservation data start datetime.
	 * @param string                           $end_at Temporary reservation data end datetime.
	 * @return array<string, mixed>|null
	 */
	private function find_matching_available_slot( array $slots, string $slot_id, string $start_at, string $end_at ): ?array {
		// 枠の ID が一致する枠を探す。ID が一致しても開始・終了が一致しなければ採用しない。
		$has_same_slot_id = false;
		foreach ( $slots as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_id = isset( $candidate['slot_id'] ) ? (string) $candidate['slot_id'] : '';
			if ( '' !== $slot_id && $candidate_id === $slot_id ) {
				$has_same_slot_id = true;
				if ( $this->is_same_slot_time( $candidate, $start_at, $end_at ) ) {
					return $candidate;
				}
			}
		}

		// 枠の ID は一致したが日時が一致しなかった場合は、一致なしとする。
		if ( $has_same_slot_id ) {
			return null;
		}

		// 枠の ID が見つからない場合は、従来どおり開始・終了が一致する枠を探す。
		foreach ( $slots as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			if ( $this->is_same_slot_time( $candidate, $start_at, $end_at ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Determine whether the candidate slot has the same start/end time as the draft.
	 *
	 * 空き枠の開始・終了が、下書きの開始・終了と時刻として一致するかを判定します。
	 * タイムゾーンの書き方の違い（+09:00 と Z など）で一致しないと判定しないよう、
	 * 文字列ではなくタイムスタンプで比べます。下書きの終了が空の場合は開始だけを比べます。
	 *
	 * @param array<string, mixed> $candidate Available slot.
	 * @param string               $start_at  Temporary reservation data start datetime.
	 * @param string               $end_at    Temporary reservation data end datetime.
	 * @return bool 一致すれば true。どちらかが日時として読めない場合は false。
	 */
	private function is_same_slot_time( array $candidate, string $start_at, string $end_at ): bool {
		$candidate_start = strtotime( isset( $candidate['start_at'] ) ? (string) $candidate['start_at'] : '' );
		$draft_start     = strtotime( $start_at );
		if ( false === $candidate_start || false === $draft_start || $candidate_start !== $draft_start ) {
			return false;
		}

		// 下書きの終了が空の場合は、従来どおり開始だけで判定する（保存には空き枠の終了を使う）。
		if ( '' === $end_at ) {
			return true;
		}

		$candidate_end = strtotime( isset( $candidate['end_at'] ) ? (string) $candidate['end_at'] : '' );
		$draft_end     = strtotime( $end_at );

		return false !== $candidate_end && false !== $draft_end && $candidate_end === $draft_end;
	}

	/**
	 * Check capacity limit with transient-based mutex to prevent race conditions.
	 *
	 * 予約確定直前に capacity 上限をチェックし、自動割当時はロック保持下で
	 * 人数を収容できる単一スタッフをベストフィットで確定します（分割しない）。
	 * transient を使った簡易排他制御で同時リクエストによる超過予約を防止します。
	 *
	 * #392: 指名を使うメニューは指名予約（1対1）・自動割当（指名なし）のいずれも1枠1組（貸切）扱いのため、
	 * 自動割当側は max_capacity が2以上でも1対1判定（既に予約があるスタッフを候補から除外）を適用する。
	 *
	 * @param int        $menu_id            Service menu ID.
	 * @param int        $staff_id           Assigned staff ID (designated booking). Auto-assign では候補からベストフィットで確定する。
	 * @param string     $start_at           Slot start (ISO8601).
	 * @param string     $end_at             Slot end (ISO8601).
	 * @param int        $guests             Number of guests for the incoming booking.
	 * @param bool       $is_staff_preferred Whether this is a designated (1:1) booking that must never share a staff member.
	 * @param array<int> $assignable_staff   自動割当時の候補スタッフID（指名予約では空配列）。
	 * @param bool       $user_exclusive     ユーザーが貸し切りを指定したか（#305）。true の場合、ロック保持下で
	 *                                       「その枠に既に他の予約があれば貸し切り不可」を再判定する。
	 * @return array{name:string,token:string,staff_id:int}|WP_Error Lock handle + 確定スタッフID。caller は release_slot_mutex() で解放する。WP_Error on failure.
	 */
	private function check_capacity_with_mutex( int $menu_id, int $staff_id, string $start_at, string $end_at, int $guests = 1, bool $is_staff_preferred = false, array $assignable_staff = array(), bool $user_exclusive = false ) {
		$max_capacity = $this->get_menu_max_capacity( $menu_id );
		$guests       = max( 1, $guests );

		// ミューテックスキーを生成。メニューIDとスロット開始時刻で一意にする。
		// Generate mutex key unique to menu ID and slot start time.
		$mutex_key = sprintf( 'vkbm_booking_mutex_%d_%s', $menu_id, md5( $start_at . '|' . $end_at ) );

		// DBレベルの排他制御: INSERT IGNORE でアトミックにロックを取得する。
		$lock = $this->acquire_mutex_lock( $mutex_key );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$option_name = $lock['name'];
		$lock_token  = $lock['token'];

		// 指名を使うメニューでは、貸切の排他を「メニュー全体」ではなく「担当スタッフ単位」で判定する
		// （#392の「担当ごとに並行して予約できる」と整合させる。指名を使わないメニューの動きは
		// 変えない＝引き続きメニュー全体で判定）。
		$is_nomination_menu = Staff_Editor::is_nomination_enabled_for_menu( $menu_id );

		// 貸し切り（枠を専有する）予約がこの時間帯に既に入っていれば、残席があっても受付停止する。
		// API直叩き対策として、ロック取得後に最新状態で再判定する（フロントのスロット一覧での抑止とは別経路の多層防御）。
		//
		// 指名を使うメニューで、担当スタッフが未確定（自動割当・$is_staff_preferred=false）の場合は、
		// この時点でスタッフスコープの判定ができない（メニュー全体で判定すると他の担当への予約まで
		// 誤って止めてしまう）ため、ここでは判定せず下の自動割当ロジックへ委ねる。自動割当は
		// has_staff_conflict()（担当スタッフ単位・貸切フラグの有無を問わずそのスタッフの予約全てを
		// 見る、より厳格な判定）で候補を絞り込むため、貸切の有無に関わらず既に予約が入っている
		// スタッフは候補から除外される。したがって自動割当の経路では本チェックを重複させる必要が無い。
		$can_check_exclusive_guard_now = ! $is_nomination_menu || ( $is_staff_preferred && $staff_id > 0 );
		if ( $can_check_exclusive_guard_now ) {
			$exclusive_guard_staff_id = $is_nomination_menu ? $staff_id : 0;
			if ( $this->slot_has_exclusive_booking_for_menu( $menu_id, $start_at, $end_at, $exclusive_guard_staff_id ) ) {
				$this->release_slot_mutex( $option_name, $lock_token );
				return new WP_Error(
					'capacity_exceeded',
					__( 'Reservations are closed for this time slot because it has been reserved exclusively.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}

			// ユーザーによる貸し切り指定（#305）: ユーザーが貸切を選んだ場合、その枠に既に他の予約があれば貸切にできない。
			// フロントでは booked_guests > 0 の枠の貸切チェックを無効化するが、API直叩きで迂回され得るため、
			// ロック保持下で最新状態を再判定する（必須の多層防御）。最初の予約者のみ貸切指定できる仕様の担保。
			// 自動割当（担当スタッフ未確定）の場合の扱いは上の $can_check_exclusive_guard_now と同じ理由で、
			// has_staff_conflict() に委ねて重複させない。
			if ( $user_exclusive && $this->slot_has_any_active_booking_for_menu( $menu_id, $start_at, $end_at, $exclusive_guard_staff_id ) ) {
				$this->release_slot_mutex( $option_name, $lock_token );
				return new WP_Error(
					'exclusive_unavailable',
					__( 'This slot cannot be reserved exclusively because it already has a booking.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		if ( $is_staff_preferred ) {
			// 指名予約（1対1）：ロック取得後にスタッフの競合を再チェックして二重割り当てを防止する。
			if ( $staff_id > 0 && $this->has_staff_conflict( $staff_id, $start_at, $end_at ) ) {
				$this->release_slot_mutex( $option_name, $lock_token );
				return new WP_Error(
					'staff_unavailable',
					__( 'The selected staff member is no longer available.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
			$assigned_staff_id = $staff_id;
		} else {
			// 自動割当：ロック保持下の最新の残席で、人数を収容できる単一スタッフをベストフィットで確定する。
			$candidates = ! empty( $assignable_staff )
				? array_values( array_map( 'intval', $assignable_staff ) )
				: ( $staff_id > 0 ? array( $staff_id ) : array() );

			// max_capacity = 1 は1スタッフ1対1。メニューをまたいだ競合（同一スタッフが別メニューで予約済み）も除外する。
			// #392: 指名を使うメニューは「指名なし」の自動割当でも1枠1組（貸切）扱いのため、max_capacity が
			// 2以上でも同じ1対1判定（既にそのスタッフへ他メニュー含め予約があれば候補から除外）を適用する。
			// この判定は貸切フラグの有無を問わず「そのスタッフに予約が1件でもあるか」を見るため、
			// 上の $can_check_exclusive_guard_now で自動割当を対象外にした貸切ガードの役割も兼ねる。
			$is_single_party = 1 === $max_capacity || $is_nomination_menu;
			if ( $is_single_party ) {
				$candidates = array_values(
					array_filter(
						$candidates,
						function ( $sid ) use ( $start_at, $end_at ): bool {
							return (int) $sid > 0 && ! $this->has_staff_conflict( (int) $sid, $start_at, $end_at );
						}
					)
				);
			}

			// #656: 各候補の残席を、空き枠の計算・管理画面の競合判定と同じ共通関数で求める
			// （別メニューの予約があるスタッフは定員に関わらず残席0）。予約の取得は1回のクエリ。
			$summaries = $this->get_staff_summaries_for_slot( $menu_id, $start_at, $end_at );
			// select_best_fit_staff() は「定員 − 負荷」で残りを数えるため、残席から逆算した負荷を渡す。
			$loads = array();
			foreach ( $candidates as $sid ) {
				$remaining           = Staff_Load_Calculator::calculate_remaining( $max_capacity, $is_single_party, $summaries[ (int) $sid ] ?? array() );
				$loads[ (int) $sid ] = $max_capacity - $remaining;
			}

			$assigned_staff_id = $this->select_best_fit_staff( $candidates, $loads, $guests, $max_capacity );
			if ( $assigned_staff_id <= 0 ) {
				$this->release_slot_mutex( $option_name, $lock_token );
				return new WP_Error(
					'capacity_exceeded',
					__( 'This time slot is fully booked. Please choose another time.', 'vk-booking-manager' ),
					array( 'status' => 409 )
				);
			}
		}

		// 成功時はロックを解放せず、ロック名・トークンと確定スタッフIDを呼び出し側へ返す。
		// 予約投稿の作成とメタ保存まで同じロックを保持し、確定直前の競合（同時挿入による超過予約）を防ぐ。
		return array(
			'name'     => $option_name,
			'token'    => $lock_token,
			'staff_id' => $assigned_staff_id,
		);
	}

	/**
	 * ミューテックスロックを取得する（options テーブルへの INSERT IGNORE によるアトミックな取得）。
	 *
	 * 期限切れ（MUTEX_TIMEOUT 秒超）のロックは compare-and-swap で奪取する。最大3回、200ms 間隔で再試行する。
	 * 取得できたロックは、呼び出し側が release_slot_mutex() で必ず解放する。
	 *
	 * @param string $mutex_key ロックのキー（transient 名。`_transient_` を付けた名前で options に保存する）。
	 * @return array{name:string,token:string}|WP_Error ロック名と所有者トークン。取得できなければ 409 のエラー。
	 */
	private function acquire_mutex_lock( string $mutex_key ) {
		global $wpdb;
		$option_name = '_transient_' . $mutex_key;
		// ロック所有者を識別する一意トークン。値は "token|timestamp" 形式で保存し、解放時に所有者を検証する。
		// 期限切れロックを別リクエストが奪取した後、遅延した旧リクエストが新所有者のロックを誤って削除するのを防ぐ。
		$lock_token    = wp_generate_password( 20, false, false );
		$lock_acquired = false;
		for ( $i = 0; $i < 3; $i++ ) {
			// options テーブルを用いたミューテックスロック。アトミックな取得・確認・上書きが
			// 必要で、キャッシュするとロックが機能しないため直接クエリを使用する。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mutex lock acquisition; caching would defeat locking.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$option_name,
					$lock_token . '|' . time()
				)
			);
			if ( 1 === $result ) {
				$lock_acquired = true;
				break;
			}

			// タイムアウトしたロックを検出して上書きする。
			// 上書きは「読み取った値と一致する場合のみ更新」する compare-and-swap で行い、
			// 同時に複数リクエストが同じ期限切れロックを奪取するのを防ぐ。
			// Detect and overwrite stale locks that exceeded MUTEX_TIMEOUT.
			// Use a compare-and-swap (WHERE option_value = the value we just read) so only one
			// concurrent request can claim the same expired lock.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading current mutex lock state; caching would defeat locking.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
			if ( null !== $existing && ( time() - $this->parse_mutex_lock_timestamp( (string) $existing ) ) > self::MUTEX_TIMEOUT ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically overwriting a stale mutex lock; caching would defeat locking.
				$updated = $wpdb->update(
					$wpdb->options,
					array( 'option_value' => $lock_token . '|' . time() ),
					array(
						'option_name'  => $option_name,
						'option_value' => $existing,
					)
				);
				if ( 1 === $updated ) {
					$lock_acquired = true;
					break;
				}
				// 別リクエストが先にロックを奪取したためリトライする。
			}

			// 短時間待機してリトライする。
			// Wait briefly and retry.
			usleep( 200000 ); // 200ms.
		}

		if ( ! $lock_acquired ) {
			return new WP_Error(
				'booking_busy',
				__( 'The system is currently processing another booking. Please try again in a few seconds.', 'vk-booking-manager' ),
				array( 'status' => 409 )
			);
		}

		return array(
			'name'  => $option_name,
			'token' => $lock_token,
		);
	}

	/**
	 * 泊の予約のために客室 ID 単位のロックを取り、ロック中に期間全体の重なりと販売日を確かめる。
	 *
	 * 時間枠のロックは「メニュー＋開始・終了の日時」単位のため、同じ客室でも別プラン・一部だけ重なる期間は別のロックになり、
	 * 同時に確定すると両方が通りうる。泊の予約は客室 ID だけでロック名を決め、どのプランからの確定も1件ずつ処理する。
	 * ロックを取得できた場合は解放せずに返す（予約の保存まで保持し、呼び出し側が release_slot_mutex() で解放する）。
	 *
	 * @param array<string, mixed> $lodging_stay Lodging_Booking::prepare() が返した検証済みの滞在の情報。
	 * @return array{name:string,token:string,staff_id:int}|WP_Error ロックの情報と客室 ID。重なり・販売日の問題があれば 409 のエラー。
	 */
	private function check_lodging_room_with_mutex( array $lodging_stay ) {
		$room_id = (int) $lodging_stay['room_id'];

		$lock = $this->acquire_mutex_lock( sprintf( 'vkbm_booking_mutex_room_%d', $room_id ) );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// ロック中に、チェックイン〜チェックアウト全体の重なりと販売日をやり直す。
		if ( $this->has_staff_conflict( $room_id, (string) $lodging_stay['check_in_at'], (string) $lodging_stay['check_out_at'] ) ) {
			$this->release_slot_mutex( $lock['name'], $lock['token'] );
			return new WP_Error(
				'staff_unavailable',
				sprintf(
					/* translators: %1$s: resource label (singular), e.g. "Room". %2$s: the same label. */
					__( 'The selected %1$s is no longer available for these dates. Please choose different dates or another %2$s.', 'vk-booking-manager' ),
					vkbm_get_resource_label_singular(),
					vkbm_get_resource_label_singular()
				),
				array( 'status' => 409 )
			);
		}

		if ( ! Lodging_Booking::is_stay_on_sale( $room_id, (string) $lodging_stay['check_in_date'], (string) $lodging_stay['check_out_date'] ) ) {
			$this->release_slot_mutex( $lock['name'], $lock['token'] );
			return Lodging_Booking::stay_not_on_sale_error();
		}

		return array(
			'name'     => $lock['name'],
			'token'    => $lock['token'],
			'staff_id' => $room_id,
		);
	}

	/**
	 * Release a slot mutex lock acquired by check_capacity_with_mutex().
	 *
	 * check_capacity_with_mutex() が取得したスロットロックを解放します。
	 * 保存されたトークンが自分のものと一致する場合のみ削除し、期限切れ奪取後に
	 * 他リクエストが取得した新しいロックを誤って削除しないようにします（ロック盗難防止）。
	 *
	 * @param string $option_name Lock option name returned by check_capacity_with_mutex().
	 * @param string $token       Owner token returned by check_capacity_with_mutex().
	 */
	private function release_slot_mutex( string $option_name, string $token ): void {
		if ( '' === $option_name || '' === $token ) {
			return;
		}

		global $wpdb;
		// 現在のロック値を読み、トークン部分が自分のものと一致する場合のみ削除する。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading mutex lock state for ownership check; caching would defeat locking.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
		if ( null === $existing ) {
			return;
		}

		$existing_token = $this->parse_mutex_lock_token( (string) $existing );
		if ( '' === $existing_token || ! hash_equals( $existing_token, $token ) ) {
			// 期限切れで別リクエストに奪取された後など、所有者が異なる場合は削除しない。
			return;
		}

		// option_value を条件に含めた1クエリの条件付き DELETE でアトミックに解放する。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mutex lock release; caching would defeat locking.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => $existing,
			)
		);

		// 直接クエリのため option キャッシュを無効化しておく。
		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Parse the timestamp portion from a mutex lock value ("token|timestamp").
	 *
	 * ミューテックスロック値（"token|timestamp"）からタイムスタンプ部分を取り出します。
	 * 後方互換のため、区切りが無い旧形式（純粋な数値）もそのまま数値として扱います。
	 *
	 * @param string $value Stored lock value.
	 * @return int
	 */
	private function parse_mutex_lock_timestamp( string $value ): int {
		$pos = strrpos( $value, '|' );
		return false === $pos ? (int) $value : (int) substr( $value, $pos + 1 );
	}

	/**
	 * Parse the owner token portion from a mutex lock value ("token|timestamp").
	 *
	 * ミューテックスロック値（"token|timestamp"）から所有者トークン部分を取り出します。
	 * 区切りが無い旧形式（トークン無し）の場合は空文字を返します。
	 *
	 * @param string $value Stored lock value.
	 * @return string
	 */
	private function parse_mutex_lock_token( string $value ): string {
		$pos = strrpos( $value, '|' );
		return false === $pos ? '' : substr( $value, 0, $pos );
	}

	/**
	 * Get the max capacity for a service menu.
	 *
	 * Availability_Service 側の公開メソッドに委譲します。
	 *
	 * @param int $menu_id Service menu ID.
	 * @return int
	 */
	private function get_menu_max_capacity( int $menu_id ): int {
		$menu_post = get_post( $menu_id );
		if ( ! $menu_post instanceof \WP_Post ) {
			return 1;
		}
		return $this->availability_service->get_menu_max_capacity( $menu_post );
	}

	/**
	 * Get the effective minimum participants to confirm for a service menu.
	 *
	 * Availability_Service 側の正規メソッドに委譲する。最大受付数が1以下のメニューでは
	 * 0（催行判定なし）を返すため、get_post_meta の直読みより default 揺れが無い（#320）。
	 *
	 * @param int $menu_id Service menu ID.
	 * @return int 実効の最小催行人数（0=制約なし）。
	 */
	private function get_menu_min_capacity( int $menu_id ): int {
		$menu_post = get_post( $menu_id );
		if ( ! $menu_post instanceof \WP_Post ) {
			return 0;
		}
		return $this->availability_service->get_menu_min_capacity( $menu_post );
	}

	/**
	 * 指名を使うメニューの、予約を受け付けるために必要な実効の最低申し込み人数を返す。
	 *
	 * Availability_Service 側の正規メソッドに委譲する（#393）。get_menu_min_capacity()（催行状態の
	 * 表示専用）とは別の取得経路で、指名を使うメニューの「最低申し込み人数」（受付制限）を返す。
	 *
	 * @param int $menu_id Service menu ID.
	 * @return int 実効の最低申し込み人数（0=制限なし）。
	 */
	private function get_menu_nomination_min_guests( int $menu_id ): int {
		$menu_post = get_post( $menu_id );
		if ( ! $menu_post instanceof \WP_Post ) {
			return 0;
		}
		return $this->availability_service->get_menu_nomination_min_guests( $menu_post );
	}

	/**
	 * メニューが「予約が入ったら貸し切りにする」設定として有効かどうかを返す。
	 *
	 * 貸し切り予約は複数人一括予約の文脈でのみ意味を持つため、メタ値だけでなくフルゲートを
	 * 再適用する（load-bearing な主防御）。Pro でなくなった／予約枠の定員機能が無効化された／
	 * メニューの複数人一括予約許可がOFFになった等で条件が崩れた場合は、保存済みメタ（stale）が
	 * 残っていても false を返し、無効な貸し切り設定が実際の受付停止として効かないようにする。
	 * 保存側（Service_Menu_Editor::save_post）はこれらのメタを削除せず保持する方針（#412 A-1）のため、
	 * ここでの再判定が唯一の防御になる。
	 *
	 * #440: 指名を使うメニューでも本設定を利用できる（指名OFFは条件ではない）。ただし排他の
	 * 判定範囲は「メニュー全体」ではなく「担当スタッフ単位」に変わっており、それは本メソッドではなく
	 * 呼び出し元 check_capacity_with_mutex() が slot_has_exclusive_booking_for_menu() へ渡す
	 * スコープ（$staff_id）で制御する。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return bool 貸し切り設定が有効なら true。
	 */
	private function is_menu_exclusive_when_booked( int $menu_id ): bool {
		$menu_post = $menu_id > 0 ? get_post( $menu_id ) : null;
		if ( ! $menu_post instanceof \WP_Post ) {
			return false;
		}

		// 判定そのもの（フルゲートの再適用）は Availability_Service に集約している（#968）。
		return $this->availability_service->is_menu_exclusive_when_booked( $menu_post );
	}

	/**
	 * メニューが「ユーザーによる貸し切り指定を受け付ける」設定として有効かどうかを返す（#305）。
	 *
	 * 貸し切り指定は複数人一括予約の文脈でのみ意味を持つため、メタ値だけでなく
	 * is_menu_exclusive_when_booked() と同じフルゲート（Pro版・予約枠の定員ON・
	 * メニューの複数人一括予約許可ON）を再適用する。条件が崩れていれば stale メタが残っていても
	 * false を返し、無効なユーザー貸し切り指定が実際に効かないようにする（save 側の delete と多層防御）。
	 *
	 * #440: 指名を使うメニューでも本設定を利用できる（指名OFFは条件ではない）。「最初の予約者のみ
	 * 貸切にできる」判定の範囲も、指名を使うメニューでは「メニュー全体」ではなく「担当スタッフ単位」に
	 * 変わっており、それは本メソッドではなく呼び出し元 check_capacity_with_mutex() が
	 * slot_has_any_active_booking_for_menu() へ渡すスコープ（$staff_id）で制御する。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return bool ユーザー貸し切り指定が有効なら true。
	 */
	private function is_user_exclusive_selectable( int $menu_id ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		// 「貸し切り予約」ONのメニューでは、予約者の指定に関わらず既に枠全体が貸切扱いになるため、
		// ユーザーによる貸し切り指定は受け付けない（#388）。両方ONで保存されると、指定した予約者だけ
		// 貸し切り料金を負担する不整合（払った人が損をする）が起きるため排他にする。
		// 下書きコントローラ（Booking_Draft_Controller::is_user_exclusive_selectable）と同型の判定。
		if ( get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_WHEN_BOOKED, true ) ) {
			return false;
		}

		// メタが立っていなければ対象外。
		if ( ! get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_USER_SELECTABLE, true ) ) {
			return false;
		}

		// フルゲート（Pro版・予約枠の定員ON）を再適用する（#391: メニュー単位の判定へ置き換え）。
		// #412 C-3: 判定を Staff_Editor::is_multi_guest_available_for_menu() へ集約していたが、#392 で
		// そのメソッドから「指名OFF」条件を外したため、貸切系の3設定（本メソッドが担う「予約者による
		// 貸切指定」もその1つ）は専用の Staff_Editor::is_exclusive_booking_available_for_menu() へ
		// 切り替えた。そちらのメソッドからも「指名OFF」条件を外したため、指名を使うメニューでもこの
		// 判定を通る（排他の判定範囲は上記のとおり呼び出し元でスタッフ単位に絞る）。
		if ( ! Staff_Editor::is_exclusive_booking_available_for_menu( $menu_id ) ) {
			return false;
		}

		// メニュー単位の複数人一括予約許可（_vkbm_allow_multiple_guests）も必須。
		if ( ! get_post_meta( $menu_id, self::MENU_META_ALLOW_GUESTS, true ) ) {
			return false;
		}

		// 実効の最大予約受付数が1以下なら貸し切り指定は意味を持たないため無効化する（#320）。
		// 表示制御（管理画面の hidden）だけに頼らず、保存済みメタが残っていても実効0扱いにする。
		if ( $this->get_menu_max_capacity( $menu_id ) <= 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * メニュー設定とユーザー選択に基づき、貸し切り料金を権威的に再計算する（#305）。
	 *
	 * 単価・適用外人数は必ずサーバ保存メタを正とする（フロント送信額は信用しない）。
	 * ゲート（is_user_exclusive_selectable）を満たさないメニューでは 0 を返す。
	 * 実際の計算式は Exclusive_Fee::calculate() に委譲する（下書きコントローラ・テストと同一ロジック）。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @param int $guests  申込人数。
	 * @return int 貸し切り料金（0 以上）。
	 */
	private function calculate_exclusive_fee( int $menu_id, int $guests ): int {
		if ( ! $this->is_user_exclusive_selectable( $menu_id ) ) {
			return 0;
		}

		$per_person    = max( 0, (int) get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_FEE_PER_PERSON, true ) );
		$exempt_guests = max( 0, (int) get_post_meta( $menu_id, self::MENU_META_EXCLUSIVE_FEE_EXEMPT, true ) );

		return Exclusive_Fee::calculate( true, $per_person, $exempt_guests, $guests );
	}

	/**
	 * 指定スロットに、ステータスが有効な予約（貸し切りか否かを問わない）が既に1件以上あるかを判定する（#305）。
	 *
	 * ユーザー貸し切り指定時に「最初の予約者のみ貸切にできる（既に予約があれば貸切不可）」を
	 * ロック保持下で担保するための判定。slot_has_exclusive_booking_for_menu() が
	 * 貸し切りフラグの有無で絞るのに対し、こちらは枠を消費する有効な予約があれば貸し切りフラグの
	 * 有無に関わらず true を返す。cancelled / no_show は枠を消費しないため除外する（既存の空き判定と同方針）。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @param int    $staff_id 0より大きい場合、この担当スタッフの予約のみを対象にスコープする
	 *                          （指名を使うメニュー用）。0（既定）はメニュー全体を対象にする。
	 * @return bool 有効な予約が重複していれば true。
	 */
	private function slot_has_any_active_booking_for_menu( int $menu_id, string $start_at, string $end_at, int $staff_id = 0 ): bool {
		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return false;
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_SERVICE_ID,
				'value'   => $menu_id,
				'compare' => '=',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => Booking_Overlap::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Booking_Overlap::META_STATUS,
					'value'   => array(
						self::BOOKING_STATUS_CANCELLED,
						self::BOOKING_STATUS_NO_SHOW,
					),
					'compare' => 'NOT IN',
				),
			),
			array(
				'key'     => Booking_Overlap::META_START,
				'value'   => $end_for_storage,
				'compare' => '<',
				'type'    => 'DATETIME',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => Booking_Overlap::META_TOTAL_END,
					'value'   => $start_for_storage,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => Booking_Overlap::META_END,
					'value'   => $start_for_storage,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			),
		);

		// 指名を使うメニューでは担当スタッフ単位で「最初の予約者のみ貸切にできる」を判定する。
		if ( $staff_id > 0 ) {
			$meta_query[] = array(
				'key'     => Booking_Overlap::META_RESOURCE,
				'value'   => $staff_id,
				'compare' => '=',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				// 有効な予約が1件でもあれば判定は確定するため、cancelled/no_show を除いた上で
				// 取得件数を絞らず status を個別確認する（おとり対策）。最大10件まで見れば十分。
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, Booking_Overlap::META_STATUS, true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため除外する。
			if ( self::BOOKING_STATUS_CANCELLED === $status || self::BOOKING_STATUS_NO_SHOW === $status ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * 指定スロットに、枠を専有する貸し切り予約が既に1件以上あるかを判定する。
	 *
	 * 確定処理のロック保持下で最新状態を再判定するための多重防御。
	 * 既存の空き判定と同じく、publish かつ status が cancelled/no_show 以外の予約のみを対象とし、
	 * その中で _vkbm_booking_exclusive が立っている予約がスロットに重複していれば true を返す。
	 *
	 * `$staff_id` を渡すと「メニューID＋時間帯」に加えて Booking_Overlap::META_RESOURCE
	 * （`_vkbm_booking_resource_id`）も一致する予約だけを対象にする（スタッフ単位の判定）。
	 * 省略（0）した場合は従来どおりメニュー全体で判定する。呼び出し元
	 * （check_capacity_with_mutex()）が、指名を使うメニューのときだけ担当スタッフIDを渡す。
	 * 指名を使わないメニューの動きは変えない。
	 *
	 * @param int    $menu_id  サービスメニューID。
	 * @param string $start_at スロット開始（ISO8601）。
	 * @param string $end_at   スロット終了（ISO8601）。
	 * @param int    $staff_id 0より大きい場合、この担当スタッフの予約のみを対象にスコープする
	 *                          （指名を使うメニュー用）。0（既定）はメニュー全体を対象にする。
	 * @return bool 貸し切り予約が重複していれば true。
	 */
	private function slot_has_exclusive_booking_for_menu( int $menu_id, string $start_at, string $end_at, int $staff_id = 0 ): bool {
		$start_for_storage = $this->format_datetime_for_storage( $start_at );
		$end_for_storage   = $this->format_datetime_for_storage( $end_at );
		if ( '' === $start_for_storage ) {
			return false;
		}
		if ( '' === $end_for_storage ) {
			$end_for_storage = $start_for_storage;
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => self::META_SERVICE_ID,
				'value'   => $menu_id,
				'compare' => '=',
			),
			// 貸し切りフラグが立っている予約のみを対象にする。
			array(
				'key'     => self::META_EXCLUSIVE,
				'value'   => '1',
				'compare' => '=',
			),
			// キャンセル・無断キャンセルの予約は枠を消費しないためクエリ段階で除外する。
			// posts_per_page=1 で取得を1件に絞っても、対象外ステータスの予約が
			// 先頭に来て有効な貸し切り予約を見落とすことがないようにする。
			array(
				'relation' => 'OR',
				array(
					'key'     => Booking_Overlap::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Booking_Overlap::META_STATUS,
					'value'   => array(
						self::BOOKING_STATUS_CANCELLED,
						self::BOOKING_STATUS_NO_SHOW,
					),
					'compare' => 'NOT IN',
				),
			),
			array(
				'key'     => Booking_Overlap::META_START,
				'value'   => $end_for_storage,
				'compare' => '<',
				'type'    => 'DATETIME',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => Booking_Overlap::META_TOTAL_END,
					'value'   => $start_for_storage,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => Booking_Overlap::META_END,
					'value'   => $start_for_storage,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			),
		);

		// 指名を使うメニューでは担当スタッフ単位で貸切を判定する。
		if ( $staff_id > 0 ) {
			$meta_query[] = array(
				'key'     => Booking_Overlap::META_RESOURCE,
				'value'   => $staff_id,
				'compare' => '=',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => Booking_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish' ),
				// 1件でもヒットすれば貸し切り判定は確定するため取得は1件に絞る（軽微な最適化）。
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, Booking_Overlap::META_STATUS, true );
			// キャンセル・無断キャンセルの予約は枠を消費しないため除外する（既存の空き判定と同じ方針）。
			if ( self::BOOKING_STATUS_CANCELLED === $status || self::BOOKING_STATUS_NO_SHOW === $status ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * メニューを担当できるスタッフ数を数える。
	 *
	 * #485: 「すべてのリソースが担当できる」フラグのメニューは公開中の全リソース数になる。
	 * 候補の解決（重複排除・0以下の除外を含む）は Service_Menu_Post_Type::get_assignable_staff_ids() に集約。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return int スタッフ数。
	 */
	private function count_menu_staff( int $menu_id ): int {
		return count( Service_Menu_Post_Type::get_assignable_staff_ids( $menu_id ) );
	}

	/**
	 * メニューに有効な料金区分が定義されている場合に、その正規化済み区分を返す。
	 *
	 * 料金区分は複数人一括予約が適用される場面（Pro版・予約枠の定員機能ON・複数人一括予約許可・
	 * スタッフ割当あり）でのみ意味を持つ。resolve_guests と同じゲートを通し、適用外なら空配列を
	 * 返す（区分未定義として従来計算に倒す）。
	 *
	 * #392: 以前はこのゲートに「指名OFF」も含めていたが、指名を使うメニューでも料金区分を
	 * 利用できるようにするため外した（Staff_Editor::is_multi_guest_available_for_menu() 側の変更）。
	 *
	 * @param int $menu_id サービスメニューID。
	 * @return array<int, array{label: string, price: int}>
	 */
	private function resolve_menu_price_tiers( int $menu_id ): array {
		// 複数人一括予約のゲート（Pro版・予約枠の定員機能ON・許可フラグON・スタッフ割当あり）を満たさない場合は区分を使わない。
		// #412 C-3: 判定を Staff_Editor::is_multi_guest_available_for_menu() へ集約。
		// #392: 上記メソッドから「指名OFF」条件を外したため、指名を使うメニューでもこの判定を通る。
		// $menu_id <= 0 の早期リターンは元の条件をそのまま維持する（メニュー文脈が無い呼び出しを弾く）。
		if ( $menu_id <= 0 || ! Staff_Editor::is_multi_guest_available_for_menu( $menu_id ) ) {
			return array();
		}

		// 1室1泊あたりのプランは許可フラグが未保存・OFF でも ON として扱う（#831）。
		if ( ! Service_Menu_Post_Type::is_multi_guest_booking_enabled( $menu_id ) || $this->count_menu_staff( $menu_id ) < 1 ) {
			return array();
		}

		// 実効の最大予約受付数が1以下なら料金区分は意味を持たないため空配列扱いにする（#320）。
		// 区分未定義として基本料金×人数へフォールバックさせる（保存済みメタは破壊しない）。
		if ( $this->get_menu_max_capacity( $menu_id ) <= 1 ) {
			return array();
		}

		return Price_Tiers::normalize_tiers( get_post_meta( $menu_id, self::MENU_META_PRICE_TIERS, true ) );
	}

	/**
	 * Resolve the number of guests for a booking against the menu's multi-guest settings.
	 *
	 * メニューの複数人一括予約設定に基づいて予約人数を確定する。
	 * 複数人一括予約が無効な場合は常に1名を返す。有効な場合は 1〜最大人数の範囲にクランプする。
	 *
	 * #392: 指名を使うメニューでも、複数人一括予約が許可されていれば（予約枠の定員＝1組の最大人数までの）
	 * 人数を受け付ける。指名を使うメニューの1件の予約は必ず単一スタッフを占有する（1枠1組）ため、
	 * 「1予約は分割せず単一スタッフに割り当てる」という上限の考え方自体は指名の有無で変わらない。
	 *
	 * NOTE: 同等のロジックを Booking_Draft_Controller::resolve_guests にも意図的に複製している。
	 * 下書きと確定の2つの REST コントローラは独立しており、共有トレイト化すると両者を不要に結合させるため、
	 * あえて各クラスに閉じた実装としている。仕様変更時は必ず両方を同じ式（上限 = max_capacity）で更新すること。
	 * 泊単位のプラン向けの Lodging_Booking::get_max_guests()（3か所目。複数人一括予約の適用条件と上限の式は同じ）も併せて更新すること。
	 *
	 * @param int $menu_id   Service menu ID.
	 * @param int $requested Requested number of guests.
	 * @return int
	 */
	private function resolve_guests( int $menu_id, int $requested ): int {
		// 複数人一括予約は Pro 版 かつ 予約枠の定員機能ONのときのみ有効。
		// Pro から無料版へダウングレードしてもメタが残る可能性があるため、確定時に Pro 版を再確認する。
		// #412 C-3: 判定を Staff_Editor::is_multi_guest_available_for_menu() へ集約。
		// #392: 上記メソッドから「指名OFF」条件を外したため、指名を使うメニューでもこの判定を通る。
		// $menu_id <= 0 の早期リターンは元の条件をそのまま維持する（メニュー文脈が無い呼び出しを弾く）。
		if ( $menu_id <= 0 || ! Staff_Editor::is_multi_guest_available_for_menu( $menu_id ) ) {
			return 1;
		}

		// 1室1泊あたりのプランは許可フラグが未保存・OFF でも ON として扱う（#831）。
		if ( ! Service_Menu_Post_Type::is_multi_guest_booking_enabled( $menu_id ) ) {
			return 1;
		}

		// 1予約は分割せず単一スタッフに割り当てるため、上限は max_capacity（1スタッフの上限）。
		// スタッフ未割当のメニューは割り当て先が無いため複数人一括予約を許可しない（常に1名）。
		// 実際のスロット別の空きは確定時（check_capacity_with_mutex のベストフィット判定）で厳密に判定する。
		$max_raw      = get_post_meta( $menu_id, self::MENU_META_MAX_CAPACITY, true );
		$max_capacity = '' === $max_raw ? 1 : max( 1, (int) $max_raw );
		$staff_count  = $this->count_menu_staff( $menu_id );

		if ( $staff_count < 1 ) {
			return 1;
		}

		return max( 1, min( $max_capacity, $requested ) );
	}
}
