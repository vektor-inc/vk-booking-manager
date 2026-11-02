<?php
/**
 * サービスメニュー編集画面の「1ツアーあたり」で規定人数を超えた分の追加料金（保存項目・丸めと通知・描画）のテスト（#1003）。
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
 * 追加料金の保存・描画のテスト（#1003）。
 *
 * @group admin
 * @group price-unit
 * @group extra-guest
 */
class Service_Menu_Editor_Extra_Guest_Test extends WP_UnitTestCase {

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
			$this->markTestSkipped( '追加料金（1ツアーあたり）は Pro 版の業種プリセット（アクティビティ）限定のため、無料版ではスキップする。' );
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
		delete_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
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
	 * 指定した ID の要素（tr / div）の開始タグを返す。要素が無ければ null。
	 *
	 * @param string $output 描画結果。
	 * @param string $tag    タグ名。
	 * @param string $id     id 属性。
	 * @return string|null
	 */
	private function get_open_tag( string $output, string $tag, string $id ): ?string {
		return 1 === preg_match( '/<' . $tag . ' id="' . preg_quote( $id, '/' ) . '"[^>]*>/', $output, $matches ) ? $matches[0] : null;
	}

	/**
	 * 「1ツアーあたり」で追加料金を使う送信値を組み立てる。
	 *
	 * @param array<string, mixed> $override 上書きする値（null を渡した項目は送信しない）。
	 * @return array<string, mixed>
	 */
	private function build_submission( array $override = array() ): array {
		$submission = array_merge(
			array(
				'price_unit'            => 'per_booking',
				'extra_guest_enabled'   => '1',
				'included_guests'       => '4',
				'base_price'            => '30000',
				'max_capacity'          => '8',
				'allow_multiple_guests' => '1',
				'price_tiers'           => array(
					'label' => array( '大人', '子供' ),
					'price' => array( '3000', '1500' ),
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
	 * 保存済みのメタを返す。メタが無ければ null。
	 *
	 * @param int    $post_id  投稿 ID。
	 * @param string $meta_key メタキー。
	 * @return mixed
	 */
	private function get_meta_or_null( int $post_id, string $meta_key ) {
		return metadata_exists( 'post', $post_id, $meta_key ) ? get_post_meta( $post_id, $meta_key, true ) : null;
	}

	/**
	 * save_post: 追加料金の保存規則（有効フラグ・含まれる人数・料金区分）。
	 */
	public function test_save_post(): void {
		$two_tiers = array(
			array(
				'label' => '大人',
				'price' => 3000,
			),
			array(
				'label' => '子供',
				'price' => 1500,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '1ツアーあたり・追加料金あり => 有効フラグ 1・含まれる人数 4・料金区分を超過分の単価として保存する',
				'preset'              => 'activity',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_price_unit'          => 'per_booking',
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 4,
					'_vkbm_price_tiers'         => $two_tiers,
					'_vkbm_base_price'          => '30000',
				),
			),
			array(
				'test_condition_name' => '「複数人一括予約を許可」のチェックが外れて送られても（行を隠しているため） => 料金区分は保存する',
				'preset'              => 'activity',
				'submitted'           => array( 'allow_multiple_guests' => null ),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_price_tiers'         => $two_tiers,
				),
			),
			array(
				'test_condition_name' => '追加料金なし（チェックなし） => 有効フラグを削除し、料金区分も削除する。含まれる人数は入力値を保持する',
				'preset'              => 'activity',
				'submitted'           => array( 'extra_guest_enabled' => null ),
				'initial_meta'        => array(
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_price_tiers'         => array(
						array(
							'label' => '大人',
							'price' => 3000,
						),
					),
				),
				'expected'            => array(
					'_vkbm_extra_guest_enabled' => null,
					'_vkbm_price_tiers'         => null,
					'_vkbm_included_guests'     => 4,
				),
			),
			array(
				'test_condition_name' => '含まれる人数が最大人数（8）を超える => 8 に丸めて保存する',
				'preset'              => 'activity',
				'submitted'           => array( 'included_guests' => '12' ),
				'initial_meta'        => array(),
				'expected'            => array( '_vkbm_included_guests' => 8 ),
			),
			array(
				'test_condition_name' => '含まれる人数が 0 以下・空欄 => 1 にする',
				'preset'              => 'activity',
				'submitted'           => array( 'included_guests' => '0' ),
				'initial_meta'        => array(),
				'expected'            => array( '_vkbm_included_guests' => 1 ),
			),
			array(
				'test_condition_name' => '1名あたりへ切り替えて保存（チェック付きのまま） => 有効フラグと人数は保持し、区分は従来どおり保存する（追加料金としては効かない）',
				'preset'              => 'activity',
				'submitted'           => array( 'price_unit' => 'per_guest' ),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_price_unit'          => null,
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 4,
					'_vkbm_price_tiers'         => $two_tiers,
				),
			),
			array(
				'test_condition_name' => '料金の単位の欄が送信されない保存 => 保存済みの追加料金の設定を消さない',
				'preset'              => 'activity',
				'submitted'           => array(
					'price_unit'          => null,
					'extra_guest_enabled' => null,
					'included_guests'     => null,
				),
				'initial_meta'        => array(
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 6,
				),
				'expected'            => array(
					'_vkbm_extra_guest_enabled' => '1',
					'_vkbm_included_guests'     => 6,
				),
			),
			array(
				'test_condition_name' => 'アクティビティ以外のサイト（custom）=> 送信されても保存しない',
				'preset'              => 'custom',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_extra_guest_enabled' => null,
					'_vkbm_included_guests'     => null,
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト => 送信されても保存しない（宿泊の追加料金とは別の項目）',
				'preset'              => 'lodging',
				'submitted'           => array(),
				'initial_meta'        => array(),
				'expected'            => array(
					'_vkbm_extra_guest_enabled' => null,
					'_vkbm_included_guests'     => null,
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
				$this->assertEquals( $expected, $this->get_meta_or_null( $post->ID, $meta_key ), $case['test_condition_name'] . ': ' . $meta_key );
			}
		}
	}

	/**
	 * save_post: 含まれる人数を最大人数に丸めたときと、追加料金を使うのに料金区分が0件のときだけ、管理画面の上部で知らせる。
	 */
	public function test_save_post_notices(): void {
		$rounded_message = sprintf(
			/* translators: %d: maximum number of guests per tour (the number of guests included in the basic price was reduced to this value). */
			__( 'The number of guests included in the basic price exceeded the maximum guests per tour, so it was reduced to %d.', 'vk-booking-manager' ),
			8
		);
		$no_tier_message = __( 'No price category is entered, so the extra guest fee is not added to the price. Enter at least one price category to charge the extra guest fee.', 'vk-booking-manager' );

		$test_cases = array(
			array(
				'test_condition_name' => '最大人数を超える人数 => 丸めたことを知らせる',
				'submitted'           => array( 'included_guests' => '12' ),
				'expect_rounded'      => true,
				'expect_no_tier'      => false,
			),
			array(
				'test_condition_name' => '最大人数以内 => 知らせない',
				'submitted'           => array( 'included_guests' => '8' ),
				'expect_rounded'      => false,
				'expect_no_tier'      => false,
			),
			array(
				'test_condition_name' => '追加料金なしで最大人数を超える人数 => 知らせない（使わない設定のため）',
				'submitted'           => array(
					'included_guests'     => '12',
					'extra_guest_enabled' => null,
				),
				'expect_rounded'      => false,
				'expect_no_tier'      => false,
			),
			array(
				'test_condition_name' => '追加料金ありで料金区分が0件 => 追加料金が加算されない旨を知らせる（保存は止めない）',
				'submitted'           => array( 'price_tiers' => null ),
				'expect_rounded'      => false,
				'expect_no_tier'      => true,
			),
			array(
				'test_condition_name' => '追加料金なしで料金区分が0件 => 知らせない',
				'submitted'           => array(
					'price_tiers'         => null,
					'extra_guest_enabled' => null,
				),
				'expect_rounded'      => false,
				'expect_no_tier'      => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( 'activity' );
			$post = $this->create_menu();

			$this->submit( $post, $this->build_submission( $case['submitted'] ) );

			$notice = (string) get_transient( 'vkbm_reservation_day_notice_' . get_current_user_id() );
			$this->assertSame( $case['expect_rounded'], str_contains( $notice, $rounded_message ), $case['test_condition_name'] . ' / 丸めの通知' );
			$this->assertSame( $case['expect_no_tier'], str_contains( $notice, $no_tier_message ), $case['test_condition_name'] . ' / 料金区分が無い通知' );
			$this->assertSame( '30000', get_post_meta( $post->ID, '_vkbm_base_price', true ), $case['test_condition_name'] . ' / 保存は止めない' );
		}
	}

	/**
	 * 実効値: 追加料金は料金の単位が「1ツアーあたり」のときだけ効く。
	 */
	public function test_effective_extra_guest_fee(): void {
		$this->set_site( 'activity' );
		$post = $this->create_menu();
		$this->submit( $post, $this->build_submission() );

		$this->assertTrue( Service_Menu_Post_Type::is_extra_guest_fee_enabled( $post->ID ), '1ツアーあたり・チェックあり => 効く' );
		$this->assertSame( 4, Service_Menu_Post_Type::get_included_guests( $post->ID ), '含まれる人数' );
		$this->assertTrue( Service_Menu_Post_Type::allows_guest_tier_input( $post->ID ), '区分ごとの人数を受け取れる' );
		$this->assertSame(
			array(
				'enabled'         => true,
				'included_guests' => 4,
			),
			Service_Menu_Post_Type::get_extra_guest_fee_rest_value( $post->ID ),
			'REST 用の実効値'
		);

		// 1名あたりへ戻すと、メタが残っていても効かない。
		delete_post_meta( $post->ID, '_vkbm_price_unit' );
		$this->assertFalse( Service_Menu_Post_Type::is_extra_guest_fee_enabled( $post->ID ), '1名あたり => 効かない' );
		$this->assertTrue( Service_Menu_Post_Type::allows_guest_tier_input( $post->ID ), '1名あたりは区分ごとの人数を受け取れる' );
		$this->assertFalse( Service_Menu_Post_Type::get_extra_guest_fee_rest_value( $post->ID )['enabled'], '1名あたりの REST 用の実効値は無効' );

		// 追加料金を使うのに料金区分が0件のメニューは、画面では無効として扱う。
		update_post_meta( $post->ID, '_vkbm_price_unit', 'per_booking' );
		delete_post_meta( $post->ID, '_vkbm_price_tiers' );
		$this->assertTrue( Service_Menu_Post_Type::is_extra_guest_fee_enabled( $post->ID ), '設定は有効のまま' );
		$this->assertFalse( Service_Menu_Post_Type::get_extra_guest_fee_rest_value( $post->ID )['enabled'], '区分が0件 => 画面では無効' );

		// 追加料金なしの1ツアーあたりは、区分ごとの人数を受け取らない。
		delete_post_meta( $post->ID, '_vkbm_extra_guest_enabled' );
		$this->assertFalse( Service_Menu_Post_Type::allows_guest_tier_input( $post->ID ), '追加料金なしの1ツアーあたり => 受け取らない' );
	}

	/**
	 * render_conditions_meta_box: 追加料金の欄は「1ツアーあたり」のときだけ出て、保存済みの選択と人数を表示する。
	 */
	public function test_render_extra_guest_fields(): void {
		$this->set_site( 'activity' );
		$post = $this->create_menu();
		update_post_meta( $post->ID, '_vkbm_max_capacity', 8 );
		update_post_meta( $post->ID, '_vkbm_extra_guest_enabled', '1' );
		update_post_meta( $post->ID, '_vkbm_included_guests', 4 );
		update_post_meta(
			$post->ID,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '大人',
					'price' => 3000,
				),
			)
		);

		// 1名あたり（保存なし）: 欄は DOM に残すが隠す。
		$per_guest = $this->render( $post );
		$this->assertStringContainsString( 'hidden', (string) $this->get_open_tag( $per_guest, 'div', 'vkbm-extra-guest-fields' ), '1名あたりは追加料金の欄を隠す' );
		$this->assertStringContainsString( 'name="vkbm_service_menu[included_guests]"', $per_guest, '含まれる人数の入力は DOM に残す' );

		// 1ツアーあたり・追加料金あり: 欄を出し、保存済みの選択と人数を表示する。
		update_post_meta( $post->ID, '_vkbm_price_unit', 'per_booking' );
		$per_booking = $this->render( $post );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_open_tag( $per_booking, 'div', 'vkbm-extra-guest-fields' ), '追加料金の欄を出す' );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_open_tag( $per_booking, 'div', 'vkbm-included-guests-field' ), '追加料金ありは含まれる人数の欄を出す' );
		$this->assertMatchesRegularExpression( '/id="vkbm_service_menu_extra_guest_enabled"[^>]*checked/', $per_booking, 'チェックが入っている' );
		$this->assertMatchesRegularExpression( '/name="vkbm_service_menu\[included_guests\]"[^>]*value="4"/', $per_booking, '含まれる人数 4' );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_open_tag( $per_booking, 'tr', 'vkbm-price-tiers-field' ), '料金区分の行を出す' );
		$this->assertStringContainsString( __( 'Additional fee per extra guest', 'vk-booking-manager' ), $per_booking, '区分の列見出しは「超過1名あたりの追加料金」' );
		$this->assertStringContainsString( __( 'Enter the fee added for each guest over the number included in the basic price.', 'vk-booking-manager' ), $per_booking, '追加料金用の説明文' );
		$this->assertStringContainsString( __( 'The price does not change with the number of people up to the number included in the basic price.', 'vk-booking-manager' ), $per_booking, '最大人数の説明文に「含まれる人数までは料金が変わらない」' );
		$this->assertDoesNotMatchRegularExpression( '/id="vkbm_service_menu_base_price"[^>]*readonly/', $per_booking, '基本料金は区分で上書きされない' );

		// 1ツアーあたり・追加料金なし: 含まれる人数の欄と料金区分の行は隠す。
		delete_post_meta( $post->ID, '_vkbm_extra_guest_enabled' );
		$no_extra = $this->render( $post );
		$this->assertStringNotContainsString( 'hidden', (string) $this->get_open_tag( $no_extra, 'div', 'vkbm-extra-guest-fields' ), '追加料金のチェックは出す' );
		$this->assertStringContainsString( 'hidden', (string) $this->get_open_tag( $no_extra, 'div', 'vkbm-included-guests-field' ), '追加料金なしは含まれる人数の欄を隠す' );
		$this->assertStringContainsString( 'hidden', (string) $this->get_open_tag( $no_extra, 'tr', 'vkbm-price-tiers-field' ), '追加料金なしは料金区分の行を隠す' );
	}

	/**
	 * 追加料金の欄は、アクティビティ以外のサイトには出さない。
	 */
	public function test_render_extra_guest_fields_only_for_activity_site(): void {
		$test_cases = array(
			array(
				'preset'     => 'activity',
				'expect_row' => true,
			),
			array(
				'preset'     => 'custom',
				'expect_row' => false,
			),
			array(
				'preset'     => 'salon',
				'expect_row' => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post   = $this->create_menu();
			$output = $this->render( $post );

			$this->assertSame( $case['expect_row'], str_contains( $output, 'id="vkbm-extra-guest-fields"' ), $case['preset'] . ' / 追加料金の欄' );
		}
	}
}
