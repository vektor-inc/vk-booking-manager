<?php
/**
 * 客室（リソース）編集画面の「客室ごとの追加料金」（指名料の欄）の出し分けテスト（#736）。
 *
 * 宿泊が有効なサイトでは入力欄（メタボックス）を出さず、保存済みの金額を消さず、予約画面向けの REST では 0 を返すことを検証する。
 * それ以外のサイトは従来どおりであることも検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Staff;

use VKBookingManager\Admin\Pro_Upsell;
use VKBookingManager\PostTypes\Resource_Post_Type;
use VKBookingManager\ProviderSettings\Industry_Presets;
use VKBookingManager\ProviderSettings\Settings_Repository;
use VKBookingManager\Staff\Staff_Editor;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Staff_Editor::render_meta_box() の単位表記の出し分けテスト。
 *
 * @group staff
 * @group lodging
 */
class Staff_Editor_Room_Surcharge_Unit_Test extends WP_UnitTestCase {

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
			$this->markTestSkipped( '客室ごとの追加料金は Pro 版の宿泊プリセット限定のため、無料版ではスキップする。' );
		}
	}

	/**
	 * テスト後に全体設定・フィルター・キャッシュを元に戻す。
	 */
	protected function tearDown(): void {
		if ( false === $this->original_settings ) {
			delete_option( Settings_Repository::OPTION_KEY );
		} else {
			update_option( Settings_Repository::OPTION_KEY, $this->original_settings );
		}
		Industry_Presets::clear_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 業種プリセットを保存する。
	 *
	 * @param string $preset 業種プリセットのキー。
	 */
	private function set_site( string $preset ): void {
		Industry_Presets::clear_cache();
		update_option(
			Settings_Repository::OPTION_KEY,
			array_merge( ( new Settings_Repository() )->get_default_settings(), array( 'industry_preset' => $preset ) )
		);
	}

	/**
	 * add_meta_boxes: 宿泊のサイトでは入力欄のメタボックスを登録しない。
	 */
	public function test_add_meta_boxes(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト: 客室ごとの追加料金の入力欄を出さない',
				'preset'              => 'lodging',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'サロンのサイト: 従来どおり指名料の入力欄を出す',
				'preset'              => 'salon',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'カスタムのサイト: 従来どおり',
				'preset'              => 'custom',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			unset( $GLOBALS['wp_meta_boxes'] );
			$this->set_site( $case['preset'] );

			( new Staff_Editor() )->add_meta_boxes();

			$this->assertSame(
				$case['expected'],
				isset( $GLOBALS['wp_meta_boxes'][ Resource_Post_Type::POST_TYPE ]['normal']['default']['vkbm-staff-basic'] ),
				$case['test_condition_name']
			);
		}
	}

	/**
	 * render_meta_box: 宿泊以外のサイトでは従来の説明文を出し、宿泊向けの単位表記は出さない。
	 */
	public function test_render_meta_box(): void {
		$this->set_site( 'salon' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$room_id = (int) $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );

		ob_start();
		( new Staff_Editor() )->render_meta_box( get_post( $room_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="vkbm_staff[nomination_fee]"', $output );
		$this->assertStringContainsString( 'This is the fee that will be added if the customer nominates this', $output );
		$this->assertStringNotContainsString( 'vkbm-staff-nomination-fee-unit', $output );
		$this->assertStringNotContainsString( 'per room, per night', $output );
	}

	/**
	 * save_post: 宿泊のサイトでは入力欄が送信されなくても保存済みの金額を消さない。宿泊以外は従来どおり保存する。
	 */
	public function test_save_post_keeps_saved_fee_for_lodging_site(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト: 欄が送信されなくても保存済みの 3000 が残る',
				'preset'              => 'lodging',
				'post_data'           => array(),
				'expected'            => '3000',
			),
			array(
				'test_condition_name' => '宿泊のサイト: 欄が送信されても保存済みの値を変えない',
				'preset'              => 'lodging',
				'post_data'           => array( 'nomination_fee' => '500' ),
				'expected'            => '3000',
			),
			array(
				'test_condition_name' => 'サロンのサイト: 送信された値で従来どおり保存する',
				'preset'              => 'salon',
				'post_data'           => array( 'nomination_fee' => '500' ),
				'expected'            => '500',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
			$room_id = (int) $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );
			update_post_meta( $room_id, Staff_Editor::META_NOMINATION_FEE, '3000' );

			$_POST = array(
				'_vkbm_staff_meta_nonce' => wp_create_nonce( 'vkbm_staff_meta' ),
				'vkbm_staff'             => $case['post_data'],
			);
			( new Staff_Editor() )->save_post( $room_id, get_post( $room_id ) );
			$_POST = array();

			$this->assertSame( $case['expected'], (string) get_post_meta( $room_id, Staff_Editor::META_NOMINATION_FEE, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * zero_nomination_fee_in_rest_response: 宿泊のサイトの予約画面向け REST では指名料を 0 にする。
	 */
	public function test_zero_nomination_fee_in_rest_response(): void {
		$test_cases = array(
			array(
				'test_condition_name' => '宿泊のサイト（view）: 0 にする',
				'preset'              => 'lodging',
				'context'             => 'view',
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '宿泊のサイト（edit）: 保存済みの値を返す',
				'preset'              => 'lodging',
				'context'             => 'edit',
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => 'サロンのサイト（view）: 保存済みの値を返す',
				'preset'              => 'salon',
				'context'             => 'view',
				'expected'            => 3000,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->set_site( $case['preset'] );
			$room_id = (int) $this->factory()->post->create( array( 'post_type' => Resource_Post_Type::POST_TYPE ) );
			$request = new WP_REST_Request( 'GET', '/wp/v2/' . Resource_Post_Type::POST_TYPE );
			$request->set_param( 'context', $case['context'] );
			$response = new WP_REST_Response(
				array(
					'nomination_fee' => 3000,
					'meta'           => array(
						Staff_Editor::META_NOMINATION_FEE => 3000,
						'other'                           => 'x',
					),
				)
			);

			$data = ( new Staff_Editor() )->zero_nomination_fee_in_rest_response( $response, get_post( $room_id ), $request )->get_data();

			$this->assertSame( $case['expected'], $data['nomination_fee'], $case['test_condition_name'] . ' / nomination_fee' );
			$this->assertSame( $case['expected'], $data['meta'][ Staff_Editor::META_NOMINATION_FEE ], $case['test_condition_name'] . ' / meta' );
			$this->assertSame( 'x', $data['meta']['other'], $case['test_condition_name'] . ' / 他のメタは変えない' );
		}
	}
}
