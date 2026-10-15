<?php
/**
 * 予約ページを他サイトへ埋め込んだときのカードのタイトル（OEmbed_Override::override_response()）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\OEmbed;

use VKBookingManager\OEmbed\OEmbed_Override;
use VKBookingManager\ProviderSettings\Settings_Repository;
use WP_UnitTestCase;

/**
 * 埋め込みカードのタイトルが言語設定に従うことを検証するテストクラス。
 *
 * @group oembed
 */
class OEmbed_Override_Test extends WP_UnitTestCase {

	/**
	 * ロケールを切り替えたかどうか。
	 *
	 * @var bool
	 */
	private $switched_locale = false;

	/**
	 * 予約ページ URL と事業者名を保存しておく。
	 */
	protected function setUp(): void {
		parent::setUp();
		update_option(
			Settings_Repository::OPTION_KEY,
			array(
				'reservation_page_url' => home_url( '/reservation/' ),
				'provider_name'        => 'テスト店',
			)
		);
	}

	/**
	 * 設定とロケールを元に戻す。
	 */
	protected function tearDown(): void {
		delete_option( Settings_Repository::OPTION_KEY );
		if ( $this->switched_locale ) {
			restore_current_locale();
			$this->switched_locale = false;
		}
		// 直接読み込んだ日本語の翻訳ファイルが、後続のテストへ残らないようにする。
		unset( $GLOBALS['l10n']['vk-booking-manager'] );
		parent::tearDown();
	}

	/**
	 * タイトルが言語設定に従って翻訳されることを検証する。
	 */
	public function test_override_response(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '英語 => 「事業者名 Web reservation」',
				'locale'              => 'en_US',
				'expected_title'      => 'テスト店 Web reservation',
			),
			array(
				'test_condition_name' => '日本語 => 「事業者名 ウェブ予約」',
				'locale'              => 'ja',
				'expected_title'      => 'テスト店 ウェブ予約',
			),
		);

		// 日本語の翻訳ファイルを読み込んだ後に英語へ戻すと状態が残るため、英語 => 日本語の順で検証する。
		foreach ( $test_cases as $case ) {
			$this->switched_locale = switch_to_locale( $case['locale'] ) || $this->switched_locale;
			if ( 'ja' === $case['locale'] ) {
				// switch_to_locale() だけではプラグインの翻訳ファイルが読み込まれないため、.mo を直接読み込む。
				load_textdomain( 'vk-booking-manager', dirname( __DIR__, 3 ) . '/languages/vk-booking-manager-ja.mo' );
			}

			$override = new OEmbed_Override();
			$result   = $override->override_response(
				array( 'url' => home_url( '/reservation/' ) ),
				null
			);

			$this->assertSame( $case['expected_title'], $result['title'], $case['test_condition_name'] );
			$this->assertSame( 'テスト店', $result['author_name'], $case['test_condition_name'] . '（作成者名）' );
		}
	}
}
