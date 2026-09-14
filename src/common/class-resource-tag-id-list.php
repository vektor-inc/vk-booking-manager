<?php
/**
 * リソースタグのターム ID 配列の正規化を担うユーティリティ。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VKBookingManager\Admin\Pro_Upsell;

/**
 * リソースタグ ID 配列の正規化ルールを1か所に集約するユーティリティクラス。
 *
 * 以前は REST 引数・下書きペイロード・タクソノミーヘルパーなど6箇所で同じ正規化処理
 * （正の整数だけ・重複除去・上限件数）を個別に実装しており、`absint()` を使った実装だけ
 * 負値（例: -5）が絶対値化されて有効なIDとして紛れ込む挙動の食い違いがあった。
 * このクラスに一本化し、全箇所で同じ挙動（`(int)` キャスト後に 0 以下を除外）を保証する。
 *
 * また、無料版にはリソースタグ機能自体が無い（タグを持つリソースの照合ロジックが
 * 常に空配列を返すスタブに置き換わる）ため、無料版で `resource_tag_ids` を指定すると
 * 「該当リソース0件」として絞り込まれてしまい、常に空き枠なし・409になる不具合があった。
 * この正規化の入口（normalize()）で無料版なら常に空配列を返すことで、無料版では
 * タグ指定を「絞り込みなし」として扱う挙動に統一する。呼び出し側（空き枠計算・下書き・
 * 予約確定・メニュー一覧APIなど）は個別にエディション判定を持たない。
 */
class Resource_Tag_Id_List {
	/**
	 * 1回の絞り込みで受け付けるリソースタグ ID の最大件数。
	 *
	 * 運用上、数十件のタグを同時選択することは想定しないため十分な余裕を持った値とする。
	 * REST 引数の `maxItems` にもこの定数を使い、スキーマ側の上限とサニタイズ側の上限を一致させる。
	 *
	 * @var int
	 */
	public const MAX_COUNT = 20;

	/**
	 * `Pro_Upsell::is_free_edition()` の判定結果のメモ化キャッシュ。
	 *
	 * `is_free_edition()` はプラグイン本体ファイルを `get_file_data()` で毎回読み直す
	 * 実装のため、`normalize()` を1リクエスト内で何度も呼ぶ経路（予約一覧の表示等、
	 * 1行ごとに呼ばれる箇所がある）では無視できない負荷になる。エディションは
	 * 1リクエスト内で変わらないため、`normalize()` の第2引数を省略した呼び出し
	 * （＝実行時のエディション判定に頼る呼び出し）に限り、判定結果をここへ記憶して
	 * 使い回す。第2引数を明示した呼び出し（テスト等）はこのキャッシュを参照・更新しない。
	 *
	 * @var bool|null
	 */
	private static ?bool $free_edition_cache = null;

	/**
	 * リソースタグ ID の生の値を、重複除去済み・正の整数のみの配列へ正規化する。
	 *
	 * `absint()` は負値を絶対値化してしまう（例: `absint( -5 )` は `5` になる）ため、
	 * ここでは `(int)` キャスト後に 0 以下の値を除外する方式に統一し、負値・0 を
	 * 有効な ID として紛れ込ませない。上限件数（self::MAX_COUNT）に達した時点で
	 * ループを打ち切り、巨大配列送信による CPU 負荷・肥大化（DoS 経路）を防ぐ。
	 *
	 * 無料版にはリソースタグ機能自体が無いため、無料版のときは入力値に関わらず
	 * 常に空配列を返し、タグ指定を「絞り込みなし」として扱う。
	 *
	 * @param mixed     $raw_tag_ids     生の値（配列以外は空配列として扱う）。
	 * @param bool|null $is_free_edition テスト注入用。省略時は `Pro_Upsell::is_free_edition()` の
	 *                                    判定結果（メモ化キャッシュ経由）で実行時のエディションを
	 *                                    判定する。明示した場合はキャッシュを参照・更新しない。
	 * @return array<int> 正規化済みのターム ID 配列。無料版のときは常に空配列。
	 */
	public static function normalize( $raw_tag_ids, ?bool $is_free_edition = null ): array {
		if ( null === $is_free_edition ) {
			// 第2引数省略時のみキャッシュを使う。一度判定した後は同一リクエスト内で
			// 使い回し、`get_file_data()` によるファイル読み直しを繰り返さない。
			self::$free_edition_cache ??= Pro_Upsell::is_free_edition();
			$is_free_edition            = self::$free_edition_cache;
		}

		// 無料版にはタグ機能自体が無いため、要求された絞り込みは常に「未指定」として扱う。
		if ( $is_free_edition ) {
			return array();
		}

		if ( ! is_array( $raw_tag_ids ) ) {
			return array();
		}

		$tag_ids = array();
		$seen    = array();

		foreach ( $raw_tag_ids as $candidate ) {
			$id = (int) $candidate;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$tag_ids[]   = $id;

			if ( count( $tag_ids ) >= self::MAX_COUNT ) {
				break;
			}
		}

		return $tag_ids;
	}
}
