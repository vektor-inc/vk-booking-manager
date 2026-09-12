<?php
/**
 * 業種プリセットの定義と適用処理。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Pro_Upsell;

/**
 * 業種プリセットを一元管理する。
 */
class Industry_Presets {
	/**
	 * get_presets() の結果のメモ化キャッシュ（リクエスト内で使い回す）。
	 *
	 * $defaults の内容とロケール（determine_locale()）ごとにキャッシュする
	 * （キーは "ロケール:serializeしたハッシュ"）。get_settings() が呼ばれるたびに
	 * 毎回フィルター適用・翻訳ルックアップをやり直さないための最適化
	 * （#387 レビュー指摘・安藤案）。
	 *
	 * キャッシュしているのはプリセット「定義」（フィルター適用済み・翻訳済みの静的な
	 * データ）であり、設定の保存値そのものではないため、設定保存時に破棄する必要は
	 * 実際には無い。clear_cache() は PHPUnit のテスト間分離のために用意している
	 * （テストごとに `vkbm_industry_presets` フィルターを付け外しても、このキャッシュが
	 * 残っていると前のテストの結果が次のテストに漏れてしまうため、set_up()/tear_down()
	 * から呼ぶ）。
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $presets_cache = array();

	/**
	 * プリセット対象の設定キー。
	 *
	 * @var array<int, string>
	 */
	public const FIELD_KEYS = array(
		'staff_enabled',
		'slot_capacity_enabled',
		'resource_label_singular',
		'resource_label_plural',
		'resource_label_menu',
		'resource_menu_icon',
		'guests_unit_label',
		'no_nomination_label',
		'nomination_fee_label',
	);

