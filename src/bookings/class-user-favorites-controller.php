<?php
/**
 * ログインユーザーのお気に入り（メニュー＋スタッフの組み合わせ）を管理する REST コントローラ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function __;
use function absint;
use function array_values;
use function delete_user_meta;
use function get_current_user_id;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function get_user_meta;
use function is_array;
use function is_user_logged_in;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_text_field;
use function update_user_meta;
use function wp_generate_uuid4;
use function wp_unslash;

/**
 * 「いつもの」機能。よく使うメニューとスタッフの組み合わせをユーザーごとに保存する。
 *
 * 保存先はユーザーメタ（キー: _vkbm_favorites）。値は連想配列の配列で、
 * 各要素は { id, menu_id, resource_id, label } を持つ。
 */
class User_Favorites_Controller {
	private const REST_NAMESPACE = 'vkbm/v1';

	/**
	 * お気に入りを格納するユーザーメタのキー。
	 */
	private const META_KEY = '_vkbm_favorites';

	/**
	 * 1ユーザーが登録できるお気に入りの上限件数。
	 */
	private const MAX_FAVORITES = 20;

	/**
	 * メニューに紐づく対応スタッフID一覧を保持するメニュー側メタのキー。
	 */
	private const META_MENU_STAFF_IDS = '_vkbm_staff_ids';

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * REST ルートを登録する。
	 */
	public function register_routes(): void {
		// お気に入り一覧の取得と新規追加。
		register_rest_route(
			self::REST_NAMESPACE,
			'/favorites',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_favorites' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_favorite' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'menu_id'     => array(
							'type'     => 'integer',
							'required' => true,
						),
						'resource_id' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'label'       => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		// 個別のお気に入り削除。
		register_rest_route(
			self::REST_NAMESPACE,
			'/favorites/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_favorite' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * ログイン必須の認可コールバック。
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * 現在のユーザーのお気に入り一覧を返す。
	 *
	 * @param WP_REST_Request $request リクエスト。
	 * @return WP_REST_Response
	 */
	public function get_favorites( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return rest_ensure_response( array() );
		}

		$favorites = $this->read_favorites( $user_id );

		// 表示用に menu_name / resource_name / available を付与して返す。
		$items = array();
		foreach ( $favorites as $favorite ) {
			$items[] = $this->present_favorite( $favorite );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * お気に入りを新規追加する。
	 *
	 * @param WP_REST_Request $request リクエスト。
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_favorite( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$menu_id     = absint( $request->get_param( 'menu_id' ) );
		$resource_id = absint( $request->get_param( 'resource_id' ) );

		// メニューの存在チェック。公開済みのサービスメニューのみ受け付ける。
		$menu = get_post( $menu_id );
		if ( ! $menu instanceof WP_Post || Service_Menu_Post_Type::POST_TYPE !== $menu->post_type || 'publish' !== $menu->post_status ) {
			return new WP_Error( 'invalid_menu', __( 'The selected service menu is invalid.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		// スタッフ指名がある場合、そのメニューで指名可能なスタッフかを検証する。
		if ( $resource_id > 0 && ! $this->is_resource_assignable( $menu_id, $resource_id ) ) {
			return new WP_Error( 'invalid_resource', __( 'The selected staff member cannot be assigned to this service menu.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		$favorites = $this->read_favorites( $user_id );

		// 同じメニュー＋スタッフの組み合わせが既にあれば重複登録しない。
		foreach ( $favorites as $favorite ) {
			if ( (int) $favorite['menu_id'] === $menu_id && (int) $favorite['resource_id'] === $resource_id ) {
				return new WP_Error( 'duplicate_favorite', __( 'This combination is already saved as a favorite.', 'vk-booking-manager' ), array( 'status' => 409 ) );
			}
		}

		// 上限件数チェック。
		if ( count( $favorites ) >= self::MAX_FAVORITES ) {
			return new WP_Error( 'favorites_limit_reached', __( 'You have reached the maximum number of favorites.', 'vk-booking-manager' ), array( 'status' => 400 ) );
		}

		// ラベルは任意。未指定ならメニュー名（＋スタッフ名）を既定値にする。
		$label = sanitize_text_field( (string) wp_unslash( (string) $request->get_param( 'label' ) ) );
		if ( '' === $label ) {
			$label = $this->build_default_label( $menu_id, $resource_id );
		}

		$new_favorite = array(
			'id'          => wp_generate_uuid4(),
			'menu_id'     => $menu_id,
			'resource_id' => $resource_id,
			'label'       => $label,
		);

		$favorites[] = $new_favorite;
		$this->save_favorites( $user_id, $favorites );

		return new WP_REST_Response( $this->present_favorite( $new_favorite ), 201 );
	}

	/**
	 * 指定IDのお気に入りを削除する。
	 *
	 * @param WP_REST_Request $request リクエスト。
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_favorite( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'not_logged_in', __( 'Login required.', 'vk-booking-manager' ), array( 'status' => 401 ) );
		}

		$id = sanitize_text_field( (string) $request['id'] );

		$favorites = $this->read_favorites( $user_id );
		$remaining = array();
		$found     = false;
		foreach ( $favorites as $favorite ) {
			if ( $favorite['id'] === $id ) {
				$found = true;
				continue;
			}
			$remaining[] = $favorite;
		}

		if ( ! $found ) {
			return new WP_Error( 'favorite_not_found', __( 'No favorite found.', 'vk-booking-manager' ), array( 'status' => 404 ) );
		}

		$this->save_favorites( $user_id, $remaining );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * ユーザーメタからお気に入り配列を読み込み、正規化して返す。
	 *
	 * @param int $user_id ユーザーID。
	 * @return array<int, array{id:string,menu_id:int,resource_id:int,label:string}>
	 */
	private function read_favorites( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$favorites = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id      = isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '';
			$menu_id = isset( $item['menu_id'] ) ? absint( $item['menu_id'] ) : 0;
			if ( '' === $id || $menu_id <= 0 ) {
				continue;
			}

			$favorites[] = array(
				'id'          => $id,
				'menu_id'     => $menu_id,
				'resource_id' => isset( $item['resource_id'] ) ? absint( $item['resource_id'] ) : 0,
				'label'       => isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : '',
			);
		}

		return $favorites;
	}

	/**
	 * お気に入り配列をユーザーメタに保存する。空の場合はメタを削除する。
	 *
	 * @param int                                                                   $user_id   ユーザーID。
	 * @param array<int, array{id:string,menu_id:int,resource_id:int,label:string}> $favorites お気に入り配列。
	 */
	private function save_favorites( int $user_id, array $favorites ): void {
		if ( empty( $favorites ) ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}

		update_user_meta( $user_id, self::META_KEY, array_values( $favorites ) );
	}

	/**
	 * 表示用にメニュー名・スタッフ名・利用可否を付与したお気に入りを返す。
	 *
	 * メニュー、または指名スタッフが削除済み（または非公開）の場合は available を
	 * false にし、フロント側で非活性表示できるようにする（適用しても復元できないため）。
	 *
	 * @param array{id:string,menu_id:int,resource_id:int,label:string} $favorite お気に入り。
	 * @return array<string, mixed>
	 */
	private function present_favorite( array $favorite ): array {
		$menu_id     = (int) $favorite['menu_id'];
		$resource_id = (int) $favorite['resource_id'];

		$menu           = get_post( $menu_id );
		$menu_available = ( $menu instanceof WP_Post && Service_Menu_Post_Type::POST_TYPE === $menu->post_type && 'publish' === $menu->post_status );

		// 指名あり（resource_id>0）の場合は、そのスタッフが今も公開状態で存在することも条件にする。
		$resource_available = true;
		if ( $resource_id > 0 ) {
			$resource           = get_post( $resource_id );
			$resource_available = ( $resource instanceof WP_Post && Resource_Post_Type::POST_TYPE === $resource->post_type && 'publish' === $resource->post_status );
		}

		$available = ( $menu_available && $resource_available );

		$menu_name = $menu_available ? (string) get_the_title( $menu_id ) : '';

		// 指名なし（resource_id=0）は「指名なし」ラベル、指名ありは表示名を返す。
		$resource_name = $resource_id > 0
			? vkbm_get_resource_display_name( $resource_id )
			: vkbm_get_no_nomination_label();

		return array(
			'id'            => $favorite['id'],
			'menu_id'       => $menu_id,
			'menu_name'     => $menu_name,
			'resource_id'   => $resource_id,
			'resource_name' => $resource_name,
			'label'         => $favorite['label'],
			'available'     => $available,
		);
	}

	/**
	 * ラベル未指定時の既定ラベルを組み立てる（メニュー名／メニュー名＋スタッフ名）。
	 *
	 * @param int $menu_id     メニューID。
	 * @param int $resource_id スタッフID（0は指名なし）。
	 * @return string
	 */
	private function build_default_label( int $menu_id, int $resource_id ): string {
		$menu_name = (string) get_the_title( $menu_id );
		if ( $resource_id <= 0 ) {
			return $menu_name;
		}

		$resource_name = vkbm_get_resource_display_name( $resource_id );

		// 例: 「カット / 田中」のようにメニュー名とスタッフ名を連結する。
		return $menu_name . ' / ' . $resource_name;
	}

	/**
	 * 指定スタッフがそのメニューで指名可能かを判定する。
	 *
	 * メニューに対応スタッフ（_vkbm_staff_ids）が設定されている場合はその中に
	 * 含まれること、未設定の場合は公開済みリソースであることを条件とする。
	 *
	 * @param int $menu_id     メニューID。
	 * @param int $resource_id スタッフID。
	 * @return bool
	 */
	private function is_resource_assignable( int $menu_id, int $resource_id ): bool {
		$resource = get_post( $resource_id );
		if ( ! $resource instanceof WP_Post || Resource_Post_Type::POST_TYPE !== $resource->post_type || 'publish' !== $resource->post_status ) {
			return false;
		}

		$staff_ids = get_post_meta( $menu_id, self::META_MENU_STAFF_IDS, true );
		$staff_ids = is_array( $staff_ids ) ? array_map( 'intval', $staff_ids ) : array();

		// メニューに対応スタッフ指定がある場合は、その中に含まれることを必須とする。
		if ( ! empty( $staff_ids ) ) {
			return in_array( $resource_id, $staff_ids, true );
		}

		// 対応スタッフ未指定のメニューは、公開済みリソースであれば許可する。
		return true;
	}
}
