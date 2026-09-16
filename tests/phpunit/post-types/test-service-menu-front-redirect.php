<?php
/**
 * 詳細ページを使用しないサービスメニューのリダイレクト処理のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Front_Redirect;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use RuntimeException;
use WP_UnitTestCase;
use function add_filter;
use function add_query_arg;
use function do_action;
use function get_permalink;
use function get_post;
use function home_url;
use function is_preview;
use function is_singular;
use function remove_filter;
use function update_post_meta;
use function wp_parse_url;

/**
 * Service_Menu_Front_Redirect の挙動を保証するテスト。
 *
 * @group post-types
 */
class Service_Menu_Front_Redirect_Test extends WP_UnitTestCase {

	/**
	 * 投稿タイプの登録を保証する。
	 */
	protected function setUp(): void {
		parent::setUp();
		do_action( 'init' );
	}

	/**
	 * 各テスト後に基本設定オプションを掃除する。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 指定した値で基本設定オプションを保存する。
	 *
	 * 既定値とマージされるため、必要なキーだけ渡せばよい。
	 *
	 * @param array<string,mixed> $settings 保存する設定値。
	 * @return void
	 */
	private function set_settings( array $settings ): void {
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * リダイレクト処理ハンドラを生成する。
	 *
	 * @return Service_Menu_Front_Redirect
	 */
	private function make_handler(): Service_Menu_Front_Redirect {
		return new Service_Menu_Front_Redirect( new Settings_Repository() );
	}

	/**
	 * サービスメニュー投稿を1件作成する。
	 *
	 * @param array<string,mixed> $meta 付与するメタ。
	 * @return \WP_Post
	 */
	private function create_menu_post( array $meta = array() ): \WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_title'  => 'Sample Plan',
				'post_status' => 'publish',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return get_post( $post_id );
	}