	/**
	 * 翻訳前のプリセット定義。
	 *
	 * ラベル値は get_presets() で翻訳し、default は Settings_Repository の既定値へ解決する。
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PRESET_DEFINITIONS = array(
		'salon'    => array(
			'label'  => 'Salon',
			'fields' => array(
				'staff_enabled'           => array(
					'value'   => true,
					'display' => true,
				),
				'slot_capacity_enabled'   => array(
					'value'   => false,
					'display' => false,
				),
				'resource_label_singular' => array(
					'value'   => 'Staff',
					'display' => false,
				),
				'resource_label_plural'   => array(
					'value'   => 'Staff',
					'display' => false,
				),
				'resource_label_menu'     => array(
					'value'   => 'Staff available',
					'display' => true,
				),
				'resource_menu_icon'      => array(
					'value'   => 'dashicons-groups',
					'display' => false,
				),
				'guests_unit_label'       => array(
					'value'   => null,
					'display' => false,
				),
				'no_nomination_label'     => array(
					'value'   => '__default__',
					'display' => true,
				),
				'nomination_fee_label'    => array(
					'value'   => '__default__',
					'display' => true,
				),
			),
		),
		'activity' => array(
			'label'  => 'Activity / Tour',
			'fields' => array(
				'staff_enabled'           => array(
					'value'   => false,
					'display' => true,
				),
				'slot_capacity_enabled'   => array(
					'value'   => true,
					'display' => true,
				),
				'resource_label_singular' => array(
					'value'   => 'Guide',
					'display' => true,
				),
				'resource_label_plural'   => array(
					'value'   => 'Guide',
					'display' => true,
				),
				'resource_label_menu'     => array(
					'value'   => 'Guide',
					'display' => true,
				),
				'resource_menu_icon'      => array(
					'value'   => 'dashicons-admin-users',
					'display' => false,
				),
				'guests_unit_label'       => array(
					'value'   => null,
					'display' => false,
				),
				'no_nomination_label'     => array(
					'value'   => '__default__',
					'display' => false,
				),
				'nomination_fee_label'    => array(
					'value'   => '__default__',
					'display' => false,
				),
			),
		),
		'seminar'  => array(
			'label'  => 'Seminar',
			'fields' => array(
				'staff_enabled'           => array(
					'value'   => false,
					'display' => false,
				),
				'slot_capacity_enabled'   => array(
					'value'   => true,
					'display' => false,
				),
				'resource_label_singular' => array(
					'value'   => 'Instructor',
					'display' => false,
				),
				'resource_label_plural'   => array(
					'value'   => 'Instructor',
					'display' => false,
				),
				'resource_label_menu'     => array(
					'value'   => 'Instructor',
					'display' => true,
				),
				'resource_menu_icon'      => array(
					'value'   => 'dashicons-businessman',
					'display' => false,
				),
				'guests_unit_label'       => array(
					'value'   => null,
					'display' => false,
				),
				'no_nomination_label'     => array(
					'value'   => '__default__',
					'display' => false,
				),
				'nomination_fee_label'    => array(
					'value'   => '__default__',
					'display' => false,
				),
			),
		),
	);

	/**
	 * フィルター適用済みのプリセット一覧を返す（リクエスト内でメモ化する）。
	 *
	 * @param array<string, mixed> $defaults 設定の既定値。
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_presets( array $defaults ): array {
		// キャッシュキーに determine_locale() を含める。get_default_settings() は日本語か
		// どうかでしか分岐しないため、これが無いと en_US と fr_FR が同一キーになり、先に
		// 評価された言語の翻訳ラベルが同一リクエスト内で使い回されてしまう（#387 レビュー
		// 指摘・安藤案）。
		$cache_key = determine_locale() . ':' . md5( serialize( $defaults ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- キャッシュキー生成のみに使用し、保存や復元はしない。
		if ( array_key_exists( $cache_key, self::$presets_cache ) ) {
			return self::$presets_cache[ $cache_key ];
		}

		// 翻訳テーブルは static キャッシュにせず、get_presets() 呼び出しのたびに（＝キャッシュ
		// ミス時のみ）ここで1回だけ組み立ててループへ渡す。static にすると翻訳テーブル自体が
		// プロセス内で固定され、キャッシュキーに言語を含めても言語切り替えに追随できないため
		// （#387 レビュー指摘・安藤案）。呼び出し頻度はメモ化により抑えられているため、
		// __() の呼び出し回数はキャッシュミス1回あたり7回で済む。
		$labels = array(
			'Salon'           => __( 'Salon', 'vk-booking-manager' ),
			'Activity / Tour' => __( 'Activity / Tour', 'vk-booking-manager' ),
			'Seminar'         => __( 'Seminar', 'vk-booking-manager' ),
		);
		$values = array(
			'Staff'           => __( 'Staff', 'vk-booking-manager' ),
			'Staff available' => __( 'Staff available', 'vk-booking-manager' ),
			'Guide'           => __( 'Guide', 'vk-booking-manager' ),
			'Instructor'      => __( 'Instructor', 'vk-booking-manager' ),
		);

		$custom_fields = array();
		foreach ( self::FIELD_KEYS as $field_key ) {
			$custom_fields[ $field_key ] = array(
				'value'   => $defaults[ $field_key ] ?? null,
				'display' => true,
			);
		}

		$presets = array(
			'custom' => array(
				'label'  => __( 'Custom', 'vk-booking-manager' ),
				'fields' => $custom_fields,
			),
		);

		foreach ( self::PRESET_DEFINITIONS as $preset_key => $definition ) {
			$fields = array();
			foreach ( $definition['fields'] as $field_key => $field ) {
				$value                = '__default__' === $field['value'] ? ( $defaults[ $field_key ] ?? null ) : $field['value'];
				$value                = self::translate_definition_value( $value, $field_key, $values );
				$fields[ $field_key ] = array(
					'value'   => $value,
					'display' => (bool) $field['display'],
				);
			}
			$presets[ $preset_key ] = array(
				'label'  => self::translate_definition_label( (string) $definition['label'], $labels ),
				'fields' => $fields,
			);
		}

		/**
		 * 業種プリセット定義を差し替え・追加する。
		 *
		 * @param array<string, array<string, mixed>> $presets プリセット一覧。
		 */
		$filtered = apply_filters( 'vkbm_industry_presets', $presets );

