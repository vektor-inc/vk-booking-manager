<?php
/**
 * 予約画面が読み込むサービスメニュー・リソース・リソースタグを vkbm/v1 で返す REST ルート。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use WP_REST_Controller;
use WP_REST_Server;
use function get_post_type_object;
use function get_taxonomy;
use function register_rest_route;

/**
 * 予約画面（フロント）が使う読み取り用ルートを vkbm/v1 に登録するクラス（#1153）。
 *
 * セキュリティ系プラグイン（SiteGuard の「ユーザー名漏えい防御」など）は、編集権限を持たない人からの
 * REST 通信をネームスペース単位で止める。予約画面の通信を vkbm/v1 だけにまとめ、除外の設定を
 * vkbm/v1 の 1 行で済ませるために、wp/v2 と同じ内容を vkbm/v1 からも返す。
 *
 * 中身は、投稿タイプ・タクソノミーに登録されている wp/v2 用の REST コントローラー（同じインスタンス）の
 * 一覧・1 件取得と権限チェックをそのまま呼ぶ。返す内容・見られる範囲・既存のフィルター
 * （`rest_{post_type}_query`・`rest_prepare_{post_type}`・`register_rest_field` など）は wp/v2 と同じになる。
 * rest_do_request() で wp/v2 を内部で呼び直すと、内部の呼び出しにも `rest_pre_dispatch` で止める処理が
 * 効いてしまうため、その方式は使わないこと。
 */
class Reservation_Content_Routes {

	/**
	 * ルートのネームスペース。
	 */
	public const REST_NAMESPACE = 'vkbm/v1';

	/**
	 * 対象が投稿タイプであることを表す値。
	 */
	private const OBJECT_POST_TYPE = 'post_type';

	/**
	 * 対象がタクソノミーであることを表す値。
	 */
	private const OBJECT_TAXONOMY = 'taxonomy';

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * 登録するルートの定義を返す。
	 *
	 * キーは vkbm/v1 での rest_base。`object` は投稿タイプかタクソノミーか、`name` はその名前、
	 * `item_route` は ID を指定した 1 件取得のルートも登録するか。
	 *
	 * @return array<string, array{object: string, name: string, item_route: bool}> ルートの定義。
	 */
	public static function get_route_definitions(): array {
		return array(
			'service-menus' => array(
				'object'     => self::OBJECT_POST_TYPE,
				'name'       => Service_Menu_Post_Type::POST_TYPE,
				'item_route' => true,
			),
			'resources'     => array(
				'object'     => self::OBJECT_POST_TYPE,
				'name'       => Resource_Post_Type::POST_TYPE,
				'item_route' => true,
			),
			'resource-tags' => array(
				'object'     => self::OBJECT_TAXONOMY,
				'name'       => Resource_Tag_Taxonomy::TAXONOMY,
				'item_route' => false,
			),
		);
	}

	/**
	 * ルートを登録する。
	 *
	 * wp/v2 で公開していない投稿タイプ・タクソノミー（無料版のリソース・リソースタグなど）は登録しない。
	 */
	public function register_routes(): void {
		foreach ( self::get_route_definitions() as $rest_base => $definition ) {
			// wp/v2 用に登録されているコントローラーを取り出す。wp/v2 に無いものは vkbm/v1 にも出さない。
			$controller = self::get_wp_rest_controller( $definition['object'], $definition['name'] );
			if ( null === $controller ) {
				continue;
			}

			// 一覧（wp/v2 の一覧と同じ引数・処理・権限チェック）。
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $rest_base,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $controller, 'get_items' ),
						'permission_callback' => array( $controller, 'get_items_permissions_check' ),
						'args'                => $controller->get_collection_params(),
					),
					'schema' => array( $controller, 'get_public_item_schema' ),
				)
			);

			if ( ! $definition['item_route'] ) {
				continue;
			}

			// ID を指定した 1 件取得（wp/v2 の 1 件取得と同じ引数・処理・権限チェック）。
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $rest_base . '/(?P<id>[\d]+)',
				array(
					'args'   => array(
						'id' => array(
							'type' => 'integer',
						),
					),
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $controller, 'get_item' ),
						'permission_callback' => array( $controller, 'get_item_permissions_check' ),
						'args'                => self::build_post_item_args( $controller ),
					),
					'schema' => array( $controller, 'get_public_item_schema' ),
				)
			);
		}
	}

	/**
	 * 投稿タイプ・タクソノミーに登録されている wp/v2 用の REST コントローラーを返す。
	 *
	 * WordPress 本体が wp/v2 のルートに使うものと同じインスタンスを返す。
	 * 登録されていない・REST で公開していない（show_in_rest が false）ときは null。
	 *
	 * @param string $object_type `post_type` か `taxonomy`。
	 * @param string $name        投稿タイプ名またはタクソノミー名。
	 * @return WP_REST_Controller|null コントローラー。使えないときは null。
	 */
	public static function get_wp_rest_controller( string $object_type, string $name ): ?WP_REST_Controller {
		$object = self::OBJECT_TAXONOMY === $object_type ? get_taxonomy( $name ) : get_post_type_object( $name );
		if ( ! $object || empty( $object->show_in_rest ) ) {
			return null;
		}

		$controller = $object->get_rest_controller();

		return $controller instanceof WP_REST_Controller ? $controller : null;
	}

	/**
	 * 投稿の 1 件取得に渡す引数を、WordPress 本体の WP_REST_Posts_Controller::register_routes() と同じ形で組み立てる。
	 *
	 * 本体の引数のうち、値の検査・既定値に効くキーだけをそろえる（説明文は省く）。
	 * 本体の引数の組み立てが変わったら、ここも合わせること。
	 *
	 * @param WP_REST_Controller $controller 投稿タイプのコントローラー。
	 * @return array<string, array<string, mixed>> 1 件取得の引数。
	 */
	private static function build_post_item_args( WP_REST_Controller $controller ): array {
		$schema = $controller->get_item_schema();
		$args   = array(
			'context' => $controller->get_context_param( array( 'default' => 'view' ) ),
		);
		if ( isset( $schema['properties']['excerpt'] ) ) {
			$args['excerpt_length'] = array(
				'type' => 'integer',
			);
		}
		if ( isset( $schema['properties']['password'] ) ) {
			$args['password'] = array(
				'type' => 'string',
			);
		}

		return $args;
	}
}
