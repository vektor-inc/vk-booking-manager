<?php
/**
 * 投稿複製の権限と保存内容を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Post_Duplicator;
use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * 管理画面の投稿複製テスト。
 */
class Post_Duplicator_Test extends WP_UnitTestCase {
	/**
	 * 両投稿タイプの本文・メタ・分類を下書きへ複製することを確認する。
	 *
	 * @return void
	 */
	public function test_duplicate_post(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$duplicator = new Post_Duplicator();
		register_taxonomy( 'vkbm_duplicate_test', array( Service_Menu_Post_Type::POST_TYPE, Resource_Post_Type::POST_TYPE ) );
		$term = wp_insert_term( '複製テスト', 'vkbm_duplicate_test' );

		foreach ( array( Service_Menu_Post_Type::POST_TYPE, Resource_Post_Type::POST_TYPE ) as $post_type ) {
			// 元投稿に複数値のメタと分類を設定する。
			$source_id = $this->factory()->post->create(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => '原本',
					'post_content' => '本文',
				)
			);
			add_post_meta( $source_id, '_vkbm_test', '一つ目' );
			add_post_meta( $source_id, '_vkbm_test', '二つ目' );
			// 数字だけのメタキーは全件取得時に整数の配列キーになる。
			add_post_meta( $source_id, '123', '数値キーの値' );
			$this->assertArrayHasKey( 123, get_post_meta( $source_id ) );
			add_post_meta( $source_id, '_edit_lock', '123:1' );
			add_post_meta( $source_id, '_wp_trash_meta_status', 'publish' );
			add_post_meta( $source_id, '_wp_trash_meta_time', '123' );
			add_post_meta( $source_id, '_vkbm_external_calendars', array( array( 'url' => 'https://example.test/calendar.ics' ) ) );
			add_post_meta( $source_id, '_vkbm_external_calendar_status', array( 'state' => 'error' ) );
			wp_set_object_terms( $source_id, (int) $term['term_id'], 'vkbm_duplicate_test' );

			$copy_id = $duplicator->duplicate_post( $source_id );
			$this->assertIsInt( $copy_id );
			$this->assertSame( 'draft', get_post_status( $copy_id ) );
			$this->assertSame( $post_type, get_post_type( $copy_id ) );
			$this->assertSame( '原本', get_post( $copy_id )->post_title );
			$this->assertSame( '本文', get_post( $copy_id )->post_content );
			$this->assertSame( array( '一つ目', '二つ目' ), get_post_meta( $copy_id, '_vkbm_test', false ) );
			$this->assertSame( '数値キーの値', get_post_meta( $copy_id, '123', true ) );
			$this->assertSame( '', get_post_meta( $copy_id, '_edit_lock', true ) );
			$this->assertSame( '', get_post_meta( $copy_id, '_wp_trash_meta_status', true ) );
			$this->assertSame( '', get_post_meta( $copy_id, '_wp_trash_meta_time', true ) );
			$this->assertSame( array( array( 'url' => 'https://example.test/calendar.ics' ) ), get_post_meta( $copy_id, '_vkbm_external_calendars', true ) );
			$this->assertSame( '', get_post_meta( $copy_id, '_vkbm_external_calendar_status', true ) );
			$this->assertSame( array( (int) $term['term_id'] ), wp_get_object_terms( $copy_id, 'vkbm_duplicate_test', array( 'fields' => 'ids' ) ) );
			$this->assertSame( 'publish', get_post_status( $source_id ) );
		}
		unregister_taxonomy( 'vkbm_duplicate_test' );
	}

	/**
	 * 対象外の投稿と権限のない利用者を拒否することを確認する。
	 *
	 * @return void
	 */
	public function test_can_duplicate(): void {
		// 行アクションは投稿一覧（template.php 読み込み済み）でだけ呼ばれる。テストでは自分で読み込む。
		if ( ! function_exists( '_draft_or_post_title' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$admin_id      = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$duplicator    = new Post_Duplicator();
		$menu_id       = $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$page_id       = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		$cases         = array(
			array(
				'user'     => $admin_id,
				'post'     => $menu_id,
				'expected' => true,
			),
			array(
				'user'     => $admin_id,
				'post'     => $page_id,
				'expected' => false,
			),
			array(
				'user'     => $subscriber_id,
				'post'     => $menu_id,
				'expected' => false,
			),
		);
		foreach ( $cases as $case ) {
			wp_set_current_user( $case['user'] );
			$this->assertSame( $case['expected'], (bool) $duplicator->can_duplicate( get_post( $case['post'] ) ) );
			$this->assertSame( $case['expected'], isset( $duplicator->add_row_action( array(), get_post( $case['post'] ) )['vkbm_duplicate'] ) );
			if ( $case['expected'] ) {
				$this->assertStringContainsString( 'aria-label="', $duplicator->add_row_action( array(), get_post( $case['post'] ) )['vkbm_duplicate'] );
			}
		}
		// タイトルが空の投稿は、コアの行アクションと同じく「(タイトルなし)」を読み上げ用の名前に使う。
		wp_set_current_user( $admin_id );
		$untitled = get_post(
			$this->factory()->post->create(
				array(
					'post_type'  => Service_Menu_Post_Type::POST_TYPE,
					'post_title' => '',
				)
			)
		);
		$link     = $duplicator->add_row_action( array(), $untitled )['vkbm_duplicate'];
		$this->assertNotSame( '', _draft_or_post_title( $untitled ) );
		/* translators: %s: 投稿タイトル。 */
		$this->assertStringContainsString( 'aria-label="' . esc_attr( sprintf( __( 'Duplicate “%s”', 'vk-booking-manager' ), _draft_or_post_title( $untitled ) ) ) . '"', $link );
		$this->assertStringNotContainsString( '“”', $link );
		wp_set_current_user( $subscriber_id );
		$this->assertWPError( $duplicator->duplicate_post( $menu_id ) );
	}

	/**
	 * メタの保存に失敗した下書きを削除する。
	 *
	 * @return void
	 */
	public function test_duplicate_post_cleans_up_failed_meta(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$source_id = $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );
		add_post_meta( $source_id, '_vkbm_test_fail', 'value' );
		$created_id = 0;
		$track      = function ( $post_id, $post ) use ( $source_id, &$created_id ) {
			if ( $source_id !== $post_id && Resource_Post_Type::POST_TYPE === $post->post_type ) {
				$created_id = $post_id;
			}
		};
		$fail       = function ( $check, $post_id, $key ) use ( &$created_id ) {
			return $created_id === $post_id && '_vkbm_test_fail' === $key ? false : $check;
		};
		add_action( 'save_post', $track, 10, 2 );
		add_filter( 'add_post_metadata', $fail, 10, 3 );
		$result = ( new Post_Duplicator() )->duplicate_post( $source_id );
		remove_filter( 'add_post_metadata', $fail, 10 );
		remove_action( 'save_post', $track, 10 );
		$this->assertWPError( $result );
		$this->assertSame( 'vkbm_duplicate_meta_failed', $result->get_error_code() );
		$this->assertGreaterThan( 0, $created_id );
		$this->assertNull( get_post( $created_id ) );
		$this->assertNotNull( get_post( $source_id ) );
	}

	/**
	 * 不正な要求と権限不足を 403 で終了する。
	 *
	 * @return void
	 */
	public function test_handle_action_rejects_invalid_request(): void {
		$admin_id     = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$source_id    = $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力を保持。
		$cases        = array(
			array(
				'user'  => $admin_id,
				'query' => array(),
			),
			array(
				'user'  => $admin_id,
				'query' => array(
					'post'     => $source_id,
					'_wpnonce' => 'invalid',
				),
			),
			array(
				'user'  => $this->factory()->user->create( array( 'role' => 'subscriber' ) ),
				'query' => array(
					'post'     => $source_id,
					'_wpnonce' => wp_create_nonce( 'vkbm_duplicate_post_' . $source_id ),
				),
			),
		);
		$response     = null;
		$back_link    = null;
		$handler      = function () use ( &$response, &$back_link ) {
			return function ( $message, $title, $args ) use ( &$response, &$back_link ) {
				$response  = $args['response'];
				$back_link = $args['back_link'] ?? null;
				throw new \RuntimeException( 'Stopped by test.' );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		try {
			foreach ( $cases as $case ) {
				wp_set_current_user( $case['user'] );
				$_GET     = $case['query']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。
				$response = null;
				try {
					( new Post_Duplicator() )->handle_action();
				} catch ( \RuntimeException $exception ) {
					$this->assertSame( 'Stopped by test.', $exception->getMessage() );
				}
				$this->assertSame( 403, $response );
				// 一覧へ戻れるよう、戻るリンクを付ける。
				$this->assertTrue( $back_link );
			}
		} finally {
			remove_filter( 'wp_die_handler', $handler );
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力を復元。
		}
	}

	/**
	 * 下書き作成に失敗した要求を 500 で終了する。
	 *
	 * @return void
	 */
	public function test_handle_action_reports_server_failure(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$source_id    = $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力を保持。
		$_GET         = array(
			'post'     => $source_id,
			'_wpnonce' => wp_create_nonce( 'vkbm_duplicate_post_' . $source_id ),
		); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。
		$fail         = function () {
			return true;
		};
		$response     = null;
		$back_link    = null;
		$handler      = function () use ( &$response, &$back_link ) {
			return function ( $message, $title, $args ) use ( &$response, &$back_link ) {
				$response  = $args['response'];
				$back_link = $args['back_link'] ?? null;
				throw new \RuntimeException( 'Stopped by test.' );
			};
		};
		add_filter( 'wp_insert_post_empty_content', $fail );
		add_filter( 'wp_die_handler', $handler );
		try {
			try {
				( new Post_Duplicator() )->handle_action();
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Stopped by test.', $exception->getMessage() );
			}
		} finally {
			remove_filter( 'wp_die_handler', $handler );
			remove_filter( 'wp_insert_post_empty_content', $fail );
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力を復元。
		}
		$this->assertSame( 500, $response );
		$this->assertTrue( $back_link );
	}

	/**
	 * 成功時は新しい下書きの編集画面へ遷移し、通知用トークンを付ける（サービスメニューとリソース）。
	 *
	 * @return void
	 */
	public function test_handle_action_redirects_to_draft(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$cases        = array(
			array(
				'test_condition_name' => 'サービスメニューを複製 => 新しい下書きの編集画面へ移動する',
				'post_type'           => Service_Menu_Post_Type::POST_TYPE,
				'skip_in_free'        => false,
			),
			array(
				'test_condition_name' => 'リソースを複製 => 新しい下書きの編集画面へ移動する',
				'post_type'           => Resource_Post_Type::POST_TYPE,
				// 無料版のリソースは show_ui が false のため、コアが編集画面の URL（_edit_link）を空にし、遷移先に投稿 ID が付かない。
				// 一覧画面も開けず複製リンクが出ない（複製の導線が無い）ため、無料版ではこのケースを確かめない。
				'skip_in_free'        => true,
			),
		);
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力を保持。
		try {
			foreach ( $cases as $case ) {
				if ( $case['skip_in_free'] && Pro_Upsell::is_free_edition() ) {
					continue;
				}

				// 複製元を作り、正しいトークン付きの複製要求にする。
				$source_id = $this->factory()->post->create( array( 'post_type' => $case['post_type'] ) );
				$_GET      = array(
					'post'     => $source_id,
					'_wpnonce' => wp_create_nonce( 'vkbm_duplicate_post_' . $source_id ),
				); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。

				// リダイレクトを横取りして遷移先の URL を受け取る。
				$location  = '';
				$intercept = function ( $url ) use ( &$location ) {
					$location = $url;
					throw new \RuntimeException( 'Redirect intercepted.' );
				};
				add_filter( 'wp_redirect', $intercept );
				try {
					( new Post_Duplicator() )->handle_action();
				} catch ( \RuntimeException $exception ) {
					$this->assertSame( 'Redirect intercepted.', $exception->getMessage(), $case['test_condition_name'] );
				} finally {
					remove_filter( 'wp_redirect', $intercept );
				}

				// 遷移先は新しい下書きの編集画面で、通知用トークンが付いている。
				parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
				$this->assertArrayHasKey( 'post', $query, $case['test_condition_name'] );
				$this->assertSame( 'draft', get_post_status( (int) $query['post'] ), $case['test_condition_name'] );
				$this->assertSame( $case['post_type'], get_post_type( (int) $query['post'] ), $case['test_condition_name'] );
				$this->assertNotSame( $source_id, (int) $query['post'], $case['test_condition_name'] );
				$this->assertSame( 'edit', $query['action'], $case['test_condition_name'] );
				$this->assertSame( 1, wp_verify_nonce( $query['vkbm_duplicated'], 'vkbm_duplicate_notice_' . $query['post'] ), $case['test_condition_name'] );
			}
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力を復元。
		}
	}

	/**
	 * 複製先の正しい通知トークンだけが成功通知を表示する。
	 *
	 * @return void
	 */
	public function test_render_duplicate_notice(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id      = $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力を保持。
		$screen       = get_current_screen();
		set_current_screen( 'post' );
		$cases = array(
			array(
				'nonce'    => wp_create_nonce( 'vkbm_duplicate_notice_' . $post_id ),
				'expected' => true,
			),
			array(
				'nonce'    => 'invalid',
				'expected' => false,
			),
			// ブロックエディタの画面では admin_notices の出力が見えないため出さない（core/notices 側で出す）。
			array(
				'nonce'        => wp_create_nonce( 'vkbm_duplicate_notice_' . $post_id ),
				'block_editor' => true,
				'expected'     => false,
			),
		);
		try {
			foreach ( $cases as $case ) {
				get_current_screen()->is_block_editor( ! empty( $case['block_editor'] ) );
				$_GET = array(
					'post'            => $post_id,
					'vkbm_duplicated' => $case['nonce'],
				); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。
				ob_start();
				( new Post_Duplicator() )->render_duplicate_notice();
				$output = ob_get_clean();
				$this->assertSame( $case['expected'], (bool) preg_match( '/notice-success/', $output ) );
				if ( true === $case['expected'] ) {
					$this->assertStringContainsString( esc_html__( 'A copy was created as a draft. Review it before publishing.', 'vk-booking-manager' ), $output );
				}
			}
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力を復元。
			set_current_screen( $screen ? $screen->id : 'front' );
		}
	}

	/**
	 * 通知の文言は、複製直後の下書き（対象投稿タイプ・正しいトークン・編集権限）でだけ返す。
	 *
	 * @return void
	 */
	public function test_get_duplicate_notice_message(): void {
		$admin_id      = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$message       = __( 'A copy was created as a draft. Review it before publishing.', 'vk-booking-manager' );
		$menu_draft    = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$resource      = $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$menu_publish  = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$post_draft    = $this->factory()->post->create( array( 'post_status' => 'draft' ) );
		$cases         = array(
			array(
				'name'     => 'サービスメニューの下書きでは文言を返す',
				'user'     => $admin_id,
				'post'     => $menu_draft,
				'expected' => $message,
			),
			array(
				'name'     => 'リソースの下書きでは文言を返す',
				'user'     => $admin_id,
				'post'     => $resource,
				'expected' => $message,
			),
			array(
				'name'     => '公開済みの投稿では返さない',
				'user'     => $admin_id,
				'post'     => $menu_publish,
				'expected' => '',
			),
			array(
				'name'     => '対象外の投稿タイプでは返さない',
				'user'     => $admin_id,
				'post'     => $post_draft,
				'expected' => '',
			),
			array(
				'name'     => '編集権限が無い利用者には返さない',
				'user'     => $subscriber_id,
				'post'     => $menu_draft,
				'expected' => '',
			),
			array(
				'name'     => 'トークンが違うときは返さない',
				'user'     => $admin_id,
				'post'     => $menu_draft,
				'nonce'    => 'invalid',
				'expected' => '',
			),
			array(
				'name'     => 'トークンが無いときは返さない',
				'user'     => $admin_id,
				'post'     => $menu_draft,
				'nonce'    => null,
				'expected' => '',
			),
		);
		$original_get  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力を保持。
		try {
			foreach ( $cases as $case ) {
				wp_set_current_user( $case['user'] );
				$_GET  = array( 'post' => $case['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。
				$nonce = array_key_exists( 'nonce', $case ) ? $case['nonce'] : wp_create_nonce( 'vkbm_duplicate_notice_' . $case['post'] );
				if ( null !== $nonce ) {
					$_GET['vkbm_duplicated'] = $nonce; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。
				}
				$this->assertSame( $case['expected'], Post_Duplicator::get_duplicate_notice_message(), $case['name'] );
			}
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力を復元。
		}
	}

	/**
	 * サービスメニューのブロックエディタでは、複製直後だけ通知の文言を core/notices 用のスクリプトへ渡す。
	 *
	 * @return void
	 */
	public function test_service_menu_block_editor_passes_duplicate_message(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id          = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$message          = __( 'A copy was created as a draft. Review it before publishing.', 'vk-booking-manager' );
		$original_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト前の入力を保持。
		$original_post    = $GLOBALS['post'] ?? null;
		$original_scripts = $GLOBALS['wp_scripts'] ?? null;
		$original_styles  = $GLOBALS['wp_styles'] ?? null;
		$screen           = get_current_screen();
		$cases            = array(
			array(
				'name'     => '複製直後は文言を渡す',
				'nonce'    => wp_create_nonce( 'vkbm_duplicate_notice_' . $post_id ),
				'expected' => $message,
			),
			array(
				'name'     => 'トークンが違うときは空の文言を渡す',
				'nonce'    => 'invalid',
				'expected' => '',
			),
		);
		try {
			$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで差し替え、終了時に戻す。
			foreach ( $cases as $case ) {
				unset( $GLOBALS['wp_scripts'], $GLOBALS['wp_styles'] );
				set_current_screen( Service_Menu_Post_Type::POST_TYPE );
				get_current_screen()->post_type = Service_Menu_Post_Type::POST_TYPE;
				get_current_screen()->is_block_editor( true );
				$_GET = array(
					'post'            => $post_id,
					'vkbm_duplicated' => $case['nonce'],
				); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力。

				( new Service_Menu_Editor() )->enqueue_assets( 'post.php' );

				$this->assertTrue( wp_script_is( 'vkbm-service-menu-editor-notices', 'enqueued' ), $case['name'] );
				$data = (string) wp_scripts()->get_data( 'vkbm-service-menu-editor-notices', 'data' );
				$this->assertSame( 1, preg_match( '/var vkbmServiceMenuNotices = (\{.*\});/', $data, $matches ), $case['name'] );
				$config = json_decode( $matches[1], true );
				$this->assertSame( $case['expected'], $config['duplicatedMessage'], $case['name'] );
			}
		} finally {
			$_GET                  = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト入力を復元。
			$GLOBALS['post']       = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで差し替え、終了時に戻す。
			$GLOBALS['wp_scripts'] = $original_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで差し替え、終了時に戻す。
			$GLOBALS['wp_styles']  = $original_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストで差し替え、終了時に戻す。
			set_current_screen( $screen ? $screen->id : 'front' );
		}
	}
}
