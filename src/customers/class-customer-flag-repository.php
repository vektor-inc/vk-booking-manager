<?php
/**
 * 顧客フラグの永続化を担うリポジトリ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function delete_user_meta;
use function get_option;
use function get_user_meta;
use function get_users;
use function in_array;
use function is_array;
use function update_option;
use function update_user_meta;

/**
 * 顧客フラグの「種別定義」と「顧客への割り当て」を保存・取得する。
 *
 * - 種別定義 : 店舗が自由に決めるラベル（要注意 / VIP 等）と、予約ブロック可否・ブロック時メッセージ。サイト全体で共通のため options に保存する。
 * - 割り当て : 顧客（ユーザー）に対して、どの種別フラグとメモを付与しているか。顧客の状態のため user_meta に保存する。
 */
class Customer_Flag_Repository {
	/**
	 * フラグ種別定義の保存先オプションキー。
	 */
	public const OPTION_TYPES = 'vkbm_customer_flag_types';

	/**
	 * 顧客への割り当てを保存する user_meta のキー。
	 */
	public const USER_META_ASSIGNMENT = 'vkbm_customer_flag';

	/**
	 * 保存済みのフラグ種別定義（リスト）を返す。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_types(): array {
		$stored = get_option( self::OPTION_TYPES, array() );

		// 不正な保存値（配列以外）の場合は空配列へフォールバックする。
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $stored;
	}

	/**
	 * フラグ種別定義（リスト）を保存する。
	 *
	 * @param array<int, array<string, mixed>> $types サニタイズ済みの種別定義リスト。
	 * @return bool 保存に成功したら true。
	 */
	public function save_types( array $types ): bool {
		// 保存値が現状と同一の場合 update_option は false を返すため、変更なし＝成功として扱う。
		if ( get_option( self::OPTION_TYPES, array() ) === $types ) {
			return true;
		}

		return update_option( self::OPTION_TYPES, $types );
	}

	/**
	 * 指定ユーザー（顧客）のフラグ割り当てを返す。
	 *
	 * @param int $user_id 顧客のユーザー ID。
	 * @return array<string, mixed>|null 割り当てが無ければ null。
	 */
	public function get_assignment( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		$stored = get_user_meta( $user_id, self::USER_META_ASSIGNMENT, true );

		// 不正な保存値（配列以外）や未保存の場合は null を返す。
		if ( ! is_array( $stored ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * 指定ユーザー（顧客）のフラグ割り当てを保存する。
	 *
	 * @param int                  $user_id    顧客のユーザー ID。
	 * @param array<string, mixed> $assignment 保存する割り当て。
	 * @return bool 保存に成功したら true。
	 */
	public function save_assignment( int $user_id, array $assignment ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// 保存値が現状と同一の場合 update_user_meta は false を返すため、変更なし＝成功として扱う。
		if ( get_user_meta( $user_id, self::USER_META_ASSIGNMENT, true ) === $assignment ) {
			return true;
		}

		return false !== update_user_meta( $user_id, self::USER_META_ASSIGNMENT, $assignment );
	}

	/**
	 * 指定ユーザー（顧客）のフラグ割り当てを削除する。
	 *
	 * @param int $user_id 顧客のユーザー ID。
	 * @return bool 削除に成功（または既に無い）場合 true。
	 */
	public function delete_assignment( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// 既に存在しなければ冪等に成功扱いとする。
		if ( null === $this->get_assignment( $user_id ) ) {
			return true;
		}

		return delete_user_meta( $user_id, self::USER_META_ASSIGNMENT );
	}

	/**
	 * 指定した種別が割り当てられている顧客の割り当てを、すべて削除する。
	 *
	 * 種別を削除したときに、その種別の割り当て（顧客についてのメモを含む）を残さないために使う。
	 *
	 * @param array<int, string> $type_ids 削除した種別の ID。
	 * @return void
	 */
	public function delete_assignments_by_type_ids( array $type_ids ): void {
		if ( empty( $type_ids ) ) {
			return;
		}

		// 割り当てを持つユーザーを列挙し、削除した種別が割り当てられているものだけを消す。
		$user_ids = get_users(
			array(
				'meta_key' => self::USER_META_ASSIGNMENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 種別の削除時のみ実行する。
				'fields'   => 'ID',
			)
		);

		foreach ( $user_ids as $user_id ) {
			$assignment = $this->get_assignment( (int) $user_id );
			if ( is_array( $assignment ) && in_array( (string) ( $assignment['type_id'] ?? '' ), $type_ids, true ) ) {
				delete_user_meta( (int) $user_id, self::USER_META_ASSIGNMENT );
			}
		}
	}
}
