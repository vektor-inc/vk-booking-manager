<?php
/**
 * #633: 予約ブロックのフッター用データが REST レスポンスへ正しく反映されることのテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\REST;

use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\REST\Provider_Settings_Controller;
use WP_UnitTestCase;

/**
 * フッターの表示可否（reservation_show_footer）と、フッターに載せる店舗情報・
 * プライバシーポリシー（reservation_footer）の出し分けを検証する。
 *
 * 実際の表示（空項目の非表示・モーダル）は React 側のため、Jest（footer-model.test.js）で検証する。
 *
 * @group rest
 * @group provider-settings
 */
class Provider_Settings_Controller_Footer_Test extends WP_UnitTestCase {
	/**
	 * get_settings() のレスポンスが、フッター設定の ON/OFF に応じて
	 * reservation_show_footer / reservation_footer を返すことを検証する。
	 */
	public function test_get_settings(): void {
		$repository = new Settings_Repository();
		$defaults   = $repository->get_default_settings();
		$controller = new Provider_Settings_Controller( $repository );

		$store_info = array(
			'provider_name'                   => '奥伊勢SUPクラブ',
			'provider_address'                => '〒519-0000 三重県多気郡大台町サンプル123-4',
			'provider_phone'                  => '0598-00-0000',
			'provider_business_hours'         => "9:00〜17:00\n定休日：水曜",
			'provider_privacy_policy_mode'    => 'content',
			'provider_privacy_policy_url'     => '',
			'provider_privacy_policy_content' => 'プライバシーポリシー本文',
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'フッター OFF => false で、店舗情報は公開しない（null）',
				'conditions'          => array_merge( $store_info, array( 'reservation_show_footer' => false ) ),
				'expected_show'       => false,
				'expected_footer'     => null,
			),
			array(
				'test_condition_name' => 'フッター ON・本文モード => 店舗情報と本文を返す',
				'conditions'          => array_merge( $store_info, array( 'reservation_show_footer' => true ) ),
				'expected_show'       => true,
				'expected_footer'     => array(
					'provider_name'  => '奥伊勢SUPクラブ',
					'address'        => '〒519-0000 三重県多気郡大台町サンプル123-4',
					'phone'          => '0598-00-0000',
					'business_hours' => "9:00〜17:00\n定休日：水曜",
					'website_url'    => '',
					'privacy_policy' => array(
						'mode'    => 'content',
						'url'     => '',
						'content' => 'プライバシーポリシー本文',
					),
				),
			),
			array(
				'test_condition_name' => 'フッター ON・URL モード => URL を返し、本文は返さない',
				'conditions'          => array_merge(
					$store_info,
					array(
						'reservation_show_footer'         => true,
						'provider_privacy_policy_mode'    => 'url',
						'provider_privacy_policy_url'     => 'https://example.com/privacy/',
						'provider_privacy_policy_content' => '使われない本文',
					)
				),
				'expected_show'       => true,
				'expected_footer'     => array(
					'provider_name'  => '奥伊勢SUPクラブ',
					'address'        => '〒519-0000 三重県多気郡大台町サンプル123-4',
					'phone'          => '0598-00-0000',
					'business_hours' => "9:00〜17:00\n定休日：水曜",
					'website_url'    => '',
					'privacy_policy' => array(
						'mode'    => 'url',
						'url'     => 'https://example.com/privacy/',
						'content' => '',
					),
				),
			),
			array(
				'test_condition_name' => 'フッター ON・店舗情報が未入力・プライバシーポリシー無し => 空文字で返す',
				'conditions'          => array(
					'reservation_show_footer'         => true,
					'provider_name'                   => '',
					'provider_address'                => '',
					'provider_phone'                  => '',
					'provider_business_hours'         => '',
					'provider_privacy_policy_mode'    => 'none',
					'provider_privacy_policy_url'     => 'https://example.com/privacy/',
					'provider_privacy_policy_content' => '使われない本文',
				),
				'expected_show'       => true,
				'expected_footer'     => array(
					'provider_name'  => '',
					'address'        => '',
					'phone'          => '',
					'business_hours' => '',
					'website_url'    => '',
					'privacy_policy' => array(
						'mode'    => 'none',
						'url'     => '',
						'content' => '',
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$repository->update_settings( array_merge( $defaults, $case['conditions'] ) );

			$data = $controller->get_settings()->get_data();

			$this->assertSame( $case['expected_show'], $data['reservation_show_footer'], $case['test_condition_name'] . ' (reservation_show_footer)' );
			$this->assertSame( $case['expected_footer'], $data['reservation_footer'], $case['test_condition_name'] . ' (reservation_footer)' );
		}
	}

	/**
	 * オプション自体が保存されていない状態（フレッシュインストール相当）では、
	 * フッターは非表示（false）で返ることを固定する（境界値）。
	 */
	public function test_get_settings_defaults_to_footer_disabled_when_option_not_saved(): void {
		delete_option( Settings_Repository::OPTION_KEY );

		$controller = new Provider_Settings_Controller( new Settings_Repository() );
		$data       = $controller->get_settings()->get_data();

		$this->assertFalse( $data['reservation_show_footer'] );
		$this->assertNull( $data['reservation_footer'] );
	}

	/**
	 * build_footer_data() が、前後の空白の除去と、プライバシーポリシーの不正なモードの
	 * 「表示しない」への丸め込みを行うことを検証する。
	 */
	public function test_build_footer_data(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '前後の空白は除去する',
				'settings'            => array(
					'provider_name'                => '  店舗  ',
					'provider_phone'               => ' 03-0000-0000 ',
					'provider_privacy_policy_mode' => 'none',
				),
				'expected'            => array(
					'provider_name' => '店舗',
					'phone'         => '03-0000-0000',
					'mode'          => 'none',
				),
			),
			array(
				'test_condition_name' => '不正なモードは none に丸める',
				'settings'            => array(
					'provider_privacy_policy_mode'    => 'unknown',
					'provider_privacy_policy_content' => '本文',
				),
				'expected'            => array(
					'provider_name' => '',
					'phone'         => '',
					'mode'          => 'none',
				),
			),
			array(
				'test_condition_name' => 'キーが無い設定でも空文字で返す',
				'settings'            => array(),
				'expected'            => array(
					'provider_name' => '',
					'phone'         => '',
					'mode'          => 'none',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = Provider_Settings_Controller::build_footer_data( $case['settings'] );

			$this->assertSame( $case['expected']['provider_name'], $result['provider_name'], $case['test_condition_name'] . ' (provider_name)' );
			$this->assertSame( $case['expected']['phone'], $result['phone'], $case['test_condition_name'] . ' (phone)' );
			$this->assertSame( $case['expected']['mode'], $result['privacy_policy']['mode'], $case['test_condition_name'] . ' (mode)' );
		}
	}

	/**
	 * build_footer_data() の website_url が、予約ページURLと異なるときだけ返ることを検証する。
	 */
	public function test_build_footer_data_website_url(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '予約ページURLと別のURL => ウェブサイトURLを返す',
				'settings'            => array(
					'provider_website_url' => 'https://example.com/',
					'reservation_page_url' => 'https://example.com/reserve/',
				),
				'expected'            => 'https://example.com/',
			),
			array(
				'test_condition_name' => '予約ページURL未入力 => ウェブサイトURLを返す',
				'settings'            => array(
					'provider_website_url' => 'https://example.com/',
					'reservation_page_url' => '',
				),
				'expected'            => 'https://example.com/',
			),
			array(
				'test_condition_name' => '予約ページURLのキーが無い => ウェブサイトURLを返す',
				'settings'            => array( 'provider_website_url' => 'https://example.com/' ),
				'expected'            => 'https://example.com/',
			),
			array(
				'test_condition_name' => '同じURL => 空文字',
				'settings'            => array(
					'provider_website_url' => 'https://example.com/reserve/',
					'reservation_page_url' => 'https://example.com/reserve/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '末尾スラッシュだけ違う => 空文字',
				'settings'            => array(
					'provider_website_url' => 'https://example.com/reserve',
					'reservation_page_url' => 'https://example.com/reserve/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '大文字小文字だけ違う => 空文字',
				'settings'            => array(
					'provider_website_url' => 'https://Example.com/Reserve/',
					'reservation_page_url' => 'https://example.com/reserve/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '前後に空白がある同じURL => 空文字',
				'settings'            => array(
					'provider_website_url' => '  https://example.com/reserve/ ',
					'reservation_page_url' => ' https://example.com/reserve/  ',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '予約ページURLがパス指定で、ウェブサイトURLがそのページの絶対URL => 空文字',
				'settings'            => array(
					'provider_website_url' => home_url( '/reserve/' ),
					'reservation_page_url' => '/reserve/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ウェブサイトURL未入力 => 空文字',
				'settings'            => array(
					'provider_website_url' => '',
					'reservation_page_url' => 'https://example.com/reserve/',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ウェブサイトURLが http / https 以外（mailto:） => 空文字',
				'settings'            => array(
					'provider_website_url' => 'mailto:info@example.com',
					'reservation_page_url' => '',
				),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'ウェブサイトURLが空白のみ => 空文字',
				'settings'            => array(
					'provider_website_url' => '   ',
					'reservation_page_url' => '',
				),
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = Provider_Settings_Controller::build_footer_data( $case['settings'] );

			$this->assertSame( $case['expected'], $result['website_url'], $case['test_condition_name'] );
		}
	}
}
