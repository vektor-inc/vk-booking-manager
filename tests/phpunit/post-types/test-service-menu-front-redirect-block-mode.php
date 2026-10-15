<?php
/**
 * 詳細ページを予約ブロック内で表示する設定（#635）のリダイレクト・サイトマップ除外のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Front_Redirect;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use WP_UnitTestCase;

/**
 * Service_Menu_Front_Redirect の「予約ブロック内で表示」時の挙動を保証するテスト。
 *
 * @group post-types
 */
class Service_Menu_Front_Redirect_Block_Mode_Test extends WP_UnitTestCase {

	/**
	 * 投稿タイプの登録を保証する。
	 */
	protected function setUp(): void {
		parent::setUp();
		do_action( 'init' );
	}

	/**
	 * 各テスト後に基本設定オプションとログイン状態を掃除する。
	 */
	public function tear_down(): void {
		$_GET = array();
		delete_option( Settings_Repository::OPTION_KEY );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 基本設定を保存する。
	 *
	 * @param string $mode            詳細ページの表示方法（block / template）。
	 * @param string $reservation_url 予約ページURL。
	 */
	private function save_settings( string $mode, string $reservation_url ): void {
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'service_menu_detail_display_mode' => $mode,
				'reservation_page_url'             => $reservation_url,
			)
		);
	}

	/**
	 * サービスメニュー投稿を1件作成する。
	 *
	 * @param array<string,mixed> $post_args 投稿の上書き項目。
	 * @param array<string,mixed> $meta      付与するメタ。
	 * @return WP_Post
	 */
	private function create_menu( array $post_args = array(), array $meta = array( '_vkbm_use_detail_page' => '1' ) ): WP_Post {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_title'  => 'カット',
					'post_status' => 'publish',
				),
				$post_args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return get_post( $post_id );
	}

	/**
	 * resolve_redirect_url() の block モードの条件を検証する。
	 */
	public function test_resolve_redirect_url(): void {
		$reserve = home_url( '/booking/' );

		$test_cases = array(
			array(
				'test_condition_name' => 'block・詳細ページON・自サイトの予約ページURL => 詳細表示用URLへ',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'expected_to_detail'  => true,
			),
			array(
				'test_condition_name' => 'template・詳細ページON => 転送しない（従来どおり個別ページを表示）',
				'mode'                => 'template',
				'reservation_url'     => $reserve,
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'expected_to_detail'  => false,
			),
			array(
				'test_condition_name' => 'block・予約ページURL未設定 => 転送しない',
				'mode'                => 'block',
				'reservation_url'     => '',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'expected_to_detail'  => false,
			),
			array(
				'test_condition_name' => 'block・予約ページURLが自サイト外 => 転送しない',
				'mode'                => 'block',
				'reservation_url'     => 'https://other.example.org/booking/',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'expected_to_detail'  => false,
			),
			array(
				'test_condition_name' => 'block・非公開で権限なし => 転送しない（転送先で表示できないため）',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'post_args'           => array( 'post_status' => 'private' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'expected_to_detail'  => false,
			),
			array(
				'test_condition_name' => 'block・アーカイブ済み => 転送しない',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'post_args'           => array(),
				'meta'                => array(
					'_vkbm_use_detail_page' => '1',
					'_vkbm_is_archived'     => '1',
				),
				'expected_to_detail'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['mode'], $case['reservation_url'] );
			$menu    = $this->create_menu( $case['post_args'], $case['meta'] );
			$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );

			$expected = $case['expected_to_detail']
				? add_query_arg(
					array(
						'menu_id'   => (string) $menu->ID,
						'vkbm_view' => 'detail',
					),
					$case['reservation_url']
				)
				: '';

			$this->assertSame( $expected, $handler->resolve_redirect_url( $menu ), $case['test_condition_name'] );
		}
	}

	/**
	 * block モードでも、詳細ページOFFのメニューは従来どおり予約ページ（クエリなし）へ転送されることを検証する。
	 */
	public function test_resolve_redirect_url_detail_page_off(): void {
		$reserve = home_url( '/booking/' );
		$this->save_settings( 'block', $reserve );

		$menu    = $this->create_menu( array(), array( '_vkbm_use_detail_page' => '' ) );
		$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );

		$this->assertSame( $reserve, $handler->resolve_redirect_url( $menu ), '詳細ページOFFは従来どおり予約ページのトップへ' );
	}

	/**
	 * 詳細ページOFFのメニューは、URL に vkbm_view=detail が付いていても予約ページへ転送されることを検証する（#453 の転送のすり抜け防止）。
	 */
	public function test_resolve_redirect_url_detail_page_off_with_view_query(): void {
		$reserve = home_url( '/booking/' );
		$this->save_settings( 'block', $reserve );

		$menu    = $this->create_menu( array(), array( '_vkbm_use_detail_page' => '' ) );
		$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );

		$_GET = array( 'vkbm_view' => 'detail' );
		$url  = $handler->resolve_redirect_url( $menu );
		$_GET = array();

		$this->assertSame( $reserve, $url, '詳細ページOFFは vkbm_view=detail が付いていても予約ページのトップへ' );
	}

	/**
	 * 詳細ページONのメニューは、vkbm_view=detail 付きのリクエストでは転送しない（ループ防止）ことを検証する。
	 */
	public function test_resolve_redirect_url_detail_page_on_with_view_query(): void {
		$menu = $this->create_menu();
		$this->save_settings( 'block', home_url( '/booking/' ) );
		$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );

		$this->assertNotSame( '', $handler->resolve_redirect_url( $menu ), 'クエリなしでは詳細表示へ転送する' );

		$_GET = array( 'vkbm_view' => 'detail' );
		$url  = $handler->resolve_redirect_url( $menu );
		$_GET = array();

		$this->assertSame( '', $url, '詳細ページONは vkbm_view=detail なら転送しない（ループ防止）' );
	}

	/**
	 * filter_sitemap_query_args() が block モードでサービスメニューをすべて除外することを検証する。
	 */
	public function test_filter_sitemap_query_args(): void {
		$reserve = home_url( '/booking/' );

		$test_cases = array(
			array(
				'test_condition_name' => 'block・サービスメニュー => 投稿を1件も含まない条件にする',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'expected_excluded'   => true,
			),
			array(
				'test_condition_name' => 'block・通常投稿 => 変更しない',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'post_type'           => 'post',
				'expected_excluded'   => false,
			),
			array(
				'test_condition_name' => 'template・サービスメニュー => 全除外にはしない（従来の絞り込み）',
				'mode'                => 'template',
				'reservation_url'     => $reserve,
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'expected_excluded'   => false,
			),
			array(
				'test_condition_name' => 'block・予約ページURL未設定のサービスメニュー => 全除外にはしない（個別ページが従来どおり表示されるため）',
				'mode'                => 'block',
				'reservation_url'     => '',
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'expected_excluded'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['mode'], $case['reservation_url'] );
			$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );

			$actual = $handler->filter_sitemap_query_args( array(), $case['post_type'] );

			$this->assertSame(
				$case['expected_excluded'],
				array( 0 ) === ( $actual['post__in'] ?? null ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * WordPress 標準サイトマップのURL一覧から、block モードではサービスメニューが消えることを検証する。
	 */
	public function test_sitemap_url_list(): void {
		$this->save_settings( 'block', home_url( '/booking/' ) );
		( new Service_Menu_Front_Redirect( new Settings_Repository() ) )->register();
		// register() で追加したフックは WP_UnitTestCase の tear_down() が復元するため、個別の後片付けは不要。

		$this->create_menu();

		$provider = new \WP_Sitemaps_Posts();
		$url_list = $provider->get_url_list( 1, Service_Menu_Post_Type::POST_TYPE );

		$this->assertSame( array(), $url_list, 'block では詳細ページONのメニューもサイトマップに含まれない' );
	}

	/**
	 * maybe_redirect() が、すでに詳細表示のURL（vkbm_view=detail）なら転送しないことを検証する（自己転送ループの防止）。
	 */
	public function test_maybe_redirect(): void {
		$menu = $this->create_menu();
		// 予約ページURLが、このメニュー自身の個別ページURLに余分なクエリを足した形の設定。
		$this->save_settings( 'block', add_query_arg( 'extra', '1', get_permalink( $menu ) ) );
		$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );

		$redirected = false;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = true;
				return $location;
			}
		);

		// 詳細表示のURLでは、転送を繰り返さずそのまま表示する。
		$_GET = array( 'vkbm_view' => 'detail' );
		$this->go_to(
			add_query_arg(
				array(
					'extra'     => '1',
					'menu_id'   => (string) $menu->ID,
					'vkbm_view' => 'detail',
				),
				get_permalink( $menu )
			)
		);
		$handler->maybe_redirect();
		$_GET = array();

		$this->assertFalse( $redirected, '詳細表示のURLでは転送しない' );
	}

	/**
	 * 個別ページから詳細表示への転送先に、検証済みの日付が引き継がれることを検証する。
	 */
	public function test_resolve_redirect_url_carries_dates(): void {
		$menu    = $this->create_menu();
		$reserve = home_url( '/booking/' );
		$this->save_settings( 'block', $reserve );
		$handler = new Service_Menu_Front_Redirect( new Settings_Repository() );
		$base    = add_query_arg(
			array(
				'menu_id'   => (string) $menu->ID,
				'vkbm_view' => 'detail',
			),
			$reserve
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'date がある => 転送先に引き継ぐ',
				'get'                 => array( 'date' => '2098-10-20' ),
				'expected'            => $base . '&date=2098-10-20',
			),
			array(
				'test_condition_name' => '不正な date => 引き継がない',
				'get'                 => array( 'date' => 'abc' ),
				'expected'            => $base,
			),
			array(
				'test_condition_name' => 'クエリなし => 従来どおり',
				'get'                 => array(),
				'expected'            => $base,
			),
		);

		foreach ( $test_cases as $case ) {
			$_GET = $case['get'];
			$this->assertSame( $case['expected'], $handler->resolve_redirect_url( $menu ), $case['test_condition_name'] );
		}
		$_GET = array();
	}
}
