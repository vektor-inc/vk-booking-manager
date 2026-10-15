<?php
/**
 * Payment_Instructions（お支払いの案内の旧初期値判定・オンライン決済の問い合わせ）の単体テスト（#540）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\ProviderSettings;

use VKBookingManager\ProviderSettings\Payment_Instructions;
use WP_UnitTestCase;
use function add_filter;
use function remove_all_filters;

/**
 * Payment_Instructions の各メソッドを検証するテストクラス。
 *
 * @group provider-settings
 */
class Payment_Instructions_Test extends WP_UnitTestCase {

	/**
	 * テスト後に vkbm_online_payment_enabled フィルターを取り除く。
	 */
	public function tear_down(): void {
		remove_all_filters( Payment_Instructions::ONLINE_PAYMENT_ENABLED_FILTER );
		parent::tear_down();
	}

	/**
	 * 改行コード・行末の空白・前後の空白が揃えられること。
	 */
	public function test_normalize_for_comparison(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '改行コード \r\n は \n に揃えられる',
				'text'                => "1行目\r\n2行目",
				'expected'            => "1行目\n2行目",
			),
			array(
				'test_condition_name' => '改行コード \r は \n に揃えられる',
				'text'                => "1行目\r2行目",
				'expected'            => "1行目\n2行目",
			),
			array(
				'test_condition_name' => '各行末の半角スペース・タブは取り除かれる',
				'text'                => "1行目 \t\n2行目  ",
				'expected'            => "1行目\n2行目",
			),
			array(
				'test_condition_name' => '全体の前後の空白・改行は取り除かれる',
				'text'                => "\n  1行目\n2行目\n\n",
				'expected'            => "1行目\n2行目",
			),
			array(
				'test_condition_name' => '行の途中の空白と「\」+「n」の2文字はそのまま残る',
				'text'                => 'visit. \nYou',
				'expected'            => 'visit. \nYou',
			),
			array(
				'test_condition_name' => '空文字は空文字のまま',
				'text'                => '',
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Payment_Instructions::normalize_for_comparison( $case['text'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 旧初期値（4通り）と一致するかが判定されること。
	 */
	public function test_is_legacy_default_text(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '日本語の旧初期値 => true',
				'text'                => "ご来店時に店舗にてお支払いください。\n現金、クレジットカード、交通系ICカードがご利用いただけます。",
				'expected'            => true,
			),
			array(
				'test_condition_name' => '英語の旧初期値 => true',
				'text'                => "Please pay at the store when you visit.\nYou can use cash, credit cards, and transportation ICs.",
				'expected'            => true,
			),
			array(
				'test_condition_name' => '英語の旧初期値の日本語訳 => true',
				'text'                => "来店時に店舗でお支払いください。\n現金、クレジットカード、交通系ICカードがご利用いただけます。",
				'expected'            => true,
			),
			array(
				'test_condition_name' => '0.0.2 以前の英語の旧初期値（「\」+「n」の2文字入り） => true',
				'text'                => 'Please pay at the store when you visit. \nYou can use cash, credit cards, and transportation ICs.',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '日本語の旧初期値で、改行コードが \r\n・行末と前後に空白がある => true',
				'text'                => "  ご来店時に店舗にてお支払いください。 \r\n現金、クレジットカード、交通系ICカードがご利用いただけます。\t\r\n",
				'expected'            => true,
			),
			array(
				'test_condition_name' => '新しい日本語の初期値 => false',
				'text'                => 'ご来店時のお支払いには、現金・クレジットカード・交通系ICカードがご利用いただけます。',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '旧初期値を一部書き換えた文章 => false',
				'text'                => "ご来店時に店舗にてお支払いください。\n現金のみご利用いただけます。",
				'expected'            => false,
			),
			array(
				'test_condition_name' => '旧初期値の1行目だけ => false',
				'text'                => 'ご来店時に店舗にてお支払いください。',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空欄 => false（対象外）',
				'text'                => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空白・改行だけ => false（空欄として対象外）',
				'text'                => " \r\n\t ",
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = Payment_Instructions::is_legacy_default_text( $case['text'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * vkbm_online_payment_enabled フィルターの返り値で判定されること。
	 */
	public function test_is_online_payment_enabled(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'フィルターが登録されていない => false（既定は無効）',
				'filter_value'        => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'フィルターが true を返す => true',
				'filter_value'        => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'フィルターが false を返す => false',
				'filter_value'        => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'フィルターが真と評価される値（1）を返す => true（bool に変換される）',
				'filter_value'        => 1,
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			remove_all_filters( Payment_Instructions::ONLINE_PAYMENT_ENABLED_FILTER );

			// null の場合はフィルターを登録しない。
			if ( null !== $case['filter_value'] ) {
				$filter_value = $case['filter_value'];
				add_filter(
					Payment_Instructions::ONLINE_PAYMENT_ENABLED_FILTER,
					static function () use ( $filter_value ) {
						return $filter_value;
					}
				);
			}

			$actual = Payment_Instructions::is_online_payment_enabled();
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * オンライン決済が有効で、かつ旧初期値のままのときだけ通知対象になること。
	 */
	public function test_should_show_legacy_default_notice(): void {
		$legacy_text = "ご来店時に店舗にてお支払いください。\n現金、クレジットカード、交通系ICカードがご利用いただけます。";

		$test_cases = array(
			array(
				'test_condition_name' => 'オンライン決済が有効 かつ 旧初期値 => true',
				'online_enabled'      => true,
				'text'                => $legacy_text,
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'オンライン決済が有効 かつ 書き換え済みの文章 => false',
				'online_enabled'      => true,
				'text'                => 'ご来店時のお支払いには、現金のみご利用いただけます。',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'オンライン決済が無効 かつ 旧初期値 => false',
				'online_enabled'      => false,
				'text'                => $legacy_text,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'オンライン決済が有効 かつ 空欄 => false',
				'online_enabled'      => true,
				'text'                => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			// オンライン決済が有効かどうかは引数で渡す（呼び出し側で1回だけ求める設計のため）。
			$actual = Payment_Instructions::should_show_legacy_default_notice( $case['text'], $case['online_enabled'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