		// フィルターの戻り値は信頼しない。構造が壊れていれば個々のプリセット・項目単位で
		// 読み飛ばし、custom が失われていれば再注入する（#387 レビュー指摘・安藤案）。
		// custom がプルダウンから消えると、保存済み値が選択肢に無くなりブラウザが先頭の
		// 選択肢を暗黙で選ぶため、保存するだけでプリセットが黙って切り替わってしまう。
		$normalized                        = self::normalize_filtered_presets( $filtered, $presets, $custom_fields );
		self::$presets_cache[ $cache_key ] = $normalized;

		return $normalized;
	}

	/**
	 * `vkbm_industry_presets` フィルターの戻り値を正規化する。
	 *
	 * @param mixed                               $filtered      フィルター適用後の値（型不明）。
	 * @param array<string, array<string, mixed>> $fallback      フィルターが配列を返さなかった場合の既定値。
	 * @param array<string, array<string, mixed>> $custom_fields custom プリセットの項目一覧（再注入用）。
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_filtered_presets( $filtered, array $fallback, array $custom_fields ): array {
		if ( ! is_array( $filtered ) ) {
			return $fallback;
		}

		$normalized = array();
		foreach ( $filtered as $preset_key => $preset ) {
			if ( ! is_array( $preset ) || ! isset( $preset['fields'] ) || ! is_array( $preset['fields'] ) ) {
				continue;
			}

			$fields = array();
			foreach ( $preset['fields'] as $field_key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				// display 欠落時は true（表示する・値を強制しない）へ倒す。false へ倒すと、
				// 壊れた第三者定義が利用者の保存値を黙って上書きする向きになってしまう
				// （#387 レビュー指摘・安藤案）。
				$fields[ $field_key ] = array(
					'value'   => $field['value'] ?? null,
					'display' => (bool) ( $field['display'] ?? true ),
				);
			}

			$label                     = $preset['label'] ?? $preset_key;
			$normalized[ $preset_key ] = array(
				'label'  => is_scalar( $label ) ? (string) $label : (string) $preset_key,
				'fields' => $fields,
			);
		}

		if ( ! isset( $normalized['custom'] ) ) {
			$normalized = array(
				'custom' => array(
					'label'  => __( 'Custom', 'vk-booking-manager' ),
					'fields' => $custom_fields,
				),
			) + $normalized;
		}

		return $normalized;
	}

	/**
	 * 定数定義内のプリセットラベルを翻訳する。
	 *
	 * @param string               $label  翻訳前ラベル。
	 * @param array<string,string> $labels get_presets() で組み立てた翻訳テーブル。
	 * @return string
	 */
	private static function translate_definition_label( string $label, array $labels ): string {
		return $labels[ $label ] ?? $label;
	}

	/**
	 * 定数定義内の表示値を翻訳する。
	 *
	 * @param mixed                $value     翻訳前の値。
	 * @param string               $field_key 設定キー。
	 * @param array<string,string> $values    get_presets() で組み立てた翻訳テーブル。
	 * @return mixed
	 */
	private static function translate_definition_value( $value, string $field_key, array $values ) {
		if ( ! is_string( $value ) || 'resource_menu_icon' === $field_key ) {
			return $value;
		}

		return $values[ $value ] ?? $value;
	}

	/**
	 * リクエスト内のメモ化キャッシュを破棄する。
	 *
	 * キャッシュしているのはプリセット「定義」であり設定の保存値ではないため、
	 * 実際の呼び出し元は設定保存処理ではなく PHPUnit のテストの set_up()/tear_down()
	 * のみである。テストごとに `vkbm_industry_presets` フィルターを付け外しても、この
	 * キャッシュが残っていると前のテストの結果が次のテストに漏れてしまうため、
	 * テスト間の分離のために用意している（#387 レビュー指摘・安藤案）。
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$presets_cache = array();
	}

	/**
	 * 保存値から現在の有効なプリセットキーを返す。
	 *
	 * @param array<string, mixed> $settings 設定値。
	 * @param array<string, mixed> $defaults 設定の既定値。
	 * @return string
	 */
	public static function get_current_preset( array $settings, array $defaults ): string {
		if ( Pro_Upsell::is_free_edition() ) {
			return 'custom';
		}

		$key     = self::sanitize_preset_key( $settings['industry_preset'] ?? 'custom' );
		$presets = self::get_presets( $defaults );

		return isset( $presets[ $key ] ) ? $key : 'custom';
	}

	/**
	 * 生の送信値からプリセットキーへ安全に変換する（配列等を渡されても警告を出さない）。
	 *
	 * `industry_preset[]=x` のように配列で送信された場合、`(string)` へ直接キャストすると
	 * 「Array to string conversion」警告が出る（結果自体は custom へ倒れるため安全側だが、
	 * ログが汚れる）。scalar でなければ変換前に 'custom' へ倒すことで警告を避ける
	 * （#387 レビュー指摘・安藤案）。
	 *
	 * @param mixed $raw 生の送信値。
	 * @return string
	 */
	public static function sanitize_preset_key( $raw ): string {
		return sanitize_key( is_scalar( $raw ) ? (string) $raw : 'custom' );
	}

	/**
	 * 非表示項目へ現在のプリセット値を強制する。
	 *
	 * @param array<string, mixed> $settings 設定値。
	 * @param array<string, mixed> $defaults 設定の既定値。
	 * @return array<string, mixed>
	 */
	public static function apply_effective_values( array $settings, array $defaults ): array {
		if ( Pro_Upsell::is_free_edition() ) {
			// 無料版では業種プリセット機能自体を提供しないため、保存されている industry_preset の
			// 生の値は読み取り・保存経路のどちらでも書き換えない（#387 レビュー指摘・安藤案B）。
			// 以前は読み取り経路だけ実効値として 'custom' へ潰していたが、
			// Settings_Service::save_settings() は保存前に Settings_Repository::get_settings()
			// （＝読み取り経路）で現在値を取得してから apply_transition() へ渡すため、無料版で
			// 何かを保存するたびに潰された 'custom' が現在値として使われ、Pro 版で選択していた
			// 業種が保存の都度失われてしまっていた（$persist 引数による経路分離では、保存経路側の
			// 書き換え防止だけでは直せなかった）。
			// industry_preset の「実効値」（無料版では常に custom）が必要な箇所は
			// get_current_preset()（無料版早期リターンで 'custom' を返す）を使っており、
			// $settings['industry_preset'] を実効値として直接参照している箇所は無いため、
			// ここで生の値を保持しても他へは影響しない。
			return $settings;
		}

		// 保存値が素の 'custom'（または未設定）であれば、フィルター適用・翻訳ルックアップを
		// 伴う get_presets() を呼ばずに抜けられる。既存サイト・custom 運用中のサイトは
		// すべてこの経路を通るため、最も効くコスト削減（#387 レビュー指摘・安藤案）。
		$raw_key = self::sanitize_preset_key( $settings['industry_preset'] ?? 'custom' );
		if ( 'custom' === $raw_key ) {
			$settings['industry_preset'] = 'custom';
			return $settings;
		}

		$preset_key                  = self::get_current_preset( $settings, $defaults );
		$settings['industry_preset'] = $preset_key;
		if ( 'custom' === $preset_key ) {
			return $settings;
		}

		$presets = self::get_presets( $defaults );
		foreach ( $presets[ $preset_key ]['fields'] ?? array() as $field_key => $field ) {
			if ( empty( $field['display'] ) && in_array( $field_key, self::FIELD_KEYS, true ) ) {
				$settings[ $field_key ] = $field['value'] ?? null;
			}
		}

		return $settings;
	}

	/**
	 * プリセット切り替え規則をサニタイズ済み設定へ適用する。
	 *
	 * @param array<string, mixed> $current   切り替え前の設定値。
	 * @param array<string, mixed> $sanitized サニタイズ済みの送信値。
	 * @param array<string, mixed> $input     元の送信値。
	 * @param array<string, mixed> $defaults  設定の既定値。
	 * @return array<string, mixed>
	 */
	public static function apply_transition( array $current, array $sanitized, array $input, array $defaults ): array {
		$previous_key = self::get_current_preset( $current, $defaults );

		// industry_preset 自体が未送信（無料版の画面には選択欄が無い、または REST 等で
		// 明示的に送られなかった場合）は、Settings_Sanitizer が便宜上 'custom' へ倒した値を
		// 使わず、保存済みの現在値をそのまま維持する（#387 レビュー指摘・安藤案）。これにより、
		// 無料版で他の項目だけを保存しても選択済みの業種が消えず、Pro へ戻したときにそのまま
		// 復元される。get_current_preset() の free 版早期リターンは「実効値は常に custom」を
		// 返すためのものであり「保存すべき生の値」の判定には使えないため、ここでは
		// sanitize_preset_key() で直接サニタイズする。
		$sanitized['industry_preset'] = array_key_exists( 'industry_preset', $input )
			? self::sanitize_preset_key( $sanitized['industry_preset'] ?? 'custom' )
			: self::sanitize_preset_key( $current['industry_preset'] ?? 'custom' );

		$new_key = self::get_current_preset( $sanitized, $defaults );
		$presets = self::get_presets( $defaults );

		foreach ( self::FIELD_KEYS as $field_key ) {
			$current_value = array_key_exists( $field_key, $current ) ? $current[ $field_key ] : ( $defaults[ $field_key ] ?? null );
			// フォームに存在しない項目は、切り替え判定の前に現在値を復元する。
			if ( ! array_key_exists( $field_key, $input ) ) {
				$sanitized[ $field_key ] = $current_value;
			}
		}

		// custom へ戻す場合は保存済み値を一切差し替えない。
		if ( 'custom' === $new_key ) {
			return $sanitized;
		}

		foreach ( $presets[ $new_key ]['fields'] ?? array() as $field_key => $new_field ) {
			if ( ! in_array( $field_key, self::FIELD_KEYS, true ) ) {
				continue;
			}

			if ( empty( $new_field['display'] ) ) {
				$sanitized[ $field_key ] = $new_field['value'] ?? null;
				continue;
			}

			$current_value   = array_key_exists( $field_key, $current ) ? $current[ $field_key ] : ( $defaults[ $field_key ] ?? null );
			$submitted_value = $sanitized[ $field_key ] ?? null;
			// 切り替え操作と同時に編集された送信値はユーザー値として優先する。
			if ( array_key_exists( $field_key, $input ) && ! self::values_equal( $submitted_value, $current_value ) ) {
				continue;
			}

			$previous_value = 'custom' === $previous_key
				? ( $defaults[ $field_key ] ?? null )
				: ( $presets[ $previous_key ]['fields'][ $field_key ]['value'] ?? ( $defaults[ $field_key ] ?? null ) );
			if ( self::values_equal( $current_value, $previous_value ) ) {
				$sanitized[ $field_key ] = $new_field['value'] ?? null;
			}
		}

		return $sanitized;
	}

	/**
	 * プリセット値の型を考慮して値を比較する。
	 *
	 * @param mixed $actual   比較対象値。
	 * @param mixed $expected プリセット値。
	 * @return bool
	 */
	private static function values_equal( $actual, $expected ): bool {
		if ( is_bool( $expected ) ) {
			return (bool) $actual === $expected;
		}

		return $actual === $expected;
	}
}
