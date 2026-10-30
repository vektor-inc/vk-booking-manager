<?php
/**
 * 顧客フラグ設定ページの「ゲストに付けたフラグ」の一覧と解除のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Customers;

use VKBookingManager\Customers\Customer_Flag_Service;
use VKBookingManager\Customers\Customer_Flag_Settings_Page;
use WP_UnitTestCase;

/**
 * ゲストに付けたフラグの一覧の表示・絞り込み・解除（nonce・権限）を検証する。
 *
 * @group customer-flags
 */
class Customer_Flag_Settings_Page_Guest_Test extends WP_UnitTestCase {
	use Customer_Flag_Options_Cleanup_Trait;

	/**
	 * フラグサービス。
	 *
	 * @var Customer_Flag_Service
	 */
	private $service;

	/**
	 * テスト対象。
	 *
	 * @var Customer_Flag_Settings_Page
	 */
	private $page;

	/**
	 * テスト前の $_POST と $_GET（復元用）。
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $request_backup = array();

	/**
	 * 各テストの前に、種別とゲストのフラグを用意する。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_all_flag_options();
		$this->request_backup = array(
			'post' => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テスト後に復元するための退避。
			'get'  => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- テスト後に復元するための退避。
		);

		$this->service = new Customer_Flag_Service();
		$this->page    = new Customer_Flag_Settings_Page( $this->service );
		$this->service->save_types(
			array(
				array(
					'id'             => 'caution',
					'label'          => '要注意',
					'blocks_booking' => false,
					'block_message'  => '',
				),
				array(
					'id'             => 'blacklist',
					'label'          => 'ブラックリスト',
					'blocks_booking' => true,
					'block_message'  => '',
				),
			)
		);
		$this->service->set_guest_flag( 'taro@example.com', '09011112222', 'blacklist', '無断キャンセル' );
		$this->service->set_guest_flag( 'hanako@example.org', '', 'caution', '遅刻' );
	}

	/**
	 * 各テストの後に、リクエストの値・ユーザー・データを元に戻す。
	 */
	public function tear_down(): void {
		$_POST = $this->request_backup['post'];
		$_GET  = $this->request_backup['get'];
		$_REQUEST = array();
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_redirect' );
		$this->delete_all_flag_options();

		parent::tear_down();
	}

