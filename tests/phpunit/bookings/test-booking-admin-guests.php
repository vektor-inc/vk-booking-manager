<?php

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\Capabilities\Capabilities;
use VKBookingManager\PostTypes\Booking_Post_Type;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function delete_option;
use function delete_transient;
use function get_current_user_id;
use function get_option;
use function get_post;
use function get_post_meta;
use function get_transient;
use function get_user_by;
use function update_option;
use function update_post_meta;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * 予約編集画面（save_post）での予約人数の編集と、基本料金合計の再計算を検証する。
 *
 * @group bookings
 */
class Booking_Admin_Guests_Test extends WP_UnitTestCase {
	private const META_DATE_START       = '_vkbm_booking_service_start';
	private const META_DATE_END         = '_vkbm_booking_service_end';
	private const META_TOTAL_END        = '_vkbm_booking_total_end';
	private const META_RESOURCE_ID      = '_vkbm_booking_resource_id';
	private const META_SERVICE_ID       = '_vkbm_booking_service_id';
	private const META_STATUS           = '_vkbm_booking_status';
	private const META_GUESTS           = '_vkbm_booking_guests';
	private const META_BASE_TOTAL_PRICE = '_vkbm_booking_base_total_price';

	/**
	 * テスト開始時の基本設定オプション値（復元用）。未設定時は false。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	protected function setUp(): void {
		parent::setUp();
		$this->set_current_user_with_caps();
		// set_nomination_enabled() が変更する基本設定オプションを保存しておく。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );
		Staff_Editor::clear_nomination_enabled_cache();
	}

	protected function tearDown(): void {
		$_POST = array();
		// テスト中に変更した基本設定オプションを元の状態へ復元する。
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		parent::tearDown();
	}

	/**
	 * save_post で予約人数・担当スタッフ・基本料金合計が正しく保存されることを検証する。
	 *
	 * 1予約は分割せず単一スタッフに割り当てる。指名機能OFFでは単一スタッフ＋人数（1〜max_capacity）、
	 * ONでは単一スタッフ・1名で保存される。人数（_vkbm_booking_guests）・担当スタッフ
	 * （_vkbm_booking_resource_id）・基本料金合計を確認し、分配メタは保存されないことも確認する。
	 * スタッフは 'a' のプレースホルダで指定し、ループ内で実IDへ変換する。
	 */
	public function test_save_post(): void {
		$base_price = 1000;

		$test_cases = array(
			array(
				'test_condition_name' => '複数人予約有効・人数を3に編集 => 人数3 / 代表A / 基本料金 1000×3',
				'nomination_enabled'  => false,
				'allow_multiple'      => true,
				'max_guests'          => 5,
				'existing_guests'     => 1,
				'posted_guests'       => 3,
				'expected_guests'     => 3,
				'expected_base_total' => 3000,
				'expected_resource'   => 'a',
			),
			array(
				'test_condition_name' => '複数人予約有効・人数を上限ちょうど5に編集 => 人数5 / 代表A / 基本料金 1000×5（境界値）',
				'nomination_enabled'  => false,
				'allow_multiple'      => true,
				'max_guests'          => 5,
				'existing_guests'     => 1,
				'posted_guests'       => 5,
				'expected_guests'     => 5,
				'expected_base_total' => 5000,
				'expected_resource'   => 'a',
			),
			array(
				// 指名ON時は複数人予約が無効で人数入力欄が無いため、set_booking_post_data は人数を送信せず
				// スタッフのドロップダウンのみ送る（posted_guests は未使用）。save_post は人数を常に1へ固定する。
				//
				// 【将来対応メモ】このケースは「指名ON＝1人固定」という現行仕様を固定化するもの。
				// 指名ON＋複数人予約を解禁する場合は、このケースを削除し、以下のロジックを見直すこと:
				//   - save_post / resolve_guests の「指名ONなら人数1」固定
				//   - 指名予約の容量判定（class-booking-confirmation-controller の check_capacity_with_mutex）。
				'test_condition_name' => '指名機能ON => 人数は常に1へ固定 / 代表A / 基本料金 1000×1',
				'nomination_enabled'  => true,
				'allow_multiple'      => false,
				'max_guests'          => 1,
				'existing_guests'     => 1,
				'posted_guests'       => null,
				'expected_guests'     => 1,
				'expected_base_total' => 1000,
				'expected_resource'   => 'a',
			),
		);

		foreach ( $test_cases as $case ) {
			// 指名機能（staff_enabled）の有効・無効を設定し、キャッシュをクリアする。
			$this->set_nomination_enabled( $case['nomination_enabled'] );

			// サービスメニューを作成し、基本料金と複数人予約設定を付与する。
			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_base_price', $base_price );
			if ( $case['allow_multiple'] ) {
				update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
				// 1予約あたりの最大人数は「1枠あたり最大受付数（_vkbm_max_capacity）」に統一済み。
				update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_guests'] );
			}

			// スタッフを作成し、メニューに割り当てる（'a' プレースホルダ）。
			$staff_a = $this->create_staff( 'Staff A ' . $case['test_condition_name'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a ) );
			$staff_map = array( 'a' => $staff_a );

			$booking_id = $this->create_booking( $menu_id, $staff_a, $case['existing_guests'] );

			// 予約編集画面の保存をシミュレートする。
			$this->set_booking_post_data( $menu_id, $staff_a, $case['posted_guests'], $case['nomination_enabled'] );

			$post  = get_post( $booking_id );
			$admin = new Booking_Admin();
			$admin->save_post( $booking_id, $post );

			// 人数の検証。
			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
				$case['test_condition_name']
			);

			// 基本料金合計の検証（人数変更が反映されること）。
			$this->assertSame(
				$case['expected_base_total'],
				(int) get_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, true ),
				$case['test_condition_name']
			);

