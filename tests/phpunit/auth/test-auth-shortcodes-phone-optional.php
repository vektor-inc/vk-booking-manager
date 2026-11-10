<?php
/**
 * 電話番号の必須設定（customer_phone_required）が会員登録・プロフィール編集の画面と入力チェックに反映されること（#1053）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Auth;

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- リダイレクトで exit しないテスト用サブクラスを同じファイルに置くため。

/**
 * リダイレクトで exit しないようにしたテスト用の Auth_Shortcodes。
 */
class Phone_Optional_Auth_Shortcodes extends Auth_Shortcodes {

	/**
	 * リダイレクトを記録するだけにして、exit しない。
	 *
	 * @param string $url リダイレクト先。
	 */
	protected function redirect_and_exit( string $url ): void {
		unset( $url );
	}

	/**
	 * 通知用の Cookie は設定しない。
	 *
	 * @param string      $name    Cookie 名。
	 * @param string      $message メッセージ。
	 * @param string|null $path    パス。
	 */
	protected function set_notice_cookie( string $name, string $message, ?string $path = null ): void {
		unset( $name, $message, $path );
	}
}

/**
 * 会員登録フォーム・プロフィール編集フォームの電話番号の必須切り替えを検証するテスト。
 *
 * @group auth
 */
class Auth_Shortcodes_Phone_Optional_Test extends WP_UnitTestCase {

	/**
	 * 退避した $_POST。
	 *
	 * @var array<string, mixed>
	 */
	private $previous_post = array();

	/**
	 * 退避した $_SERVER。
	 *
	 * @var array<string, mixed>
	 */
	private $previous_server = array();

	/**
	 * 退避した users_can_register。
	 *
	 * @var mixed
	 */
	private $previous_registration;

