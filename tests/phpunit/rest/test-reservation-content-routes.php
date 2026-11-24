<?php
/**
 * 予約画面が読み込むメニュー・リソース・リソースタグを vkbm/v1 で返すルートのテスト（#1153）。
 *
 * vkbm/v1 の各ルートが、wp/v2 の同じ取得と同じ結果（本文・件数ヘッダー・状態コード）を返すこと、
 * 下書き・非公開・一覧から外すメニュー（#1047）を出さないこと、無料版（REST 非公開）では登録しないこと、
 * SiteGuard の「ユーザー名漏えい防御」と同じ形で wp/v2 が止められても vkbm/v1 は通ることを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Reservation_Content_Routes;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Reservation_Content_Routes のテストクラス。
 *
 * @group rest
 */
class Reservation_Content_Routes_Test extends WP_UnitTestCase {

	/**
	 * 予約画面のメニュー一覧が指定する _fields。
	 */
	private const MENU_FIELDS = 'id,title,meta,menu_order,vkbm_menu_group,vkbm_nomination_min_guests,vkbm_assignable_staff_ids,vkbm_lodging,vkbm_price_unit,vkbm_extra_guest_fee';

	/**
	 * 予約画面のリソース一覧が指定する _fields。
	 */
	private const RESOURCE_FIELDS = 'id,title,meta,nomination_fee,resource_tags,resource_tag_ids';

	/**
	 * 退避した全体設定（tear_down で戻す）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テストで作った投稿・タームの ID。
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * 全体設定を退避し、REST サーバーを作り直す。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_settings   = get_option( Settings_Repository::OPTION_KEY, false );
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。
	}

	/**
	 * 全体設定・ログイン状態・REST サーバーを元に戻す。
	 */
	public function tear_down(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。
		parent::tear_down();
	}

