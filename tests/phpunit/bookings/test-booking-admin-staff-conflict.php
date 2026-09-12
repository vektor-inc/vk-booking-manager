<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function delete_option;
use function get_user_by;
use function get_option;
use function get_post;
use function get_post_meta;
use function update_post_meta;
use function update_option;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * @group bookings
 */
class Booking_Admin_Staff_Conflict_Test extends WP_UnitTestCase {
	private const META_DATE_START  = '_vkbm_booking_service_start';
	private const META_DATE_END    = '_vkbm_booking_service_end';
	private const META_TOTAL_END   = '_vkbm_booking_total_end';
	private const META_RESOURCE_ID = '_vkbm_booking_resource_id';
	private const META_SERVICE_ID  = '_vkbm_booking_service_id';
	private const META_GUESTS      = '_vkbm_booking_guests';
	private const META_STATUS      = '_vkbm_booking_status';

	/**
	 * テスト開始時の基本設定オプション値（復元用）。未設定時は false。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	protected function setUp(): void {
		parent::setUp();
		$this->set_current_user_with_caps();
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	protected function tearDown(): void {
		$_POST = [];
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	public function test_has_staff_conflict_cases(): void {
		$test_cases = [
			[
				'name'               => 'blocks_conflicting_staff_change',
				'existing_start'     => '2024-01-01 10:00:00',
				'existing_end'       => '2024-01-01 11:00:00',
				'target_start'       => '2024-01-01 10:15:00',
				'target_end'         => '2024-01-01 11:00:00',
				'expected'           => true,
			],
			[
				'name'               => 'allows_non_conflicting_staff_change',
				'existing_start'     => '2024-01-02 10:00:00',
				'existing_end'       => '2024-01-02 11:00:00',
				'target_start'       => '2024-01-02 12:00:00',
				'target_end'         => '2024-01-02 12:30:00',
				'expected'           => false,
			],
		];

		$admin = new Booking_Admin_Test_Double();

		foreach ( $test_cases as $case ) {
			$staff_a = $this->create_staff( 'Staff A ' . $case['name'] );

			$this->create_booking(
				$staff_a,
				$case['existing_start'],
				$case['existing_end'],
				'confirmed'
			);

			$booking_id = $this->create_booking(
				$this->create_staff( 'Staff B ' . $case['name'] ),
				$case['target_start'],
				$case['target_end'],
				'confirmed'
			);

			$actual = $admin->has_staff_conflict_public(
				$booking_id,
				$staff_a,
				$case['target_start'],
				$case['target_end']
			);

			$this->assertSame( $case['expected'], $actual, $case['name'] );
		}
	}

	public function test_save_post_staff_conflict_setting_cases(): void {
		$test_cases = [
			[
				'name'               => 'disallow_conflict_on_admin_save',
				'allow_overlap'      => false,
				'existing_start'     => '2024-01-03 10:00:00',
				'existing_end'       => '2024-01-03 11:00:00',
				'booking_start'      => '2024-01-03 09:00:00',
				'booking_end'        => '2024-01-03 09:30:00',
				'requested_date'     => '2024-01-03',
				'requested_start'    => '10:15',
				'requested_end'      => '11:00',
				'expected_staff_key' => 'staff_b',
				'expected_start'     => '2024-01-03 09:00:00',
			],
			[
				'name'               => 'allow_conflict_on_admin_save',
				'allow_overlap'      => true,
				'existing_start'     => '2024-01-04 10:00:00',
				'existing_end'       => '2024-01-04 11:00:00',
				'booking_start'      => '2024-01-04 09:00:00',
				'booking_end'        => '2024-01-04 09:30:00',
				'requested_date'     => '2024-01-04',
				'requested_start'    => '10:15',
				'requested_end'      => '11:00',
				'expected_staff_key' => 'staff_a',
				'expected_start'     => '2024-01-04 10:15:00',
			],
		];

		foreach ( $test_cases as $case ) {
			$this->set_provider_settings(
				[
					'provider_allow_staff_overlap_admin' => $case['allow_overlap'],
				]
			);

			$staff_a = $this->create_staff( 'Staff A ' . $case['name'] );
			$staff_b = $this->create_staff( 'Staff B ' . $case['name'] );

			$this->create_booking(
				$staff_a,
				$case['existing_start'],
				$case['existing_end'],
				'confirmed'
			);

			$booking_id = $this->create_booking(
				$staff_b,
				$case['booking_start'],
				$case['booking_end'],
				'confirmed'
			);

			$this->set_booking_post_data(
				$case['requested_date'],
				$case['requested_start'],
				$case['requested_end'],
				$staff_a
			);

			$post  = get_post( $booking_id );
			$admin = new Booking_Admin();
			$admin->save_post( $booking_id, $post );

			$expected_staff_id = 'staff_a' === $case['expected_staff_key'] ? $staff_a : $staff_b;
			$this->assertSame( $expected_staff_id, (int) get_post_meta( $booking_id, self::META_RESOURCE_ID, true ), $case['name'] );
			$this->assertSame( $case['expected_start'], get_post_meta( $booking_id, self::META_DATE_START, true ), $case['name'] );
		}
	}

	/**
	 * #394: get_conflicting_staff_ids() の担当スタッフ候補絞り込みが、指名を使うメニューでは
	 * 従来と完全に同じ結果（時間帯が重なる別予約が1件でもあるスタッフを除外）になることを検証する。
	 *
	 * 回帰防止テスト。定員が2以上のメニューでも、指名ONなら1枠1組（貸切）扱いのため、
	 * 定員に余裕があっても除外されることを確認する（定員基準の絞り込みに切り替わっていないこと）。
	 */
	public function test_get_conflicting_staff_ids_with_nomination_matches_legacy_overlap_check(): void {
		$this->set_nomination_enabled( true );

		$test_cases = [
			[
				'name'                 => '指名ON・定員3でも同じメニューの重なる予約が1件あれば除外される',
				'other_menu'           => false,
				'other_start'          => '2024-02-01 10:00:00',
				'other_end'            => '2024-02-01 11:00:00',
				'target_start'         => '2024-02-01 10:15:00',
				'target_end'           => '2024-02-01 11:00:00',
				'expect_excluded'      => true,
			],
			[
				'name'                 => '指名ON・別メニューの重なる予約でも除外される（メニュー問わず1件でも重なれば除外という従来仕様）',
				'other_menu'           => true,
				'other_start'          => '2024-02-02 10:00:00',
				'other_end'            => '2024-02-02 11:00:00',
				'target_start'         => '2024-02-02 10:15:00',
				'target_end'           => '2024-02-02 11:00:00',
				'expect_excluded'      => true,
			],
			[
				'name'                 => '指名ON・時間帯が重ならなければ除外されない',
				'other_menu'           => false,
				'other_start'          => '2024-02-03 10:00:00',
				'other_end'            => '2024-02-03 11:00:00',
				'target_start'         => '2024-02-03 12:00:00',
				'target_end'           => '2024-02-03 12:30:00',
				'expect_excluded'      => false,
			],
		];

		$admin = new Booking_Admin_Test_Double();

		foreach ( $test_cases as $case ) {
			// 指名を使うメニュー（定員3。定員に余裕があっても除外されることを確認するのが本テストの主眼）。
			$menu_id = $this->create_menu( 3 );
			// 別メニュー扱いにするケース用に、もう1つメニューを用意する。
			$other_menu_id = $case['other_menu'] ? $this->create_menu( 3 ) : $menu_id;

			$staff_a = $this->create_staff( 'Staff A ' . $case['name'] );

			$this->create_booking_with_menu(
				$staff_a,
				$case['other_start'],
				$case['other_end'],
				'confirmed',
				$other_menu_id,
				1
			);

			$booking_id = $this->create_booking_with_menu(
				$this->create_staff( 'Staff B ' . $case['name'] ),
				$case['target_start'],
				$case['target_end'],
				'confirmed',
				$menu_id,
				1
			);

			$result = $admin->get_conflicting_staff_ids_public(
				$booking_id,
				$case['target_start'],
				$case['target_end'],
				$menu_id,
				1
			);

			$this->assertSame(
				$case['expect_excluded'],
				in_array( $staff_a, $result, true ),
				$case['name']
			);
		}
	}

