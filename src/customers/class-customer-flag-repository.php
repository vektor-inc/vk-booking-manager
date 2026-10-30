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

use function delete_option;
use function delete_user_meta;
use function get_option;
use function get_user_meta;
use function get_users;
use function in_array;
use function is_array;
use function update_option;
use function usleep;
use function wp_cache_delete;
use function wp_cache_get;
use function wp_cache_set;
use function wp_generate_password;
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
	 * ゲスト（会員登録なしのお客様）に付けたフラグの保存先オプションキー。
	 *
	 * 1 人 1 件の記録（メールアドレス・電話番号・種別・メモなど）のリストを、autoload なしで保存する。
	 */
	public const OPTION_GUEST_FLAGS = 'vkbm_guest_customer_flags';

	/**
	 * ゲストに付けたフラグの書き換え中であることを示す排他（一時キー）のオプション名。
	 */
	public const OPTION_GUEST_FLAGS_LOCK = 'vkbm_guest_customer_flags_lock';

	/**
	 * 排他が残ったままになったとみなす秒数（致命的エラーなどで解放されなかった排他を取り直すため）。
	 */
	private const LOCK_STALE_SECONDS = 30;

	/**
	 * 排他を取れるまで待つ回数と、1 回ごとの待ち時間（マイクロ秒）。
	 */
	private const LOCK_RETRIES        = 20;
	private const LOCK_RETRY_INTERVAL = 100000;

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

	/**
	 * ゲストに付けたフラグの記録（リスト）を返す。
	 *
	 * @return array<int, array<string, mixed>> 記録のリスト。保存値が不正または未保存なら空配列。
	 */
	public function get_guest_records(): array {
		$stored = get_option( self::OPTION_GUEST_FLAGS, array() );

		// 不正な保存値（配列以外）の場合は空配列へフォールバックする。
		if ( ! is_array( $stored ) ) {
			return array();
		}

		// 各記録が配列でないものは捨て、連番のリストにそろえる。
		return array_values( array_filter( $stored, 'is_array' ) );
	}

	/**
	 * ゲストに付けたフラグの記録（リスト）を保存する。
	 *
	 * 全ページの表示ごとに読み込まれないよう、autoload なしで保存する。
	 *
	 * @param array<int, array<string, mixed>> $records 保存する記録のリスト。
	 * @return bool 保存に成功したら true。
	 */
	public function save_guest_records( array $records ): bool {
		$records = array_values( $records );

		// 保存値が現状と同一の場合 update_option は false を返すため、変更なし＝成功として扱う。
		if ( get_option( self::OPTION_GUEST_FLAGS, array() ) === $records ) {
			return true;
		}

		// 記録が無くなったら option ごと消す（空のまま残さない）。
		if ( empty( $records ) ) {
			return delete_option( self::OPTION_GUEST_FLAGS );
		}

		return update_option( self::OPTION_GUEST_FLAGS, $records, false );
	}

	/**
	 * ゲストに付けたフラグの記録を、排他を取って読み直してから書き換える。
	 *
	 * 読み取りから保存までを 1 つの排他で囲み、同時に行われた別の書き換えを上書きして消さないようにする。
	 * 予約処理と同じく、wp_options への INSERT IGNORE（add_option 方式の一時キー）で排他を取る。
	 *
	 * @param callable $mutator 排他の中で最新の記録（リスト）を受け取り、保存する新しい記録のリストを返す関数。変更が無ければ null を返す。
	 * @return bool 保存した（または変更が無かった）ら true。排他を取れない・保存に失敗したら false。
	 */
	public function update_guest_records( callable $mutator ): bool {
		$lock_value = $this->acquire_guest_lock();
		if ( '' === $lock_value ) {
			return false;
		}

		try {
			// オブジェクトキャッシュの古い値や「存在しない option」の記憶を読まないよう、排他を取ってから読み直す。
			$this->refresh_guest_option_cache();
			$updated = $mutator( $this->get_guest_records() );

			return null === $updated ? true : $this->save_guest_records( $updated );
		} finally {
			$this->release_guest_lock( $lock_value );
		}
	}

	/**
	 * ゲストの記録の option について、キャッシュ上の値と「存在しない option」の記憶を捨てる。
	 *
	 * wp_cache_delete() だけでは notoptions キャッシュ（存在しない option の記憶）が残り、
	 * 別のリクエストが先に作った option を get_option() が既定値で返してしまうため、あわせて外す。
	 */
	private function refresh_guest_option_cache(): void {
		wp_cache_delete( self::OPTION_GUEST_FLAGS, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION_GUEST_FLAGS ] ) ) {
			unset( $notoptions[ self::OPTION_GUEST_FLAGS ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * ゲストの記録の排他を取る。
	 *
	 * @return string 取れたときは解放に使う値。取れなければ空文字。
	 */
	private function acquire_guest_lock(): string {
		global $wpdb;

		$value = wp_generate_password( 12, false, false ) . '|' . time();

		for ( $try = 0; $try < self::LOCK_RETRIES; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- アトミックな排他の取得のため、キャッシュを通さず直接クエリする。
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::OPTION_GUEST_FLAGS_LOCK,
					$value
				)
			);
			if ( 1 === $inserted ) {
				return $value;
			}

			// 古い排他が残っていれば、見た値のときだけ消して取り直す。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 排他の今の値を DB から読む。キャッシュを通すと排他にならない。
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_GUEST_FLAGS_LOCK ) );
			if ( null !== $current ) {
				$parts  = explode( '|', (string) $current );
				$locked = (int) end( $parts );
				if ( $locked > 0 && ( time() - $locked ) >= self::LOCK_STALE_SECONDS ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 古い排他の条件付き削除。
					$wpdb->delete(
						$wpdb->options,
						array(
							'option_name'  => self::OPTION_GUEST_FLAGS_LOCK,
							'option_value' => (string) $current,
						)
					);
					continue;
				}
			}

			usleep( self::LOCK_RETRY_INTERVAL );
		}

		return '';
	}

	/**
	 * ゲストの記録の排他を解放する。自分の値のときだけ消す（取り直された排他を消さないため）。
	 *
	 * @param string $value acquire_guest_lock() が返した値。
	 */
	private function release_guest_lock( string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自分の値のときだけ消す条件付きの削除。
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::OPTION_GUEST_FLAGS_LOCK,
				'option_value' => $value,
			)
		);
		wp_cache_delete( self::OPTION_GUEST_FLAGS_LOCK, 'options' );
	}
}
