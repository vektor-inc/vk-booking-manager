<?php
/**
 * 予約ページのログインフォームと SiteGuard WP Plugin の画像認証の連携、および標準のログイン画面への案内（#1111）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Auth;

use RuntimeException;
use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Integrations\SiteGuard\SiteGuard_Login_Captcha;
use VKBookingManager\Integrations\SiteGuard\SiteGuard_Rest_Api_Check;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use VKBookingManager\Tests\Integrations\SiteGuard\SiteGuard_Login_Captcha_Test;
use WP_UnitTestCase;

/**
 * ページキャッシュから外す処理を呼んだ回数を記録するテスト用サブクラス（定数 DONOTCACHEPAGE はテストの途中で消せないため）。
 */
class Cache_Recording_Auth_Shortcodes extends Auth_Shortcodes {
	/**
	 * ページキャッシュから外す処理を呼んだ回数。
	 *
	 * @var int
	 */
	public $page_cache_disabled_count = 0;

	/**
	 * 定数とヘッダーの代わりに、呼ばれた回数を数える。
	 */
	protected function disable_page_cache(): void {
		++$this->page_cache_disabled_count;
	}
}

/**
 * 画像認証欄の出し分け・失敗文言の出し分け・wp-login.php から転送されて来たときだけの案内リンク・
 * 案内リンク先（vkbm_native_login=1 付きの標準のログイン画面）が転送されないことを検証する。
 *
 * @group auth
 */
class Auth_Shortcodes_SiteGuard_Login_Test extends WP_UnitTestCase {

	/**
	 * 案内文（日本語訳）。
	 */
	private const GUIDE_TEXT = '管理画面にログインする場合は、WordPress 標準のログイン画面をお試しください。';

	/**
	 * 案内リンクの文字列（日本語訳）。
	 */
	private const GUIDE_LINK_TEXT = '標準のログイン画面を開く';

	/**
	 * 画像認証欄を出さないときの失敗文言（日本語訳）。
	 */
	private const AUTH_FAILED_TEXT = 'ユーザー名またはパスワードが正しくありません。';

	/**
	 * 画像認証欄を出すときの失敗文言（日本語訳）。
	 */
	private const AUTH_FAILED_WITH_CAPTCHA_TEXT = 'ユーザー名、パスワード、または画像の文字が正しくありません。';

	/**
	 * テスト前のスーパーグローバル。
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $previous_globals = array();

	/**
	 * テスト中に wp_authenticate_user へ登録した照合処理。
	 *
	 * @var array<int, array{0: object, 1: string}>
	 */
	private array $registered_filters = array();

	/**
	 * 日本語訳を読み込み、スーパーグローバルを退避する。
	 */
	public function set_up(): void {
		parent::set_up();
		if ( function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( 'ja' );
		}
		$mo_path = dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo';
		if ( file_exists( $mo_path ) ) {
			load_textdomain( 'vk-booking-manager', $mo_path );
		}

		wp_set_current_user( 0 );
		$this->previous_globals = array(
			'get'     => $_GET,
			'post'    => $_POST,
			'request' => $_REQUEST,
			'server'  => $_SERVER,
		);
	}

	/**
	 * スーパーグローバル・SiteGuard の状態・フィルタを戻す。
	 */
	public function tear_down(): void {
		$_GET     = $this->previous_globals['get'];
		$_POST    = $this->previous_globals['post'];
		$_REQUEST = $this->previous_globals['request'];
		$_SERVER  = $this->previous_globals['server'];
		$this->reset_siteguard();
		remove_all_filters( 'wp_redirect' );
		parent::tear_down();
	}

	/**
	 * SiteGuard の画像認証の状態を片付ける。
	 */
	private function reset_siteguard(): void {
		foreach ( $this->registered_filters as $callback ) {
			remove_filter( 'wp_authenticate_user', $callback, 1 );
		}
		$this->registered_filters = array();
		unset( $GLOBALS[ SiteGuard_Login_Captcha::HANDLER_GLOBAL_NAME ] );
		delete_option( SiteGuard_Rest_Api_Check::OPTION_NAME );
	}

