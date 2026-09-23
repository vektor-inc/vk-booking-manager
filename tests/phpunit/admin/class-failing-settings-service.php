<?php
/**
 * Settings_Service::save_settings() を強制的に失敗（WP_Error）させるテスト用サブクラス。
 *
 * test-provider-settings-page-shift-auto-register.php から使う（保存が失敗した場合に
 * シフトの自動登録を実行しないことを検証するため）。phpcs（1ファイル1クラス）に合わせて
 * テストケースのファイルとは分けている。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\ProviderSettings\Settings_Service;
use WP_Error;

/**
 * バリデーション内容に関わらず、常に WP_Error を返す Settings_Service のテストダブル。
 */
class Failing_Settings_Service extends Settings_Service {
	/**
	 * バリデーションの内容に関わらず、常に WP_Error を返す。
	 *
	 * @param array<string, mixed> $raw_settings Raw settings payload（このテストダブルでは使用しない）。
	 * @return WP_Error
	 */
	public function save_settings( array $raw_settings ) {
		return new WP_Error( 'vkbm_settings_invalid_for_test', 'Forced failure for test.' );
	}
}
