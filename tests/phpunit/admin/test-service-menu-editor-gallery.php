<?php
/**
 * サービスメニュー編集画面のサブ画像欄（#623）の描画順と保存のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Admin;

use VKBookingManager\Admin\Service_Menu_Editor;
use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * Service_Menu_Editor のサブ画像欄のテスト。
 *
 * @group admin
 */
class Service_Menu_Editor_Gallery_Test extends WP_UnitTestCase {

	private const NONCE_ACTION = 'vkbm_service_menu_meta';
	private const NONCE_NAME   = '_vkbm_service_menu_nonce';

	/**
	 * テスト用のサービスメニュー投稿ID。
	 *
	 * @var int
	 */
	private int $menu_id;

	/**
	 * テスト前に管理者とサービスメニューを用意する。
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->menu_id = $this->factory()->post->create(
			array(
				'post_type'   => Service_Menu_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * テスト後にログイン状態と $_POST を戻す。
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * 画像の添付を作成する。
	 *
	 * @return int 添付ID。
	 */
	private function create_image(): int {
		return (int) $this->factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/06/editor-gallery.jpg',
			)
		);
	}

	/**
	 * 基本メタボックスの出力を取得する。
	 *
	 * @return string 出力 HTML。
	 */
	private function render_meta_box(): string {
		ob_start();
		( new Service_Menu_Editor() )->render_vkbm_meta_box( get_post( $this->menu_id ) );
		return (string) ob_get_clean();
	}

	/**
	 * 保存処理を、指定した hidden 欄の値で実行する。
	 *
	 * @param mixed $gallery_ids 送信する gallery_ids。null のときは項目自体を送らない。
	 */
	private function save_with_gallery_ids( $gallery_ids ): void {
		$data = array( 'base_price' => '3000' );
		if ( null !== $gallery_ids ) {
			$data['gallery_ids'] = $gallery_ids;
		}
		$_POST = array(
			self::NONCE_NAME    => wp_create_nonce( self::NONCE_ACTION ),
			'vkbm_service_menu' => $data,
		);
		( new Service_Menu_Editor() )->save_post( $this->menu_id, get_post( $this->menu_id ) );
	}

	/**
	 * render_vkbm_meta_box: サブ画像欄がキャッチコピーの直後に描画され、登録済みの画像が並び順どおり出る。
	 */
	public function test_render_vkbm_meta_box(): void {
		$image_a = $this->create_image();
		$image_b = $this->create_image();

		$test_cases = array(
			array(
				'test_condition_name' => '画像なし => 欄は描画され、件数は 0 枚相当で「すべて削除」は隠れる',
				'stored'              => array(),
				'expected_hidden'     => '',
				'clear_hidden'        => true,
			),
			array(
				'test_condition_name' => '2枚登録 => hidden 欄に並び順どおりの ID が入り、「すべて削除」が表示される',
				'stored'              => array( $image_b, $image_a ),
				'expected_hidden'     => $image_b . ',' . $image_a,
				'clear_hidden'        => false,
			),
		);

		foreach ( $test_cases as $case ) {
			delete_post_meta( $this->menu_id, Service_Menu_Post_Type::META_GALLERY_IDS );
			if ( ! empty( $case['stored'] ) ) {
				update_post_meta( $this->menu_id, Service_Menu_Post_Type::META_GALLERY_IDS, $case['stored'] );
			}
			$output = $this->render_meta_box();

			// キャッチコピー欄の直後（次の項目より前）にサブ画像欄が出ることを確認する。
			$pos_catch   = strpos( $output, 'id="vkbm_service_menu_catch_copy"' );
			$pos_gallery = strpos( $output, 'data-vkbm-gallery="1"' );
			$this->assertNotFalse( $pos_catch, $case['test_condition_name'] . ': キャッチコピー欄がある' );
			$this->assertNotFalse( $pos_gallery, $case['test_condition_name'] . ': サブ画像欄がある' );
			$this->assertGreaterThan( $pos_catch, $pos_gallery, $case['test_condition_name'] . ': サブ画像欄はキャッチコピーの後' );

			// サブ画像欄と、キャッチコピーの次に出る欄の前後関係を確認する（欄の間に他の項目が入らない）。
			$between = substr( $output, (int) $pos_catch, (int) $pos_gallery - (int) $pos_catch );
			$this->assertSame( 1, substr_count( $between, '<tr' ), $case['test_condition_name'] . ': キャッチコピー行とサブ画像行の間に別の行がない' );

			$this->assertStringContainsString( 'name="vkbm_service_menu[gallery_ids]"', $output, $case['test_condition_name'] );
			$this->assertStringContainsString( 'class="vkbm-gallery__input" value="' . $case['expected_hidden'] . '"', $output, $case['test_condition_name'] );
			// 「すべて削除」は2枚以上のときだけ表示される（hidden 属性の有無で確認する）。
			preg_match( '/<button[^>]*vkbm-gallery__clear"([^>]*)>/', $output, $clear_match );
			$this->assertNotEmpty( $clear_match, $case['test_condition_name'] . ': すべて削除ボタンがある' );
			$this->assertSame( $case['clear_hidden'], str_contains( $clear_match[1], 'hidden' ), $case['test_condition_name'] );
		}
	}

	/**
	 * save_post: サブ画像の ID を整理して保存し、空なら削除し、項目が送られなければ既存の値を残す。
	 */
	public function test_save_post(): void {
		$image_a = $this->create_image();
		$image_b = $this->create_image();

		$test_cases = array(
			array(
				'test_condition_name' => '並び順・重複・存在しない ID が混ざる => 重複と存在しない ID を除いて順序どおり保存',
				'posted'              => $image_b . ',' . $image_a . ',' . $image_b . ',999999',
				'expected'            => array( $image_b, $image_a ),
			),
			array(
				'test_condition_name' => '空文字 => メタを削除',
				'posted'              => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '配列が送られた（不正入力） => 既存の値を変えない',
				'posted'              => array( '1' ),
				'expected'            => array( $image_a ),
			),
			array(
				'test_condition_name' => '項目が送られない => 既存の値を変えない',
				'posted'              => null,
				'expected'            => array( $image_a ),
			),
		);

		foreach ( $test_cases as $case ) {
			// ケースごとに、1枚だけ保存済みの状態を作り直す。
			delete_post_meta( $this->menu_id, Service_Menu_Post_Type::META_GALLERY_IDS );
			update_post_meta( $this->menu_id, Service_Menu_Post_Type::META_GALLERY_IDS, array( $image_a ) );

			$this->save_with_gallery_ids( $case['posted'] );

			$stored = get_post_meta( $this->menu_id, Service_Menu_Post_Type::META_GALLERY_IDS, true );
			if ( array() === $case['expected'] ) {
				$this->assertFalse( metadata_exists( 'post', $this->menu_id, Service_Menu_Post_Type::META_GALLERY_IDS ), $case['test_condition_name'] );
			} else {
				$this->assertSame( $case['expected'], array_map( 'intval', (array) $stored ), $case['test_condition_name'] );
			}
		}
	}
}
