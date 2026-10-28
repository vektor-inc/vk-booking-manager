<?php
/**
 * 予約の編集画面からのアップロードに対する画像の縮小・JPEG 変換（issue #871）のテスト。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Booking_Admin のアップロード時のフィルターを検証する。
 *
 * @group bookings
 */
class Booking_Admin_Upload_Filter_Test extends WP_UnitTestCase {

	/**
	 * リクエストの後始末。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_REQUEST['vkbm_booking_upload'], $_REQUEST['post_id'] );
		parent::tear_down();
	}

	/**
	 * 予約の編集画面からのアップロードとして、リクエストを組み立てる。
	 *
	 * @param int $post_id アップロード先の投稿ID。
	 * @return void
	 */
	private function set_upload_request( int $post_id ): void {
		$_REQUEST['vkbm_booking_upload'] = '1';
		$_REQUEST['post_id']             = (string) $post_id;
	}

	/**
	 * 目印が付くかどうかを、品質フィルターの結果で調べる（予約画面からのアップロードなら 50 になる）。
	 *
	 * @return bool
	 */
	private function is_marked_as_booking_upload(): bool {
		return 50 === ( new Booking_Admin() )->filter_booking_upload_quality( 82, 'image/jpeg' );
	}

	/**
	 * 予約の投稿への、権限のある利用者によるアップロードは予約画面からのアップロードとして扱う。
	 *
	 * @return void
	 */
	public function test_booking_post_with_capability_is_marked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$this->set_upload_request( $booking_id );

		$this->assertTrue( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 予約以外の投稿を指定したアップロードは、予約画面からのアップロードとして扱わない。
	 *
	 * @return void
	 */
	public function test_non_booking_post_is_not_marked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->set_upload_request( $page_id );

		$this->assertFalse( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 投稿IDが無い、または存在しない場合は予約画面からのアップロードとして扱わない。
	 *
	 * @return void
	 */
	public function test_missing_post_id_is_not_marked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_upload_request( 0 );
		$this->assertFalse( $this->is_marked_as_booking_upload() );

		$this->set_upload_request( 99999999 );
		$this->assertFalse( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 予約を管理する権限の無い利用者では、予約の投稿を指定しても扱わない。
	 *
	 * @return void
	 */
	public function test_user_without_capability_is_not_marked(): void {
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->set_upload_request( $booking_id );

		$this->assertFalse( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 変換後の .jpg と同名のファイルが同じフォルダーにあっても、そのファイルを残して別名で保存する。
	 *
	 * @return void
	 */
	public function test_converted_file_does_not_overwrite_existing_jpg(): void {
		if ( ! function_exists( 'imagepng' ) ) {
			$this->markTestSkipped( 'GD is required.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$this->set_upload_request( $booking_id );

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['path'];
		wp_mkdir_p( $dir );

		$png_file = $dir . '/vkbm-871-sample.png';
		$image    = imagecreatetruecolor( 40, 30 );
		imagepng( $image, $png_file );
		imagedestroy( $image );

		$existing_jpg = $dir . '/vkbm-871-sample.jpg';
		file_put_contents( $existing_jpg, 'existing-file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$png_file,
			0,
			array( 'post_mime_type' => 'image/png' )
		);

		( new Booking_Admin() )->filter_booking_attachment_metadata( array( 'width' => 40, 'height' => 30 ), $attachment_id );

		$this->assertSame( 'existing-file', file_get_contents( $existing_jpg ), '既存のファイルが残っていること' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$new_file = get_attached_file( $attachment_id );
		$this->assertNotSame( $existing_jpg, $new_file );
		$this->assertStringEndsWith( '.jpg', $new_file );
		$this->assertFileExists( $new_file );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $attachment_id ) );

		wp_delete_file( $existing_jpg );
		wp_delete_file( $new_file );
	}
}