	/**
	 * グローバルの状態を退避し、ユーザー登録を有効にする。
	 */
	public function set_up(): void {
		parent::set_up();
		$this->previous_post         = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後に復元するための退避。
		$this->previous_server       = $_SERVER;
		$this->previous_registration = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );
	}

	/**
	 * グローバルの状態と設定を元に戻す。
	 */
	public function tear_down(): void {
		$_POST   = $this->previous_post;
		$_SERVER = $this->previous_server;
		update_option( 'users_can_register', $this->previous_registration );
		delete_option( Settings_Repository::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * 電話番号の必須設定を保存する。
	 *
	 * @param bool $required 必須にするか。
	 */
	private function save_phone_required( bool $required ): void {
		$repository = new Settings_Repository();
		update_option( Settings_Repository::OPTION_KEY, array_merge( $repository->get_settings(), array( 'customer_phone_required' => $required ) ) );
	}

	/**
	 * 設定を読み込んだ Auth_Shortcodes を作る。
	 */
	private function build_shortcodes(): Phone_Optional_Auth_Shortcodes {
		return new Phone_Optional_Auth_Shortcodes( new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ) );
	}

	/**
	 * 電話番号の入力欄が必須かを、描画した HTML から判定する。
	 *
	 * @param string $html  フォームの HTML。
	 * @param string $field 入力欄の id。
	 * @return array{required:bool,mark:bool} required 属性の有無と、ラベルの必須マークの有無。
	 */
	private function inspect_phone_field( string $html, string $field ): array {
		$this->assertSame( 1, preg_match( '/<input[^>]*id="' . preg_quote( $field, '/' ) . '"[^>]*>/s', $html, $input ), '電話番号の入力欄がある' );
		$this->assertSame( 1, preg_match( '/<label[^>]*for="' . preg_quote( $field, '/' ) . '"[^>]*>(.*?)<\/label>/s', $html, $label ), '電話番号のラベルがある' );

		return array(
			'required' => 1 === preg_match( '/\srequired[\s>]/', $input[0] ),
			'mark'     => false !== strpos( $label[1], 'vkbm-auth-form__required' ),
		);
	}

	/**
	 * 会員登録フォーム・プロフィール編集フォームの電話番号欄の必須マークと required 属性が設定に従うことを検証する。
	 */
	public function test_phone_field_required_markup(): void {
		wp_set_current_user( $this->factory()->user->create() );

		$test_cases = array(
			array(
				'test_condition_name' => '必須の設定 => 必須マークと required がある',
				'required'            => true,
				'expected'            => array(
					'required' => true,
					'mark'     => true,
				),
			),
			array(
				'test_condition_name' => '任意の設定 => 必須マークも required も無い',
				'required'            => false,
				'expected'            => array(
					'required' => false,
					'mark'     => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->save_phone_required( $case['required'] );
			$shortcodes = $this->build_shortcodes();

			$this->assertSame( $case['expected'], $this->inspect_phone_field( $shortcodes->render_registration_form(), 'vkbm-register-phone' ), $case['test_condition_name'] . '（会員登録）' );
			$this->assertSame( $case['expected'], $this->inspect_phone_field( $shortcodes->render_profile_form(), 'vkbm-profile-phone' ), $case['test_condition_name'] . '（プロフィール編集）' );
		}
	}

	/**
	 * 会員登録を電話番号なしで送ったときの入力エラー（empty_phone）が設定に従うことを検証する。
	 */
	public function test_registration_phone_validation(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '必須の設定で電話番号が空 => empty_phone のエラー',
				'required'            => true,
				'phone'               => '',
				'expect_error'        => true,
			),
			array(
				'test_condition_name' => '任意の設定で電話番号が空 => 電話番号のエラーは無い',
				'required'            => false,
				'phone'               => '',
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '任意の設定で全角空白だけ => 電話番号のエラーは無い',
				'required'            => false,
				'phone'               => '　',
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '任意の設定で数字の無い入力（なし）=> エラー',
				'required'            => false,
				'phone'               => 'なし',
				'expect_error'        => true,
			),
			array(
				'test_condition_name' => '必須の設定で電話番号がある => 電話番号のエラーは無い',
				'required'            => true,
				'phone'               => '090-0000-0000',
				'expect_error'        => false,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->save_phone_required( $case['required'] );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			// 確認パスワードを一致させず、ほかの入力エラーで止めて、ユーザーは作らない。
			$_POST = array(
				'vkbm_registration_form'      => '1',
				'vkbm_registration_nonce'     => wp_create_nonce( 'vkbm_registration_form' ),
				'user_login'                  => 'phoneopt' . $index,
				'user_email'                  => 'phoneopt' . $index . '@example.com',
				'user_pass'                   => 'password123',
				'user_pass_confirm'           => 'password456',
				'kana_name'                   => 'たろう',
				'phone_number'                => $case['phone'],
				'vkbm_agree_terms_of_service' => '1',
				'vkbm_agree_privacy_policy'   => '1',
			);

			$shortcodes = $this->build_shortcodes();
			$shortcodes->handle_form_submission();

			$property = new \ReflectionProperty( Auth_Shortcodes::class, 'registration_errors' );
			$property->setAccessible( true );
			$errors = $property->getValue( $shortcodes );

			$this->assertInstanceOf( \WP_Error::class, $errors, $case['test_condition_name'] );
			$this->assertSame( $case['expect_error'], in_array( 'empty_phone', $errors->get_error_codes(), true ), $case['test_condition_name'] );
		}
	}

	/**
	 * 有効な登録情報（確認パスワードも一致）を電話番号なしで送ったとき、設定に従ってユーザーが作られるか、作られないことを検証する。
	 */
	public function test_registration_creates_user_without_phone(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '必須の設定で電話番号が空 => ユーザーは作られない',
				'required'            => true,
				'phone'               => '',
				'expect_created'      => false,
			),
			array(
				'test_condition_name' => '任意の設定で電話番号が空 => ユーザーが作られ、電話番号は空で保存される',
				'required'            => false,
				'phone'               => '',
				'expect_created'      => true,
			),
			array(
				'test_condition_name' => '任意の設定で全角空白だけ => ユーザーが作られ、電話番号は空で保存される',
				'required'            => false,
				'phone'               => '　',
				'expect_created'      => true,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->save_phone_required( $case['required'] );
			$user_login                = 'phonecreate' . $index;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'vkbm_registration_form'      => '1',
				'vkbm_registration_nonce'     => wp_create_nonce( 'vkbm_registration_form' ),
				'user_login'                  => $user_login,
				'user_email'                  => $user_login . '@example.com',
				'user_pass'                   => 'password123',
				'user_pass_confirm'           => 'password123',
				'kana_name'                   => 'たろう',
				'phone_number'                => $case['phone'],
				'vkbm_agree_terms_of_service' => '1',
				'vkbm_agree_privacy_policy'   => '1',
			);

			$shortcodes = $this->build_shortcodes();
			$shortcodes->handle_form_submission();

			$user = get_user_by( 'login', $user_login );

			if ( $case['expect_created'] ) {
				$this->assertInstanceOf( \WP_User::class, $user, $case['test_condition_name'] );
				$this->assertSame( '', (string) get_user_meta( $user->ID, 'phone_number', true ), $case['test_condition_name'] . '（保存された電話番号）' );
			} else {
				$this->assertFalse( $user, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * プロフィール編集を電話番号なしで送ったときの入力エラー（missing_phone）が設定に従うことを検証する。
	 */
	public function test_profile_phone_validation(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '必須の設定で電話番号が空 => missing_phone のエラー',
				'required'            => true,
				'phone'               => '',
				'expect_error'        => true,
			),
			array(
				'test_condition_name' => '任意の設定で電話番号が空 => 電話番号のエラーは無く、空で保存される',
				'required'            => false,
				'phone'               => '',
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '任意の設定で全角空白だけ => 電話番号のエラーは無い',
				'required'            => false,
				'phone'               => '　',
				'expect_error'        => false,
			),
			array(
				'test_condition_name' => '任意の設定で数字の無い入力（なし）=> エラー',
				'required'            => false,
				'phone'               => 'なし',
				'expect_error'        => true,
			),
			array(
				'test_condition_name' => '必須の設定で電話番号がある => 電話番号のエラーは無い',
				'required'            => true,
				'phone'               => '090-0000-0000',
				'expect_error'        => false,
			),
		);

		foreach ( $test_cases as $index => $case ) {
			$this->save_phone_required( $case['required'] );
			$user_id = $this->factory()->user->create(
				array(
					'user_login' => 'profilephone' . $index,
					'user_email' => 'profilephone' . $index . '@example.com',
				)
			);
			update_user_meta( $user_id, 'phone_number', '08011112222' );
			wp_set_current_user( $user_id );

			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'vkbm_profile_form'  => '1',
				'vkbm_profile_nonce' => wp_create_nonce( 'vkbm_profile_form' ),
				'user_email'         => 'profilephone' . $index . '@example.com',
				'kana_name'          => 'たろう',
				'phone_number'       => $case['phone'],
			);

			$shortcodes = $this->build_shortcodes();
			$shortcodes->handle_form_submission();

			$property = new \ReflectionProperty( Auth_Shortcodes::class, 'profile_errors' );
			$property->setAccessible( true );
			$errors = $property->getValue( $shortcodes );

			$has_phone_error = $errors instanceof \WP_Error && in_array( 'missing_phone', $errors->get_error_codes(), true );
			$this->assertSame( $case['expect_error'], $has_phone_error, $case['test_condition_name'] );

			if ( ! $case['expect_error'] ) {
				$expected_saved = '' === trim( $case['phone'], ' 　' ) ? '' : '09000000000';
				$this->assertSame( $expected_saved, (string) get_user_meta( $user_id, 'phone_number', true ), $case['test_condition_name'] . '（保存された電話番号）' );
			}
		}
	}
}