	/**
	 * #394: get_conflicting_staff_ids() の担当スタッフ候補絞り込みが、指名を使わない・定員2以上の
	 * メニューでは「残り（定員 − 当該メニューの既存予約人数の合計）がこの予約の人数に満たない」
	 * スタッフだけを除外することを検証する。
	 */
	public function test_get_conflicting_staff_ids_without_nomination_excludes_by_remaining_capacity(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->set_nomination_enabled( false );

		$menu_id = $this->create_menu( 3 );
		$start   = '2024-02-10 10:00:00';
		$end     = '2024-02-10 11:00:00';

		$test_cases = [
			[
				'name'            => '既存人数2・残り1（3-2） に対し人数1を要求 => 除外されない',
				'existing_guests' => 2,
				'requested_guests' => 1,
				'expect_excluded' => false,
			],
			[
				'name'            => '既存人数2・残り1（3-2） に対し人数2を要求 => 残数不足で除外される',
				'existing_guests' => 2,
				'requested_guests' => 2,
				'expect_excluded' => true,
			],
			[
				'name'            => '既存予約なし（残り3）に対し人数3を要求 => 除外されない（境界値）',
				'existing_guests' => 0,
				'requested_guests' => 3,
				'expect_excluded' => false,
			],
		];

		$admin = new Booking_Admin_Test_Double();

		foreach ( $test_cases as $case ) {
			$staff_a = $this->create_staff( 'Staff A ' . $case['name'] );

			if ( $case['existing_guests'] > 0 ) {
				$this->create_booking_with_menu( $staff_a, $start, $end, 'confirmed', $menu_id, $case['existing_guests'] );
			}

			$booking_id = $this->create_booking_with_menu(
				$this->create_staff( 'Staff B ' . $case['name'] ),
				$start,
				$end,
				'confirmed',
				$menu_id,
				$case['requested_guests']
			);

			$result = $admin->get_conflicting_staff_ids_public(
				$booking_id,
				$start,
				$end,
				$menu_id,
				$case['requested_guests']
			);

			$this->assertSame(
				$case['expect_excluded'],
				in_array( $staff_a, $result, true ),
				$case['name']
			);
		}

		// #394 レビュー対応（項目2）: 残数判定（同じメニューの負荷）は別メニューの予約を見ないが、
		// 除外集合はそれと「別メニューの重なる予約を持つスタッフ」の和集合になる。管理者の重複保存
		// 許可設定（provider_allow_staff_overlap_admin）がこのメニュー種別だけ無効化されないよう、
		// 別メニューとの重複は従来どおり止める。
		$other_menu_id = $this->create_menu( 3 );
		$staff_c       = $this->create_staff( 'Staff C other-menu' );
		$this->create_booking_with_menu( $staff_c, $start, $end, 'confirmed', $other_menu_id, 3 );

		$booking_id = $this->create_booking_with_menu(
			$this->create_staff( 'Staff D other-menu' ),
			$start,
			$end,
			'confirmed',
			$menu_id,
			3
		);

		$result = $admin->get_conflicting_staff_ids_public( $booking_id, $start, $end, $menu_id, 3 );
		$this->assertTrue(
			in_array( $staff_c, $result, true ),
			'別メニューの重なる予約を持つスタッフは、指名OFF・定員2以上でも除外される（和集合）'
		);

		// #394 レビュー対応（項目2の回帰テスト追加）: 同一メニューと別メニューの両方に予約があるスタッフ。
		// 同一メニュー側だけを見れば残数は足りている（人数1・残り2）が、別メニューでも同時刻に重なる
		// 予約があるため除外される。array_diff( 全メニューの重なり, array_keys( $loads ) ) のような
		// 誤った実装（$loads に載っている＝除外しない、と誤認する）に差し替えても通ってしまう回帰を防ぐ。
		$other_menu_id_2 = $this->create_menu( 3 );
		$staff_e         = $this->create_staff( 'Staff E both-menus' );
		// 同一メニューの予約（人数1）：残り2（3-1）で、単独なら人数1の要求は除外されない。
		$this->create_booking_with_menu( $staff_e, $start, $end, 'confirmed', $menu_id, 1 );
		// 同時刻・別メニューの重なる予約。
		$this->create_booking_with_menu( $staff_e, $start, $end, 'confirmed', $other_menu_id_2, 1 );

		$booking_id = $this->create_booking_with_menu(
			$this->create_staff( 'Staff F both-menus' ),
			$start,
			$end,
			'confirmed',
			$menu_id,
			1
		);

		$result = $admin->get_conflicting_staff_ids_public( $booking_id, $start, $end, $menu_id, 1 );
		$this->assertTrue(
			in_array( $staff_e, $result, true ),
			'同一メニューの残数は足りていても、別メニューで同時刻に重なる予約があれば除外される（和集合の取りこぼし回帰防止）'
		);
	}

