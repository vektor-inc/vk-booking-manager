<?php
/**
 * _vkbm_disable_nomination メタの auth_callback テスト（#391）。
 *
 * メニュー単位で指名機能を無効化するメタの REST 書き込み認可（auth_callback）が、
 * 編集権限に加え「Pro版 かつ サイト全体の指名機能ON」の業務ゲートに従うことを検証する。
 * このメタ自体がメニュー単位の指名可否を決めるため、判定にメニュー単位の関数
 * （is_nomination_enabled_for_menu）を使わず、サイト全体の設定のみをゲートに使う設計になっている
 * （自己参照を避けるため）。詳細は Service_Menu_Post_Type::register_meta() 内のコメントを参照。
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
 * メニュー単位の指名機能無効化メタ（_vkbm_disable_nomination）の auth_callback のテスト。
 *
 * @group post-types
 * @group nomination
 */
class Service_Menu_Disable_Nomination_Auth_Test extends WP_UnitTestCase {

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

		// メニュー単位の指名機能設定は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより
		// 検証対象の挙動が無効になる。無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニュー単位の指名機能設定は Pro 版限定機能のため、無料版ではスキップする。' );
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
	 * サイト全体の指名機能スイッチを更新する。
	 *
	 * @param bool $enabled 指名機能を有効にする場合は true。
	 */
	private function set_site_wide_nomination( bool $enabled ): void {
		$repository                = new Settings_Repository();
		$settings                  = $repository->get_settings();
		$settings['staff_enabled'] = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 登録済みメタ _vkbm_disable_nomination の auth_callback を取得する。
	 *
	 * @return callable|null
	 */
	private function get_auth_callback(): ?callable {
		// メタ登録を明示的に実行して確実に登録された状態にする（多重登録は WP 側で冪等）。
		( new Service_Menu_Post_Type() )->register_meta();

		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );
		$meta       = $registered['_vkbm_disable_nomination'] ?? null;
		if ( ! is_array( $meta ) || ! isset( $meta['auth_callback'] ) || ! is_callable( $meta['auth_callback'] ) ) {
			return null;
		}
		return $meta['auth_callback'];
	}

	/**
	 * auth_callback が「編集権限 ＋ Pro版 ＋ サイト全体の指名機能ON」のゲートに従うことを検証する。
	 */
	public function test_auth_callback(): void {
		$auth_callback = $this->get_auth_callback();
		$this->assertNotNull( $auth_callback, '_vkbm_disable_nomination の auth_callback が登録されている' );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => '編集権限（administrator）＋サイト全体の指名機能ON => 許可（正常系：メニュー単位トグルの前提が揃っている）',
				'role'                => 'administrator',
				'site_wide'           => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '編集権限（administrator）＋サイト全体の指名機能OFF => 拒否（正常系：サイト全体OFFではメニュー単位の設定自体に意味が無い）',
				'role'                => 'administrator',
				'site_wide'           => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '未ログイン（編集権限なし）＋サイト全体の指名機能ON => 拒否（境界値：権限チェック）',
				'role'                => null,
				'site_wide'           => true,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ログイン済みだが編集権限を持たないロール（subscriber）＋サイト全体の指名機能ON => 拒否（#412 A-8：境界値・権限チェックの網羅）',
				'role'                => 'subscriber',
				'site_wide'           => true,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( null !== $case['role'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => $case['role'] ) );
				wp_set_current_user( $user_id );
			} else {
				wp_set_current_user( 0 );
			}

			$this->set_site_wide_nomination( $case['site_wide'] );

			$actual = (bool) call_user_func( $auth_callback, false, '_vkbm_disable_nomination', $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