	/**
	 * SiteGuard のログイン画面の画像認証が有効で、内部の処理が動く状態を再現する。
	 */
	private function enable_siteguard_captcha(): void {
		update_option(
			SiteGuard_Rest_Api_Check::OPTION_NAME,
			array(
				'captcha_enable' => '1',
				'captcha_login'  => '1',
			)
		);
		$handler = SiteGuard_Login_Captcha_Test::make_handler();
		$GLOBALS[ SiteGuard_Login_Captcha::HANDLER_GLOBAL_NAME ] = $handler;
		$callback = array( $handler, SiteGuard_Login_Captcha::VERIFY_METHOD );
		add_filter( 'wp_authenticate_user', $callback, 1, 2 );
		$this->registered_filters[] = $callback;
	}

	/**
	 * 予約ブロックを置いた予約ページを作り、「ログイン画面のリダイレクト」を設定して、ショートコードの処理を返す。
	 *
	 * @param bool   $captcha 画像認証欄を出せる状態にするか。
	 * @param string $content 予約ページの本文。
	 * @return array{0: Cache_Recording_Auth_Shortcodes, 1: string, 2: int} ショートコードの処理と予約ページの URL と ID。
	 */
	private function make_shortcodes( bool $captcha = false, string $content = '<!-- wp:vk-booking-manager/reservation /-->' ): array {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$settings['membership_redirect_wp_login'] = true;
		$settings['reservation_page_url']         = get_permalink( $page_id );
		$repository->update_settings( $settings );

		if ( $captcha ) {
			$this->enable_siteguard_captcha();
		}

		$shortcodes = new Cache_Recording_Auth_Shortcodes(
			new Settings_Service( $repository, new Settings_Sanitizer() ),
			new SiteGuard_Login_Captcha( $captcha )
		);

		return array( $shortcodes, (string) get_permalink( $page_id ), (int) $page_id );
	}

