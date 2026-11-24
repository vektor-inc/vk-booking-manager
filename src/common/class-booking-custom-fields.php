<?php
/**
 * 予約時の入力項目（施設が BM 設定で追加する入力欄）の定義と回答を扱う。
 *
 * 仕様は docs/specification-booking-custom-fields.md を正とする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use VKBookingManager\Bookings\Booking_Draft_Controller;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_Error;

use function __;
use function get_post_meta;
use function is_array;
use function is_scalar;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sprintf;
use function strtolower;
use function wp_generate_password;

/**
 * 予約時の入力項目の共通処理。
 */
class Booking_Custom_Fields {
	/**
	 * 項目の定義を保存する設定キー（option `vkbm_provider_settings` の中）。
	 */
	public const SETTINGS_KEY = 'booking_custom_fields';

	/**
	 * 設定画面がこのタブの欄を送ったことを示す印。項目が 0 件の保存でも「全部削除した」と判別するために使う。
	 */
	public const SETTINGS_SUBMITTED_KEY = 'booking_custom_fields_submitted';

	/**
	 * 回答を保存する予約メタのキー。
	 */
	public const META_KEY = '_vkbm_booking_custom_fields';

	/**
	 * 予約の確定・下書き・ゲスト予約の REST で回答を受け取るパラメータ名。
	 */
	public const REQUEST_PARAM = 'custom_fields';

	/**
	 * 登録できる項目の上限。
	 */
	public const MAX_FIELDS = 10;

	/**
	 * 項目名の上限文字数。
	 */
	public const LABEL_MAX_LENGTH = 100;

	/**
	 * 1 行のテキストの上限文字数（「ご利用になる方のお名前」と揃える）。
	 */
	public const TEXT_MAX_LENGTH = 200;

	/**
	 * 入力欄の種類: 1 行のテキスト。
	 */
	public const TYPE_TEXT = 'text';

	/**
	 * 入力欄の種類: 複数行のテキスト。
	 */
	public const TYPE_TEXTAREA = 'textarea';

	/** 選択式の入力欄。 */
	public const TYPE_SELECT = 'select';

	/** ラジオボタンの入力欄。 */
	public const TYPE_RADIO = 'radio';

	/** 数値の入力欄。 */
	public const TYPE_NUMBER = 'number';

	/**
	 * 項目 ID の形式。設定画面で項目を追加したときに振り、以後変えない。
	 */
	private const ID_PATTERN = '/^cf_[a-z0-9]{1,32}$/';

	/**
	 * 選べる入力欄の種類を返す。
	 *
	 * @return string[] 種類の一覧。
	 */
	public static function get_types(): array {
		return array( self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_NUMBER );
	}

	/**
	 * 入力欄の種類ごとの表示名を返す（設定画面のプルダウン用）。
	 *
	 * @return array<string, string> 種類 => 表示名。
	 */
	public static function get_type_labels(): array {
		return array(
			self::TYPE_TEXT     => __( 'Single-line text', 'vk-booking-manager' ),
			self::TYPE_TEXTAREA => __( 'Multi-line text', 'vk-booking-manager' ),
			self::TYPE_SELECT   => __( 'Select box', 'vk-booking-manager' ),
			self::TYPE_RADIO    => __( 'Radio buttons', 'vk-booking-manager' ),
			self::TYPE_NUMBER   => __( 'Number', 'vk-booking-manager' ),
		);
	}

	/**
	 * 種類ごとの回答の上限文字数を返す。
	 *
	 * 複数行は「ご要望・備考」と同じ上限（vkbm_draft_memo_max_length フィルタを反映した値）を使う。
	 *
	 * @param string $type 入力欄の種類。
	 * @return int 上限文字数。
	 */
	public static function get_max_length( string $type ): int {
		return self::TYPE_TEXTAREA === $type ? Booking_Draft_Controller::resolve_memo_max_length() : self::TEXT_MAX_LENGTH;
	}

