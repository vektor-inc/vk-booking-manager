<?php
/**
 * Resource label helper functions.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Multilingual\Multilingual_Labels;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Resources\Resource_Tag_Taxonomy;

/**
 * 呼び方（単数・複数・メニュー名）を既定値で表示する必要があるかを返す。
 *
 * 呼び方の入力欄は Pro 版にしか無いため、無料版では保存値があっても既定値で表示する（保存値は消さない）。
 * Pro_Upsell::is_free_edition() は呼ぶたびにプラグイン本体のファイルを読み直すため、結果をリクエスト内で覚える。
 *
 * @return bool 既定値で表示する（無料版）なら true。
 */
function vkbm_should_use_default_resource_labels(): bool {
	static $cached = null;

	// 初回のみ判定し、以降は覚えた結果を返す。
	if ( null === $cached ) {
		$cached = Pro_Upsell::is_free_edition();
	}

	return $cached;
}

/**
 * 保存されている呼び方（単数）をそのまま返す（無料版でも既定値へ置き換えない）。
 *
 * 管理画面で「Pro 版で設定された呼び方」を案内するための取得用。表示用には vkbm_get_resource_label_singular() を使う。
 *
 * @return string 保存値。未保存・空文字の場合は既定値。
 */
function vkbm_get_saved_resource_label_singular(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$default    = __( 'Staff', 'vk-booking-manager' );
	$label      = isset( $settings['resource_label_singular'] ) ? trim( (string) $settings['resource_label_singular'] ) : '';

	return '' !== $label ? $label : $default;
}

/**
 * 保存されている呼び方（複数）をそのまま返す（無料版でも既定値へ置き換えない）。
 *
 * @return string 保存値。未保存・空文字の場合は既定値。
 */
function vkbm_get_saved_resource_label_plural(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$default    = __( 'Staff', 'vk-booking-manager' );
	$label      = isset( $settings['resource_label_plural'] ) ? trim( (string) $settings['resource_label_plural'] ) : '';

	return '' !== $label ? $label : $default;
}

/**
 * 保存されている呼び方（メニュー名）をそのまま返す（無料版でも既定値へ置き換えない）。
 *
 * @return string 保存値。未保存・空文字の場合は既定値。
 */
function vkbm_get_saved_resource_label_menu(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$default    = __( 'Staff available', 'vk-booking-manager' );
	$label      = isset( $settings['resource_label_menu'] ) ? trim( (string) $settings['resource_label_menu'] ) : '';

	return '' !== $label ? $label : $default;
}

/**
 * Get the configured singular label for resources (default: Staff).
 * 無料版では保存値ではなく既定値を返す。
 *
 * @return string
 */
function vkbm_get_resource_label_singular(): string {
	$default = __( 'Staff', 'vk-booking-manager' );

	// 無料版は呼び方を変えられないため、保存値があっても既定値で表示する。
	if ( vkbm_should_use_default_resource_labels() ) {
		return $default;
	}

	return vkbm_get_saved_resource_label_singular();
}

/**
 * Get the configured plural label for resources (default: Staff).
 * 無料版では保存値ではなく既定値を返す。
 *
 * @return string
 */
function vkbm_get_resource_label_plural(): string {
	$default = __( 'Staff', 'vk-booking-manager' );

	// 無料版は呼び方を変えられないため、保存値があっても既定値で表示する。
	if ( vkbm_should_use_default_resource_labels() ) {
		return $default;
	}

	// 保存値が空・未保存の場合は、取得関数が既定値を返す。
	$label = vkbm_get_saved_resource_label_plural();

	$singular = vkbm_get_resource_label_singular();
	// If the plural label is still the default and singular has been customized,
	// use the singular label as the plural label.
	// 複数形ラベルがデフォルトのままで単数形がカスタマイズされている場合、
	// 単数形ラベルを複数形として使用する。
	if ( $default === $label && $default !== $singular ) {
		return $singular;
	}

	return $label;
}

/**
 * リソースタグ絞り込みで該当リソースが無いときの表示文言を返す。
 *
 * 業種プリセットごとの自然な表現と、無料版を含むリソース複数名の実効値をここで解決し、
 * 予約画面と予約確定時のエラーで同じ完成済み文言を使えるようにする。
 *
 * @return string ローカライズ済みの表示文言。
 */
