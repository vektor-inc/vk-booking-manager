<?php
/**
 * メニュー単位の指名機能トグル（_vkbm_disable_nomination）の保存処理テスト（#391 / #412）。
 *
 * Service_Menu_Editor::save_post() が、
 * - サイト全体の指名機能がONのときのみ「このメニューで指名を使う」チェックの送信値を反映すること
 * - チェックを外すと _vkbm_disable_nomination が保存され、チェックしたまま（既定）保存すると
 *   削除されること
 * - サイト全体の指名機能がOFFのときは「指名を使う」トグル自体の項目がフォームに無いため
 *   _vkbm_disable_nomination の値だけは変更しないこと（他の親スイッチOFF時の項目と同じ「保持」方針）
 * - 【最重要・#412 A-1/A-4の回帰防止】既存の予約枠の定員・複数人一括予約・料金区分・貸切設定を
 *   持つメニューで、サイト全体の指名機能を後からONにし、そのメニューを開いて「指名を使う」の
 *   チェックを外して保存しても、これらの設定値が消えたり初期化されたりしないこと。
 *   同様に、チェックを戻して「指名を使う」に切り替えて保存しても、複数人一括予約系の設定が
 *   削除されず保持されること
 * を検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\Common\Price_Tiers;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_UnitTestCase;
use function metadata_exists;
use function update_option;
use function wp_create_nonce;
use function wp_set_current_user;

/**
 * メニュー単位の指名機能トグルの保存処理テスト（#391 / #412）。
 *
 * @group admin
 * @group nomination
 */
