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
	 * #394: 指名を使うメニューでも、メニューの定員（1組の最大人数）まで人数を編集して保存できる
	 * （料金区分未定義の予約に限る）。1予約は分割せず単一スタッフに割り当てる。人数
	 * （_vkbm_booking_guests）・担当スタッフ（_vkbm_booking_resource_id）・基本料金合計を確認し、
	 * 分配メタは保存されないことも確認する。スタッフは 'a' のプレースホルダで指定し、ループ内で実IDへ変換する。
	 */
	public function test_save_post(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$base_price = 1000;

		$test_cases = array(
			array(
				'test_condition_name' => '指名OFF・複数人予約有効・人数を3に編集 => 人数3 / 代表A / 基本料金 1000×3',
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
				'test_condition_name' => '指名OFF・複数人予約有効・人数を上限ちょうど5に編集 => 人数5 / 代表A / 基本料金 1000×5（境界値）',
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
				// #394: 指名を使うメニューでも1件の予約の中でメニューの定員まで複数人を申し込めるため、
				// 指名ONでも人数編集欄が表示され、変更が反映される（1枠1組・貸切の前提は保たれる）。
				'test_condition_name' => '指名ON・複数人予約有効・人数を3に編集 => 人数3 / 代表A / 基本料金 1000×3',
				'nomination_enabled'  => true,
				'allow_multiple'      => true,
				'max_guests'          => 5,
				'existing_guests'     => 1,
				'posted_guests'       => 3,
				'expected_guests'     => 3,
				'expected_base_total' => 3000,
				'expected_resource'   => 'a',
			),
			array(
				'test_condition_name' => '指名ON・人数を上限ちょうど2に編集 => 人数2 / 代表A / 基本料金 1000×2（境界値）',
				'nomination_enabled'  => true,
				'allow_multiple'      => true,
				'max_guests'          => 2,
				'existing_guests'     => 1,
				'posted_guests'       => 2,
				'expected_guests'     => 2,
				'expected_base_total' => 2000,
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
			$this->set_booking_post_data( $menu_id, $staff_a, $case['posted_guests'] );

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
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		// 通知 transient のキーは「プレフィックス + ユーザーID + '_' + 投稿ID」。
		$notice_prefix = 'vkbm_booking_staff_conflict_';
		$user_id       = get_current_user_id();

		// 既存予約の人数（保存中断時に据え置かれる値）。
		$existing_guests = 1;

		$test_cases = array(
			array(
				'test_condition_name' => '指名OFF・最大2に対し5を要求 → 保存中断・人数据え置き(1)・エラー通知あり（異常系：上限超過）',
				'nomination_enabled'  => false,
				'max_capacity'        => 2,
				'posted_guests'       => 5,
				'expected_guests'     => $existing_guests,
				'expect_saved'        => false,
			),
			array(
				'test_condition_name' => '指名OFF・配分行を1つも送信しない → 保存中断・人数据え置き(1)・エラー通知あり（異常系：担当なし）',
				'nomination_enabled'  => false,
				'max_capacity'        => 5,
				'posted_guests'       => null,
				'expected_guests'     => $existing_guests,
				'expect_saved'        => false,
			),
			array(
				'test_condition_name' => '指名OFF・最大2に対し2を要求 → 保存される(2)・通知なし（境界値：上限ちょうど）',
				'nomination_enabled'  => false,
				'max_capacity'        => 2,
				'posted_guests'       => 2,
				'expected_guests'     => 2,
				'expect_saved'        => true,
			),
			array(
				'test_condition_name' => '指名OFF・最大5に対し3を要求 → 保存される(3)・通知なし（正常系：上限未満・担当あり）',
				'nomination_enabled'  => false,
				'max_capacity'        => 5,
				'posted_guests'       => 3,
				'expected_guests'     => 3,
				'expect_saved'        => true,
			),
			array(
				// #394: 指名を使うメニューでも人数編集欄が表示されるようになったため、指名ONでも
				// 定員超過は同じく保存を中断し、既存の値（人数）が壊れない（=据え置かれる）ことを検証する。
				'test_condition_name' => '指名ON・最大2に対し5を要求 → 保存中断・人数据え置き(1)・エラー通知あり（異常系：上限超過）',
				'nomination_enabled'  => true,
				'max_capacity'        => 2,
				'posted_guests'       => 5,
				'expected_guests'     => $existing_guests,
				'expect_saved'        => false,
			),
			array(
				'test_condition_name' => '指名ON・最大3に対し3を要求 → 保存される(3)・通知なし（境界値：上限ちょうど）',
				'nomination_enabled'  => true,
				'max_capacity'        => 3,
				'posted_guests'       => 3,
				'expected_guests'     => 3,
				'expect_saved'        => true,
			),
		);

		foreach ( $test_cases as $case ) {
			// 指名機能（staff_enabled）の有効・無効を設定し、キャッシュをクリアする。
			$this->set_nomination_enabled( $case['nomination_enabled'] );

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
			$this->set_booking_post_data( $menu_id, $posted_staff, $case['posted_guests'] );

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
	 *
	 * #394: 料金区分が定義された予約は、指名の有無に関わらず人数編集UIの対象外（内訳表示のみ）。
	 * 指名ON・OFFの両方でループし、posted_guests（99）を送っても無視され、保存済みの内訳
	 * （人数・基本料金合計）がそのまま保持されることを確認する。
	 */
	public function test_save_post_preserves_guest_tiers_total(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '指名OFF・料金区分予約に人数99を送信 => 無視され内訳(5名/18000円)が保持される',
				'nomination_enabled'  => false,
			),
			array(
				'test_condition_name' => '指名ON・料金区分予約に人数99を送信 => 無視され内訳(5名/18000円)が保持される',
				'nomination_enabled'  => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_nomination_enabled( $case['nomination_enabled'] );

			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_base_price', 9999 );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
			$staff_id = $this->create_staff( 'Staff A ' . $case['test_condition_name'] );
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

			// 編集画面保存をシミュレートする。料金区分が定義された予約はUI上そもそも人数欄を出さないが、
			// 「送信されても無視される」ことまで確認するため、あえて99を送る。
			$this->set_booking_post_data( $menu_id, $staff_id, 99 );

			$post  = get_post( $booking_id );
			$admin = new Booking_Admin();
			$admin->save_post( $booking_id, $post );

			// count が保持され、基本料金合計が区分合計（18000）で再計算されることを確認する。
			$this->assertSame(
				5,
				(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
				$case['test_condition_name'] . ' / 区分予約の人数（合計）が保持される'
			);
			$this->assertSame(
				18000,
				(int) get_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, true ),
				$case['test_condition_name'] . ' / 基本料金合計が区分料金合計（4000×3+3000×2）で再計算される'
			);

			$_POST = array();
		}
	}

	/**
	 * save_post: 指名ONの予約で人数を変更したとき、基本料金合計が
	 * 「基本料金スナップショット × 変更後の人数 ＋ 指名料スナップショット」で再計算されることを検証する。
	 *
	 * #394: 指名を使うメニューでも複数人まで予約できるため、指名料を含む合計の再計算が
	 * 人数編集後も正しく行われることを確認する（指名機能を持つプラン特有の回帰防止）。
	 */
	public function test_save_post_recalculates_base_total_with_nomination_fee(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->set_nomination_enabled( true );

		$base_price     = 1000;
		$nomination_fee = 500;

		$test_cases = array(
			array(
				'test_condition_name' => '人数を1から3へ変更 => 基本料金合計 = 1000×3+500',
				'max_capacity'        => 5,
				'existing_guests'     => 1,
				'posted_guests'       => 3,
				'expected_guests'     => 3,
				'expected_base_total' => ( $base_price * 3 ) + $nomination_fee,
			),
			array(
				'test_condition_name' => '人数を定員ちょうど5へ変更 => 基本料金合計 = 1000×5+500（境界値）',
				'max_capacity'        => 5,
				'existing_guests'     => 1,
				'posted_guests'       => 5,
				'expected_guests'     => 5,
				'expected_base_total' => ( $base_price * 5 ) + $nomination_fee,
			),
		);

		foreach ( $test_cases as $case ) {
			$menu_id = $this->create_menu();
			update_post_meta( $menu_id, '_vkbm_base_price', $base_price );
			update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $menu_id, '_vkbm_max_capacity', $case['max_capacity'] );

			$staff_id = $this->create_staff( 'Staff A ' . $case['test_condition_name'] );
			update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

			$booking_id = $this->create_booking( $menu_id, $staff_id, $case['existing_guests'] );
			// 予約時点で確定した指名料のスナップショット。
			update_post_meta( $booking_id, '_vkbm_booking_nomination_fee', $nomination_fee );

			$this->set_booking_post_data( $menu_id, $staff_id, $case['posted_guests'] );

			$post  = get_post( $booking_id );
			$admin = new Booking_Admin();
			$admin->save_post( $booking_id, $post );

			$this->assertSame(
				$case['expected_guests'],
				(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
				$case['test_condition_name']
			);
			$this->assertSame(
				$case['expected_base_total'],
				(int) get_post_meta( $booking_id, self::META_BASE_TOTAL_PRICE, true ),
				$case['test_condition_name']
			);

			$_POST = array();
		}
	}

	/**
	 * save_post: 人数欄が未送信（POSTに guests キーが無い）の場合、黙って1へ巻き戻さず
	 * 保存済みの人数へフォールバックすることを検証する（#394 レビュー対応・項目6）。
	 *
	 * 通常の画面操作では人数入力欄が必ず送信されるため実運用では発火しない経路だが、
	 * 多層防御として、未送信時に人数が失われない（1人分に巻き戻らない）ことを確認する。
	 */
	public function test_save_post_falls_back_to_saved_guests_when_not_posted(): void {
		$this->set_nomination_enabled( false );

		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );

		$staff_id = $this->create_staff( 'Staff A guests-not-posted' );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		// 既存人数3で作成する。
		$booking_id = $this->create_booking( $menu_id, $staff_id, 3 );

		// 人数欄を送信しない（posted_guests = null）。担当スタッフは送信するため、
		// 担当なしによる中断（set_staff_required_notice）には該当しない。
		$this->set_booking_post_data( $menu_id, $staff_id, null );

		$post  = get_post( $booking_id );
		$admin = new Booking_Admin();
		$admin->save_post( $booking_id, $post );

		$this->assertSame(
			3,
			(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'人数欄が未送信でも、既存の人数（3）が保持され、1へ巻き戻らない'
		);

		$_POST = array();
	}

	/**
	 * save_post: 基本設定「予約枠の定員」機能（slot_capacity_enabled）がOFFのとき、メニューに
	 * 古い定員値（3）が残っていても定員1として扱われ、2名は上限超過で保存が中断されることを
	 * 検証する（#394 レビュー対応・項目1）。
	 */
	public function test_save_post_treats_capacity_as_one_when_slot_capacity_disabled(): void {
		$this->set_nomination_enabled( false );
		// メニューには定員3が残ったままだが、予約枠の定員機能自体をOFFにする。
		$this->set_slot_capacity_enabled( false );

		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 3 );

		$staff_id = $this->create_staff( 'Staff A slot-capacity-disabled-save' );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		$booking_id = $this->create_booking( $menu_id, $staff_id, 1 );

		// 定員機能がONなら通るはずの2名を送信する。
		$this->set_booking_post_data( $menu_id, $staff_id, 2 );

		$post  = get_post( $booking_id );
		$admin = new Booking_Admin();
		$admin->save_post( $booking_id, $post );

		$this->assertSame(
			1,
			(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'予約枠の定員機能OFF時はメニューの古い定員値（3）を無視して定員1扱いになり、2名は上限超過で保存が中断され既存値（1）が保持される'
		);

		$_POST = array();
	}

	/**
	 * save_post: 予約枠の定員機能OFF時、保存済み人数が2以上の既存予約でも、人数以外の項目
	 * （メモ等）を編集して保存できることを検証する（#394 レビュー対応・項目1の回帰修正の確認）。
	 *
	 * この回帰は「定員機能OFFで定員が1に倒れる → 人数編集欄が <input max="1" value="3"> になる →
	 * ブラウザのネイティブ制約検証・guests_exceeded の両方で保存が一切ブロックされる（メモ1行の
	 * 変更すら保存できない）」というもの。レンダリング側は人数編集欄自体を出さなくなる
	 * （$_POST に guests キーが含まれない）ため、その状態を実際の $_POST 構造で再現する。
	 */
	public function test_save_post_allows_editing_other_fields_when_guests_locked_by_disabled_capacity(): void {
		$this->set_nomination_enabled( false );
		// メニューに定員5が残ったままだが、予約枠の定員機能自体をOFFにする。
		$this->set_slot_capacity_enabled( false );

		$menu_id = $this->create_menu();
		update_post_meta( $menu_id, '_vkbm_base_price', 1000 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_id, '_vkbm_max_capacity', 5 );

		$staff_id = $this->create_staff( 'Staff A guests-locked-editable' );
		update_post_meta( $menu_id, '_vkbm_staff_ids', array( $staff_id ) );

		// 既存人数3で保存済みの予約（定員機能がONだった頃に複数人予約として作成されたもの）。
		$booking_id = $this->create_booking( $menu_id, $staff_id, 3 );

		// レンダリング側が人数編集欄を出さない状態（guests未送信）を再現し、メモだけを変更する。
		$this->set_booking_post_data( $menu_id, $staff_id, null );
		$_POST['vkbm_booking']['note'] = '定員機能OFF時のメモ編集確認';

		$post  = get_post( $booking_id );
		$admin = new Booking_Admin();
		$admin->save_post( $booking_id, $post );

		// 保存済みの人数（3）が保持され、上限超過エラーで保存が中断されないこと。
		$this->assertSame(
			3,
			(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'定員機能OFF時も、保存済みの人数（3）が保持され上限超過エラーにならない'
		);
		// メモの変更が反映されること（＝保存自体は中断されずに通ったことの確認）。
		$this->assertSame(
			'定員機能OFF時のメモ編集確認',
			(string) get_post_meta( $booking_id, '_vkbm_booking_note', true ),
			'人数以外の項目（メモ）の変更が保存される'
		);

		$_POST = array();
	}

	/**
	 * save_post: 「メニュー変更（例外）」で定員1の別メニューへ切り替えて保存しようとしたとき、
	 * 定員超過の検証を素通りせず、保存が中断されることを検証する（#394 再々レビュー対応・項目1）。
	 *
	 * ロック判定（$guests_locked_by_capacity）を「切替後の新メニュー」基準で行うと、定員5の
	 * メニューAで人数3の予約を定員1のメニューBへ切り替えたとき「1===1 && 3>1」で誤ってロックが
	 * 成立し、guests_exceeded の検証を素通りして人数3のまま（メニューだけBに切り替わって）
	 * 保存されてしまう（fail-open の回帰）。ロック判定は保存済み（切替前）のメニュー基準で
	 * 行う必要があり、その場合はメニューAの定員5が使われるためロックは成立せず、従来どおり
	 * 上限超過（1名までのメニューBに3名）で保存が中断されることを確認する。
	 */
	public function test_save_post_rejects_guests_exceeded_when_switching_to_lower_capacity_menu(): void {
		// 複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより検証対象の挙動が無効になる。
		// 無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '複数人予約（予約枠の定員）は Pro 版限定機能のため、無料版ではスキップする。' );
		}

		$this->set_nomination_enabled( false );

		// メニューA：定員5。
		$menu_a = $this->create_menu();
		update_post_meta( $menu_a, '_vkbm_base_price', 1000 );
		update_post_meta( $menu_a, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $menu_a, '_vkbm_max_capacity', 5 );

		// メニューB：定員1（未設定時の既定値。大多数のメニューが該当するケースを再現する）。
		$menu_b = $this->create_menu();
		update_post_meta( $menu_b, '_vkbm_base_price', 2000 );

		$staff_id = $this->create_staff( 'Staff A menu-switch-capacity' );
		update_post_meta( $menu_a, '_vkbm_staff_ids', array( $staff_id ) );
		update_post_meta( $menu_b, '_vkbm_staff_ids', array( $staff_id ) );

		// メニューAで人数3の予約を作成する（切替前の保存済み状態）。
		$booking_id = $this->create_booking( $menu_a, $staff_id, 3 );

		// 「メニュー変更（例外）」でメニューBへ切り替え、人数3のまま保存しようとする。
		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => array(
				'date'                 => '2024-06-01',
				'start_time'           => '10:00',
				'end_time'             => '10:30',
				'service_id'           => $menu_a,
				'allow_service_change' => '1',
				'service_id_select'    => $menu_b,
				'resource_id'          => $staff_id,
				'status'               => 'confirmed',
				'guests'               => 3,
			),
		);

		$post  = get_post( $booking_id );
		$admin = new Booking_Admin();
		$admin->save_post( $booking_id, $post );

		// 保存が中断され、メニュー・人数とも元のまま維持される。
		$this->assertSame(
			$menu_a,
			(int) get_post_meta( $booking_id, self::META_SERVICE_ID, true ),
			'メニュー切替で定員超過になる保存は中断され、メニューが元のまま維持される'
		);
		$this->assertSame(
			3,
			(int) get_post_meta( $booking_id, self::META_GUESTS, true ),
			'メニュー切替で定員超過になる保存は中断され、人数が元のまま維持される'
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
	 * #394 レビュー対応（項目1）: 基本設定「予約枠の定員」機能（slot_capacity_enabled）の有効・無効を
	 * 設定し、Staff_Editor のキャッシュをクリアする。
	 *
	 * @param bool $enabled 有効にする場合は true。
	 */
	private function set_slot_capacity_enabled( bool $enabled ): void {
		$repository                        = new Settings_Repository();
		$settings                          = $repository->get_settings();
		$settings['slot_capacity_enabled'] = $enabled;
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
	 * 担当スタッフは単一選択（resource_id）。1予約は分割せず単一スタッフに割り当てるため、
	 * リピーター（staff_distribution）は送信しない。
	 *
	 * #394: 人数編集欄の表示可否は「料金区分（guest_tiers）の有無」だけで決まり、指名の有無は
	 * 関係なくなったため、人数欄の送信も指名の有無で出し分けない（posted_guests が指定されていれば送信）。
	 *
	 * @param int      $menu_id       サービスメニューID。
	 * @param int      $staff_id      担当スタッフID。0 のときは未選択（担当なし）を再現するため送信しない。
	 * @param int|null $posted_guests 送信する人数。null なら人数欄を送らない（料金区分が定義された予約の再現用）。
	 */
	private function set_booking_post_data( int $menu_id, int $staff_id, ?int $posted_guests ): void {
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

		if ( null !== $posted_guests ) {
			$booking['guests'] = $posted_guests;
		}

		$_POST = array(
			'_vkbm_booking_meta_nonce' => wp_create_nonce( 'vkbm_booking_meta' ),
			'vkbm_booking'             => $booking,
		);
	}
}
