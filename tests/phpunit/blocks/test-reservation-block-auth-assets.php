<?php
/**
 * issue #707: 認証フォーム用の CSS/JS を、認証フォームを使うページだけで読み込むことのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use VKBookingManager\Assets\Common_Styles;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Blocks\Reservation_Block;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

/**
 * 認証フォーム用アセットの読み込み条件のテスト。
 *
 * @group blocks
 */
class Reservation_Block_Auth_Assets_Test extends WP_UnitTestCase {
	/**
	 * register() が登録するショートコード名。
	 */
	private const SHORTCODE_NAMES = array( 'vkbm_login_form', 'vkbm_register_form' );

	/**
	 * テスト前のショートコードのコールバック（未登録なら null）。
	 *
	 * @var array<string, callable|null>
	 */
	private $original_shortcodes = array();

	/**
	 * 前のテストで読み込まれた状態を持ち越さないよう、認証フォーム用アセットを外しておく。
	 */
	protected function setUp(): void {
		parent::setUp();

		// テスト内で張り替えるショートコードを、後片付けで元に戻すため控えておく。
		foreach ( self::SHORTCODE_NAMES as $name ) {
			$this->original_shortcodes[ $name ] = $GLOBALS['shortcode_tags'][ $name ] ?? null;
		}

		$this->reset_auth_assets();
	}

	/**
	 * 後続のテストへ読み込み状態を持ち越さない。
	 */
	protected function tearDown(): void {
		$this->reset_auth_assets();
		delete_option( 'users_can_register' );

		// ショートコードの登録を、テスト前の状態へ戻す。
		foreach ( $this->original_shortcodes as $name => $callback ) {
			if ( null === $callback ) {
				remove_shortcode( $name );
			} else {
				add_shortcode( $name, $callback );
			}
		}

		parent::tearDown();
	}

	/**
	 * 認証フォーム用の CSS/JS を読み込み待ちから外す。
	 */
	private function reset_auth_assets(): void {
		wp_dequeue_style( Common_Styles::AUTH_HANDLE );
		// wp_add_inline_script() で積まれたインラインスクリプトごと戻すため、登録も外す。
		wp_dequeue_script( 'vkbm-auth-forms' );
		wp_deregister_script( 'vkbm-auth-forms' );
	}

	/**
	 * 認証フォーム用アセットが読み込み待ちかどうかを返す。
	 *
	 * @return array{style: bool, script: bool} 各アセットの読み込み待ちの有無。
	 */
	private function get_auth_assets_state(): array {
		return array(
			'style'  => wp_style_is( Common_Styles::AUTH_HANDLE, 'enqueued' ),
			'script' => wp_script_is( 'vkbm-auth-forms', 'enqueued' ),
		);
	}

	/**
	 * テスト用の Auth_Shortcodes を作る。
	 *
	 * @return Auth_Shortcodes
	 */
	private function create_auth_shortcodes(): Auth_Shortcodes {
		$service = new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() );

