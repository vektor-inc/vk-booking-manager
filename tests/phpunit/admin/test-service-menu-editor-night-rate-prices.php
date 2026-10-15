<?php
/**
 * サービスメニュー編集画面の、泊のプランの休前日・特定日の料金（基本料金と料金区分）のテスト（#737）。
 *
 * 宿泊の機能を使うサイト（Pro 版かつ業種プリセットが宿泊）でだけ欄を描画・保存し、
 * それ以外では入力済みの値を消さないことを検証する。
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
 * 休前日・特定日の料金の保存・描画のテスト。
 *
 * @group admin
 * @group lodging
 */
class Service_Menu_Editor_Night_Rate_Prices_Test extends WP_UnitTestCase {

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
			$this->markTestSkipped( '休前日・特定日の料金は Pro 版の業種プリセット（宿泊）限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・キャッシュ・ログイン状態・$_POST を元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		Staff_Editor::clear_nomination_enabled_cache();
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
	 * 泊の予約単位・複数人一括予約・料金区分を含む送信値を組み立てる。
	 *
	 * @param array<string, mixed> $override 上書きする値。
	 * @return array<string, mixed>
	 */
	private function build_submission( array $override ): array {
		return array_merge(
			array(
				'booking_unit'          => 'night',
				'night_price_unit'      => 'per_guest',
				'max_capacity'          => '4',
				'allow_multiple_guests' => '1',
				'base_price'            => '10000',
				'price_tiers'           => array(
					'label' => array( '大人', '子供' ),
					'price' => array( '12000', '6000' ),
				),
			),
			$override
		);
	}

	/**
	 * save_post: 基本料金の休前日・特定日の保存規則を検証する。
	 */
	public function test_save_post_night_base_prices(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト: 休前日・特定日の料金を送ると整数で保存される',
				'preset'              => 'lodging',
				'initial_meta'        => array(),
				'submitted'           => array(
					'night_price_pre_holiday' => '15000',
					'night_price_special'     => ' 20000 ',
				),
				'expected'            => array(
					'_vkbm_night_price_pre_holiday' => '15000',
					'_vkbm_night_price_special'     => '20000',
					'_vkbm_base_price'              => '10000',
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト: 空欄で送るとメタが削除される（平日の料金で計算される）',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_night_price_pre_holiday' => 15000,
					'_vkbm_night_price_special'     => 20000,
				),
				'submitted'           => array(
					'night_price_pre_holiday' => '',
					'night_price_special'     => '',
				),
				'expected'            => array(
					'_vkbm_night_price_pre_holiday' => null,
					'_vkbm_night_price_special'     => null,
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト: 0 は 0 円として保存し、負数・数値でない値は空欄として削除する',
				'preset'              => 'lodging',
				'initial_meta'        => array( '_vkbm_night_price_special' => 20000 ),
				'submitted'           => array(
					'night_price_pre_holiday' => '0',
					'night_price_special'     => 'abc',
				),
				'expected'            => array(
					'_vkbm_night_price_pre_holiday' => '0',
					'_vkbm_night_price_special'     => null,
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト: 欄が送られない保存では既存の値に触れない',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_night_price_pre_holiday' => 15000,
					'_vkbm_night_price_special'     => 20000,
				),
				'submitted'           => array(),
				'expected'            => array(
					'_vkbm_night_price_pre_holiday' => '15000',
					'_vkbm_night_price_special'     => '20000',
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）: 欄が送られても保存せず、入力済みの値も消さない',
				'preset'              => 'custom',
				'initial_meta'        => array( '_vkbm_night_price_pre_holiday' => 15000 ),
				'submitted'           => array(
					'night_price_pre_holiday' => '99999',
					'night_price_special'     => '88888',
				),
				'expected'            => array(
					'_vkbm_night_price_pre_holiday' => '15000',
					'_vkbm_night_price_special'     => null,
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
	 * save_post: 料金区分の休前日・特定日の料金の保存規則を検証する。
	 */
	public function test_save_post_price_tiers(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト: 区分ごとの休前日・特定日の料金が保存される。空欄の欄はキーを持たない',
				'preset'              => 'lodging',
				'price_tiers'         => array(
					'label'             => array( '大人', '子供' ),
					'price'             => array( '12000', '6000' ),
					'pre_holiday_price' => array( '15000', '' ),
					'special_price'     => array( '', '9000' ),
				),
				'expected'            => array(
					array(
						'label'             => '大人',
						'price'             => 12000,
						'pre_holiday_price' => 15000,
					),
					array(
						'label'         => '子供',
						'price'         => 6000,
						'special_price' => 9000,
					),
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト: 休前日・特定日の欄が無い送信は今までと同じ形で保存される',
				'preset'              => 'lodging',
				'price_tiers'         => array(
					'label' => array( '大人' ),
					'price' => array( '12000' ),
				),
				'expected'            => array(
					array(
						'label' => '大人',
						'price' => 12000,
					),
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）: 非表示の欄（hidden）から送り直された値もそのまま保存される',
				'preset'              => 'custom',
				'price_tiers'         => array(
					'label'             => array( '大人' ),
					'price'             => array( '12000' ),
					'pre_holiday_price' => array( '15000' ),
					'special_price'     => array( '' ),
				),
				'expected'            => array(
					array(
						'label'             => '大人',
						'price'             => 12000,
						'pre_holiday_price' => 15000,
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();

			$this->submit( $post, $this->build_submission( array( 'price_tiers' => $case['price_tiers'] ) ) );

			$this->assertSame( $case['expected'], get_post_meta( $post->ID, '_vkbm_price_tiers', true ), $case['test_condition_name'] );
		}
	}

	/**
	 * render_conditions_meta_box: 休前日・特定日の欄の描画（宿泊のサイトだけ）と、空欄に出す平日の金額・説明文を検証する。
	 */
	public function test_render_conditions_meta_box(): void {
		$tiers = array(
			array(
				'label'             => '大人',
				'price'             => 12000,
				'pre_holiday_price' => 15000,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト・泊: 基本料金と料金区分に休前日・特定日の欄が出る。空欄には平日の金額を灰色のプレースホルダーで出す',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'meta'                => array(
					'_vkbm_base_price'              => 10000,
					'_vkbm_night_price_pre_holiday' => 15000,
				),
				'contains'            => array(
					'name="vkbm_service_menu[night_price_pre_holiday]"',
					'name="vkbm_service_menu[night_price_special]"',
					'value="15000"',
					'placeholder="10000"',
					'name="vkbm_service_menu[price_tiers][pre_holiday_price][]"',
					'name="vkbm_service_menu[price_tiers][special_price][]"',
					'placeholder="12000"',
					'If the pre-holiday price is empty, the regular price is used. If the special date price is empty, the night is priced as a pre-holiday or regular night.',
					'tab=system#vkbm-lodging-rate-row',
					'Pre-holidays and special dates on the General Settings page',
				),
				'not_contains'        => array( 'type="hidden" name="vkbm_service_menu[price_tiers][pre_holiday_price][]"' ),
				'extra_fields_hidden' => false,
			),
			array(
				'test_condition_name' => '宿泊のサイト・時間枠: 欄は描画するが hidden（値は残して送る）',
				'preset'              => 'lodging',
				'unit'                => '',
				'meta'                => array( '_vkbm_base_price' => 10000 ),
				'contains'            => array(
					'name="vkbm_service_menu[night_price_pre_holiday]"',
					'name="vkbm_service_menu[price_tiers][special_price][]"',
				),
				'not_contains'        => array(),
				'extra_fields_hidden' => true,
			),
			array(
				'test_condition_name' => '宿泊以外のサイト: 欄も説明文も出さず、入力済みの区分の休前日の料金は hidden で送り直す',
				'preset'              => 'custom',
				'unit'                => '',
				'meta'                => array( '_vkbm_base_price' => 10000 ),
				'contains'            => array(
					'type="hidden" name="vkbm_service_menu[price_tiers][pre_holiday_price][]" value="15000"',
				),
				'not_contains'        => array(
					'name="vkbm_service_menu[night_price_pre_holiday]"',
					'vkbm-night-rate-caption',
					'If the pre-holiday price is empty, the regular price is used.',
				),
				'extra_fields_hidden' => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_max_capacity', 4 );
			update_post_meta( $post->ID, '_vkbm_allow_multiple_guests', true );
			update_post_meta( $post->ID, '_vkbm_price_tiers', $tiers );
			if ( '' !== $case['unit'] ) {
				update_post_meta( $post->ID, '_vkbm_booking_unit', $case['unit'] );
			}
			foreach ( $case['meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}
			$output = $this->render( $post );

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}

			if ( null !== $case['extra_fields_hidden'] ) {
				// 休前日・特定日の欄を包む要素（vkbm-night-rate-field vkbm-booking-unit-night-only）の hidden を確かめる。
				$matched = preg_match_all( '/<span class="vkbm-night-rate-field vkbm-booking-unit-night-only"([^>]*)>/', $output, $matches );
				$this->assertGreaterThanOrEqual( 4, $matched, $case['test_condition_name'] . ': 基本料金 2 欄 + 区分 2 欄が描画されること' );
				foreach ( $matches[1] as $attributes ) {
					$this->assertSame( $case['extra_fields_hidden'], str_contains( $attributes, 'hidden' ), $case['test_condition_name'] . ': 欄の hidden' );
				}
			}
		}
	}

	/**
	 * render_conditions_meta_box: 料金区分があるとき、基本料金の休前日・特定日の欄も基本料金と同じく読み取り専用になる。
	 */
	public function test_render_conditions_meta_box_base_prices_readonly_with_tiers(): void {
		$this->set_site( 'lodging' );
		$post = $this->create_menu();
		update_post_meta( $post->ID, '_vkbm_max_capacity', 4 );
		update_post_meta( $post->ID, '_vkbm_allow_multiple_guests', true );
		update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );
		update_post_meta(
			$post->ID,
			'_vkbm_price_tiers',
			array(
				array(
					'label' => '大人',
					'price' => 12000,
				),
			)
		);

		$output  = $this->render( $post );
		$matched = preg_match_all( '/<input[^>]*vkbm-night-base-price-extra[^>]*>/s', $output, $matches );

		$this->assertSame( 2, $matched, '休前日・特定日の基本料金の入力欄が 2 つ' );
		foreach ( $matches[0] as $input_html ) {
			$this->assertStringContainsString( 'readonly', $input_html, '料金区分があるときは読み取り専用' );
			$this->assertStringContainsString( 'vkbm-base-price-overridden', $input_html, '料金区分があるときはグレーアウトの見た目' );
		}
	}
}
