<?php
/**
 * サービスメニュー編集画面の「料金の単位」（1名あたり／1ツアーあたり）のテスト（#1002）。
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

/**
 * 料金の単位の保存・描画のテスト（#1002）。
 *
 * @group admin
 * @group price-unit
 */
class Service_Menu_Editor_Price_Unit_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/**
	 * Setup で退避する全体設定（tearDown で復元）。
	 *
	 * @var mixed
	 */
	private $original_settings = false;

	/**
	 * テスト前に全体設定を退避する。
	 */
	protected function setUp(): void {
		parent::setUp();

		// tearDown() はスキップ時にも実行されるため、退避はスキップ判定より前に行う。
		$this->original_settings = get_option( Settings_Repository::OPTION_KEY, false );

		if ( Pro_Upsell::is_free_edition() ) {
			$this->markTestSkipped( '料金の単位（1ツアーあたり）は Pro 版の業種プリセット（アクティビティ）限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・キャッシュ・ログイン状態・$_POST・お知らせを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
		delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
		wp_set_current_user( 0 );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * 業種プリセットを設定する（予約枠の定員機能は ON）。
	 *
	 * @param string $preset 保存する業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => $preset,
					'slot_capacity_enabled' => true,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();
	}

	/**
	 * 管理者でログインし、サービスメニューを1件作成する。
	 *
	 * @return \WP_Post 作成したメニュー。
	 */
	private function create_menu(): \WP_Post {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$menu_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		return get_post( $menu_id );
	}

	/**
	 * 編集画面の保存を模す。
	 *
	 * @param \WP_Post             $post         対象メニュー。
	 * @param array<string, mixed> $service_menu 送信された vkbm_service_menu。
	 */
	private function submit( \WP_Post $post, array $service_menu ): void {
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => $service_menu,
		);
		( new Service_Menu_Editor() )->save_post( $post->ID, $post );
	}

	/**
	 * 条件メタボックスの描画結果を返す。
	 *
	 * @param \WP_Post $post 対象メニュー。
	 * @return string
	 */
	private function render( \WP_Post $post ): string {
		ob_start();
		( new Service_Menu_Editor() )->render_conditions_meta_box( $post );
		return (string) ob_get_clean();
	}

	/**
	 * 指定した ID の行（tr）の開始タグを返す。行が無ければ null。
	 *
	 * @param string $output 描画結果。
	 * @param string $row_id 行の id 属性。
	 * @return string|null
	 */
	private function get_row_tag( string $output, string $row_id ): ?string {
		return 1 === preg_match( '/<tr id="' . preg_quote( $row_id, '/' ) . '"[^>]*>/', $output, $matches ) ? $matches[0] : null;
	}

	/**
	 * アクティビティのサイトで「1ツアーあたり」を送信するときの送信値を組み立てる。
	 *
	 * @param array<string, mixed> $override 上書きする値（null を渡した項目は送信しない）。
	 * @return array<string, mixed>
	 */
	private function build_submission( array $override = array() ): array {
		$submission = array_merge(
			array(
				'price_unit'            => 'per_booking',
				'base_price'            => '30000',
				'max_capacity'          => '8',
				'allow_multiple_guests' => '1',
				'price_tiers'           => array(
					'label' => array( '大人', '子供' ),
					'price' => array( '5000', '3000' ),
				),
			),
			$override
		);

		return array_filter(
			$submission,
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * save_post: 料金の単位の保存規則と、1ツアーあたりの連動（料金区分・予約者による貸切指定の削除）を検証する。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクティビティ・1ツアーあたり => per_booking を保存し、料金区分を削除する',
				'preset'              => 'activity',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_price_unit'  => 'per_booking',
					'_vkbm_price_tiers' => null,
					'_vkbm_base_price'  => '30000',
				),
			),
			array(
				'test_condition_name' => 'アクティビティ・1名あたり => メタを保存しない（保存なし＝1名あたり）。料金区分は従来どおり保存する',
				'preset'              => 'activity',
				'submitted'           => array( 'price_unit' => 'per_guest' ),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_price_unit'  => null,
					'_vkbm_price_tiers' => array(
						array(
							'label' => '大人',
							'price' => 5000,
						),
						array(
							'label' => '子供',
							'price' => 3000,
						),
					),
				),
			),
			array(
				'test_condition_name' => '1ツアーあたりから1名あたりへ戻して保存 => メタを削除する',
				'preset'              => 'activity',
				'submitted'           => array( 'price_unit' => 'per_guest' ),
				'initial_meta'        => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => array( '_vkbm_price_unit' => null ),
			),
			array(
				'test_condition_name' => '不正な値 => 1名あたり扱いでメタを保存しない',
				'preset'              => 'activity',
				'submitted'           => array( 'price_unit' => 'per_room' ),
				'initial_meta'        => array(),
				'expected'            => array( '_vkbm_price_unit' => null ),
			),
			array(
				'test_condition_name' => '料金の単位の欄が送信されない保存 => 保存済みの per_booking を消さない',
				'preset'              => 'activity',
				'submitted'           => array( 'price_unit' => null ),
				'initial_meta'        => array( '_vkbm_price_unit' => 'per_booking' ),
				'expected'            => array( '_vkbm_price_unit' => 'per_booking' ),
			),
			array(
				'test_condition_name' => 'アクティビティ以外のサイト（custom）=> 送信されても保存しない',
				'preset'              => 'custom',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array( '_vkbm_price_unit' => null ),
			),
			array(
				'test_condition_name' => 'サロンのサイト => 送信されても保存しない',
				'preset'              => 'salon',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array( '_vkbm_price_unit' => null ),
			),
			array(
				'test_condition_name' => '宿泊のサイト => 送信されても保存しない（宿泊の料金の単位とは別の項目）',
				'preset'              => 'lodging',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array( '_vkbm_price_unit' => null ),
			),
			array(
				'test_condition_name' => 'アクティビティ・1ツアーあたり => 予約者による貸切指定とその貸し切り料金を削除する',
				'preset'              => 'activity',
				'submitted'           => array(
					'exclusive_user_selectable'   => '1',
					'exclusive_fee_per_person'    => '500',
					'exclusive_fee_exempt_guests' => '4',
				),
				'initial_meta'        => array(
					'_vkbm_exclusive_user_selectable'   => true,
					'_vkbm_exclusive_fee_per_person'    => 500,
					'_vkbm_exclusive_fee_exempt_guests' => 4,
				),
				'expected'            => array(
					'_vkbm_price_unit'                  => 'per_booking',
					'_vkbm_exclusive_user_selectable'   => null,
					'_vkbm_exclusive_fee_per_person'    => null,
					'_vkbm_exclusive_fee_exempt_guests' => null,
				),
			),
			array(
				'test_condition_name' => '1ツアーあたりの保存でも、貸し切り予約の保存値は書き換えない（1名あたりへ戻したときに復帰できる）',
				'preset'              => 'activity',
				'submitted'           => array( 'exclusive_when_booked' => '1' ),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_price_unit'            => 'per_booking',
					'_vkbm_exclusive_when_booked' => '1',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			foreach ( $case['initial_meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}

			$this->submit( $post, $this->build_submission( $case['submitted'] ) );

			foreach ( $case['expected'] as $meta_key => $expected ) {
				$actual = metadata_exists( 'post', $post->ID, $meta_key ) ? get_post_meta( $post->ID, $meta_key, true ) : null;
				$this->assertEquals( $expected, $actual, $case['test_condition_name'] . ': ' . $meta_key );
			}
		}
	}

	/**
	 * save_post: 1ツアーあたりで保存して料金区分を削除したときだけ、管理画面の上部で知らせる。
	 */
	public function test_save_post_notice_when_price_tiers_are_deleted(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '保存前に料金区分があった => 削除したことを知らせる',
				'tiers_before'        => true,
				'price_unit'          => 'per_booking',
				'expect_notice'       => true,
			),
			array(
				'test_condition_name' => '保存前に料金区分が無い => 知らせない',
				'tiers_before'        => false,
				'price_unit'          => 'per_booking',
				'expect_notice'       => false,
			),
			array(
				'test_condition_name' => '1名あたりで保存 => 知らせない（区分は消さない）',
				'tiers_before'        => true,
				'price_unit'          => 'per_guest',
				'expect_notice'       => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'activity' );
			$post = $this->create_menu();
			delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
			if ( $case['tiers_before'] ) {
				update_post_meta(
					$post->ID,
					'_vkbm_price_tiers',
					array(
						array(
							'label' => '大人',
							'price' => 5000,
						),
					)
				);
			}

			$submission = $this->build_submission( array( 'price_unit' => $case['price_unit'] ) );
			if ( ! $case['tiers_before'] ) {
				unset( $submission['price_tiers'] );
			}
			$this->submit( $post, $submission );

			$notice = get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
			if ( $case['expect_notice'] ) {
				$this->assertIsString( $notice, $case['test_condition_name'] );
				$this->assertStringContainsString( __( 'The price unit is "Per tour", so the price categories were deleted.', 'vk-booking-manager' ), $notice, $case['test_condition_name'] );
			} else {
				$this->assertFalse( $notice, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * 1ツアーあたりのメニューは、保存後に複数人一括予約・貸し切り予約が常に有効として扱われ、料金区分は使われない。
	 */
	public function test_per_booking_menu_effective_settings(): void {
		$this->set_site( 'activity' );
		$post = $this->create_menu();

		// 「複数人一括予約を許可」のチェックを送信しなくても（行を隠しているため値が変わらない画面でも）有効として扱われる。
		$this->submit( $post, $this->build_submission( array( 'allow_multiple_guests' => null ) ) );

		$this->assertTrue( Service_Menu_Post_Type::is_price_per_booking( $post->ID ), '1ツアーあたりが効く' );
		$this->assertTrue( Service_Menu_Post_Type::is_multi_guest_booking_enabled( $post->ID ), '複数人一括予約は常に有効扱い' );
		$this->assertTrue( Service_Menu_Post_Type::is_exclusive_when_booked_setting_on( $post->ID ), '貸し切り予約は自動で有効扱い' );
		$this->assertFalse( metadata_exists( 'post', $post->ID, '_vkbm_exclusive_when_booked' ), '貸し切り予約の保存値は書き換えない' );
	}

	/**
	 * render_conditions_meta_box: 料金の単位の行はアクティビティのサイト（Pro 版）にだけ出る。
	 */
	public function test_render_price_unit_row_only_for_activity_site(): void {
		$test_cases = array(
			array(
				'test_condition_name' => 'アクティビティ => 行と基本料金の単位表記が出る',
				'preset'              => 'activity',
				'expect_row'          => true,
			),
			array(
				'test_condition_name' => 'カスタム => 出ない',
				'preset'              => 'custom',
				'expect_row'          => false,
			),
			array(
				'test_condition_name' => 'サロン => 出ない',
				'preset'              => 'salon',
				'expect_row'          => false,
			),
			array(
				'test_condition_name' => 'セミナー => 出ない',
				'preset'              => 'seminar',
				'expect_row'          => false,
			),
			array(
				'test_condition_name' => '宿泊 => 出ない（宿泊の料金の単位の行は別）',
				'preset'              => 'lodging',
				'expect_row'          => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post   = $this->create_menu();
			$output = $this->render( $post );

			$this->assertSame( $case['expect_row'], null !== $this->get_row_tag( $output, 'vkbm-price-unit-field' ), $case['test_condition_name'] . ' / 料金の単位の行' );
			$this->assertSame( $case['expect_row'], str_contains( $output, 'id="vkbm-base-price-slot-unit"' ), $case['test_condition_name'] . ' / 基本料金の単位表記' );
			$this->assertSame( $case['expect_row'], str_contains( $output, 'name="vkbm_service_menu[price_unit]"' ), $case['test_condition_name'] . ' / ラジオ' );
		}
	}

	/**
	 * render_conditions_meta_box: 1ツアーあたりのメニューでは、連動する行が隠れ、ラベル・単位表記が切り替わる。
	 */
	public function test_render_per_booking_linkage(): void {
		$this->set_site( 'activity' );
		$post = $this->create_menu();
		update_post_meta( $post->ID, '_vkbm_max_capacity', 8 );
		update_post_meta( $post->ID, '_vkbm_allow_multiple_guests', true );
		update_post_meta(
			$post->ID,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '大人',
					'price' => 5000,
				),
			)
		);
		update_post_meta( $post->ID, '_vkbm_exclusive_user_selectable', true );

		// 1名あたり（保存なし）。
		$per_guest = $this->render( $post );
		$this->assertStringNotContainsString( 'value="per_booking" checked', preg_replace( '/\s+/', ' ', $per_guest ) ?? '', '1名あたりが選択されている' );
		$this->assertStringContainsString( __( '/ 1 guest', 'vk-booking-manager' ), $per_guest, '単位表記は「/ 1名」' );
		$this->assertStringNotContainsString( __( 'Maximum guests per tour', 'vk-booking-manager' ), $per_guest, '最大人数のラベルは変わらない' );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_row_tag( $per_guest, 'vkbm-allow-multiple-guests-field' ), '複数人一括予約の行は出る' );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_row_tag( $per_guest, 'vkbm-price-tiers-field' ), '料金区分の行は出る' );

		// 1ツアーあたり。
		update_post_meta( $post->ID, '_vkbm_price_unit', 'per_booking' );
		$per_booking = $this->render( $post );
		$this->assertMatchesRegularExpression( '/value="per_booking"\s+checked/', $per_booking, '1ツアーあたりが選択されている' );
		$this->assertStringContainsString( __( '/ 1 tour', 'vk-booking-manager' ), $per_booking, '単位表記は「/ 1ツアー」' );
		$this->assertStringContainsString( __( 'Maximum guests per tour', 'vk-booking-manager' ), $per_booking, '最大人数のラベルは「1ツアーの最大人数」' );
		$this->assertStringContainsString( __( 'The price does not change even if the number of guests is entered.', 'vk-booking-manager' ), $per_booking, '説明文に「人数を入力しても料金は変わりません。」' );
		$this->assertStringContainsString( 'hidden', (string) $this->get_row_tag( $per_booking, 'vkbm-allow-multiple-guests-field' ), '複数人一括予約の行は隠す' );
		$this->assertStringContainsString( 'hidden', (string) $this->get_row_tag( $per_booking, 'vkbm-exclusive-when-booked-field' ), '貸し切り予約の行は隠す' );
		$this->assertStringContainsString( 'hidden', (string) $this->get_row_tag( $per_booking, 'vkbm-exclusive-user-selectable-field' ), '予約者による貸切指定（と貸切料金）の行は隠す' );
		$this->assertStringContainsString( 'hidden', (string) $this->get_row_tag( $per_booking, 'vkbm-price-tiers-field' ), '料金区分の行は隠す' );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_row_tag( $per_booking, 'vkbm-min-capacity-field' ), '最少催行人数の行は残す（貸し切りが有効なので「最低申し込み人数」の表記になる）' );
		$this->assertStringContainsString( __( 'Minimum guests to accept a booking', 'vk-booking-manager' ), $per_booking, '最少人数の欄は「最低申し込み人数」の表記' );
	}

	/**
	 * 無料版・予約枠の定員機能が OFF の編集画面では、料金の単位の行を出さない（保存値には触れない）。
	 */
	public function test_render_price_unit_row_hidden_when_slot_capacity_is_off(): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge(
				( new Settings_Repository() )->get_default_settings(),
				array(
					'industry_preset'       => 'activity',
					'slot_capacity_enabled' => false,
				)
			)
		);
		Staff_Editor::clear_nomination_enabled_cache();

		$post = $this->create_menu();
		update_post_meta( $post->ID, '_vkbm_price_unit', 'per_booking' );

		$output = $this->render( $post );
		$this->assertNull( $this->get_row_tag( $output, 'vkbm-price-unit-field' ), '定員機能が OFF のときは料金の単位の行を出さない' );

		// 行が無い画面から保存しても、保存済みの選択は消えない。
		$this->submit( $post, $this->build_submission( array( 'price_unit' => null ) ) );
		$this->assertSame( 'per_booking', get_post_meta( $post->ID, '_vkbm_price_unit', true ), '保存済みの選択は消えない' );
	}
}
