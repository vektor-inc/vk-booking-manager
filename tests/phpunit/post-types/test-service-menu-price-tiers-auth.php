<?php
/**
 * _vkbm_price_tiers メタの auth_callback テスト。
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
 * 料金区分メタ（_vkbm_price_tiers）の REST 書き込み認可（auth_callback）のテスト。
 *
 * @group post-types
 */
class Service_Menu_Price_Tiers_Auth_Test extends WP_UnitTestCase {

	/**
	 * @var mixed
	 */
	private $original_settings = false;

	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 料金区分は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金区分は Pro 版限定機能のため、無料版ではスキップする。' );
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
	 * 指名機能（staff_enabled）の有効・無効を設定する。
	 *
	 * @param bool $enabled 有効にする場合は true。
	 */
	private function set_nomination_enabled( bool $enabled ): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 登録済みメタ _vkbm_price_tiers の auth_callback を取得する。
	 *
	 * テスト環境ではメタ登録のタイミングに依存しないよう、register_meta() を明示的に呼んでから取得する。
	 *
	 * @return callable|null
	 */
	private function get_price_tiers_auth_callback(): ?callable {
		// メタ登録を明示的に実行して確実に登録された状態にする（多重登録は WP 側で冪等）。
		( new Service_Menu_Post_Type() )->register_meta();

		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );
		$meta       = $registered['_vkbm_price_tiers'] ?? null;
		if ( ! is_array( $meta ) || ! isset( $meta['auth_callback'] ) || ! is_callable( $meta['auth_callback'] ) ) {
			return null;
		}
		return $meta['auth_callback'];
	}

	/**
	 * auth_callback: 複数人予約ON＋料金区分を同一REST更新する際、保存済み allow フラグに依存せず認可する。
	 *
	 * stale read 依存（保存済み _vkbm_allow_multiple_guests を読む）を撤去したため、
	 * allow フラグがまだ保存されていない（=false 相当）状態でも、編集権限＋Pro＋指名OFF なら許可される。
	 */
	public function test_auth_callback_does_not_depend_on_stored_allow_flag(): void {
		$auth_callback = $this->get_price_tiers_auth_callback();
		$this->assertNotNull( $auth_callback, '_vkbm_price_tiers の auth_callback が登録されている' );

		$this->set_nomination_enabled( false );

		// 編集権限を持つ管理者でログインする。
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		// 複数人予約フラグは未保存（同一REST更新で後から書かれる想定）。stale=false でも拒否されないこと。
		$this->assertEmpty(
			get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true ),
			'前提: allow フラグは未保存'
		);

		$test_cases = array(
			array(
				'test_condition_name' => '編集権限＋Pro＋指名OFF・allow未保存 => 許可（race回避：stale非依存）',
				'nomination'          => false,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '指名ON => 拒否（複数人予約が成立しないため）',
				'nomination'          => true,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_nomination_enabled( $case['nomination'] );
			$actual = (bool) call_user_func( $auth_callback, false, '_vkbm_price_tiers', $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