	/**
	 * 項目 ID の形式が正しいか判定する。
	 *
	 * @param mixed $id 項目 ID。
	 * @return bool 正しければ true。
	 */
	public static function is_valid_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * 新しい項目 ID を作る。
	 *
	 * @return string 項目 ID（例: cf_a1b2c3d4）。
	 */
	public static function generate_id(): string {
		return 'cf_' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * 設定値から、項目の定義を設定した順に返す。
	 *
	 * 保存済みの値も読み出しのたびに正規化し、形の崩れた行は捨てる（エラーは出さない）。
	 *
	 * @param array<string, mixed>|null $settings 設定値。null なら保存済みの設定を読む。
	 * @return array<int, array{id: string, label: string, type: string, required: bool}> 項目の定義。
	 */
	public static function get_definitions( ?array $settings = null ): array {
		$settings = $settings ?? ( new Settings_Repository() )->get_settings();

		return self::normalize_definitions( $settings[ self::SETTINGS_KEY ] ?? array() );
	}

	/**
	 * 保存済みの定義を正規化する。ID・項目名が無い行と、重複した ID の行は捨て、上限件数で切る。
	 *
	 * @param mixed $raw 定義の配列。
	 * @return array<int, array{id: string, label: string, type: string, required: bool}> 項目の定義。
	 */
	public static function normalize_definitions( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$definitions = array();
		$seen_ids    = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id    = $row['id'] ?? '';
			$label = self::sanitize_label( $row['label'] ?? '' );
			if ( ! self::is_valid_id( $id ) || '' === $label || isset( $seen_ids[ $id ] ) ) {
				continue;
			}
			$seen_ids[ $id ] = true;
			$type            = self::sanitize_type( $row['type'] ?? '' );
			$definition      = array(
				'id'       => $id,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $row['required'] ),
			);
			if ( self::TYPE_SELECT === $type || self::TYPE_RADIO === $type ) {
				$definition['choices'] = self::sanitize_choices( $row['choices'] ?? array() );
			} elseif ( self::TYPE_NUMBER === $type ) {
				$definition['default_value'] = self::sanitize_number_default( $row['default_value'] ?? '' );
			}
			$definitions[] = $definition;
			if ( count( $definitions ) >= self::MAX_FIELDS ) {
				break;
			}
		}

