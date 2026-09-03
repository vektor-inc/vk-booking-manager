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
	 * @return array<int, array{label: string, price: int}> 正規化済みの料金区分。
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

			$tiers[] = array(
				'label' => $label,
				'price' => $price,
			);

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
	 * @return array<int, array{label: string, price: int}>
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

			$tiers[] = array(
				'label' => $label,
				'price' => $price,
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
	 * @return array<int, array{label: string, price: int, count: int}>
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

			$tiers[] = array(
				'label' => $label,
				'price' => $price,
				'count' => $count,
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
	 * 予約時に送られた区分ごとの人数を、メニューの正規メタ（ラベル・料金）に突き合わせて確定する。
	 *
	 * - 料金・ラベルは必ずサーバ保存済みメタ（$menu_tiers）を正とする。
	 *   クライアントから送られた料金・ラベルは一切信用しない（改竄防止）。
	 * - 人数はインデックス（区分の並び順）で突き合わせ、0 以上にクランプする。
	 * - 結果は区分ごとに { label, price, count } を持つ確定スナップショットとして返す。
	 *
	 * @param array<int, array{label: string, price: int}> $menu_tiers   メニューの正規化済み料金区分（サーバ保存メタ）。
	 * @param mixed                                        $requested    クライアントが送った区分人数。
	 *                                                                   `[ index => count ]` または `[ [ 'count' => n ], ... ]` を許容する。
	 * @return array<int, array{label: string, price: int, count: int}> 確定済みの区分内訳スナップショット。
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

			$snapshot[] = array(
				'label' => $label,
				'price' => $price,
				'count' => $count,
			);
		}

		return $snapshot;
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
				$count = isset( $value['count'] ) && is_numeric( $value['count'] ) ? (int) $value['count'] : 0;
			} elseif ( is_numeric( $value ) ) {
				// `index => n` 形式。
				$count = (int) $value;
			} else {
				$count = 0;
			}

			$counts[ $idx ] = max( 0, $count );
		}

		return $counts;
	}

	/**
	 * 区分内訳スナップショットの合計人数を求める。
	 *
	 * @param array<int, array{label: string, price: int, count: int}> $guest_tiers 区分内訳。
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
	 * @param array<int, array{label: string, price: int, count: int}> $guest_tiers 区分内訳。
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
