<?php
/**
 * 「お支払いの案内」（provider_payment_method）に関する判定をまとめたユーティリティクラス。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\ProviderSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function apply_filters;

/**
 * 「お支払いの案内」が旧初期値のままかどうかの判定と、オンライン決済が有効かどうかの問い合わせを行う。
 *
 * 決済プラグイン（vk-booking-manager-payments）でカード決済を有効にすると、予約確認画面の
 * 「カード決済」のすぐ下に、来店時払い向けの旧初期値の案内文が表示されて矛盾する（#538 / #540）。
 * BM 設定画面でその状態に気づけるよう、通知の表示条件をここで判定する。
 *
 * 状態を持たない静的メソッドのみで構成し、設定画面の描画処理から切り離して単体テストできるようにしている。
 */
class Payment_Instructions {
	/**
	 * オンライン決済が有効かどうかを問い合わせるフィルター名（テスト等でフィルターを付け外しする際に使う）。
	 *
	 * apply_filters() にはフック名を検索しやすいよう文字列で直接書いているため、変更する際は両方を揃えること。
	 *
	 * @var string
	 */
	public const ONLINE_PAYMENT_ENABLED_FILTER = 'vkbm_online_payment_enabled';

	/**
	 * 過去のバージョンで「お支払いの案内」の初期値として使っていた文章の一覧を返す。
	 *
	 * 翻訳関数を通さず、保存されうる文字列そのものを直書きしている（翻訳で変わると、
	 * 別の言語で保存された旧初期値を判定できなくなるため）。
	 *
	 * @return string[] 旧初期値の文章の配列。
	 */
	public static function get_legacy_default_texts(): array {
		return array(
			// 日本語サイトでの旧初期値（#540 で変更する前のもの）。
			"ご来店時に店舗にてお支払いください。\n現金、クレジットカード、交通系ICカードがご利用いただけます。",
			// 日本語以外のサイトでの旧初期値（#540 で変更する前のもの）。
			"Please pay at the store when you visit.\nYou can use cash, credit cards, and transportation ICs.",
			// 上記の英語の初期値に付けていた日本語訳（.po の msgstr）。
			"来店時に店舗でお支払いください。\n現金、クレジットカード、交通系ICカードがご利用いただけます。",
			// 0.0.2 以前の英語の初期値。改行ではなく「\」+「n」の2文字が入っている。
			'Please pay at the store when you visit. \nYou can use cash, credit cards, and transportation ICs.',
		);
	}

	/**
	 * 比較用に文章の表記ゆれを揃える。
	 *
	 * 改行コード（\r\n / \r）を \n に統一し、各行末の空白と、全体の前後の空白を取り除く。
	 *
	 * @param string $text 対象の文章。
	 * @return string 表記ゆれを揃えた文章。
	 */
	public static function normalize_for_comparison( string $text ): string {
		// 改行コードを \n に統一する。
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// 各行末の空白（半角スペース・タブ）を取り除く。
		$text = (string) preg_replace( '/[ \t]+$/m', '', $text );

		// 全体の前後の空白を取り除く。
		return trim( $text );
	}

	/**
	 * 文章が旧初期値のいずれかと一致するかを判定する。
	 *
	 * 空欄（空白だけの場合を含む）は対象外として false を返す。
	 *
	 * @param string $text 保存されている「お支払いの案内」。
	 * @return bool 旧初期値と一致する場合は true。
	 */
	public static function is_legacy_default_text( string $text ): bool {
		$normalized = self::normalize_for_comparison( $text );

		// 空欄は見直しを促す対象にしない。
		if ( '' === $normalized ) {
			return false;
		}

		// 旧初期値の側も同じ手順で揃えてから比較する。
		$legacy_texts = array_map( array( self::class, 'normalize_for_comparison' ), self::get_legacy_default_texts() );

		return in_array( $normalized, $legacy_texts, true );
	}

	/**
	 * オンライン決済（カード決済など）が有効かどうかを返す。
	 *
	 * 既定は無効（false）。決済プラグインが vkbm_online_payment_enabled フィルターで答える。
	 *
	 * @return bool オンライン決済が有効な場合は true。
	 */
	public static function is_online_payment_enabled(): bool {
		/**
		 * オンライン決済（カード決済など）が有効かどうかを問い合わせるフィルター。
		 *
		 * @param bool $enabled オンライン決済が有効な場合は true。既定は false。
		 */
		return (bool) apply_filters( 'vkbm_online_payment_enabled', false );
	}

	/**
	 * 「お支払いの案内」の見直しを促す通知を出すべきかを判定する。
	 *
	 * オンライン決済が有効で、かつ保存されている案内文が旧初期値のままのときに true を返す。
	 * オンライン決済が有効かどうかは呼び出し側で is_online_payment_enabled() を1回だけ求めて渡す
	 * （設定画面の1回の描画で、説明文の出し分けと同じ判定結果を使い回すため）。
	 *
	 * @param string $text                   保存されている「お支払いの案内」。
	 * @param bool   $online_payment_enabled オンライン決済が有効な場合は true。
	 * @return bool 通知を出すべき場合は true。
	 */
	public static function should_show_legacy_default_notice( string $text, bool $online_payment_enabled ): bool {
		return $online_payment_enabled && self::is_legacy_default_text( $text );
	}
}
