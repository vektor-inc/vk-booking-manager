<?php
/**
 * 料金区分（大人料金・子供料金など）のサニタイズ・計算を担うユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\Staff\Staff_Editor;
use function get_post_meta;

/**
 * 料金区分の正規化・人数内訳の計算をまとめたユーティリティクラス。
 *
 * サービスメニューに定義する料金区分（`_vkbm_price_tiers`）と、
 * 予約時に確定する区分人数の内訳（`_vkbm_booking_guest_tiers`）の
 * サニタイズ・計算をここに集約する。状態を持たない純粋なメソッドのみで構成し、
 * 管理画面・REST コントローラ・PHPUnit のいずれからも同じロジックを再利用できるようにする。
 */
class Price_Tiers {
	/**
	 * 1メニューに定義できる料金区分の最大件数。
	 *
	 * 無制限にすると wp_options / postmeta の肥大化や UI の破綻を招くため、
	 * 現実的な上限としてクランプする。
	 *
	 * @var int
	 */
	public const MAX_TIERS = 20;

	/**
	 * 1区分あたりの人数の上限（極端に大きな値で合計が整数の範囲を超えないようにする）。
	 */
	public const MAX_GUESTS_PER_TIER = 9999;

	/**
	 * 区分名（ラベル）の最大文字数。
	 *
	 * @var int
	 */
	public const LABEL_MAX_LENGTH = 50;

