<?php
/**
 * 予約ブロックの app.js 用の翻訳 JSON に、reservation/ 直下のファイルの文言が統合されていることのテスト。
 *
 * 画面では app.js 用の JSON だけが読み込まれる。bin/merge-json-translations.js の
 * reservationBundledFiles に書き漏らすと、その文言だけ英語のまま表示される。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Blocks;

use WP_UnitTestCase;

/**
 * 予約ブロックの app.js 用の翻訳 JSON のテスト。
 *
 * @group blocks
 */
class Reservation_Block_App_Js_Translations_Test extends WP_UnitTestCase {
	private const APP_JS_JSON = 'vk-booking-manager-ja-5a65dc19bd83bf90afeedaaf518e966b.json';

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_bundled_messages(): array {
		return array(
			'guest-booking.js の名前の入力チェック'  => array( 'Please enter your name.' ),
			'guest-booking.js のメールの入力チェック' => array( 'Please enter your email address.' ),
			'guest-booking.js の電話番号の入力チェック' => array( 'Please enter your phone number.' ),
		);
	}

	/**
	 * @dataProvider provide_bundled_messages
	 */
	public function test_app_js_json_contains_message( string $message ): void {
		$path = dirname( __DIR__, 3 ) . '/languages/' . self::APP_JS_JSON;
		$this->assertFileExists( $path );

		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsArray( $data );

		$messages = $data['locale_data']['messages'] ?? array();
		$this->assertArrayHasKey( $message, $messages );
	}
}
