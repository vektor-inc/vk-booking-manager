<?php
/**
 * SiteGuard_Login_Captcha（予約ページのログインフォームに SiteGuard の画像認証欄を出す処理、#1111）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\SiteGuard;

use RuntimeException;
use VKBookingManager\Integrations\SiteGuard\SiteGuard_Login_Captcha;
use VKBookingManager\Integrations\SiteGuard\SiteGuard_Rest_Api_Check;
use WP_UnitTestCase;

/**
 * 画像認証欄を出す条件（SiteGuard の設定値・SiteGuard の内部の処理の有無）と、出す HTML を検証する。
 *
 * @group integrations
 */
class SiteGuard_Login_Captcha_Test extends WP_UnitTestCase {

	/**
	 * SiteGuard 1.8.11 の handler_login_form() が出力する HTML と同じ形。
	 */
	public const SITEGUARD_MARKUP = '<p><img src="http://example.org/wp-content/siteguard/123456.png" alt="CAPTCHA"></p><p><label for="siteguard_captcha">Please enter the characters shown above.</label><br /><input type="text" name="siteguard_captcha" id="siteguard_captcha" class="input" value="" size="10" aria-required="true" /><input type="hidden" name="siteguard_captcha_prefix" id="siteguard_captcha_prefix" value="123456" /></p>';

	/**
	 * テスト中に wp_authenticate_user へ登録した照合処理。
	 *
	 * @var array<int, array{0: object, 1: string}>
	 */
	private array $registered_filters = array();

