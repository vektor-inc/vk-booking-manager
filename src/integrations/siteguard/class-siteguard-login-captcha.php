<?php
/**
 * 予約ページのログインフォームに、SiteGuard WP Plugin のログイン画面の画像認証欄を出す。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\SiteGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Throwable;
use WP_HTML_Tag_Processor;

/**
 * SiteGuard の画像認証欄を、予約ページのログインフォーム用に組み立てるクラス（#1111）。
 *
 * SiteGuard は、ログインがどの画面から来たかを問わず、送信内容に画像認証の値が無ければログインを断る
 * （`wp_authenticate_user` フィルタ）。そのため予約ページのログインフォームにも同じ欄を出す。
 * 欄の HTML は SiteGuard 自身の出力処理で作り、照合も SiteGuard に任せる（当プラグインでは照合を外さない）。
 */
class SiteGuard_Login_Captcha {

	/**
	 * SiteGuard が画像認証の処理を置いているグローバル変数の名前。
	 */
	public const HANDLER_GLOBAL_NAME = 'siteguard_captcha';

	/**
	 * SiteGuard がログイン画面の画像認証欄を出力するメソッドの名前。
	 */
	public const RENDER_METHOD = 'handler_login_form';

	/**
	 * SiteGuard がログイン時に画像認証を照合するメソッドの名前（`wp_authenticate_user` に登録される）。
	 */
	public const VERIFY_METHOD = 'handler_wp_authenticate_user';

	/**
	 * SiteGuard の画像認証欄の入力欄の name 属性。
	 */
	public const INPUT_NAME = 'siteguard_captcha';

	/**
	 * SiteGuard が有効化されているか。null のときは実行中のサイトで判定する。
	 *
	 * @var bool|null
	 */
	private ?bool $is_siteguard_active;

	/**
	 * コンストラクタ。
	 *
	 * @param bool|null $is_siteguard_active SiteGuard が有効化されているか。null のときは実行中のサイトで判定する（テストで差し替えるための引数）。
	 */
	public function __construct( ?bool $is_siteguard_active = null ) {
		$this->is_siteguard_active = $is_siteguard_active;
	}

	/**
	 * SiteGuard の設定値が、ログイン画面の画像認証を有効にしているかを返す。
	 *
	 * SiteGuard（1.8.11）と同じく、`captcha_enable` が `'1'` で、`captcha_login` が `'0'`（無効）以外のときに有効とみなす。
	 * `captcha_login` の値は `'1'`（ひらがな）・`'2'`（英数字）で、キーが無いときも SiteGuard は有効として扱う。
	 * 設定値が配列でない・値が文字列や数値でないときは false（欄を出さない側）にする。
	 *
	 * @param mixed $config option `siteguard_config` の値。
	 * @return bool 有効なら true。
	 */
	public static function is_login_captcha_config( $config ): bool {
		if ( ! is_array( $config ) ) {
			return false;
		}

		$enable = $config['captcha_enable'] ?? '';
		$login  = $config['captcha_login'] ?? '';
		if ( ! is_scalar( $enable ) || ! is_scalar( $login ) ) {
			return false;
		}

		return '1' === (string) $enable && '0' !== (string) $login;
	}

	/**
	 * 予約ページのログインフォームに画像認証欄を出せるかを返す。
	 *
	 * SiteGuard が有効化されていて、設定でログイン画面の画像認証が有効で、SiteGuard の出力処理と照合処理が
	 * 実際に動く状態のときだけ true。
	 *
	 * @return bool 出せるなら true。
	 */
	public function is_available(): bool {
		if ( ! $this->is_siteguard_active() ) {
			return false;
		}

		if ( ! self::is_login_captcha_config( get_option( SiteGuard_Rest_Api_Check::OPTION_NAME ) ) ) {
			return false;
		}

		return null !== $this->get_captcha_handler();
	}

