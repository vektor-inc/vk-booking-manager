<?php
/**
 * BM 設定画面の「お支払いの案内」欄と、旧初期値のままのときの見直し通知の描画テスト（#540）。
 *
 * - 入力欄のラベルが予約確認画面の見出し「決済方法」（Payment method）とは別の「お支払いの案内」
 *   （Payment instructions）になっていること
 * - オンライン決済が有効なときだけ説明文が続けて表示されること
 * - オンライン決済が有効かつ旧初期値のままのときだけ、画面上部（どのタブでも）と入力欄のすぐ下に
 *   通知が出ること。上部の通知には店舗タブの入力欄へのリンクが付き、閉じるボタンは付かないこと
 *
 * 文言を英語のまま検証できるよう、ロケールを en_US に固定して描画する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Provider_Settings_Page;
use VKBookingManager\ProviderSettings\Payment_Instructions;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\ProviderSettings\Settings_Sanitizer;
use VKBookingManager\ProviderSettings\Settings_Service;
use WP_UnitTestCase;
use function add_filter;
use function delete_option;
use function ob_get_clean;
use function ob_start;
use function remove_all_filters;
use function restore_previous_locale;
use function switch_to_locale;
use function update_option;
use function wp_set_current_user;

/**
 * Provider_Settings_Page::render_page() のお支払いの案内まわりの出力を検証するテストクラス。
 *
 * @group admin
 */
class Provider_Settings_Page_Payment_Instructions_Test extends WP_UnitTestCase {

	/**
	 * ロケールを切り替えたかどうか（後片付けで元に戻すため）。
	 *
	 * @var bool
	 */
	private $switched_locale = false;

