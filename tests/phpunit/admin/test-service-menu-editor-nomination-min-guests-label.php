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
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function get_post;
use function vkbm_get_resource_selection_strings;
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
		Industry_Presets::clear_cache();
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

	/**
	 * 業種プリセットを切り替える（宿泊の機能は業種プリセットが「宿泊」のときだけ有効になる）。
	 *
	 * @param string $preset 業種プリセットのキー（'lodging' / 'custom' など）。
	 */
	private function set_industry_preset( string $preset ): void {
		$repository                  = new Settings_Repository();
		$settings                    = $repository->get_settings();
		$settings['industry_preset'] = $preset;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Industry_Presets::clear_cache();
	}

	/**
	 * 人数の下限・予約枠の定員の欄を含む条件ボックスを描画し、2つの欄の説明文の HTML を返す。
	 *
	 * @param array<string,mixed> $meta メニューに保存する投稿メタ（キー => 値）。
	 * @return array{output:string,min_description:string,max_description:string,min_hidden:bool} 描画結果。
	 */
	private function render_capacity_fields( array $meta ): array {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $menu_id, $key, $value );
		}

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new Service_Menu_Editor() )->render_conditions_meta_box( get_post( $menu_id ) );
		$output = (string) ob_get_clean();

		preg_match( '/<tr id="vkbm-min-capacity-field"([^>]*)>/', $output, $row );
		preg_match( '/<p class="description" id="vkbm-min-capacity-description">(.*?)<\/p>/s', $output, $min );
		preg_match( '/<p class="description" id="vkbm-max-capacity-description">(.*?)<\/p>/s', $output, $max );

		return array(
			'output'          => $output,
			'min_description' => $min[1] ?? '',
			'max_description' => $max[1] ?? '',
			'min_hidden'      => isset( $row[1] ) && false !== strpos( $row[1], 'hidden' ),
		);
	}

	/**
	 * 宿泊の「1名1泊あたり」プランでは、人数の下限の欄が「最低申し込み人数」と新しい説明文になり、
	 * 予約枠の定員の欄の説明文も「1件の予約で一度に申し込める最大人数」になること（#935）。
	 */
	public function test_lodging_per_guest_night_uses_booking_copy(): void {
		$this->set_settings( true, true );
		$this->set_industry_preset( 'lodging' );
		$strings = vkbm_get_resource_selection_strings();

		$result = $this->render_capacity_fields(
			array(
				'_vkbm_booking_unit'          => 'night',
				'_vkbm_night_price_unit'      => 'per_guest',
				'_vkbm_allow_multiple_guests' => true,
				'_vkbm_max_capacity'          => 3,
			)
		);

		$this->assertFalse( $result['min_hidden'], '人数の下限の欄は表示される' );
		$this->assertStringContainsString( 'Minimum guests to accept a booking', $result['output'], 'ラベルは最低申し込み人数' );
		$this->assertStringNotContainsString( 'Minimum participants to confirm', $result['output'], '最少催行人数のラベルは出ない' );

		$min = $result['min_description'];
		$this->assertStringContainsString( 'Each booking is for one group using one room.', $min );
		$this->assertStringContainsString( 'If the number of guests is below this value, the reservation cannot be accepted.', $min );
		$this->assertStringContainsString( 'Enter 0 to remove the minimum and accept bookings from 1 guest.', $min );
		$this->assertStringContainsString( 'Values above the time slot capacity are reduced to that capacity.', $min );
		$this->assertStringNotContainsString( 'the maximum guests per group', $min, '指名前提の4行目は出さない' );
		$this->assertStringNotContainsString( $strings['min_capacity_nomination_1'], $min, '指名前提の1行目は出さない' );
		$this->assertStringNotContainsString( 'participants', $min, '催行の語は出さない' );

		$max = $result['max_description'];
		$this->assertStringContainsString( 'Maximum number of guests that can be booked together in a single reservation.', $max );
		$this->assertStringContainsString( 'Default is 1.', $max );
		$this->assertStringNotContainsString( $strings['max_capacity_nomination_1'], $max, '指名前提の説明は出さない' );
		$this->assertStringNotContainsString( $strings['max_capacity_nomination_2'], $max, '指名前提の説明は出さない' );
		$this->assertStringNotContainsString( $strings['max_capacity_default_1'], $max, '時間枠を共有する前提の説明は出さない' );
	}

	/**
	 * 宿泊の「1室1泊あたり」プランでは、人数の下限の欄は出ないままで、予約枠の定員の欄は1室の最大人数の説明のままであること（#935）。
	 */
	public function test_lodging_per_room_night_keeps_hidden_min_field_and_room_copy(): void {
		$this->set_settings( true, true );
		$this->set_industry_preset( 'lodging' );

		$result = $this->render_capacity_fields(
			array(
				'_vkbm_booking_unit'     => 'night',
				'_vkbm_night_price_unit' => 'per_room',
				'_vkbm_max_capacity'     => 3,
				'_vkbm_min_capacity'     => 2,
			)
		);

		$this->assertTrue( $result['min_hidden'], '人数の下限の欄は隠れたまま' );
		$this->assertStringContainsString( 'Enter the maximum number of guests who can stay in this room.', $result['max_description'] );
		$this->assertStringNotContainsString( 'Maximum number of guests that can be booked together in a single reservation.', $result['max_description'] );
		$this->assertStringNotContainsString( 'Each booking is for one group using one room.', $result['output'] );
	}

	/**
	 * 宿泊の「1名1泊あたり」プランでも、メニューの指名を使わない設定のときは、
	 * 人数の下限の欄は従来の「最少催行人数」の文言のままで、1組1室の説明文は出ないこと（#935）。
	 *
	 * サイト全体の指名 OFF は、宿泊の業種プリセットが指名を常に有効にするため、宿泊のサイトでは作れない。
	 */
	public function test_lodging_per_guest_night_with_menu_nomination_disabled_keeps_default_copy(): void {
		$this->set_settings( true, true );
		$this->set_industry_preset( 'lodging' );

		$result = $this->render_capacity_fields(
			array(
				'_vkbm_booking_unit'          => 'night',
				'_vkbm_night_price_unit'      => 'per_guest',
				'_vkbm_allow_multiple_guests' => true,
				'_vkbm_max_capacity'          => 3,
				'_vkbm_disable_nomination'    => 1,
			)
		);

		$this->assertFalse( $result['min_hidden'], '人数の下限の欄は表示される' );
		$this->assertStringContainsString( 'Minimum participants to confirm', $result['output'], 'ラベルは最少催行人数' );
		$this->assertStringNotContainsString( 'Minimum guests to accept a booking', $result['output'], '最低申し込み人数のラベルは出ない' );
		$this->assertStringNotContainsString( 'Each booking is for one group using one room.', $result['output'], '1組1室の説明文は出ない' );
		$this->assertStringNotContainsString( 'Maximum number of guests that can be booked together in a single reservation.', $result['max_description'], '新しい予約枠の定員の説明は出ない' );
	}

	/**
	 * 宿泊以外のサイトと、宿泊の時間枠（slot）単位プランでは、従来の指名向けの文言のままで新しい文言は出ないこと（#935）。
	 */
	public function test_non_night_plans_keep_existing_nomination_copy(): void {
		$cases = array(
			'宿泊以外のサイト'     => 'custom',
			'宿泊の時間枠単位のプラン' => 'lodging',
		);

		foreach ( $cases as $name => $preset ) {
			$this->set_settings( true, true );
			$this->set_industry_preset( $preset );
			$strings = vkbm_get_resource_selection_strings();

			$result = $this->render_capacity_fields(
				array(
					'_vkbm_allow_multiple_guests' => true,
					'_vkbm_max_capacity'          => 3,
				)
			);

			$this->assertStringContainsString( 'Minimum guests to accept a booking', $result['output'], $name . ': ラベル' );
			$this->assertStringContainsString( $strings['min_capacity_nomination_1'], $result['min_description'], $name . ': 人数の下限の1行目' );
			$this->assertStringContainsString( 'Values above the time slot capacity (the maximum guests per group) are reduced to that capacity.', $result['min_description'], $name . ': 人数の下限の4行目' );
			$this->assertStringContainsString( $strings['max_capacity_nomination_1'], $result['max_description'], $name . ': 予約枠の定員の説明' );
			$this->assertStringNotContainsString( 'Each booking is for one group using one room.', $result['output'], $name . ': 新しい文言は出ない' );
			$this->assertStringNotContainsString( 'Maximum number of guests that can be booked together in a single reservation.', $result['max_description'], $name . ': 新しい文言は出ない' );
		}
	}
}
