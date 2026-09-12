<?php
/**
 * _vkbm_allow_multiple_guests メタの auth_callback テスト（#392）。
 *
 * #392 で、このメタの auth_callback から「このメニューで指名機能を使っていないこと」の条件を
 * 除去した（指名を使うメニューでも複数人一括予約を許可できるようにするため）。
 * 予約枠の定員（_vkbm_max_capacity）が既にこの条件を持たなかったことと対称になる。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * 複数人一括予約許可メタ（_vkbm_allow_multiple_guests）の REST 書き込み認可（auth_callback）のテスト。
 *
 * @group post-types
 * @group nomination
 */
class Service_Menu_Allow_Multiple_Guests_Auth_Test extends WP_UnitTestCase {

	/**
	 * @var mixed
	 */
	private $original_settings = false;

	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 複数人一括予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人一括予約は Pro 版限定機能のため、無料版ではスキップする。' );
		}
	}

	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 登録済みメタ _vkbm_allow_multiple_guests の auth_callback を取得する。
	 *
	 * @return callable|null
	 */
	private function get_allow_multiple_guests_auth_callback(): ?callable {
		// メタ登録を明示的に実行して確実に登録された状態にする（多重登録は WP 側で冪等）。
		( new Service_Menu_Post_Type() )->register_meta();

		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );
		$meta       = $registered['_vkbm_allow_multiple_guests'] ?? null;
		if ( ! is_array( $meta ) || ! isset( $meta['auth_callback'] ) || ! is_callable( $meta['auth_callback'] ) ) {
			return null;
		}
		return $meta['auth_callback'];
	}

	/**
	 * auth_callback が、指名機能の有効/無効・予約枠の定員機能の有効/無効の組み合わせに
	 * 応じて期待どおり認可・拒否することを検証する。
	 */
	public function test_auth_callback(): void {
		$auth_callback = $this->get_allow_multiple_guests_auth_callback();
		$this->assertNotNull( $auth_callback, '_vkbm_allow_multiple_guests の auth_callback が登録されている' );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '編集権限＋Pro＋予約枠の定員機能ON・指名OFF => 許可（正常系：従来どおり）',
				'slot_capacity'       => true,
				'nomination'          => false,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '編集権限＋Pro＋予約枠の定員機能ON・指名ON => 許可（#392：指名を使うメニューでも複数人一括予約を利用できるようにするため、指名OFF条件を除去した）',
				'slot_capacity'       => true,
				'nomination'          => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '編集権限＋Pro＋予約枠の定員機能OFF => 拒否（境界値：親スイッチが最優先）',
				'slot_capacity'       => false,
				'nomination'          => false,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$repository                        = new Settings_Repository();
			$settings                          = $repository->get_settings();
			$settings['slot_capacity_enabled'] = $case['slot_capacity'];
			$settings['staff_enabled']          = $case['nomination'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			$actual = (bool) call_user_func( $auth_callback, false, '_vkbm_allow_multiple_guests', $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ログインしていない（編集権限が無い）場合は、他の条件を満たしていても拒否されることを検証する。
	 */
	public function test_auth_callback_requires_edit_permission(): void {
		$auth_callback = $this->get_allow_multiple_guests_auth_callback();
		$this->assertNotNull( $auth_callback, '_vkbm_allow_multiple_guests の auth_callback が登録されている' );

		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['slot_capacity_enabled'] = true;
		$settings['staff_enabled']          = false;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		// 未ログイン（wp_set_current_user(0)）のため編集権限が無い。
		wp_set_current_user( 0 );

		$actual = (bool) call_user_func( $auth_callback, false, '_vkbm_allow_multiple_guests', $menu_id );
		$this->assertFalse( $actual, '編集権限が無い場合は他の条件を満たしていても拒否される' );
	}
}
