<?php
/**
 * REST controller for availability data.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Availability\Availability_Service;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\Resource_Tag_Id_List;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;
use function current_user_can;
use function is_array;

/**
 * REST controller for availability endpoints.
 */
class Availability_Controller {
	private const NAMESPACE = 'vkbm/v1';

	/**
	 * Availability service.
	 *
	 * @var Availability_Service
	 */
	private Availability_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Availability_Service $service Availability service.
	 */
	public function __construct( Availability_Service $service ) {
		$this->service = $service;
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
		// Publicly readable: the availability calendar must be visible to anonymous
		// visitors before they log in to make a reservation.
		// 公開情報のため誰でも参照可能。予約フォーム表示前の未ログインユーザーにも空き状況を返す必要がある。
		register_rest_route(
			self::NAMESPACE,
			'/calendar-meta',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_calendar_meta' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_calendar_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_daily_slots' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_daily_args(),
			)
		);
	}

	/**
	 * Handle calendar meta request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_calendar_meta( WP_REST_Request $request ) {
		$args = array(
			'menu_id'          => (int) $request['menu_id'],
			'resource_id'      => isset( $request['resource_id'] ) ? (int) $request['resource_id'] : null,
			'year'             => (int) $request['year'],
			'month'            => (int) $request['month'],
			'timezone'         => (string) $request['timezone'],
			// #431: リソースタグ絞り込み（ターム ID配列・AND条件）。
			'resource_tag_ids' => $this->sanitize_tag_ids( $request['resource_tag_ids'] ?? array() ),
		);

		$data = $this->service->get_calendar_meta( $args );

		$can_diagnose = current_user_can( Capabilities::MANAGE_SYSTEM_SETTINGS );

		if ( is_wp_error( $data ) ) {
			// #411 麗美さん確認（PR #414 差し戻し）: get_calendar_meta() が validate_menu() /
			// resolve_staff_ids() の時点で WP_Error を返して早期returnすると、下の成功時パスの
			// 診断処理（get_unavailability_reason）に到達できず、担当スタッフ0件などで
			// 完了条件1の診断バナーがまったく出ない配線漏れがあった。
			// エラーコードごとに分岐せず、この経路でも同じ get_unavailability_reason() を必ず
			// 呼ぶことで、validate_menu() 由来（メニュー非公開等）・resolve_staff_ids() 由来
			// （担当スタッフ0件・指名不一致）のどちらの早期returnでも取りこぼさない。
			// get_unavailability_reason() 自身が年月・投稿の妥当性を再検証して該当しなければ
			// null を返すため、ここでは呼び出すだけでよい。
			$reason = null;
			if ( $can_diagnose ) {
				$reason = $this->service->get_unavailability_reason( $args );
			}

			$masked = $this->mask_error_for_public_visitors( $data );

			// #411 安藤さんレビュー指摘（LOW・PR #414 再差し戻し）: 診断理由の付与は
			// mask_error_for_public_visitors() の**後**に行う。以前は先に $data へ付与してから
			// マスク処理へ渡していたため、「マスク側が権限の無いユーザー向けには必ず
			// 新しい WP_Error を作り直して返す（付随データを丸ごと置き換える）」という
			// mask_error_for_public_visitors() の実装詳細に安全性が依存していた。将来そちらが
			// 付随データをマージする実装に変わると、その瞬間に非該当者へ内部理由が漏れる。
			// ここでは「マスクされずに元のオブジェクトのまま返ってきたか」を同一性（===）で
			// 判定してから付与するため、mask_error_for_public_visitors() の実装に依存しない。
			// 非該当者には必ず新しい WP_Error インスタンスが返る（$masked !== $data）ため、
			// このブロックは権限のあるユーザーにしか実行されない。
			if ( null !== $reason && $masked === $data ) {
				// get_error_data() はデータ未設定時 null を返す（(array) キャストすると
				// array(0 => null) になってしまうため、明示的に空配列へフォールバックする）。
				$existing_data = $masked->get_error_data();
				$existing_data = is_array( $existing_data ) ? $existing_data : array();

				$masked->add_data(
					array_merge( $existing_data, array( 'unavailability_reason' => $reason ) )
				);
			}

			return $masked;
		}

		// #411: 表示中の月に予約可能日が1件も無い場合の診断理由。管理者・サイトオーナー・
		// サロンオーナー（vkbm_manage_system_settings）にのみレスポンスへ含める。
		// 非該当者にはフィールド自体を出力しない（CSSで隠す・フロント側だけの分岐は不可）。
		//
		// 診断処理（get_unavailability_reason）は日数×スタッフ数ぶんの WP_Query を発行しうるため、
		// 予約可能日が1件でもある通常時は絶対に呼ばない（安藤さんレビュー指摘）。
		// get_calendar_meta() がキャッシュ（transient）から返った場合、Availability_Service の
		// インスタンス内 booking_cache は空になるため、門番を置かないと診断ループのたびに
		// 同じクエリを再発行してしまう。
		if ( $can_diagnose && ! $this->has_bookable_day( $data ) ) {
			$reason = $this->service->get_unavailability_reason( $args );
			if ( null !== $reason ) {
				$data['unavailability_reason'] = $reason;
			}
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * カレンダーレスポンスに、選択可能な日（is_disabled === false）が1件でも含まれるかを判定する。
	 *
	 * #411: 診断処理（get_unavailability_reason）は「予約可能日が1件も無いとき」だけに
	 * 呼び出す門番として使う。1件でも予約可能日があれば、その時点で false を返し診断をスキップする。
	 *
	 * @param array<string, mixed> $data get_calendar_meta() が返した正常時のレスポンス配列。
	 * @return bool 予約可能日が1件でもあれば true。
	 */
	private function has_bookable_day( array $data ): bool {
		foreach ( (array) ( $data['days'] ?? array() ) as $day ) {
			if ( empty( $day['is_disabled'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 公開エンドポイントが返す WP_Error を、権限に応じてマスクする。
	 *
	 * `/calendar-meta` `/availabilities` は permission_callback => '__return_true' の公開APIのため、
	 * 「担当スタッフが未設定」等の内部設定を示すエラーメッセージをそのまま一般訪問者へ返すと、
	 * 管理者だけに見せたい情報が漏えいする（#411で確認された既存の不具合）。
	 * vkbm_manage_system_settings を持つユーザーには従来通り詳細なメッセージを返し、
	 * それ以外には識別子（error code）・メッセージ・data のすべてを固定の汎用値へ置換する。
	 * 元のエラーコード・data をそのまま返すと、メッセージだけ差し替えても識別子から
	 * 内部設定が伝わってしまうため（安藤さんレビュー指摘）、素通しにしない。
	 *
	 * @param WP_Error $error 元のエラー。
	 * @return WP_Error 権限に応じたエラー。権限のあるユーザーには引数 $error をそのまま返す
	 *                   （呼び出し側が `$masked === $error` の同一性でマスクの有無を判定しているため、
	 *                   ここで clone や別インスタンスを返さないこと）。
	 */
	private function mask_error_for_public_visitors( WP_Error $error ): WP_Error {
		if ( current_user_can( Capabilities::MANAGE_SYSTEM_SETTINGS ) ) {
			return $error;
		}

		// #431: resource_tag_no_match（選択したタグに合う担当がいない）は
		// 内部設定を示す情報ではなく、訪問者自身が選んだ絞り込み条件に起因するものなので、
		// 他のエラーと違って汎用文言へ置き換えず、意味の伝わるメッセージのまま返す。
		// ただし新しいインスタンスとして返す点は他の分岐と揃える。呼び出し側は
		// 「$masked === $data の同一性」で「マスクされていない＝admin専用の診断情報を
		// 付与してよい」を判定しているため、ここで元の $error をそのまま返す（同一性を保つ）と、
		// admin専用の unavailability_reason が非該当者のレスポンスにまで付与されてしまう。
		if ( 'resource_tag_no_match' === $error->get_error_code() ) {
			return new WP_Error(
				'resource_tag_no_match',
				$error->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// 対処方法を別の文として案内する（1翻訳関数につき1文に分ける）。結合は
		// join_sentences()（#411 植草さんレビュー指摘: ハードコードの半角スペースで連結すると、
		// 句点で終わる日本語文のあいだに不要な隙間ができるため、文末が非ASCIIかどうかで
		// 区切り方を切り替える。この文言は一般訪問者全員が目にするため、診断バナー側と
		// 同じロジック（src/common/class-nomination-min-guests-message.php の
		// join_sentences()）を再利用して揃える）。
		$message = Nomination_Min_Guests_Message::join_sentences(
			__( 'We are not currently accepting reservations for this content.', 'vk-booking-manager' ),
			__( 'Please choose a different menu, or contact the site administrator for assistance.', 'vk-booking-manager' )
		);

		return new WP_Error(
			'reservation_unavailable',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * Handle daily slot request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_daily_slots( WP_REST_Request $request ) {
		$data = $this->service->get_daily_slots(
			array(
				'menu_id'          => (int) $request['menu_id'],
				'resource_id'      => isset( $request['resource_id'] ) ? (int) $request['resource_id'] : null,
				'date'             => (string) $request['date'],
				'timezone'         => (string) $request['timezone'],
				// #431: リソースタグ絞り込み（ターム ID配列・AND条件）。
				'resource_tag_ids' => $this->sanitize_tag_ids( $request['resource_tag_ids'] ?? array() ),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $this->mask_error_for_public_visitors( $data );
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Arguments for calendar endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_calendar_args(): array {
		return array(
			'menu_id'          => array(
				'required'          => true,
				'type'              => 'integer',
				'description'       => __( 'Service menu post ID', 'vk-booking-manager' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'resource_id'      => array(
				'required'          => false,
				'type'              => 'integer',
				'description'       => __( 'Nominated resource ID (optional)', 'vk-booking-manager' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'year'             => array(
				'required'    => true,
				'type'        => 'integer',
				'description' => __( 'Target year (YYYY)', 'vk-booking-manager' ),
				'minimum'     => 2000,
				'maximum'     => 2100,
			),
			'month'            => array(
				'required'    => true,
				'type'        => 'integer',
				'description' => __( 'Target month (1-12)', 'vk-booking-manager' ),
				'minimum'     => 1,
				'maximum'     => 12,
			),
			'timezone'         => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'Time zone name (Site setting if not specified)', 'vk-booking-manager' ),
				'default'     => '',
			),
			// #431: リソースタグでの絞り込み（ターム ID配列）。指定した全タグを持つリソースのみが候補になる。
			// 上限件数は Resource_Tag_Id_List::MAX_COUNT と一致させ、
			// スキーマ側の上限（maxItems）とサニタイズ側の上限を1つの定数に揃える。
			'resource_tag_ids' => array(
				'required'    => false,
				'type'        => 'array',
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'maxItems'    => Resource_Tag_Id_List::MAX_COUNT,
				'default'     => array(),
				'description' => __( 'Resource tag term IDs to filter by (AND condition).', 'vk-booking-manager' ),
			),
		);
	}

	/**
	 * リソースタグ ID の配列引数を正規化する（#431）。
	 *
	 * 正規化ルールは Resource_Tag_Id_List::normalize() に一元化している
	 * （以前は6箇所で個別に実装しており、`absint()` を使う実装だけ
	 * 負値が絶対値化されて有効なIDとして紛れ込む挙動の食い違いがあった）。
	 *
	 * @param mixed $raw_tag_ids リクエストから渡された生の値。
	 * @return array<int>
	 */
	private function sanitize_tag_ids( $raw_tag_ids ): array {
		return Resource_Tag_Id_List::normalize( $raw_tag_ids );
	}

	/**
	 * Arguments for daily endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_daily_args(): array {
		return array(
			'menu_id'          => $this->get_calendar_args()['menu_id'],
			'resource_id'      => $this->get_calendar_args()['resource_id'],
			'date'             => array(
				'required'    => true,
				'type'        => 'string',
				'description' => __( 'Target date (YYYY-MM-DD)', 'vk-booking-manager' ),
				'pattern'     => '^\d{4}-\d{2}-\d{2}$',
			),
			'timezone'         => $this->get_calendar_args()['timezone'],
			'resource_tag_ids' => $this->get_calendar_args()['resource_tag_ids'],
		);
	}
}