function vkbm_get_resource_tag_empty_message(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$defaults   = $repository->get_default_settings();
	$preset     = Industry_Presets::get_current_preset( $settings, $defaults );
	$plural     = vkbm_get_resource_label_plural();

	// 宿泊は客室などの無生物を想定した表現にする。
	if ( 'lodging' === $preset ) {
		return sprintf(
			/* translators: %s: リソースの複数名（例: 客室）。 */
			__( 'No %s are available matching the selected conditions.', 'vk-booking-manager' ),
			$plural
		);
	}

	// 定義済みの人物を扱う業種は、人が不在であることを伝える表現にする。
	if ( in_array( $preset, array( 'salon', 'activity', 'seminar' ), true ) ) {
		return sprintf(
			/* translators: %s: リソースの複数名（例: スタッフ、ガイド、講師）。 */
			__( 'There are no %s matching the selected conditions.', 'vk-booking-manager' ),
			$plural
		);
	}

	// カスタム・無料版・未知の業種は、対象の種類に依存しない短い表現へ倒す。
	return sprintf(
		/* translators: %s: リソースの複数名。 */
		__( 'No matching %s.', 'vk-booking-manager' ),
		$plural
	);
}

/**
 * サービスメニュー編集画面で使うリソース選択関連の文言を返す。
 *
 * PHP の初期描画と JavaScript の切り替え表示で同じ完成文を使い、宿泊では「指定」、
 * それ以外では「指名」と表現する。
 *
 * @return array<string,string> リソース選択関連のローカライズ済み文言。
 */