		return $definitions;
	}

	/**
	 * 設定画面から送られた行を検証して、保存用の定義にする。
	 *
	 * - 行の並びは送られた順（画面の上から順）を保つ。
	 * - ID が無い行（新しく追加した行）と、形式の崩れた・重複した ID の行には新しい ID を振る。
	 * - 項目名が空の行はエラーにする。エラー文は errors、対象入力は error_fields に入れる。
	 * - 上限件数を超えた分はエラーにする（全体のエラーは errors['_all'] に入れる）。
	 *
	 * @param mixed $raw 送られた行（行の番号 => [ id, label, type, required ]）。
	 * @return array{definitions: array<int, array{id: string, label: string, type: string, required: bool}>, errors: array<string, string>, error_fields: array<string, string>} 保存用の定義とエラー。
	 */
	public static function sanitize_definitions_input( $raw ): array {
		$result = array(
			'definitions'  => array(),
			'errors'       => array(),
			'error_fields' => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $result;
		}

		$seen_ids = array();
		foreach ( $raw as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// 項目名が空の行は保存しない（どの行かを画面に示す）。
			$label = self::sanitize_label( $row['label'] ?? '' );
			if ( '' === $label ) {
				$result['errors'][ (string) $index ]       = __( 'Please enter the item name.', 'vk-booking-manager' );
				$result['error_fields'][ (string) $index ] = 'label';
				continue;
			}

			// ID は既存の項目なら引き継ぎ、新しい行・重複した ID には振り直す。
			$id = is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : '';
			if ( ! self::is_valid_id( $id ) || isset( $seen_ids[ $id ] ) ) {
				do {
					$id = self::generate_id();
				} while ( isset( $seen_ids[ $id ] ) );
			}
			$seen_ids[ $id ] = true;

			$type       = self::sanitize_type( $row['type'] ?? '' );
			$definition = array(
				'id'       => $id,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $row['required'] ),
			);
			if ( self::TYPE_SELECT === $type || self::TYPE_RADIO === $type ) {
				$definition['choices'] = self::sanitize_choices( $row['choices'] ?? array() );
				if ( array() === $definition['choices'] ) {
					$result['errors'][ (string) $index ]       = __( 'Please enter at least one choice.', 'vk-booking-manager' );
					$result['error_fields'][ (string) $index ] = 'choices';
				}
			} elseif ( self::TYPE_NUMBER === $type ) {
				$default = is_scalar( $row['default_value'] ?? null ) ? trim( (string) $row['default_value'] ) : '';
				if ( '' !== $default && ! self::is_valid_number( $default ) ) {
					$result['errors'][ (string) $index ]       = __( 'Please enter a valid number.', 'vk-booking-manager' );
					$result['error_fields'][ (string) $index ] = 'default_value';
				}
				$definition['default_value'] = self::sanitize_number_default( $default );
			}
			$result['definitions'][] = $definition;
		}

		if ( count( $result['definitions'] ) > self::MAX_FIELDS ) {
			$result['errors']['_all'] = sprintf(
				/* translators: %d: Maximum number of booking input fields. */
				__( 'You can register up to %d items.', 'vk-booking-manager' ),
				self::MAX_FIELDS
			);
			$result['definitions'] = array_slice( $result['definitions'], 0, self::MAX_FIELDS );
		}

		return $result;
	}

	/**
	 * 予約画面へ渡す定義を返す（REST の provider-settings 用）。回答の上限文字数を添える。
	 *
	 * @param array<string, mixed>|null $settings 設定値。
	 * @return array<int, array{id: string, label: string, type: string, required: bool, max_length: int}> 予約画面用の定義。
	 */
	public static function get_public_definitions( ?array $settings = null ): array {
		$public = array();
		foreach ( self::get_definitions( $settings ) as $definition ) {
			$definition['max_length'] = self::get_max_length( $definition['type'] );
			$public[]                 = $definition;
		}

		return $public;
	}

	/**
	 * 送られた回答から、定義にある項目の値だけを取り出してサニタイズする。
	 *
	 * 定義に無い ID の値は捨てる。上限文字数による切り詰めはしない（検証は validate_values()、保存前の切り詰めは truncate_values() で行う）。
	 *
	 * @param mixed                                                                      $raw         回答（項目 ID => 値）。
	 * @param array<int, array{id: string, label: string, type: string, required: bool}> $definitions 項目の定義。
	 * @param bool                                                                       $only_submitted true なら送信されたキーだけを返す（下書き保存用）。
	 * @return array<string, string> 項目 ID => 値。定義の順。通常は未回答を空文字で補う。
	 */
	public static function sanitize_values( $raw, array $definitions, bool $only_submitted = false ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$values = array();
		foreach ( $definitions as $definition ) {
			if ( $only_submitted && ! array_key_exists( $definition['id'], $raw ) ) {
				continue;
			}
			$value = $raw[ $definition['id'] ] ?? '';
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( self::TYPE_TEXTAREA === $definition['type'] ) {
				$value = trim( sanitize_textarea_field( $value ) );
			} elseif ( self::TYPE_TEXT === $definition['type'] ) {
				$value = trim( sanitize_text_field( $value ) );
			}
			// 選択式と数値は元の文字列を検証する。整形後に一致しても改ざん値を受理しない。
			$values[ $definition['id'] ] = $value;
		}

		return $values;
	}

	/**
	 * 回答を種類ごとの上限文字数に切り詰める。
	 *
	 * @param array<string, string>                                                      $values      項目 ID => 値。
	 * @param array<int, array{id: string, label: string, type: string, required: bool}> $definitions 項目の定義。
	 * @return array<string, string> 切り詰めた回答。
	 */
	public static function truncate_values( array $values, array $definitions ): array {
		foreach ( $definitions as $definition ) {
			$id = $definition['id'];
			if ( ! isset( $values[ $id ] ) ) {
				continue;
			}
			$max_length = self::get_max_length( $definition['type'] );
			if ( mb_strlen( $values[ $id ] ) > $max_length ) {
				$values[ $id ] = mb_substr( $values[ $id ], 0, $max_length );
			}
		}

		return $values;
	}

	/**
	 * 回答を検証し、項目ごとのエラーを返す。
	 *
	 * @param array<string, string>                                                      $values           項目 ID => 値（sanitize_values() の戻り値）。
	 * @param array<int, array{id: string, label: string, type: string, required: bool}> $definitions      項目の定義。
	 * @param bool                                                                       $enforce_required 必須の項目を空で断るか（代理予約・ゲストの確認リンクでは false）。
	 * @return array<string, string> 項目 ID => エラーメッセージ。エラーが無ければ空配列。
	 */
	public static function validate_values( array $values, array $definitions, bool $enforce_required ): array {
		$errors = array();
		foreach ( $definitions as $definition ) {
			$id    = $definition['id'];
			$value = $values[ $id ] ?? '';

			if ( $enforce_required && $definition['required'] && '' === $value ) {
				if ( in_array( $definition['type'], array( self::TYPE_SELECT, self::TYPE_RADIO ), true ) ) {
					/* translators: %s: Booking input field label. */
					$errors[ $id ] = sprintf( _x( 'Please select %s.', 'booking input field', 'vk-booking-manager' ), $definition['label'] );
				} else {
					/* translators: %s: Booking input field label. */
					$errors[ $id ] = sprintf( __( 'Please enter %s.', 'vk-booking-manager' ), $definition['label'] );
				}
				continue;
			}

			$max_length = self::get_max_length( $definition['type'] );
			if ( '' !== $value && ( self::TYPE_SELECT === $definition['type'] || self::TYPE_RADIO === $definition['type'] ) && ! in_array( $value, $definition['choices'] ?? array(), true ) ) {
				$errors[ $id ] = __( 'Please select a valid choice.', 'vk-booking-manager' );
				continue;
			}
			if ( '' !== $value && self::TYPE_NUMBER === $definition['type'] && ! self::is_valid_number( $value ) ) {
				$errors[ $id ] = __( 'Please enter a valid number.', 'vk-booking-manager' );
				continue;
			}
			if ( mb_strlen( $value ) > $max_length ) {
				$errors[ $id ] = sprintf(
					/* translators: 1: Booking input field label, 2: Maximum number of characters. */
					__( '%1$s must be %2$d characters or fewer.', 'vk-booking-manager' ),
					$definition['label'],
					$max_length
				);
			}
		}

		return $errors;
	}

	/**
	 * 項目ごとのエラーを、REST で返す WP_Error にまとめる。
	 *
	 * @param array<string, string> $errors 項目 ID => エラーメッセージ。
	 * @return WP_Error|null エラーがあれば WP_Error（data.fields に項目ごとのエラー）。無ければ null。
	 */
	public static function create_error( array $errors ): ?WP_Error {
		if ( array() === $errors ) {
			return null;
		}

		return new WP_Error(
			'vkbm_custom_field_invalid',
			(string) reset( $errors ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * 予約に保存する回答の記録（スナップショット）を作る。
	 *
	 * 回答した時点の項目名・種類を一緒に残し、後から設定で項目名を変えたり項目を消したりしても過去の予約が読めるようにする。
	 *
	 * @param array<string, string>                                                      $values      項目 ID => 値。
	 * @param array<int, array{id: string, label: string, type: string, required: bool}> $definitions 項目の定義。
	 * @return array<int, array{id: string, label: string, type: string, value: string}> 回答の記録。定義が無ければ空配列。
	 */
	public static function build_snapshot( array $values, array $definitions ): array {
		$snapshot = array();
		foreach ( $definitions as $definition ) {
			$snapshot[] = array(
				'id'    => $definition['id'],
				'label' => $definition['label'],
				'type'  => $definition['type'],
				'value' => (string) ( $values[ $definition['id'] ] ?? '' ),
			);
		}

		return $snapshot;
	}

	/**
	 * 保存済みの回答の記録を正規化する。形の崩れた行は捨てる。
	 *
	 * @param mixed $raw 予約メタの値。
	 * @return array<int, array{id: string, label: string, type: string, value: string}> 回答の記録。
	 */
	public static function normalize_snapshot( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$snapshot = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || ! self::is_valid_id( $row['id'] ?? '' ) ) {
				continue;
			}
			$label = is_scalar( $row['label'] ?? null ) ? (string) $row['label'] : '';
			if ( '' === $label ) {
				continue;
			}
			$snapshot[] = array(
				'id'    => (string) $row['id'],
				'label' => $label,
				'type'  => self::sanitize_type( $row['type'] ?? '' ),
				'value' => is_scalar( $row['value'] ?? null ) ? (string) $row['value'] : '',
			);
		}

		return $snapshot;
	}

	/**
	 * 予約に保存した回答の記録を返す。
	 *
	 * @param int $booking_id 予約の投稿 ID。
	 * @return array<int, array{id: string, label: string, type: string, value: string}> 回答の記録。
	 */
	public static function get_booking_snapshot( int $booking_id ): array {
		return self::normalize_snapshot( get_post_meta( $booking_id, self::META_KEY, true ) );
	}

	/**
	 * 予約の編集画面に並べる行を返す。
	 *
	 * 予約に保存した記録の項目を先に（当時の項目名で）並べ、記録に無い今の設定の項目を後ろに空欄で足す。
	 * 記録が無い予約（この機能より前の予約・管理画面で作った予約）でも、スタッフが回答を書き込めるようにするため。
	 *
	 * @param array<int, array{id: string, label: string, type: string, value: string}>  $snapshot    予約に保存した記録。
	 * @param array<int, array{id: string, label: string, type: string, required: bool}> $definitions 今の設定の定義。
	 * @return array<int, array{id: string, label: string, type: string, value: string}> 編集画面の行。
	 */
	public static function merge_snapshot_with_definitions( array $snapshot, array $definitions ): array {
		$rows     = $snapshot;
		$seen_ids = array();
		foreach ( $snapshot as $row ) {
			$seen_ids[ $row['id'] ] = true;
		}
		foreach ( $definitions as $definition ) {
			if ( isset( $seen_ids[ $definition['id'] ] ) ) {
				continue;
			}
			$rows[] = array(
				'id'    => $definition['id'],
				'label' => $definition['label'],
				'type'  => $definition['type'],
				'value' => '',
			);
		}

		return $rows;
	}

	/**
	 * 予約の編集画面から送られた回答で、記録を作り直す。
	 *
	 * 行の項目名・種類は merge_snapshot_with_definitions() と同じ並びで決め、送られた値だけを差し替える。
	 * 送られなかった行は元の値を保つ。
	 *
	 * @param array<int, array{id: string, label: string, type: string, value: string}>  $snapshot    予約に保存した記録。
	 * @param array<int, array{id: string, label: string, type: string, required: bool}> $definitions 今の設定の定義。
	 * @param mixed                                                                      $raw         送られた値（項目 ID => 値）。
	 * @return array<int, array{id: string, label: string, type: string, value: string}> 新しい記録。
	 */
	public static function apply_admin_input( array $snapshot, array $definitions, $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$rows = self::merge_snapshot_with_definitions( $snapshot, $definitions );
		foreach ( $rows as $index => $row ) {
			if ( ! array_key_exists( $row['id'], $raw ) ) {
				continue;
			}
			$value = is_scalar( $raw[ $row['id'] ] ) ? (string) $raw[ $row['id'] ] : '';
			$value = trim( self::TYPE_TEXTAREA === $row['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );
			if ( self::TYPE_NUMBER === $row['type'] && '' !== $value && ! self::is_valid_number( $value ) ) {
				continue;
			}

			$max_length = self::get_max_length( $row['type'] );
			if ( mb_strlen( $value ) > $max_length ) {
				$value = mb_substr( $value, 0, $max_length );
			}
			$rows[ $index ]['value'] = $value;
		}

		return $rows;
	}

	/**
	 * 記録に回答が 1 つでもあるか判定する。
	 *
	 * @param array<int, array{id: string, label: string, type: string, value: string}> $snapshot 回答の記録。
	 * @return bool 回答があれば true。
	 */
	public static function has_any_value( array $snapshot ): bool {
		foreach ( $snapshot as $row ) {
			if ( '' !== $row['value'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 通知メールの本文に入れる行を作る。
	 *
	 * 「項目名: 値」の形で並べ、未回答は「（なし）」にする。複数行の回答は項目名の次の行から書き出す。
	 *
	 * @param array<int, array{id: string, label: string, type: string, value: string}> $snapshot 回答の記録。
	 * @return string[] メール本文の行。
	 */
	public static function build_email_lines( array $snapshot ): array {
		$lines = array();
		foreach ( $snapshot as $row ) {
			$value = '' !== $row['value'] ? $row['value'] : __( '(none)', 'vk-booking-manager' );
			if ( self::TYPE_TEXTAREA === $row['type'] && '' !== $row['value'] ) {
				/* translators: %s: Booking input field label. */
				$lines[] = sprintf( __( '%s:', 'vk-booking-manager' ), $row['label'] );
				$lines[] = $value;
				continue;
			}
			$lines[] = sprintf(
				/* translators: 1: Booking input field label, 2: Answer. */
				__( '%1$s: %2$s', 'vk-booking-manager' ),
				$row['label'],
				$value
			);
		}

		return $lines;
	}

	/**
	 * 項目名をサニタイズし、上限文字数に収める。
	 *
	 * @param mixed $value 項目名。
	 * @return string サニタイズ済みの項目名。
	 */
	private static function sanitize_label( $value ): string {
		$label = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';

		return mb_strlen( $label ) > self::LABEL_MAX_LENGTH ? mb_substr( $label, 0, self::LABEL_MAX_LENGTH ) : $label;
	}

	/**
	 * 入力欄の種類を、許可した値に揃える（それ以外は 1 行のテキスト）。
	 *
	 * @param mixed $value 種類。
	 * @return string 種類。
	 */
	private static function sanitize_type( $value ): string {
		return in_array( $value, self::get_types(), true ) ? $value : self::TYPE_TEXT;
	}

	/**
	 * 選択肢の空行を除き、表示と保存に使う文字列を返す。
	 *
	 * @param mixed $raw 改行区切りまたは文字列配列。
	 * @return string[] 選択肢。
	 */
	private static function sanitize_choices( $raw ): array {
		$lines   = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', is_scalar( $raw ) ? (string) $raw : '' );
		$choices = array();
		foreach ( $lines as $line ) {
			$choice = is_scalar( $line ) ? trim( sanitize_text_field( (string) $line ) ) : '';
			if ( '' !== $choice ) {
				$choices[] = mb_substr( $choice, 0, self::TEXT_MAX_LENGTH );
			}
		}

		return array_values( array_unique( $choices ) );
	}

	/**
	 * 数値表記と有限性、長さを検証する。
	 *
	 * @param string $value 入力された値。
	 * @return bool 有効な数値なら true。
	 */
	public static function is_valid_number( string $value ): bool {
		return strlen( $value ) <= self::TEXT_MAX_LENGTH
			&& 1 === preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value )
			&& is_finite( (float) $value );
	}

	/**
	 * 数値の既定値を文字列として保存する。
	 *
	 * @param mixed $raw 設定値。
	 * @return string 有効な数値または空文字。
	 */
	private static function sanitize_number_default( $raw ): string {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		return self::is_valid_number( $value ) ? $value : '';
	}
}
