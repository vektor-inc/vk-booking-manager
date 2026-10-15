<?php
/**
 * 予約ブロック内のサービスメニュー詳細表示（#635）をサーバー側で用意する処理のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Menu_Loop_Block;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\Blocks\Service_Menu_Detail_Renderer;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Service_Menu_Detail_Link;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_Post;
use WP_UnitTestCase;

/**
 * Service_Menu_Detail_Renderer の要求判定・公開範囲・本文埋め込みを検証するテスト。
 *
 * @group blocks
 */
class Service_Menu_Detail_Renderer_Test extends WP_UnitTestCase {

	/**
	 * 投稿タイプの登録と、クエリ・ログイン状態の初期化を行う。
	 */
	protected function setUp(): void {
		parent::setUp();
		do_action( 'init' );
		$_GET = array();
		wp_set_current_user( 0 );
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'service_menu_detail_display_mode' => 'block',
				'reservation_page_url'             => home_url( '/booking/' ),
			)
		);
	}

	/**
	 * 書き換えたグローバル状態と基本設定を元へ戻す。
	 */
	public function tear_down(): void {
		$_GET = array();
		wp_set_current_user( 0 );
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * テスト対象を生成する。
	 *
	 * @return Service_Menu_Detail_Renderer
	 */
	private function make_renderer(): Service_Menu_Detail_Renderer {
		$repository = new Settings_Repository();
		$link       = new Service_Menu_Detail_Link( $repository );

		return new Service_Menu_Detail_Renderer( $link, new Menu_Loop_Block( $repository, null, $link ) );
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
					'post_type'    => Service_Menu_Post_Type::POST_TYPE,
					'post_title'   => 'カット',
					'post_content' => '<!-- wp:paragraph --><p>本文の段落です。</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
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
	 * 詳細ソースが、対象メニューを編集できる利用者にだけ編集リンクを渡すことを検証する。
	 */
	public function test_build_source_markup_edit_link(): void {
		$menu       = $this->create_menu();
		$_GET       = array( 'vkbm_view' => 'detail', 'menu_id' => (string) $menu->ID );
		$test_cases = array(
			array(
				'test_condition_name' => '管理者 => 見出しとカードの編集URLを出す',
				'role'                => 'administrator',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'メニュー管理権限を付与した購読者 => 編集URLを出す',
				'role'                => 'subscriber',
				'grant'               => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '購読者 => 編集URLを出さない',
				'role'                => 'subscriber',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログイン => 編集URLを出さない',
				'role'                => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// 利用者を切り替えてサーバーが埋め込むソース全体を検証する。
			$user_id = $case['role'] ? self::factory()->user->create( array( 'role' => $case['role'] ) ) : 0;
			if ( ! empty( $case['grant'] ) ) {
				get_user_by( 'id', $user_id )->add_cap( Capabilities::MANAGE_SERVICE_MENUS );
			}
			wp_set_current_user( $user_id );
			$markup = $this->make_renderer()->build_source_markup();
			if ( $case['expected'] ) {
				$this->assertSame( 2, substr_count( $markup, 'class="vkbm-menu-loop__edit-link"' ), $case['test_condition_name'] );
				$this->assertStringContainsString( esc_url( get_edit_post_link( $menu->ID, '' ) ), $markup, $case['test_condition_name'] );
				$this->assertStringContainsString( '<div class="vkbm-reservation-detail-source__edit-link"><span class="vkbm-menu-loop__edit-link">', $markup, $case['test_condition_name'] );
			} else {
				$this->assertStringNotContainsString( 'class="vkbm-menu-loop__edit-link"', $markup, $case['test_condition_name'] );
				$this->assertStringNotContainsString( 'post.php?', $markup, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * is_detail_request() が、vkbm_view=detail と block 有効の両方を満たすときだけ true になることを検証する。
	 */
	public function test_is_detail_request(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'vkbm_view=detail・block有効 => 詳細表示の要求',
				'get'                 => array( 'vkbm_view' => 'detail' ),
				'mode'                => 'block',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'vkbm_view が無い => 詳細表示の要求ではない',
				'get'                 => array( 'menu_id' => '1' ),
				'mode'                => 'block',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'vkbm_view が detail 以外 => 詳細表示の要求ではない',
				'get'                 => array( 'vkbm_view' => 'list' ),
				'mode'                => 'block',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'vkbm_view=detail でも template => 詳細表示の要求ではない（従来の予約画面）',
				'get'                 => array( 'vkbm_view' => 'detail' ),
				'mode'                => 'template',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option(
				Settings_Repository::OPTION_KEY,
				array(
					'service_menu_detail_display_mode' => $case['mode'],
					'reservation_page_url'             => home_url( '/booking/' ),
				)
			);
			$_GET = $case['get'];

			$this->assertSame( $case['expected'], $this->make_renderer()->is_detail_request(), $case['test_condition_name'] );
		}
	}

	/**
	 * build_source_markup() が、公開範囲に応じて本文入りの埋め込み・案内用の印・空文字を返すことを検証する。
	 */
	public function test_build_source_markup(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '公開・詳細ページON => 本文とカードを含む埋め込み',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'menu_id_param'       => 'created',
				'expected_status'     => 'ok',
				'expected_content'    => true,
			),
			array(
				'test_condition_name' => '詳細ページOFF => 案内用の印だけ（本文は出さない）',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '' ),
				'user_id'             => 0,
				'menu_id_param'       => 'created',
				'expected_status'     => 'unavailable',
				'expected_content'    => false,
			),
			array(
				'test_condition_name' => '非公開・権限なし => 案内用の印だけ',
				'post_args'           => array( 'post_status' => 'private' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'menu_id_param'       => 'created',
				'expected_status'     => 'unavailable',
				'expected_content'    => false,
			),
			array(
				'test_condition_name' => '非公開・権限あり => 本文を含む埋め込み',
				'post_args'           => array( 'post_status' => 'private' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => $administrator_id,
				'menu_id_param'       => 'created',
				'expected_status'     => 'ok',
				'expected_content'    => true,
			),
			array(
				'test_condition_name' => '下書き => 権限があっても案内用の印だけ',
				'post_args'           => array( 'post_status' => 'draft' ),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => $administrator_id,
				'menu_id_param'       => 'created',
				'expected_status'     => 'unavailable',
				'expected_content'    => false,
			),
			array(
				'test_condition_name' => '存在しないID => 案内用の印だけ',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'menu_id_param'       => '99999999',
				'expected_status'     => 'unavailable',
				'expected_content'    => false,
			),
			array(
				'test_condition_name' => 'menu_id が無い => 案内用の印だけ',
				'post_args'           => array(),
				'meta'                => array( '_vkbm_use_detail_page' => '1' ),
				'user_id'             => 0,
				'menu_id_param'       => '',
				'expected_status'     => 'unavailable',
				'expected_content'    => false,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['user_id'] );
			$menu = $this->create_menu( $case['post_args'], $case['meta'] );

			$menu_id = 'created' === $case['menu_id_param'] ? (string) $menu->ID : $case['menu_id_param'];
			$_GET    = array(
				'vkbm_view' => 'detail',
				'menu_id'   => $menu_id,
			);

			$markup = $this->make_renderer()->build_source_markup();

			$this->assertStringContainsString( 'data-vkbm-detail-status="' . $case['expected_status'] . '"', $markup, $case['test_condition_name'] );
			$this->assertSame( $case['expected_content'], false !== strpos( $markup, '本文の段落です。' ), $case['test_condition_name'] . '（本文）' );
			$this->assertSame( $case['expected_content'], false !== strpos( $markup, 'vkbm-menu-loop__card-item' ), $case['test_condition_name'] . '（カード）' );
		}
	}

	/**
	 * 詳細表示の要求でないときは、何も埋め込まないことを検証する。
	 */
	public function test_build_source_markup_without_detail_request(): void {
		$menu = $this->create_menu();
		$_GET = array( 'menu_id' => (string) $menu->ID );

		$this->assertSame( '', $this->make_renderer()->build_source_markup(), 'vkbm_view が無いときは空文字' );
	}

	/**
	 * 埋め込むカードに「詳細を見る」とタイトルのリンクが無く、予約に進むボタンはあることを検証する。
	 */
	public function test_build_source_markup_card(): void {
		$menu = $this->create_menu();
		$_GET = array(
			'vkbm_view' => 'detail',
			'menu_id'   => (string) $menu->ID,
		);

		$markup = $this->make_renderer()->build_source_markup();

		$this->assertStringNotContainsString( 'vkbm-button__primary" href', $markup, '詳細を見るボタンを出さない' );
		$this->assertStringNotContainsString( '<h3 class="vkbm-menu-loop__card-title"><a ', $markup, 'タイトルをリンクにしない' );
		$this->assertStringContainsString( 'vkbm-menu-loop__button--reserve', $markup, '予約に進むボタンは出す' );
		$this->assertStringContainsString( 'data-vkbm-detail-title="カット"', $markup, 'メニュー名を埋め込む' );
	}

	/**
	 * 本文の組み立て中に表示中の投稿がメニューへ差し替わり、終わったら元へ戻ることを検証する。
	 */
	public function test_build_source_markup_restores_global_post(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => '予約ページ',
			)
		);
		$page    = get_post( $page_id );

		$GLOBALS['post'] = $page;
		setup_postdata( $page );

		// 本文の中から、そのとき表示中の投稿のタイトルを読み取るフィルターで差し替えを確認する。
		$seen_title = '';
		$callback   = static function ( $content ) use ( &$seen_title ) {
			$current    = get_post();
			$seen_title = $current instanceof WP_Post ? $current->post_title : '';
			return $content;
		};
		add_filter( 'the_content', $callback, 5 );

		$menu = $this->create_menu();
		$_GET = array(
			'vkbm_view' => 'detail',
			'menu_id'   => (string) $menu->ID,
		);
		$this->make_renderer()->build_source_markup();

		remove_filter( 'the_content', $callback, 5 );

		$this->assertSame( 'カット', $seen_title, '本文の処理中は表示中の投稿がメニューになる' );
		$this->assertSame( $page_id, get_post()->ID, '処理後は元の投稿へ戻る' );
	}

	/**
	 * 本文の組み立て後、setup_postdata() が書き換えるグローバル変数が元の状態（無かったものは無い状態）へ戻ることを検証する。
	 */
	public function test_build_source_markup_restores_post_globals(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '表示中の投稿が無い状態（post が null） => 実行後も post・id・pages は無い／null のまま',
				'has_previous_post'   => false,
			),
			array(
				'test_condition_name' => '表示中の投稿がある状態 => 実行後も同じ投稿・同じ id・同じ pages',
				'has_previous_post'   => true,
			),
		);

		foreach ( $test_cases as $case ) {
			unset( $GLOBALS['post'], $GLOBALS['id'], $GLOBALS['pages'], $GLOBALS['page'], $GLOBALS['more'] );
			$page_id = 0;
			if ( $case['has_previous_post'] ) {
				$page_id         = self::factory()->post->create( array( 'post_type' => 'page' ) );
				$GLOBALS['post'] = get_post( $page_id );
				setup_postdata( $GLOBALS['post'] );
			}
			$before_pages = $GLOBALS['pages'] ?? null;

			$menu = $this->create_menu();
			$_GET = array(
				'vkbm_view' => 'detail',
				'menu_id'   => (string) $menu->ID,
			);
			$this->make_renderer()->build_source_markup();

			if ( $case['has_previous_post'] ) {
				$this->assertSame( $page_id, $GLOBALS['post']->ID, $case['test_condition_name'] );
				$this->assertSame( $page_id, $GLOBALS['id'], $case['test_condition_name'] . '（id）' );
				$this->assertSame( $before_pages, $GLOBALS['pages'], $case['test_condition_name'] . '（pages）' );
			} else {
				$this->assertArrayNotHasKey( 'post', $GLOBALS, $case['test_condition_name'] );
				$this->assertArrayNotHasKey( 'id', $GLOBALS, $case['test_condition_name'] . '（id）' );
				$this->assertArrayNotHasKey( 'pages', $GLOBALS, $case['test_condition_name'] . '（pages）' );
			}
		}
	}

	/**
	 * 非公開メニューのメニュー名に「非公開:」が付かないこと（本文・カードの処理が終わると元のフィルターへ戻ること）を検証する。
	 */
	public function test_build_source_markup_private_title(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu = $this->create_menu( array( 'post_status' => 'private' ) );
		$_GET = array(
			'vkbm_view' => 'detail',
			'menu_id'   => (string) $menu->ID,
		);

		$markup = $this->make_renderer()->build_source_markup();

		$this->assertStringContainsString( 'data-vkbm-detail-title="カット"', $markup, 'メニュー名に非公開の接頭辞を付けない' );
		$this->assertStringNotContainsString( 'Private:', $markup, 'カードにも非公開の接頭辞を付けない' );
		$this->assertStringNotContainsString( '非公開:', $markup, 'カードにも非公開の接頭辞を付けない（日本語）' );
		$this->assertNotSame( 'カット', get_the_title( $menu ), '処理後は通常のタイトル表示（接頭辞付き）へ戻る' );
	}

	/**
	 * 予約ブロックが複数あっても、詳細表示の本文は1リクエストで1回だけ埋め込まれることを検証する。
	 */
	public function test_inject_service_menu_detail_markup(): void {
		$menu = $this->create_menu();
		$_GET = array(
			'vkbm_view' => 'detail',
			'menu_id'   => (string) $menu->ID,
		);
		Reservation_Block::reset_detail_embedded_flag();
		$block_instance = new Reservation_Block(
			new Auth_Shortcodes( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) ),
			$this->make_renderer()
		);
		$wrapper        = '<div class="wp-block-vk-booking-manager-reservation vkbm-reservation-block"></div>';
		$block          = array( 'blockName' => 'vk-booking-manager/reservation' );

		$first  = $block_instance->inject_service_menu_detail_markup( $wrapper, $block );
		$second = $block_instance->inject_service_menu_detail_markup( $wrapper, $block );
		Reservation_Block::reset_detail_embedded_flag();

		$this->assertStringContainsString( '本文の段落です。', $first, '1つ目の予約ブロックには埋め込む' );
		$this->assertSame( $wrapper, $second, '2つ目以降の予約ブロックには埋め込まない' );
	}
}
