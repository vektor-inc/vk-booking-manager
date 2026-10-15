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
use VKBookingManager\ProviderSettings\Provider_Display_Name;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_User;
use function __;
use function current_time;
use function get_userdata;
use function is_array;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function trim;
use function wp_generate_uuid4;

/**
 * 顧客フラグの種別定義・割り当ての解決、予約ブロック判定、警告文の生成を行う。
 *
 * フラグは顧客（予約者ユーザー）の状態として、ユーザー ID に割り当てる。
 * メールアドレスに割り当てると、顧客がプロフィールでメールアドレスを変えるだけでフラグが外れてしまうため。
 */
class Customer_Flag_Service {
	/**
	 * 永続化リポジトリ。
	 *
	 * @var Customer_Flag_Repository
	 */
	private $repository;

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

		$sanitized = $this->sanitize_types( $raw_types );
		$saved     = $this->repository->save_types( $sanitized );

		// 削除された種別の割り当て（顧客についてのメモを含む）も消す。
		// 残しておいても種別が無ければ無効だが、画面では「解除される」と案内しているため実データも合わせる。
		if ( $saved ) {
			$removed_ids = array_diff( array_keys( $stored_labels ), array_column( $sanitized, 'id' ) );
			$this->repository->delete_assignments_by_type_ids( array_values( array_map( 'strval', $removed_ids ) ) );
		}

		return $saved;
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

		$message = trim( (string) $flag['block_message'] );
		if ( '' !== $message ) {
			return $message;
		}

		return $this->get_default_block_message();
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