	/**
	 * #394 レビュー対応（項目1）: 基本設定「予約枠の定員」機能（slot_capacity_enabled）がOFFのとき、
	 * メニューに残った古い定員値（例: 3）を無視して常に定員1として扱うことを検証する。
	 *
	 * 定員1扱いになれば get_conflicting_staff_ids() は「1件でも重なる予約があれば除外」の
	 * 判定（get_overlapping_staff_ids）へ分岐するため、残数（定員3−負荷1=2）があっても
	 * 除外されることで、機能OFFが正しく効いていることを確認する。
	 */
	public function test_get_conflicting_staff_ids_ignores_stale_max_capacity_when_slot_capacity_disabled(): void {
		$this->set_nomination_enabled( false );
		// メニューには定員3が残ったままだが、予約枠の定員機能自体をOFFにする。
		$this->set_slot_capacity_enabled( false );

		$menu_id = $this->create_menu( 3 );
		$start   = '2024-02-20 10:00:00';
		$end     = '2024-02-20 11:00:00';

		$staff_a = $this->create_staff( 'Staff A slot-capacity-disabled' );
		// 同じメニュー・同じ枠に既存予約1名（定員3が生きていれば残り2で除外されないはずの負荷）。
		$this->create_booking_with_menu( $staff_a, $start, $end, 'confirmed', $menu_id, 1 );

		$booking_id = $this->create_booking_with_menu(
			$this->create_staff( 'Staff B slot-capacity-disabled' ),
			$start,
			$end,
			'confirmed',
			$menu_id,
			1
		);

		$admin  = new Booking_Admin_Test_Double();
		$result = $admin->get_conflicting_staff_ids_public( $booking_id, $start, $end, $menu_id, 1 );

		$this->assertTrue(
			in_array( $staff_a, $result, true ),
			'予約枠の定員機能OFF時は、メニューの古い定員値（3）を無視して定員1扱いになり、既存予約1件だけで除外される'
		);
	}

