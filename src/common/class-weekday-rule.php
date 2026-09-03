<?php
/**
 * 「頻度 × 曜日」ルール（毎週◯曜・第N◯曜）の共通ユーティリティ。
 *
 * 基本設定の「定休日指定」と、サービスメニューの「予約可能日：曜日指定」で
 * 同一形式の行追加式テーブル（頻度 × 曜日）を使うため、オプション定義・
 * 行マークアップ・サニタイズ・日付マッチング判定を1か所へ集約する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

use DateTimeInterface;

use function __;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function sanitize_text_field;
use function selected;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 頻度 × 曜日ルールの共通処理を提供する、状態を持たない静的ユーティリティクラス。
 */
class Weekday_Rule {
	/**
	 * 許容する頻度の一覧（毎週・第1〜第5）。
	 *
	 * @var array<int, string>
	 */
	public const FREQUENCIES = array( 'weekly', 'nth-1', 'nth-2', 'nth-3', 'nth-4', 'nth-5' );

	/**
	 * 許容する曜日キーの一覧（月〜日）。
	 *
	 * @var array<int, string>
	 */
	public const WEEKDAY_KEYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * 曜日キーと ISO-8601 曜日番号（N: 1=月〜7=日）の対応表。
	 *
	 * @var array<string, int>
	 */
	private const WEEKDAY_KEY_TO_ISO = array(
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 7,
	);

	/**
	 * 頻度セレクトの選択肢（値 => 表示ラベル）を返す。
	 *
	 * @return array<string, string>
	 */
	public static function frequency_options(): array {
		return array(
			'weekly' => __( 'Weekly', 'vk-booking-manager' ),
			'nth-1'  => __( '1st', 'vk-booking-manager' ),
			'nth-2'  => __( '2nd', 'vk-booking-manager' ),
			'nth-3'  => __( '3rd', 'vk-booking-manager' ),
			'nth-4'  => __( '4th', 'vk-booking-manager' ),
			'nth-5'  => __( 'Fifth', 'vk-booking-manager' ),
		);
	}

	/**
	 * 曜日セレクトの選択肢（値 => 表示ラベル）を返す。
	 *
	 * @return array<string, string>
	 */
	public static function weekday_options(): array {
		return array(
			'mon' => __( 'Monday', 'vk-booking-manager' ),
			'tue' => __( 'Tuesday', 'vk-booking-manager' ),
			'wed' => __( 'Wednesday', 'vk-booking-manager' ),
			'thu' => __( 'Thursday', 'vk-booking-manager' ),
			'fri' => __( 'Friday', 'vk-booking-manager' ),
			'sat' => __( 'Saturday', 'vk-booking-manager' ),
			'sun' => __( 'Sunday', 'vk-booking-manager' ),
		);
	}

	/**
	 * 「頻度 × 曜日」ルールの配列をサニタイズする。
	 *
	 * 許容外の頻度・曜日キーを含む行は破棄し、正規化した行だけを返す。
	 *
	 * @param mixed $raw フォーム等から受け取った生のルール配列。
	 * @return array<int, array{frequency: string, weekday: string}> 正規化済みのルール配列。
	 */
	public static function sanitize_rules( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$result = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// 頻度・曜日をテキストとしてサニタイズし、許容値メンバかどうかを検証する。
			$frequency = sanitize_text_field( (string) ( $row['frequency'] ?? '' ) );
			$weekday   = sanitize_text_field( (string) ( $row['weekday'] ?? '' ) );

			if ( ! in_array( $frequency, self::FREQUENCIES, true ) ) {
				continue;
			}

			if ( ! in_array( $weekday, self::WEEKDAY_KEYS, true ) ) {
				continue;
			}

			$result[] = array(
				'frequency' => $frequency,
				'weekday'   => $weekday,
			);
		}

