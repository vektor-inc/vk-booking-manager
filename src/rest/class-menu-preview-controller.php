<?php

declare( strict_types=1 );

namespace VKBookingManager\REST;

use VKBookingManager\Blocks\Menu_Loop_Block;
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
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/menu-preview/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_menu_preview' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'description'       => __( 'Service menu post ID', 'vk-booking-manager' ),
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/menu-loop',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_menu_loop' ],
				'permission_callback' => '__return_true',
			]
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
			[
				'showDetailButton'  => false,
				'showReserveButton' => false,
			]
		);

		if ( '' === $html ) {
			return new WP_Error(
				'vkbm_menu_not_found',
				__( 'The specified menu was not found.', 'vk-booking-manager' ),
				[
					'status' => 404,
				]
			);
		}

		return new WP_REST_Response(
			[
				'html' => $html,
			]
		);
	}

	/**
	 * Return rendered menu loop markup for the reservation page.
	 *
	 * @return WP_REST_Response
	 */
	public function get_menu_loop(): WP_REST_Response {
		$html = $this->menu_loop_block->render_menu_selection_list();

		return new WP_REST_Response(
			[
				'html' => $html,
			]
		);
	}
}
