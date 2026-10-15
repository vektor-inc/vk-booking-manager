<?php
/**
 * Tests for vkbm_resolve_logout_redirect().
 *
 * @package VKBookingManager
 */

/**
 * issue #593: wp_logout フックは、戻り先の指定（redirect_to）がある場合は転送しない。
 *
 * @group logout
 */
class Logout_Redirect_Resolver_Test extends WP_UnitTestCase {
	/**
	 * データプロバイダ。
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_cases(): array {
		return array(
			'redirect_to あり: 転送しない'            => array( 'https://example.com/booking/?a=1', 'https://example.com/reservation/', '' ),
			'redirect_to なし: 予約ページ URL'        => array( '', 'https://example.com/reservation/', 'https://example.com/reservation/' ),
			'予約ページ URL 未設定: 何もしない'       => array( '', '', '' ),
			'redirect_to が空白のみ: 予約ページ URL' => array( '  ', 'https://example.com/reservation/', 'https://example.com/reservation/' ),
		);
	}

	/**
	 * 転送先の判定。
	 *
	 * @dataProvider provide_cases
	 *
	 * @param string $requested 戻り先の指定。
	 * @param string $reservation 予約ページ URL。
	 * @param string $expected 期待値。
	 */
	public function test_resolve( string $requested, string $reservation, string $expected ): void {
		$this->assertSame( $expected, vkbm_resolve_logout_redirect( $requested, $reservation ) );
	}

	/**
	 * リクエストから redirect_to を取り出す。
	 *
	 * @dataProvider provide_request_cases
	 *
	 * @param array<string, mixed> $request リクエスト相当の配列。
	 * @param string               $expected 期待値。
	 */
	public function test_get_requested_logout_redirect( array $request, string $expected ): void {
		$this->assertSame( $expected, vkbm_get_requested_logout_redirect( $request ) );
	}

	/**
	 * データプロバイダ（リクエスト）。
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function provide_request_cases(): array {
		return array(
			'あり'     => array( array( 'redirect_to' => 'https://example.com/booking/?a=1' ), 'https://example.com/booking/?a=1' ),
			'なし'     => array( array(), '' ),
			'空白のみ' => array( array( 'redirect_to' => '   ' ), '' ),
			'配列'     => array( array( 'redirect_to' => array( 'https://example.com/' ) ), '' ),
		);
	}
}
