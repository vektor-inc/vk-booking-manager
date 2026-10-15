<?php
/**
 * サービスメニュー詳細ページのリンク先の判定（#635）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\PostTypes\Service_Menu_Detail_Link;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Post;
use WP_UnitTestCase;

/**
 * Service_Menu_Detail_Link と、それを使うメニューループの「詳細を見る」リンクのテスト。
 *
 * @group post-types
 */
class Service_Menu_Detail_Link_Test extends WP_UnitTestCase {

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
	 * get_detail_url() / is_block_display_enabled() の切り替えを検証する。
	 */
	public function test_get_detail_url(): void {
		$menu       = $this->create_menu();
		$permalink  = get_permalink( $menu );
		$reserve    = home_url( '/booking/' );
		$detail_url = add_query_arg(
			array(
				'menu_id'   => (string) $menu->ID,
				'vkbm_view' => 'detail',
			),
			$reserve
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'block・予約ページURLが自サイト => 予約ページURL + menu_id + vkbm_view=detail',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'expected_url'        => $detail_url,
				'expected_enabled'    => true,
			),
			array(
				'test_condition_name' => 'block・予約ページURLが相対パス => 自サイトのURLに正規化した詳細表示用URL',
				'mode'                => 'block',
				'reservation_url'     => '/booking/',
				'expected_url'        => $detail_url,
				'expected_enabled'    => true,
			),
			array(
				'test_condition_name' => 'template => 個別ページのパーマリンク',
				'mode'                => 'template',
				'reservation_url'     => $reserve,
				'expected_url'        => $permalink,
				'expected_enabled'    => false,
			),
			array(
				'test_condition_name' => 'block・予約ページURLが未設定 => 個別ページのパーマリンク',
				'mode'                => 'block',
				'reservation_url'     => '',
				'expected_url'        => $permalink,
				'expected_enabled'    => false,
			),
			array(
				'test_condition_name' => 'block・予約ページURLが自サイト外 => 個別ページのパーマリンク',
				'mode'                => 'block',
				'reservation_url'     => 'https://other.example.org/booking/',
				'expected_url'        => $permalink,
				'expected_enabled'    => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['mode'], $case['reservation_url'] );
			$link = new Service_Menu_Detail_Link( new Settings_Repository() );

			$this->assertSame( $case['expected_url'], $link->get_detail_url( $menu ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_enabled'], $link->is_block_display_enabled(), $case['test_condition_name'] );
		}
	}

	/**
	 * is_detail_viewable() の公開範囲の判定を検証する。
	 */
	public function test_is_detail_viewable(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '公開・詳細ページON => 表示できる',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '公開・詳細ページOFF => 表示できない',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '' ),
				'user_id'             => 0,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '非公開・権限なし => 表示できない',
				'post_args'           => array( 'post_status' => 'private' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '非公開・権限あり => 表示できる',
				'post_args'           => array( 'post_status' => 'private' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => $editor_id,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '下書き => 権限があっても表示できない',
				'post_args'           => array( 'post_status' => 'draft' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => $editor_id,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'アーカイブ済み => 表示できない',
				'post_args'           => array(),
				'meta'                => array(
					'_vkbm_use_detail_page' => '1',
					'_vkbm_is_archived'     => '1',
				),
				'user_id'             => 0,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'パスワード保護 => 表示できない',
				'post_args'           => array( 'post_password' => 'secret' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サービスメニュー以外の投稿 => 表示できない',
				'post_args'           => array( 'post_type' => 'post' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'expected'            => false,
			),
		);

		$link = new Service_Menu_Detail_Link( new Settings_Repository() );

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$menu = $this->create_menu( $case['post_args'], $case['meta'] );

			$this->assertSame( $case['expected'], $link->is_detail_viewable( $menu ), $case['test_condition_name'] );
		}
	}

	/**
	 * メニューループのカードの「詳細を見る」とタイトルのリンク先が、設定に応じて切り替わることを検証する。
	 */
	public function test_render_menu_card_links(): void {
		$menu       = $this->create_menu();
		$reserve    = home_url( '/booking/' );
		$detail_url = add_query_arg(
			array(
				'menu_id'   => (string) $menu->ID,
				'vkbm_view' => 'detail',
			),
			$reserve
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'block・自サイトの予約ページURL => 詳細を見るのリンクが詳細表示用URL',
				'mode'                => 'block',
				'reservation_url'     => $reserve,
				'expected_href'       => $detail_url,
			),
			array(
				'test_condition_name' => 'template => 詳細を見るのリンクが個別ページのパーマリンク',
				'mode'                => 'template',
				'reservation_url'     => $reserve,
				'expected_href'       => get_permalink( $menu ),
			),
			array(
				'test_condition_name' => 'block・予約ページURL未設定 => 詳細を見るのリンクが個別ページのパーマリンク',
				'mode'                => 'block',
				'reservation_url'     => '',
				'expected_href'       => get_permalink( $menu ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['mode'], $case['reservation_url'] );
			$block = new Menu_Loop_Block( new Settings_Repository() );

			$card_html = $block->render_menu_card( $menu->ID, array( 'disableTitleLink' => false ) );

			// 詳細を見るボタンとタイトルの両方が、同じリンク先を指す。
			$href = esc_url( $case['expected_href'] );
			$this->assertStringContainsString(
				'<a class="vkbm-menu-loop__button vkbm-button vkbm-button__primary" href="' . $href . '">',
				$card_html,
				$case['test_condition_name'] . '（詳細を見るボタン）'
			);
			// WP の版による実体参照の表記差を除き、タイトルのリンク先を厳密に比較する。
			$this->assertSame( 1, preg_match( '/<a href="([^"]+)">カット<\/a>/', $card_html, $matches ), $case['test_condition_name'] . '（タイトル）' );
			$this->assertDoesNotMatchRegularExpression(
				'/&(?!amp;|#038;)/',
				$matches[1],
				$case['test_condition_name'] . '（タイトルのリンク先に未エスケープの & がない）'
			);
			$this->assertSame(
				$case['expected_href'],
				html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ),
				$case['test_condition_name'] . '（タイトルのリンク先）'
			);
		}
	}

	/**
	 * 解決した予約ページURLのキャッシュと、clear_cache() / 設定変更での破棄を検証する。
	 */
	public function test_get_block_display_page_url_cache(): void {
		$first  = home_url( '/booking/' );
		$second = home_url( '/reserve/' );
		$this->save_settings( 'block', $first );
		$link = new Service_Menu_Detail_Link( new Settings_Repository() );

		$this->assertSame( $first, $link->get_block_display_page_url(), '初回は設定の値' );

		// 設定を直接書き換えても、同じインスタンスはキャッシュを返す（update_option を通さず DB だけ変えられないため、hook を外して検証）。
		remove_all_actions( 'update_option_' . Settings_Repository::OPTION_KEY );
		$this->save_settings( 'block', $second );
		$this->assertSame( $first, $link->get_block_display_page_url(), 'キャッシュを返す' );

		$link->clear_cache();
		$this->assertSame( $second, $link->get_block_display_page_url(), 'clear_cache() 後は新しい設定' );
	}

	/**
	 * 設定を保存すると、キャッシュが自動で破棄されることを検証する。
	 */
	public function test_clear_cache_on_option_update(): void {
		$this->save_settings( 'block', home_url( '/booking/' ) );
		$link = new Service_Menu_Detail_Link( new Settings_Repository() );
		$this->assertTrue( $link->is_block_display_enabled(), '前提: 有効' );

		$this->save_settings( 'template', home_url( '/booking/' ) );

		$this->assertFalse( $link->is_block_display_enabled(), '設定の保存でキャッシュが破棄される' );
	}

	/**
	 * resolve_usable_reservation_page_url() の判定を検証する。
	 */
	public function test_resolve_usable_reservation_page_url(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '空文字 => 空文字',
				'raw_url'             => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '自サイトの絶対URL => そのまま',
				'raw_url'             => home_url( '/booking/' ),
				'expected'            => home_url( '/booking/' ),
			),
			array(
				'test_condition_name' => '相対パス => 自サイトのURLに正規化',
				'raw_url'             => '/booking/',
				'expected'            => home_url( '/booking/' ),
			),
			array(
				'test_condition_name' => '自サイト外のURL => 空文字',
				'raw_url'             => 'https://other.example.org/booking/',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Menu_Detail_Link::resolve_usable_reservation_page_url( $case['raw_url'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_detail_url() が、追加のクエリ（引き継ぐ日付）を詳細表示用URLにだけ付けることを検証する。
	 */
	public function test_get_detail_url_with_extra_query(): void {
		$menu     = $this->create_menu();
		$reserve  = home_url( '/booking/' );
		$base_arg = array(
			'menu_id'   => (string) $menu->ID,
			'vkbm_view' => 'detail',
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'block・date と check_out_date => 詳細表示用URLに両方付く',
				'mode'                => 'block',
				'extra_query'         => array(
					'date'           => '2098-10-20',
					'check_out_date' => '2098-10-22',
				),
				'expected_url'        => add_query_arg(
					array_merge(
						$base_arg,
						array(
							'date'           => '2098-10-20',
							'check_out_date' => '2098-10-22',
						)
					),
					$reserve
				),
			),
			array(
				'test_condition_name' => 'block・空の値は付けない',
				'mode'                => 'block',
				'extra_query'         => array(
					'date'           => '2098-10-20',
					'check_out_date' => '',
				),
				'expected_url'        => add_query_arg( array_merge( $base_arg, array( 'date' => '2098-10-20' ) ), $reserve ),
			),
			array(
				'test_condition_name' => 'block・menu_id と vkbm_view は追加のクエリで上書きさせない',
				'mode'                => 'block',
				'extra_query'         => array(
					'menu_id'   => '999',
					'vkbm_view' => 'other',
				),
				'expected_url'        => add_query_arg( $base_arg, $reserve ),
			),
			array(
				'test_condition_name' => 'block・追加のクエリなし => 従来どおり',
				'mode'                => 'block',
				'extra_query'         => array(),
				'expected_url'        => add_query_arg( $base_arg, $reserve ),
			),
			array(
				'test_condition_name' => 'template => 個別ページのパーマリンクには付けない',
				'mode'                => 'template',
				'extra_query'         => array( 'date' => '2098-10-20' ),
				'expected_url'        => get_permalink( $menu ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_settings( $case['mode'], $reserve );
			$link = new Service_Menu_Detail_Link( new Settings_Repository() );

			$this->assertSame( $case['expected_url'], $link->get_detail_url( $menu, $case['extra_query'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * メニューループのカードの詳細リンクに、引き継ぐ日付（detailLinkQuery）が付くことを検証する。
	 */
	public function test_render_menu_card_links_carry_dates(): void {
		$menu    = $this->create_menu();
		$reserve = home_url( '/booking/' );
		$this->save_settings( 'block', $reserve );
		$block = new Menu_Loop_Block( new Settings_Repository() );

		$with_dates = $block->render_menu_card(
			$menu->ID,
			array(
				'disableTitleLink' => false,
				'detailLinkQuery'  => array( 'date' => '2098-10-20' ),
			)
		);
		$without    = $block->render_menu_card( $menu->ID, array( 'disableTitleLink' => false ) );

		// 詳細を見るボタンとタイトルの両方に日付が付き、日付なしのカードには付かない。
		$this->assertSame( 2, substr_count( $with_dates, 'date=2098-10-20' ), 'ボタンとタイトルの両方に日付が付く' );
		$this->assertStringNotContainsString( 'date=', $without, '日付を指定しないカードには付かない' );
	}

	/**
	 * メニューループのカードの詳細リンクに、日付以外のキーや不正な日付が付かないことを検証する。
	 */
	public function test_render_menu_card_links_ignore_unexpected_query(): void {
		$menu = $this->create_menu();
		$this->save_settings( 'block', home_url( '/booking/' ) );
		$block = new Menu_Loop_Block( new Settings_Repository() );

		$html = $block->render_menu_card(
			$menu->ID,
			array(
				'disableTitleLink' => false,
				'detailLinkQuery'  => array(
					'date'      => '2098-10-20',
					'draft'     => 'token',
					'vkbm_auth' => 'login',
				),
			)
		);
		$this->assertStringContainsString( 'date=2098-10-20', $html, '日付は付く' );
		$this->assertStringNotContainsString( 'draft=', $html, '日付以外のキーは付かない' );
		$this->assertStringNotContainsString( 'vkbm_auth=', $html, '日付以外のキーは付かない' );

		$invalid = $block->render_menu_card(
			$menu->ID,
			array(
				'disableTitleLink' => false,
				'detailLinkQuery'  => array( 'date' => '2098-02-31' ),
			)
		);
		$this->assertStringNotContainsString( 'date=', $invalid, '不正な日付は付かない' );
	}
}
