<?php
/**
 * Staff_Editor::is_multi_guest_available_for_menu() / is_exclusive_booking_available_for_menu() のテスト（#392）。
 *
 * 指名を使うメニューを「1枠1組（貸切）」として扱う仕様変更に伴い、
 * - 予約枠の定員・複数人一括予約・料金区分のゲート（is_multi_guest_available_for_menu）から
 *   「このメニューで指名機能を使っていないこと」を外した
 * - 貸し切り予約・予約者による貸切指定・貸切料金のゲート（is_exclusive_booking_available_for_menu）は
 *   引き続き「指名OFF」を要求する（新設メソッド）
 * という2つのメソッドに分岐したことを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Staff;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;

/**
 * Staff_Editor の複数人一括予約系・貸切系ゲート判定のテストクラス。
 *
 * @group staff
 * @group nomination
 * @group capacity
 */
class Staff_Editor_Multi_Guest_And_Exclusive_Booking_Gates_Test extends WP_UnitTestCase {

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

		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
	}

	/**
	 * テスト後に全体設定・静的キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * サービスメニュー投稿を作成する。
	 *
	 * @param bool|null $disable_nomination メニュー単位の指名無効化メタ（null は未設定のまま）。
	 * @return int 作成したメニューの投稿ID。
	 */
	private function create_menu( ?bool $disable_nomination = null ): int {
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		if ( null !== $disable_nomination ) {
			update_post_meta( $menu_id, '_vkbm_disable_nomination', $disable_nomination );
		}

		return $menu_id;
	}

	/**
	 * is_multi_guest_available_for_menu() が「予約枠の定員機能ON」のみで判定され、
	 * このメニューの指名可否には左右されないことを検証する（#392）。
	 *
	 * Pro版であることが前提のため、無料版ビルドでは常に false になりスキップする。
	 */
	public function test_is_multi_guest_available_for_menu(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'is_multi_guest_available_for_menu() は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '予約枠の定員機能ON・指名OFF => true（正常系：従来どおり利用可能）',
				'slot_capacity'       => true,
				'staff_enabled'       => false,
				'disable_nomination'  => null,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能ON・指名ON（メニュー単位設定なし＝既定で使う） => true（#392：指名条件を外したため利用可能）',
				'slot_capacity'       => true,
				'staff_enabled'       => true,
				'disable_nomination'  => null,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能ON・指名ON・メニュー単位も明示的に「使う」(false) => true（#392）',
				'slot_capacity'       => true,
				'staff_enabled'       => true,
				'disable_nomination'  => false,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF・指名OFF => false（境界値：親スイッチが最優先）',
				'slot_capacity'       => false,
				'staff_enabled'       => false,
				'disable_nomination'  => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$repository                        = new Settings_Repository();
			$settings                          = $repository->get_settings();
			$settings['slot_capacity_enabled'] = $case['slot_capacity'];
			$settings['staff_enabled']          = $case['staff_enabled'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			$menu_id = $this->create_menu( $case['disable_nomination'] );

			$this->assertSame(
				$case['expected'],
				Staff_Editor::is_multi_guest_available_for_menu( $menu_id ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * is_exclusive_booking_available_for_menu() が、is_multi_guest_available_for_menu() と異なり
	 * 「このメニューで指名機能を使っていないこと」を引き続き要求することを検証する（#392）。
	 *
	 * Pro版であることが前提のため、無料版ビルドでは常に false になりスキップする。
	 */
	public function test_is_exclusive_booking_available_for_menu(): void {
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'is_exclusive_booking_available_for_menu() は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '予約枠の定員機能ON・指名OFF => true（正常系：貸切系設定を利用可能）',
				'slot_capacity'       => true,
				'staff_enabled'       => false,
				'disable_nomination'  => null,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能ON・指名ON（メニュー単位設定なし＝既定で使う） => false（指名を使うメニューは貸切系設定を利用不可）',
				'slot_capacity'       => true,
				'staff_enabled'       => true,
				'disable_nomination'  => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '予約枠の定員機能ON・サイト全体は指名ONだがメニュー単位で指名を無効化 => true（自動割り当てに切替）',
				'slot_capacity'       => true,
				'staff_enabled'       => true,
				'disable_nomination'  => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '予約枠の定員機能OFF・指名OFF => false（境界値：親スイッチが最優先）',
				'slot_capacity'       => false,
				'staff_enabled'       => false,
				'disable_nomination'  => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$repository                        = new Settings_Repository();
			$settings                          = $repository->get_settings();
			$settings['slot_capacity_enabled'] = $case['slot_capacity'];
			$settings['staff_enabled']          = $case['staff_enabled'];
			update_option( Settings_Repository::OPTION_KEY, $settings );
			Staff_Editor::clear_nomination_enabled_cache();

			$menu_id = $this->create_menu( $case['disable_nomination'] );

			$this->assertSame(
				$case['expected'],
				Staff_Editor::is_exclusive_booking_available_for_menu( $menu_id ),
				$case['test_condition_name']
			);
		}
	}
}
