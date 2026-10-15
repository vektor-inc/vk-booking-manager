<?php
/**
 * 顧客フラグテスト共通のクリーンアップ trait。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Customers\Customer_Flag_Repository;
use function delete_metadata;
use function delete_option;

/**
 * 顧客フラグの種別定義と割り当てのクリーンアップ処理を、複数のテストクラスで共有するための trait。
 */
trait Customer_Flag_Options_Cleanup_Trait {
	/**
	 * 顧客フラグの種別定義と、全ユーザーの割り当てを削除し、テスト間で割り当て状態が漏れないようにする。
	 *
	 * @return void
	 */
	protected function delete_all_flag_options(): void {
		delete_option( Customer_Flag_Repository::OPTION_TYPES );

		// 全ユーザーの割り当て（user_meta）をまとめて削除する。
		delete_metadata( 'user', 0, Customer_Flag_Repository::USER_META_ASSIGNMENT, '', true );
	}
}
