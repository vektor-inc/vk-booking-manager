<?php
/**
 * ご利用になる方の名簿を保存する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Attendees;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_User;

/**
 * 名簿の取得、検証、比較書き込み、番号検索用メタの同期を担う。
 */
class Attendee_Roster {
	/** 本人以外に登録できる人数。 */
	public const MAX_ATTENDEES = 20;

	/** 比較書き込みが競合した場合の試行回数。 */
	private const MAX_UPDATE_ATTEMPTS = 3;

	/**
	 * 名簿の user meta キーを返す。
	 *
	 * @return string user meta キー。
	 */
	public static function get_roster_meta_key(): string {
		return is_multisite() ? 'vkbm_attendees_site_' . get_current_blog_id() : 'vkbm_attendees';
	}

	/**
	 * 番号検索用 user meta キーを返す。
	 *
	 * @return string user meta キー。
	 */
	public static function get_number_meta_key(): string {
		return is_multisite() ? 'vkbm_attendee_number_site_' . get_current_blog_id() : 'vkbm_attendee_number';
	}

	/**
	 * 番号を検索用の表記へ正規化する。
	 *
	 * @param string $number 入力された番号。
	 * @return string 正規化した番号。
	 */
	public static function normalize_number( string $number ): string {
		$number = wp_strip_all_tags( $number );
		if ( function_exists( 'mb_convert_kana' ) ) {
			$number = mb_convert_kana( $number, 'asKV', 'UTF-8' );
		}
		$number = trim( $number );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $number, 'UTF-8' ) : strtolower( $number );
	}

	/**
	 * 保存済みの名簿を返す。
	 *
	 * @param int $user_id ユーザー ID。
	 * @return array<int,array{id:string,name:string,kana:string,number:string}> 名簿。
	 */
	public function get( int $user_id ): array {
		$value = get_user_meta( $user_id, self::get_roster_meta_key(), true );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * 名簿の番号と同じ番号を、別の会員の名簿に持つ会員を探す。
	 *
	 * 比較は検索用 user meta の正規化済みの値で行い、保存は止めず警告用に使う。
	 *
	 * @param int          $user_id 名簿の持ち主の ID。この会員自身は含めない。
	 * @param array<mixed> $rows    名簿。
	 * @return array<string,array{number:string,user_ids:int[]}> 正規化した番号をキーにした、入力された番号と相手会員の ID。
	 */
	public function find_duplicate_number_users( int $user_id, array $rows ): array {
		$duplicates = array();
		foreach ( $rows as $row ) {
			$number     = trim( (string) ( is_array( $row ) ? ( $row['number'] ?? '' ) : '' ) );
			$normalized = self::normalize_number( $number );
			if ( '' === $normalized || isset( $duplicates[ $normalized ] ) ) {
				continue;
			}
			// 検索用 user meta は正規化済みの番号を持つため、完全一致で別会員を探す。
			$user_ids = get_users(
				array(
					'meta_key'   => self::get_number_meta_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 番号の重複を調べる専用のメタキー。
					'meta_value' => $normalized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 同上。
					'exclude'    => array( $user_id ),
					'fields'     => 'ID',
					'number'     => 20,
				)
			);
			if ( $user_ids ) {
				$duplicates[ $normalized ] = array(
					'number'   => $number,
					'user_ids' => array_map( 'intval', $user_ids ),
				);
			}
		}
		return $duplicates;
	}

	/**
	 * 本人行を先頭に補った名簿を返す。本人の名前はプロフィールを正とする。
	 *
	 * @param WP_User $user 名簿の持ち主。
	 * @return array<int,array{id:string,name:string,kana:string,number:string}> 本人行を含む名簿。
	 */
	public function get_with_self( WP_User $user ): array {
		return $this->with_self( $user, $this->get( $user->ID ) );
	}

	/**
	 * 指定した名簿の本人行を、プロフィールの名前で補って先頭へ置く。
	 *
	 * @param WP_User|null $user 名簿の持ち主。
	 * @param array<mixed> $rows 名簿。
	 * @return array<int,array{id:string,name:string,kana:string,number:string}> 本人行を先頭にした名簿。
	 */
	public function with_self( ?WP_User $user, array $rows ): array {
		$self_name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
		if ( '' === $self_name && $user ) {
			$self_name = $user->display_name;
		}
		$self   = array(
			'id'     => 'self',
			'name'   => $self_name,
			'kana'   => $user ? (string) get_user_meta( $user->ID, 'vkbm_kana_name', true ) : '',
			'number' => '',
		);
		$others = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 'self' === (string) ( $row['id'] ?? '' ) ) {
				$self['number'] = (string) ( $row['number'] ?? '' );
			} else {
				$others[] = $row;
			}
		}
		return array_merge( array( $self ), $others );
	}

	/**
	 * 名簿を検証して保存する。
	 *
	 * @param int               $user_id 保存先ユーザー ID。
	 * @param array<mixed>      $rows    送信された名簿。
	 * @param array<mixed>|null $base 編集画面を表示した時点の名簿。省略時は保存開始時の値。
	 * @return true|WP_Error 成功時 true、失敗時エラー。
	 */
	public function save( int $user_id, array $rows, ?array $base = null ) {
		$sanitized = $this->sanitize_rows( $rows );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		$key = self::get_roster_meta_key();
		for ( $attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; $attempt++ ) {
			if ( 0 < $attempt ) {
				wp_cache_delete( $user_id, 'user_meta' );
			}
			$previous = get_user_meta( $user_id, $key, true );
			$previous = is_array( $previous ) ? $previous : array();
			if ( null === $base ) {
				$base = $previous;
			}
			if ( $previous !== $base ) {
				// ほかの画面が変更した行を残し、この保存で変更された行だけを最新値へ重ねる。
				$sanitized = $this->merge_changed_rows( $base, $sanitized, $previous );
				$sanitized = $this->sanitize_rows( $sanitized );
				if ( is_wp_error( $sanitized ) ) {
					return $sanitized;
				}
				$base = $previous;
			}

			// 値が同じ場合も成功として番号検索用メタを整合させる。
			$saved = $previous === $sanitized;
			if ( ! $saved && array() === $previous ) {
				$saved = add_user_meta( $user_id, $key, $sanitized, true );
			} elseif ( ! $saved ) {
				$saved = (bool) update_user_meta( $user_id, $key, $sanitized, $previous );
			}
			if ( $saved ) {
				wp_cache_delete( $user_id, 'user_meta' );
				$this->rebuild_number_meta( $user_id, $this->get( $user_id ) );
				return true;
			}
		}

		return new WP_Error( 'vkbm_attendee_roster_conflict', __( 'The list was updated elsewhere. Please reload the page and try again.', 'vk-booking-manager' ) );
	}

	/**
	 * 編集開始時との差分だけを、競合後の最新名簿へ反映する。
	 *
	 * @param array<mixed> $base    編集開始時の名簿。
	 * @param array<mixed> $desired 保存したい名簿。
	 * @param array<mixed> $latest  競合後に読み直した名簿。
	 * @return array<mixed> 競合側の変更を残した保存値。
	 */
	private function merge_changed_rows( array $base, array $desired, array $latest ): array {
		$base_by_id    = array_column( $base, null, 'id' );
		$desired_by_id = array_column( $desired, null, 'id' );
		$merged_by_id  = array_column( $latest, null, 'id' );

		// 削除された行と、追加・変更された行だけを最新値へ反映する。
		foreach ( $base_by_id as $id => $row ) {
			if ( ! isset( $desired_by_id[ $id ] ) && 'self' !== $id ) {
				unset( $merged_by_id[ $id ] );
			}
		}
		foreach ( $desired_by_id as $id => $row ) {
			if ( ! isset( $base_by_id[ $id ] ) || $base_by_id[ $id ] !== $row ) {
				$merged_by_id[ $id ] = $row;
			}
		}
		// 初回保存などで最新値に本人行が無い場合も、保存対象の本人行を失わない。
		if ( ! isset( $merged_by_id['self'] ) && isset( $desired_by_id['self'] ) ) {
			$merged_by_id['self'] = $desired_by_id['self'];
		}

		return array_values( $merged_by_id );
	}

	/**
	 * 入力された行を検証して保存形式へ整える。
	 *
	 * @param array<mixed> $rows 入力行。
	 * @return array<int,array{id:string,name:string,kana:string,number:string}>|WP_Error 保存形式またはエラー。
	 */
	public function sanitize_rows( array $rows ) {
		$sanitized = array();
		$seen_ids  = array();
		$self_seen = false;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id     = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$name   = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 100 );
			$kana   = mb_substr( sanitize_text_field( (string) ( $row['kana'] ?? '' ) ), 0, 100 );
			$number = mb_substr( sanitize_text_field( (string) ( $row['number'] ?? '' ) ), 0, 50 );

			if ( '' === $id ) {
				$id = wp_generate_uuid4();
			}
			if ( isset( $seen_ids[ $id ] ) ) {
				return new WP_Error( 'vkbm_attendee_duplicate_id', __( 'The list contains duplicate entries.', 'vk-booking-manager' ) );
			}
			if ( 'self' === $id ) {
				$self_seen = true;
			}
			if ( '' === $name ) {
				return new WP_Error( 'vkbm_attendee_name_required', __( 'Please enter a name for every person.', 'vk-booking-manager' ) );
			}
			$seen_ids[ $id ] = true;
			$sanitized[]     = compact( 'id', 'name', 'kana', 'number' );
		}

		if ( ! $self_seen ) {
			return new WP_Error( 'vkbm_attendee_self_required', __( 'Your own entry cannot be deleted.', 'vk-booking-manager' ) );
		}
		if ( count( $sanitized ) - 1 > self::MAX_ATTENDEES ) {
			/* translators: %d: Maximum number of other people that can be registered. */
			return new WP_Error( 'vkbm_attendee_limit', sprintf( __( 'You can register up to %d other people.', 'vk-booking-manager' ), self::MAX_ATTENDEES ) );
		}

		return $sanitized;
	}

	/**
	 * 番号検索用メタを現在の名簿から作り直す。
	 *
	 * @param int                                                               $user_id ユーザー ID。
	 * @param array<int,array{id:string,name:string,kana:string,number:string}> $rows 名簿。
	 * @return void
	 */
	private function rebuild_number_meta( int $user_id, array $rows ): void {
		$key = self::get_number_meta_key();
		delete_user_meta( $user_id, $key );
		foreach ( $rows as $row ) {
			$normalized = self::normalize_number( $row['number'] );
			if ( '' !== $normalized ) {
				add_user_meta( $user_id, $key, $normalized, false );
			}
		}
	}
}