	/**
	 * 業種プリセットを保存する。
	 *
	 * @param string $preset 業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $preset ) )
		);
	}

	/**
	 * テスト用のメニュー・リソース・リソースタグを作る。
	 *
	 * 公開・下書き・非公開のメニューとリソース、#1047 で一覧から外すアーカイブ済みのメニュー、
	 * 公開リソースに付いたタグと、どこにも付いていないタグを用意する。
	 */
	private function create_fixtures(): void {
		// 公開中のリソース（メニューの担当・タグの件数の対象）。
		$this->ids['resource_publish'] = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '公開スタッフ',
			)
		);
		update_post_meta( $this->ids['resource_publish'], '_vkbm_nomination_fee', '3000' );
		$this->ids['resource_draft']   = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => '下書きスタッフ',
			)
		);
		$this->ids['resource_private'] = (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => '非公開スタッフ',
			)
		);

		// メニュー（担当がいないメニューは一覧に出ない #1144 ため、全員担当にする）。
		$menus = array(
			'menu_publish'  => array( 'publish', array() ),
			'menu_draft'    => array( 'draft', array() ),
			'menu_private'  => array( 'private', array() ),
			'menu_archived' => array( 'publish', array( '_vkbm_is_archived' => '1' ) ),
		);
		foreach ( $menus as $key => $menu ) {
			$this->ids[ $key ] = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => $menu[0],
					'post_title'  => $key,
				)
			);
			update_post_meta( $this->ids[ $key ], Service_Menu_Post_Type::META_STAFF_ALL, '1' );
			foreach ( $menu[1] as $meta_key => $meta_value ) {
				update_post_meta( $this->ids[ $key ], $meta_key, $meta_value );
			}
		}

		// リソースタグ（無料版にはタクソノミーが無い）。
		if ( taxonomy_exists( Resource_Tag_Taxonomy::TAXONOMY ) ) {
			$this->ids['tag_used']  = (int) $this->factory()->term->create(
				array(
					'taxonomy' => Resource_Tag_Taxonomy::TAXONOMY,
					'name'     => '使用中タグ',
				)
			);
			$this->ids['tag_empty'] = (int) $this->factory()->term->create(
				array(
					'taxonomy' => Resource_Tag_Taxonomy::TAXONOMY,
					'name'     => '未使用タグ',
				)
			);
			// リソースの投稿タイプを登録し直すテスト（unregister_post_type()）が先に走ると、タグとリソースの結び付きが外れ、
			// タグの件数が 0 のままになる。実際のサイトと同じ結び付きに戻してから件数を数える。
			register_taxonomy_for_object_type( Resource_Tag_Taxonomy::TAXONOMY, Resource_Post_Type::POST_TYPE );
			wp_set_object_terms( $this->ids['resource_publish'], array( $this->ids['tag_used'] ), Resource_Tag_Taxonomy::TAXONOMY );
		}
	}

	/**
	 * REST リクエストを送り、配信時と同じ後処理（_fields の絞り込み）をかけた結果を返す。
	 *
	 * @param string              $route  ルート。
	 * @param array<string,mixed> $params クエリ引数。
	 * @return array{status: int, data: mixed, total: mixed, total_pages: mixed} 結果。
	 */
	private function dispatch( string $route, array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$server   = rest_get_server();
		$response = rest_ensure_response( $server->dispatch( $request ) );
		$response = apply_filters( 'rest_post_dispatch', $response, $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 配信時に WordPress 本体がかける後処理（_fields の絞り込み）を再現する。
		$headers  = $response->get_headers();

		return array(
			'status'      => $response->get_status(),
			'data'        => $response->get_data(),
			'total'       => $headers['X-WP-Total'] ?? null,
			'total_pages' => $headers['X-WP-TotalPages'] ?? null,
		);
	}

	/**
	 * 一覧の結果から ID を取り出す。
	 *
	 * @param mixed $data レスポンスの本文。
	 * @return array<int, int> ID（昇順）。
	 */
	private function extract_ids( $data ): array {
		$ids = is_array( $data ) ? array_map( 'intval', wp_list_pluck( $data, 'id' ) ) : array();
		sort( $ids );

		return $ids;
	}

	/**
	 * SiteGuard の「ユーザー名漏えい防御」と同じ形の rest_pre_dispatch フィルターを返す。
	 *
	 * 投稿・固定ページの編集権限を持たない人の REST 通信を止め（未ログインは 401、ログイン中は 403）、除外リストのネームスペースだけを通す。
	 *
	 * @param array<int, string> $excludes 除外するネームスペース。
	 * @return callable フィルター。
	 */
	private function build_siteguard_like_filter( array $excludes ): callable {
		return static function ( $result, $server, $request ) use ( $excludes ) {
			unset( $server );
			if ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) ) {
				return $result;
			}
			$route = $request->get_route();
			foreach ( $excludes as $exclude ) {
				if ( '/' . $exclude === $route || 0 === strpos( $route, '/' . $exclude . '/' ) ) {
					return $result;
				}
			}

			return new WP_Error( 'rest_disabled', 'The REST API on this site has been disabled.', array( 'status' => rest_authorization_required_code() ) );
		};
	}

	/**
	 * register_routes: 版ごとに登録するルート。REST で公開していない投稿タイプ・タクソノミーは登録しない。
	 */
	public function test_register_routes(): void {
		$is_free    = Pro_Upsell::is_free_edition();
		$test_cases = array(
			array(
				'test_condition_name' => 'メニューの一覧 => Pro 版・無料版とも登録する',
				'route'               => '/vkbm/v1/service-menus',
				'expected_pro'        => true,
				'expected_free'       => true,
			),
			array(
				'test_condition_name' => 'メニューの 1 件取得 => Pro 版・無料版とも登録する',
				'route'               => '/vkbm/v1/service-menus/(?P<id>[\d]+)',
				'expected_pro'        => true,
				'expected_free'       => true,
			),
			array(
				'test_condition_name' => 'リソースの一覧 => Pro 版だけ登録する（無料版はリソースの REST が無い）',
				'route'               => '/vkbm/v1/resources',
				'expected_pro'        => true,
				'expected_free'       => false,
			),
			array(
				'test_condition_name' => 'リソースの 1 件取得 => Pro 版だけ登録する',
				'route'               => '/vkbm/v1/resources/(?P<id>[\d]+)',
				'expected_pro'        => true,
				'expected_free'       => false,
			),
			array(
				'test_condition_name' => 'リソースタグの一覧 => Pro 版だけ登録する（無料版はリソースタグが無い）',
				'route'               => '/vkbm/v1/resource-tags',
				'expected_pro'        => true,
				'expected_free'       => false,
			),
			array(
				'test_condition_name' => 'リソースタグの 1 件取得 => 予約画面で使わないため登録しない',
				'route'               => '/vkbm/v1/resource-tags/(?P<id>[\d]+)',
				'expected_pro'        => false,
				'expected_free'       => false,
			),
		);

		$routes = rest_get_server()->get_routes( Reservation_Content_Routes::REST_NAMESPACE );
		foreach ( $test_cases as $case ) {
			$expected = $is_free ? $case['expected_free'] : $case['expected_pro'];
			$this->assertSame( $expected, isset( $routes[ $case['route'] ] ), $case['test_condition_name'] );
			if ( $expected ) {
				// 読み取り（GET）だけを受け付け、書き込みの入口は作らない。
				foreach ( $routes[ $case['route'] ] as $handler ) {
					$this->assertSame( array( 'GET' => true ), $handler['methods'], $case['test_condition_name'] . ' / GET だけ' );
				}
			}
		}
	}

	/**
	 * register_routes: 無料版と同じく、リソースの REST とリソースタグが無いサイトでは該当のルートを登録しない。
	 *
	 * 無料版はリソースの投稿タイプを show_in_rest => false で登録し、リソースタグは登録しない。
	 * Pro 版の環境でもこの状態を作り、登録の判定が REST の公開状態に従うことを確かめる。
	 */
	public function test_register_routes_without_rest_objects(): void {
		global $wp_post_types, $wp_taxonomies;

		$original_resource_show = $wp_post_types[ Resource_Post_Type::POST_TYPE ]->show_in_rest ?? null;
		$original_tag           = $wp_taxonomies[ Resource_Tag_Taxonomy::TAXONOMY ] ?? null;

		// 無料版と同じ状態にする。
		if ( isset( $wp_post_types[ Resource_Post_Type::POST_TYPE ] ) ) {
			$wp_post_types[ Resource_Post_Type::POST_TYPE ]->show_in_rest = false;
		}
		unset( $wp_taxonomies[ Resource_Tag_Taxonomy::TAXONOMY ] );
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。

		$routes = rest_get_server()->get_routes();

		// 元に戻す。
		if ( null !== $original_resource_show ) {
			$wp_post_types[ Resource_Post_Type::POST_TYPE ]->show_in_rest = $original_resource_show;
		}
		if ( null !== $original_tag ) {
			$wp_taxonomies[ Resource_Tag_Taxonomy::TAXONOMY ] = $original_tag; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 無料版の状態を作るために外したタクソノミーを戻す。
		}
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。

		$test_cases = array(
			array(
				'test_condition_name' => 'メニューの一覧 => 登録する',
				'route'               => '/vkbm/v1/service-menus',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'リソースの一覧 => wp/v2 に無いため登録しない',
				'route'               => '/vkbm/v1/resources',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リソースの 1 件取得 => 登録しない',
				'route'               => '/vkbm/v1/resources/(?P<id>[\d]+)',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リソースタグの一覧 => タクソノミーが無いため登録しない',
				'route'               => '/vkbm/v1/resource-tags',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '（比較）wp/v2 のリソースの一覧も無い',
				'route'               => '/wp/v2/' . Resource_Post_Type::POST_TYPE,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], isset( $routes[ $case['route'] ] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_items / get_item: 未ログイン・会員・管理者で、vkbm/v1 が wp/v2 と同じ結果を返し、見せてはいけない投稿を出さない。
	 */
	public function test_get_items(): void {
		$this->set_site( 'salon' );
		$this->create_fixtures();
		$is_free       = Pro_Upsell::is_free_edition();
		$subscriber_id = (int) $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin_id      = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '未ログインのメニュー一覧 => 公開中だけ（下書き・非公開・アーカイブ済みは出ない）',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/service-menus',
				'params'              => array(
					'per_page' => 100,
					'_fields'  => self::MENU_FIELDS,
				),
				'pro_only'            => false,
				'expected_status'     => 200,
				'expected_ids'        => array( $this->ids['menu_publish'] ),
			),
			array(
				'test_condition_name' => '会員のメニュー一覧 => 公開中だけ',
				'user'                => $subscriber_id,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/service-menus',
				'params'              => array( 'per_page' => 100 ),
				'pro_only'            => false,
				'expected_status'     => 200,
				'expected_ids'        => array( $this->ids['menu_publish'] ),
			),
			array(
				'test_condition_name' => '未ログインが status=publish,private を付ける => wp/v2 と同じく拒否される',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/service-menus',
				'params'              => array(
					'per_page' => 100,
					'status'   => 'publish,private',
				),
				'pro_only'            => false,
				'expected_status'     => 400,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '未ログインが context=edit を付ける => wp/v2 と同じく 401',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/service-menus',
				'params'              => array( 'context' => 'edit' ),
				'pro_only'            => false,
				'expected_status'     => 401,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '管理者が status=publish,private を付ける => 非公開も出る（アーカイブ済みは出ない）',
				'user'                => $admin_id,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/service-menus',
				'params'              => array(
					'per_page' => 100,
					'status'   => 'publish,private',
				),
				'pro_only'            => false,
				'expected_status'     => 200,
				'expected_ids'        => array( $this->ids['menu_publish'], $this->ids['menu_private'] ),
			),
			array(
				'test_condition_name' => '未ログインの include 指定（お気に入りの取り寄せ） => 公開中だけ',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/service-menus',
				'params'              => array(
					'per_page' => 100,
					'include'  => array( $this->ids['menu_publish'], $this->ids['menu_draft'], $this->ids['menu_archived'] ),
				),
				'pro_only'            => false,
				'expected_status'     => 200,
				'expected_ids'        => array( $this->ids['menu_publish'] ),
			),
			array(
				'test_condition_name' => '未ログインのメニュー 1 件取得（公開） => 取得できる',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $this->ids['menu_publish'],
				'vkbm_route'          => '/vkbm/v1/service-menus/' . $this->ids['menu_publish'],
				'params'              => array( '_fields' => 'id,title,meta' ),
				'pro_only'            => false,
				'expected_status'     => 200,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '未ログインのメニュー 1 件取得（下書き） => wp/v2 と同じく 401',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $this->ids['menu_draft'],
				'vkbm_route'          => '/vkbm/v1/service-menus/' . $this->ids['menu_draft'],
				'params'              => array(),
				'pro_only'            => false,
				'expected_status'     => 401,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '未ログインのメニュー 1 件取得（非公開） => wp/v2 と同じく 401',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $this->ids['menu_private'],
				'vkbm_route'          => '/vkbm/v1/service-menus/' . $this->ids['menu_private'],
				'params'              => array(),
				'pro_only'            => false,
				'expected_status'     => 401,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '存在しないメニューの 1 件取得 => wp/v2 と同じく 404',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/999999',
				'vkbm_route'          => '/vkbm/v1/service-menus/999999',
				'params'              => array(),
				'pro_only'            => false,
				'expected_status'     => 404,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '未ログインのリソース一覧 => 公開中だけ（下書き・非公開は出ない）',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Resource_Post_Type::POST_TYPE,
				'vkbm_route'          => '/vkbm/v1/resources',
				'params'              => array(
					'per_page' => 100,
					'_fields'  => self::RESOURCE_FIELDS,
				),
				'pro_only'            => true,
				'expected_status'     => 200,
				'expected_ids'        => array( $this->ids['resource_publish'] ),
			),
			array(
				'test_condition_name' => '未ログインのリソース 1 件取得（公開） => 取得できる',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Resource_Post_Type::POST_TYPE . '/' . $this->ids['resource_publish'],
				'vkbm_route'          => '/vkbm/v1/resources/' . $this->ids['resource_publish'],
				'params'              => array( '_fields' => 'id,title,meta' ),
				'pro_only'            => true,
				'expected_status'     => 200,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '未ログインのリソース 1 件取得（下書き） => wp/v2 と同じく 401',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Resource_Post_Type::POST_TYPE . '/' . $this->ids['resource_draft'],
				'vkbm_route'          => '/vkbm/v1/resources/' . $this->ids['resource_draft'],
				'params'              => array(),
				'pro_only'            => true,
				'expected_status'     => 401,
				'expected_ids'        => array(),
			),
			array(
				'test_condition_name' => '未ログインのリソースタグ一覧（hide_empty） => 公開リソースに付いたタグだけ',
				'user'                => 0,
				'wp_route'            => '/wp/v2/' . Resource_Tag_Taxonomy::TAXONOMY,
				'vkbm_route'          => '/vkbm/v1/resource-tags',
				'params'              => array(
					'per_page'   => 100,
					'hide_empty' => true,
					'_fields'    => 'id,name,count,order',
				),
				'pro_only'            => true,
				'expected_status'     => 200,
				'expected_ids'        => array( $this->ids['tag_used'] ?? 0 ),
			),
		);

		foreach ( $test_cases as $case ) {
			// 無料版にはリソースの REST とリソースタグが無いため、比べる相手が無い。
			if ( $is_free && $case['pro_only'] ) {
				continue;
			}
			wp_set_current_user( $case['user'] );

			$wp_result   = $this->dispatch( $case['wp_route'], $case['params'] );
			$vkbm_result = $this->dispatch( $case['vkbm_route'], $case['params'] );

			// 本文・状態コード・件数ヘッダーまで wp/v2 と同じ。
			$this->assertSame( $wp_result, $vkbm_result, $case['test_condition_name'] . ' / wp/v2 と同じ結果' );
			$this->assertSame( $case['expected_status'], $vkbm_result['status'], $case['test_condition_name'] . ' / 状態コード' );
			if ( 200 === $case['expected_status'] && array() !== $case['expected_ids'] ) {
				$this->assertSame( $case['expected_ids'], $this->extract_ids( $vkbm_result['data'] ), $case['test_condition_name'] . ' / 返す ID' );
				$this->assertSame( count( $case['expected_ids'] ), (int) $vkbm_result['total'], $case['test_condition_name'] . ' / X-WP-Total' );
			}
		}
	}

	/**
	 * get_items / get_item: 宿泊のサイトでは、wp/v2 と同じく予約画面向けのリソースの指名料を 0 にして返す。
	 */
	public function test_get_items_nomination_fee(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'リソースの REST と宿泊は Pro 版限定のため、無料版ではスキップする。' );
		}
		$this->create_fixtures();
		wp_set_current_user( 0 );

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトのリソース一覧 => 指名料は 0',
				'preset'              => 'lodging',
				'route'               => '/vkbm/v1/resources',
				'is_collection'       => true,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '宿泊のサイトのリソース 1 件取得 => 指名料は 0',
				'preset'              => 'lodging',
				'route'               => '/vkbm/v1/resources/' . $this->ids['resource_publish'],
				'is_collection'       => false,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'サロンのサイトのリソース一覧 => 保存済みの指名料',
				'preset'              => 'salon',
				'route'               => '/vkbm/v1/resources',
				'is_collection'       => true,
				'expected'            => 3000,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$result = $this->dispatch( $case['route'], array( '_fields' => 'id,nomination_fee' ) );
			$item   = $case['is_collection'] ? $result['data'][0] : $result['data'];

			$this->assertSame( 200, $result['status'], $case['test_condition_name'] . ' / 状態コード' );
			$this->assertSame( $case['expected'], (int) $item['nomination_fee'], $case['test_condition_name'] );
		}
	}

	/**
	 * rest_pre_dispatch: SiteGuard と同じ形で wp/v2 が止められても、除外に vkbm/v1 だけを書けば予約画面の取得は通る。
	 */
	public function test_rest_pre_dispatch(): void {
		$this->create_fixtures();
		$is_free       = Pro_Upsell::is_free_edition();
		$subscriber_id = (int) $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor_id     = (int) $this->factory()->user->create( array( 'role' => 'editor' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '未ログインの wp/v2 のメニュー一覧 => 止められる（401）',
				'user'                => 0,
				'route'               => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'pro_only'            => false,
				'expected'            => 401,
			),
			array(
				'test_condition_name' => '未ログインの vkbm/v1 のメニュー一覧 => 通る',
				'user'                => 0,
				'route'               => '/vkbm/v1/service-menus',
				'pro_only'            => false,
				'expected'            => 200,
			),
			array(
				'test_condition_name' => '会員の vkbm/v1 のメニュー 1 件取得 => 通る',
				'user'                => $subscriber_id,
				'route'               => '/vkbm/v1/service-menus/' . $this->ids['menu_publish'],
				'pro_only'            => false,
				'expected'            => 200,
			),
			array(
				'test_condition_name' => '未ログインの vkbm/v1 のリソース一覧 => 通る',
				'user'                => 0,
				'route'               => '/vkbm/v1/resources',
				'pro_only'            => true,
				'expected'            => 200,
			),
			array(
				'test_condition_name' => '未ログインの vkbm/v1 のリソース 1 件取得 => 通る',
				'user'                => 0,
				'route'               => '/vkbm/v1/resources/' . $this->ids['resource_publish'],
				'pro_only'            => true,
				'expected'            => 200,
			),
			array(
				'test_condition_name' => '未ログインの vkbm/v1 のリソースタグ一覧 => 通る',
				'user'                => 0,
				'route'               => '/vkbm/v1/resource-tags',
				'pro_only'            => true,
				'expected'            => 200,
			),
			array(
				'test_condition_name' => '会員の wp/v2 のリソースタグ一覧 => 止められる（ログイン中のため 403）',
				'user'                => $subscriber_id,
				'route'               => '/wp/v2/' . Resource_Tag_Taxonomy::TAXONOMY,
				'pro_only'            => true,
				'expected'            => 403,
			),
			array(
				'test_condition_name' => '編集者（edit_posts あり）の wp/v2 のメニュー一覧 => 止められない',
				'user'                => $editor_id,
				'route'               => '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE,
				'pro_only'            => false,
				'expected'            => 200,
			),
		);

		$filter = $this->build_siteguard_like_filter( array( 'vkbm/v1' ) );
		add_filter( 'rest_pre_dispatch', $filter, 10, 3 );
		foreach ( $test_cases as $case ) {
			if ( $is_free && $case['pro_only'] ) {
				continue;
			}
			wp_set_current_user( $case['user'] );

			$result = $this->dispatch( $case['route'] );

			$this->assertSame( $case['expected'], $result['status'], $case['test_condition_name'] );
		}
		remove_filter( 'rest_pre_dispatch', $filter, 10 );
	}

	/**
	 * get_route_definitions: 予約画面が使うルートと、対象の投稿タイプ・タクソノミーの対応。
	 */
	public function test_get_route_definitions(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'service-menus => サービスメニューの投稿タイプ・1 件取得あり',
				'rest_base'           => 'service-menus',
				'expected'            => array(
					'object'     => 'post_type',
					'name'       => 'vkbm_service_menu',
					'item_route' => true,
				),
			),
			array(
				'test_condition_name' => 'resources => リソースの投稿タイプ・1 件取得あり',
				'rest_base'           => 'resources',
				'expected'            => array(
					'object'     => 'post_type',
					'name'       => 'vkbm_resource',
					'item_route' => true,
				),
			),
			array(
				'test_condition_name' => 'resource-tags => リソースタグのタクソノミー・1 件取得なし',
				'rest_base'           => 'resource-tags',
				'expected'            => array(
					'object'     => 'taxonomy',
					'name'       => 'vkbm_resource_tag',
					'item_route' => false,
				),
			),
			array(
				'test_condition_name' => '予約画面で使わないもの（bookings） => 定義に無い',
				'rest_base'           => 'bookings',
				'expected'            => null,
			),
		);

		$definitions = Reservation_Content_Routes::get_route_definitions();
		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], $definitions[ $case['rest_base'] ] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * get_wp_rest_controller: wp/v2 のルートと同じコントローラーのインスタンスを返し、REST 非公開・未登録なら null。
	 */
	public function test_get_wp_rest_controller(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'サービスメニュー => 投稿タイプに登録されたコントローラー',
				'object'              => 'post_type',
				'name'                => Service_Menu_Post_Type::POST_TYPE,
				'expected_same_as'    => get_post_type_object( Service_Menu_Post_Type::POST_TYPE )->get_rest_controller(),
			),
			array(
				'test_condition_name' => 'REST 非公開の投稿タイプ（予約） => null',
				'object'              => 'post_type',
				'name'                => 'vkbm_booking',
				'expected_same_as'    => null,
			),
			array(
				'test_condition_name' => '存在しないタクソノミー => null',
				'object'              => 'taxonomy',
				'name'                => 'vkbm_no_such_taxonomy',
				'expected_same_as'    => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame(
				$case['expected_same_as'],
				Reservation_Content_Routes::get_wp_rest_controller( $case['object'], $case['name'] ),
				$case['test_condition_name']
			);
		}

		// wp/v2 のルートが実際に同じインスタンスを使っていることも確かめる。
		$routes  = rest_get_server()->get_routes();
		$handler = $routes[ '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE ][0];
		$this->assertSame(
			$handler['callback'][0],
			$routes['/vkbm/v1/service-menus'][0]['callback'][0],
			'vkbm/v1 と wp/v2 のメニュー一覧は同じコントローラーを使う'
		);
	}
}
