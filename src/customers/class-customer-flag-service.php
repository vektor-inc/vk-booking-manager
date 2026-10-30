<?php
/**
 * 顧客フラグ機能のビジネスロジックを担うサービス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Customers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Auth\Auth_Shortcodes;
use VKBookingManager\Common\Nomination_Min_Guests_Message;
use VKBookingManager\Common\VKBM_Helper;
use VKBookingManager\ProviderSettings\Provider_Display_Name;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_User;
use function __;
use function current_time;
use function get_user_by;
use function get_user_meta;
use function get_userdata;
use function get_users;
use function in_array;
use function is_array;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function trim;
use function wp_generate_uuid4;

/**
 * 顧客フラグの種別定義・割り当ての解決、予約ブロック判定、警告文の生成を行う。
 *
 * 会員のフラグは顧客（予約者ユーザー）の状態として、ユーザー ID に割り当てる。
 * メールアドレスに割り当てると、顧客がプロフィールでメールアドレスを変えるだけでフラグが外れてしまうため。
 * ユーザーを持たないゲスト（会員登録なしで予約するお客様）のフラグだけは、メールアドレスと電話番号（そろえた値）に割り当てる。
 */
class Customer_Flag_Service {
	/**
	 * 永続化リポジトリ。
	 *
	 * @var Customer_Flag_Repository
	 */
	private $repository;

	/**
	 * 直近の save_types() で、削除した種別のゲストの記録を消せなかったか。
	 *
	 * @var bool
	 */
	private $guest_cleanup_failed = false;

	/**
	 * コンストラクタ。
	 *
	 * @param Customer_Flag_Repository|null $repository 永続化リポジトリ。未指定なら既定実装を生成する。
	 */
	public function __construct( ?Customer_Flag_Repository $repository = null ) {
		$this->repository = $repository ?? new Customer_Flag_Repository();
	}

	/**
	 * 指定ユーザーにフラグを割り当てられるか（予約顧客か）を返す。
	 *
	 * 管理者・オーナー・スタッフは対象外にする。代理予約で会員と紐付かなかった予約は
	 * 代理入力した管理者が予約者になるため、その予約からフラグを付けて管理者自身に付くのを防ぐ。
	 *
	 * @param int $user_id ユーザー ID。
	 * @return bool 予約顧客なら true。
	 */
	public function can_have_flag( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		return $user instanceof WP_User && Auth_Shortcodes::is_booking_customer( $user );
	}

