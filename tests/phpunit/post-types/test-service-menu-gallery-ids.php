<?php
/**
 * サービスメニューのサブ画像（_vkbm_gallery_ids）の整理・読み出し・メタ登録のテスト（#623）。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\PostTypes;

use VKBookingManager\PostTypes\Service_Menu_Post_Type;
use WP_UnitTestCase;

/**
 * Service_Menu_Post_Type のサブ画像まわりのテスト。
 *
 * @group post-types
 */
class Service_Menu_Gallery_Ids_Test extends WP_UnitTestCase {

	/**
	 * 画像の添付を作成する。
	 *
	 * @return int 添付ID。
	 */
	private function create_image(): int {
		return (int) $this->factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/06/gallery.jpg',
			)
		);
	}

	/**
	 * 画像ではない添付（PDF）を作成する。
	 *
	 * @return int 添付ID。
	 */
	private function create_pdf(): int {
		return (int) $this->factory()->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
				'file'           => '2026/06/document.pdf',
			)
		);
	}

	/**
	 * sanitize_gallery_ids: 整数化・重複除去・画像でない添付の除外を行い、並び順は保つ。
	 */
	public function test_sanitize_gallery_ids(): void {
		$image_a = $this->create_image();
		$image_b = $this->create_image();
		$image_c = $this->create_image();
		$pdf     = $this->create_pdf();

		$test_cases = array(
			array(
				'test_condition_name' => '並び順を保つ => 渡した順のまま返る',
				'input'               => array( $image_c, $image_a, $image_b ),
				'expected'            => array( $image_c, $image_a, $image_b ),
			),
			array(
				'test_condition_name' => 'カンマ区切りの文字列（hidden 欄の値） => 配列として扱われ順序が保たれる',
				'input'               => $image_b . ',' . $image_a,
				'expected'            => array( $image_b, $image_a ),
			),
			array(
				'test_condition_name' => '重複 => 先に出た方だけ残る',
				'input'               => array( $image_a, $image_b, $image_a ),
				'expected'            => array( $image_a, $image_b ),
			),
			array(
				'test_condition_name' => '文字列の数字・0・数値でない値・配列 => 整数化され 0 や不正な値は除かれる',
				'input'               => array( (string) $image_a, '0', 'abc', array( 1 ) ),
				'expected'            => array( $image_a ),
			),
			array(
				'test_condition_name' => '存在しない添付ID・画像でない添付 => 除かれる',
				'input'               => array( $image_a, 999999, $pdf ),
				'expected'            => array( $image_a ),
			),
			array(
				'test_condition_name' => '空文字 => 空配列',
				'input'               => '',
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '配列でも文字列でもない値 => 空配列',
				'input'               => 12,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::sanitize_gallery_ids( $case['input'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * get_gallery_attachment_ids: 保存済みの並び順で返し、削除済みの添付や画像でない添付は除く。
	 */
	public function test_get_gallery_attachment_ids(): void {
		global $wpdb;

		$menu_id = $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );
		$image_a = $this->create_image();
		$image_b = $this->create_image();
		$pdf     = $this->create_pdf();

		$test_cases = array(
			array(
				'test_condition_name' => '未設定 => 空配列',
				'stored'              => null,
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '保存した並び順 => その順で返る',
				'stored'              => array( $image_b, $image_a ),
				'expected'            => array( $image_b, $image_a ),
			),
			array(
				'test_condition_name' => '保存後に画像でなくなった・存在しない添付が混ざる => 除いて返る',
				'stored'              => array( $image_a, $pdf, 999999 ),
				'expected'            => array( $image_a ),
			),
		);

		foreach ( $test_cases as $case ) {
			delete_post_meta( $menu_id, Service_Menu_Post_Type::META_GALLERY_IDS );
			if ( null !== $case['stored'] ) {
				// 保存時の整理を通さず、保存済みの値をそのまま入れる（読み出し側の除外を確かめるため）。
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->postmeta,
					array(
						'post_id'    => $menu_id,
						'meta_key'   => Service_Menu_Post_Type::META_GALLERY_IDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => maybe_serialize( $case['stored'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				wp_cache_delete( $menu_id, 'post_meta' );
			}
			$this->assertSame( $case['expected'], Service_Menu_Post_Type::get_gallery_attachment_ids( $menu_id ), $case['test_condition_name'] );
		}

		// 添付そのものを削除したとき、一覧から外れることを確認する。
		delete_post_meta( $menu_id, Service_Menu_Post_Type::META_GALLERY_IDS );
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_GALLERY_IDS, array( $image_a, $image_b ) );
		wp_delete_attachment( $image_a, true );
		$this->assertSame( array( $image_b ), Service_Menu_Post_Type::get_gallery_attachment_ids( $menu_id ), '添付を削除した => 一覧から外れる' );
	}

	/**
	 * register_meta: サブ画像のメタが REST 公開の整数配列として登録され、リビジョン対象にも含まれる。
	 */
	public function test_register_meta(): void {
		// メタ登録のタイミングに依存しないよう、明示的に登録してから確認する（多重登録は WP 側で冪等）。
		( new Service_Menu_Post_Type() )->register_meta();
		$registered = get_registered_meta_keys( 'post', Service_Menu_Post_Type::POST_TYPE );

		$this->assertArrayHasKey( Service_Menu_Post_Type::META_GALLERY_IDS, $registered, 'メタが登録されている' );
		$this->assertSame( 'array', $registered[ Service_Menu_Post_Type::META_GALLERY_IDS ]['type'], '型は配列' );
		$this->assertSame( 'integer', $registered[ Service_Menu_Post_Type::META_GALLERY_IDS ]['show_in_rest']['schema']['items']['type'], '要素は整数' );

		$this->assertContains( Service_Menu_Post_Type::META_GALLERY_IDS, ( new Service_Menu_Post_Type() )->get_revisioned_meta_keys(), 'リビジョン対象に含まれる' );
	}

	/**
	 * register_meta: 編集権限の無いユーザーはサブ画像のメタを書き込めず（REST などのメタ API 経由も同じ判定）、編集者は書き込める。
	 */
	public function test_register_meta_auth_callback(): void {
		( new Service_Menu_Post_Type() )->register_meta();
		$menu_id = $this->factory()->post->create( array( 'post_type' => Service_Menu_Post_Type::POST_TYPE ) );

		$test_cases = array(
			array(
				'test_condition_name' => '購読者 => 書き込めない',
				'role'                => 'subscriber',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '管理者 => 書き込める',
				'role'                => 'administrator',
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $this->factory()->user->create( array( 'role' => $case['role'] ) ) );

			$this->assertSame( $case['expected'], current_user_can( 'edit_post_meta', $menu_id, Service_Menu_Post_Type::META_GALLERY_IDS ), $case['test_condition_name'] );
		}

		// 購読者のときは実際の書き込み経路（REST のメタ更新）でも値が変わらないことを確認する。
		update_post_meta( $menu_id, Service_Menu_Post_Type::META_GALLERY_IDS, array() );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$image_id = (int) $this->factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/06/auth.jpg',
			)
		);
		$request  = new \WP_REST_Request( 'POST', '/wp/v2/' . Service_Menu_Post_Type::POST_TYPE . '/' . $menu_id );
		$request->set_body_params( array( 'meta' => array( Service_Menu_Post_Type::META_GALLERY_IDS => array( $image_id ) ) ) );
		rest_get_server()->dispatch( $request );
		$this->assertSame( array(), Service_Menu_Post_Type::get_gallery_attachment_ids( $menu_id ), '購読者の REST 書き込み => 値が変わらない' );

		wp_set_current_user( 0 );
	}
}