	/**
	 * テスト前に管理者としてログインし、ロケールを英語に固定する。
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->switched_locale = switch_to_locale( 'en_US' );
		delete_option( Settings_Repository::OPTION_KEY );
	}

	/**
	 * テスト後に保存内容・フィルター・ロケール・画面の指定を元へ戻す。
	 */
	public function tear_down(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		remove_all_filters( Payment_Instructions::ONLINE_PAYMENT_ENABLED_FILTER );
		if ( $this->switched_locale ) {
			restore_previous_locale();
		}
		$_GET = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * 指定したタブで設定画面を描画し、出力を返す。
	 *
	 * @param string $tab 表示するタブ。
	 * @return string 描画結果。
	 */
	private function render_settings_page( string $tab ): string {
		$page = new Provider_Settings_Page(
			new Settings_Service( new Settings_Repository(), new Settings_Sanitizer() ),
			'manage_options',
			''
		);

		$_GET['tab'] = $tab;

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * 条件（オンライン決済の有効・無効、保存されている案内文、タブ）ごとの出力を検証する。
	 */
	public function test_render_page(): void {
		$legacy_ja = "ご来店時に店舗にてお支払いください。\n現金、クレジットカード、交通系ICカードがご利用いただけます。";

		$test_cases = array(
			array(
				'test_condition_name' => 'オンライン決済が有効・旧初期値（日本語）・店舗タブ => 上部と入力欄下に通知、説明文の追記あり',
				'online_enabled'      => true,
				'stored_text'         => $legacy_ja,
				'tab'                 => 'store',
				'expected'            => array(
					'page_notice'  => true,
					'field_notice' => true,
					'extra_desc'   => true,
				),
			),
			array(
				'test_condition_name' => 'オンライン決済が有効・0.0.2 以前の英語の旧初期値・システムタブ => 他のタブでも上部に通知',
				'online_enabled'      => true,
				'stored_text'         => 'Please pay at the store when you visit. \nYou can use cash, credit cards, and transportation ICs.',
				'tab'                 => 'system',
				'expected'            => array(
					'page_notice'  => true,
					'field_notice' => true,
					'extra_desc'   => true,
				),
			),
			array(
				'test_condition_name' => 'オンライン決済が有効・旧初期値（改行コード \r\n と行末空白入り）=> 表記ゆれを揃えて通知',
				'online_enabled'      => true,
				'stored_text'         => "ご来店時に店舗にてお支払いください。 \r\n現金、クレジットカード、交通系ICカードがご利用いただけます。\r\n",
				'tab'                 => 'store',
				'expected'            => array(
					'page_notice'  => true,
					'field_notice' => true,
					'extra_desc'   => true,
				),
			),
			array(
				'test_condition_name' => 'オンライン決済が有効・書き換え済みの文章 => 通知なし、説明文の追記あり',
				'online_enabled'      => true,
				'stored_text'         => 'ご来店時のお支払いには、現金のみご利用いただけます。',
				'tab'                 => 'store',
				'expected'            => array(
					'page_notice'  => false,
					'field_notice' => false,
					'extra_desc'   => true,
				),
			),
			array(
				'test_condition_name' => 'オンライン決済が有効・空欄 => 通知なし（対象外）',
				'online_enabled'      => true,
				'stored_text'         => '',
				'tab'                 => 'store',
				'expected'            => array(
					'page_notice'  => false,
					'field_notice' => false,
					'extra_desc'   => true,
				),
			),
			array(
				'test_condition_name' => 'オンライン決済が無効・旧初期値 => 通知なし、説明文の追記なし',
				'online_enabled'      => false,
				'stored_text'         => $legacy_ja,
				'tab'                 => 'store',
				'expected'            => array(
					'page_notice'  => false,
					'field_notice' => false,
					'extra_desc'   => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// オンライン決済の有効・無効をフィルターで切り替える。
			remove_all_filters( Payment_Instructions::ONLINE_PAYMENT_ENABLED_FILTER );
			add_filter( Payment_Instructions::ONLINE_PAYMENT_ENABLED_FILTER, $case['online_enabled'] ? '__return_true' : '__return_false' );

			// 案内文を保存する。
			update_option( Settings_Repository::OPTION_KEY, array( 'provider_payment_method' => $case['stored_text'] ) );

			$output = $this->render_settings_page( $case['tab'] );
			$name   = $case['test_condition_name'];

			// ラベルは「お支払いの案内」になり、予約確認画面の見出し「決済方法」とは別の文字列になっている。
			$this->assertStringContainsString( '<label for="vkbm-provider-payment-method">Payment instructions</label>', $output, $name . '（ラベル）' );

			// 常に表示する説明文。
			$this->assertStringContainsString(
				'Enter payment information for customers (for example, payment methods accepted at the store and receipts). This is shown in the &quot;Payment method&quot; section of the booking confirmation screen.',
				$output,
				$name . '（常に表示する説明文）'
			);

			// オンライン決済が有効なときだけ続けて表示する説明文。
			$this->assertSame(
				$case['expected']['extra_desc'],
				str_contains( $output, 'Because card payment is enabled, this text is shown to customers regardless of which payment method they choose.' ),
				$name . '（説明文の追記）'
			);

			// 画面上部の通知（店舗タブの入力欄へのリンク付き・閉じるボタンなし）。
			$page_notice_pattern = '#<div class="notice notice-warning vkbm-payment-instructions-notice"><p>Card payment is enabled, but &quot;Payment instructions&quot; still contains the default text written for paying at the store\. This text is also shown to customers who choose card payment, so please revise it to fit both payment methods\. <a href="[^"]*tab=store\#vkbm-provider-payment-method" class="vkbm-payment-instructions-notice__link">Edit payment instructions</a></p></div>#';
			$this->assertSame(
				$case['expected']['page_notice'] ? 1 : 0,
				preg_match( $page_notice_pattern, $output ),
				$name . '（画面上部の通知）'
			);

			// 入力欄のすぐ下の注意（WordPress が上部へ移動させないよう inline クラス付き）。
			// textarea の閉じタグの直後（間は空白のみ）に出ていることで「すぐ下」を確認する。
			$field_notice_pattern = '#</textarea>\s*<div class="notice notice-warning inline vkbm-payment-instructions-field-notice" id="vkbm-provider-payment-method-notice"><p>This is still the default text written for paying at the store\. It is also shown to customers who choose card payment, so please revise it\.</p></div>#';
			$this->assertSame(
				$case['expected']['field_notice'] ? 1 : 0,
				preg_match( $field_notice_pattern, $output ),
				$name . '（入力欄のすぐ下の注意）'
			);

			// 通知には閉じるボタンを付けない。
			$this->assertStringNotContainsString( 'vkbm-payment-instructions-notice is-dismissible', $output, $name . '（閉じるボタンなし）' );
		}
	}
}