	/**
	 * 予約ページのログインフォームに差し込む画像認証欄の HTML を返す。
	 *
	 * SiteGuard の出力処理（`$siteguard_captcha->handler_login_form()`）を出力バッファで呼び、
	 * ラベルと入力欄に当プラグインのフォームと同じクラスを足して、入力欄 1 つ分の枠で囲む。
	 * 出せないとき・SiteGuard の出力処理が例外を投げたとき・出力に入力欄が無いときは空文字を返す。
	 * 戻り値は SiteGuard の出力を許可したタグ・属性だけに絞ってある。
	 *
	 * @return string 画像認証欄の HTML。出さないときは空文字。
	 */
	public function render_field(): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		$handler = $this->get_captcha_handler();
		if ( null === $handler ) {
			return '';
		}

		// SiteGuard の出力処理は echo するため、出力バッファで受け取る。
		ob_start();
		try {
			$handler->{self::RENDER_METHOD}();
		} catch ( Throwable $error ) {
			ob_end_clean();
			return '';
		}
		$markup = (string) ob_get_clean();

		// SiteGuard の出力の形が変わり、照合に使う入力欄が見つからないときは欄を出さない。
		if ( false === strpos( $markup, 'name="' . self::INPUT_NAME . '"' ) ) {
			return '';
		}

		$markup = wp_kses( $this->add_form_classes( $markup ), $this->get_allowed_tags() );

		return '<div class="vkbm-auth-form__field vkbm-auth-form__captcha">' . $markup . '</div>';
	}

	/**
	 * SiteGuard の画像認証の処理（グローバル `$siteguard_captcha`）を返す。
	 *
	 * 出力処理のメソッドがあり、照合処理が `wp_authenticate_user` に登録されているときだけ返す。
	 * SiteGuard は、サーバーが画像を作れないときなどに照合処理を登録しない。そのとき欄だけ出すと、
	 * 照合されない欄になったり、出力処理の中で止まったりするため。
	 *
	 * @return object|null 画像認証の処理。見つからないときは null。
	 */
	private function get_captcha_handler(): ?object {
		$handler = $GLOBALS[ self::HANDLER_GLOBAL_NAME ] ?? null;
		if ( ! is_object( $handler ) || ! method_exists( $handler, self::RENDER_METHOD ) ) {
			return null;
		}

		if ( false === has_filter( 'wp_authenticate_user', array( $handler, self::VERIFY_METHOD ) ) ) {
			return null;
		}

		return $handler;
	}

	/**
	 * SiteGuard の出力のラベルと入力欄に、当プラグインのフォームと同じクラスを足す。
	 *
	 * @param string $markup SiteGuard の出力。
	 * @return string クラスを足した HTML。
	 */
	private function add_form_classes( string $markup ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $markup;
		}

		$processor = new WP_HTML_Tag_Processor( $markup );
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'LABEL' === $tag ) {
				$processor->add_class( 'vkbm-auth-form__label' );
			} elseif ( 'INPUT' === $tag && self::INPUT_NAME === $processor->get_attribute( 'name' ) ) {
				$processor->add_class( 'vkbm-auth-form__input' );
				// 自動入力・自動補正で画像の文字以外が入らないようにする。
				$processor->set_attribute( 'autocomplete', 'off' );
				$processor->set_attribute( 'autocapitalize', 'off' );
				$processor->set_attribute( 'spellcheck', 'false' );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * 画像認証欄の HTML で許可するタグと属性を返す。
	 *
	 * @return array<string, array<string, bool>> wp_kses() に渡す許可リスト。
	 */
	private function get_allowed_tags(): array {
		return array(
			'p'     => array(),
			'br'    => array(),
			'img'   => array(
				'src' => true,
				'alt' => true,
			),
			'label' => array(
				'for'   => true,
				'class' => true,
			),
			'input' => array(
				'type'           => true,
				'name'           => true,
				'id'             => true,
				'class'          => true,
				'value'          => true,
				'size'           => true,
				'aria-required'  => true,
				'autocomplete'   => true,
				'autocapitalize' => true,
				'spellcheck'     => true,
			),
		);
	}

	/**
	 * SiteGuard が有効化されているかを返す。
	 *
	 * SiteGuard は読み込み時に定数 `SITEGUARD_VERSION` を定義するため、その有無で判定する。
	 *
	 * @return bool 有効化されていれば true。
	 */
	private function is_siteguard_active(): bool {
		if ( null !== $this->is_siteguard_active ) {
			return $this->is_siteguard_active;
		}

		return defined( 'SITEGUARD_VERSION' );
	}
}