	/**
	 * テスト後に SiteGuard の設定値・グローバル・フィルタを片付ける。
	 */
	public function tear_down(): void {
		foreach ( $this->registered_filters as $callback ) {
			remove_filter( 'wp_authenticate_user', $callback, 1 );
		}
		$this->registered_filters = array();
		unset( $GLOBALS[ SiteGuard_Login_Captcha::HANDLER_GLOBAL_NAME ] );
		delete_option( SiteGuard_Rest_Api_Check::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * SiteGuard の画像認証の処理の代わりになるオブジェクトを作る。
	 *
	 * @param string $kind 'normal'（SiteGuard と同じ出力）/ 'no_input'（入力欄の無い出力）/ 'throws'（例外を投げる）/ 'no_method'（出力処理が無い）。
	 * @return object 代わりのオブジェクト。
	 */
	public static function make_handler( string $kind = 'normal' ): object {
		if ( 'no_method' === $kind ) {
			return new class() {
				/**
				 * 照合処理だけを持つ。
				 *
				 * @param mixed $user     ユーザー。
				 * @param mixed $password パスワード。
				 * @return mixed そのまま返す。
				 */
				public function handler_wp_authenticate_user( $user, $password ) {
					unset( $password );
					return $user;
				}
			};
		}

		return new class( $kind ) {
			/**
			 * 出力の種類。
			 *
			 * @var string
			 */
			private string $kind;

			/**
			 * コンストラクタ。
			 *
			 * @param string $kind 出力の種類。
			 */
			public function __construct( string $kind ) {
				$this->kind = $kind;
			}

			/**
			 * SiteGuard の出力処理の代わり。
			 *
			 * @throws RuntimeException 'throws' のとき。
			 */
			public function handler_login_form(): void {
				if ( 'throws' === $this->kind ) {
					echo '<p>partial';
					throw new RuntimeException( 'captcha unavailable' );
				}

				echo 'no_input' === $this->kind ? '<p>changed markup</p>' : SiteGuard_Login_Captcha_Test::SITEGUARD_MARKUP; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- テスト用の固定値。
			}

			/**
			 * SiteGuard の照合処理の代わり。
			 *
			 * @param mixed $user     ユーザー。
			 * @param mixed $password パスワード。
			 * @return mixed そのまま返す。
			 */
			public function handler_wp_authenticate_user( $user, $password ) {
				unset( $password );
				return $user;
			}
		};
	}

	/**
	 * SiteGuard の状態を再現する。
	 *
	 * @param array<string, mixed> $state 'config'（設定値。null なら保存しない）・'handler'（make_handler() の種類。null ならグローバルを置かない）・'hooked'（照合処理を登録するか）。
	 */
	private function set_siteguard_state( array $state ): void {
		if ( null !== $state['config'] ) {
			update_option( SiteGuard_Rest_Api_Check::OPTION_NAME, $state['config'] );
		}

		if ( null === $state['handler'] ) {
			return;
		}

		$handler = self::make_handler( $state['handler'] );
		$GLOBALS[ SiteGuard_Login_Captcha::HANDLER_GLOBAL_NAME ] = $handler;

		if ( $state['hooked'] ) {
			$callback = array( $handler, SiteGuard_Login_Captcha::VERIFY_METHOD );
			add_filter( 'wp_authenticate_user', $callback, 1, 2 );
			$this->registered_filters[] = $callback;
		}
	}

	/**
	 * is_login_captcha_config(): SiteGuard と同じく、captcha_enable が '1' で captcha_login が '0' 以外のときだけ有効。
	 */
	public function test_is_login_captcha_config(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '画像認証が有効・ログインはひらがな（1） => 有効',
				'config'              => array(
					'captcha_enable' => '1',
					'captcha_login'  => '1',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '画像認証が有効・ログインは英数字（2） => 有効',
				'config'              => array(
					'captcha_enable' => '1',
					'captcha_login'  => '2',
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '画像認証が有効・captcha_login のキーが無い => SiteGuard と同じく有効',
				'config'              => array( 'captcha_enable' => '1' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '画像認証が有効・ログインは無効（0） => 無効',
				'config'              => array(
					'captcha_enable' => '1',
					'captcha_login'  => '0',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '画像認証が無効（0）・ログインはひらがな => 無効',
				'config'              => array(
					'captcha_enable' => '0',
					'captcha_login'  => '1',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'captcha_enable のキーが無い => 無効',
				'config'              => array( 'captcha_login' => '1' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '値が配列（想定外の型） => 無効',
				'config'              => array(
					'captcha_enable' => array( '1' ),
					'captcha_login'  => '1',
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '設定値が配列でない（option が無い） => 無効',
				'config'              => false,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], SiteGuard_Login_Captcha::is_login_captcha_config( $case['config'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_field(): 画像認証が有効で SiteGuard の内部の処理が動く状態のときだけ欄を出し、それ以外は例外・警告を出さずに空文字を返す。
	 */
	public function test_render_field(): void {
		$enabled_config = array(
			'captcha_enable' => '1',
			'captcha_login'  => '1',
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'SiteGuard 有効・画像認証有効・内部の処理あり => 欄を出す',
				'active'              => true,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => 'normal',
					'hooked'  => true,
				),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'SiteGuard 有効・画像認証無効 => 出さない',
				'active'              => true,
				'state'               => array(
					'config'  => array(
						'captcha_enable' => '0',
						'captcha_login'  => '1',
					),
					'handler' => 'normal',
					'hooked'  => true,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'SiteGuard が無効化されている（設定値だけ残っている） => 出さない',
				'active'              => false,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => 'normal',
					'hooked'  => true,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'グローバル $siteguard_captcha が無い（SiteGuard の更新で変わった等） => 出さない',
				'active'              => true,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => null,
					'hooked'  => false,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '出力処理のメソッドが無い => 出さない',
				'active'              => true,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => 'no_method',
					'hooked'  => true,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '照合処理が登録されていない（サーバーが画像を作れない等） => 出さない',
				'active'              => true,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => 'normal',
					'hooked'  => false,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '出力処理が例外を投げる => 途中の出力も含めて出さない',
				'active'              => true,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => 'throws',
					'hooked'  => true,
				),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '出力に入力欄が無い（出力の形が変わった） => 出さない',
				'active'              => true,
				'state'               => array(
					'config'  => $enabled_config,
					'handler' => 'no_input',
					'hooked'  => true,
				),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_siteguard_state( $case['state'] );

			$captcha = new SiteGuard_Login_Captcha( $case['active'] );

			$level = ob_get_level();
			$html  = $captcha->render_field();

			// 出力バッファを残さない（例外のときも閉じている）。
			$this->assertSame( $level, ob_get_level(), $case['test_condition_name'] . '（出力バッファ）' );
			$this->assertSame( $case['expected'], $captcha->is_available() && '' !== $html, $case['test_condition_name'] );

			if ( $case['expected'] ) {
				// 入力欄 1 つ分の枠で囲み、ラベルと入力欄に当プラグインのフォームのクラスを足す。
				$this->assertStringStartsWith( '<div class="vkbm-auth-form__field vkbm-auth-form__captcha">', $html, $case['test_condition_name'] );
				$this->assertMatchesRegularExpression( '/<label[^>]*class="vkbm-auth-form__label"/', $html, $case['test_condition_name'] );
				$this->assertMatchesRegularExpression( '/<input[^>]*name="siteguard_captcha"[^>]*class="input vkbm-auth-form__input"|<input[^>]*class="input vkbm-auth-form__input"[^>]*name="siteguard_captcha"/', $html, $case['test_condition_name'] );
				// 照合に使う値（入力欄と、画像の識別子の隠し項目）がそのまま送信される。
				$this->assertStringContainsString( 'name="siteguard_captcha_prefix"', $html, $case['test_condition_name'] );
				$this->assertStringContainsString( 'value="123456"', $html, $case['test_condition_name'] );
				$this->assertStringContainsString( '<img src="http://example.org/wp-content/siteguard/123456.png" alt="CAPTCHA"', $html, $case['test_condition_name'] );
			} else {
				$this->assertSame( '', $html, $case['test_condition_name'] );
			}

			// 次のケースのために片付ける。
			$this->tear_down_siteguard_state();
		}
	}

	/**
	 * set_siteguard_state() で作った状態を片付ける。
	 */
	private function tear_down_siteguard_state(): void {
		foreach ( $this->registered_filters as $callback ) {
			remove_filter( 'wp_authenticate_user', $callback, 1 );
		}
		$this->registered_filters = array();
		unset( $GLOBALS[ SiteGuard_Login_Captcha::HANDLER_GLOBAL_NAME ] );
		delete_option( SiteGuard_Rest_Api_Check::OPTION_NAME );
	}
}
