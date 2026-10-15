<?php
/**
 * サービスメニュー編集画面の「料金の単位」（泊単位のプランの料金の単位）のテスト（#572）。
 *
 * 宿泊の機能を使うサイト（Pro 版かつ業種プリセットが宿泊）でだけ欄を描画・保存し、
 * それ以外・時間枠のメニューでは画面も保存データも従来どおりであることを検証する。
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
 * 料金の単位の保存・描画・読み取りヘルパーのテスト（#572）。
 *
 * @group admin
 * @group lodging
 */
class Service_Menu_Editor_Night_Price_Unit_Test extends WP_UnitTestCase {

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
			$this->markTestSkipped( '料金の単位は Pro 版の業種プリセット（宿泊）限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・フィルター・キャッシュ・ログイン状態・$_POST を元に戻す。
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
	 * @param string $preset         保存する業種プリセットのキー。
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
	 * 複数人一括予約・料金区分・貸切指定を含む送信値を組み立てる。
	 *
	 * @param array<string, mixed> $override 上書きする値。
	 * @return array<string, mixed>
	 */
	private function build_submission( array $override ): array {
		return array_merge(
			array(
				'booking_unit'              => 'night',
				'night_price_unit'          => 'per_guest',
				'max_capacity'              => '4',
				'allow_multiple_guests'     => '1',
				'base_price'                => '10000',
				'price_tiers'               => array(
					'label' => array( '大人', '子供' ),
					'price' => array( '12000', '6000' ),
				),
				'exclusive_user_selectable' => '1',
				'exclusive_fee_per_person'  => '500',
			),
			$override
		);
	}

	/**
	 * save_post: 料金の単位の保存規則と、泊単位で使わない設定の削除を検証する。
	 */
	public function test_save_post(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '1室1泊あたりを送ると per_room が保存される',
				'preset'              => 'lodging',
				'initial_meta'        => array(),
				'submitted'           => array( 'night_price_unit' => 'per_room' ),
				'expected'            => array( '_vkbm_night_price_unit' => 'per_room' ),
			),
			array(
				'test_condition_name' => '1名1泊あたりへ戻すとメタが削除される',
				'preset'              => 'lodging',
				'initial_meta'        => array( '_vkbm_night_price_unit' => 'per_room' ),
				'submitted'           => array( 'night_price_unit' => 'per_guest' ),
				'expected'            => array( '_vkbm_night_price_unit' => null ),
			),
			array(
				'test_condition_name' => '不正な値（week）はメタが削除される',
				'preset'              => 'lodging',
				'initial_meta'        => array( '_vkbm_night_price_unit' => 'per_room' ),
				'submitted'           => array( 'night_price_unit' => 'week' ),
				'expected'            => array( '_vkbm_night_price_unit' => null ),
			),
			array(
				'test_condition_name' => 'フォームに料金の単位が無い保存では既存の値に触れない',
				'preset'              => 'lodging',
				'initial_meta'        => array( '_vkbm_night_price_unit' => 'per_room' ),
				'submitted'           => array( 'night_price_unit' => null ),
				'expected'            => array( '_vkbm_night_price_unit' => 'per_room' ),
			),
			array(
				'test_condition_name' => '1室1泊あたりで保存すると料金区分のメタが削除され、基本料金は残る',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_price_tiers' => array(
						array(
							'label' => '大人',
							'price' => 12000,
						),
					),
				),
				'submitted'           => array( 'night_price_unit' => 'per_room' ),
				'expected'            => array(
					'_vkbm_night_price_unit' => 'per_room',
					'_vkbm_price_tiers'      => null,
					'_vkbm_base_price'       => '10000',
				),
			),
			array(
				'test_condition_name' => '1名1泊あたりで保存すると料金区分のメタは保存される',
				'preset'              => 'lodging',
				'initial_meta'        => array(),
				'submitted'           => array( 'night_price_unit' => 'per_guest' ),
				'expected'            => array(
					'_vkbm_price_tiers' => array(
						array(
							'label' => '大人',
							'price' => 12000,
						),
						array(
							'label' => '子供',
							'price' => 6000,
						),
					),
				),
			),
			array(
				'test_condition_name' => '泊単位では予約者が選ぶ貸切（指定・貸切料金・適用外人数）は保存されない',
				'preset'              => 'lodging',
				'initial_meta'        => array(
					'_vkbm_exclusive_user_selectable'   => true,
					'_vkbm_exclusive_fee_per_person'    => 300,
					'_vkbm_exclusive_fee_exempt_guests' => 5,
				),
				'submitted'           => array( 'night_price_unit' => 'per_guest' ),
				'expected'            => array(
					'_vkbm_exclusive_user_selectable'   => null,
					'_vkbm_exclusive_fee_per_person'    => null,
					'_vkbm_exclusive_fee_exempt_guests' => null,
				),
			),
			array(
				'test_condition_name' => '宿泊のサイトの時間枠のメニューでは、貸切も料金区分も従来どおり保存される（単位は per_room を送っても影響しない）',
				'preset'              => 'lodging',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'     => 'slot',
					'night_price_unit' => 'per_room',
				),
				'expected'            => array(
					'_vkbm_exclusive_user_selectable' => true,
					'_vkbm_exclusive_fee_per_person'  => 500,
					'_vkbm_price_tiers'               => array(
						array(
							'label' => '大人',
							'price' => 12000,
						),
						array(
							'label' => '子供',
							'price' => 6000,
						),
					),
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）では料金の単位を保存せず、貸切・料金区分も従来どおり保存される',
				'preset'              => 'custom',
				'initial_meta'        => array(),
				'submitted'           => array(
					'booking_unit'     => 'night',
					'night_price_unit' => 'per_room',
				),
				'expected'            => array(
					'_vkbm_night_price_unit'          => null,
					'_vkbm_exclusive_user_selectable' => true,
					'_vkbm_price_tiers'               => array(
						array(
							'label' => '大人',
							'price' => 12000,
						),
						array(
							'label' => '子供',
							'price' => 6000,
						),
					),
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			foreach ( $case['initial_meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post->ID, $meta_key, $meta_value );
			}
			// 宿泊のサイトで泊の予約単位を既に保存した状態から始める（単位ラジオの保存はテスト対象外）。
			$submission = $this->build_submission( $case['submitted'] );
			if ( array_key_exists( 'night_price_unit', $case['submitted'] ) && null === $case['submitted']['night_price_unit'] ) {
				unset( $submission['night_price_unit'], $submission['booking_unit'] );
				update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );
			}

			$this->submit( $post, $submission );

			foreach ( $case['expected'] as $meta_key => $expected ) {
				$actual = metadata_exists( 'post', $post->ID, $meta_key ) ? get_post_meta( $post->ID, $meta_key, true ) : null;
				$this->assertEquals( $expected, $actual, $case['test_condition_name'] . ': ' . $meta_key );
			}
		}
	}

	/**
	 * get_night_price_unit / is_night_price_per_room: 読み取りの規則を検証する。
	 */
	public function test_get_night_price_unit(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト・泊・per_room は per_room',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'price_unit'          => 'per_room',
				'expected'            => 'per_room',
			),
			array(
				'test_condition_name' => '宿泊のサイト・泊・メタなし（#570 で泊にしたメニュー）は per_guest',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'price_unit'          => '',
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => '宿泊のサイト・泊・不正値は per_guest',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'price_unit'          => 'week',
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => '宿泊のサイト・時間枠のメニューは per_room が残っていても per_guest',
				'preset'              => 'lodging',
				'unit'                => '',
				'price_unit'          => 'per_room',
				'expected'            => 'per_guest',
			),
			array(
				'test_condition_name' => '宿泊以外（salon）へ切り替えると、per_room が残っていても per_guest',
				'preset'              => 'salon',
				'unit'                => 'night',
				'price_unit'          => 'per_room',
				'expected'            => 'per_guest',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			if ( '' !== $case['unit'] ) {
				update_post_meta( $post->ID, '_vkbm_booking_unit', $case['unit'] );
			}
			if ( '' !== $case['price_unit'] ) {
				update_post_meta( $post->ID, '_vkbm_night_price_unit', $case['price_unit'] );
			}
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_night_price_unit( $post->ID ), $case['test_condition_name'] );
			$this->assertSame( 'per_room' === $case['expected'], Service_Menu_Post_Type::is_night_price_per_room( $post->ID ), $case['test_condition_name'] . '（is_night_price_per_room）' );
		}
		$this->assertSame( 'per_guest', Service_Menu_Post_Type::get_night_price_unit( 0 ), 'メニューIDが0なら per_guest' );
	}

	/**
	 * render_conditions_meta_box: 宿泊のサイトの泊単位でだけ欄・単位表記を出し、1室あたりでは料金区分・貸切の行を隠す。
	 */
	public function test_render_conditions_meta_box(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト・泊・1名あたり: ラジオと単位表記を出し、料金区分は表示、貸切指定は hidden',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'price_unit'          => '',
				'contains'            => array(
					'name="vkbm_service_menu[night_price_unit]"',
					'/ per guest, per night',
					'Price (per guest, per night)',
					'aria-describedby="vkbm-base-price-unit vkbm-base-price-overridden-description"',
				),
				'not_contains'        => array( '/ per room, per night</span>', 'The price does not change with the number of people.' ),
				'row_hidden'          => array(
					'vkbm-night-price-unit-field'          => false,
					'vkbm-price-tiers-field'               => false,
					'vkbm-exclusive-user-selectable-field' => true,
					'vkbm-exclusive-when-booked-field'     => false,
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト・泊・1室あたり: 単位表記が切り替わり、料金区分は hidden、基本料金は読み取り専用でない',
				'preset'              => 'lodging',
				'unit'                => 'night',
				'price_unit'          => 'per_room',
				'contains'            => array(
					'/ per room, per night</span>',
					'The price does not change with the number of people.',
					'aria-describedby="vkbm-base-price-unit"',
				),
				'not_contains'        => array( 'readonly', 'vkbm-base-price-overridden"' ),
				'row_hidden'          => array(
					'vkbm-night-price-unit-field'          => false,
					'vkbm-price-tiers-field'               => true,
					'vkbm-exclusive-user-selectable-field' => true,
				),
			),
			array(
				'test_condition_name' => '宿泊のサイト・時間枠: 料金の単位の行は hidden、料金区分・貸切指定は従来どおり表示',
				'preset'              => 'lodging',
				'unit'                => '',
				'price_unit'          => 'per_room',
				'contains'            => array( 'name="vkbm_service_menu[night_price_unit]"' ),
				'not_contains'        => array( 'aria-describedby="vkbm-base-price-unit"' ),
				'row_hidden'          => array(
					'vkbm-night-price-unit-field'          => true,
					'vkbm-price-tiers-field'               => false,
					'vkbm-exclusive-user-selectable-field' => false,
				),
			),
			array(
				'test_condition_name' => '宿泊以外のサイト（custom）: 料金の単位・単位表記を出さず、料金区分・貸切指定は従来どおり表示',
				'preset'              => 'custom',
				'unit'                => '',
				'price_unit'          => '',
				'contains'            => array( 'name="vkbm_service_menu[allow_multiple_guests]"' ),
				'not_contains'        => array( 'night_price_unit', 'vkbm-base-price-unit', 'per guest, per night' ),
				'row_hidden'          => array(
					'vkbm-price-tiers-field'               => false,
					'vkbm-exclusive-user-selectable-field' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_max_capacity', 4 );
			update_post_meta( $post->ID, '_vkbm_allow_multiple_guests', true );
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
			if ( '' !== $case['unit'] ) {
				update_post_meta( $post->ID, '_vkbm_booking_unit', $case['unit'] );
			}
			if ( '' !== $case['price_unit'] ) {
				update_post_meta( $post->ID, '_vkbm_night_price_unit', $case['price_unit'] );
			}
			$output = $this->render( $post );

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] . ': ' . $needle );
			}
			foreach ( $case['row_hidden'] as $row_id => $expected_hidden ) {
				$matched = preg_match( '/<tr id="' . preg_quote( $row_id, '/' ) . '"[^>]*>/', $output, $matches );
				$this->assertSame( 1, $matched, $case['test_condition_name'] . ': ' . $row_id . ' の行が描画されること' );
				$this->assertSame( $expected_hidden, str_contains( $matches[0], 'hidden' ), $case['test_condition_name'] . ': ' . $row_id . ' の hidden' );
			}
		}
	}

	/**
	 * 時間枠へ戻して保存しても、料金の単位の選択はラジオに残り、再保存でも消えない。
	 */
	public function test_night_price_unit_kept_when_switched_to_slot(): void {
		$this->set_site( 'lodging' );
		$post = $this->create_menu();

		// 泊・1室あたりで保存し、時間枠へ戻して保存する（ラジオは描画された選択のまま送られる）。
		$this->submit( $post, $this->build_submission( array( 'night_price_unit' => 'per_room' ) ) );
		$this->submit(
			$post,
			$this->build_submission(
				array(
					'booking_unit'     => 'slot',
					'night_price_unit' => 'per_room',
				)
			)
		);
		$this->assertSame( 'per_room', get_post_meta( $post->ID, '_vkbm_night_price_unit', true ), '時間枠へ戻しても単位メタが残る' );
		$this->assertSame( 'per_guest', Service_Menu_Post_Type::get_night_price_unit( $post->ID ), '時間枠では実効の単位は per_guest' );
		$this->assertSame( 'per_room', Service_Menu_Post_Type::get_stored_night_price_unit( $post->ID ), '保存済みの単位は per_room' );

		// 再描画したラジオの選択は per_room。
		$output = $this->render( $post );
		$this->assertSame( 1, preg_match( '/value="per_room"\s+checked=\'checked\'/', $output ), '再描画したラジオで per_room が選択されている' );

		// 描画された選択のまま、もう一度保存してもメタが残る。
		$this->submit(
			$post,
			$this->build_submission(
				array(
					'booking_unit'     => 'slot',
					'night_price_unit' => 'per_room',
				)
			)
		);
		$this->assertSame( 'per_room', get_post_meta( $post->ID, '_vkbm_night_price_unit', true ), '再保存でも単位メタが残る' );
	}

	/**
	 * delete_night_unit_unsupported_meta: 保存の分岐（予約枠の定員機能の有無など）に関わらず、泊単位では貸切・料金区分を削除する。
	 *
	 * 宿泊のプリセットは予約枠の定員機能を常にオンにするため、定員機能オフの分岐へは設定では到達できない。
	 * そのため保存処理の末尾で呼ぶ削除処理を直接呼び、分岐に依存しないことを確認する。
	 */
	public function test_delete_night_unit_unsupported_meta(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '泊・1室あたり: 貸切3メタと料金区分を削除する',
				'booking_unit'        => 'night',
				'price_unit'          => 'per_room',
				'expected_exists'     => false,
			),
			array(
				'test_condition_name' => '泊・1名あたり: 貸切3メタは削除するが料金区分は残す',
				'booking_unit'        => 'night',
				'price_unit'          => '',
				'expected_exists'     => true,
			),
			array(
				'test_condition_name' => '時間枠: 何も削除しない',
				'booking_unit'        => '',
				'price_unit'          => 'per_room',
				'expected_exists'     => true,
			),
		);

		$method = new \ReflectionMethod( Service_Menu_Editor::class, 'delete_night_unit_unsupported_meta' );
		$method->setAccessible( true );

		foreach ( $test_cases as $case ) {
			$this->set_site( 'lodging' );
			$post = $this->create_menu();
			update_post_meta( $post->ID, '_vkbm_exclusive_user_selectable', true );
			update_post_meta( $post->ID, '_vkbm_exclusive_fee_per_person', 300 );
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
			if ( '' !== $case['booking_unit'] ) {
				update_post_meta( $post->ID, '_vkbm_booking_unit', $case['booking_unit'] );
			}
			if ( '' !== $case['price_unit'] ) {
				update_post_meta( $post->ID, '_vkbm_night_price_unit', $case['price_unit'] );
			}

			$method->invoke( new Service_Menu_Editor(), $post->ID );

			$is_time_slot = '' === $case['booking_unit'];
			$this->assertSame( $is_time_slot, metadata_exists( 'post', $post->ID, '_vkbm_exclusive_user_selectable' ), $case['test_condition_name'] . ': 貸切指定' );
			$this->assertSame( $case['expected_exists'], metadata_exists( 'post', $post->ID, '_vkbm_price_tiers' ), $case['test_condition_name'] . ': 料金区分' );
		}
	}

	/**
	 * 料金の単位の行は基本料金の行の直上に描画される。
	 */
	public function test_render_night_price_unit_position(): void {
		$this->set_site( 'lodging' );
		$post = $this->create_menu();
		update_post_meta( $post->ID, '_vkbm_booking_unit', 'night' );

		$output = $this->render( $post );

		$this->assertSame(
			1,
			preg_match( '/id="vkbm-night-price-unit-field".*?<\/tr>\s*<tr id="vkbm-base-price-field"/s', $output ),
			'料金の単位の行が基本料金の行の直上に描画されること'
		);
	}

	/**
	 * メタの書き込み権限と REST 経由の書き込み: 宿泊のサイトで編集権限のある場合だけ書け、不正値は保存されない。
	 */
	public function test_register_meta(): void {
		// テストケース間でメタ登録が解除されるため、登録を明示的にやり直す。
		( new Service_Menu_Post_Type() )->register_meta();
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイトでは編集権限があれば書き込める',
				'preset'              => 'lodging',
				'role'                => 'administrator',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '宿泊以外のサイトでは編集権限があっても書き込めない',
				'preset'              => 'salon',
				'role'                => 'administrator',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '宿泊のサイトでも、編集権限の無い購読者は書き込めない',
				'preset'              => 'lodging',
				'role'                => 'subscriber',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$post = $this->create_menu();
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );
			$this->assertSame(
				$case['expected'],
				current_user_can( 'edit_post_meta', $post->ID, '_vkbm_night_price_unit' ),
				$case['test_condition_name']
			);
		}

		$this->assertContains( '_vkbm_night_price_unit', ( new Service_Menu_Post_Type() )->get_revisioned_meta_keys(), 'リビジョン対象に料金の単位が含まれること' );

		// REST 経由: per_room は保存され、不正値は保存されない。
		$GLOBALS['wp_rest_server'] = null;  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core のグローバル。
		$rest_cases                = array(
			array(
				'test_condition_name' => 'per_room は保存される',
				'value'               => 'per_room',
				'expected'            => 'per_room',
			),
			array(
				'test_condition_name' => '不正な値（week）は per_room にならない',
				'value'               => 'week',
				'expected'            => '',
			),
		);
		foreach ( $rest_cases as $case ) {
			$this->set_site( 'lodging' );
			$post    = $this->create_menu();
			$request = new \WP_REST_Request( 'POST', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $post->ID );
			$request->set_body_params( array( 'meta' => array( '_vkbm_night_price_unit' => $case['value'] ) ) );
			rest_get_server()->dispatch( $request );
			$this->assertSame( $case['expected'], (string) get_post_meta( $post->ID, '_vkbm_night_price_unit', true ), $case['test_condition_name'] );
		}
	}
}
