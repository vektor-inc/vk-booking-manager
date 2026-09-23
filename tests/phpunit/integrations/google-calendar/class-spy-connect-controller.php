<?php
/**
 * 送り出し（リダイレクト）の代わりに、送り先を記録して例外を投げるテスト用の子クラス。
 *
 * issue #475。`test-google-calendar-connect-controller.php` から使う。
 * phpcs（1ファイル1クラス）に合わせてテストケースのファイルとは分けている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Connect_Controller;

/**
 * 実処理は親クラスへ委譲しつつ、送り出しだけを差し替えるテストダブル。
 */
class Spy_Connect_Controller extends Google_Calendar_Connect_Controller {

	/**
	 * 最後に送り出そうとした先。中継サーバーの場合は URL、設定画面の場合は `settings`。
	 *
	 * @var string
	 */
	public $redirected_to = '';

	/**
	 * 中継サーバーへの送り出しの代わりに、送り先を記録して処理を止める。
	 *
	 * @param string $url 送り先の URL。
	 * @return void
	 * @throws Redirect_Exception 送り出しの代わりに必ず投げる例外.
	 */
	protected function redirect_to_relay( string $url ): void {
		$this->redirected_to = $url;

		throw new Redirect_Exception( 'relay' );
	}

	/**
	 * 設定画面への送り出しの代わりに、送り先を記録して処理を止める。
	 *
	 * @return void
	 * @throws Redirect_Exception 送り出しの代わりに必ず投げる例外.
	 */
	protected function redirect_to_settings(): void {
		$this->redirected_to = 'settings';

		throw new Redirect_Exception( 'settings' );
	}
}
