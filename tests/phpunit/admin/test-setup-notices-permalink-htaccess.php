<?php
/**
 * Setup_Notices の「パーマリンク設定が保存されていない可能性があります」通知（issue #489）のテスト.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Setup_Notices;
use WP_UnitTestCase;

/**
 * サーバー側（.htaccess）に WordPress の書き換えルールが反映されていない場合にのみ
 * 通知が表示されることを検証する。
 *
 * @group admin
 */
class Setup_Notices_Permalink_Htaccess_Test extends WP_UnitTestCase {
	private const NOTICE_HEADING = 'Permalink settings may not have been saved';

	/**
	 * テストで作成する .htaccess のパス。
	 *
	 * @var string
	 */
	private string $htaccess_path = '';

	/**
	 * テスト開始時点の .htaccess の内容。ファイルが元から存在しなかった場合は null。
	 * tearDown で元の状態へ正確に復元するために保持する（安藤レビュー指摘 MEDIUM-3）。
	 *
	 * @var string|null
	 */
	private ?string $original_htaccess_contents = null;

	/**
	 * 管理者ユーザーを用意し、判定に必要な wp-admin 関数を読み込んでおく。
	 * あわせて、テスト対象の .htaccess の元の内容を控えておく。
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'extract_from_markers' ) || ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$this->htaccess_path = get_home_path() . '.htaccess';

		// テスト環境に元から存在する .htaccess を壊さないよう、内容を退避しておく。
		$this->original_htaccess_contents = file_exists( $this->htaccess_path )
			? (string) file_get_contents( $this->htaccess_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- テストのみでの直接読み取り。
			: null;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * テストで書き換えた .htaccess を元の内容へ復元し（元から無ければ削除）、
	 * パーマリンク構造・フィルタを元に戻す。他テストへ影響を残さないための後片付け。
	 */
	protected function tearDown(): void {
		if ( '' !== $this->htaccess_path ) {
			if ( null === $this->original_htaccess_contents ) {
				if ( file_exists( $this->htaccess_path ) ) {
					wp_delete_file( $this->htaccess_path );
				}
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テストのみでの直接書き戻し。
				file_put_contents( $this->htaccess_path, $this->original_htaccess_contents );
			}
		}
		remove_all_filters( 'got_rewrite' );
		$this->set_permalink_structure( '' );

		parent::tearDown();
	}

	/**
	 * mod_rewrite パーマリンク＋mod_rewrite ありのサーバーで、.htaccess 自体が無い
	 * （＝ WordPress マーカー内が空扱いになる）場合に通知が表示されることを確認する。
	 */
	public function test_notice_shown_when_htaccess_rules_missing(): void {
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'got_rewrite', '__return_true' );

		if ( file_exists( $this->htaccess_path ) ) {
			wp_delete_file( $this->htaccess_path );
		}

		$output = $this->capture_notices();

		$this->assertStringContainsString( self::NOTICE_HEADING, $output );
	}

	/**
	 * .htaccess の WordPress マーカー内に書き換えルールが反映されていれば、
	 * 通知が表示されないことを確認する。
	 */
	public function test_notice_hidden_when_htaccess_rules_present(): void {
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'got_rewrite', '__return_true' );

		insert_with_markers(
			$this->htaccess_path,
			'WordPress',
			array( 'RewriteEngine On', 'RewriteRule . /index.php [L]' )
		);

		$output = $this->capture_notices();

		$this->assertStringNotContainsString( self::NOTICE_HEADING, $output );
	}

	/**
	 * 基本パーマリンク（mod_rewrite を使わない）のときは、.htaccess の状態に関わらず
	 * 通知が表示されないことを確認する（対象外の環境で誤って表示しない）。
	 */
	public function test_notice_hidden_when_permalinks_are_plain(): void {
		$this->set_permalink_structure( '' );
		add_filter( 'got_rewrite', '__return_true' );

		if ( file_exists( $this->htaccess_path ) ) {
			wp_delete_file( $this->htaccess_path );
		}

		$output = $this->capture_notices();

		$this->assertStringNotContainsString( self::NOTICE_HEADING, $output );
	}

	/**
	 * mod_rewrite の無いサーバー（nginx 等、got_mod_rewrite() が false）では
	 * .htaccess で判定できないため、通知を表示しないことを確認する。
	 */
	public function test_notice_hidden_when_server_has_no_mod_rewrite(): void {
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'got_rewrite', '__return_false' );

		if ( file_exists( $this->htaccess_path ) ) {
			wp_delete_file( $this->htaccess_path );
		}

		$output = $this->capture_notices();

		$this->assertStringNotContainsString( self::NOTICE_HEADING, $output );
	}

	/**
	 * マルチサイトでは .htaccess がネットワーク全体で共有され、
	 * get_home_path() の前提（1サイト1ドキュメントルート）も成り立たないため、
	 * 他の条件（mod_rewrite パーマリンク・.htaccess 未反映）を満たしていても
	 * 通知を表示しないことを確認する（安藤レビュー指摘 MEDIUM-2）。
	 *
	 * マルチサイトのテスト実行環境でなければ意味のある検証ができないため、
	 * その場合はスキップする。
	 */
	public function test_notice_hidden_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'マルチサイト環境（WP_TESTS_MULTISITE=1）でのみ実行できるテストのためスキップします。' );
		}

		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'got_rewrite', '__return_true' );

		if ( file_exists( $this->htaccess_path ) ) {
			wp_delete_file( $this->htaccess_path );
		}

		$output = $this->capture_notices();

		$this->assertStringNotContainsString( self::NOTICE_HEADING, $output );
	}

	/**
	 * Setup_Notices::render_notices() の出力を取得する。
	 *
	 * @return string 出力内容.
	 */
	private function capture_notices(): string {
		ob_start();
		( new Setup_Notices() )->render_notices();
		return (string) ob_get_clean();
	}
}