	/**
	 * #394 レビュー対応（項目5）: 指名OFF・定員1のメニューでも、別メニューの重なる予約を持つ
	 * スタッフは除外されることを検証する（項目1・2の回帰テストを兼ねる）。
	 *
	 * 定員1のメニューは get_conflicting_staff_ids() が get_overlapping_staff_ids() の
	 * 「メニュー問わず1件でも重なれば除外」判定へそのまま分岐するため、#394 より前の
	 * 挙動と変わらないことを確認する。
	 */
	public function test_get_conflicting_staff_ids_with_max_capacity_one_excludes_other_menu_conflict(): void {
		$this->set_nomination_enabled( false );

		$menu_id       = $this->create_menu( 1 );
		$other_menu_id = $this->create_menu( 1 );
		$start         = '2024-02-21 10:00:00';
		$end           = '2024-02-21 11:00:00';

		$staff_a = $this->create_staff( 'Staff A max-capacity-one-other-menu' );
		// 別メニューの重なる予約。
		$this->create_booking_with_menu( $staff_a, $start, $end, 'confirmed', $other_menu_id, 1 );

		$booking_id = $this->create_booking_with_menu(
			$this->create_staff( 'Staff B max-capacity-one-other-menu' ),
			$start,
			$end,
			'confirmed',
			$menu_id,
			1
		);

		$admin  = new Booking_Admin_Test_Double();
		$result = $admin->get_conflicting_staff_ids_public( $booking_id, $start, $end, $menu_id, 1 );

		$this->assertTrue(
			in_array( $staff_a, $result, true ),
			'指名OFF・定員1のメニューでも、別メニューの重なる予約を持つスタッフは除外される'
		);
	}

	private function set_current_user_with_caps(): void {
		$user_id = $this->factory()->user->create(
			[
				'role' => 'administrator',
			]
		);
		wp_set_current_user( $user_id );

		$user = get_user_by( 'id', $user_id );
		$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
	}

