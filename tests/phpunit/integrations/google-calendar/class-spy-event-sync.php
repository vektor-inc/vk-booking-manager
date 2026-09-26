<?php
/**
 * 送り出し（リダイレクト）の代わりに、送り先を記録して例外を投げるテスト用の子クラス。
 *
 * issue #476。`test-google-calendar-event-sync.php` から使う。
 * phpcs（1ファイル1クラス）に合わせてテストケースのファイルとは分けている
 * （`Spy_Connect_Controller` と同じ考え方）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use VKBookingManager\Integrations\GoogleCalendar\Google_Calendar_Event_Sync;

/**
 * 実処理は親クラスへ委譲しつつ、「今すぐ再試行」後の送り出しだけを差し替えるテストダブル。
 */
class Spy_Event_Sync extends Google_Calendar_Event_Sync {

	/**
	 * 最後に送り出そうとした先の URL。
	 *
	 * @var string
	 */
	public $redirected_to = '';

	/**
	 * 送り出しの代わりに、送り先を記録して処理を止める。
	 *
	 * @param string $url 送り先の URL。
	 * @return void
	 * @throws Redirect_Exception 送り出しの代わりに必ず投げる例外.
	 */
	protected function redirect_after_retry( string $url ): void {
		$this->redirected_to = $url;

		throw new Redirect_Exception( 'redirect' );
	}
}