function vkbm_get_resource_selection_strings(): array {
	$resource_label = vkbm_get_resource_label_singular();
	$is_lodging     = Industry_Presets::supports( 'lodging' );

	if ( $is_lodging ) {
		return array(
			/* translators: %s: resource label (singular), e.g. "Room". */
			'heading'                     => sprintf( _x( '%s selection', 'resource selection setting heading', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'checkbox'                    => sprintf( __( 'Allow customers to select a %s for this service menu.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %1$s: resource label (singular), e.g. "Room". */
			'unchecked'                   => sprintf( __( 'When unchecked, a %1$s is assigned automatically for this service menu and customers cannot select one.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'max_capacity_default_1'      => sprintf( __( 'Maximum number of people who can share this time slot when %s is assigned automatically.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'max_capacity_default_2'      => sprintf( __( 'When a specific %s is selected, only one booking per slot is allowed.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'max_capacity_nomination_1'   => sprintf( __( 'Maximum number of guests that can be booked together in a single reservation when a %s is selected.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'max_capacity_nomination_2'   => sprintf( __( 'Once a booking is placed, that %s is fully booked for the slot and cannot be shared with another customer.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'min_capacity_nomination_1'   => sprintf( __( 'This menu allows %s selection, so each booking becomes a private booking for one group.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'multiple_guests_assignment'  => sprintf( __( 'The maximum number per booking is the time slot capacity, because each booking is assigned to a single %s.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'multiple_guests_conditions'  => sprintf( __( 'When this is enabled and the time slot capacity is 2 or more, the minimum participants (labeled "minimum guests to accept a booking" for menus that allow %s selection), price category, and private booking settings are shown.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'exclusive_minimum_condition' => sprintf( __( 'It can be chosen only when the number of guests meets this menu\'s minimum participants (labeled "minimum guests to accept a booking" for menus that allow %s selection).', 'vk-booking-manager' ), $resource_label ),
			/* translators: %1$s: resource label (singular), e.g. "Room". */
			'exclusive_when_booked'       => sprintf( __( 'For menus that allow %1$s selection, this only closes the selected %1$s for the time slot, and other options remain bookable.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %1$s: resource label (singular), e.g. "Room". */
			'exclusive_user_selectable'   => sprintf( __( 'For menus that allow %1$s selection, this is judged per selected %1$s, not for the whole time slot.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %1$s: resource label (singular), e.g. "Room". */
			'exclusive_when_booked_night' => sprintf( __( 'Only the booked %1$s will stop accepting reservations for that time slot. Other %1$s remain bookable.', 'vk-booking-manager' ), $resource_label ),
			/* translators: %s: resource label (singular), e.g. "Room". */
			'exclusive_user_night'        => sprintf( __( 'This is judged per %s, not for the whole time slot.', 'vk-booking-manager' ), $resource_label ),
		);
	}

	return array(
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'heading'                     => sprintf( _x( '%s nomination', 'resource nomination setting heading', 'vk-booking-manager' ), $resource_label ),
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'checkbox'                    => sprintf( __( 'Use %s nomination for this service menu.', 'vk-booking-manager' ), $resource_label ),
		/* translators: %1$s: resource label (singular), e.g. "Staff". */
		'unchecked'                   => sprintf( __( 'When unchecked, a %1$s is assigned automatically for this service menu and customers cannot choose a specific %1$s.', 'vk-booking-manager' ), $resource_label ),
		'max_capacity_default_1'      => __( 'Maximum number of people who can share this time slot when staff auto-assignment is used.', 'vk-booking-manager' ),
		'max_capacity_default_2'      => __( 'When a specific staff member is selected, only one booking per slot is allowed.', 'vk-booking-manager' ),
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'max_capacity_nomination_1'   => sprintf( __( 'Maximum number of guests that can be booked together in a single reservation when a %s is nominated.', 'vk-booking-manager' ), $resource_label ),
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'max_capacity_nomination_2'   => sprintf( __( 'Once a booking is placed, that %s is fully booked for the slot (private to that group) and cannot be shared with another customer.', 'vk-booking-manager' ), $resource_label ),
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'min_capacity_nomination_1'   => sprintf( __( 'This menu uses %s nomination, so each booking becomes a private booking for one group.', 'vk-booking-manager' ), $resource_label ),
		'multiple_guests_assignment'  => __( 'The maximum number per booking is the time slot capacity, because each booking is assigned to a single staff member.', 'vk-booking-manager' ),
		'multiple_guests_conditions'  => __( 'When this is enabled and the time slot capacity is 2 or more, the minimum participants (labeled "minimum guests to accept a booking" for menus that use staff nomination), price category, and private booking settings are shown.', 'vk-booking-manager' ),
		'exclusive_minimum_condition' => __( 'It can be chosen only when the number of guests meets this menu\'s minimum participants (labeled "minimum guests to accept a booking" for menus that use staff nomination).', 'vk-booking-manager' ),
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'exclusive_when_booked'       => sprintf( __( 'For menus that use %1$s nomination, this only closes that booking\'s %1$s for the time slot, and other %1$s remain bookable.', 'vk-booking-manager' ), $resource_label ),
		/* translators: %s: resource label (singular), e.g. "Staff". */
		'exclusive_user_selectable'   => sprintf( __( 'For menus that use %1$s nomination, this is judged per %1$s, not for the whole time slot.', 'vk-booking-manager' ), $resource_label ),
		'exclusive_when_booked_night' => '',
		'exclusive_user_night'        => '',
	);
}

/**
 * Get the configured menu-card label for available resources (default: Staff available).
 * メニューカードの「担当できる〇〇」に使う呼び方を返す。無料版では保存値ではなく既定値を返す。
 *
 * @return string
 */
function vkbm_get_resource_label_menu(): string {
	$default = __( 'Staff available', 'vk-booking-manager' );

	// 無料版は呼び方を変えられないため、保存値があっても既定値で表示する。
	if ( vkbm_should_use_default_resource_labels() ) {
		return $default;
	}

	return vkbm_get_saved_resource_label_menu();
}

/**
 * リソース管理メニューに使用するデフォルトの Dashicons クラス名。
 *
 * @return string デフォルトの Dashicons クラス名。
 */
function vkbm_get_default_resource_menu_icon(): string {
	return 'dashicons-groups';
}

/**
 * リソースメニューアイコンの値を検証してサニタイズする。
 *
 * 値が 'dashicons-' で始まる正しい形式（英小文字・数字・ハイフンのみ）の場合のみ
 * その値を返し、それ以外（空文字・不正な形式・文字列以外）の場合はデフォルト
 * アイコンを返す。これにより管理画面メニューに不正なアイコンクラスが出力される
 * のを防ぐ。
 *
 * @param mixed $value 検証対象の値。
 * @return string サニタイズ済みの Dashicons クラス名。
 */
function vkbm_sanitize_resource_menu_icon( $value ): string {
	$default = vkbm_get_default_resource_menu_icon();

	if ( ! is_string( $value ) ) {
		return $default;
	}

	$value = trim( $value );

	// 'dashicons-' で始まり、英小文字・数字・ハイフンのみで構成される値のみ許可する。
	if ( 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $value ) ) {
		return $value;
	}

	return $default;
}

/**
 * 設定済みのリソース管理メニューアイコン（Dashicons クラス名）を取得する。
 *
 * 未設定または不正な値の場合はデフォルトアイコンを返す。
 *
 * @return string Dashicons クラス名。
 */
function vkbm_get_resource_menu_icon(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$value      = $settings['resource_menu_icon'] ?? '';

	return vkbm_sanitize_resource_menu_icon( $value );
}

/**
 * 呼び方を「表示中の言語で登録した値 → 基本の値 → 翻訳の既定値」の順で決め、フィルターフックに通して返す。
 *
 * 表示中の言語は determine_locale() で判定する（switch_to_locale() で切り替えている間は、切り替え後の言語になる）。
 * 言語ごとの値は BM 設定の「多言語設定」タブで登録する（Multilingual_Labels）。
 *
 * @param string   $key            呼び方のキー（Multilingual_Labels::FIELD_KEYS のいずれか）。
 * @param callable $get_base_label 言語ごとの値が空のときに使う値（基本の値、無ければ翻訳の既定値）を返す関数。引数に表示中のロケールを受け取る。
 * @return string 表示する呼び方。
 */
function vkbm_resolve_label( string $key, callable $get_base_label ): string {
	$locale = determine_locale();
	$label  = Multilingual_Labels::get_value( $locale, $key );

	// 表示中の言語の値が無ければ、基本の値（無ければ翻訳の既定値）を使う。
	if ( '' === $label ) {
		$label = (string) $get_base_label( $locale );
	}

	/**
	 * 表示する呼び方を差し替える。
	 *
	 * WPML・Polylang など、Bogo 以外の多言語化プラグインを使うサイトで、言語に合わせた呼び方へ差し替えるときに使う。
	 *
	 * @param string $label  表示する呼び方（表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決めた値）。
	 * @param string $key    呼び方のキー（例: menu_label、guests_count_label）。
	 * @param string $locale 表示中のロケール（determine_locale() の値。例: en_US）。
	 */
	return (string) apply_filters( 'vkbm_label', $label, $key, $locale ); // フック名は Multilingual_Labels::FILTER_HOOK と揃える。
}

/**
 * 呼び方のキーから、基本の値（無ければ翻訳の既定値）を返す。
 *
 * 「多言語設定」タブの入力欄のプレースホルダー（空欄のときに表示される呼び方）に使う。
 * 対象の言語で取得するには、呼び出し側で switch_to_locale() してから呼ぶ。
 *
 * @param string $key 呼び方のキー（Multilingual_Labels::FIELD_KEYS のいずれか）。
 * @return string 基本の値。対象外のキーなら空文字。
 */
function vkbm_get_base_label( string $key ): string {
	$getters = array(
		'duration_label'         => 'vkbm_get_base_duration_label',
		'other_conditions_label' => 'vkbm_get_base_other_conditions_label',
		'menu_label'             => 'vkbm_get_base_menu_label',
		'menu_label_plural'      => 'vkbm_get_base_menu_label_plural',
		'guests_count_label'     => 'vkbm_get_base_guests_count_label',
		'guests_unit_label'      => 'vkbm_get_base_guests_unit_label',
		'no_nomination_label'    => 'vkbm_get_base_no_nomination_label',
		'nomination_fee_label'   => 'vkbm_get_base_nomination_fee_label',
	);

	return isset( $getters[ $key ] ) ? (string) call_user_func( $getters[ $key ] ) : '';
}

/**
 * 指名なしラベルを取得する（デフォルト: No preference）。
 *
 * 表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決める（vkbm_resolve_label()）。
 *
 * @return string
 */
function vkbm_get_no_nomination_label(): string {
	return vkbm_resolve_label( 'no_nomination_label', 'vkbm_get_base_no_nomination_label' );
}

/**
 * 基本の値（「表記設定」タブの値）の指名なしラベルを取得する（デフォルト: No preference）。
 *
 * @return string
 */
function vkbm_get_base_no_nomination_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['no_nomination_label'] ) ? trim( (string) $settings['no_nomination_label'] ) : '';

	return '' !== $label ? $label : __( 'No preference', 'vk-booking-manager' );
}

/**
 * 指名料ラベルを取得する（デフォルト: Nomination fee）。
 *
 * 表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決める（vkbm_resolve_label()）。
 *
 * @return string
 */
function vkbm_get_nomination_fee_label(): string {
	return vkbm_resolve_label( 'nomination_fee_label', 'vkbm_get_base_nomination_fee_label' );
}

/**
 * 基本の値の指名料ラベルを取得する（デフォルト: Nomination fee）。
 *
 * @return string
 */
function vkbm_get_base_nomination_fee_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['nomination_fee_label'] ) ? trim( (string) $settings['nomination_fee_label'] ) : '';

	return '' !== $label ? $label : __( 'Nomination fee', 'vk-booking-manager' );
}

/**
 * Get the configured duration label (default: Time).
 * 所要時間ラベルを取得する（デフォルト: Time）。
 *
 * 表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決める（vkbm_resolve_label()）。
 *
 * @return string
 */
function vkbm_get_duration_label(): string {
	return vkbm_resolve_label( 'duration_label', 'vkbm_get_base_duration_label' );
}

/**
 * 基本の値の所要時間ラベルを取得する（デフォルト: Time）。
 *
 * @return string
 */
function vkbm_get_base_duration_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['duration_label'] ) ? trim( (string) $settings['duration_label'] ) : '';

	return '' !== $label ? $label : __( 'Time', 'vk-booking-manager' );
}

/**
 * Get the configured other conditions label (default: Other conditions).
 * その他条件ラベルを取得する（デフォルト: Other conditions）。
 *
 * 表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決める（vkbm_resolve_label()）。
 *
 * @return string
 */
function vkbm_get_other_conditions_label(): string {
	return vkbm_resolve_label( 'other_conditions_label', 'vkbm_get_base_other_conditions_label' );
}

/**
 * 基本の値のその他条件ラベルを取得する（デフォルト: Other conditions）。
 *
 * @return string
 */
function vkbm_get_base_other_conditions_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['other_conditions_label'] ) ? trim( (string) $settings['other_conditions_label'] ) : '';

	return '' !== $label ? $label : __( 'Other conditions', 'vk-booking-manager' );
}

/**
 * 数量の見出しラベルを取得する（デフォルト: Number of guests / 人数）。
 *
 * 表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決める（vkbm_resolve_label()）。
 *
 * @return string
 */
function vkbm_get_guests_count_label(): string {
	return vkbm_resolve_label( 'guests_count_label', 'vkbm_get_base_guests_count_label' );
}

/**
 * 基本の値の数量の見出しラベルを取得する（デフォルト: Number of guests / 人数）。
 *
 * 既存のラベル群と同じく、設定が空文字の場合は翻訳既定へフォールバックする。
 *
 * @return string
 */
function vkbm_get_base_guests_count_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['guests_count_label'] ) ? trim( (string) $settings['guests_count_label'] ) : '';

	return '' !== $label ? $label : __( 'Number of guests', 'vk-booking-manager' );
}

/**
 * メニューの呼び方を取得する（デフォルト: Menu / メニュー）。
 *
 * 表示中の言語で登録した値 → 基本の値 → 翻訳の既定値の順で決める（vkbm_resolve_label()）。
 * 無料版でも保存値をそのまま返す。
 *
 * @return string 表示する呼び方。
 */
function vkbm_get_menu_label(): string {
	return vkbm_resolve_label( 'menu_label', 'vkbm_get_base_menu_label' );
}

/**
 * 基本の値のメニューの呼び方を取得する（デフォルト: Menu / メニュー）。
 *
 * 数量の見出しと同じく、設定が空文字の場合は翻訳既定へフォールバックする。
 *
 * @return string 保存された呼び方。未保存・空文字の場合は翻訳既定の「メニュー」。
 */
function vkbm_get_base_menu_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['menu_label'] ) ? trim( (string) $settings['menu_label'] ) : '';

	return '' !== $label ? $label : __( 'Menu', 'vk-booking-manager' );
}

/**
 * メニューの呼び方（複数形）を取得する。
 *
 * 表示中の言語で登録した複数形 → 基本の値の複数形 → 単数形の基本の値の順で決める（vkbm_get_base_menu_label_plural()）。
 * ただし表示中の言語で単数形を登録しているときは、基本の値の複数形を飛ばして、その言語の単数形を使う
 * （別の言語の複数形が混ざらないようにするため）。
 *
 * @return string 表示する複数形の呼び方。
 */
function vkbm_get_menu_label_plural(): string {
	return vkbm_resolve_label(
		'menu_label_plural',
		static function ( string $locale ): string {
			// 表示中の言語で単数形を登録していれば、その単数形を複数形の代わりに使う。
			if ( '' !== Multilingual_Labels::get_value( $locale, 'menu_label' ) ) {
				return vkbm_get_menu_label();
			}

			return vkbm_get_base_menu_label_plural();
		}
	);
}

/**
 * 基本の値のメニューの呼び方（複数形）を取得する。
 *
 * 複数形の欄は複数形のある言語の画面にしか出ないため、空文字の場合は
 * 単数形の基本の値（vkbm_get_base_menu_label()）へフォールバックする。言語ごとの値は混ぜない。
 *
 * @return string 保存された複数形。未保存・空文字の場合は単数形の基本の値。
 */
function vkbm_get_base_menu_label_plural(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();
	$label      = isset( $settings['menu_label_plural'] ) ? trim( (string) $settings['menu_label_plural'] ) : '';

	return '' !== $label ? $label : vkbm_get_base_menu_label();
}

/**
 * 文に差し込む「メニューの呼び方」を取得する。
 *
 * 空欄・「メニュー」（`Menu` / `メニュー` / 翻訳済みの `Menu`）のときは空文字を返す。
 * 呼び出し側は空文字を見て、今ある文言をそのまま出す（設定を変えていないサイトの表示と、
 * 追加プラグインでの文言の差し替えを変えないため）。判定は JS 側の
 * `src/blocks/reservation/components/menu-label.js` の normalizeMenuLabel() と揃える。
 *
 * @return string 差し込む呼び方。既定のままなら空文字。
 */
function vkbm_get_custom_menu_label(): string {
	$label          = vkbm_get_menu_label();
	$default_labels = array( 'Menu', 'メニュー', __( 'Menu', 'vk-booking-manager' ) );

	return in_array( $label, $default_labels, true ) ? '' : $label;
}

/**
 * 文に差し込む「メニューの呼び方」（複数形）を取得する。
 *
 * 呼び方の要否は単数形で判定し、単数形が既定のままなら空文字を返す。
 *
 * @return string 差し込む複数形の呼び方。既定のままなら空文字。
 */
function vkbm_get_custom_menu_label_plural(): string {
	return '' === vkbm_get_custom_menu_label() ? '' : vkbm_get_menu_label_plural();
}

/**
 * 呼び方を変えていれば差し込み用の文に入れ、変えていなければ今ある文言を返す。
 *
 * 差し込み用の文では、呼び方を最初の引数（`%s` または `%1$s`）に、`$extra_args` をその後ろの引数に入れる。
 *
 * @param string $default_text     呼び方を変えていないときに出す、今ある文言（翻訳・sprintf 済み）。
 * @param string $labeled_template 呼び方を差し込む文（翻訳済み）。
 * @param array  $extra_args       呼び方の後ろに差し込む値。
 * @param bool   $use_plural       複数形の呼び方を差し込むなら true。
 * @return string 画面・メールに出す文言。
 */
function vkbm_get_menu_label_text( string $default_text, string $labeled_template, array $extra_args = array(), bool $use_plural = false ): string {
	$label = $use_plural ? vkbm_get_custom_menu_label_plural() : vkbm_get_custom_menu_label();
	if ( '' === $label ) {
		return $default_text;
	}

	return sprintf( $labeled_template, $label, ...$extra_args );
}

/**
 * 数量の単位ラベルを取得する（実効値を返す）。
 *
 * 表示中の言語で登録した値 → 基本の値（vkbm_get_base_guests_unit_label()）の順で決める（vkbm_resolve_label()）。
 * 言語ごとの値の空欄は「単位なし」ではなく「基本の値を使う」として扱う。
 *
 * @return string 表示する単位。単位なしの場合は空文字。
 */
function vkbm_get_guests_unit_label(): string {
	return vkbm_resolve_label( 'guests_unit_label', 'vkbm_get_base_guests_unit_label' );
}

/**
 * 基本の値（「表記設定」タブの値）の数量の単位ラベルを取得する。
 *
 * 設定値の出し分け:
 * - null（未設定）       → ロケール既定を返す（従来挙動の維持）。日本語は「名」、その他は「guests」。
 * - ''（意図的な空保存） → 空文字を返す（単位なし）。
 * - それ以外            → 保存された単位値をそのまま返す。
 *
 * @return string ロケール既定または保存された単位。単位なしの場合は空文字。
 */
function vkbm_get_base_guests_unit_label(): string {
	$repository = new Settings_Repository();
	$settings   = $repository->get_settings();

	// array_merge で defaults とマージされるため、保存値にキーが無ければ既定の null が入る。
	// 保存値にキーがあれば（空文字でも）そちらが優先される。
	$unit = $settings['guests_unit_label'] ?? null;

	// null（未設定）はロケール既定へ解決する。
	if ( null === $unit ) {
		return vkbm_get_guests_unit_label_locale_default();
	}

	// 空文字を含め、保存された値はそのまま返す（trim はしない。前後スペースもユーザー意図とみなす）。
	return (string) $unit;
}

/**
 * 数量の単位ラベルのロケール既定値を返す。
 *
 * 従来の表示（日本語「5名」/英語「5 guests」）を再現するための既定。
 * 日本語ロケールは「名」、それ以外のロケールは「guests」を返す。
 *
 * @return string
 */
function vkbm_get_guests_unit_label_locale_default(): string {
	// 日本語ロケールは従来どおり「名」を既定の単位とする。
	// この分岐は locale が日本語のときのみ通るため、翻訳は不要（固定値）。
	if ( strpos( get_locale(), 'ja' ) === 0 ) {
		return '名';
	}

	// 非日本語ロケールは従来の「5 guests」相当を再現するため「guests」を返す。
	return __( 'guests', 'vk-booking-manager' );
}

/**
 * 数量（数値＋単位）を表示用文字列に整形する。
 *
 * 単位とのスペーシングをここに集約し、各表示箇所で同じ組み立てを使えるようにする。
 * - 単位が空文字（単位なし）の場合は数値のみを返す。
 * - 日本語の単位（「名」「台」等）は数値と単位を詰めて返す（例: 5名）。
 * - 半角英字で始まる単位（「guests」等）は数値との間に半角スペースを入れる（例: 5 guests）。
 *
 * @param int         $count 数量。
 * @param string|null $unit  単位。null の場合は実効値（ロケール既定含む）を解決して使用する。
 * @return string
 */
function vkbm_format_guests_count( int $count, ?string $unit = null ): string {
	// 単位が渡されなければ実効値（設定 or ロケール既定）を取得する。
	if ( null === $unit ) {
		$unit = vkbm_get_guests_unit_label();
	}

	// 単位なしの場合は数値のみを返す。
	if ( '' === $unit ) {
		return (string) $count;
	}

	// 単位が半角英字で始まる場合のみ、数値との間に半角スペースを入れる（例: 5 guests）。
	// 日本語など全角の単位は詰めて表示する（例: 5名 / 5台）。
	$separator = preg_match( '/^[A-Za-z]/', $unit ) === 1 ? ' ' : '';

	return $count . $separator . $unit;
}

/**
 * リソースの表示名を取得する。
 * Get the display name for a resource.
 *
 * `resource_tag_display_enabled` 設定が ON の場合、リソース名の後ろに
 * タグを括弧表記で追加する。OFF の場合はリソース名のみ返す。
 * When the `resource_tag_display_enabled` setting is ON, tags are appended
 * in parentheses after the resource name. When OFF, only the name is returned.
 *
 * @param int $post_id リソース投稿ID / Resource post ID.
 * @return string 表示用リソース名 / Display name for the resource.
 */
function vkbm_get_resource_display_name( int $post_id ): string {
	// リソースの投稿だけを対象にする。
	if ( Resource_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
		return '';
	}

	// リクエスト内で設定値をキャッシュ（wp_cache 経由でテスト間のリセットにも対応）
	// Cache the setting value within the request (via wp_cache so it resets between tests).
	$enabled = wp_cache_get( 'resource_tag_display_enabled', 'vkbm' );
	if ( false === $enabled ) {
		$repository = new Settings_Repository();
		$settings   = $repository->get_settings();
		$enabled    = ! empty( $settings['resource_tag_display_enabled'] ) ? 'yes' : 'no';
		wp_cache_set( 'resource_tag_display_enabled', $enabled, 'vkbm' );
	}

	$name = get_the_title( $post_id );
	if ( '' === $name ) {
		return '';
	}

	if ( 'yes' !== $enabled ) {
		return $name;
	}

	$tag_labels = Resource_Tag_Taxonomy::get_tag_labels( $post_id );
	if ( empty( $tag_labels ) ) {
		return $name;
	}

	return $name . ' ( ' . implode( ', ', $tag_labels ) . ' )';
}