	/**
	 * 料金区分の生入力（配列）を保存用の正規化済み配列に変換する。
	 *
	 * - ラベルは sanitize_text_field で無害化し、最大文字数で切り詰める。
	 * - 料金は整数化し 0 以上にクランプする（負数は 0）。
	 * - ラベルが空の行は除去する（料金だけの行は意味を持たないため）。
	 * - 件数は MAX_TIERS でクランプする。
	 *
	 * @param mixed $raw 生の料金区分入力。`[ [ 'label' => ..., 'price' => ... ], ... ]` を想定。
	 * @return array<int, array{label: string, price: int, pre_holiday_price?: int, special_price?: int}> 正規化済みの料金区分。
	 */
	public static function sanitize_tiers( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$tiers = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// ラベルを無害化して前後の空白を除去する。
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$label = trim( $label );

			// ラベルが空の行は保存対象から除外する（料金のみの行は使い道がない）。
			if ( '' === $label ) {
				continue;
			}

			// マルチバイトを壊さないよう mb_substr で最大文字数に切り詰める。
			if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $label, 'UTF-8' ) > self::LABEL_MAX_LENGTH ) {
					$label = mb_substr( $label, 0, self::LABEL_MAX_LENGTH, 'UTF-8' );
				}
			} else {
				$label = substr( $label, 0, self::LABEL_MAX_LENGTH );
			}

			// 料金は整数化し 0 以上にクランプする（負数・非数値は 0 とする）。
			$price_raw = $row['price'] ?? 0;
			$price     = is_numeric( $price_raw ) ? (int) $price_raw : 0;
			$price     = max( 0, $price );

			$tier = array(
				'label' => $label,
				'price' => $price,
			);
			// 休前日・特定日の料金は任意。空欄・数値でない値は保存しない（平日の料金で計算される）。
			$tiers[] = self::with_optional_prices( $tier, $row );

			// 件数上限に達したら以降は無視する。
			if ( count( $tiers ) >= self::MAX_TIERS ) {
				break;
			}
		}

		return $tiers;
	}

	/**
	 * 保存済みメタから取得した料金区分を正規化して返す。
	 *
	 * 取得値が配列でない場合や想定外の構造の場合は空配列を返す。
	 * サニタイズ済みメタを正として扱う場面（料金計算・表示）で使用する。
	 *
	 * @param mixed $raw get_post_meta() で取得した料金区分。
	 * @return array<int, array{label: string, price: int, pre_holiday_price?: int, special_price?: int}>
	 */
	public static function normalize_tiers( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$tiers = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['label'] ) ) {
				continue;
			}

			$label = trim( (string) $row['label'] );
			if ( '' === $label ) {
				continue;
			}

			$price = isset( $row['price'] ) && is_numeric( $row['price'] ) ? max( 0, (int) $row['price'] ) : 0;

			$tiers[] = self::with_optional_prices(
				array(
					'label' => $label,
					'price' => $price,
				),
				$row
			);
		}

		return $tiers;
	}

	/**
	 * 予約に保存済みの区分内訳スナップショット（{ label, price, count }）を正規化して返す。
	 *
	 * normalize_tiers() は label/price のみで count を落とすため、人数を含む保存済みスナップショット
	 * （_vkbm_booking_guest_tiers）の取得には必ずこちらを使う。人数表示・合計再計算で count を保持する。
	 * 取得値が配列でない／ラベルが空の行は除外する。
	 *
	 * @param mixed $raw get_post_meta() で取得した区分内訳スナップショット。
	 * @return array<int, array{label: string, price: int, count: int, pre_holiday_price?: int, special_price?: int}>
	 */
	public static function normalize_guest_tiers( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$tiers = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['label'] ) ) {
				continue;
			}

			$label = trim( (string) $row['label'] );
			if ( '' === $label ) {
				continue;
			}

			$price = isset( $row['price'] ) && is_numeric( $row['price'] ) ? max( 0, (int) $row['price'] ) : 0;
			$count = isset( $row['count'] ) && is_numeric( $row['count'] ) ? max( 0, (int) $row['count'] ) : 0;

			$tiers[] = self::with_optional_prices(
				array(
					'label' => $label,
					'price' => $price,
					'count' => $count,
				),
				$row
			);
		}

		return $tiers;
	}

	/**
	 * メニューが料金区分を定義しているかどうかを判定する。
	 *
	 * 1件でも有効な区分があれば true。区分料金で計算する（基本料金は使わない）かどうかの判定に使う。
	 *
	 * @param mixed $raw 保存済みの料金区分メタ。
	 * @return bool
	 */
	public static function has_tiers( $raw ): bool {
		return ! empty( self::normalize_tiers( $raw ) );
	}

	/**
	 * メニューが「料金区分で設定されている」かどうかを判定する（#515）。
	 *
	 * 管理画面のサービスメニュー一覧の料金列（`Service_Menu_Post_Type::render_admin_columns()`）と
	 * 公開側メニューカード（`Menu_Loop_Block::render_meta_information()`）の両方で「基本料金の代わりに
	 * 区分一覧を表示するか」を判定するために使う、単一の入口。判定条件は次の4つすべてを満たすこと。
	 *
	 * 1. `Staff_Editor::is_multi_guest_available_for_menu()` が true（Pro版・予約枠の定員機能ON）。
	 * 2. 複数人一括予約が有効（`Service_Menu_Post_Type::is_multi_guest_booking_enabled()`。1室1泊あたりのプランは料金区分を使わないため除く）。
	 * 3. 予約枠の定員（`_vkbm_max_capacity`）が2以上。
	 * 4. 区分（`_vkbm_price_tiers`）が1件以上保存されている。
	 *
	 * この4条件は、編集画面で料金区分欄（`#vkbm-price-tiers-field`）を表示する条件
	 * （`Service_Menu_Editor::render_basic_meta_box()` の `$show_multi_guest_dependent_fields`）と
	 * 実質的に同一（1〜3）に「区分が1件以上」（4）を加えたもの。編集画面は区分欄そのものの
	 * 表示可否（空でも編集を始められるように）を、この判定は「区分で表示するか基本料金で表示するか」の
	 * 排他判定を担うため、条件4の有無だけが異なる。
	 *
	 * 予約確定時の料金計算（`Booking_Confirmation_Controller::resolve_menu_price_tiers()`）は、
	 * 上記に加えて「スタッフが1名以上割り当てられていること」も条件にしており、意図的に統合しない
	 * （スタッフ未割当のメニューはそもそも予約できないため、表示判定に含める必要が無い。安藤さんの判断）。
	 *
	 * @param int $menu_id サービスメニュー（vkbm_service_menu）の投稿ID。
	 * @return bool 料金区分で設定されているメニューなら true。それ以外（基本料金で設定）は false。
	 */
	public static function is_menu_using_price_tiers( int $menu_id ): bool {
		if ( $menu_id <= 0 ) {
			return false;
		}

		if ( ! Staff_Editor::is_multi_guest_available_for_menu( $menu_id ) ) {
			return false;
		}

		if ( ! Service_Menu_Post_Type::is_multi_guest_booking_enabled( $menu_id ) ) {
			return false;
		}

		// 1室1泊あたりのプランは料金区分を使わない（編集画面でも区分の行を出さず、保存時に区分を削除する）ため、
		// 複数人一括予約が常に ON でも「区分で設定されている」とは扱わない（#831）。
		if ( Service_Menu_Post_Type::is_night_price_per_room( $menu_id ) ) {
			return false;
		}

		// max_capacity の取得方法はメニューカード（Menu_Loop_Block::render_meta_information()）と揃える。
		$max_capacity = (int) get_post_meta( $menu_id, '_vkbm_max_capacity', true );
		if ( $max_capacity < 2 ) {
			return false;
		}

		return self::has_tiers( get_post_meta( $menu_id, '_vkbm_price_tiers', true ) );
	}

	/**
	 * 予約時に送られた区分ごとの人数を、メニューの正規メタ（ラベル・料金）に突き合わせて確定する。
	 *
	 * - 料金・ラベルは必ずサーバ保存済みメタ（$menu_tiers）を正とする。
	 *   クライアントから送られた料金・ラベルは一切信用しない（改竄防止）。
	 * - 人数はインデックス（区分の並び順）で突き合わせ、0 以上にクランプする。
	 * - 結果は区分ごとに { label, price, count } を持つ確定スナップショットとして返す。
	 *
	 * @param array<int, array{label: string, price: int, pre_holiday_price?: int, special_price?: int}> $menu_tiers   メニューの正規化済み料金区分（サーバ保存メタ）。
	 * @param mixed                                                                                      $requested    クライアントが送った区分人数。
	 *                                                                                                                 `[ index => count ]` または `[ [ 'count' => n ], ... ]` を許容する。
	 * @return array<int, array{label: string, price: int, count: int, pre_holiday_price?: int, special_price?: int}> 確定済みの区分内訳スナップショット。
	 */
	public static function resolve_guest_tiers( array $menu_tiers, $requested ): array {
		$counts = self::extract_counts( $requested );

		$snapshot = array();
		foreach ( $menu_tiers as $index => $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}

			$label = trim( (string) ( $tier['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$price = isset( $tier['price'] ) && is_numeric( $tier['price'] ) ? max( 0, (int) $tier['price'] ) : 0;
			$count = isset( $counts[ $index ] ) ? max( 0, (int) $counts[ $index ] ) : 0;

			$snapshot[] = self::with_optional_prices(
				array(
					'label' => $label,
					'price' => $price,
					'count' => $count,
				),
				$tier
			);
		}

		return $snapshot;
	}

	/**
	 * 区分の行に、休前日・特定日の料金（任意）を足す。
	 *
	 * 休前日・特定日の料金が入っている行にだけ `pre_holiday_price` / `special_price` を足す。
	 * 空欄・未設定の行にはキーを足さない（今までの料金区分と同じ形のまま、平日の料金で計算される）。
	 *
	 * @param array<string, mixed> $tier 平日の料金までを持つ区分の行。
	 * @param array<string, mixed> $row  休前日・特定日の料金を含みうる元の行。
	 * @return array<string, mixed> 休前日・特定日の料金を足した区分の行。
	 */
	private static function with_optional_prices( array $tier, array $row ): array {
		foreach ( array( 'pre_holiday_price', 'special_price' ) as $key ) {
			$value = $row[ $key ] ?? null;
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			// 空欄（未設定）と 0 円は別物。'' は未設定、'0' は 0 円として扱う。
			if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
				continue;
			}

			$tier[ $key ] = max( 0, (int) $value );
		}

		return $tier;
	}

	/**
	 * クライアント入力から区分インデックスごとの人数を取り出す。
	 *
	 * 受け入れる形:
	 * - `[ 0 => 3, 1 => 2 ]`（インデックス => 人数）
	 * - `[ [ 'count' => 3 ], [ 'count' => 2 ] ]`（オブジェクト配列）
	 *
	 * @param mixed $requested クライアントが送った区分人数。
	 * @return array<int, int> インデックス => 人数（0 以上）。
	 */
	private static function extract_counts( $requested ): array {
		if ( ! is_array( $requested ) ) {
			return array();
		}

		$counts = array();
		foreach ( $requested as $index => $value ) {
			$idx = (int) $index;
			if ( $idx < 0 ) {
				continue;
			}

			if ( is_array( $value ) ) {
				// `[ 'count' => n ]` 形式。
				$count = isset( $value['count'] ) && is_numeric( $value['count'] ) ? self::clamp_count( $value['count'] ) : 0;
			} elseif ( is_numeric( $value ) ) {
				// `index => n` 形式。
				$count = self::clamp_count( $value );
			} else {
				$count = 0;
			}

			$counts[ $idx ] = max( 0, $count );
		}

		return $counts;
	}

	/**
	 * 数値の人数を 0〜区分ごとの上限人数の整数に収める。
	 *
	 * @param int|float|string $value 数値として扱える人数。
	 * @return int 0 以上、区分ごとの上限人数以下の人数。
	 */
	private static function clamp_count( $value ): int {
		$count = (float) $value;

		// PHP 8.5 から NAN の int キャストが警告になるため、キャスト前に 0 として扱う。
		if ( is_nan( $count ) ) {
			return 0;
		}

		return (int) max( 0.0, min( $count, (float) self::MAX_GUESTS_PER_TIER ) );
	}

	/**
	 * 区分内訳スナップショットの合計人数を求める。
	 *
	 * @param array<int, array{label: string, price: int, count: int, pre_holiday_price?: int, special_price?: int}> $guest_tiers 区分内訳。
	 * @return int 合計人数。
	 */
	public static function total_count( array $guest_tiers ): int {
		$total = 0;
		foreach ( $guest_tiers as $tier ) {
			if ( is_array( $tier ) && isset( $tier['count'] ) ) {
				$total += max( 0, (int) $tier['count'] );
			}
		}

		return $total;
	}

	/**
	 * 区分内訳スナップショットの合計金額（Σ 区分料金 × 区分人数）を求める。
	 *
	 * 指名料は含まない（呼び出し側で別途加算する）。料金はスナップショットの値を正とする。
	 *
	 * @param array<int, array{label: string, price: int, count: int, pre_holiday_price?: int, special_price?: int}> $guest_tiers 区分内訳。
	 * @return int 区分料金の合計（0 以上）。
	 */
	public static function total_price( array $guest_tiers ): int {
		$total = 0;
		foreach ( $guest_tiers as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}

			$price = isset( $tier['price'] ) && is_numeric( $tier['price'] ) ? max( 0, (int) $tier['price'] ) : 0;
			$count = isset( $tier['count'] ) && is_numeric( $tier['count'] ) ? max( 0, (int) $tier['count'] ) : 0;

			$total += $price * $count;
		}

		return max( 0, $total );
	}
}
