<?php
/**
 * 最少催行人数／最低申し込み人数フィールドのラベル・表示条件テスト（#393）。
 *
 * サービスメニュー編集画面の「予約枠の定員」節にある最少催行人数フィールドは、
 * このメニューで指名を使うかどうかでラベル・説明文が切り替わる。
 * - 指名を使わないメニュー：「最少催行人数」（催行状態の表示用しきい値、従来のまま）
 * - 指名を使うメニュー：「最低申し込み人数」（1組の最低人数の受付制限、#393で新設）
 *
 * 表示条件（「複数人一括予約 ON かつ 予約枠の定員2以上」）は指名の有無で変わらないことも検証する。
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
use function get_post;
use function update_option;
use function update_post_meta;
use function wp_set_current_user;

/**
 * 最少催行人数／最低申し込み人数フィールドのラベル・表示条件テスト（#393）。
 *
 * @group admin
 * @group nomination
 */
class Service_Menu_Editor_Nomination_Min_Guests_Label_Test extends WP_UnitTestCase {

	/**
	 * setUp で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。Pro 版限定機能のため無料版ではスキップする。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、復元に使う元値の退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		// 予約枠の定員・複数人一括予約は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより
		// 検証対象の挙動が無効になる。無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '予約枠の定員・複数人一括予約は Pro 版限定機能のため、無料版ではスキップする。' );
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
	 * サイト全体の指名機能・予約枠の定員機能スイッチを更新する。
	 *
	 * @param bool $nomination    サイト全体の指名機能を有効にする場合は true。
	 * @param bool $slot_capacity 予約枠の定員機能を有効にする場合は true。
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
	 * render_conditions_meta_box の出力で、最少催行人数／最低申し込み人数フィールドの
	 * ラベル・表示（hidden 属性）が条件どおりに切り替わることを検証する。
	 */
	public function test_render_min_capacity_field(): void {
		$test_cases = array(
			array(
				'test_condition_name'   => '指名ON・複数人一括予約ON・定員3 => ラベル「最低申し込み人数」・表示される（正常系）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 3,
				'expect_hidden'         => false,
				'expect_label'          => 'Minimum guests to accept a booking',
				'unexpect_label'        => 'Minimum participants to confirm',
			),
			array(
				'test_condition_name'   => '指名OFF・複数人一括予約ON・定員3 => ラベル「最少催行人数」・表示される（正常系：従来どおり）',
				'nomination_enabled'    => false,
				'allow_multiple_guests' => true,
				'max_capacity'          => 3,
				'expect_hidden'         => false,
				'expect_label'          => 'Minimum participants to confirm',
				'unexpect_label'        => 'Minimum guests to accept a booking',
			),
			array(
				'test_condition_name'   => '指名ON・複数人一括予約OFF・定員3 => 非表示（境界値：指名の有無に関わらず複数人一括予約ONが必須）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => false,
				'max_capacity'          => 3,
				'expect_hidden'         => true,
				'expect_label'          => null,
				'unexpect_label'        => null,
			),
			array(
				'test_condition_name'   => '指名ON・複数人一括予約ON・定員1 => 非表示（境界値：指名の有無に関わらず定員2以上が必須）',
				'nomination_enabled'    => true,
				'allow_multiple_guests' => true,
				'max_capacity'          => 1,
				'expect_hidden'         => true,
				'expect_label'          => null,
				'unexpect_label'        => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_settings( $case['nomination_enabled'], true );

			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );
			if ( $case['allow_multiple_guests'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			}

			$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $admin_id );

			$post   = get_post( $menu_id );
			$editor = new Service_Menu_Editor();

			ob_start();
			$editor->render_conditions_meta_box( $post );
			$output = (string) ob_get_clean();

			// <tr id="vkbm-min-capacity-field" ...> の hidden 属性の有無を確認する。
			$matched = (bool) preg_match( '/<tr id="vkbm-min-capacity-field"([^>]*)>/', $output, $matches );
			$this->assertTrue( $matched, $case['test_condition_name'] . ': 対象の <tr> が出力に含まれていない' );
			$is_hidden = isset( $matches[1] ) && false !== strpos( $matches[1], 'hidden' );
			$this->assertSame(
				$case['expect_hidden'],
				$is_hidden,
				$case['test_condition_name'] . ': hidden 属性の有無'
			);

			if ( null !== $case['expect_label'] ) {
				$this->assertStringContainsString(
					$case['expect_label'],
					$output,
					$case['test_condition_name'] . ': 期待するラベルが出力に含まれていない'
				);
			}
			if ( null !== $case['unexpect_label'] ) {
				$this->assertStringNotContainsString(
					$case['unexpect_label'],
					$output,
					$case['test_condition_name'] . ': 出るべきでないラベルが出力に含まれている'
				);
			}

			wp_set_current_user( 0 );
			Staff_Editor::clear_nomination_enabled_cache();
		}
	}
}