	/**
	 * Wp_redirect が呼ばれたら例外にして、転送先を受け取れるようにする（転送処理の直後の exit を通らせないため）。
	 */
	private function capture_redirects(): void {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( (string) $location );
			}
		);
	}

	/**
	 * 標準のログイン画面を開いた・送信したリクエストを再現し、予約ページへ転送されたかを返す。
	 *
	 * @param Auth_Shortcodes      $shortcodes ショートコードの処理。
	 * @param string               $method     'GET' / 'POST'。
	 * @param array<string, mixed> $get        クエリ。
	 * @param array<string, mixed> $post       送信内容。
	 * @return string 転送先。転送されなければ空文字。
	 */
	private function request_native_login( Auth_Shortcodes $shortcodes, string $method, array $get, array $post ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = $get;
		$_POST                     = $post;
		$_REQUEST                  = array_merge( $get, $post );

		try {
			$shortcodes->redirect_wp_login_to_vkbm();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	/**
	 * URL のクエリを配列で返す。
	 *
	 * @param string $url URL。
	 * @return array<string, mixed> クエリ。
	 */
	private function get_query_args( string $url ): array {
		$query = wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $args );

		return $args;
	}

	/**
	 * Redirect_wp_login_to_vkbm(): 標準のログイン画面は予約ページへ転送し、転送先に「wp-login.php から転送されて来た」印を付ける。
	 * 案内リンク先（get_native_login_url()）は、開いたとき（GET）も、隠し項目だけで印を引き継いで送信したとき（POST）も転送しない。
	 */
	public function test_redirect_wp_login_to_vkbm(): void {
		list( $shortcodes, $reservation_url ) = $this->make_shortcodes();
		$this->capture_redirects();

		// 案内リンク先を開いたときのクエリ（リンクの URL そのものから作る）。
		$native_login_query = $this->get_query_args( $shortcodes->get_native_login_url() );

		// 案内リンク先を開いた画面で、標準のログインフォームに出る隠し項目を取り出す（POST で送られる値）。
		$_GET     = $native_login_query;
		$_REQUEST = $native_login_query;
		ob_start();
		$shortcodes->render_native_login_bypass_field();
		$bypass_field = (string) ob_get_clean();
		$this->assertSame( '<input type="hidden" name="vkbm_native_login" value="1">', $bypass_field, '案内リンク先を開いた画面のフォームに、転送しない印の隠し項目が出る' );

		$test_cases = array(
			array(
				'test_condition_name' => '標準のログイン画面を開く（印なし・GET） => 予約ページへ転送する',
				'method'              => 'GET',
				'get'                 => array(),
				'post'                => array(),
				'expected_redirect'   => true,
			),
			array(
				'test_condition_name' => '標準のログイン画面で送信（印なし・POST） => 予約ページへ転送する',
				'method'              => 'POST',
				'get'                 => array(),
				'post'                => array(
					'log' => 'someone',
					'pwd' => 'secret',
				),
				'expected_redirect'   => true,
			),
			array(
				'test_condition_name' => '案内リンク先を開く（vkbm_native_login=1 付き・GET） => 転送しない',
				'method'              => 'GET',
				'get'                 => $native_login_query,
				'post'                => array(),
				'expected_redirect'   => false,
			),
			array(
				'test_condition_name' => '案内リンク先で送信（クエリは失われ、隠し項目だけで印を引き継ぐ・POST） => 転送しない',
				'method'              => 'POST',
				'get'                 => array(),
				'post'                => array(
					'log'               => 'someone',
					'pwd'               => 'secret',
					'vkbm_native_login' => '1',
				),
				'expected_redirect'   => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$location = $this->request_native_login( $shortcodes, $case['method'], $case['get'], $case['post'] );

			$this->assertSame( $case['expected_redirect'], '' !== $location, $case['test_condition_name'] );

			if ( $case['expected_redirect'] ) {
				$args = $this->get_query_args( $location );
				$this->assertStringStartsWith( $reservation_url, $location, $case['test_condition_name'] . '（転送先は予約ページ）' );
				$this->assertSame( 'login', $args['vkbm_auth'] ?? '', $case['test_condition_name'] . '（ログインフォームを開く）' );
				// 転送されて来た印は、このサイトが発行した値（偽造できない値）にする。
				$flag = (string) ( $args[ Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR ] ?? '' );
				$this->assertNotFalse( wp_verify_nonce( $flag, Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR ), $case['test_condition_name'] . '（転送されて来た印）' );
			}
		}
	}

	/**
	 * Get_native_login_url(): 標準のログイン画面の URL に、予約ページへ転送しない印（vkbm_native_login=1）を付けて返す。
	 */
	public function test_get_native_login_url(): void {
		list( $shortcodes, $reservation_url ) = $this->make_shortcodes();

		$test_cases = array(
			array(
				'test_condition_name' => '予約ページが設定済み => 標準のログイン画面・転送しない印・ログイン後に予約ページへ戻る',
				'reservation_url'     => $reservation_url,
				'expected_query'      => array(
					'redirect_to'       => $reservation_url,
					'vkbm_native_login' => '1',
				),
			),
			array(
				'test_condition_name' => '予約ページが未設定 => 標準のログイン画面・転送しない印だけ',
				'reservation_url'     => '',
				'expected_query'      => array(
					'vkbm_native_login' => '1',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$repository = new Settings_Repository();
			$settings   = $repository->get_settings();
			$settings['reservation_page_url'] = $case['reservation_url'];
			$repository->update_settings( $settings );

			$url = $shortcodes->get_native_login_url();

			$this->assertStringStartsWith( wp_login_url(), $url, $case['test_condition_name'] );
			$args = $this->get_query_args( $url );
			ksort( $args );
			$this->assertSame( $case['expected_query'], $args, $case['test_condition_name'] );
		}
	}

	/**
	 * Render_login_form(): 画像認証欄はパスワード欄の直後に出し、案内リンクは wp-login.php から転送されて来て失敗したときだけ、失敗メッセージの箱の中に出す。
	 */
	public function test_render_login_form(): void {
		$valid_flag = wp_create_nonce( Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR );

		$test_cases = array(
			array(
				'test_condition_name' => '転送されて来た（予約ブロックの JS 経由で送信先 URL に印）・失敗 => 箱の中に案内とリンクを出し、送信先に印を引き継ぐ',
				'captcha'             => false,
				'get'                 => array(),
				'atts_flag'           => $valid_flag,
				'error_code'          => 'auth_failed',
				'expected'            => array(
					'guide'           => true,
					'captcha'         => false,
					'message'         => self::AUTH_FAILED_TEXT,
					'action_has_flag' => true,
				),
			),
			array(
				'test_condition_name' => '転送されて来た（ショートコードのページで URL に印）・失敗 => 案内を出し、送信先に印を引き継ぐ',
				'captcha'             => false,
				'get'                 => array( Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR => $valid_flag ),
				'atts_flag'           => '',
				'error_code'          => 'auth_failed',
				'expected'            => array(
					'guide'           => true,
					'captcha'         => false,
					'message'         => self::AUTH_FAILED_TEXT,
					'action_has_flag' => true,
				),
			),
			array(
				'test_condition_name' => '転送されて来た・失敗理由が別（短時間に何度も失敗） => 理由を問わず同じ案内を出す',
				'captcha'             => false,
				'get'                 => array(),
				'atts_flag'           => $valid_flag,
				'error_code'          => 'rate_limited',
				'expected'            => array(
					'guide'           => true,
					'captcha'         => false,
					'message'         => '',
					'action_has_flag' => true,
				),
			),
			array(
				'test_condition_name' => '転送されて来た・まだ失敗していない => 案内を出さない（送信先には印を引き継ぐ）',
				'captcha'             => false,
				'get'                 => array(),
				'atts_flag'           => $valid_flag,
				'error_code'          => '',
				'expected'            => array(
					'guide'           => false,
					'captcha'         => false,
					'message'         => '',
					'action_has_flag' => true,
				),
			),
			array(
				'test_condition_name' => '予約ページを直接開いた（印なし）・失敗 => 案内を出さない',
				'captcha'             => false,
				'get'                 => array(),
				'atts_flag'           => '',
				'error_code'          => 'auth_failed',
				'expected'            => array(
					'guide'           => false,
					'captcha'         => false,
					'message'         => self::AUTH_FAILED_TEXT,
					'action_has_flag' => false,
				),
			),
			array(
				'test_condition_name' => '印が偽物（このサイトが発行していない値）・失敗 => 案内を出さず、送信先にも引き継がない',
				'captcha'             => false,
				'get'                 => array( Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR => '1' ),
				'atts_flag'           => 'abcdef1234',
				'error_code'          => 'auth_failed',
				'expected'            => array(
					'guide'           => false,
					'captcha'         => false,
					'message'         => self::AUTH_FAILED_TEXT,
					'action_has_flag' => false,
				),
			),
			array(
				'test_condition_name' => '画像認証有効・失敗 => 画像認証欄を出し、失敗文言を画像の文字を含む文にする',
				'captcha'             => true,
				'get'                 => array(),
				'atts_flag'           => '',
				'error_code'          => 'auth_failed',
				'expected'            => array(
					'guide'           => false,
					'captcha'         => true,
					'message'         => self::AUTH_FAILED_WITH_CAPTCHA_TEXT,
					'action_has_flag' => false,
				),
			),
			array(
				'test_condition_name' => '画像認証有効・転送されて来た・失敗 => 画像認証欄と案内の両方を出す',
				'captcha'             => true,
				'get'                 => array(),
				'atts_flag'           => $valid_flag,
				'error_code'          => 'auth_failed',
				'expected'            => array(
					'guide'           => true,
					'captcha'         => true,
					'message'         => self::AUTH_FAILED_WITH_CAPTCHA_TEXT,
					'action_has_flag' => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			list( $shortcodes, $reservation_url ) = $this->make_shortcodes( $case['captcha'] );

			$_GET     = $case['get'];
			$_REQUEST = $case['get'];

			// 予約ブロックの JS は、今開いている URL を送信先（action_url）として REST に渡す。
			$page_url = add_query_arg( 'vkbm_auth', 'login', $reservation_url );
			if ( '' !== $case['atts_flag'] ) {
				$page_url = add_query_arg( Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR, $case['atts_flag'], $page_url );
			}

			$html = $shortcodes->render_login_form(
				array(
					'redirect'   => $page_url,
					'action_url' => $page_url,
					'error_code' => $case['error_code'],
				)
			);

			// 案内とリンクの有無と、出すときは失敗メッセージの箱の中にあること。
			$this->assertSame( $case['expected']['guide'], false !== strpos( $html, self::GUIDE_TEXT ), $case['test_condition_name'] . '（案内文）' );
			$this->assertSame( $case['expected']['guide'], false !== strpos( $html, self::GUIDE_LINK_TEXT ), $case['test_condition_name'] . '（リンク）' );
			if ( $case['expected']['guide'] ) {
				$this->assertMatchesRegularExpression(
					'#<div class="vkbm-alert vkbm-alert__danger"[^>]*>\s*<ul>.*?</ul>\s*<p[^>]*>' . preg_quote( self::GUIDE_TEXT, '#' ) . '</p>\s*<p[^>]*><a href="([^"]+)">' . preg_quote( self::GUIDE_LINK_TEXT, '#' ) . '</a></p>\s*</div>#s',
					$html,
					$case['test_condition_name'] . '（箱の中に、案内文とリンクを別の行で出す）'
				);
				preg_match( '#<a href="([^"]+)">' . preg_quote( self::GUIDE_LINK_TEXT, '#' ) . '</a>#', $html, $matches );
				$this->assertSame( $shortcodes->get_native_login_url(), html_entity_decode( $matches[1] ), $case['test_condition_name'] . '（リンク先）' );
			}

			// 画像認証欄の有無と、出すときはパスワード欄の直後・「ログイン状態を保持する」の行の前にあること。
			$captcha_pos = strpos( $html, 'vkbm-auth-form__captcha' );
			$this->assertSame( $case['expected']['captcha'], false !== $captcha_pos, $case['test_condition_name'] . '（画像認証欄）' );
			if ( $case['expected']['captcha'] ) {
				$this->assertGreaterThan( strpos( $html, 'id="vkbm-login-password"' ), $captcha_pos, $case['test_condition_name'] . '（パスワード欄の後）' );
				$this->assertLessThan( strpos( $html, 'vkbm-auth-form__remember' ), $captcha_pos, $case['test_condition_name'] . '（ログイン状態を保持するの前）' );
			}

			if ( '' !== $case['expected']['message'] ) {
				$this->assertStringContainsString( $case['expected']['message'], $html, $case['test_condition_name'] . '（失敗文言）' );
			}

			// 送信先（form の action）に、転送されて来た印を引き継ぐか。
			preg_match( '#<form class="vkbm-auth-form" method="post" action="([^"]+)"#', $html, $form_matches );
			$action_args = $this->get_query_args( $form_matches[1] ?? '' );
			$action_flag = (string) ( $action_args[ Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR ] ?? '' );
			$this->assertSame( $case['expected']['action_has_flag'], '' !== $action_flag && false !== wp_verify_nonce( $action_flag, Auth_Shortcodes::WP_LOGIN_REDIRECT_QUERY_VAR ), $case['test_condition_name'] . '（送信先の印）' );

			// 画像認証欄は使い捨てのため、欄を出したときだけページキャッシュから外す（ショートコードを本文以外に置いた場合の保険）。
			$this->assertSame( $case['expected']['captcha'] ? 1 : 0, $shortcodes->page_cache_disabled_count, $case['test_condition_name'] . '（ページキャッシュから外す）' );

			$this->reset_siteguard();
		}
	}

	/**
	 * Get_login_error_message(): 画像認証欄を出すときだけ、ログイン失敗（auth_failed）の文言を画像の文字を含む文にする。
	 */
	public function test_get_login_error_message(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '画像認証欄を出さない・auth_failed => 現行の文言',
				'captcha'             => false,
				'code'                => 'auth_failed',
				'expected'            => self::AUTH_FAILED_TEXT,
			),
			array(
				'test_condition_name' => '画像認証欄を出す・auth_failed => 画像の文字を含む文言',
				'captcha'             => true,
				'code'                => 'auth_failed',
				'expected'            => self::AUTH_FAILED_WITH_CAPTCHA_TEXT,
			),
			array(
				'test_condition_name' => '画像認証欄を出す・empty_password => 変えない',
				'captcha'             => true,
				'code'                => 'empty_password',
				'expected'            => 'パスワードを入力してください。',
			),
			array(
				'test_condition_name' => '画像認証欄を出す・一覧に無いコード => 空文字',
				'captcha'             => true,
				'code'                => 'siteguard-captcha-error',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			list( $shortcodes ) = $this->make_shortcodes( $case['captcha'] );

			$this->assertSame( $case['expected'], $shortcodes->get_login_error_message( $case['code'] ), $case['test_condition_name'] );

			$this->reset_siteguard();
		}
	}

	/**
	 * Maybe_disable_page_cache_for_login_captcha(): ログインフォームのショートコードを置いたページで、画像認証欄を出すときだけ、
	 * 本文の描画より前（ヘッダーを送る前）にページキャッシュから外す。
	 */
	public function test_maybe_disable_page_cache_for_login_captcha(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$test_cases = array(
			array(
				'test_condition_name' => 'ショートコードのページ・画像認証あり・未ログイン => キャッシュから外す',
				'content'             => '[vkbm_login_form]',
				'captcha'             => true,
				'user_id'             => 0,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => 'ショートコードのページ（属性付き）・画像認証あり・未ログイン => キャッシュから外す',
				'content'             => '<p>ようこそ</p>[vkbm_login_form title="会員ログイン"]',
				'captcha'             => true,
				'user_id'             => 0,
				'expected'            => 1,
			),
			array(
				'test_condition_name' => 'ショートコードのページ・画像認証なし => 外さない',
				'content'             => '[vkbm_login_form]',
				'captcha'             => false,
				'user_id'             => 0,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'ショートコードのページ・画像認証あり・ログイン中（フォームを出さない） => 外さない',
				'content'             => '[vkbm_login_form]',
				'captcha'             => true,
				'user_id'             => $administrator_id,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '予約ブロックだけのページ（フォームは REST で取得し no-store） => 外さない',
				'content'             => '<!-- wp:vk-booking-manager/reservation /-->',
				'captcha'             => true,
				'user_id'             => 0,
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			list( $shortcodes, $page_url ) = $this->make_shortcodes( $case['captcha'], $case['content'] );
			wp_set_current_user( $case['user_id'] );
			$this->go_to( $page_url );

			$shortcodes->maybe_disable_page_cache_for_login_captcha();

			$this->assertSame( $case['expected'], $shortcodes->page_cache_disabled_count, $case['test_condition_name'] );

			wp_set_current_user( 0 );
			$this->reset_siteguard();
		}
	}
}