	/**
	 * 入力されたフラグ種別定義をサニタイズして正規化する。
	 *
	 * - ラベルが空の行と、削除にチェックが入った行はスキップする。
	 * - ID が未指定の新規行には、ラベルと無関係なランダムな ID を生成する。
	 * - 同一 ID は先勝ちで重複排除する。
	 *
	 * @param array<int, array<string, mixed>> $raw_types 生の種別定義リスト。
	 * @return array<int, array<string, mixed>> サニタイズ済みの種別定義リスト。
	 */
	public function sanitize_types( array $raw_types ): array {
		$sanitized = array();

		foreach ( $raw_types as $raw_type ) {
			if ( ! is_array( $raw_type ) ) {
				continue;
			}

			// 削除にチェックが入った行は登録しない。
			if ( ! empty( $raw_type['delete'] ) ) {
				continue;
			}

			// ラベルは店舗が表示名として使うため必須。空行は登録対象外とする。
			$label = sanitize_text_field( (string) ( $raw_type['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			// 既存行は ID を保持し、新規行にはランダムな ID を生成する。
			// ラベルから ID を作ると、削除した種別と同名の種別を作り直したときに同じ ID になり、
			// 削除前に割り当てていた顧客のフラグ（予約ブロックを含む）が操作なしで復活してしまうため。
			$id = sanitize_key( (string) ( $raw_type['id'] ?? '' ) );
			if ( '' === $id ) {
				do {
					$id = 'flag_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
				} while ( isset( $sanitized[ $id ] ) || $this->has_raw_type_id( $raw_types, $id ) );
			}

			// 同一 ID の重複は先に登録されたものを優先する。
			if ( isset( $sanitized[ $id ] ) ) {
				continue;
			}

			$sanitized[ $id ] = array(
				'id'             => $id,
				'label'          => $label,
				'blocks_booking' => ! empty( $raw_type['blocks_booking'] ),
				'block_message'  => sanitize_textarea_field( (string) ( $raw_type['block_message'] ?? '' ) ),
			);
		}

		// 連番の配列（リスト）として返す。
		return array_values( $sanitized );
	}

	/**
	 * 生の種別定義リストに、指定 ID の行が含まれるかを返す。
	 *
	 * 新規行へ生成した ID が、同じ送信内の既存行の ID と衝突しないかの確認に使う。
	 *
	 * @param array<int, mixed> $raw_types 生の種別定義リスト。
	 * @param string            $id        確認する ID。
	 * @return bool 含まれていれば true。
	 */
	private function has_raw_type_id( array $raw_types, string $id ): bool {
		foreach ( $raw_types as $raw_type ) {
			if ( is_array( $raw_type ) && sanitize_key( (string) ( $raw_type['id'] ?? '' ) ) === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * サニタイズ済みの種別定義を保存する。
	 *
	 * 既存の種別でラベルだけが空にされた行は、保存済みのラベルに戻して残す。
	 * ラベル欄を消しただけで種別が削除され、割り当て済みの顧客のフラグが黙って外れるのを防ぐため
	 * （削除は削除欄のチェックで行う）。
	 *
	 * @param array<int, array<string, mixed>> $raw_types 生の種別定義リスト。
	 * @return bool 保存に成功したら true。
	 */
	public function save_types( array $raw_types ): bool {
		// 保存済みの種別のラベルを ID で引けるようにする。
		$stored_labels = array();
		foreach ( $this->get_types() as $type ) {
			$stored_labels[ (string) $type['id'] ] = (string) $type['label'];
		}

		// 既存の種別でラベルが空の行は、保存済みのラベルを補う。
		foreach ( $raw_types as $index => $raw_type ) {
			if ( ! is_array( $raw_type ) ) {
				continue;
			}

			$id    = sanitize_key( (string) ( $raw_type['id'] ?? '' ) );
			$label = trim( (string) ( $raw_type['label'] ?? '' ) );
			if ( '' !== $id && '' === $label && isset( $stored_labels[ $id ] ) ) {
				$raw_types[ $index ]['label'] = $stored_labels[ $id ];
			}
		}

		$sanitized                  = $this->sanitize_types( $raw_types );
		$saved                      = $this->repository->save_types( $sanitized );
		$this->guest_cleanup_failed = false;

		// 削除された種別の割り当て（顧客についてのメモを含む）も消す。
		// 残しておいても種別が無ければ無効だが、画面では「解除される」と案内しているため実データも合わせる。
		if ( $saved ) {
			$removed_ids = array_diff( array_keys( $stored_labels ), array_column( $sanitized, 'id' ) );
			$removed_ids = array_values( array_map( 'strval', $removed_ids ) );
			$this->repository->delete_assignments_by_type_ids( $removed_ids );
			// 種別に紐づかなくなったゲストの記録も消す。排他を取れずに残った場合は、次の保存でも消せるよう残った記録そのものを基準にする。
			$this->guest_cleanup_failed = ! $this->delete_orphaned_guest_flags( array_column( $sanitized, 'id' ) );
		}

		return $saved;
	}

	/**
	 * 直近の save_types() で、削除した種別のゲストの記録を消せなかったか。
	 *
	 * 種別の保存自体は成功していても、記録の書き換えの排他を取れずに記録が残ることがある。
	 * その場合は設定画面で知らせ、もう一度保存すれば消せるようにする。
	 *
	 * @return bool 消せなかった記録が残っていれば true。
	 */
	public function has_guest_cleanup_failed(): bool {
		return $this->guest_cleanup_failed;
	}

	/**
	 * 保存済みのフラグ種別定義（リスト）を返す。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_types(): array {
		return $this->sanitize_types( $this->repository->get_types() );
	}

	/**
	 * 指定 ID のフラグ種別定義を返す。
	 *
	 * @param string $type_id 種別 ID。
	 * @return array<string, mixed>|null 見つからない場合は null。
	 */
	public function get_type( string $type_id ): ?array {
		$type_id = sanitize_key( $type_id );

		foreach ( $this->get_types() as $type ) {
			if ( isset( $type['id'] ) && $type['id'] === $type_id ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * 顧客（ユーザー）にフラグを割り当てる。
	 *
	 * 種別 ID が空文字の場合は、その顧客の割り当てを解除する（編集欄での「なし」選択に対応）。
	 *
	 * @param int    $user_id     顧客のユーザー ID。
	 * @param string $type_id     付与するフラグ種別 ID。空文字なら解除。
	 * @param string $memo        メモ（理由）。
	 * @param int    $assigned_by 操作したユーザー ID。
	 * @return bool 保存に成功したら true。無効な入力なら false。
	 */
	public function set_flag( int $user_id, string $type_id, string $memo = '', int $assigned_by = 0 ): bool {
		// 予約顧客以外（管理者・オーナー・スタッフ）にはフラグを付けない。
		if ( ! $this->can_have_flag( $user_id ) ) {
			return false;
		}

		$type_id = sanitize_key( $type_id );

		// 種別 ID が空なら割り当て解除として扱う。
		if ( '' === $type_id ) {
			return $this->clear_flag( $user_id );
		}

		// 存在しない種別 ID は割り当てない（不正値の保存を防ぐ）。
		if ( null === $this->get_type( $type_id ) ) {
			return false;
		}

		$memo = sanitize_textarea_field( $memo );

		// 種別・メモが既存と同一なら、実質的な変更が無いため監査情報（assigned_at / assigned_by）を温存して何もしない。
		// current_time() を毎回生成するとリポジトリ側の「変更なし」判定が崩れ、再保存のたびに監査情報が上書きされてしまうため。
		$current = $this->repository->get_assignment( $user_id );
		if (
			is_array( $current )
			&& sanitize_key( (string) ( $current['type_id'] ?? '' ) ) === $type_id
			&& (string) ( $current['memo'] ?? '' ) === $memo
		) {
			return true;
		}

		return $this->repository->save_assignment(
			$user_id,
			array(
				'type_id'     => $type_id,
				'memo'        => $memo,
				'assigned_by' => $assigned_by,
				'assigned_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * 顧客（ユーザー）のフラグ割り当てを解除する。
	 *
	 * @param int $user_id 顧客のユーザー ID。
	 * @return bool 保存に成功したら true。割り当てが無い場合も true（冪等）。
	 */
	public function clear_flag( int $user_id ): bool {
		return $this->repository->delete_assignment( $user_id );
	}

	/**
	 * 顧客（ユーザー）に割り当てられたフラグを、種別定義とマージして解決する。
	 *
	 * 割り当てが無い場合や、割り当て先の種別が削除済みの場合は null を返す。
	 *
	 * @param int $user_id 顧客のユーザー ID。
	 * @return array<string, mixed>|null 解決済みフラグ情報。無い場合は null。
	 */
	public function get_flag( int $user_id ): ?array {
		$assignment = $this->repository->get_assignment( $user_id );
		if ( ! is_array( $assignment ) ) {
			return null;
		}

		$type_id = sanitize_key( (string) ( $assignment['type_id'] ?? '' ) );
		$type    = $this->get_type( $type_id );

		// 種別が削除済みの場合は、有効なフラグが無いものとして扱う。
		if ( null === $type ) {
			return null;
		}

		return array(
			'user_id'        => $user_id,
			'type_id'        => $type_id,
			'label'          => (string) $type['label'],
			'blocks_booking' => ! empty( $type['blocks_booking'] ),
			'block_message'  => (string) $type['block_message'],
			'memo'           => (string) ( $assignment['memo'] ?? '' ),
			'assigned_by'    => (int) ( $assignment['assigned_by'] ?? 0 ),
			'assigned_at'    => (string) ( $assignment['assigned_at'] ?? '' ),
		);
	}

	/**
	 * 顧客（ユーザー）が予約ブロック対象かどうかを返す。
	 *
	 * @param int $user_id 顧客のユーザー ID。
	 * @return bool ブロック対象なら true。
	 */
	public function is_blocked( int $user_id ): bool {
		$flag = $this->get_flag( $user_id );

		return null !== $flag && ! empty( $flag['blocks_booking'] );
	}

	/**
	 * メールアドレスか電話番号が一致する、予約をブロックする種別のフラグが付いた会員を探す。
	 *
	 * ゲスト予約（ユーザーを持たない予約）を、会員に付けたフラグで止めるために使う（docs/specification-guest-booking.md「7. 顧客フラグ」(a)）。
	 * 照合先は会員の今のメールアドレスと電話番号。メールは前後の空白を除いた小文字、電話は全角を半角にして数字以外を除いた値で比べる。
	 *
	 * @param string $email そろえたメールアドレス（空なら照合しない）。
	 * @param string $phone そろえた電話番号（空なら照合しない）。
	 * @return int 一致したブロック対象の会員のユーザー ID。無ければ 0。
	 */
	public function find_blocked_user_id_by_contact( string $email, string $phone ): int {
		$candidates = array();

		$email = VKBM_Helper::normalize_email( $email );
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof WP_User ) {
				$candidates[] = (int) $user->ID;
			}
		}

		$phone = VKBM_Helper::normalize_phone_number( $phone );
		if ( '' !== $phone ) {
			$ids = get_users(
				array(
					'meta_key'    => 'phone_number',
					'meta_value'  => $phone,
					'fields'      => 'ID',
					'count_total' => false,
				)
			);
			foreach ( $ids as $id ) {
				$candidates[] = (int) $id;
			}
		}

		foreach ( array_unique( $candidates ) as $user_id ) {
			if ( $this->is_blocked( $user_id ) ) {
				return $user_id;
			}
		}

		return 0;
	}

	/**
	 * ゲストに付けたフラグの記録（保存された形のまま）を返す。
	 *
	 * @return array<int, array<string, mixed>> 記録のリスト。
	 */
	public function get_guest_records(): array {
		return $this->repository->get_guest_records();
	}

	/**
	 * 連絡先に一致する、ゲストに付けたフラグを返す。
	 *
	 * メールアドレスか電話番号のどちらかが一致した記録を、種別定義とマージして返す。種別が削除済みの記録は含めない。
	 * 空の連絡先は照合しない（記録側が空の項目と、空の連絡先が一致してしまうのを防ぐため）。
	 *
	 * @param string $email メールアドレス（そろえる前でもよい）。
	 * @param string $phone 電話番号（そろえる前でもよい）。
	 * @return array<int, array<string, mixed>> 解決済みフラグ情報のリスト。matched に一致した項目（email・phone）を持つ。
	 */
	public function find_guest_flags( string $email, string $phone ): array {
		$email = VKBM_Helper::normalize_email( $email );
		$phone = VKBM_Helper::normalize_phone_number( $phone );

		if ( '' === $email && '' === $phone ) {
			return array();
		}

		$flags       = array();
		$types_by_id = $this->get_types_by_id();
		foreach ( $this->repository->get_guest_records() as $record ) {
			$matched = $this->get_matched_fields( $record, $email, $phone );
			if ( array() === $matched ) {
				continue;
			}

			$flag = $this->resolve_guest_record( $record, $types_by_id );
			if ( null === $flag ) {
				continue;
			}

			$flag['matched'] = $matched;
			$flags[]         = $flag;
		}

		return $flags;
	}

	/**
	 * 連絡先に一致する、予約をブロックする種別のゲストのフラグを返す。
	 *
	 * @param string $email メールアドレス（そろえる前でもよい）。
	 * @param string $phone 電話番号（そろえる前でもよい）。
	 * @return array<string, mixed>|null ブロック対象の解決済みフラグ情報。無ければ null。
	 */
	public function find_blocking_guest_flag( string $email, string $phone ): ?array {
		foreach ( $this->find_guest_flags( $email, $phone ) as $flag ) {
			if ( ! empty( $flag['blocks_booking'] ) ) {
				return $flag;
			}
		}

		return null;
	}

	/**
	 * 会員の今のメールアドレス・電話番号が、ブロック対象のゲストのフラグと一致するかを返す。
	 *
	 * 同じ人がゲストでフラグを付けられた後に会員登録しても、フラグが効くようにするために使う。
	 *
	 * @param int $user_id 会員のユーザー ID。
	 * @return array<string, mixed>|null ブロック対象の解決済みフラグ情報。無ければ null。
	 */
	public function find_blocking_guest_flag_for_user( int $user_id ): ?array {
		// ゲストの記録が無いサイトは、ユーザー情報を読まずに終える（従来の動きを変えない）。
		if ( array() === $this->repository->get_guest_records() ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return null;
		}

		return $this->find_blocking_guest_flag( (string) $user->user_email, (string) get_user_meta( $user_id, 'phone_number', true ) );
	}

	/**
	 * ゲストの連絡先にフラグを付ける（付け直す）。
	 *
	 * 種別 ID が空文字なら、連絡先に一致する記録をすべて解除する。
	 * 付けるときは、一致した側の連絡先だけを既存の記録から外し（空になった記録は消す）、この予約の連絡先で 1 件を足す。
	 * 連絡先は、そろえた値をそのまま保存する。
	 *
	 * @param string $email             メールアドレス（そろえる前でもよい）。
	 * @param string $phone             電話番号（そろえる前でもよい）。
	 * @param string $type_id           付けるフラグ種別 ID。空文字なら解除。
	 * @param string $memo              メモ（理由）。
	 * @param int    $assigned_by       操作したユーザー ID。
	 * @param int    $source_booking_id フラグを付けた予約の ID。
	 * @return bool 保存に成功したら true。無効な入力なら false。
	 */
	public function set_guest_flag( string $email, string $phone, string $type_id, string $memo = '', int $assigned_by = 0, int $source_booking_id = 0 ): bool {
		$email = VKBM_Helper::normalize_email( $email );
		$phone = VKBM_Helper::normalize_phone_number( $phone );

		// 連絡先がひとつも無ければ、誰に付けるか決められない。
		if ( '' === $email && '' === $phone ) {
			return false;
		}

		$type_id = sanitize_key( $type_id );
		if ( '' === $type_id ) {
			return $this->clear_guest_flag( $email, $phone );
		}

		// 存在しない種別 ID は割り当てない（不正値の保存を防ぐ）。
		if ( null === $this->get_type( $type_id ) ) {
			return false;
		}

		$memo = sanitize_textarea_field( $memo );

		// 排他の中で最新の記録を読み直し、一致した連絡先だけを既存の記録から外して、この予約の連絡先で 1 件を足す。
		return $this->repository->update_guest_records(
			function ( array $records ) use ( $email, $phone, $type_id, $memo, $assigned_by, $source_booking_id ): ?array {
				$kept     = array();
				$existing = array();
				foreach ( $records as $record ) {
					$matched = $this->get_matched_fields( $record, $email, $phone );
					if ( array() === $matched ) {
						$kept[] = $record;
						continue;
					}

					$existing[] = $record;

					// 一致した側の連絡先だけを取り除く。一致しなかった側の連絡先は、元の記録のまま残す（その連絡先のフラグを外さないため）。
					$remaining = $record;
					if ( in_array( 'email', $matched, true ) ) {
						$remaining['email'] = '';
					}
					if ( in_array( 'phone', $matched, true ) ) {
						$remaining['phone'] = '';
					}
					if ( '' !== (string) ( $remaining['email'] ?? '' ) || '' !== (string) ( $remaining['phone'] ?? '' ) ) {
						$kept[] = $remaining;
					}
				}

				// 連絡先・種別・メモが既存の 1 件と同一なら、監査情報（assigned_at / assigned_by）を温存して何もしない。
				if (
					1 === count( $existing )
					&& (string) ( $existing[0]['email'] ?? '' ) === $email
					&& (string) ( $existing[0]['phone'] ?? '' ) === $phone
					&& sanitize_key( (string) ( $existing[0]['type_id'] ?? '' ) ) === $type_id
					&& (string) ( $existing[0]['memo'] ?? '' ) === $memo
				) {
					return null;
				}

				$kept[] = array(
					'id'                => $this->generate_guest_record_id( $kept ),
					'email'             => $email,
					'phone'             => $phone,
					'type_id'           => $type_id,
					'memo'              => $memo,
					'assigned_by'       => $assigned_by,
					'assigned_at'       => current_time( 'mysql' ),
					'source_booking_id' => $source_booking_id,
				);

				return $kept;
			}
		);
	}

	/**
	 * ゲストの連絡先に一致するフラグの記録を、すべて解除する。
	 *
	 * @param string $email メールアドレス（そろえる前でもよい）。
	 * @param string $phone 電話番号（そろえる前でもよい）。
	 * @return bool 保存に成功したら true。一致する記録が無い場合も true（冪等）。連絡先が両方空なら false。
	 */
	public function clear_guest_flag( string $email, string $phone ): bool {
		$email = VKBM_Helper::normalize_email( $email );
		$phone = VKBM_Helper::normalize_phone_number( $phone );

		if ( '' === $email && '' === $phone ) {
			return false;
		}

		return $this->repository->update_guest_records(
			function ( array $records ) use ( $email, $phone ): array {
				return array_values(
					array_filter(
						$records,
						function ( array $record ) use ( $email, $phone ): bool {
							return array() === $this->get_matched_fields( $record, $email, $phone );
						}
					)
				);
			}
		);
	}

	/**
	 * 記録の ID を指定して、ゲストのフラグを解除する（一覧画面の解除ボタン用）。
	 *
	 * @param string $record_id 記録の ID。
	 * @return bool 解除した（または既に無い）ら true。ID が空なら false。
	 */
	public function delete_guest_flag_by_id( string $record_id ): bool {
		$record_id = sanitize_key( $record_id );
		if ( '' === $record_id ) {
			return false;
		}

		return $this->repository->update_guest_records(
			static function ( array $records ) use ( $record_id ): array {
				return array_values(
					array_filter(
						$records,
						static function ( array $record ) use ( $record_id ): bool {
							return sanitize_key( (string) ( $record['id'] ?? '' ) ) !== $record_id;
						}
					)
				);
			}
		);
	}

	/**
	 * 残っている種別に紐づかないゲストの記録を、すべて削除する。
	 *
	 * 種別を削除したときに、その種別の記録（連絡先とメモを含む）を残さないために使う。
	 * 削除した種別の ID ではなく残る種別の ID を基準にするので、前回消し損ねた記録も次の保存で消せる。
	 *
	 * @param array<int, string> $valid_type_ids 残っている種別の ID。
	 * @return bool 消す必要が無い、または消せたら true。排他を取れず消せなかったら false。
	 */
	private function delete_orphaned_guest_flags( array $valid_type_ids ): bool {
		$valid_type_ids = array_values( array_map( 'strval', $valid_type_ids ) );
		$is_orphan      = static function ( array $record ) use ( $valid_type_ids ): bool {
			return ! in_array( sanitize_key( (string) ( $record['type_id'] ?? '' ) ), $valid_type_ids, true );
		};

		// 該当する記録が無ければ、排他を取らずに終える。
		if ( array() === array_filter( $this->repository->get_guest_records(), $is_orphan ) ) {
			return true;
		}

		return $this->repository->update_guest_records(
			static function ( array $records ) use ( $is_orphan ): array {
				return array_values(
					array_filter(
						$records,
						static function ( array $record ) use ( $is_orphan ): bool {
							return ! $is_orphan( $record );
						}
					)
				);
			}
		);
	}

	/**
	 * 一覧画面に出す、ゲストに付けたフラグを返す。
	 *
	 * @param string $type_id 種別 ID で絞り込む場合に指定する。空文字なら絞り込まない。
	 * @param string $search  メールアドレス・電話番号の一部で検索する語。空文字なら検索しない。電話番号は数字だけにそろえて比べる。
	 * @return array<int, array<string, mixed>> 解決済みフラグ情報のリスト（付けた日時の新しい順）。
	 */
	public function list_guest_flags( string $type_id = '', string $search = '' ): array {
		$type_id      = sanitize_key( $type_id );
		$search       = strtolower( trim( $search ) );
		$search_phone = VKBM_Helper::normalize_phone_number( $search );
		$flags        = array();
		$types_by_id  = $this->get_types_by_id();

		foreach ( $this->repository->get_guest_records() as $record ) {
			$flag = $this->resolve_guest_record( $record, $types_by_id );
			if ( null === $flag ) {
				continue;
			}

			if ( '' !== $type_id && $flag['type_id'] !== $type_id ) {
				continue;
			}

			if ( '' !== $search ) {
				$email_hit = false !== strpos( (string) $flag['email'], $search );
				$phone_hit = '' !== $search_phone && false !== strpos( (string) $flag['phone'], $search_phone );
				if ( ! $email_hit && ! $phone_hit ) {
					continue;
				}
			}

			$flags[] = $flag;
		}

		// 付けた日時の新しい順に並べる。
		usort(
			$flags,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['assigned_at'], (string) $a['assigned_at'] );
			}
		);

		return $flags;
	}

	/**
	 * 個人データの消去依頼に合わせて、メールアドレスと電話番号に付いたゲストのフラグを消す。
	 *
	 * メールアドレスが一致する記録（記録内の電話番号も一緒に消える）と、渡された電話番号に一致する記録を消す。
	 *
	 * @param string             $email  消去依頼のメールアドレス。
	 * @param array<int, string> $phones そのメールアドレスの予約に入っている電話番号（そろえる前でもよい）。
	 * @return int|null 消した記録の件数。記録の書き換えの排他を取れない・保存に失敗したときは null（消せたか分からないので、完了扱いにしない）。
	 */
	public function erase_guest_flags( string $email, array $phones ): ?int {
		$email = VKBM_Helper::normalize_email( $email );
		if ( '' === $email ) {
			return 0;
		}

		// 電話番号をそろえ、重複を除く。
		$normalized_phones = array();
		foreach ( $phones as $phone ) {
			$normalized = VKBM_Helper::normalize_phone_number( (string) $phone );
			if ( '' !== $normalized ) {
				$normalized_phones[ $normalized ] = true;
			}
		}

		// 排他の中で最新の記録を読み直し、メールが一致する記録と電話番号が一致する記録を除いて残す。
		$removed = 0;
		$saved   = $this->repository->update_guest_records(
			function ( array $records ) use ( $email, $normalized_phones, &$removed ): ?array {
				$kept = array();
				foreach ( $records as $record ) {
					$record_phone = (string) ( $record['phone'] ?? '' );
					if ( (string) ( $record['email'] ?? '' ) === $email || ( '' !== $record_phone && isset( $normalized_phones[ $record_phone ] ) ) ) {
						continue;
					}
					$kept[] = $record;
				}

				$removed = count( $records ) - count( $kept );

				return $removed > 0 ? $kept : null;
			}
		);

		return $saved ? $removed : null;
	}

	/**
	 * 記録のうち、渡された連絡先と一致する項目を返す。
	 *
	 * @param array<string, mixed> $record 保存された記録。
	 * @param string               $email  そろえたメールアドレス（空なら照合しない）。
	 * @param string               $phone  そろえた電話番号（空なら照合しない）。
	 * @return array<int, string> 一致した項目（email・phone）。どちらも一致しなければ空配列。
	 */
	private function get_matched_fields( array $record, string $email, string $phone ): array {
		$matched = array();

		if ( '' !== $email && (string) ( $record['email'] ?? '' ) === $email ) {
			$matched[] = 'email';
		}
		if ( '' !== $phone && (string) ( $record['phone'] ?? '' ) === $phone ) {
			$matched[] = 'phone';
		}

		return $matched;
	}

	/**
	 * 種別定義を ID で引ける対応表にして返す（記録ごとに種別を読み直さないため、1 回の処理で 1 度だけ作る）。
	 *
	 * @return array<string, array<string, mixed>> 種別 ID をキーにした種別定義。
	 */
	private function get_types_by_id(): array {
		$types_by_id = array();
		foreach ( $this->get_types() as $type ) {
			$types_by_id[ (string) $type['id'] ] = $type;
		}

		return $types_by_id;
	}

	/**
	 * ゲストの記録を種別定義とマージして解決する。
	 *
	 * @param array<string, mixed>               $record      保存された記録。
	 * @param array<string, array<string,mixed>> $types_by_id get_types_by_id() が返す種別の対応表。
	 * @return array<string, mixed>|null 解決済みフラグ情報。種別が削除済みなら null。
	 */
	private function resolve_guest_record( array $record, array $types_by_id ): ?array {
		$type_id = sanitize_key( (string) ( $record['type_id'] ?? '' ) );
		$type    = $types_by_id[ $type_id ] ?? null;
		if ( null === $type ) {
			return null;
		}

		return array(
			'id'                => sanitize_key( (string) ( $record['id'] ?? '' ) ),
			'email'             => (string) ( $record['email'] ?? '' ),
			'phone'             => (string) ( $record['phone'] ?? '' ),
			'type_id'           => $type_id,
			'label'             => (string) $type['label'],
			'blocks_booking'    => ! empty( $type['blocks_booking'] ),
			'block_message'     => (string) $type['block_message'],
			'memo'              => (string) ( $record['memo'] ?? '' ),
			'assigned_by'       => (int) ( $record['assigned_by'] ?? 0 ),
			'assigned_at'       => (string) ( $record['assigned_at'] ?? '' ),
			'source_booking_id' => (int) ( $record['source_booking_id'] ?? 0 ),
		);
	}

	/**
	 * ゲストの記録に付ける、ランダムな ID を作る。
	 *
	 * @param array<int, array<string, mixed>> $records 既存の記録（ID の重複を避けるため）。
	 * @return string 記録の ID。
	 */
	private function generate_guest_record_id( array $records ): string {
		$used = array_column( $records, 'id' );

		do {
			$id = 'g' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		} while ( in_array( $id, $used, true ) );

		return $id;
	}

	/**
	 * ゲストのフラグに対する、ブロック時に表示するメッセージを返す。
	 *
	 * @param array<string, mixed> $flag find_guest_flags() が返す解決済みフラグ情報。
	 * @return string ブロックメッセージ。種別に設定が無ければ既定のメッセージ。
	 */
	public function get_guest_block_message( array $flag ): string {
		return $this->resolve_block_message( (string) ( $flag['block_message'] ?? '' ) );
	}

	/**
	 * 種別のブロック時メッセージが空なら、既定のメッセージに置き換えて返す。
	 *
	 * @param string $message 種別に設定されたメッセージ。
	 * @return string 表示するメッセージ。
	 */
	private function resolve_block_message( string $message ): string {
		$message = trim( $message );
		if ( '' !== $message ) {
			return $message;
		}

		return $this->get_default_block_message();
	}

	/**
	 * ブロック時に顧客へ表示するメッセージを返す。
	 *
	 * 種別ごとのメッセージが未設定の場合は、汎用の既定メッセージを返す。
	 *
	 * @param int $user_id 顧客のユーザー ID。
	 * @return string ブロックメッセージ。ブロック対象でない場合は空文字。
	 */
	public function get_block_message( int $user_id ): string {
		$flag = $this->get_flag( $user_id );

		if ( null === $flag || empty( $flag['blocks_booking'] ) ) {
			return '';
		}

		return $this->resolve_block_message( (string) $flag['block_message'] );
	}

	/**
	 * 種別にブロック時メッセージが設定されていない場合に表示する既定の文言を返す。
	 *
	 * 設定ページの入力欄に、未入力時の表示内容として示すためにも使う。
	 *
	 * @return string 既定のブロック時メッセージ。
	 */
	public function get_default_block_message(): string {
		// 1 つの翻訳関数に 1 文ずつ入れ、言語に応じた区切りで結合する。
		return Nomination_Min_Guests_Message::join_sentences(
			__( 'This reservation cannot be accepted.', 'vk-booking-manager' ),
			sprintf(
				/* translators: %s: provider (site) name */
				__( 'Please contact %s.', 'vk-booking-manager' ),
				$this->get_contact_name()
			)
		);
	}

	/**
	 * 問い合わせ先として表示する名前を返す。
	 *
	 * 基本設定の事業者名を使い、未設定ならサイト名に落とす。
	 *
	 * @return string 事業者名。未設定ならサイト名。
	 */
	private function get_contact_name(): string {
		$repository = new Settings_Repository();

		return Provider_Display_Name::resolve( $repository->get_settings() );
	}
}