		return new Auth_Shortcodes( $service );
	}

	/**
	 * Auth_Shortcodes::register() が、全ページで認証フォーム用アセットを読み込むフックを登録しないこと。
	 */
	public function test_register(): void {
		$auth_shortcodes = $this->create_auth_shortcodes();
		$auth_shortcodes->register();

		$this->assertFalse(
			has_action( 'wp_enqueue_scripts', array( $auth_shortcodes, 'enqueue_assets' ) ),
			'wp_enqueue_scripts に enqueue_assets が登録されていない'
		);

		$this->go_to( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core のフックを発火する。

		$this->assertSame(
			array(
				'style'  => false,
				'script' => false,
			),
			$this->get_auth_assets_state(),
			'認証フォームの無いページでは認証フォーム用の CSS/JS が読み込まれない'
		);
	}

	/**
	 * 予約ブロックの描画時だけ、認証フォーム用アセットが読み込まれること。
	 */
	public function test_enqueue_auth_assets_on_render(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約ブロックを描画した場合 => CSS と JS を読み込む',
				'block_name'          => 'vk-booking-manager/reservation',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '予約ブロックを 2 回描画した場合 => CSS と JS を読み込む（重複しても問題ない）',
				'block_name'          => 'vk-booking-manager/reservation',
				'render_twice'        => true,
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '別のブロックを描画した場合 => 読み込まない',
				'block_name'          => 'core/paragraph',
				'expected'            => array(
					'style'  => false,
					'script' => false,
				),
			),
			array(
				'test_condition_name' => 'ブロック名が空の場合 => 読み込まない',
				'block_name'          => '',
				'expected'            => array(
					'style'  => false,
					'script' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->reset_auth_assets();

			$block = new Reservation_Block( $this->create_auth_shortcodes() );

			$content = '<div class="sample"></div>';
			$result  = $block->enqueue_auth_assets_on_render( $content, array( 'blockName' => $case['block_name'] ) );
			if ( ! empty( $case['render_twice'] ) ) {
				$result = $block->enqueue_auth_assets_on_render( $result, array( 'blockName' => $case['block_name'] ) );
			}

			$this->assertSame( $content, $result, $case['test_condition_name'] . '（HTML は変更しない）' );
			$this->assertSame( $case['expected'], $this->get_auth_assets_state(), $case['test_condition_name'] );
		}
	}

	/**
	 * register() 経由の render_block フィルタで、予約ブロックを描画したときだけ読み込まれること。
	 */
	public function test_register_render_block_filter(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約ブロックを含むコンテンツを描画した場合 => 読み込む',
				'content'             => '<!-- wp:vk-booking-manager/reservation --><div class="wp-block-vk-booking-manager-reservation"></div><!-- /wp:vk-booking-manager/reservation -->',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '予約ブロックを含まないコンテンツを描画した場合 => 読み込まない',
				'content'             => '<!-- wp:paragraph --><p>sample</p><!-- /wp:paragraph -->',
				'expected'            => array(
					'style'  => false,
					'script' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->reset_auth_assets();

			$block = new Reservation_Block( $this->create_auth_shortcodes() );
			$block->register_block();

			// ブートストラップ側の同じフィルタと混ざらないよう、このテスト専用のフィルタだけにする。
			remove_all_filters( 'render_block' );
			add_filter( 'render_block', array( $block, 'enqueue_auth_assets_on_render' ), 10, 2 );

			do_blocks( $case['content'] );

			$this->assertSame( $case['expected'], $this->get_auth_assets_state(), $case['test_condition_name'] );
		}
	}

	/**
	 * ショートコードでフォームを描画したときに、認証フォーム用アセットが読み込まれること。
	 */
	public function test_render_login_form(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'ログインフォームのショートコードを描画した場合 => 読み込む',
				'method'              => 'render_login_form',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '会員登録を受け付けていて、会員登録フォームのショートコードを描画した場合 => 読み込む',
				'method'              => 'render_registration_form',
				'users_can_register'  => '1',
				'expected_form'       => 'vkbm-register-username',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '会員登録を受け付けていない場合 => 受付停止の案内でも読み込む',
				'method'              => 'render_registration_form',
				'users_can_register'  => '0',
				'expected_form'       => 'vkbm-auth-card--notice',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => 'フォームを描画しない場合 => 読み込まない',
				'method'              => '',
				'expected'            => array(
					'style'  => false,
					'script' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->reset_auth_assets();
			wp_set_current_user( 0 );

			update_option( 'users_can_register', $case['users_can_register'] ?? '0' );

			$auth_shortcodes = $this->create_auth_shortcodes();
			$output          = '';
			if ( '' !== $case['method'] ) {
				$output = $auth_shortcodes->{$case['method']}();
			}

			if ( isset( $case['expected_form'] ) ) {
				$this->assertStringContainsString( $case['expected_form'], $output, $case['test_condition_name'] . '（描画された経路）' );
			}
			$this->assertSame( $case['expected'], $this->get_auth_assets_state(), $case['test_condition_name'] );
		}
	}

	/**
	 * 表示中の投稿の本文にショートコードがあるときだけ、描画前（wp_enqueue_scripts）に読み込まれること。
	 */
	public function test_enqueue_assets_for_shortcode_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '本文にログインフォームのショートコードがある場合 => 読み込む',
				'content'             => 'text [vkbm_login_form] text',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '本文に会員登録フォームのショートコードがある場合 => 読み込む',
				'content'             => '[vkbm_register_form]',
				'expected'            => array(
					'style'  => true,
					'script' => true,
				),
			),
			array(
				'test_condition_name' => '本文にショートコードが無い場合 => 読み込まない',
				'content'             => 'plain text',
				'expected'            => array(
					'style'  => false,
					'script' => false,
				),
			),
			array(
				'test_condition_name' => '本文に別のショートコードだけがある場合 => 読み込まない',
				'content'             => '[gallery]',
				'expected'            => array(
					'style'  => false,
					'script' => false,
				),
			),
		);

		$auth_shortcodes = $this->create_auth_shortcodes();
		// has_shortcode() は登録済みのショートコードだけを検出する。
		$auth_shortcodes->register();

		foreach ( $test_cases as $case ) {
			$this->reset_auth_assets();

			$post_id = $this->factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_content' => $case['content'],
				)
			);
			$this->go_to( get_permalink( $post_id ) );

			$auth_shortcodes->enqueue_assets_for_shortcode_page();

			$this->assertSame( $case['expected'], $this->get_auth_assets_state(), $case['test_condition_name'] );
		}

		// 投稿ではないページ（トップなど）では読み込まない。
		$this->reset_auth_assets();
		$this->go_to( home_url( '/' ) );
		$auth_shortcodes->enqueue_assets_for_shortcode_page();
		$this->assertSame(
			array(
				'style'  => false,
				'script' => false,
			),
			$this->get_auth_assets_state(),
			'投稿ではない画面では読み込まない'
		);
	}
}