	/**
	 * 一覧に連絡先・メモが表示され、種別での絞り込みとメール／電話の検索ができることを検証する。
	 */
	public function test_render_page(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '条件なしなら全件のメール・電話・メモを表示する（正常系）',
				'conditions'          => array( 'get' => array() ),
				'expected'            => array(
					'contains'     => array( 'taro@example.com', '09011112222', '無断キャンセル', 'hanako@example.org', '遅刻' ),
					'not_contains' => array(),
				),
			),
			array(
				'test_condition_name' => '種別で絞り込むとその種別だけ表示する（正常系）',
				'conditions'          => array( 'get' => array( 'guest_type' => 'caution' ) ),
				'expected'            => array(
					'contains'     => array( 'hanako@example.org' ),
					'not_contains' => array( 'taro@example.com' ),
				),
			),
			array(
				'test_condition_name' => '電話の一部で検索できる（正常系）',
				'conditions'          => array( 'get' => array( 'guest_q' => '0901111' ) ),
				'expected'            => array(
					'contains'     => array( 'taro@example.com' ),
					'not_contains' => array( 'hanako@example.org' ),
				),
			),
			array(
				'test_condition_name' => '一致しない検索語では該当なしを表示する（異常系）',
				'conditions'          => array( 'get' => array( 'guest_q' => 'nobody' ) ),
				'expected'            => array(
					'contains'     => array( 'No flags match the conditions.' ),
					'not_contains' => array( 'taro@example.com', 'hanako@example.org' ),
				),
			),
		);

		wp_set_current_user( $this->create_manager() );

		foreach ( $test_cases as $case ) {
			$_GET = $case['conditions']['get'];

			ob_start();
			$this->page->render_page();
			$output = (string) ob_get_clean();

			foreach ( $case['expected']['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . ' / ' . $needle );
			}
			foreach ( $case['expected']['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] . ' / ' . $needle );
			}
		}
	}

	/**
	 * 解除が、nonce と権限を満たすときだけ記録を消し、元の画面へ戻すことを検証する。
	 */
	public function test_handle_guest_flag_removal(): void {
		$records   = $this->service->get_guest_records();
		$target_id = $records[0]['id'];

		$test_cases = array(
			array(
				'test_condition_name' => '権限が無いユーザーでは消えない（異常系）',
				'conditions'          => array(
					'manager' => false,
					'nonce'   => true,
				),
				'expected'            => array(
					'count'    => 2,
					'redirect' => false,
				),
			),
			array(
				'test_condition_name' => 'nonce が不正なら消えない（異常系）',
				'conditions'          => array(
					'manager' => true,
					'nonce'   => false,
				),
				'expected'            => array(
					'count'    => 2,
					'redirect' => false,
				),
			),
			array(
				'test_condition_name' => '権限と nonce が正しければ指定の 1 件だけ消え、一覧へ戻る（正常系）',
				'conditions'          => array(
					'manager' => true,
					'nonce'   => true,
				),
				'expected'            => array(
					'count'    => 1,
					'redirect' => true,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['conditions']['manager'] ? $this->create_manager() : self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$_POST = array(
				'_vkbm_guest_flag_remove_nonce' => $case['conditions']['nonce'] ? wp_create_nonce( 'vkbm_guest_flag_remove' ) : 'invalid',
				'vkbm_guest_flag_id'            => $target_id,
				'guest_type'                    => 'blacklist',
				'guest_q'                       => 'taro',
			);
			// check_admin_referer() は $_REQUEST を読む。
			$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- テストでリクエストを再現するため。

			$redirected_to = '';
			add_filter(
				'wp_redirect',
				static function ( $location ) use ( &$redirected_to ) {
					$redirected_to = (string) $location;
					throw new \Exception( 'redirect' );
				}
			);

			try {
				$this->page->handle_guest_flag_removal();
			} catch ( \Throwable $e ) {
				// 不正な nonce は wp_die、成功時はリダイレクトで処理が止まる。
				unset( $e );
			}
			remove_all_filters( 'wp_redirect' );

			$this->assertCount( $case['expected']['count'], $this->service->get_guest_records(), $case['test_condition_name'] . ' / 件数' );
			$this->assertSame( $case['expected']['redirect'], '' !== $redirected_to, $case['test_condition_name'] . ' / リダイレクト' );
			if ( $case['expected']['redirect'] ) {
				$this->assertStringContainsString( 'guest_removed=1', $redirected_to, $case['test_condition_name'] . ' / 完了表示' );
				$this->assertStringContainsString( 'guest_type=blacklist', $redirected_to, $case['test_condition_name'] . ' / 絞り込みを戻す' );
				$this->assertStringContainsString( '#vkbm-guest-flags', $redirected_to, $case['test_condition_name'] . ' / 一覧の位置へ戻す' );
			}
		}
	}

	/**
	 * 一覧への導線が、設定ページを開ける権限のあるユーザーにだけ出ることを検証する。
	 */
	public function test_render_guest_list_link(): void {
		wp_set_current_user( $this->create_manager() );
		ob_start();
		Customer_Flag_Settings_Page::render_guest_list_link( '一覧を見る' );
		$with_cap = (string) ob_get_clean();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Customer_Flag_Settings_Page::render_guest_list_link( '一覧を見る' );
		$without_cap = (string) ob_get_clean();

		$this->assertStringContainsString( '#vkbm-guest-flags', $with_cap, '権限があればリンクを出す' );
		$this->assertSame( '', $without_cap, '権限が無ければ何も出さない' );
	}

	/**
	 * 設定ページを開ける権限を持つユーザーを作る。
	 *
	 * @return int ユーザー ID。
	 */
	private function create_manager(): int {
		$user_id = (int) $this->factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user_id )->add_cap( \VKBookingManager\Capabilities\Capabilities::MANAGE_PROVIDER_SETTINGS );

		return $user_id;
	}
}