	/**
	 * resolve_redirect_url() の判定ロジックを検証する。
	 *
	 * WordPress のグローバルな状態（クエリ・プレビュー判定）に依存しない純粋な
	 * 判定部分のみを対象とし、副作用（wp_safe_redirect + exit）は伴わない。
	 */
	public function test_resolve_redirect_url(): void {
		$handler = $this->make_handler();

		// 通常投稿（サービスメニュー以外）を1件作成し、投稿タイプの防御チェックを検証する。
		$other_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$other_post    = get_post( $other_post_id );

		// 予約ページURLがこのサービスメニュー自身のURLと同じケース用に、先に投稿を作成しておく
		// （URLはこの投稿の permalink に依存するため、ケース配列の外で用意する）。
		$self_loop_post      = $this->create_menu_post(
			array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' )
		);
		$self_loop_permalink = (string) get_permalink( $self_loop_post );

		// 自サイトのホストで許可される予約ページURL（正常系の基準値）。
		$same_host_url = home_url( '/reserve/' );

		// 自サイトと異なる外部ドメインのURL（許可されないホストの代表例）。
		$external_host_url = 'https://example.com/reserve/';

		// home_url() のホストに www. を付けた別ホストのURL（www有無違いも許可されないことの確認用）。
		$home_host       = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$www_variant_url = 'http://www.' . $home_host . '/reserve/';

		// サービスメニューは 'rewrite' => false のため、個別ページURLは常に
		// home_url( '/?vkbm_service_menu=slug' ) 形式になる。クエリを捨てて正規化すると
		// この個別ページURLは常にサイトのトップURLと一致してしまい、予約ページURLが
		// サイトのトップやページID指定だった場合に「自分自身と同じ」と誤判定して
		// リダイレクトされなくなる不具合があった（#453 安藤再レビュー対応）。
		$home_top_url     = home_url( '/' );
		$home_page_id_url = add_query_arg( 'page_id', 12, home_url( '/' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '詳細ページを使用する=ON・予約ページURL自サイトホストで設定 => リダイレクトしない（詳細ページをそのまま表示）',
				'reservation_url'     => $same_host_url,
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '1' ),
				'post'                => null,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURL自サイトホストで設定 => 予約ページのトップURLへリダイレクト',
				'reservation_url'     => $same_host_url,
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' ),
				'post'                => null,
				'expected'            => $same_host_url,
			),
			array(
				'test_condition_name' => '詳細ページを使用する=未設定（既定値）・予約ページURL自サイトホストで設定 => 予約ページのトップURLへリダイレクト',
				'reservation_url'     => $same_host_url,
				'meta'                => array(),
				'post'                => null,
				'expected'            => $same_host_url,
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURL未設定 => リダイレクトしない（遷移先が無い）',
				'reservation_url'     => '',
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' ),
				'post'                => null,
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'サービスメニュー以外の投稿タイプ => リダイレクトしない（防御チェック）',
				'reservation_url'     => $same_host_url,
				'meta'                => array(),
				'post'                => $other_post,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURLが自サイトと異なる外部ホスト => リダイレクトしない（許可されないホスト。#453レビュー対応）',
				'reservation_url'     => $external_host_url,
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' ),
				'post'                => null,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURLが自サイトホストのwww有無違い => リダイレクトしない（許可されないホスト。#453レビュー対応）',
				'reservation_url'     => $www_variant_url,
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' ),
				'post'                => null,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURLがこの投稿自身の個別ページURLと同じ => リダイレクトしない（無限リダイレクト防止。#453レビュー対応）',
				'reservation_url'     => $self_loop_permalink,
				'meta'                => null,
				'post'                => $self_loop_post,
				'expected'            => '',
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURLがサイトのトップURL（クエリなし） => 予約ページのトップURLへリダイレクト（無限リダイレクトと誤判定しない。#453安藤再レビュー対応）',
				'reservation_url'     => $home_top_url,
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' ),
				'post'                => null,
				'expected'            => $home_top_url,
			),
			array(
				'test_condition_name' => '詳細ページを使用する=OFF・予約ページURLがpage_idクエリ付きのURL => 予約ページのトップURLへリダイレクト（無限リダイレクトと誤判定しない。#453安藤再レビュー対応）',
				'reservation_url'     => $home_page_id_url,
				'meta'                => array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' ),
				'post'                => null,
				'expected'            => $home_page_id_url,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_settings( array( 'reservation_page_url' => $case['reservation_url'] ) );

			// 'post' が指定されているケースはそれを使い、無ければ 'meta' で新規作成する.
			$post = $case['post'] ?? $this->create_menu_post( $case['meta'] );

			$actual = $handler->resolve_redirect_url( $post );

			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );

			delete_option( Settings_Repository::OPTION_KEY );
		}
	}

	/**
	 * maybe_redirect() の実際の呼び出し結果（リダイレクトする／しない）を検証する。
	 *
	 * wp_safe_redirect() は内部で wp_redirect() を呼び、'wp_redirect' フィルターを通してから
	 * header() と exit() を実行する。このフィルターで例外を投げて exit() の手前で処理を止めることで、
	 * 従来 exit() のため検証できなかった「実際にリダイレクトするケース」も、遷移先URL・ステータス
	 * コードまで含めて検証できるようにしている。「リダイレクトしないケース」は例外が飛ばないこと
	 * （＝wp_redirect が呼ばれないこと）で検証する。
	 *
	 * あわせて、各ケースの go_to() 直後に is_singular() / is_preview() の前提条件を確認し、
	 * テストの土台（クエリの組み立て方）自体がずれていないことも保証する。
	 */
	public function test_maybe_redirect(): void {
		$handler = $this->make_handler();

		$same_host_url = home_url( '/reserve/' );
		$this->set_settings( array( 'reservation_page_url' => $same_host_url ) );

		// 詳細ページを使用する=ON の個別ページ.
		$detail_page_post = $this->create_menu_post(
			array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '1' )
		);

		// 詳細ページを使用する=OFF の個別ページ（プレビュー確認・実リダイレクト確認の両方で使う）.
		$redirect_post = $this->create_menu_post(
			array( Service_Menu_Front_Redirect::META_USE_DETAIL_PAGE => '' )
		);

		// 固定ページ（サービスメニュー以外）.
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name'  => '詳細ページを使用する=ON => リダイレクトしない',
				'go_to_url'            => get_permalink( $detail_page_post ),
				'expected_is_singular' => true,
				'expected_is_preview'  => false,
				'expect_redirect'      => false,
				'expected_status'      => null,
				'expected_location'    => null,
			),
			array(
				'test_condition_name'  => 'プレビュー表示 => リダイレクトしない',
				// rewrite 無効環境の permalink（?vkbm_service_menu=... 形式）と '?' が重複しないよう、
				// add_query_arg() でクエリを付与する（文字列連結だと "??" になり preview が解決されない）.
				'go_to_url'            => add_query_arg( 'preview', 'true', get_permalink( $redirect_post ) ),
				'expected_is_singular' => true,
				'expected_is_preview'  => true,
				'expect_redirect'      => false,
				'expected_status'      => null,
				'expected_location'    => null,
			),
			array(
				'test_condition_name'  => 'サービスメニュー以外の個別ページ => リダイレクトしない',
				'go_to_url'            => get_permalink( $page_id ),
				'expected_is_singular' => false,
				'expected_is_preview'  => false,
				'expect_redirect'      => false,
				'expected_status'      => null,
				'expected_location'    => null,
			),
			array(
				'test_condition_name'  => '詳細ページを使用する=OFF・予約ページURL自サイトホストで設定 => 302で予約ページへリダイレクト',
				'go_to_url'            => get_permalink( $redirect_post ),
				'expected_is_singular' => true,
				'expected_is_preview'  => false,
				'expect_redirect'      => true,
				'expected_status'      => 302,
				'expected_location'    => $same_host_url,
			),
		);

		// wp_redirect フィルターで実際の呼び出しを検知する（exit() の手前で例外を投げて止める）.
		$captured_call    = null;
		$capture_redirect = static function ( $location, $status ) use ( &$captured_call ) {
			$captured_call = array(
				'location' => $location,
				'status'   => $status,
			);
			throw new RuntimeException( 'wp_redirect intercepted for test' );
		};
		add_filter( 'wp_redirect', $capture_redirect, 10, 2 );

		try {
			foreach ( $test_cases as $case ) {
				$captured_call = null;
				$this->go_to( $case['go_to_url'] );

				// テストの前提条件（クエリの組み立てが意図どおりか）を確認する.
				$this->assertSame(
					$case['expected_is_singular'],
					is_singular( Service_Menu_Post_Type::POST_TYPE ),
					$case['test_condition_name'] . '（前提: is_singular の状態）'
				);
				$this->assertSame(
					$case['expected_is_preview'],
					is_preview(),
					$case['test_condition_name'] . '（前提: is_preview の状態）'
				);

				$exception = null;
				try {
					$handler->maybe_redirect();
				} catch ( RuntimeException $e ) {
					$exception = $e;
				}

				if ( $case['expect_redirect'] ) {
					$this->assertNotNull( $exception, $case['test_condition_name'] . '（wp_redirect が呼ばれること）' );
					$this->assertNotNull( $captured_call, $case['test_condition_name'] );
					$this->assertSame( $case['expected_status'], $captured_call['status'], $case['test_condition_name'] . '（ステータスコード）' );
					$this->assertSame( $case['expected_location'], $captured_call['location'], $case['test_condition_name'] . '（遷移先URL）' );
				} else {
					$this->assertNull( $exception, $case['test_condition_name'] . '（wp_redirect が呼ばれないこと）' );
					$this->assertNull( $captured_call, $case['test_condition_name'] );
				}
			}
		} finally {
			remove_filter( 'wp_redirect', $capture_redirect, 10 );
		}
	}
}
