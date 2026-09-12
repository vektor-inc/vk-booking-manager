<?php
/**
 * _vkbm_min_capacity メタの auth_callback テスト。
 *
 * 最小催行人数メタの REST 書き込み認可（auth_callback）が、save_post() と同じ業務ゲート
 * （Pro版 ＋ 予約枠の定員機能ON）に従うことを検証する。ゲート外で REST 経由の
 * 書き込みをバイパスされないことを担保する（#311 CodeRabbit 指摘 #4）。
 *
 * #393（安藤レビュー指摘）: 以前は「指名OFF」もゲートに含めていたが、
 * Service_Menu_Editor::save_post() が #392 で「指名OFF」条件を外したことに
 * auth_callback 側が追従しておらず、指名を使うメニューでは REST 経由の
 * min_capacity 書き込みだけが拒否される不整合があった。save_post() のゲートと
 * 完全に一致させたため、指名ONでも Pro版・予約枠の定員機能ONなら許可する。
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
	 * 指名機能・予約枠の定員機能の有効/無効を全体設定へ反映する。
	 *
	 * #393（安藤レビュー指摘）: 第2引数は実際にはサイト全体の「予約枠の定員機能」スイッチ
	 * （slot_capacity_enabled）を設定するものであり、メニュー単位の複数人一括予約
	 * （_vkbm_allow_multiple_guests）とは別の設定である。この auth_callback の権限判定から
	 * メニュー単位の複数人一括予約の条件は含まれていないため、引数名を実態に合わせる。
	 *
	 * @param bool $nomination           指名機能を有効にする場合は true。
	 * @param bool $slot_capacity_enabled 予約枠の定員機能を有効にする場合は true。
	 */
	private function set_settings( bool $nomination, bool $slot_capacity_enabled ): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['staff_enabled']         = $nomination;
		$settings['slot_capacity_enabled'] = $slot_capacity_enabled;
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
	 * auth_callback が save_post() と同じ業務ゲート（Pro＋予約枠の定員機能ON）に従うことを検証する。
	 *
	 * Pro版テスト環境前提（is_pro_edition=true）。編集権限を持つ管理者でログインした上で、
	 * 指名・複数人予約の各状態でゲートが効くことを確認する。編集権限が無い場合は常に拒否。
	 *
	 * #393: 指名ONでも予約枠の定員機能さえONなら許可される（save_post() と同じゲートに一致）。
	 * 指名を使うメニューでは _vkbm_min_capacity が「最低申し込み人数」（受付制限）として
	 * 実際に保存・適用されるため、REST 経由の書き込みだけを拒否する理由が無い。
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
				'test_condition_name'   => '編集権限＋指名OFF＋予約枠の定員機能ON => 許可（正常系：保存ゲートと一致）',
				'logged_in'             => true,
				'nomination'            => false,
				'slot_capacity_enabled' => true,
				'expected'              => true,
			),
			array(
				'test_condition_name'   => '編集権限＋指名ON＋予約枠の定員機能ON => 許可（#393：指名を使うメニューでも受付制限として保存されるため許可）',
				'logged_in'             => true,
				'nomination'            => true,
				'slot_capacity_enabled' => true,
				'expected'              => true,
			),
			array(
				'test_condition_name'   => '編集権限＋予約枠の定員機能OFF => 拒否（最大受付数1固定で催行判定・受付制限とも無効）',
				'logged_in'             => true,
				'nomination'            => false,
				'slot_capacity_enabled' => false,
				'expected'              => false,
			),
			array(
				'test_condition_name'   => '未ログイン（編集権限なし）＋指名OFF＋予約枠の定員機能ON => 拒否（境界値：権限チェック）',
				'logged_in'             => false,
				'nomination'            => false,
				'slot_capacity_enabled' => true,
				'expected'              => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['logged_in'] ) {
				$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
				wp_set_current_user( $user_id );
			} else {
				wp_set_current_user( 0 );
			}

			$this->set_settings( $case['nomination'], $case['slot_capacity_enabled'] );

			$actual = (bool) call_user_func( $auth_callback, false, '_vkbm_min_capacity', $menu_id );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}
}
