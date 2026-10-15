<?php
/**
 * REST controller for menu preview.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Availability\Menu_Date_Filter;
use VKBookingManager\Availability\Night_Strategy;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Common\Resource_Tag_Id_List;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;

/**
 * REST controller that returns rendered service menu previews.
 */
class Menu_Preview_Controller {
	private const NAMESPACE = 'vkbm/v1';

	/**
	 * Menu loop block renderer.
	 *
	 * @var Menu_Loop_Block
	 */
	private Menu_Loop_Block $menu_loop_block;

	/**
	 * Constructor.
	 *
	 * @param Menu_Loop_Block $menu_loop_block Menu loop renderer.
	 */
	public function __construct( Menu_Loop_Block $menu_loop_block ) {
		$this->menu_loop_block = $menu_loop_block;
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
		// Publicly readable: rendered service menu markup is intended to be displayed
		// in the public reservation flow, so anonymous access is required.
		// 公開情報のため誰でも参照可能。予約フォームのメニュー一覧描画に使われる。
		register_rest_route(
			self::NAMESPACE,
			'/menu-preview/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_menu_preview' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'description'       => __( 'Service menu post ID', 'vk-booking-manager' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/menu-loop',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_menu_loop' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					// #429: 絞り込み検索でスタッフが選択されているときに、そのスタッフが
					// 対応できるメニューだけへ一覧を絞り込むための任意パラメータ。
					// 0（既定値）または未指定は絞り込みなし（指名なし）を意味する。
					'staff'            => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'description'       => __( 'Staff member post ID to filter the returned service menus by. 0 means no filtering.', 'vk-booking-manager' ),
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					// #431: リソースタグ絞り込み（ターム ID配列・AND条件）。空配列（既定値）は絞り込みなし。
					// パラメーター名は他エンドポイント（calendar-meta / availabilities）と
					// 揃えて resource_tag_ids に統一する（未リリースのため互換対応は不要）。
					'resource_tag_ids' => array(
						'required'    => false,
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems'    => Resource_Tag_Id_List::MAX_COUNT,
						'default'     => array(),
						'description' => __( 'Resource tag term IDs to filter the returned service menus by (AND condition).', 'vk-booking-manager' ),
					),
					// 絞り込み検索の日付指定（Y-m-d）。空文字（既定値）は日付で絞り込まない。
					// 形式が不正な値、実在しない日付は 400 で拒否する。
					'date'             => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'description'       => __( 'Date (Y-m-d) to filter the returned service menus by. Only menus that can be booked on this date are returned. An empty value means no filtering.', 'vk-booking-manager' ),
						'validate_callback' => array( __CLASS__, 'validate_menu_loop_date' ),
					),
					// 宿泊のサイトの絞り込み検索で選ぶチェックアウト日（Y-m-d。date はチェックイン日）。
					// 宿泊のサイト以外では無視する。形式が不正な値、実在しない日付は 400 で拒否する。
					'check_out_date'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'description'       => __( 'Check-out date (Y-m-d) used with date (the check-in date) on lodging sites. Only plans that can be booked for the whole stay are returned. Ignored on other sites.', 'vk-booking-manager' ),
						'validate_callback' => array( __CLASS__, 'validate_menu_loop_date' ),
					),
					// true のとき一覧の HTML を作らず、表示するメニューの ID だけを返す。
					// 「サービスメニュー一覧」を表示しないサイトで、プルダウンだけを日付で絞るために使う。
					'ids_only'         => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When true, only the IDs of the service menus to display are returned and the list HTML is not rendered.', 'vk-booking-manager' ),
					),
				),
			)
		);
	}

	/**
	 * Return rendered menu preview markup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_menu_preview( WP_REST_Request $request ) {
		$menu_id = (int) $request['id'];

		if ( $menu_id <= 0 ) {
			return new WP_Error(
				'vkbm_invalid_menu',
				__( 'Menu ID is invalid.', 'vk-booking-manager' )
			);
		}

		$html = $this->menu_loop_block->render_menu_card(
			$menu_id,
			array(
				'showDetailButton'  => false,
				'showReserveButton' => false,
			)
		);

		if ( '' === $html ) {
			return new WP_Error(
				'vkbm_menu_not_found',
				__( 'The specified menu was not found.', 'vk-booking-manager' ),
				array(
					'status' => 404,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'html' => $html,
			)
		);
	}

	/**
	 * menu-loop の date パラメーターが、空文字または実在する Y-m-d かを検証する。
	 *
	 * @param mixed $value 受け取った値。
	 * @return bool 空文字または実在する Y-m-d なら true。
	 */
	public static function validate_menu_loop_date( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return '' === $value || Menu_Date_Filter::is_valid_date( $value );
	}

	/**
	 * Return rendered menu loop markup for the reservation page.
	 *
	 * @param WP_REST_Request $request リクエスト（staff / resource_tag_ids / date / check_out_date / ids_only パラメータを含む）。
	 * @return WP_REST_Response|WP_Error html（一覧の HTML。ids_only のときは空文字）と menu_ids（表示するメニューの ID）を返す。宿泊のサイトで日程に矛盾があるときは 400 のエラー。
	 */
	public function get_menu_loop( WP_REST_Request $request ) {
		// #429: 絞り込み検索で選択されているスタッフID（0は指名なし＝絞り込みなし）。
		$staff_id = (int) $request->get_param( 'staff' );

		// #431: 絞り込み検索で選択されているリソースタグのターム ID配列（AND条件）。
		// 正規化ルールは Resource_Tag_Id_List::normalize() に一元化している（同じ処理を複数箇所へ重複させないため）。
		$tag_ids = Resource_Tag_Id_List::normalize( $request->get_param( 'resource_tag_ids' ) );

		// 絞り込み検索で選択されている日付（空文字は日付で絞り込まない）。
		$date = (string) $request->get_param( 'date' );

		// 宿泊のサイトのチェックアウト日。日程に矛盾（チェックアウト日がチェックイン日以前・泊数が上限超え）があれば 400 で拒否する。
		$check_out_date = (string) $request->get_param( 'check_out_date' );
		if ( '' !== $date && '' !== $check_out_date && Menu_Date_Filter::is_stay_mode() ) {
			$range_error = Night_Strategy::validate_stay_range( $date, $check_out_date );
			if ( null !== $range_error ) {
				return $range_error;
			}
		}

		// 一覧とプルダウンで同じ絞り込み結果を使うため、HTML と表示するメニューの ID を同じ絞り込みから求める。
		$list = $this->menu_loop_block->get_selection_list( $staff_id, $tag_ids, $date, ! (bool) $request->get_param( 'ids_only' ), $check_out_date );

		return new WP_REST_Response(
			array(
				'html'     => $list['html'],
				'menu_ids' => $list['menu_ids'],
			)
		);
	}
}
