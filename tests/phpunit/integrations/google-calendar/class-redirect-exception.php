<?php
/**
 * テスト中に、送り出し（リダイレクト）の代わりに投げる例外。
 *
 * issue #475。本番のコードは送り出しと同時に処理を終える（`exit`）ため、そのままでは
 * テストから続きを確かめられない。テスト用の子クラス（Spy_Connect_Controller）が
 * 送り出しの代わりにこの例外を投げ、送り先だけを確かめられるようにしている。
 * phcs（1ファイル1クラス）に合わせてテストケースのファイルとは分けている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Integrations\GoogleCalendar;

use Exception;

/**
 * 送り出しの代わりに投げる例外。
 */
class Redirect_Exception extends Exception {}