		return array_values( $result );
	}

	/**
	 * 「頻度 × 曜日」1行分の行追加式テーブルの行（tr）マークアップを生成して返す。
	 *
	 * 定休日指定（基本設定）と予約可能日：曜日指定（サービスメニュー）で共有するため、
	 * name 属性の接頭辞と、JS がフックする行・削除ボタンのクラス名を引数で差し替えられる。
	 *
	 * @param string     $name_base    name 属性の接頭辞（例: 'vkbm_service_menu[reservation_custom_weekdays]'）。
	 * @param int|string $index        行のインデックス（テンプレート用に '__INDEX__' を渡す場合もある）。
	 * @param array      $value        現在値（'frequency' / 'weekday' キー）。
	 * @param string     $row_class    行（tr）へ付与するクラス名。
	 * @param string     $remove_class 削除ボタンへ付与するクラス名。
	 * @return string 行の HTML 文字列。
	 */
	public static function render_row( string $name_base, $index, array $value = array(), string $row_class = 'vkbm-weekday-rule-row', string $remove_class = 'vkbm-weekday-rule-remove' ): string {
		// 既定値は「毎週・月曜」。既存の定休日 UI の初期値と揃える。
		$frequency_value   = isset( $value['frequency'] ) ? (string) $value['frequency'] : 'weekly';
		$weekday_value     = isset( $value['weekday'] ) ? (string) $value['weekday'] : 'mon';
		$frequency_options = self::frequency_options();
		$weekday_options   = self::weekday_options();
		$index             = (string) $index;

		ob_start();
		?>
		<tr class="<?php echo esc_attr( $row_class ); ?>" data-index="<?php echo esc_attr( $index ); ?>">
			<td>
				<select name="<?php echo esc_attr( $name_base ); ?>[<?php echo esc_attr( $index ); ?>][frequency]" aria-label="<?php esc_attr_e( 'Frequency', 'vk-booking-manager' ); ?>">
					<?php foreach ( $frequency_options as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $frequency_value, $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $name_base ); ?>[<?php echo esc_attr( $index ); ?>][weekday]" aria-label="<?php esc_attr_e( 'Day of the week', 'vk-booking-manager' ); ?>">
					<?php foreach ( $weekday_options as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $weekday_value, $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="column-actions">
				<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger <?php echo esc_attr( $remove_class ); ?>">
					<?php esc_html_e( 'delete', 'vk-booking-manager' ); ?>
				</button>
			</td>
		</tr>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * 指定した日付が、いずれかの「頻度 × 曜日」ルールに該当するかどうかを判定する。
	 *
	 * 「毎週◯曜」は曜日一致だけで該当。「第N◯曜」は曜日一致かつ、その月における
	 * 同曜日の出現回数（第何週目か）が N と一致した場合に該当する。
	 *
	 * @param DateTimeInterface $date  判定対象の日付。
	 * @param array             $rules 「頻度 × 曜日」ルールの配列。
	 * @return bool いずれかのルールに該当すれば true。
	 */
	public static function matches( DateTimeInterface $date, array $rules ): bool {
		// 判定対象日の ISO 曜日番号（1=月〜7=日）と、その月における同曜日の出現回数。
		$iso        = (int) $date->format( 'N' );
		$occurrence = self::occurrence_in_month( $date );

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$weekday   = (string) ( $rule['weekday'] ?? '' );
			$frequency = (string) ( $rule['frequency'] ?? '' );

			// 曜日キーが未知、または判定対象日の曜日と一致しない場合はスキップ。
			if ( ! isset( self::WEEKDAY_KEY_TO_ISO[ $weekday ] ) || self::WEEKDAY_KEY_TO_ISO[ $weekday ] !== $iso ) {
				continue;
			}

			// 毎週は曜日一致だけで該当。
			if ( 'weekly' === $frequency ) {
				return true;
			}

			// 第N曜は出現回数の一致を確認する。
			if ( 0 === strpos( $frequency, 'nth-' ) ) {
				$nth = (int) substr( $frequency, 4 );
				if ( $nth > 0 && $nth === $occurrence ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * 指定日が、その月における同一曜日の何回目（第何週目）かを返す。
	 *
	 * 同一曜日は7日ごとに現れ、その月最初の出現日は必ず1〜7日の範囲にあるため、
	 * 日にち d に対して floor( (d-1) / 7 ) + 1 で出現回数（1〜5）が求まる。
	 *
	 * @param DateTimeInterface $date 判定対象の日付。
	 * @return int 出現回数（1〜5）。
	 */
	public static function occurrence_in_month( DateTimeInterface $date ): int {
		$day = (int) $date->format( 'j' );

		return (int) floor( ( $day - 1 ) / 7 ) + 1;
	}
}
