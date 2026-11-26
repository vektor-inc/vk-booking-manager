<?php
/**
 * 言語ごとの呼び方（BM 設定の「多言語設定」タブで登録する値）の保存と取得。
 *
 * 仕様は docs/specification-multilingual.md を正とする。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Multilingual;

use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use function array_key_exists;
use function count;
use function get_option;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_match;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function strpos;
use function trim;
use function update_option;

/**
 * 言語ごとの呼び方を扱う。
 *
 * 保存先は option `vkbm_multilingual_labels`。基本の値（option `vkbm_provider_settings` の中の同名キー）とは別に、
 * `array( 'en_US' => array( 'menu_label' => 'Plan', ... ) )` の形で、入力のある項目だけを保存する。
 */
class Multilingual_Labels {
	/**
	 * 言語ごとの呼び方を保存する option 名。
	 */
	public const OPTION_KEY = 'vkbm_multilingual_labels';

	/**
	 * 設定画面の入力欄の name の先頭（`vkbm_multilingual_labels[en_US][menu_label]`）。
	 */
	public const INPUT_NAME = 'vkbm_multilingual_labels';

	/**
	 * 設定画面が「多言語設定」タブの欄を送ったことを示す印。言語を全部削除した保存でも判別できるよう、必ず送る。
	 */
	public const SUBMITTED_KEY = 'vkbm_multilingual_labels_submitted';

	/**
	 * 呼び方を返す関数が、結果を通すフィルターフックの名前。
	 */
	public const FILTER_HOOK      = 'vkbm_label';
	public const TEXT_FILTER_HOOK = 'vkbm_booking_guide_text';
	public const TEXT_KEYS        = array( 'provider_cancellation_policy', 'provider_payment_method', 'provider_booking_complete_message_pending' );

	/**
	 * 登録できる言語の上限（不正な送信で option が肥大化しないようにする）。
	 */
	public const MAX_LOCALES = 50;

	/**
	 * 言語ごとに登録できる呼び方のキー。並びは「表記設定」タブの欄の並びと同じにする。
	 *
	 * リソースの呼び方は Pro 版の表示だけで利用する。
	 */
	public const FIELD_KEYS = array(
		'duration_label',
		'other_conditions_label',
		'menu_label',
		'menu_label_plural',
		'guests_count_label',
		'guests_unit_label',
		'resource_label_singular',
		'resource_label_menu',
		'resource_label_plural',
		'no_nomination_label',
		'nomination_fee_label',
		...self::TEXT_KEYS,
	);

	/**
	 * 複数形を持たない言語のロケールの先頭。この言語では複数形の欄を出さない。
	 */
	private const NO_PLURAL_LOCALE_PREFIXES = array( 'ja', 'zh', 'ko' );

	/**
	 * 保存済みの言語ごとの呼び方を、形を整えて返す。
	 *
	 * @return array<string, array<string, string>> ロケール => （呼び方のキー => 値）。値が空の項目は含めない。
	 */
	public static function get_all(): array {
		return self::normalize( get_option( self::OPTION_KEY, array() ) );
	}

	/**
	 * 登録済みの言語（ロケール）の一覧を、登録した順で返す。
	 *
	 * @return string[] ロケールの一覧。
	 */
	public static function get_locales(): array {
		return array_keys( self::get_all() );
	}

	/**
	 * 指定した言語で登録した呼び方を返す。
	 *
	 * @param string $locale ロケール（例: en_US）。
	 * @param string $key    呼び方のキー（FIELD_KEYS のいずれか）。
	 * @return string 登録した値。言語が未登録・空欄・対象外のキーなら空文字。
	 */
	public static function get_value( string $locale, string $key ): string {
		if ( '' === $locale || ! in_array( $key, self::FIELD_KEYS, true ) ) {
			return '';
		}

		$all = self::get_all();

		return $all[ $locale ][ $key ] ?? '';
	}

