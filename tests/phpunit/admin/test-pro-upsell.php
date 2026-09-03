<?php
/**
 * 無料版のプロ版誘導リンク（Pro_Upsell）の単体テスト。
 *
 * @package VKBookingManager
 * @see     https://github.com/vektor-inc/vk-booking-manager-pro/issues/73
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use WP_UnitTestCase;

/**
 * Pro_Upsell の URL 解決とプラグイン一覧リンク追加処理を検証する。
 *
 * @group admin
 */
class Pro_Upsell_Test extends WP_UnitTestCase {

	/**
	 * テスト環境のロケールを英語に固定し、翻訳文字列が英語のまま返るようにする。
	 */
	protected function setUp(): void {
		parent::setUp();
		switch_to_locale( 'en_US' );
	}

	/**
	 * テスト終了後にロケールとフィルターを元に戻す。
	 */
	protected function tearDown(): void {
		remove_all_filters( 'vkbm_pro_upsell_url' );
		restore_previous_locale();
		parent::tearDown();
	}

	/**
	 * フィルター未設定時は既定のプロ版製品ページ URL を返すことをテストする。
	 */
	public function test_get_pro_url_returns_default(): void {
		$this->assertSame(
			Pro_Upsell::PRO_URL,
			Pro_Upsell::get_pro_url(),
			'フィルター未設定時は定数 PRO_URL がそのまま返ること'
		);
	}

	/**
	 * vkbm_pro_upsell_url フィルターで遷移先 URL を上書きできることをテストする。
	 */
	public function test_get_pro_url_can_be_filtered(): void {
		// フィルターで任意の URL に差し替える。
		add_filter(
			'vkbm_pro_upsell_url',
			static function (): string {
				return 'https://example.com/custom';
			}
		);

		$this->assertSame(
			'https://example.com/custom',
			Pro_Upsell::get_pro_url(),
			'フィルターで遷移先 URL を上書きできること'
		);
	}

	/**
	 * プラグイン一覧の操作リンクにアップグレードリンクが先頭追加されることをテストする。
	 */
	public function test_add_action_link_prepends_upgrade_link(): void {
		$upsell = new Pro_Upsell();

		// 既存リンク（例: 設定リンク）を渡す。
		$existing = array(
			'settings' => '<a href="#">Settings</a>',
		);

		$result = $upsell->add_action_link( $existing );

		// 先頭要素がアップグレードリンクであること。
		$first = reset( $result );
		$this->assertStringContainsString( Pro_Upsell::PRO_URL, $first, '遷移先 URL を含むこと' );
		$this->assertStringContainsString( 'Upgrade to Pro', $first, 'アップグレード文言を含むこと' );

		// 既存リンクが保持されていること。
		$this->assertArrayHasKey( 'settings', $result, '既存リンクが維持されること' );
		$this->assertCount( 2, $result, 'リンクが1件追加されること' );
	}

	/**
	 * 配列以外が渡された場合でも安全にアップグレードリンクのみを返すことをテストする。
	 */
	public function test_add_action_link_handles_non_array_input(): void {
		$upsell = new Pro_Upsell();

		// 想定外の型（null）を渡す。
		$result = $upsell->add_action_link( null );

		$this->assertIsArray( $result, '常に配列を返すこと' );
		$this->assertCount( 1, $result, 'アップグレードリンクのみ含むこと' );
	}
}
