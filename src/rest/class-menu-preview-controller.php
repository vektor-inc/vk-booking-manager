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
	 * Return rendered menu loop markup for the reservation page.
	 *
	 * @param WP_REST_Request $request リクエスト（staff / resource_tag_ids パラメータを含む）。
	 * @return WP_REST_Response
	 */
	public function get_menu_loop( WP_REST_Request $request ): WP_REST_Response {
		// #429: 絞り込み検索で選択されているスタッフID（0は指名なし＝絞り込みなし）。
		$staff_id = (int) $request->get_param( 'staff' );

		// #431: 絞り込み検索で選択されているリソースタグのターム ID配列（AND条件）。
		// 正規化ルールは Resource_Tag_Id_List::normalize() に一元化している（同じ処理を複数箇所へ重複させないため）。
		$tag_ids = Resource_Tag_Id_List::normalize( $request->get_param( 'resource_tag_ids' ) );

		$html = $this->menu_loop_block->render_menu_selection_list( $staff_id, $tag_ids );

		return new WP_REST_Response(
			array(
				'html' => $html,
			)
		);
	}
}