	/**
	 * 表示中の言語、基本設定、翻訳の既定値の順に文章を解決する。
	 *
	 * @param string $key     設定キー。
	 * @param string $base    基本設定の値。
	 * @param string $default 翻訳済みの既定値。
	 * @param string $locale  対象言語。省略時は表示中の言語。
	 * @return string 表示用の文章。
	 */
	public static function resolve_text( string $key, string $base, string $default = '', string $locale = '' ): string {
		$locale = '' !== $locale ? $locale : determine_locale();
		$value  = self::get_value( $locale, $key );
		if ( '' === $value ) {
			$value = '' !== trim( $base ) ? $base : $default;
		}
		/**
		 * 予約案内文を表示する直前に変更する。
		 *
		 * @param string $value  解決した文章。
		 * @param string $key    設定キー。
		 * @param string $locale 対象ロケール。
		 */
		return (string) apply_filters( self::TEXT_FILTER_HOOK, $value, $key, $locale ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- 定数に接頭辞付きフック名を保持する。
	}

	/**
	 * 下書きの言語でキャンセルポリシーへの同意が必要かを返す。
	 *
	 * @param array<string, mixed> $settings 基本設定。
	 * @param array<string, mixed> $draft 予約の下書き。
	 * @return bool 同意が必要なら true。
	 */
	public static function requires_cancellation_policy_agreement( array $settings, array $draft ): bool {
		return '' !== trim( self::resolve_text( 'provider_cancellation_policy', (string) ( $settings['provider_cancellation_policy'] ?? '' ), '', self::sanitize_locale( $draft['locale'] ?? '' ) ) );
	}

	/**
	 * 設定画面から送られた値で、言語ごとの呼び方を保存し直す。
	 *
	 * 印（SUBMITTED_KEY）が送られていない保存（別のタブ・REST の部分更新など）では何もしない。
	 * 送られた言語だけを残すため、画面で削除した言語の値はここで消える。
	 * 送られた言語でも、欄そのものが送られなかった項目は保存済みの値を残す（空文字が送られた項目だけを消す）。
	 *
	 * @param array<string, mixed> $post 送信値（wp_unslash 済みの $_POST）。
	 * @return bool 保存し直したら true。印が無く何もしなかったら false。
	 */
	public static function save_from_request( array $post ): bool {
		if ( empty( $post[ self::SUBMITTED_KEY ] ) ) {
			return false;
		}

		$raw    = is_array( $post[ self::INPUT_NAME ] ?? null ) ? $post[ self::INPUT_NAME ] : array();
		$labels = self::sanitize_input( $raw );
		$saved  = self::get_all();

		// 画面に欄が無かった項目（条件で欄を出さない項目・古い画面からの送信など）は、保存済みの値を引き継ぐ。
		foreach ( $labels as $locale => $values ) {
			$raw_values = is_array( $raw[ $locale ] ?? null ) ? $raw[ $locale ] : array();
			foreach ( self::FIELD_KEYS as $key ) {
				if ( ! array_key_exists( $key, $raw_values ) && isset( $saved[ $locale ][ $key ] ) ) {
					$labels[ $locale ][ $key ] = $saved[ $locale ][ $key ];
				}
			}
		}

		update_option( self::OPTION_KEY, $labels, false );

		return true;
	}

	/**
	 * 送信された言語ごとの呼び方を、保存用の形にする。
	 *
	 * - ロケールの形式に合わないキー・FIELD_KEYS に無い呼び方は捨てる。
	 * - 値は sanitize_text_field() し、前後の空白を除いて空になった項目は保存しない（空欄は基本の値で表示するため）。
	 * - 入力が全部空の言語も、追加した言語として残す（空の配列で保存する）。
	 *
	 * @param mixed $raw 送信値（`vkbm_multilingual_labels`）。
	 * @return array<string, array<string, string>> ロケール => （呼び方のキー => 値）。
	 */
	public static function sanitize_input( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$labels = array();
		foreach ( $raw as $locale => $values ) {
			// 上限を超えた言語は受け付けない。
			if ( count( $labels ) >= self::MAX_LOCALES ) {
				break;
			}

			$locale = self::sanitize_locale( $locale );
			if ( '' === $locale ) {
				continue;
			}

			$labels[ $locale ] = self::sanitize_values( is_array( $values ) ? $values : array() );
		}

		return $labels;
	}

	/**
	 * ロケールの形式（en_US・de_DE_formal・pt_PT_ao90 など）に合うかを確かめる。
	 *
	 * @param mixed $locale ロケールの候補。
	 * @return string 形式に合えばそのロケール。合わなければ空文字。
	 */
	public static function sanitize_locale( $locale ): string {
		if ( ! is_string( $locale ) ) {
			return '';
		}

		return 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[A-Za-z0-9]+)?$/', $locale ) ? $locale : '';
	}

	/**
	 * その言語に複数形があるか（「サービスメニューの呼び方（複数形）」の欄を出すか）を返す。
	 *
	 * @param string $locale ロケール。
	 * @return bool 複数形がある言語なら true。日本語・中国語・韓国語は false。
	 */
	public static function locale_has_plural_forms( string $locale ): bool {
		foreach ( self::NO_PLURAL_LOCALE_PREFIXES as $prefix ) {
			if ( '' !== $locale && 0 === strpos( $locale, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 保存値を、ロケール => （呼び方のキー => 空でない文字列）の形に整える。
	 *
	 * @param mixed $stored 保存値。
	 * @return array<string, array<string, string>> 整えた値。
	 */
	private static function normalize( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$labels = array();
		foreach ( $stored as $locale => $values ) {
			$locale = self::sanitize_locale( $locale );
			if ( '' === $locale ) {
				continue;
			}

			$labels[ $locale ] = array();
			if ( ! is_array( $values ) ) {
				continue;
			}

			// 保存時に整えた値でも、直接書き換えられた option に備えて、文字列で空でない値だけを使う。
			foreach ( self::FIELD_KEYS as $key ) {
				if ( array_key_exists( $key, $values ) && is_string( $values[ $key ] ) && '' !== trim( $values[ $key ] ) ) {
					$labels[ $locale ][ $key ] = trim( $values[ $key ] );
				}
			}
		}

		return $labels;
	}

	/**
	 * 1 言語分の送信値を、保存用の形にする。
	 *
	 * @param array<string, mixed> $values 呼び方のキー => 送信値。
	 * @return array<string, string> 呼び方のキー => 値（空の項目は含めない）。
	 */
	private static function sanitize_values( array $values ): array {
		$sanitized = array();
		foreach ( self::FIELD_KEYS as $key ) {
			if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) ) {
				continue;
			}

			if ( 'provider_booking_complete_message_pending' === $key ) {
				$value = Settings_Sanitizer::sanitize_booking_complete_message( $values[ $key ] );
			} elseif ( in_array( $key, self::TEXT_KEYS, true ) ) {
				$value = trim( sanitize_textarea_field( (string) $values[ $key ] ) );
			} else {
				$value = trim( sanitize_text_field( (string) $values[ $key ] ) );
			}
			if ( '' !== $value ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}
}
