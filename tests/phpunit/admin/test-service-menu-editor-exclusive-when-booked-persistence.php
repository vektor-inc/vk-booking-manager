<?php
/**
 * 予約枠の定員機能OFF時に、貸し切り予約メタ（_vkbm_exclusive_when_booked）が保持されることを検証するテスト（#330 懸念2）。
 *
 * 予約枠の定員機能（親スイッチ）がOFFのとき、サービスメニュー編集フォームには
 * 予約枠の定員・複数人一括予約・料金区分・貸し切り予約・ユーザー貸し切り指定のフィールドが描画されない。
 * このためこれらの従属メタは「非表示時は保持」方針で残す（親スイッチを再有効化したとき元の設定で復帰できる）。
 *
 * 貸し切り予約メタだけがこの分岐で誤って削除されていたため、他の従属メタと同じく保持されることを保証する。
 * 判定側（is_menu_exclusive_when_booked 等）がフルゲートを再適用するため、保持してもOFF中は無害。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function get_post_meta;
use function metadata_exists;
use function update_option;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * 予約枠の定員機能OFF時の貸し切り予約メタ保持テスト（#330 懸念2）。
 *
 * @group admin
 * @group exclusive
 */
class Service_Menu_Editor_Exclusive_When_Booked_Persistence_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/** @var mixed setUp で退避する全体設定（tearDown で復元）。 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 貸し切り予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '貸し切り予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・静的キャッシュ・ログイン状態・$_POST を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * 指名機能・予約枠の定員機能の有効/無効を全体設定へ反映する。
	 *
	 * @param bool $nomination     指名機能を有効にする場合は true。
	 * @param bool $slot_capacity  予約枠の定員機能を有効にする場合は true。
	 */
	private function set_settings( bool $nomination, bool $slot_capacity ): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = $nomination;
		$settings['slot_capacity_enabled'] = $slot_capacity;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * save_post: 予約枠の定員機能OFF分岐で、非表示の従属メタ（貸し切り予約・ユーザー貸し切り指定・料金区分・複数人一括予約・予約枠の定員）が保持される。
	 *
	 * - 予約枠の定員機能ON時に一通り設定を保存 → 予約枠の定員機能OFFで再保存 → 従属メタが全て残ること。
	 *   特に貸し切り予約メタ（_vkbm_exclusive_when_booked）が削除されず保持されること（#330 懸念2の主眼）。
	 * - 対照として、ユーザー貸し切り指定・料金区分・予約枠の定員・複数人一括予約も同じく保持されること。
	 */
	public function test_save_post(): void {
		// Pro版・指名OFF・予約枠の定員ON でメニューを作成し、従属設定を一通り保存する。
		$this->set_settings( false, true );

		$admin   = new Service_Menu_Editor();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$post = get_post( $menu_id );

		// 予約枠の定員機能ON状態で、複数人一括予約と従属設定（貸切・ユーザー貸切・料金区分）を保存する。
		$_POST = array(
			self::NONCE_NAME       => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu'    => array(
				'max_capacity'                => '4',
				'min_capacity'                => '2',
				'allow_multiple_guests'       => '1',
				'exclusive_when_booked'       => '1',
				'exclusive_user_selectable'   => '1',
				'exclusive_fee_per_person'    => '1000',
				'exclusive_fee_exempt_guests' => '1',
				// 料金区分フォームは label[]・price[] の並列配列で送られる（sanitize_price_tiers の期待形式）。
				'price_tiers'                 => array(
					'label' => array( '大人' ),
					'price' => array( '5000' ),
				),
			),
		);
		$admin->save_post( $menu_id, $post );

		// 前提が正しく保存されていることを確認する（この段階で貸切メタが立っている）。
		$this->assertTrue( metadata_exists( 'post', $menu_id, '_vkbm_exclusive_when_booked' ), '前提：予約枠の定員ON時に貸し切り予約メタが保存されている' );

		// 予約枠の定員機能（親スイッチ）をOFFにして再保存する。
		// このときフォームには従属フィールドが描画されないため、$_POST には従属設定を含めない。
		$this->set_settings( false, false );
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => array(
				'base_price' => '3000',
			),
		);
		$admin->save_post( $menu_id, $post );

		// 予約枠の定員機能OFF時、従属メタは「非表示時は保持」方針で残る（再有効化で復帰できる後方互換）。
		$test_cases = array(
			array(
				'test_condition_name' => '予約枠の定員機能OFF再保存後 => 貸し切り予約メタが保持される（#330 懸念2）',
				'meta_key'            => '_vkbm_exclusive_when_booked',
				'expect_exists'       => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF再保存後 => ユーザー貸し切り指定メタが保持される（兄弟メタと揃う）',
				'meta_key'            => '_vkbm_exclusive_user_selectable',
				'expect_exists'       => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF再保存後 => 貸し切り料金（1人あたり）メタが保持される',
				'meta_key'            => '_vkbm_exclusive_fee_per_person',
				'expect_exists'       => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF再保存後 => 料金区分メタが保持される',
				'meta_key'            => '_vkbm_price_tiers',
				'expect_exists'       => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF再保存後 => 複数人一括予約許可メタが保持される',
				'meta_key'            => '_vkbm_allow_multiple_guests',
				'expect_exists'       => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF再保存後 => 予約枠の定員メタが保持される',
				'meta_key'            => '_vkbm_max_capacity',
				'expect_exists'       => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = metadata_exists( 'post', $menu_id, $case['meta_key'] );
			$this->assertSame( $case['expect_exists'], $actual, $case['test_condition_name'] );
		}
	}
}
