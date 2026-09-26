<?php
/**
 * Google カレンダーの予定に載せる情報（オーナーが選ぶ項目）を保存・読み出すクラス。
 *
 * issue #476（親 issue #94: Google カレンダー連携）。司の decision record（植草との相談）で
 * 決まった仕様に従う。
 *
 * ## 保存する値は「オンにした項目の一覧」を1つの設定値で持つ
 * 項目を足すときに設定値・保存処理を増やさずに済むよう、`wp_options` へは
 * 有効化された項目キーの配列だけを1件（{@see OPTION_NAME}）で保存する。
 *
 * ## 初期値は PHP 定数で持つ
 * 未保存（連携直後、まだ一度も保存していない）のときの初期値は {@see DEFAULT_ENABLED_FIELDS} で
 * 固定する。初期値オンは「予約人数・担当・予約管理画面へのリンク」、初期値オフは
 * 「お客様の氏名・電話番号・メールアドレス・お客様からのメモ・ご要望」（植草案）。
 *
 * ## 管理用メモは選択肢に出さない
 * 店舗スタッフだけが書く内部メモ（`_vkbm_booking_internal_note`）は、このクラスの
 * 項目一覧（{@see get_field_keys()}）に含めていない。選択肢自体が存在しないため、
 * 画面にもオンにする手段が無く、Google カレンダーの予定へ載ることも無い。
 * Google カレンダーは共有やスマホ通知で管理画面より多くの人の目に触れうるための判断
 * （司の decision record 参照）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Integrations\GoogleCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function array_intersect;
use function array_values;
use function get_option;
use function in_array;
use function is_array;
use function sanitize_key;
use function update_option;

/**
 * Google カレンダーの予定に載せる情報の設定を扱うクラス。
 */
class Google_Calendar_Event_Sync_Settings {

	/**
	 * 設定を保存する option 名。
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'vkbm_google_calendar_event_sync_fields';

	/**
	 * 項目キー: 予約人数。
	 *
	 * @var string
	 */
	public const FIELD_GUESTS = 'guests';

	/**
	 * 項目キー: 担当（呼び名は「担当の呼び名」設定に従う）。
	 *
	 * @var string
	 */
	public const FIELD_STAFF = 'staff';

	/**
	 * 項目キー: 予約管理画面へのリンク。
	 *
	 * @var string
	 */
	public const FIELD_ADMIN_LINK = 'admin_link';

	/**
	 * 項目キー: お客様の氏名。
	 *
	 * @var string
	 */
	public const FIELD_CUSTOMER_NAME = 'customer_name';

	/**
	 * 項目キー: お客様の電話番号。
	 *
	 * @var string
	 */
	public const FIELD_CUSTOMER_TEL = 'customer_tel';

	/**
	 * 項目キー: お客様のメールアドレス。
	 *
	 * @var string
	 */
	public const FIELD_CUSTOMER_EMAIL = 'customer_email';

	/**
	 * 項目キー: お客様からのメモ・ご要望。
	 *
	 * @var string
	 */
	public const FIELD_CUSTOMER_NOTE = 'customer_note';

	/**
	 * 未保存のときの初期値（初期値オン）。
	 *
	 * 植草案: 予約人数・担当・予約管理画面へのリンク。
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_ENABLED_FIELDS = array(
		self::FIELD_GUESTS,
		self::FIELD_STAFF,
		self::FIELD_ADMIN_LINK,
	);

	/**
	 * 選べる項目キーの一覧（管理用メモは含めない）。
	 *
	 * @return array<int, string>
	 */
	public static function get_field_keys(): array {
		return array(
			self::FIELD_GUESTS,
			self::FIELD_STAFF,
			self::FIELD_ADMIN_LINK,
			self::FIELD_CUSTOMER_NAME,
			self::FIELD_CUSTOMER_TEL,
			self::FIELD_CUSTOMER_EMAIL,
			self::FIELD_CUSTOMER_NOTE,
		);
	}

	/**
	 * 現在オンになっている項目キーの一覧を返す。
	 *
	 * 未保存（一度も保存していない）場合は {@see DEFAULT_ENABLED_FIELDS} を返す。
	 *
	 * @return array<int, string>
	 */
	public function get_enabled_fields(): array {
		$stored = get_option( self::OPTION_NAME, null );

		if ( ! is_array( $stored ) ) {
			return self::DEFAULT_ENABLED_FIELDS;
		}

		// 保存済みでも、選べる項目キー以外（過去に削除した項目・不正な値）は除外する。
		return array_values( array_intersect( $stored, self::get_field_keys() ) );
	}

	/**
	 * 指定した項目がオンかどうかを返す。
	 *
	 * @param string $field 項目キー（self::FIELD_* のいずれか）。
	 * @return bool オンなら true。
	 */
	public function is_enabled( string $field ): bool {
		return in_array( $field, $this->get_enabled_fields(), true );
	}

	/**
	 * オンにする項目の一覧を保存する。
	 *
	 * 選べる項目キー以外（フォーム改ざん等）は無視する。
	 *
	 * @param array<int, string> $fields オンにする項目キーの一覧。
	 * @return void
	 */
	public function save( array $fields ): void {
		$sanitized = array();

		foreach ( $fields as $field ) {
			$key = sanitize_key( (string) $field );
			if ( in_array( $key, self::get_field_keys(), true ) && ! in_array( $key, $sanitized, true ) ) {
				$sanitized[] = $key;
			}
		}

		update_option( self::OPTION_NAME, $sanitized, false );
	}
}
