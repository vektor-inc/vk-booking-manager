<?php
/**
 * handle_auto_register() / run_auto_register_on_settings_saved() の呼び出し回数を数えるための
 * Shift_Editor のテスト用サブクラス。
 *
 * test-provider-settings-page-shift-auto-register.php から、値が変わった保存でも登録処理
 * （handle_auto_register()）が1回だけ実行されることを検証するために使う（安藤レビュー指摘T1の
 * 整理）。phpcs（1ファイル1クラス）に合わせてテストケースのファイルとは分けている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Shifts\Shift_Editor;

/**
 * 実処理は親クラスへ委譲しつつ、呼び出し回数だけを記録する Shift_Editor のテストダブル。
 */
class Counting_Shift_Editor extends Shift_Editor {
	/**
	 * handle_auto_register() が呼ばれた回数。
	 *
	 * @var int
	 */
	public $handle_auto_register_call_count = 0;

	/**
	 * run_auto_register_on_settings_saved() が呼ばれた回数。
	 *
	 * @var int
	 */
	public $run_auto_register_on_settings_saved_call_count = 0;

	/**
	 * {@inheritDoc}
	 */
	public function handle_auto_register(): void {
		++$this->handle_auto_register_call_count;
		parent::handle_auto_register();
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_auto_register_on_settings_saved(): void {
		++$this->run_auto_register_on_settings_saved_call_count;
		parent::run_auto_register_on_settings_saved();
	}
}