class Service_Menu_Editor_Nomination_Toggle_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

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

		// メニュー単位の指名機能設定は Pro 版限定機能のため、無料版ビルドでは Pro 判定ゲートにより
		// 検証対象の挙動が無効になる。無料版で実行された場合はこのテストをスキップする。
		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( 'メニュー単位の指名機能設定は Pro 版限定機能のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・静的キャッシュ・ログイン状態・$_POST を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Staff_Editor::clear_nomination_enabled_cache();
		wp_set_current_user( 0 );
		$_POST = array();
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
	 * 【#412 A-1/A-4 の再現・回帰防止テスト】
	 *
	 * 安藤さんの指摘した再現手順をそのまま検証する:
	 * 1. サイト全体の指名機能OFFのサイトで、メニューに定員10・最少催行人数3・料金区分・
	 *    貸切設定を保存済み（update_post_meta で直接シードし「既に保存済み」の状態を再現する）。
	 * 2. 基本設定で指名機能をONにする（メニューはまだ保存していない）。
	 * 3. そのメニューを開き「指名を使う」のチェックを外して保存する。
	 *
	 * render_conditions_meta_box() は #412 の修正で、予約枠の定員機能がONである限り
	 * このメニューの指名可否に関わらず実フィールドを常に描画する（表示/非表示は hidden 属性のみ）。
	 * そのため、ブラウザから実際に送信される $_POST には、hidden 状態でも入力欄が保持していた
	 * 既存値（定員10 等）がそのまま含まれる。この動作を模した $_POST を組み立てて検証する。
	 *
	 * 期待値: 定員・最少催行人数・複数人一括予約許可・料金区分・貸切設定は一切変化せず、
	 * 「指名を使う」トグルだけが無効化される（A-1 の修正確認）。
	 *
	 * 続けて、チェックを戻して「指名を使う」に再設定して保存しても、複数人一括予約系の
	 * 設定が削除されず保持されることも確認する（A-4 の修正確認）。
	 */
	public function test_save_post_preserves_capacity_settings_when_toggling_nomination(): void {
		// ステップ1: サイト全体の指名機能OFF・予約枠の定員機能ONの状態で、
		// メニューに定員10・最少催行人数3・複数人一括予約許可・料金区分・貸切設定を
		// 「既に保存済み」としてシードする。
		$this->set_settings( false, true );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_max_capacity', 10 );
		update_post_meta( $menu_id, '_vkbm_min_capacity', 3 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );
		update_post_meta(
			$menu_id,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => 'Adult',
					'price' => 5000,
				),
			)
		);
		update_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true );

		$admin   = new Service_Menu_Editor();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post = get_post( $menu_id );

		// ステップ2: サイト全体の指名機能をONにする（メニューはまだ保存していない）。
		$this->set_settings( true, true );

		// ステップ3: このメニューを開き「指名を使う」のチェックを外して保存する。
		// render_conditions_meta_box() が実フィールドを常に描画するため、ブラウザは
		// hidden 状態でも保持されていた既存値（定員10 等）をそのまま送信してくる。
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => array(
				// 'use_nomination' キーは無し（チェックを外した状態）。
				'max_capacity'          => '10',
				'min_capacity'          => '3',
				'allow_multiple_guests' => '1',
				'price_tiers'           => array(
					'label' => array( 'Adult' ),
					'price' => array( '5000' ),
				),
				'exclusive_when_booked' => '1',
			),
		);
		$admin->save_post( $menu_id, $post );

		$this->assertTrue(
			(bool) get_post_meta( $menu_id, '_vkbm_disable_nomination', true ),
			'チェックを外した保存で、このメニューの指名が無効化される'
		);
		$this->assertSame(
			10,
			(int) get_post_meta( $menu_id, '_vkbm_max_capacity', true ),
			'#412 A-1: 予約枠の定員（10）が1へ初期化されずに保持される'
		);
		$this->assertSame(
			3,
			(int) get_post_meta( $menu_id, '_vkbm_min_capacity', true ),
			'#412 A-1: 最少催行人数（3）が削除されずに保持される'
		);
		$this->assertTrue(
			(bool) get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true ),
			'#412 A-1: 複数人一括予約の許可フラグが削除されずに保持される'
		);
		$stored_tiers = Price_Tiers::normalize_tiers( get_post_meta( $menu_id, '_vkbm_price_tiers', true ) );
		$this->assertNotEmpty( $stored_tiers, '#412 A-1: 料金区分が削除されずに保持される' );
		$this->assertSame( 'Adult', $stored_tiers[0]['label'], '#412 A-1: 料金区分のラベルが保持される' );
		$this->assertSame( 5000, $stored_tiers[0]['price'], '#412 A-1: 料金区分の単価が保持される' );
		$this->assertTrue(
			(bool) get_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true ),
			'#412 A-1: 貸し切り予約設定が削除されずに保持される'
		);

		// 続けて「指名を使う」へ戻して保存しても、複数人一括予約系の設定が削除されず
		// 保持されることを確認する（#412 A-4）。このとき render 側では指名OFFのため
		// 実フィールドがまだ表示されており、値は変更されないまま送信される。
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => array(
				'use_nomination'        => '1',
				'max_capacity'          => '10',
				'min_capacity'          => '3',
				'allow_multiple_guests' => '1',
				'price_tiers'           => array(
					'label' => array( 'Adult' ),
					'price' => array( '5000' ),
				),
				'exclusive_when_booked' => '1',
			),
		);
		$admin->save_post( $menu_id, $post );

		$this->assertFalse(
			(bool) get_post_meta( $menu_id, '_vkbm_disable_nomination', true ),
			'「指名を使う」へ戻す保存で、このメニューの指名無効化フラグが解除される'
		);
		$this->assertSame(
			10,
			(int) get_post_meta( $menu_id, '_vkbm_max_capacity', true ),
			'#412 A-4: 指名を使うへ戻しても予約枠の定員は保持される'
		);
		$this->assertTrue(
			(bool) get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true ),
			'#412 A-4: 指名を使うへ戻しても複数人一括予約の許可フラグが削除されず保持される（以前は削除されていた）'
		);
		$stored_tiers_after = Price_Tiers::normalize_tiers( get_post_meta( $menu_id, '_vkbm_price_tiers', true ) );
		$this->assertNotEmpty( $stored_tiers_after, '#412 A-4: 指名を使うへ戻しても料金区分が削除されず保持される（以前は削除されていた）' );
		$this->assertTrue(
			(bool) get_post_meta( $menu_id, '_vkbm_exclusive_when_booked', true ),
			'#412 A-4: 指名を使うへ戻しても貸し切り予約設定が削除されず保持される（以前は削除されていた）'
		);
	}

	/**
	 * save_post の一連の挙動を、条件と期待値の組み合わせで検証する（基本的な保存分岐の確認）。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name'      => 'サイト全体ON＋チェックを外す（use_nomination未送信） => メニュー単位で無効化して保存し、複数人一括予約設定も保存される（#391・本機能の本体）',
				'site_wide_nomination'     => true,
				'use_nomination_submitted' => false,
				'existing_menu_disabled'   => null,
				'expect_menu_disabled'     => true,
				'expect_allow_guests'      => true,
				'expect_max_capacity'      => 4,
			),
			array(
				'test_condition_name'      => 'サイト全体ON＋チェックON（既定） => メニュー単位の無効化は解除（削除）され、送信された複数人一括予約設定はそのまま保存される（#412 A-4：以前は削除されていたが保持するよう修正）',
				'site_wide_nomination'     => true,
				'use_nomination_submitted' => true,
				'existing_menu_disabled'   => true,
				'expect_menu_disabled'     => false,
				'expect_allow_guests'      => true,
				'expect_max_capacity'      => 4,
			),
			array(
				'test_condition_name'      => 'サイト全体OFF => 「指名を使う」トグル自体はフォームに項目が無いため既存値をそのまま保持しつつ、'
					. '予約枠の定員機能ONにより複数人一括予約系は通常どおり保存される（#391導入前と同じ挙動）',
				'site_wide_nomination'     => false,
				'use_nomination_submitted' => false,
				'existing_menu_disabled'   => true,
				'expect_menu_disabled'     => true,
				'expect_allow_guests'      => true,
				'expect_max_capacity'      => 4,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_settings( $case['site_wide_nomination'], true );

			$admin   = new Service_Menu_Editor();
			$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );

			$menu_id = (int) $this->factory()->post->create(
				array(
					'post_type'   => Service_Menu_Post_Type::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			if ( null !== $case['existing_menu_disabled'] ) {
				update_post_meta( $menu_id, '_vkbm_disable_nomination', $case['existing_menu_disabled'] );
			}
			$post = get_post( $menu_id );

			$service_menu = array(
				'max_capacity'          => '4',
				'allow_multiple_guests' => '1',
			);
			if ( $case['use_nomination_submitted'] ) {
				$service_menu['use_nomination'] = '1';
			}

			$_POST = array(
				self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
				'vkbm_service_menu' => $service_menu,
			);
			$admin->save_post( $menu_id, $post );

			$this->assertSame(
				$case['expect_menu_disabled'],
				(bool) get_post_meta( $menu_id, '_vkbm_disable_nomination', true ),
				$case['test_condition_name'] . ' / _vkbm_disable_nomination の値'
			);

			if ( null === $case['expect_allow_guests'] ) {
				$this->assertFalse(
					metadata_exists( 'post', $menu_id, '_vkbm_allow_multiple_guests' ),
					$case['test_condition_name'] . ' / _vkbm_allow_multiple_guests 未保存'
				);
			} else {
				$this->assertSame(
					$case['expect_allow_guests'],
					(bool) get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true ),
					$case['test_condition_name'] . ' / _vkbm_allow_multiple_guests の値'
				);
			}

			if ( null === $case['expect_max_capacity'] ) {
				$this->assertFalse(
					metadata_exists( 'post', $menu_id, '_vkbm_max_capacity' ),
					$case['test_condition_name'] . ' / _vkbm_max_capacity 未保存'
				);
			} else {
				$this->assertSame(
					$case['expect_max_capacity'],
					(int) get_post_meta( $menu_id, '_vkbm_max_capacity', true ),
					$case['test_condition_name'] . ' / _vkbm_max_capacity の値'
				);
			}

			wp_set_current_user( 0 );
		}
	}

	/**
	 * 予約枠の定員機能が親スイッチでOFFのときは、フォームに項目自体が無いため、
	 * 既存のメニュー単位設定（定員・複数人一括予約）を削除せずそのまま保持することを確認する。
	 */
	public function test_save_post_preserves_settings_when_slot_capacity_disabled(): void {
		// サイト全体：指名OFF・予約枠の定員機能OFF。
		$this->set_settings( false, false );

		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $menu_id, '_vkbm_max_capacity', 7 );
		update_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true );

		$admin   = new Service_Menu_Editor();
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post = get_post( $menu_id );

		// 予約枠の定員機能OFFのため、フォームにこれらのフィールドは無い（未送信）。
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => array(
				'catch_copy' => 'テスト',
			),
		);
		$admin->save_post( $menu_id, $post );

		$this->assertSame(
			7,
			(int) get_post_meta( $menu_id, '_vkbm_max_capacity', true ),
			'予約枠の定員機能OFF時は、フォームに項目が無いため既存の定員設定を保持する'
		);
		$this->assertTrue(
			(bool) get_post_meta( $menu_id, '_vkbm_allow_multiple_guests', true ),
			'予約枠の定員機能OFF時は、複数人一括予約の許可フラグも保持する'
		);
	}
}
