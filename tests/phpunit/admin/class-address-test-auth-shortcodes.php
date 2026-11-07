<?php
/**
 * プロフィール住所のテスト用ショートコード。
 *
 * @package VKBookingManager
 */

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Auth\Auth_Shortcodes;

/** プロフィール送信をテスト中のプロセス内で完結させる。 */
class Address_Test_Auth_Shortcodes extends Auth_Shortcodes {
	/**
	 * リダイレクトでテストプロセスを終了しない。
	 *
	 * @param string $url 遷移先。
	 * @return void
	 */
	protected function redirect_and_exit( string $url ): void {}

	/**
	 * テスト中はレスポンスヘッダーを書き込まない。
	 *
	 * @param string      $name Cookie名。
	 * @param string      $message 通知文。
	 * @param string|null $path Cookieパス。
	 * @return void
	 */
	protected function set_notice_cookie( string $name, string $message, ?string $path = null ): void {}
}
