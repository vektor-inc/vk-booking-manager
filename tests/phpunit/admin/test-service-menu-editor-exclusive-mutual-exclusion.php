<?php
/**
 * 「貸し切り予約」と「予約者による貸切指定」を排他にする保存処理のテスト（#388）。
 *
 * 両方ONで保存されると、予約確定時の貸切判定が「メニューの貸し切り予約 OR 予約者の貸切指定」の
 * OR条件になっているため、予約者が貸切を指定してもしなくても枠は貸切扱いになる。にもかかわらず、
 * 貸切を指定した予約者だけ貸し切り料金が加算されるため「払った人が損をする」不整合が起きていた。
 *
 * このテストは、管理画面の保存処理（Service_Menu_Editor::save_post）で「貸し切り予約」ONのとき
 * 「予約者による貸切指定」とその従属メタ（貸し切り料金・適用外人数）が削除されることを検証する
 * （#330 で「複数人一括予約OFF時は従属メタを削除する」としたのと同じ書き方に揃える）。
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
use function metadata_exists;
use function update_option;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * 「貸し切り予約」ONのとき「予約者による貸切指定」を排他で無効化する保存処理のテスト（#388）。
 *
 * @group admin
 * @group exclusive
 */
class Service_Menu_Editor_Exclusive_Mutual_Exclusion_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/** @var mixed setUp で退避する全体設定（tearDown で復元）。 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避し、Pro版・指名OFF・予約枠の定員ONへ揃える。
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

		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = false;
		$settings['slot_capacity_enabled'] = true;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
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
	 * save_post: 「貸し切り予約」ONのとき「予約者による貸切指定」と従属メタ（貸し切り料金・適用外人数）が
	 * 削除される（#388・修正の本体）。対照として「貸し切り予約」OFF時は従来どおり保存されることも確認する。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name'       => '貸し切り予約ON＋予約者による貸切指定ON => 貸切指定・料金系メタが削除される（#388・本修正）',
				'exclusive_when_booked'     => true,
				'exclusive_user_selectable' => true,
				'expect_user_selectable'    => false,
				'expect_fee_per_person'     => false,
				'expect_fee_exempt_guests'  => false,
			),
			array(
				'test_condition_name'       => '貸し切り予約OFF＋予約者による貸切指定ON => 貸切指定・料金系メタは従来どおり保存される（対照・回帰防止）',
				'exclusive_when_booked'     => false,
				'exclusive_user_selectable' => true,
				'expect_user_selectable'    => true,
				'expect_fee_per_person'     => true,
				'expect_fee_exempt_guests'  => true,
			),
			array(
				'test_condition_name'       => '貸し切り予約ON＋予約者による貸切指定OFF => 元々未保存のため何も残らない（境界値）',
				'exclusive_when_booked'     => true,
				'exclusive_user_selectable' => false,
				'expect_user_selectable'    => false,
				'expect_fee_per_person'     => false,
				'expect_fee_exempt_guests'  => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$admin   = new Service_Menu_Editor();
			$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );

			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			$post    = get_post( $menu_id );

			$service_menu = array(
				'max_capacity'          => '4',
				'allow_multiple_guests' => '1',
			);
			if ( $case['exclusive_when_booked'] ) {
				$service_menu['exclusive_when_booked'] = '1';
			}
			if ( $case['exclusive_user_selectable'] ) {
				$service_menu['exclusive_user_selectable']   = '1';
				$service_menu['exclusive_fee_per_person']    = '1000';
				$service_menu['exclusive_fee_exempt_guests'] = '1';
			}

			$_POST = array(
				self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
				'vkbm_service_menu' => $service_menu,
			);
			$admin->save_post( $menu_id, $post );

			$this->assertSame(
				$case['expect_user_selectable'],
				metadata_exists( 'post', $menu_id, '_vkbm_exclusive_user_selectable' ),
				$case['test_condition_name'] . ' / _vkbm_exclusive_user_selectable'
			);
			$this->assertSame(
				$case['expect_fee_per_person'],
				metadata_exists( 'post', $menu_id, '_vkbm_exclusive_fee_per_person' ),
				$case['test_condition_name'] . ' / _vkbm_exclusive_fee_per_person'
			);
			$this->assertSame(
				$case['expect_fee_exempt_guests'],
				metadata_exists( 'post', $menu_id, '_vkbm_exclusive_fee_exempt_guests' ),
				$case['test_condition_name'] . ' / _vkbm_exclusive_fee_exempt_guests'
			);

			wp_set_current_user( 0 );
		}
	}
}
