<?php
/**
 * _vkbm_min_capacity メタの auth_callback テスト。
 *
 * 最小催行人数メタの REST 書き込み認可（auth_callback）が、save_post() と同じ業務ゲート
 * （Pro版 ＋ 指名OFF ＋ 複数人予約ON）に従うことを検証する。ゲート外で REST 経由の
 * 書き込みをバイパスされないことを担保する（#311 CodeRabbit 指摘 #4）。
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
 * 最小催行人数メタ（_vkbm_min_capacity）の auth_callback のテスト。
 *
 * @group post-types
 * @group min-capacity
 */
class Service_Menu_Min_Capacity_Auth_Test extends WP_UnitTestCase {

	/**
	 * テスト前の全体設定（option）を退避する。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 最少催行人数は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '最少催行人数は Pro 版限定機能のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・静的キャッシュ・ログイン状態を元に戻す。
	 */
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
	 * 指名機能・複数人予約機能の有効/無効を全体設定へ反映する。
	 *
	 * @param bool $nomination       指名機能を有効にする場合は true。
	 * @param bool $multiple_guests  複数人予約機能を有効にする場合は true。
	 */
	private function set_settings( bool $nomination, bool $multiple_guests ): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = $nomination;
		$settings['slot_capacity_enabled'] = $multiple_guests;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 登録済みメタ _vkbm_min_capacity の auth_callback を取得する。
	 *
	 * @return callable|null
	 */
	private function get_min_capacity_auth_callback(): ?callable {
		// メタ登録を明示的に実行して確実に登録された状態にする（多重登録は WP 側で冪等）。
		( new Service_Menu_Post_Type() )->register_meta();

		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );
		$meta       = $registered['_vkbm_min_capacity'] ?? null;
		if ( ! is_array( $meta ) || ! isset( $meta['auth_callback'] ) || ! is_callable( $meta['auth_callback'] ) ) {
			return null;
		}
		return $meta['auth_callback'];
	}

	/**
	 * auth_callback が save_post() と同じ業務ゲート（Pro＋指名OFF＋複数人予約ON）に従うことを検証する。
	 *
	 * Pro版テスト環境前提（is_pro_edition=true）。編集権限を持つ管理者でログインした上で、
	 * 指名・複数人予約の各状態でゲートが効くことを確認する。編集権限が無い場合は常に拒否。
	 */
	public function test_auth_callback(): void {
		$auth_callback = $this->get_min_capacity_auth_callback();
		$this->assertNotNull( $auth_callback, '_vkbm_min_capacity の auth_callback が登録されている' );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '編集権限＋指名OFF＋複数人予約ON => 許可（正常系：保存ゲートと一致）',
				'logged_in'           => true,
				'nomination'          => false,
				'multiple_guests'     => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '編集権限＋指名ON => 拒否（1対1予約のため催行判定なし）',
				'logged_in'           => true,
				'nomination'          => true,
				'multiple_guests'     => true,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '編集権限＋複数人予約OFF => 拒否（最大受付数1固定で催行判定なし）',
				'logged_in'           => true,
				'nomination'          => false,
				'multiple_guests'     => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログイン（編集権限なし）＋指名OFF＋複数人予約ON => 拒否（境界値：権限チェック）',
				'logged_in'           => false,
				'nomination'          => false,
				'multiple_guests'     => true,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['logged_in'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
				wp_set_current_user( $user_id );
			} else {
				wp_set_current_user( 0 );
			}

			$this->set_settings( $case['nomination'], $case['multiple_guests'] );

			$actual = (bool) call_user_func( $auth_callback, false, '_vkbm_min_capacity', $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