			// 代表スタッフ（resource_id）の検証。
			$this->assertSame(
				$staff_map[ $case['expected_resource'] ],
				(int) get_post_meta( $booking_id, self::META_RESOURCE_ID, true ),
				$case['test_condition_name']
			);

			// 分配メタは保存されないこと（分割しない）。
			$this->assertEmpty(
				get_post_meta( $booking_id, '_vkbm_booking_staff_distribution', true ),
				$case['test_condition_name'] . ' / 分配メタは保存されないこと'
			);

			$_POST = array();
		}
	}

	/**
	 * save_post で配分が不正な場合（人数が1スタッフ上限を超過／担当スタッフが1人もいない）に、
	 * 保存を中断し（人数・配分を更新せず）管理者向けのエラー通知（transient）が登録されることを検証する。
	 * 上限内かつ担当ありでは通常どおり保存され、通知も発生しないことも併せて確認する。
	 * フロント（顧客予約）の上限超過エラーと挙動を対称にし、対応できない予約の保存を防ぐための仕様。
	 *
	 * Verify that save_post aborts (without updating the guest count / distribution) and queues an error
	 * admin notice when the distribution is invalid (guests exceed the per-staff maximum, or no staff is
	 * assigned). When within the maximum and a staff is assigned, the booking is saved normally with no notice.
	 */
	public function test_save_post_rejects_invalid_distribution(): void {
		// 複数人予約は指名OFF（自動割り当て）時のみ有効。
		$this->set_nomination_enabled( false );

		// 通知 transient のキーは「プレフィックス + ユーザーID + '_' + 投稿ID」。
		$notice_prefix = 'vkbm_booking_staff_conflict_';
		$user_id       = get_current_user_id();

		// 既存予約の人数（保存中断時に据え置かれる値）。
		$existing_guests = 1;

		$test_cases = array(
			array(
				'test_condition_name' => '最大2に対し5を要求 → 保存中断・人数据え置き(1)・エラー通知あり（異常系：上限超過）',
				'max_capacity'        => 2,
				'posted_guests'       => 5,
				'expected_guests'     => $existing_guests,
				'expect_saved'        => false,
			),
			array(
				'test_condition_name' => '配分行を1つも送信しない → 保存中断・人数据え置き(1)・エラー通知あり（異常系：担当なし）',
				'max_capacity'        => 5,
				'posted_guests'       => null,
				'expected_guests'     => $existing_guests,
				'expect_saved'        => false,
			),
			array(
				'test_condition_name' => '最大2に対し2を要求 → 保存される(2)・通知なし（境界値：上限ちょうど）',
				'max_capacity'        => 2,
				'posted_guests'       => 2,
				'expected_guests'     => 2,
				'expect_saved'        => true,
			),
			array(
				'test_condition_name' => '最大5に対し3を要求 → 保存される(3)・通知なし（正常系：上限未満・担当あり）',
				'max_capacity'        => 5,
				'posted_guests'       => 3,
				'expected_guests'     => 3,
				'expect_saved'        => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// メニューを作成し、複数人予約設定・単価・スタッフ1名を付与する。
			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );

			$staff_a = $this->create_staff( 'Staff A ' . $case['test_condition_name'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_a ) );

			$booking_id    = $this->create_booking( $menu_id, $staff_a, $existing_guests );
			$transient_key = $notice_prefix . $user_id . '_' . $booking_id;

			// 前ケースの残留を避けるため、保存前に通知 transient をクリアする。
			delete_transient( $transient_key );

			// posted_guests が null の場合は担当スタッフを送信しない（担当なしの状態を再現する）。
			$posted_staff = null === $case['posted_guests'] ? 0 : $staff_a;
			$this->set_booking_post_data( $menu_id, $posted_staff, $case['posted_guests'], false );

			$post  = get_post( $booking_id );
			$admin = new Booking_Admin();
			$admin->save_post( $booking_id, $post );

			// 人数メタ：保存時は要求値、中断時は据え置き値であることを検証する。
			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
				$case['test_condition_name']
			);

			// 管理通知 transient の有無・内容を検証する。
			$notice = get_transient( $transient_key );
			if ( $case['expect_saved'] ) {
				// 正常：通知は登録されない。
				$this->assertFalse( $notice, $case['test_condition_name'] . ' / 通知が登録されていないこと' );
			} else {
				// 中断：エラー通知が登録され、配分は保存されない。
				$this->assertIsArray( $notice, $case['test_condition_name'] . ' / 通知ペイロードが配列であること' );
				$this->assertArrayHasKey( 'notices', $notice, $case['test_condition_name'] . ' / notices キーがあること' );
				$this->assertNotEmpty( $notice['notices'], $case['test_condition_name'] . ' / 通知が1件以上あること' );
				$this->assertSame(
					'error',
					(string) ( $notice['notices'][0]['type'] ?? '' ),
					$case['test_condition_name'] . ' / 通知種別が error であること'
				);
				$saved_distribution = get_post_meta( $booking_id, '_vkbm_booking_staff_distribution', true );
				$this->assertEmpty( $saved_distribution, $case['test_condition_name'] . ' / 配分が保存されていないこと' );
			}

			// ケースごとに後始末する。
			delete_transient( $transient_key );
			$_POST = array();
		}
	}

	/**
	 * save_post: 料金区分の予約は保存済み内訳（count付き）から基本料金合計を再計算する（count欠落の回帰防止）。
	 *
	 * normalize_guest_tiers() が count を保持するため、save_post で base_total が
	 * Σ(区分料金 × 区分人数) になることを確認する。count を落とすと合計0（または指名料のみ）に
	 * なってしまうため、その回帰を捕捉する。
	 */
	public function test_save_post_preserves_guest_tiers_total(): void {
		$this->set_nomination_enabled( false );

		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
		$staff_id = $this->create_staff( 'Staff A' );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		// 区分内訳（一般4000×3 + 子供3000×2 = 18000）を持つ予約を作成する。
		$booking_id = $this->create_booking( $menu_id, $staff_id, 5 );
		update_post_meta(
			$booking_id,
			'_vkbm_booking_guest_tiers',
			array(
				array(
					'label' => '一般',
					'price' => 4000,
					'count' => 3,
				),
				array(
					'label' => '子供',
					'price' => 3000,
					'count' => 2,
				),
			)
		);
		// 基本料金スナップショットは区分計算では使われないが、フォールバック誤計算の検出のため別値を入れておく。
		update_post_meta( $booking_id, '_vkbm_booking_service_base_price', 9999 );

		// 編集画面保存をシミュレートする（区分予約は人数欄を送らない＝既存内訳を保持）。
		$this->set_booking_post_data( $menu_id, $staff_id, null, false );

		$post  = get_post( $booking_id );
		$admin = new Booking_Admin();
		$admin->save_post( $booking_id, $post );

		// count が保持され、基本料金合計が区分合計（18000）で再計算されることを確認する。
		$this->assertSame(
			5,
			(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'区分予約の人数（合計）が保持される'
		);
		$this->assertSame(
			18000,
			(int) get_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, true ),
			'基本料金合計が区分料金合計（4000×3+3000×2）で再計算される'
		);

		$_POST = array();
	}

	/**
	 * 管理者権限のユーザーを作成してログイン状態にする。
	 */
	private function set_current_user_with_caps(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$user = get_user_by( 'id', $user_id );
		$user->add_cap( Capabilities::MANAGE_RESERVATIONS );
	}

	/**
	 * 指名機能（staff_enabled）の有効・無効を設定し、Staff_Editor のキャッシュをクリアする。
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
	 * サービスメニューを作成する。
	 *
	 * @return int メニュー投稿ID。
	 */
	private function create_menu(): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * スタッフ（リソース）を作成する。
	 *
	 * @param string $name スタッフ名。
	 * @return int スタッフ投稿ID。
	 */
	private function create_staff( string $name ): int {
		return (int) $this->factory()->post->create(
			array(
				'post_type'   => Resource_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
	}

	/**
	 * 予約投稿を作成する（メニュー・スタッフ・既存人数を付与）。
	 *
	 * @param int $menu_id         サービスメニューID。
	 * @param int $staff_id        スタッフID。
	 * @param int $existing_guests 既存の予約人数。
	 * @return int 予約投稿ID。
	 */
	private function create_booking( int $menu_id, int $staff_id, int $existing_guests ): int {
		$booking_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Booking_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $booking_id, self::META_DATE_START, '2024-06-01 10:00:00' );
		update_post_meta( $booking_id, self::META_DATE_END, '2024-06-01 10:30:00' );
		update_post_meta( $booking_id, self::META_TOTAL_END, '2024-06-01 10:30:00' );
		update_post_meta( $booking_id, self::META_RESOURCE_ID, $staff_id );
		update_post_meta( $booking_id, self::META_SERVICE_ID, $menu_id );
		update_post_meta( $booking_id, self::META_STATUS, 'confirmed' );
		update_post_meta( $booking_id, self::META_GUESTS, $existing_guests );

		return $booking_id;
	}

	/**
	 * 予約編集画面の保存（$_POST）をシミュレートする。
	 *
	 * 担当スタッフは単一選択（resource_id）。指名機能OFFでは人数欄（guests）も送信する。
	 * 1予約は分割せず単一スタッフに割り当てるため、リピーター（staff_distribution）は送信しない。
	 *
	 * @param int      $menu_id            サービスメニューID。
	 * @param int      $staff_id           担当スタッフID。0 のときは未選択（担当なし）を再現するため送信しない。
	 * @param int|null $posted_guests      送信する人数（指名OFF）。null なら人数欄を送らない。
	 * @param bool     $nomination_enabled 指名機能が有効か。
	 */
	private function set_booking_post_data( int $menu_id, int $staff_id, ?int $posted_guests, bool $nomination_enabled ): void {
		$booking = array(
			'date'       => '2024-06-01',
			'start_time' => '10:00',
			'end_time'   => '10:30',
			'service_id' => $menu_id,
			'status'     => 'confirmed',
		);

		// 担当スタッフ（単一選択）。0 のときは未選択（担当なし）を再現するため送信しない。
		if ( $staff_id > 0 ) {
			$booking['resource_id'] = $staff_id;
		}

		// 指名機能OFFのときのみ人数欄を送信する（指名ONは人数欄が無く、save_post 側で常に1へ固定）。
		if ( ! $nomination_enabled && null !== $posted_guests ) {
			$booking['guests'] = $posted_guests;
		}

		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => $booking,
		);
	}
}