	private function create_staff( string $name ): int {
		return (int) $this->factory()->post->create(
			[
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			]
		);
	}

	private function create_booking( int $staff_id, string $start_at, string $end_at, string $status ): int {
		$booking_id = (int) $this->factory()->post->create(
			[
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);

		update_post_meta( $booking_id, self::META_DATE_START, $start_at );
		update_post_meta( $booking_id, self::META_DATE_END, $end_at );
		update_post_meta( $booking_id, self::META_TOTAL_END, $end_at );
		update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );
		update_post_meta( $booking_id, self::META_STATUS, $status );

		return $booking_id;
	}

	private function set_booking_post_data( string $date, string $start_time, string $end_time, int $staff_id ): void {
		$_POST = [
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => [
				'date'        => $date,
				'start_time'  => $start_time,
				'end_time'    => $end_time,
				'resource_id' => $staff_id,
				'status'      => 'confirmed',
			],
		];
	}

	private function set_provider_settings( array $overrides ): void {
		$settings = ( new Settings_Repository() )->get_default_settings();
		$settings = array_merge( $settings, $overrides );
		update_option( Settings_Repository::OPTION_KEY, $settings );
	}

	/**
	 * #394: 指名機能（staff_enabled）の有効・無効を設定し、Staff_Editor のキャッシュをクリアする。
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
	 * #394 レビュー対応（項目1）: 基本設定「予約枠の定員」機能（slot_capacity_enabled）の有効・無効を
	 * 設定し、Staff_Editor のキャッシュをクリアする。
	 *
	 * @param bool $enabled 有効にする場合は true。
	 */
	private function set_slot_capacity_enabled( bool $enabled ): void {
		$repository                          = new Settings_Repository();
		$settings                            = $repository->get_settings();
		$settings['slot_capacity_enabled']   = $enabled;
		update_option( Settings_Repository::OPTION_KEY, $settings );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * #394: サービスメニューを作成する。
	 *
	 * @param int $max_capacity 1枠あたり最大受付数（1組の最大人数）。
	 * @return int メニュー投稿ID。
	 */
	private function create_menu( int $max_capacity ): int {
		$menu_id = (int) $this->factory()->post->create(
			[
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $menu_id, '_vkbm_max_capacity', $max_capacity );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );

		return $menu_id;
	}

	/**
	 * #394: サービスメニュー・人数を付与した予約投稿を作成する（get_conflicting_staff_ids の絞り込みテスト用）。
	 *
	 * @param int    $staff_id スタッフID。
	 * @param string $start_at 開始日時（Y-m-d H:i:s）。
	 * @param string $end_at   終了日時（Y-m-d H:i:s）。
	 * @param string $status   予約ステータス。
	 * @param int    $menu_id  サービスメニューID。
	 * @param int    $guests   予約人数。
	 * @return int 予約投稿ID。
	 */
	private function create_booking_with_menu( int $staff_id, string $start_at, string $end_at, string $status, int $menu_id, int $guests ): int {
		$booking_id = $this->create_booking( $staff_id, $start_at, $end_at, $status );
		update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
		update_post_meta( $booking_id, self::META_GUESTS, $guests );

		return $booking_id;
	}
}

class Booking_Admin_Test_Double extends Booking_Admin {
	public function has_staff_conflict_public( int $post_id, int $staff_id, string $start_at, string $end_at ): bool {
		return $this->has_staff_conflict( $post_id, $staff_id, $start_at, $end_at );
	}

	/**
	 * #394: 担当スタッフ候補の絞り込み（get_conflicting_staff_ids）をテストから直接呼べるようにする。
	 *
	 * @param int    $post_id    現在編集中の予約ID（除外）。
	 * @param string $start_at   予約開始日時（Y-m-d H:i:s）。
	 * @param string $end_at     予約終了日時（Y-m-d H:i:s）。
	 * @param int    $service_id サービスメニューID。
	 * @param int    $guests     この予約の人数。
	 * @return array<int, int>
	 */
	public function get_conflicting_staff_ids_public( int $post_id, string $start_at, string $end_at, int $service_id = 0, int $guests = 1 ): array {
		return $this->get_conflicting_staff_ids( $post_id, $start_at, $end_at, $service_id, $guests );
	}
}
